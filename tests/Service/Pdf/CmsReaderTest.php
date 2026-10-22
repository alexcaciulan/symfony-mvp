<?php

declare(strict_types=1);

namespace App\Tests\Service\Pdf;

use App\Service\Pdf\CmsReader;
use PHPUnit\Framework\TestCase;

final class CmsReaderTest extends TestCase
{
    public function testTrimDerDropsTheZeroPaddingOfContents(): void
    {
        $der = self::tlv(0x30, self::tlv(0x02, "\x01"));

        self::assertSame($der, CmsReader::trimDer($der . str_repeat("\x00", 64)));
    }

    public function testTrimDerReadsLongFormLengths(): void
    {
        $der = self::tlv(0x30, self::tlv(0x04, str_repeat('x', 300)));
        self::assertSame("\x30\x82", substr($der, 0, 2));

        self::assertSame($der, CmsReader::trimDer($der . "\x00\x00"));
    }

    public function testTrimDerRefusesATruncatedOrNonSequenceObject(): void
    {
        $der = self::tlv(0x30, self::tlv(0x04, str_repeat('x', 300)));

        self::assertNull(CmsReader::trimDer(substr($der, 0, 100)));
        self::assertNull(CmsReader::trimDer(self::tlv(0x04, 'abc')));
        self::assertNull(CmsReader::trimDer("\x00\x00\x00"));
    }

    public function testMessageImprintReadsTheHashAlgorithmAndValue(): void
    {
        $hash = hash('sha256', 'signed bytes', true);
        $tstInfo = self::tstInfo("\x60\x86\x48\x01\x65\x03\x04\x02\x01", $hash);

        self::assertSame(['algorithm' => 'sha256', 'hash' => $hash], CmsReader::messageImprint($tstInfo));
    }

    public function testMessageImprintWithAnUnknownAlgorithmIsRefused(): void
    {
        self::assertNull(CmsReader::messageImprint(self::tstInfo("\x2a\x03\x04", str_repeat("\x01", 32))));
    }

    public function testSigningTimeReadsUtcTimeAndGeneralizedTime(): void
    {
        $oid = "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x09\x05";

        self::assertEquals(
            new \DateTimeImmutable('2026-10-21 09:30:15', new \DateTimeZone('UTC')),
            CmsReader::signingTime('prefix' . $oid . self::tlv(0x31, self::tlv(0x17, '261021093015Z'))),
        );
        self::assertEquals(
            new \DateTimeImmutable('2051-01-02 03:04:05', new \DateTimeZone('UTC')),
            CmsReader::signingTime($oid . self::tlv(0x31, self::tlv(0x18, '20510102030405Z'))),
        );
        self::assertNull(CmsReader::signingTime('no signing time attribute'));
    }

    private static function tstInfo(string $hashOid, string $hash): string
    {
        $algorithm = self::tlv(0x30, self::tlv(0x06, $hashOid) . "\x05\x00");

        return self::tlv(0x30, self::tlv(0x02, "\x01") . self::tlv(0x06, "\x2a\x03") . self::tlv(0x30, $algorithm . self::tlv(0x04, $hash)));
    }

    private static function tlv(int $tag, string $value): string
    {
        $length = strlen($value);
        if ($length < 0x80) {
            return chr($tag) . chr($length) . $value;
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr($tag) . chr(0x80 | strlen($bytes)) . $bytes . $value;
    }
}
