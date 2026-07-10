<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\DTO\Calculation\CurrencyConversionResult;
use App\DTO\Calculation\InterestResult;
use App\DTO\Calculation\StampDutyResult;
use App\DTO\Wizard\Step3ClaimData;
use App\Enum\RelationshipType;
use App\Form\Wizard\Step3ClaimType;
use App\Service\Calculation\CurrencyConverter;
use App\Service\Calculation\InterestCalculatorService;
use App\Service\Calculation\StampDutyCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Pas 3.3 wizard step 3 — live form + sidebar calculations.
 *
 * Architecture: the component now owns the FORM rendering (Symfony UX
 * `ComponentWithFormTrait`) — earlier slot-based pattern crashed on LC
 * re-render because the parent template's `<twig:block name="form_slot">`
 * referenced `form`, which was a parent-scope variable not propagated to the
 * embedded re-render context. With the trait, `form` is auto-exposed via
 * `getFormView()` and rebuilt from `$initialFormData` (a serializable
 * `Step3ClaimData` DTO LiveProp) on every render, so live updates from
 * `data-model` bindings work without losing the form.
 *
 * The sidebar recomputes BNR interest + fixed stamp duty whenever the user
 * edits a field. The court resolver is intentionally NOT wired here: it
 * needs structured county/locality which Step 2 stores as free-text address.
 * Court is calculated at Step 4 with the complete LegalCase skeleton.
 *
 * All compute getters are defensive — every realistic failure mode of the
 * calculators surfaces as a null/0 return so the template renders a
 * placeholder instead of crashing the wizard on a partially-filled form.
 */
#[AsLiveComponent(name: 'Step3ClaimLiveComponent')]
final class Step3ClaimLiveComponent extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public ?Step3ClaimData $initialFormData = null;

    public function __construct(
        private readonly InterestCalculatorService $interestService,
        private readonly StampDutyCalculator $stampDutyCalculator,
        private readonly CurrencyConverter $currencyConverter,
    ) {}

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(Step3ClaimType::class, $this->initialFormData);
    }

    public function getInterest(): ?InterestResult
    {
        $amount = $this->floatFromFormValues('amount');
        if ($amount === null || $amount <= 0.0) {
            return null;
        }

        $dueDateRaw = $this->stringFromFormValues('dueDate');
        if ($dueDateRaw === null) {
            return null;
        }
        $dueDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueDateRaw);
        if ($dueDate === false) {
            return null;
        }

        $relationshipRaw = $this->stringFromFormValues('relationshipType');
        if ($relationshipRaw === null) {
            return null;
        }
        $relationship = RelationshipType::tryFrom($relationshipRaw);
        if ($relationship === null) {
            return null;
        }

        $now = new \DateTimeImmutable('today');
        if ($dueDate >= $now) {
            // Future / today → no interest accrues yet; we surface as null so
            // the template prints the explicit "due_date_future" placeholder
            // instead of "0,00 RON" (which looks like a calculated zero).
            return null;
        }

        // Foreign currency: interest is computed on the RON-converted principal.
        // If the conversion can't be resolved yet (no invoice date / missing
        // rate) we return null and the template shows the FX placeholder.
        $ronAmount = $amount;
        if ($this->getCurrency() !== 'RON') {
            $conversion = $this->getConversion();
            if ($conversion === null) {
                return null;
            }
            $ronAmount = $conversion->ronAmount;
        }

        try {
            return $this->interestService->calculate(
                amount: $ronAmount,
                dueDate: $dueDate,
                referenceDate: $now,
                relationshipType: $relationship,
                currency: 'RON',
                invoiceDate: $this->getInvoiceDate(),
            );
        } catch (\DomainException | \InvalidArgumentException | \RuntimeException) {
            return null;
        }
    }

    /**
     * FX conversion preview for the sidebar. Null for RON (no line shown) or
     * when the conversion can't be resolved (missing invoice date or no BNR
     * rate for that date). The template distinguishes the two via
     * {@see getInvoiceDate()}.
     */
    public function getConversion(): ?CurrencyConversionResult
    {
        $currency = $this->getCurrency();
        if ($currency === 'RON') {
            return null;
        }

        $amount = $this->floatFromFormValues('amount');
        if ($amount === null || $amount <= 0.0) {
            return null;
        }

        $invoiceDate = $this->getInvoiceDate();
        if ($invoiceDate === null) {
            return null;
        }

        try {
            return $this->currencyConverter->convertToRon($amount, $currency, $invoiceDate);
        } catch (\RuntimeException) {
            return null;
        }
    }

    public function getInvoiceDate(): ?\DateTimeImmutable
    {
        $raw = $this->stringFromFormValues('invoiceDate');
        if ($raw === null) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return $date !== false ? $date : null;
    }

    public function getStampDuty(): StampDutyResult
    {
        return $this->stampDutyCalculator->calculate();
    }

    /**
     * Convenience for the template — flags that getInterest came back null
     * because the user picked a future date (most-likely UX gotcha). Other
     * null causes (missing amount, unsupported currency) are surfaced by
     * inspecting `formValues` directly in the template.
     */
    public function isDueDateInFuture(): bool
    {
        $dueDateRaw = $this->stringFromFormValues('dueDate');
        if ($dueDateRaw === null) {
            return false;
        }
        $dueDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueDateRaw);
        if ($dueDate === false) {
            return false;
        }

        return $dueDate >= new \DateTimeImmutable('today');
    }

    /**
     * Exposed for the sidebar (`{{ amount }}` / `{{ currency }}` placeholders
     * in the template). The trait surfaces `formValues` array as the source of
     * truth — these typed accessors keep the template legible.
     */
    public function getAmount(): ?float
    {
        return $this->floatFromFormValues('amount');
    }

    public function getCurrency(): string
    {
        $raw = $this->stringFromFormValues('currency');

        return $raw ?? 'RON';
    }

    private function floatFromFormValues(string $key): ?float
    {
        $raw = $this->formValues[$key] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            return (float) $raw;
        }

        return null;
    }

    private function stringFromFormValues(string $key): ?string
    {
        $raw = $this->formValues[$key] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }

        return (string) $raw;
    }
}
