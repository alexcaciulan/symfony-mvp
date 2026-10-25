<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Court;
use App\Entity\CourtEmailAddress;
use App\Entity\CourtEmailAddressReport;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CourtEmailPurpose;
use App\Enum\CourtEmailReportReason;
use App\Enum\CourtEmailReportResolution;
use App\Enum\CourtEmailSource;
use App\Enum\CourtEmailStatus;
use App\Service\Court\CourtEmailAddressService;
use App\Tests\Support\FilingCaseFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CourtEmailAddressAdminTest extends WebTestCase
{
    use FilingCaseFixtureTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $lawyer;
    private User $admin;
    private LegalCase $case;
    private Court $court;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->lawyer = $this->createFilingUser($this->em, 'court-admin-lawyer');
        $this->case = $this->createFilingCase($this->em, $this->lawyer);
        $this->court = $this->case->getCourt();
        $this->admin = $this->createFilingUser($this->em, 'court-admin-admin');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->removeFilingFixtures($this->em, $this->admin ?? null, null);
            $this->removeFilingFixtures($this->em, $this->lawyer ?? null, $this->case ?? null);
        }
        parent::tearDown();
    }

    public function testIndexAppliesStatusPurposeCountyAndTypeFilters(): void
    {
        $this->createCourtEmailAddress($this->em, $this->court, 'registratura-filtru@just.ro', CourtEmailPurpose::REGISTRY);
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/admin/court-email-address', ['filters' => [
            'status' => ['comparison' => '=', 'value' => 'PROPOSED'],
            'purpose' => ['comparison' => '=', 'value' => 'REGISTRY'],
            'courtCounty' => ['comparison' => '=', 'value' => (string) $this->court->getCounty()->getId()],
            'courtType' => ['comparison' => '=', 'value' => 'judecatorie'],
        ]]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('registratura-filtru@just.ro', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/admin/court-email-address', ['filters' => [
            'courtType' => ['comparison' => '=', 'value' => 'tribunal'],
        ]]);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('registratura-filtru@just.ro', (string) $this->client->getResponse()->getContent());
    }

    /** Purpose, status and source read as words in the list, not as stored values. */
    public function testTheListShowsTranslatedPurposeStatusAndSource(): void
    {
        $this->createCourtEmailAddress($this->em, $this->court, 'registratura-eticheta@just.ro', CourtEmailPurpose::REGISTRY);
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/admin/court-email-address');
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('tbody tr')->reduce(static fn ($tr): bool => str_contains($tr->text(), 'registratura-eticheta@just.ro'));
        self::assertCount(1, $row);
        self::assertStringContainsString('Registratură', $row->text());
        self::assertStringContainsString('Propusă, neconfirmată', $row->text());
        self::assertStringNotContainsString('REGISTRY', $row->text());
        self::assertStringNotContainsString('PROPOSED', $row->text());
    }

    public function testCourtDetailListsItsAddressesReadOnly(): void
    {
        $this->createCourtEmailAddress($this->em, $this->court, 'registratura-detaliu@just.ro', CourtEmailPurpose::REGISTRY);
        // The court was loaded before its address existed; the page must read it fresh.
        $this->em->clear();
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/admin/court/' . $this->court->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="court-email-addresses"]', 'registratura-detaliu@just.ro');
    }

    public function testAdminValidationNeedsTheStatementAndRecordsTheAdmin(): void
    {
        $address = $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY);
        $this->client->loginUser($this->admin);

        $this->post($address, 'validate', []);
        self::assertSame(CourtEmailStatus::PROPOSED, $this->reload($address)->getStatus());

        $this->post($address, 'validate', ['statement_confirmed' => '1']);
        $address = $this->reload($address);
        self::assertSame(CourtEmailStatus::VALIDATED, $address->getStatus());
        self::assertSame($this->admin->getId(), $address->getValidatedBy()?->getId());
        self::assertTrue($address->isFilingDefault());
    }

    public function testRejectDefaultInvalidateAndReopenChangeTheState(): void
    {
        $proposed = $this->createCourtEmailAddress($this->em, $this->court, 'civil@just.ro', CourtEmailPurpose::SECTION_CIVIL);
        $first = $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, new \DateTimeImmutable('-1 day'), $this->lawyer, true);
        $second = $this->createCourtEmailAddress($this->em, $this->court, 'registratura2@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, new \DateTimeImmutable('-1 day'), $this->lawyer);
        $this->client->loginUser($this->admin);

        $this->post($proposed, 'reject', []);
        self::assertSame(CourtEmailStatus::REJECTED, $this->reload($proposed)->getStatus());

        $this->post($second, 'default', []);
        self::assertTrue($this->reload($second)->isFilingDefault());
        self::assertFalse($this->reload($first)->isFilingDefault());

        $this->post($second, 'invalidate', ['reason' => 'BOUNCED']);
        $second = $this->reload($second);
        self::assertSame(CourtEmailStatus::INVALID, $second->getStatus());
        self::assertSame('admin:BOUNCED', $second->getInvalidReason());

        $this->post($second, 'reopen', []);
        self::assertSame(CourtEmailStatus::PROPOSED, $this->reload($second)->getStatus());
    }

    public function testErasePersonalDeletesOnlyANominalAddress(): void
    {
        $personal = $this->createCourtEmailAddress($this->em, $this->court, 'ion.popescu@just.ro', CourtEmailPurpose::PERSONAL);
        $institutional = $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY);
        $personalId = $personal->getId();
        $this->client->loginUser($this->admin);

        $this->post($institutional, 'erase', []);
        self::assertNotNull($this->reload($institutional));

        $this->post($personal, 'erase', []);
        $this->em->clear();
        self::assertNull($this->em->find(CourtEmailAddress::class, $personalId));
    }

    public function testDashboardCountersFollowTheSeededAddresses(): void
    {
        $this->client->loginUser($this->admin);
        $before = $this->dashboardCounters();
        // The request reset the entity manager: work on managed copies from here on.
        $this->court = $this->em->find(Court::class, $this->court->getId());
        $this->lawyer = $this->em->find(User::class, $this->lawyer->getId());

        $this->createCourtEmailAddress($this->em, $this->court, 'propusa@just.ro', CourtEmailPurpose::REGISTRY);
        $this->createCourtEmailAddress($this->em, $this->court, 'expirata@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, new \DateTimeImmutable('-200 days'), $this->lawyer, true);
        $after = $this->dashboardCounters();
        self::assertSame($before['courts'], $after['courts'], 'Neither a proposal nor an expired confirmation gives the court an address.');
        self::assertSame($before['proposed'] + 1, $after['proposed']);
        self::assertSame($before['expired'] + 1, $after['expired']);

        $this->court = $this->em->find(Court::class, $this->court->getId());
        $this->lawyer = $this->em->find(User::class, $this->lawyer->getId());
        $this->createCourtEmailAddress($this->em, $this->court, 'valida@just.ro', CourtEmailPurpose::SECTION_CIVIL, CourtEmailStatus::VALIDATED, new \DateTimeImmutable('-1 day'), $this->lawyer, true);
        self::assertSame($before['courts'] - 1, $this->dashboardCounters()['courts']);
    }

    public function testAdminAddsAProposedAddressFromTheCourtDetail(): void
    {
        $this->em->clear();
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/admin/court/' . $this->court->getId());
        self::assertResponseIsSuccessful();
        $addUrl = (string) $crawler->filter('[data-testid="admin-court-email-add"]')->attr('href');
        $crawler = $this->client->request('GET', $addUrl);
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('form[data-testid="admin-court-email-add-form"] input[name="_token"]')->attr('value');

        $this->client->request('POST', $addUrl, ['_token' => $token, 'email' => 'Registratura-Admin@just.ro', 'purpose' => 'REGISTRY']);

        self::assertResponseRedirects();
        $this->em->clear();
        $address = $this->em->getRepository(CourtEmailAddress::class)->findOneBy(['court' => $this->court->getId(), 'email' => 'registratura-admin@just.ro']);
        self::assertNotNull($address);
        self::assertSame(CourtEmailStatus::PROPOSED, $address->getStatus());
        self::assertSame(CourtEmailSource::MANUAL, $address->getSource());

        // The confirmation page names the source the admin checks the address on.
        $this->client->request('GET', '/admin/court-email/' . $address->getId() . '/validate');
        self::assertSelectorTextContains('[data-testid="admin-court-email-source"]', $this->trans('enum.court_email_source.MANUAL'));
    }

    public function testTheQueueListsReportsAndTheAdminDismissesOrInvalidates(): void
    {
        $address = $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, new \DateTimeImmutable('-1 day'), $this->admin, true);
        $other = $this->createCourtEmailAddress($this->em, $this->court, 'civil@just.ro', CourtEmailPurpose::SECTION_CIVIL, CourtEmailStatus::VALIDATED, new \DateTimeImmutable('-1 day'), $this->admin, true);
        $service = static::getContainer()->get(CourtEmailAddressService::class);
        $dismissed = $service->report($other, CourtEmailReportReason::WRONG_COURT, $this->case, $this->lawyer);
        $invalidated = $service->report($address, CourtEmailReportReason::BOUNCED, $this->case, $this->lawyer);
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/admin/court-email-address-report');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('registratura@just.ro', $html);
        self::assertStringContainsString('civil@just.ro', $html);

        $this->postReport((int) $dismissed->getId(), 'dismiss');
        $this->em->clear();
        self::assertSame(CourtEmailReportResolution::DISMISSED, $this->em->find(CourtEmailAddressReport::class, $dismissed->getId())?->getResolution());
        self::assertSame(CourtEmailStatus::VALIDATED, $this->reload($other)?->getStatus());

        $this->postReport((int) $invalidated->getId(), 'invalidate');
        $this->em->clear();
        self::assertSame(CourtEmailReportResolution::INVALIDATED, $this->em->find(CourtEmailAddressReport::class, $invalidated->getId())?->getResolution());
        $address = $this->reload($address);
        self::assertSame(CourtEmailStatus::INVALID, $address?->getStatus());
        self::assertSame('admin_report:LAWYER:BOUNCED', $address?->getInvalidReason());
    }

    public function testDashboardCountsSuggestionsAndOpenReports(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin');
        $value = static fn ($crawler, string $testId): int => (int) trim($crawler->filter('[data-testid="' . $testId . '"] div')->first()->text());
        $suggestions = $value($crawler, 'dashboard-lawyer-suggestions');
        $reports = $value($crawler, 'dashboard-open-address-reports');
        $this->court = $this->em->find(Court::class, $this->court->getId());
        $case = $this->em->find(LegalCase::class, $this->case->getId());
        $lawyer = $this->em->find(User::class, $this->lawyer->getId());

        $service = static::getContainer()->get(CourtEmailAddressService::class);
        $suggested = $service->suggestByLawyer($this->court, 'sugestie@just.ro', CourtEmailPurpose::REGISTRY, $case, $lawyer);
        $service->report($suggested, CourtEmailReportReason::OTHER, $case, $lawyer);

        $crawler = $this->client->request('GET', '/admin');
        self::assertSame($suggestions + 1, $value($crawler, 'dashboard-lawyer-suggestions'));
        self::assertSame($reports + 1, $value($crawler, 'dashboard-open-address-reports'));
        self::assertSelectorExists('a[href*="CourtEmailAddressReportCrudController"], a[href*="court-email-address-report"]');
    }

    public function testANonAdminIsRefused(): void
    {
        $address = $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY);
        $this->client->loginUser($this->lawyer);

        $this->client->request('GET', '/admin/court-email/' . $address->getId() . '/validate');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/court-email/' . $address->getId() . '/validate', ['statement_confirmed' => '1']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(CourtEmailStatus::PROPOSED, $this->reload($address)->getStatus());
    }

    /** @param array<string, string> $fields */
    private function post(CourtEmailAddress $address, string $action, array $fields): void
    {
        $url = '/admin/court-email/' . $address->getId() . '/' . $action;
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', $url, ['_token' => $token] + $fields);
        self::assertResponseRedirects();
    }

    private function postReport(int $reportId, string $action): void
    {
        $url = '/admin/court-email-report/' . $reportId . '/' . $action;
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', $url, ['_token' => $token]);
        self::assertResponseRedirects();
    }

    private function trans(string $key): string
    {
        return static::getContainer()->get('translator')->trans($key);
    }

    /** @return array{courts: int, proposed: int, expired: int} */
    private function dashboardCounters(): array
    {
        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $value = static fn (string $testId): int => (int) trim($crawler->filter('[data-testid="' . $testId . '"] div')->first()->text());

        return [
            'courts' => $value('dashboard-courts-without-address'),
            'proposed' => $value('dashboard-proposed-addresses'),
            'expired' => $value('dashboard-expired-validations'),
        ];
    }

    private function reload(CourtEmailAddress $address): ?CourtEmailAddress
    {
        $this->em->clear();

        return $this->em->find(CourtEmailAddress::class, $address->getId());
    }
}
