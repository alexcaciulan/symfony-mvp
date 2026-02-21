<?php

namespace App\Tests\Service;

use App\Entity\AuditLog;
use App\Entity\LegalCase;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\PaymentStatus;
use App\Enum\PaymentType;
use App\Service\Payment\PaymentProcessingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PaymentProcessingServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PaymentProcessingService $service;
    private User $user;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(PaymentProcessingService::class);
        $this->testPrefix = 'payproc-test-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function createPendingPaymentCase(): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus('pending_payment');
        $case->setCurrentStep(6);
        $case->setClaimAmount('1500.00');
        $case->setCourtFee('50.00');
        $case->setPlatformFee('29.90');
        $case->setTotalFee('79.90');
        $this->em->persist($case);

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

        $this->em->flush();

        // Refresh to ensure payments collection is populated from DB
        $this->em->refresh($case);

        return $case;
    }

    public function testProcessPaymentMarksPaymentsAsCompleted(): void
    {
        $case = $this->createPendingPaymentCase();
        $this->service->processPayment($case);

        $payments = $this->em->getRepository(Payment::class)->findBy(['legalCase' => $case]);
        foreach ($payments as $payment) {
            $this->assertSame(PaymentStatus::COMPLETED, $payment->getStatus());
        }
    }

    public function testProcessPaymentAppliesWorkflowTransition(): void
    {
        $case = $this->createPendingPaymentCase();
        $this->service->processPayment($case);

        $this->assertSame('paid', $case->getStatus());
    }

    public function testProcessPaymentCreatesAuditLog(): void
    {
        $case = $this->createPendingPaymentCase();
        $caseId = $case->getId();
        $this->service->processPayment($case);

        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => 'LegalCase',
            'entityId' => (string) $caseId,
            'action' => 'payment_completed',
        ]);
        $this->assertNotEmpty($logs);
        $this->assertSame('paid', $logs[0]->getNewData()['status']);
    }

    public function testProcessPaymentSetsPaymentMethodAndReference(): void
    {
        $case = $this->createPendingPaymentCase();
        $this->service->processPayment($case);

        $payments = $this->em->getRepository(Payment::class)->findBy(['legalCase' => $case]);
        foreach ($payments as $payment) {
            $this->assertSame('simulator', $payment->getPaymentMethod());
            $this->assertStringStartsWith('SIM-', $payment->getExternalReference());
        }
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "DELETE al FROM audit_log al WHERE al.entity_type = 'LegalCase' AND al.entity_id IN (SELECT lc.id FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?)",
            [$this->testPrefix . '%']
        );
        $conn->executeStatement(
            "DELETE csh FROM case_status_history csh JOIN legal_case lc ON csh.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%']
        );
        $conn->executeStatement(
            "DELETE p FROM payment p JOIN legal_case lc ON p.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%']
        );
        $conn->executeStatement(
            "DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%']
        );
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->testPrefix . '%']);
        parent::tearDown();
    }
}
