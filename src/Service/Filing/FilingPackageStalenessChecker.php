<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\AppSetting;
use App\Entity\FilingPackage;
use App\Entity\LegalCase;
use App\Enum\OpisPlacement;
use App\Repository\AppSettingRepository;
use App\Repository\FilingPackageRepository;
use App\Service\Document\MissingDocumentFileException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recomputes the source fingerprint when the package is looked at, instead of
 * listening to every entity change: the sources span documents, the claim, the
 * court and the stamp duty, and a listener would miss one sooner or later. A
 * package that went to the court never becomes stale: what was sent is a fact.
 */
final class FilingPackageStalenessChecker
{
    public function __construct(
        private readonly FilingPackageRepository $packages,
        private readonly FilingAnnexSelector $selector,
        private readonly FilingSourceManifestBuilder $manifestBuilder,
        private readonly AppSettingRepository $settings,
        private readonly EntityManagerInterface $em,
        private readonly FilingLockQuery $filingLock,
    ) {}

    /** The current package, marked stale (and flushed) when its sources changed. */
    public function refresh(LegalCase $case): ?FilingPackage
    {
        $package = $this->packages->findCurrentForCase($case);
        if ($package === null || !$package->getStatus()->canBecomeStale()) {
            return $package;
        }
        // Being transmitted: the files that leave are the ones signed, whatever changes now.
        if ($this->filingLock->isLocked($case)) {
            return $package;
        }

        $reason = $this->changeReason($case, $package);
        if ($reason !== null) {
            $package->markStale($reason);
            $this->em->flush();
        }

        return $package;
    }

    private function changeReason(LegalCase $case, FilingPackage $package): ?string
    {
        try {
            $selection = $this->selector->select($case);
        } catch (FilingPackageBlockedException $e) {
            // The dated power of attorney or the petition is gone.
            return $e->messageKey === 'filing.package.blocked_petition_missing' ? 'petition' : 'power_of_attorney';
        } catch (MissingDocumentFileException) {
            return 'annexes';
        }

        $placement = $this->settings->get(AppSetting::KEY_FILING_OPIS_PLACEMENT) === 'separate' ? OpisPlacement::SEPARATE : OpisPlacement::MERGED;
        $manifest = $this->manifestBuilder->build($case, $selection, $placement);
        if (FilingPackageFingerprint::hash($manifest) === $package->getSourceFingerprint()) {
            return null;
        }

        return FilingPackageFingerprint::diff($package->getSourceManifest(), $manifest) ?? 'other';
    }
}
