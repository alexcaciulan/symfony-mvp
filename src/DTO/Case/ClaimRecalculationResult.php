<?php

declare(strict_types=1);

namespace App\DTO\Case;

use App\DTO\Calculation\ClaimTotals;
use App\DTO\Validation\AdmissibilityIssue;
use App\Entity\LegalDeadline;

/**
 * Everything that moved on a case after its claim positions changed, for the
 * caller to summarize to the lawyer and for the audit entry already written.
 */
final readonly class ClaimRecalculationResult
{
    /**
     * @param array{from: ?string, to: string}|null       $accessoryChange   previous and new stored accessory, null when it stayed
     * @param array{from: array<string, ?string>, to: array<string, ?string>}|null $fxChange previous and new case-level exchange trace, null when it stayed
     * @param list<LegalDeadline>                         $deadlinesCreated  prescription terms created for due dates not covered yet
     * @param list<int>                                   $deadlinesRemoved  ids of the prescription terms deleted because their due date left the claim
     * @param list<LegalDeadline>                         $deadlinesUnmatched open prescription terms no position supports and nothing proves obsolete
     * @param array{errors: list<AdmissibilityIssue>, warnings: list<AdmissibilityIssue>} $issues
     * @param \DateTimeImmutable                          $referenceDate     the date the accessory was computed up to
     * @param array<string, array{from: mixed, to: ?string}> $detailsChange the claim detail fields that changed, by field
     */
    public function __construct(
        public ClaimTotals $totals,
        public ?array $accessoryChange,
        public ?array $fxChange,
        public ?CourtProposal $courtProposal,
        public array $deadlinesCreated,
        public array $deadlinesRemoved,
        public array $deadlinesUnmatched,
        public array $issues,
        public \DateTimeImmutable $referenceDate,
        public array $detailsChange = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->issues['errors'] !== [];
    }

    public function hasWarnings(): bool
    {
        return $this->issues['warnings'] !== [];
    }
}
