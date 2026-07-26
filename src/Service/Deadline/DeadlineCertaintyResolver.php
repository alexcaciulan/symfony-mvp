<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\DeadlineCertainty;
use App\Enum\DeadlineType;

/**
 * Decides whether a deadline date is certain or merely estimated, using only fields
 * that already exist on {@see LegalCase}. No migration, no new column: the rule is
 * that a term is CERT only when the fact it runs from carries a confirmed date on
 * the case. Everything else is ESTIMAT, which is the conservative side of the error:
 * a date shown as certain that is not invites the lawyer to trust it.
 *
 * This resolver reads the model, it never recomputes a date. Term arithmetic lives
 * in {@see DeadlineService} and is not touched here.
 */
final class DeadlineCertaintyResolver
{
    public function resolveFor(LegalDeadline $deadline): DeadlineCertainty
    {
        return $this->resolve($deadline->getType(), $deadline->getLegalCase());
    }

    /**
     * Per type, the fact the term runs from and where its date lives:
     *
     * - RASPUNS_SOMATIE runs from the debtor receiving the summons (CPC art. 1015
     *   para. 1). At SOMATIE_TRIMISA the deadline is seeded from the generation date,
     *   and only `paymentNoticeCommunicationDate` records the real receipt, so it is
     *   CERT exactly when that field is set.
     * - PRESCRIPTIE_EXECUTARE runs from the order becoming enforceable, which the
     *   subscriber derives from `rulingCommunicationDate`; when that is missing it
     *   falls back to the current day, producing a term LONGER than the real one.
     *   CERT only when the communication date is set.
     * - CERERE_IN_ANULARE runs from the communication of the order (CPC art. 1024
     *   para. 1). Its only creation paths already require that date, but the check is
     *   repeated here so a deadline created another way cannot claim certainty.
     * - PRESCRIPTIE is scadenta plus three years (NCC art. 2517), but a communicated
     *   summons interrupts the limitation period (CPC art. 1015 para. 2 referring to
     *   NCC art. 2540). The interruption is not modelled anywhere, so once the summons
     *   is communicated the stored date is no longer the real one and cannot be CERT.
     * - TIMBRARE is CERT: the only path that creates it is the lawyer recording the
     *   court notice date (StampDutyService::recordCourtNotice), so the generating
     *   fact is confirmed by construction. The date itself is kept only in the audit
     *   log, so it cannot be re-derived from the case, which is why existence of the
     *   deadline is the evidence used here.
     * - JUDECATA is CERT: the hearing date is fixed by the court and read from the
     *   portal, not inferred.
     * - OTHER is CERT: the lawyer typed the exact date, there is no generating fact
     *   to confirm.
     * - DEPUNERE_CERERE is ESTIMAT: no service creates it and it has no legal basis
     *   in the code, so there is no fact in the model to decide on. Should it ever be
     *   given the six-month basis of NCC art. 2540, this branch must be revisited
     *   together with whatever field records that start date.
     */
    public function resolve(DeadlineType $type, LegalCase $legalCase): DeadlineCertainty
    {
        return match ($type) {
            DeadlineType::RASPUNS_SOMATIE => $this->certainWhen($legalCase->getPaymentNoticeCommunicationDate() !== null),
            DeadlineType::PRESCRIPTIE_EXECUTARE, DeadlineType::CERERE_IN_ANULARE => $this->certainWhen($legalCase->getRulingCommunicationDate() !== null),
            DeadlineType::PRESCRIPTIE => $this->certainWhen($legalCase->getPaymentNoticeCommunicationDate() === null),
            DeadlineType::TIMBRARE, DeadlineType::JUDECATA, DeadlineType::OTHER => DeadlineCertainty::CERT,
            DeadlineType::DEPUNERE_CERERE => DeadlineCertainty::ESTIMAT,
        };
    }

    /**
     * Why the date is only an estimate, as the base of a translation key. One wording
     * cannot serve both cases, because the two reasons are opposites and the row has
     * room for a single short marker.
     *
     * On every type but PRESCRIPTIE the fact the term runs from has no confirmed date
     * on the case, so the date shown is a working assumption and the lawyer has to
     * record the real one. On PRESCRIPTIE the generating fact, the due date, IS
     * confirmed; what turns the term into an estimate is that the OTHER date has been
     * confirmed, the communication of the summons, which interrupts the limitation
     * period (CPC art. 1015 para. 2 referring to NCC art. 2540) under a six-month
     * condition the application does not model. Telling the lawyer there that the
     * generating fact is unconfirmed would point at a missing date instead of at an
     * undocumented interruption.
     */
    public function estimateReasonKey(DeadlineType $type): string
    {
        return $type === DeadlineType::PRESCRIPTIE
            ? 'deadlines.row.estimate.prescription_interruption'
            : 'deadlines.row.estimate.unconfirmed_fact';
    }

    private function certainWhen(bool $condition): DeadlineCertainty
    {
        return $condition ? DeadlineCertainty::CERT : DeadlineCertainty::ESTIMAT;
    }
}
