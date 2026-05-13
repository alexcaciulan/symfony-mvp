<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\DTO\Calculation\InterestResult;
use App\DTO\Calculation\StampDutyResult;
use App\Enum\RelationshipType;
use App\Service\Calculation\InterestCalculatorService;
use App\Service\Calculation\StampDutyCalculator;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Pas 3.3 — live sidebar for wizard Step 3. Recomputes BNR interest + fixed
 * stamp duty whenever the user edits amount / dueDate / relationshipType /
 * currency. The court resolver is intentionally NOT wired here: the resolver
 * needs structured county/locality which Step 2 stores as free-text address.
 * The court is calculated at Step 4 with the complete LegalCase skeleton.
 *
 * All compute getters are defensive — every realistic failure mode of the
 * calculators surfaces as a null/0 return so the template renders a placeholder
 * instead of crashing the wizard on a partially-filled form. Specifically:
 *
 *   - dueDate in the future → InterestCalculatorService returns total=0
 *     (early-out at the gte-referenceDate guard); we still null it out here so
 *     the template renders the explicit "termen scadență invalid" hint.
 *   - currency != RON → InvalidArgumentException → null.
 *   - relationshipType = CIVIL → DomainException from
 *     RelationshipType::applicableRate (B2B-only MVP) → null.
 *   - missing BNR config covering the dueDate → RuntimeException → null.
 *
 * The sidebar is render-only — Pas 3.2's `CaseWizardController::claim` keeps
 * the form's POST handler; this component only handles the right-hand calc
 * card.
 */
#[AsLiveComponent(name: 'Step3ClaimLiveComponent')]
final class Step3ClaimLiveComponent
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public ?float $amount = null;

    /** ISO `Y-m-d` string — bound to the wizard's `<input type="date">`. */
    #[LiveProp(writable: true)]
    public ?string $dueDate = null;

    /** RelationshipType enum value ("COMERCIAL" | "CIVIL"). */
    #[LiveProp(writable: true)]
    public ?string $relationshipType = null;

    #[LiveProp(writable: true)]
    public string $currency = 'RON';

    public function __construct(
        private readonly InterestCalculatorService $interestService,
        private readonly StampDutyCalculator $stampDutyCalculator,
    ) {}

    public function getInterest(): ?InterestResult
    {
        if ($this->amount === null || $this->amount <= 0.0) {
            return null;
        }
        if ($this->dueDate === null || $this->dueDate === '') {
            return null;
        }
        if ($this->relationshipType === null) {
            return null;
        }

        $dueDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->dueDate);
        if ($dueDate === false) {
            return null;
        }

        $now = new \DateTimeImmutable('today');
        if ($dueDate >= $now) {
            // Future / today → no interest accrues yet; we surface as null so
            // the template prints the explicit "due_date_future" placeholder
            // instead of "0,00 RON" (which looks like a calculated zero).
            return null;
        }

        $relationship = RelationshipType::tryFrom($this->relationshipType);
        if ($relationship === null) {
            return null;
        }

        try {
            return $this->interestService->calculate(
                amount: $this->amount,
                dueDate: $dueDate,
                referenceDate: $now,
                relationshipType: $relationship,
                currency: $this->currency,
            );
        } catch (\DomainException | \InvalidArgumentException | \RuntimeException) {
            return null;
        }
    }

    public function getStampDuty(): StampDutyResult
    {
        return $this->stampDutyCalculator->calculate();
    }

    /**
     * Convenience for the template — flags that get-interest came back null
     * because the user picked a future date (most-likely UX gotcha). Other
     * null causes (missing amount, unsupported currency) are surfaced by
     * inspecting the props directly in the template.
     */
    public function isDueDateInFuture(): bool
    {
        if ($this->dueDate === null || $this->dueDate === '') {
            return false;
        }
        $dueDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->dueDate);
        if ($dueDate === false) {
            return false;
        }

        return $dueDate >= new \DateTimeImmutable('today');
    }
}
