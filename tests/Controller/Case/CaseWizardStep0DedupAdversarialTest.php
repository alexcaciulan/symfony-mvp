<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\Document;
use App\Entity\User;
use App\Message\ExtractDataMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Adversarial end-to-end coverage of wizard step 0 deduplication (T2 / D7).
 *
 * Every assertion here is about a scenario where an implementation that
 * "works on the happy path" quietly does the wrong thing: matching on the
 * filename, dropping the rest of a batch after the first duplicate, leaking
 * fingerprints across users, or blocking the legitimate reuse of one contract
 * in two different cases.
 */
final class CaseWizardStep0DedupAdversarialTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    /** @var list<int> */
    private array $extraUserIds = [];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('dedup-adv-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Dedup');
        $this->user->setLastName('Tester');
        $this->em->persist($this->user);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        $conn = $this->em->getConnection();
        foreach ([...$this->extraUserIds, $this->user->getId()] as $id) {
            $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $id]);
            $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $id]);
            $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    // ---------- scenario 1: same bytes, different filename ----------

    public function testSameContentUploadedUnderADifferentFilenameIsRejectedAsDuplicate(): void
    {
        $this->postFiles([$this->pdf('factura.pdf', 'payload-alpha')]);
        self::assertSame(1, $this->documentCount());

        $this->postFiles([$this->pdf('factura (1) - copie.pdf', 'payload-alpha')]);

        self::assertSame(1, $this->documentCount(), 'Renaming a file must not smuggle it past dedup');
        self::assertCount(0, $this->sentMessages(), 'A renamed duplicate must not cost a second extraction call');
    }

    // ---------- scenario 2: different bytes, same filename ----------

    public function testDifferentContentUnderTheSameFilenameIsStored(): void
    {
        $this->postFiles([$this->pdf('factura.pdf', 'invoice-001')]);
        $this->postFiles([$this->pdf('factura.pdf', 'invoice-002')]);

        self::assertSame(2, $this->documentCount(), 'Two genuinely different invoices exported under the same name must both land');
        $names = $this->documentFilenames();
        self::assertSame(['factura.pdf', 'factura.pdf'], $names);
        self::assertCount(2, array_unique($this->documentHashes()), 'Different bytes must yield different fingerprints');
    }

    public function testTwoDifferentFilesWithTheSameNameInOneBatchBothLand(): void
    {
        $this->postFiles([
            $this->pdf('factura.pdf', 'invoice-A'),
            $this->pdf('factura.pdf', 'invoice-B'),
        ]);

        self::assertSame(2, $this->documentCount());
        self::assertCount(2, $this->sentMessages());
    }

    // ---------- scenario 4: mixed batch [new, duplicate, new] ----------

    public function testMixedBatchStoresExactlyTheTwoNewFilesAndDispatchesTwoMessages(): void
    {
        $this->postFiles([$this->pdf('known.pdf', 'known-payload')]);
        self::assertSame(1, $this->documentCount());

        $this->postFiles([
            $this->pdf('new-a.pdf', 'payload-a'),
            $this->pdf('known-renamed.pdf', 'known-payload'),
            $this->pdf('new-b.pdf', 'payload-b'),
        ]);

        self::assertSame(3, $this->documentCount(), 'A duplicate in the middle must not abort the rest of the batch');
        self::assertCount(2, $this->sentMessages(), 'Exactly two extractions queued for the two new files');

        $names = $this->documentFilenames();
        sort($names);
        self::assertSame(['known.pdf', 'new-a.pdf', 'new-b.pdf'], $names);
    }

    // ---------- scenario 5: twins inside one batch ----------

    public function testThreeIdenticalCopiesInOneBatchProduceOneDocument(): void
    {
        $this->postFiles([
            $this->pdf('scan.pdf', 'triplicate'),
            $this->pdf('scan-copy.pdf', 'triplicate'),
            $this->pdf('scan (2).pdf', 'triplicate'),
        ]);

        self::assertSame(1, $this->documentCount());
        self::assertCount(1, $this->sentMessages());
    }

    public function testFullDuplicateBatchDoesNotClaimAnythingWasUploaded(): void
    {
        $this->postFiles([$this->pdf('factura.pdf', 'only-payload')]);
        $this->postFiles([$this->pdf('factura.pdf', 'only-payload')]);

        $this->client->followRedirect();
        $html = $this->client->getResponse()->getContent();

        self::assertStringContainsString('Fișier ignorat', $html);
        self::assertStringNotContainsString(
            'Documentele au fost încărcate',
            $html,
            'A batch made only of duplicates must not show the success flash',
        );
    }

    // ---------- scenario 6: the same file in two different cases ----------

    public function testTheSameFileCanBeUsedAgainInAFreshDraft(): void
    {
        // One framework contract legitimately backs two payment-order cases
        // against two different debtors. Starting a new case must let the
        // lawyer upload it again.
        $this->postFiles([$this->pdf('contract-cadru.pdf', 'the-contract')]);
        self::assertSame(1, $this->documentCount());

        // "Dosar nou" resets the wizard draft.
        $this->client->request('GET', '/case/new');
        self::assertResponseRedirects('/case/new/documents');

        $this->postFiles([$this->pdf('contract-cadru.pdf', 'the-contract')]);

        self::assertSame(2, $this->documentCount(), 'Dedup is scoped to the draft: a new case must accept the same contract');
        self::assertCount(1, $this->sentMessages(), 'The new draft copy is extracted on its own');

        $hashes = $this->documentHashes();
        self::assertCount(1, array_unique($hashes), 'Both rows carry the same fingerprint, so no unique index may exist on it');
    }

    // ---------- scenario 7: two users, same bytes ----------

    public function testASecondLawyerUploadingTheSameBytesGetsTheirOwnDocument(): void
    {
        $this->postFiles([$this->pdf('shared.pdf', 'cross-tenant-payload')]);
        $mine = $this->em->getRepository(Document::class)->findOneBy(['uploadedBy' => $this->user]);
        self::assertNotNull($mine);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $other = new User();
        $other->setEmail('dedup-adv-other-' . uniqid() . '@test.com');
        $other->setPassword($hasher->hashPassword($other, 'password'));
        $other->setIsVerified(true);
        $other->setFirstName('Other');
        $other->setLastName('Lawyer');
        $this->em->persist($other);
        $this->em->flush();
        $this->extraUserIds[] = $other->getId();

        // Fresh browser session for the second lawyer.
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($other);

        $this->postFiles([$this->pdf('shared.pdf', 'cross-tenant-payload')]);

        $theirs = $this->em->getRepository(Document::class)->findBy(['uploadedBy' => $other]);
        self::assertCount(1, $theirs, 'Another lawyer must never be blocked by someone else fingerprint');
        self::assertSame($mine->getContentHash(), $theirs[0]->getContentHash());

        // And they must not see each other documents in the wizard.
        $this->client->request('GET', '/case/new/documents');
        $html = $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('data-document-id="' . $mine->getId() . '"', $html);
    }

    // ---------- fingerprint bookkeeping ----------

    public function testEveryStoredDocumentCarriesANonEmptyFingerprint(): void
    {
        $this->postFiles([
            $this->pdf('a.pdf', 'aaa'),
            $this->pdf('b.pdf', 'bbb'),
        ]);

        foreach ($this->documentHashes() as $hash) {
            self::assertNotNull($hash, 'T2 must populate content_hash on every upload');
            self::assertSame(64, \strlen($hash));
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        }
    }

    public function testDuplicateSkippedToastNamesTheIgnoredFileOnly(): void
    {
        $this->postFiles([$this->pdf('factura-originala.pdf', 'shared-payload')]);

        $this->postFiles(
            [
                $this->pdf('anexa-noua.pdf', 'fresh-payload'),
                $this->pdf('factura-duplicat.pdf', 'shared-payload'),
            ],
            turbo: true,
        );

        $body = $this->client->getResponse()->getContent();
        self::assertStringContainsString('factura-duplicat.pdf', $body);
        self::assertStringNotContainsString('Fișier ignorat (anexa-noua.pdf', $body);
        self::assertSame(2, $this->documentCount());
    }

    public function testMaxSizeBatchOfIdenticalFilesCollapsesToASingleExtraction(): void
    {
        // The form ceiling is 10 files. Ten copies of one scan must cost one
        // LLM call, and the nine skipped names must all be reported.
        $files = [];
        for ($i = 0; $i < 10; ++$i) {
            $files[] = $this->pdf(sprintf('scan-%d.pdf', $i), 'ten-copies');
        }

        $this->postFiles($files, turbo: true);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->documentCount());
        self::assertCount(1, $this->sentMessages());

        $body = $this->client->getResponse()->getContent();
        self::assertStringContainsString('scan-9.pdf', $body);
        // Mixed outcome, mixed feedback: the nine skipped copies are warned
        // about and the one stored copy is still confirmed.
        self::assertStringContainsString('bg-amber-50', $body, 'The skipped copies must be reported');
        self::assertStringContainsString('bg-emerald-50', $body, 'The stored copy must still be confirmed');
    }

    // ---------- toast safety and fingerprint provenance ----------

    public function testDuplicateToastEscapesAHostileFilename(): void
    {
        // getClientOriginalName() is attacker-controlled and lands inside the
        // duplicate warning. It must reach the DOM escaped.
        $hostile = '<img src=x onerror=alert(1)>.pdf';
        $this->postFiles([$this->pdf($hostile, 'hostile-payload')]);
        $this->postFiles([$this->pdf($hostile, 'hostile-payload')], turbo: true);

        $body = $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $body, 'Filename must not be injected as markup');
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $body);
    }

    public function testDuplicateIsStillDetectedWhenTheStoredFileVanishedFromDisk(): void
    {
        // Dedup must read the fingerprint from the row, not re-hash the disk:
        // a missing file would otherwise silently re-open the door to a second
        // extraction call.
        $this->postFiles([$this->pdf('factura.pdf', 'disk-loss')]);
        $stored = $this->em->getRepository(Document::class)->findOneBy(['uploadedBy' => $this->user]);
        self::assertNotNull($stored);

        $uploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        @unlink($uploadsDir . '/' . $stored->getStoredFilename());

        $this->postFiles([$this->pdf('factura.pdf', 'disk-loss')]);

        self::assertSame(1, $this->documentCount());
        self::assertCount(0, $this->sentMessages());
    }

    public function testDocumentWithoutAFingerprintCannotBlockAnUpload(): void
    {
        // Rows created before T1/T2 have content_hash NULL (the migration adds
        // no backfill). They must degrade to "not deduplicated", never to a
        // false positive that swallows a different file.
        $this->postFiles([$this->pdf('legacy.pdf', 'legacy-payload')]);
        $stored = $this->em->getRepository(Document::class)->findOneBy(['uploadedBy' => $this->user]);
        self::assertNotNull($stored);

        $this->em->getConnection()->executeStatement(
            'UPDATE document SET content_hash = NULL WHERE id = :id',
            ['id' => $stored->getId()],
        );
        $this->em->clear();

        $this->postFiles([$this->pdf('legacy.pdf', 'legacy-payload')]);

        self::assertSame(2, $this->documentCount(), 'A hash-less legacy row simply is not deduplicated');
    }

    public function testDuplicateSurvivesAnInterleavedPageReload(): void
    {
        $this->postFiles([$this->pdf('factura.pdf', 'reload-payload')]);

        $this->client->request('GET', '/case/new/documents');
        self::assertResponseIsSuccessful();

        $this->postFiles([$this->pdf('factura.pdf', 'reload-payload')]);

        self::assertSame(1, $this->documentCount(), 'The draft fingerprint set must survive a GET in between');
    }

    // ---------- helpers ----------

    /**
     * @param list<UploadedFile> $files
     */
    private function postFiles(array $files, bool $turbo = false): void
    {
        $crawler = $this->client->request('GET', '/case/new/documents');
        $token = $crawler->filter('form input[name="step0_documents[_token]"]')->first()->attr('value');

        $server = $turbo
            ? ['HTTP_TURBO_FRAME' => 'step0-dynamic', 'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']
            : [];

        $this->client->request(
            'POST',
            '/case/new/documents',
            parameters: ['step0_documents' => ['_token' => $token]],
            files: ['step0_documents' => ['documents' => $files]],
            server: $server,
        );
    }

    private function documentCount(): int
    {
        return \count($this->em->getRepository(Document::class)->findBy(['uploadedBy' => $this->user]));
    }

    /** @return list<string> */
    private function documentFilenames(): array
    {
        return array_map(
            static fn (Document $d) => $d->getOriginalFilename(),
            $this->em->getRepository(Document::class)->findBy(['uploadedBy' => $this->user], ['id' => 'ASC']),
        );
    }

    /** @return list<?string> */
    private function documentHashes(): array
    {
        return array_map(
            static fn (Document $d) => $d->getContentHash(),
            $this->em->getRepository(Document::class)->findBy(['uploadedBy' => $this->user], ['id' => 'ASC']),
        );
    }

    /**
     * The in-memory transport is reset per request, so this reports what the
     * most recent POST dispatched.
     *
     * @return list<object>
     */
    private function sentMessages(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof ExtractDataMessage) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function pdf(string $clientName, string $marker): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'dedup-adv-');
        file_put_contents(
            $path,
            "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"
            . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Count 0/Kids[]>>endobj\n"
            . "trailer<</Size 3/Root 1 0 R>>\nstartxref\n110\n% " . $marker . "\n%%EOF\n",
        );
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $clientName, 'application/pdf', UPLOAD_ERR_OK, true);
    }
}
