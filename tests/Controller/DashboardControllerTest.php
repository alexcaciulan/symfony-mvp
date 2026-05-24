<?php

namespace App\Tests\Controller;

use App\Entity\Court;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CourtType;
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
        $conn->executeStatement("DELETE n FROM notification n JOIN user u ON n.user_id = u.id WHERE u.email LIKE 'dashboard-test-%'");
        $conn->executeStatement("DELETE ld FROM legal_deadline ld JOIN legal_case lc ON ld.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE 'dashboard-test-%'");
        $conn->executeStatement("DELETE FROM legal_case WHERE user_id IN (SELECT id FROM user WHERE email LIKE 'dashboard-test-%')");
        $conn->executeStatement("DELETE FROM court WHERE name LIKE 'DashTestCourt-%'");
        $conn->executeStatement("DELETE FROM user WHERE email LIKE 'dashboard-test-%'");
    }

    private function createCase(User $owner, string $courtName, CaseStatus $status = CaseStatus::AMIABIL): LegalCase
    {
        $court = new Court();
        $court->setName($courtName);
        $court->setCounty('CJ');
        $court->setType(CourtType::JUDECATORIE);
        $this->em->persist($court);

        $case = new LegalCase();
        $case->setUser($owner);
        $case->setCourt($court);
        $case->setStatus($status);
        $case->setAmount('5000.00');
        $case->setCurrency('RON');
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    public function testDashboardRequiresAuth(): void
    {
        self::ensureKernelShutdown();
        $anonClient = static::createClient();
        $anonClient->request('GET', '/dashboard');

        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', $anonClient->getResponse()->headers->get('Location'));
    }

    public function testDashboardShowsRecentCases(): void
    {
        $courtName = 'DashTestCourt-' . uniqid();
        $this->createCase($this->user, $courtName);

        $this->client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString($courtName, $content);
    }

    public function testDashboardRendersKpiCards(): void
    {
        $this->createCase($this->user, 'DashTestCourt-' . uniqid());

        $this->client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $translator = $this->client->getContainer()->get('translator');
        $this->assertStringContainsString($translator->trans('dashboard.cases.kpi.active'), $content);
        $this->assertStringContainsString($translator->trans('dashboard.cases.kpi.overdue'), $content);
    }

    public function testDashboardDoesNotShowOtherUsersCases(): void
    {
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');
        $otherUser = new User();
        $otherUser->setEmail('dashboard-test-other-' . uniqid() . '@example.com');
        $otherUser->setPassword($hasher->hashPassword($otherUser, 'password'));
        $otherUser->setIsVerified(true);
        $this->em->persist($otherUser);
        $this->em->flush();

        $otherCourtName = 'DashTestCourt-other-' . uniqid();
        $this->createCase($otherUser, $otherCourtName);

        // Current user has at least one case so the recent table renders.
        $this->createCase($this->user, 'DashTestCourt-' . uniqid());

        $this->client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString($otherCourtName, $content);
    }

    public function testDashboardExcludesSoftDeletedCases(): void
    {
        $visibleCourt = 'DashTestCourt-' . uniqid();
        $this->createCase($this->user, $visibleCourt);

        $deletedCourt = 'DashTestCourt-deleted-' . uniqid();
        $deleted = $this->createCase($this->user, $deletedCourt);
        $deleted->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString($visibleCourt, $content);
        $this->assertStringNotContainsString($deletedCourt, $content);
    }

    public function testDashboardShowsEmptyStateWhenNoCases(): void
    {
        $this->client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString(
            $this->client->getContainer()->get('translator')->trans('dashboard.cases.empty_heading'),
            $content,
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }
}
