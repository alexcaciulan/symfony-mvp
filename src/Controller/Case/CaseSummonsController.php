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
use App\Service\Document\PaymentNoticeGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class CaseSummonsController extends AbstractController
{
    public function __construct(
        private LegalCaseRepository $legalCaseRepository,
        private PaymentNoticeGeneratorService $paymentNoticeGenerator,
        private CaseWorkflowService $workflowService,
        private AuditLogService $auditLogService,
        private SubscriptionService $subscriptionService,
        private EntityManagerInterface $em,
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
            $this->addFlash('error', 'case_overview.summons.flash_error_csrf');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        if ($case->getStatus() !== CaseStatus::AMIABIL) {
            $this->addFlash('error', 'case_overview.summons.flash_error_wrong_status');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        if ($case->getDebtors()->isEmpty()) {
            $this->addFlash('error', 'case_overview.summons.flash_error_no_debtor');

            return $this->redirectToRoute('case_overview', ['id' => $id]);
        }

        $user = $this->getUser();
        if ($user instanceof User) {
            $limiter = $summonsGenerationLimiter->create($user->getUserIdentifier());
            if (!$limiter->consume()->isAccepted()) {
                $this->addFlash('warning', 'rate_limit.summons_generation');

                return $this->redirectToRoute('case_overview', ['id' => $id]);
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

        $this->addFlash('success', 'case_overview.summons.flash_success');
        $this->addFlash('show_c4_modal', '1');

        if (SubscriptionSlotConsumptionOutcome::OVERAGE_INVOICE_CREATED === $consumption->outcome) {
            $this->addFlash('warning', 'subscription.paywall.overage');
        }

        return $this->redirectToRoute('case_overview', ['id' => $id]);
    }
}
