<?php

declare(strict_types=1);

namespace App\Service\Wizard;

use App\DTO\Calculation\CurrencyConversionResult;
use App\DTO\Calculation\InterestResult;
use App\DTO\Calculation\PenaltyResult;
use App\DTO\Calculation\StampDutyResult;
use App\DTO\Court\CourtResolveResult;
use App\DTO\Validation\AdmissibilityIssue;
use App\DTO\Wizard\ClaimItemRow;
use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\DTO\Wizard\WizardPersistResult;
use App\Entity\Court;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\User;
use App\Repository\CreditorRepository;
use App\Repository\DocumentRepository;
use App\Service\AuditLogService;
use App\Service\Case\ClaimItemFactory;
use App\Service\Case\ClaimTotalsService;
use App\Service\Case\LegalCaseClaimFieldsMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Writes the case a wizard run describes, and builds the transient skeleton
 * the confirmation step validates and computes on before anything is written.
 *
 * The whole submit runs inside `EntityManager::wrapInTransaction`, so a
 * half-built case and orphan debtors never reach the database.
 */
final class CaseWizardPersister
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WizardCreditorResolver $creditorResolver,
        private readonly WizardDebtorResolver $debtorResolver,
        private readonly WizardFigures $figures,
        private readonly LegalCaseClaimFieldsMapper $claimFields,
        private readonly ClaimItemFactory $claimItemFactory,
        private readonly ClaimTotalsService $claimTotals,
        private readonly CreditorRepository $creditors,
        private readonly DocumentRepository $documents,
        private readonly WizardStateStore $stateStore,
        private readonly AuditLogService $auditLog,
        private readonly float $confidenceThreshold = ClaimRowsPostBinder::DEFAULT_REVIEW_THRESHOLD,
    ) {}

    /**
     * @param list<int> $documentIds
     * @param array{interest: ?InterestResult, penalty: ?PenaltyResult, accessoryTotal: float, stampDuty: ?StampDutyResult, court: ?CourtResolveResult} $calculations
     * @param array{auto: list<string>, manual: list<string>} $autoFilledIndex
     * @param list<AdmissibilityIssue> $warnings
     * @param list<ClaimItemRow> $claimRows
     * @param list<array<string, mixed>> $conflictResolutions
     */
    public function persist(
        SessionInterface $session,
        User $user,
        Step1CreditorData $creditorDto,
        Step2DebtorsData $debtorsDto,
        Step3ClaimData $claimDto,
        array $documentIds,
        array $calculations,
        array $autoFilledIndex,
        array $warnings,
        ?Court $court,
        string $courtResolution,
        ?CurrencyConversionResult $conversion,
        array $claimRows = [],
        array $conflictResolutions = [],
    ): WizardPersistResult {
        return $this->em->wrapInTransaction(function () use (
            $session,
            $user,
            $creditorDto,
            $debtorsDto,
            $claimDto,
            $documentIds,
            $calculations,
            $autoFilledIndex,
            $warnings,
            $court,
            $courtResolution,
            $conversion,
            $claimRows,
            $conflictResolutions,
        ): WizardPersistResult {
            $resolved = $this->creditorResolver->reuseOrCreateCreditor($user, $creditorDto);
            $creditor = $resolved->creditor;

            $case = new LegalCase();
            $case->setUser($user);
            $case->setCreditor($creditor);
            // Foreign currency is stored converted to RON; the original values
            // are kept for transparency in the document (art. 9 audit trail).
            if ($conversion !== null) {
                $case->setAmount(sprintf('%.2f', $conversion->ronAmount));
                $case->setCurrency('RON');
                $case->setOriginalAmount(sprintf('%.2f', $conversion->originalAmount));
                $case->setOriginalCurrency($conversion->originalCurrency);
                $case->setExchangeRate(sprintf('%.4f', $conversion->rate));
                $case->setExchangeRateDate($conversion->rateDate);
            } else {
                $case->setAmount(sprintf('%.2f', $claimDto->amount ?? 0.0));
                $case->setCurrency($claimDto->currency);
            }
            // LegalCase.dueDate is DATE_MUTABLE in Doctrine and the DTO holds an
            // immutable; Doctrine's DateType rejects DateTimeImmutable, so it is
            // converted at the persistence boundary.
            $case->setDueDate($claimDto->dueDate !== null ? \DateTime::createFromImmutable($claimDto->dueDate) : null);
            $case->setRelationshipType($claimDto->relationshipType);
            $this->claimFields->applyAccessoryFields($case, $claimDto);

            if (($calculations['accessoryTotal'] ?? 0.0) > 0.0) {
                $case->setCalculatedInterest(sprintf('%.2f', $calculations['accessoryTotal']));
            }
            if ($calculations['stampDuty'] !== null) {
                $case->setStampDuty(sprintf('%.2f', $calculations['stampDuty']->amount));
            }
            if ($court !== null) {
                $case->setCourt($court);
            }

            $debtorSources = [];
            foreach ($debtorsDto->debtors as $entry) {
                $link = $this->debtorResolver->buildDebtorLink($user, $entry);
                $debtorSources[] = match (true) {
                    $link->getDebtor()->getId() === null => 'new',
                    $entry->updateLibrary => 'library_updated',
                    default => 'library',
                };
                $this->em->persist($link->getDebtor());
                $case->addDebtor($link);
            }

            // The positions are the claim; the case scalars are their total,
            // written here and nowhere else.
            if ($claimRows !== []) {
                $this->claimItemFactory->materialize($case, $claimRows);
                $this->claimTotals->recalculate($case);
            }

            $this->em->persist($case);
            $this->em->flush();

            if ($documentIds !== []) {
                $this->documents->attachToCase($documentIds, $case);
            }
            // The run is over once its documents belong to the case; dropping
            // it in the same transaction means a failed submit keeps the draft
            // and a successful one never leaves a stale copy in the list.
            $this->stateStore->removeStored($session);

            $this->auditLog->log(
                action: 'wizard_submit',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: [
                    'fields_auto' => $autoFilledIndex['auto'],
                    'fields_manual' => $autoFilledIndex['manual'],
                    'extractedDocIds' => $documentIds,
                    'admissibility_warnings' => array_map(static fn (AdmissibilityIssue $i) => $i->code, $warnings),
                    'court_resolution' => $courtResolution,
                    'court_id' => $court?->getId(),
                    // Which creditor the case names, and whether it came from
                    // the library (updated with this step's data or not).
                    'creditor' => ['creditorId' => $creditor->getId(), 'source' => $resolved->source],
                    // Which company the case pursues, and whether it was reused
                    // from the library (updated with this step's data or not)
                    // or created with this case.
                    'debtors' => array_map(
                        static fn (LegalCaseDebtor $link, string $source): array => ['debtorId' => $link->getDebtor()->getId(), 'source' => $source],
                        $case->getDebtors()->toArray(),
                        $debtorSources,
                    ),
                    // What the lawyer signed off on, position by position: the
                    // table-wide tick is only as good as the record of what it
                    // covered, and an exclusion has to stay provable.
                    'claim_items' => $this->auditClaimItems($claimRows),
                    // Why one value was retained and not the other the documents
                    // also stated. Months later the file has to answer that, and
                    // only the record of the choice can.
                    'conflict_resolutions' => $conflictResolutions,
                ],
                category: AuditLogService::CATEGORY_WIZARD_SUBMIT,
            );

            $this->em->flush();

            return new WizardPersistResult($case, $resolved->reused, $resolved->source);
        });
    }

    /**
     * Walks every DTO field and splits it into `auto` (prefilled from the
     * extraction) and `manual` (everything else the user touched). Used as the
     * `newData` payload of the wizard_submit audit entry, so a future reader
     * can answer which fields of this case came from AI extraction.
     *
     * @return array{auto: list<string>, manual: list<string>}
     */
    public function aggregateAutoFilledFields(
        Step1CreditorData $creditor,
        Step2DebtorsData $debtors,
        Step3ClaimData $claim,
    ): array {
        $auto = [];
        $manual = [];

        foreach ($creditor->autoFilled as $field) {
            $auto[] = 'creditor.' . $field;
        }
        foreach ($this->dtoFieldNames($creditor) as $field) {
            if (!in_array($field, $creditor->autoFilled, true)) {
                $manual[] = 'creditor.' . $field;
            }
        }

        foreach ($debtors->debtors as $index => $entry) {
            foreach ($entry->autoFilled as $field) {
                $auto[] = sprintf('debtor[%d].%s', $index, $field);
            }
            foreach ($this->dtoFieldNames($entry) as $field) {
                if (!in_array($field, $entry->autoFilled, true)) {
                    $manual[] = sprintf('debtor[%d].%s', $index, $field);
                }
            }
        }

        foreach ($claim->autoFilled as $field) {
            $auto[] = 'claim.' . $field;
        }
        foreach ($this->dtoFieldNames($claim) as $field) {
            if (!in_array($field, $claim->autoFilled, true)) {
                $manual[] = 'claim.' . $field;
            }
        }

        return ['auto' => $auto, 'manual' => $manual];
    }

    /**
     * The positions the case will carry: the ones read from the documents and
     * confirmed on the step 3 table, or the single one implied by a manually
     * filled claim, which the lawyer confirmed by submitting the form.
     *
     * @return list<ClaimItemRow>
     */
    public function resolveClaimRows(WizardBag $bag, Step3ClaimData $claimDto): array
    {
        $rows = $bag->claimItems ?? null;
        if ($rows !== null && $rows !== []) {
            return $rows;
        }

        $row = $this->claimItemFactory->rowFromClaim($claimDto);
        if ($row === null) {
            return [];
        }
        $row->confirmed = true;

        return [$row];
    }

    /**
     * A transient case built from the steps, never persisted from here: enough
     * for the admissibility check, the accessories and the competence rule to
     * run on exactly what will be saved at submit.
     *
     * @param list<ClaimItemRow> $claimRows
     */
    public function buildLegalCaseSkeleton(
        User $user,
        Step1CreditorData $creditorDto,
        Step2DebtorsData $debtorsDto,
        Step3ClaimData $claimDto,
        ?CurrencyConversionResult $conversion = null,
        array $claimRows = [],
    ): LegalCase {
        $case = new LegalCase();
        $case->setUser($user);
        $ronAmount = $this->figures->ronAmount($claimDto, $conversion);
        $case->setAmount($ronAmount !== null ? sprintf('%.2f', $ronAmount) : null);
        $case->setCurrency($conversion !== null ? 'RON' : $claimDto->currency);
        $case->setDueDate($claimDto->dueDate !== null ? \DateTime::createFromImmutable($claimDto->dueDate) : null);
        $case->setRelationshipType($claimDto->relationshipType);
        $this->claimFields->applyAccessoryFields($case, $claimDto);

        // The creditor is attached only when picked from the library: the
        // skeleton is for OpAdmissibilityValidator, which only looks at the
        // debtors, so an unloaded creditor breaks nothing.
        if ($creditorDto->creditorId !== null) {
            $existing = $this->creditors->findOwned($user, $creditorDto->creditorId);
            if ($existing !== null) {
                $case->setCreditor($existing);
            }
        }

        foreach ($debtorsDto->debtors as $entry) {
            // Empty rows are skipped so the validator does not raise NotNull
            // errors on entries the user added and never filled out.
            if ($entry->personType === null && ($entry->name === null || $entry->name === '')) {
                continue;
            }
            $case->addDebtor($this->debtorResolver->buildDebtorLink($user, $entry, write: false));
        }

        if ($claimRows !== []) {
            $this->claimItemFactory->materialize($case, $claimRows);
            $this->claimTotals->recalculate($case);
        }

        return $case;
    }

    /**
     * @param  list<ClaimItemRow>        $rows
     * @return list<array<string, mixed>>
     */
    private function auditClaimItems(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = [
                'dedupKey' => $row->dedupKey,
                'documentNumber' => $row->documentNumber,
                'dueDate' => $row->dueDate?->format('Y-m-d'),
                'amount' => $row->amount,
                'currency' => $row->currency,
                'amountRon' => $row->amountRon,
                'sourceDocumentId' => $row->sourceDocumentId,
                'confirmation' => match (true) {
                    $row->excluded => 'excluded',
                    !$row->confirmed => 'unconfirmed',
                    $row->requiresIndividualConfirmation($this->confidenceThreshold) => 'individual',
                    default => 'table',
                },
                'needsManualFx' => $row->needsManualFx,
            ];
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function dtoFieldNames(object $dto): array
    {
        $names = [];
        foreach ((new \ReflectionObject($dto))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->getName() === 'autoFilled') {
                continue;
            }
            $names[] = $prop->getName();
        }

        return $names;
    }
}
