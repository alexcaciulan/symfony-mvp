<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Service\Company\AnafLookupException;
use App\Service\Company\AnafLookupService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pas 3.3 — LookupController exposes `/api/anaf-lookup/{cui}` for the
 * `debtor-anaf-lookup` Stimulus controller. We override `AnafLookupService`
 * with a PHPUnit mock so the test never hits the real ANAF API (the legacy
 * `CompanyLookupTest` was deleted because it accepted "200 or 400" depending
 * on Docker → ANAF reachability — too flaky).
 *
 * The `company_lookup` rate limiter is forced to `no_limit` in test env (see
 * `config/packages/rate_limiter.yaml`), so the happy-path tests run without
 * tripping the 10/h ceiling. A dedicated rate-limit test is left as a backlog
 * follow-up (see Pas 3.2 memory).
 */
final class LookupControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private string $testPrefix;
    /** @var AnafLookupService&MockObject */
    private $anafLookupServiceMock;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->testPrefix = 'lookup-ctrl-' . uniqid();

        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();

        // Override the real ANAF lookup service with a mock so we don't hit
        // ANAF's HTTP endpoint from CI.
        $this->anafLookupServiceMock = $this->createMock(AnafLookupService::class);
        static::getContainer()->set(AnafLookupService::class, $this->anafLookupServiceMock);

        $this->client->loginUser($this->user);
    }

    public function testHappyPathReturnsCompanyData(): void
    {
        $this->anafLookupServiceMock
            ->expects(self::once())
            ->method('lookupByCui')
            ->with('14186770')
            ->willReturn([
                'companyName' => 'ACME DEBTOR SRL',
                'cui' => '14186770',
                'nrRegCom' => 'J40/1234/2018',
                'street' => 'Str. Test',
                'streetNumber' => '1',
                'city' => 'București',
                'county' => 'BUCUREȘTI',
                'postalCode' => '010101',
                'addressDetails' => null,
                'phone' => null,
                'fax' => null,
                'codCAEN' => '6201',
                'stare' => 'ACTIV',
                'platitorTVA' => true,
            ]);

        $this->client->request('GET', '/api/anaf-lookup/14186770');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('ACME DEBTOR SRL', $data['companyName']);
        self::assertSame('14186770', $data['cui']);
        self::assertSame('ACTIV', $data['anafStatus']);
        self::assertArrayHasKey('anafCheckedAt', $data);
        // address must be the composed string, not a structured object.
        self::assertStringContainsString('Str. Test', $data['address']);
        self::assertStringContainsString('București', $data['address']);
    }

    public function testHappyPathAcceptsRoPrefix(): void
    {
        $this->anafLookupServiceMock
            ->expects(self::once())
            ->method('lookupByCui')
            ->with('14186770')  // RO prefix stripped before forwarding
            ->willReturn([
                'companyName' => 'ACME SRL',
                'cui' => '14186770',
                'stare' => 'ACTIV',
                'street' => 'A',
                'streetNumber' => null,
                'city' => null,
                'county' => null,
                'postalCode' => null,
                'addressDetails' => null,
                'nrRegCom' => null,
                'phone' => null,
                'fax' => null,
                'codCAEN' => null,
                'platitorTVA' => false,
            ]);

        $this->client->request('GET', '/api/anaf-lookup/RO14186770');

        self::assertResponseIsSuccessful();
    }

    public function testRejectsTooShortCuiViaRouteRequirement(): void
    {
        // route requires `(RO)?\d{2,10}` — single digit fails the requirement
        // BEFORE the controller is even invoked → Symfony 404.
        $this->client->request('GET', '/api/anaf-lookup/1');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRejectsNonNumericCuiViaRouteRequirement(): void
    {
        $this->client->request('GET', '/api/anaf-lookup/abc');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRejectsChecksumInvalidCui(): void
    {
        // Format passes route regex but fails PiiMasker::isValidCui() — controller
        // returns its own 404 with the i18n error key.
        $this->anafLookupServiceMock->expects(self::never())->method('lookupByCui');

        $this->client->request('GET', '/api/anaf-lookup/12345678');

        self::assertResponseStatusCodeSame(404);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('exception.anaf.cui_invalid', $data['error']);
    }

    public function testReturns503OnAnafUnavailable(): void
    {
        $this->anafLookupServiceMock
            ->method('lookupByCui')
            ->willThrowException(new AnafLookupException('exception.anaf.unavailable'));

        $this->client->request('GET', '/api/anaf-lookup/14186770');

        self::assertResponseStatusCodeSame(503);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('exception.anaf.unavailable', $data['error']);
    }

    public function testRequiresAuthentication(): void
    {
        // Drop the test cookie so the kernel handles the request as anonymous.
        // We can't recreate the client here — Symfony only allows one
        // createClient() per test boot, so we strip the auth cookie instead.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/anaf-lookup/14186770');

        // access_control redirects unauthenticated users to login.
        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('/login', $this->client->getResponse()->headers->get('Location') ?? '');
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement(
            'DELETE FROM user WHERE email LIKE ?',
            [$this->testPrefix . '%'],
        );
        parent::tearDown();
    }
}
