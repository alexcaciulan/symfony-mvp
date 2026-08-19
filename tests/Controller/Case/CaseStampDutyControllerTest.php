<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CourtType;
use App\Enum\DeadlineConsequence;
use App\Enum\DeadlineType;
use App\Enum\DocumentType;
use App\Enum\PersonType;
use App\Enum\StampDutyStatus;
use App\Service\Deadline\DeadlineConsequenceResolver;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Stamp duty (OUG 80/2013): proof of payment, the deliberate deferral to the court's
 * regularization procedure, and the 10-day term that follows the court's notice.
 */
final class CaseStampDutyControllerTest extends WebTestCase
{
    use CountyFixtureTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;
    private string $uploadsDir;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('stampduty-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);

        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Creditor Timbru SRL');
        $creditor->setAddress('Str. Creditor 1, Cluj-Napoca');
        $creditor->setAddressCounty('Cluj');
        $creditor->setAddressLocality('Cluj-Napoca');
        $creditor->setCui('RO12345678');
        $this->em->persist($creditor);

        $court = new Court();
        $court->setName('Judecătoria Timbru Test');
        $court->setCounty($this->createCounty($this->em, 'Cluj'));
        $court->setType(CourtType::JUDECATORIE);
        $court->setActive(true);
        $this->em->persist($court);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setCreditor($creditor);
        $this->case->setCourt($court);
        $this->case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->case->setAmount('5000.00');
        $this->case->setCurrency('RON');
        $this->case->setStampDuty('200.00');
        $this->case->setDueDate(new \DateTime('2024-06-15'));
        $this->em->persist($this->case);

        $debtor = new Debtor();
        $debtor->setLegalCase($this->case);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Debitor Timbru SRL');
        $debtor->setAddress('Str. Debitor 2, Cluj-Napoca');
        $debtor->setCui('RO87654321');
        $this->em->persist($debtor);
        $this->case->addDebtor($debtor);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $caseId = $this->case->getId();

        $caseDir = $this->uploadsDir . '/cases/' . $caseId;
        if (is_dir($caseDir)) {
            foreach (glob($caseDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($caseDir);
        }

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id = ?', [$caseId]);
        $conn->executeStatement('DELETE FROM document WHERE legal_case_id = ?', [$caseId]);
        $conn->executeStatement('DELETE FROM case_status_history WHERE legal_case_id = ?', [$caseId]);
        $conn->executeStatement('DELETE FROM debtor WHERE legal_case_id = ?', [$caseId]);
        $conn->executeStatement('DELETE FROM notification WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM court WHERE name LIKE ?', ['Judecătoria Timbru Test%']);
        $conn->executeStatement('DELETE FROM user WHERE id = ?', [$userId]);

        parent::tearDown();
    }

    private function proofFile(): UploadedFile
    {
        $path = sys_get_temp_dir() . '/dovada-' . uniqid() . '.pdf';
        file_put_contents($path, '%PDF-1.4 dovada plata taxa timbru');

        return new UploadedFile($path, 'dovada.pdf', 'application/pdf', null, true);
    }

    /**
     * Read the CSRF token from the rendered page rather than minting one out of the
     * container: the token storage is session-bound, and the client's session is the
     * one that matters here.
     */
    private function csrfToken(string $actionSuffix, string $inputName): string
    {
        $this->client->request('GET', '/case/' . $this->case->getId());
        $input = $this->client->getCrawler()
            ->filter(sprintf('form[action$="%s"] input[name="%s"]', $actionSuffix, $inputName))
            ->first();

        return (string) $input->attr('value');
    }

    public function testUploadProofMarksTheDutyPaidAndSnapshotsTheUat(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/proof', [
            'stamp_duty_proof' => [
                '_token' => $this->csrfToken('/stamp-duty/proof', 'stamp_duty_proof[_token]'),
                'paidAt' => '2026-07-10',
                'paidAmount' => '200',
                'payerName' => 'SC Creditor Timbru SRL',
                'paymentReference' => 'OP 4471',
            ],
        ], [
            'stamp_duty_proof' => ['file' => $this->proofFile()],
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());

        self::assertSame(StampDutyStatus::ACHITATA, $refreshed->getStampDutyStatus());
        self::assertSame('2026-07-10', $refreshed->getStampDutyPaidAt()->format('Y-m-d'));
        self::assertSame('200.00', $refreshed->getStampDutyPaidAmount());
        self::assertSame('OP 4471', $refreshed->getStampDutyPaymentReference());
        // Snapshot of the town hall we advised: the creditor's office may move later,
        // and the account the duty landed in is what a dispute turns on.
        self::assertSame('Cluj-Napoca', $refreshed->getStampDutyUat());
        self::assertNotNull($refreshed->getStampDutyLawVersion());

        $documents = $this->em->getRepository(Document::class)->findBy(['legalCase' => $refreshed->getId()]);
        $types = array_map(static fn (Document $d): DocumentType => $d->getDocumentType(), $documents);
        self::assertContains(DocumentType::DOVADA_TAXA_TIMBRU, $types);
    }

    /**
     * Art. 40 alin. 3 presumes payment from an order signed by the debtor of the duty
     * (the claimant), so a proof in another name is surfaced. Advisory, not a block:
     * the client may legitimately have paid from a group account.
     */
    public function testProofWarnsWhenThePayerIsNotTheCreditor(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/proof', [
            'stamp_duty_proof' => [
                '_token' => $this->csrfToken('/stamp-duty/proof', 'stamp_duty_proof[_token]'),
                'paidAt' => '2026-07-10',
                'payerName' => 'Cabinet Avocat Popescu',
            ],
        ], [
            'stamp_duty_proof' => ['file' => $this->proofFile()],
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId());

        $flashes = $this->client->getRequest()->getSession()->getFlashBag()->peekAll();
        self::assertContains(
            'case_overview.stamp_duty.flash_warning_payer_mismatch',
            $flashes['warning'] ?? [],
            'A proof naming someone other than the claimant must be flagged.',
        );

        // Still recorded: the warning informs, it does not reject the payment.
        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(StampDutyStatus::ACHITATA, $refreshed->getStampDutyStatus());
    }

