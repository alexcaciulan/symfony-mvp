<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components;

use App\DTO\Wizard\Step3ClaimData;
use App\Enum\RelationshipType;
use App\Twig\Components\Step3ClaimLiveComponent;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Pas 3.3 — Step3ClaimLiveComponent recomputes BNR interest + fixed stamp
 * duty whenever the bound `formValues` change. Defensive fallbacks (null
 * returns) are critical: a half-filled wizard form must never crash the
 * sidebar.
 *
 * Refactored to ComponentWithFormTrait — the component now owns the form
 * rendering AND the calculation sidebar. Live updates via `data-model`
 * mutate `formValues` directly; the getters read from there.
 *
 * Court resolver is intentionally NOT wired in this component — see the
 * component's docblock for why. Pas 4 handles it with the full LegalCase.
 */
final class Step3ClaimLiveComponentTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    protected function setUp(): void
    {
        static::createClient();
        // Step3ClaimType has CSRF protection; the component instantiates the
        // form OUTSIDE an HTTP request so the token storage needs a session
        // to read/write. Push a synthetic Request with an in-memory session
        // (same pattern as Step2DebtorsLiveComponentTest).
        $stack = static::getContainer()->get(RequestStack::class);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack->push($request);
    }

    public function testPropChangeRecomputesInterest(): void
    {
        $testComponent = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'initialFormData' => new Step3ClaimData(
                amount: 10000.0,
                currency: 'RON',
                dueDate: new \DateTimeImmutable('-30 days'),
                relationshipType: RelationshipType::COMERCIAL,
            ),
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
            'initialFormData' => new Step3ClaimData(
                amount: 5000.0,
                currency: 'RON',
                dueDate: new \DateTimeImmutable('+10 days'),
                relationshipType: RelationshipType::COMERCIAL,
            ),
        ]);

        /** @var Step3ClaimLiveComponent $component */
        $component = $testComponent->component();

        self::assertNull($component->getInterest());
        self::assertTrue($component->isDueDateInFuture());
    }

    public function testEurCurrencyReturnsNullInterest(): void
    {
        $testComponent = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'initialFormData' => new Step3ClaimData(
                amount: 1000.0,
                currency: 'EUR',
                dueDate: new \DateTimeImmutable('-60 days'),
                relationshipType: RelationshipType::COMERCIAL,
            ),
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
        // CIVIL can't reach the LiveComponent via DTO+Form path — the form's
        // choice filter only accepts COMERCIAL — so we simulate a malicious
        // client (or a future DTO that allows CIVIL) by mutating `formValues`
        // directly. The getInterest() path must still swallow the DomainException
        // defensively, otherwise a bad value would crash the sidebar.
        $testComponent = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'initialFormData' => new Step3ClaimData(
                amount: 1000.0,
                currency: 'RON',
                dueDate: new \DateTimeImmutable('-60 days'),
                relationshipType: RelationshipType::COMERCIAL,
            ),
        ]);

        /** @var Step3ClaimLiveComponent $component */
        $component = $testComponent->component();
        // Force CIVIL into the live state, bypassing the form's choice filter.
        $component->formValues['relationshipType'] = RelationshipType::CIVIL->value;

        self::assertNull(
            $component->getInterest(),
            'CIVIL is B2C/P2P, out-of-scope for the B2B MVP — must surface as null.',
        );
    }

    public function testStampDutyAlwaysReturnsFixedAmount(): void
    {
        $testComponent = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'initialFormData' => new Step3ClaimData(),
        ]);

        /** @var Step3ClaimLiveComponent $component */
        $component = $testComponent->component();
        $stampDuty = $component->getStampDuty();

        self::assertSame(200.0, $stampDuty->amount, 'OUG 80/2013 art. 6 alin. 2 — fixed 200 RON for OP cases.');
    }

    public function testRenderedFormCarriesLiveDataModelForReRenderOnChange(): void
    {
        // Sanity guard for the "live recalc on field change" UX: the form tag
        // must carry the `data-model="on(change)|*"` auto-applied by
        // ComponentWithFormTrait, otherwise the sidebar wouldn't recompute
        // when the user edits amount/dueDate/currency.
        $testComponent = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'initialFormData' => new Step3ClaimData(
                amount: 1000.0,
                currency: 'RON',
                dueDate: new \DateTimeImmutable('-30 days'),
                relationshipType: RelationshipType::COMERCIAL,
            ),
        ]);

        $html = $testComponent->render()->toString();

        self::assertStringContainsString('data-model="on(change)|*"', $html);
        self::assertStringContainsString('id="step3-claim-form"', $html);
    }

    public function testMissingAmountReturnsNullInterest(): void
    {
        $testComponent = $this->createLiveComponent('Step3ClaimLiveComponent', [
            'initialFormData' => new Step3ClaimData(
                amount: null,
                currency: 'RON',
                dueDate: new \DateTimeImmutable('-30 days'),
                relationshipType: RelationshipType::COMERCIAL,
            ),
        ]);

        /** @var Step3ClaimLiveComponent $component */
        $component = $testComponent->component();

        self::assertNull($component->getInterest());
    }
}
