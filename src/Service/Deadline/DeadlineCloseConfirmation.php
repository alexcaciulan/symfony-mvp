<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * What the lawyer reads before closing a deadline whose miss cannot be undone.
 *
 * It carries legal content, not a yes/no prompt: the sanction the term protects
 * against, what closing the row does (stop the alerts) and what it does not do
 * (prove the act, interrupt a limitation period). The facts are the state of the
 * case as stored, so the sentence is checked against the record instead of being
 * asserted in the abstract.
 */
final readonly class DeadlineCloseConfirmation
{
    public function __construct(
        public string $titleKey,
        public string $bodyKey,
        /** Emphasised line shown only when the record contradicts the act being declared. */
        public ?string $warningKey = null,
        /** @var list<DeadlineConfirmationFact> */
        public array $facts = [],
    ) {}
}
