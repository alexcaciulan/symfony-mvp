<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use Symfony\Component\HttpFoundation\Request;

/**
 * What the agenda page is showing: one tab, and on the first tab optionally only the
 * terms whose miss cannot be undone.
 *
 * The state travels in the query string, so a selection survives a refresh and can be
 * pasted to a colleague, and it is applied in SQL, never in the browser: rows hidden on
 * the client would leave the numbers describing a set the lawyer cannot see.
 *
 * Bookmarks of the former page still resolve: `completed` leads to the closed terms,
 * `f=blocked` to the blocked cases, a selection with `fatal30` to the fatal terms and
 * any other `f` to the first tab. An explicit valid tab wins over all of them.
 */
final readonly class DeadlineAgendaFilter
{
    public const TAB_TODO = 'de-facut';
    public const TAB_BLOCKED = 'de-completat';
    public const TAB_PRESCRIPTIONS = 'prescriptii';
    public const TAB_COMPLETED = 'finalizate';

    public const QUERY_TAB = 'tab';
    public const QUERY_FATAL = 'fatal';
    public const QUERY_PAGE = 'page';

    private const LEGACY_PILLS = 'f';
    private const LEGACY_COMPLETED = 'completed';

    private const TABS = [self::TAB_TODO, self::TAB_BLOCKED, self::TAB_PRESCRIPTIONS, self::TAB_COMPLETED];

    /** Query values that turn a flag on; anything else leaves it off. */
    private const TRUE_VALUES = ['1', 'true', 'on', 'yes'];

    private const MAX_PAGE = 20;

    private function __construct(
        public string $tab,
        public bool $fatalOnly,
        public int $page,
    ) {}

    /** The default page: the first tab, every term of the window. */
    public static function none(): self
    {
        return new self(self::TAB_TODO, false, 1);
    }

    /**
     * Reads the selection off the request. A GET carries it in the query string; a POST
     * carries it in hidden fields of the acting form, so the answer to an action keeps
     * the page the lawyer was looking at.
     *
     * Every value is read as a string and matched against what the page itself emits, so
     * an unknown or truncated value falls back to the default page. A parameter sent as an
     * array is a malformed request and is answered as one.
     */
    public static function fromRequest(Request $request): self
    {
        $bag = $request->isMethod('POST') ? $request->getPayload() : $request->query;

        $tab = trim($bag->getString(self::QUERY_TAB));
        $fatal = self::isTrue($bag->getString(self::QUERY_FATAL));
        $page = (int) trim($bag->getString(self::QUERY_PAGE));

        $legacyCompleted = self::isTrue($bag->getString(self::LEGACY_COMPLETED));
        $legacyPills = array_values(array_filter(array_map(trim(...), explode(',', $bag->getString(self::LEGACY_PILLS)))));

        if (!\in_array($tab, self::TABS, true)) {
            $tab = match (true) {
                $legacyCompleted => self::TAB_COMPLETED,
                $legacyPills === ['blocked'] => self::TAB_BLOCKED,
                default => self::TAB_TODO,
            };
            $fatal = $fatal || ($tab === self::TAB_TODO && \in_array('fatal30', $legacyPills, true));
        }

        return self::of($tab, $fatal, $page);
    }

    public static function of(string $tab, bool $fatalOnly = false, int $page = 1): self
    {
        $tab = \in_array($tab, self::TABS, true) ? $tab : self::TAB_TODO;

        return new self(
            $tab,
            $fatalOnly && $tab === self::TAB_TODO,
            $tab === self::TAB_COMPLETED ? max(1, min($page, self::MAX_PAGE)) : 1,
        );
    }

    public function isTab(string $tab): bool
    {
        return $this->tab === $tab;
    }

    /** The state reached by pressing the fatal switch. */
    public function toggledFatal(): self
    {
        return self::of($this->tab, !$this->fatalOnly, $this->page);
    }

    /** Whether a further page exists at all: past the cap the link would lead back here. */
    public function canGrow(): bool
    {
        return $this->page < self::MAX_PAGE;
    }

    public function withNextPage(): self
    {
        return self::of($this->tab, $this->fatalOnly, $this->page + 1);
    }

    /**
     * The selection as route parameters, for a link back to the page. Default values are
     * dropped so the default page keeps a bare `/termene` URL.
     *
     * @return array<string, string>
     */
    public function queryParameters(): array
    {
        $parameters = [];
        if ($this->tab !== self::TAB_TODO) {
            $parameters[self::QUERY_TAB] = $this->tab;
        }
        if ($this->fatalOnly) {
            $parameters[self::QUERY_FATAL] = '1';
        }
        if ($this->page > 1) {
            $parameters[self::QUERY_PAGE] = (string) $this->page;
        }

        return $parameters;
    }

    /**
     * The same values as hidden inputs of an acting form, so an action answers with the
     * page it was pressed on.
     *
     * @return array<string, string>
     */
    public function hiddenFields(): array
    {
        return $this->queryParameters();
    }

    private static function isTrue(string $value): bool
    {
        return \in_array(strtolower(trim($value)), self::TRUE_VALUES, true);
    }
}
