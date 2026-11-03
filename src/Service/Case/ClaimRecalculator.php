<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\DTO\Case\ClaimRecalculationResult;
use App\DTO\Case\CourtProposal;
use App\DTO\Validation\AdmissibilityIssue;
use App\Entity\ClaimItem;
use App\Entity\Court;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\DeadlineType;
use App\Enum\IssueSeverity;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Repository\LegalDeadlineRepository;
use App\Service\AuditLogService;
use App\Service\Court\CompetentCourtResolver;
use App\Service\Deadline\DeadlineService;
use App\Service\Validation\OpAdmissibilityValidator;
use App\Util\PiiMasker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Brings a case back to consistency after its claim positions changed: the
 * denormalized totals, the case-level exchange trace, the stored accessory,
 * the prescription terms, the competent-court comparison, the admissibility
 * issues, and one audit entry holding the positions before and after.
 *
 * The caller runs {@see recalculate()} inside one transaction, after it has
 * applied the change to the positions and with the `before` snapshot it took
 * through {@see snapshot()} first. The service flushes before the deadline
 * step, which queries the database, and at the end.
 *
 * Two things are deliberately not done here. The court on the case is never
 * written: the resolver's answer is only compared with it and returned as a
 * proposal. And a prescription term that no position supports any more is
 * deleted only when its date is exactly the one computed for a due date that
 * just left the claim; a term the lawyer moved, or one whose origin cannot be
 * established, stays and is reported in `deadlinesUnmatched`. The entity has
 * no provenance column, so exact matching is the only safe criterion.
 *
 * The accessory reference date stays the one the case states (the summons
 * date, or the creation date before it, with an earlier cutoff winning): a
 * position falling due after it accrues nothing until the summons rebases
 * everything on its own date. The caller tells the lawyer which date was used.
 */
final class ClaimRecalculator
{
    public const ACTION = 'claim_edited';

    /** Warning: the accessory cutoff the lawyer set falls before a position's due date. */
    public const CODE_CUTOFF_BEFORE_DUE_DATE = 'ACCESSORY_CUTOFF_BEFORE_DUE_DATE';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClaimTotalsService $totalsService,
        private readonly CaseAccessoryService $accessoryService,
        private readonly CaseFxTrace $fxTrace,
        private readonly DeadlineService $deadlineService,
        private readonly LegalDeadlineRepository $deadlineRepository,
        private readonly CompetentCourtResolver $courtResolver,
        private readonly OpAdmissibilityValidator $admissibilityValidator,
        private readonly AccessoryComputabilityChecker $computabilityChecker,
        private readonly AuditLogService $auditLogService,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * The claim as it stands, in the shape the audit entry stores before and
     * after. Taken by the caller before it touches the positions; the caller
     * may add keys of its own (the source document, the extracted values it
     * overwrote) and they travel into the entry untouched.
     *
     * @return array<string, mixed>
     */
    public function snapshot(LegalCase $case): array
    {
        $items = [];
        foreach ($case->getClaimItems() as $item) {
            $items[] = $this->itemSnapshot($item);
        }

        $court = $case->getCourt();

        return [
            'items' => $items,
            'totals' => [
                'principal' => $case->getAmount(),
                'currency' => $case->getCurrency(),
                'accessory' => $case->getCalculatedInterest(),
                'referenceDate' => $this->accessoryService->referenceDate($case)->format('Y-m-d'),
                'dueDate' => $case->getDueDate()?->format('Y-m-d'),
                'invoiceNumber' => $case->getInvoiceNumber(),
            ],
            'fx' => $this->fxTrace->read($case),
            'court' => $court !== null ? ['id' => $court->getId(), 'name' => $court->getName()] : null,
        ];
    }

