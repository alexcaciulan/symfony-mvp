<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\FilingDeliveryStatus;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use App\Tests\Support\RecordingCourtTransport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class FilingSubmissionOverrideControllerTest extends WebTestCase
{
    use FilingCaseFixtureTrait;
    use FilingSubmissionTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private User $admin;
    private LegalCase $case;
    private int $submissionId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->user = $this->createFilingUser($this->em, 'filing-override');
        $this->admin = $this->createFilingUser($this->em, 'filing-override-admin');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $this->courtTransport()->reset();

        $submission = $this->submitFiling($this->case, $this->user);
        $this->submissionId = (int) $submission->getId();
        $this->courtTransport()->failNext = RecordingCourtTransport::lostAfterData();
        try {
            $this->handleFilingPart((int) $submission->getPart(1)?->getId());
        } catch (HandlerFailedException) {
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->getConnection()->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$this->admin->getId()]);
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
            $this->removeFilingFixtures($this->em, $this->admin, null);
        }
        parent::tearDown();
    }

    public function testOverridePageShowsMetadataOnly(): void
    {
        $this->client->loginUser($this->admin);

        $this->client->request('GET', sprintf('/admin/filing-submission/%d/override', $this->submissionId));

        self::assertResponseIsSuccessful();
        $translator = static::getContainer()->get('translator');
        self::assertSelectorTextContains('[data-testid="admin-submission-status"]', $translator->trans(FilingDeliveryStatus::NEEDS_REVIEW->label()));
        self::assertSelectorTextNotContains('[data-testid="admin-submission-status"]', 'NEEDS_REVIEW');
        self::assertSelectorExists('[data-testid="admin-override-form-1"]');
        // Only "not sent" for the petition: its acceptance is the lawyer's declaration.
        self::assertSelectorNotExists('[data-testid="admin-override-form-1"] button[value="accepted"]');
        self::assertSelectorTextContains('[data-testid="admin-override-form-1"] button[name="outcome"][value="failed"]', 'Marchează ca netrimis');
        // The effect is written before the button: the case stays generated, nothing is declared received.
        self::assertSelectorTextContains('[data-testid="admin-override-effect-failed"]', 'Dosarul rămâne la „Cerere generată”');
        self::assertSelectorTextContains('body', 'Deblocarea nu declară primirea și nu schimbă data depunerii.');
        self::assertSelectorNotExists('[data-testid="admin-close-form"]');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('.eml', $html);
        self::assertStringNotContainsString('/eml', $html);
        self::assertStringNotContainsString('SC Debitor Pachet SRL', $html);
    }

    public function testEmptyReasonIsRejectedAndAValidOneIsAudited(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->csrfToken('filing_override_' . $this->submissionId . '_1');
        $url = sprintf('/admin/filing-submission/%d/part/1/override', $this->submissionId);

        $this->client->request('POST', $url, ['_token' => $token, 'outcome' => 'failed', 'reason' => '']);
        self::assertResponseRedirects();
        self::assertSame('UNCERTAIN', $this->partStatus());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Motivul este obligatoriu');

        $this->client->request('POST', $url, ['_token' => $token, 'outcome' => 'failed', 'reason' => 'Avocatul a confirmat telefonic că nu a primit copia']);
        self::assertResponseRedirects();
        self::assertSame('FAILED_INTERNAL', $this->partStatus());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Starea e-mailului a fost actualizată.');

        $audit = $this->em->getConnection()->fetchAssociative("SELECT user_id, new_data FROM audit_log WHERE action = 'filing_part_admin_override' AND entity_id = ?", [(string) $this->case->getId()]);
        self::assertIsArray($audit);
        self::assertSame($this->admin->getId(), (int) $audit['user_id']);
        $data = json_decode((string) $audit['new_data'], true);
        self::assertSame('UNCERTAIN', $data['old']['part']);
        self::assertSame('FAILED_INTERNAL', $data['new']['part']);
        self::assertSame('NEEDS_REVIEW', $data['old']['submission']);
    }

    public function testInvalidCsrfTokenIsRejectedWithATranslatedMessage(): void
    {
        $this->client->loginUser($this->admin);

        $this->client->request('POST', sprintf('/admin/filing-submission/%d/part/1/override', $this->submissionId), ['_token' => 'invalid', 'outcome' => 'failed', 'reason' => 'Motiv suficient de lung pentru test']);

        self::assertResponseRedirects();
        self::assertSame('UNCERTAIN', $this->partStatus());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Sesiunea formularului a expirat');
    }

    public function testAfterTheOverrideTheLawyerSeesWhoUnblockedTheSendAndWhy(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->csrfToken('filing_override_' . $this->submissionId . '_1');
        $this->client->request('POST', sprintf('/admin/filing-submission/%d/part/1/override', $this->submissionId), ['_token' => $token, 'outcome' => 'failed', 'reason' => 'Avocatul a confirmat telefonic situația']);
        self::assertSame('FAILED_INTERNAL', $this->partStatus());

        $this->client->loginUser($this->user);
        $this->client->request('GET', sprintf('/case/%d?tab=depunere', $this->case->getId()));

        self::assertSelectorExists('#depunere-status[data-code="admin_failed"]');
        self::assertSelectorTextContains('[data-testid="filing-admin-override-reason"]', 'Avocatul a confirmat telefonic situația');
        self::assertSelectorTextNotContains('[data-testid="filing-admin-override-reason"]', 'Admin override');
        self::assertSelectorTextContains('#depunere-status', 'nu schimbă data depunerii');
    }

    public function testACancellableSendStatesTheCancelBeforeTheButton(): void
    {
        $lawyer = $this->createFilingUser($this->em, 'filing-override-queued');
        $case = $this->createSendableFilingCase($this->em, $lawyer);
        $queued = $this->submitFiling($case, $lawyer);
        try {
            $this->client->loginUser($this->admin);
            $this->client->request('GET', sprintf('/admin/filing-submission/%d/override', $queued->getId()));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[data-testid="admin-close-effect"]', 'Anulează transmiterea');
            self::assertSelectorTextContains('[data-testid="admin-close-effect"]', 'Dosarul rămâne la „Cerere generată”');
            self::assertSelectorTextContains('[data-testid="admin-close-submit"]', 'Anulează transmiterea');
        } finally {
            $this->removeFilingFixtures($this->em, $lawyer, $case);
        }
    }

    public function testLawyerHasNoAccessToTheAdminOverride(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('GET', sprintf('/admin/filing-submission/%d/override', $this->submissionId));

        self::assertResponseStatusCodeSame(403);
    }

    private function partStatus(): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM filing_email_part WHERE submission_id = ? AND sequence = 1', [$this->submissionId]);
    }

    private function csrfToken(string $tokenId): string
    {
        $this->client->request('GET', sprintf('/admin/filing-submission/%d/override', $this->submissionId));
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
