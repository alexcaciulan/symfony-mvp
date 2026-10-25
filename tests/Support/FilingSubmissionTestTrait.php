<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Message\SendFilingEmailPartMessage;
use App\Service\Filing\FilingSubmissionService;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Drives a court filing the way production does in a kernel test: the lawyer's
 * request with a security token, then each queued email handled as the
 * `court_filing` worker would, against the recording transport.
 */
trait FilingSubmissionTestTrait
{
    private function actAs(User $user): void
    {
        static::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    /** @param array<string, bool> $confirmed */
    private function submitFiling(LegalCase $case, User $user, array $confirmed = [FilingSubmissionService::CONFIRM_DOCUMENTS => true]): FilingSubmission
    {
        $this->actAs($user);

        return static::getContainer()->get(FilingSubmissionService::class)->submit($case, $user, $confirmed, '127.0.0.1', hash('sha256', 'session'));
    }

    private function courtQueue(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.court_filing');
    }

    private function courtTransport(): RecordingCourtTransport
    {
        return static::getContainer()->get(RecordingCourtTransport::class);
    }

    /**
     * Handles the next queued email as the worker would (no session), and acks it.
     *
     * @return bool false when the queue was empty
     */
    private function handleNextFilingMessage(): bool
    {
        $queue = $this->courtQueue();
        $envelopes = [...$queue->get()];
        if ($envelopes === []) {
            return false;
        }
        $envelope = $envelopes[0];
        $queue->ack($envelope);

        /** @var SendFilingEmailPartMessage $message */
        $message = $envelope->getMessage();
        $this->handleFilingPart($message->partId);

        return true;
    }

    /** Handles one email by id; for a web test, where each request boots a new container and its queue. */
    private function handleFilingPart(int $partId): void
    {
        static::getContainer()->get('security.token_storage')->setToken(null);
        static::getContainer()->get(MessageBusInterface::class)->dispatch(new Envelope(new SendFilingEmailPartMessage($partId), [new ReceivedStamp('court_filing')]));
    }

    private function handleAllFilingMessages(): void
    {
        $guard = 0;
        while ($this->handleNextFilingMessage() && ++$guard < 20) {
        }
    }
}
