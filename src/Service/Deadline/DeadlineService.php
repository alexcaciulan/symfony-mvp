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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creează termene procedurale (LegalDeadline). Termenele pe zile se calculează pe
 * zile libere (CPC art. 181 alin. 1 pct. 2) și se prorogă la prima zi lucrătoare
 * (CPC art. 181 alin. 2), ambele reguli aplicate într-un singur loc:
 * {@see self::proceduralTermEnd()}. Apelat fie direct de avocat
 * (createHearingDeadline), fie de subscriber la tranziții workflow. Audit log
 * obligatoriu pe fiecare operațiune.
 */
final class DeadlineService
{
    private const PAYMENT_NOTICE_DAYS = 15;          // CPC art. 1015 alin. 1
    private const APPEAL_DAYS = 10;                  // CPC art. 1024 alin. 1
    private const STAMP_DUTY_DAYS = 10;              // OUG 80/2013 art. 33 alin. 2
    private const FILING_INTERRUPTION_MONTHS = 6;    // NCC art. 2540, CPC art. 1015 alin. 2
    private const PRESCRIPTION_INTERVAL = '+3 years'; // NCC art. 2517
    private const EXECUTION_PRESCRIPTION_INTERVAL = '+3 years'; // CPC art. 705 alin. 1

    /**
     * Statuses in which the payment-order request has not reached the court yet.
     * Mirrors the `depune_cerere` transition of config/packages/workflow.yaml
     * (SOMATIE_TRIMISA -> CERERE_DEPUSA): every place from CERERE_DEPUSA onwards
     * means the request is filed, which is what stops the six-month term below.
     */
    private const STATUSES_BEFORE_FILING = [CaseStatus::AMIABIL, CaseStatus::SOMATIE_TRIMISA];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkingDayResolver $workingDayResolver,
        private readonly AuditLogService $auditLogService,
        private readonly LegalDeadlineRepository $deadlineRepository,
        private readonly TranslatorInterface $translator,
        private readonly int $voluntaryPaymentDays = 40,
        private readonly LoggerInterface $logger = new NullLogger(),
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
     * Maturity date of a term expressed in days, from the date it starts running.
     *
     * CPC art. 181 para. 1 pt. 2 ("termen pe zile libere"): neither the day the
     * term starts running nor the day it ends is counted, so a legal term of N
     * days covers N + 1 calendar days from the triggering date. A 5-day term
     * therefore spans 7 calendar days in the classic textbook example (start day +
     * 5 free days + end day). CPC art. 181 para. 2 then moves a maturity date that
     * falls on a non-working day to the first working day that follows, applied on
     * top of the N + 1 result.
     *
     * Worked example: a 15-day term running from Monday 1 June 2026 matures on
     * Wednesday 17 June 2026 (1 June + 16), not on 16 June.
     *
     * The N + 1 rule lives here and nowhere else: the PAYMENT_NOTICE_DAYS /
     * APPEAL_DAYS / STAMP_DUTY_DAYS constants stay at their legal values (15, 10,
     * 10) so they can be read against the article they cite.
     */
    private function proceduralTermEnd(\DateTimeImmutable $startsRunningOn, int $legalDays): ProceduralTerm
    {
        $rawEnd = $startsRunningOn->modify('+' . ($legalDays + 1) . ' days');

        return new ProceduralTerm($rawEnd, $this->workingDayResolver->nextWorkingDay($rawEnd));
    }

    /**
     * Maturity date of a term expressed in MONTHS, for substantive-law terms.
     *
     * NCC art. 2552 para. 1: a term set in months ends on the corresponding day of
     * the last month, so 20 February plus six months ends on 20 August. Para. 3
     * covers the months that have no such day: the term then ends on the last day
     * of that month, which is what the second branch restores, because PHP would
     * otherwise overflow 31 August plus six months into 3 March.
     *
     * Raw arithmetic only. The prorogation of NCC art. 2554 is applied by the caller,
     * so the untouched date stays available for the audit payload.
     */
    private function monthsTermEnd(\DateTimeImmutable $startsRunningOn, int $months): \DateTimeImmutable
    {
        $end = $startsRunningOn->modify('+' . $months . ' months');

        if ($end->format('d') !== $startsRunningOn->format('d')) {
            return $startsRunningOn->modify('last day of +' . $months . ' months');
        }

        return $end;
    }

