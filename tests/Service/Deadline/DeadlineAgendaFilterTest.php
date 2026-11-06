<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Service\Deadline\DeadlineAgendaFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The selection the agenda page is showing. What matters here is that it is a pure round
 * trip: what a link puts in the URL is what the next request reads back, because that is
 * the whole reason the selection lives in the query string rather than in the browser.
 */
final class DeadlineAgendaFilterTest extends TestCase
{
    public function testTheDefaultIsTheFirstTabAndAddsNothingToTheUrl(): void
    {
        $filter = DeadlineAgendaFilter::none();

        self::assertTrue($filter->isTab(DeadlineAgendaFilter::TAB_TODO));
        self::assertFalse($filter->fatalOnly);
        self::assertSame(1, $filter->page);
        self::assertSame([], $filter->queryParameters());
        self::assertSame([], $filter->hiddenFields());
    }

    public function testAnUnknownTabFallsBackToTheFirstOne(): void
    {
        self::assertTrue(DeadlineAgendaFilter::of('drop-table')->isTab(DeadlineAgendaFilter::TAB_TODO));

        $read = DeadlineAgendaFilter::fromRequest(Request::create('/termene?tab=drop-table'));
        self::assertEquals(DeadlineAgendaFilter::none(), $read);
    }

    public function testIsTabOnlyAnswersForTheActiveTab(): void
    {
        $filter = DeadlineAgendaFilter::of(DeadlineAgendaFilter::TAB_BLOCKED);

        self::assertTrue($filter->isTab(DeadlineAgendaFilter::TAB_BLOCKED));
        self::assertFalse($filter->isTab(DeadlineAgendaFilter::TAB_TODO));
    }

    /** @return iterable<string, array{string}> */
    public static function tabsOtherThanTheFirstProvider(): iterable
    {
        yield 'blocked' => [DeadlineAgendaFilter::TAB_BLOCKED];
        yield 'prescriptions' => [DeadlineAgendaFilter::TAB_PRESCRIPTIONS];
        yield 'completed' => [DeadlineAgendaFilter::TAB_COMPLETED];
    }

    /** The fatal switch exists only on the first tab, so nothing else may carry it. */
    #[DataProvider('tabsOtherThanTheFirstProvider')]
    public function testTheFatalSelectionIsDroppedOutsideTheFirstTab(string $tab): void
    {
        $filter = DeadlineAgendaFilter::of($tab, true);

        self::assertFalse($filter->fatalOnly);
        self::assertSame(['tab' => $tab], $filter->queryParameters());
    }

    public function testThePageOnlyExistsOnTheClosedTerms(): void
    {
        self::assertSame(3, DeadlineAgendaFilter::of(DeadlineAgendaFilter::TAB_COMPLETED, page: 3)->page);
        self::assertSame(1, DeadlineAgendaFilter::of(DeadlineAgendaFilter::TAB_TODO, page: 3)->page);
        self::assertSame(1, DeadlineAgendaFilter::of(DeadlineAgendaFilter::TAB_BLOCKED, page: 3)->page);
        self::assertSame(1, DeadlineAgendaFilter::of(DeadlineAgendaFilter::TAB_PRESCRIPTIONS, page: 3)->page);
    }

    /** @return iterable<string, array{int, int}> */
    public static function pageClampProvider(): iterable
    {
        yield 'negative' => [-4, 1];
        yield 'zero' => [0, 1];
        yield 'first' => [1, 1];
        yield 'last allowed' => [20, 20];
        yield 'above the cap' => [21, 20];
        yield 'absurd' => [PHP_INT_MAX, 20];
    }

    #[DataProvider('pageClampProvider')]
    public function testThePageIsClampedBetweenOneAndTwenty(int $page, int $expected): void
    {
        self::assertSame($expected, DeadlineAgendaFilter::of(DeadlineAgendaFilter::TAB_COMPLETED, page: $page)->page);
    }

