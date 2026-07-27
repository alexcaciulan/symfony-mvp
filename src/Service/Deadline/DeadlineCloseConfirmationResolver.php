<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\DeadlineConsequence;
use App\Enum\DeadlineType;
use App\Enum\StampDutyStatus;

/**
 * Decides whether closing a deadline has to be confirmed, and with what.
 *
 * Only the terms whose miss cannot be undone get a dialog. Everywhere else a
 * confirmation would be noise, and noise is what makes lawyers click through the
 * one dialog that mattered.
 *
 * The wording is per consequence, not per button, because what the lawyer needs to
 * read is the sanction the term protects against. The stamp duty variant also
 * states the duty as it is stored on the case: closing that term while the record
 * says the duty is unpaid is a declaration, and the dialog says so instead of
 * letting the agenda imply that the payment happened.
 */
final class DeadlineCloseConfirmationResolver
{
    public function resolve(DeadlineAgendaItem $item): ?DeadlineCloseConfirmation
    {
        if (!$item->isIrreversible()) {
            return null;
        }

        return match ($item->consequence) {
            DeadlineConsequence::CASE_ANNULMENT => $this->stampDuty($item->deadline),
            DeadlineConsequence::RIGHT_EXTINCTION => $this->limitation($item->deadline),
            DeadlineConsequence::FORFEITURE => $this->forfeiture($item->deadline),
            default => null,
        };
    }

    /**
     * Stamp duty (OUG 80/2013 art. 33 para. 2, CPC art. 197). The dialog prints the
     * duty as recorded on the case, so the lawyer confirms against the record rather
     * than against memory, and warns when the record says it is not paid.
     */
    private function stampDuty(LegalDeadline $deadline): DeadlineCloseConfirmation
    {
        $case = $deadline->getLegalCase();
        $status = $case->getStampDutyStatus();

        $amount = $case->getStampDuty();

        $facts = [
            new DeadlineConfirmationFact('deadlines.confirm.fact.case', $this->caseNumber($case)),
            new DeadlineConfirmationFact('deadlines.confirm.fact.stamp_duty_status', $status->label(), valueIsTranslationKey: true),
            // A case created before the amount was stored still owes the duty, so an
            // absent value is written as unknown, never as zero.
            $amount === null || $amount === ''
                ? new DeadlineConfirmationFact('deadlines.confirm.fact.stamp_duty_amount', 'deadlines.confirm.fact.unknown', valueIsTranslationKey: true)
                : new DeadlineConfirmationFact('deadlines.confirm.fact.stamp_duty_amount', number_format((float) $amount, 2, ',', '.') . ' RON'),
        ];

        $paidAt = $case->getStampDutyPaidAt();
        if ($paidAt instanceof \DateTimeImmutable) {
            $facts[] = new DeadlineConfirmationFact('deadlines.confirm.fact.stamp_duty_paid_at', $paidAt->format('d.m.Y'));
        }

        $uat = $case->getStampDutyUat();
        if ($uat !== null && $uat !== '') {
            $facts[] = new DeadlineConfirmationFact('deadlines.confirm.fact.stamp_duty_uat', $uat);
        }

        $facts[] = new DeadlineConfirmationFact('deadlines.confirm.fact.deadline_date', $deadline->getDeadlineDate()->format('d.m.Y'));

        return new DeadlineCloseConfirmation(
            titleKey: 'deadlines.confirm.stamp_duty.title',
            bodyKey: 'deadlines.confirm.stamp_duty.body',
            warningKey: $status === StampDutyStatus::ACHITATA ? null : 'deadlines.confirm.stamp_duty.warning_unpaid',
            facts: $facts,
        );
    }

    /**
     * The three terms whose miss extinguishes a right: limitation of the right to sue
     * (NCC art. 2500 para. 1, art. 2517), limitation of enforcement (CPC art. 705
     * para. 1) and the six months that keep the interruption produced by the summons
     * alive (NCC art. 2540, CPC art. 1015 para. 2). They extinguish different things,
     * so they are worded apart. Either way closing the row changes nothing in law: the
     * period runs on, and only an act of the creditor affects it.
     */
    private function limitation(LegalDeadline $deadline): DeadlineCloseConfirmation
    {
        $prefix = match ($deadline->getType()) {
            DeadlineType::PRESCRIPTIE_EXECUTARE => 'deadlines.confirm.enforcement_limitation.',
            DeadlineType::DEPUNERE_CERERE => 'deadlines.confirm.filing_interruption.',
            default => 'deadlines.confirm.limitation.',
        };

        return new DeadlineCloseConfirmation(
            titleKey: $prefix . 'title',
            bodyKey: $prefix . 'body',
            warningKey: $prefix . 'warning',
            facts: $this->commonFacts($deadline),
        );
    }

    /** Annulment request, a forfeiture term (CPC art. 1024 para. 1, art. 185 para. 1). */
    private function forfeiture(LegalDeadline $deadline): DeadlineCloseConfirmation
    {
        return new DeadlineCloseConfirmation(
            titleKey: 'deadlines.confirm.forfeiture.title',
            bodyKey: 'deadlines.confirm.forfeiture.body',
            facts: $this->commonFacts($deadline),
        );
    }

    /** @return list<DeadlineConfirmationFact> */
    private function commonFacts(LegalDeadline $deadline): array
    {
        return [
            new DeadlineConfirmationFact('deadlines.confirm.fact.case', $this->caseNumber($deadline->getLegalCase())),
            new DeadlineConfirmationFact('deadlines.confirm.fact.deadline_type', $deadline->getType()->label(), valueIsTranslationKey: true),
            new DeadlineConfirmationFact('deadlines.confirm.fact.deadline_date', $deadline->getDeadlineDate()->format('d.m.Y')),
        ];
    }

    private function caseNumber(LegalCase $case): string
    {
        return $case->getCourtCaseNumber() ?? $case->getCaseNumber();
    }
}
