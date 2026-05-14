<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\AuditLog;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\AnafStatus;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Service\AuditLogService;
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
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);

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

        self::assertResponseRedirects('/dashboard/cases');

        $this->em->clear();

        $cases = $this->em->getRepository(LegalCase::class)->findBy(['user' => $this->user]);
        self::assertCount(1, $cases);
        $case = $cases[0];
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

        self::assertResponseRedirects('/dashboard/cases');

        $this->em->clear();
        $creditors = $this->em->getRepository(Creditor::class)->findBy(['user' => $this->user]);
        self::assertCount(1, $creditors, 'No duplicate creditor on UNIQUE(user, cui) reuse');
        self::assertSame($existingId, $creditors[0]->getId());
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

        self::assertResponseRedirects('/dashboard/cases');

        $this->em->clear();
        $fresh = $this->em->find(Document::class, $docId);
        self::assertNotNull($fresh->getLegalCase(), 'Document must be attached to the new LegalCase');
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
    ): void {
        $anafCheckedAt ??= new \DateTimeImmutable('-1 day');

        $creditor = new Step1CreditorData(
            personType: PersonType::PJ,
            name: 'Acme Creditor SRL',
            cui: 'RO15193236',
            address: 'Str. Exemplu nr. 1, București',
        );

        $debtor = new Step2DebtorEntry(
            personType: $personType,
            name: 'Acme Debtor SRL',
            cui: 'RO14186770',
            address: 'Str. Debitor nr. 2, Cluj-Napoca',
            anafStatus: $anafStatus,
            anafCheckedAt: $anafCheckedAt,
            insolvencyCheckedAt: $insolvencyCheckedAt,
        );

        $claim = new Step3ClaimData(
            amount: 1000.0,
            currency: 'RON',
            dueDate: new \DateTimeImmutable('-30 days'),
            relationshipType: RelationshipType::COMERCIAL,
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
}
