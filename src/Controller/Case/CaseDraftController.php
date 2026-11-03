<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\User;
use App\Repository\CaseDraftRepository;
use App\Service\Wizard\CaseDraftDiscarder;
use App\Service\Wizard\CaseDraftPayloadCodec;
use App\Service\Wizard\CaseDraftWizardStateStore;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The two actions on an unfinished run from the lists: pick it up again, or
 * drop it with its documents. A draft of another user does not exist here.
 */
#[Route('/case/draft', name: 'case_draft_')]
#[IsGranted('ROLE_USER')]
final class CaseDraftController extends AbstractController
{
    /** Where the list that showed the buttons lives, so a discard returns there. */
    private const RETURN_ROUTES = ['app_dashboard', 'app_cases', 'case_wizard_start'];

    public function __construct(
        private readonly CaseDraftRepository $drafts,
        private readonly CaseDraftWizardStateStore $store,
        private readonly CaseDraftDiscarder $discarder,
        private readonly CaseDraftPayloadCodec $codec,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/{id}/continue', name: 'continue', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function continue(Request $request, int $id, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('case_draft_continue_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }
        $draft = $this->drafts->findOneByIdAndUser($id, $user) ?? throw $this->createNotFoundException('Draft not found');

        if (!$this->codec->isReadable($draft)) {
            $this->addFlash('error', 'case.drafts.unreadable');

            return $this->redirectToRoute('case_wizard_start');
        }

        $draft->touch($this->clock->now());
        $this->drafts->getEntityManager()->flush();
        $this->store->attach($request->getSession(), $draft);

        return $this->redirectToRoute('case_wizard_' . $draft->getStepReached());
    }

    #[Route('/{id}/discard', name: 'discard', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function discard(Request $request, int $id, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('case_draft_discard_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }
        $draft = $this->drafts->findOneByIdAndUser($id, $user) ?? throw $this->createNotFoundException('Draft not found');

        $session = $request->getSession();
        $wasActive = $this->store->currentDraft($session)?->getId() === $draft->getId();
        $this->discarder->discard($draft, $user, CaseDraftDiscarder::REASON_DISCARDED);
        if ($wasActive) {
            $this->store->detach($session);
        }
        $this->addFlash('success', 'case.drafts.discarded_flash');

        $return = (string) $request->request->get('_return', '');

        return $this->redirectToRoute(in_array($return, self::RETURN_ROUTES, true) ? $return : 'case_wizard_start');
    }
}
