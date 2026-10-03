<?php

declare(strict_types=1);

namespace App\Service\Extraction;

use App\DTO\Extraction\DetectedDataRow;
use App\DTO\Extraction\DetectedDataSection;
use App\DTO\Extraction\PrefillConflict;
use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step3ClaimData;
use App\Enum\ConflictScope;
use App\Enum\PenaltyType;

/**
 * What the step 0 card shows for each section, and how complete it is.
 *
 * The count is measured against the fields the case needs from that section,
 * not against everything the extraction schema could carry: parties are legal
 * persons only, so a CNP is never missing, and a claim without a penalty clause
 * is not short of a penalty rate. Optional values are shown when found but never
 * counted, and a value the documents disagree on is not counted until the
 * lawyer settles it on its step.
 */
final class DetectedDataPreviewBuilder
{
    /** Identity and registered office: what the petition and the court need. */
    public const PARTY_REQUIRED = ['name', 'cui', 'onrcNumber', 'address', 'addressCounty', 'addressLocality'];

    private const CREDITOR_OPTIONAL = ['iban', 'bankName', 'legalRepresentative'];
    private const DEBTOR_OPTIONAL = ['administrator', 'email', 'phone', 'iban'];

    /** Values that belong to one invoice, superseded by the total when there are several. */
    private const PER_INVOICE = ['amount', 'dueDate', 'invoiceNumber', 'invoiceDate'];

    /** Aggregation field names that differ from the wizard DTO property names. */
    private const FIELD_ALIASES = ['county' => 'addressCounty', 'locality' => 'addressLocality'];

    /**
     * @param list<PrefillConflict> $conflicts
     * @param ?array{count: int, principal: float, currency: string, earliestDueDate: ?\DateTimeInterface} $positions
     *        the total over several invoices, null for a single one
     * @param array<string, mixed> $resolutions conflict key to the lawyer's choice;
     *        a settled conflict no longer holds its field back
     *
     * @return list<DetectedDataSection>
     */
    public function build(
        Step1CreditorData $creditor,
        Step2DebtorEntry $debtor,
        Step3ClaimData $claim,
        array $conflicts = [],
        ?array $positions = null,
        array $resolutions = [],
    ): array {
        $conflicts = array_values(array_filter(
            $conflicts,
            static fn (PrefillConflict $c): bool => !array_key_exists($c->key(), $resolutions),
        ));

        return [
            $this->party('creditor', 1, $creditor, $creditor->autoFilled, self::CREDITOR_OPTIONAL, $this->conflictFields($conflicts, ConflictScope::CREDITOR), false),
            $this->party('debtor', 2, $debtor, $debtor->autoFilled, self::DEBTOR_OPTIONAL, $this->conflictFields($conflicts, ConflictScope::DEBTOR), $this->hasScope($conflicts, ConflictScope::DEBTOR_SET)),
            $this->claim($claim, [...$this->conflictFields($conflicts, ConflictScope::CLAIM), ...$this->conflictFields($conflicts, ConflictScope::CLAIM_ITEM)], $positions),
        ];
    }

