<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Service\Filing\SmtpFailureClassifier;
use App\Service\Filing\SmtpFailureKind;
use App\Tests\Support\RecordingCourtTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;

final class SmtpFailureClassifierTest extends TestCase
{
    private const COURT = 'instanta-simulata@example.test';

    /** @return iterable<string, array{0: \Throwable, 1: SmtpFailureKind, 2: bool}> */
    public static function failures(): iterable
    {
        yield '421 at RCPT' => [RecordingCourtTransport::rcptRefused(421, '4.7.0 Try again later'), SmtpFailureKind::TEMPORARY, false];
        yield '450 at RCPT' => [RecordingCourtTransport::rcptRefused(450, 'Mailbox unavailable'), SmtpFailureKind::TEMPORARY, false];
        yield '451 at RCPT' => [RecordingCourtTransport::rcptRefused(451, '4.3.0 Local error'), SmtpFailureKind::TEMPORARY, false];
        yield 'connection refused' => [RecordingCourtTransport::connectionRefused(), SmtpFailureKind::TEMPORARY, false];
        yield 'STARTTLS failure' => [RecordingCourtTransport::startTlsFailed(), SmtpFailureKind::TEMPORARY, false];
        yield 'broken while writing the body' => [RecordingCourtTransport::lostDuringData(), SmtpFailureKind::TEMPORARY, false];
        yield 'mailbox full 5.2.2' => [RecordingCourtTransport::rcptRefused(552, '5.2.2 Mailbox full'), SmtpFailureKind::TEMPORARY, false];
        yield 'timeout after the final dot' => [RecordingCourtTransport::lostAfterData(), SmtpFailureKind::UNCERTAIN, false];
        yield '550 5.1.1 at the court RCPT' => [RecordingCourtTransport::rcptRefused(550, '5.1.1 User unknown'), SmtpFailureKind::PERMANENT, true];
        yield 'bare 550 at the court RCPT' => [RecordingCourtTransport::rcptRefused(550, 'Mailbox not found'), SmtpFailureKind::PERMANENT, true];
        yield 'bare 551 at the court RCPT' => [RecordingCourtTransport::rcptRefused(551, 'User not local'), SmtpFailureKind::PERMANENT, true];
        yield 'bare 553 at the court RCPT' => [RecordingCourtTransport::rcptRefused(553, 'Mailbox name not allowed'), SmtpFailureKind::PERMANENT, true];
        yield '550 5.1.1 at the lawyer RCPT' => [RecordingCourtTransport::lawyerRcptRefused(550, '5.1.1 User unknown', self::COURT, 'avocat@example.test'), SmtpFailureKind::PERMANENT, false];
        yield '550 at MAIL FROM' => [RecordingCourtTransport::mailFromRefused(550, 'Sender rejected'), SmtpFailureKind::PERMANENT, false];
        yield '550 5.7.1 policy at RCPT' => [RecordingCourtTransport::rcptRefused(550, '5.7.1 Relaying denied'), SmtpFailureKind::PERMANENT, false];
        yield '553 5.7.1 at RCPT' => [RecordingCourtTransport::rcptRefused(553, '5.7.1 Sender address rejected'), SmtpFailureKind::PERMANENT, false];
        yield '550 5.1.8 at RCPT' => [RecordingCourtTransport::rcptRefused(550, '5.1.8 Bad sender domain'), SmtpFailureKind::PERMANENT, false];
        yield '550 5.4.1 routing at RCPT' => [RecordingCourtTransport::rcptRefused(550, '5.4.1 No route'), SmtpFailureKind::PERMANENT, false];
        yield '535 authentication' => [RecordingCourtTransport::mailFromRefused(535, '5.7.8 Authentication failed'), SmtpFailureKind::PERMANENT, false];
        yield 'unknown 554 after the dot' => [RecordingCourtTransport::endOfDataRefused(554, 'Transaction failed'), SmtpFailureKind::PERMANENT, false];
        yield '552 after the dot' => [RecordingCourtTransport::endOfDataRefused(552, 'Message too big'), SmtpFailureKind::SIZE, false];
        yield '5.3.4 after the dot' => [RecordingCourtTransport::endOfDataRefused(554, '5.3.4 Message too big for system'), SmtpFailureKind::SIZE, false];
        yield '5.2.3 after the dot' => [RecordingCourtTransport::endOfDataRefused(550, '5.2.3 Message length exceeds limit'), SmtpFailureKind::SIZE, false];
    }

