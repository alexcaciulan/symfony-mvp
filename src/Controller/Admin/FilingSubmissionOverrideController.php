<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\FilingSubmission;
use App\Entity\User;
use App\Repository\FilingSubmissionRepository;
use App\Service\Filing\FilingSendBlockedException;
use App\Service\Filing\FilingSubmissionService;
use App\Service\Filing\UncertainPartResolver;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Operational unblocking of a stuck court filing by an admin, always with a
 * reason that is audited: an uncertain email marked as not sent (or, for an
 * annex, as sent), or the rest of the submission closed or cancelled. It never
 * declares the petition accepted and never touches the filing date; the page
 * shows statuses and codes only, never the content or the copies of the emails.
 */
#[IsGranted('ROLE_ADMIN')]
class FilingSubmissionOverrideController extends AbstractController
{
    public function __construct(
        private readonly FilingSubmissionRepository $submissions,
        private readonly UncertainPartResolver $resolver,
        private readonly FilingSubmissionService $submissionService,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/admin/filing-submission/{id}/override', name: 'admin_filing_submission_override', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $submission = $this->find($id);

        return $this->render('admin/filing_submission/override.html.twig', [
            'submission' => $submission,
            'closable' => $this->submissionService->isClosable($submission) || $this->submissionService->isCancellable($submission),
            'back_url' => $this->crudUrl($id),
        ]);
    }

    #[Route('/admin/filing-submission/{id}/part/{sequence}/override', name: 'admin_filing_submission_part_override', requirements: ['id' => '\d+', 'sequence' => '\d+'], methods: ['POST'])]
    public function overridePart(int $id, int $sequence, Request $request): Response
    {
        $submission = $this->find($id);
        $part = $submission->getPart($sequence) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('filing_override_' . $id . '_' . $sequence, $request->getPayload()->getString('_token'))) {
            return $this->back($id, 'error', 'filing.override.flash.csrf');
        }

        try {
            $this->resolver->adminOverride($part, $this->currentUser(), $request->getPayload()->getString('outcome'), $request->getPayload()->getString('reason'));
        } catch (FilingSendBlockedException $e) {
            return $this->back($id, 'error', $e->messageKey);
        }

        return $this->back($id, 'success', 'filing.override.flash.saved');
    }

    #[Route('/admin/filing-submission/{id}/close', name: 'admin_filing_submission_close', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function close(int $id, Request $request): Response
    {
        $submission = $this->find($id);
        if (!$this->isCsrfTokenValid('filing_admin_close_' . $id, $request->getPayload()->getString('_token'))) {
            return $this->back($id, 'error', 'filing.override.flash.csrf');
        }

        try {
            $this->submissionService->closeByAdmin($submission, $this->currentUser(), $request->getPayload()->getString('reason'));
        } catch (FilingSendBlockedException $e) {
            return $this->back($id, 'error', $e->messageKey);
        }

        return $this->back($id, 'success', 'filing.override.flash.closed');
    }

    private function back(int $id, string $type, string $messageKey): Response
    {
        $this->addFlash($type, $this->translator->trans($messageKey));

        return $this->redirectToRoute('admin_filing_submission_override', ['id' => $id]);
    }

    private function find(int $id): FilingSubmission
    {
        return $this->submissions->find($id) ?? throw $this->createNotFoundException();
    }

    private function crudUrl(int $id): string
    {
        return $this->adminUrlGenerator->unsetAll()->setController(FilingSubmissionCrudController::class)->setAction(Action::DETAIL)->setEntityId($id)->generateUrl();
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
