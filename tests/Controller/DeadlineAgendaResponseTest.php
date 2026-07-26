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
 * What the agenda receives back after it acts.
 *
 * The claim the page rests on is that an action answers with the agenda as a fresh
 * load of it would render, not with a patch of the row that was pressed. That is why
 * the answer replaces two whole regions: closing one term moves the counters, the
 * count and the "oldest arrear" hint of its section, whether that section renders at
 * all, the rail and the blockage zone. Here that claim is checked against an actual
 * fresh load rather than against a list of targets, because a stream that swapped the
 * right regions with stale content would pass every other test in the suite.
 */
final class DeadlineAgendaResponseTest extends WebTestCase
{
    private const TURBO_ACCEPT = 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml';

    /** Targets of the case page. None of them exists on the agenda. */
    private const CASE_STREAM_TARGETS = [
        'panel-termene',
        'case-tabs-nav',
        'case-kpi-grid',
        'case-detalii-sidebar',
        'ruling-communication-alert',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $prefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->prefix = 'agenda-response-' . uniqid();
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
     * The agenda answer carries nothing addressed to the case page. Listed target by
     * target rather than checked as "does not contain panel-termene", so a stream that
     * started sending one of the other four would be caught.
     */
    public function testTheAgendaAnswerAddressesNothingOnTheCasePage(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $deadline = $this->makeDeadline($case, '+4 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $body = $this->close($deadline);

        foreach (self::CASE_STREAM_TARGETS as $target) {
            self::assertStringNotContainsString('target="' . $target . '"', $body, $target . ' is not on this page.');
        }
        self::assertStringNotContainsString('deadline-card-', $body);

        foreach (['deadline-riskbar', 'deadline-agenda', 'toasts'] as $target) {
            self::assertStringContainsString('target="' . $target . '"', $body);
        }
    }

    /**
     * The answer equals a fresh load. Both regions are compared against the page the
     * lawyer would get by reloading, so the agenda after an action cannot drift from
     * the agenda after a refresh: the two are built from the same partials, and this
     * is what keeps them that way.
     */
    public function testTheAnswerRebuildsTheRegionsExactlyAsAFreshLoadWould(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $closing = $this->makeDeadline($case, '+4 days', DeadlineType::TIMBRARE);
        $this->makeDeadline($case, '-2 days', DeadlineType::OTHER);
        $this->makeDeadline($case, 'today', DeadlineType::JUDECATA);
        $this->em->flush();

        $this->client->loginUser($user);
        $streamed = $this->close($closing);

        $fresh = $this->freshPage('/termene');

        foreach (['deadline-riskbar', 'deadline-agenda'] as $region) {
            self::assertSame(
                $this->normalize($this->regionOf($fresh, $region)),
                $this->normalize($this->streamedRegion($streamed, $region)),
                sprintf('The streamed %s must equal the one a reload renders.', $region),
            );
        }
    }

    /**
     * The counters are part of what an action moves, which is the whole reason the
     * risk bar is replaced rather than left alone. Closing the only fatal term inside
     * the window has to take its counter down in the same response.
     */
    public function testClosingTheOnlyFatalTermTakesItsCounterDownInTheSameAnswer(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $fatal = $this->makeDeadline($case, '+4 days', DeadlineType::TIMBRARE);
        $this->makeDeadline($case, '+6 days', DeadlineType::JUDECATA);
        $this->em->flush();

        $this->client->loginUser($user);
        self::assertSame('1', $this->counterOn('/termene', 'fatal30'));

        $streamed = $this->close($fatal);

        self::assertMatchesRegularExpression(
            '#data-counter="fatal30".*?<strong[^>]*>\s*0\s*</strong>#s',
            $this->streamedRegion($streamed, 'deadline-riskbar'),
            'The counter must describe the agenda the answer carries, not the one before it.',
        );
        self::assertSame('0', $this->counterOn('/termene', 'fatal30'), 'And a reload agrees.');
    }

    /**
     * The navigation badge counts the same arrears as the "Restante" pill, so an
     * action that moves one has to move the other in the same answer. Left out of the
     * stream the badge would keep claiming an arrear the lawyer had just closed, on
     * the very screen that closed it, until the next full navigation.
     *
     * Addressed by class because the sidebar and the mobile navigation each render
     * one, and both are in the document at the same time.
     */
    public function testClosingAnArrearAlsoTakesTheNavigationBadgeDown(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $arrear = $this->makeDeadline($case, '-3 days', DeadlineType::TIMBRARE);
        $this->makeDeadline($case, '-2 days', DeadlineType::OTHER);
        $this->em->flush();

        $this->client->loginUser($user);
        self::assertSame('2', $this->badgeOn('/termene'), 'Two arrears before the action.');

        $badge = $this->streamedBadge($this->close($arrear));

        self::assertStringContainsString('>1<', $badge, 'The streamed badge must carry the new count.');
        self::assertSame('1', $this->badgeOn('/termene'), 'And a reload agrees.');
    }

    /** The last arrear closed leaves the badge target in place, empty, so it can come back. */
    public function testTheBadgeTargetSurvivesReachingZero(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $onlyArrear = $this->makeDeadline($case, '-3 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $badge = $this->streamedBadge($this->close($onlyArrear));

        self::assertStringContainsString('class="deadline-nav-badge"', $badge);
        self::assertStringNotContainsString('>1<', $badge);
        self::assertNull($this->badgeOn('/termene'), 'Nothing is rendered inside it at zero.');
    }

    /**
     * Under a selection, the answer is that same selection. A stream rebuilt without
     * the filter would quietly undo the triage the lawyer set up, which on a screen
     * whose purpose is triage is worse than not answering at all.
     */
    public function testTheAnswerUnderASelectionIsThatSameSelection(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $overdue = $this->makeDeadline($case, '-2 days', DeadlineType::OTHER);
        $this->makeDeadline($case, '-3 days', DeadlineType::OTHER);
        $fatal = $this->makeDeadline($case, '+4 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $streamed = $this->close($overdue, ['f' => 'overdue']);
        $region = $this->streamedRegion($streamed, 'deadline-agenda');

        self::assertStringNotContainsString('deadline-row-' . $fatal->getId(), $region);
        self::assertStringNotContainsString('deadline-row-' . $overdue->getId(), $region, 'The closed row leaves the list.');
        self::assertSame(
            $this->normalize($this->regionOf($this->freshPage('/termene?f=overdue'), 'deadline-agenda')),
            $this->normalize($region),
        );
    }

    // ===== adding from the global page ====================================

    /**
     * The reminder lands on the case that was picked, and on no other. Pinned with
     * three cases open, because with one the assertion holds even if the route ignores
     * the field entirely.
     */
    public function testTheReminderIsCreatedOnTheChosenCaseAndOnNoOther(): void
    {
        $user = $this->makeUser();
        $first = $this->makeCase($user);
        $chosen = $this->makeCase($user);
        $third = $this->makeCase($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', '/termene/termen-nou', [
            'agenda_add_deadline' => [
                'legalCase' => (string) $chosen->getId(),
                'deadlineDate' => (new \DateTimeImmutable('+8 days'))->format('Y-m-d'),
                'description' => 'Depune completare',
                '_token' => $this->token('agenda_add_deadline'),
            ],
        ]);

        self::assertResponseRedirects('/termene');
        self::assertCount(1, $this->deadlinesOf($chosen));
        self::assertCount(0, $this->deadlinesOf($first));
        self::assertCount(0, $this->deadlinesOf($third));
    }

    /**
     * And it is on the agenda straight after, on the row of the case it was filed
     * under: an entry the lawyer typed that does not turn up in the list would be
     * worse than no entry at all.
     */
    public function testTheAddedReminderShowsOnTheAgendaUnderItsOwnCase(): void
    {
        $user = $this->makeUser();
        $this->makeCase($user);
        $chosen = $this->makeCase($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', '/termene/termen-nou', [
            'agenda_add_deadline' => [
                'legalCase' => (string) $chosen->getId(),
                'deadlineDate' => (new \DateTimeImmutable('+8 days'))->format('Y-m-d'),
                'description' => 'Depune completare',
                '_token' => $this->token('agenda_add_deadline'),
            ],
        ]);

        $created = $this->deadlinesOf($chosen)[0];
        $row = $this->client->request('GET', '/termene')->filter('#deadline-row-' . $created->getId());

        self::assertCount(1, $row);
        self::assertStringContainsString('Depune completare', $row->text());
        self::assertStringContainsString((string) $chosen->getCaseNumber(), $row->text());
    }

    /**
     * A reminder cannot be filed on an account the lawyer does not hold. The selector
     * is a convenience, so the refusal has to come from the route: nothing is written,
     * and the answer is not the page pretending it worked.
     */
    public function testAReminderCannotBeFiledOnAnotherAccountsCase(): void
    {
        $user = $this->makeUser();
        $this->makeCase($user);
        $stranger = $this->makeUser('-stranger');
        $foreign = $this->makeCase($stranger);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request(
            'POST',
            '/termene/termen-nou',
            [
                'agenda_add_deadline' => [
                    'legalCase' => (string) $foreign->getId(),
                    'deadlineDate' => (new \DateTimeImmutable('+8 days'))->format('Y-m-d'),
                    '_token' => $this->token('agenda_add_deadline'),
                ],
                '_context' => 'agenda',
            ],
            [],
            ['HTTP_ACCEPT' => self::TURBO_ACCEPT],
        );

        self::assertCount(0, $this->deadlinesOf($foreign));
        self::assertStringNotContainsString(
            'data-close-modal-target-id-value',
            (string) $this->client->getResponse()->getContent(),
            'A refused submission keeps its dialog open rather than reporting success.',
        );
    }

    /** A token minted for the case dialog does not open the agenda one. */
    public function testTheAgendaAdditionHasATokenOfItsOwn(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', '/termene/termen-nou', [
            'agenda_add_deadline' => [
                'legalCase' => (string) $case->getId(),
                'deadlineDate' => (new \DateTimeImmutable('+8 days'))->format('Y-m-d'),
                '_token' => $this->token('add_deadline'),
            ],
        ]);

        self::assertCount(0, $this->deadlinesOf($case));
    }

