<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingPackage;
use App\Entity\FilingPackagePart;
use App\Entity\LegalCase;

/** The file name a package part is downloaded under, alone or inside an archive. */
final class FilingPartDownloadName
{
    public const UNSIGNED = 'nesemnat';
    public const SIGNED = 'semnat';

    public static function of(LegalCase $case, FilingPackage $package, FilingPackagePart $part, string $suffix): string
    {
        $volume = $part->getVolumeCount() !== null && $part->getVolumeCount() > 1 ? '_vol' . $part->getVolume() : '';

        return sprintf('%s_%s_v%d%s_%s.pdf', ucfirst($part->getRole()->fileStem()), self::caseStem($case), $package->getVersion(), $volume, $suffix);
    }

    public static function archive(LegalCase $case, FilingPackage $package): string
    {
        return sprintf('Pachet_%s_v%d_%s.zip', self::caseStem($case), $package->getVersion(), self::UNSIGNED);
    }

    private static function caseStem(LegalCase $case): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $case->getCaseNumber());
    }
}
