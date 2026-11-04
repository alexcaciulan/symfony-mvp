<?php

declare(strict_types=1);

namespace App\Controller\Case\Wizard;

use App\DTO\Extraction\ConflictResolution;
use App\DTO\Wizard\Step2DebtorsData;
use App\Entity\Debtor;
use App\Entity\User;
use App\Enum\ConflictScope;
use App\Form\Wizard\DebtorPickType;
use App\Form\Wizard\Step2DebtorsType;
use App\Repository\DebtorRepository;
use App\Service\Extraction\ConflictResolutionService;
use App\Service\Wizard\WizardConflictCoordinator;
use App\Service\Wizard\WizardDebtorResolver;
use App\Service\Wizard\WizardStateStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Wizard step 2: the debtors, typed, prefilled from the documents or picked
 * from the library. Step2DebtorsLiveComponent posts its form to `debtor`.
 */
#[Route('/case/new', name: 'case_wizard_')]
#[IsGranted('ROLE_USER')]
final class Step2DebtorController extends AbstractWizardStepController
{
    public function __construct(
        WizardStateStore $stateStore,
        TranslatorInterface $translator,
        private readonly WizardConflictCoordinator $conflicts,
        private readonly ConflictResolutionService $conflictResolutions,
        private readonly DebtorRepository $debtorLibrary,
        private readonly WizardDebtorResolver $debtorResolver,
    ) {
        parent::__construct($stateStore, $translator);
    }

    /** The identity and contact fields a library creditor and the step share. */
    #[Route('/debtor', name: 'debtor', methods: ['GET', 'POST'])]
    public function debtor(Request $request, #[CurrentUser] User $user): Response
    {
        $session = $request->getSession();
        $bag = $this->stateStore->load($session);

        [$prefill, $conflicts] = $this->conflicts->prefillWithConflicts($bag, [ConflictScope::DEBTOR, ConflictScope::DEBTOR_SET]);
        $dto = $bag->debtors ?? $prefill->debtors;
        // Shown afresh on arrival; a submission carries what the page showed,
        // which matchLibraryDebtors() sets against the library itself.
        if ($bag->debtors !== null && !$request->isMethod('POST')) {
            $this->debtorResolver->refreshPickedDebtors($bag->debtors, $user);
        }

        // Adding and removing debtors happens through Step2DebtorsLiveComponent
        // (LiveActions on the component re-render only its template). The
        // controller owns only the page entry and the final submit. The form
        // is rebuilt here from the request data so server-side validation
        // catches a debtor list mutated past the cap or with empty entries.
        $rendered = $this->conflicts->snapshotDto($dto);
        $form = $this->createForm(Step2DebtorsType::class, $dto);
        $form->handleRequest($request);
        $conflicts = $this->conflicts->withoutAnafSettled($bag, $conflicts, null, $form->getData());

        $submitted = $form->isSubmitted() && $form->isValid();
        $acknowledged = true;
        $rejected = [];
        if ($form->isSubmitted()) {
            $shownParty = $this->conflicts->chosenParty($bag, $conflicts);
            $acknowledged = $this->settled($this->conflicts->settleConflicts($request, $bag, 'debtors', $conflicts));
            if ($this->conflicts->chosenParty($bag, $conflicts) !== $shownParty) {
                $this->conflicts->discardChosenParty($bag);
                $this->stateStore->save($session, $bag);

                return $this->redirectToRoute('case_wizard_debtor');
            }
            $rejected = $this->conflictResolutions->rejectedChoices(
                $request->request->all('prefill_conflict'),
                $conflicts,
                $bag->conflictResolutions,
            );
            $stands = $this->conflicts->applyChosenValues($bag, ConflictScope::DEBTOR, $rendered, $form->getData(), $rejected);
            $acknowledged = $acknowledged && $stands;
        }
        $libraryRecheck = $bag->debtorLibraryRecheck;
        if ($form->isSubmitted()) {
            $bag->debtorLibraryRecheck = false;
        }
        $libraryDiffers = [];
        if ($submitted && $acknowledged) {
            $libraryDiffers = $this->debtorResolver->matchLibraryDebtors($form->getData(), $user, $request->request->all('library_debtor_choice'));
        }
        if ($submitted && $acknowledged && $libraryDiffers === []) {
            $bag->debtors = $form->getData();
            $this->debtorResolver->bindLibraryDebtors($bag->debtors, $user);
            $this->stateStore->save($session, $bag);

            return $this->redirectToRoute('case_wizard_claim');
        }
        $this->stateStore->save($session, $bag);

        return $this->render('case/_step2_debtor_content.html.twig', [
            'current_step' => 2,
            'form' => $form,
            'pick_form' => $this->createForm(DebtorPickType::class, null, [
                'action' => $this->generateUrl('case_wizard_debtor_pick'),
            ]),
            'library_differs' => $libraryDiffers,
            'library_recheck' => $libraryRecheck && $libraryDiffers === [],
            'dto' => $form->getData() ?? $dto,
            ...$this->conflicts->conflictViewVars($bag, $conflicts, 'debtors', $rejected),
        ], $this->stepRejected($acknowledged && $libraryDiffers === []));
    }

    /**
     * Takes the case's debtor from the lawyer's library. The identity comes
     * from the library; nothing checked for another case comes with it (ANAF,
     * the Law 85/2014 attestation): those are done again for this case. What
     * the step held before is kept so "Renunță" can bring it back.
     */
    #[Route('/debtor/pick', name: 'debtor_pick', methods: ['POST'])]
    public function pickDebtor(Request $request, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(DebtorPickType::class);
        $form->handleRequest($request);
        $chosen = $form->isSubmitted() && $form->isValid() ? $form->get('debtor')->getData() : null;
        $picked = $chosen instanceof Debtor ? $this->debtorLibrary->findOwned($user, (int) $chosen->getId()) : null;
        if ($picked === null || $picked->getCuiKey() === null) {
            return $this->redirectToRoute('case_wizard_debtor', status: Response::HTTP_SEE_OTHER);
        }

        $session = $request->getSession();
        $bag = $this->stateStore->load($session);
        $current = $bag->debtors->debtors[0] ?? null;
        if ($current === null || $current->debtorId === null) {
            $bag->debtorBeforePick = $bag->debtors;
        }
        $bag->debtors = new Step2DebtorsData([$this->debtorResolver->entryFromLibrary($picked)]);
        $bag->conflictResolutions = array_filter(
            $bag->conflictResolutions,
            static fn (ConflictResolution $r): bool => $r->scope !== ConflictScope::DEBTOR,
        );
        $this->stateStore->save($session, $bag);

        return $this->redirectToRoute('case_wizard_debtor', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/debtor/unpick', name: 'debtor_unpick', methods: ['POST'])]
    public function unpickDebtor(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('wizard_debtor_unpick', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $session = $request->getSession();
        $bag = $this->stateStore->load($session);
        // Only a debtor that still comes from the library is given back: a
        // repeated request must not wipe what the first one restored.
        if (($bag->debtors->debtors[0]->debtorId ?? null) !== null) {
            $bag->debtors = $bag->debtorBeforePick;
            $bag->debtorBeforePick = null;
            $this->stateStore->save($session, $bag);
        }

        return $this->redirectToRoute('case_wizard_debtor', status: Response::HTTP_SEE_OTHER);
    }
}
