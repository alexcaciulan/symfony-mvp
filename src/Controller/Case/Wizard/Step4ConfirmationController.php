<?php

declare(strict_types=1);

namespace App\Controller\Case\Wizard;

use App\DTO\Calculation\AggregatedAccessoryResult;
use App\DTO\Calculation\CurrencyConversionResult;
use App\DTO\Calculation\InterestResult;
use App\DTO\Calculation\PenaltyResult;
use App\DTO\Calculation\StampDutyResult;
use App\DTO\Court\CourtResolveResult;
use App\DTO\Extraction\PrefillConflict;
use App\DTO\Validation\AdmissibilityIssue;
use App\DTO\Wizard\ClaimItemRow;
use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\DTO\Wizard\Step4ConfirmationData;
use App\DTO\Wizard\WizardPersistResult;
use App\Entity\ClaimItem;
use App\Entity\Court;
use App\Entity\Document;
use App\Entity\User;
use App\Form\Wizard\Step4ConfirmationType;
use App\Repository\CourtRepository;
use App\Service\Calculation\StampDutyCalculator;
use App\Service\Case\AccessoryComputabilityChecker;
use App\Service\Case\ClaimItemFactory;
use App\Service\Extraction\ConflictChoiceApplier;
use App\Service\Extraction\ConflictResolutionService;
use App\Service\Extraction\PrefillFromExtractionService;
use App\Service\Validation\OpAdmissibilityValidator;
use App\Service\Wizard\CaseWizardPersister;
use App\Service\Wizard\WizardBag;
use App\Service\Wizard\WizardConflictCoordinator;
use App\Service\Wizard\WizardCreditorResolver;
use App\Service\Wizard\WizardDebtorResolver;
use App\Service\Wizard\WizardDocumentReader;
use App\Service\Wizard\WizardFigures;
use App\Service\Wizard\WizardStateStore;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Wizard step 4: the summary the lawyer signs, and the submit.
 *
 * The whole submit runs inside `EntityManager::wrapInTransaction` (see
 * CaseWizardPersister) so a half-built case and orphan debtors never reach
 * the DB; the step 0 documents are attached to the case in the same
 * transaction.
 */
#[Route('/case/new', name: 'case_wizard_')]
#[IsGranted('ROLE_USER')]
final class Step4ConfirmationController extends AbstractWizardStepController
{
    public function __construct(
        WizardStateStore $stateStore,
        TranslatorInterface $translator,
        private readonly WizardConflictCoordinator $conflicts,
        private readonly ConflictResolutionService $conflictResolutions,
        private readonly ConflictChoiceApplier $conflictChoices,
        private readonly PrefillFromExtractionService $prefill,
        private readonly CourtRepository $courts,
        private readonly OpAdmissibilityValidator $admissibility,
        private readonly StampDutyCalculator $stampDutyCalculator,
        private readonly ClaimItemFactory $claimItemFactory,
        private readonly AccessoryComputabilityChecker $accessoryChecker,
        private readonly WizardDocumentReader $documentReader,
        private readonly WizardCreditorResolver $creditorResolver,
        private readonly WizardDebtorResolver $debtorResolver,
        private readonly WizardFigures $figures,
        private readonly CaseWizardPersister $persister,
    ) {
        parent::__construct($stateStore, $translator);
    }

