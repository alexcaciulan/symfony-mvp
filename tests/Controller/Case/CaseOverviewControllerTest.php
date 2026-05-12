<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pas 4.0.1 — Foundation tests for the case overview page.
 *
 * Exercises the full HTTP cycle (login + voter + template render) for the new
 * `case_overview` route at `/dosar/{id}`. Verifies authorization edges (200 owner
 * / 403 cross-user / 404 missing / 302 anon redirect) plus presence of all five
 * tab panels in the DOM (panels are stubs at this sub-step, populated 4.0.3+).
 */
final class CaseOverviewControllerTest extends WebTestCase
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
        $this->user->setEmail('overview-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Overview');
        $this->user->setLastName('Tester');
        $this->em->persist($this->user);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setStatus(CaseStatus::AMIABIL);
        $this->em->persist($this->case);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);

        parent::tearDown();
    }

    public function testGetOverviewForOwnedCaseReturns200(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSelectorTextContains('title', 'Dosar ' . $this->case->getCaseNumber());
    }

    public function testGetOverviewForOtherUserCaseReturns403(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $intruder = new User();
        $intruder->setEmail('overview-intruder-' . uniqid() . '@test.com');
        $intruder->setPassword($hasher->hashPassword($intruder, 'password'));
        $intruder->setIsVerified(true);
        $intruder->setFirstName('Intruder');
        $intruder->setLastName('Tester');
        $this->em->persist($intruder);
        $this->em->flush();

        try {
            $this->client->loginUser($intruder);
            $this->client->request('GET', '/case/' . $this->case->getId());

            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        } finally {
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $intruder->getId()]);
        }
    }

    public function testGetOverviewForNonexistentIdReturns404(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/dosar/999999999');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testGetOverviewUnauthenticatedRedirectsToLogin(): void
    {
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertResponseRedirects();
    }

    public function testAllFiveTabPanelsRenderInDom(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#panel-detalii[role="tabpanel"]');
        self::assertSelectorExists('#panel-documente[role="tabpanel"]');
        self::assertSelectorExists('#panel-termene[role="tabpanel"]');
        self::assertSelectorExists('#panel-portal[role="tabpanel"]');
        self::assertSelectorExists('#panel-audit[role="tabpanel"]');
    }
}
