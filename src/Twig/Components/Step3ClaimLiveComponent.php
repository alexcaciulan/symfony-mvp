<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\DTO\Calculation\CurrencyConversionResult;
use App\DTO\Calculation\InterestResult;
use App\DTO\Calculation\StampDutyResult;
use App\DTO\Wizard\ClaimItemRow;
use App\DTO\Wizard\Step3ClaimData;
use App\Enum\ClaimItemKind;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Form\Wizard\Step3ClaimType;
use App\Service\Calculation\CurrencyConverter;
use App\Service\Calculation\InterestCalculatorService;
use App\Service\Calculation\StampDutyCalculator;
use App\Service\Case\ClaimPositionsSummarizer;
use App\Util\RomanianAmountParser;
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

    /**
     * The claim positions the lawyer edits, as plain arrays so their scalars
     * (amount as a Romanian-typed string, dates as Y-m-d) round-trip through
     * the live model without float or DateTime coercion. Writable and bound
     * with `data-model` per field, so editing a sum or a due date re-renders
     * the table and the sidebar off the same figures the submit path will use.
     *
     * @var list<array<string, mixed>>
     */
    #[LiveProp(writable: true)]
    public array $rows = [];

    /** Confirmation threshold, needed to flag rows that require their own tick. */
    #[LiveProp]
    public float $reviewThreshold = 0.6;

    /** The whole-table confirmation checkbox, kept so it survives re-renders. */
    #[LiveProp(writable: true)]
    public bool $tableConfirmed = false;

    /**
     * Errors from a rejected submit, shown on the full-page render. A live edit
     * is a new state, so they clear on the next re-render.
     *
     * @var list<string>
     */
    #[LiveProp]
    public array $claimItemsErrors = [];

    /**
     * @var array<string, mixed>|null Memoized within one render: the aggregator
     *      is not free to run once per template getter.
     */
    private ?array $positionSummaryCache = null;

    public function __construct(
        private readonly InterestCalculatorService $interestService,
        private readonly StampDutyCalculator $stampDutyCalculator,
        private readonly CurrencyConverter $currencyConverter,
        private readonly ClaimPositionsSummarizer $positionsSummarizer,
    ) {}

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(Step3ClaimType::class, $this->initialFormData);
    }

    /** Whether the claim is described by positions rather than by this form's scalars. */
    public function isPositionDriven(): bool
    {
        return $this->rows !== [];
    }

    /**
     * The rows the lawyer is looking at, for the template to iterate. Kept as
     * arrays so the readonly fields render and the editable ones stay bound to
     * `data-model`.
     *
     * @return list<array<string, mixed>>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * Per-row accessory, breakdown and totals recomputed from the current rows
     * and the current relationship/penalty picked on the scalar form. This is
     * the same computation the submit path runs, so the figure the lawyer edits
     * toward matches the one that gets persisted.
     *
     * @return array<string, mixed>
     */
    public function getPositionSummary(): array
    {
        if ($this->positionSummaryCache !== null) {
            return $this->positionSummaryCache;
        }

        $rows = array_map([$this, 'rowFromArray'], $this->rows);
        $relationship = RelationshipType::tryFrom($this->stringFromFormValues('relationshipType') ?? '')
            ?? RelationshipType::COMERCIAL;
        $penalty = PenaltyType::tryFrom($this->stringFromFormValues('penaltyType') ?? '')
            ?? PenaltyType::LEGAL_PENALIZATOARE;
        $rate = $this->floatFromFormValues('contractualPenaltyRate');

        return $this->positionSummaryCache = $this->positionsSummarizer->summarize($rows, $relationship, $penalty, $rate);
    }

    public function getPositionCount(): int
    {
        return $this->getPositionSummary()['countedRows'];
    }

    public function getPositionPrincipal(): ?float
    {
        return $this->getPositionSummary()['countedRows'] > 0 ? $this->getPositionSummary()['principal'] : null;
    }

    public function getPositionAccessory(): ?float
    {
        return $this->getPositionSummary()['countedRows'] > 0 ? $this->getPositionSummary()['accessory'] : null;
    }

    public function getPositionDueDate(): ?string
    {
        $earliest = $this->getPositionSummary()['earliestDueDate'];

        return $earliest instanceof \DateTimeImmutable ? $earliest->format('Y-m-d') : null;
    }

    /**
     * Rebuild a row DTO from its live array. The amount is parsed the same
     * Romanian-aware way as the submit path, and the RON value follows it so a
     * corrected sum flows straight into the totals; a blank or unparsable entry
     * keeps the last known figure rather than zeroing the position.
     *
     * @param array<string, mixed> $row
     */
    private function rowFromArray(array $row): ClaimItemRow
    {
        $currency = is_string($row['currency'] ?? null) && $row['currency'] !== '' ? $row['currency'] : 'RON';
        $parsed = RomanianAmountParser::parse(is_string($row['amount'] ?? null) ? $row['amount'] : '');
        $amount = $parsed ?? (is_numeric($row['fallbackAmount'] ?? null) ? (float) $row['fallbackAmount'] : 0.0);

        $storedRon = is_numeric($row['amountRon'] ?? null) ? (float) $row['amountRon'] : null;
        // RON positions convert one-to-one, so an edited sum is its own RON
        // value; foreign currency keeps the rate-based figure resolved earlier.
        $amountRon = $currency === 'RON' ? $amount : $storedRon;

        return new ClaimItemRow(
            dedupKey: (string) ($row['key'] ?? ''),
            amount: $amount,
            currency: $currency,
            kind: ClaimItemKind::tryFrom(is_string($row['kind'] ?? null) ? $row['kind'] : '') ?? ClaimItemKind::INVOICE,
            documentNumber: $this->nullableString($row['number'] ?? null),
            documentDate: $this->immutableDate($row['documentDate'] ?? null),
            dueDate: $this->immutableDate($row['dueDate'] ?? null),
            amountRon: $amountRon,
            exchangeRate: is_numeric($row['exchangeRate'] ?? null) ? (float) $row['exchangeRate'] : null,
            exchangeRateDate: $this->immutableDate($row['exchangeRateDate'] ?? null),
            needsManualFx: (bool) ($row['needsManualFx'] ?? false),
            paidAmount: is_numeric($row['paidAmount'] ?? null) ? (float) $row['paidAmount'] : 0.0,
            sourceDocumentId: is_numeric($row['sourceDocumentId'] ?? null) ? (int) $row['sourceDocumentId'] : null,
            causeReference: $this->nullableString($row['cause'] ?? null),
            causeDocumentId: is_numeric($row['causeDocumentId'] ?? null) ? (int) $row['causeDocumentId'] : null,
            description: $this->nullableString($row['description'] ?? null),
            confidence: is_numeric($row['confidence'] ?? null) ? (float) $row['confidence'] : 1.0,
            confirmed: (bool) ($row['confirmed'] ?? false),
            excluded: (bool) ($row['excluded'] ?? false),
            warningKeys: is_array($row['warningKeys'] ?? null) ? array_values(array_filter($row['warningKeys'], 'is_string')) : [],
            hasStatedDeduction: (bool) ($row['hasStatedDeduction'] ?? false),
        );
    }

    /**
     * Whether a row's own tick is required because the single table-wide
     * checkbox may not confirm it on the lawyer's behalf.
     *
     * @param array<string, mixed> $row
     */
    public function rowRequiresConfirmation(array $row): bool
    {
        return $this->rowFromArray($row)->requiresIndividualConfirmation($this->reviewThreshold);
    }

    private function immutableDate(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($raw, 0, 10));

        return $date !== false ? $date : null;
    }

    private function nullableString(mixed $raw): ?string
    {
        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    public function getInterest(): ?InterestResult
    {
        if ($this->isPositionDriven()) {
            // Each position accrues from its own due date; one aggregate figure
            // from one due date is exactly the claim T4 exists to stop making.
            return null;
        }

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