    #[Route('/confirmation', name: 'confirmation', methods: ['GET', 'POST'])]
    public function confirmation(
        Request $request,
        #[CurrentUser] User $user,
        RateLimiterFactory $caseCreationLimiter,
    ): Response {
        $session = $request->getSession();
        $bag = $this->stateStore->load($session);
        if (($back = $this->redirectForDebtorsNoLongerOffered($bag)) !== null) {
            return $back;
        }
        if (($missing = $this->redirectToFirstMissingStep($bag)) !== null) {
            return $missing;
        }

        $creditorDto = $bag->creditor;
        $debtorsDto = $bag->debtors;
        $claimDto = $bag->claim;

        if (($recheck = $this->settleLibraryParties($bag, $session, $creditorDto, $debtorsDto, $user)) !== null) {
            return $recheck;
        }
        ['conflicts' => $conflicts, 'unsettled' => $unsettled] = $this->settleConflicts($bag, $session, $creditorDto, $debtorsDto, $claimDto);
        $conflictVars = $this->conflictVars($bag, $conflicts, $unsettled);

        [
            'conversion' => $conversion,
            'claimRows' => $claimRows,
            'calculations' => $calculations,
            'errors' => $errors,
            'warnings' => $warnings,
        ] = $this->computeFigures($bag, $user, $creditorDto, $debtorsDto, $claimDto);
        $hasWarnings = $warnings !== [];
        $sessionDocuments = $this->documentReader->loadOwnedDocuments($bag->documentIds, $user);

        $confirmation = new Step4ConfirmationData();
        $form = $this->createForm(Step4ConfirmationType::class, $confirmation, [
            'validation_groups' => $hasWarnings ? ['Default', 'with_warnings'] : ['Default'],
        ]);
        // Preselect the auto-resolved court id; the user may override it (or pick
        // one when resolution is ambiguous / county-unknown). On POST handleRequest
        // replaces this with the submitted id.
        $resolvedCourt = $calculations['court']?->court;
        if ($resolvedCourt !== null) {
            $form->get('court')->setData((string) $resolvedCourt->getId());
        }
        $form->handleRequest($request);

        $preselectedCourt = $this->preselectedCourt($form, $resolvedCourt);
        $autoFilledIndex = $this->persister->aggregateAutoFilledFields($creditorDto, $debtorsDto, $claimDto);
        $render = fn (): Response => $this->renderConfirmation($form, $creditorDto, $debtorsDto, $claimDto, $errors, $warnings, $calculations, $autoFilledIndex, $sessionDocuments, $preselectedCourt, $conflictVars);

        if (!$request->isMethod('POST')) {
            return $render();
        }

        if ($this->submissionRefused($form, $unsettled, $errors, $claimDto, $conversion, $user, $caseCreationLimiter)) {
            return $render();
        }

        ['court' => $chosenCourt, 'resolution' => $courtResolution] = $this->chooseCourt($form, $resolvedCourt);

        return $this->persistCase($session, $user, $bag, $creditorDto, $debtorsDto, $claimDto, $calculations, $autoFilledIndex, $warnings, $chosenCourt, $courtResolution, $conversion, $claimRows, $conflicts);
    }

    /**
     * Runs the checks that stop a submit, in order, flashing the reason where
     * there is one. The limiter is consumed only once every other check passed.
     *
     * @param list<PrefillConflict> $unsettled
     * @param list<AdmissibilityIssue> $errors
     */
    private function submissionRefused(FormInterface $form, array $unsettled, array $errors, Step3ClaimData $claimDto, ?CurrencyConversionResult $conversion, User $user, RateLimiterFactory $caseCreationLimiter): bool
    {
        if ($unsettled !== []) {
            $this->addFlash('error', 'wizard.conflict.unsettled_at_confirmation');

            return true;
        }

        if ($errors !== []) {
            $this->addFlash('error', 'wizard.step4.flash.errors_blocking');

            return true;
        }

        if (!$form->isValid()) {
            return true;
        }

        // A foreign-currency claim cannot be persisted without a resolved BNR
        // rate, otherwise the case lands in an inconsistent state (currency
        // EUR with no conversion, which the calculator guard rejects at
        // document time). Block with a clear message instead.
        if ($claimDto->currency !== 'RON' && $conversion === null) {
            $this->addFlash('error', 'wizard.step4.flash.exchange_rate_missing');

            return true;
        }

        $limiter = $caseCreationLimiter->create($user->getUserIdentifier());
        if (!$limiter->consume(1)->isAccepted()) {
            $this->addFlash('warning', 'rate_limit.case_creation');

            return true;
        }

        return false;
    }

