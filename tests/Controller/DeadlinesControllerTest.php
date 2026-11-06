<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use App\Service\Deadline\DeadlineService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
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
            'h1[data-testid="agenda-heading"]',
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
        self::assertSame(
            $translator->trans('deadlines.action.open_case'),
            $fatalRow->filter('[data-row-slot="case"]')->attr('aria-label'),
        );
        self::assertCount(0, $fatalRow->filter('[data-row-slot="close"]'));
        self::assertSame('1', $fatalRow->attr('data-consumed'));
        self::assertStringContainsString($translator->trans('deadlines.row.consequence_consumed'), $fatalRow->text());

        $harmlessRow = $crawler->filter('#deadline-row-' . $harmless->getId());
        self::assertCount(
            1,
            $harmlessRow->filter(sprintf('form[action="/case/%d/deadline/%d/complete"]', $case->getId(), $harmless->getId())),
        );
    }

    /**
     * A case whose summons is out but not yet acknowledged has no term to show, and
     * inventing one from the generation date is exactly what the blockage zone exists
     * to prevent. The row therefore prints the state of the case where a term would
     * print its date, and the button asks for the one thing that unblocks it. The
     * fifteen days of CPC art. 1015 para. 1 appear only once that date is recorded.
     */
    public function testBlockageRowStatesTheServiceIsUnderWayInsteadOfADate(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeDate(new \DateTime('-3 days'));
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene?tab=de-completat');

        self::assertResponseIsSuccessful();

        $translator = static::getContainer()->get('translator');
        $row = $crawler->filter('[data-testid="blockage-row"]');

        self::assertCount(1, $row);
        self::assertStringContainsString(
            $translator->trans('deadlines.blockage.SUMMONS_COMMUNICATION_MISSING.state'),
            $row->text(),
        );
        self::assertSame(
            $translator->trans('deadlines.blockage.SUMMONS_COMMUNICATION_MISSING.action'),
            $row->filter('[data-row-slot="case"]')->attr('aria-label'),
        );
        self::assertCount(
            0,
            $this->client->request('GET', '/termene')->filter('[id^="deadline-row-"]'),
            'No agenda row is born from a summons whose service is not recorded.',
        );
        self::assertSame('1', trim($crawler->filter('[data-counter="blocked"]')->text()));
    }

    /**
     * The date is collected on this page, in the dialog it already carries, rather
     * than by sending the lawyer into the case to type one field. The href stays
     * pointed at the case so a browser running no scripts still reaches the same
     * dialog, only through a navigation.
     */
    public function testBlockageRowOpensTheCommunicationDateDialogAndKeepsTheCaseLinkAsFallback(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeDate(new \DateTime('-3 days'));
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene?tab=de-completat');

        self::assertResponseIsSuccessful();

        $link = $crawler->filter('[data-testid="blockage-row"] a[data-row-slot="case"]');
        self::assertCount(1, $link);
        self::assertSame('agenda-dialog#openDialog', $link->attr('data-action'));
        self::assertSame('hs-modal-set-summons-communication-date', $link->attr('data-agenda-dialog-dialog-param'));
        self::assertSame(
            '/case/' . $case->getId() . '/summons-communication-date',
            $link->attr('data-agenda-dialog-action-param'),
        );
        self::assertSame('/case/' . $case->getId(), $link->attr('href'));
        self::assertCount(1, $crawler->filter('#hs-modal-set-summons-communication-date'));
    }

    /**
     * Every other blockage is recorded in a dialog that lives on the case page, so its
     * button stays a plain link there. It must never become a form: the routes behind
     * them are POST endpoints that reject a payload without a date.
     */
    public function testBlockageRowWithoutAnAgendaDialogStaysALinkIntoTheCase(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user, CaseStatus::ORDONANTA_EMISA);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene?tab=de-completat');

        self::assertResponseIsSuccessful();

        $row = $crawler->filter('[data-testid="blockage-row"]');
        self::assertCount(0, $row->filter('form'));

        $link = $row->filter('a[data-row-slot="case"]');
        self::assertNull($link->attr('data-action'));
        self::assertSame('/case/' . $case->getId(), $link->attr('href'));
        self::assertSame(
            static::getContainer()->get('translator')->trans('deadlines.blockage.RULING_COMMUNICATION_MISSING.action'),
            $link->attr('aria-label'),
        );
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
        self::assertSame('2', trim($crawler->filter('[data-counter="todo"]')->text()));

        $translator = static::getContainer()->get('translator');
        $hint = $crawler->filter('[data-tab="de-facut"]')->text();
        self::assertStringContainsString($translator->trans('deadlines.tab_hint.overdue', ['%count%' => 1]), $hint);
        self::assertStringContainsString($translator->trans('deadlines.tab_hint.today', ['%count%' => 1]), $hint);
    }

    /**
     * An expired term of the debtor is rendered under its own heading and left out of
     * the arrears counter: its expiry is the event that opens the filing of the
     * request (CPC art. 1015-1017), so counting it as a delay of the lawyer would
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
        self::assertSame('1', trim($crawler->filter('[data-counter="todo"]')->text()), 'The first tab still lists it.');
        self::assertStringContainsString(
            $translator->trans('deadlines.section.unblocked'),
            $crawler->filter('[data-group="unblocked"]')->text(),
        );
        self::assertCount(0, $crawler->filter('[data-group="overdue"]'));
        self::assertStringNotContainsString(
            $translator->trans('deadlines.tab_hint.overdue', ['%count%' => 1]),
            $crawler->filter('[data-tab="de-facut"]')->text(),
            'Its expiry is not a delay of the lawyer, so the box must not read as one.',
        );
    }

    /**
     * On a limitation period the marker under the date is not about uncertainty: the
     * due date IS confirmed, and what the stored date ignores is the interruption
     * carried by the communicated summons (CPC art. 1015 para. 2, NCC art. 2540). The
     * row states until when that interruption holds, and the date it states is
     * computed, never a placeholder: it is the communication plus six months, the same
     * arithmetic the six-month term is stored with.
     */
    public function testInterruptedLimitationPeriodStatesTheDateTheInterruptionHoldsUntil(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $communicatedOn = new \DateTimeImmutable('-20 days');
        $case->setPaymentNoticeCommunicationDate($communicatedOn);
        $limitation = $this->makeDeadline($case, '+12 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();

        $translator = static::getContainer()->get('translator');
        $holdsUntil = static::getContainer()->get(DeadlineService::class)
            ->filingInterruptionTermEnd($communicatedOn)->end;
        $params = ['%date%' => $holdsUntil->format('d.m.Y')];
        $marker = $crawler->filter('#deadline-row-' . $limitation->getId() . ' [data-testid="row-date-note"] .truncate');

        self::assertSame(
            $translator->trans('deadlines.row.estimate.prescription_interrupted.mark', $params),
            trim($marker->text()),
        );
        self::assertStringContainsString($holdsUntil->format('d.m.Y'), trim($marker->text()));

        // The full sentence stays out of the row and is stated by the panel.
        $panel = $this->client->request('GET', '/termene/' . $limitation->getId() . '/detalii', [], [], ['HTTP_TURBO_FRAME' => 'deadline-panel-frame']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            $translator->trans('deadlines.row.estimate.prescription_interrupted.note', $params),
            $panel->filter('[data-testid="deadline-panel"]')->text(),
        );
    }

    /**
     * Once the request is with the court the interruption no longer depends on the six
     * months: the filing interrupts the limitation period on its own (NCC art. 2537
     * pt. 2). Stating a date the interruption holds until would then be wrong.
     */
    public function testLimitationPeriodOnAFiledCaseNamesTheFilingAsTheInterruption(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-20 days'));
        $limitation = $this->makeDeadline($case, '+12 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();

        $translator = static::getContainer()->get('translator');
        $marker = $crawler->filter('#deadline-row-' . $limitation->getId() . ' [data-testid="row-date-note"] .truncate');

        self::assertSame(
            $translator->trans('deadlines.row.estimate.prescription_interrupted_by_filing.mark'),
            trim($marker->text()),
        );
    }

    /**
     * The agenda used to offer a way to dismiss a limitation term, and there is no
     * good answer to why a lawyer would want one off his screen: the period runs
     * whatever the row does, so the button only removed the warning. It is gone from
     * the page, not merely from the resolver, and nothing closes the term from here.
     *
     * Asserted on the markup rather than on the label, because a removed key renders
     * as the key itself: the raw name must not surface either.
     */
    public function testALimitationRowOffersNoWayToDismissTheTermFromTheAgenda(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $limitation = $this->makeDeadline($case, '+20 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();

        $row = $crawler->filter('#deadline-row-' . $limitation->getId());
        self::assertCount(1, $row);
        self::assertCount(
            0,
            $row->filter(sprintf('form[action*="/deadline/%d/complete"]', $limitation->getId())),
            'Nothing on this row may close the term.',
        );
        self::assertStringNotContainsString('stop_tracking', (string) $this->client->getResponse()->getContent());
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
        self::assertSame('1', trim($crawler->filter('[data-counter="blocked"]')->text()));
    }

    /**
     * An account with nothing tracked shows no boxes: four counters on zero read as a
     * broken screen. What it shows instead is which terms the application will watch.
     */
    public function testAccountWithoutAnyDeadlineShowsWhatWillBeTracked(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-state="no-deadlines"]'));
        self::assertCount(0, $crawler->filter('[data-counter]'));
        self::assertCount(0, $crawler->filter('[data-tab]'));
    }

    /**
     * Cases exist and the limitation periods are still tracked, but nothing falls inside
     * the window. The sentence confirms the zero, the boxes stay, and the empty state says
     * where the next term is, because "nothing" alone reads like a page that failed.
     */
    public function testEmptyWindowWithLimitationPeriodsPointsToTheNextOne(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user);
        $this->makeDeadline($case, '+2 years', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();

        $translator = static::getContainer()->get('translator');
        self::assertCount(1, $crawler->filter('[data-state="agenda-empty"]'));
        self::assertCount(1, $crawler->filter('[data-state="agenda-empty"] a[href="/termene?tab=prescriptii"]'));
        self::assertSame('0', trim($crawler->filter('[data-counter="todo"]')->text()));
        self::assertSame('1', trim($crawler->filter('[data-counter="prescriptions"]')->text()));
    }

    /** No limitation term on the account: nothing to point to, and no link pretending otherwise. */
    public function testEmptyWindowWithoutLimitationPeriodsOffersNoLinkToThem(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user);
        $closed = $this->makeDeadline($case, '-2 days', DeadlineType::OTHER);
        $closed->setCompleted(true);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertCount(1, $crawler->filter('[data-state="agenda-empty"]'));
        self::assertCount(0, $crawler->filter('[data-state="agenda-empty"] a'));
    }

    /**
     * A row carries the controls the resolver chose for it. A stamping term can be
     * closed from here, so its second control is a form, posting to the route the case
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
     * A hearing is attended at the court and its hour only lives on the portal, so the
     * first control is a link there and the close stays the second one. Both have to
     * survive the trip to the page.
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
     * Every time bucket builds its own band, in render order. They are only exercised
     * when a bucket actually holds something, so the page is asked to render a row in
     * each of them at once. Tomorrow is a day of the week group on any day of the week,
     * Sunday included.
     */
    public function testEveryTimeBucketRendersItsBandInOrder(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user);

        $dates = ['-2 days', 'today', '+1 day', '+20 days'];
        foreach ($dates as $date) {
            $this->makeDeadline($case, $date, DeadlineType::OTHER);
        }
        $debtorCase = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $debtorCase->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-20 days'));
        $this->makeDeadline($debtorCase, '-4 days', DeadlineType::RASPUNS_SOMATIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertCount(\count($dates) + 1, $crawler->filter('article[id^="deadline-row-"]'));
        self::assertSame(
            ['overdue', 'today', 'unblocked', 'week', 'later'],
            $crawler->filter('[data-group]')->each(static fn (Crawler $group): string => (string) $group->attr('data-group')),
        );
    }

    /**
     * The later bucket is the only one that can grow long enough to push the urgent
     * sections off the first screen, so past ten rows it folds into a disclosure. It is
     * the threshold that matters: ten rows stay open.
     */
    public function testTheLaterBucketFoldsAboveTenRows(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $case = $this->makeCase($user);
        for ($i = 0; $i < 10; ++$i) {
            $this->makeDeadline($case, '+20 days', DeadlineType::OTHER);
        }
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertCount(0, $crawler->filter('details[data-group="later"]'), 'Ten rows are not long.');
        self::assertCount(1, $crawler->filter('section[data-group="later"]'));

        $this->makeDeadline(
            $this->em->getRepository(LegalCase::class)->find($case->getId()),
            '+20 days',
            DeadlineType::OTHER,
        );
        $this->em->flush();

        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertCount(11, $crawler->filter('article[id^="deadline-row-"]'));
        self::assertCount(1, $crawler->filter('details[data-group="later"] summary'));
        self::assertNull($crawler->filter('details[data-group="later"]')->attr('open'));
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
