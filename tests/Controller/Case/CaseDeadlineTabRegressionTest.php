<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\CaseStatusHistory;
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
 * The contract the global agenda was built on top of: the deadline routes are shared
 * between the deadlines tab of a case and that agenda, and the only thing telling
 * them apart is the `_context` field the agenda posts. Without that field every one
 * of these routes has to answer exactly what it answered before the agenda existed.
 *
 * The claim is checked route by route rather than on the one action the agenda fires
 * today, because the branch lives in a helper that both `complete()` and
 * `respondDeadline()` call: a change to it reaches add, edit, delete and both
 * communication dates at once, and none of those is reachable from the agenda, so
 * nothing else would notice the day it breaks.
 *
 * Two shapes of the answer are pinned per route, since the branch is consulted on two
 * different conditions: the Turbo Stream a browser receives, meaning which regions it
 * swaps, and the redirect a client without Turbo receives, meaning where it lands.
 */
final class CaseDeadlineTabRegressionTest extends WebTestCase
{
    private const TURBO_ACCEPT = 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml';

    /**
     * Every target the deadlines tab is updated through. A stream that dropped one of
     * these would leave a count, a KPI or the sidebar ring describing the state the
     * case was in before the action.
     */
    private const CASE_STREAM_TARGETS = [
        'panel-termene',
        'summons-communication-alert',
        'case-tabs-nav',
        'case-kpi-grid',
        'case-detalii-sidebar',
        'toasts',
    ];

    /** Targets that exist only on the agenda page and must never reach the case. */
    private const AGENDA_STREAM_TARGETS = ['deadline-riskbar', 'deadline-agenda'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $prefix;
    private User $user;
    private LegalCase $case;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->prefix = 'deadline-tab-regression-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->prefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setStatus(CaseStatus::CERERE_DEPUSA);
        $this->case->setAmount('5000.00');
        $this->case->setCurrency('RON');
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
            'DELETE csh FROM case_status_history csh JOIN legal_case lc ON csh.legal_case_id = lc.id JOIN `user` u ON lc.user_id = u.id WHERE u.email LIKE ?',
            [$this->prefix . '%'],
        );
        $conn->executeStatement(
            'DELETE lc FROM legal_case lc JOIN `user` u ON lc.user_id = u.id WHERE u.email LIKE ?',
            [$this->prefix . '%'],
        );
        $conn->executeStatement('DELETE FROM `user` WHERE email LIKE ?', [$this->prefix . '%']);

