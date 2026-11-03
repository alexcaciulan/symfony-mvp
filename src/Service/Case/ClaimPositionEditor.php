<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\DTO\Case\ClaimDetailsInput;
use App\DTO\Case\ClaimPositionInput;
use App\DTO\Case\ClaimRecalculationResult;
use App\DTO\Wizard\ClaimItemRow;
use App\Entity\ClaimItem;
use App\Entity\Court;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\ClaimExclusionReason;
use App\Enum\DocumentType;
use App\Enum\PenaltyType;
use App\Repository\ClaimItemRepository;
use App\Service\AuditLogService;
use App\Service\Document\DocumentUploadService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The edits a lawyer makes to the claim from the case page, while the case is
 * still amiable: a position added by hand or from an uploaded invoice, a
 * position excluded or reinstated, the claim details rewritten, a proposed
 * court accepted.
 *
 * Every edit runs under a row lock on the case with the status read again
 * inside it, because the summons can be generated from another tab in the
 * meantime and a claim must not change under a summons already produced. A
 * refusal rolls the transaction back without closing the entity manager, so
 * the page can still answer with the reason.
 *
 * Positions are never deleted: an exclusion keeps the row, its reason and the
 * audit trail of why the claim no longer asks for that sum.
 */
final class ClaimPositionEditor
{
    public const REASON_ADD_MANUAL = 'add_manual';
    public const REASON_ADD_FROM_DOCUMENT = 'add_from_document';
    public const REASON_EXCLUDE = 'exclude';
    public const REASON_REINCLUDE = 'reinclude';
    public const REASON_EDIT_DETAILS = 'edit_details';
    public const ACTION_COURT_ACCEPTED = 'court_accepted';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClaimItemFactory $factory,
        private readonly ClaimItemRepository $items,
        private readonly ClaimRecalculator $recalculator,
        private readonly LegalCaseClaimFieldsMapper $fields,
        private readonly ClaimDocumentPartyMatcher $partyMatcher,
        private readonly DocumentUploadService $documents,
        private readonly AuditLogService $auditLogService,
    ) {}

    /** True while the case accepts claim edits: amiable, not deleted. */
    public function isEditable(LegalCase $case): bool
    {
        return !$case->isDeleted() && $case->getStatus() === CaseStatus::AMIABIL;
    }

    /**
     * @throws ClaimEditRefused
     */
    public function addManual(LegalCase $case, ClaimPositionInput $input, User $actor): ClaimRecalculationResult
    {
        return $this->add($case, $input, null, $actor);
    }

    /**
     * The invoice was uploaded onto the case and read; the lawyer corrected the
     * reading and confirms it. An invoice between other parties is refused and
     * the file removed: it was stored only to be read.
     *
     * @throws ClaimEditRefused
     */
    public function addFromDocument(LegalCase $case, Document $document, ClaimPositionInput $input, User $actor): ClaimRecalculationResult
    {
        if ($document->getLegalCase()?->getId() !== $case->getId()) {
            throw new ClaimEditRefused('document_mismatch');
        }

        $match = $this->partyMatcher->match($document, $case);
        if ($match->isCertainMismatch()) {
            $this->documents->delete($document, $actor);

            throw new ClaimEditRefused('party_mismatch', [
                '%creditor%' => (string) $match->extractedCreditorName,
                '%debtor%' => (string) $match->extractedDebtorName,
            ]);
        }

        $input->sourceDocumentId = $document->getId();

        return $this->add($case, $input, $document, $actor);
    }

    /**
     * @throws ClaimEditRefused
     */
    public function exclude(LegalCase $case, ClaimItem $item, ClaimExclusionReason $reason, ?string $note, User $actor): ClaimRecalculationResult
    {
        return $this->transact($case, function () use ($case, $item, $reason, $note, $actor): ClaimRecalculationResult {
            $this->assertOwned($case, $item);
            $this->assertHasCountingPosition($case);
            if ($item->isExcludedByLawyer()) {
                throw new ClaimEditRefused('already_excluded', ['%position%' => $this->positionLabel($item)]);
            }
            if ($item->countsTowardsClaim() && count($case->getCountingClaimItems()) <= 1) {
                throw new ClaimEditRefused('last_counting_position', ['%position%' => $this->positionLabel($item)]);
            }

            $before = $this->recalculator->snapshot($case);
            $item->setExcludedByLawyer(true);
            $item->setExclusionReason($reason);
            $item->setExclusionNote(LegalCaseClaimFieldsMapper::trimmedOrNull($note));
            $this->markGeneratedActsOutdated($case);

            return $this->recalculator->recalculate($case, self::REASON_EXCLUDE, $before, $actor);
        });
    }

    /**
     * @throws ClaimEditRefused
     */
    public function reinclude(LegalCase $case, ClaimItem $item, User $actor): ClaimRecalculationResult
    {
        return $this->transact($case, function () use ($case, $item, $actor): ClaimRecalculationResult {
            $this->assertOwned($case, $item);
            $this->assertHasCountingPosition($case);
            if (!$item->isExcludedByLawyer()) {
                throw new ClaimEditRefused('not_excluded', ['%position%' => $this->positionLabel($item)]);
            }
            if ($item->needsManualFx() || $item->getAmountRon() === null) {
                throw new ClaimEditRefused('fx_missing', [
                    '%currency%' => $item->getCurrency(),
                    '%date%' => $item->getDocumentDate()?->format('d.m.Y') ?? $item->getDueDate()?->format('d.m.Y') ?? '',
                ]);
            }

            $before = $this->recalculator->snapshot($case);
            $item->setExcludedByLawyer(false);
            $item->setExclusionReason(null);
            $item->setExclusionNote(null);
            $item->setConfirmedByLawyer(true);
            $this->markGeneratedActsOutdated($case);

            return $this->recalculator->recalculate($case, self::REASON_REINCLUDE, $before, $actor);
        });
    }

    /**
     * The contract reference feeds the grouping of the positions by cause, so
     * the court and the admissibility are compared again after the rewrite.
     *
     * @throws ClaimEditRefused
     */
    public function updateDetails(LegalCase $case, ClaimDetailsInput $input, User $actor): ClaimRecalculationResult
    {
        return $this->transact($case, function () use ($case, $input, $actor): ClaimRecalculationResult {
            $before = $this->recalculator->snapshot($case);
            $before['details'] = $this->detailsSnapshot($case);
            $this->fields->applyDetails($case, $input);
            $this->markGeneratedActsOutdated($case);

            return $this->recalculator->recalculate($case, self::REASON_EDIT_DETAILS, $before, $actor);
        });
    }

    /**
     * Sets the court the rules propose, and only that one: the resolver runs
     * again on the server and the posted id has to be its answer, so a stale
     * dialog or a tampered form never writes a court nobody proposed.
     *
     * @throws ClaimEditRefused
     */
    public function acceptCourt(LegalCase $case, int $courtId, User $actor): Court
    {
        return $this->transact($case, function () use ($case, $courtId, $actor): Court {
            $proposal = $this->recalculator->courtProposal($case);
            $proposed = $proposal?->proposed;
            if ($proposed === null || $proposed->getId() !== $courtId) {
                throw new ClaimEditRefused('court_proposal_stale');
            }

            $current = $case->getCourt();
            $case->setCourt($proposed);
            $this->markGeneratedActsOutdated($case);
            $this->auditLogService->log(
                action: self::ACTION_COURT_ACCEPTED,
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                oldData: ['court_id' => $current?->getId(), 'court_name' => $current?->getName()],
                newData: [
                    'court_id' => $proposed->getId(),
                    'court_name' => $proposed->getName(),
                    'caseNumber' => $case->getCaseNumber(),
                    'explanationKey' => $proposal->explanationKey,
                ],
                category: AuditLogService::CATEGORY_CLAIM_EDITED,
                actor: $actor,
            );

            return $proposed;
        });
    }

    private function add(LegalCase $case, ClaimPositionInput $input, ?Document $document, User $actor): ClaimRecalculationResult
    {
        return $this->transact($case, function () use ($case, $input, $document, $actor): ClaimRecalculationResult {
            $this->assertHasCountingPosition($case);
            $debtor = $this->resolveDebtor($case, $input);
            $row = $this->factory->rowFromInput($input, $case->getCreditor()?->getCui());
            if ($row->needsManualFx) {
                throw new ClaimEditRefused('fx_missing', [
                    '%currency%' => $row->currency,
                    '%date%' => ($row->documentDate ?? $row->dueDate)?->format('d.m.Y') ?? '',
                ]);
            }

            $existing = $this->items->findOneByDedupKey($case, $row->dedupKey);
            if ($existing !== null) {
                throw new ClaimEditRefused(
                    $existing->isExcludedByLawyer() ? 'duplicate_excluded' : 'duplicate',
                    ['%position%' => $this->positionLabel($existing)],
                    $existing,
                );
            }

            $this->assertCauseAccepted($case, $row, $input);

            $before = $this->recalculator->snapshot($case);
            $before['input'] = $this->inputSnapshot($input, $document);
            [$item] = $this->factory->materialize($case, [$row]);
            $item->setDebtor($debtor);
            $this->em->persist($item);
            $this->markGeneratedActsOutdated($case);

            return $this->recalculator->recalculate(
                $case,
                $document !== null ? self::REASON_ADD_FROM_DOCUMENT : self::REASON_ADD_MANUAL,
                $before,
                $actor,
            );
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws ClaimEditRefused
     */
    private function transact(LegalCase $case, callable $work): mixed
    {
        if (!$this->isEditable($case)) {
            throw new ClaimEditRefused('status_changed');
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->lock($case, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($case);
            if (!$this->isEditable($case)) {
                throw new ClaimEditRefused('status_changed');
            }

            $result = $work();
            $this->em->flush();
            $connection->commit();

            return $result;
        } catch (ClaimEditRefused $refusal) {
            // Nothing was written before a refusal; the manager stays usable so
            // the page can say why.
            $connection->rollBack();
            $this->em->refresh($case);

            throw $refusal;
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->em->close();

            throw $e;
        }
    }

    /**
     * A case whose positions all wait for a manual rate, or that predates the
     * positions, has no figure the edit could start from. Resolving the rate is
     * not offered here, so the edit is refused with that reason.
     */
    private function assertHasCountingPosition(LegalCase $case): void
    {
        if ($case->getCountingClaimItems() === []) {
            throw new ClaimEditRefused('no_counting_position');
        }
    }

    private function assertOwned(LegalCase $case, ClaimItem $item): void
    {
        if ($item->getLegalCase()->getId() !== $case->getId()) {
            throw new ClaimEditRefused('position_unknown');
        }
    }

    /**
     * A single-debtor case needs no choice; with several, the invoice names one
     * of them and the lawyer has to say which.
     */
    private function resolveDebtor(LegalCase $case, ClaimPositionInput $input): ?LegalCaseDebtor
    {
        $links = $case->getDebtors();
        if ($input->debtorId === null) {
            if ($links->count() > 1) {
                throw new ClaimEditRefused('debtor_required');
            }

            return $links->first() ?: null;
        }
        foreach ($links as $link) {
            if ($link->getId() === $input->debtorId) {
                return $link;
            }
        }

        throw new ClaimEditRefused('debtor_unknown');
    }

    /**
     * An invoice issued under another contract than the ones already on file is
     * another cause (CPC art. 99): it may belong in a separate case, and the
     * case's penalty clause does not automatically cover it. Both are the
     * lawyer's to confirm, explicitly, before the position is saved.
     */
    private function assertCauseAccepted(LegalCase $case, ClaimItemRow $row, ClaimPositionInput $input): void
    {
        $newKey = DocumentReferenceNormalizer::normalize($row->causeReference) ?? '';
        if ($newKey === '') {
            return;
        }
        $existing = [];
        $existingLabels = [];
        foreach ($case->getCountingClaimItems() as $item) {
            $key = $item->causeKey();
            if ($key !== '' && !isset($existing[$key])) {
                $existing[$key] = true;
                $existingLabels[] = (string) $item->getCauseReference();
            }
        }
        if ($existing === []) {
            $contractKey = DocumentReferenceNormalizer::normalize($case->getContractReference()) ?? '';
            if ($contractKey === '') {
                return;
            }
            $existing[$contractKey] = true;
            $existingLabels[] = (string) $case->getContractReference();
        }
        if (isset($existing[$newKey])) {
            return;
        }

        $params = ['%cause%' => (string) $row->causeReference, '%existing%' => implode(', ', $existingLabels)];
        if (!$input->sameCauseConfirmed) {
            throw new ClaimEditRefused('other_contract', $params);
        }
        if ($case->getPenaltyType() === PenaltyType::CONTRACTUAL && !$input->clauseAppliesConfirmed) {
            throw new ClaimEditRefused('clause_unconfirmed', $params);
        }
    }

    /**
     * A summons generated while the case was still amiable quotes sums and
     * details that just changed. It stays on file as it was issued; the lawyer
     * generates a fresh one when he decides to.
     */
    private function markGeneratedActsOutdated(LegalCase $case): void
    {
        $now = new \DateTimeImmutable();
        foreach ($case->getDocuments() as $document) {
            if ($document->getDocumentType() === DocumentType::SOMATIE && !$document->isOutdated()) {
                $document->setOutdatedAt($now);
            }
        }
    }

    private function positionLabel(ClaimItem $item): string
    {
        $number = trim((string) $item->getDocumentNumber());
        if ($number !== '') {
            return $number;
        }

        return sprintf('%s %s', number_format((float) $item->getAmount(), 2, ',', '.'), $item->getCurrency());
    }

    /** @return array<string, mixed> */
    private function inputSnapshot(ClaimPositionInput $input, ?Document $document): array
    {
        $extracted = $document?->getExtractedData();
        $claim = is_array($extracted['claim'] ?? null) ? $extracted['claim'] : null;

        return [
            'documentNumber' => $input->documentNumber,
            'documentDate' => $input->documentDate?->format('Y-m-d'),
            'dueDate' => $input->dueDate?->format('Y-m-d'),
            'amount' => $input->amount,
            'currency' => $input->currency,
            'debtorId' => $input->debtorId,
            'causeReference' => $input->causeReference,
            'sameCauseConfirmed' => $input->sameCauseConfirmed,
            'clauseAppliesConfirmed' => $input->clauseAppliesConfirmed,
            'source' => $document === null ? ['origin' => 'manual'] : [
                'origin' => 'document',
                'documentId' => $document->getId(),
                'filename' => $document->getOriginalFilename(),
                'contentHash' => $document->getContentHash(),
                'extractionStatus' => $document->getExtractionStatus()->value,
                'extractionStrategy' => $document->getExtractionStrategy(),
                // What the model read, kept next to what the lawyer saved so a
                // corrected field can be told from one taken as read.
                'extractedClaim' => $claim === null ? null : [
                    'invoiceNumber' => $claim['invoiceNumber'] ?? null,
                    'invoiceDate' => $claim['invoiceDate'] ?? null,
                    'dueDate' => $claim['dueDate'] ?? null,
                    'amount' => $claim['amount'] ?? null,
                    'currency' => $claim['currency'] ?? null,
                ],
            ],
        ];
    }

    /** @return array<string, ?string> */
    private function detailsSnapshot(LegalCase $case): array
    {
        return [
            'claimDescription' => $case->getClaimDescription(),
            'contractReference' => $case->getContractReference(),
            'penaltyClauseArticle' => $case->getPenaltyClauseArticle(),
            'penaltyClauseText' => $case->getPenaltyClauseText(),
            'paymentNoticeNumber' => $case->getPaymentNoticeNumber(),
        ];
    }
}