    public function testTheNextPageStopsAtTheCap(): void
    {
        $filter = DeadlineAgendaFilter::of(DeadlineAgendaFilter::TAB_COMPLETED, page: 19);

        self::assertSame(20, $filter->withNextPage()->page);
        self::assertSame(20, $filter->withNextPage()->withNextPage()->page);
        self::assertSame(19, $filter->page, 'The object is immutable: a rendered link stays what it was.');
    }

    public function testTheNextPageHasNoEffectOutsideTheClosedTerms(): void
    {
        self::assertEquals(DeadlineAgendaFilter::none(), DeadlineAgendaFilter::none()->withNextPage());
    }

    public function testPressingTheFatalSwitchTwiceComesBackToTheStartingPoint(): void
    {
        $filter = DeadlineAgendaFilter::none()->toggledFatal();

        self::assertTrue($filter->fatalOnly);
        self::assertSame(['fatal' => '1'], $filter->queryParameters());

        self::assertEquals(DeadlineAgendaFilter::none(), $filter->toggledFatal());
        self::assertFalse(DeadlineAgendaFilter::none()->fatalOnly, 'The object is immutable.');
    }

    public function testTheFatalSwitchDoesNothingOutsideTheFirstTab(): void
    {
        $filter = DeadlineAgendaFilter::of(DeadlineAgendaFilter::TAB_BLOCKED);

        self::assertEquals($filter, $filter->toggledFatal());
    }

    /**
     * Bookmarks of the former page. The former parameters are only ever read to work out
     * the tab; they are never written back.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function legacyUrlProvider(): iterable
    {
        $todo = DeadlineAgendaFilter::TAB_TODO;
        $blocked = DeadlineAgendaFilter::TAB_BLOCKED;
        $completed = DeadlineAgendaFilter::TAB_COMPLETED;
        $prescriptions = DeadlineAgendaFilter::TAB_PRESCRIPTIONS;

        yield 'overdue pill' => ['f=overdue', $todo, false];
        yield 'today pill' => ['f=today', $todo, false];
        yield 'fatal pill' => ['f=fatal30', $todo, true];
        yield 'blocked pill' => ['f=blocked', $blocked, false];
        yield 'padded blocked pill' => ['f=%20blocked%20', $blocked, false];
        yield 'closed terms' => ['completed=1', $completed, false];
        yield 'closed terms, true' => ['completed=true', $completed, false];
        yield 'closed terms switched off' => ['completed=0', $todo, false];
        yield 'closed terms, unreadable flag' => ['completed=banana', $todo, false];
        yield 'closed terms beat a pill' => ['f=overdue&completed=1', $completed, false];
        yield 'closed terms beat the blocked pill' => ['f=blocked&completed=1', $completed, false];
        yield 'closed terms beat the fatal pill' => ['f=fatal30&completed=1', $completed, false];
        yield 'blocked only when alone, with fatal' => ['f=blocked,fatal30', $todo, true];
        yield 'blocked only when alone, with overdue' => ['f=overdue,blocked', $todo, false];
        yield 'fatal among others' => ['f=overdue,fatal30,today', $todo, true];
        yield 'unknown pill' => ['f=nonsense', $todo, false];
        yield 'separators only' => ['f=,,,', $todo, false];
        yield 'uppercase pill is unknown' => ['f=FATAL30', $todo, false];
        yield 'explicit tab beats completed' => ['tab=de-completat&completed=1', $blocked, false];
        yield 'explicit tab beats the blocked pill' => ['tab=prescriptii&f=blocked', $prescriptions, false];
        yield 'explicit first tab beats the blocked pill' => ['tab=de-facut&f=blocked', $todo, false];
        yield 'explicit first tab ignores the fatal pill' => ['tab=de-facut&f=fatal30', $todo, false];
        yield 'an invalid tab leaves the former parameters in charge' => ['tab=nonsense&f=blocked', $blocked, false];
        yield 'fatal parameter survives on a legacy url' => ['f=overdue&fatal=1', $todo, true];
        yield 'fatal parameter falls off a legacy tab' => ['f=blocked&fatal=1', $blocked, false];
    }

    #[DataProvider('legacyUrlProvider')]
    public function testABookmarkOfTheFormerPageResolvesToATab(string $query, string $tab, bool $fatal): void
    {
        $filter = DeadlineAgendaFilter::fromRequest(Request::create('/termene?' . $query));

        self::assertSame($tab, $filter->tab);
        self::assertSame($fatal, $filter->fatalOnly);
    }

    public function testALegacyUrlRewritesToTheNewParameters(): void
    {
        $filter = DeadlineAgendaFilter::fromRequest(Request::create('/termene?f=fatal30'));

        self::assertSame(['fatal' => '1'], $filter->queryParameters());
    }

    public function testAFormPostedFromAPageLeftOpenOverTheChangeStillResolves(): void
    {
        $filter = DeadlineAgendaFilter::fromRequest(
            Request::create('/case/1/deadline/2/complete', 'POST', ['f' => 'fatal30']),
        );

        self::assertTrue($filter->isTab(DeadlineAgendaFilter::TAB_TODO));
        self::assertTrue($filter->fatalOnly);
    }

    public function testAGetRequestIsReadFromTheQueryStringAndAPostFromTheFormFields(): void
    {
        $get = DeadlineAgendaFilter::fromRequest(Request::create('/termene?tab=finalizate&page=2'));

        self::assertSame(DeadlineAgendaFilter::TAB_COMPLETED, $get->tab);
        self::assertSame(2, $get->page);

        $post = DeadlineAgendaFilter::fromRequest(
            Request::create('/case/1/deadline/2/complete?tab=finalizate', 'POST', ['tab' => 'de-completat']),
        );

        self::assertSame(DeadlineAgendaFilter::TAB_BLOCKED, $post->tab);
    }

    /** @return iterable<string, array{string}> */
    public static function unreadablePageProvider(): iterable
    {
        yield 'text' => ['abc'];
        yield 'empty' => [''];
        yield 'negative' => ['-3'];
    }

