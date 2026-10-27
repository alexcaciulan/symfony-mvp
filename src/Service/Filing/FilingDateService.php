<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\FilingChannel;
use App\Repository\FilingSubmissionRepository;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The filing date of a petition the platform transmitted by email. The SMTP
 * acceptance is not that date: the lawyer declares it from the court's answer or
 * the registration on the portal, and the platform only checks it is plausible.
 * No deadline is derived from the filing date yet, so nothing is recomputed here.
 */
final class FilingDateService
{
    private const TIMEZONE = 'Europe/Bucharest';

    private const STATUSES = [
        CaseStatus::CERERE_DEPUSA,
        CaseStatus::DOSAR_INREGISTRAT,
        CaseStatus::TERMEN_FIXAT,
        CaseStatus::ORDONANTA_EMISA,
        CaseStatus::IN_ANULARE,
        CaseStatus::DEFINITIVA,
        CaseStatus::EXECUTARE,
        CaseStatus::RESPINSA,
    ];

    public function __construct(
        private readonly FilingSubmissionRepository $submissions,
        private readonly AuditLogService $auditLogService,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {}

    /** Whether the action is offered at all on this case. */
    public function canSet(LegalCase $case): bool
    {
        return !$case->isDeleted()
            && in_array($case->getStatus(), self::STATUSES, true)
            && $case->getFilingChannel() === FilingChannel::EMAIL
            && $this->submissions->findLatestForCase($case)?->getSentAt() !== null;
    }

    /** @throws FilingDateRefusedException */
    public function setFromLawyer(LegalCase $case, \DateTimeImmutable $date, User $actor, string $source = 'lawyer'): void
    {
        if (!$this->canSet($case)) {
            throw new FilingDateRefusedException('filing.status.set_date.error_not_allowed');
        }

        $tz = new \DateTimeZone(self::TIMEZONE);
        $day = $date->setTimezone($tz)->setTime(0, 0);
        $today = $this->clock->now()->setTimezone($tz)->setTime(0, 0);
        if ($day > $today) {
            throw new FilingDateRefusedException('filing.status.set_date.error_future');
        }

        $sentAt = $this->submissions->findLatestForCase($case)?->getSentAt();
        if ($sentAt !== null && $day < $sentAt->setTimezone($tz)->setTime(0, 0)) {
            throw new FilingDateRefusedException('filing.status.set_date.error_before_sent');
        }
        $generatedAt = $case->requestGeneratedAt();
        if ($generatedAt !== null && $day < $generatedAt->setTimezone($tz)->setTime(0, 0)) {
            throw new FilingDateRefusedException('filing.status.set_date.error_before_generated');
        }

        $old = $case->getFiledAt();
        $case->setFiledAt($day);
        $this->auditLogService->log(
            action: 'filing_date_declared',
            entityType: LegalCase::class,
            entityId: (string) $case->getId(),
            oldData: ['filedAt' => $old?->format('Y-m-d')],
            newData: ['filedAt' => $day->format('Y-m-d'), 'source' => $source, 'filingChannel' => FilingChannel::EMAIL->value],
            category: AuditLogService::CATEGORY_FILING_SUBMISSION,
            actor: $actor,
        );
        $this->em->flush();
    }
}
