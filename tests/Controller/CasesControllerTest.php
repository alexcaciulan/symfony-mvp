<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CasesControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $prefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->prefix = 'cases-ctrl-' . uniqid();
    }

    public function testListPageRendersTableComponent(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/cases');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('[data-controller="tabulator"]')->count());
    }

    public function testApiReturnsOnlyOwnCasesFilteredByStatus(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $other = $this->makeUser($this->prefix . '-other@test.com');
        $this->makeCase($user, CaseStatus::AMIABIL, 'OWN-AMIABIL');
        $this->makeCase($user, CaseStatus::RESPINSA, 'OWN-RESPINSA');
        $this->makeCase($other, CaseStatus::AMIABIL, 'OTHER-AMIABIL');
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/api/table/cases', [
            'filter' => [['field' => 'status', 'value' => ['AMIABIL']]],
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame(1, $payload['last_row']);
        self::assertCount(1, $payload['data']);
        self::assertSame('OWN-AMIABIL', $payload['data'][0]['caseNumber']);
    }

    public function testApiSearchMatchesCourtCaseNumber(): void
    {
        $user = $this->makeUser($this->prefix . '@test.com');
        $this->makeCase($user, CaseStatus::AMIABIL, 'LR-A', '500/302/2026');
        $this->makeCase($user, CaseStatus::AMIABIL, 'LR-B', '777/302/2026');
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/api/table/cases', [
            'filter' => [['field' => 'search', 'value' => '500']],
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame(1, $payload['last_row']);
        self::assertSame('500/302/2026', $payload['data'][0]['caseNumber']);
    }

    private function makeUser(string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($hasher->hashPassword($user, 'test'));
        $user->setIsVerified(true);
        $this->em->persist($user);

        return $user;
    }

    private function makeCase(User $user, CaseStatus $status, string $caseNumber, ?string $courtCaseNumber = null): void
    {
        $case = new LegalCase();
        $case->setUser($user);
        $case->setStatus($status);
        $case->setCaseNumber($caseNumber);
        $case->setCourtCaseNumber($courtCaseNumber);
        $case->setAmount('1000.00');
        $case->setCurrency('RON');
        $this->em->persist($case);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?", [$this->prefix . '%']);
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->prefix . '%']);
        parent::tearDown();
    }
}
