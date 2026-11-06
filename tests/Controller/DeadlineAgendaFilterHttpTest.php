<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The fatal switch and the query string as they arrive over HTTP: what a pasted link
 * rebuilds.
 *
 * The repository tests already pin which rows the switch selects. What is pinned here is
 * the layer above them, the one a colleague actually touches: that the query string is the
 * whole state, that it is applied before the markup is built rather than hidden in the
 * browser afterwards, and that a query string nobody meant to write still produces a page.
 */
final class DeadlineAgendaFilterHttpTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $prefix;
    private User $user;
    private LegalCase $case;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->prefix = 'agenda-filter-http-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->prefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setStatus(CaseStatus::CERERE_DEPUSA);
        $this->case->setAmount('1000.00');
        $this->em->persist($this->case);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE a FROM audit_log a JOIN `user` u ON a.user_id = u.id WHERE u.email LIKE ?',
            [$this->prefix . '%'],
        );
        $conn->executeStatement(
            'DELETE d FROM legal_deadline d JOIN legal_case lc ON d.legal_case_id = lc.id JOIN `user` u ON lc.user_id = u.id WHERE u.email LIKE ?',
            [$this->prefix . '%'],
        );
        $conn->executeStatement(
            'DELETE lc FROM legal_case lc JOIN `user` u ON lc.user_id = u.id WHERE u.email LIKE ?',
            [$this->prefix . '%'],
        );
        $conn->executeStatement('DELETE FROM `user` WHERE email LIKE ?', [$this->prefix . '%']);

        parent::tearDown();
    }

    // ===== the fatal switch ===============================================

    /** The fatal selection keeps a late irreversible term: that is the one to see first. */
    public function testTheFatalSwitchKeepsTheIrreversibleRowsOfEveryBucket(): void
    {
        $late = $this->makeDeadline('-4 days', DeadlineType::TIMBRARE);
        $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $today = $this->makeDeadline('today', DeadlineType::CERERE_IN_ANULARE);
        $this->makeDeadline('today', DeadlineType::JUDECATA);
        $this->em->flush();

        self::assertSame([$late->getId(), $today->getId()], $this->rowsOn('/termene?fatal=1'));
        self::assertCount(4, $this->rowsOn('/termene'), 'Without the switch the whole window shows.');
    }

    /**
     * The switch states its position, and pressing it leads to the opposite one: without
     * that the lawyer reads a short agenda with no way to tell it from a quiet day.
     */
    public function testTheSwitchStatesItsPositionAndLeadsToTheOpposite(): void
    {
        $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $this->em->flush();

        $off = $this->client->request('GET', '/termene')->filter('[data-testid="agenda-fatal-toggle"]');
        self::assertResponseIsSuccessful();
        self::assertSame('false', $off->attr('aria-pressed'));
        self::assertSame('/termene?fatal=1', $off->attr('href'));

        $on = $this->client->request('GET', '/termene?fatal=1')->filter('[data-testid="agenda-fatal-toggle"]');
        self::assertResponseIsSuccessful();
        self::assertSame('true', $on->attr('aria-pressed'));
        self::assertSame('/termene', $on->attr('href'));
    }

    public function testTheFatalMarkFollowsTheType(): void
    {
        $annulment = $this->makeDeadline('+3 days', DeadlineType::CERERE_IN_ANULARE);
        $filing = $this->makeDeadline('+3 days', DeadlineType::DEPUNERE_CERERE);
        $hearing = $this->makeDeadline('+3 days', DeadlineType::JUDECATA);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/termene');

        self::assertCount(1, $crawler->filter('#deadline-row-' . $annulment->getId() . ' [data-testid="row-fatal"]'));
        self::assertCount(1, $crawler->filter('#deadline-row-' . $filing->getId() . ' [data-testid="row-fatal"]'));
        self::assertCount(0, $crawler->filter('#deadline-row-' . $hearing->getId() . ' [data-testid="row-fatal"]'));
        self::assertSame('1', $crawler->filter('#deadline-row-' . $annulment->getId())->attr('data-fatal'));
        self::assertSame('0', $crawler->filter('#deadline-row-' . $hearing->getId())->attr('data-fatal'));
    }

    /** With nothing on the tab there is nothing to narrow, so no switch is offered. */
    public function testTheSwitchDisappearsWhenTheTabIsEmpty(): void
    {
        $this->makeDeadline('+200 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        self::assertCount(0, $this->client->request('GET', '/termene')->filter('[data-testid="agenda-fatal-toggle"]'));
    }

    /** A filter value that is on and one that is not, as a hand-edited URL may carry them. */
    public function testOnlyTheValuesThePageRecognisesTurnTheSwitchOn(): void
    {
        $fatal = $this->makeDeadline('-4 days', DeadlineType::TIMBRARE);
        $other = $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $this->em->flush();

        foreach (['1', 'true', 'on', 'yes', 'TRUE'] as $value) {
            self::assertSame([$fatal->getId()], $this->rowsOn('/termene?fatal=' . $value), $value);
        }

        foreach (['0', 'false', 'off', 'banana', ''] as $value) {
            self::assertSame([$fatal->getId(), $other->getId()], $this->rowsOn('/termene?fatal=' . $value), $value);
        }
    }

    // ===== a query string nobody meant to write ============================

    /**
     * An unknown tab degrades into the first one rather than into an error page. The
     * query string is hand editable and travels in links, so a stale or mistyped value
     * has to land on the nearest page that does exist, and never on a 500.
     */
    public function testAnUnknownTabFallsBackToTheFirstOne(): void
    {
        $overdue = $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/termene?tab=nonsense');

        self::assertResponseIsSuccessful();
        self::assertSame([$overdue->getId()], $this->rowIdsIn($crawler));
        self::assertSame('page', $crawler->filter('[data-tab="de-facut"]')->attr('aria-current'));
        self::assertCount(0, $crawler->filter('[data-state="filter-empty"]'), 'Nothing was selected, so nothing is empty.');
    }

    /** Tab names are matched exactly: two URLs must not mean the same page. */
    public function testTheTabNamesAreMatchedExactly(): void
    {
        $this->em->flush();

        $crawler = $this->client->request('GET', '/termene?tab=DE-COMPLETAT');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-tab-panel="de-facut"]'));
    }

    /**
     * The malformed shapes that still resolve to a page: empty values, separators,
     * parameters the page has never heard of. None of them may reach an error page.
     */
    public function testMalformedButReadableQueryStringsStillRenderTheAgenda(): void
    {
        $overdue = $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $today = $this->makeDeadline('today', DeadlineType::JUDECATA);
        $this->em->flush();
        $whole = [$overdue->getId(), $today->getId()];

        foreach (['?tab=', '?fatal=', '?page=', '?f=', '?f=,,,', '?completed=', '?sort=asc&page=9', '?page=abc', '?tab=%00'] as $query) {
            self::assertSame($whole, $this->rowsOn('/termene' . $query), $query);
        }
    }

    /** A page outside the range still renders the closed tab: it is clamped, not refused. */
    public function testAPageOutOfRangeOnTheClosedTabStillRenders(): void
    {
        $closed = $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $closed->setCompleted(true);
        $this->em->flush();

        foreach (['-3', '0', '999999', 'abc'] as $page) {
            self::assertSame([$closed->getId()], $this->rowsOn('/termene?tab=finalizate&page=' . $page), $page);
        }
    }

    /**
     * The selection is applied in SQL, so a row outside it is absent from the markup
     * rather than present and hidden. A filter that only hid rows would leave them in
     * the page for anything reading it, and would make the group bands describe a set
     * the lawyer cannot see.
     */
    public function testTheRowsOutsideTheSelectionAreAbsentFromTheMarkupNotHidden(): void
    {
        $this->makeDeadline('-4 days', DeadlineType::TIMBRARE);
        $excluded = $this->makeDeadline('+9 days', DeadlineType::JUDECATA);
        $this->em->flush();

        $this->client->request('GET', '/termene?fatal=1');
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('deadline-row-' . $excluded->getId(), $body);
    }

    // ===== helpers ========================================================

    /** @return list<int> ids of the rows the page rendered, in page order */
    private function rowsOn(string $url): array
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful($url . ' must render.');

        return $this->rowIdsIn($crawler);
    }

    /** @return list<int> */
    private function rowIdsIn(Crawler $crawler): array
    {
        return $crawler->filter('article[id^="deadline-row-"]')->each(
            static fn (Crawler $node): int => (int) str_replace('deadline-row-', '', (string) $node->attr('id')),
        );
    }

    private function makeDeadline(string $date, DeadlineType $type): LegalDeadline
    {
        $deadline = new LegalDeadline();
        // Fetched rather than reused: a request through the client resets the manager,
        // so the instance held by the test is detached from the second call on.
        $deadline->setLegalCase($this->em->getRepository(LegalCase::class)->find($this->case->getId()));
        $deadline->setType($type);
        $deadline->setDeadlineDate(new \DateTimeImmutable($date));
        $deadline->setPriority($type->defaultPriority());
        $deadline->setCompleted(false);
        $this->em->persist($deadline);

        return $deadline;
    }
}
