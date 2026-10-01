<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\AuditLog;
use App\Entity\City;
use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\LegalCaseDebtor;
use App\Entity\Document;
use App\Entity\InterestRateConfig;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\PenaltyType;
use App\Enum\ContractualAccessoryLabel;
use App\Enum\AnafStatus;
use App\Enum\CourtType;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Enum\CaseStatus;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Service\AuditLogService;
use App\Service\Court\LocalityNormalizer;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Integration tests for Pas 3.2 wizard steps 1-4 (Creditor / Debitor / Creanță / Confirmare).
 *
 * We exercise the full HTTP cycle: the test client hits real routes, the
 * controller stores DTOs in the session, and step 4 POST triggers the
 * transactional persist (LegalCase + Debtor + Document.legal_case_id +
 * AuditLog). Session is primed by writing the DTO bag directly via the
 * KernelBrowser's session — faster and more deterministic than walking the
 * full 4-step happy path on every test that only cares about step 4.
 */
final class CaseWizardControllerStep1To4Test extends WebTestCase
{
    use CountyFixtureTrait;

    private const SESSION_KEY = 'case_wizard_data';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('wizard-step1to4-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Wizard');
        $this->user->setLastName('Tester');
        $this->em->persist($this->user);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        // FK order: audit_log → document → debtor → legal_case → creditor → user
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case_debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM case_status_history WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);

        // Court / city / county fixtures seeded for competent-court resolution.
        // legal_case (which FKs court_id) is already gone above.
        $conn->executeStatement('DELETE ccc FROM court_covered_city ccc JOIN court c ON ccc.court_id = c.id WHERE c.name LIKE ?', ['Wiztest%']);
        $conn->executeStatement('DELETE FROM court WHERE name LIKE ?', ['Wiztest%']);
        $conn->executeStatement('DELETE FROM city WHERE name LIKE ?', ['Wiztest%']);
        $conn->executeStatement('DELETE FROM county WHERE name LIKE ?', ['Wiztest%']);
        // Sentinel BNR rate seeded by ensureBnrRate() (this date is used nowhere else).
        $conn->executeStatement("DELETE FROM interest_rate_config WHERE valid_from = '2000-01-01'");

        parent::tearDown();
    }

    public function testCreditorGetRendersEmptyFormOnFreshSession(): void
    {
        $this->client->request('GET', '/case/new/creditor');

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Date creditor', $html);
        // CSRF token id from the Step1CreditorType.
        self::assertStringContainsString('name="step1_creditor[_token]"', $html);
    }

    public function testCreditorFormOffersOnlyLegalPerson(): void
    {
        $this->client->request('GET', '/case/new/creditor');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[type="hidden"][name="step1_creditor[personType]"][value="PJ"]');
        self::assertSelectorNotExists('input[type="radio"][name="step1_creditor[personType]"]');
        // The CNP field stays in the page for when natural persons return,
        // inside the block the toggle controller keeps hidden for PJ.
        self::assertSelectorExists('[data-person-type-toggle-target="pfOnly"] [name="step1_creditor[personalId]"]');
    }

    public function testCreditorFormExplainsTheLibraryAutosaveInsteadOfACheckbox(): void
    {
        $this->client->request('GET', '/case/new/creditor');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="_save_to_library_cosmetic"]');
        self::assertSelectorTextContains('#step1-creditor-form', 'se salvează automat în biblioteca ta');
    }

    public function testDebtorStepStatesTheInsolvencyCheckWithoutNamingASite(): void
    {
        $this->client->request('GET', '/case/new/debtor');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('nu se află în procedurile prevăzute de Legea 85/2014.', $html);
        self::assertStringNotContainsString('bpi.just.ro', $html);
    }

    public function testPickingALibraryDebtorFillsTheStepWithoutItsChecks(): void
    {
        $company = $this->libraryDebtor($this->user, 'Biblioteca Debitor SRL', 'RO15193236');
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 hour'));

        $this->pick($company->getId());

        self::assertResponseRedirects('/case/new/debtor');
        $bag = $this->client->getRequest()->getSession()->get(self::SESSION_KEY);
        $entry = $bag['debtors']->debtors[0];
        self::assertSame($company->getId(), $entry->debtorId);
        self::assertSame('Biblioteca Debitor SRL', $entry->name);
        self::assertNull($entry->anafStatus, 'checks made for another case never come with the company');
        self::assertNull($entry->insolvencyCheckedAt);
        self::assertSame('Acme Debtor SRL', $bag['debtorBeforePick']->debtors[0]->name, 'what was typed is kept for "Renunță"');
    }

    public function testAnotherLawyersCompanyCannotBePicked(): void
    {
        $stranger = new User();
        $stranger->setEmail('wizard-stranger-' . uniqid() . '@test.com');
        $stranger->setPassword('x');
        $this->em->persist($stranger);
        $this->em->flush();
        $company = $this->libraryDebtor($stranger, 'Străin SRL', 'RO14186770');
        $this->primeSessionForStep4();

        $this->pick($company->getId());

        $entry = $this->client->getRequest()->getSession()->get(self::SESSION_KEY)['debtors']->debtors[0];
        self::assertNull($entry->debtorId);
        self::assertSame('Acme Debtor SRL', $entry->name);
        $this->em->getConnection()->executeStatement('DELETE FROM `user` WHERE id = ?', [$stranger->getId()]);
    }

    public function testCancellingThePickBringsBackTheTypedDebtor(): void
    {
        $company = $this->libraryDebtor($this->user, 'Biblioteca Debitor SRL', 'RO15193236');
        $this->primeSessionForStep4();
        $this->pick($company->getId());

        $crawler = $this->client->request('GET', '/case/new/debtor');
        self::assertSelectorExists('[data-testid="debtor-from-library"]');
        self::assertSelectorExists('input[name="step2_debtors[debtors][0][name]"][readonly]');
        $this->client->submit($crawler->filter('[data-testid="debtor-unpick"]')->form());

        $entry = $this->client->getRequest()->getSession()->get(self::SESSION_KEY)['debtors']->debtors[0];
        self::assertNull($entry->debtorId);
        self::assertSame('Acme Debtor SRL', $entry->name);
    }

    public function testTheLibraryIdentityWinsOverWhatThePageSends(): void
    {
        $company = $this->libraryDebtor($this->user, 'Biblioteca Debitor SRL', 'RO15193236');
        $this->primeSessionForStep4();
        $this->pick($company->getId());
        $crawler = $this->client->request('GET', '/case/new/debtor');
        $token = $crawler->filter('input[name="step2_debtors[_token]"]')->first()->attr('value');

        $post = $this->debtorPost($token, 'RO15193236');
        $post['step2_debtors']['debtors'][0]['name'] = 'Nume Editat Pe Drum SRL';
        $post['step2_debtors']['debtors'][0]['debtorId'] = (string) $company->getId();
        $this->client->request('POST', '/case/new/debtor', $post);
        self::assertResponseStatusCodeSame(422, 'other data for a linked company is not taken silently');
        self::assertSelectorExists('[data-testid="library-differs"]');

        $this->client->request('POST', '/case/new/debtor', $post + ['library_debtor_choice' => [0 => 'library']]);
        self::assertResponseRedirects('/case/new/claim');
        $entry = $this->client->getRequest()->getSession()->get(self::SESSION_KEY)['debtors']->debtors[0];
        self::assertSame('Biblioteca Debitor SRL', $entry->name);
        self::assertSame($company->getId(), $entry->debtorId);
    }

    public function testACaseWithALibraryDebtorLinksThatCompany(): void
    {
        $company = $this->libraryDebtor($this->user, 'Biblioteca Debitor SRL', 'RO14186770');
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 hour'));
        $session = $this->client->getRequest()->getSession();
        $bag = $session->get(self::SESSION_KEY);
        $bag['debtors']->debtors[0]->debtorId = $company->getId();
        $session->set(self::SESSION_KEY, $bag);
        $session->save();

        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $token = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');
        $this->client->request('POST', '/case/new/confirmation', ['step4_confirmation' => [
            '_token' => $token,
            'acceptTerms' => '1',
            'acceptDataAccuracy' => '1',
        ]]);

        $this->em->clear();
        $case = $this->em->getRepository(LegalCase::class)->findOneBy(['user' => $this->user]);
        self::assertNotNull($case);
        self::assertSame($company->getId(), $case->getPrimaryDebtor()->getDebtor()->getId());
        self::assertCount(1, $this->em->getRepository(Debtor::class)->findBy(['user' => $this->user]), 'no copy of the company');
        self::assertNotNull($case->getPrimaryDebtor()->getInsolvencyCheckedAt(), 'the check made for this case sits on its link');
    }

    public function testAPickWithoutAValidTokenChangesNothing(): void
    {
        $company = $this->libraryDebtor($this->user, 'Biblioteca Debitor SRL', 'RO15193236');
        $this->primeSessionForStep4();

        $this->client->request('POST', '/case/new/debtor/pick', ['debtor_pick' => ['_token' => 'forged', 'debtor' => (string) $company->getId()]]);

        self::assertNull($this->client->getRequest()->getSession()->get(self::SESSION_KEY)['debtors']->debtors[0]->debtorId);
    }

    public function testCancellingTwiceKeepsWhatTheFirstCancelRestored(): void
    {
        $company = $this->libraryDebtor($this->user, 'Biblioteca Debitor SRL', 'RO15193236');
        $this->primeSessionForStep4();
        $this->pick($company->getId());
        $crawler = $this->client->request('GET', '/case/new/debtor');
        $unpick = $crawler->filter('[data-testid="debtor-unpick"]')->form();

        $this->client->submit($unpick);
        $this->client->submit($unpick);

        $entry = $this->client->getRequest()->getSession()->get(self::SESSION_KEY)['debtors']->debtors[0];
        self::assertSame('Acme Debtor SRL', $entry->name);
    }

    public function testADebtorTypedWithALibraryCuiButOtherDataAsksWhichDataStands(): void
    {
        $company = $this->libraryDebtor($this->user, 'Biblioteca Debitor SRL', 'RO14186770');
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 hour'));
        $crawler = $this->client->request('GET', '/case/new/debtor');
        $token = $crawler->filter('input[name="step2_debtors[_token]"]')->first()->attr('value');
        $post = $this->debtorPost($token, 'RO14186770');

        $this->client->request('POST', '/case/new/debtor', $post);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-testid="library-differs"]');

        $this->client->request('POST', '/case/new/debtor', $post + ['library_debtor_choice' => [0 => 'library']]);
        self::assertResponseRedirects('/case/new/claim');
        $entry = $this->client->getRequest()->getSession()->get(self::SESSION_KEY)['debtors']->debtors[0];
        self::assertSame($company->getId(), $entry->debtorId);
        self::assertSame('Biblioteca Debitor SRL', $entry->name, 'the library data stands');
        self::assertFalse($entry->updateLibrary);
    }

    public function testChoosingToUpdateTheLibraryWritesTheCompanyAtSubmission(): void
    {
        $company = $this->libraryDebtor($this->user, 'Biblioteca Debitor SRL', 'RO14186770');
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 hour'));
        $crawler = $this->client->request('GET', '/case/new/debtor');
        $token = $crawler->filter('input[name="step2_debtors[_token]"]')->first()->attr('value');
        $this->client->request('POST', '/case/new/debtor', $this->debtorPost($token, 'RO14186770') + ['library_debtor_choice' => [0 => 'update']]);
        self::assertResponseRedirects('/case/new/claim');

        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $token = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');
        $this->client->request('POST', '/case/new/confirmation', ['step4_confirmation' => ['_token' => $token, 'acceptTerms' => '1', 'acceptDataAccuracy' => '1']]);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Debtor::class)->find($company->getId());
        self::assertSame('Acme Debtor SRL', $reloaded->getName(), 'the company took this step\'s data');
        $audit = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'wizard_submit', 'user' => $this->user]);
        self::assertEquals([['debtorId' => $company->getId(), 'source' => 'library_updated']], $audit->getNewData()['debtors']);
        self::assertCount(1, $this->em->getRepository(Debtor::class)->findBy(['user' => $this->user]));
    }

    public function testADebtorMatchingTheLibraryIsLinkedWithoutAsking(): void
    {
        $company = $this->libraryDebtor($this->user, 'Acme Debtor SRL', 'RO14186770');
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 hour'));
        $crawler = $this->client->request('GET', '/case/new/debtor');
        $token = $crawler->filter('input[name="step2_debtors[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/debtor', $this->debtorPost($token, '14186770'));

        self::assertResponseRedirects('/case/new/claim');
        $entry = $this->client->getRequest()->getSession()->get(self::SESSION_KEY)['debtors']->debtors[0];
        self::assertSame($company->getId(), $entry->debtorId);
    }

    public function testTheRegistrySpellingOfTheSameNumberIsNotADifference(): void
    {
        // The library holds the single-string form ANAF returns; the step has
        // the classic form read off the invoice. Same registration, no question.
        $company = $this->libraryDebtor($this->user, 'Acme Debtor SRL', 'RO14186770');
        $company->setOnrcNumber('J2019008765401');
        $this->em->flush();
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 hour'));
        $crawler = $this->client->request('GET', '/case/new/debtor');
        $token = $crawler->filter('input[name="step2_debtors[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/debtor', $this->debtorPost($token, '14186770'));

        self::assertResponseRedirects('/case/new/claim');
        $entry = $this->client->getRequest()->getSession()->get(self::SESSION_KEY)['debtors']->debtors[0];
        self::assertSame($company->getId(), $entry->debtorId);
    }

    public function testAnotherLawyersCompanyWithTheSameCuiIsNotLinked(): void
    {
        $other = new User();
        $other->setEmail('wizard-other-' . uniqid() . '@test.com');
        $other->setPassword('x');
        $other->setIsVerified(true);
        $this->em->persist($other);
        $this->em->flush();
        try {
            $this->libraryDebtor($other, 'Biblioteca Straina SRL', 'RO14186770');
            $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 hour'));
            $crawler = $this->client->request('GET', '/case/new/debtor');
            $token = $crawler->filter('input[name="step2_debtors[_token]"]')->first()->attr('value');

            $this->client->request('POST', '/case/new/debtor', $this->debtorPost($token, 'RO14186770'));

            self::assertResponseRedirects('/case/new/claim');
            self::assertNull($this->client->getRequest()->getSession()->get(self::SESSION_KEY)['debtors']->debtors[0]->debtorId);
        } finally {
            $this->em->getConnection()->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $other->getId()]);
        }
    }

    public function testASummonedCompanyIsNotUpdatedFromTheWizard(): void
    {
        $company = $this->libraryDebtor($this->user, 'Biblioteca Debitor SRL', 'RO14186770');
        $summoned = new LegalCase();
        $summoned->setUser($this->user);
        $summoned->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $summoned->addDebtor(new LegalCaseDebtor($company));
        $this->em->persist($summoned);
        $this->em->flush();
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 hour'));
        $crawler = $this->client->request('GET', '/case/new/debtor');
        $token = $crawler->filter('input[name="step2_debtors[_token]"]')->first()->attr('value');
        $post = $this->debtorPost($token, 'RO14186770');

        $this->client->request('POST', '/case/new/debtor', $post);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('input[name="library_debtor_choice[0]"][value="library"]');
        self::assertSelectorNotExists('input[name="library_debtor_choice[0]"][value="update"]');

        $this->client->request('POST', '/case/new/debtor', $post + ['library_debtor_choice' => [0 => 'update']]);
        self::assertResponseStatusCodeSame(422, 'a forged update choice is not accepted');
        $this->em->clear();
        self::assertSame('Biblioteca Debitor SRL', $this->em->getRepository(Debtor::class)->find($company->getId())->getName());
    }

    public function testACompanySummonedBeforeSubmissionIsNotUpdated(): void
    {
        $company = $this->libraryDebtor($this->user, 'Biblioteca Debitor SRL', 'RO14186770');
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 hour'));
        $crawler = $this->client->request('GET', '/case/new/debtor');
        $token = $crawler->filter('input[name="step2_debtors[_token]"]')->first()->attr('value');
        $this->client->request('POST', '/case/new/debtor', $this->debtorPost($token, 'RO14186770') + ['library_debtor_choice' => [0 => 'update']]);
        self::assertResponseRedirects('/case/new/claim');

        $summoned = new LegalCase();
        $summoned->setUser($this->em->find(User::class, $this->user->getId()));
        $summoned->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $summoned->addDebtor(new LegalCaseDebtor($this->em->find(Debtor::class, $company->getId())));
        $this->em->persist($summoned);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/case/new/confirmation');
        self::assertStringContainsString('Biblioteca Debitor SRL', $crawler->text(), 'step 4 shows the library identity');
        $token = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');
        $this->client->request('POST', '/case/new/confirmation', ['step4_confirmation' => ['_token' => $token, 'acceptTerms' => '1', 'acceptDataAccuracy' => '1']]);

        $this->em->clear();
        self::assertSame('Biblioteca Debitor SRL', $this->em->getRepository(Debtor::class)->find($company->getId())->getName());
    }

    public function testStep4ShowsTheDebtorsRegistryNumberAndAdministrator(): void
    {
        $this->primeSessionForStep4();
        $session = $this->client->getRequest()->getSession();
        $bag = $session->get(self::SESSION_KEY);
        $bag['debtors']->debtors[0]->onrcNumber = 'J40/8765/2019';
        $bag['debtors']->debtors[0]->administrator = 'Maria Ionescu';
        $session->set(self::SESSION_KEY, $bag);
        $session->save();

        $this->client->request('GET', '/case/new/confirmation');

        self::assertSelectorExists('[data-testid="step4-debtor-onrc"]');
        self::assertSelectorTextContains('[data-testid="step4-debtor-administrator"]', 'Maria Ionescu');
    }

    public function testALinkedCompanyWhoseCuiWasChangedIsDropped(): void
    {
        $company = $this->libraryDebtor($this->user, 'Biblioteca Debitor SRL', 'RO15193236');
        $this->primeSessionForStep4();
        $this->pick($company->getId());
        $crawler = $this->client->request('GET', '/case/new/debtor');
        $token = $crawler->filter('input[name="step2_debtors[_token]"]')->first()->attr('value');

        $post = $this->debtorPost($token, 'RO14186770');
        $post['step2_debtors']['debtors'][0]['debtorId'] = (string) $company->getId();
        $this->client->request('POST', '/case/new/debtor', $post);
        self::assertResponseStatusCodeSame(422, 'the attestation made for the other company is withdrawn');

        $this->client->request('POST', '/case/new/debtor', $post);
        self::assertResponseRedirects('/case/new/claim');
        $entry = $this->client->getRequest()->getSession()->get(self::SESSION_KEY)['debtors']->debtors[0];
        self::assertNull($entry->debtorId, 'another CUI is another company');
        self::assertSame('Acme Debtor SRL', $entry->name);
    }

    public function testAPickedDebtorWithInvalidDataSaysWhereToCorrectIt(): void
    {
        $company = $this->libraryDebtor($this->user, 'Seed Vechi SRL', 'RO87654321');
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 hour'));
        $this->pick($company->getId());
        $crawler = $this->client->request('GET', '/case/new/debtor');
        $token = $crawler->filter('input[name="step2_debtors[_token]"]')->first()->attr('value');
        $post = $this->debtorPost($token, 'RO87654321');
        $post['step2_debtors']['debtors'][0]['debtorId'] = (string) $company->getId();

        $this->client->request('POST', '/case/new/debtor', $post);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-testid="library-debtor-invalid"] a[href="/debtors/' . $company->getId() . '/edit"][data-turbo-frame="_top"]');
    }

    public function testAPickedDebtorFollowsItsCorrectionInTheLibrary(): void
    {
        $company = $this->libraryDebtor($this->user, 'Seed Vechi SRL', 'RO87654321');
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 hour'));
        $this->pick($company->getId());
        $session = $this->client->getRequest()->getSession();
        $bag = $session->get(self::SESSION_KEY);
        $bag['debtors']->debtors[0]->insolvencyCheckedAt = new \DateTimeImmutable('-1 hour');
        $session->set(self::SESSION_KEY, $bag);
        $session->save();

        $reloaded = $this->em->find(Debtor::class, $company->getId());
        $reloaded->setCui('RO87654329');
        $reloaded->setAdministrator('Maria Ionescu');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/case/new/debtor');

        self::assertSame('RO87654329', $crawler->filter('input[name="step2_debtors[debtors][0][cui]"]')->attr('value'));
        self::assertSame('Maria Ionescu', $crawler->filter('input[name="step2_debtors[debtors][0][administrator]"]')->attr('value'));
        $entry = $this->client->getRequest()->getSession()->get(self::SESSION_KEY)['debtors']->debtors[0];
        self::assertSame($company->getId(), $entry->debtorId);
        self::assertNull($entry->insolvencyCheckedAt, 'the check made for the old CUI is withdrawn');
    }

    public function testACompanyAddedToTheLibraryAfterStep2WithTheSameDataIsLinked(): void
    {
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 hour'));
        $company = $this->libraryDebtor($this->user, 'Acme Debtor SRL', '14186770');

        $this->client->request('GET', '/case/new/confirmation');

        self::assertResponseIsSuccessful();
        self::assertSame($company->getId(), $this->client->getRequest()->getSession()->get(self::SESSION_KEY)['debtors']->debtors[0]->debtorId);
    }

    public function testACompanyAddedToTheLibraryAfterStep2WithOtherDataSendsTheLawyerBackToChoose(): void
    {
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 hour'));
        $this->libraryDebtor($this->user, 'Acme Alt Nume SRL', 'RO14186770');

        $this->client->request('GET', '/case/new/confirmation');
        self::assertResponseRedirects('/case/new/debtor');

        $crawler = $this->client->request('GET', '/case/new/debtor');
        self::assertSelectorExists('[data-testid="library-recheck"]');
        $token = $crawler->filter('input[name="step2_debtors[_token]"]')->first()->attr('value');
        $this->client->request('POST', '/case/new/debtor', $this->debtorPost($token, 'RO14186770'));
        self::assertSelectorExists('[data-testid="library-differs"]');
        self::assertSelectorNotExists('[data-testid="library-recheck"]');
    }

    private function libraryDebtor(User $owner, string $name, string $cui): Debtor
    {
        $debtor = new Debtor();
        $debtor->setUser($owner);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName($name);
        $debtor->setCui($cui);
        $debtor->setOnrcNumber('J40/8765/2019');
        $debtor->setAddress('Str. Debitor nr. 2, Cluj-Napoca');
        $this->em->persist($debtor);
        $this->em->flush();

        return $debtor;
    }

    private function pick(int $debtorId): void
    {
        $crawler = $this->client->request('GET', '/case/new/debtor');
        $token = $crawler->filter('input[name="debtor_pick[_token]"]')->first()->attr('value');
        $this->client->request('POST', '/case/new/debtor/pick', ['debtor_pick' => ['_token' => $token, 'debtor' => (string) $debtorId]]);
    }

    public function testCreditorPostStoresDtoInSessionAndRedirectsToDebtor(): void
    {
        $crawler = $this->client->request('GET', '/case/new/creditor');
        $token = $crawler->filter('form input[name="step1_creditor[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/creditor', [
            'step1_creditor' => [
                '_token' => $token,
                'personType' => PersonType::PJ->value,
                'name' => 'Acme Creditor SRL',
                'cui' => 'RO15193236',
                'onrcNumber' => 'J40/1234/2018',
                'address' => 'Str. Exemplu nr. 1, București',
            ],
        ]);

        self::assertResponseRedirects('/case/new/debtor');

        // Re-issue a GET on /creditor — session-stored DTO should be reflected
        // back in the form as the default.
        $crawler = $this->client->request('GET', '/case/new/creditor');
        self::assertSame(
            'Acme Creditor SRL',
            $crawler->filter('input[name="step1_creditor[name]"]')->first()->attr('value'),
        );
    }

    /**
     * Picking an existing creditor (the `creditorEntity` autocomplete) must
     * advance to step 2 without the manual fields. Regression: the bridge that
     * sets `creditorId` tied the validator's POST_SUBMIT priority, so
     * `validation_groups` saw it null, kept the `manual` group active, and
     * "Continuă" silently failed on the empty fields (fixed via listener priority).
     */
    public function testCreditorPostWithLibraryPickAdvancesWithoutManualFields(): void
    {
        $existing = new Creditor();
        $existing->setUser($this->user);
        $existing->setPersonType(PersonType::PJ);
        $existing->setName('Library Creditor SRL');
        $existing->setAddress('Str. Bibliotecă nr. 2, Cluj-Napoca');
        $existing->setCui('RO15193236');
        $this->em->persist($existing);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/case/new/creditor');
        $token = $crawler->filter('form input[name="step1_creditor[_token]"]')->first()->attr('value');

        // Only the autocomplete value is submitted — no name/cui/onrc/address.
        $this->client->request('POST', '/case/new/creditor', [
            'step1_creditor' => [
                '_token' => $token,
                'creditorEntity' => (string) $existing->getId(),
            ],
        ]);

        self::assertResponseRedirects('/case/new/debtor');
    }

    /**
     * The confirmation step and the documents read the step 1 DTO, so a library
     * pick has to carry the creditor's own data, not the empty manual fields.
     * Coming back to step 1 shows the pick again instead of an empty picker.
     */
    public function testLibraryPickFillsTheCreditorDataAndStaysSelected(): void
    {
        $existing = $this->persistLibraryCreditor('Library Creditor SRL', 'RO15193236');
        $existing->setIban('RO49AAAA1B31007593840000');
        $this->em->flush();

        $this->postCreditorStep(['creditorEntity' => (string) $existing->getId()]);
        self::assertResponseRedirects('/case/new/debtor');

        $dto = $this->storedCreditorDto();
        self::assertSame($existing->getId(), $dto->creditorId);
        self::assertSame('Library Creditor SRL', $dto->name);
        self::assertSame('RO15193236', $dto->cui);
        self::assertSame('RO49AAAA1B31007593840000', $dto->iban);

        $crawler = $this->client->request('GET', '/case/new/creditor');
        self::assertSame(
            (string) $existing->getId(),
            $crawler->filter('select[name="step1_creditor[creditorEntity]"] option[selected]')->attr('value'),
        );
    }

    /**
     * Clearing the picker and typing another creditor must file the case under
     * the typed one. The id of the earlier pick used to survive in the hidden
     * field and win at persist time, so the documents named the wrong party.
     */
    public function testClearingTheLibraryPickFallsBackToTheManualCreditor(): void
    {
        $existing = $this->persistLibraryCreditor('Library Creditor SRL', 'RO15193236');

        $this->postCreditorStep(['creditorEntity' => (string) $existing->getId()]);
        self::assertResponseRedirects('/case/new/debtor');

        $this->postCreditorStep([
            'creditorEntity' => '',
            'creditorId' => (string) $existing->getId(),
            'personType' => PersonType::PJ->value,
            'name' => 'Manual Creditor SRL',
            'cui' => 'RO14186770',
            'onrcNumber' => 'J40/1234/2018',
            'address' => 'Str. Exemplu nr. 1, București',
        ]);
        self::assertResponseRedirects('/case/new/debtor');

        $dto = $this->storedCreditorDto();
        self::assertNull($dto->creditorId);
        self::assertSame('Manual Creditor SRL', $dto->name);
        self::assertSame('RO14186770', $dto->cui);
    }

    public function testAPickedCreditorWithInvalidDataSaysWhatToCorrect(): void
    {
        $existing = $this->persistLibraryCreditor('Seed Vechi SRL', 'RO12345678');

        $this->postCreditorStep(['creditorEntity' => (string) $existing->getId()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-testid="picked-creditor-invalid"]', 'CUI');
        self::assertSelectorExists('[data-testid="picked-creditor-invalid"] a[href="/creditors/' . $existing->getId() . '/edit"][data-turbo-frame="_top"]');
    }

    private function persistLibraryCreditor(string $name, string $cui): Creditor
    {
        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName($name);
        $creditor->setAddress('Str. Bibliotecă nr. 2, Cluj-Napoca');
        $creditor->setCui($cui);
        $this->em->persist($creditor);
        $this->em->flush();

        return $creditor;
    }

    /**
     * @param array<string, string> $fields
     */
    private function postCreditorStep(array $fields): void
    {
        $crawler = $this->client->request('GET', '/case/new/creditor');
        $token = $crawler->filter('form input[name="step1_creditor[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/creditor', [
            'step1_creditor' => ['_token' => $token, ...$fields],
        ]);
    }

    private function storedCreditorDto(): Step1CreditorData
    {
        $dto = $this->client->getRequest()->getSession()->get(self::SESSION_KEY)['creditor'] ?? null;
        self::assertInstanceOf(Step1CreditorData::class, $dto);

        return $dto;
    }

    public function testDebtorPostWithEmptyFieldsRendersValidationErrorsAndDoesNotAdvance(): void
    {
        // Regression: previously the controller validated correctly (isValid=false
        // → stays on /debtor) but the Step2DebtorsLiveComponent rebuilt a fresh
        // form from `initialFormData`, discarding the errors. The user saw no
        // feedback. Fix: `_step2_debtor_content.html.twig` passes
        // `form: form.createView` so ComponentWithFormTrait::initializeForm
        // picks up the validated FormView and sets isValidated=true.
        $crawler = $this->client->request('GET', '/case/new/debtor');
        $token = $crawler->filter('input[name="step2_debtors[_token]"]')->first()->attr('value');

        // POST with personType=PJ (the new default) and EVERY other field empty.
        // The Step2DebtorEntry DTO + Callback should produce violations on
        // name, address, cui, onrcNumber.
        $this->client->request('POST', '/case/new/debtor', [
            'step2_debtors' => [
                '_token' => $token,
                'debtors' => [
                    [
                        'personType' => 'PJ',
                        'name' => '',
                        'cui' => '',
                        'onrcNumber' => '',
                        'address' => '',
                    ],
                ],
            ],
        ]);

        // Symfony 7 returns 422 on invalid form submissions (RFC 9110). The
        // controller stays on /debtor — no redirect to /claim.
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $html = $this->client->getResponse()->getContent();
        // Each of the 4 required-PJ fields surfaces its own translated error.
        // Assert on the RO copy (validators.ro.yaml) — these are the strings
        // the user actually reads when validation fails.
        self::assertStringContainsString('denumirea sau numele complet al debitorului', $html);
        self::assertStringContainsString('adresa debitorului', $html);
        self::assertStringContainsString('CUI-ul debitorului este obligatoriu', $html);
        self::assertStringContainsString('Numărul ONRC al debitorului este obligatoriu', $html);
    }

    public function testClaimStepNamesTheSourceOfTheClaimNotItsLegalGround(): void
    {
        // The lawyer's term for the act the claim comes from (lease, sale,
        // services). "Temei juridic" stays reserved for the CPC articles.
        self::assertTrue($this->primeSessionForClaimStep(), 'session bag must be primed');

        $crawler = $this->client->request('GET', '/case/new/claim');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Izvor creanță', $crawler->filter('body')->text());
        self::assertStringNotContainsString('Temei juridic', $crawler->filter('body')->text());
    }

    public function testClaimPostWithMissingRequiredFieldsRendersValidationErrorsAndDoesNotAdvance(): void
    {
        // Equivalent to testDebtorPostWithEmptyFields — verifies that Step 3's
        // ComponentWithFormTrait refactor preserves controller-side validation
        // errors across the LC re-render boundary.
        $debtorBag = $this->primeSessionForClaimStep();
        self::assertTrue($debtorBag, 'session bag must be primed');

        $crawler = $this->client->request('GET', '/case/new/claim');
        $token = $crawler->filter('input[name="step3_claim[_token]"]')->first()->attr('value');

        // POST with empty amount + dueDate → DTO Asserts fire. Currency stays
        // 'RON' because Step3ClaimData::$currency is non-nullable string; we
        // exercise the optional-but-validated paths.
        $this->client->request('POST', '/case/new/claim', [
            'step3_claim' => [
                '_token' => $token,
                'amount' => '',
                'currency' => 'RON',
                'dueDate' => '',
                'relationshipType' => 'COMERCIAL',
            ],
        ]);

        // Symfony 7 returns 422 on invalid submissions; controller stays on /claim.
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $html = $this->client->getResponse()->getContent();
        // Required-amount + required-currency + required-due-date errors all
        // surface inline via field_errors macro (controller-validated form
        // propagates through Step3ClaimLiveComponent re-render via the
        // ComponentWithFormTrait `form` arg). RO strings come from
        // translations/validators.ro.yaml.
        self::assertStringContainsString('Introdu suma datorată', $html);
        self::assertStringContainsString('Introdu data scadenței', $html);
    }

    public function testConfirmationGetWithoutPriorStepsRedirectsToCreditor(): void
    {
        $this->client->request('GET', '/case/new/confirmation');

        self::assertResponseRedirects('/case/new/creditor');
    }

    public function testAStaleNaturalPersonDebtorIsSentBackToTheDebtorStep(): void
    {
        $this->primeSessionForStep4(personType: PersonType::PF, anafStatus: null);

        $this->client->request('GET', '/case/new/claim');
        self::assertResponseRedirects('/case/new/debtor');

        $this->client->request('GET', '/case/new/confirmation');
        self::assertResponseRedirects('/case/new/debtor');

        $this->client->request('POST', '/case/new/confirmation', ['step4_confirmation' => ['acceptTerms' => '1']]);
        self::assertResponseRedirects('/case/new/debtor');
    }

    public function testADebtorReadAsNaturalPersonIsOfferedAsLegalPersonWithANote(): void
    {
        $this->primeSessionForStep4(personType: PersonType::PF, anafStatus: null);

        $this->client->request('GET', '/case/new/debtor');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[type="hidden"][name="step2_debtors[debtors][0][personType]"][value="PJ"]');
        self::assertSelectorNotExists('input[type="radio"][name="step2_debtors[debtors][0][personType]"]');
        self::assertSelectorTextContains('#step2-debtors-form', 'Documentele par să descrie o persoană fizică');
    }

    public function testChangingTheDebtorCuiDropsTheChecksMadeForTheOtherCompany(): void
    {
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 hour'));
        $crawler = $this->client->request('GET', '/case/new/debtor');
        $token = $crawler->filter('input[name="step2_debtors[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/debtor', $this->debtorPost($token, 'RO15193236'));

        // The tick that came with the form was given for the previous company.
        self::assertResponseStatusCodeSame(422);
        $entry = $this->client->getRequest()->getSession()->get(self::SESSION_KEY)['debtors']->debtors[0];
        self::assertNull($entry->insolvencyCheckedAt, 'the attestation must be given again for the new company');
        self::assertNull($entry->anafStatus, 'the ANAF status was read for the previous company');
        self::assertNull($entry->anafCheckedAt);
    }

    public function testAFreshAnafSyncForTheNewCuiIsKept(): void
    {
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 hour'));
        $crawler = $this->client->request('GET', '/case/new/debtor');
        $token = $crawler->filter('input[name="step2_debtors[_token]"]')->first()->attr('value');

        $post = $this->debtorPost($token, 'RO15193236');
        $post['step2_debtors']['debtors'][0]['anafStatus'] = AnafStatus::INACTIV->value;
        $post['step2_debtors']['debtors'][0]['anafCheckedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $this->client->request('POST', '/case/new/debtor', $post);

        $entry = $this->client->getRequest()->getSession()->get(self::SESSION_KEY)['debtors']->debtors[0];
        self::assertSame(AnafStatus::INACTIV, $entry->anafStatus, 'the sync made for the new CUI stands');
        self::assertNull($entry->insolvencyCheckedAt, 'the attestation still has to be given again');
    }

    public function testKeepingTheDebtorCuiKeepsTheChecks(): void
    {
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 hour'));
        $crawler = $this->client->request('GET', '/case/new/debtor');
        $token = $crawler->filter('input[name="step2_debtors[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/debtor', $this->debtorPost($token, 'ro 14186770'));

        self::assertResponseRedirects('/case/new/claim');
        $entry = $this->client->getRequest()->getSession()->get(self::SESSION_KEY)['debtors']->debtors[0];
        self::assertSame(AnafStatus::ACTIV, $entry->anafStatus);
        self::assertNotNull($entry->insolvencyCheckedAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function debtorPost(?string $token, string $cui): array
    {
        return ['step2_debtors' => [
            '_token' => $token,
            'debtors' => [[
                'personType' => 'PJ',
                'name' => 'Acme Debtor SRL',
                'cui' => $cui,
                'onrcNumber' => 'J40/8765/2019',
                'address' => 'Str. Debitor nr. 2, Cluj-Napoca',
                'bpiVerifiedToday' => '1',
            ]],
        ]];
    }

    public function testConfirmationGetShowsErrorAlertWhenDebtorIsAnafRadiat(): void
    {
        $this->primeSessionForStep4(anafStatus: AnafStatus::RADIAT);

        $crawler = $this->client->request('GET', '/case/new/confirmation');

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        // The validator emits OP_BLOCKED_DEREGISTERED → message key shown
        // through |trans, so we assert on the RO translation directly.
        self::assertStringContainsString('Cererea NU poate fi depusă', $html);
        // Submit button must be disabled.
        $submitButton = $crawler->filter('button[type=submit][disabled]')->first();
        self::assertGreaterThan(0, $submitButton->count(), 'Submit button must be disabled on ERROR');
    }

    public function testConfirmationGetShowsAcknowledgeCheckboxOnWarningPath(): void
    {
        // Fiscally inactive debtor: OP_DEFENDANT_FISCALLY_INACTIVE (WARNING). No ERROR.
        $this->primeSessionForStep4(anafStatus: AnafStatus::INACTIV, insolvencyCheckedAt: new \DateTimeImmutable('-1 hour'));

        $this->client->request('GET', '/case/new/confirmation');

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Atenție', $html);
        self::assertStringContainsString('acknowledgedWarnings', $html);
    }

    public function testConfirmationSubmitRespectsAcknowledgeRequirementOnWarningPath(): void
    {
        $this->primeSessionForStep4(anafStatus: AnafStatus::INACTIV, insolvencyCheckedAt: new \DateTimeImmutable('-1 hour'));
        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $token = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');

        // Submit without bifing acknowledgedWarnings → form invalid → re-render
        // step 4 GET. Symfony 7 returns HTTP 422 on invalid form submissions
        // (RFC 9110); the previous 200 default was kept for back-compat only.
        $this->client->request('POST', '/case/new/confirmation', [
            'step4_confirmation' => [
                '_token' => $token,
                'acceptTerms' => '1',
                'acceptDataAccuracy' => '1',
                // acknowledgedWarnings deliberately omitted
            ],
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $cases = $this->em->getRepository(LegalCase::class)->findBy(['user' => $this->user]);
        self::assertCount(0, $cases, 'LegalCase must NOT be persisted when acknowledgedWarnings is missing');
    }

    public function testAContractObjectLongerThanTheOldColumnSavesWithoutCrashing(): void
    {
        // The user's real case: AI put the contract's object phrase (over the old
        // 100-char limit, within the widened 255) into contractReference, which
        // used to 500 the save. It must now persist whole, since the same value
        // flows into ClaimItem.causeReference that groups positions for CPC art. 99.
        $reference = 'Servicii de dezvoltare software: realizare programe, cod sursa, documentatii tehnice si mentenanta lunara'; // ~104 chars
        $this->primeSessionForStep4(
            anafCheckedAt: new \DateTimeImmutable('-1 day'),
            insolvencyCheckedAt: new \DateTimeImmutable('-1 day'),
            contractReference: $reference,
        );

        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $token = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');
        $this->client->request('POST', '/case/new/confirmation', [
            'step4_confirmation' => ['_token' => $token, 'acceptTerms' => '1', 'acceptDataAccuracy' => '1'],
        ]);
        $this->em->clear();

        $cases = $this->em->getRepository(LegalCase::class)->findBy(['user' => $this->user]);
        self::assertCount(1, $cases, 'The case saves without a truncation 500');
        // Stored whole, not cut: the cause key that decides competence reads it in full.
        self::assertSame($reference, $cases[0]->getContractReference());
    }

    public function testConfirmationPersistsTheContractualPaymentNoticeFields(): void
    {
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 day'), claim: new Step3ClaimData(
            amount: 1000.0,
            currency: 'RON',
            dueDate: new \DateTimeImmutable('-30 days'),
            relationshipType: RelationshipType::COMERCIAL,
            penaltyType: PenaltyType::CONTRACTUAL,
            contractualPenaltyRate: 0.1,
            penaltyClauseArticle: '  art. 7.2 ',
            penaltyClauseText: 'Întârzierea atrage penalități de 0,1% pe zi.',
            contractualAccessoryLabel: ContractualAccessoryLabel::MAJORARI_INTARZIERE,
            contractObject: 'prestarea de servicii de transport',
            paymentNoticeNumber: '868',
        ));

        $case = $this->submitConfirmation();

        self::assertSame('art. 7.2', $case->getPenaltyClauseArticle());
        self::assertSame('Întârzierea atrage penalități de 0,1% pe zi.', $case->getPenaltyClauseText());
        self::assertSame(ContractualAccessoryLabel::MAJORARI_INTARZIERE, $case->getContractualAccessoryLabel());
        self::assertSame('prestarea de servicii de transport', $case->getContractObject());
        self::assertSame('868', $case->getPaymentNoticeNumber());
    }

    public function testStatutoryCaseKeepsNoPenaltyClause(): void
    {
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 day'), claim: new Step3ClaimData(
            amount: 1000.0,
            currency: 'RON',
            dueDate: new \DateTimeImmutable('-30 days'),
            relationshipType: RelationshipType::COMERCIAL,
            penaltyType: PenaltyType::LEGAL_PENALIZATOARE,
            penaltyClauseArticle: 'art. 7.2',
            penaltyClauseText: 'text rămas de la varianta contractuală',
            contractualAccessoryLabel: ContractualAccessoryLabel::MAJORARI_INTARZIERE,
            contractObject: 'prestarea de servicii de transport',
            paymentNoticeNumber: '   ',
        ));

        $case = $this->submitConfirmation();

        self::assertNull($case->getPenaltyClauseArticle());
        self::assertNull($case->getPenaltyClauseText());
        self::assertNull($case->getContractualAccessoryLabel());
        self::assertSame('prestarea de servicii de transport', $case->getContractObject());
        self::assertNull($case->getPaymentNoticeNumber(), 'A blank number is no number.');
    }

    private function submitConfirmation(): LegalCase
    {
        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $token = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');
        $this->client->request('POST', '/case/new/confirmation', [
            'step4_confirmation' => ['_token' => $token, 'acceptTerms' => '1', 'acceptDataAccuracy' => '1'],
        ]);
        $this->em->clear();

        $cases = $this->em->getRepository(LegalCase::class)->findBy(['user' => $this->user]);
        self::assertCount(1, $cases);

        return $cases[0];
    }

    public function testConfirmationSubmitHappyPathPersistsCaseDebtorAuditLogAndRedirects(): void
    {
        $this->primeSessionForStep4(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: new \DateTimeImmutable('-1 day'),
            insolvencyCheckedAt: new \DateTimeImmutable('-1 day'),
        );

        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $token = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/confirmation', [
            'step4_confirmation' => [
                '_token' => $token,
                'acceptTerms' => '1',
                'acceptDataAccuracy' => '1',
            ],
        ]);

        $this->em->clear();

        $cases = $this->em->getRepository(LegalCase::class)->findBy(['user' => $this->user]);
        self::assertCount(1, $cases);
        $case = $cases[0];

        // Redirect goes to overview page (NOT dashboard_cases) — UX decision to
        // land the lawyer on the newly-created case immediately. PLAN-DEZVOLTARE
        // Pas 3.2 specified dashboard_cases originally; revised here.
        self::assertResponseRedirects('/case/' . $case->getId());

        // Toast flash bag carries rich structure (key + params + details list)
        // so base.html.twig can render a multi-line toast on overview.
        $session = $this->client->getRequest()->getSession();
        $toastFlashes = $session->getFlashBag()->peek('toast.success');
        self::assertCount(1, $toastFlashes);
        self::assertIsArray($toastFlashes[0]);
        self::assertSame('wizard.step4.flash.success_toast', $toastFlashes[0]['key']);
        self::assertArrayHasKey('%caseNumber%', $toastFlashes[0]['params']);
        self::assertCount(2, $toastFlashes[0]['details']);

        // One-time „Următorul pas" hint on overview hero CTA.
        self::assertNotEmpty($session->getFlashBag()->peek('case_just_created'));

        self::assertSame('1000.00', $case->getAmount());
        self::assertSame('RON', $case->getCurrency());
        self::assertSame(RelationshipType::COMERCIAL, $case->getRelationshipType());
        self::assertNotNull($case->getCreditor(), 'Creditor must be linked');

        $debtors = $this->em->getRepository(LegalCaseDebtor::class)->findBy(['legalCase' => $case]);
        self::assertCount(1, $debtors);
        self::assertSame('Acme Debtor SRL', $debtors[0]->getName());

        $auditLogs = $this->em->getRepository(AuditLog::class)->findBy([
            'category' => AuditLogService::CATEGORY_WIZARD_SUBMIT,
            'user' => $this->user,
        ]);
        self::assertCount(1, $auditLogs);
        $newData = $auditLogs[0]->getNewData();
        self::assertArrayHasKey('fields_auto', $newData);
        self::assertArrayHasKey('fields_manual', $newData);
        self::assertArrayHasKey('extractedDocIds', $newData);
        self::assertArrayHasKey('admissibility_warnings', $newData);
    }

    public function testOverviewAfterWizardSubmitRendersJustCreatedBadgeOnce(): void
    {
        // Regression for the wizard → overview UX flow: the first GET on
        // /case/{id} after wizard submit must show the „Următorul pas" badge
        // (flash bag consumed); subsequent F5 must drop it.
        $this->primeSessionForStep4(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: new \DateTimeImmutable('-1 day'),
            insolvencyCheckedAt: new \DateTimeImmutable('-1 day'),
        );

        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $token = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/confirmation', [
            'step4_confirmation' => [
                '_token' => $token,
                'acceptTerms' => '1',
                'acceptDataAccuracy' => '1',
            ],
        ]);

        // Follow redirect → first GET on overview. Badge must be rendered.
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $firstHtml = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Următorul pas', $firstHtml, 'next-step badge must appear after wizard submit');
        self::assertStringContainsString('soft-pulse-once', $firstHtml, 'CTA must carry soft-pulse-once animation class');

        // Second GET on same overview → badge gone (flash bag was consumed).
        $cases = $this->em->getRepository(LegalCase::class)->findBy(['user' => $this->user]);
        self::assertCount(1, $cases);
        $this->client->request('GET', '/case/' . $cases[0]->getId());
        self::assertResponseIsSuccessful();
        $secondHtml = $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Următorul pas', $secondHtml);
        self::assertStringNotContainsString('soft-pulse-once', $secondHtml);
    }

    public function testConfirmationSubmitReusesExistingCreditorOnUserCuiUnique(): void
    {
        // Pre-existing creditor for the user on the same CUI.
        $existing = new Creditor();
        $existing->setUser($this->user);
        $existing->setPersonType(PersonType::PJ);
        $existing->setName('Acme Creditor SRL');
        $existing->setAddress('Str. Exemplu nr. 1, București');
        $existing->setCui('RO15193236');
        $this->em->persist($existing);
        $this->em->flush();
        $existingId = $existing->getId();

        $this->primeSessionForStep4(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: new \DateTimeImmutable('-1 day'),
            insolvencyCheckedAt: new \DateTimeImmutable('-1 day'),
        );

        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $token = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/confirmation', [
            'step4_confirmation' => [
                '_token' => $token,
                'acceptTerms' => '1',
                'acceptDataAccuracy' => '1',
            ],
        ]);

        $this->em->clear();
        $creditors = $this->em->getRepository(Creditor::class)->findBy(['user' => $this->user]);
        self::assertCount(1, $creditors, 'No duplicate creditor on UNIQUE(user, cui) reuse');
        self::assertSame($existingId, $creditors[0]->getId());

        $cases = $this->em->getRepository(LegalCase::class)->findBy(['user' => $this->user]);
        self::assertCount(1, $cases);
        self::assertResponseRedirects('/case/' . $cases[0]->getId());
    }

    /**
     * A creditor typed at step 1 whose company reached the library with other
     * data is not written over silently at submission: the lawyer goes back to
     * step 1 and chooses which data stands.
     */
    public function testACreditorFoundInTheLibraryWithOtherDataSendsTheLawyerBackToChoose(): void
    {
        $this->libraryCreditor('Acme Creditor SRL', 'RO15193236', 'Str. Veche nr. 9, Cluj-Napoca');
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 day'));

        $this->client->request('GET', '/case/new/confirmation');

        self::assertResponseRedirects('/case/new/creditor');
        $this->client->request('GET', '/case/new/creditor');
        self::assertSelectorExists('[data-testid="creditor-library-recheck"]');
    }

    public function testChoosingToUpdateTheLibraryCreditorWritesTheStepData(): void
    {
        $existing = $this->libraryCreditor('Acme Creditor SRL', 'RO15193236', 'Str. Veche nr. 9, Cluj-Napoca');
        $fields = [
            'personType' => PersonType::PJ->value,
            'name' => 'Acme Creditor SRL',
            'cui' => '15193236',
            'onrcNumber' => 'J40/1234/2018',
            'address' => 'Strada Răsăritului, Nr. 5, cod poștal 061202',
        ];

        $this->postCreditorStep($fields);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-testid="creditor-library-differs"] input[name="library_creditor_choice"][value="update"]');

        $this->postCreditorStepWithChoice($fields, 'update');
        self::assertResponseRedirects('/case/new/debtor');
        $dto = $this->storedCreditorDto();
        self::assertSame($existing->getId(), $dto->creditorId);
        self::assertTrue($dto->updateLibrary);

        $this->submitWithCreditor($dto);

        $this->em->clear();
        self::assertSame('Strada Răsăritului, Nr. 5, cod poștal 061202', $this->em->find(Creditor::class, $existing->getId())->getAddress());
        self::assertCount(1, $this->em->getRepository(Creditor::class)->findBy(['user' => $this->user]), 'no second row for another spelling of the CUI');
        $audit = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'creditor_updated', 'entityId' => (string) $existing->getId()]);
        self::assertSame('Str. Veche nr. 9, Cluj-Napoca', $audit->getOldData()['address']);
        $submit = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'wizard_submit', 'user' => $this->user]);
        self::assertEquals(['creditorId' => $existing->getId(), 'source' => 'library_updated'], $submit->getNewData()['creditor']);
    }

    /** Keeping the library data never blanks what the step leaves empty. */
    public function testChoosingTheLibraryCreditorKeepsItsData(): void
    {
        $existing = $this->libraryCreditor('Acme Creditor SRL', 'RO15193236', 'Str. Veche nr. 9, Cluj-Napoca');
        $existing->setIban('RO49AAAA1B31007593840000');
        $existing->setEmail('contact@acme.test');
        $this->em->flush();
        $fields = [
            'personType' => PersonType::PJ->value,
            'name' => 'Acme Creditor SRL',
            'cui' => 'RO15193236',
            'onrcNumber' => 'J40/1234/2018',
            'address' => 'Str. Nouă nr. 1, București',
        ];

        $this->postCreditorStepWithChoice($fields, 'library');
        self::assertResponseRedirects('/case/new/debtor');
        $this->submitWithCreditor($this->storedCreditorDto());

        $this->em->clear();
        $reloaded = $this->em->find(Creditor::class, $existing->getId());
        self::assertSame('Str. Veche nr. 9, Cluj-Napoca', $reloaded->getAddress());
        self::assertSame('RO49AAAA1B31007593840000', $reloaded->getIban());
        self::assertSame('contact@acme.test', $reloaded->getEmail());
        self::assertSame('J40/1234/2018', $reloaded->getOnrcNumber(), 'an empty library field is completed');
    }

    public function testASummonedCreditorIsNotUpdatedFromTheWizard(): void
    {
        $existing = $this->libraryCreditor('Acme Creditor SRL', 'RO15193236', 'Str. Veche nr. 9, Cluj-Napoca');
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setCreditor($existing);
        $case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->em->persist($case);
        $this->em->flush();
        $fields = [
            'personType' => PersonType::PJ->value,
            'name' => 'Acme Creditor SRL',
            'cui' => 'RO15193236',
            'onrcNumber' => 'J40/1234/2018',
            'address' => 'Str. Nouă nr. 1, București',
        ];

        $this->postCreditorStepWithChoice($fields, 'update');

        self::assertResponseStatusCodeSame(422, 'a forged update choice is not accepted');
        self::assertSelectorNotExists('input[name="library_creditor_choice"][value="update"]');
    }

    public function testACreditorTypedWithTheLibraryDataIsLinkedWithoutAsking(): void
    {
        $existing = $this->libraryCreditor('Acme Creditor SRL', 'RO15193236', 'Str. Veche nr. 9, Cluj-Napoca');

        $this->postCreditorStep([
            'personType' => PersonType::PJ->value,
            'name' => 'Acme Creditor SRL',
            'cui' => ' ro 15193236 ',
            'onrcNumber' => 'J40/1234/2018',
            'address' => 'Str. Veche nr. 9, Cluj-Napoca',
        ]);

        self::assertResponseRedirects('/case/new/debtor');
        self::assertSame($existing->getId(), $this->storedCreditorDto()->creditorId);
    }

    public function testReturningToStep1AfterChoosingUpdateAsksAgainInsteadOfDroppingTheData(): void
    {
        $this->libraryCreditor('Acme Creditor SRL', 'RO15193236', 'Str. Veche nr. 9, Cluj-Napoca');
        $fields = [
            'personType' => PersonType::PJ->value,
            'name' => 'Acme Creditor SRL',
            'cui' => 'RO15193236',
            'onrcNumber' => 'J40/1234/2018',
            'address' => 'Str. Nouă nr. 1, București',
        ];
        $this->postCreditorStepWithChoice($fields, 'update');
        self::assertResponseRedirects('/case/new/debtor');

        $crawler = $this->client->request('GET', '/case/new/creditor');
        self::assertSame('Str. Nouă nr. 1, București', $crawler->filter('textarea[name="step1_creditor[address]"]')->text());
        self::assertCount(0, $crawler->filter('select[name="step1_creditor[creditorEntity]"] option[selected]'));

        $this->postCreditorStep($fields);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-testid="creditor-library-differs"]');
    }

    private function libraryCreditor(string $name, string $cui, string $address): Creditor
    {
        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName($name);
        $creditor->setCui($cui);
        $creditor->setAddress($address);
        $this->em->persist($creditor);
        $this->em->flush();

        return $creditor;
    }

    /** @param array<string, string> $fields */
    private function postCreditorStepWithChoice(array $fields, string $choice): void
    {
        $crawler = $this->client->request('GET', '/case/new/creditor');
        $token = $crawler->filter('form input[name="step1_creditor[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/creditor', [
            'step1_creditor' => ['_token' => $token, ...$fields],
            'library_creditor_choice' => $choice,
        ]);
    }

    /** Runs steps 2 to 4 on the prepared session, keeping this step 1 creditor. */
    private function submitWithCreditor(Step1CreditorData $creditor): void
    {
        $this->primeSessionForStep4(insolvencyCheckedAt: new \DateTimeImmutable('-1 day'));
        $session = $this->client->getRequest()->getSession();
        $bag = $session->get(self::SESSION_KEY);
        $bag['creditor'] = $creditor;
        $session->set(self::SESSION_KEY, $bag);
        $session->save();

        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $token = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');
        $this->client->request('POST', '/case/new/confirmation', ['step4_confirmation' => [
            '_token' => $token,
            'acceptTerms' => '1',
            'acceptDataAccuracy' => '1',
        ]]);
    }

    public function testConfirmationGetBlocksWhenInsolvencyNotVerified(): void
    {
        // PJ debtor with insolvencyCheckedAt=null → OP_INSOLVENCY_NOT_VERIFIED (ERROR).
        $this->primeSessionForStep4(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: new \DateTimeImmutable('-1 day'),
            insolvencyCheckedAt: null,
        );

        $this->client->request('GET', '/case/new/confirmation');

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        // Translated message key for OP_INSOLVENCY_NOT_VERIFIED — assert on
        // the validation key surface (we don't depend on the exact RO copy).
        self::assertStringContainsString('Cererea NU poate fi depusă', $html);
    }

    public function testConfirmationSubmitAttachesPendingDocumentsToCase(): void
    {
        $doc = new Document();
        $doc->setDocumentType(DocumentType::ALT_DOCUMENT);
        $doc->setOriginalFilename('demo.pdf');
        $doc->setStoredFilename('cases/_pending/9/demo.pdf');
        $doc->setFileSize(1);
        $doc->setMimeType('application/pdf');
        $doc->setUploadedBy($this->user);
        $doc->setExtractionStatus(ExtractionStatus::COMPLETED);
        $this->em->persist($doc);
        $this->em->flush();
        $docId = $doc->getId();

        $this->primeSessionForStep4(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: new \DateTimeImmutable('-1 day'),
            insolvencyCheckedAt: new \DateTimeImmutable('-1 day'),
            documentIds: [$docId],
        );

        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $token = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/confirmation', [
            'step4_confirmation' => [
                '_token' => $token,
                'acceptTerms' => '1',
                'acceptDataAccuracy' => '1',
            ],
        ]);

        $this->em->clear();
        $fresh = $this->em->find(Document::class, $docId);
        self::assertNotNull($fresh->getLegalCase(), 'Document must be attached to the new LegalCase');
        self::assertResponseRedirects('/case/' . $fresh->getLegalCase()->getId());
    }

    public function testConfirmationSubmitResolvesAndPersistsCompetentCourt(): void
    {
        $this->ensureBnrRate();
        $token = (string) uniqid();
        $county = 'Wiztestjud' . $token;
        $locality = 'Wiztestloc' . $token;
        $court = $this->seedJudecatorie($county, $locality);

        $this->primeSessionForStep4(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: new \DateTimeImmutable('-1 day'),
            insolvencyCheckedAt: new \DateTimeImmutable('-1 day'),
            addressCounty: $county,
            addressLocality: $locality,
        );

        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $tokenField = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/confirmation', [
            'step4_confirmation' => [
                '_token' => $tokenField,
                'acceptTerms' => '1',
                'acceptDataAccuracy' => '1',
            ],
        ]);

        $this->em->clear();
        $cases = $this->em->getRepository(LegalCase::class)->findBy(['user' => $this->user]);
        self::assertCount(1, $cases);
        self::assertNotNull($cases[0]->getCourt(), 'Competent court must be auto-resolved and persisted');
        self::assertSame($court->getId(), $cases[0]->getCourt()->getId());

        $audit = $this->em->getRepository(AuditLog::class)->findOneBy([
            'category' => AuditLogService::CATEGORY_WIZARD_SUBMIT,
            'user' => $this->user,
        ]);
        self::assertSame('auto', $audit->getNewData()['court_resolution']);
        self::assertSame($court->getId(), $audit->getNewData()['court_id']);
    }

    public function testConfirmationSubmitLeavesCourtNullWhenLocalityUnmatched(): void
    {
        $this->ensureBnrRate();
        $token = (string) uniqid();
        $county = 'Wiztestjud' . $token;
        // Seed a judecătorie in the county but covering a DIFFERENT locality, so
        // the debtor's locality finds no match → resolver returns court=null,
        // submit still proceeds (per spec C5).
        $this->seedJudecatorie($county, 'Wiztestloc' . $token);

        $this->primeSessionForStep4(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: new \DateTimeImmutable('-1 day'),
            insolvencyCheckedAt: new \DateTimeImmutable('-1 day'),
            addressCounty: $county,
            addressLocality: 'Wiztest Localitate Fara Acoperire',
        );

        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $tokenField = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/confirmation', [
            'step4_confirmation' => [
                '_token' => $tokenField,
                'acceptTerms' => '1',
                'acceptDataAccuracy' => '1',
            ],
        ]);

        $this->em->clear();
        $cases = $this->em->getRepository(LegalCase::class)->findBy(['user' => $this->user]);
        self::assertCount(1, $cases, 'Case must still persist when court is undetermined');
        self::assertNull($cases[0]->getCourt(), 'Court must remain null when locality is unmatched');

        $audit = $this->em->getRepository(AuditLog::class)->findOneBy([
            'category' => AuditLogService::CATEGORY_WIZARD_SUBMIT,
            'user' => $this->user,
        ]);
        self::assertSame('none', $audit->getNewData()['court_resolution']);
        self::assertNull($audit->getNewData()['court_id']);
    }

    public function testConfirmationSubmitIgnoresInactiveCourtId(): void
    {
        // The court id is a free hidden value fed by the Tom Select picker; the
        // controller loads it and rejects a non-active court (defends against a
        // tampered id). With no county the case is filed with court undetermined.
        $token = (string) uniqid();
        $county = $this->createCounty($this->em, 'Wiztestjud' . $token);
        $inactive = new Court();
        $inactive->setName('Wiztest Judecătoria Inactiva ' . $token);
        $inactive->setCounty($county);
        $inactive->setType(CourtType::JUDECATORIE);
        $inactive->setActive(false);
        $this->em->persist($inactive);
        $this->em->flush();
        $inactiveId = $inactive->getId();

        $this->primeSessionForStep4(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: new \DateTimeImmutable('-1 day'),
            insolvencyCheckedAt: new \DateTimeImmutable('-1 day'),
        );

        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $tokenField = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/confirmation', [
            'step4_confirmation' => [
                '_token' => $tokenField,
                'acceptTerms' => '1',
                'acceptDataAccuracy' => '1',
                'court' => (string) $inactiveId,
            ],
        ]);

        // Case is filed (hidden court id is optional), but the inactive court is
        // rejected server-side → not persisted on the case.
        $this->em->clear();
        $cases = $this->em->getRepository(LegalCase::class)->findBy(['user' => $this->user]);
        self::assertCount(1, $cases, 'Case still persists');
        self::assertNull($cases[0]->getCourt(), 'Inactive court id must not be persisted');
    }

    public function testConfirmationSubmitPersistsManuallyPickedCourtWhenAutoUnresolved(): void
    {
        $token = (string) uniqid();
        $court = $this->seedJudecatorie('Wiztestjud' . $token, 'Wiztestloc' . $token);

        // No addressCounty → auto resolution yields county_unknown (court=null);
        // the user picks the court manually via the step-4 autocomplete.
        $this->primeSessionForStep4(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: new \DateTimeImmutable('-1 day'),
            insolvencyCheckedAt: new \DateTimeImmutable('-1 day'),
        );

        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $tokenField = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/confirmation', [
            'step4_confirmation' => [
                '_token' => $tokenField,
                'acceptTerms' => '1',
                'acceptDataAccuracy' => '1',
                'court' => (string) $court->getId(),
            ],
        ]);

        $this->em->clear();
        $cases = $this->em->getRepository(LegalCase::class)->findBy(['user' => $this->user]);
        self::assertCount(1, $cases);
        self::assertNotNull($cases[0]->getCourt(), 'Manually picked court must be persisted');
        self::assertSame($court->getId(), $cases[0]->getCourt()->getId());

        $audit = $this->em->getRepository(AuditLog::class)->findOneBy([
            'category' => AuditLogService::CATEGORY_WIZARD_SUBMIT,
            'user' => $this->user,
        ]);
        self::assertSame('manual', $audit->getNewData()['court_resolution']);
        self::assertSame($court->getId(), $audit->getNewData()['court_id']);
    }

    public function testConfirmationSubmitManualCourtOverridesAutoResolution(): void
    {
        $this->ensureBnrRate();
        $token = (string) uniqid();
        $county = 'Wiztestjud' . $token;
        $locality = 'Wiztestloc' . $token;
        $this->seedJudecatorie($county, $locality);                  // auto-resolves to this
        $override = $this->seedJudecatorie('Wiztestjudb' . $token, 'Wiztestlocb' . $token);

        $this->primeSessionForStep4(
            personType: PersonType::PJ,
            anafStatus: AnafStatus::ACTIV,
            anafCheckedAt: new \DateTimeImmutable('-1 day'),
            insolvencyCheckedAt: new \DateTimeImmutable('-1 day'),
            addressCounty: $county,
            addressLocality: $locality,
        );

        $crawler = $this->client->request('GET', '/case/new/confirmation');
        $tokenField = $crawler->filter('form input[name="step4_confirmation[_token]"]')->first()->attr('value');

        $this->client->request('POST', '/case/new/confirmation', [
            'step4_confirmation' => [
                '_token' => $tokenField,
                'acceptTerms' => '1',
                'acceptDataAccuracy' => '1',
                'court' => (string) $override->getId(),
            ],
        ]);

        $this->em->clear();
        $cases = $this->em->getRepository(LegalCase::class)->findBy(['user' => $this->user]);
        self::assertCount(1, $cases);
        self::assertSame($override->getId(), $cases[0]->getCourt()->getId(), 'Manual override must beat auto resolution');

        $audit = $this->em->getRepository(AuditLog::class)->findOneBy([
            'category' => AuditLogService::CATEGORY_WIZARD_SUBMIT,
            'user' => $this->user,
        ]);
        self::assertSame('manual', $audit->getNewData()['court_resolution']);
    }

    private function seedJudecatorie(string $countyName, string $cityName): Court
    {
        $county = $this->createCounty($this->em, $countyName);

        $city = new City();
        $city->setCounty($county);
        $city->setName($cityName);
        $city->setNormalizedName(LocalityNormalizer::normalize($cityName) ?? $cityName);
        $this->em->persist($city);

        $court = new Court();
        $court->setName('Wiztest Judecătoria ' . $cityName);
        $court->setCounty($county);
        $court->setType(CourtType::JUDECATORIE);
        $court->setActive(true);
        $court->addCoveredCity($city);
        $this->em->persist($court);
        $this->em->flush();

        return $court;
    }

    /**
     * Ensure a BNR reference rate exists for the (-30 days) due date so the
     * resolver's internal interest calc does not throw. Find-or-create avoids
     * the unique(validFrom) collision across runs (tearDown keeps rate data).
     */
    private function ensureBnrRate(): void
    {
        $repo = $this->em->getRepository(InterestRateConfig::class);
        if ($repo->findRateValidAt(new \DateTimeImmutable('-30 days')) !== null) {
            return;
        }

        $config = new InterestRateConfig();
        $config->setValidFrom(new \DateTimeImmutable('2000-01-01'));
        $config->setReferenceRate('7.00');
        $this->em->persist($config);
        $this->em->flush();
    }

    /**
     * @param list<int> $documentIds
     */
    private function primeSessionForStep4(
        PersonType $personType = PersonType::PJ,
        ?AnafStatus $anafStatus = AnafStatus::ACTIV,
        ?\DateTimeImmutable $anafCheckedAt = null,
        ?\DateTimeImmutable $insolvencyCheckedAt = null,
        array $documentIds = [],
        ?string $addressCounty = null,
        ?string $addressLocality = null,
        ?string $contractReference = null,
        string $creditorAddress = 'Str. Exemplu nr. 1, București',
        ?string $creditorAddressCounty = null,
        ?string $creditorAddressLocality = null,
        ?Step3ClaimData $claim = null,
    ): void {
        $anafCheckedAt ??= new \DateTimeImmutable('-1 day');

        $creditor = new Step1CreditorData(
            personType: PersonType::PJ,
            name: 'Acme Creditor SRL',
            cui: 'RO15193236',
            address: $creditorAddress,
            addressCounty: $creditorAddressCounty,
            addressLocality: $creditorAddressLocality,
        );

        $debtor = new Step2DebtorEntry(
            personType: $personType,
            name: 'Acme Debtor SRL',
            cui: 'RO14186770',
            address: 'Str. Debitor nr. 2, Cluj-Napoca',
            addressCounty: $addressCounty,
            addressLocality: $addressLocality,
            anafStatus: $anafStatus,
            anafCheckedAt: $anafCheckedAt,
            insolvencyCheckedAt: $insolvencyCheckedAt,
        );

        $claim ??= new Step3ClaimData(
            amount: 1000.0,
            currency: 'RON',
            dueDate: new \DateTimeImmutable('-30 days'),
            relationshipType: RelationshipType::COMERCIAL,
            contractReference: $contractReference,
        );

        // Prime session via the KernelBrowser. We use a direct request first
        // to ensure the session cookie is set, then mutate the session bag and
        // persist it back.
        $this->client->request('GET', '/case/new/documents');
        $session = $this->client->getRequest()->getSession();
        $session->set(self::SESSION_KEY, [
            'documentIds' => $documentIds,
            'creditor' => $creditor,
            'debtors' => new Step2DebtorsData([$debtor]),
            'claim' => $claim,
        ]);
        $session->save();
    }

    /**
     * Primes session for /claim GET (creditor + debtors only — claim left null
     * so the form renders empty and the user can POST an invalid payload).
     */
    private function primeSessionForClaimStep(): bool
    {
        $creditor = new Step1CreditorData(
            personType: PersonType::PJ,
            name: 'Acme Creditor SRL',
            cui: 'RO15193236',
            onrcNumber: 'J40/1234/2018',
            address: 'Str. Exemplu nr. 1, București',
        );
        $debtor = new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'Acme Debtor SRL',
            cui: 'RO14186770',
            onrcNumber: 'J40/8765/2019',
            address: 'Str. Debitor nr. 2, Cluj-Napoca',
        );

        $this->client->request('GET', '/case/new/documents');
        $session = $this->client->getRequest()->getSession();
        $session->set(self::SESSION_KEY, [
            'documentIds' => [],
            'creditor' => $creditor,
            'debtors' => new Step2DebtorsData([$debtor]),
            'claim' => null,
        ]);
        $session->save();

        return true;
    }
}