    /**
     * @param list<string> $autoFilled
     * @param list<string> $optional
     * @param list<string> $conflictFields
     */
    private function party(string $key, int $step, object $dto, array $autoFilled, array $optional, array $conflictFields, bool $choiceRequired): DetectedDataSection
    {
        $rows = [];
        $filled = 0;
        $missing = [];
        foreach (self::PARTY_REQUIRED as $field) {
            $row = $this->row($field, $dto->{$field}, $autoFilled, $conflictFields);
            if ($row === null) {
                $missing[] = $field;
                continue;
            }
            $rows[] = $row;
            if (!$row->inConflict) {
                ++$filled;
            }
        }
        foreach ($optional as $field) {
            $row = $this->row($field, $dto->{$field}, $autoFilled, $conflictFields, $field === 'iban' ? 'iban' : 'text');
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return new DetectedDataSection($key, $step, $rows, $filled, count(self::PARTY_REQUIRED), $missing, $choiceRequired);
    }

    /**
     * @param list<string> $conflictFields
     * @param ?array{count: int, principal: float, currency: string, earliestDueDate: ?\DateTimeInterface} $positions
     */
    private function claim(Step3ClaimData $claim, array $conflictFields, ?array $positions): DetectedDataSection
    {
        $auto = $claim->autoFilled;
        $rows = [];
        $filled = 0;
        $missing = [];
        $requiredFields = [];

        // Required: the sum and its due date make the claim certain, liquid and
        // exigible; a foreign currency also needs the invoice date for the rate.
        if ($positions !== null) {
            // The total banner carries both, summed over the invoices.
            $filled += 1 + ($positions['earliestDueDate'] !== null ? 1 : 0);
            if ($positions['earliestDueDate'] === null) {
                $missing[] = 'dueDate';
            }
            $required = 2;
        } else {
            $amount = $claim->amount !== null && in_array('amount', $auto, true)
                ? $this->formatAmount($claim->amount, $claim->currency)
                : null;
            $requiredFields = ['amount' => $amount, 'dueDate' => $claim->dueDate];
            if ($claim->currency !== 'RON') {
                $requiredFields['invoiceDate'] = $claim->invoiceDate;
            }
            foreach ($requiredFields as $field => $value) {
                $row = $field === 'amount'
                    ? ($value !== null ? new DetectedDataRow('amount', $value, 'text', in_array('amount', $conflictFields, true)) : null)
                    : $this->row($field, $value, $auto, $conflictFields);
                if ($row === null) {
                    $missing[] = $field;
                    continue;
                }
                $rows[] = $row;
                if (!$row->inConflict) {
                    ++$filled;
                }
            }
            $required = count($requiredFields);
        }

        // Optional: shown when read, never counted.
        $optional = ['legalGround', 'invoiceNumber', 'invoiceDate', 'contractNumber', 'contractDate', 'description'];
        if ($claim->contractNumber === null) {
            // Read as the contract's description when it carries no number;
            // one row either way.
            $optional[3] = 'contractReference';
        }
        if ($claim->penaltyType === PenaltyType::CONTRACTUAL) {
            array_push($optional, 'penaltyType', 'contractualPenaltyRate');
        }
        if ($positions === null && $claim->amount === null) {
            array_unshift($optional, 'currency');
        }
        foreach ($optional as $field) {
            if (array_key_exists($field, $requiredFields) || ($positions !== null && in_array($field, self::PER_INVOICE, true))) {
                continue;
            }
            $row = $this->row($field, $claim->{$field}, $auto, $conflictFields);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return new DetectedDataSection('claim', 3, $rows, $filled, $required, $missing);
    }

    /**
     * A row for a value the documents provided, or null when there is none.
     * Values the aggregation did not fill (DTO defaults) are not "detected".
     *
     * @param list<string> $autoFilled
     * @param list<string> $conflictFields
     */
    private function row(string $field, mixed $value, array $autoFilled, array $conflictFields, string $format = 'text'): ?DetectedDataRow
    {
        $inConflict = in_array($field, $conflictFields, true);
        if ($value === null || $value === '' || (!in_array($field, $autoFilled, true) && !$inConflict)) {
            return null;
        }

        if ($value instanceof \BackedEnum) {
            return new DetectedDataRow($field, method_exists($value, 'label') ? $value->label() : (string) $value->value, 'trans', $inConflict);
        }
        if ($value instanceof \DateTimeInterface) {
            return new DetectedDataRow($field, $value->format('d.m.Y'), 'text', $inConflict);
        }
        if (is_float($value)) {
            return new DetectedDataRow($field, rtrim(rtrim(number_format($value, 4, ',', '.'), '0'), ','), 'text', $inConflict);
        }

        return new DetectedDataRow($field, (string) $value, $format, $inConflict);
    }

    private function formatAmount(float $amount, string $currency): string
    {
        return number_format($amount, 2, ',', '.') . ' ' . $currency;
    }

    /**
     * Only a choice between values holds a field back: a warning with a single
     * option (an identity completed from a second document) has nothing to
     * choose, so its value counts.
     *
     * @param list<PrefillConflict> $conflicts
     *
     * @return list<string> DTO field names in dispute for that scope
     */
    private function conflictFields(array $conflicts, ConflictScope $scope): array
    {
        $fields = [];
        foreach ($conflicts as $conflict) {
            if ($conflict->scope === $scope && $conflict->field !== null && count($conflict->options) > 1) {
                $fields[] = self::FIELD_ALIASES[$conflict->field] ?? $conflict->field;
            }
        }

        return $fields;
    }

    /**
     * @param list<PrefillConflict> $conflicts
     */
    private function hasScope(array $conflicts, ConflictScope $scope): bool
    {
        foreach ($conflicts as $conflict) {
            if ($conflict->scope === $scope) {
                return true;
            }
        }

        return false;
    }
}
