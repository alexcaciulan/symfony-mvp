<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlinePriority;
use App\Enum\DeadlineType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pas 4.3 — Tests for CaseDeadlineController (3 POST routes).
 *
 * Mark complete: happy + idempotent + 403 + CSRF reject + Turbo Stream response
 * Add deadline: happy OTHER persistat (custom, fără prorogare) + Turbo Stream + redirect
 * Set rulingCommunicationDate: happy trigger CERERE_IN_ANULARE + skip pre-ordonanta + idempotent
 */
final class CaseDeadlineControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('deadline-ctrl-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Deadline');
        $this->user->setLastName('Owner');
        $this->em->persist($this->user);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setAmount('5000.00');
        $this->case->setCurrency('RON');
        $this->case->setDueDate(new \DateTime('2024-03-15'));
        $this->em->persist($this->case);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id IS NULL', []);
        $conn->executeStatement(
            'DELETE d FROM legal_deadline d JOIN legal_case lc ON d.legal_case_id = lc.id WHERE lc.user_id = ?',
            [$userId]
        );
        $conn->executeStatement(
            'DELETE csh FROM case_status_history csh JOIN legal_case lc ON csh.legal_case_id = lc.id WHERE lc.user_id = ?',
            [$userId]
        );
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$userId]);

        parent::tearDown();
    }

    private function createDeadline(DeadlineType $type = DeadlineType::RASPUNS_SOMATIE, ?\DateTimeImmutable $date = null): LegalDeadline
    {
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($this->case);
        $deadline->setType($type);
        $deadline->setDeadlineDate($date ?? new \DateTimeImmutable('2026-12-31'));
        $deadline->setPriority(DeadlinePriority::HIGH);
        $this->em->persist($deadline);
        $this->em->flush();

        return $deadline;
    }

    /**
     * Extrage tokeni CSRF din overview page (DOM) — pattern care evită
     * SessionNotFoundException la apel direct la CsrfTokenManager între requests.
     * Returnează un array indexed cu cele 3 tokens necesare în teste.
     *
     * @return array{complete: array<int, string>, add: string, set_ruling: string, edit: string, delete: array<int, string>}
     */
    private function tokensFromOverview(): array
    {
        $this->client->request('GET', '/case/' . $this->case->getId());
        $crawler = $this->client->getCrawler();

        $complete = [];
        $crawler->filter('button[data-optimistic-action-csrf-token-value]')->each(function ($node) use (&$complete) {
            $url = (string) $node->attr('data-optimistic-action-url-value');
            if (preg_match('#/deadline/(\d+)/complete#', $url, $matches)) {
                $complete[(int) $matches[1]] = (string) $node->attr('data-optimistic-action-csrf-token-value');
            }
        });

        $add = (string) $crawler->filter('input[name="add_deadline[_token]"]')->first()->attr('value');
        $setRuling = (string) $crawler->filter('input[name="ruling_communication_date[_token]"]')->first()->attr('value');

        $editNodes = $crawler->filter('input[name="edit_deadline[_token]"]');
        $edit = $editNodes->count() > 0 ? (string) $editNodes->first()->attr('value') : '';

        $delete = [];
        $crawler->filter('form[action*="/delete"]')->each(function ($form) use (&$delete) {
            if (preg_match('#/deadline/(\d+)/delete#', (string) $form->attr('action'), $m)) {
                $delete[(int) $m[1]] = (string) $form->filter('input[name="_token"]')->attr('value');
            }
        });

        return ['complete' => $complete, 'add' => $add, 'set_ruling' => $setRuling, 'edit' => $edit, 'delete' => $delete];
    }

    public function testEditDeadlineHappyPathUpdatesDateAndDescription(): void
    {
        $this->client->loginUser($this->user);
        $deadline = $this->createDeadline(DeadlineType::OTHER, new \DateTimeImmutable('2026-12-31'));
        $tokens = $this->tokensFromOverview();

        $this->client->request('POST', sprintf('/case/%d/deadline/%d/edit', $this->case->getId(), $deadline->getId()), [
            'edit_deadline' => [
                '_token' => $tokens['edit'],
                'deadlineDate' => '2027-01-15',
                'description' => 'Descriere actualizată',
            ],
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        $updated = $this->em->getRepository(LegalDeadline::class)->find($deadline->getId());
        self::assertSame('2027-01-15', $updated->getDeadlineDate()->format('Y-m-d'));
        self::assertSame('Descriere actualizată', $updated->getDescription());
    }

    public function testEditDeadlineReturnsTurboStreamWhenRequested(): void
    {
        $this->client->loginUser($this->user);
        $deadline = $this->createDeadline(DeadlineType::OTHER, new \DateTimeImmutable('2026-12-31'));
        $tokens = $this->tokensFromOverview();

        $this->client->request(
            'POST',
            sprintf('/case/%d/deadline/%d/edit', $this->case->getId(), $deadline->getId()),
            ['edit_deadline' => ['_token' => $tokens['edit'], 'deadlineDate' => '2027-02-01', 'description' => 'x']],
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="panel-termene"', $body);
        self::assertStringContainsString('close-modal', $body);
    }

    public function testDeleteDeadlineHappyPathRemovesIt(): void
    {
        $this->client->loginUser($this->user);
        $deadline = $this->createDeadline(DeadlineType::OTHER);
        $tokens = $this->tokensFromOverview();

        $this->client->request('POST', sprintf('/case/%d/deadline/%d/delete', $this->case->getId(), $deadline->getId()), [
            '_token' => $tokens['delete'][$deadline->getId()],
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        self::assertNull($this->em->getRepository(LegalDeadline::class)->find($deadline->getId()));
    }

    public function testDeleteDeadlineRejectsInvalidCsrf(): void
    {
        $this->client->loginUser($this->user);
        $deadline = $this->createDeadline(DeadlineType::OTHER);

        $this->client->request('POST', sprintf('/case/%d/deadline/%d/delete', $this->case->getId(), $deadline->getId()), [
            '_token' => 'invalid-token',
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(LegalDeadline::class)->find($deadline->getId()));
    }

    // ===== complete =====================================================

    public function testCompleteHappyPathMarksDeadlineCompleted(): void
    {
        $this->client->loginUser($this->user);
        // PRESCRIPTIE deja creată automat la setUp persist (Pas 4.2 subscriber).
        // Folosim acel deadline pentru test (singurul existent).
        $existing = $this->em->getRepository(LegalDeadline::class)->findOneBy(['legalCase' => $this->case->getId()]);
        self::assertNotNull($existing);

        $tokens = $this->tokensFromOverview();
        $token = $tokens['complete'][$existing->getId()] ?? '';

        $this->client->request('POST', sprintf('/case/%d/deadline/%d/complete', $this->case->getId(), $existing->getId()), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalDeadline::class)->find($existing->getId());
        self::assertTrue($refreshed->isCompleted());
        self::assertSame($this->user->getId(), $refreshed->getCompletedBy()?->getId());
    }

    public function testCompleteRespondsWithTurboStreamOnAcceptHeader(): void
    {
        $this->client->loginUser($this->user);
        $existing = $this->em->getRepository(LegalDeadline::class)->findOneBy(['legalCase' => $this->case->getId()]);
        self::assertNotNull($existing);
        $tokens = $this->tokensFromOverview();
        $token = $tokens['complete'][$existing->getId()] ?? '';

        $this->client->request(
            'POST',
            sprintf('/case/%d/deadline/%d/complete', $this->case->getId(), $existing->getId()),
            ['_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $response = $this->client->getResponse();
        self::assertStringContainsString('turbo-stream', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('action="replace"', (string) $response->getContent());
        self::assertStringContainsString('deadline-card-' . $existing->getId(), (string) $response->getContent());
    }

    public function testCompleteIsIdempotent(): void
    {
        $this->client->loginUser($this->user);
        $existing = $this->em->getRepository(LegalDeadline::class)->findOneBy(['legalCase' => $this->case->getId()]);
        self::assertNotNull($existing);
        $tokens = $this->tokensFromOverview();
        $token = $tokens['complete'][$existing->getId()] ?? '';

        $this->client->request('POST', sprintf('/case/%d/deadline/%d/complete', $this->case->getId(), $existing->getId()), [
            '_token' => $token,
        ]);
        $this->em->clear();
        $firstCompletedAt = $this->em->getRepository(LegalDeadline::class)->find($existing->getId())->getCompletedAt();

        // A doua execuție: reutilizăm același token (CSRF token e session-bound)
        $this->client->request('POST', sprintf('/case/%d/deadline/%d/complete', $this->case->getId(), $existing->getId()), [
            '_token' => $token,
        ]);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalDeadline::class)->find($existing->getId());
        self::assertEquals($firstCompletedAt, $refreshed->getCompletedAt());
    }

    public function testCompleteRejectsInvalidCsrf(): void
    {
        $this->client->loginUser($this->user);
        $existing = $this->em->getRepository(LegalDeadline::class)->findOneBy(['legalCase' => $this->case->getId()]);

        $this->client->request('POST', sprintf('/case/%d/deadline/%d/complete', $this->case->getId(), $existing->getId()), [
            '_token' => 'fake-token',
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalDeadline::class)->find($existing->getId());
        self::assertFalse($refreshed->isCompleted());
    }

    public function testCompleteForbiddenForOtherUser(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $intruder = new User();
        $intruder->setEmail('intruder-' . uniqid() . '@test.com');
        $intruder->setPassword($hasher->hashPassword($intruder, 'password'));
        $intruder->setIsVerified(true);
        $intruder->setFirstName('Intruder');
        $intruder->setLastName('Tester');
        $this->em->persist($intruder);
        $this->em->flush();

        $existing = $this->em->getRepository(LegalDeadline::class)->findOneBy(['legalCase' => $this->case->getId()]);

        try {
            $this->client->loginUser($intruder);
            $this->client->request('POST', sprintf('/case/%d/deadline/%d/complete', $this->case->getId(), $existing->getId()), [
                '_token' => 'any',
            ]);

            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        } finally {
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$intruder->getId()]);
        }
    }

    public function testEditCompletedDeadlineIsRejected(): void
    {
        $this->client->loginUser($this->user);
        $deadline = $this->createDeadline(DeadlineType::OTHER, new \DateTimeImmutable('2026-12-31'));
        $deadline->markCompleted($this->user);
        $this->em->flush();

        $tokens = $this->tokensFromOverview();
        $this->client->request('POST', sprintf('/case/%d/deadline/%d/edit', $this->case->getId(), $deadline->getId()), [
            'edit_deadline' => ['_token' => $tokens['edit'], 'deadlineDate' => '2027-03-03', 'description' => 'x'],
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        $unchanged = $this->em->getRepository(LegalDeadline::class)->find($deadline->getId());
        self::assertSame('2026-12-31', $unchanged->getDeadlineDate()->format('Y-m-d'), 'A completed deadline must not be editable.');
    }

    public function testEditDeadlineForbiddenForOtherUser(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $intruder = new User();
        $intruder->setEmail('intruder-' . uniqid() . '@test.com');
        $intruder->setPassword($hasher->hashPassword($intruder, 'password'));
        $intruder->setIsVerified(true);
        $this->em->persist($intruder);
        $this->em->flush();

        $deadline = $this->createDeadline(DeadlineType::OTHER);

        try {
            $this->client->loginUser($intruder);
            $this->client->request('POST', sprintf('/case/%d/deadline/%d/edit', $this->case->getId(), $deadline->getId()), [
                'edit_deadline' => ['_token' => 'any', 'deadlineDate' => '2027-01-01'],
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        } finally {
            $this->em->getConnection()->executeStatement('DELETE FROM `user` WHERE id = ?', [$intruder->getId()]);
        }
    }

    public function testDeleteDeadlineForbiddenForOtherUser(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $intruder = new User();
        $intruder->setEmail('intruder-' . uniqid() . '@test.com');
        $intruder->setPassword($hasher->hashPassword($intruder, 'password'));
        $intruder->setIsVerified(true);
        $this->em->persist($intruder);
        $this->em->flush();

        $deadline = $this->createDeadline(DeadlineType::OTHER);

        try {
            $this->client->loginUser($intruder);
            $this->client->request('POST', sprintf('/case/%d/deadline/%d/delete', $this->case->getId(), $deadline->getId()), [
                '_token' => 'any',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        } finally {
            $this->em->getConnection()->executeStatement('DELETE FROM `user` WHERE id = ?', [$intruder->getId()]);
        }
    }

    public function testEditCriticalDeadlineResetsAlertFlagsOnDateChange(): void
    {
        $this->client->loginUser($this->user);
        $deadline = $this->createDeadline(DeadlineType::CERERE_IN_ANULARE, new \DateTimeImmutable('2026-07-11'));
        $deadline->setAlertSent7(true);
        $deadline->setAlertSent3(true);
        $this->em->flush();

        $tokens = $this->tokensFromOverview();
        $this->client->request('POST', sprintf('/case/%d/deadline/%d/edit', $this->case->getId(), $deadline->getId()), [
            'edit_deadline' => ['_token' => $tokens['edit'], 'deadlineDate' => '2026-07-13', 'description' => ''],
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        $updated = $this->em->getRepository(LegalDeadline::class)->find($deadline->getId());
        self::assertSame('2026-07-13', $updated->getDeadlineDate()->format('Y-m-d'));
        self::assertFalse($updated->isAlertSent7(), 'Alert flags must reset when the date changes.');
        self::assertFalse($updated->isAlertSent3());
    }

    // ===== add ==========================================================

    public function testAddHearingDeadlineHappyPath(): void
    {
        $this->client->loginUser($this->user);
        $tokens = $this->tokensFromOverview();

        $this->client->request('POST', sprintf('/case/%d/deadline/add', $this->case->getId()), [
            'add_deadline' => [
                '_token' => $tokens['add'],
                'deadlineDate' => (new \DateTime('+30 days'))->format('Y-m-d'),
                'description' => 'Sala C2, ora 11:00',
            ],
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $this->case->getId(),
            'type' => DeadlineType::OTHER,
        ]);
        self::assertCount(1, $deadlines);
        self::assertSame('Sala C2, ora 11:00', $deadlines[0]->getDescription());
    }

    public function testAddDeadlineReturnsTurboStreamWhenRequested(): void
    {
        $this->client->loginUser($this->user);
        $tokens = $this->tokensFromOverview();

        $this->client->request(
            'POST',
            sprintf('/case/%d/deadline/add', $this->case->getId()),
            ['add_deadline' => [
                '_token' => $tokens['add'],
                'deadlineDate' => (new \DateTime('+30 days'))->format('Y-m-d'),
                'description' => 'Custom',
            ]],
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'text/vnd.turbo-stream.html',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
        );
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="panel-termene"', $body);
        self::assertStringContainsString('close-modal', $body);
        self::assertStringContainsString('target="toasts"', $body);
        // The active deadline drives the top KPI card and the Detalii sidebar ring.
        self::assertStringContainsString('target="case-kpi-grid"', $body);
        self::assertStringContainsString('target="case-detalii-sidebar"', $body);
        // The tab nav must refresh so the deadline count badge is not stale.
        self::assertStringContainsString('target="case-tabs-nav"', $body);
    }

    public function testAddDeadlineErrorReturnsToastStreamWhenRequested(): void
    {
        $this->client->loginUser($this->user);
        $tokens = $this->tokensFromOverview();

        $this->client->request(
            'POST',
            sprintf('/case/%d/deadline/add', $this->case->getId()),
            ['add_deadline' => [
                '_token' => $tokens['add'],
                'deadlineDate' => (new \DateTime('-30 days'))->format('Y-m-d'),
                'description' => 'In trecut',
            ]],
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        // Error path: only a toast, no region update and no modal close (stays open to fix).
        self::assertStringContainsString('target="toasts"', $body);
        self::assertStringNotContainsString('target="panel-termene"', $body);
        self::assertStringNotContainsString('close-modal', $body);
    }

    public function testAddDeadlineRejectsPastDate(): void
    {
        $this->client->loginUser($this->user);
        $tokens = $this->tokensFromOverview();

        $this->client->request('POST', sprintf('/case/%d/deadline/add', $this->case->getId()), [
            'add_deadline' => [
                '_token' => $tokens['add'],
                'deadlineDate' => (new \DateTime('-30 days'))->format('Y-m-d'),
                'description' => 'In trecut',
            ],
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $this->case->getId(),
            'type' => DeadlineType::OTHER,
        ]);
        self::assertCount(0, $deadlines, 'Data în trecut trebuie respinsă de validator.');
    }

    // ===== ruling-communication-date ====================================

    public function testSetRulingCommunicationDateCreatesAppealDeadlineWhenOrdonantaEmisa(): void
    {
        $this->case->setStatus(CaseStatus::ORDONANTA_EMISA);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $tokens = $this->tokensFromOverview();

        $this->client->request('POST', sprintf('/case/%d/ruling-communication-date', $this->case->getId()), [
            'ruling_communication_date' => [
                '_token' => $tokens['set_ruling'],
                'rulingCommunicationDate' => '2026-02-02', // luni
            ],
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame('2026-02-02', $refreshed->getRulingCommunicationDate()->format('Y-m-d'));

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $refreshed->getId(),
            'type' => DeadlineType::CERERE_IN_ANULARE,
        ]);
        self::assertCount(1, $deadlines);
        // 2026-02-02 luni + 10 zile = 2026-02-12 joi
        self::assertSame('2026-02-12', $deadlines[0]->getDeadlineDate()->format('Y-m-d'));
    }

    public function testSetRulingCommunicationDateSkipsAppealDeadlineBeforeOrdonantaEmisa(): void
    {
        // Case rămâne în AMIABIL (status la setUp)
        $this->client->loginUser($this->user);
        $tokens = $this->tokensFromOverview();

        $this->client->request('POST', sprintf('/case/%d/ruling-communication-date', $this->case->getId()), [
            'ruling_communication_date' => [
                '_token' => $tokens['set_ruling'],
                'rulingCommunicationDate' => '2026-02-02',
            ],
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame('2026-02-02', $refreshed->getRulingCommunicationDate()->format('Y-m-d'));

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $refreshed->getId(),
            'type' => DeadlineType::CERERE_IN_ANULARE,
        ]);
        self::assertCount(0, $deadlines, 'Status pre-ORDONANTA — CERERE_IN_ANULARE nu trebuie creată.');
    }

    public function testAddDeadlineForbiddenForOtherUser(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $intruder = new User();
        $intruder->setEmail('intruder-add-' . uniqid() . '@test.com');
        $intruder->setPassword($hasher->hashPassword($intruder, 'password'));
        $intruder->setIsVerified(true);
        $intruder->setFirstName('Intruder');
        $intruder->setLastName('Add');
        $this->em->persist($intruder);
        $this->em->flush();

        try {
            $this->client->loginUser($intruder);
            $this->client->request('POST', sprintf('/case/%d/deadline/add', $this->case->getId()), [
                'add_deadline' => [
                    '_token' => 'any',
                    'deadlineDate' => (new \DateTime('+30 days'))->format('Y-m-d'),
                    'description' => 'Sala C2',
                ],
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        } finally {
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$intruder->getId()]);
        }
    }

    public function testAddDeadlineRejectsInvalidCsrf(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', sprintf('/case/%d/deadline/add', $this->case->getId()), [
            'add_deadline' => [
                '_token' => 'invalid-token',
                'deadlineDate' => (new \DateTime('+30 days'))->format('Y-m-d'),
                'description' => 'X',
            ],
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $this->case->getId(),
            'type' => DeadlineType::OTHER,
        ]);
        self::assertCount(0, $deadlines, 'CSRF invalid → form invalid → no deadline created.');
    }

    public function testSetRulingCommunicationDateForbiddenForOtherUser(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $intruder = new User();
        $intruder->setEmail('intruder-ruling-' . uniqid() . '@test.com');
        $intruder->setPassword($hasher->hashPassword($intruder, 'password'));
        $intruder->setIsVerified(true);
        $intruder->setFirstName('Intruder');
        $intruder->setLastName('Ruling');
        $this->em->persist($intruder);
        $this->em->flush();

        try {
            $this->client->loginUser($intruder);
            $this->client->request('POST', sprintf('/case/%d/ruling-communication-date', $this->case->getId()), [
                'ruling_communication_date' => [
                    '_token' => 'any',
                    'rulingCommunicationDate' => '2026-02-02',
                ],
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        } finally {
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$intruder->getId()]);
        }
    }

    public function testSetRulingCommunicationDateRejectsInvalidCsrf(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', sprintf('/case/%d/ruling-communication-date', $this->case->getId()), [
            'ruling_communication_date' => [
                '_token' => 'invalid-token',
                'rulingCommunicationDate' => '2026-02-02',
            ],
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertNull($refreshed->getRulingCommunicationDate(), 'CSRF invalid → field NOT set.');
    }

    public function testSetRulingCommunicationDateIsIdempotent(): void
    {
        $this->case->setStatus(CaseStatus::ORDONANTA_EMISA);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $tokens = $this->tokensFromOverview();

        $this->client->request('POST', sprintf('/case/%d/ruling-communication-date', $this->case->getId()), [
            'ruling_communication_date' => [
                '_token' => $tokens['set_ruling'],
                'rulingCommunicationDate' => '2026-02-02',
            ],
        ]);

        // Re-trimite cu altă dată — câmpul se schimbă, dar CERERE_IN_ANULARE rămâne 1
        $this->client->request('POST', sprintf('/case/%d/ruling-communication-date', $this->case->getId()), [
            'ruling_communication_date' => [
                '_token' => $tokens['set_ruling'],
                'rulingCommunicationDate' => '2026-03-15',
            ],
        ]);

        $this->em->clear();
        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $this->case->getId(),
            'type' => DeadlineType::CERERE_IN_ANULARE,
        ]);
        self::assertCount(1, $deadlines, 'Idempotency: 2 apeluri → un singur CERERE_IN_ANULARE.');
    }
}
