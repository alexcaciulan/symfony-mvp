<?php

namespace App\Tests\Entity;

use App\Entity\LegalCase;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class LegalCaseEntityTest extends TestCase
{
    public function testGetClaimantNameReturnsNameFromData(): void
    {
        $case = new LegalCase();
        $case->setClaimantData(['name' => 'Ion Popescu', 'email' => 'ion@test.com']);

        $this->assertSame('Ion Popescu', $case->getClaimantName());
    }

    public function testGetClaimantNameReturnsNullWhenNoData(): void
    {
        $case = new LegalCase();

        $this->assertNull($case->getClaimantName());
    }

    public function testGetClaimantNameReturnsNullWhenNoNameKey(): void
    {
        $case = new LegalCase();
        $case->setClaimantData(['email' => 'ion@test.com']);

        $this->assertNull($case->getClaimantName());
    }

    public function testGetFirstDefendantNameReturnsFirstEntry(): void
    {
        $case = new LegalCase();
        $case->setDefendants([
            ['name' => 'Defendant One', 'city' => 'Bucharest'],
            ['name' => 'Defendant Two', 'city' => 'Cluj'],
        ]);

        $this->assertSame('Defendant One', $case->getFirstDefendantName());
    }

    public function testGetFirstDefendantNameReturnsNullWhenEmpty(): void
    {
        $case = new LegalCase();
        $case->setDefendants([]);

        $this->assertNull($case->getFirstDefendantName());
    }

    public function testGetFirstDefendantNameReturnsNullWhenNull(): void
    {
        $case = new LegalCase();

        $this->assertNull($case->getFirstDefendantName());
    }

    public function testIsDeletedReturnsCorrectValue(): void
    {
        $case = new LegalCase();
        $this->assertFalse($case->isDeleted());

        $case->setDeletedAt(new \DateTimeImmutable());
        $this->assertTrue($case->isDeleted());
    }

    public function testDefaultStatusIsDraft(): void
    {
        $case = new LegalCase();
        $this->assertSame('draft', $case->getStatus());
    }

    public function testDefaultCurrentStepIsOne(): void
    {
        $case = new LegalCase();
        $this->assertSame(1, $case->getCurrentStep());
    }

    public function testToStringFormat(): void
    {
        $case = new LegalCase();
        $this->assertSame('Dosar #nou', (string) $case);
    }

    public function testCreatedAtSetInConstructor(): void
    {
        $before = new \DateTimeImmutable();
        $case = new LegalCase();
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $case->getCreatedAt()->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $case->getCreatedAt()->getTimestamp());
    }
}
