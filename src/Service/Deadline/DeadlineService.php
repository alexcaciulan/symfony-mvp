<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use App\Repository\LegalDeadlineRepository;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creează termene procedurale (LegalDeadline) cu date prorogate per CPC art. 181
 * alin. (4). Apelat fie direct de avocat (createHearingDeadline), fie de un
 * subscriber (Pas 4.2) la tranziții workflow. Audit log obligatoriu pe fiecare
 * operațiune.
 */
final class DeadlineService
{
    private const PAYMENT_NOTICE_DAYS = 15;          // CPC art. 1015 alin. 1
    private const APPEAL_DAYS = 10;                  // CPC art. 1024 alin. 1
    private const STAMP_DUTY_DAYS = 10;              // OUG 80/2013 art. 33 alin. 2
    private const PRESCRIPTION_INTERVAL = '+3 years'; // NCC art. 2517
    private const EXECUTION_PRESCRIPTION_INTERVAL = '+3 years'; // CPC art. 706 alin. 1

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkingDayResolver $workingDayResolver,
        private readonly AuditLogService $auditLogService,
        private readonly LegalDeadlineRepository $deadlineRepository,
        private readonly TranslatorInterface $translator,
        private readonly int $voluntaryPaymentDays = 40,
    ) {}

    /**
     * Advisory date after which starting enforcement is recommended: the
     * voluntary-payment window (default 40 days, configurable) counted from the
     * date the ruling was communicated to the lawyer, prorogated to the next
     * working day. Returns null unless the case is final (DEFINITIVA) and that
     * communication date is known. This is NOT a procedural term and never blocks
     * `trece_la_executare`: a final order is enforceable immediately (CPC art.
     * 1021); the window is a practical courtesy before enforcing.
     */
    public function recommendedExecutionDate(LegalCase $legalCase): ?\DateTimeImmutable
    {
        if ($legalCase->getStatus() !== CaseStatus::DEFINITIVA) {
            return null;
        }

        $communicationDate = $legalCase->getRulingCommunicationDate();
        if ($communicationDate === null) {
            return null;
        }

        $rawDate = $communicationDate->modify('+' . $this->voluntaryPaymentDays . ' days');

        return $this->workingDayResolver->nextWorkingDay($rawDate);
    }

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
     * Whether the 15-day summons payment term has expired (CPC art. 1015 alin. 1).
     * Returns false when the real communication date is unknown: the term cannot
     * be proven expired, so OP generation relies on the lawyer's explicit consent
     * instead (it is never computed from the PDF generation date, which would be
     * premature and inadmissible per CPC art. 1016).
     */
    public function isPaymentTermExpired(LegalCase $legalCase, \DateTimeImmutable $today): bool
    {
        $communicationDate = $legalCase->getPaymentNoticeCommunicationDate();
        if ($communicationDate === null) {
            return false;
        }

        $termEnd = $this->workingDayResolver->nextWorkingDay($communicationDate->modify('+' . self::PAYMENT_NOTICE_DAYS . ' days'));

        // Strictly after: the term lapses at the end of day D15, so the OP is
        // admissible only from D16 (CPC art. 1015-1016).
        return $today > $termEnd;
    }

    /**
     * Recomputes the RASPUNS_SOMATIE deadline from the real date the debtor received
     * the summons (CPC art. 1015 alin. 1: 15 days run from receipt, not generation),
     * clearing the "estimated" disclaimer; creates it if missing. Next-working-day
     * prorogation is a product choice (substantive term, not CPC art. 181 procedural).
     */
    public function recalculatePaymentNoticeDeadline(LegalCase $legalCase, \DateTimeImmutable $communicationDate): LegalDeadline
    {
        $deadline = $this->deadlineRepository->findOneByCaseAndType($legalCase, DeadlineType::RASPUNS_SOMATIE);
        if ($deadline === null) {
            return $this->createPaymentNoticeDeadline($legalCase, $communicationDate);
        }

        $rawDeadline = $communicationDate->modify('+' . self::PAYMENT_NOTICE_DAYS . ' days');
        $deadlineDate = $this->workingDayResolver->nextWorkingDay($rawDeadline);

        $deadline->setDeadlineDate($deadlineDate);
        $deadline->setDescription(null); // estimate confirmed against the real communication date
        $this->em->flush();

        $this->auditLogService->log(
            action: 'payment_notice_deadline_recomputed',
            entityType: LegalDeadline::class,
            entityId: (string) $deadline->getId(),
            newData: [
                'deadlineId' => $deadline->getId(),
                'type' => DeadlineType::RASPUNS_SOMATIE->value,
                'caseNumber' => $legalCase->getCaseNumber(),
                'baseDate' => $communicationDate->format('Y-m-d'),
                'rawDeadline' => $rawDeadline->format('Y-m-d'),
                'deadlineDate' => $deadlineDate->format('Y-m-d'),
                'prorogated' => $rawDeadline->format('Y-m-d') !== $deadlineDate->format('Y-m-d'),
            ],
            category: AuditLogService::CATEGORY_DEADLINE_EDITED,
        );
        $this->em->flush();

        return $deadline;
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
     * Termen de timbrare: data comunicării înștiințării instanței + 10 zile (OUG
     * 80/2013 art. 33 alin. 2, care trimite la CPC art. 200 alin. 2 teza I),
     * prorogat la prima zi lucrătoare. Prioritate CRITICAL: ratarea lui atrage
     * ANULAREA cererii (CPC art. 197).
     *
     * Termenul curge de la comunicarea instanței, dată pe care platforma nu o
     * cunoaște, deci e furnizată de avocat când primește înștiințarea. Recalculează
     * termenul existent dacă avocatul corectează data.
     */
    public function createStampDutyDeadline(LegalCase $legalCase, \DateTimeImmutable $courtNoticeDate): LegalDeadline
    {
        $rawDeadline = $courtNoticeDate->modify('+' . self::STAMP_DUTY_DAYS . ' days');
        $deadlineDate = $this->workingDayResolver->nextWorkingDay($rawDeadline);

        $existing = $this->deadlineRepository->findOneByCaseAndType($legalCase, DeadlineType::TIMBRARE);
        if ($existing !== null) {
            $existing->setDeadlineDate($deadlineDate);
            $existing->resetAlertFlags();
            $this->em->flush();

            $this->auditLogService->log(
                action: 'stamp_duty_deadline_recomputed',
                entityType: LegalDeadline::class,
                entityId: (string) $existing->getId(),
                newData: [
                    'deadlineId' => $existing->getId(),
                    'type' => DeadlineType::TIMBRARE->value,
                    'caseNumber' => $legalCase->getCaseNumber(),
                    'baseDate' => $courtNoticeDate->format('Y-m-d'),
                    'rawDeadline' => $rawDeadline->format('Y-m-d'),
                    'deadlineDate' => $deadlineDate->format('Y-m-d'),
                    'prorogated' => $rawDeadline->format('Y-m-d') !== $deadlineDate->format('Y-m-d'),
                ],
                category: AuditLogService::CATEGORY_DEADLINE_EDITED,
            );
            $this->em->flush();

            return $existing;
        }

        return $this->persistDeadline(
            $legalCase,
            DeadlineType::TIMBRARE,
            $deadlineDate,
            baseDate: $courtNoticeDate,
            rawDeadline: $rawDeadline,
        );
    }

    /**
     * Creates one PRESCRIPTIE deadline per DISTINCT due date among the case
     * positions: each invoice prescribes three years from its OWN due date (NCC
     * art. 2517), so a single deadline pinned to the earliest due date would
     * leave the later invoices unmonitored once the oldest one is resolved.
     *
     * No working-day prorogation: prescription is a substantive-law term (NCC art.
     * 2539-2541), not a procedural one (CPC art. 181 para. 2 covers only procedural
     * terms). The exact date is kept; prorogating it would show a date later than
     * the real one and could make the lawyer file after the term has actually run.
     *
     * Idempotent per due date via {@see LegalDeadlineRepository::findOneByCaseTypeAndDate()},
     * so a re-run only fills the uncovered due dates. When the case carries no
     * positions (cases created before the multi-position model), it falls back to
     * the denormalized case due date. Returns an empty array when no due date is
     * known anywhere (neither on positions nor on the scalar).
     *
     * @return list<LegalDeadline> deadlines created by this call (existing ones skipped)
     */
    public function createPrescriptionDeadlines(LegalCase $legalCase): array
    {
        $created = [];
        foreach ($this->prescriptionDueDates($legalCase) as $dueDate) {
            $deadlineDate = $dueDate->modify(self::PRESCRIPTION_INTERVAL);
            if ($this->deadlineRepository->findOneByCaseTypeAndDate($legalCase, DeadlineType::PRESCRIPTIE, $deadlineDate) !== null) {
                continue;
            }

            // Rendered to text so the Tab Termene card distinguishes the several
            // prescription terms by the due date each one covers.
            $description = $this->translator->trans(
                'case_overview.deadlines.prescription_covers',
                ['%date%' => $dueDate->format('d.m.Y')],
            );

            $created[] = $this->persistDeadline(
                $legalCase,
                DeadlineType::PRESCRIPTIE,
                $deadlineDate,
                baseDate: $dueDate,
                rawDeadline: $deadlineDate,
                description: $description,
            );
        }

        return $created;
    }

    /**
     * Distinct due dates a prescription term must cover, keyed by Y-m-d to dedupe
     * positions falling due the same day. Draws from the claim positions, skipping
     * the ones the lawyer excluded and credit notes (which reduce, not form, the
     * claim). Falls back to the denormalized case due date when the case has no
     * positions.
     *
     * @return list<\DateTimeImmutable> ascending
     */
    private function prescriptionDueDates(LegalCase $legalCase): array
    {
        $dates = [];
        foreach ($legalCase->getClaimItems() as $item) {
            if ($item->isExcludedByLawyer() || $item->isCreditNote()) {
                continue;
            }

            $dueDate = $item->getDueDate();
            if ($dueDate === null) {
                continue;
            }

            $dates[$dueDate->format('Y-m-d')] = $dueDate;
        }

        if ($dates === []) {
            $caseDueDate = $legalCase->getDueDate();
            if ($caseDueDate !== null) {
                $immutable = \DateTimeImmutable::createFromInterface($caseDueDate);
                $dates[$immutable->format('Y-m-d')] = $immutable;
            }
        }

        ksort($dates);

        return array_values($dates);
    }

    /**
     * Enforcement prescription deadline: definitiveDate + 3 years (CPC art. 706 para.
     * 1, runs from when the order became final). Priority CRITICAL. No prorogation:
     * a years-based limitation is outside CPC art. 181 para. 4 (day-based terms).
     */
    public function createExecutionPrescriptionDeadline(LegalCase $legalCase, \DateTimeImmutable $definitiveDate): LegalDeadline
    {
        $deadlineDate = $definitiveDate->modify(self::EXECUTION_PRESCRIPTION_INTERVAL);

        return $this->persistDeadline(
            $legalCase,
            DeadlineType::PRESCRIPTIE_EXECUTARE,
            $deadlineDate,
            baseDate: $definitiveDate,
            rawDeadline: $deadlineDate,
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
     * Manual deadline (type OTHER). No working-day prorogation: the lawyer's exact
     * date is kept (a manual reminder is not necessarily a procedural term).
     */
    public function createCustomDeadline(LegalCase $legalCase, \DateTimeImmutable $date, ?string $description = null): LegalDeadline
    {
        return $this->persistDeadline(
            $legalCase,
            DeadlineType::OTHER,
            $date,
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
