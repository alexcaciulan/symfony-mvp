<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CourtType;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pas 4.0.1 + 4.0.2 — Tests for the case overview page.
 *
 * Exercises the full HTTP cycle (login + voter + template render) for the
 * `case_overview` route at `/case/{id}`. Verifies authorization edges
 * (200 owner / 403 cross-user / 404 missing / 302 anon), DOM structure
 * (5 tab panels at 4.0.1; hero/KPI/pipeline/tabs nav rendering at 4.0.2).
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
        $conn->executeStatement('DELETE FROM case_status_history WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement("DELETE FROM court WHERE name LIKE 'Test Court %'");
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);

        parent::tearDown();
    }

    /**
     * Attach a creditor, primary debtor, court, and full claim figures to {@see self::$case}
     * so hero/KPI assertions have realistic data. Returns the case for chaining.
     */
    private function enrichCase(?CaseStatus $status = null): LegalCase
    {
        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Tehno Construct SRL');
        $creditor->setAddress('Str. Industriilor 47, București');
        $this->em->persist($creditor);

        $debtor = new Debtor();
        $debtor->setLegalCase($this->case);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Beta Solutions SRL');
        $debtor->setAddress('Str. Iuliu Maniu 152, București');
        $this->em->persist($debtor);

        $court = new Court();
        $court->setName('Test Court ' . uniqid());
        $court->setCounty('București');
        $court->setType(CourtType::JUDECATORIE);
        $this->em->persist($court);

        $this->case->setCreditor($creditor);
        $this->case->addDebtor($debtor);
        $this->case->setCourt($court);
        $this->case->setAmount('47500.00');
        $this->case->setCurrency('RON');
        $this->case->setDueDate(new \DateTime('-90 days'));
        $this->case->setCalculatedInterest('3842.50');
        $this->case->setStampDuty('200.00');
        $this->case->setRelationshipType(RelationshipType::COMERCIAL);
        if ($status !== null) {
            $this->case->setStatus($status);
        }

        $this->em->flush();

        return $this->case;
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
        $this->client->request('GET', '/case/999999999');

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

    public function testHeroRendersCreditorVsDebtorTitle(): void
    {
        $this->enrichCase();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $h1 = $crawler->filter('h1')->text();
        self::assertStringContainsString('SC Tehno Construct SRL', $h1);
        self::assertStringContainsString('vs', $h1);
        self::assertStringContainsString('SC Beta Solutions SRL', $h1);
    }

    public function testHeroRendersStatusPillMatchingCaseStatus(): void
    {
        $this->enrichCase(CaseStatus::SOMATIE_TRIMISA);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        // StatusBadge maps SOMATIE_TRIMISA → amber palette + label from enum.case_status key.
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('bg-amber-100', $html);
        self::assertStringContainsString('soft-pulse', $html, 'Pulse animation must be active for non-terminal status');
    }

    public function testHeroMetadataRendersCourtNameWhenPresent(): void
    {
        $this->enrichCase();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString($this->case->getCourt()->getName(), $html);
    }

    public function testHeroMetadataRendersCourtUndeterminedWhenNull(): void
    {
        // Don't call enrichCase — base setUp case has no court.
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Instanță needeterminată');
    }

    public function testKpiPrincipalRendersWithCurrency(): void
    {
        $this->enrichCase();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('47.500,00', $html, 'Principal amount formatted with Romanian thousand/decimal separators');
        self::assertStringContainsString('RON', $html);
    }

    public function testKpiActiveDeadlinePlaceholderWhenNoDeadlines(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Niciun termen activ');
    }

    public function testPipelineActiveStageMatchesCaseStatus(): void
    {
        $this->enrichCase(CaseStatus::CERERE_DEPUSA);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        // Stage 3 (CERERE_DEPUSA index=2) must be active (amber styling + "Cerere depusă" label).
        self::assertStringContainsString('Stadiu 3 din 5', $html);
        self::assertStringContainsString('Cerere depusă', $html);
    }

    public function testTabsNavRenders5ButtonsWithDataHsTab(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('button[data-hs-tab]'));
    }
}
