<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\AuditLog;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Enum\PersonType;
use App\Enum\SubscriptionStatus;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pas 5.1 — Tests for CaseSummonsController::generate.
 *
 * Verifies the full POST flow: voter → CSRF → status guard → rate limit →
 * transactional (PDF generation + paymentNoticeDate + workflow apply +
 * audit log) → flash + redirect.
 */
final class CaseSummonsControllerTest extends WebTestCase
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
        $this->user->setEmail('summons-ctrl-' . uniqid() . '@test.com');
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
        $creditor->setCui('RO11112222');
        $this->em->persist($creditor);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setCreditor($creditor);
        $this->case->setStatus(CaseStatus::AMIABIL);
        $this->case->setAmount('3000.00');
        $this->case->setCurrency('RON');
        $this->em->persist($this->case);

        $debtor = new Debtor();
        $debtor->setLegalCase($this->case);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Debitor Ctrl SRL');
        $debtor->setAddress('Str. Ctrl 2, București');
        $debtor->setCui('RO33334444');
        $this->em->persist($debtor);
        $this->case->addDebtor($debtor);

        // Active subscription with free slots so the paywall lets the summons through.
        $plan = new Plan();
        $plan->setName('Summons Test Plan ' . uniqid());
        $plan->setPriceMonthly('99.00');
        $plan->setIncludedCases(10);
        $plan->setPricePerExtra('25.00');
        $plan->setIsActive(true);
        $plan->setIsTrial(false);
        $this->em->persist($plan);

        $subscription = new Subscription();
        $subscription->setUser($this->user);
        $subscription->setPlan($plan);
        $subscription->setStatus(SubscriptionStatus::ACTIVE);
        $subscription->setCurrentPeriodStart(new \DateTimeImmutable('-1 day'));
        $subscription->setCurrentPeriodEnd(new \DateTimeImmutable('+30 days'));
        $subscription->setCasesConsumed(0);
        $this->em->persist($subscription);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM invoice WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM subscription WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM case_status_history WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM notification WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);
        $conn->executeStatement("DELETE FROM plan WHERE name LIKE 'Summons Test Plan %'");
        parent::tearDown();
    }

    private function csrfTokenFromOverview(int $caseId): string
    {
        // Hit the overview page (status AMIABIL in setUp) to render the form
        // and extract the real CSRF token bound to the test client session.
        $this->client->request('GET', '/case/' . $caseId);

        $crawler = $this->client->getCrawler();
        $input = $crawler->filter('input[name="_token"]')->first();

        return $input->attr('value');
    }

    public function testGenerateSummonsHappyPathTransitionsToSomatieTrimisa(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->csrfTokenFromOverview($this->case->getId());

        $this->client->request('POST', '/case/' . $this->case->getId() . '/summons/generate', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());

        self::assertSame(CaseStatus::SOMATIE_TRIMISA, $refreshed->getStatus(), 'Status must transition AMIABIL → SOMATIE_TRIMISA.');
        self::assertNotNull($refreshed->getPaymentNoticeDate(), 'paymentNoticeDate must be set on generate.');

        $documents = $this->em->getRepository(Document::class)->findBy(['legalCase' => $refreshed->getId()]);
        $somatieDocs = array_filter($documents, fn (Document $d): bool => $d->getDocumentType() === DocumentType::SOMATIE);
        self::assertCount(1, $somatieDocs, 'Exactly one SOMATIE document must be persisted.');

        $somatieDoc = array_values($somatieDocs)[0];
        self::assertSame('application/pdf', $somatieDoc->getMimeType());
        self::assertGreaterThan(0, $somatieDoc->getFileSize());
        self::assertStringContainsString($refreshed->getCaseNumber(), $somatieDoc->getOriginalFilename());

        $auditEntries = $this->em->getRepository(AuditLog::class)->findBy([
            'category' => AuditLogService::CATEGORY_SUMMONS_GENERATED,
            'entityType' => LegalCase::class,
            'entityId' => (string) $refreshed->getId(),
        ]);
        self::assertCount(1, $auditEntries, 'Exactly one AuditLog with category SUMMONS_GENERATED must be persisted.');
        $auditPayload = $auditEntries[0]->getNewData();
        self::assertSame($refreshed->getCaseNumber(), $auditPayload['caseNumber'] ?? null);
        self::assertSame($somatieDoc->getId(), $auditPayload['documentId'] ?? null);

        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testGenerateSummonsBlockedWhenDebtorMissing(): void
    {
        // Remove the debtor so the controller's empty-debtors guard triggers.
        foreach ($this->case->getDebtors() as $debtor) {
            $this->em->remove($debtor);
        }
        $this->em->flush();
        $this->em->refresh($this->case);

        $this->client->loginUser($this->user);
        $token = $this->csrfTokenFromOverview($this->case->getId());

        $this->client->request('POST', '/case/' . $this->case->getId() . '/summons/generate', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(CaseStatus::AMIABIL, $refreshed->getStatus(), 'Status must remain AMIABIL when guard rejects no-debtor case.');

        $documents = $this->em->getRepository(Document::class)->findBy(['legalCase' => $refreshed->getId()]);
        $somatieDocs = array_filter($documents, fn (Document $d): bool => $d->getDocumentType() === DocumentType::SOMATIE);
        self::assertCount(0, $somatieDocs, 'No SOMATIE document should be generated when debtor is missing.');
    }

    public function testGenerateSummonsBlockedFromNonAmiabilStatus(): void
    {
        $this->client->loginUser($this->user);

        // Fetch CSRF token BEFORE advancing status, since the form only renders
        // for AMIABIL. After we flip status, the token remains valid for this
        // session and can still be submitted, which is exactly what we want to
        // test (the controller's status-guard rejection, not CSRF rejection).
        $token = $this->csrfTokenFromOverview($this->case->getId());

        $this->case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->em->flush();

        $this->client->request('POST', '/case/' . $this->case->getId() . '/summons/generate', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(CaseStatus::SOMATIE_TRIMISA, $refreshed->getStatus(), 'Status must remain unchanged when guard rejects.');

        $documents = $this->em->getRepository(Document::class)->findBy(['legalCase' => $refreshed->getId()]);
        $somatieDocs = array_filter($documents, fn (Document $d): bool => $d->getDocumentType() === DocumentType::SOMATIE);
        self::assertCount(0, $somatieDocs, 'No SOMATIE document should be created when status guard rejects.');
    }

    public function testGenerateSummonsBlockedWithoutActiveSubscription(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->csrfTokenFromOverview($this->case->getId());

        // Suspend the subscription so the paywall blocks activation.
        $sub = $this->em->getRepository(Subscription::class)->findOneBy(['user' => $this->user]);
        $sub->setStatus(SubscriptionStatus::SUSPENDED);
        $this->em->flush();

        $this->client->request('POST', '/case/' . $this->case->getId() . '/summons/generate', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/subscription');

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(CaseStatus::AMIABIL, $refreshed->getStatus(), 'Status must remain AMIABIL when paywall blocks activation.');

        $documents = $this->em->getRepository(Document::class)->findBy(['legalCase' => $refreshed->getId()]);
        $somatieDocs = array_filter($documents, fn (Document $d): bool => $d->getDocumentType() === DocumentType::SOMATIE);
        self::assertCount(0, $somatieDocs, 'No SOMATIE document when the paywall blocks.');
    }

    public function testGenerateSummonsRejectsInvalidCsrf(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/summons/generate', [
            '_token' => 'invalid-token',
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(CaseStatus::AMIABIL, $refreshed->getStatus(), 'Status must remain AMIABIL on CSRF failure.');
    }

    public function testGenerateSummonsDeniedForOtherUser(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $intruder = new User();
        $intruder->setEmail('summons-intruder-' . uniqid() . '@test.com');
        $intruder->setPassword($hasher->hashPassword($intruder, 'password'));
        $intruder->setIsVerified(true);
        $intruder->setFirstName('Intruder');
        $intruder->setLastName('Tester');
        $this->em->persist($intruder);
        $this->em->flush();

        try {
            $this->client->loginUser($intruder);

            $this->client->request('POST', '/case/' . $this->case->getId() . '/summons/generate', [
                '_token' => 'any-token',
            ]);

            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        } finally {
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $intruder->getId()]);
        }
    }
}
