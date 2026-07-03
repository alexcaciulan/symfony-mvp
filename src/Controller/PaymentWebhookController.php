<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\ProcessPaymentWebhookMessage;
use App\Service\Billing\Netopia\NetopiaException;
use App\Service\Billing\PaymentGatewayInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public server-to-server IPN endpoint for the payment gateway. Lives OUTSIDE
 * `^/subscription` (see security.yaml: this path is PUBLIC_ACCESS) because
 * Netopia calls it with no user session, and carries no CSRF token.
 *
 * Trust boundary: the gateway verifies the IPN signature synchronously here; a
 * bad signature is rejected (400) and nothing is dispatched. Only verified,
 * normalized data is handed to the async handler for settlement, keeping the
 * ack fast and letting Messenger retry transient failures.
 */
final class PaymentWebhookController extends AbstractController
{
    public function __construct(
        private readonly PaymentGatewayInterface $paymentGateway,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/webhook/netopia', name: 'app_webhook_netopia', methods: ['POST'])]
    public function netopia(Request $request, RateLimiterFactory $paymentWebhookLimiter): Response
    {
        if (!$paymentWebhookLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new JsonResponse(['errorCode' => 1, 'errorMessage' => 'rate limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $result = $this->paymentGateway->handleWebhook($request);
        } catch (NetopiaException $e) {
            // Invalid/unsigned IPN: reject and do not process. Never leak details.
            $this->logger->warning('payment.webhook.rejected', ['reason' => $e->getMessage()]);

            return new JsonResponse(['errorCode' => 1, 'errorMessage' => 'invalid notification'], Response::HTTP_BAD_REQUEST);
        }

        if (null === $result->invoiceId) {
            // Signed but unparseable order id: ack so Netopia stops resending, but
            // record it for reconciliation.
            $this->logger->warning('payment.webhook.no_invoice_id', ['externalRef' => $result->externalRef]);

            return $this->ack();
        }

        $this->bus->dispatch(new ProcessPaymentWebhookMessage(
            invoiceId: $result->invoiceId,
            paid: $result->paid,
            externalRef: $result->externalRef,
            status: $result->status,
            token: $result->token,
            tokenExpiresAt: $result->tokenExpiresAt,
            cardMask: $result->cardMask,
        ));

        return $this->ack();
    }

    /** @wire Netopia expects a success ack; confirm the exact envelope on sandbox. */
    private function ack(): JsonResponse
    {
        return new JsonResponse(['errorCode' => 0]);
    }
}