        parent::tearDown();
    }

    // ===== Turbo Stream: which regions the tab swaps ======================

    /**
     * Closing a term is the one action the agenda actually fires, so it is where a
     * mistaken branch would surface first. The tab answers with the single card it
     * renders, and with nothing belonging to the agenda.
     */
    public function testClosingATermFromTheTabStillAnswersWithTheCardStream(): void
    {
        $deadline = $this->makeDeadline('+5 days', DeadlineType::TIMBRARE);
        $token = $this->completeToken($deadline);

        $body = $this->postTurbo($this->completeUrl($deadline), ['_token' => $token]);

        self::assertStringContainsString('target="deadline-card-' . $deadline->getId() . '"', $body);
        self::assertStringContainsString('action="replace"', $body);
        $this->assertNoAgendaTargets($body);
    }

    public function testAddingADeadlineFromTheTabStillSwapsEveryCaseRegion(): void
    {
        $token = $this->formToken('add_deadline');

        $body = $this->postTurbo(sprintf('/case/%d/deadline/add', $this->case->getId()), [
            'add_deadline' => [
                '_token' => $token,
                'deadlineDate' => (new \DateTimeImmutable('+30 days'))->format('Y-m-d'),
                'description' => 'Sala C2',
            ],
        ]);

        $this->assertCaseRegionsSwapped($body);
        self::assertStringContainsString('data-close-modal-target-id-value="hs-modal-add-deadline"', $body);
    }

    public function testEditingADeadlineFromTheTabStillSwapsEveryCaseRegion(): void
    {
        $deadline = $this->makeDeadline('+40 days', DeadlineType::OTHER);
        $token = $this->formToken('edit_deadline');

        $body = $this->postTurbo(sprintf('/case/%d/deadline/%d/edit', $this->case->getId(), $deadline->getId()), [
            'edit_deadline' => [
                '_token' => $token,
                'deadlineDate' => (new \DateTimeImmutable('+45 days'))->format('Y-m-d'),
                'description' => 'Mutat',
            ],
        ]);

        $this->assertCaseRegionsSwapped($body);
    }

    public function testDeletingADeadlineFromTheTabStillSwapsEveryCaseRegion(): void
    {
        $deadline = $this->makeDeadline('+40 days', DeadlineType::OTHER);
        $token = $this->deleteToken($deadline);

        $body = $this->postTurbo(
            sprintf('/case/%d/deadline/%d/delete', $this->case->getId(), $deadline->getId()),
            ['_token' => $token],
        );

        $this->assertCaseRegionsSwapped($body);
        self::assertNull($this->em->getRepository(LegalDeadline::class)->find($deadline->getId()));
    }

    /**
     * The date the ruling on the annulment request was served is what the enforcement
     * limitation runs from on a case that went through such a request (CPC art. 705
     * para. 2 read with art. 1024 para. 8). It is collected in a dialog of its own, so
     * the case page has to render both the alert that opens it and the dialog itself,
     * and the route has to close that dialog rather than the one for the first ruling.
     */
    public function testTheCasePageOffersTheAnnulmentRulingDialogAndTheRouteClosesIt(): void
    {
        $this->setStatus(CaseStatus::DEFINITIVA);
        $this->recordAnnulmentPassage();

        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#hs-modal-set-annulment-ruling-communication-date'));
        self::assertCount(
            1,
            $crawler->filter('#annulment-ruling-communication-alert button[data-hs-overlay="#hs-modal-set-annulment-ruling-communication-date"]'),
        );

        $token = $this->formToken('annulment_ruling_communication_date');
        $body = $this->postTurbo(sprintf('/case/%d/annulment-ruling-communication-date', $this->case->getId()), [
            'annulment_ruling_communication_date' => [
                '_token' => $token,
                'annulmentRulingCommunicationDate' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
            ],
        ]);

        $this->assertCaseRegionsSwapped($body);
        self::assertStringContainsString('target="annulment-ruling-communication-alert"', $body);
        self::assertStringContainsString(
            'data-close-modal-target-id-value="hs-modal-set-annulment-ruling-communication-date"',
            $body,
        );

        $stored = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertNotNull($stored?->getAnnulmentRulingCommunicationDate());
    }

    public function testRecordingTheSummonsCommunicationDateFromTheTabStillSwapsEveryCaseRegion(): void
    {
        $this->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $token = $this->formToken('payment_notice_communication_date');

        $body = $this->postTurbo(sprintf('/case/%d/summons-communication-date', $this->case->getId()), [
            'payment_notice_communication_date' => [
                '_token' => $token,
                'paymentNoticeCommunicationDate' => (new \DateTimeImmutable('-2 days'))->format('Y-m-d'),
                'paymentNoticeCommunicationMethod' => 'EXECUTOR',
            ],
        ]);

        $this->assertCaseRegionsSwapped($body);
        self::assertStringContainsString('data-close-modal-target-id-value="hs-modal-set-summons-communication-date"', $body);
    }

    /**
     * The summons alert reads the communication date, and this is the response that
     * writes it, so the swapped region has to carry the state AFTER the write. Asserting
     * the target alone would not catch it: the region can be swapped with the very text
     * it already showed, leaving the case claiming the summons is still in service while
     * the 15-day term it denies is rendered directly underneath.
     */
    public function testRecordingTheSummonsCommunicationDateSwapsTheAlertOutOfItsInServiceState(): void
    {
        $this->setStatus(CaseStatus::SOMATIE_TRIMISA);

        $before = $this->client->request('GET', '/case/' . $this->case->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            $this->trans('case_overview.summons.alert_in_service'),
            $before->filter('#summons-communication-alert')->html(),
        );

        $body = $this->postTurbo(sprintf('/case/%d/summons-communication-date', $this->case->getId()), [
            'payment_notice_communication_date' => [
                '_token' => $this->formToken('payment_notice_communication_date'),
                'paymentNoticeCommunicationDate' => (new \DateTimeImmutable('-2 days'))->format('Y-m-d'),
                'paymentNoticeCommunicationMethod' => 'EXECUTOR',
            ],
        ]);

        self::assertStringNotContainsString($this->trans('case_overview.summons.alert_in_service'), $body);
        self::assertStringContainsString($this->trans('case_overview.summons.alert_attach_proof'), $body);
    }

    private function trans(string $key): string
    {
        return self::getContainer()->get('translator')->trans($key, [], null, 'ro');
    }

    public function testRecordingTheRulingCommunicationDateFromTheTabStillSwapsEveryCaseRegion(): void
    {
        $this->setStatus(CaseStatus::ORDONANTA_EMISA);
        $token = $this->formToken('ruling_communication_date');

        $body = $this->postTurbo(sprintf('/case/%d/ruling-communication-date', $this->case->getId()), [
            'ruling_communication_date' => [
                '_token' => $token,
                'rulingCommunicationDate' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
            ],
        ]);

        $this->assertCaseRegionsSwapped($body);
        self::assertStringContainsString('data-close-modal-target-id-value="hs-modal-set-ruling-communication-date"', $body);
    }

    // ===== Redirect: where a client without Turbo lands ===================

    public function testClosingATermWithoutTurboStillRedirectsToTheTab(): void
    {
        $deadline = $this->makeDeadline('+5 days', DeadlineType::TIMBRARE);

        $this->client->request('POST', $this->completeUrl($deadline), ['_token' => $this->completeToken($deadline)]);

        $this->assertRedirectsToTab();
    }

    public function testAddingADeadlineWithoutTurboStillRedirectsToTheTab(): void
    {
        $token = $this->formToken('add_deadline');

        $this->client->request('POST', sprintf('/case/%d/deadline/add', $this->case->getId()), [
            'add_deadline' => [
                '_token' => $token,
                'deadlineDate' => (new \DateTimeImmutable('+30 days'))->format('Y-m-d'),
                'description' => 'Sala C2',
            ],
        ]);

        $this->assertRedirectsToTab();
    }

    public function testEditingADeadlineWithoutTurboStillRedirectsToTheTab(): void
    {
        $deadline = $this->makeDeadline('+40 days', DeadlineType::OTHER);
        $token = $this->formToken('edit_deadline');

        $this->client->request('POST', sprintf('/case/%d/deadline/%d/edit', $this->case->getId(), $deadline->getId()), [
            'edit_deadline' => [
                '_token' => $token,
                'deadlineDate' => (new \DateTimeImmutable('+45 days'))->format('Y-m-d'),
                'description' => 'Mutat',
            ],
        ]);

        $this->assertRedirectsToTab();
    }

    public function testDeletingADeadlineWithoutTurboStillRedirectsToTheTab(): void
    {
        $deadline = $this->makeDeadline('+40 days', DeadlineType::OTHER);

        $this->client->request(
            'POST',
            sprintf('/case/%d/deadline/%d/delete', $this->case->getId(), $deadline->getId()),
            ['_token' => $this->deleteToken($deadline)],
        );

        $this->assertRedirectsToTab();
    }

    public function testRecordingTheSummonsCommunicationDateWithoutTurboStillRedirectsToTheTab(): void
    {
        $this->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $token = $this->formToken('payment_notice_communication_date');

        $this->client->request('POST', sprintf('/case/%d/summons-communication-date', $this->case->getId()), [
            'payment_notice_communication_date' => [
                '_token' => $token,
                'paymentNoticeCommunicationDate' => (new \DateTimeImmutable('-2 days'))->format('Y-m-d'),
                'paymentNoticeCommunicationMethod' => 'EXECUTOR',
            ],
        ]);

        $this->assertRedirectsToTab();
    }

    public function testRecordingTheRulingCommunicationDateWithoutTurboStillRedirectsToTheTab(): void
    {
        $this->setStatus(CaseStatus::ORDONANTA_EMISA);
        $token = $this->formToken('ruling_communication_date');

        $this->client->request('POST', sprintf('/case/%d/ruling-communication-date', $this->case->getId()), [
            'ruling_communication_date' => [
                '_token' => $token,
                'rulingCommunicationDate' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
            ],
        ]);

        $this->assertRedirectsToTab();
    }

    /**
     * A rejected submission redirects to the tab too. The failure path of `complete()`
     * skips the stream entirely and goes straight to the redirect helper, so it is a
     * second, independent call site of the same decision.
     */
    public function testARejectedSubmissionFromTheTabStillRedirectsToTheTab(): void
    {
        $deadline = $this->makeDeadline('+5 days', DeadlineType::TIMBRARE);

        $this->client->request(
            'POST',
            $this->completeUrl($deadline),
            ['_token' => 'not-the-token'],
            [],
            ['HTTP_ACCEPT' => self::TURBO_ACCEPT],
        );

        $this->assertRedirectsToTab();
        self::assertFalse(
            $this->em->getRepository(LegalDeadline::class)->find($deadline->getId())->isCompleted(),
            'A rejected token must leave the record alone.',
        );
    }

    // ===== The context field is the only switch ===========================

    /**
     * The filter fields of the agenda are ordinary names that could appear in any
     * payload. On their own they must change nothing: the switch is the context field,
     * and a stray `f` or `completed` must not divert the tab onto the agenda answer.
     */
    public function testTheFilterFieldsAloneDoNotDivertTheTabOntoTheAgendaAnswer(): void
    {
        $deadline = $this->makeDeadline('+5 days', DeadlineType::TIMBRARE);
        $token = $this->completeToken($deadline);

        $body = $this->postTurbo($this->completeUrl($deadline), [
            '_token' => $token,
            'f' => 'overdue,today',
            'completed' => '1',
        ]);

        self::assertStringContainsString('target="deadline-card-' . $deadline->getId() . '"', $body);
        $this->assertNoAgendaTargets($body);
    }

    /**
     * The context is matched on its exact value, so anything else is the case page.
     * Pinned because a looser check would make the tab answer with agenda markup on
     * any payload that merely mentions a context.
     */
    public function testOnlyTheExactContextValueSwitchesTheAnswer(): void
    {
        foreach (['case', 'AGENDA', ' agenda', 'agenda-x', ''] as $context) {
            $deadline = $this->makeDeadline('+5 days', DeadlineType::TIMBRARE);
            $token = $this->completeToken($deadline);

            $body = $this->postTurbo($this->completeUrl($deadline), ['_token' => $token, '_context' => $context]);

            self::assertStringContainsString(
                'target="deadline-card-' . $deadline->getId() . '"',
                $body,
                sprintf('The context "%s" is not the agenda.', $context),
            );
            $this->assertNoAgendaTargets($body);
        }
    }

    /** The same rule on the redirect: only the exact value sends the client elsewhere. */
    public function testOnlyTheExactContextValueChangesTheRedirect(): void
    {
        $deadline = $this->makeDeadline('+5 days', DeadlineType::TIMBRARE);

        $this->client->request('POST', $this->completeUrl($deadline), [
            '_token' => $this->completeToken($deadline),
            '_context' => 'agendas',
        ]);

        $this->assertRedirectsToTab();
    }

    // ===== helpers ========================================================

    private function assertRedirectsToTab(): void
    {
        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');
    }

    private function assertCaseRegionsSwapped(string $body): void
    {
        foreach (self::CASE_STREAM_TARGETS as $target) {
            self::assertStringContainsString('target="' . $target . '"', $body, $target . ' must still be swapped.');
        }

        $this->assertNoAgendaTargets($body);
    }

    private function assertNoAgendaTargets(string $body): void
    {
        foreach (self::AGENDA_STREAM_TARGETS as $target) {
            self::assertStringNotContainsString('target="' . $target . '"', $body, $target . ' belongs to the agenda only.');
        }
    }

    private function completeUrl(LegalDeadline $deadline): string
    {
        return sprintf('/case/%d/deadline/%d/complete', $this->case->getId(), $deadline->getId());
    }

    /** @param array<string, mixed> $payload */
    /**
     * Writes the history entry that proves the case entered IN_ANULARE at some point.
     * The identity map is dropped afterwards: the case was created in this test, so its
     * history collection is already initialized and empty, and a row written around it
     * would stay invisible to `hasPassedThroughAnnulment()`.
     */
    private function recordAnnulmentPassage(): void
    {
        $entry = new CaseStatusHistory();
        $entry->setLegalCase($this->case);
        $entry->setOldStatus(CaseStatus::ORDONANTA_EMISA->value);
        $entry->setNewStatus(CaseStatus::IN_ANULARE->value);
        $this->em->persist($entry);
        $this->em->flush();

        $caseId = $this->case->getId();
        $this->em->clear();
        $this->case = $this->em->getRepository(LegalCase::class)->find($caseId);
    }

    private function postTurbo(string $uri, array $payload): string
    {
        $this->client->request('POST', $uri, $payload, [], ['HTTP_ACCEPT' => self::TURBO_ACCEPT]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'text/vnd.turbo-stream.html',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
        );

        return (string) $this->client->getResponse()->getContent();
    }

    private function setStatus(CaseStatus $status): void
    {
        $this->case->setStatus($status);
        $this->em->flush();
    }

    /**
     * A CSRF token read off the rendered case page, which is how the browser gets it.
     * Reading the DOM instead of asking the token manager keeps the token bound to the
     * session the next request will send.
     */
    private function formToken(string $formName): string
    {
        return (string) $this->overview()
            ->filter(sprintf('input[name="%s[_token]"]', $formName))
            ->first()
            ->attr('value');
    }

    private function completeToken(LegalDeadline $deadline): string
    {
        $token = '';
        $this->overview()->filter('button[data-optimistic-action-csrf-token-value]')->each(
            static function (Crawler $node) use (&$token, $deadline): void {
                $url = (string) $node->attr('data-optimistic-action-url-value');
                if (str_contains($url, sprintf('/deadline/%d/complete', $deadline->getId()))) {
                    $token = (string) $node->attr('data-optimistic-action-csrf-token-value');
                }
            },
        );

        self::assertNotSame('', $token, 'The tab must offer the closing control this test posts.');

        return $token;
    }

    private function deleteToken(LegalDeadline $deadline): string
    {
        $token = '';
        $this->overview()->filter('form[action*="/delete"]')->each(
            static function (Crawler $form) use (&$token, $deadline): void {
                if (str_contains((string) $form->attr('action'), sprintf('/deadline/%d/delete', $deadline->getId()))) {
                    $token = (string) $form->filter('input[name="_token"]')->attr('value');
                }
            },
        );

        self::assertNotSame('', $token, 'The tab must offer the delete control this test posts.');

        return $token;
    }

    private function overview(): Crawler
    {
        return $this->client->request('GET', '/case/' . $this->case->getId());
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
        $this->em->flush();

        return $deadline;
    }
}
