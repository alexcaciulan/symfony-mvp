<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification;
use App\Enum\NotificationChannel;
use App\Service\Mercure\MercureTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fans a {@see NotificationDispatch} out to email, an in-app Notification, and a
 * Mercure toast. Each channel is fault-isolated (failures logged, not propagated)
 * and the service is headless-safe (no request/session/Security) for cron/worker.
 */
final class NotificationDispatcher implements NotificationDispatcherInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        #[Autowire('%app.mailer_from%')]
        private readonly string $mailerFrom,
        private readonly ?HubInterface $hub = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function dispatch(NotificationDispatch $request): void
    {
        $this->sendEmail($request);
        $this->persistInApp($request);
        $this->publishToast($request);
    }

    private function sendEmail(NotificationDispatch $request): void
    {
        $recipient = $request->user->getEmail();
        if ($request->emailSubject === null || $request->emailTemplate === null || $recipient === null) {
            return;
        }

        try {
            $email = (new TemplatedEmail())
                ->from(new Address($this->mailerFrom, $this->translator->trans('email.sender_name')))
                ->to($recipient)
                ->subject($request->emailSubject)
                ->htmlTemplate($request->emailTemplate)
                ->context($request->emailContext);
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->warning('notification.email.failed', [
                'type' => $request->type,
                'exceptionClass' => $e::class,
            ]);
        }
    }

    private function persistInApp(NotificationDispatch $request): void
    {
        if (!$request->persistInApp) {
            return;
        }

        try {
            $notification = (new Notification())
                ->setUser($request->user)
                ->setLegalCase($request->legalCase)
                ->setType($request->type)
                ->setChannel(NotificationChannel::IN_APP)
                ->setTitle($request->title)
                ->setMessage($request->message)
                ->setResourceLink($request->resourceLink);
            $this->em->persist($notification);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('notification.in_app.failed', [
                'type' => $request->type,
                'exceptionClass' => $e::class,
            ]);
        }
    }

    private function publishToast(NotificationDispatch $request): void
    {
        if ($this->hub === null) {
            return;
        }

        try {
            $this->hub->publish(new Update(
                topics: MercureTokenService::userNotificationTopic($request->user),
                data: json_encode(
                    ['type' => 'toast', 'variant' => $request->variant, 'message' => $request->title],
                    \JSON_THROW_ON_ERROR,
                ),
                private: true,
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('notification.mercure.failed', [
                'type' => $request->type,
                'exceptionClass' => $e::class,
            ]);
        }
    }
}
