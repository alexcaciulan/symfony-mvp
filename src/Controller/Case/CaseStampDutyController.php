<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\LegalCase;
use App\Enum\StampDutyStatus;
use App\Form\Case\StampDutyProofType;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\Case\OverviewContextBuilder;
use App\Service\StampDuty\StampDutyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Judicial stamp duty (OUG 80/2013): proof of payment, deferral to the court's
 * regularization procedure, and the court's notice date that starts the 10-day term.
 *
 * The proof has its own upload route rather than the generic document modal for two
 * reasons: it carries payment state no other document has, and it must remain
 * uploadable after the case is registered, which `CASE_UPLOAD` forbids.
 */
final class CaseStampDutyController extends AbstractController
{
    public function __construct(
        private readonly LegalCaseRepository $legalCaseRepository,
        private readonly StampDutyService $stampDutyService,
        private readonly OverviewContextBuilder $contextBuilder,
        private readonly string $stampDutyLawVersion,
    ) {}

    #[Route('/case/{id}/stamp-duty/proof', name: 'case_stamp_duty_proof', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function proof(int $id, Request $request, RateLimiterFactory $documentUploadLimiter): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::STAMP_DUTY_MANAGE, $case);

        // The UI hides the button once the duty is paid, but a second proof would
        // leave two DOVADA_TAXA_TIMBRU documents on the case and put an ambiguous
        // one in the filing package.
        if ($case->getStampDutyStatus() === StampDutyStatus::ACHITATA) {
            return $this->respond($request, $case, false, 'warning', 'case_overview.stamp_duty.flash_error_already_paid');
        }

        $user = $this->getUser();
        if ($user !== null && !$documentUploadLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
            return $this->respond($request, $case, false, 'warning', 'rate_limit.document_upload');
        }

        $form = $this->createForm(StampDutyProofType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $firstError = null;
            foreach ($form->getErrors(true) as $error) {
                $firstError = $error;
                break;
            }

            return $this->respond(
                $request,
                $case,
                false,
                'error',
                $firstError?->getMessage() ?? 'case_overview.stamp_duty.flash_error_invalid',
            );
        }

        $data = $form->getData();
        $payerName = $data['payerName'] ?? null;
        $paidAmount = $data['paidAmount'] ?? null;

        $this->stampDutyService->recordPayment(
            case: $case,
            file: $form->get('file')->getData(),
            user: $user,
            paidAt: $data['paidAt'],
            paidAmount: $paidAmount !== null ? sprintf('%.2f', $paidAmount) : null,
            payerName: $payerName,
            paymentReference: $data['paymentReference'] ?? null,
            lawVersion: $this->stampDutyLawVersion,
        );

        // Advisory, never blocking: the claimant owes the duty, so a proof in
        // another name weakens the art. 40 alin. 3 presumption of payment.
        $extraToast = $this->stampDutyService->payerDiffersFromCreditor($case, $payerName)
            ? 'case_overview.stamp_duty.flash_warning_payer_mismatch'
            : null;

        return $this->respond(
            $request,
            $case,
            true,
            'success',
            'case_overview.stamp_duty.flash_success_paid',
            'hs-modal-stamp-duty-proof',
            $extraToast,
        );
    }

    #[Route('/case/{id}/stamp-duty/defer', name: 'case_stamp_duty_defer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function defer(int $id, Request $request): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::STAMP_DUTY_MANAGE, $case);

        if (!$this->isCsrfTokenValid('stamp_duty_defer_' . $id, $request->getPayload()->getString('_token'))) {
            return $this->respond($request, $case, false, 'error', 'case_overview.stamp_duty.flash_error_csrf');
        }

        if ($case->getStampDutyStatus() === StampDutyStatus::ACHITATA) {
            return $this->respond($request, $case, false, 'warning', 'case_overview.stamp_duty.flash_error_already_paid');
        }

        if (!$request->getPayload()->getBoolean('deferralConsent')) {
            return $this->respond($request, $case, false, 'error', 'case_overview.stamp_duty.flash_error_consent_required');
        }

        $this->stampDutyService->deferToRegularization($case, $this->getUser());

        return $this->respond(
            $request,
            $case,
            true,
            'warning',
            'case_overview.stamp_duty.flash_success_deferred',
            'hs-modal-stamp-duty-defer',
        );
    }

    #[Route('/case/{id}/stamp-duty/court-notice', name: 'case_stamp_duty_court_notice', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function courtNotice(int $id, Request $request): Response
    {
        $case = $this->findOrThrow($id);
        $this->denyAccessUnlessGranted(CaseVoter::STAMP_DUTY_MANAGE, $case);

        if (!$this->isCsrfTokenValid('stamp_duty_court_notice_' . $id, $request->getPayload()->getString('_token'))) {
            return $this->respond($request, $case, false, 'error', 'case_overview.stamp_duty.flash_error_csrf');
        }

        // The 10-day stamping term only exists for a case that was filed unstamped.
        // Creating it elsewhere would put a CRITICAL deadline on a case that has no
        // annulment risk to watch.
        if ($case->getStampDutyStatus() !== StampDutyStatus::AMANATA_REGULARIZARE) {
            return $this->respond($request, $case, false, 'error', 'case_overview.stamp_duty.flash_error_not_deferred');
        }

        $raw = trim($request->getPayload()->getString('courtNoticeDate'));
        $noticeDate = $raw !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $raw) : false;

        if (!$noticeDate instanceof \DateTimeImmutable) {
            return $this->respond($request, $case, false, 'error', 'case_overview.stamp_duty.flash_error_notice_date_invalid');
        }

        if ($noticeDate > new \DateTimeImmutable('today')) {
            return $this->respond($request, $case, false, 'error', 'case_overview.stamp_duty.flash_error_notice_date_future');
        }

        $this->stampDutyService->recordCourtNotice($case, $noticeDate);

        return $this->respond(
            $request,
            $case,
            true,
            'success',
            'case_overview.stamp_duty.flash_success_court_notice',
            'hs-modal-stamp-duty-court-notice',
        );
    }

    /**
     * Turbo Stream (in-place, status regions refreshed) for Turbo clients, redirect
     * + flash otherwise. Mirrors {@see CasePaymentOrderController::respond()}.
     */
    private function respond(
        Request $request,
        LegalCase $case,
        bool $updateRegions,
        string $toastVariant,
        string $toastKey,
        ?string $closeModalId = null,
        ?string $extraToastKey = null,
    ): Response {
        if (str_contains((string) $request->headers->get('Accept', ''), 'text/vnd.turbo-stream.html')) {
            $context = $updateRegions ? $this->contextBuilder->build($case) : ['case' => $case];
            $context['update_regions'] = $updateRegions;
            $context['toast_variant'] = $toastVariant;
            $context['toast_key'] = $toastKey;
            $context['extra_toast_key'] = $extraToastKey;
            $context['close_modal_id'] = $closeModalId;
            $context['open_modal_id'] = null;

            return new Response(
                $this->renderView('case/overview/_stamp_duty_turbo_stream.html.twig', $context),
                Response::HTTP_OK,
                ['Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8'],
            );
        }

        $this->addFlash($toastVariant, $toastKey);
        if ($extraToastKey !== null) {
            $this->addFlash('warning', $extraToastKey);
        }

        return $this->redirectToRoute('case_overview', ['id' => $case->getId()]);
    }

    private function findOrThrow(int $id): LegalCase
    {
        $case = $this->legalCaseRepository->find($id);
        if (!$case instanceof LegalCase || $case->isDeleted()) {
            throw $this->createNotFoundException();
        }

        return $case;
    }
}
