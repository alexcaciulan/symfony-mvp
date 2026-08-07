<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;

/**
 * Decides, per agenda row, what the lawyer can press. The decision is taken here
 * and not in the template: it depends on the deadline type, on whether the date is
 * certain, on whether the term has passed and on the stage of the case, which is
 * four conditions no view layer should be carrying.
 *
 * Every route is one that already exists, so authorization through CaseVoter and
 * the audit trail keep living where they live today.
 */
final class DeadlineRowActionResolver
{
    private const OPEN_CASE = 'deadlines.action.open_case';
    private const OPEN_PORTAL = 'deadlines.action.open_portal';
    private const MARK_DONE = 'deadlines.action.mark_done';
    private const MARK_STAMPED = 'deadlines.action.mark_stamped';
    private const STOP_TRACKING = 'deadlines.action.stop_tracking';
    private const SEND_SUMMONS = 'deadlines.action.send_summons';
    private const GENERATE_PAYMENT_ORDER = 'deadlines.action.generate_payment_order';
    private const CONFIRM_FILING = 'deadlines.action.confirm_filing';
    private const SET_SUMMONS_COMMUNICATION_DATE = 'deadlines.action.set_summons_communication_date';

    /**
     * Dialog that collects the communication date on the agenda page. It carries the
     * id of the case page dialog on purpose: the two are the same act on two screens
     * and never coexist in one document, so the answer that dismisses one dismisses
     * the other with no mapping in between.
     */
    private const SUMMONS_DATE_DIALOG_ID = 'hs-modal-set-summons-communication-date';

    private const NOTE_MARK_DONE = 'deadlines.action.note.mark_done';
    private const NOTE_STOP_TRACKING = 'deadlines.action.note.stop_tracking';

    public function resolve(DeadlineAgendaItem $item): DeadlineRowAction
    {
        // A closed term is only on screen because the lawyer asked to see what was
        // already dealt with. Re-closing it does nothing and every act it used to
        // offer belongs to a term that is still running, so the row keeps one way out.
        if ($item->deadline->isCompleted()) {
            return new DeadlineRowAction($this->openCase($item->deadline->getLegalCase()));
        }

        // A fatal term that has passed on a certain date can no longer be worked on:
        // closing it would only silence alerts about a consequence that may already
        // have occurred. The row keeps a single way out, into the case.
        if ($item->isConsequenceConsumed()) {
            return new DeadlineRowAction($this->openCase($item->deadline->getLegalCase()));
        }

        return match ($item->deadline->getType()) {
            // The act is performed right here, so the primary button is the close.
            DeadlineType::TIMBRARE => new DeadlineRowAction($this->close($item, self::MARK_STAMPED)),
            DeadlineType::CERERE_IN_ANULARE, DeadlineType::OTHER => new DeadlineRowAction($this->close($item, self::MARK_DONE, self::NOTE_MARK_DONE)),

            // The hearing is attended at the court and its hour only exists on the
            // portal, so the primary button leads there and the close stays secondary.
            DeadlineType::JUDECATA => new DeadlineRowAction(
                $this->openCase($item->deadline->getLegalCase(), self::OPEN_PORTAL, ['tab' => 'portal']),
                $this->close($item, self::MARK_DONE, self::NOTE_MARK_DONE),
            ),

            DeadlineType::RASPUNS_SOMATIE => $this->summonsAnswerAction($item),

            // Both are satisfied by the same act, the request reaching the court, and
            // closing either changes nothing in law, so they share the same buttons.
            DeadlineType::PRESCRIPTIE, DeadlineType::DEPUNERE_CERERE => $this->limitationAction($item),

            // Enforcement runs through a bailiff, outside the platform, so there is no
            // act to offer. The row states the term and lets the lawyer into the case.
            DeadlineType::PRESCRIPTIE_EXECUTARE => new DeadlineRowAction(
                $this->openCase($item->deadline->getLegalCase()),
                $this->close($item, self::STOP_TRACKING, self::NOTE_STOP_TRACKING),
            ),
        };
    }

