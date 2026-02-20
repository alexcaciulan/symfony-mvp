<?php

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Court;
use App\Entity\LegalCase;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\CourtType;
use App\Enum\PaymentStatus;
use App\Enum\PaymentType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PaymentControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private User $otherUser;
    private Court $court;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->createTestData();
        $this->client->loginUser($this->user);
    }

    private function createTestData(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->court = new Court();
        $this->court->setName('Judecătoria Payment ' . uniqid());
        $this->court->setCounty('PaymentTest');
        $this->court->setType(CourtType::JUDECATORIE);
        $this->court->setActive(true);
        $this->em->persist($this->court);

        $this->user = new User();
        $this->user->setEmail('payment-test-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Payment');
        $this->user->setLastName('User');
        $this->em->persist($this->user);

        $this->otherUser = new User();
        $this->otherUser->setEmail('payment-other-' . uniqid() . '@test.com');
        $this->otherUser->setPassword($hasher->hashPassword($this->otherUser, 'password'));
        $this->otherUser->setIsVerified(true);
        $this->otherUser->setFirstName('Other');
        $this->otherUser->setLastName('User');
        $this->em->persist($this->otherUser);

        $this->em->flush();
    }

    private function createCaseWithPayments(string $status = 'pending_payment'): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setCourt($this->court);
        $case->setStatus($status);
        $case->setClaimAmount('1000.00');
        $case->setCourtFee('50.00');
        $case->setPlatformFee('29.90');
        $case->setTotalFee('79.90');
        $this->em->persist($case);

        if ($status === 'pending_payment') {
            $courtPayment = new Payment();
            $courtPayment->setLegalCase($case);
            $courtPayment->setUser($this->user);
            $courtPayment->setAmount('50.00');
            $courtPayment->setPaymentType(PaymentType::TAXA_JUDICIARA);
            $courtPayment->setStatus(PaymentStatus::PENDING);
            $this->em->persist($courtPayment);

            $platformPayment = new Payment();
            $platformPayment->setLegalCase($case);
            $platformPayment->setUser($this->user);
            $platformPayment->setAmount('29.90');
            $platformPayment->setPaymentType(PaymentType::COMISION_PLATFORMA);
            $platformPayment->setStatus(PaymentStatus::PENDING);
            $this->em->persist($platformPayment);
        }

        $this->em->flush();

        return $case;
    }

    public function testPaymentPageShowsFees(): void
    {
        $case = $this->createCaseWithPayments();

        $crawler = $this->client->request('GET', '/case/' . $case->getId() . '/payment');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('50,00', $content);
        $this->assertStringContainsString('29,90', $content);
        $this->assertStringContainsString('79,90', $content);
    }

    public function testPaymentPageRequiresAuth(): void
    {
        $case = $this->createCaseWithPayments();

        self::ensureKernelShutdown();
        $anonClient = static::createClient();
        $anonClient->request('GET', '/case/' . $case->getId() . '/payment');

        $this->assertResponseRedirects('/login');
    }

    public function testPaymentPageDeniedForNonOwner(): void
    {
        $case = $this->createCaseWithPayments();

        $this->client->loginUser($this->otherUser);
        $this->client->request('GET', '/case/' . $case->getId() . '/payment');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testPaymentPageOnlyForPendingPayment(): void
    {
        $case = $this->createCaseWithPayments('paid');

        $this->client->request('GET', '/case/' . $case->getId() . '/payment');

        $this->assertResponseRedirects('/case/' . $case->getId());
    }

    public function testPaymentProcessMarksPaymentsCompleted(): void
    {
        $case = $this->createCaseWithPayments();
        $caseId = $case->getId();

        $crawler = $this->client->request('GET', '/case/' . $caseId . '/payment');
        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/case/' . $caseId . '/payment/process', [
            '_token' => $csrfToken,
        ]);

        $this->assertResponseRedirects('/case/' . $caseId);

        $freshEm = static::getContainer()->get(EntityManagerInterface::class);
        $payments = $freshEm->getRepository(Payment::class)->findBy(['legalCase' => $caseId]);

        $this->assertCount(2, $payments);
        foreach ($payments as $payment) {
            $this->assertSame(PaymentStatus::COMPLETED, $payment->getStatus());
            $this->assertSame('simulator', $payment->getPaymentMethod());
            $this->assertNotNull($payment->getExternalReference());
            $this->assertStringStartsWith('SIM-', $payment->getExternalReference());
        }
    }

    public function testPaymentProcessAppliesWorkflowTransition(): void
    {
        $case = $this->createCaseWithPayments();
        $caseId = $case->getId();

        $crawler = $this->client->request('GET', '/case/' . $caseId . '/payment');
        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/case/' . $caseId . '/payment/process', [
            '_token' => $csrfToken,
        ]);

        $this->assertResponseRedirects();

        $freshEm = static::getContainer()->get(EntityManagerInterface::class);
        $updatedCase = $freshEm->getRepository(LegalCase::class)->find($caseId);
        $this->assertSame('paid', $updatedCase->getStatus());
    }

    public function testPaymentProcessCreatesAuditLog(): void
    {
        $case = $this->createCaseWithPayments();
        $caseId = $case->getId();

        $crawler = $this->client->request('GET', '/case/' . $caseId . '/payment');
        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/case/' . $caseId . '/payment/process', [
            '_token' => $csrfToken,
        ]);

        $freshEm = static::getContainer()->get(EntityManagerInterface::class);
        $auditLogs = $freshEm->getRepository(AuditLog::class)->findBy([
            'action' => 'payment_completed',
            'entityId' => (string) $caseId,
        ]);

        $this->assertNotEmpty($auditLogs);
        $this->assertSame('LegalCase', $auditLogs[0]->getEntityType());
    }

    public function testPaymentProcessRejectsInvalidCsrf(): void
    {
        $case = $this->createCaseWithPayments();
        $caseId = $case->getId();

        $this->client->request('POST', '/case/' . $caseId . '/payment/process', [
            '_token' => 'invalid-token',
        ]);

        $this->assertResponseRedirects('/case/' . $caseId . '/payment');

        // Status should remain unchanged
        $freshEm = static::getContainer()->get(EntityManagerInterface::class);
        $updatedCase = $freshEm->getRepository(LegalCase::class)->find($caseId);
        $this->assertSame('pending_payment', $updatedCase->getStatus());
    }
}
