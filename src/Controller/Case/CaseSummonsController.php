<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\SubscriptionSlotConsumptionOutcome;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\AuditLogService;
use App\Service\Billing\SubscriptionService;
use App\Service\Case\CaseWorkflowService;
use App\Service\Case\OverviewContextBuilder;
use App\Service\Document\PaymentNoticeGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class CaseSummonsController extends AbstractController
{
    private const C4_MODAL_ID = 'hs-modal-c4-summons';

    public function __construct(
        private readonly LegalCaseRepository $legalCaseRepository,
        private readonly PaymentNoticeGeneratorService $paymentNoticeGenerator,
        private readonly CaseWorkflowService $workflowService,
        private readonly AuditLogService $auditLogService,
        private readonly SubscriptionService $subscriptionService,
        private readonly OverviewContextBuilder $contextBuilder,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/case/{id}/summons/generate', name: 'case_summons_generate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function generate(int $id, Request $request, RateLimiterFactory $summonsGenerationLimiter): Response
    {
        $case = $this->legalCaseRepository->find($id);

        if (!$case || $case->isDeleted()) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(CaseVoter::TRANSITION, $case);

        if (!$this->isCsrfTokenValid('generate_summons_' . $id, $request->getPayload()->getString('_token'))) {
            return $this->respond($request, $case, false, 'error', 'case_overview.summons.flash_error_csrf', null, null);
        }

        if ($case->getStatus() !== CaseStatus::AMIABIL) {
            return $this->respond($request, $case, false, 'error', 'case_overview.summons.flash_error_wrong_status', null, null);
        }

        if ($case->getDebtors()->isEmpty()) {
            return $this->respond($request, $case, false, 'error', 'case_overview.summons.flash_error_no_debtor', null, null);
        }

        $user = $this->getUser();
        if ($user instanceof User) {
            $limiter = $summonsGenerationLimiter->create($user->getUserIdentifier());
            if (!$limiter->consume()->isAccepted()) {
                return $this->respond($request, $case, false, 'warning', 'rate_limit.summons_generation', null, null);
            }
        }

        // Paywall: activating a case (trimite_somatie) consumes a subscription
        // slot. Blocking outcomes (no/expired/exhausted-trial subscription) are
        // side-effect-free; route the lawyer to the subscription page instead.
        $consumption = $this->subscriptionService->consumeCaseSlot($case);
        if (!$consumption->allowsCaseActivation) {
            $this->addFlash('warning', 'subscription.paywall.blocked');

            return $this->redirectToRoute('app_subscription');
        }

        $this->em->wrapInTransaction(function () use ($case): void {
            $document = $this->paymentNoticeGenerator->generate($case);

            $case->setPaymentNoticeDate(new \DateTime());
            $this->workflowService->apply($case, 'trimite_somatie');

            $this->em->flush();

            $this->auditLogService->log(
                action: 'summons_generated',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: [
                    'documentId' => $document->getId(),
                    'caseNumber' => $case->getCaseNumber(),
                    'paymentNoticeDate' => $case->getPaymentNoticeDate()?->format('Y-m-d'),
                ],
                category: AuditLogService::CATEGORY_SUMMONS_GENERATED,
            );
        });

        $overageToast = SubscriptionSlotConsumptionOutcome::OVERAGE_INVOICE_CREATED === $consumption->outcome
            ? 'subscription.paywall.overage'
            : null;

        // Open the C4 communication memento modal right after the summons is generated.
        return $this->respond($request, $case, true, 'success', 'case_overview.summons.flash_success', self::C4_MODAL_ID, $overageToast);
    }

    /**
     * Turbo Stream (in-place, status regions refreshed) for Turbo clients, redirect
     * + flash otherwise. `$openModalId` surfaces the C4 memento on success.
     */
    private function respond(
        Request $request,
        LegalCase $case,
        bool $updateRegions,
        string $toastVariant,
        string $toastKey,
        ?string $openModalId,
        ?string $extraToastKey,
    ): Response {
        if (str_contains((string) $request->headers->get('Accept', ''), 'text/vnd.turbo-stream.html')) {
            $context = $updateRegions ? $this->contextBuilder->build($case) : ['case' => $case];
            $context['update_regions'] = $updateRegions;
            $context['toast_variant'] = $toastVariant;
            $context['toast_key'] = $toastKey;
            $context['open_modal_id'] = $openModalId;
            $context['close_modal_id'] = null;
            $context['extra_toast_key'] = $extraToastKey;

            return new Response(
                $this->renderView('case/overview/_documents_generate_turbo_stream.html.twig', $context),
                Response::HTTP_OK,
                ['Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8'],
            );
        }

        $this->addFlash($toastVariant, $toastKey);
        if ($openModalId === self::C4_MODAL_ID) {
            $this->addFlash('show_c4_modal', '1');
        }
        if ($extraToastKey !== null) {
            $this->addFlash('warning', $extraToastKey);
        }

        return $this->redirectToRoute('case_overview', ['id' => $case->getId()]);
    }
}