    /**
     * Prorogation of a SUBSTANTIVE term to the first working day that follows (NCC
     * art. 2554), applied to the limitation periods and to the six months that keep
     * the interruption alive.
     *
     * The rule is the substantive-law counterpart of CPC art. 181 para. 2 and it is
     * what makes the prorogated day the real day the term is fulfilled, not a day
     * later than the real one: art. 2554 says the term itself ends at the close of
     * that first working day. Showing the raw date would therefore show a date the
     * law does not treat as the maturity date.
     *
     * The raw date is returned alongside, so the audit payload keeps the plain
     * calculation next to the date the lawyer is shown.
     */
    private function substantiveTermEnd(\DateTimeImmutable $rawEnd): ProceduralTerm
    {
        return new ProceduralTerm($rawEnd, $this->workingDayResolver->nextWorkingDay($rawEnd));
    }

    /**
     * Maturity date of the annulment-request term (CPC art. 1024 alin. 1: 10 days
     * from service of the payment order), exposed so callers that derive a later
     * date from it (the day the order becomes final) share this calculation instead
     * of repeating it.
     */
    public function appealTermEnd(\DateTimeImmutable $rulingCommunicationDate): \DateTimeImmutable
    {
        return $this->proceduralTermEnd($rulingCommunicationDate, self::APPEAL_DAYS)->end;
    }

    /**
     * Statutory length in days of a term counted in days, or null for the types that
     * are not: the limitation periods are counted in years, the interruption window in
     * months, and hearing or manual dates are not terms at all.
     *
     * Exposed so a caller that has to reproduce a term outside this service reads the
     * legal number from the one place that holds it, instead of repeating 15 or 10.
     */
    public function legalDaysFor(DeadlineType $type): ?int
    {
        return match ($type) {
            DeadlineType::RASPUNS_SOMATIE => self::PAYMENT_NOTICE_DAYS,
            DeadlineType::CERERE_IN_ANULARE => self::APPEAL_DAYS,
            DeadlineType::TIMBRARE => self::STAMP_DUTY_DAYS,
            default => null,
        };
    }

    /**
     * Maturity of a day-based term of $type running from $startsRunningOn, or null when
     * the type is not counted in days. Single entry point for callers that need the
     * current rule (free days plus prorogation) without repeating it.
     */
    public function dayBasedTermEnd(DeadlineType $type, \DateTimeImmutable $startsRunningOn): ?ProceduralTerm
    {
        $days = $this->legalDaysFor($type);

        return $days === null ? null : $this->proceduralTermEnd($startsRunningOn, $days);
    }

    /**
     * Maturity of the six-month term that keeps the interruption alive, from the date
     * the summons was communicated. Exposed for the same reason as
     * {@see self::dayBasedTermEnd()}: the months arithmetic of NCC art. 2552 and the
     * prorogation of art. 2554 stay in one place.
     */
    public function filingInterruptionTermEnd(\DateTimeImmutable $communicationDate): ProceduralTerm
    {
        return $this->substantiveTermEnd($this->monthsTermEnd($communicationDate, self::FILING_INTERRUPTION_MONTHS));
    }

    /**
     * Whether the payment-order request has not reached the court yet, which is the
     * event that ends the six-month term above and turns the interruption produced by
     * the summons into an unconditional one. Exposed so a caller that has to state
     * which of the two situations a case is in reads it from the list that governs the
     * term itself.
     */
    public function isBeforeFiling(LegalCase $legalCase): bool
    {
        return in_array($legalCase->getStatus(), self::STATUSES_BEFORE_FILING, true);
    }

    /**
     * Maturity of a limitation period running from $startsRunningOn: the claim
     * prescribes three years from each due date (NCC art. 2517), the right to enforce
     * three years from the order becoming final (CPC art. 705 para. 1). Both are
     * prorogated per NCC art. 2554. Null for any other type, which has no limitation
     * period of its own.
     */
    public function limitationTermEnd(DeadlineType $type, \DateTimeImmutable $startsRunningOn): ?ProceduralTerm
    {
        $interval = match ($type) {
            DeadlineType::PRESCRIPTIE => self::PRESCRIPTION_INTERVAL,
            DeadlineType::PRESCRIPTIE_EXECUTARE => self::EXECUTION_PRESCRIPTION_INTERVAL,
            default => null,
        };

        return $interval === null ? null : $this->substantiveTermEnd($startsRunningOn->modify($interval));
    }

