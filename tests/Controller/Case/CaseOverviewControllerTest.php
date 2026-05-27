<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\Debtor;
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
use App\Enum\ExtractionStatus;
use App\Enum\PersonType;
use App\Enum\PortalEventType;
use App\Enum\RelationshipType;
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
        $conn->executeStatement('DELETE FROM debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
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
        $event->setEventDate($date);
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
    private function attachDocument(DocumentType $type, ?float $confidence = null, string $filename = 'test-document.pdf', int $sizeBytes = 102400): Document
    {
        $doc = new Document();
        $doc->setLegalCase($this->case);
        $doc->setDocumentType($type);
        $doc->setOriginalFilename($filename);
        $doc->setStoredFilename('stored-' . uniqid() . '.pdf');
        $doc->setFileSize($sizeBytes);
        $doc->setMimeType('application/pdf');
        $doc->setUploadedBy($this->user);
        $doc->setExtractionStatus(ExtractionStatus::COMPLETED);
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
        $debtor->setLegalCase($this->case);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Beta Solutions SRL');
        $debtor->setAddress('Str. Iuliu Maniu 152, București');
        $debtor->setCui('RO87654321');
        $debtor->setAdministrator('Constantin Marinescu');
        if ($debtorAnafStatus !== null) {
            $debtor->setAnafStatus($debtorAnafStatus);
        }
        $this->em->persist($debtor);

        $court = new Court();
        $court->setName('Test Court ' . uniqid());
        $court->setCounty('București');
        $court->setType(CourtType::JUDECATORIE);
        $this->em->persist($court);

        $this->case->setCreditor($creditor);
        $this->case->addDebtor($debtor);
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

    public function testDetaliiCourtSummaryRendersCountyHint(): void
    {
        $this->enrichCase();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        // Rule hint dynamically interpolates `court.county` into the i18n string.
        self::assertSelectorTextContains('body', 'în raza București');
        self::assertSelectorTextContains('body', 'CPC art. 1015');
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
        self::assertSelectorTextContains('#panel-detalii', 'calculul indisponibil');
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

    public function testDocumenteSourceListExtractionPctRendered(): void
    {
        $this->attachDocument(DocumentType::CONTRACT, 0.95, 'contract-95.pdf');
        $this->em->clear();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-documente', 'extracție 95%');
    }

    public function testDocumenteCommunicationWarningShownWhenNoProof(): void
    {
        // No DOVADA_COMUNICARE attached — warning must surface in the documents aside.
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-documente', 'Lipsește dovada comunicării');
    }

    public function testDocumenteCommunicationWarningHiddenWhenProofExists(): void
    {
        $this->attachDocument(DocumentType::DOVADA_COMUNICARE);
        $this->em->clear();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        // Target the warning section DOM element directly — robust against unrelated copy
        // changes that might accidentally contain the literal "Lipsește dovada comunicării".
        self::assertCount(0, $crawler->filter('#panel-documente section.bg-amber-50'));
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

    public function testPortalSampleTimelineRenders3PreviewEntries(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        // Sample preview shows 3 educational entries regardless of actual portalEvents.
        self::assertSelectorTextContains('#panel-portal', 'Exemplu de timeline');
        self::assertSelectorTextContains('#panel-portal', 'Ordonanță emisă');
        self::assertSelectorTextContains('#panel-portal', 'Termen judecată fixat');
        self::assertSelectorTextContains('#panel-portal', 'Dosar înregistrat la registratură');
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
        // Sidebar „Cum funcționează" must list all 4 numbered steps.
        self::assertSelectorTextContains('#panel-portal', 'Cum funcționează');
        self::assertSelectorTextContains('#panel-portal', 'Cron rulează zilnic');
        self::assertSelectorTextContains('#panel-portal', 'Detectare evenimente noi');
        self::assertSelectorTextContains('#panel-portal', 'Tranziții automate');
        self::assertSelectorTextContains('#panel-portal', 'Email + notificare');
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
        // 4 real options + 1 placeholder = 5 <option> tags. Pas 7.2 promoted the
        // select from a coming-soon placeholder to a real CloseCaseType form,
        // so the field id changed from `hs-modal-close-case-reason` to
        // `close_case_reason_field` and the option values are now uppercase
        // (PAID, PARTIAL, INSOLVENT, ABANDONED — matching the CloseReason enum).
        self::assertCount(5, $crawler->filter('#close_case_reason_field option'));
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
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());

        self::assertResponseIsSuccessful();
        // Hero CTA must wire `data-hs-overlay` to the modal (NOT aria-disabled).
        $cta = $crawler->filter('button[data-hs-overlay="#hs-modal-cerere-op"]')->reduce(static function ($node) {
            return str_contains($node->text(), 'Generează cerere OP');
        });
        self::assertGreaterThan(0, $cta->count(), 'Hero CTA must trigger modal via data-hs-overlay');
    }
}