    /**
     * @param array{interest: ?InterestResult, penalty: ?PenaltyResult, accessoryTotal: float, stampDuty: ?StampDutyResult, court: ?CourtResolveResult} $calculations
     * @param array{auto: list<string>, manual: list<string>} $autoFilledIndex
     * @param list<AdmissibilityIssue> $warnings
     * @param list<ClaimItemRow> $claimRows
     * @param list<PrefillConflict> $conflicts
     */
    private function persistCase(SessionInterface $session, User $user, WizardBag $bag, Step1CreditorData $creditorDto, Step2DebtorsData $debtorsDto, Step3ClaimData $claimDto, array $calculations, array $autoFilledIndex, array $warnings, ?Court $chosenCourt, string $courtResolution, ?CurrencyConversionResult $conversion, array $claimRows, array $conflicts): Response
    {
        $persisted = $this->persister->persist(
            $session,
            $user,
            $creditorDto,
            $debtorsDto,
            $claimDto,
            $bag->documentIds,
            $calculations,
            $autoFilledIndex,
            $warnings,
            $chosenCourt,
            $courtResolution,
            $conversion,
            $claimRows,
            $this->conflictResolutions->auditEntries(
                $conflicts,
                $bag->conflictResolutions,
                $this->documentReader->conflictDocumentDescriptors($bag->documentIds),
                $this->conflicts->acknowledgements($bag, $conflicts),
            ),
        );

        // The session forgets the run only after the transaction committed.
        $this->stateStore->detach($session);

        if ($persisted->creditorReused) {
            $this->addFlash('info', 'wizard.step4.flash.creditor_reused');
        }
        $this->flashSuccessToast($persisted, $debtorsDto, $calculations);
        // One-time hint on the overview (next-step badge and a pulse on
        // the generate CTA), consumed by its first render.
        $this->addFlash('case_just_created', '1');

        return $this->redirectToRoute('case_overview', ['id' => $persisted->case->getId()]);
    }

    /**
     * Soft guard: an incomplete wizard state means the user navigated directly
     * to step 4 without filling earlier steps. Redirects to the first missing step.
     */
    private function redirectToFirstMissingStep(WizardBag $bag): ?Response
    {
        if (($bag->creditor ?? null) === null) {
            return $this->redirectToRoute('case_wizard_creditor');
        }
        if (($bag->debtors ?? null) === null) {
            return $this->redirectToRoute('case_wizard_debtor');
        }
        if (($bag->claim ?? null) === null) {
            return $this->redirectToRoute('case_wizard_claim');
        }

        return null;
    }

    /**
     * A library company may have been edited since it was picked: the court and
     * the summary are worked out from what the case will carry. Returns the
     * redirect to the step that has to be rechecked, if any.
     */
    private function settleLibraryParties(WizardBag $bag, SessionInterface $session, Step1CreditorData $creditorDto, Step2DebtorsData $debtorsDto, User $user): ?Response
    {
        if ($this->creditorResolver->settleCreditorWithLibrary($creditorDto, $user)) {
            $bag->creditorLibraryRecheck = true;
            $this->stateStore->save($session, $bag);

            return $this->redirectToRoute('case_wizard_creditor');
        }
        $this->debtorResolver->refreshPickedDebtors($debtorsDto, $user);
        if ($this->debtorResolver->debtorReachedLibrary($debtorsDto, $user)) {
            $bag->debtorLibraryRecheck = true;
            $this->stateStore->save($session, $bag);

            return $this->redirectToRoute('case_wizard_debtor');
        }
        $this->debtorResolver->bindLibraryDebtors($debtorsDto, $user);
        if ($this->debtorResolver->checkAnafStatus($debtorsDto)) {
            $this->stateStore->save($session, $bag);
        }

        return null;
    }

    /**
     * Everything the documents disagree about, checked once more here. A
     * blocking conflict still open at this point was walked past rather than
     * decided, and this is the last screen before a filing is created.
     *
     * @return array{conflicts: list<PrefillConflict>, unsettled: list<PrefillConflict>}
     */
    private function settleConflicts(WizardBag $bag, SessionInterface $session, Step1CreditorData $creditorDto, Step2DebtorsData $debtorsDto, Step3ClaimData $claimDto): array
    {
        $prefill = $this->prefill->aggregate($bag->documentIds, $bag->conflictResolutions);
        $conflicts = $this->conflicts->withLibraryDebtor($bag, $prefill, array_values([
            ...$prefill->conflicts,
            ...$this->claimItemFactory->collectRows($bag->documentIds)->conflicts,
        ]));
        $conflicts = $this->conflicts->withoutAnafSettled($bag, $conflicts, $creditorDto, $debtorsDto);
        // Every disagreement of the file is on this screen, so a decision with
        // no conflict left to match is about documents that are no longer here
        // and has to go rather than wait for its key to mean something else.
        $bag->conflictResolutions = $this->conflictResolutions->reconcile($bag->conflictResolutions, $conflicts, true);
        // What the audit will say was retained is what the filing has to carry.
        // The steps already moved these values into the forms; doing it once
        // more here is what makes the two impossible to pull apart, whichever
        // way the bag was reached.
        $this->conflictChoices->applyToCreditor($creditorDto, null, $prefill->creditor, $bag->conflictResolutions);
        $this->conflictChoices->applyToDebtors($debtorsDto, null, $prefill->debtors, $bag->conflictResolutions);
        $this->conflictChoices->applyToClaim($claimDto, null, $prefill->claim, $bag->conflictResolutions);
        $this->stateStore->save($session, $bag);

        return [
            'conflicts' => $conflicts,
            'unsettled' => $this->conflicts->unsettledConflicts($bag, $conflicts),
        ];
    }

