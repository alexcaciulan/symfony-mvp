<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification;
use App\Enum\NotificationChannel;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fans a {@see NotificationDispatch} out to email and an in-app Notification row.
 * Each channel is fault-isolated (failures logged, not propagated) and the service
 * is headless-safe (no request/session/Security) for cron/worker.
 *
 * Real-time surfacing is handled client-side by polling the durable row (see the
 * notification center), so this service does not push to Mercure.
 */
final class NotificationDispatcher implements NotificationDispatcherInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        #[Autowire('%app.mailer_from%')]
        private readonly string $mailerFrom,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function dispatch(NotificationDispatch $request): void
    {
        $this->sendEmail($request);
        $this->persistInApp($request);
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
                'type' => $request->type->value,
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
                ->setType($request->type->value)
                ->setChannel(NotificationChannel::IN_APP)
                ->setTitle($request->title)
                ->setMessage($request->message)
                ->setResourceLink($request->resourceLink)
                ->setDedupKey($request->dedupKey);
            $this->em->persist($notification);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('notification.in_app.failed', [
                'type' => $request->type->value,
                'exceptionClass' => $e::class,
            ]);
        }
    }
}
