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
use App\Entity\Document;
use App\Entity\InterestRateConfig;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\AnafStatus;
use App\Enum\CourtType;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
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
        $conn->executeStatement('DELETE FROM debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
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
        // PF debtor → emits OP_PF_BIPF_MANUAL_CHECK (WARNING). No ERROR.
        $this->primeSessionForStep4(personType: PersonType::PF, anafStatus: null);

        $this->client->request('GET', '/case/new/confirmation');

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Atenție', $html);
        self::assertStringContainsString('acknowledgedWarnings', $html);
    }

    public function testConfirmationSubmitRespectsAcknowledgeRequirementOnWarningPath(): void
    {
        $this->primeSessionForStep4(personType: PersonType::PF, anafStatus: null);
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

        $debtors = $this->em->getRepository(Debtor::class)->findBy(['legalCase' => $case]);
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
     * The lawyer synced the creditor from ANAF, and only then did the CUI turn
     * out to match one already in their library. Before, everything but a
     * missing county/locality was dropped on save: the wizard showed the fresh
     * address, the case kept the stale one, and the somaţie went to the old
     * registered office.
     */
    public function testConfirmationSubmitRefreshesAReusedCreditorWithTheSyncedData(): void
    {
        $existing = new Creditor();
        $existing->setUser($this->user);
        $existing->setPersonType(PersonType::PJ);
        $existing->setName('Acme Creditor SRL');
        $existing->setAddress('Str. Veche nr. 9, Cluj-Napoca');
        $existing->setCui('RO15193236');
        $this->em->persist($existing);
        $this->em->flush();
        $existingId = $existing->getId();

        $this->primeSessionForStep4(
            anafCheckedAt: new \DateTimeImmutable('-1 day'),
            insolvencyCheckedAt: new \DateTimeImmutable('-1 day'),
            creditorAddress: 'Strada Răsăritului, Nr. 5, Bloc 4C, Scara A, Etaj 3, Ap. 12, cod poștal 061202',
            creditorAddressCounty: 'București',
            creditorAddressLocality: 'Sector 6',
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
        $refreshed = $this->em->getRepository(Creditor::class)->find($existingId);
        self::assertSame(
            'Strada Răsăritului, Nr. 5, Bloc 4C, Scara A, Etaj 3, Ap. 12, cod poștal 061202',
            $refreshed->getAddress(),
        );
        self::assertSame('București', $refreshed->getAddressCounty());
        self::assertSame('Sector 6', $refreshed->getAddressLocality());

        // The creditor is shared with the lawyer's other cases, so the change
        // has to be reconstructable months later.
        $audit = $this->em->getRepository(AuditLog::class)->findOneBy([
            'action' => 'creditor_refreshed',
            'entityId' => (string) $existingId,
        ]);
        self::assertNotNull($audit);
        self::assertSame('Str. Veche nr. 9, Cluj-Napoca', $audit->getNewData()['address']['from']);
    }

    /** A value the register does not carry must not blank a curated one. */
    public function testConfirmationSubmitDoesNotBlankAReusedCreditorFieldTheFormLeftEmpty(): void
    {
        $existing = new Creditor();
        $existing->setUser($this->user);
        $existing->setPersonType(PersonType::PJ);
        $existing->setName('Acme Creditor SRL');
        $existing->setAddress('Str. Veche nr. 9, Cluj-Napoca');
        $existing->setCui('RO15193236');
        $existing->setIban('RO49AAAA1B31007593840000');
        $existing->setEmail('contact@acme.test');
        $this->em->persist($existing);
        $this->em->flush();
        $existingId = $existing->getId();

        $this->primeSessionForStep4(
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
        $refreshed = $this->em->getRepository(Creditor::class)->find($existingId);
        self::assertSame('RO49AAAA1B31007593840000', $refreshed->getIban());
        self::assertSame('contact@acme.test', $refreshed->getEmail());
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

        $claim = new Step3ClaimData(
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
