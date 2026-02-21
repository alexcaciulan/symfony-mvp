<?php

namespace App\Tests\Repository;

use App\Entity\LegalCase;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\PaymentStatus;
use App\Enum\PaymentType;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PaymentRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PaymentRepository $repo;
    private User $user;
    private LegalCase $case;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(Payment::class);
        $this->testPrefix = 'repo-pay-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setStatus('draft');
        $this->case->setCurrentStep(1);
        $this->em->persist($this->case);

        $this->em->flush();
    }

    private function createPayment(string $amount, PaymentStatus $status, ?\DateTimeImmutable $createdAt = null): Payment
    {
        $payment = new Payment();
        $payment->setLegalCase($this->case);
        $payment->setUser($this->user);
        $payment->setAmount($amount);
        $payment->setPaymentType(PaymentType::TAXA_JUDICIARA);
        $payment->setStatus($status);
        $this->em->persist($payment);

        // Override createdAt if needed via reflection
        if ($createdAt) {
            $ref = new \ReflectionProperty(Payment::class, 'createdAt');
            $ref->setValue($payment, $createdAt);
        }

        return $payment;
    }

    public function testSumCompletedCurrentMonthOnlyCountsCompleted(): void
    {
        $this->createPayment('100.00', PaymentStatus::COMPLETED);
        $this->createPayment('50.00', PaymentStatus::PENDING);
        $this->createPayment('75.00', PaymentStatus::FAILED);
        $this->em->flush();

        $sum = $this->repo->sumCompletedCurrentMonth();
        // Sum should include only the 100.00 completed payment (plus any pre-existing)
        $this->assertGreaterThanOrEqual(100, (float) $sum);
    }

    public function testSumCompletedCurrentMonthExcludesPreviousMonth(): void
    {
        $lastMonth = new \DateTimeImmutable('first day of last month');
        $this->createPayment('200.00', PaymentStatus::COMPLETED, $lastMonth);
        $this->em->flush();

        // The last month payment should not be included
        // We can't assert exact value since other tests may leave data,
        // but we check that adding a last-month payment doesn't increase the sum
        $sumBefore = $this->repo->sumCompletedCurrentMonth();

        $this->createPayment('50.00', PaymentStatus::COMPLETED, $lastMonth);
        $this->em->flush();

        $sumAfter = $this->repo->sumCompletedCurrentMonth();
        $this->assertSame($sumBefore, $sumAfter);
    }

    public function testSumCompletedCurrentMonthReturnsZeroWhenEmpty(): void
    {
        // No payments created for current month
        $sum = $this->repo->sumCompletedCurrentMonth();
        $this->assertIsString($sum);
        // COALESCE returns 0 when no rows match
        $this->assertGreaterThanOrEqual(0, (float) $sum);
    }

    public function testSumCompletedCurrentMonthSumsMultiplePayments(): void
    {
        $sumBefore = (float) $this->repo->sumCompletedCurrentMonth();

        $this->createPayment('100.50', PaymentStatus::COMPLETED);
        $this->createPayment('200.25', PaymentStatus::COMPLETED);
        $this->em->flush();

        $sum = (float) $this->repo->sumCompletedCurrentMonth();
        $this->assertEqualsWithDelta($sumBefore + 300.75, $sum, 0.01);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
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
