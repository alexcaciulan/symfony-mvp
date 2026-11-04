<?php

declare(strict_types=1);

namespace App\Controller\Case\Wizard;

use App\Entity\User;
use App\Enum\ConflictScope;
use App\Form\Wizard\Step1CreditorType;
use App\Repository\CreditorRepository;
use App\Service\Extraction\ConflictResolutionService;
use App\Service\Wizard\WizardConflictCoordinator;
use App\Service\Wizard\WizardCreditorResolver;
use App\Service\Wizard\WizardDocumentReader;
use App\Service\Wizard\WizardStateStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Wizard step 1: the creditor, prefilled from the documents or taken from the library. */
#[Route('/case/new', name: 'case_wizard_')]
#[IsGranted('ROLE_USER')]
final class Step1CreditorController extends AbstractWizardStepController
{
    public function __construct(
        WizardStateStore $stateStore,
        TranslatorInterface $translator,
        private readonly WizardConflictCoordinator $conflicts,
        private readonly ConflictResolutionService $conflictResolutions,
        private readonly CreditorRepository $creditors,
        private readonly WizardCreditorResolver $creditorResolver,
        private readonly WizardDocumentReader $documentReader,
    ) {
        parent::__construct($stateStore, $translator);
    }

    #[Route('/creditor', name: 'creditor', methods: ['GET', 'POST'])]
    public function creditor(Request $request, #[CurrentUser] User $user): Response
    {
        $session = $request->getSession();
        $bag = $this->stateStore->load($session);

        [$prefill, $conflicts] = $this->conflicts->prefillWithConflicts($bag, [ConflictScope::CREDITOR]);
        $dto = $bag->creditor ?? $prefill->creditor;
        // Taken before the form binds, because the form writes into this very
        // object: it is what the page put on screen, which is how a field the
        // lawyer edited is told from one they left as the aggregation had it.
        $rendered = $this->conflicts->snapshotDto($dto);
        $form = $this->createForm(Step1CreditorType::class, $dto);
        // The picker is unmapped, so a creditor chosen earlier has to be put
        // back by hand; an empty picker on the next submit reads as "enter a
        // new creditor manually" and would drop the choice. A creditor the
        // lawyer chose to update stays in the manual fields with this step's
        // data, so the choice is asked again rather than lost to the picker.
        $picked = $dto->creditorId !== null && !$dto->updateLibrary ? $this->creditors->findOwned($user, $dto->creditorId) : null;
        if ($picked !== null) {
            $form->get('creditorEntity')->setData($picked);
        }
        $form->handleRequest($request);
        $conflicts = $this->conflicts->withoutAnafSettled($bag, $conflicts, $form->getData(), null);

        $submitted = $form->isSubmitted() && $form->isValid();
        $acknowledged = true;
        $rejected = [];
        if ($form->isSubmitted()) {
            // Settled on every submission, valid form or not: a step refused
            // over a mistyped field would otherwise make the lawyer choose
            // between the documents all over again, which is the most ordinary
            // way to reach this page twice.
            $acknowledged = $this->settled($this->conflicts->settleConflicts($request, $bag, 'creditor', $conflicts));
            $rejected = $this->conflictResolutions->rejectedChoices(
                $request->request->all('prefill_conflict'),
                $conflicts,
                $bag->conflictResolutions,
            );
            $stands = $this->conflicts->applyChosenValues($bag, ConflictScope::CREDITOR, $rendered, $form->getData(), $rejected);
            $acknowledged = $acknowledged && $stands;
        }
        $libraryRecheck = $bag->creditorLibraryRecheck;
        if ($form->isSubmitted()) {
            $bag->creditorLibraryRecheck = false;
        }
        $libraryDiffers = null;
        if ($submitted && $acknowledged) {
            $choice = $request->request->get('library_creditor_choice');
            $libraryDiffers = $this->creditorResolver->matchLibraryCreditor($form->getData(), $user, is_string($choice) ? $choice : '');
        }
        if ($submitted && $acknowledged && $libraryDiffers === null) {
            $bag->creditor = $form->getData();
            $this->stateStore->save($session, $bag);

            return $this->redirectToRoute('case_wizard_debtor');
        }
        $this->stateStore->save($session, $bag);

        return $this->render('case/_step1_creditor_content.html.twig', [
            'current_step' => 1,
            'form' => $form,
            'dto' => $dto,
            'creditor_library_differs' => $libraryDiffers,
            'creditor_library_recheck' => $libraryRecheck && $libraryDiffers === null,
            'creditor_bank_accounts' => $this->documentReader->creditorBankAccounts($bag->documentIds, $user),
            ...$this->conflicts->conflictViewVars($bag, $conflicts, 'creditor', $rejected),
        ], $this->stepRejected($acknowledged && $libraryDiffers === null));
    }
}
