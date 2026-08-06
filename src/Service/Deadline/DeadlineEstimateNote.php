<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * The short text printed under an agenda row when the date on it needs qualifying:
 * either the fact the term runs from has no confirmed date, or the term has been
 * interrupted and the stored date ignores it.
 *
 * The mark is what the row shows, the note is the sentence behind it, and the
 * parameters are shared by both so a date computed once appears in each.
 */
final readonly class DeadlineEstimateNote
{
    public function __construct(
        /** Translation key of the short marker on the row. */
        public string $markKey,
        /** Translation key of the full sentence, shown on hover. */
        public string $noteKey,
        /** @var array<string, string> placeholders both keys are translated with */
        public array $parameters = [],
    ) {}
}
