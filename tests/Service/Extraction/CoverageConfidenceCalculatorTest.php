<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\Service\Extraction\CoverageConfidenceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the coverage-weighted confidence formula. The calculator
 * sits at the center of the extraction cascade — a regression here changes
 * which strategy wins for a given document, which translates directly into
 * how much manual work the lawyer-user has to do. Pin the contract explicitly
 * so a silent regression (e.g. denominator drift) fails the build.
 */
final class CoverageConfidenceCalculatorTest extends TestCase
{
    public function testAllBucketsNullReturnsZero(): void
    {
        $this->assertSame(0.0, CoverageConfidenceCalculator::compute(null, null, null));
    }

    public function testEmptyBucketsReturnZero(): void
    {
        $this->assertSame(0.0, CoverageConfidenceCalculator::compute([], [], []));
    }

    public function testSingleBucketSumsAcrossExpectedTotal(): void
    {
        // 5 fields at 1.0 in creditor only — denominator is 25, so 5/25 = 0.2.
        $score = CoverageConfidenceCalculator::compute(
            ['name' => 1.0, 'cui' => 1.0, 'iban' => 1.0, 'address' => 1.0, 'email' => 1.0],
            null,
            null,
        );

        $this->assertSame(0.2, $score);
    }

    public function testFullCoverageAtPerfectConfidenceReachesOne(): void
    {
        // 25 fields at 1.0 — exactly EXPECTED_TOTAL_FIELDS — must produce 1.0.
        $creditor = array_fill_keys(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'], 1.0);
        $debtor = array_fill_keys(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'], 1.0);
        $claim = array_fill_keys(['a', 'b', 'c', 'd', 'e'], 1.0);

        $this->assertSame(1.0, CoverageConfidenceCalculator::compute($creditor, $debtor, $claim));
    }

    public function testOverCountedFieldsClampToOne(): void
    {
        // 30 fields at 1.0 (5 above expected total) — malformed AI output that
        // would otherwise yield 30/25 = 1.2. The clamp protects threshold
        // comparisons downstream which assume [0, 1].
        $creditor = array_fill_keys(range(1, 15), 1.0);
        $debtor = array_fill_keys(range(1, 15), 1.0);

        $this->assertSame(1.0, CoverageConfidenceCalculator::compute($creditor, $debtor, null));
    }

    public function testNegativeAndZeroValuesAreFilteredOut(): void
    {
        // Per-field confidence is supposed to live in [0, 1]. Defensive: if AI
        // emits -0.3 or 0.0 (no signal), it must NOT subtract from the sum.
        $creditor = ['name' => 1.0, 'cui' => -0.3, 'email' => 0.0];

        // Only the 1.0 contributes → 1/25 = 0.04.
        $this->assertSame(0.04, CoverageConfidenceCalculator::compute($creditor, null, null));
    }

    public function testNonNumericValuesAreIgnored(): void
    {
        // Hardening against unexpected AI payloads — strings, nulls per field,
        // arrays — must not raise a TypeError or skew the sum.
        $creditor = [
            'name' => 0.9,
            'cui' => 'oops',
            'address' => null,
            'iban' => ['nested' => 0.5],
        ];

        // 0.9 / 25 has IEEE 754 representational error, so compare with delta.
        $this->assertEqualsWithDelta(0.036, CoverageConfidenceCalculator::compute($creditor, null, null), 0.0001);
    }

    public function testMixedBucketsSumCorrectly(): void
    {
        // Realistic AI output: ~10 fields populated unevenly across buckets.
        $creditor = ['name' => 0.95, 'cui' => 0.99, 'iban' => 0.92];
        $debtor = ['name' => 0.90, 'cui' => 0.97];
        $claim = ['amount' => 0.99, 'currency' => 1.0, 'dueDate' => 0.85];

        // Sum = 0.95 + 0.99 + 0.92 + 0.90 + 0.97 + 0.99 + 1.0 + 0.85 = 7.57
        // 7.57 / 25 = 0.3028.
        $score = CoverageConfidenceCalculator::compute($creditor, $debtor, $claim);

        $this->assertEqualsWithDelta(0.3028, $score, 0.0001);
    }

    public function testExpectedTotalConstantsAreInSync(): void
    {
        // Guardrail against drift between the per-bucket constants and the
        // total — if someone bumps EXPECTED_CREDITOR_FIELDS to 11, the total
        // must follow. Catches "I only updated half the constants" regressions.
        $this->assertSame(
            CoverageConfidenceCalculator::EXPECTED_CREDITOR_FIELDS
            + CoverageConfidenceCalculator::EXPECTED_DEBTOR_FIELDS
            + CoverageConfidenceCalculator::EXPECTED_CLAIM_FIELDS,
            CoverageConfidenceCalculator::EXPECTED_TOTAL_FIELDS,
        );
    }
}
