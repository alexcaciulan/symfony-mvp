<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingPackage;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\FilingPackageStatus;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One archive with every unsigned file of the current package, for signing them
 * in one go. The files carry client data under professional secrecy: the archive
 * is written to a temporary file the caller deletes after sending, never kept in
 * storage, and each download is audited like a single-file one.
 */
final class UnsignedPackageArchiveBuilder
{
    /** Only a package still waiting for, or holding, the lawyer's signatures has files to sign. */
    public const ARCHIVABLE_STATUSES = [
        FilingPackageStatus::BUILT,
        FilingPackageStatus::AWAITING_SIGNATURES,
        FilingPackageStatus::SIGNED,
    ];

    public function __construct(
        private readonly string $uploadsDir,
        private readonly AuditLogService $auditLogService,
        private readonly EntityManagerInterface $em,
    ) {}

    public static function isArchivable(FilingPackage $package): bool
    {
        return in_array($package->getStatus(), self::ARCHIVABLE_STATUSES, true);
    }

    /**
     * @return string absolute path of a temporary .zip, to be deleted by the caller
     *
     * @throws UnsignedArchiveUnavailableException when a file is missing or the archive cannot be written
     */
    public function build(FilingPackage $package, User $actor): string
    {
        if (!self::isArchivable($package)) {
            throw new UnsignedArchiveUnavailableException('The package has no files to sign in its current status.');
        }
        $case = $package->getLegalCase();

        $zipPath = (string) tempnam(sys_get_temp_dir(), 'filing-unsigned-');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);

            throw new UnsignedArchiveUnavailableException('The archive cannot be opened for writing.');
        }

        $entries = [];
        try {
            foreach ($package->getParts() as $part) {
                $path = $this->uploadsDir . '/' . $part->getUnsignedPath();
                if (!is_file($path)) {
                    throw new UnsignedArchiveUnavailableException(sprintf('Unsigned file missing for package part %d.', (int) $part->getId()));
                }
                $zip->addFile($path, FilingPartDownloadName::of($case, $package, $part, FilingPartDownloadName::UNSIGNED));
                $entries[] = [
                    'role' => $part->getRole()->value,
                    'volume' => $part->getVolume(),
                    'sha256' => $part->getUnsignedSha256(),
                ];
            }
        } catch (UnsignedArchiveUnavailableException $e) {
            $zip->close();
            @unlink($zipPath);

            throw $e;
        }
        if (!$zip->close() || $entries === []) {
            @unlink($zipPath);

            throw new UnsignedArchiveUnavailableException('The archive could not be written.');
        }

        $this->auditLogService->log(
            action: 'filing_package_unsigned_archive_downloaded',
            entityType: LegalCase::class,
            entityId: (string) $case->getId(),
            newData: ['version' => $package->getVersion(), 'parts' => $entries],
            category: AuditLogService::CATEGORY_FILING_PACKAGE,
            actor: $actor,
        );
        $package->markDownloadedForSigning();
        $this->em->flush();

        return $zipPath;
    }
}
