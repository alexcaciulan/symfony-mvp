<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Library\DebtorLibraryData;
use App\Entity\Debtor;
use App\Entity\User;
use App\Form\DebtorLibraryType;
use App\Repository\DebtorRepository;
use App\Security\Voter\DebtorVoter;
use App\Service\Debtor\DebtorLibraryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Debtor library: the companies the lawyer pursues, kept once and reused across
 * cases. What was checked about a debtor for a case stays on that case.
 */
#[Route('/debtors')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class DebtorLibraryController extends AbstractController
{
    public function __construct(
        private DebtorRepository $debtors,
        private DebtorLibraryService $library,
        private TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_debtors', methods: ['GET'])]
    public function index(): Response
    {
        // Rows come from the generic table endpoint (DebtorTableDefinition).
        return $this->render('debtors/index.html.twig');
    }

    #[Route('/new', name: 'app_debtors_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = new DebtorLibraryData();
        $form = $this->createForm(DebtorLibraryType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && !$this->isDuplicate($form, $user, $data, null)) {
            $this->library->create($user, $data);
            $this->addFlash('success', $this->translator->trans('library.debtors.flash.created'));

            return $this->redirectToRoute('app_debtors');
        }

        return $this->render('debtors/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/edit', name: 'app_debtors_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $debtor = $this->findOr404($id);
        $this->denyAccessUnlessGranted(DebtorVoter::EDIT, $debtor);

        $data = DebtorLibraryData::fromDebtor($debtor);
        $form = $this->createForm(DebtorLibraryType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->library->cuiChangeRefused($debtor, $data)) {
            $form->get('cui')->addError(new FormError($this->translator->trans('library.debtors.error.cui_after_summons')));
        }
        if ($form->isSubmitted() && $form->isValid() && !$this->isDuplicate($form, $user, $data, $debtor)) {
            $this->library->update($debtor, $data);
            $this->addFlash('success', $this->translator->trans('library.debtors.flash.updated'));

            return $this->redirectToRoute('app_debtors');
        }

        return $this->render('debtors/edit.html.twig', [
            'form' => $form,
            'debtor' => $debtor,
            'cases' => $this->debtors->casesUsing($debtor),
            'links' => $this->debtors->countLinks($debtor),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_debtors_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $debtor = $this->findOr404($id);
        $this->denyAccessUnlessGranted(DebtorVoter::DELETE, $debtor);
        if (!$this->isCsrfTokenValid('debtor_delete_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if ($this->library->delete($debtor)) {
            $this->addFlash('success', $this->translator->trans('library.debtors.flash.deleted'));
        } else {
            $this->addFlash('error', $this->translator->trans('library.debtors.flash.in_use'));
        }

        return $this->redirectToRoute('app_debtors');
    }

    private function findOr404(int $id): Debtor
    {
        $debtor = $this->debtors->find($id);
        if ($debtor === null) {
            throw $this->createNotFoundException();
        }

        return $debtor;
    }

    private function isDuplicate(FormInterface $form, User $user, DebtorLibraryData $data, ?Debtor $current): bool
    {
        if ($this->library->findDuplicate($user, $data, $current) === null) {
            return false;
        }
        $form->get('cui')->addError(new FormError($this->translator->trans('library.debtors.error.cui_duplicate')));

        return true;
    }
}
