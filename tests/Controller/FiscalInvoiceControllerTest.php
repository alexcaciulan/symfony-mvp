<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\DTO\Billing\PartySnapshot;
use App\Entity\FiscalInvoice;
use App\Entity\FiscalInvoiceLine;
use App\Entity\User;
use App\Enum\FiscalInvoiceStatus;
use App\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class FiscalInvoiceControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private FiscalInvoice $invoice;
    private string $prefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->prefix = 'fiscal-ctrl-' . uniqid();

        $this->user = new User();
        $this->user->setEmail($this->prefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setType(UserType::AVOCAT);
        $this->em->persist($this->user);

        $this->invoice = (new FiscalInvoice())
            ->setUser($this->user)
            ->setStatus(FiscalInvoiceStatus::ISSUED)
            ->setSeries('FCT')->setNumber('0001')
            ->setIssuedAt(new \DateTimeImmutable())
            ->setSupplier(new PartySnapshot(name: 'LexRecovery SRL', cui: 'RO123'))
            ->setBuyer(new PartySnapshot(name: 'Cabinet ' . $this->prefix, cui: 'RO99'))
            ->setNetTotal('99.00')->setVatTotal('18.81')->setGrossTotal('117.81');
        $this->invoice->addLine((new FiscalInvoiceLine())
            ->setDescription('Abonament')->setQuantity('1.000')->setUnitPriceNet('99.00')
            ->setVatRate('19')->setLineNet('99.00')->setLineVat('18.81')->setLineGross('117.81'));
        $this->em->persist($this->invoice);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE fil FROM fiscal_invoice_line fil JOIN fiscal_invoice fi ON fil.fiscal_invoice_id = fi.id JOIN user u ON fi.user_id = u.id WHERE u.email LIKE ?', [$this->prefix . '%']);
        $conn->executeStatement('DELETE fi FROM fiscal_invoice fi JOIN user u ON fi.user_id = u.id WHERE u.email LIKE ?', [$this->prefix . '%']);
        $conn->executeStatement('DELETE FROM user WHERE email LIKE ?', [$this->prefix . '%']);
        parent::tearDown();
    }

    public function testIndexListsUsersInvoices(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/subscription/fiscal-invoices');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'FCT 0001');
    }

    public function testShowRendersForOwner(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/subscription/fiscal-invoices/' . $this->invoice->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '117');
    }

    public function testShowDeniedForNonOwner(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $intruder = new User();
        $intruder->setEmail($this->prefix . '-intruder@test.com');
        $intruder->setPassword($hasher->hashPassword($intruder, 'password'));
        $intruder->setIsVerified(true);
        $this->em->persist($intruder);
        $this->em->flush();

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/subscription/fiscal-invoices/' . $this->invoice->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testDownloadRedirectsToTrustedProviderUrlWhenNoBytes(): void
    {
        // Stub provider returns no PDF bytes; controller falls back to a trusted URL.
        $this->invoice->setPdfUrl('https://www.oblio.eu/pdf/abc');
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/subscription/fiscal-invoices/' . $this->invoice->getId() . '/pdf');

        self::assertResponseRedirects('https://www.oblio.eu/pdf/abc');
    }

    public function testDownloadRejectsUntrustedUrlWith404(): void
    {
        $this->invoice->setPdfUrl('https://evil.test/steal');
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/subscription/fiscal-invoices/' . $this->invoice->getId() . '/pdf');

        self::assertResponseStatusCodeSame(404);
    }
}
