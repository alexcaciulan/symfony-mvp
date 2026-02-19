<?php

namespace App\Tests\Controller;

use App\Entity\Court;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CourtType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CaseWizardControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private Court $court;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->createTestData();
        $this->client->loginUser($this->user);
    }

    private function createTestData(): void
    {
        $this->court = new Court();
        $this->court->setName('Judecătoria Test');
        $this->court->setCounty('Test');
        $this->court->setType(CourtType::JUDECATORIE);
        $this->court->setActive(true);
        $this->em->persist($this->court);

        $this->user = new User();
        $this->user->setEmail('wizard-test-' . uniqid() . '@test.com');
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Test');
        $this->user->setLastName('User');
        $this->em->persist($this->user);

        $this->em->flush();
    }

    public function testNewCaseCreatesAndRedirects(): void
    {
        $this->client->request('GET', '/case/new');

        $this->assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        $this->assertMatchesRegularExpression('#/case/\d+/step/1#', $location);
    }

    public function testNewCaseRequiresAuth(): void
    {
        self::ensureKernelShutdown();
        $anonClient = static::createClient();
        $anonClient->request('GET', '/case/new');

        $this->assertResponseRedirects('/login');
    }

    public function testStep1DisplaysForm(): void
    {
        $legalCase = $this->createDraftCase();

        $this->client->request('GET', "/case/{$legalCase->getId()}/step/1");

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testStep1SubmitSavesCourtAndRedirects(): void
    {
        $legalCase = $this->createDraftCase();
        $id = $legalCase->getId();

        // Get CSRF token from rendered form
        $crawler = $this->client->request('GET', "/case/{$id}/step/1");
        $token = $crawler->filter('input[id=step1_court__token]')->attr('value');

        // Submit directly via POST (court dropdown is JS-populated, can't use crawler form)
        $this->client->request('POST', "/case/{$id}/step/1", [
            'step1_court' => [
                'county' => 'Test',
                'court' => $this->court->getId(),
                '_token' => $token,
            ],
        ]);

        $this->assertResponseRedirects("/case/{$id}/step/2");

        $this->em->clear();
        $updated = $this->em->find(LegalCase::class, $id);
        $this->assertSame('Test', $updated->getCounty());
        $this->assertSame($this->court->getId(), $updated->getCourt()->getId());
        $this->assertSame(2, $updated->getCurrentStep());
    }

    public function testStep2DisplaysPrefilled(): void
    {
        $legalCase = $this->createDraftCase(2);

        $this->client->request('GET', "/case/{$legalCase->getId()}/step/2");

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testStep2SubmitSavesClaimantData(): void
    {
        $legalCase = $this->createDraftCase(2);
        $id = $legalCase->getId();

        $crawler = $this->client->request('GET', "/case/{$id}/step/2");
        $form = $crawler->filter('button[type=submit]')->form([
            'step2_claimant[type]' => 'pf',
            'step2_claimant[name]' => 'Ion Popescu',
            'step2_claimant[email]' => 'ion@test.com',
            'step2_claimant[city]' => 'București',
            'step2_claimant[county]' => 'București',
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects("/case/{$id}/step/3");

        $this->em->clear();
        $updated = $this->em->find(LegalCase::class, $id);
        $this->assertSame('pf', $updated->getClaimantType());
        $this->assertSame('Ion Popescu', $updated->getClaimantData()['name']);
    }

    public function testStep3SubmitSavesDefendants(): void
    {
        $legalCase = $this->createDraftCase(3);
        $id = $legalCase->getId();

        $crawler = $this->client->request('GET', "/case/{$id}/step/3");
        $form = $crawler->filter('button[type=submit]')->form([
            'step3_defendant[defendants][0][type]' => 'pf',
            'step3_defendant[defendants][0][name]' => 'Maria Ionescu',
            'step3_defendant[defendants][0][city]' => 'Cluj-Napoca',
            'step3_defendant[defendants][0][county]' => 'Cluj',
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects("/case/{$id}/step/4");

        $this->em->clear();
        $updated = $this->em->find(LegalCase::class, $id);
        $this->assertCount(1, $updated->getDefendants());
        $this->assertSame('Maria Ionescu', $updated->getDefendants()[0]['name']);
    }

    public function testStep4SubmitSavesClaimData(): void
    {
        $legalCase = $this->createDraftCase(4);
        $id = $legalCase->getId();

        $crawler = $this->client->request('GET', "/case/{$id}/step/4");
        $form = $crawler->filter('button[type=submit]')->form([
            'step4_claim[claimAmount]' => '5000',
            'step4_claim[claimDescription]' => 'Datorie neplătită conform contractului',
            'step4_claim[interestType]' => 'none',
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects("/case/{$id}/step/5");

        $this->em->clear();
        $updated = $this->em->find(LegalCase::class, $id);
        $this->assertSame('5000.00', $updated->getClaimAmount());
        $this->assertStringContainsString('Datorie', $updated->getClaimDescription());
    }

    public function testStep4InvalidAmountShowsError(): void
    {
        $legalCase = $this->createDraftCase(4);
        $id = $legalCase->getId();

        $crawler = $this->client->request('GET', "/case/{$id}/step/4");
        $form = $crawler->filter('button[type=submit]')->form([
            'step4_claim[claimAmount]' => '15000',
            'step4_claim[claimDescription]' => 'Test',
            'step4_claim[interestType]' => 'none',
        ]);
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorExists('form');
    }

    public function testCannotSkipSteps(): void
    {
        $legalCase = $this->createDraftCase(1);

        $this->client->request('GET', "/case/{$legalCase->getId()}/step/3");

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCannotAccessOtherUsersCase(): void
    {
        $otherUser = new User();
        $otherUser->setEmail('other-' . uniqid() . '@test.com');
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $otherUser->setPassword($hasher->hashPassword($otherUser, 'password'));
        $otherUser->setIsVerified(true);
        $this->em->persist($otherUser);

        $otherCase = new LegalCase();
        $otherCase->setUser($otherUser);
        $otherCase->setStatus('draft');
        $this->em->persist($otherCase);
        $this->em->flush();

        $this->client->request('GET', "/case/{$otherCase->getId()}/step/1");

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCourtsByCountyReturnsJson(): void
    {
        $this->client->request('GET', '/case/courts-by-county/Test');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNotEmpty($data);
        $this->assertSame('Judecătoria Test', $data[0]['name']);
    }

    public function testDashboardCasesListsUserCases(): void
    {
        $this->createDraftCase();

        $this->client->request('GET', '/dashboard/cases');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('table');
    }

    public function testDashboardCasesEmptyState(): void
    {
        $this->client->request('GET', '/dashboard/cases');

        $this->assertResponseIsSuccessful();
    }

    public function testNavigateBackPreservesData(): void
    {
        $legalCase = $this->createDraftCase(2);
        $legalCase->setCounty('Test');
        $legalCase->setCourt($this->court);
        $this->em->flush();

        $this->client->request('GET', "/case/{$legalCase->getId()}/step/1");

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    private function createDraftCase(int $currentStep = 1): LegalCase
    {
        $legalCase = new LegalCase();
        $legalCase->setUser($this->user);
        $legalCase->setStatus('draft');
        $legalCase->setCurrentStep($currentStep);
        $this->em->persist($legalCase);
        $this->em->flush();

        return $legalCase;
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$this->user->getId()]);
        $conn->executeStatement('DELETE FROM user WHERE id = ?', [$this->user->getId()]);
        $conn->executeStatement('DELETE FROM court WHERE name = ?', ['Judecătoria Test']);

        parent::tearDown();
    }
}
