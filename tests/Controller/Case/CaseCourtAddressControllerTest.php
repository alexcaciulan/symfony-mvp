<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\Court;
use App\Entity\CourtEmailAddress;
use App\Entity\CourtEmailAddressReport;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CourtEmailPurpose;
use App\Enum\CourtEmailReportKind;
use App\Enum\CourtEmailReportReason;
use App\Enum\CourtEmailSource;
use App\Enum\CourtEmailStatus;
use App\Tests\Support\FilingCaseFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CaseCourtAddressControllerTest extends WebTestCase
{
    use FilingCaseFixtureTrait;

    private const TURBO = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;
    private Court $court;
    /** @var list<User> */
    private array $otherUsers = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('app.uploads_dir');
        $this->user = $this->createFilingUser($this->em, 'court-addr-ctrl');
        $this->case = $this->createFilingCase($this->em, $this->user);
        $this->court = $this->case->getCourt();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            foreach ($this->otherUsers as $other) {
                $this->removeFilingFixtures($this->em, $other, null);
            }
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
        }
        parent::tearDown();
    }

    public function testOwnerSeesTheCourtAddressesWithoutNominalOnesAndCannotConfirm(): void
    {
        $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY);
        $this->createCourtEmailAddress($this->em, $this->court, 'ion.popescu@just.ro', CourtEmailPurpose::PERSONAL);
        $this->client->loginUser($this->user);

        $this->client->request('GET', $this->pageUrl());

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('registratura@just.ro', $html);
        self::assertStringNotContainsString('ion.popescu', $html);
        self::assertSelectorNotExists('form[data-testid="court-address-validate-form"]');
        self::assertSelectorExists('[data-testid="court-address-awaiting-admin"]');
        self::assertSelectorExists('form[data-testid="court-address-suggest-form"]');
        // The header link focuses the form even when it is already on screen.
        self::assertSelectorExists('a[data-testid="court-address-suggest-link"][data-action="focus-field#open"][data-focus-field-field-value="court-address-suggest-email"]');
        self::assertSelectorExists('form[data-testid="court-address-suggest-form"] input#court-address-suggest-email[name="email"]');
        self::assertSelectorExists('form[data-testid="court-address-report-form"]');
    }

    public function testThePageLeadsWithTheCourtAndTheAddressUsedForFiling(): void
    {
        $used = $this->createCourtEmailAddress($this->em, $this->court, 'registratura-folosita@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, new \DateTimeImmutable('-3 days'), $this->user, true);
        $this->createCourtEmailAddress($this->em, $this->court, 'registratura-veche@just.ro', CourtEmailPurpose::REGISTRY);
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', $this->pageUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1[data-testid="court-address-court"]', $this->court->getName());
        self::assertSelectorTextContains('[data-testid="court-address-recipient"]', 'Adresa folosită la depunere');
        self::assertSelectorTextContains('[data-testid="court-address-recipient"]', 'registratura-folosita@just.ro');
        self::assertSelectorExists('[data-testid="court-address-recipient"] form[data-testid="court-address-report-form"]');
        // The used address is reported from its own card, not again in the list.
        self::assertSelectorNotExists(sprintf('[data-testid="court-address-%d"] form', $used->getId()));
        self::assertSelectorTextContains(sprintf('[data-testid="court-address-%d"] [data-testid="court-address-status"]', $used->getId()), 'folosită la depunere');
        self::assertSelectorExists('details[data-testid="court-address-rules"]:not([open])');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertLessThan(strpos($html, 'data-testid="court-address-rules"'), strpos($html, 'data-testid="court-address-recipient"'));
        self::assertSelectorExists(sprintf('a[href="/case/%d?tab=depunere"]', $this->case->getId()));
        self::assertCount(1, $crawler->filter('form[data-testid="court-address-suggest-form"]'));
    }

    public function testTheLawyerValidationAndManualRoutesAreGone(): void
    {
        $address = $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY);
        $this->client->loginUser($this->user);

        $this->client->request('POST', $this->pageUrl() . '/' . $address->getId() . '/validate', ['statement_confirmed' => '1'], [], self::TURBO);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $this->pageUrl() . '/manual', ['email' => 'x@just.ro', 'purpose' => 'REGISTRY'], [], self::TURBO);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(CourtEmailStatus::PROPOSED, $this->reload($address)->getStatus());
    }

    public function testAnotherLawyerIsRefusedOnTheReadAndOnEveryAction(): void
    {
        $address = $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY);
        $other = $this->createFilingUser($this->em, 'court-addr-other');
        $this->em->flush();
        $this->otherUsers[] = $other;
        $this->client->loginUser($other);

        $this->client->request('GET', $this->pageUrl());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->pageUrl() . '/' . $address->getId() . '/report', ['reason' => 'BOUNCED'], [], self::TURBO);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->pageUrl() . '/suggest', ['email' => 'x@just.ro', 'purpose' => 'REGISTRY'], [], self::TURBO);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminReadsThePageWithoutForms(): void
    {
        $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY);
        $admin = $this->createFilingUser($this->em, 'court-addr-admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->otherUsers[] = $admin;
        $this->client->loginUser($admin);

        $this->client->request('GET', $this->pageUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="court-address-read-only"]');
        self::assertSelectorNotExists('#court-address-list form');
    }

    public function testAForgedTokenIsRefused(): void
    {
        $address = $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, new \DateTimeImmutable('-5 days'), $this->user, true);
        $this->client->loginUser($this->user);

        $this->client->request('POST', $this->reportUrl($address), ['_token' => 'forged', 'reason' => 'BOUNCED'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->trans('court_address.validation.error.csrf'), (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->reportCount());
    }

    public function testAnAddressOfAnotherCourtOrANominalOneIsNotFound(): void
    {
        $otherUser = $this->createFilingUser($this->em, 'court-addr-foreign');
        $this->otherUsers[] = $otherUser;
        $otherCase = $this->createFilingCase($this->em, $otherUser);
        $foreign = $this->createCourtEmailAddress($this->em, $otherCase->getCourt(), 'registratura@just.ro', CourtEmailPurpose::REGISTRY);
        $personal = $this->createCourtEmailAddress($this->em, $this->court, 'ion.popescu@just.ro', CourtEmailPurpose::PERSONAL);
        $this->client->loginUser($this->user);

        $this->client->request('POST', $this->reportUrl($foreign), ['reason' => 'BOUNCED'], [], self::TURBO);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $this->reportUrl($personal), ['reason' => 'BOUNCED'], [], self::TURBO);
        self::assertResponseStatusCodeSame(404);
    }

    public function testSuggestingANominalAddressIsRefusedWithAToast(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->token('form[data-testid="court-address-suggest-form"]');

        $this->client->request('POST', $this->pageUrl() . '/suggest', ['_token' => $token, 'email' => 'ion.popescu@just.ro', 'purpose' => 'REGISTRY'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->trans('court_address.validation.error.personal_refused'), (string) $this->client->getResponse()->getContent());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM court_email_address WHERE court_id = ?', [$this->court->getId()]));
    }

    public function testASuggestionWaitsProposedForTheAdministrator(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->token('form[data-testid="court-address-suggest-form"]');

        $this->client->request('POST', $this->pageUrl() . '/suggest', ['_token' => $token, 'email' => ' Registratura@Just.ro ', 'purpose' => 'SECTION_CIVIL'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="court-address-list"', $content);
        self::assertStringContainsString($this->trans('court_address.validation.toast.suggested'), $content);
        self::assertStringContainsString('data-testid="court-address-awaiting-admin"', $content);
        self::assertStringNotContainsString('court-address-validate-form', $content);

        $this->em->clear();
        $address = $this->em->getRepository(CourtEmailAddress::class)->findOneBy(['court' => $this->court->getId(), 'email' => 'registratura@just.ro']);
        self::assertNotNull($address);
        self::assertSame(CourtEmailStatus::PROPOSED, $address->getStatus());
        self::assertSame(CourtEmailSource::LAWYER_SUGGESTION, $address->getSource());
        self::assertSame(CourtEmailPurpose::SECTION_CIVIL, $address->getPurpose());
        self::assertNull($address->getValidatedBy());
        self::assertFalse($address->isFilingDefault());
    }

    public function testAReportLeavesTheAddressInUseAndGoesToTheAdministrator(): void
    {
        $address = $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, new \DateTimeImmutable('-5 days'), $this->user, true);
        $this->client->loginUser($this->user);
        $token = $this->token('form[data-testid="court-address-report-form"]');

        $this->client->request('POST', $this->reportUrl($address), ['_token' => $token, 'reason' => 'BOUNCED'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($this->trans('court_address.validation.toast.reported'), $content);
        self::assertStringContainsString('data-testid="court-address-reported"', $content);
        $address = $this->reload($address);
        self::assertSame(CourtEmailStatus::VALIDATED, $address->getStatus(), 'Only the administrator takes an address out of use.');
        self::assertTrue($address->isFilingDefault());
        $report = $this->em->getRepository(CourtEmailAddressReport::class)->findOneBy(['address' => $address->getId()]);
        self::assertNotNull($report);
        self::assertSame(CourtEmailReportKind::LAWYER, $report->getKind());
        self::assertSame(CourtEmailReportReason::BOUNCED, $report->getReason());
        self::assertSame($this->case->getId(), $report->getLegalCase()?->getId());
        self::assertTrue($report->isOpen());
    }

    private function pageUrl(): string
    {
        return '/case/' . $this->case->getId() . '/court-address';
    }

    private function reportUrl(CourtEmailAddress $address): string
    {
        return $this->pageUrl() . '/' . $address->getId() . '/report';
    }

    private function reportCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM court_email_address_report WHERE legal_case_id = ?', [$this->case->getId()]);
    }

    private function token(string $formSelector): string
    {
        $crawler = $this->client->request('GET', $this->pageUrl());
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter($formSelector . ' input[name="_token"]')->first()->attr('value');
    }

    private function reload(CourtEmailAddress $address): CourtEmailAddress
    {
        $this->em->clear();

        return $this->em->find(CourtEmailAddress::class, $address->getId());
    }

    private function trans(string $key): string
    {
        return htmlspecialchars(static::getContainer()->get('translator')->trans($key), ENT_QUOTES);
    }
}
