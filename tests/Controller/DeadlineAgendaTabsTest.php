<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlineBlockageReason;
use App\Enum\DeadlineType;
use App\Tests\Support\AgendaPageFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The four tabs. What the page rests on is one invariant: the number in a box is the
 * number of rows behind that box. It is what a lawyer checks with his eyes, and what the
 * merged limitation rows would break first, so it is asserted against the rendered rows.
 */
final class DeadlineAgendaTabsTest extends WebTestCase
{
    use AgendaPageFixtureTrait;

    /** tab => counter and the selector of one row on it */
    private const TABS = [
        'de-facut' => ['todo', 'article[id^="deadline-row-"]'],
        'de-completat' => ['blocked', '[data-testid="blockage-row"]'],
        'prescriptii' => ['prescriptions', 'article[id^="deadline-row-"]'],
        'finalizate' => ['completed', 'article[id^="deadline-row-"]'],
    ];

    /** What the page, layout included, costs today, plus room for a small addition. */
    private const QUERY_BUDGET = 30;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->prefix = 'agenda-tabs-' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeAgendaFixtures();

        parent::tearDown();
    }

    // ===== the boxes =======================================================

    public function testTheBoxEqualsTheRowsOnEveryTab(): void
    {
        $user = $this->seedEveryTab();
        $this->client->loginUser($user);

        foreach (self::TABS as $tab => [$counter, $rowSelector]) {
            $crawler = $this->tabPage($tab);

            self::assertCount(
                (int) $this->counterIn($crawler, $counter),
                $crawler->filter('[data-tab-panel="' . $tab . '"] ' . $rowSelector),
                sprintf('The %s box states a number its rows must match.', $tab),
            );
            self::assertGreaterThan(0, (int) $this->counterIn($crawler, $counter), 'The fixture fills every tab.');
        }
    }

    public function testTheCountersAreTheSameOnEveryTabAndUnderTheFatalSwitch(): void
    {
        $user = $this->seedEveryTab();
        $this->client->loginUser($user);

        $reference = $this->countersIn($this->client->request('GET', '/termene'));

        self::assertSame(['todo', 'blocked', 'prescriptions', 'completed'], array_keys($reference));

        foreach (['/termene?fatal=1', '/termene?tab=de-completat', '/termene?tab=prescriptii', '/termene?tab=finalizate', '/termene?tab=finalizate&page=2'] as $url) {
            self::assertSame($reference, $this->countersIn($this->client->request('GET', $url)), $url);
        }
    }

    /** The first box counts the rows of every group, whatever the switch leaves on screen. */
    public function testTheFatalSwitchNarrowsTheRowsAndNotTheBox(): void
    {
        $user = $this->seedEveryTab();
        $this->client->loginUser($user);

        $all = $this->client->request('GET', '/termene');
        $fatal = $this->client->request('GET', '/termene?fatal=1');

        self::assertLessThan(\count($this->rowIdsIn($all)), \count($this->rowIdsIn($fatal)));
        self::assertSame($this->counterIn($all, 'todo'), $this->counterIn($fatal, 'todo'));
    }

    public function testOnlyTheActiveBoxIsMarkedAsCurrent(): void
    {
        $user = $this->seedEveryTab();
        $this->client->loginUser($user);

        foreach (array_keys(self::TABS) as $tab) {
            $crawler = $this->tabPage($tab);

            $current = $crawler->filter('[data-tab][aria-current="page"]');
            self::assertCount(1, $current);
            self::assertSame($tab, $current->attr('data-tab'));
        }
    }

    public function testTheBoxesAreLinksThatWorkWithoutScript(): void
    {
        $user = $this->seedEveryTab();
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertSame('/termene', $crawler->filter('a[data-tab="de-facut"]')->attr('href'));
        self::assertSame('/termene?tab=de-completat', $crawler->filter('a[data-tab="de-completat"]')->attr('href'));
        self::assertSame('/termene?tab=prescriptii', $crawler->filter('a[data-tab="prescriptii"]')->attr('href'));
        self::assertSame('/termene?tab=finalizate', $crawler->filter('a[data-tab="finalizate"]')->attr('href'));
    }

    /**
     * A selection loads inside the page frame and lands in the address bar. Anything
     * else in the frame leaves for the top, so a link into a case stays a navigation.
     */
    public function testTheSelectionLinksLoadInsideThePageFrame(): void
    {
        $user = $this->seedEveryTab();
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        $frame = $crawler->filter('turbo-frame#deadline-page');
        self::assertCount(1, $frame);
        self::assertSame('_top', $frame->attr('target'));
        self::assertSame('advance', $frame->attr('data-turbo-action'));
        self::assertCount(1, $frame->filter('[data-testid="agenda-heading"]'));
        self::assertCount(4, $frame->filter('a[data-tab][data-turbo-frame="deadline-page"]'));
        self::assertCount(1, $frame->filter('[data-testid="agenda-fatal-toggle"][data-turbo-frame="deadline-page"]'));
        self::assertCount(0, $crawler->filter('#deadline-agenda a[data-turbo="false"], #deadline-riskbar a[data-turbo="false"]'));
    }

    /**
     * Only the active tab is built. Rows of another tab are absent from the HTML, not
     * hidden: anything reading the page, a screen reader included, would otherwise find a
     * list the boxes do not describe.
     */
    public function testRowsOfAnotherTabAreAbsentFromTheMarkup(): void
    {
        $user = $this->seedEveryTab();
        $this->client->loginUser($user);

        $seen = [];
        foreach (array_keys(self::TABS) as $tab) {
            $crawler = $this->tabPage($tab);
            self::assertCount(1, $crawler->filter('[data-tab-panel]'));
            $seen[$tab] = $tab === 'de-completat'
                ? $crawler->filter('[data-testid="blockage-row"]')->each(static fn (Crawler $row): string => (string) $row->attr('data-blockage-reason'))
                : $this->rowIdsIn($crawler);

            foreach (array_diff(array_keys(self::TABS), [$tab]) as $other) {
                self::assertCount(0, $crawler->filter('[data-tab-panel="' . $other . '"]'));
            }
        }

        self::assertSame([], array_intersect($seen['de-facut'], $seen['prescriptii']));
        self::assertSame([], array_intersect($seen['de-facut'], $seen['finalizate']));
        self::assertSame([], array_intersect($seen['prescriptii'], $seen['finalizate']));
    }

    /**
     * An account holding only closed terms still has a page: "nothing tracked" reads the
     * numbers of the whole account, not the rows of the tab that happens to be built.
     */
    public function testAnAccountWithOnlyClosedTermsKeepsItsBoxes(): void
    {
        $user = $this->makeUser();
        $this->closedDeadline($this->makeCase($user), $user);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('[data-tab]'));
        self::assertCount(0, $crawler->filter('[data-state="no-deadlines"]'));
        self::assertSame('1', $this->counterIn($crawler, 'completed'));
        self::assertCount(1, $this->rowIdsIn($this->client->request('GET', '/termene?tab=finalizate')));
    }

    /** @return iterable<string, array{string, string}> */
    public static function emptyTabProvider(): iterable
    {
        yield 'blocked' => ['de-completat', 'blocked'];
        yield 'limitation' => ['prescriptii', 'prescriptions'];
        yield 'closed' => ['finalizate', 'completed'];
    }

    /** An empty tab names what it would hold, so it cannot read as a failed page. */
    #[DataProvider('emptyTabProvider')]
    public function testAnEmptyTabStatesWhatItWouldHold(string $tab, string $key): void
    {
        $user = $this->makeUser();
        $this->makeDeadline($this->makeCase($user), '+3 days', DeadlineType::JUDECATA);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->tabPage($tab);

        self::assertResponseIsSuccessful();
        self::assertSame(
            static::getContainer()->get('translator')->trans('deadlines.empty_tab.' . $key . '.heading'),
            trim($crawler->filter('[data-state="tab-empty"] h2')->text()),
        );
    }

    // ===== the merged limitation rows ======================================

    /**
     * Three due dates, two of them already past: one row, so one arrear. The list, the
     * box, the sentence, the navigation badge and the dashboard card all read the same
     * query, and this is the case that would pull them apart.
     */
    public function testAMergedRowIsOneInTheBadgeTheBoxTheListAndTheDashboard(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user);
        $row = $this->makeDeadline($case, '-20 days', DeadlineType::PRESCRIPTIE);
        $this->makeDeadline($case, '-10 days', DeadlineType::PRESCRIPTIE);
        $running = $this->makeDeadline($case, '+300 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertSame([$row->getId()], $this->rowIdsIn($crawler));
        self::assertSame('1', $this->counterIn($crawler, 'todo'));
        self::assertSame('1', trim($crawler->filter('.deadline-nav-badge span')->first()->text()));
        self::assertCount(1, $crawler->filter('[data-group="overdue"] article'));
        self::assertStringContainsString(
            static::getContainer()->get('translator')->trans('deadlines.row.covered_terms', ['%count%' => 2]),
            $crawler->filter('#deadline-row-' . $row->getId())->text(),
        );

        // The term still running is not swallowed by the row of those that ran out.
        self::assertSame('1', $this->counterIn($crawler, 'prescriptions'));
        self::assertSame([$running->getId()], $this->rowIdsIn($this->client->request('GET', '/termene?tab=prescriptii')));

        $dashboard = $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        self::assertSame('1', trim($dashboard->filter('.deadline-nav-badge span')->first()->text()));
    }

    /** A limitation tab row states how many due dates it covers, and the box counts it once. */
    public function testAMergedRowOnTheLimitationTabCountsOnce(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user);
        $row = $this->makeDeadline($case, '+100 days', DeadlineType::PRESCRIPTIE);
        $this->makeDeadline($case, '+200 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene?tab=prescriptii');

        self::assertSame([$row->getId()], $this->rowIdsIn($crawler));
        self::assertSame('1', $this->counterIn($crawler, 'prescriptions'));
    }

    /** The terms of a case split by what is left of them, each side with its own row. */
    public function testALimitationTermStillRunningKeepsItsOwnRowBesideOneThatRanOut(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user);
        $passed = $this->makeDeadline($case, '-5 days', DeadlineType::PRESCRIPTIE);
        $running = $this->makeDeadline($case, 'today', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertSame('1', $crawler->filter('#deadline-row-' . $passed->getId())->attr('data-consumed'));
        self::assertSame('0', $crawler->filter('#deadline-row-' . $running->getId())->attr('data-consumed'));
        self::assertCount(1, $crawler->filter('[data-group="today"] #deadline-row-' . $running->getId()));
    }

    // ===== legacy URLs =====================================================

    /** @return iterable<string, array{string, string, bool}> */
    public static function legacyUrlProvider(): iterable
    {
        yield 'overdue pill' => ['/termene?f=overdue', 'de-facut', false];
        yield 'today pill' => ['/termene?f=today', 'de-facut', false];
        yield 'fatal pill' => ['/termene?f=fatal30', 'de-facut', true];
        yield 'blocked pill' => ['/termene?f=blocked', 'de-completat', false];
        yield 'closed terms' => ['/termene?completed=1', 'finalizate', false];
        yield 'closed terms beat a pill' => ['/termene?f=overdue&completed=1', 'finalizate', false];
        yield 'pair with blocked' => ['/termene?f=overdue,blocked', 'de-facut', false];
        yield 'pair with fatal' => ['/termene?f=blocked,fatal30', 'de-facut', true];
        yield 'explicit tab wins' => ['/termene?tab=prescriptii&f=blocked&completed=1', 'prescriptii', false];
        yield 'fatal dropped outside the first tab' => ['/termene?tab=de-completat&fatal=1', 'de-completat', false];
    }

    /** A bookmark renders in place: a redirect would lose nothing but cost a round trip. */
    #[DataProvider('legacyUrlProvider')]
    public function testABookmarkOfTheFormerPageRendersTheTabItMaps(string $url, string $tab, bool $fatal): void
    {
        $user = $this->seedEveryTab();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', $url);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'No redirect.');
        self::assertCount(1, $crawler->filter('[data-tab-panel="' . $tab . '"]'));
        self::assertSame('page', $crawler->filter('[data-tab="' . $tab . '"]')->attr('aria-current'));

        if ($tab === 'de-facut') {
            self::assertSame($fatal ? 'true' : 'false', $crawler->filter('[data-testid="agenda-fatal-toggle"]')->attr('aria-pressed'));
        } else {
            self::assertCount(0, $crawler->filter('[data-testid="agenda-fatal-toggle"]'));
        }
    }

    public function testTheFatalBookmarkListsWhatTheNewParameterLists(): void
    {
        $user = $this->seedEveryTab();
        $this->client->loginUser($user);

        self::assertSame(
            $this->rowIdsIn($this->client->request('GET', '/termene?fatal=1')),
            $this->rowIdsIn($this->client->request('GET', '/termene?f=fatal30')),
        );
    }

    /**
     * A parameter sent with the wrong SHAPE is not a selection the page can degrade to: it
     * is a malformed request, and the request layer refuses it before the page is built.
     * What matters is that this stays a 4xx the client can read as its own mistake, never
     * a 5xx that reads as a failure of the agenda.
     *
     * @return iterable<string, array{string}>
     */
    public static function wrongShapeProvider(): iterable
    {
        yield 'tab' => ['/termene?tab[]=de-facut'];
        yield 'fatal' => ['/termene?fatal[]=1'];
        yield 'page' => ['/termene?page[]=2'];
        yield 'former pills' => ['/termene?f[]=overdue'];
        yield 'former closed flag' => ['/termene?completed[]=1'];
        yield 'nested' => ['/termene?tab[a][b]=c'];
    }

    #[DataProvider('wrongShapeProvider')]
    public function testParametersOfTheWrongShapeAreRefusedAsClientErrors(string $url): void
    {
        $user = $this->makeUser();
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', $url);

        self::assertTrue(
            $this->client->getResponse()->isClientError(),
            sprintf('%s must answer 4xx, got %d.', $url, $this->client->getResponse()->getStatusCode()),
        );
    }

    // ===== the closed tab ==================================================

    public function testShowMoreAppearsOnlyWhenMoreExist(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user);
        for ($i = 0; $i < 50; ++$i) {
            $this->closedDeadline($case, $user);
        }
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene?tab=finalizate');

        self::assertCount(50, $this->rowIdsIn($crawler));
        self::assertCount(0, $crawler->filter('[data-testid="show-more-completed"]'), 'Fifty of fifty: nothing is left to show.');

        $this->closedDeadline($this->em->getRepository(LegalCase::class)->find($case->getId()), $this->em->getRepository(User::class)->find($user->getId()));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/termene?tab=finalizate');
        $rows = $this->rowIdsIn($crawler);
        $more = $crawler->filter('[data-testid="show-more-completed"]');

        self::assertCount(50, $rows);
        self::assertCount(1, $more);
        self::assertSame('/termene?tab=finalizate&page=2', $more->attr('href'));

        $second = $this->client->request('GET', '/termene?tab=finalizate&page=2');

        self::assertCount(51, $this->rowIdsIn($second), 'A page extends the list, it does not replace it.');
        self::assertCount(0, $second->filter('[data-testid="show-more-completed"]'));
        self::assertSame('51', $this->counterIn($second, 'completed'));
    }

    public function testClosedRowsCarryNoFormAndNoFatalMark(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user);
        $this->makeDeadline($case, '+3 days', DeadlineType::TIMBRARE)->markCompleted($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene?tab=finalizate');

        self::assertCount(1, $this->rowIdsIn($crawler));
        self::assertCount(0, $crawler->filter('[data-tab-panel="finalizate"] form'));
        self::assertCount(0, $crawler->filter('[data-tab-panel="finalizate"] [data-testid="row-fatal"]'));
        self::assertCount(0, $crawler->filter('[data-tab-panel="finalizate"] [data-testid="row-next-step"]'));
    }

    /** A closed row states when it was closed, not when it was due. */
    public function testAClosedRowShowsTheDayItWasClosed(): void
    {
        $user = $this->makeUser();
        $deadline = $this->closedDeadline($this->makeCase($user), $user, '2026-02-03');
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            'UPDATE legal_deadline SET completed_at = ? WHERE id = ?',
            ['2026-07-15 09:00:00', $deadline->getId()],
        );
        $this->em->clear();

        $this->client->loginUser($user);
        $row = $this->client->request('GET', '/termene?tab=finalizate')->filter('#deadline-row-' . $deadline->getId());

        self::assertStringContainsString('15 ' . $this->trans('deadlines.month_short.7'), $row->text());
        self::assertStringNotContainsString('3 ' . $this->trans('deadlines.month_short.2'), $row->text());
    }

    // ===== the blocked tab =================================================

    /** @return iterable<string, array{DeadlineBlockageReason}> */
    public static function reasonProvider(): iterable
    {
        foreach (DeadlineBlockageReason::cases() as $reason) {
            yield $reason->value => [$reason];
        }
    }

    #[DataProvider('reasonProvider')]
    public function testEveryReasonListsItsRowWithItsButton(DeadlineBlockageReason $reason): void
    {
        $user = $this->makeUser();
        $this->attachDebtor($case = $this->blockedCase($user, $reason), 'Debitor Blocat SRL');
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene?tab=de-completat');

        self::assertResponseIsSuccessful();

        $row = $crawler->filter('[data-blockage-reason="' . $reason->value . '"]');
        self::assertCount(1, $row);
        self::assertSame('1', $this->counterIn($crawler, 'blocked'));
        self::assertStringContainsString('Debitor Blocat SRL', $row->text());
        self::assertStringContainsString($this->trans($reason->stateLabel()), $row->text());
        self::assertStringContainsString($this->trans('deadlines.blockage_kind.' . $reason->kind() . '.action'), $row->text());
        self::assertCount(0, $row->filter('form'), 'Every act here needs data the row does not collect.');

        $button = $row->filter('a[data-row-slot="case"]');
        self::assertSame($this->trans($reason->actionLabel()), $button->attr('aria-label'));
        self::assertSame(
            '/case/' . $case->getId() . ($reason === DeadlineBlockageReason::ENFORCEMENT_REGISTRATION_NUMBER_MISSING ? '?tab=termene' : ''),
            $button->attr('href'),
        );
        self::assertCount(
            $reason->concernsFatalTerm() ? 1 : 0,
            $row->filter('[data-testid="row-fatal"]'),
        );
    }

    /** Only the communication date has a dialog on this page; the others stay links. */
    public function testOnlyTheCommunicationDateOpensADialogFromTheRow(): void
    {
        $user = $this->makeUser();
        foreach (DeadlineBlockageReason::cases() as $reason) {
            $this->blockedCase($user, $reason);
        }
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene?tab=de-completat');

        self::assertCount(\count(DeadlineBlockageReason::cases()), $crawler->filter('[data-testid="blockage-row"]'));

        $crawler->filter('[data-testid="blockage-row"]')->each(function (Crawler $row): void {
            $opensDialog = $row->filter('a[data-row-slot="case"][data-action="agenda-dialog#openDialog"]')->count() === 1;

            self::assertSame(
                $row->attr('data-blockage-reason') === DeadlineBlockageReason::SUMMONS_COMMUNICATION_MISSING->value,
                $opensDialog,
                (string) $row->attr('data-blockage-reason'),
            );
        });
    }

    public function testAForeignBlockedCaseIsNotListed(): void
    {
        $user = $this->makeUser();
        $this->makeCase($user);
        $foreign = $this->blockedCase($this->makeUser('-other'), DeadlineBlockageReason::RULING_COMMUNICATION_MISSING);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene?tab=de-completat');

        self::assertCount(0, $crawler->filter('[data-testid="blockage-row"]'));
        self::assertCount(0, $crawler->filter('a[href="/case/' . $foreign->getId() . '"]'));
    }

    /** The first tab states the blocked cases in one line, with the way to them. */
    public function testTheFirstTabPointsToTheBlockedCases(): void
    {
        $user = $this->makeUser();
        $this->blockedCase($user, DeadlineBlockageReason::RULING_COMMUNICATION_MISSING);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertCount(1, $crawler->filter('[data-tab-panel="de-facut"] a[href="/termene?tab=de-completat"]'));
    }

    // ===== nothing generates ===============================================

    /** Whatever the row suggests, it is text, and the page reaches no generating route. */
    public function testNoRowOfAnyTabPostsToAGeneratingRoute(): void
    {
        $user = $this->makeUser();
        $amicable = $this->makeCase($user);
        $this->makeDeadline($amicable, '+5 days', DeadlineType::PRESCRIPTIE);

        $summoned = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $summoned->setPaymentNoticeDate(new \DateTime('-40 days'));
        $summoned->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-30 days'));
        $this->attachProof($summoned);
        $this->makeDeadline($summoned, '-14 days', DeadlineType::RASPUNS_SOMATIE);

        $generated = $this->makeCase($user, CaseStatus::CERERE_GENERATA);
        $this->makeDeadline($generated, '+5 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $this->client->loginUser($user);

        foreach (['/termene', '/termene?tab=prescriptii', '/termene?tab=de-completat', '/termene?tab=finalizate'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();

            $crawler->filter('form[action]')->each(static function (Crawler $form): void {
                $action = (string) $form->attr('action');
                self::assertStringNotContainsString('/summons/generate', $action);
                self::assertStringNotContainsString('/payment-order/generate', $action);
                self::assertStringNotContainsString('/transition/', $action);
            });
        }

        $crawler = $this->client->request('GET', '/termene');
        self::assertSame(
            ['payment_order', 'summons', 'filing'],
            $this->suggestionsIn($crawler, [$summoned->getId(), $amicable->getId(), $generated->getId()]),
        );
    }

    public function testASilentRowPrintsNoSuggestion(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeDate(new \DateTime('-6 days'));
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-3 days'));
        $this->attachProof($case);
        $this->makeDeadline($case, '+13 days', DeadlineType::RASPUNS_SOMATIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertCount(1, $this->rowIdsIn($crawler));
        self::assertCount(0, $crawler->filter('[data-testid="row-next-step"]'));
    }

    // ===== cost ============================================================

    /**
     * The rows are built once with everything they need, so the page does not grow by a
     * query per row. Three rows are the smallest set that reaches every status a row
     * suggests something on, so the bulk queries are already in the first measure.
     */
    public function testThePageDoesNotQueryPerRow(): void
    {
        $user = $this->seedRows(3);
        $this->client->loginUser($user);
        $few = $this->queriesOn('/termene');

        $this->seedRows(12, $user->getId());
        $many = $this->queriesOn('/termene');

        self::assertSame($few, $many, 'Twelve more rows must not cost a single query more.');
    }

    public function testTheLimitationTabDoesNotQueryPerRow(): void
    {
        $user = $this->seedLimitationRows(2);
        $this->client->loginUser($user);
        $few = $this->queriesOn('/termene?tab=prescriptii');

        $this->seedLimitationRows(10, $user->getId());
        $many = $this->queriesOn('/termene?tab=prescriptii');

        self::assertSame($few, $many);
    }

    public function testTheClosedTabDoesNotQueryPerRow(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user);
        $this->closedDeadline($case, $user);
        $this->em->flush();
        $this->client->loginUser($user);
        $few = $this->queriesOn('/termene?tab=finalizate');

        $user = $this->em->getRepository(User::class)->find($user->getId());
        for ($i = 0; $i < 8; ++$i) {
            $this->closedDeadline($this->makeCase($user), $user);
        }
        $this->em->flush();
        $many = $this->queriesOn('/termene?tab=finalizate');

        self::assertSame($few, $many);
    }

    public function testThePageStaysUnderItsQueryBudget(): void
    {
        $user = $this->seedRows(15);
        $this->client->loginUser($user);

        self::assertLessThanOrEqual(self::QUERY_BUDGET, $this->queriesOn('/termene'));
    }

    // ===== helpers =========================================================

    /** An account with something on every tab, merged limitation rows included. */
    private function seedEveryTab(): User
    {
        $user = $this->makeUser();

        $filed = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $filed->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-30 days'));
        $this->makeDeadline($filed, '-4 days', DeadlineType::OTHER);
        $this->makeDeadline($filed, 'today', DeadlineType::JUDECATA);
        $this->makeDeadline($filed, '+3 days', DeadlineType::TIMBRARE);
        $this->makeDeadline($filed, '+20 days', DeadlineType::OTHER);
        $this->closedDeadline($filed, $user);
        $this->closedDeadline($filed, $user, '-6 days');

        // One row on the first tab: the nearest due date stands for the other two.
        $merged = $this->makeCase($user);
        $this->makeDeadline($merged, '+5 days', DeadlineType::PRESCRIPTIE);
        $this->makeDeadline($merged, '+12 days', DeadlineType::PRESCRIPTIE);
        $this->makeDeadline($merged, '+300 days', DeadlineType::PRESCRIPTIE);

        // One row on the limitation tab.
        $longHorizon = $this->makeCase($user);
        $this->makeDeadline($longHorizon, '+100 days', DeadlineType::PRESCRIPTIE);
        $this->makeDeadline($longHorizon, '+200 days', DeadlineType::PRESCRIPTIE);

        $enforcement = $this->makeCase($user, CaseStatus::DEFINITIVA);
        $enforcement->setRulingCommunicationDate(new \DateTimeImmutable('-30 days'));
        $this->makeDeadline($enforcement, '+500 days', DeadlineType::PRESCRIPTIE_EXECUTARE);

        $this->blockedCase($user, DeadlineBlockageReason::RULING_COMMUNICATION_MISSING);
        $this->blockedCase($user, DeadlineBlockageReason::SUMMONS_COMMUNICATION_MISSING);

        $this->em->flush();

        return $user;
    }

    /** Rows on separate cases, each with a debtor, so a lazy load would show per row. */
    private function seedRows(int $count, ?int $userId = null): User
    {
        $user = $userId !== null ? $this->em->getRepository(User::class)->find($userId) : $this->makeUser();

        for ($i = 0; $i < $count; ++$i) {
            $case = $this->makeCase($user, [CaseStatus::AMIABIL, CaseStatus::SOMATIE_TRIMISA, CaseStatus::CERERE_GENERATA][$i % 3]);
            $this->attachDebtor($case, 'Debitor ' . $i . ' SRL');
            $this->makeDeadline($case, '+' . (1 + $i) . ' days', DeadlineType::PRESCRIPTIE);
        }
        $this->em->flush();

        return $user;
    }

    private function seedLimitationRows(int $count, ?int $userId = null): User
    {
        $user = $userId !== null ? $this->em->getRepository(User::class)->find($userId) : $this->makeUser();

        for ($i = 0; $i < $count; ++$i) {
            $case = $this->makeCase($user);
            $this->attachDebtor($case, 'Debitor ' . $i . ' SRL');
            $this->makeDeadline($case, '+' . (100 + $i) . ' days', DeadlineType::PRESCRIPTIE);
        }
        $this->em->flush();

        return $user;
    }

    /**
     * The first request of a client runs on the kernel that wrote the fixtures, with their
     * entities still managed, so it is warmed first: only a fresh manager counts what a
     * lawyer's request really costs.
     */
    private function queriesOn(string $url): int
    {
        $this->client->request('GET', $url);
        $this->client->enableProfiler();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);

        /** @var \Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector $collector */
        $collector = $profile->getCollector('db');

        return $collector->getQueryCount();
    }

    private function tabPage(string $tab): Crawler
    {
        $crawler = $this->client->request('GET', '/termene' . ($tab === 'de-facut' ? '' : '?tab=' . $tab));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @param list<int> $caseIds
     *
     * @return list<string> suggestion printed on the row of each case
     */
    private function suggestionsIn(Crawler $crawler, array $caseIds): array
    {
        return array_map(static function (int $caseId) use ($crawler): string {
            $row = $crawler->filter('article')->reduce(
                static fn (Crawler $article): bool => $article->filter(sprintf('a[href="/case/%d"]', $caseId))->count() > 0,
            );

            return (string) $row->filter('[data-testid="row-next-step"]')->attr('data-next-step');
        }, $caseIds);
    }

    private function trans(string $key, ?int $count = null): string
    {
        return static::getContainer()->get('translator')->trans($key, $count !== null ? ['%count%' => $count] : []);
    }
}
