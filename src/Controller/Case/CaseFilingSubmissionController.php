<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Repository\FilingSubmissionRepository;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\Case\OverviewContextBuilder;
use App\Service\Filing\CourtFilingEmailFactory;
use App\Service\Filing\FilingDateRefusedException;
use App\Service\Filing\FilingDateService;
use App\Service\Filing\FilingEmlStorage;
use App\Service\Filing\FilingSendBlockedException;
use App\Service\Filing\FilingSendGuard;
use App\Service\Filing\FilingSubmissionService;
use App\Service\Filing\RecipientOverrideGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Transmitting the signed package to the court by email, on the lawyer's
 * request: the confirmation panel, the request itself, cancelling before
 * anything left, the copy of each sent email, and the filing date the lawyer
 * declares afterwards. Only the lawyer of the case acts here; the content is
 * under professional secrecy, so an admin has no access.
 */
final class CaseFilingSubmissionController extends AbstractController
{
    public function __construct(
        private readonly LegalCaseRepository $cases,
        private readonly FilingSubmissionRepository $submissions,
        private readonly FilingSubmissionService $submissionService,
        private readonly FilingSendGuard $guard,
        private readonly CourtFilingEmailFactory $emailFactory,
        private readonly RecipientOverrideGuard $recipientGuard,
        private readonly FilingDateService $filingDates,
        private readonly FilingEmlStorage $emlStorage,
        private readonly OverviewContextBuilder $contextBuilder,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/case/{id}/filing/submission/confirm', name: 'case_filing_submission_confirm', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function confirm(int $id): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::FILING_CONTENT, $case);

        return $this->render('case/filing/_send_modal.html.twig', $this->confirmContext($case));
    }

    #[Route('/case/{id}/filing/submission', name: 'case_filing_submission_submit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function submit(int $id, Request $request): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::FILING_CONTENT, $case);
        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        if (!$this->isCsrfTokenValid('filing_submit_' . $id, $request->getPayload()->getString('_token'))) {
            return $this->panelError($request, $case, 'filing.package.flash.csrf');
        }

        $confirmed = [];
        foreach (array_keys($this->submissionService->confirmationTexts($case)) as $key) {
            $confirmed[$key] = $request->getPayload()->getString('confirm_' . $key) === '1';
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        try {
            $this->submissionService->submit(
                $case,
                $this->currentUser(),
                $confirmed,
                $request->getClientIp(),
                $session !== null && $session->getId() !== '' ? hash('sha256', $session->getId()) : null,
            );
        } catch (FilingSendBlockedException $e) {
            return $this->panelError($request, $case, $e->messageKey);
        }

        return $this->respondCard($request, $case, 'success', 'filing.send.flash_queued');
    }

    #[Route('/case/{id}/filing/submission/{submissionId}/cancel', name: 'case_filing_submission_cancel', requirements: ['id' => '\d+', 'submissionId' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, int $submissionId, Request $request): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::FILING_CONTENT, $case);
        $submission = $this->findSubmission($case, $submissionId);

        if (!$this->isCsrfTokenValid('filing_cancel_' . $submissionId, $request->getPayload()->getString('_token'))) {
            return $this->respondCard($request, $case, 'error', 'filing.package.flash.csrf', false);
        }

        try {
            $this->submissionService->cancel($submission, $this->currentUser());
        } catch (FilingSendBlockedException $e) {
            return $this->respondCard($request, $case, 'error', $e->messageKey, false);
        }

