<?php

declare(strict_types=1);

namespace App\Tests\Service\Table\Definition;

use App\Entity\Court;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CourtType;
use App\Service\Table\TableDataService;
use App\Service\Table\TableDefinitionInterface;
use App\Service\Table\TableQuery;
use App\Service\Table\TableRegistry;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LegalCaseTableDefinitionTest extends KernelTestCase
{
    use CountyFixtureTrait;

    private EntityManagerInterface $em;
    private TableDataService $service;
    private TableDefinitionInterface $definition;
    private User $user;
    private string $prefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(TableDataService::class);
        $this->definition = static::getContainer()->get(TableRegistry::class)->get('cases');
        $this->prefix = 'cases-tbl-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = $this->makeUser($hasher, $this->prefix . '@test.com');
        $this->em->flush();
    }

    public function testScopesToUser(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $other = $this->makeUser($hasher, $this->prefix . '-other@test.com');
        $this->makeCase($this->user, CaseStatus::AMIABIL);
        $this->makeCase($other, CaseStatus::AMIABIL);
        $this->em->flush();

        $result = $this->service->query($this->definition, $this->user, new TableQuery());

        self::assertSame(1, $result->total);
    }

    public function testStatusEnumFilterAcceptsMultipleValues(): void
    {
        $this->makeCase($this->user, CaseStatus::AMIABIL);
        $this->makeCase($this->user, CaseStatus::RESPINSA);
        $this->makeCase($this->user, CaseStatus::DEFINITIVA);
        $this->em->flush();

        $single = $this->service->query($this->definition, $this->user, new TableQuery(filters: ['status' => ['AMIABIL']]));
        self::assertSame(1, $single->total);

        $multi = $this->service->query($this->definition, $this->user, new TableQuery(filters: ['status' => ['AMIABIL', 'RESPINSA']]));
        self::assertSame(2, $multi->total);
    }

    public function testSearchMatchesInternalAndCourtCaseNumber(): void
    {
        $this->makeCase($this->user, CaseStatus::AMIABIL, courtCaseNumber: '200/211/2026');
        $this->makeCase($this->user, CaseStatus::AMIABIL, courtCaseNumber: '999/333/2026');
        $this->em->flush();

        $result = $this->service->query($this->definition, $this->user, new TableQuery(filters: ['search' => '211']));

        self::assertSame(1, $result->total);
        self::assertSame('200/211/2026', $result->rows[0]['caseNumber']);
    }

    public function testSerializeRowExposesStatusBadgePayload(): void
    {
        $this->makeCase($this->user, CaseStatus::ORDONANTA_EMISA);
        $this->em->flush();

        $row = $this->service->query($this->definition, $this->user, new TableQuery())->rows[0];

        self::assertSame('ORDONANTA_EMISA', $row['status']['value']);
        self::assertArrayHasKey('label', $row['status']);
        self::assertSame('violet', $row['status']['color']);
    }

    public function testAutocompleteFilterMatchesByCourtId(): void
    {
        $courtA = $this->makeCourt('Judecătoria ' . $this->prefix . '-A');
        $courtB = $this->makeCourt('Judecătoria ' . $this->prefix . '-B');
        $this->em->flush();

        $this->makeCase($this->user, CaseStatus::AMIABIL, court: $courtA);
        $this->makeCase($this->user, CaseStatus::AMIABIL, court: $courtA);
        $this->makeCase($this->user, CaseStatus::AMIABIL, court: $courtB);
        $this->em->flush();

        $result = $this->service->query($this->definition, $this->user, new TableQuery(filters: ['court' => $courtA->getId()]));

        self::assertSame(2, $result->total);
    }

    public function testSortByAmountIsNumericOnDecimalColumn(): void
    {
        $this->makeCase($this->user, CaseStatus::AMIABIL, amount: '200.00');
        $this->makeCase($this->user, CaseStatus::AMIABIL, amount: '1000.00');
        $this->makeCase($this->user, CaseStatus::AMIABIL, amount: '50.00');
        $this->em->flush();

        $asc = $this->service->query($this->definition, $this->user, new TableQuery(sortField: 'amount', sortDir: 'ASC'));
        // Numeric order (50 < 200 < 1000), not lexicographic ('1000' < '200' < '50').
        self::assertSame('50,00 RON', $asc->rows[0]['amount']);
        self::assertSame('1.000,00 RON', $asc->rows[2]['amount']);

        $desc = $this->service->query($this->definition, $this->user, new TableQuery(sortField: 'amount', sortDir: 'DESC'));
        self::assertSame('1.000,00 RON', $desc->rows[0]['amount']);
    }

    public function testPaginationAndClamp(): void
    {
        for ($i = 0; $i < 12; ++$i) {
            $this->makeCase($this->user, CaseStatus::AMIABIL);
        }
        $this->em->flush();

        $result = $this->service->query($this->definition, $this->user, new TableQuery(page: 1, pageSize: 999));

        self::assertSame(12, $result->total);
        self::assertSame(TableDataService::DEFAULT_PAGE_SIZE, $result->pageSize);
        self::assertCount(12, $result->rows);
    }

    private function makeUser(UserPasswordHasherInterface $hasher, string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($hasher->hashPassword($user, 'test'));
        $user->setIsVerified(true);
        $this->em->persist($user);

        return $user;
    }

    private function makeCase(User $user, CaseStatus $status, ?string $courtCaseNumber = null, string $amount = '1000.00', ?Court $court = null): void
    {
        $case = new LegalCase();
        $case->setUser($user);
        $case->setStatus($status);
        $case->setCaseNumber('LR-' . uniqid());
        $case->setCourtCaseNumber($courtCaseNumber);
        $case->setAmount($amount);
        $case->setCurrency('RON');
        if ($court !== null) {
            $case->setCourt($court);
        }
        $this->em->persist($case);
    }

    private function makeCourt(string $name): Court
    {
        $court = new Court();
        $court->setName($name);
        $court->setCounty($this->createCounty($this->em, 'Ilfov'));
        $court->setType(CourtType::JUDECATORIE);
        $court->setActive(true);
        $this->em->persist($court);

        return $court;
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?", [$this->prefix . '%']);
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->prefix . '%']);
        $conn->executeStatement("DELETE FROM court WHERE name LIKE ?", ['%' . $this->prefix . '%']);
        parent::tearDown();
    }
}