    /** A second proof would leave two competing documents in the filing package. */
    public function testProofIsRejectedWhenOneIsAlreadyOnTheCase(): void
    {
        $this->client->loginUser($this->user);

        // First upload succeeds and marks the duty paid.
        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/proof', [
            'stamp_duty_proof' => [
                '_token' => $this->csrfToken('/stamp-duty/proof', 'stamp_duty_proof[_token]'),
                'paidAt' => '2026-07-10',
            ],
        ], [
            'stamp_duty_proof' => ['file' => $this->proofFile()],
        ]);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/proof', [
            'stamp_duty_proof' => [
                '_token' => $this->csrfToken('/stamp-duty/proof', 'stamp_duty_proof[_token]'),
                'paidAt' => '2026-07-11',
            ],
        ], [
            'stamp_duty_proof' => ['file' => $this->proofFile()],
        ]);

        $this->em->clear();
        $documents = $this->em->getRepository(Document::class)->findBy([
            'legalCase' => $this->case->getId(),
            'documentType' => DocumentType::DOVADA_TAXA_TIMBRU,
        ]);
        self::assertCount(1, $documents, 'The package must not carry two competing proofs.');
    }

    /**
     * A payment confirmed through the electronic registry leaves the case paid with
     * no proof of our own. The receipt the lawyer obtains later must still be filable,
     * so the guard keys on the document rather than on the status.
     */
    public function testProofIsAcceptedAfterAPaymentConfirmedThroughTheRegistry(): void
    {
        $this->case->setStampDutyStatus(StampDutyStatus::ACHITATA);
        $this->em->flush();

        $this->client->loginUser($this->user);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/proof', [
            'stamp_duty_proof' => [
                '_token' => $this->csrfToken('/stamp-duty/proof', 'stamp_duty_proof[_token]'),
                'paidAt' => '2026-07-10',
            ],
        ], [
            'stamp_duty_proof' => ['file' => $this->proofFile()],
        ]);

        $this->em->clear();
        $documents = $this->em->getRepository(Document::class)->findBy([
            'legalCase' => $this->case->getId(),
            'documentType' => DocumentType::DOVADA_TAXA_TIMBRU,
        ]);
        self::assertCount(1, $documents);
    }

    /**
     * The recommended channel takes the duty in the filing form itself, so the package
     * has to be buildable before the money moves. Without this path the lawyer who
     * pays correctly had to claim a deferral to regularization the petition then
     * asserted to the court.
     */
    public function testDeclaringPaymentAtFilingUnblocksTheFilingGate(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/at-filing', [
            '_token' => $this->csrfToken('/stamp-duty/at-filing', '_token'),
        ]);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(StampDutyStatus::ACHITARE_LA_DEPUNERE, $refreshed->getStampDutyStatus());
        self::assertTrue($refreshed->getStampDutyStatus()->allowsFiling());
    }

    /** Confirming the registry payment needs no file: the portal already sent one. */
    public function testConfirmingTheRegistryPaymentMarksTheDutyPaidWithoutAFile(): void
    {
        $this->case->setStampDutyStatus(StampDutyStatus::ACHITARE_LA_DEPUNERE);
        $this->em->flush();

        $this->client->loginUser($this->user);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/registry-paid', [
            '_token' => $this->csrfToken('/stamp-duty/registry-paid', '_token'),
            'paidAt' => '2026-07-15',
            'paymentReference' => 'GH-9911',
        ]);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(StampDutyStatus::ACHITATA, $refreshed->getStampDutyStatus());
        self::assertSame('2026-07-15', $refreshed->getStampDutyPaidAt()->format('Y-m-d'));
        self::assertSame('GH-9911', $refreshed->getStampDutyPaymentReference());
        self::assertFalse($refreshed->hasStampDutyProof(), 'No file is expected on this path.');
    }

    /**
     * Lawyers commonly pay and re-invoice, so a name other than the claimant's is the
     * ordinary case. Declaring it keeps the record honest without firing an alert that
     * would appear on almost every file and stop being read.
     */
    public function testDeclaringPaymentOnBehalfReplacesTheWarningWithANotice(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/proof', [
            'stamp_duty_proof' => [
                '_token' => $this->csrfToken('/stamp-duty/proof', 'stamp_duty_proof[_token]'),
                'paidAt' => '2026-07-10',
                'payerName' => 'Cabinet de Avocat Ionescu',
                'payerOnBehalf' => '1',
            ],
        ], [
            'stamp_duty_proof' => ['file' => $this->proofFile()],
        ]);

        $flashes = $this->client->getRequest()->getSession()->getFlashBag()->peekAll();
        $keys = array_merge(...array_values($flashes));

        self::assertContains('case_overview.stamp_duty.flash_notice_payer_on_behalf', $keys);
        self::assertNotContains('case_overview.stamp_duty.flash_warning_payer_mismatch', $keys);
    }

    /** The column holds 100 characters, so a longer reference is refused, not truncated. */
    public function testRegistryPaymentRejectsAnOverlongReference(): void
    {
        $this->case->setStampDutyStatus(StampDutyStatus::ACHITARE_LA_DEPUNERE);
        $this->em->flush();

        $this->client->loginUser($this->user);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/registry-paid', [
            '_token' => $this->csrfToken('/stamp-duty/registry-paid', '_token'),
            'paidAt' => '2026-07-15',
            'paymentReference' => str_repeat('X', 101),
        ]);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(StampDutyStatus::ACHITARE_LA_DEPUNERE, $refreshed->getStampDutyStatus());
        self::assertNull($refreshed->getStampDutyPaymentReference());
    }

    /**
     * A notice can arrive on a case the lawyer never deferred. Since the duty is paid
     * when the file number appears rather than when the court asks, the ordinary
     * unstamped case is NEACHITATA, and a court that sends a notice anyway has to be
     * recordable: otherwise the ten days run nowhere in the application.
     */
    public function testCourtNoticeIsAcceptedOnACaseThatWasNeverDeferred(): void
    {
        $this->client->loginUser($this->user);

        self::assertSame(StampDutyStatus::NEACHITATA, $this->case->getStampDutyStatus());

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/court-notice', [
            '_token' => $this->csrfToken('/stamp-duty/court-notice', '_token'),
            'courtNoticeDate' => '2026-07-07',
        ]);

        $this->em->clear();
        $deadline = $this->em->getRepository(LegalDeadline::class)->findOneBy([
            'legalCase' => $this->case->getId(),
            'type' => DeadlineType::TIMBRARE,
        ]);
        self::assertNotNull($deadline, 'An unstamped case must be able to record the notice it received.');
    }

    /**
     * A paid case stays out: the ten days guard nothing there, and creating the term
     * would put a CRITICAL deadline on a case with no annulment risk to watch.
     */
    public function testCourtNoticeIsRejectedOnceTheDutyIsPaid(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->csrfToken('/stamp-duty/court-notice', '_token');

        $this->case->setStampDutyStatus(StampDutyStatus::ACHITATA);
        $this->em->flush();

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/court-notice', [
            '_token' => $token,
            'courtNoticeDate' => '2026-07-01',
        ]);

        $this->em->clear();
        $deadline = $this->em->getRepository(LegalDeadline::class)->findOneBy([
            'legalCase' => $this->case->getId(),
            'type' => DeadlineType::TIMBRARE,
        ]);
        self::assertNull($deadline);
    }

    public function testDeferIsRejectedWithAnInvalidCsrfToken(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/defer', [
            '_token' => 'forged',
            'deferralConsent' => '1',
        ]);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(StampDutyStatus::NEACHITATA, $refreshed->getStampDutyStatus());
    }

    public function testDeferMarksTheCaseForRegularization(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/defer', [
            '_token' => $this->csrfToken('/stamp-duty/defer', '_token'),
            'deferralConsent' => '1',
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId());

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(StampDutyStatus::AMANATA_REGULARIZARE, $refreshed->getStampDutyStatus());
    }

    /** The risk acknowledgement is the whole point of the escape hatch: no tick, no deferral. */
    public function testDeferIsRejectedWithoutTheRiskAcknowledgement(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/defer', [
            '_token' => $this->csrfToken('/stamp-duty/defer', '_token'),
        ]);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(StampDutyStatus::NEACHITATA, $refreshed->getStampDutyStatus());
    }

    /**
     * The 10-day term runs from the court's notice (OUG 80/2013 art. 33 alin. 2), a
     * date the platform cannot observe, so the lawyer supplies it and we compute the term.
     */
    /**
     * CPC art. 200 grants "cel mult 10 zile", so a court that gives fewer must be
     * recordable. The lawyer supplies the number off the notice; the free-day count
     * and the prorogation stay with the application, which is the whole point of
     * asking for days rather than for a date.
     */
    public function testCourtNoticeHonoursAShorterTermGrantedByTheCourt(): void
    {
        $this->case->setStampDutyStatus(StampDutyStatus::AMANATA_REGULARIZARE);
        $this->em->flush();

        $this->client->loginUser($this->user);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/court-notice', [
            '_token' => $this->csrfToken('/stamp-duty/court-notice', '_token'),
            'courtNoticeDate' => '2026-07-07',
            'grantedDays' => '5',
        ]);

        $this->em->clear();
        $deadline = $this->em->getRepository(LegalDeadline::class)->findOneBy([
            'legalCase' => $this->case->getId(),
            'type' => DeadlineType::TIMBRARE,
        ]);

        self::assertNotNull($deadline);
        // Tuesday 2026-07-07 plus 5 free days matures on Monday 2026-07-13: the count
        // is 5 + 1 per CPC art. 181 alin. 1 pct. 2, landing on Sunday 2026-07-12, then
        // prorogated to the next working day.
        self::assertSame('2026-07-13', $deadline->getDeadlineDate()->format('Y-m-d'));
    }

    public function testCourtNoticeRefusesATermLongerThanTheLegalCeiling(): void
    {
        $this->case->setStampDutyStatus(StampDutyStatus::AMANATA_REGULARIZARE);
        $this->em->flush();

        $this->client->loginUser($this->user);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/court-notice', [
            '_token' => $this->csrfToken('/stamp-duty/court-notice', '_token'),
            'courtNoticeDate' => '2026-07-07',
            'grantedDays' => '15',
        ]);

        self::assertSame(
            ['case_overview.stamp_duty.flash_error_granted_days_invalid'],
            $this->client->getRequest()->getSession()->getFlashBag()->peek('error'),
        );

        $this->em->clear();
        self::assertNull($this->em->getRepository(LegalDeadline::class)->findOneBy([
            'legalCase' => $this->case->getId(),
            'type' => DeadlineType::TIMBRARE,
        ]), 'A refused term must not leave a deadline behind.');
    }

    public function testCourtNoticeCreatesTheTenDayStampingDeadline(): void
    {
        $this->case->setStampDutyStatus(StampDutyStatus::AMANATA_REGULARIZARE);
        $this->em->flush();

        $this->client->loginUser($this->user);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/court-notice', [
            '_token' => $this->csrfToken('/stamp-duty/court-notice', '_token'),
            'courtNoticeDate' => '2026-07-07',
        ]);

        self::assertResponseRedirects('/case/' . $this->case->getId());

        $this->em->clear();
        $deadline = $this->em->getRepository(LegalDeadline::class)->findOneBy([
            'legalCase' => $this->case->getId(),
            'type' => DeadlineType::TIMBRARE,
        ]);

        self::assertNotNull($deadline, 'Deferring without a deadline would leave the annulment risk unwatched.');
        // Both rules on one date. Tuesday 2026-07-07 plus the 10 legal days alone is
        // Friday 2026-07-17, a working day; the free day of CPC art. 181 alin. 1 pct. 2
        // carries the maturity to Saturday 2026-07-18, and alin. 2 then prorogates it
        // to Monday 2026-07-20.
        self::assertSame('2026-07-20', $deadline->getDeadlineDate()->format('Y-m-d'));
    }

    /**
     * The file number appearing is when the duty becomes DUE, not when a term to stamp
     * starts running. The two are different things and only the second one annuls the
     * claim if it is missed (OUG 80/2013 art. 33 para. 2, CPC art. 197): those ten days
     * run from the court's notice, a communication the platform never observes, so
     * inventing a term at registration would put a date on the case that no document
     * supports and a CRITICAL alert on a case nobody has asked anything of yet.
     *
     * Pinned on both routes that record the number, because they are two ways into the
     * same fact: the lawyer typing it from the registry receipt, and the portal
     * activation that takes it from the discovered file.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function caseNumberRegistrationProvider(): iterable
    {
        yield 'typed from the registry receipt' => ['/transition/register', 'register_case_number'];
        yield 'taken from the discovered file' => ['/portal/activate', 'portal_activate'];
    }

    #[DataProvider('caseNumberRegistrationProvider')]
    public function testRegisteringTheCourtFileNumberCreatesNoStampingTerm(string $path, string $formName): void
    {
        $this->case->setStatus(CaseStatus::CERERE_DEPUSA);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $token = $this->csrfToken($path, $formName . '[_token]');

        $this->client->request('POST', '/case/' . $this->case->getId() . $path, [
            $formName => ['courtCaseNumber' => '4521/302/2026', '_token' => $token],
        ]);

        $this->em->clear();
        $refreshed = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertSame(CaseStatus::DOSAR_INREGISTRAT, $refreshed->getStatus(), 'The registration itself must have happened.');
        self::assertSame('4521/302/2026', $refreshed->getCourtCaseNumber());
        self::assertSame(StampDutyStatus::NEACHITATA, $refreshed->getStampDutyStatus(), 'Registering the file changes nothing about the duty.');

        self::assertNull(
            $this->em->getRepository(LegalDeadline::class)->findOneBy([
                'legalCase' => $refreshed->getId(),
                'type' => DeadlineType::TIMBRARE,
            ]),
            'The stamping term runs from the court notice, so registration must create none.',
        );
    }

    /**
     * The other half of the rule: the notice is the ONE thing that creates the term. Read
     * off the whole enum rather than off the TIMBRARE row alone, so a case that was just
     * registered carries no deadline whose miss annuls the claim.
     */
    public function testTheCourtNoticeIsTheOnlyThingThatCreatesTheStampingTerm(): void
    {
        $this->case->setStatus(CaseStatus::CERERE_DEPUSA);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/case/' . $this->case->getId() . '/transition/register', [
            'register_case_number' => [
                'courtCaseNumber' => '4522/302/2026',
                '_token' => $this->csrfToken('/transition/register', 'register_case_number[_token]'),
            ],
        ]);

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy(['legalCase' => $this->case->getId()]);
        self::assertNotSame([], $deadlines, 'The case carries the limitation term of its due date, so this reads a populated list.');

        $consequences = new DeadlineConsequenceResolver();
        foreach ($deadlines as $deadline) {
            self::assertNotSame(
                DeadlineConsequence::CASE_ANNULMENT,
                $consequences->resolve($deadline->getType()),
                $deadline->getType()->value . ' must not appear on a case that was merely registered.',
            );
        }

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/court-notice', [
            '_token' => $this->csrfToken('/stamp-duty/court-notice', '_token'),
            'courtNoticeDate' => '2026-07-07',
        ]);

        $this->em->clear();
        self::assertNotNull(
            $this->em->getRepository(LegalDeadline::class)->findOneBy([
                'legalCase' => $this->case->getId(),
                'type' => DeadlineType::TIMBRARE,
            ]),
            'Recording the notice is what puts the ten days on the case.',
        );
    }

    /**
     * A case that already carries the term keeps it exactly as it was. The term was
     * computed from a notice date the lawyer supplied, and the registration knows nothing
     * about that date, so touching the deadline could only move it away from the ten days
     * that actually run.
     */
    public function testAnExistingStampingTermIsUntouchedByTheRegistration(): void
    {
        $this->case->setStatus(CaseStatus::CERERE_DEPUSA);
        $this->em->flush();

        $deadline = new LegalDeadline();
        $deadline->setLegalCase($this->case);
        $deadline->setType(DeadlineType::TIMBRARE);
        $deadline->setDeadlineDate(new \DateTimeImmutable('2026-07-20'));
        $deadline->setPriority(DeadlineType::TIMBRARE->defaultPriority());
        $this->em->persist($deadline);
        $this->em->flush();
        $deadlineId = $deadline->getId();

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/case/' . $this->case->getId() . '/transition/register', [
            'register_case_number' => [
                'courtCaseNumber' => '4523/302/2026',
                '_token' => $this->csrfToken('/transition/register', 'register_case_number[_token]'),
            ],
        ]);

        $this->em->clear();
        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $this->case->getId(),
            'type' => DeadlineType::TIMBRARE,
        ]);

        self::assertCount(1, $deadlines, 'No second stamping term may appear beside the one that exists.');
        self::assertSame($deadlineId, $deadlines[0]->getId());
        self::assertSame('2026-07-20', $deadlines[0]->getDeadlineDate()->format('Y-m-d'));
        self::assertFalse($deadlines[0]->isCompleted());
    }

    /**
     * A notice date corrected after the fact moves the term that exists; it never adds a
     * second one. Two stamping terms on one case would mean two dates for ten days that
     * run once, and the earlier of them would keep alerting after the real one moved.
     */
    public function testACorrectedNoticeDateMovesTheSameTermInsteadOfAddingASecond(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/court-notice', [
            '_token' => $this->csrfToken('/stamp-duty/court-notice', '_token'),
            'courtNoticeDate' => '2026-07-07',
        ]);

        $this->em->clear();
        $first = $this->em->getRepository(LegalDeadline::class)->findOneBy([
            'legalCase' => $this->case->getId(),
            'type' => DeadlineType::TIMBRARE,
        ]);
        self::assertNotNull($first);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/court-notice', [
            '_token' => $this->csrfToken('/stamp-duty/court-notice', '_token'),
            'courtNoticeDate' => '2026-07-10',
        ]);

        $this->em->clear();
        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $this->case->getId(),
            'type' => DeadlineType::TIMBRARE,
        ]);

        self::assertCount(1, $deadlines, 'The correction moves the term, it does not duplicate it.');
        self::assertSame($first->getId(), $deadlines[0]->getId());
        // Friday 2026-07-10 plus the 10 legal days is Monday 2026-07-20; the free day of
        // CPC art. 181 alin. 1 pct. 2 carries the maturity to Tuesday 2026-07-21, a
        // working day, so no prorogation applies on top.
        self::assertSame('2026-07-21', $deadlines[0]->getDeadlineDate()->format('Y-m-d'));
    }

    public function testCourtNoticeRejectsAFutureDate(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/court-notice', [
            '_token' => $this->csrfToken('/stamp-duty/court-notice', '_token'),
            'courtNoticeDate' => (new \DateTimeImmutable('+3 days'))->format('Y-m-d'),
        ]);

        $this->em->clear();
        $deadline = $this->em->getRepository(LegalDeadline::class)->findOneBy([
            'legalCase' => $this->case->getId(),
            'type' => DeadlineType::TIMBRARE,
        ]);

        self::assertNull($deadline);
    }

    public function testAnotherUserCannotTouchTheStampDuty(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $intruder = new User();
        $intruder->setEmail('intruder-' . uniqid() . '@test.com');
        $intruder->setPassword($hasher->hashPassword($intruder, 'password'));
        $intruder->setIsVerified(true);
        $this->em->persist($intruder);
        $this->em->flush();

        $this->client->loginUser($intruder);

        // Ownership is checked before the CSRF token, so any token value will do:
        // the intruder cannot even load the page that would carry a valid one.
        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/defer', [
            '_token' => 'irrelevant',
            'deferralConsent' => '1',
        ]);

        self::assertResponseStatusCodeSame(403);

        $this->em->getConnection()->executeStatement('DELETE FROM user WHERE id = ?', [$intruder->getId()]);
    }
}
