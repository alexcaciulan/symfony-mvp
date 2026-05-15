<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\DeadlineType;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creează termene procedurale (LegalDeadline) cu date prorogate per CPC art. 181
 * alin. (2). Apelat fie direct de avocat (createHearingDeadline), fie de un
 * subscriber (Pas 4.2) la tranziții workflow. Audit log obligatoriu pe fiecare
 * operațiune.
 */
final class DeadlineService
{
    private const PAYMENT_NOTICE_DAYS = 15;          // CPC art. 1015 alin. 1
    private const APPEAL_DAYS = 10;                  // CPC art. 1024 alin. 1
    private const PRESCRIPTION_INTERVAL = '+3 years'; // NCC art. 2517

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkingDayResolver $workingDayResolver,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * Termen răspuns somație: paymentNoticeDate + 15 zile (CPC art. 1015 alin. 1),
     * prorogat la prima zi lucrătoare. Prioritate HIGH.
     */
    public function createPaymentNoticeDeadline(LegalCase $legalCase, \DateTimeImmutable $paymentNoticeDate): LegalDeadline
    {
        $rawDeadline = $paymentNoticeDate->modify('+' . self::PAYMENT_NOTICE_DAYS . ' days');
        $deadlineDate = $this->workingDayResolver->nextWorkingDay($rawDeadline);

        return $this->persistDeadline(
            $legalCase,
            DeadlineType::RASPUNS_SOMATIE,
            $deadlineDate,
            baseDate: $paymentNoticeDate,
            rawDeadline: $rawDeadline,
        );
    }

    /**
     * Termen cerere în anulare: rulingCommunicationDate + 10 zile (CPC art. 1024
     * alin. 1 — "de la data înmânării sau comunicării"), prorogat la prima zi
     * lucrătoare. Prioritate CRITICAL. NU folosi `rulingDate` aici — termenul
     * curge de la COMUNICARE, nu de la pronunțare.
     */
    public function createAppealDeadline(LegalCase $legalCase, \DateTimeImmutable $rulingCommunicationDate): LegalDeadline
    {
        $rawDeadline = $rulingCommunicationDate->modify('+' . self::APPEAL_DAYS . ' days');
        $deadlineDate = $this->workingDayResolver->nextWorkingDay($rawDeadline);

        return $this->persistDeadline(
            $legalCase,
            DeadlineType::CERERE_IN_ANULARE,
            $deadlineDate,
            baseDate: $rulingCommunicationDate,
            rawDeadline: $rawDeadline,
        );
    }

    /**
     * Termen prescripție: dueDate + 3 ani (NCC art. 2517), prorogat la prima zi
     * lucrătoare. Prioritate CRITICAL. Aruncă InvalidArgumentException dacă
     * dosarul nu are dueDate setat — termenul de prescripție nu poate fi
     * calculat fără data scadenței creanței.
     */
    public function createPrescriptionDeadline(LegalCase $legalCase): LegalDeadline
    {
        $dueDate = $legalCase->getDueDate();
        if ($dueDate === null) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot create prescription deadline: LegalCase %s has no dueDate set.',
                $legalCase->getCaseNumber(),
            ));
        }

        $baseDate = \DateTimeImmutable::createFromInterface($dueDate);
        $rawDeadline = $baseDate->modify(self::PRESCRIPTION_INTERVAL);
        $deadlineDate = $this->workingDayResolver->nextWorkingDay($rawDeadline);

        return $this->persistDeadline(
            $legalCase,
            DeadlineType::PRESCRIPTIE,
            $deadlineDate,
            baseDate: $baseDate,
            rawDeadline: $rawDeadline,
        );
    }

    /**
     * Termen judecată: data fixată de instanță, prorogată la prima zi lucrătoare
     * (relevant pentru ședințele care cad accidental într-o zi nelucrătoare, deși
     * instanța nu fixează asta normal). Prioritate MEDIUM.
     */
    public function createHearingDeadline(LegalCase $legalCase, \DateTimeImmutable $date, ?string $description = null): LegalDeadline
    {
        $deadlineDate = $this->workingDayResolver->nextWorkingDay($date);

        return $this->persistDeadline(
            $legalCase,
            DeadlineType::JUDECATA,
            $deadlineDate,
            baseDate: $date,
            rawDeadline: $date,
            description: $description,
        );
    }

    /**
     * Marchează un termen ca încheiat. Idempotent: apelurile repetate NU rescriu
     * `completedAt` / `completedBy` (sunt înghețate la prima marcare).
     */
    public function markCompleted(LegalDeadline $deadline, User $user): void
    {
        if ($deadline->isCompleted()) {
            return;
        }

        $deadline->markCompleted($user);
        $this->em->flush();

        $this->auditLogService->log(
            action: 'deadline_completed',
            entityType: LegalDeadline::class,
            entityId: (string) $deadline->getId(),
            newData: [
                'deadlineId' => $deadline->getId(),
                'type' => $deadline->getType()->value,
                'caseNumber' => $deadline->getLegalCase()->getCaseNumber(),
                'completedAt' => $deadline->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            ],
            category: AuditLogService::CATEGORY_DEADLINE_COMPLETED,
        );
        $this->em->flush();
    }

    private function persistDeadline(
        LegalCase $legalCase,
        DeadlineType $type,
        \DateTimeImmutable $deadlineDate,
        \DateTimeImmutable $baseDate,
        \DateTimeImmutable $rawDeadline,
        ?string $description = null,
    ): LegalDeadline {
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($legalCase);
        $deadline->setType($type);
        $deadline->setDeadlineDate($deadlineDate);
        $deadline->setPriority($type->defaultPriority());
        if ($description !== null) {
            $deadline->setDescription($description);
        }

        $this->em->persist($deadline);
        $this->em->flush();

        $this->auditLogService->log(
            action: 'deadline_created',
            entityType: LegalDeadline::class,
            entityId: (string) $deadline->getId(),
            newData: [
                'deadlineId' => $deadline->getId(),
                'type' => $type->value,
                'caseNumber' => $legalCase->getCaseNumber(),
                'baseDate' => $baseDate->format('Y-m-d'),
                'rawDeadline' => $rawDeadline->format('Y-m-d'),
                'deadlineDate' => $deadlineDate->format('Y-m-d'),
                'prorogated' => $rawDeadline->format('Y-m-d') !== $deadlineDate->format('Y-m-d'),
            ],
            category: AuditLogService::CATEGORY_DEADLINE_CREATED,
        );
        $this->em->flush();

        return $deadline;
    }
}