    // ===== helpers ========================================================

    /**
     * Closes a deadline from the agenda and returns the stream.
     *
     * @param array<string, string> $filter
     */
    private function close(LegalDeadline $deadline, array $filter = []): string
    {
        $this->client->request(
            'POST',
            sprintf('/case/%d/deadline/%d/complete', $deadline->getLegalCase()->getId(), $deadline->getId()),
            [
                '_token' => $this->token('complete_deadline_' . $deadline->getId()),
                '_context' => 'agenda',
            ] + $filter,
            [],
            ['HTTP_ACCEPT' => self::TURBO_ACCEPT],
        );

        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /** The markup the stream carries for the navigation badge, addressed by class. */
    private function streamedBadge(string $stream): string
    {
        $pattern = '#<turbo-stream action="replace" targets="\.deadline-nav-badge">\s*<template>(.*?)</template>#s';
        self::assertMatchesRegularExpression($pattern, $stream, 'The stream must carry the navigation badge.');
        preg_match($pattern, $stream, $matches);

        return $matches[1];
    }

    /** The number the sidebar badge shows on a fresh load, null when it renders empty. */
    private function badgeOn(string $url): ?string
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $badge = $crawler->filter('.deadline-nav-badge span');

        return $badge->count() > 0 ? trim($badge->first()->text()) : null;
    }

