<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\DeadlineCertainty;
use App\Enum\DeadlinePriority;
use App\Enum\DeadlineType;
use App\Service\Deadline\DeadlineCertaintyResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A deadline is CERT only when the fact its term runs from carries a confirmed date
 * on the case. Each type is pinned against both states of its generating fact, so a
 * date can never be presented as certain once the field it depends on is emptied.
 */
class DeadlineCertaintyResolverTest extends TestCase
{
    private DeadlineCertaintyResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DeadlineCertaintyResolver();
    }

    public function testSummonsAnswerIsCertainOnlyWithTheCommunicationDate(): void
    {
        self::assertSame(
            DeadlineCertainty::ESTIMAT,
            $this->resolver->resolve(DeadlineType::RASPUNS_SOMATIE, $this->case()),
        );
        self::assertSame(
            DeadlineCertainty::CERT,
            $this->resolver->resolve(DeadlineType::RASPUNS_SOMATIE, $this->case(noticeCommunicated: true)),
        );
    }

    /** @return iterable<string, array{DeadlineType}> */
    public static function rulingDependentTypeProvider(): iterable
    {
        yield 'enforcement limitation' => [DeadlineType::PRESCRIPTIE_EXECUTARE];
        yield 'annulment request' => [DeadlineType::CERERE_IN_ANULARE];
    }

    #[DataProvider('rulingDependentTypeProvider')]
    public function testRulingDependentTypesAreCertainOnlyWithTheRulingCommunicationDate(DeadlineType $type): void
    {
        self::assertSame(DeadlineCertainty::ESTIMAT, $this->resolver->resolve($type, $this->case()));
        self::assertSame(DeadlineCertainty::CERT, $this->resolver->resolve($type, $this->case(rulingCommunicated: true)));
    }

    /**
     * Inverted on purpose: a communicated summons interrupts the limitation period
     * (CPC art. 1015 para. 2 referring to NCC art. 2540), an interruption the model
     * does not carry, so the stored date stops being the real one.
     */
    public function testLimitationStopsBeingCertainOnceTheSummonsIsCommunicated(): void
    {
        self::assertSame(
            DeadlineCertainty::CERT,
            $this->resolver->resolve(DeadlineType::PRESCRIPTIE, $this->case()),
        );
        self::assertSame(
            DeadlineCertainty::ESTIMAT,
            $this->resolver->resolve(DeadlineType::PRESCRIPTIE, $this->case(noticeCommunicated: true)),
        );
    }

    /** @return iterable<string, array{DeadlineType}> */
    public static function alwaysCertainTypeProvider(): iterable
    {
        yield 'stamp duty' => [DeadlineType::TIMBRARE];
        yield 'hearing' => [DeadlineType::JUDECATA];
        yield 'free form term' => [DeadlineType::OTHER];
    }

    #[DataProvider('alwaysCertainTypeProvider')]
    public function testTypesWithoutADerivedDateAreAlwaysCertain(DeadlineType $type): void
    {
        self::assertSame(DeadlineCertainty::CERT, $this->resolver->resolve($type, $this->case()));
        self::assertSame(
            DeadlineCertainty::CERT,
            $this->resolver->resolve($type, $this->case(noticeCommunicated: true, rulingCommunicated: true)),
        );
    }

    public function testFilingReminderIsAlwaysEstimated(): void
    {
        self::assertSame(DeadlineCertainty::ESTIMAT, $this->resolver->resolve(DeadlineType::DEPUNERE_CERERE, $this->case()));
        self::assertSame(
            DeadlineCertainty::ESTIMAT,
            $this->resolver->resolve(DeadlineType::DEPUNERE_CERERE, $this->case(noticeCommunicated: true, rulingCommunicated: true)),
        );
    }

    public function testEveryDeadlineTypeResolvesInBothStatesOfTheCase(): void
    {
        foreach (DeadlineType::cases() as $type) {
            // Would raise UnhandledMatchError if a type had no branch.
            $this->resolver->resolve($type, $this->case());
            $this->resolver->resolve($type, $this->case(noticeCommunicated: true, rulingCommunicated: true));
        }

        self::assertCount(8, DeadlineType::cases(), 'A new deadline type needs an explicit certainty rule');
    }

    public function testResolveForReadsTypeAndCaseOfTheDeadline(): void
    {
        $case = $this->case(rulingCommunicated: true);
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($case);
        $deadline->setType(DeadlineType::CERERE_IN_ANULARE);
        $deadline->setDeadlineDate(new \DateTimeImmutable('2026-08-01'));
        $deadline->setPriority(DeadlinePriority::CRITICAL);

        self::assertSame(DeadlineCertainty::CERT, $this->resolver->resolveFor($deadline));

        $case->setRulingCommunicationDate(null);
        self::assertSame(DeadlineCertainty::ESTIMAT, $this->resolver->resolveFor($deadline));
    }

    private function case(bool $noticeCommunicated = false, bool $rulingCommunicated = false): LegalCase
    {
        $case = new LegalCase();
        if ($noticeCommunicated) {
            $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('2026-06-01'));
        }
        if ($rulingCommunicated) {
            $case->setRulingCommunicationDate(new \DateTimeImmutable('2026-06-15'));
        }

        return $case;
    }
}
