<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Tests\Support\FilingCaseFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class CaseFilingConfirmPageTest extends WebTestCase
{
    use FilingCaseFixtureTrait;

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
        $this->user = $this->createFilingUser($this->em, 'filing-confirm-page');
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 2);
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

    public function testThePageShowsTheWholeEmailAndTheUntickedStatementsBeforeTheSend(): void
    {
        $this->client->loginUser($this->user);

        $crawler = $this->client->request('GET', $this->confirmUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Verifică și trimite la instanță');
        self::assertSelectorTextContains('[data-testid="filing-confirm-subtitle"]', 'nu mai poate fi anulată');
        self::assertSelectorExists(sprintf('[data-testid="filing-confirm-breadcrumb-case"][href="/case/%d?tab=depunere"]', $this->case->getId()));
        // The whole text, without a scroll box of its own.
        $body = $crawler->filter('[data-testid="filing-send-body"]');
        self::assertCount(1, $body);
        self::assertStringNotContainsString('overflow', (string) $body->attr('class'));
        self::assertStringNotContainsString('max-h', (string) $body->attr('class'));
        self::assertStringContainsString('Cu stimă', $body->text());
        $attachments = $crawler->filter('[data-testid="filing-confirm-attachment"]');
        self::assertCount(3, $attachments);
        $attachments->each(static fn ($a) => self::assertStringContainsString('Semnătură detectată', $a->text()));
        self::assertStringNotContainsString('semnătură validă', (string) $this->client->getResponse()->getContent());
        // Test environment: the effective recipient is named next to the court's own address.
        self::assertSelectorTextContains('[data-testid="filing-send-dev-banner"]', 'Mediu de test');
        $boxes = $crawler->filter('turbo-frame#filing-send-panel [data-testid^="filing-send-confirm-"]');
        self::assertCount(1, $boxes);
        self::assertNull($boxes->first()->attr('checked'));
        self::assertSelectorExists('form[data-controller="require-checks"] button[data-require-checks-target="submit"][data-testid="filing-send-submit"]');
        self::assertSelectorTextContains('[data-testid="filing-send-submit"]', 'Trimite la instanță');
    }

    public function testASentRequestLandsOnTheFilingTabWithTheQueuedMessage(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->csrfToken('filing_submit_' . $this->case->getId());

        $this->client->request('POST', $this->submitUrl(), ['_token' => $token, 'confirm_documents_checked' => '1'], [], self::TURBO);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects(sprintf('/case/%d?tab=depunere', $this->case->getId()));
        $this->client->followRedirect();
        self::assertSelectorExists('#tab-depunere.active');
        self::assertSelectorExists('#panel-depunere:not(.hidden) [data-testid="filing-panel"][data-code="sending"]');
        self::assertStringContainsString('Transmiterea a fost pusă în așteptare.', (string) $this->client->getResponse()->getContent());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM filing_submission WHERE legal_case_id = ?', [$this->case->getId()]));
    }

    public function testARefusedRequestStaysOnThePageWithTheReason(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->csrfToken('filing_submit_' . $this->case->getId());

        $this->client->request('POST', $this->submitUrl(), ['_token' => $token], [], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-stream[action="replace"][target="filing-send-panel"]');
        self::assertSelectorExists('[data-testid="filing-send-error"]');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM filing_submission WHERE legal_case_id = ?', [$this->case->getId()]));
    }

    public function testAnotherLawyerAndAnAdminCannotOpenThePage(): void
    {
        $other = $this->createFilingUser($this->em, 'filing-confirm-other');
        $admin = $this->createFilingUser($this->em, 'filing-confirm-admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->otherUsers[] = $other;
        $this->otherUsers[] = $admin;

        foreach ([$other, $admin] as $viewer) {
            $this->client->loginUser($viewer);
            $this->client->request('GET', $this->confirmUrl());
            self::assertResponseStatusCodeSame(403);
        }
    }

    private function confirmUrl(): string
    {
        return sprintf('/case/%d/filing/submission/confirm', $this->case->getId());
    }

    private function submitUrl(): string
    {
        return sprintf('/case/%d/filing/submission', $this->case->getId());
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