    /** The markup a turbo-stream carries for one target, template wrapper stripped. */
    private function streamedRegion(string $stream, string $target): string
    {
        $pattern = sprintf('#<turbo-stream action="replace" target="%s">\s*<template>(.*?)</template>#s', preg_quote($target, '#'));
        self::assertMatchesRegularExpression($pattern, $stream, 'The stream must carry ' . $target . '.');
        preg_match($pattern, $stream, $matches);

        return $matches[1];
    }

    /**
     * The page as Twig wrote it. The raw body is what the comparison needs: reading it
     * back through the crawler re-serializes the markup, which would turn formatting
     * differences the browser never sees into failures.
     */
    private function freshPage(string $url): string
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /** The same region as it stands in a rendered page. */
    private function regionOf(string $html, string $id): string
    {
        $start = strpos($html, '<div id="' . $id . '"');
        self::assertNotFalse($start, 'The page must contain ' . $id . '.');

        $depth = 0;
        $length = \strlen($html);
        for ($i = $start; $i < $length; ++$i) {
            if (str_starts_with(substr($html, $i, 4), '<div')) {
                ++$depth;
            } elseif (str_starts_with(substr($html, $i, 6), '</div>')) {
                --$depth;
                if ($depth === 0) {
                    return substr($html, $start, $i + 6 - $start);
                }
            }
        }

        self::fail($id . ' is not closed.');
    }

    /**
     * Whitespace and the CSRF tokens are dropped before comparing: the tokens are
     * regenerated per render and are not part of what the two paths must agree on.
     */
    private function normalize(string $markup): string
    {
        $markup = preg_replace('#(name="_token"[^>]*?value=")[^"]*#', '$1', $markup) ?? $markup;
        $markup = preg_replace('#\s+#', ' ', $markup) ?? $markup;

        return trim($markup);
    }

    private function counterOn(string $url, string $counter): string
    {
        return trim($this->client->request('GET', $url)->filter('[data-counter="' . $counter . '"] strong')->text());
    }

    /** @return LegalDeadline[] */
    private function deadlinesOf(LegalCase $case): array
    {
        return $this->em->getRepository(LegalDeadline::class)->findBy(['legalCase' => $case->getId()]);
    }

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
