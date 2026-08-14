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
    private const SEND_SUMMONS = 'deadlines.action.send_summons';
    private const GENERATE_PAYMENT_ORDER = 'deadlines.action.generate_payment_order';
    private const NO_ANNULMENT_REQUEST = 'deadlines.action.no_annulment_request';

    private const NOTE_MARK_DONE = 'deadlines.action.note.mark_done';
    private const NOTE_NO_ANNULMENT_REQUEST = 'deadlines.action.note.no_annulment_request';

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
            DeadlineType::OTHER => new DeadlineRowAction($this->close($item, self::MARK_DONE, self::NOTE_MARK_DONE)),

            // The only act the platform can offer on the annulment window is the
            // decision NOT to use it. It cannot draft the request: that needs the
            // reasoning of a ruling nobody has analysed here. So the button says what it
            // does, instead of a generic "done" that reads as "the request was filed".
            DeadlineType::CERERE_IN_ANULARE => new DeadlineRowAction($this->waiveAnnulment($item)),

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
            // It closes on its own when enforcement starts, so there is nothing to
            // press here either.
            DeadlineType::PRESCRIPTIE_EXECUTARE => new DeadlineRowAction($this->openCase($item->deadline->getLegalCase())),
        };
    }

    /**
     * The debtor's own term (CPC art. 1015 para. 1). The row only exists once the
     * receipt date has been recorded, because that is the fact the term runs from and
     * the only thing that makes it born; until then the case is carried by the
     * blockage zone. So there is no estimated state left to handle here, and what the
     * row offers is what its expiry unblocks, filing the request.
     */
    private function summonsAnswerAction(DeadlineAgendaItem $item): DeadlineRowAction
    {
        $case = $item->deadline->getLegalCase();

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
     *
     * No way to dismiss the row: hiding a limitation term hides the only thing that still
     * says the claim can die of age, and the row already states the date until which the
     * interruption holds, so it is informative rather than noisy. Nowhere else offers it
     * either, the deadlines tab of the case included, and
     * {@see \App\Controller\Case\CaseDeadlineController::complete()} refuses the two
     * limitation types outright. What ends such a term is the act that stops it, recorded
     * by the platform, or deleting a term created by mistake.
     */
    private function limitationAction(DeadlineAgendaItem $item): DeadlineRowAction
    {
        $case = $item->deadline->getLegalCase();

        $primary = match ($case->getStatus()) {
            CaseStatus::AMIABIL => new DeadlineActionButton(
                label: self::SEND_SUMMONS,
                route: 'case_summons_generate',
                routeParameters: ['id' => $case->getId()],
                method: 'POST',
                csrfTokenId: 'generate_summons_' . $case->getId(),
            ),
            CaseStatus::SOMATIE_TRIMISA => $this->generatePaymentOrder($case),
            default => $this->openCase($case),
        };

        return new DeadlineRowAction($primary);
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

    /**
     * Closing the annulment window by deciding against it. Its own route and its own
     * token id, not the generic close: the audit trail has to tell a decision apart from
     * a lapse. `closesDeadline` stays true, so the row still hands the page its
     * confirmation dialog before posting.
     */
    private function waiveAnnulment(DeadlineAgendaItem $item): DeadlineActionButton
    {
        $deadline = $item->deadline;

        return new DeadlineActionButton(
            label: self::NO_ANNULMENT_REQUEST,
            route: 'case_deadline_no_annulment_request',
            routeParameters: $this->closeRouteParameters($deadline),
            method: 'POST',
            closesDeadline: true,
            note: self::NOTE_NO_ANNULMENT_REQUEST,
            csrfTokenId: 'no_annulment_request_' . $deadline->getId(),
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
