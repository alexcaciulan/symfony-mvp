<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Form\Deadline\AgendaAddDeadlineType;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\Deadline\AgendaResponseFactory;
use App\Service\Deadline\DeadlineAgendaFilter;
use App\Service\Deadline\DeadlinePageViewBuilder;
use App\Service\Deadline\DeadlineService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The global deadlines agenda: what the lawyer has to do today across every case.
 * Scoping is by the authenticated user inside the queries, so no case of another
 * account can reach the page.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class DeadlinesController extends AbstractController
{
    /** Dialog the header opens; the answer to a successful add dismisses it by id. */
    private const ADD_MODAL_ID = 'hs-modal-agenda-add-deadline';

    public function __construct(
        private readonly DeadlinePageViewBuilder $pageViewBuilder,
        private readonly DeadlineService $deadlineService,
        private readonly AgendaResponseFactory $agendaResponses,
        private readonly LegalCaseRepository $legalCaseRepository,
    ) {}

    #[Route('/termene', name: 'app_deadlines', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('deadlines/index.html.twig', [
            'view' => $this->pageViewBuilder->build($user, DeadlineAgendaFilter::fromRequest($request)),
            // The choices of the add dialog. Same set the form validates against, so
            // what the header offers and what the route accepts cannot drift apart.
            'add_cases' => $this->legalCaseRepository->findActiveByUser($user),
            'add_modal_id' => self::ADD_MODAL_ID,
        ]);
    }

    /**
     * A manual reminder added without leaving the agenda. The type stays OTHER, as
     * on the case page: everything else is a legal term, computed from the fact it
     * runs from, and typing one by hand would put a date on screen that no rule
     * produced.
     *
     * The case comes from the form rather than from the URL, so it is checked twice:
     * the choices only hold the lawyer's own active cases, and the voter is asked
     * about the one that came back. The deadline itself is created by the same
     * service the case page calls, so the audit entry is identical.
     */
    #[Route('/termene/termen-nou', name: 'app_deadlines_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(AgendaAddDeadlineType::class, null, ['user' => $user]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $firstError = null;
            foreach ($form->getErrors(true) as $error) {
                $firstError = $error;
                break;
            }

            // The dialog stays open on a rejection, so what was typed is still there.
            return $this->respond($request, $user, 'error', $firstError?->getMessage() ?? 'deadlines.add.flash_error_validation', null);
        }

        $data = $form->getData();
        /** @var LegalCase $case */
        $case = $data['legalCase'];
        $this->denyAccessUnlessGranted(CaseVoter::DEADLINE_MANAGE, $case);

        $this->deadlineService->createCustomDeadline($case, $data['deadlineDate'], $data['description'] ?? null);

        return $this->respond($request, $user, 'success', 'deadlines.add.flash_added', self::ADD_MODAL_ID);
    }

    /** Agenda regions for a Turbo client, redirect back to the agenda otherwise. */
    private function respond(
        Request $request,
        User $user,
        string $toastVariant,
        string $toastKey,
        ?string $closeModalId,
    ): Response {
        if ($this->agendaResponses->wantsTurboStream($request)) {
            return $this->agendaResponses->stream($request, $user, $toastVariant, $toastKey, $closeModalId);
        }

        $this->addFlash($toastVariant, $toastKey);

        return $this->agendaResponses->redirect($request);
    }
}
