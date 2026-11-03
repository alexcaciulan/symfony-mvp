<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\Service\Case\DocumentReferenceNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentReferenceNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function spellingsOfOneReference(): iterable
    {
        yield 'leading zeros and spaces' => ['FF 0012/2025', 'FF12/2025'];
        yield 'nr. prefix' => ['Contract nr. 12/2024', 'Contract 12/2024'];
        yield 'diacritics lost on reading' => [
            'vânzarea produsului grâu boabe, origine România, recolta 2025',
            'vanzarea produsului grau boabe, origine Romania, recolta 2025',
        ];
        yield 'cedilla versus comma below' => ['Contract de prestări servicii şi livrări', 'Contract de prestări servicii și livrări'];
    }

    #[DataProvider('spellingsOfOneReference')]
    public function testTwoSpellingsOfOneReferenceNormalizeToTheSameKey(string $first, string $second): void
    {
        self::assertNotNull(DocumentReferenceNormalizer::normalize($first));
        self::assertSame(DocumentReferenceNormalizer::normalize($first), DocumentReferenceNormalizer::normalize($second));
    }

    public function testTheSeparatorBetweenNumberAndYearIsKept(): void
    {
        self::assertNotSame(DocumentReferenceNormalizer::normalize('12/2024'), DocumentReferenceNormalizer::normalize('122024'));
    }

    public function testNothingIdentifyingLeftIsNull(): void
    {
        self::assertNull(DocumentReferenceNormalizer::normalize(null));
        self::assertNull(DocumentReferenceNormalizer::normalize('  nr. '));
    }
}
