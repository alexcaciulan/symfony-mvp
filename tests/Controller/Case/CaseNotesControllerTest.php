<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\AuditLog;
use App\Entity\Creditor;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** The lawyer's internal notes, saved from the case page in any status. */
final class CaseNotesControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->user = $this->makeUser();

        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Notes Creditor SRL');
        $creditor->setAddress('Str. Notes 1, Cluj-Napoca');
        $creditor->setCui('RO12345678');
        $this->em->persist($creditor);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setCreditor($creditor);
        $this->case->setStatus(CaseStatus::AMIABIL);
        $this->case->setRelationshipType(RelationshipType::COMERCIAL);
        $this->case->setAmount('1000.00');
        $this->case->setCurrency('RON');
        $this->em->persist($this->case);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->userIds as $userId) {
            $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM case_status_history WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);
        }

        parent::tearDown();
    }

    public function testNotesAreSavedAndTheCardRedrawn(): void
    {
        $this->client->loginUser($this->user);

        $this->post('  Clientul promite plata pe 15.  ');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="case-notes-card"', $body);
        self::assertStringContainsString('>Clientul promite plata pe 15.</textarea>', $body);
        self::assertStringContainsString('Notele au fost salvate.', $body);
        $this->em->clear();
        self::assertSame('Clientul promite plata pe 15.', $this->em->find(LegalCase::class, $this->case->getId())->getNotes());
        self::assertSame(1, $this->em->getRepository(AuditLog::class)->count([
            'entityId' => (string) $this->case->getId(),
            'category' => AuditLogService::CATEGORY_CASE_NOTES,
        ]));
    }

    public function testUnchangedNotesSayNothingChanged(): void
    {
        $this->case->setNotes('Text');
        $this->em->flush();
        $this->client->loginUser($this->user);

        $this->post('Text');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Notele nu s-au modificat.', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->em->getRepository(AuditLog::class)->count(['entityId' => (string) $this->case->getId(), 'category' => AuditLogService::CATEGORY_CASE_NOTES]));
    }

    public function testNotesCanBeEditedOnAClosedCase(): void
    {
        $this->case->setStatus(CaseStatus::INCHIS_SUCCES);
        $this->em->flush();
        $this->client->loginUser($this->user);

        $this->post('Dosar închis, debitorul a plătit integral.');

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertSame('Dosar închis, debitorul a plătit integral.', $this->em->find(LegalCase::class, $this->case->getId())->getNotes());
    }

    public function testAnotherLawyerGets403(): void
    {
        $this->client->loginUser($this->makeUser());

        $this->client->request('POST', $this->route(), ['case_notes' => ['notes' => 'x']], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnAdminGets403(): void
    {
        $admin = $this->makeUser();
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->client->loginUser($admin);

        $this->client->request('POST', $this->route(), ['case_notes' => ['notes' => 'x']], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->em->clear();
        self::assertNull($this->em->find(LegalCase::class, $this->case->getId())->getNotes());
    }

    public function testTooLongNotesAreAToast(): void
    {
        $this->client->loginUser($this->user);

        $this->post(str_repeat('a', 5001));

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Notele au cel mult 5000 de caractere.', $body);
        self::assertStringNotContainsString('target="case-notes-card"', $body, 'The typed text stays in the field.');
        $this->em->clear();
        self::assertNull($this->em->find(LegalCase::class, $this->case->getId())->getNotes());
    }

    public function testInvalidCsrfIsAToast(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', $this->route(), [
            'case_notes' => ['_token' => 'bogus', 'notes' => 'x'],
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Sesiunea a expirat.', $body);
        $this->em->clear();
        self::assertNull($this->em->find(LegalCase::class, $this->case->getId())->getNotes());
    }

    private function post(string $notes): void
    {
        $this->client->request('POST', $this->route(), [
            'case_notes' => ['_token' => $this->token('case_notes'), 'notes' => $notes],
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
    }

    private function route(): string
    {
        return sprintf('/case/%d/notes', $this->case->getId());
    }

    /**
     * A token of the signed-in client's session. The storage needs a request on the
     * stack, so the last request is pushed back for the duration of the call.
     */
    private function token(string $tokenId): string
    {
        $this->client->request('GET', '/case/' . $this->case->getId());
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

    private function makeUser(): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('case-notes-ctrl-' . uniqid() . '@test.com');
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $user->setIsVerified(true);
        $user->setFirstName('Avocat');
        $user->setLastName('Notes');
        $this->em->persist($user);
        $this->em->flush();
        $this->userIds[] = $user->getId();

        return $user;
    }
}