    /**
     * @param list<PrefillConflict> $conflicts
     * @param list<PrefillConflict> $unsettled
     *
     * @return array<string, mixed>
     */
    private function conflictVars(WizardBag $bag, array $conflicts, array $unsettled): array
    {
        return [
            'prefill_conflicts' => $conflicts,
            'prefill_conflict_resolutions' => $bag->conflictResolutions,
            'prefill_conflict_documents' => $this->documentReader->conflictDocumentNames($bag->documentIds),
            'prefill_conflicts_unsettled' => $unsettled,
        ];
    }

    /**
     * @return array{conversion: ?CurrencyConversionResult, claimRows: list<ClaimItemRow>, calculations: array{interest: ?InterestResult, penalty: ?PenaltyResult, accessoryTotal: float, stampDuty: ?StampDutyResult, court: ?CourtResolveResult, conversion: ?CurrencyConversionResult, accessoryPerItem: ?AggregatedAccessoryResult, claimItems: list<ClaimItem>, principal: ?float}, errors: list<AdmissibilityIssue>, warnings: list<AdmissibilityIssue>}
     */
    private function computeFigures(WizardBag $bag, User $user, Step1CreditorData $creditorDto, Step2DebtorsData $debtorsDto, Step3ClaimData $claimDto): array
    {
        $now = new \DateTimeImmutable();
        $conversion = $this->figures->resolveConversion($claimDto);
        $claimRows = $this->persister->resolveClaimRows($bag, $claimDto);
        $this->claimItemFactory->applyResolutions($claimRows, $bag->conflictResolutions);
        $skeleton = $this->persister->buildLegalCaseSkeleton($user, $creditorDto, $debtorsDto, $claimDto, $conversion, $claimRows);
        $calculations = $this->figures->safeComputeForSidebar($claimDto, $debtorsDto->debtors[0] ?? null, $conversion, $skeleton);
        $issues = [
            ...$this->admissibility->validate($skeleton, $now),
            ...$this->admissibility->validateClaimRows($claimRows),
            // Blocking: the summons cannot be generated for such a claim, and a
            // saved case's positions can no longer be corrected.
            ...$this->accessoryChecker->issues($skeleton, $this->figures->accessoryReferenceDate($claimDto)),
        ];

        return [
            'conversion' => $conversion,
            'claimRows' => $claimRows,
            'calculations' => $calculations,
            ...$this->figures->splitIssues($issues),
        ];
    }

    /**
     * Court shown in the picker on (re)render: the submitted/preselected id if
     * it loads to an active court, else the auto-resolved one.
     */
    private function preselectedCourt(FormInterface $form, ?Court $resolvedCourt): ?Court
    {
        $courtIdData = $form->get('court')->getData();
        $preselectedCourt = (is_string($courtIdData) && $courtIdData !== '')
            ? $this->courts->find($courtIdData)
            : null;
        if ($preselectedCourt === null || !$preselectedCourt->isActive()) {
            $preselectedCourt = $resolvedCourt;
        }

        return $preselectedCourt;
    }

