<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use Symfony\Component\HttpFoundation\Request;

/**
 * What the agenda page is currently narrowed to. The four triage pills are the
 * filter: pressing one keeps only the rows it counts, pressing several keeps the
 * union of them, pressing it again releases it.
 *
 * The state travels in the query string, so a filtered agenda survives a refresh
 * and can be pasted to a colleague, and it is applied in SQL, never in the browser:
 * a filter that hides rows client side would still let the counters, the section
 * headers and the pagination describe a set the lawyer cannot see.
 *
 * The object is immutable; {@see self::toggled()} returns the next state, which is
 * exactly what a link needs to point at.
 */
final readonly class DeadlineAgendaFilter
{
    /** Past due, minus the terms of the debtor, which are not a delay of the lawyer. */
    public const PILL_OVERDUE = 'overdue';

    /** Falling exactly today, the term running until the end of the day. */
    public const PILL_TODAY = 'today';

    /** Irreversible types inside the agenda window. */
    public const PILL_FATAL30 = 'fatal30';

    /** Cases whose fatal term cannot be computed. Selects CASES, not deadlines. */
    public const PILL_BLOCKED = 'blocked';

    public const QUERY_PILLS = 'f';
    public const QUERY_COMPLETED = 'completed';

    /** Canonical order, so the same selection always produces the same URL. */
    private const PILLS = [
        self::PILL_OVERDUE,
        self::PILL_TODAY,
        self::PILL_FATAL30,
        self::PILL_BLOCKED,
    ];

    /**
     * The pills that select deadlines. The blockage pill is deliberately not one of
     * them: a blocked case has no deadline to match, so it is answered by showing or
     * hiding the blockage zone instead.
     */
    private const DEADLINE_PILLS = [
        self::PILL_OVERDUE,
        self::PILL_TODAY,
        self::PILL_FATAL30,
    ];

    /** @param list<string> $pills in canonical order, deduplicated, known values only */
    private function __construct(
        public array $pills,
        public bool $showCompleted,
    ) {}

    /** The default agenda: everything inside the window, closed terms left out. */
    public static function none(): self
    {
        return new self([], false);
    }

    /** Query values that turn the closed terms on; anything else leaves them out. */
    private const TRUE_VALUES = ['1', 'true', 'on', 'yes'];

    /**
     * Reads the filter off the request. A GET carries it in the query string; a POST
     * carries it in hidden fields of the acting form, so the answer to an action
     * keeps the agenda the lawyer was looking at.
     *
     * Every value is read as a string and matched against what the page itself emits.
     * A hand-edited or truncated URL therefore falls back to the default agenda,
     * instead of taking down the one screen the lawyer opens to see what is late.
     */
    public static function fromRequest(Request $request): self
    {
        $bag = $request->isMethod('POST') ? $request->getPayload() : $request->query;

        return self::fromValues(
            $bag->getString(self::QUERY_PILLS),
            \in_array(strtolower(trim($bag->getString(self::QUERY_COMPLETED))), self::TRUE_VALUES, true),
        );
    }

    public static function fromValues(string $pills, bool $showCompleted): self
    {
        $requested = array_map(trim(...), explode(',', $pills));

        return new self(
            array_values(array_filter(self::PILLS, static fn (string $pill): bool => \in_array($pill, $requested, true))),
            $showCompleted,
        );
    }

    public function has(string $pill): bool
    {
        return \in_array($pill, $this->pills, true);
    }

    /** Whether any pill narrows the rows. Showing closed terms widens, so it is not one. */
    public function hasPills(): bool
    {
        return $this->pills !== [];
    }

    /** Whether the page is on anything other than its default state. */
    public function isActive(): bool
    {
        return $this->hasPills() || $this->showCompleted;
    }

    /**
     * The selected pills that translate into a condition on a deadline.
     *
     * @return list<string>
     */
    public function deadlinePills(): array
    {
        return array_values(array_filter(
            $this->pills,
            static fn (string $pill): bool => \in_array($pill, self::DEADLINE_PILLS, true),
        ));
    }

    /** Blockages show when nothing is filtered, or when their own pill is pressed. */
    public function includesBlockages(): bool
    {
        return !$this->hasPills() || $this->has(self::PILL_BLOCKED);
    }

    /** The state reached by pressing $pill: selected pills release, released ones select. */
    public function toggled(string $pill): self
    {
        if (!\in_array($pill, self::PILLS, true)) {
            return $this;
        }

        $pills = $this->has($pill)
            ? array_values(array_filter($this->pills, static fn (string $current): bool => $current !== $pill))
            : array_values(array_filter(self::PILLS, fn (string $current): bool => $current === $pill || $this->has($current)));

        return new self($pills, $this->showCompleted);
    }

    public function toggledCompleted(): self
    {
        return new self($this->pills, !$this->showCompleted);
    }

    /**
     * The filter as route parameters, for a link back to the page. Empty values are
     * dropped so the default agenda keeps a bare `/termene` URL.
     *
     * @return array<string, string>
     */
    public function queryParameters(): array
    {
        $parameters = [];
        if ($this->hasPills()) {
            $parameters[self::QUERY_PILLS] = implode(',', $this->pills);
        }
        if ($this->showCompleted) {
            $parameters[self::QUERY_COMPLETED] = '1';
        }

        return $parameters;
    }

    /**
     * The same values as hidden inputs of an acting form, so an action posted from a
     * filtered agenda answers with that same filtered agenda.
     *
     * @return array<string, string>
     */
    public function hiddenFields(): array
    {
        return $this->queryParameters();
    }
}
