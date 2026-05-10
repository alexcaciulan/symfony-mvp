<?php

namespace App\Tests\Util;

use App\Util\PiiMasker;
use PHPUnit\Framework\TestCase;

class PiiMaskerTest extends TestCase
{
    // ---------- CNP validators ----------

    public function testIsValidCnpAcceptsValidChecksum(): void
    {
        // 1980715221232 — fictive, valid against OUG 97/2005 weights:
        // sum = 255, mod 11 = 2 = check digit ✓
        $this->assertTrue(PiiMasker::isValidCnp('1980715221232'));
    }

    public function testIsValidCnpRejectsInvalidChecksum(): void
    {
        $this->assertFalse(PiiMasker::isValidCnp('1234567890123'));
    }

    public function testIsValidCnpRejectsInvalidGenderCenturyDigit(): void
    {
        // Even if the checksum mathematically matched, S=0 is structurally
        // invalid (S must be 1..9, encoding gender + century).
        $this->assertFalse(PiiMasker::isValidCnp('0980715221232'));
    }

    public function testIsValidCnpRejectsWrongLength(): void
    {
        $this->assertFalse(PiiMasker::isValidCnp('123'));
        $this->assertFalse(PiiMasker::isValidCnp('19807152212320'));
        $this->assertFalse(PiiMasker::isValidCnp(''));
    }

    // ---------- CUI validators ----------

    public function testIsValidCuiAcceptsKnownValidCuis(): void
    {
        // 15193236 — fictive but checksum-valid (sum = 104, ×10 mod 11 = 6 = check)
        $this->assertTrue(PiiMasker::isValidCui('15193236'));
        // 14186770 — Banca Transilvania, public, real (sum = 133, ×10 mod 11 = 10 → 0 = check)
        $this->assertTrue(PiiMasker::isValidCui('14186770'));
    }

    public function testIsValidCuiRejectsInvalidChecksum(): void
    {
        $this->assertFalse(PiiMasker::isValidCui('12345678'));
    }

    public function testIsValidCuiRejectsAllZeroBody(): void
    {
        // "000" / "0000" trivially satisfy the checksum (sum = 0 = check digit),
        // but they are not real CUIs. Reject explicitly.
        $this->assertFalse(PiiMasker::isValidCui('000'));
        $this->assertFalse(PiiMasker::isValidCui('0000'));
        $this->assertFalse(PiiMasker::isValidCui('000000000'));
    }

    public function testIsValidCuiRejectsLengthBelowFour(): void
    {
        // Real ANAF CUIs are 4+ digits in practice; 2-3 are pre-1990 and
        // not relevant to LexRecovery scope.
        $this->assertFalse(PiiMasker::isValidCui('12'));
        $this->assertFalse(PiiMasker::isValidCui('123'));
    }

    // ---------- maskCnp ----------

    public function testMaskCnpReplacesValidCnpKeepingLastFourDigits(): void
    {
        $masked = PiiMasker::maskCnp('Debitor CNP 1980715221232 cu adresa Bucuresti');

        $this->assertSame('Debitor CNP ***-***-1232 cu adresa Bucuresti', $masked);
    }

    public function testMaskCnpIgnoresInvalidChecksum(): void
    {
        // 1234567890123 is not a valid CNP — must be left intact.
        $input = 'Numar fictiv 1234567890123 in text';
        $this->assertSame($input, PiiMasker::maskCnp($input));
    }

    public function testMaskCnpHandlesMultipleDistinctCnps(): void
    {
        // 1980715221232 (valid, last4 = 1232) + 2900605221231 (valid CNP,
        // sum = 175, mod 11 = 10 → 1 = check; last4 = 1231).
        $masked = PiiMasker::maskCnp('Doi debitori: 1980715221232 si 2900605221231.');

        $this->assertStringContainsString('***-***-1232', $masked);
        $this->assertStringContainsString('***-***-1231', $masked);
        $this->assertStringNotContainsString('1980715221232', $masked);
        $this->assertStringNotContainsString('2900605221231', $masked);
    }

    // ---------- maskCui ----------

    public function testMaskCuiPreservesRoPrefixForVatPayer(): void
    {
        // CUI cu prefix `RO` = entitate înregistrată în scopuri TVA
        // (Codul Fiscal art. 316). Mascarea trebuie să păstreze "RO" ca să
        // semnaleze plătitor TVA în logs și AI prompts.
        $masked = PiiMasker::maskCui('Furnizor CUI RO15193236 SC Foo SRL');

        $this->assertSame('Furnizor CUI RO****** SC Foo SRL', $masked);
    }

    public function testMaskCuiOmitsRoPrefixForNonVatPayer(): void
    {
        // CUI fără `RO` = CIF (Cod de Identificare Fiscală), entitate NEÎNREGISTRATĂ
        // ca plătitor TVA. Mascarea NU trebuie să adauge "RO" — ar falsifica statutul.
        $masked = PiiMasker::maskCui('CIF 14186770 Cabinet Avocatura SRL');

        $this->assertSame('CIF ****** Cabinet Avocatura SRL', $masked);
    }

