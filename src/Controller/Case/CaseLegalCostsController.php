<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\DTO\Case\LegalCostsInput;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Form\Case\LegalCostsType;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\Case\ClaimEditRefused;
use App\Service\Case\LegalCostsEditor;
use App\Service\Case\OverviewContextBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Correcting the recovery costs from the case page, until the payment order
 * petition is generated. Every answer is a Turbo Stream, so the dialog the
 * lawyer typed in is never lost to a reload; without Turbo, a redirect with a
 * flash.
 */
#[IsGranted('ROLE_USER')]
final class CaseLegalCostsController extends AbstractController
{
    public const MODAL = 'hs-modal-legal-costs';

    public function __construct(
        private readonly LegalCaseRepository $cases,
        private readonly LegalCostsEditor $editor,
        private readonly OverviewContextBuilder $contextBuilder,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/case/{id}/legal-costs', name: 'case_legal_costs_edit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(Request $request, int $id, #[CurrentUser] User $user): Response
    {
        $case = $this->cases->findWithOverviewRelations($id);
        if ($case === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isGranted(CaseVoter::LEGAL_COSTS_EDIT, $case)) {
            // The owner whose petition was generated from another tab gets the
            // card redrawn locked and the reason; anyone else gets the 403.
            $this->denyAccessUnlessGranted(CaseVoter::VIEW, $case);

            return $this->respondLocked($request, $case);
        }

        $form = $this->createForm(LegalCostsType::class, LegalCostsInput::fromCase($case));
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->respondToast($request, $case, 'error', $this->translator->trans($this->firstErrorKey($form)));
        }

        try {
            $change = $this->editor->update($case, $form->getData(), $user);
        } catch (ClaimEditRefused $refusal) {
            return $this->respondLocked($request, $case);
        }

        $details = $change->paymentOrderMarkedOutdated
            ? [$this->translator->trans('case.legal_costs.changed.payment_order_outdated')]
            : [];
        $message = $this->translator->trans($change->isEmpty() ? 'case.legal_costs.flash.unchanged' : 'case.legal_costs.flash.saved');
        if (!$this->wantsStream($request)) {
            $this->addFlash($change->isEmpty() ? 'info' : 'success', $message);
            foreach ($details as $detail) {
                $this->addFlash('warning', $detail);
            }

            return $this->redirectToRoute('case_overview', ['id' => $case->getId()]);
        }

        return $this->renderCostsStream($case, [
            'toast_variant' => $change->isEmpty() ? 'info' : 'success',
            'toast_message' => $message,
            'toast_details' => $details,
            'refresh_documents' => $change->paymentOrderMarkedOutdated,
        ]);
    }

    /** The card redrawn without its button, the dialog closed, and the reason as a toast. */
    private function respondLocked(Request $request, LegalCase $case): Response
    {
        $message = $this->translator->trans('case.claim.edit.error.' . LegalCostsEditor::REFUSAL);
        if (!$this->wantsStream($request)) {
            $this->addFlash('error', $message);

            return $this->redirectToRoute('case_overview', ['id' => $case->getId()]);
        }

        return $this->renderCostsStream($case, [
            'toast_variant' => 'error',
            'toast_message' => $message,
            'toast_details' => [],
            'refresh_documents' => false,
        ]);
    }

    private function respondToast(Request $request, LegalCase $case, string $variant, string $message): Response
    {
        if (!$this->wantsStream($request)) {
            $this->addFlash($variant, $message);

            return $this->redirectToRoute('case_overview', ['id' => $case->getId()]);
        }

        return $this->streamResponse($this->renderView('case/overview/_toast_turbo_stream.html.twig', [
            'message' => $message,
            'variant' => $variant,
        ]));
    }

    /** @param array<string, mixed> $extra */
    private function renderCostsStream(LegalCase $case, array $extra): Response
    {
        $context = $extra['refresh_documents'] ? $this->contextBuilder->build($case) : ['case' => $case];

        return $this->streamResponse($this->renderView('case/overview/_legal_costs_turbo_stream.html.twig', $context + $extra + [
            'close_modal_id' => self::MODAL,
        ]));
    }

    private function firstErrorKey(FormInterface $form): string
    {
        foreach ($form->getErrors(true) as $error) {
            return $error->getMessage();
        }

        return 'case.claim.edit.error.invalid';
    }

    private function wantsStream(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept', ''), 'text/vnd.turbo-stream.html');
    }

    private function streamResponse(string $body): Response
    {
        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8']);
    }
}
