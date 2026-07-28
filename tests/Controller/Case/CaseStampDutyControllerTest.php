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
use App\Enum\DeadlineType;
use App\Enum\DocumentType;
use App\Enum\PersonType;
use App\Enum\StampDutyStatus;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
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
    public function testProofIsRejectedWhenTheDutyIsAlreadyPaid(): void
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
        self::assertCount(0, $documents);
    }

    /** The 10-day term belongs to a case filed unstamped, nowhere else. */
    public function testCourtNoticeIsRejectedWhenTheDutyWasNotDeferred(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/case/' . $this->case->getId() . '/stamp-duty/court-notice', [
            '_token' => $this->csrfToken('/stamp-duty/court-notice', '_token'),
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
