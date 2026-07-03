<?php

namespace App\Tests\Service\Validation;

use App\Entity\Debtor;
use App\Entity\LegalCase;
use App\Enum\AnafStatus;
use App\Enum\IssueSeverity;
use App\Enum\PersonType;
use App\Service\Validation\OpAdmissibilityValidator;
use PHPUnit\Framework\TestCase;

class OpAdmissibilityValidatorTest extends TestCase
{
    private const NOW = '2026-05-09 12:00:00';

    public function testActivePjFullyVerifiedReturnsNoIssue(): void
    {
        $case = $this->makeCaseWithDebtor($this->makeDebtor(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: $this->daysAgo(3),
            inInsolvency: false,
            insolvencyCheckedAt: $this->daysAgo(3),
        ));

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $this->assertSame([], $issues);
    }

    public function testRadiatBlocksWithError(): void
    {
        $case = $this->makeCaseWithDebtor($this->makeDebtor(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::RADIAT,
            anafCheckedAt: $this->daysAgo(3),
            insolvencyCheckedAt: $this->daysAgo(3),
        ));

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $this->assertCount(1, $issues);
        $this->assertSame(IssueSeverity::ERROR, $issues[0]->severity);
        $this->assertSame('OP_BLOCKED_DEREGISTERED', $issues[0]->code);
        $this->assertSame('validation.op_admissibility.OP_BLOCKED_DEREGISTERED', $issues[0]->messageKey);
    }

    public function testInsolvencyBlocksWithError(): void
    {
        $case = $this->makeCaseWithDebtor($this->makeDebtor(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: $this->daysAgo(3),
            inInsolvency: true,
            insolvencyCheckedAt: null, // Even with null BPI, rule 2 fail-fasts.
        ));

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $this->assertCount(1, $issues, 'Rule 2 must fail-fast and not emit other issues.');
        $this->assertSame(IssueSeverity::ERROR, $issues[0]->severity);
        $this->assertSame('OP_BLOCKED_INSOLVENCY', $issues[0]->code);
    }

    public function testInactivProducesWarning(): void
    {
        $case = $this->makeCaseWithDebtor($this->makeDebtor(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::INACTIV,
            anafCheckedAt: $this->daysAgo(3),
            insolvencyCheckedAt: $this->daysAgo(3),
        ));

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $this->assertCount(1, $issues);
        $this->assertSame(IssueSeverity::WARNING, $issues[0]->severity);
        $this->assertSame('OP_DEFENDANT_FISCALLY_INACTIVE', $issues[0]->code);
    }

    public function testAnafStatusNullProducesWarningButNotStale(): void
    {
        $case = $this->makeCaseWithDebtor($this->makeDebtor(
            personType: PersonType::PJ,
            anafStatus: null,
            anafCheckedAt: null,
            insolvencyCheckedAt: $this->daysAgo(3),
        ));

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $codes = array_map(fn ($i) => $i->code, $issues);
        $this->assertContains('OP_ANAF_NOT_VERIFIED', $codes);
        $this->assertNotContains('OP_ANAF_STALE', $codes, 'Stale must NOT trigger when status is null.');
        $this->assertCount(1, $issues);
        $this->assertSame(IssueSeverity::WARNING, $issues[0]->severity);
    }

    public function testAnafStaleProducesWarning(): void
    {
        $case = $this->makeCaseWithDebtor($this->makeDebtor(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: $this->daysAgo(31),
            insolvencyCheckedAt: $this->daysAgo(3),
        ));

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $this->assertCount(1, $issues);
        $this->assertSame(IssueSeverity::WARNING, $issues[0]->severity);
        $this->assertSame('OP_ANAF_STALE', $issues[0]->code);
    }

    public function testInsolvencyNotVerifiedBlocksWithError(): void
    {
        $case = $this->makeCaseWithDebtor($this->makeDebtor(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: $this->daysAgo(3),
            insolvencyCheckedAt: null,
        ));

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $this->assertCount(1, $issues);
        $this->assertSame(IssueSeverity::ERROR, $issues[0]->severity, 'N4: rule 6 is ERROR, not WARNING.');
        $this->assertSame('OP_INSOLVENCY_NOT_VERIFIED', $issues[0]->code);
    }

    public function testInsolvencyStaleBlocksWithError(): void
    {
        $case = $this->makeCaseWithDebtor($this->makeDebtor(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: $this->daysAgo(3),
            insolvencyCheckedAt: $this->daysAgo(8),
        ));

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $this->assertCount(1, $issues);
        $this->assertSame(IssueSeverity::ERROR, $issues[0]->severity, 'N4: stale BPI is ERROR (>7 days).');
        $this->assertSame('OP_INSOLVENCY_STALE', $issues[0]->code);
    }

    public function testBoundaryConditionsAtThresholdEdgesPass(): void
    {
        // Exact sub praguri: ANAF la 29 zile (prag 30), BPI la 6 zile (prag 7) — nicio issue.
        $case = $this->makeCaseWithDebtor($this->makeDebtor(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: $this->daysAgo(29),
            insolvencyCheckedAt: $this->daysAgo(6),
        ));

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $this->assertSame([], $issues);
    }

