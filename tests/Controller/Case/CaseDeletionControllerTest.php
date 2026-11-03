<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\LegalDeadline;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use App\Enum\DocumentType;
use App\Enum\NotificationChannel;
use App\Enum\PersonType;
use App\Repository\LegalCaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Deleting a case from its page: the route, its guards, and what the rest of the
 * application shows once the case is gone.
 */
final class CaseDeletionControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private Creditor $creditor;

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->user = $this->makeUser();

        $this->creditor = new Creditor();
        $this->creditor->setUser($this->user);
        $this->creditor->setPersonType(PersonType::PJ);
        $this->creditor->setName('SC Deletion Creditor SRL');
        $this->creditor->setAddress('Str. Test 1, București');
        $this->creditor->setCui('RO12345678');
        $this->em->persist($this->creditor);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->userIds as $userId) {
            $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM notification WHERE user_id = :id', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id OR legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM case_status_history WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM legal_case_debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM debtor WHERE user_id = :id', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);
        }

        parent::tearDown();
    }

    /** @param list<string> $roles */
    private function makeUser(array $roles = []): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setRoles($roles);
        $user->setEmail('case-delete-' . uniqid() . '@test.com');
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $user->setIsVerified(true);
        $user->setFirstName('Avocat');
        $user->setLastName('Deletion');
        $this->em->persist($user);
        $this->em->flush();
        $this->userIds[] = $user->getId();

        return $user;
    }

    private function makeCase(CaseStatus $status = CaseStatus::AMIABIL, ?User $owner = null): LegalCase
    {
        $owner ??= $this->user;

        $debtor = new Debtor();
        $debtor->setUser($owner);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Deletion Debtor SRL');
        $debtor->setAddress('Str. Test 2, București');
        $debtor->setCui('RO87654321');
        $this->em->persist($debtor);

        $case = new LegalCase();
        $case->setUser($owner);
        $case->setStatus($status);
        $case->setCreditor($owner === $this->user ? $this->creditor : null);
        $case->addDebtor(new LegalCaseDebtor($debtor));
        $case->setAmount('4200.00');
        $case->setCurrency('RON');
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    private function makeDeadline(LegalCase $case): LegalDeadline
    {
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($case);
        $deadline->setType(DeadlineType::OTHER);
        $deadline->setDeadlineDate(new \DateTimeImmutable('+5 days'));
        $deadline->setPriority(DeadlineType::OTHER->defaultPriority());
        $deadline->setCompleted(false);
        $this->em->persist($deadline);
        $this->em->flush();

        return $deadline;
    }

    private function makeNotification(LegalCase $case): Notification
    {
        $notification = (new Notification())
            ->setUser($case->getUser())
            ->setLegalCase($case)
            ->setType('deadline_alert')
            ->setChannel(NotificationChannel::IN_APP)
            ->setTitle('Termen pe dosar')
            ->setMessage('Corp mesaj')
            ->setResourceLink('/case/' . $case->getId());
        $this->em->persist($notification);
        $this->em->flush();

        return $notification;
    }

    private function makeStampDutyProof(LegalCase $case): Document
    {
        $document = new Document();
        $document->setLegalCase($case);
        $document->setDocumentType(DocumentType::DOVADA_TAXA_TIMBRU);
        $document->setOriginalFilename('op-taxa-timbru.pdf');
        $document->setStoredFilename('stored-' . uniqid() . '.pdf');
        $document->setFileSize(2048);
        $document->setMimeType('application/pdf');
        $document->setUploadedBy($case->getUser());
        $case->addDocument($document);
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    /**
     * A token of the signed-in client's session. The storage needs a request on the
     * stack, so the last request is pushed back for the duration of the call and its
     * session saved for the next one.
     */
    private function csrfToken(int $caseId): string
    {
        $this->client->request('GET', '/cases');
        $request = $this->client->getRequest();
        $stack = static::getContainer()->get(RequestStack::class);
        $stack->push($request);
        try {
            $token = static::getContainer()->get('security.csrf.token_manager')->getToken('delete_case_' . $caseId)->getValue();
        } finally {
            $stack->pop();
        }
        $request->getSession()->save();

        return $token;
    }

    /** Read straight from the table: the kernel reboots between requests, so the entity in hand may be detached. */
    private function isDeletedInDb(LegalCase $case): bool
    {
        return $this->em->getConnection()->fetchOne('SELECT deleted_at FROM legal_case WHERE id = :id', ['id' => $case->getId()]) !== null;
    }

    /** @param array<string, string> $extra */
    private function postDelete(LegalCase $case, array $extra = [], bool $turbo = false): void
    {
        $this->client->request(
            'POST',
            '/case/' . $case->getId() . '/delete',
            $extra + ['_token' => $this->csrfToken($case->getId())],
            [],
            $turbo ? ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html'] : [],
        );
    }

    public function testDeletionRemovesTheCaseFromEveryList(): void
    {
        $case = $this->makeCase();
        $kept = $this->makeCase();
        $deadline = $this->makeDeadline($case);
        $notification = $this->makeNotification($case);
        $caseId = $case->getId();

        $this->client->loginUser($this->user);
        $this->postDelete($case, ['reason' => 'Deschis din greșeală']);

        self::assertResponseRedirects('/cases');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="alert"]', $case->getCaseNumber());

        $this->em->clear();
        $deleted = $this->em->find(LegalCase::class, $caseId);
        self::assertInstanceOf(LegalCase::class, $deleted);
        self::assertTrue($deleted->isDeleted());
        self::assertTrue($this->em->find(Notification::class, $notification->getId())->isRead());

        // The KPI and the dashboard.
        $cases = static::getContainer()->get(LegalCaseRepository::class);
        self::assertSame(1, $cases->countActiveByUser($this->user));
        $crawler = $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(sprintf('a[href="/case/%d"]', $caseId)));
        self::assertGreaterThan(0, $crawler->filter(sprintf('a[href="/case/%d"]', $kept->getId()))->count());

        // The list of cases.
        $this->client->request('GET', '/api/table/cases');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([$kept->getCaseNumber()], array_column($payload['data'], 'caseNumber'));

        // The deadline agenda.
        $crawler = $this->client->request('GET', '/termene');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#deadline-row-' . $deadline->getId()));
        self::assertCount(0, $crawler->filter(sprintf('a[href="/case/%d"]', $caseId)));

        // The creditor library counts one case fewer.
        $this->client->request('GET', '/api/table/creditors');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(1, $payload['data'][0]['casesCount']);

        // The page and the actions posted to it.
        $this->client->request('GET', '/case/' . $caseId);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->client->request('POST', '/case/' . $caseId . '/transition/full-payment');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->client->request('POST', '/case/' . $caseId . '/delete', ['_token' => 'irrelevant']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testThePageOffersTheDeletionOnlyWhileAmiable(): void
    {
        $case = $this->makeCase();
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/case/' . $case->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="action-delete-case"][data-hs-overlay="#hs-modal-delete-case"]');
        self::assertSelectorExists('#hs-modal-delete-case form[action="/case/' . $case->getId() . '/delete"]');
        self::assertSelectorNotExists('[data-testid="delete-warn-summons-generated"]');
        self::assertSelectorNotExists('[data-testid="delete-warn-stamp-duty-proof"]');

        $case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->em->flush();
        $this->client->request('GET', '/case/' . $case->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="action-delete-case"]');
        self::assertSelectorNotExists('#hs-modal-delete-case');
    }

    /** The proof of a paid stamp duty is the other loss worth naming: the duty is not refunded. */
    public function testTheDialogWarnsWhenTheStampDutyProofIsUploaded(): void
    {
        $case = $this->makeCase();
        $this->makeStampDutyProof($case);
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/case/' . $case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#hs-modal-delete-case [data-testid="delete-warn-stamp-duty-proof"]');
        self::assertSelectorTextContains('[data-testid="delete-warn-stamp-duty-proof"]', 'nu se restituie');
        self::assertSelectorNotExists('[data-testid="delete-warn-summons-generated"]');
        self::assertSelectorNotExists('#hs-modal-delete-case input[name="acknowledge"]');
        self::assertSame('Un document încărcat', $crawler->filter('[data-testid="delete-case-losses"] li')->first()->text());
    }

    /** The admin reads the page through the bypass but is never offered the deletion, nor may post it. */
    public function testTheAdminIsNeitherOfferedNorAllowedTheDeletion(): void
    {
        $case = $this->makeCase();
        $admin = $this->makeUser(['ROLE_ADMIN']);
        $this->client->loginUser($admin);

        $this->client->request('GET', '/case/' . $case->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="action-delete-case"]');
        self::assertSelectorNotExists('#hs-modal-delete-case');

        $this->postDelete($case);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertFalse($this->isDeletedInDb($case));
    }

    public function testRefusedOnceTheSummonsWentOut(): void
    {
        $case = $this->makeCase(CaseStatus::SOMATIE_TRIMISA);
        $this->client->loginUser($this->user);

        $this->postDelete($case);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertFalse($this->isDeletedInDb($case));
    }

    public function testRefusedToAnotherUser(): void
    {
        $case = $this->makeCase();
        $intruder = $this->makeUser();
        $this->client->loginUser($intruder);

        $this->postDelete($case);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertFalse($this->isDeletedInDb($case));
    }

    public function testAnInvalidCsrfTokenAnswersWithAToastAndKeepsTheCase(): void
    {
        $case = $this->makeCase();
        $this->client->loginUser($this->user);

        $this->client->request(
            'POST',
            '/case/' . $case->getId() . '/delete',
            ['_token' => 'not-a-token'],
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html'],
        );

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('target="toasts"', (string) $this->client->getResponse()->getContent());
        self::assertFalse($this->isDeletedInDb($case));
    }

    public function testAnInvalidCsrfTokenWithoutTurboRedirectsBackWithAFlash(): void
    {
        $case = $this->makeCase();
        $this->client->loginUser($this->user);

        $this->postDelete($case, ['_token' => 'not-a-token']);

        self::assertResponseRedirects('/case/' . $case->getId());
        self::assertFalse($this->isDeletedInDb($case));
    }

    public function testTheDialogWarnsAndAsksForAnAcknowledgementOnceTheSummonsWasGenerated(): void
    {
        $case = $this->makeCase();
        $case->setPaymentNoticeDate(new \DateTime('2026-10-20'));
        $this->em->flush();
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/case/' . $case->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="delete-warn-summons-generated"]');
        self::assertSelectorTextContains('[data-testid="delete-warn-summons-generated"]', '20.10.2026');
        self::assertSelectorExists('#hs-modal-delete-case input[name="acknowledge"][required]');

        $this->postDelete($case, [], true);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('target="toasts"', (string) $this->client->getResponse()->getContent());
        self::assertFalse($this->isDeletedInDb($case));

        $this->postDelete($case, ['acknowledge' => '1']);
        self::assertResponseRedirects('/cases');
        self::assertTrue($this->isDeletedInDb($case));
    }

    public function testAReasonLongerThanTheLimitIsRefused(): void
    {
        $case = $this->makeCase();
        $this->client->loginUser($this->user);

        $this->postDelete($case, ['reason' => str_repeat('a', 501)], true);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('target="toasts"', (string) $this->client->getResponse()->getContent());
        self::assertFalse($this->isDeletedInDb($case));
    }

    public function testAnonymousIsSentToLogin(): void
    {
        $case = $this->makeCase();

        $this->client->request('POST', '/case/' . $case->getId() . '/delete');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
