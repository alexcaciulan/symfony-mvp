<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Controller\Case\CaseLegalCostsController;
use App\Entity\Creditor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Correcting the recovery costs from the case page, until the petition is generated. */
final class CaseLegalCostsControllerTest extends WebTestCase
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
        $creditor->setName('SC Costs Creditor SRL');
        $creditor->setAddress('Str. Costs 1, Cluj-Napoca');
        $creditor->setCui('RO12345678');
        $this->em->persist($creditor);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setCreditor($creditor);
        $this->case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->case->setRelationshipType(RelationshipType::COMERCIAL);
        $this->case->setAmount('1000.00');
        $this->case->setCurrency('RON');
        $this->case->setLegalCostsFixed('500.00');
        $this->case->setLegalCostsCurrency('EUR');
        $this->em->persist($this->case);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->userIds as $userId) {
            $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id OR legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM case_status_history WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);
        }

        parent::tearDown();
    }

    public function testLegalCostsAreSavedInSummonsSentStatus(): void
    {
        $this->client->loginUser($this->user);

        $this->post(['legalCostsFixed' => '1500', 'legalCostsCurrency' => 'RON', 'legalCostsSuccessPercent' => '10']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/vnd.turbo-stream.html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="case-legal-costs-card"', $body);
        self::assertStringContainsString('target="legal-costs-fields"', $body);
        self::assertStringContainsString('1.500,00 RON', $body);
        self::assertStringContainsString('data-close-modal-target-id-value="' . CaseLegalCostsController::MODAL . '"', $body);
        self::assertStringContainsString('Cheltuielile de recuperare au fost salvate.', $body);
        self::assertStringNotContainsString('target="panel-documente"', $body, 'No petition to mark: the documents tab stays as it is.');

        $this->em->clear();
        $case = $this->em->find(LegalCase::class, $this->case->getId());
        self::assertSame('1500.00', $case->getLegalCostsFixed());
        self::assertSame('RON', $case->getLegalCostsCurrency());
        self::assertSame('10.00', $case->getLegalCostsSuccessPercent());
    }

    public function testAnExistingPetitionIsMarkedAndTheDocumentsRedrawn(): void
    {
        $petition = $this->persistDocument(DocumentType::CERERE_OP);
        $this->client->loginUser($this->user);

        $this->post(['legalCostsFixed' => '900', 'legalCostsCurrency' => 'EUR', 'legalCostsSuccessPercent' => '']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="panel-documente"', $body);
        self::assertStringContainsString('data-testid="payment-order-outdated-badge"', $body);
        self::assertStringContainsString('este marcată ca depășită', $body);
        $this->em->clear();
        self::assertTrue($this->em->find(Document::class, $petition->getId())->isOutdated());
    }

    public function testInvalidPercentIsAToast(): void
    {
        $this->client->loginUser($this->user);

        $this->post(['legalCostsFixed' => '1500', 'legalCostsCurrency' => 'RON', 'legalCostsSuccessPercent' => '101']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Procentul onorariului de succes trebuie să fie între 0 și 100.', $body);
        self::assertStringNotContainsString('target="case-legal-costs-card"', $body);
        self::assertStringNotContainsString('close-modal', $body);
        $this->em->clear();
        self::assertSame('500.00', $this->em->find(LegalCase::class, $this->case->getId())->getLegalCostsFixed());
    }

    public function testPastPetitionTheCardIsRedrawnLockedWithAToast(): void
    {
        $token = $this->token('case_legal_costs');
        $this->case->setStatus(CaseStatus::CERERE_GENERATA);
        $this->em->flush();
        $this->client->loginUser($this->user);

        $this->client->request('POST', $this->route(), [
            'legal_costs' => ['_token' => $token, 'legalCostsFixed' => '1500', 'legalCostsCurrency' => 'RON'],
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="case-legal-costs-card"', $body);
        self::assertStringContainsString('data-testid="legal-costs-locked-note"', $body);
        self::assertStringNotContainsString('data-testid="legal-costs-edit"', $body);
        self::assertStringContainsString('data-close-modal-target-id-value="' . CaseLegalCostsController::MODAL . '"', $body);
        self::assertStringContainsString('nu se mai pot modifica după generarea cererii', $body);
        $this->em->clear();
        self::assertSame('500.00', $this->em->find(LegalCase::class, $this->case->getId())->getLegalCostsFixed());
    }

    public function testAnotherLawyerGets403(): void
    {
        $this->client->loginUser($this->makeUser());

        $this->client->request('POST', $this->route(), ['legal_costs' => ['legalCostsFixed' => '1']], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testInvalidCsrfIsAToast(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', $this->route(), [
            'legal_costs' => ['_token' => 'bogus', 'legalCostsFixed' => '1500', 'legalCostsCurrency' => 'RON'],
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="toasts"', $body);
        self::assertStringContainsString('Sesiunea a expirat.', $body);
        self::assertStringNotContainsString('target="case-legal-costs-card"', $body);
        $this->em->clear();
        self::assertSame('500.00', $this->em->find(LegalCase::class, $this->case->getId())->getLegalCostsFixed());
    }

    public function testWithoutTurboAcceptItRedirectsWithFlash(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->token('case_legal_costs');

        $this->client->request('POST', $this->route(), [
            'legal_costs' => ['_token' => $token, 'legalCostsFixed' => '700', 'legalCostsCurrency' => 'RON'],
        ]);

        self::assertResponseRedirects(sprintf('/case/%d', $this->case->getId()));
        $this->client->followRedirect();
        self::assertStringContainsString('Cheltuielile de recuperare au fost salvate.', (string) $this->client->getResponse()->getContent());
    }

    /** @param array<string, string> $fields */
    private function post(array $fields): void
    {
        $this->client->request('POST', $this->route(), [
            'legal_costs' => ['_token' => $this->token('case_legal_costs')] + $fields,
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
    }

    private function route(): string
    {
        return sprintf('/case/%d/legal-costs', $this->case->getId());
    }

    /**
     * A token of the signed-in client's session. The storage needs a request on the
     * stack, so the last request is pushed back for the duration of the call.
     */
    private function token(string $tokenId): string
    {
        $this->client->loginUser($this->user);
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

    private function persistDocument(DocumentType $type): Document
    {
        $document = new Document();
        $document->setLegalCase($this->case);
        $document->setDocumentType($type);
        $document->setOriginalFilename($type->value . '-' . uniqid() . '.pdf');
        $document->setStoredFilename('cases/' . $this->case->getId() . '/' . uniqid() . '.pdf');
        $document->setFileSize(1024);
        $document->setMimeType('application/pdf');
        $document->setUploadedBy($this->user);
        $this->case->addDocument($document);
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    private function makeUser(): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('legal-costs-ctrl-' . uniqid() . '@test.com');
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $user->setIsVerified(true);
        $user->setFirstName('Avocat');
        $user->setLastName('Costs');
        $this->em->persist($user);
        $this->em->flush();
        $this->userIds[] = $user->getId();

        return $user;
    }
}
