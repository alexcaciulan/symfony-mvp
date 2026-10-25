<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\FilingPackage;
use App\Entity\FilingPackagePart;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Repository\FilingPackagePartRepository;
use App\Repository\FilingPackageRepository;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\AuditLogService;
use App\Service\Case\OverviewContextBuilder;
use App\Service\Document\MissingDocumentFileException;
use App\Service\Filing\FilingPackageBlockedException;
use App\Service\Filing\FilingPackageBuilder;
use App\Service\Filing\FilingPartDownloadName;
use App\Service\Filing\SignedPartOverBudgetException;
use App\Service\Filing\SignedPartUploadService;
use App\Service\Filing\SignedUploadRefusedException;
use App\Service\Filing\UnsignedArchiveUnavailableException;
use App\Service\Filing\UnsignedPackageArchiveBuilder;
use App\Service\Pdf\PdfToolException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The filing package: building the unsigned files, downloading them one by one
 * for signing, and uploading the signed files back. The files carry the client's
 * and the debtor's data under professional secrecy, so only the lawyer of the
 * case downloads or uploads them; an admin sees the package metadata on the case
 * page and nothing more.
 */
final class CaseFilingController extends AbstractController
{
    public function __construct(
        private readonly LegalCaseRepository $legalCaseRepository,
        private readonly FilingPackagePartRepository $partRepository,
        private readonly FilingPackageBuilder $builder,
        private readonly SignedPartUploadService $signedUploads,
        private readonly FilingPackageRepository $packageRepository,
        private readonly UnsignedPackageArchiveBuilder $archiveBuilder,
        private readonly OverviewContextBuilder $contextBuilder,
        private readonly AuditLogService $auditLogService,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly string $uploadsDir,
        private readonly int $filingMaxEmailBytes,
    ) {}

