<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Invoice;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\InvoiceType;
use App\Enum\PlanChangeOutcome;
use App\Repository\InvoiceRepository;
use App\Repository\PlanRepository;
use App\Service\Billing\InvoicingService;
use App\Service\Billing\PaymentGatewayInterface;
use App\Service\Billing\SubscriptionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/subscription')]
final class SubscriptionController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly InvoicingService $invoicingService,
        private readonly PlanRepository $planRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly PaymentGatewayInterface $paymentGateway,
        private readonly string $paymentGatewayDefault,
    ) {}

    #[Route('', name: 'app_subscription', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $current = $this->subscriptionService->getCurrentSubscription($user);

        $paidPlans = array_filter(
            $this->planRepository->findActive(),
            static fn ($plan) => !$plan->isTrial(),
        );

        // Upsell nudge only when a PAID plan's included cases are exhausted,
        // i.e. the next case would be billed as overage. Not for trials, not
        // when the plan still has free slots.
        $planExhausted = null !== $current
            && !$current->getPlan()->isTrial()
            && $current->getCasesConsumed() >= $current->getPlan()->getIncludedCases();

        // On a paid plan the grid stays on screen and each card says what it is
        // relative to the current plan, so the user always has a way out of the
        // plan they picked. Subscribing is only for the first paid plan.
        $isOnPaidPlan = null !== $current && !$current->getPlan()->isTrial();

        return $this->render('subscription/index.html.twig', [
            'subscription' => $current,
            'recommended_upgrade' => $planExhausted ? $this->subscriptionService->recommendUpgrade($current) : null,
            'plans' => $this->annotatePlans(array_values($paidPlans), $current),
            'can_subscribe' => !$isOnPaidPlan,
            'pending_plan_change' => $isOnPaidPlan
                ? ($this->invoiceRepository->findPendingByType($current, InvoiceType::PLAN_CHANGE)[0] ?? null)
                : null,
        ]);
    }

    /**
     * Labels each plan card against the current subscription so the template can
     * stay declarative.
     *
     * @param Plan[] $plans
     *
     * @return array<array{plan: Plan, is_current: bool, is_upgrade: bool, is_scheduled: bool}>
     */
    private function annotatePlans(array $plans, ?Subscription $current): array
    {
        $currentPlan = $current?->getPlan();
        $onPaidPlan = null !== $currentPlan && !$currentPlan->isTrial();

        return array_map(static function (Plan $plan) use ($current, $currentPlan, $onPaidPlan): array {
            $isCurrent = $onPaidPlan && $plan->getId() === $currentPlan->getId();

            return [
                'plan' => $plan,
                'is_current' => $isCurrent,
                // Same comparison the service bills on, so a card can never
                // promise an upgrade and produce a scheduled downgrade.
                'is_upgrade' => !$isCurrent && $onPaidPlan && $plan->comparePriceTo($currentPlan) > 0,
                'is_scheduled' => $plan->getId() === $current?->getPendingPlan()?->getId(),
            ];
        }, $plans);
    }

    #[Route('/invoices', name: 'app_subscription_invoices', methods: ['GET'])]
    public function invoices(): Response
    {
        // Invoices now live on the dedicated, filterable "Facturi" table page.
        return $this->redirectToRoute('app_invoices');
    }

    #[Route('/subscribe/{planId}', name: 'app_subscription_subscribe', requirements: ['planId' => '\d+'], methods: ['POST'])]
    public function subscribe(int $planId, Request $request, RateLimiterFactory $subscriptionCheckoutLimiter): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('subscribe_' . $planId, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'subscription.flash.csrf');

            return $this->redirectToRoute('app_subscription');
        }

        if (!$subscriptionCheckoutLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
            $this->addFlash('warning', 'rate_limit.subscription_checkout');

            return $this->redirectToRoute('app_subscription');
        }

        // Fiscal-data gate BEFORE checkout: a webhook-confirmed payment reaches
        // markPaid() without passing through pay(), so we must not let the user
        // leave for the gateway without the identity needed to issue their
        // invoice. Enforced here, not at pay() (which no longer runs on netopia).
        if (!$user->hasCompleteFiscalData()) {
            $this->addFlash('warning', 'subscription.flash.fiscal_data_required');

            return $this->redirectToRoute('app_profile_edit');
        }

        $plan = $this->planRepository->find($planId);
        if (null === $plan || !$plan->isActive() || $plan->isTrial()) {
            throw $this->createNotFoundException();
        }

        try {
            $subscription = $this->subscriptionService->subscribeToPlan($user, $plan);
        } catch (\DomainException) {
            $this->addFlash('warning', 'subscription.flash.already_subscribed');

            return $this->redirectToRoute('app_subscription');
        }

        // Scoped to the subscription just created, so this is the invoice that was
        // raised for it and not some older pending one of the same type.
        $invoice = $this->invoiceRepository->findPendingByType($subscription, InvoiceType::SUBSCRIPTION)[0] ?? null;

        if (null === $invoice) {
            return $this->redirectToRoute('app_invoices');
        }

        return $this->startCheckout($invoice);
    }

    /**
     * Moves an existing paid subscription to another paid plan. An upgrade goes to
     * checkout and only applies once paid; a downgrade is scheduled for the next
     * renewal and needs no payment.
     */
    #[Route('/change-plan/{planId}', name: 'app_subscription_change_plan', requirements: ['planId' => '\d+'], methods: ['POST'])]
    public function changePlan(int $planId, Request $request, RateLimiterFactory $subscriptionCheckoutLimiter): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('change_plan_' . $planId, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'subscription.flash.csrf');

            return $this->redirectToRoute('app_subscription');
        }

        if (!$subscriptionCheckoutLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
            $this->addFlash('warning', 'rate_limit.subscription_checkout');

            return $this->redirectToRoute('app_subscription');
        }

        $current = $this->subscriptionService->getCurrentSubscription($user);
        if (null === $current) {
            $this->addFlash('warning', 'subscription.flash.no_subscription');

            return $this->redirectToRoute('app_subscription');
        }

        $plan = $this->planRepository->find($planId);
        if (null === $plan || !$plan->isActive() || $plan->isTrial()) {
            throw $this->createNotFoundException();
        }

        // Same gate as subscribe(): an upgrade ends in a real charge whose invoice
        // needs the user's fiscal identity, and the webhook that settles it never
        // passes back through a controller.
        if (!$user->hasCompleteFiscalData()) {
            $this->addFlash('warning', 'subscription.flash.fiscal_data_required');

            return $this->redirectToRoute('app_profile_edit');
        }

        try {
            $result = $this->subscriptionService->changePlan($current, $plan);
        } catch (\DomainException) {
            $this->addFlash('warning', 'subscription.flash.plan_change_rejected');

            return $this->redirectToRoute('app_subscription');
        }

        if (PlanChangeOutcome::UPGRADE_PENDING_PAYMENT !== $result->outcome) {
            $this->addFlash('success', 'subscription.flash.plan_change_scheduled');

            return $this->redirectToRoute('app_subscription');
        }

        return $this->startCheckout($result->invoice);
    }

    #[Route('/cancel-plan-change', name: 'app_subscription_cancel_plan_change', methods: ['POST'])]
    public function cancelPlanChange(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('cancel_plan_change', $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'subscription.flash.csrf');

            return $this->redirectToRoute('app_subscription');
        }

        $current = $this->subscriptionService->getCurrentSubscription($user);
        if (null !== $current) {
            $this->subscriptionService->cancelScheduledPlanChange($current);
            $this->addFlash('success', 'subscription.flash.plan_change_canceled');
        }

        return $this->redirectToRoute('app_subscription');
    }

    #[Route('/cancel', name: 'app_subscription_cancel', methods: ['POST'])]
    public function cancel(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('cancel_subscription', $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'subscription.flash.csrf');

            return $this->redirectToRoute('app_subscription');
        }

        $current = $this->subscriptionService->getCurrentSubscription($user);
        if (null === $current || $current->getPlan()->isTrial()) {
            $this->addFlash('warning', 'subscription.flash.no_subscription');

            return $this->redirectToRoute('app_subscription');
        }

        $this->subscriptionService->cancelSubscription($current);
        $this->addFlash('success', 'subscription.flash.canceled');

        return $this->redirectToRoute('app_subscription');
    }

    /**
     * Hands a specific invoice to the gateway. Takes the invoice as an argument
     * rather than re-querying the user's pending ones: two invoices raised in the
     * same second order unpredictably, which would send the user to pay the wrong
     * amount.
     */
    private function startCheckout(Invoice $invoice): Response
    {
        $session = $this->paymentGateway->startCheckout($invoice);

        // Persist the gateway transaction ref up front so reconciliation can
        // re-query this invoice's status if its IPN never arrives.
        if (null !== $session->externalId) {
            $this->invoicingService->setExternalReference($invoice, $session->externalId);
        }

        return $this->redirect($session->url);
    }

    #[Route('/checkout/{invoiceId}', name: 'app_subscription_checkout', requirements: ['invoiceId' => '\d+'], methods: ['GET'])]
    public function checkout(int $invoiceId): Response
    {
        $invoice = $this->invoiceRepository->find($invoiceId);
        if (null === $invoice || $invoice->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('subscription/checkout.html.twig', [
            'invoice' => $invoice,
            // The manual "mark as paid" button exists only for the dev/test stub.
            // On a real gateway the user is redirected to the processor instead.
            'is_stub' => 'stub' === $this->paymentGatewayDefault,
        ]);
    }

    /**
     * Landing page after the browser returns from the gateway's hosted 3DS page.
     * Purely informational: the invoice may still be PENDING here because the
     * IPN (the source of truth) is processed asynchronously. Shows the current
     * state, never confirms anything.
     */
    #[Route('/return', name: 'app_subscription_return', methods: ['GET'])]
    public function return(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $latestInvoice = $this->invoicingService->getInvoicesByUser($user)[0] ?? null;

        return $this->render('subscription/return.html.twig', [
            'invoice' => $latestInvoice,
            'subscription' => $this->subscriptionService->getCurrentSubscription($user),
        ]);
    }

    #[Route('/checkout/{invoiceId}', name: 'app_subscription_checkout_pay', requirements: ['invoiceId' => '\d+'], methods: ['POST'])]
    public function pay(int $invoiceId, Request $request, RateLimiterFactory $subscriptionCheckoutLimiter): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Manual confirmation is a dev/test affordance of the stub gateway only.
        // On a real gateway, payments are confirmed via webhook; refuse here so a
        // hand-crafted POST can never mark an invoice paid without real money.
        if ('stub' !== $this->paymentGatewayDefault) {
            $this->addFlash('info', 'subscription.flash.payment_processing');

            return $this->redirectToRoute('app_subscription_return');
        }

        $invoice = $this->invoiceRepository->find($invoiceId);
        if (null === $invoice || $invoice->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('pay_invoice_' . $invoiceId, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'subscription.flash.csrf');

            return $this->redirectToRoute('app_subscription_checkout', ['invoiceId' => $invoiceId]);
        }

        if (!$subscriptionCheckoutLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
            $this->addFlash('warning', 'rate_limit.subscription_checkout');

            return $this->redirectToRoute('app_subscription_checkout', ['invoiceId' => $invoiceId]);
        }

        if (!$user->hasCompleteFiscalData()) {
            $this->addFlash('warning', 'subscription.flash.fiscal_data_required');

            return $this->redirectToRoute('app_profile_edit');
        }

        if (InvoiceStatus::PENDING === $invoice->getStatus()) {
            $this->invoicingService->markPaid($invoice);
            $this->addFlash('success', 'subscription.flash.paid');
        } else {
            $this->addFlash('info', 'subscription.flash.already_paid');
        }

        return $this->redirectToRoute('app_invoices');
    }
}
