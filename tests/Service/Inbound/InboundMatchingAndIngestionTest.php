<?php

declare(strict_types=1);

namespace App\Tests\Service\Inbound;

use App\Entity\FilingSubmission;
use App\Entity\InboundPollHeartbeat;
use App\Entity\LegalCase;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingEvidenceStatus;
use App\Enum\InboundAuthVerdict;
use App\Enum\InboundMatchConfidence;
use App\Enum\InboundMatchMethod;
use App\Enum\InboundReviewStatus;
use App\Enum\NotificationType;
use App\Message\ProcessInboundMailMessage;
use App\Service\Inbound\FixtureInboundSource;
use App\Service\Inbound\InboundEmailIngestor;
use App\Service\Inbound\InboundMatch;
use App\Service\Inbound\InboundMailMatcher;
use App\Service\Inbound\InboundMailParser;
use App\Service\Inbound\InboundRawStorage;
use App\Service\Inbound\RawInboundMail;
use App\Service\Inbound\ReceiptOverdueChecker;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use App\Tests\Support\InboundTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Matching rules on a real submission, the idempotent ingestion, the end-to-end
 * poll through the fixture source, and the receipt-overdue flag.
 */
final class InboundMatchingAndIngestionTest extends KernelTestCase
{
    use FilingCaseFixtureTrait;
    use FilingSubmissionTestTrait;
    use InboundTestTrait;

    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->user = $this->createFilingUser($this->em, 'inbound-match');
        $this->courtTransport()->reset();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->clear();
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
        }
        parent::tearDown();
    }

    public function testVerpTokenMatchesWithCertainty(): void
    {
        $submission = $this->sent();
        $match = $this->match($this->rawForPart($submission->getPart(1), 'postfix-5.1.1'), InboundAuthVerdict::ABSENT);

        self::assertSame(InboundMatchMethod::VERP, $match?->method);
        self::assertSame(InboundMatchConfidence::CERTAIN, $match->confidence);
        self::assertSame($submission->getPart(1)?->getId(), $match->part?->getId());
    }

    public function testReplyOnOurMessageIdIsCertainOnlyWithAnAuthenticatedJustRoSender(): void
    {
        $submission = $this->sent();
        $raw = $this->rawForPart($submission->getPart(1), 'justro-receipt');

        $trusted = $this->match($raw, InboundAuthVerdict::TRUSTED_PASS);
        self::assertSame(InboundMatchMethod::IN_REPLY_TO, $trusted?->method);
        self::assertSame(InboundMatchConfidence::CERTAIN, $trusted->confidence);

        foreach ([InboundAuthVerdict::FAIL, InboundAuthVerdict::ABSENT] as $verdict) {
            $untrusted = $this->match($raw, $verdict);
            self::assertSame(InboundMatchMethod::IN_REPLY_TO, $untrusted?->method);
            self::assertSame(InboundMatchConfidence::PROBABLE, $untrusted->confidence, $verdict->value);
        }
    }

    public function testReportQuotingOurHeadersIsOnlyProbable(): void
    {
        $submission = $this->sent();
        // Delivered to another address: only the quoted headers point at the submission.
        $raw = str_replace('bounces+' . $submission->getPart(1)?->getVerpToken(), 'postmaster', $this->rawForPart($submission->getPart(1), 'exchange-ndr-5.1.1'));

        $match = $this->match($raw, InboundAuthVerdict::ABSENT);

        self::assertSame(InboundMatchMethod::EMBEDDED_HEADERS, $match?->method);
        self::assertSame(InboundMatchConfidence::PROBABLE, $match->confidence);
    }

    public function testSenderDomainGuessIsOnlyProbableAndNeverShownToTheLawyer(): void
    {
        $submission = $this->sent();
        $domain = substr($submission->getToAddress(), (int) strrpos($submission->getToAddress(), '@') + 1);
        $raw = "From: grefa@$domain\nTo: depuneri@lexrecovery.test\nSubject: Dosar nou\nDate: Thu, 22 Oct 2026 10:00:00 +0000\nMessage-ID: <guess-" . bin2hex(random_bytes(4)) . "@$domain>\nContent-Type: text/plain\n\nInformare.";

        $match = $this->match($raw, InboundAuthVerdict::ABSENT);
        self::assertSame(InboundMatchMethod::HEURISTIC, $match?->method);
        self::assertSame(InboundMatchConfidence::PROBABLE, $match->confidence);

        $mail = $this->receive($raw);
        self::assertSame(InboundReviewStatus::PENDING, $mail->getReviewStatus());
        self::assertSame([], $this->em->getRepository(Notification::class)->findBy(['user' => $this->user->getId(), 'type' => NotificationType::FILING_INBOUND_REVIEW->value]), 'A guess is for the admin queue only.');
        self::assertSame(FilingDeliveryStatus::SENT, $this->freshSubmission((int) $submission->getId())->getDeliveryStatus());
    }

    public function testMessageWithNothingOfOursIsNotMatched(): void
    {
        $this->sent();
        $raw = "From: cineva@altundeva.example.test\nTo: depuneri@lexrecovery.test\nSubject: x\nContent-Type: text/plain\n\nx";

        self::assertNull($this->match($raw, InboundAuthVerdict::ABSENT));
    }

    public function testIngestionIsIdempotentAndKeepsTheVerifiedRawCopy(): void
    {
        $ingestor = static::getContainer()->get(InboundEmailIngestor::class);
        $raw = strtr(self::corpus('spam'), ['spam-0001' => 'spam-' . bin2hex(random_bytes(4))]);
        $id = 'ingest-' . bin2hex(random_bytes(4));

        $first = $ingestor->ingest(new RawInboundMail('fixture', $id, $raw, new \DateTimeImmutable()));
        $again = $ingestor->ingest(new RawInboundMail('fixture', $id, $raw, new \DateTimeImmutable()));
        $sameBytes = $ingestor->ingest(new RawInboundMail('fixture', $id . '-copy', $raw, new \DateTimeImmutable()));

        self::assertNotNull($first);
        self::assertNull($again, 'Same source id.');
        self::assertNull($sameBytes, 'Same bytes and Message-ID from another source id.');
        self::assertSame(hash('sha256', $raw), $first->getRawSha256());
        self::assertNotNull(static::getContainer()->get(InboundRawStorage::class)->verifiedPath($first));
        self::assertCount(1, $this->dispatchedProcessing());

        $this->processInbound((int) $first->getId());
        $mail = $this->reloadInbound((int) $first->getId());
        self::assertNotNull($mail->getPurgeAfter(), 'Spam is kept only until the purge.');

        $this->em->remove($mail);
        $this->em->flush();
        static::getContainer()->get(InboundRawStorage::class)->delete($mail->getRawPath());
    }

    public function testPollReadsTheSourceOnceAndTheReceiptReachesTheSubmission(): void
    {
        $submission = $this->sent();
        $source = static::getContainer()->get('test.public.inbound_source');
        self::assertInstanceOf(FixtureInboundSource::class, $source);
        $source->queue($this->rawForPart($submission->getPart(1), 'justro-receipt'), 'e2e-receipt');

        $tester = new CommandTester((new Application(self::$kernel))->find('app:filing:poll-inbound'));
        self::assertSame(0, $tester->execute([]));
        self::assertTrue($source->isAcknowledged('e2e-receipt'));
        $queued = $this->dispatchedProcessing();
        self::assertCount(1, $queued);
        $this->processInbound($queued[0]->getMessage()->inboundMailId);

        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingEvidenceStatus::RECEIPT_RECEIVED, $submission->getEvidenceStatus());
        $forwards = array_filter(self::getMailerEvents(), static fn ($e): bool => !$e->isQueued() && $e->getMessage()->getHeaders()->has('X-LexRecovery-Forward'));
        self::assertCount(1, $forwards);

        // A second run, even with the acknowledgements lost, stores nothing new.
        $source->forgetAcknowledgements();
        self::assertSame(0, $tester->execute([]));
        self::assertCount(1, $this->inboundFor($submission));
        self::assertCount(1, $this->dispatchedProcessing());
        self::assertNotNull($this->em->getRepository(InboundPollHeartbeat::class)->findOneBy([])?->getLastSuccessAt());
    }

    public function testMessageThatCannotBeStoredDoesNotBlockTheOnesAfterIt(): void
    {
        $submission = $this->sent();
        $source = static::getContainer()->get('test.public.inbound_source');
        $raw = $this->rawForPart($submission->getPart(1), 'justro-receipt');
        // An identifier longer than its column makes the insert fail in the database.
        $broken = str_repeat('x', 200);
        $source->queue($raw, $broken);
        $source->queue($raw, 'after-broken');

        $tester = new CommandTester((new Application(self::$kernel))->find('app:filing:poll-inbound'));
        self::assertSame(0, $tester->execute([]));

        self::assertFalse($source->isAcknowledged($broken), 'Left for the next run.');
        self::assertTrue($source->isAcknowledged('after-broken'));
        $queued = $this->dispatchedProcessing();
        self::assertCount(1, $queued);
        $this->processInbound($queued[0]->getMessage()->inboundMailId);
        self::assertSame(FilingEvidenceStatus::RECEIPT_RECEIVED, $this->freshSubmission((int) $submission->getId())->getEvidenceStatus());
        $heartbeat = $this->freshHeartbeat();
        self::assertNotNull($heartbeat->getLastSuccessAt());
        self::assertStringStartsWith('1 of 2 messages could not be stored', (string) $heartbeat->getLastError());
    }

    public function testPollFailsWhenNoMessageCouldBeStored(): void
    {
        $submission = $this->sent();
        $source = static::getContainer()->get('test.public.inbound_source');
        $raw = $this->rawForPart($submission->getPart(1), 'justro-receipt');
        $broken = str_repeat('y', 200);
        $source->queue($raw, $broken);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:filing:poll-inbound'));
        try {
            self::assertSame(1, $tester->execute([]));
            self::assertFalse($source->isAcknowledged($broken));
            self::assertSame([], $this->dispatchedProcessing());
            self::assertStringStartsWith('1 of 1 messages could not be stored', (string) $this->freshHeartbeat()->getLastError());
        } finally {
            foreach (glob($this->filingUploadsDir . '/inbound/*/*/' . hash('sha256', $raw) . '.eml') ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    public function testReceiptOverdueFlagsAfterTheWorkingDaysAndOnlyOnce(): void
    {
        $submission = $this->sent();
        $entity = $this->em->getRepository(FilingSubmission::class)->find($submission->getId());
        // Monday 5 October 2026, a working day.
        $entity->setSentAt(new \DateTimeImmutable('2026-10-05 09:00:00', new \DateTimeZone('Europe/Bucharest')));
        $this->em->flush();
        $checker = static::getContainer()->get(ReceiptOverdueChecker::class);

        self::assertSame([], $this->idsOf($checker->run(new \DateTimeImmutable('2026-10-06 18:00:00', new \DateTimeZone('Europe/Bucharest')))), 'One working day has not passed.');
        self::assertSame([(int) $submission->getId()], $this->idsOf($checker->run(new \DateTimeImmutable('2026-10-07 08:00:00', new \DateTimeZone('Europe/Bucharest')))));
        self::assertSame([], $this->idsOf($checker->run(new \DateTimeImmutable('2026-10-08 08:00:00'))), 'Flagged once.');

        $submission = $this->freshSubmission((int) $submission->getId());
        self::assertSame(FilingEvidenceStatus::RECEIPT_OVERDUE, $submission->getEvidenceStatus());
        self::assertSame(FilingDeliveryStatus::SENT, $submission->getDeliveryStatus(), 'No effect on the transmission.');
        self::assertCount(1, $this->em->getRepository(Notification::class)->findBy(['user' => $this->user->getId(), 'type' => NotificationType::FILING_RECEIPT_OVERDUE->value]));
    }

    public function testReceiptOverdueSkipsUndeliveredAndCancelledButIncludesClosedPartial(): void
    {
        $submission = $this->sent();
        $entity = $this->em->getRepository(FilingSubmission::class)->find($submission->getId());
        $entity->setSentAt(new \DateTimeImmutable('2026-10-05 09:00:00'));
        $checker = static::getContainer()->get(ReceiptOverdueChecker::class);
        $later = new \DateTimeImmutable('2026-10-12 09:00:00');

        foreach ([FilingDeliveryStatus::UNDELIVERED, FilingDeliveryStatus::CANCELLED] as $status) {
            $entity->setDeliveryStatus($status);
            $this->em->flush();
            self::assertSame([], $this->idsOf($checker->run($later)), $status->value);
        }
        $entity = $this->em->getRepository(FilingSubmission::class)->find($submission->getId());
        $entity->setDeliveryStatus(FilingDeliveryStatus::CLOSED_PARTIAL);
        $this->em->flush();
        self::assertSame([(int) $submission->getId()], $this->idsOf($checker->run($later)), 'The petition left without every annex: still waiting for a receipt.');
    }

    private function freshHeartbeat(): InboundPollHeartbeat
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $heartbeat = $em->getRepository(InboundPollHeartbeat::class)->findOneBy([]);
        self::assertNotNull($heartbeat);

        return $heartbeat;
    }

    private function sent(int $emails = 1): FilingSubmission
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, $emails);

        return $this->sentSubmission($this->case, $this->user);
    }

    private function match(string $raw, InboundAuthVerdict $auth): ?InboundMatch
    {
        return static::getContainer()->get(InboundMailMatcher::class)->match((new InboundMailParser())->parse($raw), $auth);
    }

    /** @return list<Envelope> */
    private function dispatchedProcessing(): array
    {
        /** @var InMemoryTransport $async */
        $async = static::getContainer()->get('messenger.transport.async');

        return array_values(array_filter($async->getSent(), static fn (Envelope $e): bool => $e->getMessage() instanceof ProcessInboundMailMessage));
    }

    /**
     * @param list<FilingSubmission> $submissions
     *
     * @return list<int>
     */
    private function idsOf(array $submissions): array
    {
        return array_map(static fn (FilingSubmission $s): int => (int) $s->getId(), $submissions);
    }
}