    /**
     * @param string               $reason what the caller did (`add_manual`, `exclude`, ...), kept in the audit entry
     * @param array<string, mixed> $before the {@see snapshot()} taken before the change
     */
    public function recalculate(LegalCase $case, string $reason, array $before, User $actor): ClaimRecalculationResult
    {
        $totals = $this->totalsService->recalculate($case);
        // Same guards as the totals service: with no positions, or only positions
        // awaiting a manual rate, the stored scalars (FX trace included) are the
        // only figures anyone has.
        $keepStoredScalars = $totals->itemCount === 0
            || ($totals->countedCount === 0 && $totals->needsManualFxCount > 0);
        $fxChange = $keepStoredScalars ? null : $this->fxTrace->refresh($case);
        $accessoryChange = $this->refreshAccessory($case, $totals->itemCount > 0 && $totals->countedCount === 0 && $totals->needsManualFxCount === 0);
        $referenceDate = $this->accessoryService->referenceDate($case);

        // The deadline lookups read the database, so the positions as they
        // stand now have to be there first.
        $this->em->flush();
        [$created, $removed, $unmatched] = $this->refreshPrescriptionDeadlines($case, $before);

        $courtProposal = $this->compareCourt($case, $referenceDate);
        $issues = $this->collectIssues($case, $referenceDate);

        $after = $this->snapshot($case);
        $this->auditLogService->log(
            action: self::ACTION,
            entityType: LegalCase::class,
            entityId: (string) $case->getId(),
            oldData: PiiMasker::maskCnpInArray($before),
            newData: PiiMasker::maskCnpInArray($after + [
                'reason' => $reason,
                'caseNumber' => $case->getCaseNumber(),
                'status' => $case->getStatus()->value,
                'accessoryChange' => $accessoryChange,
                'fxChange' => $fxChange,
                'courtProposal' => $this->courtProposalData($courtProposal),
                'deadlinesCreated' => array_map(static fn (LegalDeadline $d): array => self::deadlineData($d), $created),
                'deadlinesRemoved' => $removed,
                'deadlinesUnmatched' => array_map(static fn (LegalDeadline $d): array => self::deadlineData($d), $unmatched),
                'issues' => [
                    'errors' => array_map(static fn (AdmissibilityIssue $i): string => $i->code, $issues['errors']),
                    'warnings' => array_map(static fn (AdmissibilityIssue $i): string => $i->code, $issues['warnings']),
                ],
            ]),
            category: AuditLogService::CATEGORY_CLAIM_EDITED,
            actor: $actor,
        );
        $this->em->flush();

        return new ClaimRecalculationResult(
            totals: $totals,
            accessoryChange: $accessoryChange,
            fxChange: $fxChange,
            courtProposal: $courtProposal,
            deadlinesCreated: $created,
            deadlinesRemoved: $removed,
            deadlinesUnmatched: $unmatched,
            issues: $issues,
            referenceDate: $referenceDate,
        );
    }

    /**
     * The stored accessory at the case's reference date. When nothing counts
     * any more the aggregator has nothing to compute and the stored figure would
     * otherwise stay what it was: a claim emptied on purpose carries no accessory.
     *
     * @return array{from: ?string, to: string}|null
     */
    private function refreshAccessory(LegalCase $case, bool $nothingCounts): ?array
    {
        $change = $this->accessoryService->refreshStoredTotal($case);
        if ($change !== null || !$nothingCounts) {
            return $change;
        }

        $from = $case->getCalculatedInterest();
        $case->setCalculatedInterest('0.00');

        return $from === null || abs((float) $from) >= 0.005 ? ['from' => $from, 'to' => '0.00'] : null;
    }

    /**
     * Creates the prescription terms the positions now call for and deletes, with
     * audit, the open ones whose date is exactly the term computed for a due date
     * that just left the claim. Any other open term outside the expected set is
     * left alone and reported.
     *
     * @param array<string, mixed> $before
     *
     * @return array{0: list<LegalDeadline>, 1: list<int>, 2: list<LegalDeadline>}
     */
    private function refreshPrescriptionDeadlines(LegalCase $case, array $before): array
    {
        $expected = [];
        foreach ($this->deadlineService->prescriptionDueDates($case) as $dueDate) {
            $end = $this->deadlineService->limitationTermEnd(DeadlineType::PRESCRIPTIE, $dueDate);
            if ($end !== null) {
                $expected[$end->end->format('Y-m-d')] = true;
            }
        }

        // A position already prescribed gets its warning from the validator;
        // a term that matured before today would only be noise on the agenda.
        $created = $this->deadlineService->createPrescriptionDeadlines($case, new \DateTimeImmutable('today'));

        $gone = [];
        foreach ($this->dueDatesThatLeft($before) as $dueDate) {
            $end = $this->deadlineService->limitationTermEnd(DeadlineType::PRESCRIPTIE, $dueDate);
            if ($end !== null && !isset($expected[$end->end->format('Y-m-d')])) {
                $gone[$end->end->format('Y-m-d')] = true;
            }
        }

        $removed = [];
        $unmatched = [];
        foreach ($this->deadlineRepository->findBy(['legalCase' => $case, 'type' => DeadlineType::PRESCRIPTIE]) as $deadline) {
            if ($deadline->isCompleted()) {
                continue;
            }
            $key = $deadline->getDeadlineDate()->format('Y-m-d');
            if (isset($expected[$key])) {
                continue;
            }
            if (!isset($gone[$key])) {
                $unmatched[] = $deadline;

                continue;
            }

            $removed[] = $this->removeDeadline($case, $deadline);
        }

        if ($removed !== []) {
            $this->em->flush();
        }

        return [$created, $removed, $unmatched];
    }

