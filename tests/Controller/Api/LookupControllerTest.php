<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CourtType;
use App\Enum\PersonType;
use App\Service\Company\AnafLookupException;
use App\Service\Company\AnafLookupService;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
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
#[AllowMockObjectsWithoutExpectations]
final class LookupControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    use CountyFixtureTrait;

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
        // structured county + locality feed the competent-court resolver.
        self::assertSame('BUCUREȘTI', $data['county']);
        self::assertSame('București', $data['locality']);
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

    public function testCourtsLookupReturnsOnlyCourtsFromOwnCases(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $other = new User();
        $other->setEmail($this->testPrefix . '-other@test.com');
        $other->setPassword($hasher->hashPassword($other, 'password'));
        $other->setIsVerified(true);
        $this->em->persist($other);

        $mine = $this->makeCourt('Judecătoria ' . $this->testPrefix . '-mine');
        $theirs = $this->makeCourt('Judecătoria ' . $this->testPrefix . '-theirs');
        $this->em->flush();

        $this->makeCaseWithCourt($this->user, $mine);
        $this->makeCaseWithCourt($other, $theirs);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/api/courts-lookup');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $labels = array_column($payload, 'label');
        self::assertContains($mine->getName(), $labels);
        self::assertNotContains($theirs->getName(), $labels);
    }

    public function testCourtsLookupFiltersByQuery(): void
    {
        $apel = $this->makeCourt('Curtea de Apel ' . $this->testPrefix);
        $jud = $this->makeCourt('Judecătoria ' . $this->testPrefix);
        $this->em->flush();

        $this->makeCaseWithCourt($this->user, $apel);
        $this->makeCaseWithCourt($this->user, $jud);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/api/courts-lookup', ['q' => 'apel']);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $labels = array_column($payload, 'label');
        self::assertContains($apel->getName(), $labels);
        self::assertNotContains($jud->getName(), $labels);
    }

    public function testCourtsAllLookupReturnsAnyActiveCourtNotScopedToUser(): void
    {
        // Unlike /courts-lookup, the wizard endpoint must return courts the user
        // has NO cases in (a brand-new case may need any court).
        $match = $this->makeCourt('Judecătoria ' . $this->testPrefix . '-allmatch');
        $other = $this->makeCourt('Tribunalul ' . $this->testPrefix . '-allother');
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/api/courts-all-lookup', ['q' => 'allmatch']);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $labels = array_column($payload, 'label');
        self::assertContains($match->getName() . ' · ' . $match->getCounty()->getName(), $labels);
        self::assertNotContains($other->getName() . ' · ' . $other->getCounty()->getName(), $labels);
    }

    public function testCourtsLookupRequiresAuthentication(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/courts-lookup');

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('/login', $this->client->getResponse()->headers->get('Location') ?? '');
    }

    public function testCreditorsLookupReturnsOnlyCreditorsFromOwnCases(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $other = new User();
        $other->setEmail($this->testPrefix . '-other@test.com');
        $other->setPassword($hasher->hashPassword($other, 'password'));
        $other->setIsVerified(true);
        $this->em->persist($other);

        $mine = $this->makeCreditor($this->user, 'Creditor ' . $this->testPrefix . '-mine');
        $unused = $this->makeCreditor($this->user, 'Creditor ' . $this->testPrefix . '-unused');
        $theirs = $this->makeCreditor($other, 'Creditor ' . $this->testPrefix . '-theirs');
        $this->em->flush();

        $this->makeCaseWithCreditor($this->user, $mine);
        $this->makeCaseWithCreditor($other, $theirs);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/api/creditors-lookup');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $labels = array_column($payload, 'label');
        self::assertContains($mine->getName(), $labels);
        // A creditor without any case must not appear (zero-hit option).
        self::assertNotContains($unused->getName(), $labels);
        // A creditor from another user's case must never leak.
        self::assertNotContains($theirs->getName(), $labels);
    }

    public function testCreditorsLookupFiltersByQuery(): void
    {
        $alpha = $this->makeCreditor($this->user, 'Alpha ' . $this->testPrefix);
        $beta = $this->makeCreditor($this->user, 'Beta ' . $this->testPrefix);
        $this->em->flush();

        $this->makeCaseWithCreditor($this->user, $alpha);
        $this->makeCaseWithCreditor($this->user, $beta);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/api/creditors-lookup', ['q' => 'alpha']);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $labels = array_column($payload, 'label');
        self::assertContains($alpha->getName(), $labels);
        self::assertNotContains($beta->getName(), $labels);
    }

    private function makeCourt(string $name): Court
    {
        $court = new Court();
        $court->setName($name);
        $court->setCounty($this->createCounty($this->em, 'Ilfov'));
        $court->setType(CourtType::JUDECATORIE);
        $court->setActive(true);
        $this->em->persist($court);

        return $court;
    }

    private function makeCaseWithCourt(User $user, Court $court): void
    {
        $case = new LegalCase();
        $case->setUser($user);
        $case->setStatus(CaseStatus::AMIABIL);
        $case->setCaseNumber('LR-' . uniqid());
        $case->setAmount('1000.00');
        $case->setCurrency('RON');
        $case->setCourt($court);
        $this->em->persist($case);
    }

    private function makeCreditor(User $user, string $name): Creditor
    {
        $creditor = new Creditor();
        $creditor->setUser($user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName($name);
        $creditor->setAddress('Str. Test 1, București');
        $this->em->persist($creditor);

        return $creditor;
    }

    private function makeCaseWithCreditor(User $user, Creditor $creditor): void
    {
        $case = new LegalCase();
        $case->setUser($user);
        $case->setStatus(CaseStatus::AMIABIL);
        $case->setCaseNumber('LR-' . uniqid());
        $case->setAmount('1000.00');
        $case->setCurrency('RON');
        $case->setCreditor($creditor);
        $this->em->persist($case);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?',
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            'DELETE cr FROM creditor cr JOIN user u ON cr.user_id = u.id WHERE u.email LIKE ?',
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement('DELETE FROM court WHERE name LIKE ?', ['%' . $this->testPrefix . '%']);
        $conn->executeStatement('DELETE FROM user WHERE email LIKE ?', [$this->testPrefix . '%']);
        parent::tearDown();
    }
}
