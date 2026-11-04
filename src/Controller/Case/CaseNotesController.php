<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\DTO\Case\CaseNotesInput;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Form\Case\CaseNotesType;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\Case\CaseNotesService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Saving the lawyer's internal notes from the case page, in any status. The
 * answer is a Turbo Stream (the card redrawn and a toast); without Turbo, a
 * redirect with a flash.
 */
#[IsGranted('ROLE_USER')]
final class CaseNotesController extends AbstractController
{
    public function __construct(
        private readonly LegalCaseRepository $cases,
        private readonly CaseNotesService $notes,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/case/{id}/notes', name: 'case_notes_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(Request $request, int $id, #[CurrentUser] User $user): Response
    {
        $case = $this->cases->findWithOverviewRelations($id);
        if ($case === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(CaseVoter::NOTES_EDIT, $case);

        $form = $this->createForm(CaseNotesType::class, new CaseNotesInput($case->getNotes()));
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $message = 'case.claim.edit.error.invalid';
            foreach ($form->getErrors(true) as $error) {
                $message = $error->getMessage();
                break;
            }

            return $this->respond($request, $case, 'error', $this->translator->trans($message), false);
        }

        /** @var CaseNotesInput $input */
        $input = $form->getData();
        $changed = $this->notes->update($case, $input->notes, $user);

        return $this->respond(
            $request,
            $case,
            $changed ? 'success' : 'info',
            $this->translator->trans($changed ? 'case.notes.flash.saved' : 'case.notes.flash.unchanged'),
            true,
        );
    }

    /** A refused save keeps the card as it is, so the typed text is not lost. */
    private function respond(Request $request, LegalCase $case, string $variant, string $message, bool $redrawCard): Response
    {
        if (!str_contains((string) $request->headers->get('Accept', ''), 'text/vnd.turbo-stream.html')) {
            $this->addFlash($variant, $message);

            return $this->redirectToRoute('case_overview', ['id' => $case->getId()]);
        }

        return new Response(
            $this->renderView('case/overview/_notes_turbo_stream.html.twig', [
                'case' => $case,
                'redraw_card' => $redrawCard,
                'toast_message' => $message,
                'toast_variant' => $variant,
            ]),
            Response::HTTP_OK,
            ['Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8'],
        );
    }
}
