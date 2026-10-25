<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Enum\IssueSeverity;
use App\Service\Case\FilingReadinessChecker;
use App\Service\Deadline\DeadlineService;
use App\Service\Validation\OpAdmissibilityValidator;

/**
 * The conditions a filing package is built under: the same gates, in the same
 * order and with the same messages, as the petition generation, minus the one-off
 * acknowledgements given when the petition was generated. A package is the point
 * where documents leave for the court, so the gates are checked again here rather
 * than trusted from generation.
 */
final class FilingGate
{
    public function __construct(
        private readonly DeadlineService $deadlineService,
        private readonly OpAdmissibilityValidator $admissibility,
        private readonly FilingReadinessChecker $filingReadiness,
        private readonly string $uploadsDir,
    ) {}

    /** Translation key of the first failing gate, null when the package may be built. */
    public function firstBlocker(LegalCase $case): ?string
    {
        if ($case->isDeleted() || $case->getStatus() !== CaseStatus::CERERE_GENERATA) {
            return 'filing.package.blocked_wrong_status';
        }

        if ($case->getCourt() === null) {
            return 'case_overview.payment_order.flash_error_no_court';
        }

        if (!$case->getStampDutyStatus()->allowsFiling()) {
            return 'case_overview.payment_order.flash_error_stamp_duty_missing';
        }

        // CPC art. 1017 para. 2: without the proof of service the petition is inadmissible.
        if (!$case->hasDocumentOfType(DocumentType::DOVADA_COMUNICARE)) {
            return 'case_overview.payment_order.flash_error_communication_proof_missing';
        }

        // CPC art. 1015: the 15-day payment term must have run.
        if ($case->getPaymentNoticeCommunicationDate() !== null
            && !$this->deadlineService->isPaymentTermExpired($case, new \DateTimeImmutable('today'))) {
            return 'case_overview.payment_order.flash_error_term_not_expired';
        }

        foreach ($this->admissibility->validate($case) as $issue) {
            if ($issue->severity === IssueSeverity::ERROR
                && in_array($issue->code, ['OP_DEBT_NOT_YET_DUE', 'OP_ITEM_NOT_YET_DUE'], true)) {
                return $issue->messageKey;
            }
        }

        $blocker = $this->filingReadiness->check($case)->firstBlocker();
        if ($blocker !== null) {
            return 'case_overview.payment_order.flash_error_' . $blocker->code->value;
        }

        $petition = $this->petition($case);
        if ($petition === null || !is_file($this->uploadsDir . '/' . $petition->getStoredFilename())) {
            return 'filing.package.blocked_petition_missing';
        }

        return null;
    }

    private function petition(LegalCase $case): ?Document
    {
        foreach ($case->getDocuments() as $document) {
            if ($document->getDocumentType() === DocumentType::CERERE_OP) {
                return $document;
            }
        }

        return null;
    }
}
