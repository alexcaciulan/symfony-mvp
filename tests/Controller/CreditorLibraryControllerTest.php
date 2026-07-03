<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Creditor;
use App\Entity\User;
use App\Enum\PersonType;
use App\Repository\CreditorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreditorLibraryControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private CreditorRepository $creditors;
    private UserPasswordHasherInterface $hasher;

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->creditors = static::getContainer()->get(CreditorRepository::class);
        $this->hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->userIds as $userId) {
            $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);
        }

        parent::tearDown();
    }

    private function createUser(): User
    {
        $user = new User();
        $user->setEmail('creditors-' . uniqid() . '@test.com');
        $user->setPassword($this->hasher->hashPassword($user, 'password'));
        $user->setIsVerified(true);
        $user->setFirstName('Lib');
        $user->setLastName('Tester');
        $this->em->persist($user);
        $this->em->flush();
        $this->userIds[] = $user->getId();

        return $user;
    }

    private function createCreditor(User $user, string $name, ?string $cui = null): Creditor
    {
        $creditor = new Creditor();
        $creditor->setUser($user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName($name);
        $creditor->setAddress('Str. Test 1, București');
        $creditor->setCui($cui);
        $this->em->persist($creditor);
        $this->em->flush();

        return $creditor;
    }

    public function testIndexRendersTheCreditorsGrid(): void
    {
        $user = $this->createUser();
        $this->client->loginUser($user);
        $this->client->request('GET', '/creditors');

        self::assertResponseIsSuccessful();
        // The page renders the Tabulator shell; rows are fetched over AJAX.
        self::assertSelectorExists('[data-controller="tabulator"]');
    }

    public function testTableEndpointReturnsOnlyCurrentUserCreditors(): void
    {
        $user = $this->createUser();
        $this->createCreditor($user, 'Alpha Mine SRL', 'RO15193236');
        $this->createCreditor($user, 'Beta Mine SRL', 'RO14186770');

        $other = $this->createUser();
        $this->createCreditor($other, 'Gamma Other SRL', 'RO12345675');

        $this->client->loginUser($user);
        $this->client->request('GET', '/api/table/creditors');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $names = array_column($payload['data'], 'name');

        self::assertContains('Alpha Mine SRL', $names);
        self::assertContains('Beta Mine SRL', $names);
        self::assertNotContains('Gamma Other SRL', $names);
        self::assertSame(2, $payload['last_row']);
    }

    public function testNewPersistsCreditorAndRedirects(): void
    {
        $user = $this->createUser();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/creditors/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['creditor[personType]'] = PersonType::PJ->value;
        $form['creditor[name]'] = 'Newly Added SRL';
        $form['creditor[cui]'] = 'RO15193236';
        $form['creditor[onrcNumber]'] = 'J40/1234/2020';
        $form['creditor[address]'] = 'Str. Nouă 5, Cluj';
        $this->client->submit($form);

        self::assertResponseRedirects('/creditors');

        $persisted = $this->creditors->findOneBy(['user' => $user, 'cui' => 'RO15193236']);
        self::assertNotNull($persisted);
        self::assertSame('Newly Added SRL', $persisted->getName());
    }

    public function testEditUpdatesOwnedCreditor(): void
    {
        $user = $this->createUser();
        $creditor = $this->createCreditor($user, 'Before Rename SRL', 'RO15193236');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/creditors/' . $creditor->getId() . '/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['creditor[name]'] = 'After Rename SRL';
        $form['creditor[onrcNumber]'] = 'J40/9999/2021';
        $this->client->submit($form);

        self::assertResponseRedirects('/creditors');

        $this->em->clear();
        $reloaded = $this->creditors->find($creditor->getId());
        self::assertSame('After Rename SRL', $reloaded->getName());
    }

    public function testEditOtherUsersCreditorIsForbidden(): void
    {
        $owner = $this->createUser();
        $creditor = $this->createCreditor($owner, 'Owned SRL', 'RO15193236');

        $intruder = $this->createUser();
        $this->client->loginUser($intruder);
        $this->client->request('GET', '/creditors/' . $creditor->getId() . '/edit');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/creditors');

        self::assertResponseRedirects();
    }

    public function testDuplicateCuiOnNewShowsFormErrorWithoutPersisting(): void
    {
        $user = $this->createUser();
        $this->createCreditor($user, 'Existing SRL', 'RO15193236');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/creditors/new');
        $form = $crawler->filter('form')->form();
        $form['creditor[personType]'] = PersonType::PJ->value;
        $form['creditor[name]'] = 'Duplicate SRL';
        $form['creditor[cui]'] = 'RO15193236';
        $form['creditor[onrcNumber]'] = 'J40/1234/2020';
        $form['creditor[address]'] = 'Str. Dubla 7, Iași';
        $this->client->submit($form);

        // Form re-rendered with the duplicate error (Symfony 7 returns 422 for
        // an invalid form) and only the original creditor remains.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $all = $this->creditors->findOneBy(['user' => $user, 'cui' => 'RO15193236']);
        self::assertSame('Existing SRL', $all->getName());
        self::assertCount(1, $this->creditors->findByUser($user));
    }
}