    #[Route('/case/{id}/filing/package/build', name: 'case_filing_package_build', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function build(int $id, Request $request, RateLimiterFactory $filingPackageBuildLimiter): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        if (!$this->isCsrfTokenValid('filing_package_build_' . $id, $request->getPayload()->getString('_token'))) {
            return $this->respond($request, $case, false, 'error', 'filing.package.flash.csrf');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$filingPackageBuildLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
            return $this->respond($request, $case, false, 'warning', 'filing.package.flash.rate_limited');
        }

        try {
            $this->builder->build($case, $user);
        } catch (FilingPackageBlockedException $e) {
            return $this->respond($request, $case, false, 'error', $e->messageKey, $e->messageParams);
        } catch (PdfToolException $e) {
            return $this->respond($request, $case, false, 'error', $e->messageKey, $e->messageParams);
        } catch (MissingDocumentFileException) {
            return $this->respond($request, $case, false, 'error', 'case_overview.zip_package.flash_error_file_missing');
        }

        return $this->respond($request, $case, true, 'success', 'filing.package.flash.built');
    }

    #[Route('/case/{id}/filing/package/{packageId}/part/{partId}', name: 'case_filing_part_download', requirements: ['id' => '\d+', 'packageId' => '\d+', 'partId' => '\d+'], methods: ['GET'])]
    public function download(int $id, int $packageId, int $partId): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::FILING_CONTENT, $case);
        $part = $this->findPart($case, $packageId, $partId);

        $path = $this->uploadsDir . '/' . $part->getUnsignedPath();
        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }

        $package = $part->getPackage();
        $this->auditLogService->log(
            action: 'filing_package_part_downloaded',
            entityType: LegalCase::class,
            entityId: (string) $case->getId(),
            newData: [
                'version' => $package->getVersion(),
                'role' => $part->getRole()->value,
                'volume' => $part->getVolume(),
                'sha256' => $part->getUnsignedSha256(),
            ],
            category: AuditLogService::CATEGORY_FILING_PACKAGE,
        );
        $package->markDownloadedForSigning();
        $this->em->flush();

        return $this->pdfResponse($path, FilingPartDownloadName::of($case, $package, $part, FilingPartDownloadName::UNSIGNED));
    }

    #[Route('/case/{id}/filing/package/{packageId}/part/{partId}/signed', name: 'case_filing_part_upload_signed', requirements: ['id' => '\d+', 'packageId' => '\d+', 'partId' => '\d+'], methods: ['POST'])]
    public function uploadSigned(int $id, int $packageId, int $partId, Request $request, RateLimiterFactory $filingSignedUploadLimiter): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::FILING_CONTENT, $case);
        $part = $this->findPart($case, $packageId, $partId);

        // PHP drops the whole body of a POST over post_max_size: without this the
        // lawyer would read "session expired" for a file that is simply too large.
        if ($request->request->count() === 0 && $request->files->count() === 0 && (int) $request->server->get('CONTENT_LENGTH') > 0) {
            $this->signedUploads->recordTooLarge($part);

            return $this->respond($request, $case, true, 'error', 'filing.upload.error.too_large');
        }
        if (!$this->isCsrfTokenValid('filing_signed_upload_' . $part->getId(), $request->getPayload()->getString('_token'))) {
            return $this->respond($request, $case, false, 'error', 'filing.upload.error.csrf');
        }

        $user = $this->currentUser();
        if (!$filingSignedUploadLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
            return $this->respond($request, $case, false, 'warning', 'filing.upload.error.rate_limited');
        }

        $file = $request->files->get('signed_file');
        if (!$file instanceof UploadedFile) {
            return $this->respond($request, $case, false, 'error', 'filing.upload.error.failed');
        }

        try {
            $part = $this->signedUploads->upload(
                $part,
                $file,
                $user,
                $request->getPayload()->getBoolean('replace'),
            );
        } catch (SignedUploadRefusedException $e) {
            // The card is replaced so the refused row explains itself; it also shows
            // the current state after a concurrent upload.
            return $this->respond($request, $case, true, 'error', $e->messageKey);
        } catch (SignedPartOverBudgetException $e) {
            return $this->respond($request, $case, true, 'error', $e->messageKey(), [
                '%excess%' => number_format($e->excessBytes / 1048576, 2, ',', '.'),
            ]);
        }

        $key = match (true) {
            $part->isSignatureAccepted() => 'filing.upload.success.matched',
            default => 'filing.upload.success.probable_unconfirmed',
        };

        return $this->respond($request, $case, true, $part->isSignatureAccepted() ? 'success' : 'warning', $key);
    }

    #[Route('/case/{id}/filing/package/{packageId}/part/{partId}/signed/confirm', name: 'case_filing_part_confirm_signed', requirements: ['id' => '\d+', 'packageId' => '\d+', 'partId' => '\d+'], methods: ['POST'])]
    public function confirmSigned(int $id, int $packageId, int $partId, Request $request): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::FILING_CONTENT, $case);
        $part = $this->findPart($case, $packageId, $partId);

        if (!$this->isCsrfTokenValid('filing_signed_confirm_' . $part->getId(), $request->getPayload()->getString('_token'))) {
            return $this->respond($request, $case, false, 'error', 'filing.upload.error.csrf');
        }

        try {
            $this->signedUploads->confirmProbable($part, $this->currentUser(), $request->getPayload()->getString('sha256'));
        } catch (SignedUploadRefusedException $e) {
            return $this->respond($request, $case, true, 'error', $e->messageKey);
        }

        return $this->respond($request, $case, true, 'success', 'filing.upload.success.confirmed');
    }

    #[Route('/case/{id}/filing/package/{packageId}/part/{partId}/signed', name: 'case_filing_part_download_signed', requirements: ['id' => '\d+', 'packageId' => '\d+', 'partId' => '\d+'], methods: ['GET'])]
    public function downloadSigned(int $id, int $packageId, int $partId): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::FILING_CONTENT, $case);
        $part = $this->findPart($case, $packageId, $partId);

        $path = $part->getSignedPath() !== null ? $this->uploadsDir . '/' . $part->getSignedPath() : null;
        if ($path === null || !is_file($path)) {
            throw $this->createNotFoundException();
        }

        $package = $part->getPackage();
        $this->auditLogService->log(
            action: 'filing_signed_part_downloaded',
            entityType: LegalCase::class,
            entityId: (string) $case->getId(),
            newData: [
                'version' => $package->getVersion(),
                'role' => $part->getRole()->value,
                'volume' => $part->getVolume(),
                'sha256' => $part->getSignedSha256(),
            ],
            category: AuditLogService::CATEGORY_FILING_PACKAGE,
        );
        $this->em->flush();

        return $this->pdfResponse($path, FilingPartDownloadName::of($case, $package, $part, FilingPartDownloadName::SIGNED));
    }

    /**
     * Every unsigned file of the current package in one archive. Lawyer of the case
     * only, like the single downloads (an admin is refused by FILING_CONTENT).
     */
    #[Route('/case/{id}/filing/package/{packageId}/unsigned.zip', name: 'case_filing_package_unsigned_zip', requirements: ['id' => '\d+', 'packageId' => '\d+'], methods: ['GET'])]
    public function unsignedZip(int $id, int $packageId): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::FILING_CONTENT, $case);
        $package = $this->packageRepository->findCurrentForCase($case);
        if ($package === null || $package->getId() !== $packageId || !UnsignedPackageArchiveBuilder::isArchivable($package)) {
            throw $this->createNotFoundException();
        }

        try {
            $path = $this->archiveBuilder->build($package, $this->currentUser());
        } catch (UnsignedArchiveUnavailableException) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, FilingPartDownloadName::archive($case, $package));
        $response->headers->set('Content-Type', 'application/zip');
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * Splits the annexes again with a budget smaller by the measured overshoot of
     * a refused signed upload. The petition, the index and the power of attorney
     * keep their files and signatures; every annex volume is new and unsigned.
     */
    #[Route('/case/{id}/filing/package/{packageId}/rebuild-annexes', name: 'case_filing_package_rebuild_annexes', requirements: ['id' => '\d+', 'packageId' => '\d+'], methods: ['POST'])]
    public function rebuildAnnexes(int $id, int $packageId, Request $request, RateLimiterFactory $filingPackageBuildLimiter): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::FILING_CONTENT, $case);
        $package = $this->em->find(FilingPackage::class, $packageId);
        if (!$package instanceof FilingPackage || $package->getLegalCase()->getId() !== $case->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('filing_annex_rebuild_' . $packageId, $request->getPayload()->getString('_token'))) {
            return $this->respond($request, $case, false, 'error', 'filing.package.flash.csrf');
        }
        if (!$filingPackageBuildLimiter->create($this->currentUser()->getUserIdentifier())->consume()->isAccepted()) {
            return $this->respond($request, $case, false, 'warning', 'filing.package.flash.rate_limited');
        }

        // Tampering can only make the volumes smaller, which is harmless.
        $excess = max(0, min($request->getPayload()->getInt('excess'), intdiv($this->filingMaxEmailBytes, 4)));

        try {
            $this->builder->rebuildAnnexVolumes($package, $this->filingMaxEmailBytes - $excess);
        } catch (FilingPackageBlockedException $e) {
            return $this->respond($request, $case, false, 'error', $e->messageKey, $e->messageParams);
        } catch (PdfToolException $e) {
            return $this->respond($request, $case, false, 'error', $e->messageKey, $e->messageParams);
        } catch (MissingDocumentFileException) {
            return $this->respond($request, $case, false, 'error', 'case_overview.zip_package.flash_error_file_missing');
        }

        return $this->respond($request, $case, true, 'success', 'filing.upload.over_budget.rebuilt');
    }

    private function findPart(LegalCase $case, int $packageId, int $partId): FilingPackagePart
    {
        $part = $this->partRepository->find($partId);
        if (!$part instanceof FilingPackagePart
            || $part->getPackage()->getId() !== $packageId
            || $part->getPackage()->getLegalCase()->getId() !== $case->getId()) {
            throw $this->createNotFoundException();
        }

        return $part;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function pdfResponse(string $path, string $name): BinaryFileResponse
    {
        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }

    /**
     * Turbo Stream for Turbo clients (the card is replaced in place, errors stay on
     * the page as a toast); redirect with flash otherwise.
     *
     * @param array<string, string> $toastParams
     */
    private function respond(Request $request, LegalCase $case, bool $replaceCard, string $toastVariant, string $toastKey, array $toastParams = []): Response
    {
        if (str_contains((string) $request->headers->get('Accept', ''), 'text/vnd.turbo-stream.html')) {
            $context = $replaceCard ? $this->contextBuilder->build($case) : ['case' => $case];
            $context['replace_card'] = $replaceCard;
            $context['toast_variant'] = $toastVariant;
            $context['toast_key'] = $toastKey;
            $context['toast_params'] = $toastParams;

            return new Response(
                $this->renderView('case/filing/_package_turbo_stream.html.twig', $context),
                Response::HTTP_OK,
                ['Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8'],
            );
        }

        $this->addFlash($toastVariant, $toastParams === [] ? $toastKey : $this->translator->trans($toastKey, $toastParams));

        return $this->redirectToRoute('case_overview', ['id' => $case->getId(), 'tab' => 'depunere']);
    }

    private function findOrThrow(int $id): LegalCase
    {
        $case = $this->legalCaseRepository->find($id);
        if (!$case instanceof LegalCase || $case->isDeleted()) {
            throw $this->createNotFoundException();
        }

        return $case;
    }
}
