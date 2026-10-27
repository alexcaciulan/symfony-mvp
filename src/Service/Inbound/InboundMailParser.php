<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Message;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * Turns raw bytes into what the processing reads. HTML is reduced to text
 * (scripts and styles dropped first) and never kept as markup.
 */
final class InboundMailParser
{
    private const MARKERS = [
        'auto-submitted', 'x-auto-response-suppress', 'precedence', 'x-autoreply', 'x-autorespond',
        'x-lexrecovery-forward', 'x-lexrecovery-ref', 'x-spam-flag', 'x-spam-status', 'x-spam-score',
        'return-path', 'content-type', 'x-ms-exchange-inbox-rules-loop', 'x-simulated',
    ];

    /**
     * @param string $inboundDeliveryHeader the delivery header our own MTA prepends
     *                                      (topmost occurrence only, as for the authserv-id)
     */
    public function __construct(
        private readonly DsnParser $dsnParser = new DsnParser(),
        private readonly MdnParser $mdnParser = new MdnParser(),
        private readonly string $inboundDeliveryHeader = 'Delivered-To',
    ) {}

    public function parse(string $raw): ParsedInboundMail
    {
        $message = Message::from($raw, false);

        $markers = [];
        foreach (self::MARKERS as $name) {
            $value = $message->getHeaderValue($name);
            if ($value !== null) {
                $markers[$name] = $name === 'content-type' ? strtolower((string) $message->getHeader($name)?->getRawValue()) : $value;
            }
        }

        $auth = [];
        foreach ($message->getAllHeadersByName('Authentication-Results') as $header) {
            $auth[] = trim((string) preg_replace('/\s+/', ' ', $header->getRawValue()));
        }

        $envelope = [];
        foreach (['Delivered-To', 'X-Original-To', 'Envelope-To'] as $name) {
            foreach ($message->getAllHeadersByName($name) as $header) {
                foreach (self::emails($header->getRawValue()) as $email) {
                    $envelope[] = $email;
                }
            }
        }

        // Only the copy our MTA wrote on top is ours; any below came with the message.
        $trusted = null;
        if (trim($this->inboundDeliveryHeader) !== '') {
            $top = $message->getHeader(trim($this->inboundDeliveryHeader));
            $trusted = $top !== null ? (self::emails($top->getRawValue())[0] ?? null) : null;
        }

        $text = $this->text($message);
        $deliveryStatus = self::partContent($message, 'message/delivery-status');
        $disposition = self::partContent($message, 'message/disposition-notification');
        $embedded = self::partContent($message, 'message/rfc822') ?? self::partContent($message, 'text/rfc822-headers');

        $from = self::firstAddress($message, 'From');
        // A bounce comes from the null sender (Return-Path: <>) or a mail daemon.
        $nullSender = $message->getHeader('Return-Path') !== null && trim((string) $message->getHeader('Return-Path')?->getRawValue(), "<> \t") === '';
        $looksLikeBounce = $deliveryStatus !== null
            || $nullSender
            || preg_match('/^(mailer-daemon|postmaster)@/i', (string) $from) === 1
            || preg_match('/(undeliver|delivery status notification|returned mail|nedistribuit|failure notice|delivery has failed)/i', (string) $message->getSubject()) === 1;

        return new ParsedInboundMail(
            messageId: self::messageId($message->getHeaderValue('Message-ID')),
            inReplyTo: self::messageId($message->getHeaderValue('In-Reply-To')),
            references: self::messageIds((string) $message->getHeader('References')?->getRawValue()),
            from: $from,
            to: self::addresses($message, 'To'),
            cc: self::addresses($message, 'Cc'),
            envelopeRecipients: array_values(array_unique($envelope)),
            subject: $message->getSubject(),
            date: self::date($message->getHeaderValue('Date')),
            markers: $markers,
            authenticationResults: $auth,
            text: $text,
            dsn: $this->dsnParser->parse($deliveryStatus, $text, $looksLikeBounce),
            mdn: $this->mdnParser->parse($disposition),
            embeddedMessageId: $embedded !== null && preg_match('/^Message-ID:\s*(<[^>]+>)/mi', $embedded, $m) === 1 ? $m[1] : null,
            embeddedRef: $embedded !== null && preg_match('/^X-LexRecovery-Ref:\s*([a-z2-7]{26})\s*$/mi', $embedded, $r) === 1 ? strtolower($r[1]) : null,
            trustedDeliveryRecipient: $trusted,
        );
    }

    /** Readable text only: the plain part, or the HTML part stripped of every tag. */
    private function text(IMessage $message): string
    {
        $text = $message->getTextContent();
        if ($text === null || trim($text) === '') {
            $html = $message->getHtmlContent();
            if ($html !== null) {
                $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
                $text = html_entity_decode(strip_tags((string) preg_replace('#<br\s*/?>|</p>#i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return trim((string) $text);
    }

    private static function partContent(IMessage $message, string $mimeType): ?string
    {
        $part = $message->getPartByMimeType($mimeType);

        return $part instanceof IMessagePart ? $part->getContent() : null;
    }

    private static function firstAddress(IMessage $message, string $name): ?string
    {
        return self::addresses($message, $name)[0] ?? null;
    }

    /** @return list<string> */
    private static function addresses(IMessage $message, string $name): array
    {
        $out = [];
        foreach ($message->getAllHeadersByName($name) as $header) {
            if ($header instanceof AddressHeader) {
                foreach ($header->getAddresses() as $address) {
                    $email = strtolower(trim($address->getEmail()));
                    if ($email !== '') {
                        $out[] = $email;
                    }
                }
            } else {
                foreach (self::emails($header->getRawValue()) as $email) {
                    $out[] = $email;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private static function emails(string $value): array
    {
        preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+/', $value, $m);

        return array_values(array_map('strtolower', $m[0]));
    }

    private static function messageId(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (preg_match('/<[^<>\s]+>/', $value, $m) === 1) {
            return $m[0];
        }

        return '<' . trim($value, '<> ') . '>';
    }

    /** @return list<string> */
    private static function messageIds(string $value): array
    {
        preg_match_all('/<[^<>\s]+>/', $value, $m);

        return array_values(array_unique($m[0]));
    }

    private static function date(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
