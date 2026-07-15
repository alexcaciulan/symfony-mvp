<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\AuditLog;
use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CourtType;
use App\Enum\DebitAcknowledgedStatus;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Enum\PersonType;
use App\Enum\StampDutyStatus;
use App\Service\AuditLogService;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests for CasePaymentOrderController.
 *
 * POST generate: happy path + status guard + court guard + idempotency + CSRF + 403 voter.
 * GET download: happy path BinaryFile + flash error if incomplete.
 */
final class CasePaymentOrderControllerTest extends WebTestCase
{
    use CountyFixtureTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;
    private string $uploadsDir;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail('po-ctrl-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Avocat');
        $this->user->setLastName('Test');
        $this->em->persist($this->user);

        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Creditor Ctrl SRL');
        $creditor->setAddress('Str. Ctrl 1, București');
        $creditor->setCui('RO99991111');
        $this->em->persist($creditor);

        $court = new Court();
        $court->setName('Judecătoria Test Ctrl ' . uniqid());
        $court->setCounty($this->createCounty($this->em, 'București'));
        $court->setType(CourtType::JUDECATORIE);
        $court->setActive(true);
        $this->em->persist($court);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setCreditor($creditor);
        $this->case->setCourt($court);
        $this->case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->case->setAmount('5000.00');
        $this->case->setCurrency('RON');
        $this->case->setDueDate(new \DateTime('2024-06-15'));
        $this->case->setPaymentNoticeDate(new \DateTime('2026-02-01'));
        // Stamp duty settled by default: these tests exercise the petition flow, and
        // the duty gate has its own dedicated tests below.
        $this->case->setStampDutyStatus(StampDutyStatus::ACHITATA);
        $this->em->persist($this->case);

        $debtor = new Debtor();
        $debtor->setLegalCase($this->case);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Debitor Ctrl SRL');
        $debtor->setAddress('Str. Ctrl 2, București');
        $debtor->setCui('RO99992222');
        $this->em->persist($debtor);
        $this->case->addDebtor($debtor);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $caseId = $this->case->getId();

