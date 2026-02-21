<?php

namespace App\Tests\Service;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\PaymentStatus;
use App\Enum\PaymentType;
use App\Service\Case\CaseSubmissionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CaseSubmissionServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CaseSubmissionService $service;
    private User $user;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(CaseSubmissionService::class);
        $this->testPrefix = 'submission-test-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function createDraftCase(string $amount = '1500.00'): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus('draft');
        $case->setCurrentStep(6);
        $case->setClaimAmount($amount);
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    public function testSubmitCreatesTwoPayments(): void
    {
        $case = $this->createDraftCase();
        $this->service->submit($case);

        $payments = $this->em->getRepository(Payment::class)->findBy(['legalCase' => $case]);
        $this->assertCount(2, $payments);

        $types = array_map(fn(Payment $p) => $p->getPaymentType(), $payments);
        $this->assertContains(PaymentType::TAXA_JUDICIARA, $types);
        $this->assertContains(PaymentType::COMISION_PLATFORMA, $types);
    }

    public function testSubmitPaymentsHaveCorrectAmounts(): void
    {
        $case = $this->createDraftCase('1500.00');
        $this->service->submit($case);

        $payments = $this->em->getRepository(Payment::class)->findBy(['legalCase' => $case]);
        foreach ($payments as $payment) {
            if ($payment->getPaymentType() === PaymentType::TAXA_JUDICIARA) {
                $this->assertSame('50.00', $payment->getAmount());
            }
            if ($payment->getPaymentType() === PaymentType::COMISION_PLATFORMA) {
                $this->assertSame('29.90', $payment->getAmount());
            }
        }
    }

    public function testSubmitAppliesWorkflowTransition(): void
    {
        $case = $this->createDraftCase();
        $this->service->submit($case);

        $this->assertSame('pending_payment', $case->getStatus());
    }

    public function testSubmitGeneratesPdf(): void
    {
        $case = $this->createDraftCase();
        $this->service->submit($case);

        $documents = $this->em->getRepository(Document::class)->findBy(['legalCase' => $case]);
        $this->assertNotEmpty($documents);
        $this->assertSame('application/pdf', $documents[0]->getMimeType());
    }

    public function testSubmitSetsSubmittedAt(): void
    {
        $case = $this->createDraftCase();
        $this->assertNull($case->getSubmittedAt());

        $this->service->submit($case);

        $this->assertNotNull($case->getSubmittedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $case->getSubmittedAt());
    }

    public function testCalculateFeesSetsFeeFields(): void
    {
        $case = $this->createDraftCase('5000.00');
        $this->service->calculateFees($case);

        $this->assertNotNull($case->getCourtFee());
        $this->assertNotNull($case->getPlatformFee());
        $this->assertNotNull($case->getTotalFee());
        $this->assertSame('29.90', $case->getPlatformFee());
    }

    public function testCalculateFeesSkipsZeroAmount(): void
    {
        $case = $this->createDraftCase('0');
        $this->service->calculateFees($case);

        $this->assertNull($case->getCourtFee());
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
            "DELETE d FROM document d JOIN legal_case lc ON d.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
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
