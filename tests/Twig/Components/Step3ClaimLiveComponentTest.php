<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components;

use App\Enum\RelationshipType;
use App\Twig\Components\Step3ClaimLiveComponent;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Pas 3.3 — Step3ClaimLiveComponent recomputes BNR interest + fixed stamp
 * duty whenever the bound props change. Defensive fallbacks (null returns)
 * are critical: a half-filled wizard form must never crash the sidebar.
 *
 * Court resolver is intentionally NOT wired in this component — see the
 * component's docblock for why. Pas 4 handles it with the full LegalCase.
 */
final class Step3ClaimLiveComponentTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    public function testPropChangeRecomputesInterest(): void
    {
        $testComponent = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'amount' => 10000.0,
            'dueDate' => (new \DateTimeImmutable('-30 days'))->format('Y-m-d'),
            'relationshipType' => RelationshipType::COMERCIAL->value,
            'currency' => 'RON',
        ]);

        /** @var Step3ClaimLiveComponent $component */
        $component = $testComponent->component();
        $interest = $component->getInterest();

        self::assertNotNull($interest, 'Expected a positive interest on a 30-day-overdue B2B claim');
        self::assertGreaterThan(0.0, $interest->total);
    }

    public function testFutureDueDateReturnsNullInterest(): void
    {
        $testComponent = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'amount' => 5000.0,
            'dueDate' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'relationshipType' => RelationshipType::COMERCIAL->value,
            'currency' => 'RON',
        ]);

        /** @var Step3ClaimLiveComponent $component */
        $component = $testComponent->component();

        self::assertNull($component->getInterest());
        self::assertTrue($component->isDueDateInFuture());
    }

    public function testEurCurrencyReturnsNullInterest(): void
    {
        $testComponent = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'amount' => 1000.0,
            'dueDate' => (new \DateTimeImmutable('-60 days'))->format('Y-m-d'),
            'relationshipType' => RelationshipType::COMERCIAL->value,
            'currency' => 'EUR',
        ]);

        /** @var Step3ClaimLiveComponent $component */
        $component = $testComponent->component();

        self::assertNull(
            $component->getInterest(),
            'Interest calculator throws InvalidArgumentException on non-RON currencies; component must swallow it.',
        );
    }

    public function testCivilRelationshipReturnsNullInterest(): void
    {
        $testComponent = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'amount' => 1000.0,
            'dueDate' => (new \DateTimeImmutable('-60 days'))->format('Y-m-d'),
            'relationshipType' => RelationshipType::CIVIL->value,
            'currency' => 'RON',
        ]);

        /** @var Step3ClaimLiveComponent $component */
        $component = $testComponent->component();

        self::assertNull(
            $component->getInterest(),
            'CIVIL is B2C/P2P, out-of-scope for the B2B MVP — must surface as null.',
        );
    }

    public function testStampDutyAlwaysReturnsFixedAmount(): void
    {
        $testComponent = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'amount' => null,
            'dueDate' => null,
            'relationshipType' => null,
            'currency' => 'RON',
        ]);

        /** @var Step3ClaimLiveComponent $component */
        $component = $testComponent->component();
        $stampDuty = $component->getStampDuty();

        self::assertSame(200.0, $stampDuty->amount, 'OUG 80/2013 art. 6 alin. 2 — fixed 200 RON for OP cases.');
    }

    public function testMissingAmountReturnsNullInterest(): void
    {
        $testComponent = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'amount' => null,
            'dueDate' => (new \DateTimeImmutable('-30 days'))->format('Y-m-d'),
            'relationshipType' => RelationshipType::COMERCIAL->value,
            'currency' => 'RON',
        ]);

        /** @var Step3ClaimLiveComponent $component */
        $component = $testComponent->component();

        self::assertNull($component->getInterest());
    }
}
