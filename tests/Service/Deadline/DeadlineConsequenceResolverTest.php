<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\DeadlineConsequence;
use App\Enum\DeadlinePriority;
use App\Enum\DeadlineType;
use App\Service\Deadline\DeadlineConsequenceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The mapping type to consequence follows the law, so it is pinned here type by
 * type. The coverage test exists so a new DeadlineType cannot be added without a
 * deliberate decision about what missing it costs.
 */
class DeadlineConsequenceResolverTest extends TestCase
{
    private DeadlineConsequenceResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DeadlineConsequenceResolver();
    }

    /** @return array<string, array{DeadlineType, DeadlineConsequence, int, bool}> */
    public static function typeExpectationProvider(): array
    {
        return [
            'stamp duty annuls the claim' => [DeadlineType::TIMBRARE, DeadlineConsequence::CASE_ANNULMENT, 30, true],
            'annulment request is forfeited' => [DeadlineType::CERERE_IN_ANULARE, DeadlineConsequence::FORFEITURE, 40, true],
            'limitation extinguishes the right' => [DeadlineType::PRESCRIPTIE, DeadlineConsequence::RIGHT_EXTINCTION, 50, true],
            'enforcement limitation extinguishes the right' => [DeadlineType::PRESCRIPTIE_EXECUTARE, DeadlineConsequence::RIGHT_EXTINCTION, 50, true],
            'hearing costs only the appearance' => [DeadlineType::JUDECATA, DeadlineConsequence::APPEARANCE, 20, false],
            'summons answer is the debtor term' => [DeadlineType::RASPUNS_SOMATIE, DeadlineConsequence::NO_SANCTION, 0, false],
            'filing reminder has no sanction' => [DeadlineType::DEPUNERE_CERERE, DeadlineConsequence::RECORD_KEEPING, 10, false],
            'free form term has no sanction' => [DeadlineType::OTHER, DeadlineConsequence::RECORD_KEEPING, 10, false],
        ];
    }

    #[DataProvider('typeExpectationProvider')]
    public function testResolvesConsequenceRankAndReversibilityPerType(
        DeadlineType $type,
        DeadlineConsequence $expected,
        int $expectedRank,
        bool $expectedIrreversible,
    ): void {
        self::assertSame($expected, $this->resolver->resolve($type));
        self::assertSame($expectedRank, $this->resolver->severityRank($type));
        self::assertSame($expectedIrreversible, $this->resolver->isIrreversible($type));
    }

    /**
     * Guards the mapping against a newly added deadline type: both the resolver and
     * this test's expectations must be extended together.
     */
    public function testEveryDeadlineTypeIsMappedAndCovered(): void
    {
        $covered = array_map(
            static fn (array $row): DeadlineType => $row[0],
            array_values(self::typeExpectationProvider()),
        );

        foreach (DeadlineType::cases() as $type) {
            self::assertContains($type, $covered, sprintf('DeadlineType::%s has no expectation in this test', $type->name));
            // Would raise UnhandledMatchError if the resolver had no branch for it.
            $this->resolver->resolve($type);
        }

        self::assertCount(\count(DeadlineType::cases()), $covered);
    }

    public function testExactlyTheFourFatalTypesAreIrreversible(): void
    {
        $irreversible = array_values(array_filter(
            DeadlineType::cases(),
            fn (DeadlineType $type): bool => $this->resolver->isIrreversible($type),
        ));

        self::assertSame([
            DeadlineType::CERERE_IN_ANULARE,
            DeadlineType::TIMBRARE,
            DeadlineType::PRESCRIPTIE,
            DeadlineType::PRESCRIPTIE_EXECUTARE,
        ], $irreversible);
    }

    /**
     * The exported set must stay the mapping itself, not a copy of it: the agenda
     * risk bar counts the fatal deadlines through this method, so a divergence
     * would be invisible on screen.
     */
    public function testIrreversibleTypesExportsExactlyTheMappedFatalSet(): void
    {
        $expected = array_values(array_filter(
            DeadlineType::cases(),
            fn (DeadlineType $type): bool => $this->resolver->resolve($type)->isIrreversible(),
        ));

        self::assertSame($expected, $this->resolver->irreversibleTypes());
    }

    public function testRanksOrderTheIrreversibleTypesAboveTheRest(): void
    {
        self::assertGreaterThan(
            $this->resolver->severityRank(DeadlineType::JUDECATA),
            $this->resolver->severityRank(DeadlineType::TIMBRARE),
        );
        self::assertGreaterThan(
            $this->resolver->severityRank(DeadlineType::RASPUNS_SOMATIE),
            $this->resolver->severityRank(DeadlineType::OTHER),
        );
    }

    public function testResolveForReadsTheTypeOfTheDeadline(): void
    {
        $deadline = new LegalDeadline();
        $deadline->setLegalCase(new LegalCase());
        $deadline->setType(DeadlineType::TIMBRARE);
        $deadline->setDeadlineDate(new \DateTimeImmutable('2026-08-01'));
        $deadline->setPriority(DeadlinePriority::CRITICAL);

        self::assertSame(DeadlineConsequence::CASE_ANNULMENT, $this->resolver->resolveFor($deadline));
    }
}
