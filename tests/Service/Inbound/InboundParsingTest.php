<?php

declare(strict_types=1);

namespace App\Tests\Service\Inbound;

use App\Enum\InboundAuthVerdict;
use App\Enum\InboundClassification;
use App\Service\Filing\SmtpFailureClassifier;
use App\Service\Inbound\DsnResult;
use App\Service\Inbound\InboundAuthVerifier;
use App\Service\Inbound\InboundMailClassifier;
use App\Service\Inbound\InboundMailParser;
use App\Service\Inbound\ParsedInboundMail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The .eml corpus through the parser, the classifier and the sender check, with
 * the placeholders filled as for a real submission.
 */
final class InboundParsingTest extends TestCase
{
    private const VERP = 'a1b2c3d4e5f60718293a4b5c';
    private const MSGID = '<filing.abcdefghijklmnopqrstuvwxyz.1@lexrecovery.test>';
    private const REF = 'abcdefghijklmnopqrstuvwxyz';
    private const COURT = 'registratura@instanta-test.example.test';

    private static function parse(string $fixture, array $vars = []): ParsedInboundMail
    {
        $raw = strtr((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/inbound/' . $fixture . '.eml'), $vars + [
            '{{VERP}}' => self::VERP,
            '{{MSGID}}' => self::MSGID,
            '{{REF}}' => self::REF,
            '{{COURT}}' => self::COURT,
        ]);

        return (new InboundMailParser())->parse($raw);
    }

    private static function classifier(): InboundMailClassifier
    {
        // The phrases the application runs with, so a too generic one fails here.
        $parameters = Yaml::parseFile(dirname(__DIR__, 3) . '/config/packages/lexrecovery.yaml')['parameters'];

        return new InboundMailClassifier(new SmtpFailureClassifier(), 'lexrecovery.test', $parameters['lexrecovery.inbound.receipt_phrases'], $parameters['lexrecovery.inbound.receipt_general_phrases']);
    }

    /** @return iterable<string, array{string, InboundClassification}> */
    public static function corpusClasses(): iterable
    {
        yield 'Exchange NDR 5.1.1' => ['exchange-ndr-5.1.1', InboundClassification::BOUNCE_PERMANENT];
        yield 'Postfix 5.1.1' => ['postfix-5.1.1', InboundClassification::BOUNCE_PERMANENT];
        yield '552 5.3.4' => ['size-552-5.3.4', InboundClassification::BOUNCE_SIZE];
        yield '4.4.7 failed' => ['temp-4.4.7', InboundClassification::BOUNCE_TEMPORARY];
        yield 'delayed' => ['delayed', InboundClassification::DELAYED];
        yield 'MDN' => ['mdn', InboundClassification::READ_RECEIPT];
        yield 'Outlook out of office' => ['outlook-autoreply', InboundClassification::AUTO_REPLY_OTHER];
        yield 'court receipt' => ['justro-receipt', InboundClassification::RECEIPT];
        yield 'Romanian auto-reply' => ['ro-autoreply', InboundClassification::AUTO_REPLY_OTHER];
        yield 'out of office saying the message was received' => ['ro-autoreply-received', InboundClassification::AUTO_REPLY_OTHER];
        yield 'Outlook out of office saying the message was received' => ['outlook-autoreply-received', InboundClassification::AUTO_REPLY_OTHER];
        yield 'clerk reply' => ['human-reply', InboundClassification::HUMAN_REPLY];
        yield 'non-RFC bounce' => ['nonrfc-bounce', InboundClassification::BOUNCE_PERMANENT];
        yield 'spam' => ['spam', InboundClassification::SPAM];
        yield 'receipt with forged auth' => ['receipt-forged-auth', InboundClassification::RECEIPT];
    }

    #[DataProvider('corpusClasses')]
    public function testCorpusMessageGetsItsClass(string $fixture, InboundClassification $expected): void
    {
        self::assertSame($expected, self::classifier()->classify(self::parse($fixture)));
    }

    /** @return iterable<string, array{string, string, string, int, string}> */
    public static function reports(): iterable
    {
        yield 'Exchange' => ['exchange-ndr-5.1.1', 'failed', '5.1.1', 550, self::COURT];
        yield 'Postfix (folded diagnostic)' => ['postfix-5.1.1', 'failed', '5.1.1', 550, self::COURT];
        yield 'size' => ['size-552-5.3.4', 'failed', '5.3.4', 552, self::COURT];
        yield 'expired' => ['temp-4.4.7', 'failed', '4.4.7', 421, self::COURT];
        yield 'delayed' => ['delayed', 'delayed', '4.4.1', 451, self::COURT];
    }

    #[DataProvider('reports')]
    public function testDeliveryReportGivesStatusActionAndRecipient(string $fixture, string $action, string $status, int $code, string $recipient): void
    {
        $dsn = self::parse($fixture)->dsn;

        self::assertNotNull($dsn);
        self::assertTrue($dsn->standard);
        self::assertSame($action, $dsn->action);
        self::assertSame($status, $dsn->status);
        self::assertSame($code, $dsn->smtpCode);
        self::assertSame($recipient, $dsn->finalRecipient);
        self::assertStringNotContainsString("\n", (string) $dsn->diagnostic);
    }

