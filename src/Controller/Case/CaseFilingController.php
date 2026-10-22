<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\FilingPackage;
use App\Entity\FilingPackagePart;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\FilingPackageStatus;
use App\Repository\FilingPackagePartRepository;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\AuditLogService;
use App\Service\Case\OverviewContextBuilder;
use App\Service\Document\MissingDocumentFileException;
use App\Service\Filing\FilingPackageBlockedException;
use App\Service\Filing\FilingPackageBuilder;
use App\Service\Pdf\PdfToolException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The filing package: building the unsigned files and downloading them one by
 * one for signing. The files carry the client's and the debtor's data under
 * professional secrecy, so only the lawyer of the case downloads them; an admin
 * sees the package metadata on the case page and nothing more.
 */
final class CaseFilingController extends AbstractController
{
    public function __construct(
        private readonly LegalCaseRepository $legalCaseRepository,
        private readonly FilingPackagePartRepository $partRepository,
        private readonly FilingPackageBuilder $builder,
        private readonly OverviewContextBuilder $contextBuilder,
        private readonly AuditLogService $auditLogService,
        private readonly EntityManagerInterface $em,
        private readonly string $uploadsDir,
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
        $this->denyAccessUnlessGranted(CaseVoter::VIEW, $case);

        // The voter lets an admin view every case; the content of the filing stays with its lawyer.
        $user = $this->getUser();
        if (!$user instanceof User || $case->getUser()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        $part = $this->partRepository->find($partId);
        if (!$part instanceof FilingPackagePart
            || $part->getPackage()->getId() !== $packageId
            || $part->getPackage()->getLegalCase()->getId() !== $case->getId()) {
            throw $this->createNotFoundException();
        }

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
        // A downloaded file may be on its way to the signing tool.
        if ($package->getStatus() === FilingPackageStatus::BUILT) {
            $package->setStatus(FilingPackageStatus::AWAITING_SIGNATURES);
        }
        $this->em->flush();

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->downloadName($case, $package, $part));
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }

    private function downloadName(LegalCase $case, FilingPackage $package, FilingPackagePart $part): string
    {
        $caseNumber = (string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $case->getCaseNumber());
        $volume = $part->getVolumeCount() !== null && $part->getVolumeCount() > 1 ? '_vol' . $part->getVolume() : '';

        return sprintf('%s_%s_v%d%s_nesemnat.pdf', ucfirst($part->getRole()->fileStem()), $caseNumber, $package->getVersion(), $volume);
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

        $this->addFlash($toastVariant, $toastKey);

        return $this->redirectToRoute('case_overview', ['id' => $case->getId()]);
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
