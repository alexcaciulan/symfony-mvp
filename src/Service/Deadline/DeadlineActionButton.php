<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * One button of an agenda row, fully decided in PHP: the template only prints the
 * label and points the link or the form at the route already chosen here.
 */
final readonly class DeadlineActionButton
{
    public function __construct(
        /** Translation key of the label. */
        public string $label,
        public string $route,
        /** @var array<string, int|string|null> */
        public array $routeParameters,
        /** GET renders a link, POST renders a form with a CSRF token. */
        public string $method,
        /** Whether pressing it closes the deadline, meaning it stops the alerts. */
        public bool $closesDeadline = false,
        /** Translation key of the note explaining what the button does not prove. */
        public ?string $note = null,
        /**
         * Id of the CSRF token the target route validates. Present only when the
         * route needs nothing beyond that token, so the agenda can post to it
         * directly. Left null when the act needs data the agenda does not collect
         * (a communication date, the acknowledgement of the debt), because those
         * routes reject an empty payload; see {@see self::needsCaseDialog()}.
         */
        public ?string $csrfTokenId = null,
        /**
         * Id of the dialog on the agenda page that collects the missing data. Set
         * only on buttons that {@see self::needsCaseDialog()} is true for, and only
         * where the agenda carries that dialog itself; without it the button stays a
         * link into the case, which is also what happens with no JavaScript.
         */
        public ?string $agendaDialogId = null,
    ) {}

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * Whether pressing it has to happen on the case page, in the dialog that
     * collects the missing data. The agenda then renders a link to the case rather
     * than a form that the target route would reject.
     */
    public function needsCaseDialog(): bool
    {
        return $this->isPost() && $this->csrfTokenId === null;
    }
}
