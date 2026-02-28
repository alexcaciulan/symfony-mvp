<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CompanyLookupTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private string $testPrefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->testPrefix = 'lookup-' . uniqid();

        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    public function testLookupEndpointReturnsJson(): void
    {
        // ANAF may or may not be reachable from Docker — both 200 and 400 are valid
        $this->client->request('GET', '/case/company-lookup/14399840');
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 400]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);
    }

    public function testLookupRejectsInvalidCui(): void
    {
        $this->client->request('GET', '/case/company-lookup/1');

        $this->assertResponseStatusCodeSame(400);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testLookupRejectsNonNumericCui(): void
    {
        $this->client->request('GET', '/case/company-lookup/abc');

        $this->assertResponseStatusCodeSame(400);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testLookupResponseHasCorrectStructure(): void
    {
        $this->client->request('GET', '/case/company-lookup/14399840');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);

        if ($data['success']) {
            $this->assertArrayHasKey('data', $data);
            $companyData = $data['data'];
            $this->assertArrayHasKey('companyName', $companyData);
            $this->assertArrayHasKey('cui', $companyData);
            $this->assertArrayHasKey('stare', $companyData);
            $this->assertArrayHasKey('platitorTVA', $companyData);
        } else {
            $this->assertArrayHasKey('error', $data);
        }
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement(
            "DELETE FROM user WHERE email LIKE ?",
            [$this->testPrefix . '%'],
        );
        parent::tearDown();
    }
}
