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
 * in {@see DeadlineService} and is not touched here, and the wording the row shows
 * for a date that is not CERT lives in {@see DeadlineEstimateNoteResolver}.
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
     *   para. 1), recorded in `paymentNoticeCommunicationDate`. Nothing creates the
     *   term before that date exists, so in practice it is always CERT; the check is
     *   kept because a term created another way, or one left over from before the term
     *   stopped being seeded from the generation date, must not claim certainty.
     * - PRESCRIPTIE_EXECUTARE runs from the order becoming final (CPC art. 705 para.
     *   2), not from it becoming enforceable, which happens earlier and is a separate
     *   question (CPC art. 1021). Which date made it final depends on whether the
     *   debtor challenged it: after an annulment request it is the communication of
     *   the ruling given on that request (CPC art. 1024 para. 8), otherwise the
     *   subscriber derives the day from `rulingCommunicationDate` and, failing that,
     *   from the ruling date, which precedes service and therefore yields a term
     *   SHORTER than the real one. CERT only when the date that applies to the case is
     *   recorded.
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
     * - DEPUNERE_CERERE runs from the communication of the summons, the same fact as
     *   RASPUNS_SOMATIE: the six months of NCC art. 2540 are counted from it, and the
     *   only path that creates the term is the lawyer recording that date. CERT
     *   exactly when `paymentNoticeCommunicationDate` is set, which is also the only
     *   state in which the term exists at all.
     */
    public function resolve(DeadlineType $type, LegalCase $legalCase): DeadlineCertainty
    {
        return match ($type) {
            DeadlineType::RASPUNS_SOMATIE, DeadlineType::DEPUNERE_CERERE => $this->certainWhen($legalCase->getPaymentNoticeCommunicationDate() !== null),
            DeadlineType::CERERE_IN_ANULARE => $this->certainWhen($legalCase->getRulingCommunicationDate() !== null),
            DeadlineType::PRESCRIPTIE_EXECUTARE => $this->certainWhen($this->executionAnchorIsConfirmed($legalCase)),
            DeadlineType::PRESCRIPTIE => $this->certainWhen($legalCase->getPaymentNoticeCommunicationDate() === null),
            DeadlineType::TIMBRARE, DeadlineType::JUDECATA, DeadlineType::OTHER => DeadlineCertainty::CERT,
        };
    }

    /**
     * Whether the date the enforcement limitation actually runs from is recorded. A
     * case that went through an annulment request is only certain on the communication
     * of the ruling given there; the communication of the first order says nothing
     * about when the title became final, so it cannot stand in for it.
     */
    private function executionAnchorIsConfirmed(LegalCase $legalCase): bool
    {
        if ($legalCase->getAnnulmentRulingCommunicationDate() !== null) {
            return true;
        }

        return !$legalCase->hasPassedThroughAnnulment() && $legalCase->getRulingCommunicationDate() !== null;
    }

    private function certainWhen(bool $condition): DeadlineCertainty
    {
        return $condition ? DeadlineCertainty::CERT : DeadlineCertainty::ESTIMAT;
    }
}
