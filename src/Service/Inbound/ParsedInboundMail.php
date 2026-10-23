<?php

declare(strict_types=1);

namespace App\Service\Inbound;

/**
 * What the processing reads from a message: addressing and threading headers,
 * the automation markers, our own markers, the Authentication-Results copies in
 * order (topmost first), plain text, and the delivery or disposition report. The
 * HTML body is reduced to text and never kept as markup.
 */
final readonly class ParsedInboundMail
{
    /**
     * @param list<string>              $to
     * @param list<string>              $cc
     * @param list<string>              $envelopeRecipients Delivered-To, X-Original-To, Envelope-To
     * @param list<string>              $references         message ids, with angle brackets
     * @param list<string>              $authenticationResults raw values, topmost first
     * @param array<string, string>     $markers            lowercased header name => value, automation and spam markers
     */
    public function __construct(
        public ?string $messageId,
        public ?string $inReplyTo,
        public array $references,
        public ?string $from,
        public array $to,
        public array $cc,
        public array $envelopeRecipients,
        public ?string $subject,
        public ?\DateTimeImmutable $date,
        public array $markers,
        public array $authenticationResults,
        public string $text,
        public ?DsnResult $dsn,
        public ?MdnResult $mdn,
        public ?string $embeddedMessageId,
        public ?string $embeddedRef,
        /** The recipient in the delivery header our own MTA prepends, never one that came with the message. */
        public ?string $trustedDeliveryRecipient = null,
    ) {}

    public function marker(string $name): ?string
    {
        return $this->markers[strtolower($name)] ?? null;
    }

    /** Every message id this one answers, In-Reply-To first. */
    public function threadIds(): array
    {
        $ids = $this->references;
        if ($this->inReplyTo !== null) {
            array_unshift($ids, $this->inReplyTo);
        }

        return array_values(array_unique($ids));
    }
}
