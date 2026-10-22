<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Service\AuditLogService;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class CaseFilingSubmissionControllerTest extends WebTestCase
{
    use FilingCaseFixtureTrait;
    use FilingSubmissionTestTrait;

    private const TURBO = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html'];

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
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->user = $this->createFilingUser($this->em, 'filing-send-ctrl');
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
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

    public function testConfirmPanelShowsTheCalculatedCourtTheValidatedAddressAndUntickedStatements(): void
    {
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', sprintf('/case/%d/filing/submission/confirm', $this->case->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="filing-send-dev-banner"]');
        self::assertSelectorTextContains('[data-testid="filing-send-competence"]', 'calculată automat');
        self::assertSelectorTextContains('[data-testid="filing-send-address-meta"]', 'adresă confirmată la');
        self::assertSelectorTextContains('[data-testid="filing-send-subject"]', 'Cerere de emitere a ordonanței de plată');
        $boxes = $crawler->filter('[data-testid^="filing-send-confirm-"]');
        self::assertCount(1, $boxes);
        self::assertNull($boxes->first()->attr('checked'));
    }

    public function testSubmitRequiresAValidCsrfToken(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', $this->submitUrl(), ['_token' => 'forged', 'confirm_documents_checked' => '1'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-stream[target="filing-send-panel"]');
        self::assertSame(0, $this->submissionCount());
    }

    public function testAnotherLawyerIsForbidden(): void
    {
        $other = $this->createFilingUser($this->em, 'filing-send-other');
        $this->em->flush();
        $this->otherUsers[] = $other;
        $this->client->loginUser($other);

        $this->client->request('POST', $this->submitUrl(), ['_token' => 'x', 'confirm_documents_checked' => '1']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->submissionCount());
    }

    public function testUntickedStatementIsRefusedInThePanel(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->csrfToken('filing_submit_' . $this->case->getId());

        $this->client->request('POST', $this->submitUrl(), ['_token' => $token], [], self::TURBO);

        self::assertSelectorExists('[data-testid="filing-send-error"]');
        self::assertSame(0, $this->submissionCount());
    }

    public function testDownloadedArchiveAddsTheOtherChannelStatement(): void
    {
        static::getContainer()->get(AuditLogService::class)->log('zip_package_downloaded', LegalCase::class, (string) $this->case->getId());
        $this->em->flush();
        $this->client->loginUser($this->user);
        $token = $this->csrfToken('filing_submit_' . $this->case->getId());

        $this->client->request('POST', $this->submitUrl(), ['_token' => $token, 'confirm_documents_checked' => '1'], [], self::TURBO);
        self::assertSelectorExists('[data-testid="filing-send-confirm-other_channel"]');
        self::assertSame(0, $this->submissionCount());

        $this->client->request('POST', $this->submitUrl(), ['_token' => $token, 'confirm_documents_checked' => '1', 'confirm_other_channel' => '1'], [], self::TURBO);
        self::assertSame(1, $this->submissionCount());
    }

    public function testDoubleSubmitQueuesOneTransmission(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->csrfToken('filing_submit_' . $this->case->getId());

        foreach ([1, 2] as $_) {
            $this->client->request('POST', $this->submitUrl(), ['_token' => $token, 'confirm_documents_checked' => '1'], [], self::TURBO);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('turbo-stream[target="filing-package-card"]');
        }

        self::assertSame(1, $this->submissionCount());
        self::assertSelectorExists('[data-testid="filing-submission-card"][data-status="QUEUED"]');
    }

    public function testWhileTransmittingNothingFeedingThePackageChangesAndNoManualFilingIsAccepted(): void
    {
        $this->submitFiling($this->case, $this->user);
        $this->client->loginUser($this->user);
        $document = $this->case->getDocuments()->first();

        $this->client->request('POST', sprintf('/case/%d/document/upload', $this->case->getId()), []);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/case/%d/document/%d/delete', $this->case->getId(), $document->getId()), []);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/case/%d/transition/file', $this->case->getId()), [
            'confirm_filing' => ['filedAt' => (new \DateTimeImmutable())->format('Y-m-d'), 'filingChannel' => 'REGISTRATURA'],
        ], [], self::TURBO);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            htmlspecialchars(static::getContainer()->get('translator')->trans('filing.send.flash_manual_refused_active'), ENT_QUOTES),
            (string) $this->client->getResponse()->getContent(),
        );
        $status = $this->em->getConnection()->fetchOne('SELECT status FROM legal_case WHERE id = ?', [$this->case->getId()]);
        self::assertSame(CaseStatus::CERERE_GENERATA->value, $status);
    }

    public function testFilingDateIsDeclaredOnlyWithAPlausibleDate(): void
    {
        $this->submitFiling($this->case, $this->user);
        $this->handleAllFilingMessages();
        $this->client->loginUser($this->user);
        $token = $this->csrfToken('filing_filed_date_' . $this->case->getId());
        $url = sprintf('/case/%d/filing/submission/filed-date', $this->case->getId());

        $this->client->request('POST', $url, ['_token' => $token, 'filed_at' => (new \DateTimeImmutable('+3 days'))->format('Y-m-d')], [], self::TURBO);
        self::assertSelectorExists('turbo-stream[target="toasts"]');
        self::assertSelectorNotExists('turbo-stream[target="filing-package-card"]');
        self::assertNull($this->em->getConnection()->fetchOne('SELECT filed_at FROM legal_case WHERE id = ?', [$this->case->getId()]));

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $this->client->request('POST', $url, ['_token' => $token, 'filed_at' => $today], [], self::TURBO);
        self::assertSelectorExists('[data-testid="filing-submission-filed-at"]');
        self::assertSame($today, $this->em->getConnection()->fetchOne('SELECT filed_at FROM legal_case WHERE id = ?', [$this->case->getId()]));
    }

    public function testOverviewOffersTheSendActionAndThenShowsTheTransmissionWithoutCallingItFiled(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="filing-send-open"]');

        $partId = (int) $this->submitFiling($this->case, $this->user)->getPart(1)?->getId();
        $this->client->request('GET', '/case/' . $this->case->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="filing-submission-card"][data-status="QUEUED"]');
        self::assertSelectorNotExists('#case-hero-actions [data-hs-overlay="#hs-modal-confirm-filing"]');
        // The recommended-action button (full width), not the modal's own close buttons.
        self::assertSelectorNotExists('button.w-full[data-hs-overlay="#hs-modal-confirm-filing"]');
        self::assertSelectorNotExists('[data-testid="filing-part-upload-form"]');

        $this->handleFilingPart($partId);
        $this->client->request('GET', '/case/' . $this->case->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#case-status-badge', 'Transmisă instanței');
        self::assertSelectorTextContains('[data-testid="filing-submission-headline"]', 'Transmisă instanței, fără confirmare de primire');
        self::assertSelectorExists('[data-testid="filing-filed-date-form"]');
    }

    private function submitUrl(): string
    {
        return sprintf('/case/%d/filing/submission', $this->case->getId());
    }

    private function submissionCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM filing_submission WHERE legal_case_id = ?', [$this->case->getId()]);
    }

    private function csrfToken(string $tokenId): string
    {
        $this->client->request('GET', '/case/' . $this->case->getId());
        $request = $this->client->getRequest();
        $stack = static::getContainer()->get(RequestStack::class);
        $stack->push($request);
        try {
            $token = static::getContainer()->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        } finally {
            $stack->pop();
        }
        $request->getSession()->save();

        return $token;
    }
}
