<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\DTO\Case\LegalCostsChange;
use App\DTO\Case\LegalCostsInput;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Service\AuditLogService;

/**
 * The recovery costs the lawyer corrects from the case page. They appear only
 * in the payment order petition, so they stay editable after the summons and
 * until the petition is generated; nothing else on the case depends on them,
 * so no recalculation follows.
 *
 * The edit runs under a row lock with the status read again inside it, because
 * the petition can be generated from another tab in the meantime. A petition
 * already on file that no longer quotes the saved costs is kept and marked
 * outdated, like a summons after a claim edit.
 */
final class LegalCostsEditor
{
    public const ACTION = 'legal_costs_edited';
    public const REFUSAL = 'legal_costs_locked';

    public function __construct(
        private readonly CaseEditTransaction $transaction,
        private readonly LegalCaseClaimFieldsMapper $fields,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function isEditable(LegalCase $case): bool
    {
        return !$case->isDeleted() && $case->getStatus()->allowsLegalCostsEdit();
    }

    /**
     * @throws ClaimEditRefused
     */
    public function update(LegalCase $case, LegalCostsInput $input, User $actor): LegalCostsChange
    {
        return $this->transaction->run(
            $case,
            fn (LegalCase $c): bool => $this->isEditable($c),
            self::REFUSAL,
            function () use ($case, $input, $actor): LegalCostsChange {
                $before = self::snapshot($case);
                $this->fields->applyLegalCosts($case, $input->legalCostsFixed, $input->legalCostsCurrency, $input->legalCostsSuccessPercent);
                $after = self::snapshot($case);

                $changed = [];
                foreach ($after as $field => $to) {
                    if ($before[$field] !== $to) {
                        $changed[$field] = ['from' => $before[$field], 'to' => $to];
                    }
                }
                if ($changed === []) {
                    return new LegalCostsChange();
                }

                $outdated = $this->markPaymentOrderOutdated($case);
                $this->auditLogService->log(
                    action: self::ACTION,
                    entityType: LegalCase::class,
                    entityId: (string) $case->getId(),
                    oldData: $before,
                    newData: $after + [
                        'caseNumber' => $case->getCaseNumber(),
                        'status' => $case->getStatus()->value,
                        'paymentOrderOutdated' => $outdated,
                    ],
                    category: AuditLogService::CATEGORY_CLAIM_EDITED,
                    actor: $actor,
                );

                return new LegalCostsChange($changed, $outdated);
            },
        );
    }

    /**
     * A petition generated before the save quotes the old costs. In the statuses
     * that allow the edit none should exist, since generating it moves the case
     * on; the mark covers a petition left on file by a manual repair.
     */
    private function markPaymentOrderOutdated(LegalCase $case): bool
    {
        $marked = false;
        $now = new \DateTimeImmutable();
        foreach ($case->getDocuments() as $document) {
            if ($document->getDocumentType() === DocumentType::CERERE_OP && !$document->isOutdated()) {
                $document->setOutdatedAt($now);
                $marked = true;
            }
        }

        return $marked;
    }

    /** @return array{legalCostsFixed: ?string, legalCostsCurrency: ?string, legalCostsSuccessPercent: ?string} */
    private static function snapshot(LegalCase $case): array
    {
        return [
            'legalCostsFixed' => $case->getLegalCostsFixed(),
            'legalCostsCurrency' => $case->getLegalCostsCurrency(),
            'legalCostsSuccessPercent' => $case->getLegalCostsSuccessPercent(),
        ];
    }
}