    /**
     * Due dates the positions covered before the change, as the snapshot recorded
     * them: confirmed positions the lawyer had not excluded, credit notes aside,
     * which is the rule {@see DeadlineService::prescriptionDueDates()} applies.
     *
     * @param array<string, mixed> $before
     *
     * @return list<\DateTimeImmutable>
     */
    private function dueDatesThatLeft(array $before): array
    {
        $dates = [];
        foreach ($before['items'] ?? [] as $item) {
            if (!is_array($item) || ($item['excludedByLawyer'] ?? false) || ($item['kind'] ?? null) === 'credit_note') {
                continue;
            }
            $dueDate = $item['dueDate'] ?? null;
            if (!is_string($dueDate) || $dueDate === '') {
                continue;
            }
            $dates[$dueDate] = new \DateTimeImmutable($dueDate);
        }

        return array_values($dates);
    }

    private function removeDeadline(LegalCase $case, LegalDeadline $deadline): int
    {
        $id = (int) $deadline->getId();
        $this->auditLogService->log(
            action: 'deadline_deleted',
            entityType: LegalDeadline::class,
            entityId: (string) $id,
            oldData: [
                'type' => $deadline->getType()->value,
                'deadlineDate' => $deadline->getDeadlineDate()->format('Y-m-d'),
                'description' => $deadline->getDescription(),
            ],
            newData: ['caseNumber' => $case->getCaseNumber(), 'reason' => self::ACTION],
            category: AuditLogService::CATEGORY_DEADLINE_DELETED,
        );
        $case->removeDeadline($deadline);
        $this->em->remove($deadline);

        return $id;
    }

    /**
     * The competence rules against the court on the case. Null when they agree,
     * or when no position counts; a proposal when they name another court; an
     * unresolved proposal, current court kept, when they cannot name one.
     */
    private function compareCourt(LegalCase $case, \DateTimeImmutable $referenceDate): ?CourtProposal
    {
        $counting = $case->getCountingClaimItems();
        if ($counting === []) {
            return null;
        }

        $current = $case->getCourt();
        $primaryDebtor = $case->getPrimaryDebtor();
        $rate = $case->getContractualPenaltyRate();

        try {
            $result = $this->courtResolver->resolveForItems(
                items: $counting,
                referenceDate: $referenceDate,
                relationshipType: $case->getRelationshipType() ?? RelationshipType::COMERCIAL,
                debtorCounty: $primaryDebtor?->getAddressCounty(),
                debtorLocality: $primaryDebtor?->getAddressLocality(),
                penaltyType: $case->getPenaltyType() ?? PenaltyType::LEGAL_PENALIZATOARE,
                contractualDailyRate: $rate !== null ? (float) $rate : null,
                contractualPenaltyCapPercent: $case->contractualPenaltyCap(),
                contractDate: $case->contractDateImmutable(),
            );
        } catch (\DomainException | \RuntimeException | \InvalidArgumentException $e) {
            $this->logger->info('claim.recalculate.court_failed', ['case' => $case->getId(), 'reason' => $e->getMessage()]);

            return null;
        }

        if ($result->court === null) {
            return new CourtProposal($current, null, $result->explanationKey, $result->alternatives, $result->explanationParams);
        }
        if ($current !== null && $current->getId() === $result->court->getId()) {
            return null;
        }

        return new CourtProposal($current, $result->court, $result->explanationKey, [], $result->explanationParams);
    }

