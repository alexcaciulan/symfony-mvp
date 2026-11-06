<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\DeadlineType;

/**
 * Decides, per agenda row, what the lawyer can press: the way into the case and, when the
 * term can be closed from the agenda, the close. The decision is taken here and not in
 * the template: it depends on the deadline type, on whether the date is certain and on
 * whether the term has passed, which no view layer should be carrying.
 *
 * Nothing here generates a document or changes the stage of a case. Those acts cost a
 * subscription slot or need data the agenda does not collect, so they stay on the case
 * page, and a row only suggests them through {@see DeadlineNextStepResolver}.
 *
 * Every route is one that already exists, so authorization through CaseVoter and the
 * audit trail keep living where they live today.
 */
final class DeadlineRowActionResolver
{
    private const OPEN_CASE = 'deadlines.action.open_case';
    private const OPEN_PORTAL = 'deadlines.action.open_portal';
    private const OPEN_FILING = 'deadlines.action.open_filing';
    private const MARK_DONE = 'deadlines.action.mark_done';
    private const MARK_STAMPED = 'deadlines.action.mark_stamped';
    private const NO_ANNULMENT_REQUEST = 'deadlines.action.no_annulment_request';

    private const NOTE_MARK_DONE = 'deadlines.action.note.mark_done';
    private const NOTE_NO_ANNULMENT_REQUEST = 'deadlines.action.note.no_annulment_request';
    private const NOTE_SUMMONS = 'deadlines.action.note.summons';
    private const NOTE_PAYMENT_ORDER = 'deadlines.action.note.payment_order';
    private const NOTE_FILING_PACKAGE = 'deadlines.action.note.filing_package';

    public function resolve(DeadlineAgendaItem $item, ?DeadlineNextStep $nextStep = null): DeadlineRowAction
    {
        $deadline = $item->deadline;
        $open = $this->openFor($deadline->getLegalCase(), $nextStep);

        // A closed term is only on screen in the closed tab; re-closing it does nothing.
        // A fatal term that has passed on a certain date can no longer be worked on:
        // closing it would only silence alerts about a consequence that may already have
        // occurred. Both rows keep the single way out, into the case.
        if ($deadline->isCompleted() || $item->isConsequenceConsumed()) {
            return new DeadlineRowAction($open);
        }

        return match ($deadline->getType()) {
            DeadlineType::TIMBRARE => new DeadlineRowAction($open, $this->close($item, self::MARK_STAMPED)),
            DeadlineType::OTHER, DeadlineType::RASPUNS_SOMATIE => new DeadlineRowAction($open, $this->close($item, self::MARK_DONE, self::NOTE_MARK_DONE)),

            // The only act the platform can offer on the annulment window is the decision
            // NOT to use it. It cannot draft the request: that needs the reasoning of a
            // ruling nobody has analysed here.
            DeadlineType::CERERE_IN_ANULARE => new DeadlineRowAction($open, $this->waiveAnnulment($item)),

            // The hearing is attended at the court and its hour only exists on the portal.
            DeadlineType::JUDECATA => new DeadlineRowAction(
                $this->openCase($deadline->getLegalCase(), self::OPEN_PORTAL, ['tab' => 'portal']),
                $this->close($item, self::MARK_DONE, self::NOTE_MARK_DONE),
            ),

            // No way to dismiss these rows: hiding a limitation term hides the only thing
            // that still says the claim can die of age. What ends them is the act that
            // stops them, recorded by the platform, or deleting a term created by mistake.
            // Enforcement closes when the number from the bailiff is recorded.
            DeadlineType::PRESCRIPTIE, DeadlineType::DEPUNERE_CERERE, DeadlineType::PRESCRIPTIE_EXECUTARE => new DeadlineRowAction($open),
        };
    }

    private function openFor(LegalCase $case, ?DeadlineNextStep $nextStep): DeadlineActionButton
    {
        return match ($nextStep?->code) {
            DeadlineNextStep::SUMMONS => $this->openCase($case, note: self::NOTE_SUMMONS),
            DeadlineNextStep::PAYMENT_ORDER => $this->openCase($case, note: self::NOTE_PAYMENT_ORDER),
            DeadlineNextStep::FILING_PACKAGE => $this->openCase($case, self::OPEN_FILING, ['tab' => 'depunere'], self::NOTE_FILING_PACKAGE),
            default => $this->openCase($case),
        };
    }

    /** @param array<string, string> $extraParameters */
    private function openCase(LegalCase $case, string $label = self::OPEN_CASE, array $extraParameters = [], ?string $note = null): DeadlineActionButton
    {
        return new DeadlineActionButton(
            label: $label,
            route: 'case_overview',
            routeParameters: ['id' => $case->getId()] + $extraParameters,
            method: 'GET',
            note: $note,
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
