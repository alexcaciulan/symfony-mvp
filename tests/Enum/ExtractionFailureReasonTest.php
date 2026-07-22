<?php

namespace App\Tests\Enum;

use App\Enum\ExtractionFailureReason;
use PHPUnit\Framework\TestCase;

class ExtractionFailureReasonTest extends TestCase
{
    public function testHasTwelveCases(): void
    {
        $this->assertCount(12, ExtractionFailureReason::cases());
    }

    public function testLabelsFollowTheEnumKeyConvention(): void
    {
        foreach (ExtractionFailureReason::cases() as $case) {
            $this->assertSame('enum.extraction_failure_reason.' . $case->value, $case->label());
        }
    }

    /**
     * The transient set drives both the Messenger retry and the retry button,
     * so widening it by accident costs real money in repeated model calls.
     */
    public function testOnlyProviderSideCausesAreTransient(): void
    {
        $this->assertTrue(ExtractionFailureReason::API_UNAVAILABLE->isTransient());
        $this->assertTrue(ExtractionFailureReason::RATE_LIMIT_EXCEEDED->isTransient());

        $this->assertFalse(ExtractionFailureReason::FILE_TOO_LARGE->isTransient());
        $this->assertFalse(ExtractionFailureReason::FILE_UNREADABLE->isTransient());
        $this->assertFalse(ExtractionFailureReason::RESPONSE_TRUNCATED->isTransient());
        // An answer the reader could not decode is a sampling accident rather
        // than a property of the document, so the lawyer is offered a retry.
        $this->assertTrue(ExtractionFailureReason::RESPONSE_MALFORMED->isTransient());
        $this->assertFalse(ExtractionFailureReason::UNSUPPORTED_MIME->isTransient());
        $this->assertFalse(ExtractionFailureReason::LOCAL_ONLY_MODE->isTransient());
        $this->assertFalse(ExtractionFailureReason::AGREEMENT_MISSING->isTransient());
        // A key an operator never set does not appear on its own, and a request
        // the provider refuses is refused identically on every replay.
        $this->assertFalse(ExtractionFailureReason::API_KEY_MISSING->isTransient());
        $this->assertFalse(ExtractionFailureReason::PROVIDER_REJECTED->isTransient());
        // A billing or capacity refusal clears once the account is topped up, so
        // the lawyer gets a retry rather than being told to fix their file.
        $this->assertTrue(ExtractionFailureReason::PROVIDER_UNAVAILABLE->isTransient());
    }

    /**
     * A billing or capacity refusal is the platform's problem, not the file's,
     * and the message must say so instead of blaming the document.
     */
    public function testProviderUnavailableIsOperatorSide(): void
    {
        $this->assertTrue(ExtractionFailureReason::PROVIDER_UNAVAILABLE->isOperatorSide());
        $this->assertTrue(ExtractionFailureReason::API_KEY_MISSING->isOperatorSide());
        $this->assertFalse(ExtractionFailureReason::PROVIDER_REJECTED->isOperatorSide());
        $this->assertFalse(ExtractionFailureReason::FILE_TOO_LARGE->isOperatorSide());
    }
}
