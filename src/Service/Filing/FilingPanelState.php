<?php

declare(strict_types=1);

namespace App\Service\Filing;

/**
 * What the filing tab says about where the filing stands: one title, a short
 * explanation, one main action and at most two secondary ones, the four steps,
 * the dot on the tab and which legal notes apply. Translation keys and action
 * codes only; the templates render them.
 */
final readonly class FilingPanelState
{
    public const TONE_BLUE = 'blue';
    public const TONE_GREEN = 'green';
    public const TONE_AMBER = 'amber';
    public const TONE_RED = 'red';
    public const TONE_NEUTRAL = 'neutral';

    public const STEP_DONE = 'done';
    public const STEP_CURRENT = 'current';
    public const STEP_PROBLEM = 'problem';
    public const STEP_TODO = 'todo';

    /**
     * @param array<string, string|int>                                                              $titleParams
     * @param array<string, string|int>                                                              $explanationParams
     * @param list<string>                                                                          $secondaryActions
     * @param list<array{key: string, state: string, summaryKey: string, summaryParams: array<string, string|int>}> $steps
     * @param list<string>                                                                          $legalNoteKeys
     */
    public function __construct(
        public string $code,
        public string $tone,
        public string $titleKey,
        public array $titleParams,
        public string $explanationKey,
        public array $explanationParams,
        public ?string $primaryAction,
        public array $secondaryActions,
        public array $steps,
        public ?string $tabDot,
        public bool $legalNotesOpen,
        public array $legalNoteKeys,
        public ?string $criticalNoteKey,
    ) {}

    public function hasAction(string $action): bool
    {
        return $this->primaryAction === $action || in_array($action, $this->secondaryActions, true);
    }
}
