<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingPackagePart;
use App\Enum\FilingPartRole;
use App\Service\Pdf\PdfToolException;
use App\Service\Pdf\ProcessSandbox;
use App\Service\Pdf\QpdfRunner;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Writes the files of a filing package under the case's filing directory and
 * describes each one as a part. Every written path is appended to $written, so the
 * caller can remove them all when the build fails.
 */
final class FilingPartWriter
{
    public function __construct(
        private readonly ProcessSandbox $sandbox,
        private readonly QpdfRunner $qpdf,
        private readonly TranslatorInterface $translator,
        private readonly string $uploadsDir,
    ) {}

    /**
     * A file the platform generated: compacted, tagged, moved under the package dir.
     *
     * @param list<string> $written
     * @param list<int>    $sourceDocumentIds
     */
    public function generatedPart(FilingPartRole $role, int $position, string $source, string $relativeDir, ?int $volume, array &$written, array $sourceDocumentIds, ?string $title = null): FilingPackagePart
    {
        $token = $this->token();
        $compact = $this->sandbox->tempPath();
        $this->qpdf->compact($source, $compact);
        $tagged = $this->sandbox->tempPath();
        $this->qpdf->setInfoKey($compact, $tagged, QpdfRunner::PART_ID_KEY, $token);
        $pages = $this->qpdf->pageCount($tagged);

        $relative = $this->store($tagged, $relativeDir, $role, $volume, $written);
        $absolute = $this->absolute($relative);

        return new FilingPackagePart(
            $role,
            $position,
            $title ?? $this->title($role, $volume),
            $pages,
            $relative,
            (string) hash_file('sha256', $absolute),
            (int) filesize($absolute),
            $token,
            $sourceDocumentIds,
        );
    }

    /**
     * A file the lawyer or the generator produced, copied unchanged. Its token is
     * not written into the file (that would change its bytes); the sha256 identifies it.
     *
     * @param list<string> $written
     * @param list<int>    $sourceDocumentIds
     */
    public function copiedPart(FilingPartRole $role, int $position, string $source, string $relativeDir, array &$written, array $sourceDocumentIds, ?int $pages = null): FilingPackagePart
    {
        $sourceSha = (string) hash_file('sha256', $source);
        $relative = $this->store($source, $relativeDir, $role, null, $written, copy: true);
        $absolute = $this->absolute($relative);
        if (hash_file('sha256', $absolute) !== $sourceSha) {
            throw new PdfToolException('Copy of ' . $role->value . ' differs from its source');
        }

        return new FilingPackagePart(
            $role,
            $position,
            $this->title($role, null),
            $pages ?? $this->qpdf->pageCount($absolute),
            $relative,
            $sourceSha,
            (int) filesize($absolute),
            $this->token(),
            $sourceDocumentIds,
        );
    }

    /**
     * Stores the lawyer's signed file next to the package, copied byte for byte and
     * checked against its hash: a signed file is never rewritten.
     *
     * @return string the path relative to the uploads directory
     */
    public function storeSigned(string $source, string $sha256, FilingPackagePart $part): string
    {
        $package = $part->getPackage();
        $relativeDir = sprintf('cases/%d/filing/v%d/signed', $package->getLegalCase()->getId(), $package->getVersion());
        $written = [];
        $relative = $this->store($source, $relativeDir, $part->getRole(), $part->getVolume(), $written, copy: true);
        if (hash_file('sha256', $this->absolute($relative)) !== $sha256) {
            $this->removeFiles($written);
            throw new PdfToolException('Copy of the signed ' . $part->getRole()->value . ' differs from the upload');
        }

        return $relative;
    }

    public function title(FilingPartRole $role, ?int $volume, ?int $volumeCount = null, bool $withOpis = false): string
    {
        if ($role === FilingPartRole::ANNEX_BUNDLE && $volumeCount !== null && $volumeCount > 1) {
            return $this->translator->trans('filing.package.part_title.ANNEX_BUNDLE_volume', ['%volume%' => $volume, '%count%' => $volumeCount]);
        }

        return $this->translator->trans('filing.package.part_title.' . $role->value . ($withOpis ? '_with_opis' : ''));
    }

    public function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function absolute(string $relative): string
    {
        return $this->uploadsDir . '/' . $relative;
    }

    /** @param list<string> $paths */
    public function removeFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @param list<string> $written
     */
    private function store(string $source, string $relativeDir, FilingPartRole $role, ?int $volume, array &$written, bool $copy = false): string
    {
        $dir = $this->absolute($relativeDir);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new PdfToolException('Cannot create ' . $dir);
        }

        $relative = sprintf('%s/%s-%d-%s.pdf', $relativeDir, $role->fileStem(), $volume ?? 0, bin2hex(random_bytes(6)));
        $target = $this->absolute($relative);
        $ok = $copy ? copy($source, $target) : rename($source, $target);
        if (!$ok) {
            throw new PdfToolException('Cannot write ' . $relative);
        }
        $written[] = $target;

        return $relative;
    }
}
