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
 * The triage selection as it arrives over HTTP: what a pasted link rebuilds.
 *
 * The repository tests already pin which rows each pill selects. What is pinned here
 * is the layer above them, the one a colleague actually touches: that the query
 * string is the whole state, that it is applied before the markup is built rather
 * than hidden in the browser afterwards, and that a query string nobody meant to
 * write still produces a page.
 *
 * On the combination rule the page deliberately does the opposite of an intersection.
 * Two pills are a UNION. An intersection would be empty by construction on the pair a
 * lawyer is most likely to press, since no deadline is both past due and falling
 * today, so "overdue + today" would answer "nothing to do" on a morning with work in
 * both. The pills widen the triage; the way to narrow it is to press fewer.
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

    // ===== each pill narrows to what its counter counts ===================

    public function testEachPillKeepsOnlyItsOwnRows(): void
    {
        $overdue = $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $today = $this->makeDeadline('today', DeadlineType::JUDECATA);
        $fatal = $this->makeDeadline('+9 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        self::assertSame([$overdue->getId()], $this->rowsOn('/termene?f=overdue'));
        self::assertSame([$today->getId()], $this->rowsOn('/termene?f=today'));
        self::assertSame([$fatal->getId()], $this->rowsOn('/termene?f=fatal30'));

        self::assertSame(
            [$overdue->getId(), $today->getId(), $fatal->getId()],
            $this->rowsOn('/termene'),
            'No selection is the whole window, which is what the pills narrow from.',
        );
    }

    /**
     * The pressed pill is stated on the pill itself, not only in the URL: without it
     * the lawyer reads a short agenda with no way to tell it apart from a quiet day.
     */
    public function testTheSelectionIsVisibleOnThePillsAndReleasableInOneClick(): void
    {
        $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/termene?f=overdue');
        $translator = static::getContainer()->get('translator');

        self::assertResponseIsSuccessful();
        self::assertSame('true', $crawler->filter('[data-counter="overdue"]')->attr('aria-pressed'));
        self::assertSame('false', $crawler->filter('[data-counter="fatal30"]')->attr('aria-pressed'));
        self::assertStringContainsString($translator->trans('deadlines.filter.clear'), $crawler->text());
        self::assertStringContainsString($translator->trans('deadlines.filter.counters_note'), $crawler->text());
    }

    // ===== several pills are a union ======================================

    /**
     * The rule, on the pair that shows why it has to be a union: nothing is both past
     * due and due today, so read as an intersection this selection would answer "no
     * deadlines" while both rows are outstanding.
     */
    public function testTwoPillsWidenTheSelectionInsteadOfIntersectingIt(): void
    {
        $overdue = $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $today = $this->makeDeadline('today', DeadlineType::JUDECATA);
        $this->makeDeadline('+9 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $rows = $this->rowsOn('/termene?f=overdue,today');

        self::assertSame([$overdue->getId(), $today->getId()], $rows);
        self::assertCount(2, $rows, 'An intersection of these two pills is empty by construction.');
    }

    /** Three pills widen further, and the row outside all of them stays out. */
    public function testEveryPillPressedIsStillASelectionAndNotTheWholeWindow(): void
    {
        $overdue = $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $today = $this->makeDeadline('today', DeadlineType::JUDECATA);
        $fatal = $this->makeDeadline('+9 days', DeadlineType::TIMBRARE);
        $outside = $this->makeDeadline('+20 days', DeadlineType::JUDECATA);
        $this->em->flush();

        $rows = $this->rowsOn('/termene?f=overdue,today,fatal30');

        self::assertSame([$overdue->getId(), $today->getId(), $fatal->getId()], $rows);
        self::assertNotContains($outside->getId(), $rows, 'A hearing three weeks out is none of the three.');
    }

    /**
     * The order the pills were pressed in is not part of the selection: two lawyers
     * pressing the same two pills in the opposite order must be looking at the same
     * agenda, and a link pasted between them must open it.
     */
    public function testTheOrderOfThePillsInTheUrlDoesNotChangeTheResult(): void
    {
        $overdue = $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $today = $this->makeDeadline('today', DeadlineType::JUDECATA);
        $this->em->flush();

        self::assertSame(
            [$overdue->getId(), $today->getId()],
            $this->rowsOn('/termene?f=today,overdue'),
        );
    }

    /**
     * The blockage pill counts cases whose deadline does not exist yet, so it has no
     * row to match. Pressed alone it says so instead of quietly falling through to
     * every row, and it is what keeps the blockage zone on screen.
     */
    public function testTheBlockagePillEmptiesTheListAndKeepsTheBlockageZone(): void
    {
        $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $blocked = new LegalCase();
        $blocked->setUser($this->em->getRepository(User::class)->find($this->user->getId()));
        $blocked->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $blocked->setAmount('2000.00');
        $this->em->persist($blocked);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/termene?f=blocked');
        $heading = static::getContainer()->get('translator')->trans('deadlines.blockages.heading');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->rowIdsIn($crawler));
        self::assertCount(1, $crawler->filter('[data-state="filter-empty"]'));
        self::assertStringContainsString($heading, $crawler->text());
    }

    /** Combined with a deadline pill it still contributes no rows of its own. */
    public function testTheBlockagePillAddsNoRowsToAnotherPill(): void
    {
        $overdue = $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $this->makeDeadline('today', DeadlineType::JUDECATA);
        $this->em->flush();

        self::assertSame([$overdue->getId()], $this->rowsOn('/termene?f=overdue,blocked'));
    }

    // ===== showing closed terms widens, it does not select =================

    public function testShowingClosedTermsAddsToTheListRatherThanFilteringIt(): void
    {
        $open = $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $closed = $this->makeDeadline('-5 days', DeadlineType::OTHER);
        $closed->setCompleted(true);
        $this->em->flush();

        self::assertSame([$open->getId()], $this->rowsOn('/termene'));
        self::assertSame(
            [$closed->getId(), $open->getId()],
            $this->rowsOn('/termene?completed=1'),
            'Both, oldest first: the open list is widened, not replaced.',
        );
    }

    public function testShowingClosedTermsComposesWithAPillOverHttp(): void
    {
        $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $closedToday = $this->makeDeadline('today', DeadlineType::JUDECATA);
        $closedToday->setCompleted(true);
        $this->em->flush();

        self::assertSame([$closedToday->getId()], $this->rowsOn('/termene?f=today&completed=1'));
    }

    // ===== a query string nobody meant to write ============================

    /**
     * Unknown pills are dropped rather than answered with an error page. The query
     * string is hand editable and travels in links, so a stale or mistyped value has
     * to degrade into the nearest agenda that does exist, and never into a 500.
     */
    public function testAnUnknownPillIsIgnoredAndTheKnownOnesStillApply(): void
    {
        $overdue = $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $this->makeDeadline('today', DeadlineType::JUDECATA);
        $this->em->flush();

        self::assertSame([$overdue->getId()], $this->rowsOn('/termene?f=overdue,inexistent'));
    }

    /** A pill misspelled on its own leaves no selection at all, so the window is whole. */
    public function testAQueryStringOfOnlyUnknownPillsFallsBackToTheWholeWindow(): void
    {
        $overdue = $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $today = $this->makeDeadline('today', DeadlineType::JUDECATA);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/termene?f=nonsense');

        self::assertResponseIsSuccessful();
        self::assertSame([$overdue->getId(), $today->getId()], $this->rowIdsIn($crawler));
        self::assertSame('false', $crawler->filter('[data-counter="overdue"]')->attr('aria-pressed'));
        self::assertCount(0, $crawler->filter('[data-state="filter-empty"]'), 'Nothing was selected, so nothing is empty.');
    }

    /**
     * The values are matched exactly, so a pill in the wrong case is an unknown pill
     * rather than a near miss quietly accepted. Pinned because the alternative,
     * normalising the input, would make two different URLs mean the same agenda while
     * only one of them is ever generated.
     */
    public function testThePillNamesAreMatchedExactly(): void
    {
        $overdue = $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $today = $this->makeDeadline('today', DeadlineType::JUDECATA);
        $this->em->flush();

        self::assertSame(
            [$overdue->getId(), $today->getId()],
            $this->rowsOn('/termene?f=OVERDUE'),
            'An uppercase pill selects nothing, so the whole window shows.',
        );
    }

    /**
     * The malformed shapes that still resolve to an agenda: empty, all separators,
     * padded values, a pill named twice, an empty flag, and parameters the page has
     * never heard of. None of them may reach an error page.
     */
    public function testMalformedButReadableQueryStringsStillRenderTheAgenda(): void
    {
        $overdue = $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $today = $this->makeDeadline('today', DeadlineType::JUDECATA);
        $this->em->flush();
        $whole = [$overdue->getId(), $today->getId()];

        self::assertSame($whole, $this->rowsOn('/termene?f='));
        self::assertSame($whole, $this->rowsOn('/termene?f=,,,'));
        self::assertSame($whole, $this->rowsOn('/termene?completed='));
        self::assertSame($whole, $this->rowsOn('/termene?sort=asc&page=9'));

        // Padded and repeated values resolve to the selection they name.
        self::assertSame([$overdue->getId()], $this->rowsOn('/termene?f=%20overdue%20'));
        self::assertSame([$overdue->getId()], $this->rowsOn('/termene?f=overdue,overdue'));
    }

    /**
     * A value nobody recognises falls back to the default agenda. It holds for the
     * pills, where an unknown name is dropped and the rest of the selection applies,
     * and equally for the flag that shows the closed terms, where anything other than
     * what the page itself emits means "off".
     *
     * The agenda is the screen a lawyer opens to find out what is late. A URL that was
     * hand-edited, truncated by a mail client or pasted with a stray character has to
     * degrade to the whole agenda, never to an error page in place of it.
     */
    public function testAnUnreadableFlagFallsBackToTheDefaultAgendaInsteadOfFailing(): void
    {
        $overdue = $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $this->em->flush();

        foreach (['/termene?completed=maybe', '/termene?completed=banana', '/termene?completed=0'] as $url) {
            self::assertSame([$overdue->getId()], $this->rowsOn($url), $url . ' must render the default agenda.');
        }
    }

    /**
     * A parameter sent with the wrong SHAPE is a different matter: an array where a
     * string is expected is not a selection the page can degrade to, it is a
     * malformed request, and the request layer refuses it before the page is built.
     *
     * What matters for a page whose whole state lives in the URL is that this stays a
     * rejection of the request: a 4xx the client can read as its own mistake, never a
     * 5xx that reads as a failure of the agenda.
     */
    public function testParametersOfTheWrongShapeAreRefusedAsClientErrorsNotCrashes(): void
    {
        $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $this->em->flush();

        foreach (['/termene?f[]=overdue', '/termene?completed[]=1'] as $url) {
            $this->client->request('GET', $url);

            $status = $this->client->getResponse()->getStatusCode();
            self::assertTrue(
                $this->client->getResponse()->isClientError(),
                sprintf('%s must answer 4xx, got %d.', $url, $status),
            );
        }
    }

    /**
     * The selection is applied in SQL, so a row outside it is absent from the markup
     * rather than present and hidden. A filter that only hid rows would leave them in
     * the page for anything reading it, and would make the counters and the section
     * headers describe a set the lawyer cannot see.
     */
    public function testTheRowsOutsideTheSelectionAreAbsentFromTheMarkupNotHidden(): void
    {
        $this->makeDeadline('-4 days', DeadlineType::OTHER);
        $excluded = $this->makeDeadline('+9 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->request('GET', '/termene?f=overdue');
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
