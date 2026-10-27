<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\DTO\Wizard\ClaimItemRow;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\Enum\PaymentTermAnchor;
use App\Service\Deadline\WorkingDayResolver;

/**
 * Reads the payment term a contract states and applies it to the due dates of
 * the invoices issued under that contract.
 */
final class ContractualPaymentTermApplier
{
    /** Law 72/2013 art. 3 alin. 3 lit. a: the term between professionals who set none. */
    private const STATUTORY_TERM_DAYS = 30;

    /** Shorter contract numbers ("4") turn up inside any reference by chance. */
    private const MIN_CONTAINED_CONTRACT_LENGTH = 4;

    public function __construct(
        private readonly WorkingDayResolver $workingDays = new WorkingDayResolver(),
    ) {}

    /**
     * Sets the due date of each invoice from the payment term of the contract
     * it was issued under.
     *
     * Invoices are routinely issued with a due date that ignores the contract
     * (due the day they are issued, where the contract grants four days), and
     * the contract is what binds the debtor. So a term counted from the issue
     * date, in calendar or in working days, replaces the printed due date. A
     * term counted from any other event (delivery, receipt) cannot be computed
     * from the invoice: the printed date stays and the row says the lawyer has
     * to set it. Either way the row says why, and the date stays editable.
     *
     * An invoice no term reaches and that prints no due date is due 30 days
     * after it was issued: between professionals that is the term the law sets
     * when the parties set none (Law 72/2013 art. 3 alin. 3 lit. a), and a sale
     * made on the invoice alone is still a contract. The term runs from receipt,
     * which the file does not show, so it runs from the issue date, which
     * e-invoicing puts days apart at most. The row says so, because a term the
     * contract does state but the extraction missed would look the same.
     *
     * @param list<ClaimItemRow> $rows
     * @param list<array{contract: ?string, days: int, anchor: PaymentTermAnchor, businessDays: bool}> $terms
     * @param array<int, DocumentType> $typesByDocument
     * @param bool $hasContract whether the file holds a contract or an amendment
     */
    public function apply(array $rows, array $terms, array $typesByDocument, bool $hasContract = false): void
    {
        foreach ($rows as $row) {
            if ($row->isCreditNote()
                || ($typesByDocument[$row->sourceDocumentId ?? 0] ?? null) !== DocumentType::FACTURA) {
                continue;
            }

            $candidates = $this->termsGoverning($row, $terms);
            $distinct = [];
            foreach ($candidates as $term) {
                unset($term['contract']);
                $distinct[serialize($term)] = $term;
            }
            if ($distinct === []) {
                $this->applyStatutoryTerm($row, $hasContract && $terms === []);

                continue;
            }
            if (count($distinct) > 1) {
                // Two contractual documents disagree on the term (a contract and
                // an amendment, typically): which one applies to this invoice
                // depends on dates the wizard does not weigh.
                $row->dueDateNote = $this->dueDateNote('wizard.step3.claim_items.note.contract_term_ambiguous', null, $row);

                continue;
            }

            $term = array_values($distinct)[0];
            // Working-day terms read the same as calendar ones, only the unit differs.
            $unit = $term['businessDays'] ? '_business_days' : '';
            if ($term['anchor'] !== PaymentTermAnchor::ISSUE_DATE || $row->documentDate === null) {
                $row->dueDateNote = $this->dueDateNote('wizard.step3.claim_items.note.contract_term_manual' . $unit, $term, $row);

                continue;
            }

            // Weekends and legal holidays do not count towards a term in working days.
            $derived = $term['businessDays']
                ? $this->workingDays->addWorkingDays($row->documentDate, $term['days'])
                : $row->documentDate->modify(sprintf('+%d days', $term['days']));
            if ($row->dueDate !== null && $row->dueDate > $derived) {
                // A later date on the invoice is a longer term the creditor
                // granted, which the debtor may rely on.
                $row->dueDateNote = $this->dueDateNote('wizard.step3.claim_items.note.contract_term_longer_on_invoice' . $unit, $term, $row, $row->dueDate);

                continue;
            }
            $overrides = $row->dueDate !== null && $row->dueDate->format('Y-m-d') !== $derived->format('Y-m-d');
            $row->dueDateNote = $this->dueDateNote(
                ($overrides
                    ? 'wizard.step3.claim_items.note.contract_term_overrides_invoice'
                    : 'wizard.step3.claim_items.note.contract_term_applied') . $unit,
                $term,
                $row,
                $derived,
            );
            $row->dueDate = $derived;
        }
    }

