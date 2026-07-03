<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use App\Service\Billing\Netopia\NetopiaException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
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
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly string $mailerFrom,
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
            ++$summary[$this->renewOne($subscription, $on)];
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
            $this->sendActionRequired($subscription, 'token_expired');

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
            $this->sendActionRequired($subscription, 'charge_failed');

            return 'past_due';
        }

        if (!$result->accepted) {
            $this->logger->warning('renewal.charge_declined', [
                'subscriptionId' => $subscription->getId(),
                'status' => $result->status,
            ]);
            $this->subscriptionService->markPastDue($subscription, 'charge_declined');
            $this->sendActionRequired($subscription, 'charge_failed');

            return 'past_due';
        }

        // Accepted. Store ntpID so the IPN can correlate and reconciliation can
        // settle it if the IPN is lost. The IPN performs the actual markPaid.
        if (null !== $result->ntpID) {
            $this->invoicing->setExternalReference($invoice, $result->ntpID);
        }

        return 'charged';
    }

    private function sendActionRequired(Subscription $subscription, string $reason): void
    {
        $user = $subscription->getUser();

        $email = (new TemplatedEmail())
            ->from($this->mailerFrom)
            ->to((string) $user->getEmail())
            ->subject($this->translator->trans('email.subscription_action_required.subject'))
            ->htmlTemplate('email/subscription_action_required.html.twig')
            ->context([
                'reason' => $reason,
                'subscriptionUrl' => rtrim($this->appBaseUrl, '/') . '/subscription',
                'userName' => $user->getFullName() ?? (string) $user->getEmail(),
            ]);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            // A dunning email failure must not abort the renewal run for the rest
            // of the subscriptions; the PAST_DUE flag already gates access.
            $this->logger->error('renewal.dunning_email_failed', [
                'subscriptionId' => $subscription->getId(),
                'exceptionClass' => $e::class,
            ]);
        }
    }
}
