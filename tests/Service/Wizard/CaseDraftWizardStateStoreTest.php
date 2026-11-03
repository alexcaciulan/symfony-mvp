<?php

declare(strict_types=1);

namespace App\Tests\Service\Wizard;

use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\CaseDraft;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Enum\PersonType;
use App\Service\Wizard\CaseDraftPayloadCodec;
use App\Service\Wizard\CaseDraftWizardStateStore;
use App\Service\Wizard\WizardBag;
use App\Service\Wizard\WizardStateStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class CaseDraftWizardStateStoreTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CaseDraftWizardStateStore $store;
    private CaseDraftPayloadCodec $codec;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->store = static::getContainer()->get(CaseDraftWizardStateStore::class);
        $this->codec = static::getContainer()->get(CaseDraftPayloadCodec::class);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail('draft-store-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();

        static::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($this->user, 'main', $this->user->getRoles()));
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM case_draft WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);

        parent::tearDown();
    }

    public function testTheInterfaceResolvesToTheDraftStore(): void
    {
        self::assertInstanceOf(CaseDraftWizardStateStore::class, static::getContainer()->get(WizardStateStore::class));
    }

    public function testAnEmptySessionLoadsAnEmptyBagAndCreatesNothing(): void
    {
        $session = $this->session();

        $bag = $this->store->load($session);
        $this->store->save($session, $bag);

        self::assertSame([], $bag->documentIds);
        self::assertSame(0, $this->draftCount());
        self::assertFalse($session->has(CaseDraftWizardStateStore::SESSION_KEY));
    }

    public function testTheFirstSaveCreatesTheDraftAndAnotherSessionReadsItBack(): void
    {
        $session = $this->session();
        $creditor = new Step1CreditorData(personType: PersonType::PJ, name: 'Cedent SRL', cui: 'RO15193236');
        $bag = new WizardBag(documentIds: [1, 2], creditor: $creditor, claimItemsTableConfirmed: true);

        $this->store->save($session, $bag);

        $id = $session->get(CaseDraftWizardStateStore::SESSION_KEY);
        self::assertIsInt($id);
        $draft = $this->em->find(CaseDraft::class, $id);
        self::assertInstanceOf(CaseDraft::class, $draft);
        self::assertSame(CaseDraft::STEP_DEBTOR, $draft->getStepReached());
        self::assertSame(2, $draft->getDocumentsCount());
        self::assertSame('Cedent SRL', $draft->getCreditorName());

        $other = $this->session();
        $this->store->attach($other, $draft);
        $loaded = $this->store->load($other);

        self::assertSame(serialize($bag->toArray()), serialize($loaded->toArray()));
        self::assertSame([1, 2], $loaded->documentIds);
        self::assertNotSame($creditor, $loaded->creditor, 'the second session reads its own copy');
        self::assertSame(1, $this->auditCount('draft_created'));
    }

    public function testAnUnchangedSaveWritesNothing(): void
    {
        $session = $this->session();
        $this->store->save($session, new WizardBag(documentIds: [1]));
        $draft = $this->currentDraft($session);
        $this->em->refresh($draft);
        $version = $draft->getVersion();
        $updatedAt = $draft->getUpdatedAt();

        $bag = $this->store->load($session);
        $this->store->save($session, $bag);

        $this->em->refresh($draft);
        self::assertSame($version, $draft->getVersion());
        self::assertEquals($updatedAt, $draft->getUpdatedAt());
    }

    public function testAnInvalidIdInTheSessionLoadsAnEmptyBagAndClearsTheSession(): void
    {
        $session = $this->session();
        $session->set(CaseDraftWizardStateStore::SESSION_KEY, 999_999_999);

        $bag = $this->store->load($session);

        self::assertSame([], $bag->documentIds);
        self::assertFalse($session->has(CaseDraftWizardStateStore::SESSION_KEY));
    }

    public function testAnotherUsersDraftIdInTheSessionIsNotLoaded(): void
    {
        $stranger = $this->anotherUser();
        $draft = new CaseDraft($stranger);
        $draft->setPayload($this->codec->encode(new WizardBag(documentIds: [7])), CaseDraftPayloadCodec::PAYLOAD_VERSION);
        $this->em->persist($draft);
        $this->em->flush();
        $session = $this->session();
        $session->set(CaseDraftWizardStateStore::SESSION_KEY, $draft->getId());

        $bag = $this->store->load($session);

        self::assertSame([], $bag->documentIds);
        self::assertFalse($session->has(CaseDraftWizardStateStore::SESSION_KEY));
        $this->em->getConnection()->executeStatement('DELETE FROM case_draft WHERE user_id = ?', [$stranger->getId()]);
        $this->em->getConnection()->executeStatement('DELETE FROM `user` WHERE id = ?', [$stranger->getId()]);
    }

    public function testTwoSavesStartedFromTheSameVersionKeepBothDocumentIds(): void
    {
        $first = $this->session();
        $this->store->save($first, new WizardBag(documentIds: [1]));
        $draft = $this->currentDraft($first);
        $second = $this->session();
        $this->store->attach($second, $draft);

        // Both tabs read version 1; the first writes, the second writes over
        // a row it no longer knows the version of.
        $fromFirst = $this->store->load($first);
        $fromSecond = $this->store->load($second);
        $fromFirst->documentIds[] = 2;
        $this->store->save($first, $fromFirst);
        $fromSecond->documentIds[] = 3;
        $fromSecond->creditor = new Step1CreditorData(name: 'Later SRL');
        $this->store->save($second, $fromSecond);

        $final = $this->store->load($this->sessionAttachedTo($draft));
        self::assertSame([1, 3, 2], $final->documentIds, 'the merge keeps the documents of both writes');
        self::assertSame('Later SRL', $final->creditor?->name, 'the later write wins for the rest');
        $this->em->refresh($draft);
        self::assertSame(3, $draft->getVersion());
    }

    public function testAnUnreadablePayloadIsReportedAndLoadsAnEmptyBag(): void
    {
        $draft = new CaseDraft($this->user);
        $draft->setPayload('not a serialized bag', CaseDraftPayloadCodec::PAYLOAD_VERSION);
        $this->em->persist($draft);
        $this->em->flush();
        $session = $this->sessionAttachedTo($draft);

        self::assertFalse($this->codec->isReadable($draft));
        $bag = $this->store->load($session);

        self::assertSame([], $bag->documentIds);
        self::assertFalse($session->has(CaseDraftWizardStateStore::SESSION_KEY), 'the session lets go of a draft it cannot read');
        self::assertNotNull($this->em->find(CaseDraft::class, $draft->getId()), 'the row stays for the list to show and the user to drop');
    }

    public function testAnUnknownPayloadVersionIsUnreadable(): void
    {
        $draft = new CaseDraft($this->user);
        $draft->setPayload($this->codec->encode(new WizardBag(documentIds: [1])), CaseDraftPayloadCodec::PAYLOAD_VERSION + 1);

        self::assertFalse($this->codec->isReadable($draft));
    }

    public function testAPayloadNamingAClassOutsideTheAllowedListIsUnreadable(): void
    {
        // The bytes are a valid serialization, but of an object the codec does
        // not let in; unserialize turns it into an incomplete class.
        $payload = serialize(['documentIds' => [1], 'creditor' => new \ArrayObject(['name' => 'Cedent SRL'])]);
        self::assertStringContainsString('ArrayObject', $payload);
        $draft = new CaseDraft($this->user);
        $draft->setPayload($payload, CaseDraftPayloadCodec::PAYLOAD_VERSION);
        $this->em->persist($draft);
        $this->em->flush();
        $session = $this->sessionAttachedTo($draft);

        self::assertNull($this->codec->decode($payload, CaseDraftPayloadCodec::PAYLOAD_VERSION));
        self::assertFalse($this->codec->isReadable($draft));
        $bag = $this->store->load($session);

        self::assertSame([], $bag->documentIds, 'nothing of an unreadable payload is kept, not even the readable part');
        self::assertFalse($session->has(CaseDraftWizardStateStore::SESSION_KEY));
        self::assertNotNull($this->em->find(CaseDraft::class, $draft->getId()));
    }

    public function testAnIncompleteClassNestedInsideADtoIsUnreadable(): void
    {
        $creditor = new Step1CreditorData(name: 'Cedent SRL');
        $payload = serialize(['documentIds' => [], 'creditor' => $creditor]);
        // A class renamed or removed in a later deployment reads back as
        // incomplete wherever it sits, including inside an allowed DTO. The
        // new name has the same length, so the bytes stay a valid serialization.
        $payload = str_replace('App\\DTO\\Wizard\\Step1CreditorData', 'App\\DTO\\Wizard\\Step1CreditorGone', $payload);

        self::assertNull($this->codec->decode($payload, CaseDraftPayloadCodec::PAYLOAD_VERSION));
    }

    public function testResetDeletesTheDraft(): void
    {
        $session = $this->session();
        $this->store->save($session, new WizardBag(documentIds: [1]));
        $id = $session->get(CaseDraftWizardStateStore::SESSION_KEY);

        $this->store->reset($session);

        self::assertFalse($session->has(CaseDraftWizardStateStore::SESSION_KEY));
        self::assertNull($this->em->find(CaseDraft::class, $id));
        self::assertSame([], $this->store->load($session)->documentIds);
    }

    public function testSavingAnEmptyBagOverADraftDeletesIt(): void
    {
        $session = $this->session();
        $this->store->save($session, new WizardBag(documentIds: [1]));
        $id = $session->get(CaseDraftWizardStateStore::SESSION_KEY);

        $this->store->save($session, new WizardBag());

        self::assertNull($this->em->find(CaseDraft::class, $id));
        self::assertFalse($session->has(CaseDraftWizardStateStore::SESSION_KEY));
        self::assertSame(1, $this->auditCount('draft_discarded'));
    }

    public function testDetachKeepsTheDraftAndStartsOver(): void
    {
        $session = $this->session();
        $this->store->save($session, new WizardBag(documentIds: [1]));
        $id = $session->get(CaseDraftWizardStateStore::SESSION_KEY);

        $this->store->detach($session);

        self::assertSame([], $this->store->load($session)->documentIds);
        self::assertNotNull($this->em->find(CaseDraft::class, $id));
    }

    public function testSavingLinksTheOwnersPendingDocumentsToTheDraft(): void
    {
        $mine = $this->document($this->user);
        $session = $this->session();

        $this->store->save($session, new WizardBag(documentIds: [$mine->getId()]));

        $draft = $this->currentDraft($session);
        $this->em->refresh($mine);
        self::assertSame($draft->getId(), $mine->getDraft()?->getId());
    }

    public function testADocumentLinkedToTheDraftButMissingFromItsListIsRepairedOnLoad(): void
    {
        $session = $this->session();
        $this->store->save($session, new WizardBag(documentIds: [1]));
        $draft = $this->currentDraft($session);
        $lost = $this->document($this->user);
        $lost->setDraft($draft);
        $this->em->flush();

        $bag = $this->store->load($session);

        self::assertSame([1, $lost->getId()], $bag->documentIds);
    }

    public function testTheCommittedPayloadFixtureStillReadsWithEveryPropertyInitialized(): void
    {
        $payload = (string) file_get_contents(\dirname(__DIR__, 2) . '/fixtures/wizard/case_draft_payload_v1.bin');

        $raw = $this->codec->decode($payload, CaseDraftPayloadCodec::PAYLOAD_VERSION);

        self::assertNotNull($raw, 'the fixture written at the current payload version must still decode');
        $bag = WizardBag::fromArray($raw);
        self::assertSame([1, 2], $bag->documentIds);
        self::assertInstanceOf(Step1CreditorData::class, $bag->creditor);
        self::assertInstanceOf(Step2DebtorsData::class, $bag->debtors);
        self::assertInstanceOf(Step3ClaimData::class, $bag->claim);
        self::assertCount(1, $bag->claimItems ?? []);
        self::assertCount(1, $bag->conflictResolutions);
        // A property added to a DTO without a default stays uninitialized on
        // an older payload and would break the first step that reads it.
        $this->assertEveryPropertyInitialized($bag->toArray(), 'bag');
    }

    private function assertEveryPropertyInitialized(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->assertEveryPropertyInitialized($item, $path . '.' . $key);
            }

            return;
        }
        if (!is_object($value) || $value instanceof \UnitEnum || $value instanceof \DateTimeInterface) {
            return;
        }
        foreach ((new \ReflectionObject($value))->getProperties() as $property) {
            self::assertTrue(
                $property->isInitialized($value),
                sprintf('%s::$%s (at %s) is not initialized by the stored payload: give it a default', $value::class, $property->getName(), $path),
            );
            $this->assertEveryPropertyInitialized($property->getValue($value), $path . '.' . $property->getName());
        }
    }

    private function session(): SessionInterface
    {
        return new Session(new MockArraySessionStorage());
    }

    private function sessionAttachedTo(CaseDraft $draft): SessionInterface
    {
        $session = $this->session();
        $this->store->attach($session, $draft);

        return $session;
    }

    private function currentDraft(SessionInterface $session): CaseDraft
    {
        $draft = $this->store->currentDraft($session);
        self::assertInstanceOf(CaseDraft::class, $draft);

        return $draft;
    }

    private function draftCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM case_draft WHERE user_id = ?', [$this->user->getId()]);
    }

    private function auditCount(string $action): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE user_id = ? AND action = ?', [$this->user->getId(), $action]);
    }

    private function document(User $owner): Document
    {
        $document = new Document();
        $document->setUploadedBy($owner);
        $document->setDocumentType(DocumentType::FACTURA);
        $document->setOriginalFilename('factura.pdf');
        $document->setStoredFilename('stored-' . uniqid() . '.pdf');
        $document->setMimeType('application/pdf');
        $document->setFileSize(10);
        $document->setExtractionStatus(ExtractionStatus::COMPLETED);
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    private function anotherUser(): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('draft-store-other-' . uniqid() . '@test.com');
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $user->setIsVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
