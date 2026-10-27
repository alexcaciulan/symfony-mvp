<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\FilingPackage;
use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\FilingPackageStatus;
use App\Enum\FilingPartStatus;
use App\Service\Filing\UncertainPartResolver;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use App\Tests\Support\RecordingCourtTransport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class CaseFilingTabTest extends WebTestCase
{
    use FilingCaseFixtureTrait;
    use FilingSubmissionTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;
    /** @var list<User> */
    private array $otherUsers = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('app.uploads_dir');
        $this->user = $this->createFilingUser($this->em, 'filing-tab');
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 2);
        $this->courtTransport()->reset();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->getConnection()->executeStatement('DELETE FROM audit_log WHERE entity_type = ? AND entity_id = ?', [LegalCase::class, (string) $this->case->getId()]);
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
            foreach ($this->otherUsers as $other) {
                $this->removeFilingFixtures($this->em, $other, null);
            }
        }
        parent::tearDown();
    }

    public function testTheTabSitsBetweenDocumentsAndDeadlinesAndOpensFromTheQuery(): void
    {
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', $this->tabUrl());

        self::assertResponseIsSuccessful();
        $tabs = $crawler->filter('#case-tabs-nav [role="tab"]')->each(static fn ($t) => (string) $t->attr('id'));
        self::assertSame(['tab-detalii', 'tab-documente', 'tab-depunere', 'tab-termene'], array_slice($tabs, 0, 4));
        self::assertSelectorExists('#tab-depunere.active');
        self::assertSelectorExists('#panel-depunere:not(.hidden) #filing-package-card [data-testid="filing-stepper"]');
        self::assertSelectorExists('#panel-documente.hidden');
        self::assertSelectorTextContains('#tab-depunere', 'Depunere la instanță');
    }

    public function testTheTabExplainsItselfBeforeThePetitionAndAfterTheFullPayment(): void
    {
        $lawyer = $this->createFilingUser($this->em, 'filing-tab-early');
        $this->otherUsers[] = $lawyer;
        $early = $this->createFilingCase($this->em, $lawyer, CaseStatus::SOMATIE_TRIMISA);
        $this->em->flush();
        $this->client->loginUser($lawyer);
        $this->client->request('GET', sprintf('/case/%d?tab=depunere', $early->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#tab-depunere.active');
        self::assertSelectorNotExists('#tab-depunere [data-testid="filing-tab-dot"] span');
        self::assertSelectorExists('#panel-depunere:not(.hidden) [data-testid="filing-tab-empty"][data-reason="before_petition"]');
        self::assertSelectorTextContains('[data-testid="filing-tab-empty"]', 'Depunerea devine disponibilă după generarea cererii');
        self::assertSelectorNotExists('#filing-package-card [data-testid="filing-stepper"]');
        self::assertSelectorNotExists('[data-testid="filing-documents-card"]');

        $this->case->setFullPaymentDate(new \DateTimeImmutable('2026-10-20'));
        $this->em->flush();
        $this->client->loginUser($this->user);
        $this->client->request('GET', $this->tabUrl());
        self::assertSelectorExists('#tab-depunere.active');
        self::assertSelectorNotExists('#tab-depunere [data-testid="filing-tab-dot"] span');
        self::assertSelectorExists('[data-testid="filing-tab-empty"][data-reason="full_payment"]');
        self::assertSelectorTextContains('[data-testid="filing-tab-empty"]', 'Debitorul a achitat integral la data de 20.10.2026.');
        self::assertSelectorNotExists('[data-testid="filing-documents-card"]');
    }

    public function testTheTabSaysWhenThePetitionWasFiledOutsideThePlatform(): void
    {
        $lawyer = $this->createFilingUser($this->em, 'filing-tab-other');
        $this->otherUsers[] = $lawyer;
        $filed = $this->createFilingCase($this->em, $lawyer, CaseStatus::DOSAR_INREGISTRAT);
        $filed->setFiledAt(new \DateTimeImmutable('2026-10-15'));
        $this->em->flush();
        $closedLawyer = $this->createFilingUser($this->em, 'filing-tab-closed');
        $this->otherUsers[] = $closedLawyer;
        $closed = $this->createFilingCase($this->em, $closedLawyer, CaseStatus::INCHIS_FARA_RECUPERARE);
        $this->client->loginUser($lawyer);

        $this->client->request('GET', sprintf('/case/%d?tab=depunere', $filed->getId()));
        self::assertSelectorExists('[data-testid="filing-tab-empty"][data-reason="other_channel"]');
        self::assertSelectorTextContains('[data-testid="filing-tab-empty"]', 'Data declarată a depunerii: 15.10.2026.');

        $filed->setFiledAt(null);
        $this->em->flush();
        $this->client->request('GET', sprintf('/case/%d?tab=depunere', $filed->getId()));
        self::assertSelectorTextContains('[data-testid="filing-tab-empty"]', 'Pe acest dosar nu există o transmitere prin e-mail din platformă.');
        self::assertSelectorTextNotContains('[data-testid="filing-tab-empty"]', 'Data declarată');

        $this->client->loginUser($closedLawyer);
        $this->client->request('GET', sprintf('/case/%d?tab=depunere', $closed->getId()));
        self::assertSelectorExists('[data-testid="filing-tab-empty"][data-reason="closed"]');
        self::assertSelectorTextContains('[data-testid="filing-tab-empty"]', 'Dosarul este finalizat');
    }

    public function testDocumentsKeepOnlyTheCompactCardAndTheHeroLeadsToTheTab(): void
    {
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', sprintf('/case/%d?tab=documente', $this->case->getId()));

        self::assertSelectorTextContains('#panel-documente [data-testid="filing-documents-card-title"]', 'Pachetul este semnat și gata de trimis');
        self::assertSelectorExists(sprintf('#panel-documente a[data-testid="filing-documents-card-open"][href="/case/%d?tab=depunere"]', $this->case->getId()));
        self::assertSelectorNotExists('#panel-documente [data-testid="filing-package-parts"]');
        self::assertSelectorNotExists('#panel-documente [data-testid="filing-send-open"]');
        // The hero only leads to the tab: no form, no modal.
        self::assertSelectorExists(sprintf('#case-hero-actions a[data-testid="hero-filing-link"][href="/case/%d?tab=depunere#depunere-status"]', $this->case->getId()));
        self::assertSelectorTextContains('#case-hero-actions [data-testid="hero-filing-link"]', 'Verifică și trimite');
        self::assertCount(0, $crawler->filter('#case-hero-actions form'));
        self::assertSelectorNotExists('#case-hero-actions [data-hs-overlay="#hs-modal-confirm-filing"]');
        self::assertSelectorExists('#case-recommended-actions [data-testid="recommended-filing-link"]');
        self::assertSelectorExists('[data-testid="filing-tab-dot"][data-tone="blue"]');
    }

    public function testAReadyPackageOffersTheReviewPageAsTheOnlyMainAction(): void
    {
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', $this->tabUrl());

        self::assertSelectorExists('#depunere-status[data-code="ready"]');
        self::assertSelectorTextContains('#depunere-status [data-testid="filing-submission-headline"]', 'Pachetul este semnat și gata de trimis');
        $primary = $crawler->filter('#depunere-status [data-testid="filing-panel-actions"] > *')->first();
        self::assertSame('filing-send-open', $primary->attr('data-testid'));
        self::assertSame(sprintf('/case/%d/filing/submission/confirm', $this->case->getId()), $primary->attr('href'));
        self::assertLessThanOrEqual(3, $crawler->filter('#depunere-status [data-testid="filing-panel-actions"] > *')->count());
        self::assertSelectorExists('[data-testid="filing-package-parts"]');
        self::assertSelectorExists('details[data-testid="filing-legal-notes"]:not([open])');
    }

    public function testASentPetitionAsksForTheFilingDateInThePanelWithoutSavingIt(): void
    {
        $this->sent();
        $this->client->loginUser($this->user);

        $this->client->request('GET', $this->tabUrl());

        self::assertSelectorTextContains('#depunere-status [data-testid="filing-submission-headline"]', 'Cererea a fost trimisă. Se așteaptă confirmarea de primire');
        self::assertSelectorTextContains('#depunere-status [data-testid="filing-panel-explanation"]', 'asta nu înseamnă înregistrarea cererii');
        self::assertSelectorExists('#depunere-status form[data-testid="filing-filed-date-form"] input#filing-filed-at[value=""]');
        self::assertSelectorTextContains('#depunere-status [data-testid="filing-filed-date-submit"]', 'Salvează data depunerii');
        self::assertSelectorExists('[data-testid="filing-email-timeline"] [data-testid="filing-submission-part-status-1"]');
        self::assertSelectorTextContains('[data-testid="filing-submission-part-status-1"]', 'Acceptat de serverul instanței');
        self::assertSelectorExists('[data-testid="filing-inbound-empty"]');
        self::assertSelectorExists('[data-testid="filing-tab-dot"][data-tone="blue"]');
        self::assertSelectorExists('[data-testid="filing-step-filed_date"][data-state="current"]');
        // The test environment is named once, in the court block, with the effective recipient.
        self::assertSelectorTextContains('[data-testid="filing-recipient-test-environment"]', 'Mediu de test');
        self::assertNull($this->em->getConnection()->fetchOne('SELECT filed_at FROM legal_case WHERE id = ?', [$this->case->getId()]));
        $html = (string) $this->client->getResponse()->getContent();
        foreach (['Cererea a fost depusă', 'Cererea a fost înregistrată', 'primită de instanță'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
    }

    public function testAnUndeliveredPetitionShowsTheShortPrescriptionNoteAndOpensTheLegalNotes(): void
    {
        $submission = $this->sent();
        $part = $submission->getPart(1);
        $part->recordAsyncFailure(FilingPartStatus::FAILED_PERMANENT, '5.1.1', 'Delivery report: 550 5.1.1 User unknown');
        $submission->markUndelivered();
        $this->case->setStatus(CaseStatus::CERERE_GENERATA);
        $submission->getPackage()->setStatus(FilingPackageStatus::SIGNED);
        $this->em->flush();
        $this->client->loginUser($this->user);

        $this->client->request('GET', $this->tabUrl());

        self::assertSelectorExists('#depunere-status[data-tone="red"]');
        self::assertSelectorTextContains('#depunere-status [data-testid="filing-submission-headline"]', 'Cererea nu a ajuns la instanță: adresa nu există');
        self::assertSelectorTextContains('#depunere-status [data-testid="filing-submission-undelivered-deadline"]', 'NCC art. 2540');
        self::assertSelectorExists('details[data-testid="filing-legal-notes"][open]');
        self::assertSelectorTextContains('details[data-testid="filing-legal-notes"]', 'Text provizoriu');
        self::assertSelectorExists('[data-testid="filing-tab-dot"][data-tone="red"]');
        // The server's code stays in the technical details, folded.
        self::assertSelectorExists('details[data-testid="filing-technical-details"]:not([open]) [data-testid="filing-submission-undelivered-diagnostic"]');
        self::assertSelectorTextNotContains('#depunere-status [data-testid="filing-submission-headline"]', '5.1.1');
    }

    public function testAnUncertainEmailOffersTwoEqualDeclarations(): void
    {
        $submission = $this->submitFiling($this->case, $this->user);
        $submission->getPart(1)?->setStatus(FilingPartStatus::UNCERTAIN);
        $submission->markNeedsReview('filing.status.review.part_uncertain');
        $this->em->flush();
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', $this->tabUrl());

        self::assertSelectorExists('#depunere-status[data-code="uncertain"]');
        self::assertCount(2, $crawler->filter('[data-testid="filing-submission-uncertain-actions"] form'));
        self::assertSelectorExists('[data-testid="filing-cc-received-card"] input[type="checkbox"][required]:not([checked])');
        self::assertSelectorExists('[data-testid="filing-cc-not-received-card"] input[type="checkbox"][required]:not([checked])');
        self::assertSelectorTextContains('[data-testid="filing-cc-not-received-card"]', 'dosarul rămâne la „Cerere generată”');
        self::assertSelectorExists('[data-testid="filing-cc-where"]');
        self::assertSelectorExists('[data-testid="filing-tab-dot"][data-tone="amber"]');

        // After the lawyer's statement the panel and the email row say what was declared, never "not sent".
        $part = $this->em->getRepository(FilingSubmission::class)->find($submission->getId())?->getPart(1);
        self::assertNotNull($part);
        static::getContainer()->get(UncertainPartResolver::class)->declareNotReceived($part, $this->em->getRepository(User::class)->find($this->user->getId()), [UncertainPartResolver::CONFIRM_NOT_RECEIVED => true]);
        $this->client->request('GET', $this->tabUrl());
        self::assertSelectorExists('#depunere-status[data-code="cc_not_received_petition"]');
        self::assertSelectorTextContains('[data-testid="filing-submission-part-status-1"]', 'copia în CC nu a fost primită');
        self::assertSelectorTextNotContains('#depunere-status', 'netrimis');
        self::assertSelectorExists('#depunere-status [data-testid="filing-send-open"]');
    }

    public function testAStalledSendOffersTheResumeAndClosesOnlyThroughTheDialog(): void
    {
        $this->stalledAtAnnex();
        $this->client->loginUser($this->user);

        $this->client->request('GET', $this->tabUrl());

        self::assertSelectorExists('#depunere-status[data-code="stalled"]');
        self::assertSelectorTextContains('#depunere-status [data-testid="filing-submission-resume"]', 'Trimite anexele rămase');
        self::assertSelectorExists('#depunere-status button[data-testid="filing-submission-close-open"][data-action="modal-dialog#open"]');
        self::assertSelectorNotExists('#depunere-status form[data-testid="filing-submission-close-form"]');
        self::assertSelectorExists('dialog#filing-close-dialog form[data-testid="filing-submission-close-form"] input[name="confirm_incomplete"][required]');
        self::assertSelectorExists('[data-testid="filing-submission-incomplete-warning"]');
        // The codes and every attempt are in the folded technical details only.
        self::assertSelectorExists('details[data-testid="filing-technical-details"]:not([open]) [data-testid="filing-submission-attempts-2"]');
        self::assertSelectorTextContains('[data-testid="filing-submission-attempts-2"]', 'SMTP 554');
        self::assertSelectorTextNotContains('#depunere-status', 'SMTP 554');
    }

    public function testAnAdminSeesTheTabWithoutDownloadsOrActions(): void
    {
        $this->sent();
        $admin = $this->createFilingUser($this->em, 'filing-tab-admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->otherUsers[] = $admin;
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', $this->tabUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#depunere-status');
        self::assertSelectorNotExists('#filing-package-card a[href*="/filing/"]');
        self::assertSelectorNotExists('[data-testid="filing-filed-date-form"]');
        self::assertCount(0, $crawler->filter('#filing-package-card form'));
    }

    public function testADocumentUploadRefreshesTheFilingTabInPlace(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/case/' . $this->case->getId());
        $token = (string) $crawler->filter('input[name="document_upload[_token]"]')->first()->attr('value');
        $path = tempnam(sys_get_temp_dir(), 'doc') . '.pdf';
        file_put_contents($path, self::pdfBytes(['Contract nou']));

        $this->client->request(
            'POST',
            sprintf('/case/%d/document/upload', $this->case->getId()),
            ['document_upload' => ['_token' => $token, 'documentType' => 'contract']],
            ['document_upload' => ['file' => new UploadedFile($path, 'contract.pdf', 'application/pdf', null, true)]],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="update" target="panel-depunere">', $content);
        self::assertStringContainsString('<turbo-stream action="replace" target="filing-tab-dot">', $content);
        self::assertStringContainsString('data-code="stale"', $content);
    }

    private function tabUrl(): string
    {
        return sprintf('/case/%d?tab=depunere', $this->case->getId());
    }

    private function sent(): FilingSubmission
    {
        $lawyer = $this->em->getRepository(User::class)->find($this->user->getId());
        $case = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        self::assertInstanceOf(User::class, $lawyer);
        self::assertInstanceOf(LegalCase::class, $case);
        $submission = $this->submitFiling($case, $lawyer);
        $this->handleAllFilingMessages();
        $this->em->clear();
        $this->case = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        $submission = $this->em->getRepository(FilingSubmission::class)->find($submission->getId());
        self::assertInstanceOf(FilingSubmission::class, $submission);
        self::assertSame('SENT', $submission->getDeliveryStatus()->value);
        self::assertInstanceOf(FilingPackage::class, $submission->getPackage());

        return $submission;
    }

    private function stalledAtAnnex(): void
    {
        $submission = $this->submitFiling($this->case, $this->user);
        $this->handleFilingPart((int) $submission->getPart(1)?->getId());
        $this->courtTransport()->failNext = RecordingCourtTransport::rcptRefused(554, 'Transaction failed');
        try {
            $this->handleFilingPart((int) $submission->getPart(2)?->getId());
        } catch (HandlerFailedException) {
        }
    }
}
