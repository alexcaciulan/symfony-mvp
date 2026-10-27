<?php

declare(strict_types=1);

namespace App\Service\Pdf;

/**
 * Reads the few DER fields the signature inspection needs from a CMS blob or an
 * RFC 3161 TSTInfo. Only lengths and tags are trusted, never the content: the
 * cryptographic checks stay with OpenSSL.
 */
final class CmsReader
{
    private const OID_SIGNING_TIME = "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x09\x05";
    private const HASH_OIDS = [
        '2b0e03021a' => 'sha1',
        '608648016503040201' => 'sha256',
        '608648016503040202' => 'sha384',
        '608648016503040203' => 'sha512',
    ];

    /** The DER object at the start of a zero-padded /Contents, without the padding. */
    public static function trimDer(string $der): ?string
    {
        $header = self::derHeader($der, 0);
        if ($header === null || $header['tag'] !== 0x30) {
            return null;
        }
        $total = $header['headerLength'] + $header['length'];

        return $total <= strlen($der) ? substr($der, 0, $total) : null;
    }

    /**
     * TSTInfo ::= SEQUENCE { version, policy, messageImprint SEQUENCE { AlgorithmIdentifier, OCTET STRING } ... }
     *
     * @return array{algorithm: string, hash: string}|null
     */
    public static function messageImprint(string $tstInfo): ?array
    {
        $outer = self::derHeader($tstInfo, 0);
        if ($outer === null || $outer['tag'] !== 0x30) {
            return null;
        }
        $offset = $outer['headerLength'];
        foreach ([0x02, 0x06] as $expected) {
            $h = self::derHeader($tstInfo, $offset);
            if ($h === null || $h['tag'] !== $expected) {
                return null;
            }
            $offset += $h['headerLength'] + $h['length'];
        }
        $imprint = self::derHeader($tstInfo, $offset);
        $algId = $imprint !== null && $imprint['tag'] === 0x30 ? self::derHeader($tstInfo, $offset + $imprint['headerLength']) : null;
        if ($algId === null || $algId['tag'] !== 0x30) {
            return null;
        }
        $algOffset = $offset + $imprint['headerLength'];
        $oid = self::derHeader($tstInfo, $algOffset + $algId['headerLength']);
        if ($oid === null || $oid['tag'] !== 0x06) {
            return null;
        }
        $algorithm = self::HASH_OIDS[bin2hex(substr($tstInfo, $algOffset + $algId['headerLength'] + $oid['headerLength'], $oid['length']))] ?? null;
        $hashOffset = $algOffset + $algId['headerLength'] + $algId['length'];
        $hash = self::derHeader($tstInfo, $hashOffset);
        if ($algorithm === null || $hash === null || $hash['tag'] !== 0x04) {
            return null;
        }

        return ['algorithm' => $algorithm, 'hash' => substr($tstInfo, $hashOffset + $hash['headerLength'], $hash['length'])];
    }

    /** The signingTime signed attribute, as declared by the signer's tool. */
    public static function signingTime(string $cms): ?\DateTimeImmutable
    {
        $pos = strpos($cms, self::OID_SIGNING_TIME);
        if ($pos === false) {
            return null;
        }
        $set = self::derHeader($cms, $pos + strlen(self::OID_SIGNING_TIME));
        if ($set === null || $set['tag'] !== 0x31) {
            return null;
        }
        $timeOffset = $pos + strlen(self::OID_SIGNING_TIME) + $set['headerLength'];
        $time = self::derHeader($cms, $timeOffset);
        if ($time === null) {
            return null;
        }
        $value = substr($cms, $timeOffset + $time['headerLength'], $time['length']);
        $format = match ($time['tag']) {
            0x17 => 'ymdHis',
            0x18 => 'YmdHis',
            default => null,
        };
        if ($format === null) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!' . $format, substr(rtrim($value, 'Z'), 0, $format === 'ymdHis' ? 12 : 14), new \DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed;
    }

    /** @return array{tag: int, headerLength: int, length: int}|null */
    private static function derHeader(string $der, int $offset): ?array
    {
        if ($offset + 2 > strlen($der)) {
            return null;
        }
        $tag = ord($der[$offset]);
        $first = ord($der[$offset + 1]);
        if ($first < 0x80) {
            return ['tag' => $tag, 'headerLength' => 2, 'length' => $first];
        }
        $count = $first & 0x7F;
        if ($count === 0 || $count > 4 || $offset + 2 + $count > strlen($der)) {
            return null;
        }
        $length = 0;
        for ($i = 0; $i < $count; $i++) {
            $length = ($length << 8) | ord($der[$offset + 2 + $i]);
        }

        return ['tag' => $tag, 'headerLength' => 2 + $count, 'length' => $length];
    }
}
