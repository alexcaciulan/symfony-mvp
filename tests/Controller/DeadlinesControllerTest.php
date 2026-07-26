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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The global deadlines page. What matters at this level is that it is closed to
 * anonymous visitors, that it renders server side in a single request, and that the
 * counters it shows only ever describe the signed-in lawyer's own cases.
 */
final class DeadlinesControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $prefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->prefix = 'deadlines-ctrl-' . uniqid();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
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

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        $this->client->request('GET', '/termene');

        self::assertResponseRedirects();
        self::assertStringContainsString(
            '/login',
            (string) $this->client->getResponse()->headers->get('Location'),
        );
    }

    public function testSignedInLawyerReachesThePageAndReadsItsHeading(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user);
        $this->makeDeadline($case, '+4 days', DeadlineType::JUDECATA);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(
            'h1',
            static::getContainer()->get('translator')->trans('deadlines.heading'),
        );
    }

    /**
     * Tenant isolation on the one page that reads across every case at once: a
     * deadline of another account must not reach the markup at all, neither as a row
     * nor as a link into that account's case.
     */
    public function testAgendaNeverShowsTheDeadlineOfAnotherAccount(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $other = $this->makeUser($this->prefix . '-other@test.com');

        $ownCase = $this->makeCase($user);
        $ownDeadline = $this->makeDeadline($ownCase, '+2 days', DeadlineType::JUDECATA);

        $foreignCase = $this->makeCase($other);
        $foreignDeadline = $this->makeDeadline($foreignCase, '+2 days', DeadlineType::JUDECATA);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('article[id^="deadline-row-"]'));
        self::assertCount(1, $crawler->filter('#deadline-row-' . $ownDeadline->getId()));
        self::assertCount(0, $crawler->filter('#deadline-row-' . $foreignDeadline->getId()));
        self::assertCount(
            0,
            $crawler->filter(sprintf('a[href="/case/%d"]', $foreignCase->getId())),
            'No link may point into a case of another account.',
        );
    }

    /**
     * The agenda is a triage screen, not a to-do list: a row is closed through the
     * button the resolver chose for it, which posts to the route the case page uses,
     * so authorization and audit stay in one place. A checkbox would bypass all of it.
     */
    public function testAgendaRowsCarryNoCheckbox(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $this->makeDeadline($case, '-1 day', DeadlineType::OTHER);
        $this->makeDeadline($case, 'today', DeadlineType::TIMBRARE);
        $this->makeDeadline($case, '+9 days', DeadlineType::JUDECATA);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('article[id^="deadline-row-"]'));
        self::assertCount(0, $crawler->filter('article[id^="deadline-row-"] input[type="checkbox"]'));
        self::assertCount(0, $crawler->filter('input[type="checkbox"]'), 'The page has no checkbox at all.');
    }

    /**
     * A fatal term already missed on a certain date keeps a single way out, into the
     * case: closing it would only silence the alerts on the one case where silence
     * costs the claim. A term whose miss carries no sanction stays closeable, so the
     * rule is shown to discriminate rather than to suppress every button.
     */
    public function testMissedFatalDeadlineOffersNoWayToSilenceIt(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $fatal = $this->makeDeadline($case, '-4 days', DeadlineType::TIMBRARE);
        $harmless = $this->makeDeadline($case, '-4 days', DeadlineType::OTHER);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();

        $translator = static::getContainer()->get('translator');
        $fatalRow = $crawler->filter('#deadline-row-' . $fatal->getId());
        self::assertCount(1, $fatalRow);
        self::assertCount(0, $fatalRow->filter('form'), 'A consumed fatal term must not be closeable.');
        self::assertStringContainsString($translator->trans('deadlines.action.open_case'), $fatalRow->text());
        self::assertStringContainsString($translator->trans('deadlines.row.consequence_consumed'), $fatalRow->text());

        $harmlessRow = $crawler->filter('#deadline-row-' . $harmless->getId());
        self::assertCount(
            1,
            $harmlessRow->filter(sprintf('form[action="/case/%d/deadline/%d/complete"]', $case->getId(), $harmless->getId())),
        );
    }

    /**
     * The blockage zone justifies the page, but it is not what the lawyer reads every
     * morning: expanded it pushes the agenda below the fold. It is a native <details>,
     * so the collapsed state lives on the element itself and is announced without any
     * ARIA attribute of ours, which would go stale the moment the zone is opened.
     */
    public function testBlockageZoneIsRenderedCollapsed(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $this->makeCase($user, CaseStatus::ORDONANTA_EMISA);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();

        $zone = $crawler->filter('details');
        self::assertCount(1, $zone);
        self::assertNull($zone->attr('open'), 'The blockage zone must render collapsed.');
        self::assertNull(
            $zone->filter('summary')->attr('aria-expanded'),
            'A native disclosure carries its own state; a hardcoded aria-expanded would lie once opened.',
        );

        $translator = static::getContainer()->get('translator');
        self::assertStringContainsString($translator->trans('deadlines.blockages.heading'), $zone->filter('summary')->text());
        self::assertStringContainsString($translator->trans('deadlines.blockages.open'), $zone->filter('summary')->text());
    }

    public function testPageRendersCountersForTheSignedInLawyerOnly(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $other = $this->makeUser($this->prefix . '-other@test.com');

        $own = $this->makeCase($user);
        $this->makeDeadline($own, '-3 days', DeadlineType::TIMBRARE);
        $this->makeDeadline($own, 'today', DeadlineType::JUDECATA);
        $this->makeDeadline($this->makeCase($other), '-1 day', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertSame('1', trim($crawler->filter('[data-counter="overdue"] strong')->text()));
        self::assertSame('1', trim($crawler->filter('[data-counter="today"] strong')->text()));
    }

    /**
     * An expired term of the debtor is rendered under its own heading and left out of
     * the arrears counter: its expiry is the event that opens the filing of the
     * request (CPC art. 1015-1016), so counting it as a delay of the lawyer would
     * inflate the one number the morning triage is read from.
     */
    public function testExpiredDebtorTermGetsItsOwnSectionAndNoArrearsCount(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-20 days'));
        $summonsAnswer = $this->makeDeadline($case, '-5 days', DeadlineType::RASPUNS_SOMATIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();

        $translator = static::getContainer()->get('translator');
        self::assertCount(1, $crawler->filter('#deadline-row-' . $summonsAnswer->getId()));
        self::assertSame('0', trim($crawler->filter('[data-counter="overdue"] strong')->text()));

        $headings = $crawler->filter('h2')->each(static fn ($node): string => $node->text());
        self::assertStringContainsString($translator->trans('deadlines.section.unblocked'), implode(' | ', $headings));
        self::assertStringNotContainsString($translator->trans('deadlines.section.overdue'), implode(' | ', $headings));
    }

    /**
     * On a limitation period the date is an estimate for the opposite reason than
     * everywhere else: the due date IS confirmed, and what the application does not
     * model is the interruption carried by the communicated summons (CPC art. 1015
     * para. 2, NCC art. 2540). The row must say that, not that a date is missing.
     */
    public function testEstimatedLimitationPeriodExplainsTheInterruptionNotAMissingDate(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-20 days'));
        $limitation = $this->makeDeadline($case, '+12 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();

        $translator = static::getContainer()->get('translator');
        $marker = $crawler->filter('#deadline-row-' . $limitation->getId() . ' span[title]')->last();

        self::assertSame($translator->trans('deadlines.row.estimate.prescription_interruption.mark'), trim($marker->text()));
        self::assertSame($translator->trans('deadlines.row.estimate.prescription_interruption.note'), $marker->attr('title'));
    }

    public function testBlockedCasesAreCounted(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $blocked = $this->makeCase($user, CaseStatus::ORDONANTA_EMISA);
        $this->makeCase($user);
        $this->em->flush();

        self::assertNotNull($blocked->getId());

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertSame('1', trim($crawler->filter('[data-counter="blocked"] strong')->text()));
    }

    /**
     * An account with nothing tracked shows neither the risk bar nor the blockage
     * zone: four counters on zero read as a broken screen. What it shows instead is
     * which terms the application will watch.
     */
    public function testAccountWithoutAnyDeadlineShowsWhatWillBeTracked(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-state="no-deadlines"]'));
        self::assertCount(0, $crawler->filter('[data-counter="overdue"]'));
    }

    /**
     * Cases exist and the limitation periods are still tracked, but nothing falls
     * inside the window: the bar collapses onto the line that confirms the zero, and
     * the rail keeps showing that the tracking goes on.
     */
    public function testEmptyWindowWithLimitationPeriodsShowsTheCleanRegisterState(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user);
        $this->makeDeadline($case, '+2 years', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-state="clean"]'));
        self::assertCount(1, $crawler->filter('[data-state="agenda-empty"]'));
        self::assertCount(0, $crawler->filter('[data-counter="overdue"]'));
    }

    /**
     * A row carries the buttons the resolver chose for it. A stamping term is done
     * on the spot, so its single button is the close, posting to the route the case
     * page has always used.
     */
    public function testRowRendersTheResolvedActionAsAFormOnTheExistingRoute(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $this->makeDeadline($case, '+2 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('article[id^="deadline-row-"]'));
        self::assertCount(
            1,
            $crawler->filter(sprintf('form[action^="/case/%d/deadline/"] input[name="_token"]', $case->getId())),
        );
    }

    /**
     * A hearing is attended at the court and its hour only lives on the portal, so
     * the primary button is a link there and the close stays a second, outlined
     * control. Both have to survive the trip to the page.
     */
    public function testHearingRowLinksToThePortalTabAndKeepsTheCloseButton(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user, CaseStatus::TERMEN_FIXAT);
        $this->makeDeadline($case, '+3 days', DeadlineType::JUDECATA);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('a[href="/case/%d?tab=portal"]', $case->getId())));
        self::assertCount(
            1,
            $crawler->filter(sprintf('form[action^="/case/%d/deadline/"] input[name="_token"]', $case->getId())),
        );
    }

    /**
     * Every time bucket builds its own header, with the weekday and the date spelled
     * out. They are only exercised when a bucket actually holds something, so the
     * page is asked to render one row in each of them at once.
     */
    public function testEveryTimeBucketRendersItsHeader(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user);

        $dates = ['-2 days', 'today', '+1 day', '+20 days'];
        // Only up to Friday is there still a "rest of the week" left to fill.
        if ((int) (new \DateTimeImmutable('today'))->format('N') <= 5) {
            $dates[] = 'sunday this week';
        }
        foreach ($dates as $date) {
            $this->makeDeadline($case, $date, DeadlineType::OTHER);
        }
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertCount(\count($dates), $crawler->filter('article[id^="deadline-row-"]'));
        // One heading per bucket: with no blockage and no rail on this account, the
        // group headers are the only h2 on the page.
        self::assertCount(\count($dates), $crawler->filter('h2'));
    }

    /**
     * The 30-day bucket is the only one that can grow long enough to push the urgent
     * sections off the first screen, so past ten rows it folds into a disclosure.
     */
    public function testTheThirtyDayBucketFoldsWhenItGrowsLong(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user);
        for ($i = 0; $i < 11; ++$i) {
            $this->makeDeadline($case, '+20 days', DeadlineType::OTHER);
        }
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertCount(11, $crawler->filter('article[id^="deadline-row-"]'));
        self::assertCount(1, $crawler->filter('details summary'));
    }

    private function makeUser(string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($hasher->hashPassword($user, 'test'));
        $user->setIsVerified(true);
        $this->em->persist($user);

        return $user;
    }

    private function makeCase(User $user, CaseStatus $status = CaseStatus::AMIABIL): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($user);
        $case->setStatus($status);
        $case->setAmount('1000.00');
        $this->em->persist($case);

        return $case;
    }

    private function makeDeadline(LegalCase $case, string $date, DeadlineType $type): LegalDeadline
    {
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($case);
        $deadline->setType($type);
        $deadline->setDeadlineDate(new \DateTimeImmutable($date));
        $deadline->setPriority($type->defaultPriority());
        $deadline->setCompleted(false);
        $this->em->persist($deadline);

        return $deadline;
    }
}
