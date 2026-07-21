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

    private function proPlan(): Plan
    {
        return $this->em->getRepository(Plan::class)->findOneBy(['name' => $this->prefix . '-pro']);
    }

    public function testIndexRendersPlansForPaidSubscriber(): void
    {
        $this->createActiveSubscription(casesConsumed: 0);
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/subscription');

        self::assertResponseIsSuccessful();
        // The grid used to disappear entirely on a paid plan, stranding the user.
        self::assertSelectorTextContains('body', $this->proPlan()->getName());
        self::assertGreaterThan(
            0,
            $crawler->filter('form[action="/subscription/change-plan/' . $this->proPlan()->getId() . '"]')->count(),
        );
    }

    public function testIndexMarksCurrentPlanAsCurrent(): void
    {
        $this->createActiveSubscription(casesConsumed: 0);
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/subscription');

        self::assertResponseIsSuccessful();
        // The current plan gets no actionable form, only a disabled button.
        self::assertSame(0, $crawler->filter('form[action="/subscription/change-plan/' . $this->plan->getId() . '"]')->count());
        self::assertSelectorExists('button[disabled]');
    }

    public function testChangePlanRedirectsToCheckoutForUpgrade(): void
    {
        $this->createActiveSubscription(casesConsumed: 3);
        $pro = $this->proPlan();
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/subscription');
        $token = $crawler
            ->filter('form[action="/subscription/change-plan/' . $pro->getId() . '"] input[name="_token"]')
            ->first()->attr('value');

        $this->client->request('POST', '/subscription/change-plan/' . $pro->getId(), ['_token' => $token]);

        self::assertResponseRedirects();

        $this->em->clear();
        $invoices = $this->em->getRepository(Invoice::class)->findBy([
            'user' => $this->user->getId(),
            'type' => InvoiceType::PLAN_CHANGE,
        ]);
        self::assertCount(1, $invoices);
        self::assertSame('299.00', $invoices[0]->getAmount());
        self::assertSame($pro->getId(), $invoices[0]->getTargetPlan()->getId());

        // The plan itself must not move before the invoice is settled.
        $subs = $this->em->getRepository(Subscription::class)->findBy(['user' => $this->user->getId()]);
        self::assertCount(1, $subs);
        self::assertSame($this->plan->getId(), $subs[0]->getPlan()->getId());
    }

    public function testCheckoutUsesTheInvoiceCreatedByThePlanChange(): void
    {
        $sub = $this->createActiveSubscription(casesConsumed: 0);
        $pro = $this->proPlan();

        // An older open invoice on the same subscription. The controller used to
        // re-query the user's pending invoices and take the first, which with
        // one-second createdAt granularity could pick this one instead.
        $stale = $this->createInvoice($this->user);
        $stale->setSubscription($sub);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/subscription');
        $token = $crawler
            ->filter('form[action="/subscription/change-plan/' . $pro->getId() . '"] input[name="_token"]')
            ->first()->attr('value');

        $this->client->request('POST', '/subscription/change-plan/' . $pro->getId(), ['_token' => $token]);

        $this->em->clear();
        $planChange = $this->em->getRepository(Invoice::class)->findOneBy([
            'user' => $this->user->getId(),
            'type' => InvoiceType::PLAN_CHANGE,
        ]);
        // The stub gateway checks out in-app, so the redirect names the invoice.
        self::assertResponseRedirects('http://localhost/subscription/checkout/' . $planChange->getId());
    }

    public function testChangePlanSchedulesDowngradeAndRedirectsBack(): void
    {
        $sub = $this->createActiveSubscription(casesConsumed: 0);
        $pro = $this->proPlan();
        // Start from the dearer plan so the cheaper one is a downgrade.
        $sub->setPlan($pro);
        $this->em->flush();
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/subscription');
        $token = $crawler
            ->filter('form[action="/subscription/change-plan/' . $this->plan->getId() . '"] input[name="_token"]')
            ->first()->attr('value');

        $this->client->request('POST', '/subscription/change-plan/' . $this->plan->getId(), ['_token' => $token]);

        self::assertResponseRedirects('/subscription');

        $this->em->clear();
        $refreshed = $this->em->getRepository(Subscription::class)->find($sub->getId());
        self::assertSame($this->plan->getId(), $refreshed->getPendingPlan()->getId());
        self::assertSame($pro->getId(), $refreshed->getPlan()->getId());
        self::assertCount(0, $this->em->getRepository(Invoice::class)->findBy([
            'user' => $this->user->getId(),
            'type' => InvoiceType::PLAN_CHANGE,
        ]));
    }

    public function testChangePlanRequiresValidCsrfToken(): void
    {
        $this->createActiveSubscription(casesConsumed: 0);
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/subscription/change-plan/' . $this->proPlan()->getId(), ['_token' => 'bogus']);

        self::assertResponseRedirects('/subscription');
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Invoice::class)->findBy(['user' => $this->user->getId()]));
    }

    public function testChangePlanRequiresCompleteFiscalData(): void
    {
        $this->createActiveSubscription(casesConsumed: 0);
        $pro = $this->proPlan();
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/subscription');
        $token = $crawler
            ->filter('form[action="/subscription/change-plan/' . $pro->getId() . '"] input[name="_token"]')
            ->first()->attr('value');

        $this->user->setCui(null);
        $this->em->flush();

        $this->client->request('POST', '/subscription/change-plan/' . $pro->getId(), ['_token' => $token]);

        self::assertResponseRedirects('/profile/edit');
    }

    public function testUpgradeNudgeRendersActionableCta(): void
    {
        $this->createActiveSubscription(casesConsumed: 5); // exhausted
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/subscription');

        self::assertResponseIsSuccessful();
        // The nudge used to be text only, pointing at a plan the user could not pick.
        // Which plan it recommends depends on the seeded catalogue, so assert the
        // CTA exists and targets the change-plan route rather than a specific id.
        $cta = $crawler->filter('.bg-amber-50 form');
        self::assertGreaterThan(0, $cta->count());
        self::assertStringStartsWith('/subscription/change-plan/', $cta->first()->attr('action'));
    }

    public function testPendingChangeBannerPostsToTheResumeRoute(): void
    {
        $sub = $this->createActiveSubscription(casesConsumed: 0);
        $invoice = $this->createInvoice($this->user);
        $invoice->setSubscription($sub)->setType(InvoiceType::PLAN_CHANGE)->setTargetPlan($this->proPlan());
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/subscription');

        // A link to the checkout page is a dead end on a real gateway: that page
        // only informs, the redirect to the processor happens on POST.
        self::assertSame(0, $crawler->filter('a[href="/subscription/checkout/' . $invoice->getId() . '"]')->count());
        self::assertGreaterThan(0, $crawler->filter('form[action="/subscription/pay/' . $invoice->getId() . '"]')->count());
    }

    public function testPayResumesCheckoutForAPendingInvoice(): void
    {
        $sub = $this->createActiveSubscription(casesConsumed: 0);
        $invoice = $this->createInvoice($this->user);
        $invoice->setSubscription($sub)->setType(InvoiceType::PLAN_CHANGE)->setTargetPlan($this->proPlan());
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/subscription');
        $token = $crawler
            ->filter('form[action="/subscription/pay/' . $invoice->getId() . '"] input[name="_token"]')
            ->first()->attr('value');

        $this->client->request('POST', '/subscription/pay/' . $invoice->getId(), ['_token' => $token]);

        // Stub gateway checks out in-app, so the redirect names the same invoice.
        self::assertResponseRedirects('http://localhost/subscription/checkout/' . $invoice->getId());
    }

    public function testCheckoutRefusesAnInvoiceOwnedBySomeoneElse(): void
    {
        $other = $this->createOtherUser();
        $victimInvoice = $this->createInvoice($other);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/subscription/checkout/' . $victimInvoice->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function createOtherUser(): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $other = new User();
        $other->setEmail($this->prefix . '-other@test.com');
        $other->setPassword($hasher->hashPassword($other, 'password'));
        $other->setIsVerified(true);
        $this->em->persist($other);
        $this->em->flush();

        return $other;
    }

    public function testPayWithAnotherSessionsTokenCannotStartCheckout(): void
    {
        $other = $this->createOtherUser();
        $victimInvoice = $this->createInvoice($other);

        // Token minted in the victim's session, then replayed by the intruder.
        // It is refused before ownership is even considered, because CSRF tokens
        // are session-bound; the ownership guard itself is covered by the
        // checkout test above.
        $this->client->loginUser($other);
        $crawler = $this->client->request('GET', '/subscription/checkout/' . $victimInvoice->getId());
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/subscription/pay/' . $victimInvoice->getId(), ['_token' => $token]);

        self::assertResponseRedirects('/subscription');
        self::assertStringNotContainsString(
            '/subscription/checkout/' . $victimInvoice->getId(),
            (string) $this->client->getResponse()->headers->get('Location'),
        );
    }

    public function testPayOnAnAlreadyPaidInvoiceDoesNotStartCheckout(): void
    {
        $invoice = $this->createInvoice($this->user);
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/subscription/checkout/' . $invoice->getId());
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        // Settled between rendering the button and clicking it, e.g. the IPN landed
        // while the page was open.
        $invoice->markPaid();
        $this->em->flush();

        $this->client->request('POST', '/subscription/pay/' . $invoice->getId(), ['_token' => $token]);

        self::assertResponseRedirects('/subscription');
    }

    public function testCancelPlanChangeDropsTheScheduledPlan(): void
    {
        $sub = $this->createActiveSubscription(casesConsumed: 0);
        $sub->setPendingPlan($this->proPlan());
        $this->em->flush();
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/subscription');
        $token = $crawler
            ->filter('form[action="/subscription/cancel-plan-change"] input[name="_token"]')
            ->first()->attr('value');

        $this->client->request('POST', '/subscription/cancel-plan-change', ['_token' => $token]);

        self::assertResponseRedirects('/subscription');
        $this->em->clear();
        self::assertNull($this->em->getRepository(Subscription::class)->find($sub->getId())->getPendingPlan());
    }

    public function testCancelSubscriptionRouteCancelsAndRedirects(): void
    {
        $sub = $this->createActiveSubscription(casesConsumed: 0);
        $sub->setRecurringToken('tok-drop-me');
        $this->em->flush();
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/subscription');
        $token = $crawler
            ->filter('form[action="/subscription/cancel"] input[name="_token"]')
            ->first()->attr('value');

        $this->client->request('POST', '/subscription/cancel', ['_token' => $token]);

        self::assertResponseRedirects('/subscription');

        $this->em->clear();
        $refreshed = $this->em->getRepository(Subscription::class)->find($sub->getId());
        self::assertSame(SubscriptionStatus::CANCELED, $refreshed->getStatus());
        self::assertNull($refreshed->getRecurringToken());
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
        // Settling a payment now persists a PAYMENT_SUCCEEDED notification for the
        // user; clear it before the user FK is removed.
        $conn->executeStatement('DELETE n FROM notification n JOIN `user` u ON n.user_id = u.id WHERE u.email LIKE ?', [$this->prefix . '%']);
        $conn->executeStatement('DELETE FROM `user` WHERE email LIKE ?', [$this->prefix . '%']);
        $conn->executeStatement('DELETE FROM plan WHERE name LIKE ?', [$this->prefix . '%']);
        parent::tearDown();
    }
}
