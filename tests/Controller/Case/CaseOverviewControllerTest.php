<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\ClaimItem;
use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\LegalCaseDebtor;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Entity\CaseStatusHistory;
use App\Entity\CourtPortalEvent;
use App\Entity\Document;
use App\Entity\LegalDeadline;
use App\Enum\AnafStatus;
use App\Enum\CaseStatus;
use App\Enum\CourtType;
use App\Enum\DeadlinePriority;
use App\Enum\DeadlineType;
use App\Enum\DocumentType;
use App\Enum\ExtractionFailureReason;
use App\Enum\ExtractionStatus;
use App\Enum\PaymentNoticeCommunicationMethod;
use App\Enum\PenaltyType;
use App\Enum\PersonType;
use App\Enum\PortalEventType;
use App\Enum\RelationshipType;
use App\Enum\StampDutyStatus;
use App\Service\Case\CaseNotesService;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pas 4.0.1 + 4.0.2 — Tests for the case overview page.
 *
 * Exercises the full HTTP cycle (login + voter + template render) for the
 * `case_overview` route at `/case/{id}`. Verifies authorization edges
 * (200 owner / 403 cross-user / 404 missing / 302 anon), DOM structure
 * (5 tab panels at 4.0.1; hero/KPI/pipeline/tabs nav rendering at 4.0.2).
 */
final class CaseOverviewControllerTest extends WebTestCase
{
    use CountyFixtureTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;

