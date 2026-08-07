<?php

declare(strict_types=1);

namespace App\Service\StampDuty;

use App\Entity\LegalCase;
use App\Enum\NotificationType;
use App\Repository\LegalCaseRepository;
use App\Service\AuditLogService;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Chases the stamp duty on petitions already filed but not stamped.
 *
 * The law's own remedy runs from the court's notice to pay (OUG 80/2013 art. 33
 * alin. 2), a notice the platform never sees and that can be served on the claimant
 * rather than the lawyer. So the reminders start from the filing, which we do know
 * about, and stop the moment the duty is settled.
 *
 * They do not replace the 10-day term: insisting has no procedural effect, and what
 * counts before the court is still the day the notice was served. That term is
 * created separately, when the lawyer records the notice.
 */
final class StampDutyReminderService
{
    /**
     * Days after filing at which a reminder goes out. Spread rather than daily: the
     * duty is 200 lei on a file the lawyer has just worked on, so the risk is
     * forgetting over weeks, not over hours. Three messages, then silence, because a
     * fourth would only train them to ignore the third.
     */
    public const SCHEDULE_DAYS = [3, 10, 24];

    public function __construct(
        private readonly LegalCaseRepository $cases,
        private readonly NotificationDispatcherInterface $dispatcher,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AuditLogService $auditLogService,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @return int how many reminders were sent
     */
    public function run(\DateTimeImmutable $today): int
    {
        $sent = 0;

        foreach ($this->cases->findFiledWithOutstandingStampDuty() as $case) {
            $due = $this->dueReminderIndex($case, $today);
            if ($due === null) {
                continue;
            }

            $this->send($case, $due, $today);
            ++$sent;
        }

        return $sent;
    }

    /**
     * Which reminder in the schedule is due today, or null when none is. Compares
     * against the count already sent rather than against the calendar alone, so a
     * missed run catches up with one message instead of firing the whole backlog.
     */
    public function dueReminderIndex(LegalCase $case, \DateTimeImmutable $today): ?int
    {
        if ($case->getStampDutyRemindersMutedAt() !== null) {
            return null;
        }

        $filedAt = $case->courtArrivalDate();
        if ($filedAt === null) {
            return null;
        }

        $alreadySent = $case->getStampDutyRemindersSent();
        if ($alreadySent >= count(self::SCHEDULE_DAYS)) {
            return null;
        }

        // One message per day at most, whatever the schedule says.
        if ($case->getStampDutyLastReminderAt()?->format('Y-m-d') === $today->format('Y-m-d')) {
            return null;
        }

        $daysSinceFiling = (int) $filedAt->setTime(0, 0)->diff($today->setTime(0, 0))->format('%r%a');

        return $daysSinceFiling >= self::SCHEDULE_DAYS[$alreadySent] ? $alreadySent : null;
    }

    public function mute(LegalCase $case, \DateTimeImmutable $now): void
    {
        $this->em->wrapInTransaction(function () use ($case, $now): void {
            $case->setStampDutyRemindersMutedAt($now);
            $this->em->flush();

            $this->auditLogService->log(
                action: 'stamp_duty_reminders_muted',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: [
                    'caseNumber' => $case->getCaseNumber(),
                    'mutedAt' => $now->format('Y-m-d H:i:s'),
                ],
                category: AuditLogService::CATEGORY_STAMP_DUTY,
            );
            $this->em->flush();
        });
    }

    private function send(LegalCase $case, int $index, \DateTimeImmutable $today): void
    {
        $params = ['%case%' => $case->getCaseNumber() ?? ''];
        $caseUrl = $this->urlGenerator->generate(
            'case_overview',
            ['id' => $case->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $this->dispatcher->dispatch(new NotificationDispatch(
            user: $case->getUser(),
            legalCase: $case,
            type: NotificationType::STAMP_DUTY_UNPAID,
            title: $this->translator->trans('notification.stamp_duty_unpaid.title', $params),
            message: $this->translator->trans('notification.stamp_duty_unpaid.message', $params),
            resourceLink: '/case/' . $case->getId(),
            variant: 'warning',
            emailSubject: $this->translator->trans('email.stamp_duty_unpaid.subject', $params),
            emailTemplate: 'emails/stamp_duty_unpaid.html.twig',
            emailContext: [
                'case' => $case,
                'caseUrl' => $caseUrl,
                'heading' => $this->translator->trans('email.stamp_duty_unpaid.heading', $params),
                'body' => $this->translator->trans('email.stamp_duty_unpaid.body', $params),
                // The deep link depends on whether the court has given the file a
                // number: sending a lawyer to the new-case form on a case already on
                // the roll would have them register a duplicate.
                'payUrl' => $case->getCourtCaseNumber() !== null
                    ? 'https://registratura.rejust.ro/plata-taxei-judiciare-de-timbru-intr-un-dosar-existent'
                    : 'https://registratura.rejust.ro/inregistreaza-un-dosar-nou-pe-rolul-instantei-de-judecata',
                'courtCaseNumber' => $case->getCourtCaseNumber(),
            ],
        ));

        $case->setStampDutyRemindersSent($index + 1);
        $case->setStampDutyLastReminderAt($today);
        $this->em->flush();
    }
}
