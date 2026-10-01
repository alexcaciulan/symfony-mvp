<?php

declare(strict_types=1);

namespace App\DTO\Extraction;

use App\Enum\ConflictScope;
use App\Enum\ConflictSeverity;

/**
 * A disagreement between documents that the aggregation refused to settle on
 * its own.
 *
 * Collected during aggregation and carried on {@see WizardPrefillResult}. The
 * card that renders these and the resolutions the lawyer picks are the next
 * tranche; the shape is fixed here so that tranche adds a view rather than a
 * refactor.
 */
final readonly class PrefillConflict
{
    /**
     * @param ?string $entityKey which entity of the scope, when there are
     *        several (the cluster key of a debtor, the dedup key of a claim
     *        position); null when the scope has exactly one subject
     * @param list<ConflictOption> $options the values in play, in a stable
     *        order; empty when the conflict is not a choice between values
     * @param ?int $suggestedIndex index into $options the aggregation would
     *        take; null when it declined to suggest one
     * @param bool $acknowledgeable whether a blocking conflict without options
     *        can be passed by stating it was read; false when only changing the
     *        documents can settle it
     * @param bool $manualAllowed whether the lawyer may type a value instead of
     *        choosing an option; false when the options are the only answers
     */
    public function __construct(
        public ConflictScope $scope,
        public ConflictSeverity $severity,
        public string $messageKey,
        public ?string $field = null,
        public ?string $entityKey = null,
        public array $options = [],
        public ?int $suggestedIndex = null,
        public bool $acknowledgeable = true,
        public bool $manualAllowed = true,
    ) {}

    /**
     * Stable identity of the conflict, so a resolution picked in one request
     * still matches the same conflict on the next. Recomputed rather than
     * stored: the aggregation is deterministic, so the same documents produce
     * the same key.
     */
    public function key(): string
    {
        return implode(':', [
            $this->scope->value,
            $this->entityKey ?? '-',
            $this->field ?? '-',
            $this->messageKey,
        ]);
    }

    public function blocks(): bool
    {
        return $this->severity->blocks();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'scope' => $this->scope->value,
            'severity' => $this->severity->value,
            'messageKey' => $this->messageKey,
            'field' => $this->field,
            'entityKey' => $this->entityKey,
            'options' => array_map(static fn (ConflictOption $o): array => $o->toArray(), $this->options),
            'suggestedIndex' => $this->suggestedIndex,
            'acknowledgeable' => $this->acknowledgeable,
            'manualAllowed' => $this->manualAllowed,
        ];
    }
}
