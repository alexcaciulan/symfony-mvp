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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Acting on a deadline from the global agenda, and narrowing that agenda.
 *
 * The acts run on the routes the case page has always posted to, so authorization
 * through CaseVoter and the audit trail stay in one place. What is pinned here is
 * the branch that decides the answer: with the agenda context the response has to
 * describe this page, and without it the case page must receive exactly what it
 * received before this branch existed.
 */
final class DeadlineAgendaActionsTest extends WebTestCase
{
    private const TURBO_ACCEPT = 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $prefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->prefix = 'agenda-actions-' . uniqid();
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

    /**
     * The whole point of the branch: a term closed from the agenda answers with the
     * regions of the agenda. The stream the case page receives targets
     * `deadline-card-{id}`, which does not exist here, so receiving it would leave
     * the row untouched and the counters wrong.
     */
    public function testClosingATermFromTheAgendaAnswersWithTheAgendaRegions(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $deadline = $this->makeDeadline($case, '+3 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->post($deadline, ['_context' => 'agenda'], turbo: true);

        $response = $this->client->getResponse();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/vnd.turbo-stream.html', (string) $response->headers->get('Content-Type'));

        $body = (string) $response->getContent();
        self::assertStringContainsString('target="deadline-riskbar"', $body);
        self::assertStringContainsString('target="deadline-agenda"', $body);
        self::assertStringContainsString('target="toasts"', $body);
        self::assertStringNotContainsString('deadline-card-' . $deadline->getId(), $body);
        self::assertStringNotContainsString('panel-termene', $body);

        $reloaded = $this->em->getRepository(LegalDeadline::class)->find($deadline->getId());
        self::assertTrue($reloaded->isCompleted(), 'The act itself still runs on the shared route.');
    }

    /**
     * The regression that matters most: without the context field the case page must
     * get the same answer it always got. The deadlines tab of a case is untouched by
     * this phase.
     */
    public function testWithoutTheContextFieldTheCasePageStillGetsItsCardStream(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $deadline = $this->makeDeadline($case, '+3 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->post($deadline, [], turbo: true);

        $body = (string) $this->client->getResponse()->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('target="deadline-card-' . $deadline->getId() . '"', $body);
        self::assertStringNotContainsString('deadline-riskbar', $body);
    }

    /** Same rule on the redirect fallback: no context field, no change of destination. */
    public function testWithoutTheContextFieldTheRedirectStillLandsOnTheCase(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $deadline = $this->makeDeadline($case, '+3 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->post($deadline, [], turbo: false);

        self::assertResponseRedirects('/case/' . $case->getId() . '?tab=termene');
    }

    /**
     * A client without Turbo has to land back on the agenda it acted from, with the
     * same selection: the redirect is the only thing that carries it, the browser
     * having posted a form rather than followed a link.
     */
    public function testAClientWithoutTurboLandsBackOnTheFilteredAgenda(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $deadline = $this->makeDeadline($case, '-2 days', DeadlineType::OTHER);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->post($deadline, ['_context' => 'agenda', 'f' => 'overdue,today'], turbo: false);

        self::assertResponseRedirects('/termene?f=overdue,today');
    }

    /**
     * The answer to an action fired under a filter is that same filtered agenda. A
     * stream built without the filter would quietly reset the triage the lawyer set
     * up, which is worse than not updating at all.
     */
    public function testTheAnswerKeepsTheFilterTheActionWasFiredUnder(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $overdue = $this->makeDeadline($case, '-2 days', DeadlineType::OTHER);
        $future = $this->makeDeadline($case, '+10 days', DeadlineType::JUDECATA);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->post($overdue, ['_context' => 'agenda', 'f' => 'overdue'], turbo: true);

        $body = (string) $this->client->getResponse()->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('deadline-row-' . $future->getId(), $body, 'The filter must survive the action.');
    }

    /**
     * The routes that record a communication date go through the same branch. The
     * agenda cannot collect the date itself yet, but the contract has to hold, or the
     * dialog built on top of it would answer into the case page.
     */
    public function testRecordingTheRulingCommunicationDateAnswersTheAgendaToo(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::ORDONANTA_EMISA);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request(
            'POST',
            '/case/' . $case->getId() . '/ruling-communication-date',
            [
                'ruling_communication_date' => [
                    'rulingCommunicationDate' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
                    '_token' => $this->token('ruling_communication_date'),
                ],
                '_context' => 'agenda',
            ],
            [],
            ['HTTP_ACCEPT' => self::TURBO_ACCEPT],
        );

        $body = (string) $this->client->getResponse()->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('target="deadline-agenda"', $body);
        self::assertStringNotContainsString('panel-termene', $body);

        $reloaded = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertNotNull($reloaded->getRulingCommunicationDate());
    }

    /**
     * The pills are the filter, and the filter is in the URL: a pasted link has to
     * rebuild the same agenda. Filtering is done in SQL, so a row outside the
     * selection is absent from the markup rather than hidden in it.
     */
    public function testTheSelectionTravelsInTheUrlAndIsAppliedServerSide(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $overdue = $this->makeDeadline($case, '-3 days', DeadlineType::OTHER);
        $future = $this->makeDeadline($case, '+9 days', DeadlineType::JUDECATA);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene?f=overdue');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#deadline-row-' . $overdue->getId()));
        self::assertCount(0, $crawler->filter('#deadline-row-' . $future->getId()));
        self::assertSame('true', $crawler->filter('[data-counter="overdue"]')->attr('aria-pressed'));
        self::assertSame('false', $crawler->filter('[data-counter="today"]')->attr('aria-pressed'));
    }