    #[DataProvider('unreadablePageProvider')]
    public function testAnUnreadablePageIsTheFirstPage(string $page): void
    {
        $filter = DeadlineAgendaFilter::fromRequest(Request::create('/termene?tab=finalizate&page=' . $page));

        self::assertSame(1, $filter->page);
    }

    /** @return iterable<string, array{DeadlineAgendaFilter}> */
    public static function selectionProvider(): iterable
    {
        yield 'default' => [DeadlineAgendaFilter::none()];
        yield 'fatal' => [DeadlineAgendaFilter::of(DeadlineAgendaFilter::TAB_TODO, true)];
        yield 'blocked' => [DeadlineAgendaFilter::of(DeadlineAgendaFilter::TAB_BLOCKED)];
        yield 'prescriptions' => [DeadlineAgendaFilter::of(DeadlineAgendaFilter::TAB_PRESCRIPTIONS)];
        yield 'completed' => [DeadlineAgendaFilter::of(DeadlineAgendaFilter::TAB_COMPLETED)];
        yield 'completed, third page' => [DeadlineAgendaFilter::of(DeadlineAgendaFilter::TAB_COMPLETED, page: 3)];
    }

    /**
     * The fields an acting form posts back have to rebuild the same selection, or an
     * action would silently drop the lawyer out of the tab that was on screen.
     */
    #[DataProvider('selectionProvider')]
    public function testTheHiddenFieldsOfAFormRebuildTheSameSelection(DeadlineAgendaFilter $filter): void
    {
        $posted = DeadlineAgendaFilter::fromRequest(
            Request::create('/case/1/deadline/2/complete', 'POST', $filter->hiddenFields()),
        );

        self::assertEquals($filter, $posted);
    }

    #[DataProvider('selectionProvider')]
    public function testTheQueryParametersRebuildTheSameSelection(DeadlineAgendaFilter $filter): void
    {
        $read = DeadlineAgendaFilter::fromRequest(Request::create('/termene', 'GET', $filter->queryParameters()));

        self::assertEquals($filter, $read);
    }
}
