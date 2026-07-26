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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * What the agenda context may and may not get through.
 *
 * The page adds a second way to reach routes that already existed, so the question
 * worth asking is not whether those routes are guarded, which the case tab tests
 * already pin, but whether the new field can be used to step around a guard. It
 * cannot: the context is read only after the voter and the token have had their say,
 * and it changes the shape of the answer, never who is allowed to act.
 *
 * A hand written POST is the only way to test this. Everything here is a request the
 * page itself would never produce, which is exactly the point: the buttons of a
 * screen are guidance, and guidance is not enforcement.
 */
final class DeadlineAgendaAuthorizationTest extends WebTestCase
{
    private const TURBO_ACCEPT = 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $prefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->prefix = 'agenda-authz-' . uniqid();
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

    // ===== another account's records ======================================

    /**
     * The agenda scopes its queries by the authenticated user, so a foreign deadline
     * is never on screen. That is a rendering decision, and the route has to refuse it
     * on its own: the voter runs on the case before anything is read or written, and
     * the context field arrives too late to matter.
     */
    public function testClosingAnotherAccountsDeadlineFromTheAgendaIsRefused(): void
    {
        $intruder = $this->makeUser();
        $owner = $this->makeUser('-owner');
        $case = $this->makeCase($owner, CaseStatus::CERERE_DEPUSA);
        $deadline = $this->makeDeadline($case, '+5 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($intruder);
        $this->client->request(
            'POST',
            $this->completeUrl($deadline),
            ['_token' => 'any', '_context' => 'agenda'],
            [],
            ['HTTP_ACCEPT' => self::TURBO_ACCEPT],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertFalse($this->reload($deadline)->isCompleted());
    }

    /**
     * The same refusal without the context field, so the comparison is meaningful: the
     * answer is 403 either way, and the new branch neither hardens nor loosens it.
     */
    public function testTheRefusalIsTheSameWithAndWithoutTheAgendaContext(): void
    {
        $intruder = $this->makeUser();
        $owner = $this->makeUser('-owner');
        $case = $this->makeCase($owner, CaseStatus::CERERE_DEPUSA);
        $deadline = $this->makeDeadline($case, '+5 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($intruder);
        $this->client->request('POST', $this->completeUrl($deadline), ['_token' => 'any']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * The pair in the URL is checked, not just the case. Owning the case in the path
     * is not permission over a deadline filed under someone else's: the deadline is
     * looked up and rejected when it does not belong to that case, which is what stops
     * an id swapped by hand from closing a record on another account.
     */
    public function testADeadlineThatDoesNotBelongToTheCaseInTheUrlIsNotFound(): void
    {
        $lawyer = $this->makeUser();
        $ownCase = $this->makeCase($lawyer, CaseStatus::CERERE_DEPUSA);
        $other = $this->makeUser('-other');
        $foreignDeadline = $this->makeDeadline(
            $this->makeCase($other, CaseStatus::CERERE_DEPUSA),
            '+5 days',
            DeadlineType::TIMBRARE,
        );
        $this->em->flush();

        $this->client->loginUser($lawyer);
        $this->client->request('POST', sprintf('/case/%d/deadline/%d/complete', $ownCase->getId(), $foreignDeadline->getId()), [
            '_token' => $this->token('complete_deadline_' . $foreignDeadline->getId()),
            '_context' => 'agenda',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertFalse($this->reload($foreignDeadline)->isCompleted());
    }

    /** The agenda itself is closed to visitors who are not signed in. */
    public function testTheAgendaIsClosedToAnonymousVisitors(): void
    {
        $this->client->request('GET', '/termene');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    // ===== the token ======================================================

    /**
     * A rejected token writes nothing. The context field does not exempt the request
     * from the check: it is read after it, and only to decide where the answer goes.
     */
    public function testAnInvalidTokenFromTheAgendaClosesNothing(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $deadline = $this->makeDeadline($case, '+5 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', $this->completeUrl($deadline), ['_token' => 'not-the-token', '_context' => 'agenda']);

        self::assertFalse($this->reload($deadline)->isCompleted(), 'An invalid token must leave the record alone.');
    }

    /**
     * Where the rejection lands is the one thing the context does change: back on the
     * agenda, under the same filter, rather than inside the case. The lawyer pressed a
     * button on a triage screen, so a refusal must not also move them off it.
     */
    public function testARejectedTokenSendsTheLawyerBackToTheAgendaNotIntoTheCase(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $deadline = $this->makeDeadline($case, '+5 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request(
            'POST',
            $this->completeUrl($deadline),
            ['_token' => 'not-the-token', '_context' => 'agenda', 'f' => 'fatal30'],
            [],
            ['HTTP_ACCEPT' => self::TURBO_ACCEPT],
        );

        self::assertResponseRedirects('/termene?f=fatal30');
    }

    /**
     * A token minted for one deadline must not close another. The id is part of what
     * is signed (`complete_deadline_{id}`), so a token lifted off a neighbouring row
     * is as good as no token at all.
     */
    public function testATokenMintedForAnotherDeadlineIsRefused(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $target = $this->makeDeadline($case, '+5 days', DeadlineType::TIMBRARE);
        $neighbour = $this->makeDeadline($case, '+6 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', $this->completeUrl($target), [
            '_token' => $this->token('complete_deadline_' . $neighbour->getId()),
            '_context' => 'agenda',
        ]);

        self::assertFalse($this->reload($target)->isCompleted());
        self::assertFalse($this->reload($neighbour)->isCompleted());
    }

    // ===== a fatal term whose consequence may already have occurred =======

    /**
     * The agenda offers no closing control on a fatal term that has passed on a date
     * taken from the record: closing it would only silence the alerts on the one case
     * where silence costs the claim. That is stated on screen first.
     */
    public function testTheAgendaOffersNoClosingControlOnAConsumedFatalTerm(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $deadline = $this->makeDeadline($case, '-3 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $row = $this->client->request('GET', '/termene')->filter('#deadline-row-' . $deadline->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $row);
        self::assertCount(0, $row->filter('form'), 'The row keeps a single way out, into the case.');
        self::assertStringContainsString(
            static::getContainer()->get('translator')->trans('deadlines.row.consequence_consumed'),
            $row->text(),
        );
    }

    /**
     * Posted by hand anyway, the term closes.
     *
     * The decision, and the reason for it: the absence of the button is triage
     * guidance, not an authorization rule, and it is not promoted to one here. Two
     * things follow from that.
     *
     * The route is shared with the deadlines tab of the case, where closing a record
     * is an ordinary act at any stage. Refusing the POST would change that tab, which
     * is not what this page is allowed to do.
     *
     * And the record has to stay closable. A term whose consequence has occurred is
     * still an entry the lawyer has to be able to take off a list of what remains to
     * be done; refusing it would pin the row in the arrears section for good and make
     * the agenda describe work that no longer exists. What must never happen is the
     * platform pretending the consequence did not occur, and nothing here does: the
     * closing is written to the audit trail under the same action as any other, the
     * date and the type are unchanged, and the row comes back on request, dimmed and
     * with its "the consequence may already have occurred" marking intact.
     */
    public function testAConsumedFatalTermPostedByHandIsClosedAndRecorded(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $deadline = $this->makeDeadline($case, '-3 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request(
            'POST',
            $this->completeUrl($deadline),
            ['_token' => $this->token('complete_deadline_' . $deadline->getId()), '_context' => 'agenda'],
            [],
            ['HTTP_ACCEPT' => self::TURBO_ACCEPT],
        );

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('target="deadline-agenda"', (string) $this->client->getResponse()->getContent());

        $closed = $this->reload($deadline);
        self::assertTrue($closed->isCompleted());
        self::assertNotNull($closed->getCompletedAt());
        self::assertSame(
            1,
            (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_id = ?',
                ['deadline_completed', (string) $deadline->getId()],
            ),
            'The closing of a fatal term is recorded like any other.',
        );
    }

    /**
     * And what the closing did not do: the date stands, the type stands, and the row
     * comes back on request still carrying the sanction it was under. Closing is
     * bookkeeping, not absolution.
     *
     * Which of the two markings the second line gets is a decision, so it is written
     * down rather than left to whichever branch happens to come first: on a closed row
     * "finalizat" replaces "consecința s-a putut produce". The row keeps one short
     * marking, and the pair that stays visible, the badge naming the sanction and the
     * "overdue by N days" text, already says the fatal term was missed. Flipping the
     * precedence would print a row that reads as unresolved work.
     */
    public function testClosingAConsumedFatalTermChangesNothingAboutTheConsequence(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $deadline = $this->makeDeadline($case, '-3 days', DeadlineType::TIMBRARE);
        $this->em->flush();
        $dateBefore = $deadline->getDeadlineDate()->format('Y-m-d');

        $this->client->loginUser($user);
        $this->client->request('POST', $this->completeUrl($deadline), [
            '_token' => $this->token('complete_deadline_' . $deadline->getId()),
            '_context' => 'agenda',
        ]);

        $closed = $this->reload($deadline);
        self::assertSame($dateBefore, $closed->getDeadlineDate()->format('Y-m-d'));
        self::assertSame(DeadlineType::TIMBRARE, $closed->getType());

        self::assertCount(
            0,
            $this->client->request('GET', '/termene')->filter('#deadline-row-' . $deadline->getId()),
            'It is off the list of what remains to be done.',
        );

        $translator = static::getContainer()->get('translator');
        $row = $this->client->request('GET', '/termene?completed=1')->filter('#deadline-row-' . $deadline->getId());
        self::assertCount(1, $row);

        $text = $row->text();
        self::assertStringContainsString($translator->trans('deadlines.row.completed'), $text);
        self::assertStringNotContainsString($translator->trans('deadlines.row.consequence_consumed'), $text);
        self::assertStringContainsString(
            $translator->trans('enum.deadline_consequence.CASE_ANNULMENT'),
            $text,
            'The badge still names the sanction the term carried.',
        );
        self::assertStringContainsString(
            $translator->trans('deadlines.row.overdue_by', ['%count%' => 3]),
            $text,
            'And the line still says the term was missed, by how much.',
        );
    }

    // ===== helpers ========================================================

    private function completeUrl(LegalDeadline $deadline): string
    {
        return sprintf('/case/%d/deadline/%d/complete', $deadline->getLegalCase()->getId(), $deadline->getId());
    }

    private function reload(LegalDeadline $deadline): LegalDeadline
    {
        return $this->em->getRepository(LegalDeadline::class)->find($deadline->getId());
    }

    /**
     * A stateful CSRF token bound to the session of the test client. The page is
     * requested first so that session exists, then the request is put back on the
     * stack while the token is generated: the storage reads the session off it, and
     * the client reuses the same session on the next call through its cookie jar.
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