    /**
     * The counters describe the whole open agenda even while a pill is pressed: a
     * number that shrank to the selection it produced would measure the click rather
     * than the risk.
     */
    public function testTheCountersDoNotFollowTheSelection(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $this->makeDeadline($case, '-3 days', DeadlineType::OTHER);
        $this->makeDeadline($case, 'today', DeadlineType::JUDECATA);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene?f=overdue');

        self::assertResponseIsSuccessful();
        self::assertSame('1', trim($crawler->filter('[data-counter="overdue"] strong')->text()));
        self::assertSame('1', trim($crawler->filter('[data-counter="today"] strong')->text()));
    }

    /**
     * An empty result under a selection says something about the selection, never
     * about the account, so it must not borrow either the "clean register" line or
     * the state that lists what the application will start watching.
     */
    public function testAnEmptySelectionSaysSoAndOffersTheWayOut(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $this->makeDeadline($case, '+9 days', DeadlineType::JUDECATA);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene?f=overdue');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-state="filter-empty"]'));
        self::assertCount(0, $crawler->filter('[data-state="clean"]'));
        self::assertCount(0, $crawler->filter('[data-state="no-deadlines"]'));
        self::assertCount(1, $crawler->filter('[data-counter="overdue"]'), 'The pills stay reachable.');
    }

    /**
     * Closed terms come back only when asked for, and a closed row offers no way to
     * close it again: the only act left on it is opening the case.
     */
    public function testClosedTermsAppearOnlyWhenAskedForAndCarryNoCloseButton(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $closed = $this->makeDeadline($case, '+4 days', DeadlineType::TIMBRARE);
        $closed->setCompleted(true);
        $this->em->flush();

        $this->client->loginUser($user);

        self::assertCount(0, $this->client->request('GET', '/termene')->filter('#deadline-row-' . $closed->getId()));

        $crawler = $this->client->request('GET', '/termene?completed=1');
        $row = $crawler->filter('#deadline-row-' . $closed->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $row);
        self::assertCount(0, $row->filter('form'));
        self::assertStringContainsString(
            static::getContainer()->get('translator')->trans('deadlines.row.completed'),
            $row->text(),
        );
    }

    /** The acting forms carry the selection, or the answer would silently reset it. */
    public function testTheActingFormsCarryTheContextAndTheSelection(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $this->makeDeadline($case, '-3 days', DeadlineType::OTHER);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene?f=overdue&completed=1');
        $form = $crawler->filter('article[id^="deadline-row-"] form');

        self::assertResponseIsSuccessful();
        self::assertSame('agenda', $form->filter('input[name="_context"]')->attr('value'));
        self::assertSame('overdue', $form->filter('input[name="f"]')->attr('value'));
        self::assertSame('1', $form->filter('input[name="completed"]')->attr('value'));
    }

    /** A manual reminder added without leaving the agenda, on the case that was chosen. */
    public function testAddingAManualDeadlineFromTheAgendaCreatesItOnTheChosenCase(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', '/termene/termen-nou', [
            'agenda_add_deadline' => [
                'legalCase' => (string) $case->getId(),
                'deadlineDate' => (new \DateTimeImmutable('+5 days'))->format('Y-m-d'),
                'description' => 'Depune completare',
                '_token' => $this->token('agenda_add_deadline'),
            ],
        ]);

        self::assertResponseRedirects('/termene');

        $created = $this->em->getRepository(LegalDeadline::class)->findBy(['legalCase' => $case]);
        self::assertCount(1, $created);
        self::assertSame(DeadlineType::OTHER, $created[0]->getType());
        self::assertSame('Depune completare', $created[0]->getDescription());
    }

    /**
     * The case selector is not a permission. A case of another account is not among
     * the choices, so the submission fails before anything is written, and no
     * deadline lands on a file the lawyer has no business touching.
     */
    public function testAManualDeadlineCannotBeAddedOnAnotherAccountsCase(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser('-other');
        $foreignCase = $this->makeCase($other);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', '/termene/termen-nou', [
            'agenda_add_deadline' => [
                'legalCase' => (string) $foreignCase->getId(),
                'deadlineDate' => (new \DateTimeImmutable('+5 days'))->format('Y-m-d'),
                '_token' => $this->token('agenda_add_deadline'),
            ],
        ]);

        self::assertResponseRedirects();
        self::assertSame([], $this->em->getRepository(LegalDeadline::class)->findBy(['legalCase' => $foreignCase]));
    }

    public function testAddingAManualDeadlineIsClosedToAnonymousVisitors(): void
    {
        $this->client->request('POST', '/termene/termen-nou');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /** @param array<string, string> $extra */
    private function post(LegalDeadline $deadline, array $extra, bool $turbo): void
    {
        $this->client->request(
            'POST',
            sprintf('/case/%d/deadline/%d/complete', $deadline->getLegalCase()->getId(), $deadline->getId()),
            ['_token' => $this->token('complete_deadline_' . $deadline->getId())] + $extra,
            [],
            $turbo ? ['HTTP_ACCEPT' => self::TURBO_ACCEPT] : [],
        );
    }

    /**
     * A stateful CSRF token bound to the session of the test client. The page is
     * requested first so that session exists, then the request is put back on the
     * stack for the duration of the generation: the token storage reads the session
     * off it, and the client reuses the same session on the next call through its
     * cookie jar.
     */
    private function token(string $tokenId): string
    {
        $this->client->request('GET', '/termene');
        $request = $this->client->getRequest();

        $stack = static::getContainer()->get(RequestStack::class);
        $stack->push($request);

        try {
            $token = static::getContainer()->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        } finally {
            $stack->pop();
        }

        $request->getSession()->save();

        return $token;
    }

    private function makeUser(string $suffix = ''): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($this->prefix . $suffix . '@test.com');
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
