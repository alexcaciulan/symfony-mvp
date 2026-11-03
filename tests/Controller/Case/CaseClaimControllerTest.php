<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Controller\Case\CaseClaimController;
use App\Entity\ClaimItem;
use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\User;
use App\Enum\AnafStatus;
use App\Enum\CaseStatus;
use App\Enum\CourtType;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Message\ExtractDataMessage;
use App\Service\Case\ClaimItemFactory;
use App\Service\Document\UploadRateLimiter;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Editing the claim from the case page: the routes, their guards, the Turbo
 * Stream they answer with on success and on refusal, and the reading of an
 * uploaded invoice.
 */
final class CaseClaimControllerTest extends WebTestCase
{
    use CountyFixtureTrait;

    private const CREDITOR_CUI = 'RO12345678';
    private const DEBTOR_CUI = 'RO87654321';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;
    private ClaimItem $first;
    private string $uploadsDir;

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uploadsDir = static::getContainer()->getParameter('app.uploads_dir');

        $this->user = $this->makeUser();

        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Claim Creditor SRL');
        $creditor->setAddress('Str. Claim 1, Cluj-Napoca');
        $creditor->setCui(self::CREDITOR_CUI);
        $this->em->persist($creditor);

        $debtor = new Debtor();
        $debtor->setUser($this->user);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Claim Debtor SRL');
        $debtor->setAddress('Str. Memorandumului 1, Cluj-Napoca');
        $debtor->setAddressCounty('Cluj');
        $debtor->setAddressLocality('Cluj-Napoca');
        $debtor->setCui(self::DEBTOR_CUI);
        $this->em->persist($debtor);
        $link = new LegalCaseDebtor($debtor);
        $link->setAnafStatus(AnafStatus::ACTIV);
        $link->setAnafCheckedAt(new \DateTimeImmutable());
        $link->setInsolvencyCheckedAt(new \DateTimeImmutable());

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setCreditor($creditor);
        $this->case->setStatus(CaseStatus::AMIABIL);
        $this->case->setRelationshipType(RelationshipType::COMERCIAL);
        $this->case->setAmount('1000.00');
        $this->case->setCurrency('RON');
        $this->case->setDueDate(new \DateTime('-100 days'));
        $this->case->addDebtor($link);
        $this->em->persist($this->case);
        $this->first = $this->position('F-1', '1000.00', '-100 days');
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->userIds as $userId) {
            $conn->executeStatement('DELETE FROM claim_item WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id OR legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM case_status_history WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM legal_case_debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM debtor WHERE user_id = :id', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);
        }
        $conn->executeStatement("DELETE FROM court WHERE name LIKE 'Test Court %'");
        $caseDir = $this->uploadsDir . '/cases/' . $this->case->getId();
        if (is_dir($caseDir)) {
            foreach (glob($caseDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($caseDir);
        }

        parent::tearDown();
    }

    // ===== guards =======================================================

    public function testTheButtonsShowOnlyWhileTheCaseIsAmiable(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="claim-add-invoice"]'));
        self::assertCount(1, $crawler->filter('[data-testid="claim-edit-details"]'));
        self::assertCount(1, $crawler->filter('[data-testid="claim-exclude-' . $this->first->getId() . '"]'));
        self::assertCount(1, $crawler->filter('[data-testid="modal-claim-add"]'));
        self::assertCount(1, $crawler->filter('[data-testid="claim-positions-table"]'), 'The table shows even a single position.');

        $this->case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->em->flush();
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertCount(0, $crawler->filter('[data-testid="claim-add-invoice"]'));
        self::assertCount(0, $crawler->filter('[data-testid="claim-exclude-' . $this->first->getId() . '"]'));
        self::assertCount(0, $crawler->filter('[data-testid="modal-claim-add"]'));
        self::assertCount(1, $crawler->filter('[data-testid="claim-locked-note"]'));
        self::assertCount(1, $crawler->filter('[data-testid="claim-positions-table"]'));
    }

    public function testEveryRouteIsForbiddenPastTheAmiableStage(): void
    {
        $this->case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->em->flush();
        $this->client->loginUser($this->user);

        foreach ($this->routes() as $route) {
            $this->client->request('POST', $route, [], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $route);
        }
    }

    public function testEveryRouteIsForbiddenToAnotherUser(): void
    {
        $this->client->loginUser($this->makeUser());

        foreach ($this->routes() as $route) {
            $this->client->request('POST', $route);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $route);
        }
    }

    public function testADeletedCaseIsNotFound(): void
    {
        $this->case->markAsDeleted();
        $this->em->flush();
        $this->client->loginUser($this->user);

        $this->client->request('POST', sprintf('/case/%d/claim/add', $this->case->getId()));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnInvalidTokenIsRefusedWithAToast(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', sprintf('/case/%d/claim/add', $this->case->getId()), [
            'claim_position' => ['_token' => 'bogus', 'documentNumber' => 'F-2', 'dueDate' => '2026-01-01', 'amount' => '10', 'currency' => 'RON'],
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="toasts"', $body);
        self::assertStringNotContainsString('target="case-claim-card"', $body);
        $this->em->clear();
        self::assertSame(1, $this->em->getRepository(ClaimItem::class)->count(['legalCase' => $this->case->getId()]));
    }

    // ===== add manual ===================================================

    public function testAddingAnInvoiceByHandAnswersWithTheRegionsAndTheSummary(): void
    {
        $this->client->loginUser($this->user);

        $this->postPosition(['documentNumber' => 'F-2', 'documentDate' => $this->date('-60 days'), 'dueDate' => $this->date('-30 days'), 'amount' => '500.00', 'currency' => 'RON']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/vnd.turbo-stream.html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="case-claim-card"', $body);
        self::assertStringContainsString('target="case-court-card"', $body);
        self::assertStringContainsString('target="case-kpi-grid"', $body);
        self::assertStringContainsString('target="case-hero"', $body);
        self::assertStringContainsString('target="case-detalii-sidebar"', $body);
        self::assertStringContainsString('target="panel-termene"', $body);
        self::assertStringContainsString('data-close-modal-target-id-value="' . CaseClaimController::MODAL_ADD . '"', $body);
        self::assertStringContainsString('Factura a fost adăugată în creanță.', $body);
        self::assertStringContainsString('Principal: din 1.000,00 RON în 1.500,00 RON', $body);
        self::assertStringContainsString('data-testid="claim-change-summary"', $body);

        $this->em->clear();
        $case = $this->em->find(LegalCase::class, $this->case->getId());
        self::assertSame('1500.00', $case->getAmount());
        self::assertCount(2, $case->getClaimItems());
    }

    public function testAValidationErrorAnswersWithAToastOnlyAndKeepsTheDialog(): void
    {
        $this->client->loginUser($this->user);

        $this->postPosition(['documentNumber' => 'F-2', 'dueDate' => $this->date('-30 days'), 'amount' => '', 'currency' => 'RON']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Suma este obligatorie.', $body);
        self::assertStringNotContainsString('target="case-claim-card"', $body);
        self::assertStringNotContainsString('close-modal', $body);
        $this->em->clear();
        self::assertSame(1, $this->em->getRepository(ClaimItem::class)->count(['legalCase' => $this->case->getId()]));
    }

    public function testADuplicateIsRefusedWithTheExistingPositionNamed(): void
    {
        $this->client->loginUser($this->user);

        $this->postPosition(['documentNumber' => 'F-1', 'documentDate' => $this->date('-130 days'), 'dueDate' => $this->date('-100 days'), 'amount' => '1000.00', 'currency' => 'RON']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Factura F-1 există deja în dosar.', $body);
        self::assertStringNotContainsString('target="case-claim-card"', $body);
    }

    public function testAFutureDueDateIsSavedWithAWarning(): void
    {
        $this->client->loginUser($this->user);

        $this->postPosition(['documentNumber' => 'F-3', 'documentDate' => $this->date('-5 days'), 'dueDate' => $this->date('+10 days'), 'amount' => '200.00', 'currency' => 'RON']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="case-claim-card"', $body);
        self::assertStringContainsString('scadența în viitor', $body);
        $this->em->clear();
        self::assertSame(2, $this->em->getRepository(ClaimItem::class)->count(['legalCase' => $this->case->getId()]));
    }

    public function testAnInvoiceFromAnotherContractAsksForConfirmationInsideTheDialog(): void
    {
        $this->first->setCauseReference('Contract 7/2024');
        $this->em->flush();
        $this->client->loginUser($this->user);

        $this->postPosition(['documentNumber' => 'F-4', 'dueDate' => $this->date('-30 days'), 'amount' => '200.00', 'currency' => 'RON', 'causeReference' => 'CTR 88/2025']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="claim-position-warnings-manual"', $body);
        self::assertStringContainsString('name="claim_position[sameCauseConfirmed]"', $body);
        self::assertStringNotContainsString('target="case-claim-card"', $body);

        $this->postPosition(['documentNumber' => 'F-4', 'dueDate' => $this->date('-30 days'), 'amount' => '200.00', 'currency' => 'RON', 'causeReference' => 'CTR 88/2025', 'sameCauseConfirmed' => '1']);

        self::assertStringContainsString('target="case-claim-card"', (string) $this->client->getResponse()->getContent());
    }

    // ===== exclude / reinclude / details ================================

    public function testExcludeAndReincludeRoundTrip(): void
    {
        $second = $this->position('F-2', '500.00', '-60 days');
        $this->em->flush();
        $this->client->loginUser($this->user);

        $this->client->request('POST', sprintf('/case/%d/claim/item/%d/exclude', $this->case->getId(), $second->getId()), [
            'claim_exclude' => ['_token' => $this->token('claim_exclude'), 'reason' => 'paid_in_full', 'note' => 'paid on 20.06.2025'],
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="case-claim-card"', $body);
        self::assertStringContainsString('data-close-modal-target-id-value="' . CaseClaimController::MODAL_EXCLUDE . '"', $body);
        self::assertStringContainsString('Plătită integral: paid on 20.06.2025', $body);
        self::assertStringContainsString('data-testid="claim-reinclude-' . $second->getId() . '"', $body);
        $this->em->clear();
        self::assertTrue($this->em->find(ClaimItem::class, $second->getId())->isExcludedByLawyer());

        $this->client->request('POST', sprintf('/case/%d/claim/item/%d/reinclude', $this->case->getId(), $second->getId()), [
            '_token' => $this->token('claim_reinclude_' . $second->getId()),
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Factura a fost reinclusă în creanță.', (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertFalse($this->em->find(ClaimItem::class, $second->getId())->isExcludedByLawyer());
    }

    public function testExcludingTheOnlyPositionIsRefused(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', sprintf('/case/%d/claim/item/%d/exclude', $this->case->getId(), $this->first->getId()), [
            'claim_exclude' => ['_token' => $this->token('claim_exclude'), 'reason' => 'disputed'],
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('singura care intră în creanță', $body);
        self::assertStringNotContainsString('target="case-claim-card"', $body);
    }

    public function testAPositionOfAnotherCaseIsNotFound(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('POST', sprintf('/case/%d/claim/item/%d/exclude', $this->case->getId(), 999999), [
            'claim_exclude' => ['_token' => $this->token('claim_exclude'), 'reason' => 'disputed'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTheDetailsAreSavedAndTheDialogRefilled(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', sprintf('/case/%d/claim/details', $this->case->getId()), [
            'claim_details' => ['_token' => $this->token('claim_details'), 'claimDescription' => 'Lucrări', 'contractReference' => 'Contract 2/2024', 'paymentNoticeNumber' => '114/2026'],
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="claim-details-fields"', $body);
        self::assertStringContainsString('value="Contract 2/2024"', $body);
        self::assertStringContainsString('data-close-modal-target-id-value="' . CaseClaimController::MODAL_DETAILS . '"', $body);
        $this->em->clear();
        $case = $this->em->find(LegalCase::class, $this->case->getId());
        self::assertSame('Lucrări', $case->getClaimDescription());
        self::assertSame('114/2026', $case->getPaymentNoticeNumber());
    }

    // ===== court ========================================================

    public function testAcceptingACourtNobodyProposedIsRefused(): void
    {
        $local = $this->court('Judecătoria Cluj-Napoca');
        $this->case->setCourt($local);
        $this->em->flush();
        $this->client->loginUser($this->user);

        $other = new Court();
        $other->setName('Test Court ' . uniqid());
        $other->setCounty($this->createCounty($this->em, 'Cluj'));
        $other->setType(CourtType::JUDECATORIE);
        $this->em->persist($other);
        $this->em->flush();

        $this->client->request('POST', sprintf('/case/%d/claim/court/accept', $this->case->getId()), [
            '_token' => $this->token('claim_court_accept_' . $this->case->getId()),
            'court_id' => $other->getId(),
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Propunerea de instanță nu mai corespunde', (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertSame($local->getId(), $this->em->find(LegalCase::class, $this->case->getId())->getCourt()?->getId());
    }

    public function testCrossingTheThresholdProposesTheTribunalAndAcceptingItWritesTheCourt(): void
    {
        $local = $this->court('Judecătoria Cluj-Napoca');
        $this->case->setCourt($local);
        $this->first->setAmount('150000.00');
        $this->first->setAmountRon('150000.00');
        $this->first->setCauseReference('Contract 7/2024');
        $this->case->setAmount('150000.00');
        $this->em->flush();
        $this->client->loginUser($this->user);

        $this->postPosition(['documentNumber' => 'F-2', 'documentDate' => $this->date('-80 days'), 'dueDate' => $this->date('-50 days'), 'amount' => '100000.00', 'currency' => 'RON', 'causeReference' => 'Contract 7/2024']);

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="claim-court-proposal"', $body);
        self::assertStringContainsString('Tribunalul Specializat Cluj', $body);
        $this->em->clear();
        self::assertSame($local->getId(), $this->em->find(LegalCase::class, $this->case->getId())->getCourt()?->getId(), 'Never changed on its own.');

        $tribunal = $this->court('Tribunalul Specializat Cluj');
        $this->client->request('POST', sprintf('/case/%d/claim/court/accept', $this->case->getId()), [
            '_token' => $this->token('claim_court_accept_' . $this->case->getId()),
            'court_id' => $tribunal->getId(),
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Instanța dosarului este acum Tribunalul Specializat Cluj', (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertSame($tribunal->getId(), $this->em->find(LegalCase::class, $this->case->getId())->getCourt()?->getId());
    }

    // ===== upload and reading ===========================================

    public function testUploadingAnInvoiceStoresItAndDispatchesTheReading(): void
    {
        $this->client->loginUser($this->user);

        $this->uploadInvoice($this->makePdf('factura-F-2025-0210.pdf'));

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="claim-invoice-status"', $body);
        self::assertStringContainsString('data-claim-invoice-state="processing"', $body);
        self::assertStringContainsString('data-controller="poll-frame mercure claim-invoice-status"', $body);

        $this->em->clear();
        $documents = $this->em->getRepository(Document::class)->findBy(['legalCase' => $this->case->getId()]);
        self::assertCount(1, $documents);
        self::assertSame(DocumentType::FACTURA, $documents[0]->getDocumentType());
        self::assertSame(ExtractionStatus::PENDING, $documents[0]->getExtractionStatus());

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(ExtractDataMessage::class, $message);
        self::assertSame($documents[0]->getId(), $message->documentId);
    }

    public function testTheAttachmentCapRefusesTheUploadWithoutDispatching(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->persistDocument(DocumentType::DOVADA);
        }
        $this->client->loginUser($this->user);

        $this->uploadInvoice($this->makePdf());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Limita de 10 atașamente', (string) $this->client->getResponse()->getContent());
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertCount(0, $transport->getSent());
        $this->em->clear();
        self::assertSame(10, $this->em->getRepository(Document::class)->count(['legalCase' => $this->case->getId()]));
    }

    public function testTheSameFileTwiceIsStoredOnce(): void
    {
        $this->client->loginUser($this->user);

        $this->uploadInvoice($this->makePdf('f.pdf', 'same-bytes'));
        $this->uploadInvoice($this->makePdf('f-again.pdf', 'same-bytes'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('este deja încărcat în acest dosar', (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertSame(1, $this->em->getRepository(Document::class)->count(['legalCase' => $this->case->getId()]));
    }

    public function testACompletedReadingPrefillsTheForm(): void
    {
        $document = $this->invoiceDocument(ExtractionStatus::COMPLETED, [
            'creditor' => ['cui' => self::CREDITOR_CUI, 'name' => 'SC Claim Creditor SRL'],
            'debtor' => ['cui' => self::DEBTOR_CUI, 'name' => 'SC Claim Debtor SRL'],
            'claim' => ['invoiceNumber' => 'F-2025-0210', 'invoiceDate' => '2025-05-20', 'dueDate' => '2025-06-14', 'amount' => 12500, 'currency' => 'RON'],
        ]);
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', sprintf('/case/%d/claim/invoice/%d', $this->case->getId(), $document->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame('ready', $crawler->filter('#claim-invoice-status-body')->attr('data-claim-invoice-state'));
        self::assertSame('F-2025-0210', $crawler->filter('input[name="claim_position[documentNumber]"]')->attr('value'));
        self::assertSame('2025-06-14', $crawler->filter('input[name="claim_position[dueDate]"]')->attr('value'));
        self::assertSame('12500.00', $crawler->filter('input[name="claim_position[amount]"]')->attr('value'));
        self::assertSame((string) $document->getId(), $crawler->filter('input[name="claim_position[sourceDocumentId]"]')->attr('value'));
        self::assertStringContainsString('corespunde dosarului', $crawler->filter('[data-testid="claim-party-check"]')->text());
        self::assertStringContainsString('/claim/invoice/' . $document->getId() . '/add', (string) $crawler->filter('form[data-testid="claim-position-form-document"]')->attr('action'));

        $this->client->request('POST', sprintf('/case/%d/claim/invoice/%d/add', $this->case->getId(), $document->getId()), [
            'claim_position' => ['_token' => $this->token('claim_position'), 'documentNumber' => 'F-2025-0210', 'documentDate' => '2025-05-20', 'dueDate' => '2025-06-14', 'amount' => '12500.00', 'currency' => 'RON', 'sourceDocumentId' => (string) $document->getId()],
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('target="case-claim-card"', (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        $added = $this->em->getRepository(ClaimItem::class)->findOneBy(['legalCase' => $this->case->getId(), 'documentNumber' => 'F-2025-0210']);
        self::assertInstanceOf(ClaimItem::class, $added);
        self::assertSame($document->getId(), $added->getSourceDocument()?->getId());
    }

    public function testAFailedOrSkippedReadingOffersTheEmptyFormWithTheDocumentAttached(): void
    {
        $this->client->loginUser($this->user);
        foreach ([[ExtractionStatus::FAILED, 'nu a putut fi citit'], [ExtractionStatus::SKIPPED_BY_POLICY, 'Citirea automată este dezactivată']] as [$status, $message]) {
            $document = $this->invoiceDocument($status, null);

            $crawler = $this->client->request('GET', sprintf('/case/%d/claim/invoice/%d', $this->case->getId(), $document->getId()));

            self::assertResponseIsSuccessful();
            self::assertStringContainsString($message, $crawler->filter('[data-testid="claim-invoice-read-failed"]')->text());
            self::assertSame('', (string) $crawler->filter('input[name="claim_position[documentNumber]"]')->attr('value'));
            self::assertSame((string) $document->getId(), $crawler->filter('input[name="claim_position[sourceDocumentId]"]')->attr('value'));
            self::assertSame('false', $crawler->filter('#claim-invoice-status-body')->attr('data-poll-frame-pending-value'));
        }
    }

    public function testAnInvoiceBetweenOtherPartiesIsRefusedWithoutTouchingTheFile(): void
    {
        $document = $this->invoiceDocument(ExtractionStatus::COMPLETED, [
            'creditor' => ['cui' => 'RO11223344', 'name' => 'SC Gamma Trade SRL'],
            'debtor' => ['cui' => self::DEBTOR_CUI, 'name' => 'SC Claim Debtor SRL'],
            'claim' => ['invoiceNumber' => 'G-1', 'amount' => 100, 'currency' => 'RON'],
        ]);
        $documentId = $document->getId();
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', sprintf('/case/%d/claim/invoice/%d', $this->case->getId(), $documentId));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('SC Gamma Trade SRL', $crawler->filter('[data-testid="claim-invoice-party-mismatch"]')->text());
        $this->em->clear();
        self::assertNotNull($this->em->find(Document::class, $documentId), 'A read never removes the file.');
    }

    public function testTheReadingOfAnotherLawyersDocumentIsNotFound(): void
    {
        $document = $this->invoiceDocument(ExtractionStatus::COMPLETED, null);
        $other = $this->makeUser();
        $document->setUploadedBy($other);
        $this->em->flush();
        $this->client->loginUser($this->user);

        $this->client->request('GET', sprintf('/case/%d/claim/invoice/%d', $this->case->getId(), $document->getId()));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testExcludingWithoutAReasonIsRefusedWithAToast(): void
    {
        $second = $this->position('F-2', '500.00', '-60 days');
        $this->em->flush();
        $this->client->loginUser($this->user);

        $this->client->request('POST', sprintf('/case/%d/claim/item/%d/exclude', $this->case->getId(), $second->getId()), [
            'claim_exclude' => ['_token' => $this->token('claim_exclude'), 'note' => 'paid on 20.06.2025'],
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Alege motivul excluderii.', $body);
        self::assertStringNotContainsString('target="case-claim-card"', $body);
        self::assertStringNotContainsString('close-modal', $body, 'The dialog stays open for the lawyer to pick a reason.');
        $this->em->clear();
        self::assertFalse($this->em->find(ClaimItem::class, $second->getId())->isExcludedByLawyer());
    }

    public function testReincludeAndCourtAcceptRefuseABogusToken(): void
    {
        $second = $this->position('F-2', '500.00', '-60 days');
        $second->setExcludedByLawyer(true);
        $local = $this->court('Judecătoria Cluj-Napoca');
        $this->case->setCourt($local);
        $this->em->flush();
        $this->client->loginUser($this->user);

        $this->client->request('POST', sprintf('/case/%d/claim/item/%d/reinclude', $this->case->getId(), $second->getId()), [
            '_token' => 'bogus',
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Sesiunea a expirat', $body);
        self::assertStringNotContainsString('target="case-claim-card"', $body);

        $this->client->request('POST', sprintf('/case/%d/claim/court/accept', $this->case->getId()), [
            '_token' => 'bogus',
            'court_id' => $this->court('Tribunalul Specializat Cluj')->getId(),
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sesiunea a expirat', (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertTrue($this->em->find(ClaimItem::class, $second->getId())->isExcludedByLawyer());
        self::assertSame($local->getId(), $this->em->find(LegalCase::class, $this->case->getId())->getCourt()?->getId());
    }

    public function testACaseWithTwoDebtorsAsksWhichOneTheInvoiceNames(): void
    {
        $other = new Debtor();
        $other->setUser($this->user);
        $other->setPersonType(PersonType::PJ);
        $other->setName('SC Second Debtor SRL');
        $other->setAddress('Str. A 2, Cluj-Napoca');
        $other->setCui('RO55556666');
        $this->em->persist($other);
        $link = new LegalCaseDebtor($other);
        $this->case->addDebtor($link);
        $this->em->flush();
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $select = $crawler->filter('select#claim_position_manual_debtorId');
        self::assertCount(1, $select, 'With two debtors the dialog asks which one the invoice names.');
        self::assertCount(1, $select->filter(sprintf('option[value="%d"]', $link->getId())));
        self::assertStringContainsString('SC Second Debtor SRL (RO55556666)', $select->text());

        $this->postPosition(['documentNumber' => 'F-2', 'documentDate' => $this->date('-60 days'), 'dueDate' => $this->date('-30 days'), 'amount' => '500.00', 'currency' => 'RON']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Alege debitorul căruia i-a fost emisă factura.', $body);
        self::assertStringNotContainsString('target="case-claim-card"', $body);
        $this->em->clear();
        self::assertSame(1, $this->em->getRepository(ClaimItem::class)->count(['legalCase' => $this->case->getId()]));

        $this->postPosition(['documentNumber' => 'F-2', 'documentDate' => $this->date('-60 days'), 'dueDate' => $this->date('-30 days'), 'amount' => '500.00', 'currency' => 'RON', 'debtorId' => (string) $link->getId()]);

        self::assertStringContainsString('target="case-claim-card"', (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        $added = $this->em->getRepository(ClaimItem::class)->findOneBy(['legalCase' => $this->case->getId(), 'documentNumber' => 'F-2']);
        self::assertInstanceOf(ClaimItem::class, $added);
        self::assertSame($link->getId(), $added->getDebtor()?->getId());
    }

    /**
     * A summons generated while the case was still amiable quotes figures that
     * just changed: the stream refreshes the documents tab with the summons
     * marked outdated, the toast says so, and the dialogs warn beforehand.
     */
    public function testAnEditAfterTheSummonsWasGeneratedMarksItOutdated(): void
    {
        $second = $this->position('F-2', '500.00', '-60 days');
        $this->case->setPaymentNoticeDate(new \DateTime('-2 days'));
        $summons = $this->persistDocument(DocumentType::SOMATIE);
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="claim-details-regenerate-note"]'));
        self::assertCount(1, $crawler->filter('[data-testid="claim-summons-regenerate-note"]'));
        self::assertCount(0, $crawler->filter('[data-testid="summons-outdated-badge"]'));

        $this->client->request('POST', sprintf('/case/%d/claim/item/%d/exclude', $this->case->getId(), $second->getId()), [
            'claim_exclude' => ['_token' => $this->token('claim_exclude'), 'reason' => 'disputed'],
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="case-claim-card"', $body);
        self::assertStringContainsString('target="panel-documente"', $body);
        self::assertStringContainsString('data-testid="summons-outdated-badge"', $body);
        self::assertStringContainsString('DEPĂȘITĂ', $body);
        self::assertStringContainsString('este marcată ca depășită', $body);
        $this->em->clear();
        $reloaded = $this->em->find(Document::class, $summons->getId());
        self::assertTrue($reloaded->isOutdated());
        self::assertNotNull($reloaded->getOutdatedAt());
    }

    /**
     * The extraction budget is charged before the file is stored: at the ceiling
     * nothing is written and nothing is queued, and the lawyer is told why.
     */
    public function testAnExhaustedExtractionBudgetRefusesTheUploadWithoutStoringOrDispatching(): void
    {
        $this->client->disableReboot();
        $this->client->loginUser($this->user);
        $token = $this->token('claim_invoice');

        $exhausted = new RateLimiterFactory(
            ['id' => 'claim_test_extractions', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
        $exhausted->create($this->user->getUserIdentifier())->consume(1);
        $traffic = new RateLimiterFactory(['id' => 'claim_test_traffic', 'policy' => 'no_limit'], new InMemoryStorage());
        static::getContainer()->set(UploadRateLimiter::class, new UploadRateLimiter($exhausted, $traffic));

        $this->client->request(
            'POST',
            sprintf('/case/%d/claim/invoice', $this->case->getId()),
            ['claim_invoice' => ['_token' => $token]],
            ['claim_invoice' => ['file' => $this->makePdf()]],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Ați atins limita de upload-uri pe oră', $body);
        self::assertStringNotContainsString('target="claim-invoice-status"', $body);
        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(Document::class)->count(['legalCase' => $this->case->getId()]));
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertCount(0, $transport->getSent());
    }

    public function testAReadingIsOfferedOnlyForAnInvoiceOfThisCase(): void
    {
        $contract = $this->persistDocument(DocumentType::CONTRACT);
        $sibling = new LegalCase();
        $sibling->setUser($this->user);
        $sibling->setCreditor($this->case->getCreditor());
        $sibling->setStatus(CaseStatus::AMIABIL);
        $sibling->setRelationshipType(RelationshipType::COMERCIAL);
        $sibling->setAmount('100.00');
        $sibling->setCurrency('RON');
        $this->em->persist($sibling);
        $elsewhere = $this->persistDocument(DocumentType::FACTURA);
        $elsewhere->setLegalCase($sibling);
        $elsewhere->setExtractionStatus(ExtractionStatus::COMPLETED);
        $this->em->flush();
        $this->client->loginUser($this->user);

        $this->client->request('GET', sprintf('/case/%d/claim/invoice/%d', $this->case->getId(), $contract->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'Only an invoice is read into a position.');

        $this->client->request('GET', sprintf('/case/%d/claim/invoice/%d', $this->case->getId(), $elsewhere->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'An invoice of another case is not shown here.');

        $this->client->request('POST', sprintf('/case/%d/claim/invoice/%d/add', $this->case->getId(), $elsewhere->getId()), [
            'claim_position' => ['_token' => $this->token('claim_position'), 'documentNumber' => 'E-1', 'dueDate' => $this->date('-30 days'), 'amount' => '100.00', 'currency' => 'RON'],
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->em->clear();
        self::assertSame(1, $this->em->getRepository(ClaimItem::class)->count(['legalCase' => $this->case->getId()]));
    }

    // ===== helpers ======================================================

    /** @return list<string> */
    private function routes(): array
    {
        $id = $this->case->getId();

        return [
            sprintf('/case/%d/claim/add', $id),
            sprintf('/case/%d/claim/invoice', $id),
            sprintf('/case/%d/claim/item/%d/exclude', $id, $this->first->getId()),
            sprintf('/case/%d/claim/item/%d/reinclude', $id, $this->first->getId()),
            sprintf('/case/%d/claim/details', $id),
            sprintf('/case/%d/claim/court/accept', $id),
        ];
    }

    /** @param array<string, string> $fields */
    private function postPosition(array $fields): void
    {
        $this->client->request('POST', sprintf('/case/%d/claim/add', $this->case->getId()), [
            'claim_position' => ['_token' => $this->token('claim_position')] + $fields,
        ], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
    }

    private function uploadInvoice(UploadedFile $file): void
    {
        $this->client->request(
            'POST',
            sprintf('/case/%d/claim/invoice', $this->case->getId()),
            ['claim_invoice' => ['_token' => $this->token('claim_invoice')]],
            ['claim_invoice' => ['file' => $file]],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );
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
        $user->setEmail('claim-ctrl-' . uniqid() . '@test.com');
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $user->setIsVerified(true);
        $user->setFirstName('Avocat');
        $user->setLastName('Claim');
        $this->em->persist($user);
        $this->em->flush();
        $this->userIds[] = $user->getId();

        return $user;
    }

    private function position(string $number, string $amount, string $dueDate): ClaimItem
    {
        $due = new \DateTimeImmutable($dueDate);
        $item = new ClaimItem();
        $item->setDocumentNumber($number);
        $item->setDocumentDate($due->modify('-30 days'));
        $item->setAmount($amount);
        $item->setCurrency('RON');
        $item->setAmountRon($amount);
        $item->setDueDate($due);
        $item->setConfirmedByLawyer(true);
        // Keyed as the editor keys a typed invoice, so a repeated number collides.
        $item->setDedupKey(static::getContainer()->get(ClaimItemFactory::class)->dedupKey($number, $due->modify('-30 days'), (float) $amount, 'RON', self::CREDITOR_CUI));
        $item->setDebtor($this->case->getPrimaryDebtor());
        $this->case->addClaimItem($item);
        $this->em->persist($item);

        return $item;
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

    /** @param array<string, mixed>|null $extracted */
    private function invoiceDocument(ExtractionStatus $status, ?array $extracted): Document
    {
        $document = $this->persistDocument(DocumentType::FACTURA);
        $document->setExtractionStatus($status);
        $document->setExtractedData($extracted);
        $document->setExtractionConfidence($extracted !== null ? '0.90' : null);
        $this->em->flush();

        return $document;
    }

    private function makePdf(string $name = 'factura.pdf', string $salt = ''): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'inv') . '.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n% " . ($salt !== '' ? $salt : uniqid()) . "\ntrailer<</Root 1 0 R>>\n%%EOF");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function court(string $name): Court
    {
        $court = $this->em->getRepository(Court::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Court::class, $court, 'Court fixture missing: ' . $name);

        return $court;
    }

    private function date(string $modifier): string
    {
        return (new \DateTimeImmutable($modifier))->format('Y-m-d');
    }
}
