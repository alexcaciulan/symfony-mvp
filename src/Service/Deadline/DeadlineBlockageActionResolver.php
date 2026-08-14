<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Enum\DeadlineBlockageReason;

/**
 * The single button of a blockage row: the route that records the missing date, and
 * the dialog that collects it when the agenda page carries one.
 *
 * Every one of these routes is a POST that rejects an empty payload, so the button is
 * built without a CSRF token id. {@see DeadlineActionButton::needsCaseDialog()} is
 * then true, which is what makes the shared button template render a link to the case
 * rather than a form the route would answer 405 or 400 to. Where a dialog id is set as
 * well, the same link opens that dialog in place and the href stays the fallback for a
 * browser running no scripts.
 */
final class DeadlineBlockageActionResolver
{
    /**
     * The only dialog of this kind the agenda page carries. The id is the one the case
     * page uses for the same act, on purpose: the two never coexist in a document, so
     * the answer that dismisses one dismisses the other with no mapping in between.
     */
    private const SUMMONS_DATE_DIALOG_ID = 'hs-modal-set-summons-communication-date';

    public function resolve(DeadlineBlockage $blockage): DeadlineActionButton
    {
        $case = $blockage->legalCase;

        return match ($blockage->reason) {
            DeadlineBlockageReason::SUMMONS_COMMUNICATION_MISSING => $this->button(
                $blockage,
                'case_deadline_summons_communication_date',
                ['caseId' => $case->getId()],
                self::SUMMONS_DATE_DIALOG_ID,
            ),
            // No dialog: the act is a file upload, and a dialog on the agenda would have
            // to carry the file input, the type and the cap the case page already owns.
            // The button therefore stays a link into the case, which is the default of
            // {@see DeadlineActionButton::needsCaseDialog()}.
            DeadlineBlockageReason::SUMMONS_PROOF_MISSING => $this->button(
                $blockage,
                'case_document_upload',
                ['caseId' => $case->getId()],
            ),
            DeadlineBlockageReason::RULING_COMMUNICATION_MISSING,
            // The enforcement anchor of a case that never went through an annulment
            // request is the communication of the order itself, so it is recorded in
            // the same dialog and through the same route.
            DeadlineBlockageReason::EXECUTION_ANCHOR_MISSING => $this->button(
                $blockage,
                'case_deadline_ruling_date',
                ['caseId' => $case->getId()],
            ),
            DeadlineBlockageReason::ANNULMENT_RULING_COMMUNICATION_MISSING => $this->button(
                $blockage,
                'case_deadline_annulment_ruling_date',
                ['caseId' => $case->getId()],
            ),
            DeadlineBlockageReason::STAMP_DUTY_NOTICE_MISSING => $this->button(
                $blockage,
                'case_stamp_duty_court_notice',
                ['id' => $case->getId()],
            ),
            DeadlineBlockageReason::ENFORCEMENT_REGISTRATION_NUMBER_MISSING => $this->button(
                $blockage,
                'case_deadline_enforcement_registration_number',
                ['caseId' => $case->getId()],
            ),
        };
    }

    /** @param array<string, int|string|null> $routeParameters */
    private function button(
        DeadlineBlockage $blockage,
        string $route,
        array $routeParameters,
        ?string $agendaDialogId = null,
    ): DeadlineActionButton {
        return new DeadlineActionButton(
            label: $blockage->reason->actionLabel(),
            route: $route,
            routeParameters: $routeParameters,
            method: 'POST',
            agendaDialogId: $agendaDialogId,
        );
    }
}