    #[DataProvider('failures')]
    public function testClassifiesTheFailure(\Throwable $failure, SmtpFailureKind $kind, bool $invalidates): void
    {
        $result = (new SmtpFailureClassifier())->classify($failure, self::COURT);

        self::assertSame($kind, $result->kind);
        self::assertSame($invalidates, $result->invalidatesCourtAddress);
    }

    public function testParsesCodeEnhancedStatusAndStage(): void
    {
        $result = (new SmtpFailureClassifier())->classify(RecordingCourtTransport::rcptRefused(550, '5.1.1 User unknown'), self::COURT);

        self::assertSame(550, $result->smtpCode);
        self::assertSame('5.1.1', $result->enhancedStatus);
        self::assertSame(SmtpFailureClassifier::STAGE_RCPT, $result->stage);
    }

    public function testAnotherRecipientAtRcptNeverInvalidatesTheCourtAddress(): void
    {
        $result = (new SmtpFailureClassifier())->classify(RecordingCourtTransport::rcptRefused(550, '5.1.1 User unknown', 'other@example.test'), self::COURT);

        self::assertSame(SmtpFailureKind::PERMANENT, $result->kind);
        self::assertFalse($result->invalidatesCourtAddress);
    }

    public function testSafeMessageCarriesNoEmailAddress(): void
    {
        $failure = RecordingCourtTransport::rcptRefused(550, '5.1.1 <registratura@instanta.example.test>: Recipient address rejected: user registratura@instanta.example.test does not exist');

        $result = (new SmtpFailureClassifier())->classify($failure, self::COURT);

        self::assertStringNotContainsString('@', $result->safeMessage);
        self::assertStringContainsString('550', $result->safeMessage);
        self::assertStringContainsString('5.1.1', $result->safeMessage);
        self::assertLessThanOrEqual(500, mb_strlen($result->safeMessage));
    }

    public function testNonTransportErrorWithoutCodeIsTemporaryBeforeData(): void
    {
        // The handler treats non-transport errors as internal before classifying; the
        // classifier itself never invents a reply code for them.
        $result = (new SmtpFailureClassifier())->classify(new InvalidArgumentException('Invalid address'), self::COURT);

        self::assertNull($result->smtpCode);
        self::assertSame(SmtpFailureKind::TEMPORARY, $result->kind);
    }

    /** @return iterable<string, array{?int, string, SmtpFailureKind, bool}> */
    public static function reportStatuses(): iterable
    {
        yield '5.1.1 unknown user' => [null, '5.1.1', SmtpFailureKind::PERMANENT, true];
        yield '5.4.4 no route to the domain' => [null, '5.4.4', SmtpFailureKind::PERMANENT, true];
        yield '5.2.2 mailbox full' => [null, '5.2.2', SmtpFailureKind::TEMPORARY, false];
        yield '552 5.3.4 size' => [552, '5.3.4', SmtpFailureKind::SIZE, false];
        yield '5.7.1 policy' => [null, '5.7.1', SmtpFailureKind::PERMANENT, false];
        yield '4.4.7 expired' => [421, '4.4.7', SmtpFailureKind::TEMPORARY, false];
    }

    #[DataProvider('reportStatuses')]
    public function testClassifyStatusInvalidatesTheAddressOnlyForAnUnknownRecipient(?int $code, string $enhanced, SmtpFailureKind $kind, bool $invalidates): void
    {
        $result = (new SmtpFailureClassifier())->classifyStatus($code, $enhanced, 'dsn', true);

        self::assertSame($kind, $result->kind);
        self::assertSame($invalidates, $result->invalidatesCourtAddress);
    }

    public function testClassifyStatusNeverInvalidatesForAnotherRecipient(): void
    {
        self::assertFalse((new SmtpFailureClassifier())->classifyStatus(null, '5.1.1', 'dsn', false)->invalidatesCourtAddress);
    }
}
