<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\DTO\Wizard\Step1CreditorData;
use App\Entity\CaseDraft;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Service\Wizard\CaseDraftLimit;
use App\Service\Wizard\CaseDraftPayloadCodec;
use App\Service\Wizard\CaseDraftWizardStateStore;
use App\Service\Wizard\WizardBag;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CaseDraftControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private CaseDraftPayloadCodec $codec;
    private User $user;
    private User $other;
    private string $uploadsDir;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->codec = static::getContainer()->get(CaseDraftPayloadCodec::class);
        $this->uploadsDir = static::getContainer()->getParameter('app.uploads_dir');

        $this->user = $this->makeUser('draft-ctrl-');
        $this->other = $this->makeUser('draft-ctrl-other-');
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ([$this->user, $this->other] as $user) {
            $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $user->getId()]);
            $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $user->getId()]);
            $conn->executeStatement('DELETE FROM case_draft WHERE user_id = :id', ['id' => $user->getId()]);
            $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $user->getId()]);
        }

        parent::tearDown();
    }

    public function testTheListsShowOnlyTheOwnersDrafts(): void
    {
        $mine = $this->draft($this->user, 'Cedent Al Meu SRL');
        $theirs = $this->draft($this->other, 'Cedent Strain SRL');

        foreach (['/dashboard', '/cases', '/case/new'] as $path) {
            $crawler = $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            $rows = $crawler->filter('[data-testid="draft-row"]');
            self::assertSame(1, $rows->count(), $path);
            self::assertSame((string) $mine->getId(), $rows->attr('data-draft-id'), $path);
            self::assertStringContainsString('Cedent Al Meu SRL', $rows->text());
            self::assertStringNotContainsString('Cedent Strain SRL', $crawler->html());
            self::assertStringNotContainsString((string) $theirs->getId(), $rows->attr('data-draft-id'));
        }
    }

    public function testContinueAttachesTheDraftAndOpensTheStepReached(): void
    {
        $draft = $this->draft($this->user, 'Cedent SRL');
        $crawler = $this->client->request('GET', '/cases');

        $this->client->submit($this->continueForm($crawler, $draft));

        self::assertResponseRedirects('/case/new/debtor');
        self::assertSame($draft->getId(), $this->client->getRequest()->getSession()->get(CaseDraftWizardStateStore::SESSION_KEY));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/case/new');
        self::assertSame(1, $crawler->filter('[data-testid="draft-active"]')->count(), 'the continued draft is the open one');
    }

    public function testContinueOnARunStoppedAtTheDocumentsStepShowsItsDocuments(): void
    {
        $draft = new CaseDraft($this->user);
        $this->em->persist($draft);
        $this->em->flush();
        $document = $this->pendingDocument($this->user, $draft);
        $bag = new WizardBag(documentIds: [$document->getId()]);
        $draft->setPayload($this->codec->encode($bag), CaseDraftPayloadCodec::PAYLOAD_VERSION)->denormalizeFrom($bag, new \DateTimeImmutable());
        $this->em->flush();
        self::assertSame(CaseDraft::STEP_DOCUMENTS, $draft->getStepReached());
        $crawler = $this->client->request('GET', '/dashboard');
        self::assertStringContainsString('un document', $crawler->filter('[data-testid="draft-row"]')->text());

        $this->client->submit($this->continueForm($crawler, $draft));

        self::assertResponseRedirects('/case/new/documents');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-document-id="' . $document->getId() . '"]')->count(), 'the document of the run is listed again');
        self::assertStringContainsString($document->getOriginalFilename(), $crawler->filter('[data-document-id="' . $document->getId() . '"]')->text());
    }

    public function testTheLastContinueWinsAndTheListSaysWhichRunIsOpen(): void
    {
        $first = $this->draft($this->user, 'Primul SRL');
        $second = $this->draft($this->user, 'Al Doilea SRL');

        $crawler = $this->client->request('GET', '/cases');
        $this->client->submit($this->continueForm($crawler, $first));
        self::assertSame($first->getId(), $this->client->getRequest()->getSession()->get(CaseDraftWizardStateStore::SESSION_KEY));

        $crawler = $this->client->request('GET', '/cases');
        $this->client->submit($this->continueForm($crawler, $second));

        self::assertResponseRedirects('/case/new/debtor');
        self::assertSame($second->getId(), $this->client->getRequest()->getSession()->get(CaseDraftWizardStateStore::SESSION_KEY));
        $crawler = $this->client->request('GET', '/case/new');
        $badges = $crawler->filter('[data-testid="draft-active"]');
        self::assertSame(1, $badges->count(), 'exactly one run is marked as open');
        self::assertSame((string) $second->getId(), $badges->closest('[data-testid="draft-row"]')?->attr('data-draft-id'));
        self::assertSame(2, $crawler->filter('[data-testid="draft-row"]')->count(), 'the run left behind is still listed');
        $crawler = $this->client->request('GET', '/case/new/creditor');
        self::assertResponseIsSuccessful();
        self::assertSame('Al Doilea SRL', $crawler->filter('input[name="step1_creditor[name]"]')->attr('value'), 'the wizard works on the run continued last');
    }

    public function testDiscardDeletesTheDraftItsRowsAndItsFiles(): void
    {
        $draft = $this->draft($this->user, 'Cedent SRL');
        $document = $this->pendingDocument($this->user, $draft);
        $path = $this->uploadsDir . '/' . $document->getStoredFilename();
        self::assertFileExists($path);
        $crawler = $this->client->request('GET', '/cases');

        $this->client->submit($this->discardForm($crawler, $draft));

        self::assertResponseRedirects('/cases');
        $this->em->clear();
        self::assertNull($this->em->find(CaseDraft::class, $draft->getId()));
        self::assertNull($this->em->find(Document::class, $document->getId()));
        self::assertFileDoesNotExist($path);
        self::assertDirectoryDoesNotExist(dirname($path));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE user_id = ? AND action = ? AND entity_id = ?',
            [$this->user->getId(), 'draft_discarded', (string) $draft->getId()],
        ));
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Dosarul neterminat a fost șters');
    }

    public function testDiscardingTheOpenDraftDetachesTheSession(): void
    {
        $draft = $this->draft($this->user, 'Cedent SRL');
        $crawler = $this->client->request('GET', '/case/new');
        $this->client->submit($this->continueForm($crawler, $draft));
        $crawler = $this->client->request('GET', '/case/new');

        $this->client->submit($this->discardForm($crawler, $draft));

        self::assertResponseRedirects('/case/new');
        self::assertNull($this->client->getRequest()->getSession()->get(CaseDraftWizardStateStore::SESSION_KEY));
        $this->client->followRedirect();
        self::assertResponseRedirects('/case/new/documents');
    }

    public function testAnotherUsersDraftIsNotFound(): void
    {
        $theirs = $this->draft($this->other, 'Cedent Strain SRL');

        $this->client->request('POST', '/case/draft/' . $theirs->getId() . '/continue', [
            '_token' => $this->csrf('case_draft_continue_' . $theirs->getId()),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->client->request('POST', '/case/draft/' . $theirs->getId() . '/discard', [
            '_token' => $this->csrf('case_draft_discard_' . $theirs->getId()),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->em->clear();
        self::assertNotNull($this->em->find(CaseDraft::class, $theirs->getId()));
    }

    public function testAnInvalidTokenIsRefused(): void
    {
        $draft = $this->draft($this->user, 'Cedent SRL');

        $this->client->request('POST', '/case/draft/' . $draft->getId() . '/discard', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->em->clear();
        self::assertNotNull($this->em->find(CaseDraft::class, $draft->getId()));
    }

    public function testTheCapRefusesASixthRunAndSendsToTheList(): void
    {
        for ($i = 0; $i < CaseDraftLimit::MAX_PER_USER; ++$i) {
            $this->draft($this->user, 'Cedent ' . $i . ' SRL');
        }

        $crawler = $this->client->request('GET', '/case/new');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('[data-testid="draft-new"]')->count(), 'no button to start another run');
        self::assertSame(1, $crawler->filter('[data-testid="drafts-limit"]')->count());

        $this->client->request('POST', '/case/new/draft/new', ['_token' => $this->csrf('case_wizard_draft_new')]);
        self::assertResponseRedirects('/case/new');

        // Walking straight to step 0 with no open run is refused the same way.
        $this->client->request('GET', '/case/new/documents');
        self::assertResponseRedirects('/case/new');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'limita de 5 dosare neterminate');
        self::assertSame(CaseDraftLimit::MAX_PER_USER, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM case_draft WHERE user_id = ?', [$this->user->getId()]));
    }

    public function testAnUnreadableDraftOffersOnlyDiscard(): void
    {
        $draft = new CaseDraft($this->user);
        $draft->setPayload('garbage', CaseDraftPayloadCodec::PAYLOAD_VERSION);
        $this->em->persist($draft);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/case/new');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-testid="draft-unreadable"]')->count());
        self::assertSame(0, $crawler->filter('[data-testid="draft-continue"]')->count());
        self::assertSame(1, $crawler->filter('[data-testid="draft-discard"]')->count());
    }

    public function testContinueOnAnUnreadableDraftIsRefusedAndNotAttached(): void
    {
        $draft = new CaseDraft($this->user);
        $draft->setPayload('garbage', CaseDraftPayloadCodec::PAYLOAD_VERSION);
        $this->em->persist($draft);
        $this->em->flush();

        $this->client->request('POST', '/case/draft/' . $draft->getId() . '/continue', ['_token' => $this->csrf('case_draft_continue_' . $draft->getId())]);

        self::assertResponseRedirects('/case/new');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'nu mai poate fi citit');
        $this->em->clear();
        self::assertNotNull($this->em->find(CaseDraft::class, $draft->getId()));
    }

    private function continueForm(Crawler $crawler, CaseDraft $draft): \Symfony\Component\DomCrawler\Form
    {
        return $crawler->filter('form[action="/case/draft/' . $draft->getId() . '/continue"] button')->form();
    }

    private function discardForm(Crawler $crawler, CaseDraft $draft): \Symfony\Component\DomCrawler\Form
    {
        return $crawler->filter('form[action="/case/draft/' . $draft->getId() . '/discard"] button')->form();
    }

    /**
     * A token for an id no page renders a form for. The session-backed token
     * storage needs a request on the stack, so the last request is pushed back
     * for the duration of the call and its session saved for the next one.
     */
    private function csrf(string $id): string
    {
        $this->client->request('GET', '/cases');
        $request = $this->client->getRequest();
        $stack = static::getContainer()->get(RequestStack::class);
        $stack->push($request);
        try {
            $token = static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
        } finally {
            $stack->pop();
        }
        $request->getSession()->save();

        return $token;
    }

    private function draft(User $owner, string $creditorName): CaseDraft
    {
        $bag = new WizardBag(creditor: new Step1CreditorData(name: $creditorName, cui: 'RO15193236'));
        $draft = new CaseDraft($owner);
        $draft->setPayload($this->codec->encode($bag), CaseDraftPayloadCodec::PAYLOAD_VERSION)->denormalizeFrom($bag, new \DateTimeImmutable());
        $this->em->persist($draft);
        $this->em->flush();

        return $draft;
    }

    private function pendingDocument(User $owner, CaseDraft $draft): Document
    {
        $relative = 'cases/_pending/' . $owner->getId() . '/' . uniqid('draft-', true) . '/file.pdf';
        $absolute = $this->uploadsDir . '/' . $relative;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0o777, true);
        }
        file_put_contents($absolute, '%PDF-1.4 test');

        $document = new Document();
        $document->setUploadedBy($owner);
        $document->setDraft($draft);
        $document->setDocumentType(DocumentType::FACTURA);
        $document->setOriginalFilename('file.pdf');
        $document->setStoredFilename($relative);
        $document->setMimeType('application/pdf');
        $document->setFileSize(13);
        $document->setExtractionStatus(ExtractionStatus::COMPLETED);
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    private function makeUser(string $prefix): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($prefix . uniqid() . '@test.com');
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $user->setIsVerified(true);
        $user->setFirstName('Draft');
        $user->setLastName('Tester');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
