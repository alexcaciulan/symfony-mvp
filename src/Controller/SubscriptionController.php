<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\InvoiceType;
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

        return $this->render('subscription/index.html.twig', [
            'subscription' => $current,
            'recommended_upgrade' => $planExhausted ? $this->subscriptionService->recommendUpgrade($current) : null,
            'plans' => array_values($paidPlans),
            'can_subscribe' => null === $current || $current->getPlan()->isTrial(),
        ]);
    }

    #[Route('/invoices', name: 'app_subscription_invoices', methods: ['GET'])]
    public function invoices(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('subscription/invoices.html.twig', [
            'invoices' => $this->invoicingService->getInvoicesByUser($user),
        ]);
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

        $plan = $this->planRepository->find($planId);
        if (null === $plan || !$plan->isActive() || $plan->isTrial()) {
            throw $this->createNotFoundException();
        }

        try {
            $this->subscriptionService->subscribeToPlan($user, $plan);
        } catch (\DomainException) {
            $this->addFlash('warning', 'subscription.flash.already_subscribed');

            return $this->redirectToRoute('app_subscription');
        }

        $invoice = $this->invoicingService->getInvoicesByUser($user, [
            'status' => InvoiceStatus::PENDING,
            'type' => InvoiceType::SUBSCRIPTION,
        ])[0] ?? null;

        if (null === $invoice) {
            return $this->redirectToRoute('app_subscription_invoices');
        }

        return $this->redirect($this->paymentGateway->startCheckout($invoice)->url);
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
        ]);
    }

    #[Route('/checkout/{invoiceId}', name: 'app_subscription_checkout_pay', requirements: ['invoiceId' => '\d+'], methods: ['POST'])]
    public function pay(int $invoiceId, Request $request, RateLimiterFactory $subscriptionCheckoutLimiter): Response
    {
        /** @var User $user */
        $user = $this->getUser();

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

        if (InvoiceStatus::PENDING === $invoice->getStatus()) {
            $this->invoicingService->markPaid($invoice);
            $this->addFlash('success', 'subscription.flash.paid');
        } else {
            $this->addFlash('info', 'subscription.flash.already_paid');
        }

        return $this->redirectToRoute('app_subscription_invoices');
    }
}