    /** @return array{errors: list<AdmissibilityIssue>, warnings: list<AdmissibilityIssue>} */
    private function collectIssues(LegalCase $case, \DateTimeImmutable $referenceDate): array
    {
        $issues = ['errors' => [], 'warnings' => []];
        $all = [
            ...$this->admissibilityValidator->validate($case),
            ...$this->computabilityChecker->issues($case, $referenceDate),
            ...$this->cutoffIssues($case),
        ];
        foreach ($all as $issue) {
            $issues[$issue->severity === IssueSeverity::ERROR ? 'errors' : 'warnings'][] = $issue;
        }

        return $issues;
    }

    /**
     * A cutoff the lawyer set before a counting position falls due leaves that
     * position with no accessory at all, and nothing else would say so: the
     * aggregator reports zero days late, which is correct and silent.
     *
     * @return list<AdmissibilityIssue>
     */
    private function cutoffIssues(LegalCase $case): array
    {
        $cutoff = $case->getAccessoryCutoffDate();
        if ($cutoff === null) {
            return [];
        }

        $issues = [];
        foreach ($case->getCountingClaimItems() as $index => $item) {
            $dueDate = $item->getDueDate();
            if ($item->isCreditNote() || $dueDate === null || $dueDate <= $cutoff) {
                continue;
            }
            $number = trim((string) $item->getDocumentNumber());
            $issues[] = new AdmissibilityIssue(
                IssueSeverity::WARNING,
                self::CODE_CUTOFF_BEFORE_DUE_DATE,
                'validation.accessory_cutoff.before_due_date',
                [
                    '%position%' => $number !== '' ? sprintf('%d (%s)', $index + 1, $number) : (string) ($index + 1),
                    '%cutoff%' => $cutoff->format('d.m.Y'),
                    '%due_date%' => $dueDate->format('d.m.Y'),
                ],
            );
        }

        return $issues;
    }

    /** @return array<string, mixed> */
    private function itemSnapshot(ClaimItem $item): array
    {
        $debtor = $item->getDebtor();
        $source = $item->getSourceDocument();

        return [
            'id' => $item->getId(),
            'documentNumber' => $item->getDocumentNumber(),
            'documentDate' => $item->getDocumentDate()?->format('Y-m-d'),
            'dueDate' => $item->getDueDate()?->format('Y-m-d'),
            'amount' => $item->getAmount(),
            'currency' => $item->getCurrency(),
            'exchangeRate' => $item->getExchangeRate(),
            'exchangeRateDate' => $item->getExchangeRateDate()?->format('Y-m-d'),
            'amountRon' => $item->getAmountRon(),
            'paidAmount' => $item->getPaidAmount(),
            'kind' => $item->getKind()->value,
            'excludedByLawyer' => $item->isExcludedByLawyer(),
            'confirmed' => $item->isConfirmedByLawyer(),
            'debtor' => $debtor !== null ? ['id' => $debtor->getId(), 'name' => $debtor->getName()] : null,
            'causeReference' => $item->getCauseReference(),
            'dedupKey' => $item->getDedupKey(),
            'description' => $item->getDescription(),
            'source' => $source !== null
                ? [
                    'origin' => 'document',
                    'documentId' => $source->getId(),
                    'filename' => $source->getOriginalFilename(),
                    'contentHash' => $source->getContentHash(),
                ]
                : ['origin' => 'manual'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function courtProposalData(?CourtProposal $proposal): ?array
    {
        if ($proposal === null) {
            return null;
        }

        return [
            'current' => self::courtData($proposal->current),
            'proposed' => self::courtData($proposal->proposed),
            'explanationKey' => $proposal->explanationKey,
            'candidates' => array_map(static fn (Court $c): array => (array) self::courtData($c), $proposal->candidates),
        ];
    }

    /** @return array{id: ?int, name: string}|null */
    private static function courtData(?Court $court): ?array
    {
        return $court !== null ? ['id' => $court->getId(), 'name' => $court->getName()] : null;
    }

    /** @return array{id: ?int, deadlineDate: string, description: ?string} */
    private static function deadlineData(LegalDeadline $deadline): array
    {
        return [
            'id' => $deadline->getId(),
            'deadlineDate' => $deadline->getDeadlineDate()->format('Y-m-d'),
            'description' => $deadline->getDescription(),
        ];
    }
}