    public function testReportQuotingOurHeadersExposesMessageIdAndReference(): void
    {
        foreach (['exchange-ndr-5.1.1', 'postfix-5.1.1'] as $fixture) {
            $parsed = self::parse($fixture);
            self::assertSame(self::MSGID, $parsed->embeddedMessageId, $fixture);
            self::assertSame(self::REF, $parsed->embeddedRef, $fixture);
            self::assertContains('bounces+' . self::VERP . '@lexrecovery.test', $parsed->envelopeRecipients, $fixture);
        }
    }

    public function testNonStandardBounceFallsBackToTheTextAndSkipsOurOwnAddresses(): void
    {
        $dsn = self::parse('nonrfc-bounce')->dsn;

        self::assertNotNull($dsn);
        self::assertFalse($dsn->standard);
        self::assertSame('5.1.1', $dsn->status);
        self::assertSame(550, $dsn->smtpCode);
        self::assertSame(self::COURT, $dsn->finalRecipient);
    }

    public function testReadReceiptGivesTheOriginalMessageId(): void
    {
        $mdn = self::parse('mdn')->mdn;

        self::assertNotNull($mdn);
        self::assertSame(self::MSGID, $mdn->originalMessageId);
        self::assertSame(self::COURT, $mdn->finalRecipient);
        self::assertStringContainsString('displayed', (string) $mdn->disposition);
    }

    public function testHtmlIsReducedToTextWithoutScripts(): void
    {
        $html = "From: a@instanta-test.example.test\nTo: depuneri@lexrecovery.test\nSubject: x\nContent-Type: text/html; charset=utf-8\n\n<p>Textul <b>cererii</b></p><script>alert(1)</script><style>p{}</style>";
        $text = (new InboundMailParser())->parse($html)->text;

        self::assertSame('Textul cererii', $text);
    }

    public function testOurOwnForwardIsNeverAReply(): void
    {
        $raw = strtr((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/inbound/human-reply.eml'), ['{{MSGID}}' => self::MSGID]);
        $raw = "X-LexRecovery-Forward: 42\n" . $raw;

        self::assertSame(InboundClassification::AUTO_REPLY_OTHER, self::classifier()->classify((new InboundMailParser())->parse($raw)));
    }

    public function testGeneratedRegistryAnswerWithAGeneralPhraseIsAReceipt(): void
    {
        $raw = "From: registratura@instanta-test.example.test\nTo: depuneri@lexrecovery.test\nSubject: Cerere de ordonanta de plata\nAuto-Submitted: auto-generated\nContent-Type: text/plain; charset=utf-8\n\nMesajul dumneavoastră a fost primit de registratura instanței.";

        self::assertSame(InboundClassification::RECEIPT, self::classifier()->classify((new InboundMailParser())->parse($raw)));
    }

    public function testOutOfOfficeWithAnExplicitConfirmationIsAReceipt(): void
    {
        $raw = "From: registratura@instanta-test.example.test\nTo: depuneri@lexrecovery.test\nSubject: Răspuns automat: Cerere de ordonanta de plata\nAuto-Submitted: auto-replied\nContent-Type: text/plain; charset=utf-8\n\nConfirmăm primirea mesajului. Registratura este închisă până luni.";

        self::assertSame(InboundClassification::RECEIPT, self::classifier()->classify((new InboundMailParser())->parse($raw)));
    }

    public function testUnthreadedPersonalMessageIsUnknown(): void
    {
        $raw = "From: cineva@instanta-test.example.test\nTo: depuneri@lexrecovery.test\nSubject: Intrebare\nContent-Type: text/plain\n\nBuna ziua.";

        self::assertSame(InboundClassification::UNKNOWN, self::classifier()->classify((new InboundMailParser())->parse($raw)));
    }

    public function testFullMailboxReportedAsFailedIsPermanent(): void
    {
        $dsn = new DsnResult('failed', '5.2.2', 552, self::COURT, '552 5.2.2 Mailbox full', true);

        self::assertSame(InboundClassification::BOUNCE_PERMANENT, self::classifier()->bounceClass($dsn), 'The remote server gave up: nothing retries it.');
    }

    public function testAuthenticationTrustsOnlyOurTopmostHeaderWithDmarcPassForJustRo(): void
    {
        $verifier = new InboundAuthVerifier('mx.lexrecovery.test');

        self::assertSame(InboundAuthVerdict::TRUSTED_PASS, $verifier->verify(self::parse('justro-receipt')));
        self::assertSame(InboundAuthVerdict::FAIL, $verifier->verify(self::parse('receipt-forged-auth')), 'A foreign authserv-id on top, ours only below.');
        self::assertSame(InboundAuthVerdict::FAIL, $verifier->verify(self::parse('spam')), 'Ours, but DMARC failed and the domain is not just.ro.');
        self::assertSame(InboundAuthVerdict::ABSENT, $verifier->verify(self::parse('delayed')));
    }

    public function testDmarcPassForAnotherDomainIsNotTrusted(): void
    {
        $raw = "Authentication-Results: mx.lexrecovery.test; dmarc=pass header.from=notjust.ro\nFrom: a@notjust.ro\nTo: depuneri@lexrecovery.test\nSubject: x\n\nx";

        self::assertSame(InboundAuthVerdict::FAIL, (new InboundAuthVerifier('mx.lexrecovery.test'))->verify((new InboundMailParser())->parse($raw)));
    }

    public function testNoConfiguredAuthservIdTrustsNothing(): void
    {
        self::assertSame(InboundAuthVerdict::FAIL, (new InboundAuthVerifier(''))->verify(self::parse('justro-receipt')));
    }
}
