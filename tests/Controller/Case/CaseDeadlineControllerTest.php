<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\AuditLog;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlinePriority;
use App\Enum\DeadlineType;
use App\Enum\DocumentType;
use App\Enum\PaymentNoticeCommunicationMethod;
use App\Service\AuditLogService;
use App\Service\Deadline\DeadlineAlertService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
    /** @var list<string> temporary upload sources, removed after each test */
    private array $temporaryFiles = [];
    private string $uploadsDir;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';

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

        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
        $this->temporaryFiles = [];
        $caseDir = $this->uploadsDir . '/cases/' . $this->case->getId();
        foreach (glob($caseDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($caseDir);

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = ?', [$userId]);
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

    /** A real PDF on disk, since the upload sniffs the file server-side before accepting it. */
    private function uploadedProof(): UploadedFile
    {
        $path = sys_get_temp_dir() . '/dovada-comunicare-' . uniqid() . '.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, 'DovadaComunicare.pdf', 'application/pdf', null, true);
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
        $setSummons = (string) $crawler->filter('input[name="payment_notice_communication_date[_token]"]')->first()->attr('value');

        $editNodes = $crawler->filter('input[name="edit_deadline[_token]"]');
        $edit = $editNodes->count() > 0 ? (string) $editNodes->first()->attr('value') : '';

        $delete = [];
        $crawler->filter('form[action*="/delete"]')->each(function ($form) use (&$delete) {
            if (preg_match('#/deadline/(\d+)/delete#', (string) $form->attr('action'), $m)) {
                $delete[(int) $m[1]] = (string) $form->filter('input[name="_token"]')->attr('value');
            }
        });

        return ['complete' => $complete, 'add' => $add, 'set_ruling' => $setRuling, 'set_summons' => $setSummons, 'edit' => $edit, 'delete' => $delete];
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

    /**
     * A closable type on purpose. The deadline the case creates on its own is a
     * PRESCRIPTIE, and no screen offers a way to close a limitation term any more, so
     * using it here would test the guard instead of the generic close.
     */
    public function testCompleteHappyPathMarksDeadlineCompleted(): void
    {
        $this->client->loginUser($this->user);
        $existing = $this->createDeadline(DeadlineType::OTHER, new \DateTimeImmutable('2026-12-31'));

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
        $existing = $this->createDeadline(DeadlineType::OTHER, new \DateTimeImmutable('2026-12-31'));
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
        $existing = $this->createDeadline(DeadlineType::OTHER, new \DateTimeImmutable('2026-12-31'));
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
        $existing = $this->createDeadline(DeadlineType::OTHER, new \DateTimeImmutable('2026-12-31'));

        $this->client->request('POST', sprintf('/case/%d/deadline/%d/complete', $this->case->getId(), $existing->getId()), [
            '_token' => 'fake-token',
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalDeadline::class)->find($existing->getId());
        self::assertFalse($refreshed->isCompleted());
    }

    /**
     * The types the generic tick never closes, each with the refusal it earns. The two
     * limitation terms have no manual close at all: they end through the act that
     * interrupts them, recorded by the application. The annulment window does close by a
     * decision of the lawyer, but through a route of its own, so the audit trail can tell
     * a deliberate waiver apart from a lapse and from a generic completion.
     *
     * The screens stopped offering all three after the lawyer review, and the route
     * refuses them as well: it is shared with the agenda, so a page left open in another
     * tab or a hand-made post would otherwise still close a term nothing can bring back.
     *
     * @return iterable<string, array{DeadlineType, string}>
     */
    public static function nonClosableRefusalProvider(): iterable
    {
        yield 'claim limitation' => [DeadlineType::PRESCRIPTIE, 'case_overview.deadlines.flash_error_limitation_no_close'];
        yield 'enforcement limitation' => [DeadlineType::PRESCRIPTIE_EXECUTARE, 'case_overview.deadlines.flash_error_limitation_no_close'];
        yield 'annulment window' => [DeadlineType::CERERE_IN_ANULARE, 'case_overview.deadlines.flash_error_annulment_no_generic_close'];
    }

    /**
     * The same set for the tests that only care which types are refused.
     *
     * @return iterable<string, array{DeadlineType}>
     */
    public static function nonClosableTypeProvider(): iterable
    {
        foreach (self::nonClosableRefusalProvider() as $name => $row) {
            yield $name => [$row[0]];
        }
    }

    #[DataProvider('nonClosableTypeProvider')]
    public function testCompleteRefusesToCloseANonClosableDeadline(DeadlineType $type): void
    {
        $this->client->loginUser($this->user);

        // The exact situation the guard exists for, reproduced rather than simulated: the
        // page was rendered while the term was closable, the term is a non-closable one by
        // the time the button is pressed, and the token is therefore perfectly valid.
        $deadline = $this->createDeadline(DeadlineType::OTHER, new \DateTimeImmutable('2028-12-31'));
        $token = $this->tokensFromOverview()['complete'][$deadline->getId()] ?? '';
        self::assertNotSame('', $token);

        $deadline->setType($type);
        $this->em->flush();

        $this->client->request('POST', sprintf('/case/%d/deadline/%d/complete', $this->case->getId(), $deadline->getId()), [
            '_token' => $token,
        ]);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalDeadline::class)->find($deadline->getId());
        self::assertFalse($refreshed->isCompleted(), sprintf('%s must not be closable by a generic tick.', $type->value));
    }

    public function testTheDeadlinesTabOffersNoCloseOnALimitationTerm(): void
    {
        $this->client->loginUser($this->user);
        $this->createDeadline(DeadlineType::PRESCRIPTIE_EXECUTARE, new \DateTimeImmutable('2029-05-04'));
        $closable = $this->createDeadline(DeadlineType::OTHER, new \DateTimeImmutable('2026-12-31'));

        $tokens = $this->tokensFromOverview();

        self::assertArrayHasKey($closable->getId(), $tokens['complete'], 'An ordinary term keeps its toggle.');
        foreach ($this->em->getRepository(LegalDeadline::class)->findBy(['legalCase' => $this->case->getId()]) as $deadline) {
            if (in_array($deadline->getType(), [DeadlineType::PRESCRIPTIE, DeadlineType::PRESCRIPTIE_EXECUTARE], true)) {
                self::assertArrayNotHasKey($deadline->getId(), $tokens['complete']);
            }
        }
    }

    /**
     * The whole rule in one pass, one row per deadline type: the tick disappeared from
     * the three terms that end through an act of their own and from nothing else. Read
     * off the rendered tab rather than off the template, and driven by the enum, so a
     * type added later is either decided about here or fails.
     */
    public function testTheDeadlinesTabKeepsItsCloseOnEveryTypeButTheNonClosableOnes(): void
    {
        $this->client->loginUser($this->user);

        $nonClosable = array_map(
            static fn (array $row): string => $row[0]->value,
            iterator_to_array(self::nonClosableTypeProvider()),
        );

        $created = [];
        foreach (DeadlineType::cases() as $type) {
            // The case already carries the PRESCRIPTIE its persist created, and the tab
            // renders one card per row, so a second one of the same type would be a
            // duplicate rather than a new observation.
            if ($type === DeadlineType::PRESCRIPTIE) {
                continue;
            }
            $created[$type->value] = $this->createDeadline($type, new \DateTimeImmutable('2028-12-31'))->getId();
        }

        $tokens = $this->tokensFromOverview()['complete'];

        foreach ($created as $value => $deadlineId) {
            $closable = !in_array($value, $nonClosable, true);
            self::assertSame(
                $closable,
                array_key_exists($deadlineId, $tokens),
                sprintf('%s must %sbe closable from the deadlines tab.', $value, $closable ? '' : 'not '),
            );
        }
    }

    /**
     * The refusal is an answer, not a silence. The lawyer pressed something and has to be
     * told why nothing happened, otherwise the only reading left is that the application
     * dropped the click, and the same trace has to be absent from the audit log: an entry
     * saying a limitation term or a forfeiture window was completed is exactly the claim
     * the guard exists to prevent from ever being made.
     */
    #[DataProvider('nonClosableRefusalProvider')]
    public function testTheRefusedCloseSaysWhyAndLeavesNoTrace(DeadlineType $type, string $expectedFlashKey): void
    {
        $this->client->loginUser($this->user);

        $deadline = $this->createDeadline(DeadlineType::OTHER, new \DateTimeImmutable('2028-12-31'));
        $token = $this->tokensFromOverview()['complete'][$deadline->getId()] ?? '';
        $deadline->setType($type);
        $this->em->flush();

        $this->client->request('POST', sprintf('/case/%d/deadline/%d/complete', $this->case->getId(), $deadline->getId()), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');
        self::assertSame(
            [$expectedFlashKey],
            $this->client->getRequest()->getSession()->getFlashBag()->peek('error'),
        );
        self::assertSame([], $this->auditEntriesFor($deadline), 'A refused close must leave nothing behind.');
    }

    /** The same refusal reaches a Turbo client, which never reads a redirect. */
    #[DataProvider('nonClosableTypeProvider')]
    public function testTheRefusedCloseReachesATurboClientAsAnErrorToast(DeadlineType $type): void
    {
        $this->client->loginUser($this->user);

        $deadline = $this->createDeadline(DeadlineType::OTHER, new \DateTimeImmutable('2028-12-31'));
        $token = $this->tokensFromOverview()['complete'][$deadline->getId()] ?? '';
        $deadline->setType($type);
        $this->em->flush();

        $this->client->request(
            'POST',
            sprintf('/case/%d/deadline/%d/complete', $this->case->getId(), $deadline->getId()),
            ['_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('toast', $body);

        $this->em->clear();
        self::assertFalse($this->em->getRepository(LegalDeadline::class)->find($deadline->getId())->isCompleted());
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

    // ===== summons-communication-date ===================================

    public function testSetPaymentNoticeCommunicationDateRecomputesDeadline(): void
    {
        $this->case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->em->flush();
        // Pre-existing estimated RASPUNS_SOMATIE deadline (as set at trimite_somatie).
        $this->createDeadline(DeadlineType::RASPUNS_SOMATIE, new \DateTimeImmutable('2026-01-20'));

        $this->client->loginUser($this->user);
        $tokens = $this->tokensFromOverview();

        $this->client->request('POST', sprintf('/case/%d/summons-communication-date', $this->case->getId()), [
            'payment_notice_communication_date' => [
                '_token' => $tokens['set_summons'],
                'paymentNoticeCommunicationDate' => '2026-02-10',
                'paymentNoticeCommunicationMethod' => 'EXECUTOR',
            ],
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame('2026-02-10', $refreshed->getPaymentNoticeCommunicationDate()->format('Y-m-d'));
        self::assertSame(PaymentNoticeCommunicationMethod::EXECUTOR, $refreshed->getPaymentNoticeCommunicationMethod());

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $refreshed->getId(),
            'type' => DeadlineType::RASPUNS_SOMATIE,
        ]);
        self::assertCount(1, $deadlines);
        // 15 free days (CPC art. 181 alin. 1 pct. 2) = 16 calendar days:
        // 2026-02-10 (Tue) + 16 = 2026-02-26 (Thu, working day).
        self::assertSame('2026-02-26', $deadlines[0]->getDeadlineDate()->format('Y-m-d'));
    }

    /**
     * The same date starts the six months of NCC art. 2540, to which CPC art. 1015
     * alin. 2 refers: past them, the interruption the summons produced is deemed never
     * to have happened. Counted in months (NCC art. 2552), so 10 February 2026 gives
     * 10 August 2026, with no free-days N + 1 and no working-day prorogation.
     */
    public function testSetPaymentNoticeCommunicationDateAlsoCreatesTheSixMonthFilingDeadline(): void
    {
        $this->case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $tokens = $this->tokensFromOverview();

        $this->client->request('POST', sprintf('/case/%d/summons-communication-date', $this->case->getId()), [
            'payment_notice_communication_date' => [
                '_token' => $tokens['set_summons'],
                'paymentNoticeCommunicationDate' => '2026-02-10',
                'paymentNoticeCommunicationMethod' => 'EXECUTOR',
            ],
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $this->case->getId(),
            'type' => DeadlineType::DEPUNERE_CERERE,
        ]);

        self::assertCount(1, $deadlines);
        self::assertSame('2026-08-10', $deadlines[0]->getDeadlineDate()->format('Y-m-d'));
        self::assertNotNull($deadlines[0]->getDescription(), 'The row has to say that what expires is the interruption, not the right to file.');
    }

    /**
     * Served by the post office, the acknowledgement and the date arrive together, so the
     * dialog accepts both and the case is complete after one trip.
     */
    public function testSetSummonsCommunicationDateAlsoStoresTheProofWhenOneIsAttached(): void
    {
        $this->case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $tokens = $this->tokensFromOverview();

        $this->client->request(
            'POST',
            sprintf('/case/%d/summons-communication-date', $this->case->getId()),
            [
                'payment_notice_communication_date' => [
                    '_token' => $tokens['set_summons'],
                    'paymentNoticeCommunicationDate' => '2026-02-10',
                    'paymentNoticeCommunicationMethod' => 'POSTA_RCD',
                ],
            ],
            [
                'payment_notice_communication_date' => [
                    'communicationProof' => $this->uploadedProof(),
                ],
            ],
        );

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame('2026-02-10', $refreshed->getPaymentNoticeCommunicationDate()->format('Y-m-d'));

        $documents = $this->em->getRepository(Document::class)->findBy([
            'legalCase' => $refreshed->getId(),
            'documentType' => DocumentType::DOVADA_COMUNICARE,
        ]);
        self::assertCount(1, $documents, 'The attached file must land on the case as the proof of communication.');
    }

    /**
     * Served by a bailiff, the date is known before the record is issued. Requiring the
     * file would hold back the date, and with it the 15-day term, so the dialog saves the
     * date alone and attaches nothing.
     */
    public function testSetSummonsCommunicationDateWorksWithoutAProof(): void
    {
        $this->case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $tokens = $this->tokensFromOverview();

        $this->client->request('POST', sprintf('/case/%d/summons-communication-date', $this->case->getId()), [
            'payment_notice_communication_date' => [
                '_token' => $tokens['set_summons'],
                'paymentNoticeCommunicationDate' => '2026-02-10',
                'paymentNoticeCommunicationMethod' => 'EXECUTOR',
            ],
        ]);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame('2026-02-10', $refreshed->getPaymentNoticeCommunicationDate()->format('Y-m-d'));
        self::assertCount(
            0,
            $this->em->getRepository(Document::class)->findBy(['legalCase' => $refreshed->getId()]),
            'No file was sent, so nothing may be attached.',
        );
    }

    /**
     * The shape a browser actually sends when the dialog is submitted with nothing
     * chosen in the file input: the multipart part exists and carries UPLOAD_ERR_NO_FILE.
     * That must read as "no proof", not as a rejected upload, otherwise the field added
     * for the postal case would hold back the date, and with it the 15-day term of CPC
     * art. 1015 para. 1, for every case served by a bailiff.
     */
    public function testAnEmptyFilePartStillSavesTheDateAndStartsTheTerm(): void
    {
        $this->case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $tokens = $this->tokensFromOverview();

        $this->client->request(
            'POST',
            sprintf('/case/%d/summons-communication-date', $this->case->getId()),
            [
                'payment_notice_communication_date' => [
                    '_token' => $tokens['set_summons'],
                    'paymentNoticeCommunicationDate' => '2026-02-10',
                    'paymentNoticeCommunicationMethod' => 'EXECUTOR',
                ],
            ],
            [
                'payment_notice_communication_date' => [
                    'communicationProof' => [
                        'name' => '',
                        'type' => '',
                        'tmp_name' => '',
                        'error' => \UPLOAD_ERR_NO_FILE,
                        'size' => 0,
                    ],
                ],
            ],
        );

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame('2026-02-10', $refreshed->getPaymentNoticeCommunicationDate()->format('Y-m-d'));
        self::assertCount(
            0,
            $this->em->getRepository(Document::class)->findBy(['legalCase' => $refreshed->getId()]),
            'An empty file part attaches nothing.',
        );
        self::assertNotNull(
            $this->em->getRepository(LegalDeadline::class)->findOneBy([
                'legalCase' => $refreshed->getId(),
                'type' => DeadlineType::RASPUNS_SOMATIE,
            ]),
            'The date is what creates the term, and it must not wait for a document.',
        );
    }

    public function testSetSummonsCommunicationDateRejectsInvalidCsrf(): void
    {
        $this->case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('POST', sprintf('/case/%d/summons-communication-date', $this->case->getId()), [
            'payment_notice_communication_date' => [
                '_token' => 'invalid-token',
                'paymentNoticeCommunicationDate' => '2026-02-10',
                'paymentNoticeCommunicationMethod' => 'EXECUTOR',
            ],
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId() . '?tab=termene');

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertNull($refreshed->getPaymentNoticeCommunicationDate(), 'CSRF invalid → no date persisted.');
    }

    public function testSetSummonsCommunicationDateForbiddenForOtherUser(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $intruder = new User();
        $intruder->setEmail('intruder-summons-' . uniqid() . '@test.com');
        $intruder->setPassword($hasher->hashPassword($intruder, 'password'));
        $intruder->setIsVerified(true);
        $intruder->setFirstName('Intruder');
        $intruder->setLastName('Summons');
        $this->em->persist($intruder);
        $this->em->flush();

        try {
            $this->client->loginUser($intruder);
            $this->client->request('POST', sprintf('/case/%d/summons-communication-date', $this->case->getId()), [
                'payment_notice_communication_date' => [
                    '_token' => 'any',
                    'paymentNoticeCommunicationDate' => '2026-02-10',
                    'paymentNoticeCommunicationMethod' => 'EXECUTOR',
                ],
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        } finally {
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$intruder->getId()]);
        }
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
        // 10 zile libere (CPC art. 1024 alin. 1 + art. 181 alin. 1 pct. 2) = 11 zile
        // calendaristice: 2026-02-02 luni + 11 = 2026-02-13 vineri.
        self::assertSame('2026-02-13', $deadlines[0]->getDeadlineDate()->format('Y-m-d'));
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

    /**
     * The enforcement-limitation term was closed against the request filed with the
     * bailiff. CPC art. 708 para. 3 says the limitation is NOT interrupted when that
     * enforcement is dismissed, annulled, perimed or abandoned, so the closing has to
     * be reversible and the term comes back with its original date.
     */
    public function testEnforcementThatDidNotInterruptReopensTheExecutionPrescriptionTerm(): void
    {
        $this->case->setStatus(CaseStatus::EXECUTARE);
        $this->case->setEnforcementRequestDate(new \DateTimeImmutable('2026-07-20'));
        $this->case->setEnforcementRegistrationNumber('412/2026');
        $deadline = $this->createDeadline(DeadlineType::PRESCRIPTIE_EXECUTARE, new \DateTimeImmutable('2029-05-04'));
        $deadline->markCompleted(null);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());
        $form = $this->client->getCrawler()->filter('form[action*="/enforcement-not-interrupting"]');
        self::assertCount(1, $form, 'The closed term must offer the way back.');
        $token = (string) $form->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', sprintf('/case/%d/enforcement-not-interrupting', $this->case->getId()), [
            '_token' => $token,
        ]);

        $this->em->clear();
        $refreshedCase = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        $refreshed = $this->em->getRepository(LegalDeadline::class)->find($deadline->getId());

        self::assertNull($refreshedCase->getEnforcementRequestDate());
        self::assertNull($refreshedCase->getEnforcementRegistrationNumber(), 'Both facts the closing rested on are cleared.');
        self::assertFalse($refreshed->isCompleted());
        self::assertNull($refreshed->getCompletedAt());
        self::assertSame('2029-05-04', $refreshed->getDeadlineDate()->format('Y-m-d'), 'The original anchor did not move, only the interruption fell away.');
    }

    public function testEnforcementThatDidNotInterruptRejectsInvalidCsrf(): void
    {
        $this->case->setStatus(CaseStatus::EXECUTARE);
        $this->case->setEnforcementRequestDate(new \DateTimeImmutable('2026-07-20'));
        $this->case->setEnforcementRegistrationNumber('412/2026');
        $deadline = $this->createDeadline(DeadlineType::PRESCRIPTIE_EXECUTARE, new \DateTimeImmutable('2029-05-04'));
        $deadline->markCompleted(null);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('POST', sprintf('/case/%d/enforcement-not-interrupting', $this->case->getId()), [
            '_token' => 'invalid',
        ]);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalDeadline::class)->find($deadline->getId());
        self::assertTrue($refreshed->isCompleted());
        self::assertNotNull($this->em->getRepository(LegalCase::class)->find($this->case->getId())->getEnforcementRequestDate());
    }

    /**
     * The bailiff registration number is what closes the enforcement-limitation term, and
     * the term is closed against the date the request was FILED, not against the day the
     * number arrived: the interruption of CPC art. 708 para. 1 pt. 2 attaches to the
     * request filed and runs from its date.
     */
    public function testTheRegistrationNumberClosesTheEnforcementLimitationTerm(): void
    {
        $this->case->setStatus(CaseStatus::EXECUTARE);
        $this->case->setEnforcementRequestDate(new \DateTimeImmutable('2026-07-20'));
        $deadline = $this->createDeadline(DeadlineType::PRESCRIPTIE_EXECUTARE, new \DateTimeImmutable('2029-05-04'));
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());
        $token = (string) $this->client->getCrawler()
            ->filter('input[name="enforcement_registration_number[_token]"]')->first()->attr('value');

        $this->client->request('POST', sprintf('/case/%d/enforcement-registration-number', $this->case->getId()), [
            'enforcement_registration_number' => [
                '_token' => $token,
                'enforcementRegistrationNumber' => ' 412/2026 ',
            ],
        ]);

        $this->em->clear();
        $refreshedCase = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        $refreshed = $this->em->getRepository(LegalDeadline::class)->find($deadline->getId());

        self::assertSame('412/2026', $refreshedCase->getEnforcementRegistrationNumber());
        self::assertTrue($refreshed->isCompleted());
    }

    /** Without the filing date there is no anchor, so the number alone is refused. */
    public function testTheRegistrationNumberIsRefusedWithoutTheFilingDate(): void
    {
        $this->case->setStatus(CaseStatus::EXECUTARE);
        $this->case->setEnforcementRequestDate(new \DateTimeImmutable('2026-07-20'));
        $deadline = $this->createDeadline(DeadlineType::PRESCRIPTIE_EXECUTARE, new \DateTimeImmutable('2029-05-04'));
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());
        $token = (string) $this->client->getCrawler()
            ->filter('input[name="enforcement_registration_number[_token]"]')->first()->attr('value');

        // The anchor disappears between the page and the post, which is the only way this
        // route can be reached without one: the dialog is not rendered without it.
        $this->case->setEnforcementRequestDate(null);
        $this->em->flush();

        $this->client->request('POST', sprintf('/case/%d/enforcement-registration-number', $this->case->getId()), [
            'enforcement_registration_number' => [
                '_token' => $token,
                'enforcementRegistrationNumber' => '412/2026',
            ],
        ]);

        $this->em->clear();
        self::assertNull($this->em->getRepository(LegalCase::class)->find($this->case->getId())->getEnforcementRegistrationNumber());
        self::assertFalse($this->em->getRepository(LegalDeadline::class)->find($deadline->getId())->isCompleted());
    }

    /**
     * The lawyer states he is not filing an annulment request. The term closes on the
     * spot, and the record says WHO decided, which is what tells this apart from the ten
     * days lapsing on their own.
     */
    public function testDecliningTheAnnulmentRequestClosesTheTerm(): void
    {
        $this->case->setStatus(CaseStatus::ORDONANTA_EMISA);
        $this->case->setRulingCommunicationDate(new \DateTimeImmutable('2026-08-01'));
        $deadline = $this->createDeadline(DeadlineType::CERERE_IN_ANULARE, new \DateTimeImmutable('2026-08-13'));
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());
        $form = $this->client->getCrawler()->filter('form[action*="/no-annulment-request"]');
        self::assertCount(1, $form, 'The open annulment window must offer the decision.');
        $token = (string) $form->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', sprintf('/case/%d/deadline/%d/no-annulment-request', $this->case->getId(), $deadline->getId()), [
            '_token' => $token,
        ]);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalDeadline::class)->find($deadline->getId());
        self::assertTrue($refreshed->isCompleted());
        self::assertSame($this->user->getId(), $refreshed->getCompletedBy()?->getId(), 'A decision is recorded against the lawyer who took it.');
    }

    public function testDecliningTheAnnulmentRequestRejectsInvalidCsrf(): void
    {
        $this->case->setStatus(CaseStatus::ORDONANTA_EMISA);
        $deadline = $this->createDeadline(DeadlineType::CERERE_IN_ANULARE, new \DateTimeImmutable('2026-08-13'));
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('POST', sprintf('/case/%d/deadline/%d/no-annulment-request', $this->case->getId(), $deadline->getId()), [
            '_token' => 'invalid',
        ]);

        $this->em->clear();
        self::assertFalse($this->em->getRepository(LegalDeadline::class)->find($deadline->getId())->isCompleted());
    }

    /** The route belongs to that one window; every other type is a 404, not a close. */
    public function testDecliningTheAnnulmentRequestIsRefusedOnAnotherType(): void
    {
        $this->case->setStatus(CaseStatus::ORDONANTA_EMISA);
        $deadline = $this->createDeadline(DeadlineType::CERERE_IN_ANULARE, new \DateTimeImmutable('2026-08-13'));
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());
        $token = (string) $this->client->getCrawler()
            ->filter('form[action*="/no-annulment-request"] input[name="_token"]')->first()->attr('value');

        $deadline->setType(DeadlineType::OTHER);
        $this->em->flush();

        $this->client->request('POST', sprintf('/case/%d/deadline/%d/no-annulment-request', $this->case->getId(), $deadline->getId()), [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * The decision is attributed to the signed-in lawyer in the log as well, not only on
     * the deadline row. The row keeps the last state and can be reopened by nothing here,
     * while the log is what still answers, after the ten days are long gone, who chose to
     * let the challenge go and when.
     */
    public function testDecliningTheAnnulmentRequestIsLoggedAgainstTheSignedInLawyer(): void
    {
        $this->case->setStatus(CaseStatus::ORDONANTA_EMISA);
        $this->case->setRulingCommunicationDate(new \DateTimeImmutable('2026-08-01'));
        $deadline = $this->createDeadline(DeadlineType::CERERE_IN_ANULARE, new \DateTimeImmutable('2026-08-13'));
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());
        $token = (string) $this->client->getCrawler()
            ->filter('form[action*="/no-annulment-request"] input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', sprintf('/case/%d/deadline/%d/no-annulment-request', $this->case->getId(), $deadline->getId()), [
            '_token' => $token,
        ]);

        $entries = $this->auditEntriesFor($deadline);
        self::assertCount(1, $entries);
        self::assertSame('appeal_deadline_closed', $entries[0]->getAction());
        self::assertSame('annulment_request_waived', $entries[0]->getNewData()['reason'] ?? null);
        self::assertSame($this->user->getId(), $entries[0]->getUser()?->getId());
    }

    /**
     * Recording the number is logged on the CASE, separately from the closing it causes
     * on the deadline. Two facts, two entries: the number is a piece of case data that
     * outlives the term, and the old value travels with it, so a correction to a
     * mistyped number can be told apart from the first entry of a real one.
     */
    public function testRecordingTheRegistrationNumberIsLoggedOnTheCase(): void
    {
        $this->case->setStatus(CaseStatus::EXECUTARE);
        $this->case->setEnforcementRequestDate(new \DateTimeImmutable('2026-07-20'));
        $this->createDeadline(DeadlineType::PRESCRIPTIE_EXECUTARE, new \DateTimeImmutable('2029-05-04'));
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());
        $token = (string) $this->client->getCrawler()
            ->filter('input[name="enforcement_registration_number[_token]"]')->first()->attr('value');

        $this->client->request('POST', sprintf('/case/%d/enforcement-registration-number', $this->case->getId()), [
            'enforcement_registration_number' => [
                '_token' => $token,
                'enforcementRegistrationNumber' => '412/2026',
            ],
        ]);

        $entries = array_values($this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => LegalCase::class,
            'entityId' => (string) $this->case->getId(),
            'action' => 'enforcement_registration_number_set',
        ]));

        self::assertCount(1, $entries);
        self::assertSame($this->user->getId(), $entries[0]->getUser()?->getId());
        self::assertArrayHasKey('enforcementRegistrationNumber', (array) $entries[0]->getOldData());
        self::assertNull($entries[0]->getOldData()['enforcementRegistrationNumber'], 'The case carried no number before.');
        self::assertSame('412/2026', $entries[0]->getNewData()['enforcementRegistrationNumber'] ?? null);
        self::assertSame('2026-07-20', $entries[0]->getNewData()['enforcementRequestDate'] ?? null);
    }

    /**
     * The enforcement can fail before the bailiff ever registers it: dismissed, withdrawn,
     * abandoned. In that window the term is already silent (the filing is declared) but
     * still open (no number yet), and the lawyer needs the way out on the card, not only
     * after the closing. Without it the term would sit unwatched with no visible action.
     */
    public function testTheEnforcementFailedActionIsOfferedWhileTheTermIsStillOpen(): void
    {
        $this->case->setStatus(CaseStatus::EXECUTARE);
        $this->case->setEnforcementRequestDate(new \DateTimeImmutable('2026-07-20'));
        $deadline = $this->createDeadline(DeadlineType::PRESCRIPTIE_EXECUTARE, new \DateTimeImmutable('2029-05-04'));
        $this->em->flush();

        $deadlineId = (int) $deadline->getId();
        self::assertTrue(
            static::getContainer()->get(DeadlineAlertService::class)->alertsMuted($deadline),
            'The declared filing silences the term.',
        );

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        $form = $this->client->getCrawler()->filter('form[action*="/enforcement-not-interrupting"]');
        self::assertCount(1, $form, 'The revert is offered on an open, silenced term.');

        $this->client->request('POST', sprintf('/case/%d/enforcement-not-interrupting', $this->case->getId()), [
            '_token' => (string) $form->filter('input[name="_token"]')->first()->attr('value'),
        ]);

        $this->em->clear();
        $reloaded = $this->em->getRepository(LegalDeadline::class)->find($deadlineId);
        self::assertInstanceOf(LegalDeadline::class, $reloaded);

        self::assertNull($reloaded->getLegalCase()->getEnforcementRequestDate());
        self::assertNull($reloaded->getLegalCase()->getEnforcementRegistrationNumber());
        self::assertFalse($reloaded->isCompleted(), 'It was never closed, so nothing had to be reopened.');
        self::assertFalse(
            static::getContainer()->get(DeadlineAlertService::class)->alertsMuted($reloaded),
            'The term is watched again.',
        );
        self::assertSame('2029-05-04', $reloaded->getDeadlineDate()->format('Y-m-d'), 'The original anchor did not move.');
    }

    /**
     * Every closing ever logged against a deadline.
     *
     * @return list<AuditLog>
     */
    private function auditEntriesFor(LegalDeadline $deadline): array
    {
        return array_values($this->em->getRepository(AuditLog::class)->findBy([
            'category' => AuditLogService::CATEGORY_DEADLINE_COMPLETED,
            'entityType' => LegalDeadline::class,
            'entityId' => (string) $deadline->getId(),
        ], ['id' => 'ASC']));
    }
}