    /**
     * The debtor's own term (CPC art. 1015 para. 1). While the receipt date is
     * missing the deadline is an estimate, so recording that date is worth more than
     * closing the row. Once it has expired, what it unblocks is filing the request.
     */
    private function summonsAnswerAction(DeadlineAgendaItem $item): DeadlineRowAction
    {
        $case = $item->deadline->getLegalCase();

        if ($item->certainty->isEstimated()) {
            return new DeadlineRowAction(
                new DeadlineActionButton(
                    label: self::SET_SUMMONS_COMMUNICATION_DATE,
                    route: 'case_deadline_summons_communication_date',
                    routeParameters: ['caseId' => $case->getId()],
                    method: 'POST',
                    agendaDialogId: self::SUMMONS_DATE_DIALOG_ID,
                ),
                $this->close($item, self::MARK_DONE, self::NOTE_MARK_DONE),
            );
        }

        if ($item->daysRemaining < 0) {
            return new DeadlineRowAction(
                $this->generatePaymentOrder($case),
                $this->close($item, self::MARK_DONE, self::NOTE_MARK_DONE),
            );
        }

        return new DeadlineRowAction($this->close($item, self::MARK_DONE, self::NOTE_MARK_DONE));
    }

    /**
     * Limitation of the right to sue (NCC art. 2517). What stops it is an act of the
     * creditor, and which act depends on how far the case got: the summons interrupts
     * it (CPC art. 1015 para. 2), the request filed in court is what keeps the
     * interruption. Past filing there is no further act to offer from the agenda.
     */
    private function limitationAction(DeadlineAgendaItem $item): DeadlineRowAction
    {
        $case = $item->deadline->getLegalCase();
        $close = $this->close($item, self::STOP_TRACKING, self::NOTE_STOP_TRACKING);

        $primary = match ($case->getStatus()) {
            CaseStatus::AMIABIL => new DeadlineActionButton(
                label: self::SEND_SUMMONS,
                route: 'case_summons_generate',
                routeParameters: ['id' => $case->getId()],
                method: 'POST',
                csrfTokenId: 'generate_summons_' . $case->getId(),
            ),
            CaseStatus::SOMATIE_TRIMISA => $this->generatePaymentOrder($case),
            // The package is ready and only the filing is missing, which is the very
            // act that stops this term running. No CSRF token id: confirming needs a
            // date and a channel, so the row links into the case dialog that asks for
            // them rather than posting an empty payload the route would reject.
            CaseStatus::CERERE_GENERATA => new DeadlineActionButton(
                label: self::CONFIRM_FILING,
                route: 'case_transition_file',
                routeParameters: ['id' => $case->getId()],
                method: 'POST',
            ),
            default => $this->openCase($case),
        };

        return new DeadlineRowAction($primary, $close);
    }

    private function generatePaymentOrder(LegalCase $case): DeadlineActionButton
    {
        return new DeadlineActionButton(
            label: self::GENERATE_PAYMENT_ORDER,
            route: 'case_payment_order_generate',
            routeParameters: ['id' => $case->getId()],
            method: 'POST',
        );
    }

    /** @param array<string, string> $extraParameters */
    private function openCase(LegalCase $case, string $label = self::OPEN_CASE, array $extraParameters = []): DeadlineActionButton
    {
        return new DeadlineActionButton(
            label: $label,
            route: 'case_overview',
            routeParameters: ['id' => $case->getId()] + $extraParameters,
            method: 'GET',
        );
    }

    private function close(DeadlineAgendaItem $item, string $label, ?string $note = null): DeadlineActionButton
    {
        $deadline = $item->deadline;

        return new DeadlineActionButton(
            label: $label,
            route: 'case_deadline_complete',
            routeParameters: $this->closeRouteParameters($deadline),
            method: 'POST',
            closesDeadline: true,
            note: $note,
            csrfTokenId: 'complete_deadline_' . $deadline->getId(),
        );
    }

    /** @return array<string, int|null> */
    private function closeRouteParameters(LegalDeadline $deadline): array
    {
        return [
            'caseId' => $deadline->getLegalCase()->getId(),
            'deadlineId' => $deadline->getId(),
        ];
    }
}
