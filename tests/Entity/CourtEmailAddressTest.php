<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Court;
use App\Entity\CourtEmailAddress;
use App\Entity\User;
use App\Enum\CourtEmailPurpose;
use App\Enum\CourtEmailSource;
use App\Enum\CourtEmailStatus;
use PHPUnit\Framework\TestCase;

final class CourtEmailAddressTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-22 12:00:00');
    }

    public function testEmailIsTrimmedAndLowercased(): void
    {
        $address = $this->address(CourtEmailPurpose::REGISTRY);
        $address->setEmail('  Registratura.Test@JUST.ro ');

        self::assertSame('registratura.test@just.ro', $address->getEmail());
    }

    public function testValidationExpiresOnlyAfterOneHundredEightyDays(): void
    {
        $fresh = $this->validated(CourtEmailPurpose::REGISTRY, $this->now->modify('-179 days'));
        $stale = $this->validated(CourtEmailPurpose::REGISTRY, $this->now->modify('-181 days'));

        self::assertFalse($fresh->isValidationExpired($this->now));
        self::assertTrue($fresh->isUsableForFiling($this->now));
        self::assertTrue($stale->isValidationExpired($this->now));
        self::assertFalse($stale->isUsableForFiling($this->now));
    }

    public function testAnAddressMissingFromItsSourceIsExpiredWhateverItsDate(): void
    {
        $address = $this->validated(CourtEmailPurpose::REGISTRY, $this->now->modify('-1 day'));
        $address->setMissingSince($this->now->modify('-1 hour'));

        self::assertTrue($address->isValidationExpired($this->now));
        self::assertFalse($address->isUsableForFiling($this->now));
    }

    public function testOnlyAConfirmedFilingPurposeAddressIsUsable(): void
    {
        self::assertFalse($this->validated(CourtEmailPurpose::PERSONAL, $this->now)->isUsableForFiling($this->now));
        self::assertFalse($this->validated(CourtEmailPurpose::OTHER, $this->now)->isUsableForFiling($this->now));
        self::assertFalse($this->address(CourtEmailPurpose::REGISTRY)->isUsableForFiling($this->now));

        $invalid = $this->validated(CourtEmailPurpose::REGISTRY, $this->now);
        $invalid->markInvalid($this->now, 'admin:BOUNCED');
        self::assertSame(CourtEmailStatus::INVALID, $invalid->getStatus());
        self::assertFalse($invalid->isUsableForFiling($this->now));

        self::assertTrue($this->validated(CourtEmailPurpose::SECTION_CIVIL, $this->now)->isUsableForFiling($this->now));
    }

    private function address(CourtEmailPurpose $purpose): CourtEmailAddress
    {
        return new CourtEmailAddress(new Court(), 'registratura@just.ro', $purpose, CourtEmailSource::MANUAL, $this->now->modify('-1 year'));
    }

    private function validated(CourtEmailPurpose $purpose, \DateTimeImmutable $at): CourtEmailAddress
    {
        return $this->address($purpose)->markValidated(new User(), $at, 'statement');
    }
}
