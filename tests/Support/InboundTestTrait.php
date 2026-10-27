<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\FilingEmailPart;
use App\Entity\FilingSubmission;
use App\Entity\InboundMail;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Message\ProcessInboundMailMessage;
use App\Repository\FilingSubmissionRepository;
use App\Repository\InboundMailRepository;
use App\Service\Inbound\InboundEmailIngestor;
use App\Service\Inbound\RawInboundMail;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Builds received messages from the .eml corpus around a real submission (its
 * VERP token, Message-ID and reference) and runs them through the production
 * pipeline: the ingestor, then the processing handler as the `async` worker would
 * run it, with no session.
 */
trait InboundTestTrait
{
    private static function corpus(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/inbound/' . $name . '.eml');
    }

    /** @param array<string, string> $vars placeholder => value, on top of the part's own */
    private function rawForPart(FilingEmailPart $part, string $fixture, array $vars = []): string
    {
        $submission = $part->getSubmission();

        return strtr(self::corpus($fixture), $vars + [
            '{{VERP}}' => $part->getVerpToken(),
            '{{MSGID}}' => $part->getMessageId(),
            '{{REF}}' => $submission->getToken(),
            '{{COURT}}' => $submission->getToAddress(),
        ]);
    }

    /** Stores and processes one message; returns the stored row as the database now has it. */
    private function receive(string $raw, ?string $sourceId = null): InboundMail
    {
        $container = static::getContainer();
        $container->get('security.token_storage')->setToken(null);
        // A worker starts each message with an empty unit of work.
        $container->get('doctrine.orm.entity_manager')->clear();
        $mail = $container->get(InboundEmailIngestor::class)->ingest(new RawInboundMail('fixture', $sourceId ?? 'msg-' . bin2hex(random_bytes(6)), $raw, new \DateTimeImmutable()));
        self::assertNotNull($mail, 'The message was stored.');
        $id = (int) $mail->getId();
        $this->processInbound($id);

        return $this->reloadInbound($id);
    }

    private function processInbound(int $id): void
    {
        static::getContainer()->get('security.token_storage')->setToken(null);
        static::getContainer()->get(MessageBusInterface::class)->dispatch(new Envelope(new ProcessInboundMailMessage($id), [new ReceivedStamp('async')]));
    }

    private function reloadInbound(int $id): InboundMail
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();

        return $em->getRepository(InboundMail::class)->find($id) ?? throw new \LogicException('Inbound mail gone.');
    }

    private function freshSubmission(int $id): FilingSubmission
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();

        return $em->getRepository(FilingSubmission::class)->find($id) ?? throw new \LogicException('Submission gone.');
    }

    /** A sendable case whose emails were all accepted by the recording SMTP transport. */
    private function sentSubmission(LegalCase $case, User $user): FilingSubmission
    {
        $submission = $this->submitFiling($case, $user);
        $this->handleAllFilingMessages();

        return $this->freshSubmission((int) $submission->getId());
    }

    /** @return list<InboundMail> */
    private function inboundFor(FilingSubmission $submission): array
    {
        return static::getContainer()->get(InboundMailRepository::class)->findForSubmission(
            static::getContainer()->get(FilingSubmissionRepository::class)->find($submission->getId()),
        );
    }
}
