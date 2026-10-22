<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingPackage;
use App\Entity\FilingPackagePart;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\FilingPackageStatus;
use App\Enum\FilingPartRole;
use App\Enum\SignatureStatus;
use App\Service\AuditLogService;
use App\Service\Pdf\PdfSignatureInspector;
use App\Service\Pdf\PdfToolException;
use App\Service\Pdf\ProcessSandbox;
use App\Service\Pdf\SignatureInspection;
use App\Service\Pdf\UploadedPdfException;
use App\Service\Pdf\UploadedPdfValidator;
use App\Service\Pdf\ValidatedPdf;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Accepts the lawyer's signed file for one part of a filing package: validates
 * the upload, checks that a signature is present and the signed bytes unchanged,
 * ties the file to the generated part, and re-checks the email size on the real
 * signed bytes. A refused upload leaves the part as it was. The package becomes
 * SIGNED when every part has an accepted signature, which is a technical state:
 * the qualified status of the signatures and the rest of the filing requirements
 * stay with the lawyer.
 */
final class SignedPartUploadService
{
    /** Below the web server's 120 s FastCGI timeout. */
    private const SANDBOX_BUDGET_SECONDS = 60.0;

    private const UPLOADABLE_PACKAGE_STATUSES = [
        FilingPackageStatus::BUILT,
        FilingPackageStatus::AWAITING_SIGNATURES,
        FilingPackageStatus::SIGNED,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FilingPackageStalenessChecker $staleness,
        private readonly UploadedPdfValidator $validator,
        private readonly PdfSignatureInspector $inspector,
        private readonly SignedPartMatcher $matcher,
        private readonly FilingPartWriter $writer,
        private readonly ProcessSandbox $sandbox,
        private readonly AuditLogService $auditLogService,
        private readonly int $filingMaxEmailBytes,
    ) {}

    /**
     * @throws SignedUploadRefusedException  the file is not accepted for this part
     * @throws SignedPartOverBudgetException the signed file does not fit its email
     */
    public function upload(FilingPackagePart $part, UploadedFile $file, User $user, bool $replace): FilingPackagePart
    {
        $package = $part->getPackage();
        $this->assertUploadable($package, $this->staleness->refresh($package->getLegalCase()));
        if ($part->isSignatureAccepted() && !$replace) {
            throw new SignedUploadRefusedException('filing.upload.error.already_signed');
        }
        $snapshotSha = $part->getSignedSha256();

        $this->sandbox->begin(self::SANDBOX_BUDGET_SECONDS);
        try {
            $validated = $this->validate($part, $file);
            $inspection = $this->inspector->inspect($validated->path);
            match ($inspection->outcome) {
                SignatureInspection::NONE => $this->refuse($part, $validated, 'filing.signature.refused.not_signed', SignatureStatus::NOT_SIGNED),
                SignatureInspection::UNSUPPORTED => $this->refuse($part, $validated, 'filing.signature.refused.unsupported_format', SignatureStatus::NOT_SIGNED),
                SignatureInspection::DAMAGED => $this->refuse($part, $validated, 'filing.signature.refused.damaged', SignatureStatus::DAMAGED),
                default => null,
            };

            $match = $this->matcher->match($part, $validated->path, $validated->pageCount);
            if ($match->status === SignatureStatus::MISMATCH) {
                $this->refuse($part, $validated, 'filing.signature.refused.mismatch', SignatureStatus::MISMATCH);
            }

            $this->assertWithinBudget($part, $validated);

            $report = [
                ...$inspection->toReport(),
                'matchMethod' => $match->method,
                'warnings' => [...$inspection->warnings, ...$match->warnings, ...$this->nameWarnings($inspection, $user)],
            ];

            return $this->commit($part, $validated, $user, $match->status, $report, $replace, $snapshotSha);
        } catch (PdfToolException $e) {
            throw new SignedUploadRefusedException($e->messageKey === 'filing.package.error.timeout' ? 'filing.upload.error.timeout' : 'filing.upload.error.unreadable', null, $e);
        } finally {
            $this->sandbox->end();
        }
    }

