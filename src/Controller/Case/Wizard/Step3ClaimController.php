<?php

declare(strict_types=1);

namespace App\Controller\Case\Wizard;

use App\DTO\Wizard\ClaimItemRow;
use App\DTO\Wizard\Step3ClaimData;
use App\Enum\ConflictScope;
use App\Form\Wizard\Step3ClaimType;
use App\Service\Case\ClaimItemFactory;
use App\Service\Extraction\ConflictResolutionService;
use App\Service\Wizard\ClaimRowsPostBinder;
use App\Service\Wizard\WizardConflictCoordinator;
use App\Service\Wizard\WizardDocumentReader;
use App\Service\Wizard\WizardStateStore;
use App\Util\ClaimRowLiveMapper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Wizard step 3: the claim and its positions table. */
#[Route('/case/new', name: 'case_wizard_')]
#[IsGranted('ROLE_USER')]
final class Step3ClaimController extends AbstractWizardStepController
{
    public function __construct(
        WizardStateStore $stateStore,
        TranslatorInterface $translator,
        private readonly WizardConflictCoordinator $conflicts,
        private readonly ConflictResolutionService $conflictResolutions,
        private readonly ClaimItemFactory $claimItemFactory,
        private readonly ClaimRowsPostBinder $rowsBinder,
        private readonly WizardDocumentReader $documentReader,
    ) {
        parent::__construct($stateStore, $translator);
    }

    #[Route('/claim', name: 'claim', methods: ['GET', 'POST'])]
    public function claim(Request $request): Response
    {
        $session = $request->getSession();
        $bag = $this->stateStore->load($session);
        if (($back = $this->redirectForDebtorsNoLongerOffered($bag)) !== null) {
            return $back;
        }

        [$prefill, $claimConflicts] = $this->conflicts->prefillWithConflicts($bag, [ConflictScope::CLAIM]);
        $collected = $this->claimItemFactory->collectRows($bag->documentIds);
        $conflicts = array_values([...$claimConflicts, ...$collected->conflicts]);
        $bag->conflictResolutions = $this->conflictResolutions->reconcile($bag->conflictResolutions, $conflicts);
        $dto = $bag->claim ?? $this->claimWithPositionAwareDescription($prefill->claim, $collected->rows);
        $rendered = $this->conflicts->snapshotDto($dto);
        $form = $this->createForm(Step3ClaimType::class, $dto);
        $form->handleRequest($request);

        $rows = $bag->claimItems ?? $collected->rows;
        $tableConfirmed = $bag->claimItemsTableConfirmed;
        $rowErrors = [];

        $submitted = $form->isSubmitted() && $form->isValid();
        $acknowledged = true;
        $rejected = [];
        if ($form->isSubmitted()) {
            $acknowledged = $this->settled($this->conflicts->settleConflicts($request, $bag, 'claim', $conflicts));
            $rejected = $this->conflictResolutions->rejectedChoices(
                $request->request->all('prefill_conflict'),
                $conflicts,
                $bag->conflictResolutions,
            );
            $stands = $this->conflicts->applyChosenValues($bag, ConflictScope::CLAIM, $rendered, $form->getData(), $rejected);
            $acknowledged = $acknowledged && $stands;
        }
        // Applied after the decisions of this request are in: a sum chosen now
        // has to reach the table now, not on the next render.
        $this->claimItemFactory->applyResolutions($rows, $bag->conflictResolutions);
        if ($submitted && $acknowledged) {
            if ($rows !== []) {
                $tableConfirmed = $request->request->getBoolean('claim_items_table_confirmed');
                $this->rowsBinder->applyPostedRows($rows, $request->request->all('claim_items'), $tableConfirmed);
                $rowErrors = $this->rowsBinder->validateRows($rows, $tableConfirmed);
            }

            if ($rowErrors === []) {
                $bag->claim = $form->getData();
                $bag->claimItems = $rows !== [] ? $rows : null;
                $bag->claimItemsTableConfirmed = $tableConfirmed;
                $this->stateStore->save($session, $bag);

                return $this->redirectToRoute('case_wizard_confirmation');
            }

            foreach ($rowErrors as $errorKey) {
                $this->addFlash('error', $errorKey);
            }
        }

        // The sidebar and the positions table are owned by
        // `Step3ClaimLiveComponent`, which recomputes interest and totals from
        // its `rows` prop on every debounced edit. The controller only hands it
        // the initial rows; step 4 uses WizardFigures::safeComputeForSidebar()
        // for the final figures.
        $this->stateStore->save($session, $bag);

        return $this->render('case/_step3_claim_content.html.twig', [
            'current_step' => 3,
            'form' => $form,
            'dto' => $dto,
            'claim_items' => $rows,
            'claim_items_live' => ClaimRowLiveMapper::toArrays($rows),
            'claim_items_table_confirmed' => $tableConfirmed,
            'claim_items_review_threshold' => $this->rowsBinder->reviewThreshold(),
            'claim_items_errors' => $rowErrors,
            'claim_items_source_names' => $this->documentReader->conflictDocumentNames($bag->documentIds),
            ...$this->conflicts->conflictViewVars($bag, $conflicts, 'claim', $rejected),
        ], $this->stepRejected($acknowledged));
    }

    /**
     * With several invoices the aggregated description is one invoice's wording,
     * which reads as if the claim were only about that one. The per-invoice
     * wording is what the petition itemises and stays on the rows; this field is
     * the object of the whole claim, so it names all of them.
     *
     * @param list<ClaimItemRow> $rows
     */
    private function claimWithPositionAwareDescription(Step3ClaimData $claim, array $rows): Step3ClaimData
    {
        // Only the invoices the claim asks for: an excluded position (a paid
        // advance, a duplicate) or a storno is not an unpaid invoice.
        $rows = array_values(array_filter(
            $rows,
            static fn (ClaimItemRow $row): bool => $row->willCount() && !$row->isCreditNote(),
        ));
        if (count($rows) < 2) {
            return $claim;
        }

        $numbered = array_values(array_filter(array_map(
            static fn (ClaimItemRow $row) => $row->documentNumber,
            $rows,
        )));

        $claim->description = $numbered === []
            ? $this->translator->trans('wizard.step3.derived_from_positions.description_generic', ['%count%' => count($rows)])
            : $this->translator->trans('wizard.step3.derived_from_positions.description', [
                '%count%' => count($rows),
                '%numbers%' => implode(', ', $numbered),
            ]);

        return $claim;
    }
}
