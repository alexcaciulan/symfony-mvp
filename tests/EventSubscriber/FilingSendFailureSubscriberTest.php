<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingPartStatus;
use App\Message\SendFilingEmailPartMessage;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * The bookkeeping after a failed court email runs inside Worker::ack(): an
 * exception there would stop the message from being rejected and crash the
 * consumer, so it must survive a manager closed by the handler's failed flush.
 */
final class FilingSendFailureSubscriberTest extends KernelTestCase
{
    use FilingCaseFixtureTrait;
    use FilingSubmissionTestTrait;

    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('app.uploads_dir');
        $this->user = $this->createFilingUser($this->em, 'filing-failure-sub');
        $this->courtTransport()->reset();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em = static::getContainer()->get(EntityManagerInterface::class);
            $this->em->clear();
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
        }
        parent::tearDown();
    }

    public function testAClosedManagerIsResetAndTheExhaustedEmailIsStillRecorded(): void
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user);
        $submission = $this->submitFiling($this->case, $this->user);
        $submissionId = (int) $submission->getId();
        $part = $submission->getPart(1) ?? throw new \LogicException('No part.');
        $part->setStatus(FilingPartStatus::RETRY_SCHEDULED);
        $this->em->flush();
        $partId = (int) $part->getId();
        // A failed flush in the handler closes the manager before this listener runs.
        $this->em->close();

        $event = new WorkerMessageFailedEvent(
            new Envelope(new SendFilingEmailPartMessage($partId)),
            'court_filing',
            new UnrecoverableMessageHandlingException('Court filing email was not accepted.'),
        );
        static::getContainer()->get(EventDispatcherInterface::class)->dispatch($event);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertTrue($em->isOpen());
        $em->clear();
        $submission = $em->find(FilingSubmission::class, $submissionId);
        self::assertSame(FilingPartStatus::FAILED_TEMPORARY_EXHAUSTED, $submission?->getPart(1)?->getStatus());
        self::assertSame(FilingDeliveryStatus::FAILED_TEMPORARY_EXHAUSTED, $submission->getDeliveryStatus());
    }
}
