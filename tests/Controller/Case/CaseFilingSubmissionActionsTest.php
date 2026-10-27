<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use App\Tests\Support\RecordingCourtTransport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/** The lawyer's ways out of a stopped transmission, from the status card. */
final class CaseFilingSubmissionActionsTest extends WebTestCase
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
        $this->filingUploadsDir = static::getContainer()->getParameter('app.uploads_dir');
        $this->user = $this->createFilingUser($this->em, 'filing-actions');
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

    public function testResumeQueuesTheFailedAnnexAgain(): void
    {
        $submissionId = $this->stalledAtAnnex();
        $this->client->loginUser($this->user);
        $token = $this->csrfToken('filing_resume_' . $submissionId);

        $this->client->request('POST', $this->url($submissionId, 'resume'), ['_token' => $token, 'confirm_resume_remaining' => '1'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-stream[target="filing-package-card"]');
        self::assertSame('PENDING', $this->partStatus($submissionId, 2));
        self::assertSame('PARTIALLY_SENT', $this->submissionStatus($submissionId));
    }

    public function testCloseRemainingFreesTheCaseAndWarnsTheFilingIsIncomplete(): void
    {
        $submissionId = $this->stalledAtAnnex();
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());
        self::assertSelectorExists('[data-testid="filing-submission-close-form"]');
        self::assertSelectorExists('[data-testid="filing-submission-resume-form"]');
        self::assertSelectorExists('[data-testid="filing-submission-card"][data-stalled="1"]');
        $token = $this->csrfToken('filing_close_' . $submissionId);

        $this->client->request('POST', $this->url($submissionId, 'close-remaining'), ['_token' => $token, 'confirm_incomplete' => '1'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="filing-submission-card"][data-status="CLOSED_PARTIAL"]');
        self::assertSelectorExists('[data-testid="filing-submission-incomplete-warning"]');
        self::assertNull($this->em->getConnection()->fetchOne('SELECT active_lock FROM filing_submission WHERE id = ?', [$submissionId]));
    }

    public function testCaseMovedOnDuringTheSendCanBeClosedAndUnlocked(): void
    {
        $submission = $this->submitFiling($this->case, $this->user);
        $submissionId = (int) $submission->getId();
        // The case went on by another path while the petition was being handed over.
        $conn = $this->em->getConnection();
        $caseId = (int) $this->case->getId();
        $this->courtTransport()->beforeSend = static function () use ($conn, $caseId): void {
            $conn->executeStatement('UPDATE legal_case SET status = ? WHERE id = ?', [CaseStatus::CERERE_DEPUSA->value, $caseId]);
        };
        $this->handleFilingPart((int) $submission->getPart(1)?->getId());
        self::assertSame('NEEDS_REVIEW', $this->submissionStatus($submissionId));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT active_lock FROM filing_submission WHERE id = ?', [$submissionId]));

        $this->client->loginUser($this->user);
        $token = $this->csrfToken('filing_close_' . $submissionId);
        $this->client->request('POST', $this->url($submissionId, 'close-remaining'), ['_token' => $token, 'confirm_incomplete' => '1'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertNull($this->em->getConnection()->fetchOne('SELECT active_lock FROM filing_submission WHERE id = ?', [$submissionId]));
        self::assertSame('CLOSED_PARTIAL', $this->submissionStatus($submissionId));
        self::assertSelectorExists('[data-testid="filing-submission-incomplete-warning"]');
    }

    public function testCcReceivedAcceptsTheUncertainPetition(): void
    {
        $submissionId = $this->uncertainPetition();
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/case/' . $this->case->getId());
        self::assertSelectorExists('[data-testid="filing-submission-uncertain-actions"]');
        self::assertSelectorExists('[data-testid="filing-submission-uncertain-actions"] [data-testid="filing-submission-uncertain-explain"]');
        self::assertSelectorTextContains('[data-testid="filing-submission-uncertain-actions"]', 'Platforma va considera cererea trimisă');
        $token = $this->csrfToken('filing_uncertain_' . $submissionId . '_1');

        $this->client->request('POST', $this->url($submissionId, 'part/1/cc-received'), ['_token' => $token, 'confirm_cc_received' => '1'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertSame('ACCEPTED', $this->partStatus($submissionId, 1));
        self::assertSame(CaseStatus::CERERE_DEPUSA->value, $this->em->getConnection()->fetchOne('SELECT status FROM legal_case WHERE id = ?', [$this->case->getId()]));
    }

    public function testCcNotReceivedEndsTheSubmission(): void
    {
        $submissionId = $this->uncertainPetition();
        $this->client->loginUser($this->user);
        $token = $this->csrfToken('filing_uncertain_' . $submissionId . '_1');

        $this->client->request('POST', $this->url($submissionId, 'part/1/cc-not-received'), ['_token' => $token, 'confirm_cc_not_received' => '1'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertSame('FAILED_INTERNAL', $this->partStatus($submissionId, 1));
        self::assertNull($this->em->getConnection()->fetchOne('SELECT active_lock FROM filing_submission WHERE id = ?', [$submissionId]));
    }

    public function testForgedTokenIsRefusedWithAToastAndChangesNothing(): void
    {
        $submissionId = $this->stalledAtAnnex();
        $this->client->loginUser($this->user);

        $this->client->request('POST', $this->url($submissionId, 'close-remaining'), ['_token' => 'forged', 'confirm_incomplete' => '1'], [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-stream[target="toasts"]');
        self::assertSelectorNotExists('turbo-stream[target="filing-package-card"]');
        self::assertSame('PARTIALLY_SENT', $this->submissionStatus($submissionId));
    }

    public function testAnotherLawyerIsForbidden(): void
    {
        $submissionId = $this->stalledAtAnnex();
        $other = $this->createFilingUser($this->em, 'filing-actions-other');
        $this->em->flush();
        $this->otherUsers[] = $other;
        $this->client->loginUser($other);

        foreach (['resume', 'close-remaining', 'part/2/cc-not-received'] as $action) {
            $this->client->request('POST', $this->url($submissionId, $action), ['_token' => 'x']);
            self::assertResponseStatusCodeSame(403);
        }
        self::assertSame('PARTIALLY_SENT', $this->submissionStatus($submissionId));
    }

    public function testAdminHasNoAccessToTheLawyersActions(): void
    {
        $submissionId = $this->stalledAtAnnex();
        $admin = $this->createFilingUser($this->em, 'filing-actions-admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->otherUsers[] = $admin;
        $this->client->loginUser($admin);

        $this->client->request('POST', $this->url($submissionId, 'close-remaining'), ['_token' => 'x', 'confirm_incomplete' => '1']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('PARTIALLY_SENT', $this->submissionStatus($submissionId));
    }

    public function testAfterSplittingTheAnnexesAgainTheCardSaysSoInsteadOfThatItCannotHelp(): void
    {
        // One email carrying the annexes, refused for its size: splitting again can help.
        $lawyer = $this->createFilingUser($this->em, 'filing-actions-resplit');
        $this->otherUsers[] = $lawyer;
        $case = $this->createSendableFilingCase($this->em, $lawyer);
        $submission = $this->submitFiling($case, $lawyer);
        $this->courtTransport()->failNext = RecordingCourtTransport::endOfDataRefused(552, 'Message size exceeds fixed limit');
        try {
            $this->handleFilingPart((int) $submission->getPart(1)?->getId());
        } catch (HandlerFailedException) {
        }
        $this->em->refresh($submission);
        $submission->getPart(1)?->recordEncodedBytes(4_000_000);
        $this->em->flush();
        $submissionId = (int) $submission->getId();
        $card = sprintf('/case/%d/filing/submission/card', $case->getId());
        $translator = static::getContainer()->get('translator');

        $this->client->loginUser($lawyer);
        $crawler = $this->client->request('GET', $card);
        self::assertSelectorTextContains('[data-testid="filing-submission-size-help"]', $translator->trans('filing.resplit.proposal'));
        $this->client->submit($crawler->filter('[data-testid="filing-submission-resplit"]')->form(), [], self::TURBO);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(htmlspecialchars($translator->trans('filing.resplit.done'), ENT_QUOTES), (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', $card);
        self::assertSame('FAILED_SIZE', $this->submissionStatus($submissionId));
        self::assertSelectorTextContains('[data-testid="filing-submission-size-help"]', $translator->trans('filing.resplit.after_resplit'));
        self::assertStringNotContainsString($translator->trans('filing.resplit.cannot_help'), (string) $this->client->getResponse()->getContent());
    }

    /** Petition accepted, the annex refused for good: the submission is partially sent and stalled. */
    private function stalledAtAnnex(): int
    {
        $submission = $this->submitFiling($this->case, $this->user);
        $this->handleFilingPart((int) $submission->getPart(1)?->getId());
        $this->courtTransport()->failNext = RecordingCourtTransport::rcptRefused(554, 'Transaction failed');
        try {
            $this->handleFilingPart((int) $submission->getPart(2)?->getId());
        } catch (HandlerFailedException) {
        }
        self::assertSame('FAILED_PERMANENT', $this->partStatus((int) $submission->getId(), 2));

        return (int) $submission->getId();
    }

    private function uncertainPetition(): int
    {
        $submission = $this->submitFiling($this->case, $this->user);
        $this->courtTransport()->failNext = RecordingCourtTransport::lostAfterData();
        try {
            $this->handleFilingPart((int) $submission->getPart(1)?->getId());
        } catch (HandlerFailedException) {
        }
        self::assertSame('UNCERTAIN', $this->partStatus((int) $submission->getId(), 1));

        return (int) $submission->getId();
    }

    private function url(int $submissionId, string $action): string
    {
        return sprintf('/case/%d/filing/submission/%d/%s', $this->case->getId(), $submissionId, $action);
    }

    private function partStatus(int $submissionId, int $sequence): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM filing_email_part WHERE submission_id = ? AND sequence = ?', [$submissionId, $sequence]);
    }

    private function submissionStatus(int $submissionId): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT delivery_status FROM filing_submission WHERE id = ?', [$submissionId]);
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
