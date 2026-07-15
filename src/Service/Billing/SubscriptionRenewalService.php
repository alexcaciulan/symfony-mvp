<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Subscription;
use App\Enum\NotificationType;
use App\Repository\SubscriptionRepository;
use App\Service\Billing\Netopia\NetopiaException;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Drives off-session monthly renewals: for each due subscription, roll the
 * period + issue the invoice, then charge the saved token. On failure (expired
 * token, declined card, gateway error) the subscription is flipped to PAST_DUE
 * and the client is emailed to re-authorize. Invoked by the renewal cron
 * ({@see App\Command\RenewSubscriptionsCommand}).
 *
 * The definitive settlement of a successful charge still arrives via IPN
 * (idempotent {@see App\MessageHandler\ProcessPaymentWebhookMessageHandler});
 * the ntpID is stored so the reconcile command can also settle it if that IPN
 * is lost.
 */
final class SubscriptionRenewalService
{
    /** @var array{processed: int, charged: int, past_due: int, reauth: int} zero summary */
    private const ZERO_SUMMARY = ['processed' => 0, 'charged' => 0, 'past_due' => 0, 'reauth' => 0];

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionService $subscriptionService,
        private readonly InvoicingService $invoicing,
        private readonly NetopiaPaymentGateway $gateway,
        private readonly NotificationDispatcherInterface $dispatcher,
        private readonly TranslatorInterface $translator,
        private readonly string $appBaseUrl,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @return array{processed: int, charged: int, past_due: int, reauth: int}
     */
    public function renewDue(\DateTimeImmutable $on): array
    {
        $summary = self::ZERO_SUMMARY;

        foreach ($this->subscriptions->findDueForRenewal($on) as $subscription) {
            ++$summary['processed'];
            try {
                ++$summary[$this->renewOne($subscription, $on)];
            } catch (\Throwable $e) {
                // A persistence failure for one subscription can close the shared
                // EntityManager (Doctrine closes it on any commit error), which would
                // make every later subscription in this loop throw. Stop cleanly and
                // let the next cron run resume the untouched subscriptions instead of
                // crashing the whole batch.
                $this->logger->error('renewal.batch_aborted', [
                    'subscriptionId' => $subscription->getId(),
                    'exceptionClass' => $e::class,
                ]);

                break;
            }
        }

        return $summary;
    }

    /**
     * @return 'charged'|'past_due'|'reauth'
     */
    private function renewOne(Subscription $subscription, \DateTimeImmutable $on): string
    {
        // Expired/missing token: cannot charge off-session. Flag for action and
        // ask the client to re-enter their card. Do NOT roll the period.
        if (!$subscription->hasChargeableToken($on)) {
            $this->subscriptionService->markPastDue($subscription, 'token_expired');
            $this->sendActionRequired($subscription, 'token_expired', $on);

            return 'reauth';
        }

        $invoice = $this->subscriptionService->renewSubscription($subscription);

        try {
            $result = $this->gateway->chargeRenewal($subscription, $invoice);
        } catch (NetopiaException $e) {
            $this->logger->error('renewal.charge_error', [
                'subscriptionId' => $subscription->getId(),
                'reason' => $e->getMessage(),
            ]);
            $this->subscriptionService->markPastDue($subscription, 'charge_error');
            $this->sendActionRequired($subscription, 'charge_failed', $on);

            return 'past_due';
        }

        if (!$result->accepted) {
            $this->logger->warning('renewal.charge_declined', [
                'subscriptionId' => $subscription->getId(),
                'status' => $result->status,
            ]);
            $this->subscriptionService->markPastDue($subscription, 'charge_declined');
            $this->sendActionRequired($subscription, 'charge_failed', $on);

            return 'past_due';
        }

        // Accepted. Store ntpID so the IPN can correlate and reconciliation can
        // settle it if the IPN is lost. The IPN performs the actual markPaid.
        if (null !== $result->ntpID) {
            $this->invoicing->setExternalReference($invoice, $result->ntpID);
        }

        return 'charged';
    }

    /**
     * Dunning: warn the client that action is needed (re-authorize card / update
     * payment) via the notification dispatcher, which fans out to email + an in-app
     * row and isolates any channel failure so the renewal loop is never aborted.
     *
     * @param 'token_expired'|'charge_failed' $reason
     */
    private function sendActionRequired(Subscription $subscription, string $reason, \DateTimeImmutable $on): void
    {
        $user = $subscription->getUser();

        // 'charge_failed' covers both a declined card and a gateway error; both need
        // the same "update your payment" action, so they share one notification type.
        $type = $reason === 'token_expired' ? NotificationType::TOKEN_EXPIRED : NotificationType::PAYMENT_FAILED;
        $copyKey = $type->value;

        $this->dispatcher->dispatch(new NotificationDispatch(
            user: $user,
            legalCase: null,
            type: $type,
            title: $this->translator->trans("notification.$copyKey.title"),
            message: $this->translator->trans("notification.$copyKey.message"),
            resourceLink: '/subscription',
            variant: 'error',
            emailSubject: $this->translator->trans('email.subscription_action_required.subject'),
            emailTemplate: 'emails/subscription_action_required.html.twig',
            emailContext: [
                'reason' => $reason,
                'subscriptionUrl' => rtrim($this->appBaseUrl, '/') . '/subscription',
                'userName' => $user->getFullName() ?? (string) $user->getEmail(),
            ],
            // One dunning notice per subscription, reason, and cron run day; a cron
            // re-attempt on the same day is a no-op (dispatcher dedup guard).
            dedupKey: sprintf('dunning:%d:%s:%s', $subscription->getId(), $reason, $on->format('Y-m-d')),
        ));
    }
}
