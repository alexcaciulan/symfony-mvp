<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\Court;
use App\Entity\CourtEmailAddress;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CourtEmailPurpose;
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
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
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

    public function testOwnerSeesTheCourtAddressesWithoutNominalOnes(): void
    {
        $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY);
        $this->createCourtEmailAddress($this->em, $this->court, 'ion.popescu@just.ro', CourtEmailPurpose::PERSONAL);
        $this->client->loginUser($this->user);

        $this->client->request('GET', $this->pageUrl());

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('registratura@just.ro', $html);
        self::assertStringNotContainsString('ion.popescu', $html);
        self::assertSelectorExists('form[data-testid="court-address-validate-form"]');
        self::assertSelectorExists('form[data-testid="court-address-manual-form"]');
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
        $this->client->request('POST', $this->pageUrl() . '/' . $address->getId() . '/validate', ['statement_confirmed' => '1'], [], self::TURBO);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->pageUrl() . '/manual', ['email' => 'x@just.ro', 'purpose' => 'REGISTRY'], [], self::TURBO);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminReadsThePageWithoutFormsAndCannotConfirm(): void
    {
        $address = $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY);
        $admin = $this->createFilingUser($this->em, 'court-addr-admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->otherUsers[] = $admin;
        $this->client->loginUser($admin);

        $this->client->request('GET', $this->pageUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="court-address-read-only"]');
        self::assertSelectorNotExists('#court-address-list form');

        $this->client->request('POST', $this->pageUrl() . '/' . $address->getId() . '/validate', ['statement_confirmed' => '1'], [], self::TURBO);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(CourtEmailStatus::PROPOSED, $this->reload($address)->getStatus());
    }

    public function testAForgedTokenIsRefused(): void
    {
        $address = $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY);
        $this->client->loginUser($this->user);

        $this->client->request('POST', $this->validateUrl($address), ['_token' => 'forged', 'statement_confirmed' => '1'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->trans('court_address.validation.error.csrf'), (string) $this->client->getResponse()->getContent());
        self::assertSame(CourtEmailStatus::PROPOSED, $this->reload($address)->getStatus());
    }

    public function testValidationWithTheStatementConfirmsTheAddressInAStream(): void
    {
        $address = $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY);
        $this->client->loginUser($this->user);
        $token = $this->token('form[data-testid="court-address-validate-form"]');

        $this->client->request('POST', $this->validateUrl($address), ['_token' => $token, 'statement_confirmed' => '1'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/vnd.turbo-stream.html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('target="court-address-list"', (string) $this->client->getResponse()->getContent());
        $address = $this->reload($address);
        self::assertSame(CourtEmailStatus::VALIDATED, $address->getStatus());
        self::assertSame($this->user->getId(), $address->getValidatedBy()?->getId());
    }

    public function testValidationWithoutTheStatementLeavesTheAddressProposed(): void
    {
        $address = $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY);
        $this->client->loginUser($this->user);
        $token = $this->token('form[data-testid="court-address-validate-form"]');

        $this->client->request('POST', $this->validateUrl($address), ['_token' => $token], [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->trans('court_address.validation.error.statement_required'), (string) $this->client->getResponse()->getContent());
        self::assertSame(CourtEmailStatus::PROPOSED, $this->reload($address)->getStatus());
    }

    public function testAnAddressOfAnotherCourtOrANominalOneIsNotFound(): void
    {
        $otherUser = $this->createFilingUser($this->em, 'court-addr-foreign');
        $this->otherUsers[] = $otherUser;
        $otherCase = $this->createFilingCase($this->em, $otherUser);
        $foreign = $this->createCourtEmailAddress($this->em, $otherCase->getCourt(), 'registratura@just.ro', CourtEmailPurpose::REGISTRY);
        $personal = $this->createCourtEmailAddress($this->em, $this->court, 'ion.popescu@just.ro', CourtEmailPurpose::PERSONAL);
        $this->client->loginUser($this->user);

        $this->client->request('POST', $this->validateUrl($foreign), ['statement_confirmed' => '1'], [], self::TURBO);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $this->validateUrl($personal), ['statement_confirmed' => '1'], [], self::TURBO);
        self::assertResponseStatusCodeSame(404);
    }

    public function testManualAddOfANominalAddressIsRefusedWithAToast(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->token('form[data-testid="court-address-manual-form"]');

        $this->client->request('POST', $this->pageUrl() . '/manual', ['_token' => $token, 'email' => 'ion.popescu@just.ro', 'purpose' => 'REGISTRY'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->trans('court_address.validation.error.personal_refused'), (string) $this->client->getResponse()->getContent());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM court_email_address WHERE court_id = ?', [$this->court->getId()]));

        $this->client->request('POST', $this->pageUrl() . '/manual', ['_token' => $token, 'email' => 'registratura@example.test', 'purpose' => 'REGISTRY'], [], self::TURBO);
        self::assertStringContainsString('registratura@example.test', (string) $this->client->getResponse()->getContent());
    }

    public function testManualAddStoresAProposedManualAddressAndShowsItForConfirmation(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->token('form[data-testid="court-address-manual-form"]');

        $this->client->request('POST', $this->pageUrl() . '/manual', ['_token' => $token, 'email' => ' Registratura@Just.ro ', 'purpose' => 'SECTION_CIVIL'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="court-address-list"', $content);
        self::assertStringContainsString($this->trans('court_address.validation.toast.added'), $content);
        self::assertStringContainsString('data-testid="court-address-validate-form"', $content);
        self::assertStringContainsString($this->trans('court_address.validation.scope_note'), $content);

        $this->em->clear();
        $address = $this->em->getRepository(CourtEmailAddress::class)->findOneBy(['court' => $this->court->getId(), 'email' => 'registratura@just.ro']);
        self::assertNotNull($address);
        self::assertSame(CourtEmailStatus::PROPOSED, $address->getStatus());
        self::assertSame(CourtEmailSource::MANUAL, $address->getSource());
        self::assertSame(CourtEmailPurpose::SECTION_CIVIL, $address->getPurpose());
        self::assertNull($address->getValidatedBy());
    }

    public function testReportMovesAConfirmedAddressToInvalid(): void
    {
        $address = $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, new \DateTimeImmutable('-5 days'), $this->user, true);
        $this->client->loginUser($this->user);
        $token = $this->token('form[data-testid="court-address-report-form"]');

        $this->client->request('POST', $this->pageUrl() . '/' . $address->getId() . '/report', ['_token' => $token, 'reason' => 'BOUNCED'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        $address = $this->reload($address);
        self::assertSame(CourtEmailStatus::INVALID, $address->getStatus());
        self::assertSame('reported_by_lawyer:BOUNCED', $address->getInvalidReason());
    }

    private function pageUrl(): string
    {
        return '/case/' . $this->case->getId() . '/court-address';
    }

    private function validateUrl(CourtEmailAddress $address): string
    {
        return $this->pageUrl() . '/' . $address->getId() . '/validate';
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
