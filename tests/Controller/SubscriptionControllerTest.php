<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Invoice;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\InvoiceType;
use App\Enum\SubscriptionStatus;
use App\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SubscriptionControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private Plan $plan;
    private string $prefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->prefix = 'sub-ctrl-' . uniqid();

        $this->user = new User();
        $this->user->setEmail($this->prefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        // Complete fiscal data so the checkout gate passes (lawyer cabinet, CIF).
        $this->user->setType(UserType::AVOCAT);
        $this->user->setCompanyName('Cabinet ' . $this->prefix);
        $this->user->setCui('RO12345678');
        $this->user->setStreet('Str. Test 1');
        $this->user->setCity('București');
        $this->em->persist($this->user);

        $this->plan = new Plan();
        $this->plan->setName($this->prefix . '-plan');
        $this->plan->setPriceMonthly('99.00');
        $this->plan->setIncludedCases(5);
        $this->plan->setPricePerExtra('25.00');
        $this->plan->setIsActive(true);
        $this->plan->setIsTrial(false);
        $this->em->persist($this->plan);

        $this->em->flush();
    }

    private function createActiveSubscription(int $casesConsumed): Subscription
    {
        $sub = new Subscription();
        $sub->setUser($this->user);
        $sub->setPlan($this->plan); // includedCases = 5
        $sub->setStatus(SubscriptionStatus::ACTIVE);
        $sub->setCurrentPeriodStart(new \DateTimeImmutable('-1 day'));
        $sub->setCurrentPeriodEnd(new \DateTimeImmutable('+30 days'));
        $sub->setCasesConsumed($casesConsumed);
        $this->em->persist($sub);

        $bigger = new Plan();
        $bigger->setName($this->prefix . '-pro');
        $bigger->setPriceMonthly('299.00');
        $bigger->setIncludedCases(25);
        $bigger->setPricePerExtra('15.00');
        $bigger->setIsActive(true);
        $bigger->setIsTrial(false);
        $this->em->persist($bigger);

        $this->em->flush();

        return $sub;
    }

    public function testUpgradeNudgeHiddenWhenPlanHasFreeSlots(): void
    {
        $this->createActiveSubscription(casesConsumed: 0); // 0 / 5
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/subscription');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.bg-amber-50', 'No upgrade nudge while the plan still has free slots (0/5).');
    }

    public function testUpgradeNudgeShownWhenPlanExhausted(): void
    {
        $this->createActiveSubscription(casesConsumed: 5); // 5 / 5
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/subscription');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.bg-amber-50', 'Upgrade nudge shown once the plan is exhausted (5/5).');
    }

    private function createInvoice(User $user, InvoiceStatus $status = InvoiceStatus::PENDING): Invoice
    {
        $invoice = (new Invoice())
            ->setUser($user)
            ->setType(InvoiceType::SUBSCRIPTION)
            ->setStatus($status)
            ->setAmount('99.00');
        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }

    public function testIndexRendersPlans(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/subscription');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $this->plan->getName());
    }

    public function testInvoicesRedirectsToInvoicesPage(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/subscription/invoices');

        self::assertResponseRedirects('/invoices');
    }

    public function testSubscribeCreatesSubscriptionAndInvoiceThenRedirects(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/subscription');
        $token = $crawler
            ->filter('form[action="/subscription/subscribe/' . $this->plan->getId() . '"] input[name="_token"]')
            ->attr('value');

        $this->client->request('POST', '/subscription/subscribe/' . $this->plan->getId(), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $subs = $this->em->getRepository(Subscription::class)->findBy(['user' => $this->user->getId()]);
        self::assertCount(1, $subs);
        self::assertSame(SubscriptionStatus::ACTIVE, $subs[0]->getStatus());

        $invoices = $this->em->getRepository(Invoice::class)->findBy(['user' => $this->user->getId()]);
        self::assertCount(1, $invoices);
        self::assertSame(InvoiceType::SUBSCRIPTION, $invoices[0]->getType());
    }

    public function testCheckoutGetRendersForOwner(): void
    {
        $invoice = $this->createInvoice($this->user);
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/subscription/checkout/' . $invoice->getId());

        self::assertResponseIsSuccessful();
    }

    public function testPayMarksInvoicePaid(): void
    {
        $invoice = $this->createInvoice($this->user);
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/subscription/checkout/' . $invoice->getId());
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/subscription/checkout/' . $invoice->getId(), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/invoices');

        $this->em->clear();
        $refreshed = $this->em->getRepository(Invoice::class)->find($invoice->getId());
        self::assertSame(InvoiceStatus::PAID, $refreshed->getStatus());
        self::assertNotNull($refreshed->getPaidAt());
    }

    public function testPayBlockedWhenFiscalDataIncomplete(): void
    {
        // Strip the CIF so the fiscal-data gate trips before payment.
        $this->user->setCui(null);
        $this->em->flush();

        $invoice = $this->createInvoice($this->user);
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/subscription/checkout/' . $invoice->getId());
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/subscription/checkout/' . $invoice->getId(), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/profile/edit');

        $this->em->clear();
        $refreshed = $this->em->getRepository(Invoice::class)->find($invoice->getId());
        self::assertSame(InvoiceStatus::PENDING, $refreshed->getStatus(), 'invoice must NOT be paid when fiscal data is missing');
    }

    public function testCheckoutDeniedForNonOwner(): void
    {
        $invoice = $this->createInvoice($this->user);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $intruder = new User();
        $intruder->setEmail($this->prefix . '-intruder@test.com');
        $intruder->setPassword($hasher->hashPassword($intruder, 'password'));
        $intruder->setIsVerified(true);
        $this->em->persist($intruder);
        $this->em->flush();

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/subscription/checkout/' . $invoice->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testPayDeniedForNonOwner(): void
    {
        $invoice = $this->createInvoice($this->user);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $intruder = new User();
        $intruder->setEmail($this->prefix . '-intruder2@test.com');
        $intruder->setPassword($hasher->hashPassword($intruder, 'password'));
        $intruder->setIsVerified(true);
        $this->em->persist($intruder);
        $this->em->flush();

        $this->client->loginUser($intruder);
        $this->client->request('POST', '/subscription/checkout/' . $invoice->getId(), [
            '_token' => 'any',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->em->clear();
        $refreshed = $this->em->getRepository(Invoice::class)->find($invoice->getId());
        self::assertSame(InvoiceStatus::PENDING, $refreshed->getStatus(), 'Intruder must not be able to pay another user invoice.');
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE i FROM invoice i JOIN `user` u ON i.user_id = u.id WHERE u.email LIKE ?', [$this->prefix . '%']);
        $conn->executeStatement('DELETE s FROM subscription s JOIN `user` u ON s.user_id = u.id WHERE u.email LIKE ?', [$this->prefix . '%']);
        $conn->executeStatement('DELETE a FROM audit_log a JOIN `user` u ON a.user_id = u.id WHERE u.email LIKE ?', [$this->prefix . '%']);
        $conn->executeStatement('DELETE FROM `user` WHERE email LIKE ?', [$this->prefix . '%']);
        $conn->executeStatement('DELETE FROM plan WHERE name LIKE ?', [$this->prefix . '%']);
        parent::tearDown();
    }
}