    /**
     * Court precedence: the submitted id (auto-resolved preselection kept, or
     * manual override) wins when it loads to an active court; otherwise fall
     * back to the auto-resolved court. Loading + active check guards against a
     * tampered hidden id.
     *
     * @return array{court: ?Court, resolution: string}
     */
    private function chooseCourt(FormInterface $form, ?Court $resolvedCourt): array
    {
        $selectedId = $form->get('court')->getData();
        $selectedCourt = (is_string($selectedId) && $selectedId !== '')
            ? $this->courts->find($selectedId)
            : null;
        if ($selectedCourt !== null && !$selectedCourt->isActive()) {
            $selectedCourt = null;
        }
        $chosenCourt = $selectedCourt ?? $resolvedCourt;
        $courtResolution = match (true) {
            $chosenCourt === null => 'none',
            $resolvedCourt !== null && $chosenCourt->getId() === $resolvedCourt->getId() => 'auto',
            default => 'manual',
        };

        return ['court' => $chosenCourt, 'resolution' => $courtResolution];
    }

    /**
     * The overview toast names the case and gives two details: the number of
     * debtors and the total claimed (principal, accessories when any, stamp
     * duty). The duty falls back to the calculator's fixed amount (OUG 80/2013
     * art. 6 alin. 2) rather than a literal.
     *
     * @param array{interest: ?InterestResult, penalty: ?PenaltyResult, accessoryTotal: float, stampDuty: ?StampDutyResult, court: ?CourtResolveResult} $calculations
     */
    private function flashSuccessToast(WizardPersistResult $persisted, Step2DebtorsData $debtorsDto, array $calculations): void
    {
        $totalAmount = (float) $persisted->case->getAmount()
            + ($calculations['accessoryTotal'] ?? 0.0)
            + ($calculations['stampDuty']?->amount ?? $this->stampDutyCalculator->calculate()->amount);

        $this->addFlash('toast.success', [
            'key' => 'wizard.step4.flash.success_toast',
            'params' => ['%caseNumber%' => $persisted->case->getCaseNumber()],
            'details' => [
                ['key' => 'wizard.step4.flash.detail.debtors', 'params' => ['%count%' => count($debtorsDto->debtors)]],
                ['key' => 'wizard.step4.flash.detail.total', 'params' => ['%total%' => number_format($totalAmount, 2, ',', '.')]],
            ],
        ]);
    }

    /**
     * @param array{interest: ?InterestResult, penalty: ?PenaltyResult, accessoryTotal: float, stampDuty: ?StampDutyResult, court: ?CourtResolveResult} $calculations
     * @param list<AdmissibilityIssue> $errors
     * @param list<AdmissibilityIssue> $warnings
     * @param array{auto: list<string>, manual: list<string>} $autoFilledIndex
     * @param list<Document> $sessionDocuments
     * @param array<string, mixed> $conflictVars what the read-only conflicts panel needs
     */
    private function renderConfirmation(
        FormInterface $form,
        Step1CreditorData $creditor,
        Step2DebtorsData $debtors,
        Step3ClaimData $claim,
        array $errors,
        array $warnings,
        array $calculations,
        array $autoFilledIndex,
        array $sessionDocuments = [],
        ?Court $preselectedCourt = null,
        array $conflictVars = [],
    ): Response {
        return $this->render('case/_step4_confirmation_content.html.twig', [
            ...$conflictVars,
            'current_step' => 4,
            'form' => $form,
            'preselected_court' => $preselectedCourt,
            'creditor' => $creditor,
            'debtors' => $debtors,
            'claim' => $claim,
            'documents' => $sessionDocuments,
            'errors' => $errors,
            'warnings' => $warnings,
            'has_errors' => $errors !== [],
            'has_warnings' => $warnings !== [],
            'interest' => $calculations['interest'],
            'penalty' => $calculations['penalty'],
            'accessory_total' => $calculations['accessoryTotal'] ?? 0.0,
            'stamp_duty' => $calculations['stampDuty'],
            'court' => $calculations['court'],
            'conversion' => $calculations['conversion'] ?? null,
            // The positions are the claim, so the screen the lawyer signs shows
            // them and the principal they add up to, not the scalar from step 3.
            'claim_items' => $calculations['claimItems'] ?? [],
            'claim_item_accessories' => $calculations['accessoryPerItem'] ?? null,
            'claim_items_principal' => $calculations['principal'] ?? null,
            'auto_filled' => $autoFilledIndex,
        ]);
    }
}
