<?php

namespace App\Tests\Repository;

use App\Entity\Court;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CourtType;
use App\Repository\LegalCaseRepository;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class LegalCaseRepositoryTest extends KernelTestCase
{
    use CountyFixtureTrait;

    private EntityManagerInterface $em;
    private LegalCaseRepository $repo;
    private User $user;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(LegalCase::class);
        $this->testPrefix = 'repo-case-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function createCase(CaseStatus $status = CaseStatus::AMIABIL, bool $deleted = false): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus($status);
        if ($deleted) {
            $case->setDeletedAt(new \DateTimeImmutable());
        }
        $this->em->persist($case);

        return $case;
    }

    private function createCourt(bool $withPortalCode = true): Court
    {
        $court = new Court();
        $court->setName('Repo Court ' . $this->testPrefix . '-' . uniqid());
        $court->setCounty($this->createCounty($this->em, 'CJ'));
        $court->setType(CourtType::JUDECATORIE);
        if ($withPortalCode) {
            $court->setPortalCode('RepoCourt' . uniqid());
        }
        $this->em->persist($court);

        return $court;
    }

    public function testFindByUserReturnsOnlyUserCases(): void
    {
        $this->createCase();
        $this->createCase();

        // Create another user with a case
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $other = new User();
        $other->setEmail($this->testPrefix . '-other@test.com');
        $other->setPassword($hasher->hashPassword($other, 'test'));
        $other->setIsVerified(true);
        $this->em->persist($other);

        $otherCase = new LegalCase();
        $otherCase->setUser($other);
        $otherCase->setStatus(CaseStatus::AMIABIL);
        $this->em->persist($otherCase);
        $this->em->flush();

        $result = $this->repo->findByUser($this->user);
        $this->assertCount(2, $result);
        foreach ($result as $case) {
            $this->assertSame($this->user->getId(), $case->getUser()->getId());
        }
    }

    public function testFindByUserExcludesSoftDeleted(): void
    {
        $this->createCase(CaseStatus::AMIABIL, false);
        $this->createCase(CaseStatus::AMIABIL, true);
        $this->em->flush();

        $result = $this->repo->findByUser($this->user);
        $this->assertCount(1, $result);
    }

    public function testFindByUserOrdersByCreatedAtDesc(): void
    {
        $this->createCase();
        $this->em->flush();

        // Small delay to ensure different timestamps
        usleep(10000);

        $this->createCase();
        $this->em->flush();

        $result = $this->repo->findByUser($this->user);
        $this->assertCount(2, $result);
        $this->assertGreaterThanOrEqual(
            $result[1]->getCreatedAt()->getTimestamp(),
            $result[0]->getCreatedAt()->getTimestamp()
        );
    }

    public function testCountAllExcludesSoftDeleted(): void
    {
        $countBefore = $this->repo->countAll();
        $this->createCase(CaseStatus::AMIABIL, false);
        $this->createCase(CaseStatus::AMIABIL, true);
        $this->em->flush();

        $this->assertSame($countBefore + 1, $this->repo->countAll());
    }

    public function testCountByStatusFiltersByStatusAndExcludesSoftDeleted(): void
    {
        $amiabilBefore = $this->repo->countByStatus(CaseStatus::AMIABIL->value);
        $depusaBefore = $this->repo->countByStatus(CaseStatus::CERERE_DEPUSA->value);

        $this->createCase(CaseStatus::AMIABIL);
        $this->createCase(CaseStatus::AMIABIL, true); // soft-deleted
        $this->createCase(CaseStatus::CERERE_DEPUSA);
        $this->em->flush();

        $this->assertSame($amiabilBefore + 1, $this->repo->countByStatus(CaseStatus::AMIABIL->value));
        $this->assertSame($depusaBefore + 1, $this->repo->countByStatus(CaseStatus::CERERE_DEPUSA->value));
    }

    public function testFindActiveForMonitoringIncludesOnlyEligibleCases(): void
    {
        $courtWithCode = $this->createCourt(true);
        $courtWithoutCode = $this->createCourt(false);

        // Eligibil: activ + courtCaseNumber + status pe portal + instanță cu cod.
        $eligible = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $eligible->setCourt($courtWithCode);
        $eligible->setCourtCaseNumber('111/211/2026');
        $eligible->setPortalMonitoringActive(true);

        // Inactiv (portalMonitoringActive = false).
        $inactive = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $inactive->setCourt($courtWithCode);
        $inactive->setCourtCaseNumber('222/211/2026');

        // Fără courtCaseNumber.
        $noNumber = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $noNumber->setCourt($courtWithCode);
        $noNumber->setPortalMonitoringActive(true);

        // Status terminal (nu e activ pe portal).
        $terminal = $this->createCase(CaseStatus::DEFINITIVA);
        $terminal->setCourt($courtWithCode);
        $terminal->setCourtCaseNumber('333/211/2026');
        $terminal->setPortalMonitoringActive(true);

        // Instanță fără cod portal.
        $noPortalCode = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $noPortalCode->setCourt($courtWithoutCode);
        $noPortalCode->setCourtCaseNumber('444/211/2026');
        $noPortalCode->setPortalMonitoringActive(true);

        // Soft-deleted eligibil.
        $deleted = $this->createCase(CaseStatus::DOSAR_INREGISTRAT, true);
        $deleted->setCourt($courtWithCode);
        $deleted->setCourtCaseNumber('555/211/2026');
        $deleted->setPortalMonitoringActive(true);

        $this->em->flush();

        $result = $this->repo->findActiveForMonitoring();
        $ids = array_map(static fn (LegalCase $c): int => $c->getId(), $result);

        $this->assertContains($eligible->getId(), $ids);
        $this->assertNotContains($inactive->getId(), $ids);
        $this->assertNotContains($noNumber->getId(), $ids);
        $this->assertNotContains($terminal->getId(), $ids);
        $this->assertNotContains($noPortalCode->getId(), $ids);
        $this->assertNotContains($deleted->getId(), $ids);
    }

    /**
     * The "În recuperare" KPI is labelled RON, so a case whose amount is still in
     * a foreign currency (positions awaiting a manual exchange rate) must not be
     * summed into the RON total. Terminal and soft-deleted cases are excluded too.
     */
    public function testSumActiveAmountByUserCountsOnlyRonActiveCases(): void
    {
        $ronActive = $this->createCase(CaseStatus::AMIABIL);
        $ronActive->setAmount('10000.00');
        $ronActive->setCurrency('RON');

        $eurActive = $this->createCase(CaseStatus::AMIABIL);
        $eurActive->setAmount('5000.00');
        $eurActive->setCurrency('EUR');

        $ronTerminal = $this->createCase(CaseStatus::INCHIS_SUCCES);
        $ronTerminal->setAmount('7000.00');
        $ronTerminal->setCurrency('RON');

        $ronDeleted = $this->createCase(CaseStatus::AMIABIL, true);
        $ronDeleted->setAmount('3000.00');
        $ronDeleted->setCurrency('RON');

        $this->em->flush();

        self::assertSame(10000.0, $this->repo->sumActiveAmountByUser($this->user));
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%']
        );
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->testPrefix . '%']);
        $conn->executeStatement("DELETE FROM court WHERE name LIKE ?", ['Repo Court ' . $this->testPrefix . '%']);
        parent::tearDown();
    }
}