    /**
     * The lawyer confirms that the file recognised by its text is the generated
     * document. Bound to the file's hash, so a file replaced in the meantime is
     * never confirmed by a click made on the previous one.
     *
     * @throws SignedUploadRefusedException
     */
    public function confirmProbable(FilingPackagePart $part, User $user, string $expectedSha256): FilingPackagePart
    {
        $package = $part->getPackage();
        $this->assertUploadable($package, $this->staleness->refresh($package->getLegalCase()));

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->lock($part);
            $this->assertUploadable($package, $package);
            if (!$part->isAwaitingProbableConfirmation() || $part->getSignedSha256() !== $expectedSha256) {
                throw new SignedUploadRefusedException('filing.upload.error.concurrent');
            }

            $part->confirmProbableMatch($user);
            $this->updatePackageStatus($package);
            $this->auditLogService->log(
                action: 'filing_signed_part_probable_confirmed',
                entityType: LegalCase::class,
                entityId: (string) $package->getLegalCase()->getId(),
                newData: [
                    'version' => $package->getVersion(),
                    'role' => $part->getRole()->value,
                    'volume' => $part->getVolume(),
                    'sha256' => $part->getSignedSha256(),
                    'packageStatus' => $package->getStatus()->value,
                ],
                category: AuditLogService::CATEGORY_FILING_PACKAGE,
            );
            $this->em->flush();
            $connection->commit();

            return $part;
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    private function validate(FilingPackagePart $part, UploadedFile $file): ValidatedPdf
    {
        try {
            return $this->validator->validate($file);
        } catch (UploadedPdfException $e) {
            $this->auditRefusal($part, $e->messageKey, null, null);

            throw new SignedUploadRefusedException($e->messageKey, null, $e);
        }
    }

    private function refuse(FilingPackagePart $part, ValidatedPdf $file, string $key, SignatureStatus $status): never
    {
        $this->auditRefusal($part, $key, $status, $file);

        throw new SignedUploadRefusedException($key, $status);
    }

    /**
     * The email this part travels in, recomputed with the signed size. Annex
     * volumes can be split again; the petition, the index and the power of
     * attorney cannot, since they are the lawyer's documents.
     */
    private function assertWithinBudget(FilingPackagePart $part, ValidatedPdf $file): void
    {
        $package = $part->getPackage();
        $index = $package->emailIndexForPart($part->getPartToken());
        if ($index === null) {
            return;
        }
        $tokens = $package->getSizePlan()['emails'][$index]['parts'] ?? [];

        $bytes = 0;
        $carriesAnnexes = false;
        foreach ($package->getParts() as $other) {
            if (!in_array($other->getPartToken(), $tokens, true)) {
                continue;
            }
            $bytes += $other === $part ? $file->bytes : ($other->getSignedBytes() ?? $other->getUnsignedBytes());
            $carriesAnnexes = $carriesAnnexes || $other->getRole() === FilingPartRole::ANNEX_BUNDLE;
        }

        $encoded = (int) ceil($bytes * FilingSizePlanner::ENCODING_FACTOR);
        if ($encoded <= $this->filingMaxEmailBytes) {
            return;
        }

        $this->auditRefusal($part, 'filing.upload.error.over_budget', null, $file);

        throw new SignedPartOverBudgetException($encoded - $this->filingMaxEmailBytes, $carriesAnnexes);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function commit(
        FilingPackagePart $part,
        ValidatedPdf $file,
        User $user,
        SignatureStatus $status,
        array $report,
        bool $replace,
        ?string $snapshotSha,
    ): FilingPackagePart {
        $package = $part->getPackage();
        $stored = null;
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->lock($part);
            $this->assertUploadable($package, $package);
            // Another upload for this part finished while this one was being inspected.
            if ($part->getSignedSha256() !== $snapshotSha) {
                throw new SignedUploadRefusedException('filing.upload.error.concurrent');
            }
            if ($part->isSignatureAccepted() && !$replace) {
                throw new SignedUploadRefusedException('filing.upload.error.already_signed');
            }
            $replaced = $part->hasSignedFile();

            $stored = $this->writer->storeSigned($file->path, $file->sha256, $part);
            $part->recordSignedUpload(
                $stored,
                $file->sha256,
                $file->bytes,
                $user,
                $status,
                $report,
            );
            $package->recomputeSignedTotals();
            $this->updatePackageStatus($package);

            $this->auditLogService->log(
                action: 'filing_signed_part_uploaded',
                entityType: LegalCase::class,
                entityId: (string) $package->getLegalCase()->getId(),
                newData: [
                    'version' => $package->getVersion(),
                    'role' => $part->getRole()->value,
                    'volume' => $part->getVolume(),
                    'status' => $status->value,
                    'confirmed' => $part->isSignatureAccepted(),
                    'matchMethod' => $report['matchMethod'],
                    'sha256' => $file->sha256,
                    'bytes' => $file->bytes,
                    'warnings' => $report['warnings'],
                    'replaced' => $replaced,
                    'packageStatus' => $package->getStatus()->value,
                ],
                category: AuditLogService::CATEGORY_FILING_PACKAGE,
            );
            $this->em->flush();
            $connection->commit();

            return $part;
        } catch (\Throwable $e) {
            $connection->rollBack();
            if ($stored !== null) {
                $this->writer->removeFiles([$this->writer->absolute($stored)]);
            }

            throw $e;
        }
    }

    /**
     * The package row first, then the part: two parts uploaded at once both
     * recompute the package's state, and the second one must see the first.
     */
    private function lock(FilingPackagePart $part): void
    {
        $package = $part->getPackage();
        $this->em->lock($package, LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($package);
        $this->em->lock($part, LockMode::PESSIMISTIC_WRITE);
        foreach ($package->getParts() as $each) {
            $this->em->refresh($each);
        }
    }

    private function updatePackageStatus(FilingPackage $package): void
    {
        $package->setStatus($package->allPartsSignatureAccepted() ? FilingPackageStatus::SIGNED : FilingPackageStatus::AWAITING_SIGNATURES);
    }

    /**
     * Only the current, unsent package of a case still waiting to be filed takes
     * signed files.
     */
    private function assertUploadable(FilingPackage $package, ?FilingPackage $current): void
    {
        if ($current === null
            || $current->getId() !== $package->getId()
            || !in_array($package->getStatus(), self::UPLOADABLE_PACKAGE_STATUSES, true)
            || $package->getLegalCase()->getStatus() !== CaseStatus::CERERE_GENERATA) {
            throw new SignedUploadRefusedException('filing.upload.error.not_uploadable');
        }
    }

    /**
     * The name in the certificate is compared loosely (case, diacritics, word
     * order). A difference is only a warning: certificates carry titles or
     * pseudonyms, and representation is proven by the power of attorney.
     *
     * @return list<string>
     */
    private function nameWarnings(SignatureInspection $inspection, User $user): array
    {
        $fullName = trim((string) $user->getFullName());
        if ($fullName === '' || $inspection->signerCn === null) {
            return [];
        }

        return self::normalizedName($fullName) === self::normalizedName($inspection->signerCn) ? [] : ['filing.signature.warning.cn_differs'];
    }

    private static function normalizedName(string $name): string
    {
        $ascii = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()')?->transliterate($name) ?? mb_strtolower($name);
        $words = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($words);

        return implode(' ', $words);
    }

    /**
     * Hash, size and outcome only: no PDF content and nothing from the certificate.
     */
    private function auditRefusal(FilingPackagePart $part, string $key, ?SignatureStatus $status, ?ValidatedPdf $file): void
    {
        $package = $part->getPackage();
        $this->auditLogService->log(
            action: 'filing_signed_upload_refused',
            entityType: LegalCase::class,
            entityId: (string) $package->getLegalCase()->getId(),
            newData: [
                'version' => $package->getVersion(),
                'role' => $part->getRole()->value,
                'volume' => $part->getVolume(),
                'reason' => $key,
                'status' => $status?->value,
                'sha256' => $file?->sha256,
                'bytes' => $file?->bytes,
            ],
            category: AuditLogService::CATEGORY_FILING_PACKAGE,
        );
        $this->em->flush();
    }
}
