<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Service\Deadline\DeadlineAgendaFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The selection the agenda page is narrowed to. What matters here is that it is a
 * pure round trip: what a link puts in the URL is what the next request reads back,
 * because that is the whole reason the filter lives in the query string rather than
 * in the browser.
 */
final class DeadlineAgendaFilterTest extends TestCase
{
    public function testTheDefaultSelectsNothingAndAddsNothingToTheUrl(): void
    {
        $filter = DeadlineAgendaFilter::none();

        self::assertFalse($filter->hasPills());
        self::assertFalse($filter->isActive());
        self::assertFalse($filter->showCompleted);
        self::assertSame([], $filter->queryParameters());
    }

    public function testPillsAreCumulativeAndPressingOneAgainReleasesIt(): void
    {
        $filter = DeadlineAgendaFilter::none()
            ->toggled(DeadlineAgendaFilter::PILL_TODAY)
            ->toggled(DeadlineAgendaFilter::PILL_OVERDUE);

        self::assertTrue($filter->has(DeadlineAgendaFilter::PILL_TODAY));
        self::assertTrue($filter->has(DeadlineAgendaFilter::PILL_OVERDUE));

        $released = $filter->toggled(DeadlineAgendaFilter::PILL_TODAY);

        self::assertFalse($released->has(DeadlineAgendaFilter::PILL_TODAY));
        self::assertTrue($released->has(DeadlineAgendaFilter::PILL_OVERDUE));
        // The object is immutable, so the link the page rendered still points where
        // it did when it was rendered.
        self::assertTrue($filter->has(DeadlineAgendaFilter::PILL_TODAY));
    }

    /**
     * The same selection always produces the same URL, whichever order the pills were
     * pressed in. Otherwise two lawyers looking at the same agenda would hold two
     * different links to it, and the browser history would fill with duplicates.
     */
    public function testTheUrlOfASelectionDoesNotDependOnTheOrderItWasBuiltIn(): void
    {
        $one = DeadlineAgendaFilter::none()
            ->toggled(DeadlineAgendaFilter::PILL_FATAL30)
            ->toggled(DeadlineAgendaFilter::PILL_OVERDUE);
        $other = DeadlineAgendaFilter::none()
            ->toggled(DeadlineAgendaFilter::PILL_OVERDUE)
            ->toggled(DeadlineAgendaFilter::PILL_FATAL30);

        self::assertSame(['f' => 'overdue,fatal30'], $one->queryParameters());
        self::assertSame($one->queryParameters(), $other->queryParameters());
    }

    public function testUnknownPillsInTheUrlAreDroppedInsteadOfBreakingThePage(): void
    {
        $filter = DeadlineAgendaFilter::fromValues('overdue,drop-table,today', false);

        self::assertSame(['overdue', 'today'], $filter->pills);
    }

    public function testAGetRequestIsReadFromTheQueryStringAndAPostFromTheFormFields(): void
    {
        $get = DeadlineAgendaFilter::fromRequest(Request::create('/termene?f=overdue&completed=1'));

        self::assertSame(['overdue'], $get->pills);
        self::assertTrue($get->showCompleted);

        $post = DeadlineAgendaFilter::fromRequest(
            Request::create('/case/1/deadline/2/complete', 'POST', ['f' => 'today']),
        );

        self::assertSame(['today'], $post->pills);
        self::assertFalse($post->showCompleted);
    }

    /**
     * The filter an acting form posts back has to rebuild the same selection, or an
     * action would silently drop the lawyer out of the agenda that was on screen.
     */
    public function testTheHiddenFieldsOfAFormRebuildTheSameSelection(): void
    {
        $filter = DeadlineAgendaFilter::none()
            ->toggled(DeadlineAgendaFilter::PILL_BLOCKED)
            ->toggledCompleted();

        $posted = DeadlineAgendaFilter::fromRequest(
            Request::create('/case/1/deadline/2/complete', 'POST', $filter->hiddenFields()),
        );

        self::assertEquals($filter, $posted);
    }

    /**
     * The blockage pill counts cases, not deadlines, so it never becomes a condition
     * on a row: it decides whether the blockage zone is on screen.
     */
    public function testTheBlockagePillIsNotACondition(): void
    {
        $blocked = DeadlineAgendaFilter::none()->toggled(DeadlineAgendaFilter::PILL_BLOCKED);

        self::assertSame([], $blocked->deadlinePills());
        self::assertTrue($blocked->includesBlockages());

        $overdue = DeadlineAgendaFilter::none()->toggled(DeadlineAgendaFilter::PILL_OVERDUE);

        self::assertSame(['overdue'], $overdue->deadlinePills());
        self::assertFalse($overdue->includesBlockages(), 'A row selection hides the blockage zone.');
        self::assertTrue(DeadlineAgendaFilter::none()->includesBlockages(), 'Unfiltered, everything shows.');
    }

    /**
     * Showing closed terms widens the agenda instead of narrowing it, so it must not
     * count as a row selection: the empty states read the two apart.
     */
    public function testShowingCompletedTermsIsNotARowSelection(): void
    {
        $filter = DeadlineAgendaFilter::none()->toggledCompleted();

        self::assertTrue($filter->showCompleted);
        self::assertFalse($filter->hasPills());
        self::assertTrue($filter->isActive());
        self::assertSame(['completed' => '1'], $filter->queryParameters());
        self::assertTrue($filter->includesBlockages());
    }
}
