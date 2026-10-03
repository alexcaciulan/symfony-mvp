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
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
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
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case_debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM case_status_history WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement("DELETE FROM court WHERE name LIKE 'Test Court %'");
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);

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

    public function testFullPaymentActionAndModalOfferedOnAmiabilAndSomatie(): void
    {
        $this->client->loginUser($this->user);
        $this->enrichCase();

        foreach ([CaseStatus::AMIABIL, CaseStatus::SOMATIE_TRIMISA] as $status) {
            $this->case->setStatus($status);
            $this->em->flush();
            $this->client->request('GET', '/case/' . $this->case->getId());

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[data-testid="action-full-payment"][data-hs-overlay="#hs-modal-full-payment"]', $status->value);
            self::assertSelectorExists('#hs-modal-full-payment form[action$="/transition/full-payment"]', $status->value);
            self::assertSelectorNotExists('[data-testid="full-payment-multi-debtor"]', 'Single debtor: no multi-debtor warning.');
        }
    }

    public function testFullPaymentActionAbsentOnceRequestGenerated(): void
    {
        $this->enrichCase(CaseStatus::CERERE_GENERATA);
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertSelectorNotExists('[data-testid="action-full-payment"]');
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
        self::assertSelectorExists('[data-testid="action-full-payment-done"]');
        self::assertSelectorNotExists('[data-testid="action-full-payment"]');
        self::assertSelectorNotExists('#hs-modal-add-deadline');
        self::assertSelectorNotExists('#case-recommended-actions [data-hs-overlay="#hs-modal-cerere-op"]');
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

    public function testTabsNavRenders5ButtonsWithDataHsTab(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('button[data-hs-tab]'));
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

    public function testDetaliiClaimCompositionBarPercentagesMatch(): void
    {
        // Principal 47500 + Interest 3842.50 ⇒ total 51342.50 ⇒ ~92.5% / ~7.5%
        $this->enrichCase();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('92,5%', $html, 'Principal share rendered with Romanian decimal');
        self::assertStringContainsString('7,5%', $html, 'Interest share rendered with Romanian decimal');
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
        // Two interest-bearing invoices with distinct past due dates: the overview
        // must expose the BNR period breakdown per invoice (restored for multi-invoice
        // claims), not just the per-position summary row.
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
        }
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('2 facturi · OG 13/2011', $html);
        self::assertStringContainsString('Factură INV-A', $html);
        self::assertStringContainsString('Factură INV-B', $html);
        self::assertSelectorTextContains('#panel-detalii', 'Rata BNR');
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
        $breakdown = $crawler->filter('[data-testid="overview-penalty-breakdown"]');
        self::assertCount(1, $breakdown);
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
        $breakdown = $crawler->filter('[data-testid="overview-penalty-breakdown"]');
        self::assertStringContainsString('2 facturi', $breakdown->text());
        self::assertSame(2, $breakdown->filter('tbody tr')->count());
        self::assertStringContainsString('INV-A', $breakdown->text());
        self::assertStringContainsString('02.03.2025 → 23.03.2025', $breakdown->text());
    }

    public function testDetaliiShowsNoPenaltyTableForLegalInterest(): void
    {
        $this->enrichCase();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="overview-penalty-breakdown"]'));
    }

    public function testDetaliiCourtSummaryFallbackWhenNull(): void
    {
        // No enrichCase — base setup case has no court.
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-detalii', 'Instanță needeterminată');
    }

    public function testDetaliiRecommendedActionsStrikesGeneratedSummonsWhenStatusAdvanced(): void
    {
        $this->enrichCase(CaseStatus::SOMATIE_TRIMISA);
        $this->recordTransition('SOMATIE_TRIMISA');
        $this->em->clear(); // Detach so the request EM re-reads statusHistory from DB.

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        // The "Generează somație" entry must be line-through styled when summons is past.
        $struck = $crawler->filter('#panel-detalii .line-through');
        self::assertGreaterThan(0, $struck->count(), 'Summons row must be struck through when status is past AMIABIL');
        self::assertSelectorTextContains('#panel-detalii', 'deja generată');
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


}
