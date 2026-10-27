<?php

declare(strict_types=1);

namespace App\Tests\Service\Pdf;

use App\Service\Pdf\PdfTextProbe;
use PHPUnit\Framework\TestCase;

final class PdfTextProbeTest extends TestCase
{
    public function testCedillaAndCommaBelowFormsOfSAndTAreTheSame(): void
    {
        self::assertSame(
            PdfTextProbe::normalize("\u{0219}i \u{0218}I \u{021B}ar\u{0103} \u{021A}ARA"),
            PdfTextProbe::normalize("\u{015F}i \u{015E}I \u{0163}ar\u{0103} \u{0162}ARA"),
        );
    }

    public function testDecomposedDiacriticsMatchComposedOnes(): void
    {
        self::assertSame(PdfTextProbe::normalize("\u{0219}i \u{00E2}"), PdfTextProbe::normalize("s\u{0326}i a\u{0302}"));
    }

    public function testLigaturesAreSpelledOut(): void
    {
        self::assertSame('definitiv', PdfTextProbe::normalize("de\u{FB01}nitiv"));
    }

    public function testTypographicQuotesAndDashesBecomeAscii(): void
    {
        self::assertSame('"SC Alfa" \'x\' 2020-2021', PdfTextProbe::normalize("\u{201E}SC Alfa\u{201D} \u{2018}x\u{2019} 2020\u{2013}2021"));
        self::assertSame('"a" "b"', PdfTextProbe::normalize("\u{201C}a\u{201D} \u{00AB}b\u{00BB}"));
    }

    public function testTheEmDashStaysAsDocumentText(): void
    {
        self::assertSame("a \u{2014} b", PdfTextProbe::normalize("a \u{2014} b"));
    }

    public function testSoftHyphensAreRemovedAndLineEndHyphenationIsJoined(): void
    {
        self::assertSame('ordonanta', PdfTextProbe::normalize("ordo\u{00AD}nanta"));
        self::assertSame('ordonanta de plata', PdfTextProbe::normalize("ordo-\nnanta de plata"));
    }

    /** Hyphens that belong to the word stay, including a word that ends a line. */
    public function testGenuineHyphensAreKept(): void
    {
        self::assertSame('S.C.-urile B2B-ului', PdfTextProbe::normalize('S.C.-urile B2B-ului'));
        self::assertSame('S.C.- urile', PdfTextProbe::normalize("S.C.-\nurile"));
        self::assertSame('Cluj- Napoca', PdfTextProbe::normalize("Cluj-\nNapoca"));
    }

    public function testWhitespaceIncludingNoBreakSpacesCollapses(): void
    {
        self::assertSame('200,00 RON', PdfTextProbe::normalize("  200,00\u{00A0}\u{00A0}RON \n\t"));
    }

    public function testAmountsKeepTheirSeparators(): void
    {
        self::assertNotSame(PdfTextProbe::normalize('1.000'), PdfTextProbe::normalize('1000'));
        self::assertNotSame(PdfTextProbe::normalize('1.000,50'), PdfTextProbe::normalize('1000,50'));
    }
}