    /**
     * The 30-day term of Law 72/2013 art. 3 alin. 3 lit. a, for an invoice that
     * prints no due date. `$contractSilent` tells a contract that states no term
     * apart from a file with no contract for the invoice.
     */
    private function applyStatutoryTerm(ClaimItemRow $row, bool $contractSilent): void
    {
        if ($row->dueDate !== null || $row->documentDate === null) {
            return;
        }

        $derived = $row->documentDate->modify(sprintf('+%d days', self::STATUTORY_TERM_DAYS));
        $row->dueDateNote = $this->dueDateNote(
            $contractSilent
                ? 'wizard.step3.claim_items.note.statutory_term_contract_silent'
                : 'wizard.step3.claim_items.note.statutory_term_no_contract',
            null,
            $row,
            $derived,
        );
        $row->dueDate = $derived;
    }

    /**
     * The terms that bind one invoice: those of the contract it cites, or, when
     * it cites none, those of the only contract in the file. An invoice citing a
     * contract the file does not hold gets none.
     *
     * @param list<array{contract: ?string, days: int, anchor: PaymentTermAnchor, businessDays: bool}> $terms
     * @return list<array{contract: ?string, days: int, anchor: PaymentTermAnchor, businessDays: bool}>
     */
    private function termsGoverning(ClaimItemRow $row, array $terms): array
    {
        $contracts = array_values(array_unique(array_filter(
            array_column($terms, 'contract'),
            static fn (?string $contract): bool => $contract !== null,
        )));

        $cited = DocumentReferenceNormalizer::normalize($row->causeReference);
        if ($cited === null || $contracts === []) {
            // Nothing to tell contracts apart by: the term applies only when
            // the file holds a single contract.
            return count($contracts) <= 1 ? $terms : [];
        }

        return array_values(array_filter(
            $terms,
            fn (array $term): bool => $term['contract'] !== null && $this->sameContract($term['contract'], $cited),
        ));
    }

    /**
     * "CONTRACT12/2024" on an invoice names contract "12/2024". Containment is
     * only trusted for a number long enough not to occur by chance.
     */
    private function sameContract(string $contract, string $cited): bool
    {
        if ($contract === $cited) {
            return true;
        }
        $shorter = strlen($contract) <= strlen($cited) ? $contract : $cited;
        $longer = $shorter === $contract ? $cited : $contract;

        return strlen($shorter) >= self::MIN_CONTAINED_CONTRACT_LENGTH && str_contains($longer, $shorter);
    }

    /**
     * @param ?array{days: int, anchor: PaymentTermAnchor, businessDays: bool} $term
     * @return array{key: string, days: int, anchor: string, printedDueDate: ?string, appliedDueDate: ?string}
     */
    private function dueDateNote(string $key, ?array $term, ClaimItemRow $row, ?\DateTimeImmutable $applied = null): array
    {
        return [
            'key' => $key,
            'days' => $term['days'] ?? 0,
            'anchor' => ($term['anchor'] ?? PaymentTermAnchor::OTHER)->value,
            'printedDueDate' => $row->dueDate?->format('Y-m-d'),
            'appliedDueDate' => $applied?->format('Y-m-d'),
        ];
    }

    /**
     * The payment term a contract or an amendment states, as extracted. Null
     * on payloads written before the term was extracted, and on documents that
     * state none.
     *
     * @return ?array{contract: ?string, days: int, anchor: PaymentTermAnchor, businessDays: bool}
     */
    public function termOf(Document $document): ?array
    {
        $extracted = $document->getExtractedData();
        $claim = is_array($extracted) ? ($extracted['claim'] ?? null) : null;
        if (!is_array($claim) || !is_int($claim['paymentTermDays'] ?? null) || $claim['paymentTermDays'] < 0) {
            return null;
        }

        return [
            'contract' => DocumentReferenceNormalizer::normalize(ExtractedValue::string($claim['contractNumber'] ?? null)),
            'days' => $claim['paymentTermDays'],
            'anchor' => PaymentTermAnchor::tryFrom(is_string($claim['paymentTermAnchor'] ?? null) ? $claim['paymentTermAnchor'] : '')
                ?? PaymentTermAnchor::OTHER,
            'businessDays' => ($claim['paymentTermBusinessDays'] ?? null) === true,
        ];
    }
}
