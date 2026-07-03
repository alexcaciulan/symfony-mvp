<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class InvoiceListControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private string $prefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->prefix = 'inv-page-' . uniqid();

        $this->user = new User();
        $this->user->setEmail($this->prefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM user WHERE email LIKE ?', [$this->prefix . '%']);
        parent::tearDown();
    }

    public function testInvoicesPageMountsTable(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/invoices');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('div[data-controller="tabulator"]');
    }
}
