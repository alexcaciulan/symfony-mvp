<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\StampDutyStatus;
use App\Service\AuditLogService;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testFilingAnswersAlsoRefreshTheHeroAndTheRecommendedActions(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->csrfToken('filing_submit_' . $this->case->getId());

        $this->client->request('POST', $this->submitUrl(), ['_token' => $token, 'confirm_documents_checked' => '1'], [], self::TURBO);

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="case-hero"', $content);
        self::assertStringContainsString('target="case-detalii-sidebar"', $content);
        self::assertStringContainsString('target="case-pipeline"', $content);
        $hero = $this->templateHtml($content, 'case-hero');
        self::assertNotSame('', $hero);
        self::assertStringNotContainsString('#hs-modal-confirm-filing', $hero, 'The hero no longer offers to confirm a manual filing.');
        self::assertStringNotContainsString('#hs-modal-confirm-filing', $this->templateHtml($content, 'case-detalii-sidebar'), 'Nor do the recommended actions.');

        $submissionId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM filing_submission WHERE legal_case_id = ?', [$this->case->getId()]);
        $cancelToken = $this->csrfToken('filing_cancel_' . $submissionId);
        $this->client->request('POST', sprintf('/case/%d/filing/submission/%d/cancel', $this->case->getId(), $submissionId), ['_token' => $cancelToken], [], self::TURBO);
        self::assertStringContainsString('target="case-hero"', (string) $this->client->getResponse()->getContent());
    }

    public function testTheFiledDateAnswerRefreshesTheStatusBadge(): void
    {
        $this->submitFiling($this->case, $this->user);
        $this->handleAllFilingMessages();
        $this->client->loginUser($this->user);
        $token = $this->csrfToken('filing_filed_date_' . $this->case->getId());

        $this->client->request('POST', sprintf('/case/%d/filing/submission/filed-date', $this->case->getId()), ['_token' => $token, 'filed_at' => (new \DateTimeImmutable('today'))->format('Y-m-d')], [], self::TURBO);

        $hero = $this->templateHtml((string) $this->client->getResponse()->getContent(), 'case-hero');
        self::assertStringContainsString(static::getContainer()->get('translator')->trans(CaseStatus::CERERE_DEPUSA->label()), $hero);
        self::assertStringNotContainsString(static::getContainer()->get('translator')->trans('filing.status.hero_label'), $hero);
    }

    public function testTheStateStreamReturnsTheRegionsTheFilingChanges(): void
    {
        $this->submitFiling($this->case, $this->user);
        $this->client->loginUser($this->user);

        $this->client->request('GET', sprintf('/case/%d/filing/state', $this->case->getId()), [], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/vnd.turbo-stream.html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        foreach (['case-hero', 'case-pipeline', 'case-kpi-grid', 'case-detalii-sidebar'] as $target) {
            self::assertStringContainsString('target="' . $target . '"', (string) $this->client->getResponse()->getContent());
        }

        // The polling card carries the key the page compares to decide when to fetch it.
        $this->client->request('GET', sprintf('/case/%d/filing/submission/card', $this->case->getId()));
        self::assertSelectorExists('turbo-frame[data-poll-frame-state-key-value*="CERERE_GENERATA|-|active|QUEUED"]');
    }

    /** @return iterable<string, array{bool}> */
    public static function lockingSubmissions(): iterable
    {
        yield 'queued' => [false];
        yield 'in review' => [true];
    }

    #[DataProvider('lockingSubmissions')]
    public function testWhileASubmissionHoldsTheCaseTheRefusedActionsAreNotOffered(bool $review): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId() . '?tab=documente');
        self::assertSelectorExists('[data-testid="documents-source-add"]', 'Offered while nothing holds the case.');
        $this->case = $this->em->getRepository(LegalCase::class)->find($this->case->getId());
        $this->user = $this->em->getRepository(User::class)->find($this->user->getId());
        $submission = $this->submitFiling($this->case, $this->user);
        // The duty is still owed while the case is being sent (it is paid once the court gives a number).
        $this->case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        if ($review) {
            $submission->markNeedsReview('filing.status.review.case_moved_on');
        }
        $this->em->flush();
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/case/' . $this->case->getId() . '?tab=documente');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#case-stamp-duty-card');
        self::assertSelectorNotExists('#case-stamp-duty-card [data-hs-overlay="#hs-modal-stamp-duty-proof"]');
        self::assertSelectorNotExists('#case-stamp-duty-card [data-hs-overlay="#hs-modal-stamp-duty-court-notice"]');
        self::assertSelectorNotExists('[data-testid="documents-source-add"]');
        self::assertSelectorExists('[data-testid="stamp-duty-filing-lock-note"]');
        self::assertSelectorExists('[data-testid="documents-filing-lock-note"]');
    }

    public function testDashboardNeverCallsAnEmailTransmittedCaseFiled(): void
    {
        $this->submitFiling($this->case, $this->user);
        $this->handleAllFilingMessages();
        self::assertSame('CERERE_DEPUSA', $this->em->getConnection()->fetchOne('SELECT status FROM legal_case WHERE id = ?', [$this->case->getId()]));
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Transmisă instanței', (string) $this->client->getResponse()->getContent());
    }

    private function templateHtml(string $stream, string $target): string
    {
        return preg_match('#<turbo-stream action="[a-z]+" target="' . preg_quote($target, '#') . '">\s*<template>(.*?)</template>\s*</turbo-stream>#s', $stream, $m) === 1 ? $m[1] : '';
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