        return $this->respondCard($request, $case, 'success', 'filing.status.cancelled');
    }

    /** The card alone, refreshed by the page while the emails are on their way. */
    #[Route('/case/{id}/filing/submission/card', name: 'case_filing_submission_card', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function card(int $id): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::VIEW, $case);

        return $this->render('case/filing/_submission_card.html.twig', $this->contextBuilder->build($case));
    }

    #[Route('/case/{id}/filing/submission/{submissionId}/part/{sequence}/eml', name: 'case_filing_submission_eml', requirements: ['id' => '\d+', 'submissionId' => '\d+', 'sequence' => '\d+'], methods: ['GET'])]
    public function eml(int $id, int $submissionId, int $sequence): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::FILING_CONTENT, $case);
        $part = $this->findSubmission($case, $submissionId)->getPart($sequence) ?? throw $this->createNotFoundException();

        // A copy that no longer matches its recorded hash is not offered as what was sent.
        $path = $this->emlStorage->verifiedPath($part) ?? throw $this->createNotFoundException();
        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('email-instanta-%s-partea-%d.eml', preg_replace('/[^A-Za-z0-9_-]+/', '_', $case->getCaseNumber()), $sequence),
        );
        $response->headers->set('Content-Type', 'message/rfc822');

        return $response;
    }

    #[Route('/case/{id}/filing/submission/filed-date', name: 'case_filing_submission_filed_date', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function filedDate(int $id, Request $request): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::FILING_CONTENT, $case);

        if (!$this->isCsrfTokenValid('filing_filed_date_' . $id, $request->getPayload()->getString('_token'))) {
            return $this->respondCard($request, $case, 'error', 'filing.package.flash.csrf', false);
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->getPayload()->getString('filed_at'));
        if ($date === false) {
            return $this->respondCard($request, $case, 'error', 'filing.status.set_date.error_invalid', false);
        }

        try {
            $this->filingDates->setFromLawyer($case, $date, $this->currentUser());
        } catch (FilingDateRefusedException $e) {
            return $this->respondCard($request, $case, 'error', $e->messageKey, false);
        }

        return $this->respondCard($request, $case, 'success', 'filing.status.set_date.saved');
    }

    /** @return array<string, mixed> */
    private function confirmContext(LegalCase $case, ?string $errorKey = null): array
    {
        $verdict = $this->guard->checkForSubmit($case, $this->currentUser());
        $preview = null;
        if ($verdict->isAllowed() && $verdict->package !== null) {
            $package = $verdict->package;
            $emails = $this->emailFactory->emailsOf($package);
            $attachments = [];
            foreach ($emails as $i => $parts) {
                foreach ($parts as $part) {
                    $attachments[] = ['email' => $i + 1, 'name' => $this->emailFactory->attachmentName($package, $part), 'bytes' => $part->getSignedBytes()];
                }
            }
            $preview = [
                'subject' => $this->emailFactory->subject($case, 1, count($emails)),
                'body' => $this->emailFactory->body($case, $package, 1),
                'from' => sprintf('%s <%s>', $this->emailFactory->fromName($case->getUser()), $this->emailFactory->fromAddress()),
                'cc' => $case->getUser()->getEmail(),
                'email_count' => count($emails),
                'attachments' => $attachments,
                'interest_date' => $package->getSourceManifest()['accessoryReferenceDate'] ?? null,
            ];
        }

        return [
            'case' => $case,
            'verdict' => $verdict,
            'preview' => $preview,
            'confirmations' => $this->submissionService->confirmationTexts($case),
            'court_live' => $this->recipientGuard->isLive(),
            'recipient_override' => $this->recipientGuard->override(),
            'error_key' => $errorKey,
        ];
    }

    private function panelError(Request $request, LegalCase $case, string $key): Response
    {
        if ($this->wantsTurboStream($request)) {
            return $this->render(
                'case/filing/_send_modal_turbo_stream.html.twig',
                $this->confirmContext($case, $key),
                new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8']),
            );
        }

        $this->addFlash('error', $key);

        return $this->redirectToRoute('case_overview', ['id' => $case->getId(), 'tab' => 'documente']);
    }

    private function respondCard(Request $request, LegalCase $case, string $variant, string $key, bool $replaceCard = true): Response
    {
        if ($this->wantsTurboStream($request)) {
            $context = $replaceCard ? $this->contextBuilder->build($case) : ['case' => $case];
            $context['replace_card'] = $replaceCard;
            $context['toast_variant'] = $variant;
            $context['toast_key'] = $key;
            $context['toast_params'] = [];

            return new Response(
                $this->renderView('case/filing/_package_turbo_stream.html.twig', $context),
                Response::HTTP_OK,
                ['Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8'],
            );
        }

        $this->addFlash($variant, $this->translator->trans($key));

        return $this->redirectToRoute('case_overview', ['id' => $case->getId(), 'tab' => 'documente']);
    }

    private function wantsTurboStream(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept', ''), 'text/vnd.turbo-stream.html');
    }

    private function findSubmission(LegalCase $case, int $submissionId): FilingSubmission
    {
        $submission = $this->submissions->find($submissionId);
        if (!$submission instanceof FilingSubmission || $submission->getLegalCase()->getId() !== $case->getId()) {
            throw $this->createNotFoundException();
        }

        return $submission;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function findOrThrow(int $id): LegalCase
    {
        $case = $this->cases->find($id);
        if (!$case instanceof LegalCase || $case->isDeleted()) {
            throw $this->createNotFoundException();
        }

        return $case;
    }
}
