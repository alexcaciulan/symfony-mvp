<?php

namespace App\Tests\Controller;

use App\Entity\LegalCase;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class DashboardControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine.orm.entity_manager');
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        // Clean up previous test data
        $this->cleanup();

        $this->user = new User();
        $this->user->setEmail('dashboard-test-' . uniqid() . '@example.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    private function cleanup(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE 'dashboard-test-%'");
        $conn->executeStatement("DELETE FROM user WHERE email LIKE 'dashboard-test-%'");
    }

    private function createCase(string $status = 'draft'): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus($status);
        $case->setCurrentStep(1);
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    public function testDashboardRequiresAuth(): void
    {
        self::ensureKernelShutdown();
        $anonClient = static::createClient();
        $anonClient->request('GET', '/dashboard/cases');

        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', $anonClient->getResponse()->headers->get('Location'));
    }

    public function testDashboardShowsUserCases(): void
    {
        $case = $this->createCase();

        $this->client->request('GET', '/dashboard/cases');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString((string) $case->getId(), $content);
    }

    public function testDashboardDoesNotShowOtherUsersCases(): void
    {
        // Create another user with a case
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');
        $otherUser = new User();
        $otherUser->setEmail('dashboard-test-other-' . uniqid() . '@example.com');
        $otherUser->setPassword($hasher->hashPassword($otherUser, 'password'));
        $otherUser->setIsVerified(true);
        $this->em->persist($otherUser);

        $otherCase = new LegalCase();
        $otherCase->setUser($otherUser);
        $otherCase->setStatus('draft');
        $otherCase->setCurrentStep(1);
        $otherCase->setClaimantData(['name' => 'Other User Case']);
        $this->em->persist($otherCase);
        $this->em->flush();

        $this->client->request('GET', '/dashboard/cases');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('Other User Case', $content);
    }

    public function testDashboardExcludesSoftDeletedCases(): void
    {
        $case = $this->createCase();
        $case->setDeletedAt(new \DateTimeImmutable());
        $case->setClaimantData(['name' => 'Deleted Case Marker']);
        $this->em->flush();

        $this->client->request('GET', '/dashboard/cases');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('Deleted Case Marker', $content);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }
}
