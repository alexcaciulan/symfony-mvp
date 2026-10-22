<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\FilingPackage;
use App\Entity\FilingPackagePart;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\FilingPartRole;
use App\Enum\OpisPlacement;
use App\Enum\SignatureStatus;
use App\Service\Filing\FilingSizePlanner;
use PHPUnit\Framework\TestCase;

final class FilingPackagePartTest extends TestCase
{
    public function testMatchedSignatureIsAcceptedAndProbableOnlyOnceConfirmed(): void
    {
        $lawyer = new User();
        $part = self::part(FilingPartRole::PETITION, 'tok-a', 1000);
        self::assertFalse($part->isSignatureAccepted());
        self::assertFalse($part->hasSignedFile());

        $part->recordSignedUpload('signed/a.pdf', str_repeat('a', 64), 1200, $lawyer, SignatureStatus::PRESENT_PROBABLE, ['warnings' => ['w']]);
        self::assertTrue($part->hasSignedFile());
        self::assertFalse($part->isSignatureAccepted());
        self::assertTrue($part->isAwaitingProbableConfirmation());
        self::assertSame(['w'], $part->getSignatureWarnings());

        $part->confirmProbableMatch($lawyer);
        self::assertTrue($part->isSignatureAccepted());
        self::assertSame($lawyer, $part->getProbableMatchConfirmedBy());

        $part->recordSignedUpload('signed/b.pdf', str_repeat('b', 64), 1300, $lawyer, SignatureStatus::PRESENT_MATCHED, []);
        self::assertTrue($part->isSignatureAccepted());
    }

    public function testAConfirmationNeverCarriesOverToANewFile(): void
    {
        $lawyer = new User();
        $part = self::part(FilingPartRole::PETITION, 'tok-a', 1000);
        $part->recordSignedUpload('signed/a.pdf', str_repeat('a', 64), 1200, $lawyer, SignatureStatus::PRESENT_PROBABLE, []);
        $part->confirmProbableMatch($lawyer);
        self::assertTrue($part->isSignatureAccepted());

        $part->recordSignedUpload('signed/b.pdf', str_repeat('b', 64), 1250, $lawyer, SignatureStatus::PRESENT_PROBABLE, []);

        self::assertFalse($part->isSignatureAccepted());
        self::assertNull($part->getProbableMatchConfirmedBy());
        self::assertNull($part->getProbableMatchConfirmedAt());
    }

    public function testRefusedOutcomesAreNeverRecordedOnAPart(): void
    {
        $part = self::part(FilingPartRole::PETITION, 'tok-a', 1000);

        foreach ([SignatureStatus::MISMATCH, SignatureStatus::NOT_SIGNED, SignatureStatus::DAMAGED, SignatureStatus::MISSING] as $status) {
            try {
                $part->recordSignedUpload('signed/x.pdf', str_repeat('x', 64), 1, new User(), $status, []);
                self::fail('Expected a refusal for ' . $status->value);
            } catch (\LogicException) {
                self::assertSame(SignatureStatus::MISSING, $part->getSignatureStatus());
            }
        }
    }

    public function testOnlyAnUnconfirmedProbableMatchCanBeConfirmed(): void
    {
        $part = self::part(FilingPartRole::PETITION, 'tok-a', 1000);
        $part->recordSignedUpload('signed/a.pdf', str_repeat('a', 64), 1200, new User(), SignatureStatus::PRESENT_MATCHED, []);

        $this->expectException(\LogicException::class);
        $part->confirmProbableMatch(new User());
    }

    public function testPackageIsFullyAcceptedOnlyWhenEveryPartIs(): void
    {
        $package = new FilingPackage(new LegalCase(), 1, OpisPlacement::MERGED, new User());
        self::assertFalse($package->allPartsSignatureAccepted(), 'An empty package is never signed.');

        $petition = self::part(FilingPartRole::PETITION, 'tok-p', 1000);
        $annex = self::part(FilingPartRole::ANNEX_BUNDLE, 'tok-x', 5000);
        $package->addPart($petition)->addPart($annex);
        $petition->recordSignedUpload('s/p.pdf', str_repeat('p', 64), 1100, new User(), SignatureStatus::PRESENT_MATCHED, []);
        self::assertFalse($package->allPartsSignatureAccepted());

        $annex->recordSignedUpload('s/x.pdf', str_repeat('x', 64), 5100, new User(), SignatureStatus::PRESENT_MATCHED, []);
        self::assertTrue($package->allPartsSignatureAccepted());
    }

    public function testSignedTotalsFollowTheRealSignedBytesPerEmail(): void
    {
        $package = new FilingPackage(new LegalCase(), 1, OpisPlacement::MERGED, new User());
        $petition = self::part(FilingPartRole::PETITION, 'tok-p', 1000);
        $poa = self::part(FilingPartRole::POWER_OF_ATTORNEY, 'tok-i', 300);
        $annex = self::part(FilingPartRole::ANNEX_BUNDLE, 'tok-x', 5000);
        $package->addPart($petition)->addPart($poa)->addPart($annex);
        $package->setSizePlan(['emails' => FilingSizePlanner::emailBytes($package->getParts(), [['tok-p', 'tok-i'], ['tok-x']])]);
        self::assertSame(1, $package->emailIndexForPart('tok-x'));
        self::assertNull($package->emailIndexForPart('unknown'));

        $petition->recordSignedUpload('s/p.pdf', str_repeat('p', 64), 1500, new User(), SignatureStatus::PRESENT_MATCHED, []);
        $package->recomputeSignedTotals();

        self::assertSame(1500, $package->getTotalSignedBytes());
        $emails = $package->getSizePlan()['emails'];
        self::assertSame(1800, $emails[0]['bytes']);
        self::assertSame((int) ceil(1800 * FilingSizePlanner::ENCODING_FACTOR), $emails[0]['encodedBytes']);
        self::assertSame(5000, $emails[1]['bytes']);
    }

    private static function part(FilingPartRole $role, string $token, int $bytes): FilingPackagePart
    {
        return new FilingPackagePart($role, 1, 'Titlu', 2, 'unsigned/' . $token . '.pdf', str_repeat('0', 64), $bytes, $token);
    }
}
