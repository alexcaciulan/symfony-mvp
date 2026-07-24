<?php

namespace App\Tests\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\ClaimItem;
use App\Entity\Court;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\ClaimItemKind;
use App\Enum\CourtType;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AdminAccessTest extends WebTestCase
{
    use CountyFixtureTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private User $regularUser;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->admin = new User();
        $this->admin->setEmail('admin-test-' . uniqid() . '@test.com');
        $this->admin->setPassword($hasher->hashPassword($this->admin, 'password'));
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->admin->setIsVerified(true);
        $this->admin->setFirstName('Admin');
        $this->admin->setLastName('Test');
        $this->em->persist($this->admin);

        $this->regularUser = new User();
        $this->regularUser->setEmail('user-test-' . uniqid() . '@test.com');
        $this->regularUser->setPassword($hasher->hashPassword($this->regularUser, 'password'));
        $this->regularUser->setIsVerified(true);
        $this->regularUser->setFirstName('Regular');
        $this->regularUser->setLastName('User');
        $this->em->persist($this->regularUser);

        $this->em->flush();
    }

    public function testAdminDashboardAccessibleByAdmin(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
    }

    public function testAdminDashboardForbiddenForRegularUser(): void
    {
        $this->client->loginUser($this->regularUser);
        $this->client->request('GET', '/admin');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminDashboardRedirectsAnonymous(): void
    {
        $this->client->request('GET', '/admin');

        $this->assertResponseRedirects();
    }

    public function testDashboardShowsCaseStats(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Total dosare', $content);
        $this->assertStringContainsString('Active', $content);
        $this->assertStringContainsString('Total', $content);
    }

    public function testDashboardShowsMenuLinks(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
        $menuText = $crawler->filter('.sidebar-menu, .main-menu, nav')->text('');
        // Verify menu items exist by checking link text
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Dosare', $content);
        $this->assertStringContainsString('Utilizatori', $content);
        $this->assertStringContainsString('Instanțe', $content);
        $this->assertStringContainsString('Jurnal audit', $content);
    }

    public function testChangeStatusPageAccessibleByAdmin(): void
    {
        $this->client->loginUser($this->admin);
        $case = $this->createTestCase(CaseStatus::AMIABIL);

        $this->client->request('GET', '/admin/case/' . $case->getId() . '/change-status');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h3', 'Informații dosar');
    }

    public function testChangeStatusAppliesTransition(): void
    {
        $this->client->loginUser($this->admin);
        $case = $this->createTestCase(CaseStatus::AMIABIL);
        $caseId = $case->getId();

        // GET the form page first to get CSRF token
        $crawler = $this->client->request('GET', '/admin/case/' . $caseId . '/change-status');
        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/case/' . $caseId . '/change-status', [
            'transition' => 'trimite_somatie',
            'reason' => 'Test tranziție',
            '_token' => $csrfToken,
        ]);

        $this->assertResponseRedirects();

        // Re-fetch entity from fresh EM after client request
        $freshEm = static::getContainer()->get(EntityManagerInterface::class);
        $updatedCase = $freshEm->getRepository(LegalCase::class)->find($caseId);
        $this->assertSame(CaseStatus::SOMATIE_TRIMISA, $updatedCase->getStatus());

        // Verify AuditLog was created
        $auditLogs = $freshEm->getRepository(AuditLog::class)->findBy([
            'action' => 'admin_status_change',
            'entityId' => (string) $caseId,
        ]);
        $this->assertNotEmpty($auditLogs);
    }

    public function testChangeStatusRejectsInvalidTransition(): void
    {
        $this->client->loginUser($this->admin);
        $case = $this->createTestCase(CaseStatus::AMIABIL);
        $caseId = $case->getId();

        $crawler = $this->client->request('GET', '/admin/case/' . $caseId . '/change-status');
        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        // emite_ordonanta is not enabled from AMIABIL, so the controller rejects it.
        $this->client->request('POST', '/admin/case/' . $caseId . '/change-status', [
            'transition' => 'emite_ordonanta',
            'reason' => '',
            '_token' => $csrfToken,
        ]);

        $this->assertResponseRedirects();

        $freshEm = static::getContainer()->get(EntityManagerInterface::class);
        $updatedCase = $freshEm->getRepository(LegalCase::class)->find($caseId);
        $this->assertSame(CaseStatus::AMIABIL, $updatedCase->getStatus());
    }

    public function testChangeStatusRejectsInvalidCsrf(): void
    {
        $this->client->loginUser($this->admin);
        $case = $this->createTestCase(CaseStatus::AMIABIL);
        $caseId = $case->getId();

        $this->client->request('POST', '/admin/case/' . $caseId . '/change-status', [
            'transition' => 'trimite_somatie',
            'reason' => '',
            '_token' => 'invalid-token',
        ]);

        $this->assertResponseRedirects();

        $freshEm = static::getContainer()->get(EntityManagerInterface::class);
        $updatedCase = $freshEm->getRepository(LegalCase::class)->find($caseId);
        $this->assertSame(CaseStatus::AMIABIL, $updatedCase->getStatus());
    }

    public function testChangeStatusForbiddenForRegularUser(): void
    {
        $this->client->loginUser($this->admin);
        $case = $this->createTestCase(CaseStatus::AMIABIL);
        $caseId = $case->getId();

        // Now login as regular user
        $this->client->loginUser($this->regularUser);
        $this->client->request('GET', '/admin/case/' . $caseId . '/change-status');

        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * @return iterable<string, array{string}> EasyAdmin generated CRUD index paths
     */
    public static function crudControllerProvider(): iterable
    {
        yield 'Creditor' => ['/admin/creditor'];
        yield 'Debtor' => ['/admin/debtor'];
        yield 'ClaimItem' => ['/admin/claim-item'];
        yield 'Document' => ['/admin/document'];
        yield 'LegalDeadline' => ['/admin/legal-deadline'];
        yield 'Plan' => ['/admin/plan'];
        yield 'Subscription' => ['/admin/subscription'];
        yield 'Invoice' => ['/admin/invoice'];
        yield 'InterestRateConfig' => ['/admin/interest-rate-config'];
    }

    #[DataProvider('crudControllerProvider')]
    public function testCrudIndexAccessibleByAdmin(string $indexPath): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', $indexPath);

        $this->assertResponseIsSuccessful();
    }

    #[DataProvider('crudControllerProvider')]
    public function testCrudIndexForbiddenForRegularUser(string $indexPath): void
    {
        $this->client->loginUser($this->regularUser);
        $this->client->request('GET', $indexPath);

        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * Detail render for the two new read-only CRUD controllers, with a real row.
     * A foreign-currency position exercises MoneyField::setCurrencyPropertyPath,
     * which reads the currency off the entity rather than a hardcoded RON.
     */
    public function testNewCrudDetailPagesRenderWithRows(): void
    {
        $this->client->loginUser($this->admin);
        $case = $this->createTestCase(CaseStatus::AMIABIL);

        $item = new ClaimItem();
        $item->setLegalCase($case);
        $item->setKind(ClaimItemKind::INVOICE);
        $item->setDocumentNumber('F-2026-001');
        $item->setDueDate(new \DateTimeImmutable('2026-01-15'));
        $item->setAmount('1200.00');
        $item->setCurrency('EUR');
        $item->setAmountRon('5988.00');
        $item->setDedupKey('dedup-' . uniqid());
        $item->setConfirmedByLawyer(true);
        $this->em->persist($item);

        $document = new Document();
        $document->setLegalCase($case);
        $document->setDocumentType(DocumentType::FACTURA);
        $document->setOriginalFilename('factura.pdf');
        $document->setStoredFilename('stored.pdf');
        $document->setFileSize(1024);
        $document->setMimeType('application/pdf');
        $document->setUploadedBy($this->regularUser);
        $document->setExtractionStatus(ExtractionStatus::COMPLETED);
        $document->setDetectedType(DocumentType::FACTURA);
        $document->setContentHash(str_repeat('a', 64));
        $this->em->persist($document);
        $this->em->flush();

        $this->client->request('GET', '/admin/claim-item?crudAction=detail&entityId=' . $item->getId());
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/document?crudAction=detail&entityId=' . $document->getId());
        $this->assertResponseIsSuccessful();
    }

    public function testDashboardShowsBillingKpi(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Facturi în așteptare', $content);
        $this->assertStringContainsString('Ordonanțe emise luna curentă', $content);
        $this->assertStringContainsString('Monetizare', $content);
    }

    private function createTestCase(CaseStatus $status): LegalCase
    {
        $court = new Court();
        $court->setName('Judecătoria Admin ' . uniqid());
        $court->setCounty($this->createCounty($this->em, 'AdminTest'));
        $court->setType(CourtType::JUDECATORIE);
        $court->setActive(true);
        $this->em->persist($court);

        $case = new LegalCase();
        $case->setUser($this->regularUser);
        $case->setCourt($court);
        $case->setStatus($status);
        $case->setAmount('1000.00');
        $case->setCurrency('RON');
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }
}
