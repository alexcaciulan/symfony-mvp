<?php

declare(strict_types=1);

namespace App\Tests\Service\Portal;

use App\Service\Portal\PartyNameNormalizer;
use PHPUnit\Framework\TestCase;

final class PartyNameNormalizerTest extends TestCase
{
    public function testNormalizeStripsLegalFormDiacriticsAndPunctuation(): void
    {
        $this->assertSame('ACMES', PartyNameNormalizer::normalize('S.C. Acmeș S.R.L.'));
        $this->assertSame('GRADINITA VESELA', PartyNameNormalizer::normalize('Grădinița Veselă'));
        $this->assertSame('POPESCU ION', PartyNameNormalizer::normalize('  Popescu   Ion  '));
    }

    public function testMatchesIgnoresLegalFormAndDiacritics(): void
    {
        $this->assertTrue(PartyNameNormalizer::matches('SC ACME SRL', 'Acme'));
        $this->assertTrue(PartyNameNormalizer::matches('Grădinița Veselă', 'GRADINITA VESELA'));
        $this->assertTrue(PartyNameNormalizer::matches('SC ACME GRUP SRL', 'ACME GRUP'));
    }

    public function testMatchesAcceptsContainmentEitherDirection(): void
    {
        $this->assertTrue(PartyNameNormalizer::matches('ACME', 'ACME GRUP'));
        $this->assertTrue(PartyNameNormalizer::matches('ACME GRUP', 'ACME'));
    }

    public function testDoesNotMatchDifferentParties(): void
    {
        $this->assertFalse(PartyNameNormalizer::matches('Ion Popescu', 'Gheorghe Ionescu'));
        $this->assertFalse(PartyNameNormalizer::matches('SC Alpha SRL', 'SC Beta SRL'));
    }

    public function testEmptyNamesNeverMatch(): void
    {
        $this->assertFalse(PartyNameNormalizer::matches('', 'ACME'));
        $this->assertFalse(PartyNameNormalizer::matches('SRL', 'ACME'));
    }
}