        // Cleanup test files on disk
        $caseDir = $this->uploadsDir . '/cases/' . $caseId;
        if (is_dir($caseDir)) {
            foreach (glob($caseDir . '/packages/*.zip') ?: [] as $zip) {
                @unlink($zip);
            }
            @rmdir($caseDir . '/packages');
            foreach (glob($caseDir . '/*.pdf') ?: [] as $pdf) {
                @unlink($pdf);
            }
            @rmdir($caseDir);
        }

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id IS NULL', []);
        $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = ?)', [$userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM case_status_history WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = ?)', [$userId]);
        $conn->executeStatement('DELETE FROM debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = ?)', [$userId]);
        $conn->executeStatement('DELETE FROM notification WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = ?', [$userId]);
        $conn->executeStatement("DELETE FROM court WHERE name LIKE 'Judecătoria Test Ctrl %'");
        $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$userId]);

        parent::tearDown();
    }

    private function csrfTokenFromOverview(int $caseId): string
    {
        $this->client->request('GET', '/case/' . $caseId);
        $crawler = $this->client->getCrawler();

        // Token-ul cerere OP e în modalul de generate
        $input = $crawler->filter('form[action$="/payment-order/generate"] input[name="_token"]')->first();

        return (string) $input->attr('value');
    }

    private function attachSomatieFile(): Document
    {
        $caseDir = $this->uploadsDir . '/cases/' . $this->case->getId();
        if (!is_dir($caseDir)) {
            mkdir($caseDir, 0755, true);
        }
        $storedFile = 'cases/' . $this->case->getId() . '/somatie.pdf';
        file_put_contents($this->uploadsDir . '/' . $storedFile, '%PDF-1.4 mock somatie');

        $doc = new Document();
        $doc->setLegalCase($this->case);
        $doc->setDocumentType(DocumentType::SOMATIE);
        $doc->setOriginalFilename('Somatie.pdf');
        $doc->setStoredFilename($storedFile);
        $doc->setFileSize(20);
        $doc->setMimeType('application/pdf');
        $doc->setUploadedBy($this->user);
        $doc->setExtractionStatus(ExtractionStatus::COMPLETED);
        $this->em->persist($doc);
        $this->em->flush();
        $this->case->getDocuments()->add($doc);

        return $doc;
    }

    /**
     * CPC art. 197: proof of the stamp duty is attached to the petition, and failing
     * to stamp it annuls the claim. So an unpaid duty must stop the filing package
     * from being produced at all.
     */
    public function testGenerateIsBlockedWhenStampDutyIsUnpaid(): void
    {
        $this->attachSomatieFile();
        $this->case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $token = $this->csrfTokenFromOverview($this->case->getId());

        $this->client->request('POST', '/case/' . $this->case->getId() . '/payment-order/generate', [
            '_token' => $token,
            'debitAcknowledgedStatus' => 'UNPAID',
            'opGenerationConsent' => '1',
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(CaseStatus::SOMATIE_TRIMISA, $refreshed->getStatus(), 'An unstamped case must not reach CERERE_DEPUSA.');

        $documents = $this->em->getRepository(Document::class)->findBy(['legalCase' => $refreshed->getId()]);
        $types = array_map(static fn (Document $d): DocumentType => $d->getDocumentType(), $documents);
        self::assertNotContains(DocumentType::CERERE_OP, $types, 'No petition may be generated while the duty is unpaid.');
    }

    /**
     * OUG 80/2013 art. 33 alin. 2 lets the claimant stamp during regularization, so a
     * lawyer who knowingly takes that route must not be locked out of filing.
     */
    public function testGenerateIsAllowedWhenStampDutyIsDeferredToRegularization(): void
    {
        $this->attachSomatieFile();
        $this->case->setStampDutyStatus(StampDutyStatus::AMANATA_REGULARIZARE);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $token = $this->csrfTokenFromOverview($this->case->getId());

        $this->client->request('POST', '/case/' . $this->case->getId() . '/payment-order/generate', [
            '_token' => $token,
            'debitAcknowledgedStatus' => 'UNPAID',
            'opGenerationConsent' => '1',
        ]);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(CaseStatus::CERERE_DEPUSA, $refreshed->getStatus());
    }

    public function testGenerateHappyPathTransitionsToCerereDepusa(): void
    {
        $this->attachSomatieFile();
        $this->client->loginUser($this->user);
        $token = $this->csrfTokenFromOverview($this->case->getId());

        $this->client->request('POST', '/case/' . $this->case->getId() . '/payment-order/generate', [
            '_token' => $token,
            'debitAcknowledgedStatus' => 'UNPAID',
            'opGenerationConsent' => '1',
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(CaseStatus::CERERE_DEPUSA, $refreshed->getStatus(), 'Status must transition SOMATIE_TRIMISA → CERERE_DEPUSA.');
        self::assertSame(DebitAcknowledgedStatus::UNPAID, $refreshed->getDebitAcknowledgedStatus());
        self::assertTrue($refreshed->getOpGenerationConsent());

        $documents = $this->em->getRepository(Document::class)->findBy(['legalCase' => $refreshed->getId()]);
        $types = array_map(static fn (Document $d): DocumentType => $d->getDocumentType(), $documents);
        self::assertContains(DocumentType::CERERE_OP, $types);
        self::assertContains(DocumentType::OPIS, $types);

        $auditEntries = $this->em->getRepository(AuditLog::class)->findBy([
            'category' => AuditLogService::CATEGORY_PAYMENT_ORDER_GENERATED,
            'entityType' => LegalCase::class,
            'entityId' => (string) $refreshed->getId(),
        ]);
        self::assertCount(1, $auditEntries);
        $payload = $auditEntries[0]->getNewData();
        self::assertSame($refreshed->getCaseNumber(), $payload['caseNumber'] ?? null);
        self::assertNotNull($payload['paymentOrderDocumentId'] ?? null);
        self::assertNotNull($payload['opisDocumentId'] ?? null);
        self::assertSame('UNPAID', $payload['debitAcknowledgedStatus'] ?? null);
    }

    public function testGenerateRespondsWithTurboStream(): void
    {
        $this->attachSomatieFile();
        $this->client->loginUser($this->user);
        $token = $this->csrfTokenFromOverview($this->case->getId());

        $this->client->request(
            'POST',
            '/case/' . $this->case->getId() . '/payment-order/generate',
            ['_token' => $token, 'debitAcknowledgedStatus' => 'UNPAID', 'opGenerationConsent' => '1'],
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'text/vnd.turbo-stream.html',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
        );
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="case-hero"', $body);
        self::assertStringContainsString('target="case-pipeline"', $body);
        self::assertStringContainsString('target="panel-documente"', $body);
        self::assertStringContainsString('target="toasts"', $body);
        // Status change refreshes the recommended actions + active deadline regions.
        self::assertStringContainsString('target="case-kpi-grid"', $body);
        self::assertStringContainsString('target="case-detalii-sidebar"', $body);
        // The originating „Generează cerere OP" modal closes after the swap.
        self::assertStringContainsString('close-modal', $body);
        self::assertStringContainsString('hs-modal-cerere-op', $body);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(CaseStatus::CERERE_DEPUSA, $refreshed->getStatus());

        // The re-rendered "Documente generate" panel must reflect the freshly
        // generated Cerere OP (regression: stale EXTRA_LAZY collection after the
        // opis generator initialized it mid-transaction).
        $cerere = $this->em->getRepository(Document::class)->findOneBy([
            'legalCase' => $refreshed->getId(),
            'documentType' => DocumentType::CERERE_OP,
        ]);
        self::assertNotNull($cerere);
        self::assertStringContainsString(
            sprintf('/case/%d/document/%d/download', $refreshed->getId(), $cerere->getId()),
            $body,
            'Generated Cerere OP must appear as a download link in the re-rendered panel.',
        );
    }

    public function testGenerateBlockedFromWrongStatus(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->csrfTokenFromOverview($this->case->getId());

        // Forțăm status non-SOMATIE_TRIMISA înainte de POST
        $this->case->setStatus(CaseStatus::AMIABIL);
        $this->em->flush();

        $this->client->request('POST', '/case/' . $this->case->getId() . '/payment-order/generate', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(CaseStatus::AMIABIL, $refreshed->getStatus(), 'Status nu trebuie să se schimbe.');

        $cerereDocs = $this->em->getRepository(Document::class)->findBy([
            'legalCase' => $refreshed->getId(),
            'documentType' => DocumentType::CERERE_OP,
        ]);
        self::assertCount(0, $cerereDocs);
    }

    public function testGenerateBlockedWhenNoCourt(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->csrfTokenFromOverview($this->case->getId());

        $this->case->setCourt(null);
        $this->em->flush();

        $this->client->request('POST', '/case/' . $this->case->getId() . '/payment-order/generate', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId());

        $this->em->clear();
        $cerereDocs = $this->em->getRepository(Document::class)->findBy([
            'legalCase' => $this->case->getId(),
            'documentType' => DocumentType::CERERE_OP,
        ]);
        self::assertCount(0, $cerereDocs, 'Fără court → CERERE_OP nu trebuie creat.');
    }

    public function testGenerateBlockedWhenConsentMissing(): void
    {
        $this->attachSomatieFile();
        $this->client->loginUser($this->user);
        $token = $this->csrfTokenFromOverview($this->case->getId());

        $this->client->request('POST', '/case/' . $this->case->getId() . '/payment-order/generate', [
            '_token' => $token,
            'debitAcknowledgedStatus' => 'UNPAID',
            // opGenerationConsent missing
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(CaseStatus::SOMATIE_TRIMISA, $refreshed->getStatus(), 'Fără acord → OP nu se generează.');
    }

    public function testGenerateBlockedWhenDebitStatusMissing(): void
    {
        $this->attachSomatieFile();
        $this->client->loginUser($this->user);
        $token = $this->csrfTokenFromOverview($this->case->getId());

        $this->client->request('POST', '/case/' . $this->case->getId() . '/payment-order/generate', [
            '_token' => $token,
            'opGenerationConsent' => '1',
            // debitAcknowledgedStatus missing
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(CaseStatus::SOMATIE_TRIMISA, $refreshed->getStatus(), 'Fără status debit → OP nu se generează.');
    }

    public function testGenerateBlockedWhenTermNotExpired(): void
    {
        // Communication date set to today → 15-day term has NOT expired yet.
        $this->case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('today'));
        $this->em->flush();

        $this->attachSomatieFile();
        $this->client->loginUser($this->user);
        $token = $this->csrfTokenFromOverview($this->case->getId());

        $this->client->request('POST', '/case/' . $this->case->getId() . '/payment-order/generate', [
            '_token' => $token,
            'debitAcknowledgedStatus' => 'UNPAID',
            'opGenerationConsent' => '1',
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(CaseStatus::SOMATIE_TRIMISA, $refreshed->getStatus(), 'Termen neexpirat → OP blocată.');
    }

    public function testGenerateIdempotentWhenAlreadyExists(): void
    {
        $this->attachSomatieFile();
        $this->client->loginUser($this->user);
        $token = $this->csrfTokenFromOverview($this->case->getId());

        // Prima generare
        $this->client->request('POST', '/case/' . $this->case->getId() . '/payment-order/generate', [
            '_token' => $token,
            'debitAcknowledgedStatus' => 'UNPAID',
            'opGenerationConsent' => '1',
        ]);

        // A doua tentativă — trebuie respinsă cu flash error
        $token2 = $this->csrfTokenFromOverview($this->case->getId());
        $this->client->request('POST', '/case/' . $this->case->getId() . '/payment-order/generate', [
            '_token' => $token2,
        ]);

        $this->em->clear();
        $cerereDocs = $this->em->getRepository(Document::class)->findBy([
            'legalCase' => $this->case->getId(),
            'documentType' => DocumentType::CERERE_OP,
        ]);
        self::assertCount(1, $cerereDocs, 'Un singur CERERE_OP per dosar — idempotency strict.');
    }

    public function testGenerateRejectsInvalidCsrf(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/payment-order/generate', [
            '_token' => 'invalid-token',
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(CaseStatus::SOMATIE_TRIMISA, $refreshed->getStatus(), 'Status nemodificat pe CSRF invalid.');
    }

    public function testGenerateForbiddenForOtherUser(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $intruder = new User();
        $intruder->setEmail('po-intruder-' . uniqid() . '@test.com');
        $intruder->setPassword($hasher->hashPassword($intruder, 'password'));
        $intruder->setIsVerified(true);
        $intruder->setFirstName('Intruder');
        $intruder->setLastName('Test');
        $this->em->persist($intruder);
        $this->em->flush();

        try {
            $this->client->loginUser($intruder);
            $this->client->request('POST', '/case/' . $this->case->getId() . '/payment-order/generate', [
                '_token' => 'any',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        } finally {
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$intruder->getId()]);
        }
    }

    public function testDownloadZipHappyPath(): void
    {
        $this->attachSomatieFile();
        $this->client->loginUser($this->user);

        // Prima dată generăm ca să avem toate documentele
        $token = $this->csrfTokenFromOverview($this->case->getId());
        $this->client->request('POST', '/case/' . $this->case->getId() . '/payment-order/generate', [
            '_token' => $token,
            'debitAcknowledgedStatus' => 'UNPAID',
            'opGenerationConsent' => '1',
        ]);

        // Acum descărcăm ZIP
        $this->client->request('GET', '/case/' . $this->case->getId() . '/zip-package/download');

        $response = $this->client->getResponse();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('application/zip', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('Pachet_', (string) $response->headers->get('Content-Disposition'));
    }

    public function testDownloadZipFailsWhenDocumentsIncomplete(): void
    {
        // NU generăm cererea OP — doar somația
        $this->attachSomatieFile();
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/case/' . $this->case->getId() . '/zip-package/download');

        self::assertResponseRedirects('/case/' . $this->case->getId());
    }
}