    public function testMaskCuiPreservesDistinctionInSameText(): void
    {
        // Document cu un plătitor TVA + un non-plătitor — mascarea trebuie să
        // diferențieze pentru a păstra informația în audit.
        $masked = PiiMasker::maskCui('Furnizor RO15193236 si Client 14186770.');

        $this->assertSame('Furnizor RO****** si Client ******.', $masked);
    }

    public function testMaskCuiIgnoresInvalidChecksum(): void
    {
        $input = 'Cod fictiv 12345678 pe document';
        $this->assertSame($input, PiiMasker::maskCui($input));
    }

    // ---------- round-trip ----------

    public function testBuildCnpMapAndRestoreRoundTrip(): void
    {
        $original = 'Debitor 1: CNP 1980715221232. Debitor 2: CNP 2900605221231.';

        $map = PiiMasker::buildCnpMap($original);

        $this->assertCount(2, $map);
        $this->assertArrayHasKey('1980715221232', $map);
        $this->assertArrayHasKey('2900605221231', $map);

        // Apply mascat using the placeholders from the map.
        $masked = $original;
        foreach ($map as $cnp => $placeholder) {
            $masked = str_replace($cnp, $placeholder, $masked);
        }
        $this->assertStringNotContainsString('1980715221232', $masked);

        // Restore with PiiMasker::restoreCnp — round trip lossless.
        $restored = PiiMasker::restoreCnp($masked, $map);
        $this->assertSame($original, $restored);
    }

    public function testBuildCnpMapDeduplicatesIdenticalCnps(): void
    {
        // Same CNP appearing twice → one entry in the map.
        $map = PiiMasker::buildCnpMap('1980715221232 ... 1980715221232');

        $this->assertCount(1, $map);
        $this->assertSame('***-***-1232', $map['1980715221232']);
    }

    public function testBuildCnpMapSkipsInvalidCnps(): void
    {
        // 13-digit but invalid checksum → not in map.
        $map = PiiMasker::buildCnpMap('1234567890123 invalid; 1980715221232 valid');

        $this->assertCount(1, $map);
        $this->assertArrayHasKey('1980715221232', $map);
        $this->assertArrayNotHasKey('1234567890123', $map);
    }

    public function testRestoreCnpHandlesLastFourCollisionWithoutCorruption(): void
    {
        // Two distinct, checksum-valid CNPs sharing the same last 4 digits "1232":
        //   - 1980715221232 (M, 1998-07-15, jud 22)
        //   - 2800101031232 (F, 1980-01-01, jud 03)
        // buildCnpMap suffixes the second placeholder with "#1" to disambiguate.
        // restoreCnp must recover both originals, NOT corrupt one into the other.
        $original = 'Debitor 1: 1980715221232. Debitor 2: 2800101031232.';

        $map = PiiMasker::buildCnpMap($original);

        $this->assertCount(2, $map);
        $this->assertArrayHasKey('1980715221232', $map);
        $this->assertArrayHasKey('2800101031232', $map);

        // Apply the map to mask the text.
        $masked = str_replace(array_keys($map), array_values($map), $original);
        $this->assertStringNotContainsString('1980715221232', $masked);
        $this->assertStringNotContainsString('2800101031232', $masked);

        // Restore — both originals must be back in their correct positions.
        $restored = PiiMasker::restoreCnp($masked, $map);
        $this->assertSame($original, $restored, 'Round-trip must preserve text under last-4 collision');
    }

    // ---------- maskCnpInArray ----------

    public function testMaskCnpInArrayMasksStringLeavesAtAnyDepth(): void
    {
        $payload = [
            'creditor' => [
                'name' => 'SC Foo SRL',
                'cnp' => '1980715221232',
            ],
            'debtor' => [
                'note' => 'CNP 2800101031232 in remarks',
                'amount' => 5000.0,
            ],
            'meta' => [
                'tags' => ['urgent', 'CNP 1980715221232 attached'],
            ],
        ];

        $masked = PiiMasker::maskCnpInArray($payload);

        $this->assertSame('SC Foo SRL', $masked['creditor']['name']);
        $this->assertSame('***-***-1232', $masked['creditor']['cnp']);
        $this->assertSame('CNP ***-***-1232 in remarks', $masked['debtor']['note']);
        $this->assertSame(5000.0, $masked['debtor']['amount'], 'Non-string scalars unchanged');
        $this->assertSame('CNP ***-***-1232 attached', $masked['meta']['tags'][1]);
    }

    public function testMaskCnpInArrayLeavesEmptyArrayIntact(): void
    {
        $this->assertSame([], PiiMasker::maskCnpInArray([]));
    }
}