    public function testAnafStatusSetWithoutTimestampIsTreatedAsStale(): void
    {
        // CR-W1 fix: stare inconsistentă (anafStatus != null + anafCheckedAt = null)
        // nu mai e raportată silent ca OK; emite OP_ANAF_STALE.
        $case = $this->makeCaseWithDebtor($this->makeDebtor(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: null,
            insolvencyCheckedAt: $this->daysAgo(3),
        ));

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $this->assertCount(1, $issues);
        $this->assertSame(IssueSeverity::WARNING, $issues[0]->severity);
        $this->assertSame('OP_ANAF_STALE', $issues[0]->code);
    }

    public function testInactivAndBpiNullProducesBothIssues(): void
    {
        // INACTIV nu fail-fast (e WARNING, nu ERROR); rule 6 (BPI null = ERROR) coexistă.
        $case = $this->makeCaseWithDebtor($this->makeDebtor(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::INACTIV,
            anafCheckedAt: $this->daysAgo(3),
            insolvencyCheckedAt: null,
        ));

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $this->assertCount(2, $issues);
        $codes = array_map(fn ($i) => $i->code, $issues);
        $this->assertContains('OP_DEFENDANT_FISCALLY_INACTIVE', $codes);
        $this->assertContains('OP_INSOLVENCY_NOT_VERIFIED', $codes);
    }

    public function testPfDebtorReturnsManualBipfWarning(): void
    {
        $case = $this->makeCaseWithDebtor($this->makeDebtor(
            personType: PersonType::PF,
            anafStatus: null,
            anafCheckedAt: null,
            insolvencyCheckedAt: null,
        ));

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $this->assertCount(1, $issues, 'PF debtor: WARNING static ca memento pentru BIPF (L 151/2015).');
        $this->assertSame(IssueSeverity::WARNING, $issues[0]->severity);
        $this->assertSame('OP_PF_BIPF_MANUAL_CHECK', $issues[0]->code);
        $this->assertSame('validation.op_admissibility.OP_PF_BIPF_MANUAL_CHECK', $issues[0]->messageKey);
    }

    public function testMultipleDebtorsAggregateIssues(): void
    {
        $case = new LegalCase();
        $case->addDebtor($this->makeDebtor(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::RADIAT,
            anafCheckedAt: $this->daysAgo(3),
            insolvencyCheckedAt: $this->daysAgo(3),
        ));
        $case->addDebtor($this->makeDebtor(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: $this->daysAgo(3),
            insolvencyCheckedAt: $this->daysAgo(3),
        ));

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $this->assertCount(1, $issues, 'Only the RADIAT debtor produces an issue.');
        $this->assertSame('OP_BLOCKED_DEREGISTERED', $issues[0]->code);
    }

    public function testFutureDueDateBlocksWithError(): void
    {
        // Debtor is otherwise fully clean; the future due date alone is the blocker.
        $case = $this->makeCaseWithDebtor($this->makeDebtor(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: $this->daysAgo(3),
            insolvencyCheckedAt: $this->daysAgo(3),
        ));
        $case->setDueDate($this->now()->modify('+10 days'));

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $this->assertCount(1, $issues);
        $this->assertSame(IssueSeverity::ERROR, $issues[0]->severity);
        $this->assertSame('OP_DEBT_NOT_YET_DUE', $issues[0]->code);
        $this->assertSame('validation.op_admissibility.OP_DEBT_NOT_YET_DUE', $issues[0]->messageKey);
    }

    public function testDueDateTodayIsExigibleAndProducesNoIssue(): void
    {
        // Same calendar day as `now` (after midnight normalization) is exigible.
        $case = $this->makeCaseWithDebtor($this->makeDebtor(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: $this->daysAgo(3),
            insolvencyCheckedAt: $this->daysAgo(3),
        ));
        $case->setDueDate($this->now());

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $this->assertSame([], $issues);
    }

    public function testPastDueDateProducesNoExigibilityIssue(): void
    {
        $case = $this->makeCaseWithDebtor($this->makeDebtor(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: $this->daysAgo(3),
            insolvencyCheckedAt: $this->daysAgo(3),
        ));
        $case->setDueDate($this->now()->modify('-30 days'));

        $issues = (new OpAdmissibilityValidator())->validate($case, $this->now());

        $this->assertSame([], $issues);
    }

    private function makeCaseWithDebtor(Debtor $debtor): LegalCase
    {
        $case = new LegalCase();
        $case->addDebtor($debtor);

        return $case;
    }

    private function makeDebtor(
        PersonType $personType,
        ?AnafStatus $anafStatus = null,
        ?\DateTimeImmutable $anafCheckedAt = null,
        bool $inInsolvency = false,
        ?\DateTimeImmutable $insolvencyCheckedAt = null,
    ): Debtor {
        $debtor = new Debtor();
        $debtor->setPersonType($personType);
        $debtor->setName('Debitor Test');
        $debtor->setAddress('Str. Test 1');
        $debtor->setAnafStatus($anafStatus);
        $debtor->setAnafCheckedAt($anafCheckedAt);
        $debtor->setInInsolvency($inInsolvency);
        $debtor->setInsolvencyCheckedAt($insolvencyCheckedAt);

        return $debtor;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function daysAgo(int $days): \DateTimeImmutable
    {
        return $this->now()->modify(sprintf('-%d days', $days));
    }
}