    /** @var list<int> */
    private array $extraUserIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('overview-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Overview');
        $this->user->setLastName('Tester');
        $this->em->persist($this->user);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setStatus(CaseStatus::AMIABIL);
        $this->em->persist($this->case);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM court_portal_event WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM claim_item WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case_debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM case_status_history WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement("DELETE FROM court WHERE name LIKE 'Test Court %'");
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);
        foreach ($this->extraUserIds as $extraId) {
            $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $extraId]);
        }

        parent::tearDown();
    }

    /**
     * Attach a CourtPortalEvent to {@see self::$case} for Tab Activitate Portal tests.
     */
    private function attachPortalEvent(PortalEventType $type, \DateTimeImmutable $date, string $description = 'Test portal event'): CourtPortalEvent
    {
        $event = new CourtPortalEvent();
        $event->setLegalCase($this->case);
        $event->setEventType($type);
        // CourtPortalEvent.eventDate is mapped DATE_MUTABLE — store a mutable DateTime.
        $event->setEventDate(\DateTime::createFromInterface($date));
        $event->setDescription($description);
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    /**
     * Attach a LegalDeadline to {@see self::$case} for Tab Termene tests.
     */
    private function attachDeadline(DeadlineType $type, DeadlinePriority $priority, \DateTimeImmutable $date, bool $completed = false, ?string $description = null): LegalDeadline
    {
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($this->case);
        $deadline->setType($type);
        $deadline->setDeadlineDate($date);
        $deadline->setPriority($priority);
        if ($description !== null) {
            $deadline->setDescription($description);
        }
        if ($completed) {
            $deadline->markCompleted();
        }
        $this->em->persist($deadline);
        $this->em->flush();

        return $deadline;
    }

    /**
     * Attach a Document to {@see self::$case} for Tab Documente tests. Defaults to a 100 KB
     * PDF with no extraction confidence — caller overrides via parameters as needed.
     */
    private function attachDocument(
        DocumentType $type,
        ?float $confidence = null,
        string $filename = 'test-document.pdf',
        int $sizeBytes = 102400,
        ExtractionStatus $status = ExtractionStatus::COMPLETED,
        ?ExtractionFailureReason $failureReason = null,
    ): Document {
        $doc = new Document();
        $doc->setLegalCase($this->case);
        $doc->setDocumentType($type);
        $doc->setOriginalFilename($filename);
        $doc->setStoredFilename('stored-' . uniqid() . '.pdf');
        $doc->setFileSize($sizeBytes);
        $doc->setMimeType('application/pdf');
        $doc->setUploadedBy($this->user);
        $doc->setExtractionStatus($status);
        $doc->setExtractionFailureReason($failureReason);
        if ($confidence !== null) {
            $doc->setExtractionConfidence((string) $confidence);
        }
        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }

    /**
     * Attach a creditor, primary debtor, court, and full claim figures to {@see self::$case}
     * so hero/KPI assertions have realistic data. Returns the case for chaining.
     */
    private function enrichCase(?CaseStatus $status = null, ?AnafStatus $debtorAnafStatus = AnafStatus::ACTIV): LegalCase
    {
        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Tehno Construct SRL');
        $creditor->setAddress('Str. Industriilor 47, București');
        $creditor->setCui('RO12345678');
        $creditor->setIban('RO49RNCB0082004480010001');
        $this->em->persist($creditor);

        $debtor = new Debtor();
        $debtor->setUser($this->case->getUser());
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Beta Solutions SRL');
        $debtor->setAddress('Str. Iuliu Maniu 152, București');
        $debtor->setCui('RO87654321');
        $debtor->setAdministrator('Constantin Marinescu');
        $this->em->persist($debtor);
        $debtorLink = new LegalCaseDebtor($debtor);
        $debtorLink->setAnafStatus($debtorAnafStatus);

        $court = new Court();
        $court->setName('Test Court ' . uniqid());
        $court->setCounty($this->createCounty($this->em, 'București'));
        $court->setType(CourtType::JUDECATORIE);
        $this->em->persist($court);

        $this->case->setCreditor($creditor);
        $this->case->addDebtor($debtorLink);
        $this->case->setCourt($court);
        $this->case->setAmount('47500.00');
        $this->case->setCurrency('RON');
        // LegalCase.dueDate still uses DATE_MUTABLE — pass \DateTime, not \DateTimeImmutable.
        $this->case->setDueDate(new \DateTime('-90 days'));
        $this->case->setCalculatedInterest('3842.50');
        $this->case->setStampDuty('200.00');
        $this->case->setRelationshipType(RelationshipType::COMERCIAL);
        if ($status !== null) {
            $this->case->setStatus($status);
        }

        $this->em->flush();

        return $this->case;
    }

    /**
     * Attach one counting position to {@see self::$case}, keyed uniquely so two
     * tests never collide on the case's dedup index.
     */
    private function attachClaimItem(string $documentNumber, string $amount, string $dueDate = '-90 days'): ClaimItem
    {
        $item = new ClaimItem();
        $item->setLegalCase($this->case);
        $item->setDedupKey('ov-claim-' . uniqid('', true));
        $item->setDocumentNumber($documentNumber);
        $item->setDocumentDate(new \DateTimeImmutable($dueDate . ' -30 days'));
        $item->setAmount($amount);
        $item->setCurrency('RON');
        $item->setAmountRon($amount);
        $item->setDueDate(new \DateTimeImmutable($dueDate));
        $item->setConfirmedByLawyer(true);
        $item->setDebtor($this->case->getPrimaryDebtor());
        $this->case->addClaimItem($item);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    /**
     * Append a status-history entry so the Recommended Actions card can resolve
     * "Generează somație → deja generată %date%" against a real transition date.
     */
    private function recordTransition(string $newStatus, string $oldStatus = 'AMIABIL'): CaseStatusHistory
    {
        $entry = new CaseStatusHistory();
        $entry->setLegalCase($this->case);
        $entry->setOldStatus($oldStatus);
        $entry->setNewStatus($newStatus);
        $entry->setCreatedBy($this->user);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    public function testClaimEditControlsAreAbsentPastTheAmiableStage(): void
    {
        $this->enrichCase(CaseStatus::SOMATIE_TRIMISA);
        $item = $this->attachClaimItem('INV-1', '47500.00');
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="claim-add-invoice"]'));
        self::assertCount(0, $crawler->filter('[data-testid="claim-edit-details"]'));
        self::assertCount(0, $crawler->filter('[data-testid="claim-exclude-' . $item->getId() . '"]'));
        self::assertCount(0, $crawler->filter('[data-testid="modal-claim-add"]'));
        self::assertCount(0, $crawler->filter('[data-testid="modal-claim-exclude"]'));
        self::assertCount(0, $crawler->filter('[data-testid="modal-claim-details"]'));
        self::assertCount(0, $crawler->filter('[data-testid="claim-court-proposal"]'));
        self::assertCount(1, $crawler->filter('[data-testid="claim-positions-table"]'), 'The positions stay readable.');
        self::assertCount(1, $crawler->filter('[data-testid="claim-locked-note"]'));
        self::assertStringContainsString('Facturile nu se mai pot modifica după trimiterea somației', $crawler->filter('[data-testid="claim-locked-note"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="claim-state-chip"][data-state="locked"]'));
        self::assertStringContainsString('Blocată după somație', $crawler->filter('[data-testid="claim-state-chip"]')->text());
        self::assertCount(0, $crawler->filter('[data-testid="claim-reinclude-' . $item->getId() . '"]'));
    }

    public function testClaimLockedNoteIsAbsentOnAnAmiableCaseWithoutPositions(): void
    {
        $this->enrichCase();
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="claim-locked-note"]'));
        self::assertCount(1, $crawler->filter('[data-testid="claim-state-chip"][data-state="editable"]'));
        self::assertStringContainsString('Editabilă până la trimiterea somației', $crawler->filter('[data-testid="claim-state-chip"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="claim-add-invoice"]'));
        self::assertCount(1, $crawler->filter('[data-testid="modal-claim-add"]'));
        self::assertCount(0, $crawler->filter('[data-testid="court-card-proposal"]'), 'Nothing counts, so there is nothing to compare the court with.');
    }

    /**
     * The fixture debtor has no county: the rules cannot name a court, the
     * court on the case stays and the card says why.
     */
    public function testCourtCardSaysWhenTheRulesCannotNameACourt(): void
    {
        $this->enrichCase();
        $this->attachClaimItem('INV-1', '47500.00');
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $note = $crawler->filter('[data-testid="court-card-proposal"]');
        self::assertCount(1, $note);
        self::assertStringContainsString('Județul debitorului nu este specificat', $note->text());
        self::assertStringContainsString($this->case->getCourt()->getName(), $crawler->filter('#case-court-card')->text(), 'The court on the case stays.');
        $block = $crawler->filter('[data-testid="claim-court-proposal"]');
        self::assertCount(1, $block, 'The claim card says why no court can be named.');
        self::assertStringContainsString('Instanța competentă nu poate fi stabilită', $block->text());
        self::assertStringContainsString('Instanța actuală rămâne pe dosar', $block->text());
        self::assertCount(0, $block->filter('[data-testid="claim-court-accept"]'), 'Nothing to accept.');
    }

    public function testFullPaymentActionAndModalOfferedOnAmiabilAndSomatie(): void
    {
        $this->client->loginUser($this->user);
        $this->enrichCase();

        // The client reboots the kernel between requests, so the status is written straight to the row.
        foreach ([CaseStatus::AMIABIL, CaseStatus::SOMATIE_TRIMISA] as $status) {
            $this->em->getConnection()->executeStatement('UPDATE legal_case SET status = ? WHERE id = ?', [$status->value, $this->case->getId()]);
            $this->client->request('GET', '/case/' . $this->case->getId());

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#case-status-badge', $status === CaseStatus::AMIABIL ? 'Amiabil' : 'Somație trimisă', 'The status really changed.');
            self::assertSelectorExists('#case-hero-actions [data-testid="hero-full-payment-action"][data-hs-overlay="#hs-modal-full-payment"]', $status->value);
            self::assertSelectorExists('#case-hero-actions [data-testid="hero-menu-full-payment"][data-hs-overlay="#hs-modal-full-payment"]', $status->value);
            self::assertSelectorExists('#hs-modal-full-payment form[action$="/transition/full-payment"]', $status->value);
            self::assertSelectorNotExists('[data-testid="full-payment-multi-debtor"]', 'Single debtor: no multi-debtor warning.');
        }
    }

    public function testHeroStatesTheNextStepForEveryStatus(): void
    {
        $this->client->loginUser($this->user);
        $this->enrichCase();
        $this->case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-20 days'));
        $this->case->setRulingCommunicationDate(new \DateTimeImmutable('-5 days'));
        $this->attachDocument(DocumentType::DOVADA_COMUNICARE);
        // The client reboots the kernel between requests, so the status is written straight to the row.
        $connection = $this->em->getConnection();

        foreach (CaseStatus::cases() as $status) {
            $connection->executeStatement('UPDATE legal_case SET status = ? WHERE id = ?', [$status->value, $this->case->getId()]);
            $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

            self::assertResponseIsSuccessful($status->value);
            $sentence = $crawler->filter('#case-hero [data-testid="hero-next-step"]');
            self::assertCount(1, $sentence, $status->value);
            self::assertSame('false', $sentence->attr('data-blocked'), $status->value);
            self::assertStringNotContainsString('case_overview.', $sentence->text(), $status->value . ' has a translated sentence.');
            self::assertStringContainsString($status->isTerminal() ? 'Dosar închis:' : 'Următorul pas:', $sentence->text(), $status->value);
        }
    }

    public function testHeroActionStepsBackWhileTheSummonsCommunicationDateIsMissing(): void
    {
        $this->client->loginUser($this->user);
        $this->enrichCase(CaseStatus::SOMATIE_TRIMISA);
        $this->case->setPaymentNoticeCommunicationDate(null);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertSelectorExists('#summons-communication-alert button', 'The band carries the only filled button.');
        self::assertSame('true', $crawler->filter('[data-testid="hero-next-step"]')->attr('data-blocked'));
        self::assertStringContainsString('data comunicării somației', $crawler->filter('[data-testid="hero-next-step"]')->text());
        self::assertSame('outline', $crawler->filter('#case-hero-actions [data-testid="hero-primary-cta"]')->attr('data-tone'));

        $this->em->getConnection()->executeStatement('UPDATE legal_case SET payment_notice_communication_date = ? WHERE id = ?', [(new \DateTimeImmutable('-3 days'))->format('Y-m-d'), $this->case->getId()]);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertSame('true', $crawler->filter('[data-testid="hero-next-step"]')->attr('data-blocked'), 'The proof is still missing.');
        self::assertStringContainsString('dovada comunicării somației', $crawler->filter('[data-testid="hero-next-step"]')->text());
        self::assertSame('outline', $crawler->filter('#case-hero-actions [data-testid="hero-primary-cta"]')->attr('data-tone'));
        self::assertSelectorExists('#summons-communication-alert [data-testid="summons-alert-upload-proof"][data-upload-preset-type="dovada_comunicare"]', 'The band now carries the filled button.');

        // The request rebooted the kernel: reload the case and the user into the live entity manager.
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->case = $this->em->find(LegalCase::class, $this->case->getId());
        $this->user = $this->em->find(User::class, $this->user->getId());
        $this->attachDocument(DocumentType::DOVADA_COMUNICARE);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertSame('false', $crawler->filter('[data-testid="hero-next-step"]')->attr('data-blocked'));
        self::assertSame('filled', $crawler->filter('#case-hero-actions [data-testid="hero-primary-cta"]')->attr('data-tone'));
        self::assertSelectorTextNotContains('#summons-communication-alert', 'Atașează dovada');
    }

    public function testHeroActionStepsBackWhileTheOrderCommunicationDateIsMissing(): void
    {
        $this->client->loginUser($this->user);
        $this->enrichCase(CaseStatus::IN_ANULARE);
        $this->case->setRulingCommunicationDate(null);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertSame('true', $crawler->filter('[data-testid="hero-next-step"]')->attr('data-blocked'));
        self::assertStringContainsString('data comunicării ordonanței', $crawler->filter('[data-testid="hero-next-step"]')->text());
        self::assertSame('outline', $crawler->filter('#case-hero-actions [data-testid="hero-primary-cta"][data-hs-overlay="#hs-modal-executare"]')->attr('data-tone'));
    }

    public function testHeroActionStepsBackWhileTheStampDutyBandAsksForPayment(): void
    {
        $this->client->loginUser($this->user);
        $this->enrichCase(CaseStatus::TERMEN_FIXAT);
        $this->case->setCourtCaseNumber('1234/211/2026');
        $this->case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertSelectorTextContains('#portal-case-alert', 'Taxa de timbru nu este achitată');
        self::assertSame('true', $crawler->filter('[data-testid="hero-next-step"]')->attr('data-blocked'));
        self::assertStringContainsString('Taxa de timbru, din banda de mai sus', $crawler->filter('[data-testid="hero-next-step"]')->text());
        self::assertSame('outline', $crawler->filter('#case-hero-actions [data-testid="hero-primary-cta"][data-hs-overlay="#hs-modal-issue-ruling"]')->attr('data-tone'));
    }

    public function testFullPaymentActionAbsentOnceRequestGenerated(): void
    {
        $this->enrichCase(CaseStatus::CERERE_GENERATA);
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertSelectorNotExists('[data-testid="hero-full-payment-action"]');
        self::assertSelectorNotExists('[data-testid="hero-menu-full-payment"]');
        self::assertSelectorNotExists('#hs-modal-full-payment');
    }

    public function testFullPaymentModalWarnsWhenSeveralDebtors(): void
    {
        $this->enrichCase(CaseStatus::SOMATIE_TRIMISA);
        $second = new Debtor();
        $second->setUser($this->user);
        $second->setPersonType(PersonType::PJ);
        $second->setName('SC Gamma Distribution SRL');
        $second->setAddress('Str. Lunga 3, Brașov');
        $second->setCui('RO11223344');
        $this->em->persist($second);
        $this->case->addDebtor(new LegalCaseDebtor($second));
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertSelectorExists('[data-testid="full-payment-multi-debtor"]');
    }

    /** Closed from Somație trimisă: the pipeline stops there, later stages read as not needed. */
    public function testCaseClosedOnFullPaymentShowsWhereItStopped(): void
    {
        $this->enrichCase(CaseStatus::INCHIS_SUCCES);
        $this->case->setFullPaymentDate(new \DateTimeImmutable('2026-01-15'));
        $this->case->setPaymentNoticeDate(new \DateTime('2026-01-05'));
        $this->em->flush();
        $this->recordTransition('SOMATIE_TRIMISA');
        $this->recordTransition('INCHIS_SUCCES', 'SOMATIE_TRIMISA');

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        $translator = static::getContainer()->get('translator');
        self::assertSelectorTextContains('#case-pipeline h2', $translator->trans('case_overview.pipeline.closed_at_stage', ['%current%' => 2, '%total%' => 5, '%label%' => $translator->trans('case_overview.pipeline.stage_2_somatie')]));
        self::assertSelectorTextContains('[data-testid="pipeline-closed-full-payment"]', '15.01.2026');
        self::assertSelectorTextNotContains('#case-pipeline', $translator->trans('case_overview.pipeline.stage_in_progress_label'));
        self::assertSelectorExists('[data-testid="hero-full-payment"]');
        self::assertSelectorNotExists('[data-testid="hero-full-payment-action"]');
        self::assertSelectorNotExists('#hs-modal-add-deadline');
        self::assertSelectorNotExists('#case-hero-actions [data-hs-overlay="#hs-modal-cerere-op"]');
        self::assertSelectorNotExists('#panel-documente [data-hs-overlay="#hs-modal-cerere-op"]');
        self::assertSelectorTextContains('#panel-documente', $translator->trans('case_overview.documents.not_needed'));
        self::assertSelectorNotExists('#panel-documente [data-hs-overlay="#hs-modal-upload-document"]');
        self::assertSelectorTextNotContains('#panel-documente', $translator->trans('case_overview.documents.opis_status_pending'));
    }

    public function testGetOverviewForOwnedCaseReturns200(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSelectorTextContains('title', 'Dosar ' . $this->case->getCaseNumber());
    }

    public function testGetOverviewForOtherUserCaseReturns403(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $intruder = new User();
        $intruder->setEmail('overview-intruder-' . uniqid() . '@test.com');
        $intruder->setPassword($hasher->hashPassword($intruder, 'password'));
        $intruder->setIsVerified(true);
        $intruder->setFirstName('Intruder');
        $intruder->setLastName('Tester');
        $this->em->persist($intruder);
        $this->em->flush();

        try {
            $this->client->loginUser($intruder);
            $this->client->request('GET', '/case/' . $this->case->getId());

            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        } finally {
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $intruder->getId()]);
        }
    }

    public function testGetOverviewForNonexistentIdReturns404(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/999999999');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testGetOverviewUnauthenticatedRedirectsToLogin(): void
    {
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertResponseRedirects();
    }

    public function testAllFiveTabPanelsRenderInDom(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#panel-detalii[role="tabpanel"]');
        self::assertSelectorExists('#panel-documente[role="tabpanel"]');
        self::assertSelectorExists('#panel-termene[role="tabpanel"]');
        self::assertSelectorExists('#panel-portal[role="tabpanel"]');
        self::assertSelectorExists('#panel-audit[role="tabpanel"]');
    }

    public function testHeroRendersCreditorVsDebtorTitle(): void
    {
        $this->enrichCase();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $h1 = $crawler->filter('h1')->text();
        self::assertStringContainsString('SC Tehno Construct SRL', $h1);
        self::assertStringContainsString('vs', $h1);
        self::assertStringContainsString('SC Beta Solutions SRL', $h1);
    }

    public function testHeroRendersStatusPillMatchingCaseStatus(): void
    {
        $this->enrichCase(CaseStatus::SOMATIE_TRIMISA);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        // StatusBadge maps SOMATIE_TRIMISA → amber palette + label from enum.case_status key.
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('bg-amber-100', $html);
        self::assertStringContainsString('soft-pulse', $html, 'Pulse animation must be active for non-terminal status');
    }

    public function testHeroMetadataRendersCourtNameWhenPresent(): void
    {
        $this->enrichCase();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString($this->case->getCourt()->getName(), $html);
    }

    public function testHeroMetadataRendersCourtUndeterminedWhenNull(): void
    {
        // Don't call enrichCase — base setUp case has no court.
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Instanță needeterminată');
    }

    public function testKpiPrincipalRendersWithCurrency(): void
    {
        $this->enrichCase();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('47.500,00', $html, 'Principal amount formatted with Romanian thousand/decimal separators');
        self::assertStringContainsString('RON', $html);
    }

    public function testKpiActiveDeadlinePlaceholderWhenNoDeadlines(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Niciun termen activ');
    }

    public function testPipelineActiveStageMatchesCaseStatus(): void
    {
        $this->enrichCase(CaseStatus::CERERE_DEPUSA);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        // Stage 3 (CERERE_DEPUSA index=2) must be active (amber styling + "Cerere depusă" label).
        self::assertStringContainsString('Stadiu 3 din 5', $html);
        self::assertStringContainsString('Cerere depusă', $html);
    }

    public function testTabsNavRendersAllSixTabsWithDataHsTab(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(6, $crawler->filter('button[data-hs-tab]'));
        self::assertCount(1, $crawler->filter('#tab-depunere[data-hs-tab="#panel-depunere"]'));
    }

    public function testHeroNoCreditorFallbackUsesCreditorSpecificLabel(): void
    {
        // Base setUp case has no creditor — verify the fallback label says „fără creditor",
        // not „fără debitor" (W1 regression guard against i18n key copy-paste).
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'fără creditor');
    }

    public function testHeroStatusPillSkipsPulseForTerminalStatus(): void
    {
        $this->case->setStatus(CaseStatus::INCHIS_SUCCES);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        // The status pill itself is present, but the soft-pulse animation must NOT be
        // attached to it (terminal cases shouldn't visually nag the user).
        $pillNode = $crawler->filter('span.bg-green-100')->first();
        self::assertGreaterThan(0, $pillNode->count(), 'INCHIS_SUCCES uses green palette');
        $dotClass = $pillNode->filter('span')->first()->attr('class') ?? '';
        self::assertStringNotContainsString('soft-pulse', $dotClass);
    }

    public function testDetaliiPartyCardCreditorRendersFields(): void
    {
        $this->enrichCase();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        // Inside #panel-detalii — party card must surface CUI + seat + IBAN.
        self::assertStringContainsString('RO12345678', $html, 'Creditor CUI rendered');
        self::assertStringContainsString('Str. Industriilor 47', $html, 'Creditor seat rendered');
        self::assertStringContainsString('RO49RNCB0082004480010001', $html, 'Creditor IBAN rendered');
    }

    public function testDetaliiPartyCardDebtorRendersAnafBadgeWhenActive(): void
    {
        $this->enrichCase(null, AnafStatus::ACTIV);

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        // ANAF ACTIV → green badge with the localized label.
        $badge = $crawler->filter('span.bg-green-100')->reduce(static function ($node) {
            return str_contains($node->text(), 'ONRC ACTIV');
        });
        self::assertGreaterThan(0, $badge->count(), 'Green ONRC ACTIV badge must be rendered for AnafStatus::ACTIV debtor');
    }

    public function testClaimSummaryLineAddsPrincipalAccessoryAndStampDuty(): void
    {
        // Principal 47500 + interest 3842.50 + stamp duty 200 = 51542.50 to file.
        $this->enrichCase();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $line = $crawler->filter('[data-testid="claim-summary-line"]');
        self::assertCount(1, $line);
        self::assertStringContainsString('Cerere totală de depus', $line->text());
        self::assertStringContainsString('51.542,50', $line->text());
        self::assertStringContainsString('principal 47.500,00', $line->text());
        self::assertStringContainsString('dobândă 3.842,50', $line->text());
        self::assertStringContainsString('taxă timbru 200,00', $line->text());
        $card = $crawler->filter('#case-claim-card')->text();
        self::assertStringNotContainsString('92,5%', $card);
        self::assertStringNotContainsString('Compoziție creanță', $card);
    }

    public function testDetaliiCourtSummaryRendersJudecatorieRationaleForSmallClaim(): void
    {
        // enrichCase uses a JUDECATORIE court with a 47.500 RON claim (below threshold).
        $this->enrichCase();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-detalii', 'Judecătorie competentă conform CPC art. 94');
    }

    public function testDetaliiCourtSummaryShowsTribunalRationaleForLargeClaim(): void
    {
        $this->enrichCase();
        // A >200.000 RON claim routes to the tribunal; the rationale must reflect
        // art. 95, not the judecătorie threshold text.
        $this->case->getCourt()->setType(CourtType::TRIBUNAL);
        $this->case->setAmount('236288.75');
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-detalii', 'Tribunal competent conform CPC art. 95');
        self::assertSelectorTextContains('#panel-detalii', 'art. 98');
        self::assertStringNotContainsString(
            'art. 94 pct. 1 lit. k',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testDetaliiClaimCompositionShowsPerInvoiceBnrBreakdownForMultipleItems(): void
    {
        $this->enrichCase();
        // Two interest-bearing invoices with distinct past due dates: each row carries its
        // own BNR period calculation, opened from its accessory amount.
        $items = [];
        foreach ([['INV-A', '2024-01-15', '1000.00'], ['INV-B', '2024-06-20', '2000.00']] as [$doc, $due, $amt]) {
            $item = new ClaimItem();
            $item->setLegalCase($this->case);
            $item->setDedupKey('ov-bnr-' . uniqid('', true));
            $item->setDocumentNumber($doc);
            $item->setAmount($amt);
            $item->setCurrency('RON');
            $item->setAmountRon($amt);
            $item->setDueDate(new \DateTimeImmutable($due));
            // Only lawyer-confirmed, RON-denominated positions count towards the claim.
            $item->setConfirmedByLawyer(true);
            $this->case->addClaimItem($item);
            $this->em->persist($item);
            $items[$doc] = $item;
        }
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        foreach ($items as $doc => $item) {
            $id = $item->getId();
            $toggle = $crawler->filter('[data-testid="claim-toggle-breakdown-' . $id . '"]');
            self::assertCount(1, $toggle, $doc);
            self::assertSame('false', $toggle->attr('aria-expanded'));
            self::assertSame('claim-breakdown-' . $id, $toggle->attr('aria-controls'));
            $breakdown = $crawler->filter('#claim-breakdown-' . $id);
            self::assertCount(1, $breakdown, $doc);
            self::assertStringContainsString('hidden', (string) $breakdown->attr('class'), 'Closed until the amount is clicked.');
            self::assertStringContainsString('Calculul dobânzii · ' . $doc, $breakdown->text());
            self::assertStringContainsString('Rata BNR', $breakdown->text());
            self::assertCount(1, $breakdown->filter('[data-testid="claim-position-breakdown"][data-kind="interest"]'));
        }
        self::assertStringNotContainsString('Vezi breakdown calcul dobândă', (string) $this->client->getResponse()->getContent());
    }

    public function testDetaliiShowsHowEachContractualPenaltyWasComputed(): void
    {
        $this->enrichCase();
        $this->case->setPenaltyType(PenaltyType::CONTRACTUAL);
        $this->case->setContractualPenaltyRate('0.1000');
        $this->case->setContractualPenaltyCapPercent('10.00');
        $this->case->setAccessoryCutoffDate(new \DateTimeImmutable('2026-10-03'));
        $item = new ClaimItem();
        $item->setLegalCase($this->case);
        $item->setDedupKey('ov-pen-' . uniqid('', true));
        $item->setDocumentNumber('KSS 1534');
        $item->setAmount('21318.90');
        $item->setCurrency('RON');
        $item->setAmountRon('21318.90');
        $item->setDueDate(new \DateTimeImmutable('2025-02-21'));
        $item->setConfirmedByLawyer(true);
        $this->case->addClaimItem($item);
        $this->em->persist($item);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $breakdown = $crawler->filter('[data-testid="claim-position-breakdown"][data-kind="penalty"]');
        self::assertCount(1, $breakdown);
        self::assertStringContainsString('Calculul penalităților · KSS 1534', $breakdown->text());
        $text = $breakdown->text();
        self::assertStringContainsString('22.02.2025 → 03.10.2026', $text);
        self::assertStringContainsString('589', $text);
        self::assertStringContainsString('12.556,83', $text);
        self::assertStringContainsString('Plafon din contract: 10% din 21.318,90 = 2.131,89 lei.', $text);
    }

    public function testDetaliiShowsOnePenaltyRowPerInvoice(): void
    {
        $this->enrichCase();
        $this->case->setPenaltyType(PenaltyType::CONTRACTUAL);
        $this->case->setContractualPenaltyRate('0.1000');
        $this->case->setAccessoryCutoffDate(new \DateTimeImmutable('2025-03-23'));
        foreach ([['INV-A', '2025-02-21', '1000.00'], ['INV-B', '2025-03-01', '2000.00']] as [$doc, $due, $amt]) {
            $item = new ClaimItem();
            $item->setLegalCase($this->case);
            $item->setDedupKey('ov-pen-' . uniqid('', true));
            $item->setDocumentNumber($doc);
            $item->setAmount($amt);
            $item->setCurrency('RON');
            $item->setAmountRon($amt);
            $item->setDueDate(new \DateTimeImmutable($due));
            $item->setConfirmedByLawyer(true);
            $this->case->addClaimItem($item);
            $this->em->persist($item);
        }
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $breakdown = $crawler->filter('[data-testid="claim-position-breakdown"][data-kind="penalty"]');
        self::assertCount(2, $breakdown, 'One calculation per invoice.');
        self::assertStringContainsString('Calculul penalităților · INV-A', $breakdown->eq(0)->text());
        self::assertStringContainsString('22.02.2025 → 23.03.2025', $breakdown->eq(0)->text());
        self::assertStringContainsString('Calculul penalităților · INV-B', $breakdown->eq(1)->text());
        self::assertStringContainsString('02.03.2025 → 23.03.2025', $breakdown->eq(1)->text());
        self::assertSame(1, $breakdown->eq(0)->filter('tbody tr')->count());
    }

    public function testDetaliiShowsNoPenaltyTableForLegalInterest(): void
    {
        $this->enrichCase();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="claim-position-breakdown"][data-kind="penalty"]'));
    }

    public function testKpiHintStatesTheMarginTheContractCarries(): void
    {
        $this->enrichCase();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());
        self::assertSelectorTextContains('#case-kpi-grid', 'BNR + 8 pp');

        // A contract concluded before Law 72/2013 keeps the 4 pp margin.
        $this->case->setContractDate(new \DateTime('2012-05-10'));
        $this->em->flush();

        $this->client->request('GET', '/case/' . $this->case->getId());
        self::assertSelectorTextContains('#case-kpi-grid', 'BNR + 4 pp');
    }

    public function testDetaliiCourtSummaryFallbackWhenNull(): void
    {
        // No enrichCase — base setup case has no court.
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-detalii', 'Instanță needeterminată');
    }

    public function testDetaliiHasNoSidebar(): void
    {
        $this->enrichCase(CaseStatus::SOMATIE_TRIMISA);
        $this->attachDeadline(DeadlineType::RASPUNS_SOMATIE, DeadlinePriority::HIGH, new \DateTimeImmutable('+10 days'));

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#case-detalii-sidebar');
        self::assertSelectorNotExists('#case-recommended-actions');
        self::assertSelectorNotExists('#panel-detalii svg.-rotate-90', 'No deadline ring in the tab.');
        self::assertSelectorNotExists('#panel-detalii .xl\\:grid-cols-3');
        self::assertSelectorExists('#panel-detalii #case-claim-card');
    }

    public function testDetaliiBreakdownErrorRenderedWhenCalculatorFails(): void
    {
        // CIVIL relationship throws DomainException from RelationshipType::applicableRate()
        // per Pas 2.1 C3 (MVP B2B-only) — Controller catches and sets breakdown_error=true.
        $this->enrichCase();
        $this->case->setRelationshipType(RelationshipType::CIVIL);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-detalii', 'Defalcarea pe perioade nu poate fi recalculată');
        self::assertSelectorExists('[data-testid="claim-breakdown-error"]');
        self::assertSelectorNotExists('[data-testid="claim-case-breakdown"]');
    }

    public function testDocumenteGeneratedRenders3RowsAlways(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        // Three placeholder rows (Somație / Cerere OP / Opis) — Faza 6 will wire real generation.
        self::assertSelectorTextContains('#panel-documente', 'Somație de plată');
        self::assertSelectorTextContains('#panel-documente', 'Cerere ordonanță de plată');
        self::assertSelectorTextContains('#panel-documente', 'Opis documente');
    }

    public function testDocumenteSourceListCountMatchesCaseDocuments(): void
    {
        $this->attachDocument(DocumentType::CONTRACT, null, 'contract-x.pdf');
        $this->attachDocument(DocumentType::FACTURA, null, 'factura-y.pdf');
        $this->em->clear();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('contract-x.pdf', $html);
        self::assertStringContainsString('factura-y.pdf', $html);
    }

    public function testDocumenteSourceListEmptyStateWhenNoDocuments(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-documente', 'Niciun document încărcat încă');
    }

    public function testDocumenteSourceListShowsNoExtractionPercentage(): void
    {
        // The score is the extractor's own estimate of how clearly it read the
        // fields it found, not how much of the document it read or whether it
        // is right; shown as a percentage it reads as a quality grade.
        $this->attachDocument(DocumentType::CONTRACT, 0.95, 'contract-95.pdf');
        $this->em->clear();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-documente', 'contract-95.pdf');
        self::assertSelectorTextNotContains('#panel-documente', '95%');
    }

    public function testDocumenteSourceListHidesExtractionStatus(): void
    {
        // Documents added after the case exists never reach the extraction queue,
        // so a status badge would read "queued" forever on a communication proof.
        $this->attachDocument(DocumentType::DOVADA_COMUNICARE, null, 'dovada.pdf', status: ExtractionStatus::PENDING);
        $this->attachDocument(DocumentType::CONTRACT, 0.95, 'contract-ok.pdf', status: ExtractionStatus::COMPLETED);
        $this->attachDocument(
            DocumentType::FACTURA,
            null,
            'factura-fail.pdf',
            status: ExtractionStatus::FAILED,
            failureReason: ExtractionFailureReason::FILE_TOO_LARGE,
        );
        $this->em->clear();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-documente', 'dovada.pdf');
        self::assertSelectorTextNotContains('#panel-documente', 'În coadă');
        self::assertSelectorTextNotContains('#panel-documente', 'Date extrase');
        self::assertSelectorTextNotContains('#panel-documente', 'Eșec extracție');
        self::assertSelectorTextNotContains('#panel-documente', 'Fișier prea mare');
    }

    public function testDocumenteSourceListShowsDocumentTypeOnce(): void
    {
        $this->attachDocument(DocumentType::FACTURA, null, 'factura-unica.pdf');
        $this->em->clear();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('#panel-documente div.divide-y > div')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'factura-unica.pdf'),
        );
        self::assertCount(1, $row);
        self::assertSame(1, substr_count($row->text(), 'Factură'));
    }

    public function testDocumenteSourceListLabelsImageAsImg(): void
    {
        $doc = $this->attachDocument(DocumentType::DOVADA_COMUNICARE, null, 'IMG_1219.JPG');
        $doc->setMimeType('image/jpeg');
        $this->em->flush();
        $this->em->clear();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('#panel-documente div.divide-y > div')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'IMG_1219.JPG'),
        );
        self::assertCount(1, $row);
        self::assertSame('IMG', trim($row->filter('div.size-9')->text()));
    }

    public function testDocumenteSourceListLabelsPdfAsPdfAndOtherFilesAsDoc(): void
    {
        $this->attachDocument(DocumentType::CONTRACT, null, 'contract.pdf');
        $docx = $this->attachDocument(DocumentType::ALT_DOCUMENT, null, 'anexa.docx');
        $docx->setMimeType('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->em->flush();
        $this->em->clear();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $iconFor = static function (string $filename) use ($crawler): string {
            $row = $crawler->filter('#panel-documente div.divide-y > div')->reduce(
                static fn ($node): bool => str_contains($node->text(), $filename),
            );
            self::assertCount(1, $row);

            return trim($row->filter('div.size-9')->text());
        };
        self::assertSame('PDF', $iconFor('contract.pdf'));
        self::assertSame('DOC', $iconFor('anexa.docx'));
    }

    public function testDocumenteSourceListRendersColoredBadgeForNewDocumentType(): void
    {
        // extras_cont is one of the newer DocumentType values; its label must render
        // and its badge must carry a dedicated (non-default) color class.
        $this->attachDocument(DocumentType::EXTRAS_CONT, null, 'extras-cont.pdf');
        $this->em->clear();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('#panel-documente .bg-teal-50')->count(),
            'extras_cont document should render its dedicated teal badge color',
        );
    }

    public function testClaimDescriptionRenderedWhenPresent(): void
    {
        $this->case->setClaimDescription('Contravaloare servicii de consultanță neachitate');
        $this->em->flush();
        $this->em->clear();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-detalii', 'Contravaloare servicii de consultanță neachitate');
    }

    public function testClaimDescriptionHiddenWhenEmpty(): void
    {
        // No claim description set, so the label must not appear.
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('#panel-detalii', 'Obiectul creanței');
    }

    public function testSummonsCommunicationCardHiddenBeforeTheSummonsExists(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#panel-documente #summons-communication-card'));
    }

    public function testSummonsCommunicationCardAsksForTheDateFirst(): void
    {
        $this->case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->attachDocument(DocumentType::SOMATIE);
        $this->em->clear();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('#panel-documente #summons-communication-card');
        self::assertCount(1, $card);
        self::assertStringContainsString('Lipsește data', $card->text());
        // The date field sits in the card itself and posts back to the documents tab.
        self::assertCount(1, $card->filter('input[name="payment_notice_communication_date[paymentNoticeCommunicationDate]"]'));
        self::assertCount(1, $card->filter('input[name="return_tab"][value="documente"]'));
        self::assertCount(1, $card->filter('button[data-upload-preset-type="dovada_comunicare"]'));
    }

    public function testSummonsCommunicationCardShowsTheTermEndOnceTheDateIsSaved(): void
    {
        $this->case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('2026-03-02'));
        $this->case->setPaymentNoticeCommunicationMethod(PaymentNoticeCommunicationMethod::EXECUTOR);
        $this->attachDocument(DocumentType::SOMATIE);
        $this->em->clear();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('#panel-documente #summons-communication-card');
        self::assertStringContainsString('02.03.2026', $card->text());
        self::assertStringContainsString('Lipsește dovada', $card->text());
        // Free-days counting: 2 + 16 = 18 March 2026, a Wednesday.
        self::assertStringContainsString('s-a împlinit la 18.03.2026', $card->text());
        self::assertStringContainsString('Corectează data', $card->text());
    }

    public function testSummonsCommunicationCardIsCompleteWithDateAndProof(): void
    {
        $this->case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('2026-03-02'));
        $this->case->setPaymentNoticeCommunicationMethod(PaymentNoticeCommunicationMethod::POSTA_RCD);
        $this->attachDocument(DocumentType::SOMATIE);
        $this->attachDocument(DocumentType::DOVADA_COMUNICARE, filename: 'confirmare-primire.pdf');
        $this->em->clear();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('#panel-documente #summons-communication-card');
        self::assertStringContainsString('Complet', $card->text());
        self::assertStringContainsString('confirmare-primire.pdf', $card->text());
        self::assertCount(0, $card->filter('button[data-upload-preset-type="dovada_comunicare"]'));
    }

    public function testSummonsCommunicationCardOffersNoCorrectionOnceThePetitionIsGenerated(): void
    {
        $this->case->setStatus(CaseStatus::CERERE_GENERATA);
        $this->case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('2026-03-02'));
        $this->case->setPaymentNoticeCommunicationMethod(PaymentNoticeCommunicationMethod::EXECUTOR);
        $this->attachDocument(DocumentType::SOMATIE);
        $this->attachDocument(DocumentType::DOVADA_COMUNICARE);
        $this->em->clear();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('#panel-documente #summons-communication-card');
        self::assertCount(1, $card);
        self::assertStringNotContainsString('Corectează data', $card->text());
    }

    public function testGenerateOpDialogDisablesTheButtonWhileThePaymentTermRuns(): void
    {
        // Proof attached and stamp duty paid: only the running term holds the petition.
        $this->case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->case->setStampDutyStatus(StampDutyStatus::ACHITATA);
        $this->case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('today'));
        $this->case->setPaymentNoticeCommunicationMethod(PaymentNoticeCommunicationMethod::EXECUTOR);
        $this->attachDocument(DocumentType::SOMATIE);
        $this->attachDocument(DocumentType::DOVADA_COMUNICARE);
        $this->em->clear();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $dialog = $crawler->filter('#hs-modal-cerere-op');
        self::assertStringContainsString('Cererea de ordonanță se poate genera din', $dialog->text());
        self::assertStringNotContainsString('nu e completată', $dialog->text());
        self::assertNotNull($dialog->filter('button[type="submit"]')->attr('disabled'));
    }

    public function testDocumenteZipCtaTriggersGenerateOpModal(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        // Since 4.0.7 the ZIP CTA opens the generate-op modal (informative);
        // the final ZIP generation itself is wired in Faza 6.
        $zipCta = $crawler->filter('#panel-documente button[data-hs-overlay="#hs-modal-cerere-op"]')->reduce(static function ($node) {
            return str_contains($node->text(), 'Generează & descarcă ZIP');
        });
        self::assertGreaterThan(0, $zipCta->count(), 'ZIP CTA must trigger generate-op modal');
    }

    public function testTermeneCounterShowsActiveExpiredCompleted(): void
    {
        $this->attachDeadline(DeadlineType::RASPUNS_SOMATIE, DeadlinePriority::HIGH, new \DateTimeImmutable('+5 days'));
        $this->attachDeadline(DeadlineType::DEPUNERE_CERERE, DeadlinePriority::MEDIUM, new \DateTimeImmutable('-3 days'));
        $this->attachDeadline(DeadlineType::OTHER, DeadlinePriority::LOW, new \DateTimeImmutable('-10 days'), true);
        $this->em->clear();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-termene', '1 active');
        self::assertSelectorTextContains('#panel-termene', '1 expirate');
        self::assertSelectorTextContains('#panel-termene', '1 completat');
    }

    public function testTermeneEmptyStateWhenNoDeadlines(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-termene', 'Niciun termen creat încă');
    }

    public function testTermeneCardHighRendersAmberGradient(): void
    {
        $this->attachDeadline(DeadlineType::RASPUNS_SOMATIE, DeadlinePriority::HIGH, new \DateTimeImmutable('+7 days'));
        $this->em->clear();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('from-amber-50', $html, 'HIGH priority card must use amber-orange gradient');
    }

    public function testTermeneCardCompletedRendersLineThrough(): void
    {
        $this->attachDeadline(DeadlineType::OTHER, DeadlinePriority::MEDIUM, new \DateTimeImmutable('-5 days'), true, 'Recipisa atașată.');
        $this->em->clear();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $struck = $crawler->filter('#panel-termene .line-through');
        self::assertGreaterThan(0, $struck->count(), 'Completed deadline card must use line-through styling');
        self::assertSelectorTextContains('#panel-termene', 'făcut');
    }

    public function testTermeneCalendarShowsUpcomingDeadline(): void
    {
        $this->attachDeadline(DeadlineType::RASPUNS_SOMATIE, DeadlinePriority::HIGH, new \DateTimeImmutable('+7 days'));
        $this->em->clear();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        // The calendar sidebar must list the deadline type label within 30-day window.
        self::assertSelectorTextContains('#panel-termene', 'Calendar termene');
        self::assertSelectorTextContains('#panel-termene', 'Răspuns somație');
    }

    public function testTermeneCalendarEmptyFallback(): void
    {
        // Deadline beyond 30 days — should not appear in the calendar, fallback renders.
        $this->attachDeadline(DeadlineType::PRESCRIPTIE, DeadlinePriority::LOW, new \DateTimeImmutable('+2 years'));
        $this->em->clear();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-termene', 'niciun termen apropiat');
    }

    public function testPortalConfigRendersInactiveBadgeWhenNoCourtCaseNumber(): void
    {
        // Base setUp case has no courtCaseNumber — proxy „monitoring inactive".
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-portal', 'NEACTIVATĂ');
    }

    public function testPortalConfigRendersActivationForm(): void
    {
        // Pas 6.1 — config card wired: form POST către case_portal_activate cu
        // input editabil + token CSRF + buton submit (NU mai e shell aria-disabled).
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('#panel-portal form[action$="/portal/activate"]');
        self::assertGreaterThan(0, $form->count(), 'Activation form must be present');
        self::assertGreaterThan(0, $form->filter('input[name="portal_activate[courtCaseNumber]"]')->count());
        self::assertGreaterThan(0, $form->filter('input[name="portal_activate[_token]"]')->count());
        self::assertSame(0, $crawler->filter('#panel-portal input[aria-disabled="true"]')->count());
    }

    public function testPortalTimelineEmptyStateWhenNoEvents(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-portal', 'Niciun eveniment monitorizat încă');
    }

    public function testPortalSyncStatusShowsNeporneetWhenNeverChecked(): void
    {
        // Base case has lastPortalCheckAt = null → italic fallback in sync status sidebar.
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-portal', 'încă nepornită');
    }

    public function testPortalConfigRendersActiveBadgeWhenMonitoringActive(): void
    {
        // Pas 6.1 — badge „ACTIVĂ" derivat din portalMonitoringActive (NU din
        // courtCaseNumber). Activăm monitorizarea și verificăm trecerea pe verde.
        $this->case->setCourtCaseNumber('4521/302/2026');
        $this->case->setPortalMonitoringActive(true);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-portal', 'ACTIVĂ');
    }

    public function testPortalHowItWorksRenders4Steps(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        // Sidebar „Cum funcționează" must list all 4 numbered steps (plain language).
        self::assertSelectorTextContains('#panel-portal', 'Cum funcționează');
        self::assertSelectorTextContains('#panel-portal', 'Verificăm dosarul automat');
        self::assertSelectorTextContains('#panel-portal', 'Identificăm noutățile');
        self::assertSelectorTextContains('#panel-portal', 'Actualizăm dosarul automat');
        self::assertSelectorTextContains('#panel-portal', 'Primești email și notificare');
    }

    public function testPortalRulingProposalCardShownForDetectedSolution(): void
    {
        $this->case->setStatus(CaseStatus::TERMEN_FIXAT);
        $event = $this->attachPortalEvent(PortalEventType::HEARING_COMPLETED, new \DateTimeImmutable('2026-03-10'));
        $event->setSolutie('Admite cererea');
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#portal-ruling-proposal');
        self::assertSelectorTextContains('#portal-ruling-proposal', 'Soluție detectată pe portal');
        // The confirm CTA targets the issue-ruling modal (emite_ordonanta).
        self::assertSame(
            1,
            $crawler->filter('#portal-ruling-proposal button[data-hs-overlay="#hs-modal-issue-ruling"]')->count(),
        );
    }

    public function testModalCloseCasePresentInDom(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#hs-modal-close-case[role="dialog"]');
        self::assertSelectorTextContains('#hs-modal-close-case', 'Confirmă închiderea dosarului');
    }

    public function testModalCloseCaseReasonSelectHas4Options(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        // From a non-EXECUTARE case (here AMIABIL): 3 real options + 1 placeholder
        // = 4 <option> tags (PAID, PARTIAL, ABANDONED). INSOLVENT_EXECUTARE is
        // status-gated and only appears from EXECUTARE (R1 workflow revision).
        self::assertCount(4, $crawler->filter('#close_case_reason_field option'));
    }

    public function testModalCloseCaseConfirmButtonIsActive(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        // Pas 7.2 wired the close-case modal to a real backend (`case_transition_close`),
        // so the previously aria-disabled submit is now a functional `type="submit"`.
        $form = $crawler->filter('#hs-modal-close-case form[action*="/transition/close"]');
        self::assertGreaterThan(0, $form->count(), 'Close-case modal must POST to case_transition_close.');
        $submit = $crawler->filter('#hs-modal-close-case button[type="submit"]');
        self::assertGreaterThan(0, $submit->count(), 'Close-case confirm button must be an active submit.');
    }

    public function testModalGenerateOpPresentInDom(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#hs-modal-cerere-op[role="dialog"]');
        self::assertSelectorTextContains('#hs-modal-cerere-op', 'Generează pachet cerere OP');
    }

    public function testModalGenerateOpDisclaimerRendersCourtName(): void
    {
        $this->enrichCase();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        // Disclaimer text dynamically interpolates court.name.
        self::assertSelectorTextContains('#hs-modal-cerere-op', $this->case->getCourt()->getName());
    }

    public function testModalGenerateOpConfirmButtonSubmitsForm(): void
    {
        // Pas 5.2 — modalul are acum form POST cu submit button (NU mai aria-disabled).
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('#hs-modal-cerere-op form[action$="/payment-order/generate"]');
        self::assertGreaterThan(0, $form->count(), 'Pas 5.2: modalul cerere OP trebuie să aibă form POST către case_payment_order_generate.');

        $submit = $crawler->filter('#hs-modal-cerere-op button[type="submit"]');
        self::assertGreaterThan(0, $submit->count(), 'Submit button trebuie să fie funcțional (NU aria-disabled).');
    }

    public function testHeroGenerateOpCtaTriggersModalViaDataHsOverlay(): void
    {
        // The hero primary CTA is status-driven: "Generează cerere OP" (modal trigger)
        // appears only once the summons has been sent (status SOMATIE_TRIMISA).
        $this->case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        // Hero CTA must wire `data-hs-overlay` to the modal (NOT aria-disabled).
        $cta = $crawler->filter('button[data-hs-overlay="#hs-modal-cerere-op"]')->reduce(static function ($node) {
            return str_contains($node->text(), 'Generează cerere OP');
        });
        self::assertGreaterThan(0, $cta->count(), 'Hero CTA must trigger modal via data-hs-overlay');
    }

    public function testHeroPrimaryCtaIsSendSummonsWhenStatusAmiabil(): void
    {
        // Base setup case is AMIABIL — the next workflow action is sending the summons,
        // exposed as a POST form (NOT the payment-order modal).
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $summonsSubmit = $crawler->filter('#case-hero-actions form[action$="/summons/generate"] button[type="submit"]');
        self::assertGreaterThan(0, $summonsSubmit->count(), 'AMIABIL hero CTA must be the summons submit form');
        self::assertSelectorTextContains('#case-hero-actions form[action$="/summons/generate"]', 'Trimite somație');

        // The payment-order modal trigger must NOT be present in the hero while AMIABIL.
        $opCta = $crawler->filter('#case-hero-actions button[data-hs-overlay="#hs-modal-cerere-op"]');
        self::assertSame(0, $opCta->count(), 'OP modal trigger must not appear in hero before the summons is sent');
    }



    // ===== filing readiness in the generation dialog and the package card =====

    private function submitDisabled(\Symfony\Component\DomCrawler\Crawler $crawler): bool
    {
        return $crawler->filter('#hs-modal-cerere-op button[type="submit"]')->attr('disabled') !== null;
    }

    /** Everything the dialog checks besides the filing pieces, so only those can disable it. */
    private function readyForGenerationExceptFilingPieces(): void
    {
        $this->enrichCase(CaseStatus::SOMATIE_TRIMISA);
        $this->case->setStampDutyStatus(StampDutyStatus::ACHITATA);
        $this->case->setPaymentNoticeDate(new \DateTime('2026-02-01'));
        $this->em->flush();
        $this->attachDocument(DocumentType::DOVADA_COMUNICARE);
    }

    private function attachPowerOfAttorney(?string $date): Document
    {
        $doc = $this->attachDocument(DocumentType::IMPUTERNICIRE_AVOCATIALA, filename: 'imputernicire.pdf');
        $doc->setPowerOfAttorneyDate($date !== null ? new \DateTimeImmutable($date) : null);
        $this->em->flush();

        return $doc;
    }

    public function testGenerationDialogBlocksWithoutThePowerOfAttorneyAndOffersTheUpload(): void
    {
        $this->readyForGenerationExceptFilingPieces();
        $this->attachDocument(DocumentType::FACTURA);
        $this->em->clear();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        $blocker = $crawler->filter('#hs-modal-cerere-op [data-testid="filing-blocker-power_of_attorney_missing"]');
        self::assertCount(1, $blocker);
        self::assertCount(1, $blocker->filter('button[data-upload-preset-type="imputernicire_avocatiala"]'));
        self::assertStringNotContainsString('Puteți continua', $crawler->filter('#hs-modal-cerere-op')->text());
        self::assertTrue($this->submitDisabled($crawler));
    }

    public function testGenerationDialogOffersToCompleteAnUndatedPowerOfAttorney(): void
    {
        $this->readyForGenerationExceptFilingPieces();
        $this->attachDocument(DocumentType::FACTURA);
        $poa = $this->attachPowerOfAttorney(null);
        $this->em->clear();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        $blocker = $crawler->filter('#hs-modal-cerere-op [data-testid="filing-blocker-power_of_attorney_date_missing"]');
        self::assertCount(1, $blocker);
        $cta = $blocker->filter('button[data-hs-overlay="#hs-modal-power-of-attorney"]');
        self::assertCount(1, $cta);
        self::assertStringEndsWith('/document/' . $poa->getId() . '/power-of-attorney', (string) $cta->attr('data-poa-edit-url'));
        self::assertTrue($this->submitDisabled($crawler));
        self::assertSelectorExists('#hs-modal-power-of-attorney form');
    }

    public function testGenerationDialogBlocksWithoutADocumentTheClaimRestsOn(): void
    {
        $this->readyForGenerationExceptFilingPieces();
        $this->attachPowerOfAttorney('2026-01-15');
        $this->em->clear();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        $blocker = $crawler->filter('#hs-modal-cerere-op [data-testid="filing-blocker-claim_document_missing"]');
        self::assertCount(1, $blocker);
        self::assertCount(1, $blocker->filter('button[data-upload-preset-type="factura"]'));
        self::assertTrue($this->submitDisabled($crawler));
    }

    /** The package card lists the power of attorney and the claim documents, flagged until they are on the case. */
    public function testTheZipCardFlagsTheMissingPowerOfAttorneyAndClaimDocuments(): void
    {
        $this->readyForGenerationExceptFilingPieces();
        $this->em->clear();
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());
        self::assertStringContainsString('!', $crawler->filter('[data-testid="zip-item-power-of-attorney"]')->text());
        self::assertStringContainsString('!', $crawler->filter('[data-testid="zip-item-evidence"]')->text());
        self::assertStringNotContainsString('Contract + factură', $crawler->text());
    }

    public function testTheZipCardTicksThePowerOfAttorneyAndClaimDocumentsOnceAttached(): void
    {
        $this->readyForGenerationExceptFilingPieces();
        $this->attachDocument(DocumentType::FACTURA);
        $this->attachPowerOfAttorney('2026-01-15');
        $this->em->clear();
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());
        $poa = $crawler->filter('[data-testid="zip-item-power-of-attorney"]')->text();
        self::assertStringNotContainsString('!', $poa);
        self::assertStringContainsString(static::getContainer()->get('translator')->trans('case_overview.zip_package.item_power_of_attorney'), $poa);
        self::assertStringNotContainsString('!', $crawler->filter('[data-testid="zip-item-evidence"]')->text());
    }

    /** Invoices without a contract are enough: the dialog shows no contract warning. */
    public function testAMissingContractNeitherWarnsNorDisablesGeneration(): void
    {
        $this->readyForGenerationExceptFilingPieces();
        $this->attachDocument(DocumentType::FACTURA);
        $this->attachPowerOfAttorney('2026-01-15');
        $this->em->clear();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertCount(0, $crawler->filter('#hs-modal-cerere-op [data-testid^="filing-warning-"]'));
        self::assertCount(0, $crawler->filter('#hs-modal-cerere-op [data-testid^="filing-blocker-"]'));
        self::assertFalse($this->submitDisabled($crawler));
    }

    public function testAPowerOfAttorneyDatedAfterTheSummonsWarnsWithBothDates(): void
    {
        $this->readyForGenerationExceptFilingPieces();
        $this->attachDocument(DocumentType::FACTURA);
        $this->attachDocument(DocumentType::CONTRACT);
        $this->attachPowerOfAttorney('2026-02-03');
        $this->em->clear();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        $warning = $crawler->filter('#hs-modal-cerere-op [data-testid="filing-warning-power_of_attorney_after_summons"]');
        self::assertCount(1, $warning);
        self::assertStringContainsString('03.02.2026', $warning->text());
        self::assertStringContainsString('01.02.2026', $warning->text());
        self::assertStringContainsString('generării somației', $warning->text());
        self::assertFalse($this->submitDisabled($crawler));
    }

    /**
     * A case set to CERERE_GENERATA by the admin override, without the power of
     * attorney: the package card shows the way forward instead of a download the
     * server would refuse.
     */
    public function testThePackageCardOffersThePowerOfAttorneyInsteadOfARefusedDownload(): void
    {
        $this->readyForGenerationExceptFilingPieces();
        $this->case->setStatus(CaseStatus::CERERE_GENERATA);
        $this->em->flush();
        $this->attachDocument(DocumentType::FACTURA);
        $this->attachDocument(DocumentType::SOMATIE);
        $this->attachDocument(DocumentType::CERERE_OP);
        $this->em->clear();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        $blocker = $crawler->filter('#panel-documente [data-testid="zip-blocker-power_of_attorney_missing"]');
        self::assertCount(1, $blocker);
        self::assertCount(1, $blocker->filter('button[data-upload-preset-type="imputernicire_avocatiala"]'));
        self::assertCount(0, $crawler->filter('#panel-documente a[href$="/zip-package/download"]'));
    }

    #[DataProvider('legalCostsEditableStatuses')]
    public function testLegalCostsCardShowsValuesAndEditButtonWhileAllowed(CaseStatus $status): void
    {
        $this->enrichCase($status);
        $this->case->setLegalCostsFixed('1500.00');
        $this->case->setLegalCostsCurrency('RON');
        $this->case->setLegalCostsSuccessPercent('7.50');
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-testid="case-legal-costs-card"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('1.500,00 RON', $card->filter('[data-testid="legal-costs-fixed"]')->text());
        self::assertStringContainsString('7,5%', $card->filter('[data-testid="legal-costs-success"]')->text());
        self::assertCount(1, $card->filter('[data-testid="legal-costs-edit"]'));
        self::assertCount(0, $card->filter('[data-testid="legal-costs-locked-note"]'));
        self::assertCount(1, $crawler->filter('[data-testid="modal-legal-costs"]'));
        self::assertSame('1500', $crawler->filter('#legal_costs_legalCostsFixed')->attr('value'));
    }

    /** @return iterable<string, array{CaseStatus}> */
    public static function legalCostsEditableStatuses(): iterable
    {
        yield 'amiable' => [CaseStatus::AMIABIL];
        yield 'summons sent' => [CaseStatus::SOMATIE_TRIMISA];
    }

    public function testLegalCostsCardIsLockedFromPetitionGenerated(): void
    {
        $this->enrichCase(CaseStatus::CERERE_GENERATA);

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-testid="case-legal-costs-card"]');
        self::assertCount(1, $card);
        self::assertCount(0, $card->filter('[data-testid="legal-costs-edit"]'));
        self::assertCount(1, $card->filter('[data-testid="legal-costs-locked-note"]'));
        self::assertCount(0, $crawler->filter('[data-testid="modal-legal-costs"]'));
    }

    public function testNotesCardIsVisibleToTheOwnerInAnyStatus(): void
    {
        foreach ([CaseStatus::AMIABIL, CaseStatus::DOSAR_INREGISTRAT, CaseStatus::INCHIS_SUCCES] as $status) {
            $this->case->setStatus($status);
            $this->case->setNotes('Clientul revine luni.');
            $this->em->flush();

            $this->client->loginUser($this->user);
            $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

            self::assertResponseIsSuccessful($status->value);
            self::assertCount(1, $crawler->filter('[data-testid="case-notes-card"]'), $status->value);
            self::assertSame('Clientul revine luni.', $crawler->filter('[data-testid="case-notes-textarea"]')->text(), $status->value);
        }
    }

    public function testNotesCardIsHiddenFromTheAdmin(): void
    {
        $this->enrichCase();
        // Saved through the service, so the audit tab carries an entry with the text too.
        static::getContainer()->get(CaseNotesService::class)->update($this->case, 'Secret profesional.', $this->user);
        $admin = new User();
        $admin->setEmail('overview-admin-' . uniqid() . '@test.com');
        $admin->setPassword('x');
        $admin->setIsVerified(true);
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();
        $this->extraUserIds[] = $admin->getId();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="case-notes-card"]'));
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Case Notes Edited', $page, 'The audit tab says the notes changed.');
        self::assertStringNotContainsString('Secret profesional.', $page, 'Neither the card nor the audit entry shows the text.');
    }

    public function testOutdatedPetitionShowsTheBadge(): void
    {
        $this->enrichCase(CaseStatus::CERERE_GENERATA);
        $petition = $this->attachDocument(DocumentType::CERERE_OP);
        $petition->setOutdatedAt(new \DateTimeImmutable('-1 day'));
        $this->em->flush();
        $this->em->clear();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#panel-documente [data-testid="payment-order-outdated-badge"]'));
    }

    public function testClaimDetailsDialogContainsTheAccessoryAndContractFields(): void
    {
        $this->enrichCase();
        $this->case->setPenaltyType(PenaltyType::CONTRACTUAL);
        $this->case->setContractualPenaltyRate('0.150');
        $this->case->setContractualPenaltyCapPercent('10.00');
        $this->case->setContractNumber('58');
        $this->case->setContractDate(new \DateTime('2024-01-10'));
        $this->case->setAccessoryCutoffDate(new \DateTimeImmutable('-10 days'));
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $dialog = $crawler->filter('[data-testid="modal-claim-details"]');
        self::assertCount(1, $dialog);
        self::assertSame('CONTRACTUAL', $dialog->filter('#claim_details_penaltyType option[selected]')->attr('value'));
        self::assertSame('0.15', $dialog->filter('#claim_details_contractualPenaltyRate')->attr('value'));
        self::assertSame('10', $dialog->filter('#claim_details_contractualPenaltyCapPercent')->attr('value'));
        self::assertCount(1, $dialog->filter('#claim_details_contractualAccessoryLabel'));
        self::assertSame((new \DateTimeImmutable('-10 days'))->format('Y-m-d'), $dialog->filter('#claim_details_accessoryCutoffDate')->attr('value'));
        self::assertSame('58', $dialog->filter('#claim_details_contractNumber')->attr('value'));
        self::assertSame('2024-01-10', $dialog->filter('#claim_details_contractDate')->attr('value'));
        self::assertCount(1, $dialog->filter('#claim_details_contractObject'));
        $contractual = $dialog->filter('[data-conditional-fields-target="conditional"]');
        self::assertCount(1, $contractual);
        self::assertStringNotContainsString('hidden', (string) $contractual->attr('class'), 'The contractual section is open for a contractual case.');
    }

    /**
     * Every action the Detalii sidebar used to offer keeps a place in the hero: the
     * primary CTA, the outlined amicable-payment button or the actions menu.
     *
     * @return iterable<string, array{CaseStatus, bool, list<string>, list<string>}>
     */
    public static function heroActionMap(): iterable
    {
        yield 'AMIABIL' => [CaseStatus::AMIABIL, false, [
            '[data-testid="hero-primary-cta"]',
            '[data-testid="hero-full-payment-action"][data-hs-overlay="#hs-modal-full-payment"]',
            '[data-testid="hero-menu-full-payment"][data-hs-overlay="#hs-modal-full-payment"]',
        ], ['#hs-modal-full-payment']];
        yield 'SOMATIE_TRIMISA' => [CaseStatus::SOMATIE_TRIMISA, false, [
            '[data-testid="hero-primary-cta"][data-hs-overlay="#hs-modal-cerere-op"]',
            '[data-testid="hero-full-payment-action"]',
            '[data-testid="hero-menu-full-payment"]',
        ], ['#hs-modal-cerere-op', '#hs-modal-full-payment']];
        yield 'CERERE_GENERATA' => [CaseStatus::CERERE_GENERATA, false, [
            '[data-testid="hero-primary-cta"][data-hs-overlay="#hs-modal-confirm-filing"]',
        ], ['#hs-modal-confirm-filing']];
        yield 'CERERE_DEPUSA' => [CaseStatus::CERERE_DEPUSA, false, [
            '[data-testid="hero-primary-cta"][data-controller="goto-portal-discover"]',
        ], []];
        yield 'TERMEN_FIXAT' => [CaseStatus::TERMEN_FIXAT, false, [
            '[data-testid="hero-primary-cta"][data-hs-overlay="#hs-modal-issue-ruling"]',
            '[data-testid="hero-menu-reject"][data-hs-overlay="#hs-modal-reject"]',
        ], ['#hs-modal-issue-ruling', '#hs-modal-reject']];
        yield 'ORDONANTA_EMISA communicated' => [CaseStatus::ORDONANTA_EMISA, true, [
            '[data-testid="hero-primary-cta"][data-hs-overlay="#hs-modal-executare"]',
        ], ['#hs-modal-executare']];
        yield 'IN_ANULARE' => [CaseStatus::IN_ANULARE, false, [
            '[data-testid="hero-primary-cta"][data-hs-overlay="#hs-modal-executare"]',
            '[data-testid="hero-menu-grant-annulment"][data-hs-overlay="#hs-modal-reject"]',
            '[data-testid="hero-menu-annulment-rejected"][data-hs-overlay="#hs-modal-annulment-rejected"]',
        ], ['#hs-modal-executare', '#hs-modal-reject', '#hs-modal-annulment-rejected']];
        yield 'DEFINITIVA' => [CaseStatus::DEFINITIVA, false, [
            '[data-testid="hero-primary-cta"][data-hs-overlay="#hs-modal-executare"]',
            '[data-hs-overlay="#hs-modal-close-case"]',
        ], ['#hs-modal-executare', '#hs-modal-close-case']];
        yield 'EXECUTARE' => [CaseStatus::EXECUTARE, false, [
            '[data-testid="hero-primary-cta"][data-hs-overlay="#hs-modal-close-case"]',
            '[data-testid="hero-menu-grant-annulment"][data-hs-overlay="#hs-modal-reject"]',
            '[data-testid="hero-menu-annulment-rejected"][data-hs-overlay="#hs-modal-annulment-rejected"]',
        ], ['#hs-modal-close-case', '#hs-modal-reject', '#hs-modal-annulment-rejected']];
    }

    /**
     * @param list<string> $heroSelectors
     * @param list<string> $modals
     */
    #[DataProvider('heroActionMap')]
    public function testEverySidebarActionKeepsAPlaceInTheHero(CaseStatus $status, bool $rulingCommunicated, array $heroSelectors, array $modals): void
    {
        $this->enrichCase($status);
        if ($rulingCommunicated) {
            $this->case->setRulingCommunicationDate(new \DateTimeImmutable('-3 days'));
            $this->em->flush();
        }

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#case-hero-actions [data-testid="hero-primary-cta"]'), 'One next step.');
        foreach ($heroSelectors as $selector) {
            self::assertCount(1, $crawler->filter('#case-hero-actions ' . $selector), $selector);
        }
        foreach ($modals as $modal) {
            self::assertCount(1, $crawler->filter($modal), $modal);
        }
        self::assertCount(0, $crawler->filter('#case-hero-actions form [data-hs-overlay]'), 'Menu entries open dialogs, they post nothing themselves.');
    }

    public function testIssuedOrderOffersNoEnforcementBeforeItIsCommunicated(): void
    {
        $this->enrichCase(CaseStatus::ORDONANTA_EMISA);

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#case-hero-actions [data-hs-overlay="#hs-modal-executare"]');
    }

    public function testKpiShowsTheNextAlertOfTheActiveDeadline(): void
    {
        $this->enrichCase();
        $deadline = $this->attachDeadline(DeadlineType::PRESCRIPTIE, DeadlinePriority::CRITICAL, new \DateTimeImmutable('+400 days'));
        $offset = static::getContainer()->get(\App\Service\Deadline\DeadlineAlertService::class)->nextAlertDaysBefore($deadline);
        self::assertNotNull($offset, 'A term 400 days away still has every alert ahead of it.');
        $expected = $deadline->getDeadlineDate()->modify('-' . $offset . ' days')->format('d.m.Y');

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $translator = static::getContainer()->get('translator');
        self::assertStringContainsString($translator->trans(DeadlineType::PRESCRIPTIE->label()), $crawler->filter('[data-testid="kpi-active-deadline-line"]')->text());
        self::assertStringContainsString($deadline->getDeadlineDate()->format('d.m.Y'), $crawler->filter('[data-testid="kpi-active-deadline-line"]')->text());
        $alert = $crawler->filter('#case-kpi-grid [data-testid="kpi-next-alert"]');
        self::assertCount(1, $alert);
        self::assertStringContainsString('următoarea alertă', $alert->text());
        self::assertStringContainsString($expected, $alert->text());
    }

    public function testPositionsAreOrderedByDueDateWithExcludedLast(): void
    {
        $this->enrichCase();
        $this->attachClaimItem('INV-LATE', '1000.00', '-30 days');
        $excluded = $this->attachClaimItem('INV-EXCL', '500.00', '-400 days');
        $excluded->setExcludedByLawyer(true);
        $excluded->setExclusionReason(\App\Enum\ClaimExclusionReason::PAID_IN_FULL);
        $excluded->setExclusionNote('plata din 02.10.2026, transfer bancar cu ordinul de plată numărul 4471');
        $this->attachClaimItem('INV-OLD', '2000.00', '-200 days');
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-testid="claim-position-row"]');
        self::assertSame(['counting', 'counting', 'excluded'], $rows->each(static fn ($row) => $row->attr('data-claim-position-state')));
        self::assertStringContainsString('INV-OLD', $rows->eq(0)->text());
        self::assertStringContainsString('INV-LATE', $rows->eq(1)->text());
        self::assertStringContainsString('INV-EXCL', $rows->eq(2)->text());
        $reason = $rows->eq(2)->filter('[data-testid="claim-exclusion-reason"]');
        self::assertStringContainsString('truncate', (string) $reason->attr('class'));
        self::assertStringContainsString('Plătită integral: plata din 02.10.2026, transfer bancar cu ordinul de plată numărul 4471', (string) $reason->attr('title'));
    }

    public function testOnlyExceptionsCarryAPill(): void
    {
        $this->enrichCase();
        $this->attachClaimItem('INV-OK', '1000.00');
        $excluded = $this->attachClaimItem('INV-EXCL', '500.00');
        $excluded->setExcludedByLawyer(true);
        $fx = $this->attachClaimItem('INV-USD', '1200.00');
        $fx->setCurrency('USD');
        $fx->setAmountRon(null);
        $fx->setNeedsManualFx(true);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('#case-claim-card');
        self::assertStringNotContainsString('Confirmată', $card->text());
        self::assertSame(['excluded', 'not_counting'], $card->filter('[data-testid="claim-position-pill"]')->each(static fn ($pill) => $pill->attr('data-kind')));
        $fxRow = $crawler->filter('#claim-position-' . $fx->getId());
        self::assertStringContainsString('necalculat', $fxRow->text());
        self::assertStringContainsString('fără curs BNR', $fxRow->text());
        self::assertCount(0, $crawler->filter('#claim-position-' . $this->case->getClaimItems()->first()->getId() . ' [data-testid="claim-position-pill"]'));
    }

    public function testFxPositionShowsRateAndRonLine(): void
    {
        $this->enrichCase();
        $this->attachClaimItem('INV-RON', '1000.00');
        $eur = $this->attachClaimItem('INV-EUR', '2500.00');
        $eur->setCurrency('EUR');
        $eur->setAmountRon('12678.00');
        $eur->setExchangeRate('5.0712');
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $fx = $crawler->filter('#claim-position-' . $eur->getId() . ' [data-testid="claim-position-fx"]');
        self::assertCount(1, $fx);
        self::assertSame('curs BNR 5,0712 = 12.678,00 RON', trim($fx->text()));
        self::assertCount(1, $crawler->filter('[data-testid="claim-how-fx"]'), 'The currency sentence appears with a foreign position.');
    }

    public function testHowItIsCalculatedMentionsCurrencyOnlyWithAForeignPosition(): void
    {
        $this->enrichCase();
        $this->attachClaimItem('INV-1', '1000.00');

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $how = $crawler->filter('details[data-testid="claim-how-calculated"]');
        self::assertCount(1, $how);
        self::assertStringContainsString('Cum se calculează', $how->filter('summary')->text());
        self::assertStringContainsString('Pozițiile excluse rămân în dosar', $how->text());
        self::assertCount(0, $crawler->filter('[data-testid="claim-how-fx"]'));
    }

    public function testFiltersAppearAboveSixPositionsAndRowsBeyondEightStartHidden(): void
    {
        $this->enrichCase();
        for ($i = 1; $i <= 8; ++$i) {
            $this->attachClaimItem('INV-' . $i, '100.00', '-' . (300 - $i) . ' days');
        }
        $excluded = $this->attachClaimItem('INV-X', '100.00', '-400 days');
        $excluded->setExcludedByLawyer(true);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('9', $crawler->filter('[data-testid="claim-filter-all"]')->text());
        self::assertSame('true', $crawler->filter('[data-testid="claim-filter-all"]')->attr('aria-pressed'));
        self::assertStringContainsString('8', $crawler->filter('[data-testid="claim-filter-in"]')->text());
        self::assertStringContainsString('1', $crawler->filter('[data-testid="claim-filter-excluded"]')->text());
        self::assertCount(0, $crawler->filter('[data-testid="claim-filter-attention"]'), 'No position needs attention.');
        $groups = $crawler->filter('[data-testid="claim-position-group"]');
        self::assertCount(9, $groups);
        for ($i = 0; $i < 8; ++$i) {
            self::assertStringNotContainsString('hidden', (string) $groups->eq($i)->attr('class'));
        }
        self::assertStringContainsString('hidden', (string) $groups->eq(8)->attr('class'));
        self::assertSame('excluded', $groups->eq(8)->attr('data-filter-state'));
        $showAll = $crawler->filter('[data-testid="claim-positions-show-all"]');
        self::assertCount(1, $showAll);
        self::assertStringContainsString('Arată toate cele 9 poziții', $showAll->text());
        self::assertStringContainsString('încă 1', $showAll->text());
    }

    public function testNoFiltersUpToSixPositions(): void
    {
        $this->enrichCase();
        for ($i = 1; $i <= 6; ++$i) {
            $this->attachClaimItem('INV-' . $i, '100.00', '-' . (100 + $i) . ' days');
        }

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid^="claim-filter-"]'));
        self::assertCount(0, $crawler->filter('[data-testid="claim-positions-show-all"]'));
        self::assertCount(6, $crawler->filter('[data-testid="claim-position-row"]'));
    }

    public function testDetailsBlockListsContractAndSummonsNumber(): void
    {
        $this->enrichCase();
        $this->attachClaimItem('INV-1', '1000.00');
        $this->case->setContractNumber('58');
        $this->case->setContractDate(new \DateTime('2025-01-10'));
        $this->case->setContractObject('Execuție lucrări de instalații');
        $this->case->setPaymentNoticeNumber('114/2026');
        $this->case->setClaimDescription('Contravaloarea lucrărilor executate.');
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $block = $crawler->filter('[data-testid="claim-details-block"]');
        self::assertCount(1, $block);
        self::assertStringContainsString('Detaliile creanței', $block->text());
        self::assertSame('nr. 58 din 10.01.2025 · Execuție lucrări de instalații', trim($block->filter('[data-testid="claim-details-contract"] dd')->text()));
        self::assertSame('114/2026', trim($block->filter('[data-testid="claim-details-summons-number"] dd')->text()));
        self::assertStringContainsString('Dobândă legală penalizatoare', $block->filter('[data-testid="claim-details-mode"]')->text());
        self::assertStringContainsString('(se recalculează la data somației)', $block->filter('[data-testid="claim-details-reference"]')->text());
        self::assertStringContainsString('Contravaloarea lucrărilor executate.', $block->text());
        self::assertCount(1, $block->filter('[data-testid="claim-edit-details"][data-hs-overlay="#hs-modal-claim-details"]'));
        self::assertCount(0, $block->filter('[data-testid="claim-details-clause"]'), 'Legal interest has no penalty clause.');
    }

    public function testDetailsBlockShowsTheClauseOnAContractualPenalty(): void
    {
        $this->enrichCase(CaseStatus::SOMATIE_TRIMISA);
        $this->attachClaimItem('INV-1', '1000.00');
        $this->case->setPenaltyType(PenaltyType::CONTRACTUAL);
        $this->case->setContractualPenaltyRate('0.1000');
        $this->case->setContractualPenaltyCapPercent('10.00');
        $this->case->setPenaltyClauseArticle('art. 7.2');
        $this->case->setPenaltyClauseText('Penalități de 0,10% pe zi.');
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $block = $crawler->filter('[data-testid="claim-details-block"]');
        self::assertStringContainsString('0,10% pe zi, plafon 10% din sumă', $block->filter('[data-testid="claim-details-mode"]')->text());
        self::assertStringContainsString('art. 7.2: Penalități de 0,10% pe zi.', $block->filter('[data-testid="claim-details-clause"]')->text());
        self::assertStringContainsString('(data somației)', $block->filter('[data-testid="claim-details-reference"]')->text());
        self::assertCount(0, $block->filter('[data-testid="claim-edit-details"]'), 'Locked after the summons.');
    }

    public function testFooterTotalsAlignWithTheCaseFigures(): void
    {
        $this->enrichCase();
        $this->attachClaimItem('INV-1', '1000.00');
        $paid = $this->attachClaimItem('INV-2', '2000.00');
        $paid->setPaidAmount('500.00');
        $this->em->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $footer = $crawler->filter('[data-testid="claim-positions-footer"]');
        self::assertCount(1, $footer);
        self::assertStringContainsString('2 din 2 poziții', $footer->text());
        self::assertSame('500,00', trim($footer->filter('[data-testid="claim-footer-paid"]')->text()));
        self::assertSame('47.500,00', trim($footer->filter('[data-testid="claim-footer-principal"]')->text()), 'The principal the case states.');
        self::assertSame('+3.842,50', trim($footer->filter('[data-testid="claim-footer-accessory"]')->text()), 'The accessory the case states.');
    }

    public function testCaseWithoutPositionsShowsTheCaseBreakdown(): void
    {
        $this->enrichCase();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="claim-positions-table"]'));
        $breakdown = $crawler->filter('[data-testid="claim-case-breakdown"]');
        self::assertCount(1, $breakdown);
        self::assertStringContainsString('Calculul dobânzii', $breakdown->text());
        self::assertStringContainsString('Rata BNR', $breakdown->text());
        self::assertStringContainsString('3.842,50', $breakdown->text());
    }

    public function testTheOnlyCountingPositionCannotBeExcludedFromTheRowMenu(): void
    {
        $this->enrichCase();
        $only = $this->attachClaimItem('INV-1', '1000.00');

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $exclude = $crawler->filter('[data-testid="claim-exclude-' . $only->getId() . '"]');
        self::assertCount(1, $exclude);
        self::assertSame('true', $exclude->attr('aria-disabled'));
        self::assertNull($exclude->attr('data-hs-overlay'));
        self::assertStringContainsString('singura care intră în creanță', $exclude->text());
        self::assertCount(1, $crawler->filter('[data-testid="claim-position-menu-' . $only->getId() . '"]'));
    }
}