    /**
     * Termen răspuns somație: paymentNoticeDate + 15 zile libere (CPC art. 1015
     * alin. 1 coroborat cu art. 181 alin. 1 pct. 2), prorogat la prima zi
     * lucrătoare. Prioritate HIGH.
     */
    public function createPaymentNoticeDeadline(LegalCase $legalCase, \DateTimeImmutable $paymentNoticeDate): LegalDeadline
    {
        $term = $this->proceduralTermEnd($paymentNoticeDate, self::PAYMENT_NOTICE_DAYS);

        return $this->persistDeadline(
            $legalCase,
            DeadlineType::RASPUNS_SOMATIE,
            $term->end,
            baseDate: $paymentNoticeDate,
            rawDeadline: $term->rawEnd,
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

        $termEnd = $this->proceduralTermEnd($communicationDate, self::PAYMENT_NOTICE_DAYS)->end;

        // Strictly after: on free-days counting the term still runs throughout its
        // maturity day (service date + 16 calendar days, prorogated), so the debtor
        // may still pay that day and the OP is admissible only from the day after
        // it. Filing on the maturity day itself is premature (CPC art. 1015-1016).
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

        $term = $this->proceduralTermEnd($communicationDate, self::PAYMENT_NOTICE_DAYS);
        $rawDeadline = $term->rawEnd;
        $deadlineDate = $term->end;

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
     * The six-month term the interruption of the limitation period depends on: the
     * communicated summons interrupts the prescription, but the interruption is
     * deemed never to have happened unless the claim reaches the court within six
     * months of that communication (NCC art. 2540, to which CPC art. 1015 para. 2
     * refers expressly inside the payment-order chapter itself).
     *
     * Anchored on `paymentNoticeCommunicationDate`, never on the date the summons PDF
     * was generated: the term runs from communication, and generation is typically
     * days earlier, which would show a maturity date later than the real one.
     *
     * Substantive-law term, so it is counted in months per NCC art. 2552 through
     * {@see self::monthsTermEnd()} and prorogated to the first working day under NCC
     * art. 2554, the substantive-law counterpart of CPC art. 181 para. 2, applied
     * through {@see self::substantiveTermEnd()}. It is prorogated for the same reason
     * the two limitation periods are: art. 2554 makes the first working day the day
     * the term is actually fulfilled, so the prorogated date is the real one.
     *
     * Only created while the request has not been filed yet: past CERERE_DEPUSA the
     * condition of art. 2540 is already met and a term counting down to it would be
     * telling the lawyer to do something already done. An existing deadline is
     * recomputed instead, so correcting the communication date moves this term the
     * same way it moves the summons-answer one. Returns null when there is nothing to
     * track.
     */
    public function createFilingDeadline(LegalCase $legalCase, \DateTimeImmutable $communicationDate): ?LegalDeadline
    {
        $existing = $this->deadlineRepository->findOneByCaseAndType($legalCase, DeadlineType::DEPUNERE_CERERE);

        if (!$this->isBeforeFiling($legalCase)) {
            return $existing;
        }

        $term = $this->substantiveTermEnd($this->monthsTermEnd($communicationDate, self::FILING_INTERRUPTION_MONTHS));
        $deadlineDate = $term->end;

        // Rendered to text: the row has to say what expiry costs, and what expires is
        // not the right to file but the interruption the summons produced.
        $description = $this->translator->trans(
            'case_overview.deadlines.filing_interruption_covers',
            ['%date%' => $communicationDate->format('d.m.Y')],
        );

        if ($existing === null) {
            return $this->persistDeadline(
                $legalCase,
                DeadlineType::DEPUNERE_CERERE,
                $deadlineDate,
                baseDate: $communicationDate,
                rawDeadline: $term->rawEnd,
                description: $description,
            );
        }

        $previousDate = $existing->getDeadlineDate();
        if ($previousDate->format('Y-m-d') === $deadlineDate->format('Y-m-d')) {
            return $existing;
        }

        $existing->setDeadlineDate($deadlineDate);
        $existing->setDescription($description);
        $existing->resetAlertFlags();
        $this->em->flush();

        $this->auditLogService->log(
            action: 'filing_deadline_recomputed',
            entityType: LegalDeadline::class,
            entityId: (string) $existing->getId(),
            oldData: ['deadlineDate' => $previousDate->format('Y-m-d')],
            newData: [
                'deadlineId' => $existing->getId(),
                'type' => DeadlineType::DEPUNERE_CERERE->value,
                'caseNumber' => $legalCase->getCaseNumber(),
                'baseDate' => $communicationDate->format('Y-m-d'),
                'rawDeadline' => $term->rawEnd->format('Y-m-d'),
                'deadlineDate' => $deadlineDate->format('Y-m-d'),
                'prorogated' => $term->rawEnd->format('Y-m-d') !== $deadlineDate->format('Y-m-d'),
            ],
            category: AuditLogService::CATEGORY_DEADLINE_EDITED,
        );
        $this->em->flush();

        return $existing;
    }

    /**
     * Closes the six-month term once the request has been filed: filing is exactly
     * the condition NCC art. 2540 sets, so the term has been met and nothing is left
     * to watch.
     *
     * Unlike CERERE_IN_ANULARE, this term has a single holder, the creditor, and a
     * single unambiguous triggering fact, so closing it automatically hides nothing
     * from the client. Completed without a user: the platform closed it, not a lawyer.
     */
    public function closeFilingDeadline(LegalCase $legalCase): ?LegalDeadline
    {
        return $this->closeDeadline($legalCase, DeadlineType::DEPUNERE_CERERE, 'filing_deadline_closed', 'request_filed');
    }

    /**
     * Closes the enforcement-limitation term against the date the enforcement request
     * was filed with the bailiff. That filing is what interrupts the limitation of the
     * right to enforce (CPC art. 708 para. 1 pt. 2), so from that date the term has no
     * object left: what it protected against, the title losing its enforceable power
     * while nobody acts on it, cannot happen any more.
     *
     * The date is required rather than taken from the case being in enforcement. The
     * limitation runs from the filing, not from the day the lawyer flipped the status,
     * and an irreversible closing has to rest on a recorded fact.
     *
     * The closing is undone by {@see self::reopenExecutionPrescriptionDeadline()} when
     * the enforcement fails in one of the ways CPC art. 708 para. 3 lists, because then
     * the interruption never happened and the original term is still running.
     *
     * Completed without a user: the platform closed it, not a lawyer.
     */
    public function closeExecutionPrescriptionDeadline(LegalCase $legalCase, \DateTimeImmutable $enforcementRequestDate): ?LegalDeadline
    {
        return $this->closeDeadline(
            $legalCase,
            DeadlineType::PRESCRIPTIE_EXECUTARE,
            'execution_prescription_deadline_closed',
            'enforcement_request_filed',
            ['enforcementRequestDate' => $enforcementRequestDate->format('Y-m-d')],
        );
    }

    /**
     * Reopens the enforcement-limitation term when the enforcement that closed it was
     * dismissed, annulled, allowed to lapse or abandoned. CPC art. 708 para. 3 says the
     * limitation is NOT interrupted in those cases, so the term the closing hid is
     * still running from its original anchor and the creditor is back to watching it.
     *
     * The deadline date is left untouched: the original anchor did not move, only the
     * interruption fell away. Alert flags are reset so the term can warn again.
     */
    public function reopenExecutionPrescriptionDeadline(LegalCase $legalCase, string $reason): ?LegalDeadline
    {
        $deadline = $this->deadlineRepository->findOneByCaseAndType($legalCase, DeadlineType::PRESCRIPTIE_EXECUTARE);
        if ($deadline === null || !$deadline->isCompleted()) {
            return $deadline;
        }

        $deadline->setCompleted(false);
        $deadline->setCompletedAt(null);
        $deadline->setCompletedBy(null);
        $deadline->resetAlertFlags();
        $this->em->flush();

        $this->auditLogService->log(
            action: 'execution_prescription_deadline_reopened',
            entityType: LegalDeadline::class,
            entityId: (string) $deadline->getId(),
            newData: [
                'deadlineId' => $deadline->getId(),
                'type' => DeadlineType::PRESCRIPTIE_EXECUTARE->value,
                'caseNumber' => $legalCase->getCaseNumber(),
                'deadlineDate' => $deadline->getDeadlineDate()->format('Y-m-d'),
                'reason' => $reason,
            ],
            category: AuditLogService::CATEGORY_DEADLINE_EDITED,
        );
        $this->em->flush();

        return $deadline;
    }

    /**
     * Closes the annulment-request term once its ten days have run (CPC art. 1024
     * para. 1). Both the debtor's window and the creditor's own run from the same
     * fact, service of the order, so they expire on the same day and closing at
     * expiry hides neither: at that point both have been consumed.
     *
     * The caller decides that the term has expired, from `rulingCommunicationDate`
     * through {@see self::appealTermEnd()}, so the date used here is the same one
     * {@see CaseAutoFinalizer} finalizes on and no second date can appear.
     */
    public function closeAppealDeadline(LegalCase $legalCase): ?LegalDeadline
    {
        return $this->closeDeadline($legalCase, DeadlineType::CERERE_IN_ANULARE, 'appeal_deadline_closed', 'appeal_term_lapsed');
    }

    /**
     * Marks the single automatic deadline of a type as completed, without a user,
     * because the platform closed it. Idempotent: an already closed or absent term is
     * returned untouched.
     *
     * @param array<string, string> $extraAuditData merged into the audit payload, for the fact the closing rests on
     */
    private function closeDeadline(LegalCase $legalCase, DeadlineType $type, string $action, string $reason, array $extraAuditData = []): ?LegalDeadline
    {
        $deadline = $this->deadlineRepository->findOneByCaseAndType($legalCase, $type);
        if ($deadline === null || $deadline->isCompleted()) {
            return $deadline;
        }

        $deadline->markCompleted(null);
        $this->em->flush();

        $this->auditLogService->log(
            action: $action,
            entityType: LegalDeadline::class,
            entityId: (string) $deadline->getId(),
            newData: [
                'deadlineId' => $deadline->getId(),
                'type' => $type->value,
                'caseNumber' => $legalCase->getCaseNumber(),
                'deadlineDate' => $deadline->getDeadlineDate()->format('Y-m-d'),
                'reason' => $reason,
                ...$extraAuditData,
            ],
            category: AuditLogService::CATEGORY_DEADLINE_COMPLETED,
        );
        $this->em->flush();

        return $deadline;
    }

    /**
     * Termen cerere în anulare: rulingCommunicationDate + 10 zile libere (CPC art.
     * 1024 alin. 1, "de la data înmânării sau comunicării", coroborat cu art. 181
     * alin. 1 pct. 2), prorogat la prima zi lucrătoare. Prioritate CRITICAL. NU
     * folosi `rulingDate` aici: termenul curge de la COMUNICARE, nu de la
     * pronunțare.
     */
    public function createAppealDeadline(LegalCase $legalCase, \DateTimeImmutable $rulingCommunicationDate): LegalDeadline
    {
        $term = $this->proceduralTermEnd($rulingCommunicationDate, self::APPEAL_DAYS);

        return $this->persistDeadline(
            $legalCase,
            DeadlineType::CERERE_IN_ANULARE,
            $term->end,
            baseDate: $rulingCommunicationDate,
            rawDeadline: $term->rawEnd,
        );
    }

    /**
     * Termen de timbrare: data comunicării înștiințării instanței + 10 zile libere
     * (OUG 80/2013 art. 33 alin. 2, care trimite la CPC art. 200 alin. 2 teza I,
     * coroborat cu art. 181 alin. 1 pct. 2), prorogat la prima zi lucrătoare.
     * Prioritate CRITICAL: ratarea lui atrage ANULAREA cererii (CPC art. 197).
     *
     * Termenul curge de la comunicarea instanței, dată pe care platforma nu o
     * cunoaște, deci e furnizată de avocat când primește înștiințarea. Recalculează
     * termenul existent dacă avocatul corectează data.
     */
    public function createStampDutyDeadline(LegalCase $legalCase, \DateTimeImmutable $courtNoticeDate): LegalDeadline
    {
        $term = $this->proceduralTermEnd($courtNoticeDate, self::STAMP_DUTY_DAYS);
        $rawDeadline = $term->rawEnd;
        $deadlineDate = $term->end;

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
     * Prorogated to the first working day under NCC art. 2554, the substantive-law
     * counterpart of CPC art. 181 para. 2: prescription is a substantive term (NCC
     * art. 2539-2541), so the rule that applies to it is art. 2554, which makes the
     * term end at the close of the first working day that follows. The prorogated
     * date is therefore the real maturity date, not a date later than it, and the raw
     * one stays in the audit payload.
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
            $term = $this->substantiveTermEnd($dueDate->modify(self::PRESCRIPTION_INTERVAL));
            $deadlineDate = $term->end;
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
                rawDeadline: $term->rawEnd,
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
    public function prescriptionDueDates(LegalCase $legalCase): array
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
     * Enforcement prescription deadline: definitiveDate + 3 years (CPC art. 705 para.
     * 1, runs from when the order became final). Priority CRITICAL.
     *
     * Prorogated to the first working day under NCC art. 2554, like the other
     * substantive terms here: a years-based limitation is outside CPC art. 181 para. 1
     * pt. 2, which governs day-based procedural terms, but art. 2554 covers it and
     * makes the first working day the day the term is actually fulfilled.
     */
    public function createExecutionPrescriptionDeadline(LegalCase $legalCase, \DateTimeImmutable $definitiveDate): LegalDeadline
    {
        $term = $this->substantiveTermEnd($definitiveDate->modify(self::EXECUTION_PRESCRIPTION_INTERVAL));

        return $this->persistDeadline(
            $legalCase,
            DeadlineType::PRESCRIPTIE_EXECUTARE,
            $term->end,
            baseDate: $definitiveDate,
            rawDeadline: $term->rawEnd,
        );
    }

    /**
     * Creates the enforcement-limitation term unless the case already carries one.
     * The anchor is chosen by the caller, which is the only place that knows which
     * ruling made the order final; this method only guarantees the single term per
     * case that {@see LegalDeadlineRepository::findOneByCaseAndType()} assumes.
     */
    public function ensureExecutionPrescriptionDeadline(LegalCase $legalCase, \DateTimeImmutable $definitiveDate): LegalDeadline
    {
        return $this->deadlineRepository->findOneByCaseAndType($legalCase, DeadlineType::PRESCRIPTIE_EXECUTARE)
            ?? $this->createExecutionPrescriptionDeadline($legalCase, $definitiveDate);
    }

    /**
     * Hearing date, stored exactly as the court fixed it. Priority MEDIUM.
     *
     * Deliberately NOT prorogated to the next working day. CPC art. 181 para. 2
     * prorogates a term that MATURES on a non-working day; a hearing date is not a
     * term that matures, it is a date the court set and the summons states. Moving it
     * would make the application show a day other than the one on the summons, and a
     * lawyer who reads this page instead of the summons would appear on the wrong day.
     *
     * A hearing falling on a non-working day is therefore reported, not corrected:
     * courts do not sit on those days, so it means the portal reading is wrong or the
     * date was mistyped, and only a human can tell which. The anomaly is signalled
     * twice, both times without touching the date: a warning in the log, for whoever
     * watches the portal sync, and a `nonWorkingDay` flag in the audit payload, which
     * stays attached to the record long after the log rotates.
     */
    public function createHearingDeadline(LegalCase $legalCase, \DateTimeImmutable $date, ?string $description = null): LegalDeadline
    {
        $isWorkingDay = $this->workingDayResolver->isWorkingDay($date);
        if (!$isWorkingDay) {
            $this->logger->warning('Hearing date falls on a non-working day; stored as received, verify against the summons', [
                'caseNumber' => $legalCase->getCaseNumber(),
                'hearingDate' => $date->format('Y-m-d'),
            ]);
        }

        return $this->persistDeadline(
            $legalCase,
            DeadlineType::JUDECATA,
            $date,
            baseDate: $date,
            rawDeadline: $date,
            description: $description,
            extraAuditData: $isWorkingDay ? [] : ['nonWorkingDay' => true],
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

    /** @param array<string, scalar> $extraAuditData merged into the audit payload */
    private function persistDeadline(
        LegalCase $legalCase,
        DeadlineType $type,
        \DateTimeImmutable $deadlineDate,
        \DateTimeImmutable $baseDate,
        \DateTimeImmutable $rawDeadline,
        ?string $description = null,
        array $extraAuditData = [],
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
            ] + $extraAuditData,
            category: AuditLogService::CATEGORY_DEADLINE_CREATED,
        );
        $this->em->flush();

        return $deadline;
    }
}
