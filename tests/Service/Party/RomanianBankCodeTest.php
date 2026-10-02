<?php

declare(strict_types=1);

namespace App\Tests\Service\Party;

use App\Service\Party\RomanianBankCode;
use PHPUnit\Framework\TestCase;

final class RomanianBankCodeTest extends TestCase
{
    public function testTheBankIsReadFromTheIbanCode(): void
    {
        self::assertSame('CEC Bank', RomanianBankCode::bankName('RO24CECENT0430RON1019827'));
        self::assertSame('Banca Comercială Română', RomanianBankCode::bankName('RO31 RNCB 0199 0053 0731 0001'));
        self::assertSame('Trezoreria Statului', RomanianBankCode::bankName('RO59TREZ7015069XXX019702'));
        self::assertSame('Banca Transilvania', RomanianBankCode::bankName('ro96btrlroncrt0350262601'));
    }

    public function testAnUnknownOrMalformedIbanNamesNoBank(): void
    {
        self::assertNull(RomanianBankCode::bankName('RO24ZZZZNT0430RON1019827'));
        self::assertNull(RomanianBankCode::bankName('RO24CECE'));
        self::assertNull(RomanianBankCode::bankName('DE89370400440532013000'));
        self::assertNull(RomanianBankCode::bankName(null));
    }

    public function testABankNameIsMatchedAgainstTheAccountItBelongsTo(): void
    {
        self::assertTrue(RomanianBankCode::nameMatches('BANCA TRANSILVANIA', 'RO96BTRLRONCRT0350262601'));
        self::assertTrue(RomanianBankCode::nameMatches('BCR TG NEAMT', 'RO31RNCB0199005307310001'));
        self::assertTrue(RomanianBankCode::nameMatches('Trezoreria sectorului 1', 'RO59TREZ7015069XXX019702'));
        self::assertTrue(RomanianBankCode::nameMatches('ING BANK ROMANIA', 'RO81INGB0000999912851953'));
        self::assertFalse(RomanianBankCode::nameMatches('BANCA TRANSILVANIA', 'RO81INGB0000999912851953'));
        self::assertFalse(RomanianBankCode::nameMatches('Ingrid Bank', 'RO81INGB0000999912851953'));
        self::assertFalse(RomanianBankCode::nameMatches(null, 'RO81INGB0000999912851953'));
        self::assertTrue(RomanianBankCode::nameMatches('Orice bancă', 'RO24ZZZZNT0430RON1019827'), 'an unknown bank code proves no mismatch');
    }
}
