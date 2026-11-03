<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\Case\CaseDeletionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Deleting a case from its page, confirmed in a dialog. A success leaves the page
 * for the list of cases, the case being gone; a refusal answers in place, as a
 * toast, so the dialog and what was typed in it stay.
 */
#[IsGranted('ROLE_USER')]
final class CaseDeletionController extends AbstractController
{
    private const REASON_MAX_LENGTH = 500;

    public function __construct(
        private readonly LegalCaseRepository $cases,
        private readonly CaseDeletionService $deletion,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/case/{id}/delete', name: 'case_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function __invoke(Request $request, int $id, #[CurrentUser] User $user): Response
    {
        $case = $this->cases->findWithOverviewRelations($id);
        if (!$case instanceof LegalCase) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(CaseVoter::DELETE, $case);

        $payload = $request->getPayload();
        if (!$this->isCsrfTokenValid('delete_case_' . $id, $payload->getString('_token'))) {
            return $this->respondError($request, $case, 'case.delete.flash_error_csrf');
        }

        // The summons already produced is the one loss the lawyer must own in
        // writing: a dated notice to the debtor disappears with the case.
        if ($case->getPaymentNoticeDate() !== null && !$payload->getBoolean('acknowledge')) {
            return $this->respondError($request, $case, 'case.delete.flash_acknowledge_required');
        }

        $reason = trim($payload->getString('reason'));
        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            return $this->respondError($request, $case, 'case.delete.flash_reason_too_long');
        }

        try {
            $this->deletion->delete($case, $user, $reason === '' ? null : $reason);
        } catch (\DomainException) {
            return $this->respondError($request, $case, 'case.delete.flash_forbidden_status');
        }

        $this->addFlash('success', $this->translator->trans('case.delete.flash_success', ['%caseNumber%' => $case->getCaseNumber()]));

        return $this->redirectToRoute('app_cases');
    }

    private function respondError(Request $request, LegalCase $case, string $messageKey): Response
    {
        $message = $this->translator->trans($messageKey);

        if (str_contains((string) $request->headers->get('Accept', ''), 'text/vnd.turbo-stream.html')) {
            return $this->render(
                'case/overview/_toast_turbo_stream.html.twig',
                ['message' => $message],
                new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8']),
            );
        }

        $this->addFlash('error', $message);

        return $this->redirectToRoute('case_overview', ['id' => $case->getId()]);
    }
}
