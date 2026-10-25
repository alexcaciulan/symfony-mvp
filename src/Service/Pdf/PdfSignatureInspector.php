<?php

declare(strict_types=1);

namespace App\Service\Pdf;

/**
 * Detects the signatures of a PDF and checks that the signed bytes were not
 * changed: the /ByteRange structure, the CMS digest over it, and that nothing
 * but validation data was appended after the last signature. The certificate is
 * read only for its common names and its validity dates, both reported as
 * warnings. No chain, no trust list, no revocation, no network: the platform
 * reports a detected signature, never a valid qualified one.
 *
 * Signatures are found through the form fields, then through any object holding
 * a /ByteRange and /Contents (including objects in object streams), then by a
 * raw byte scan. A document timestamp is never counted as the signer's
 * signature. The appended updates after a signature are compared structurally
 * (PdfRevisionDiff) and also scanned as bytes when uncompressed.
 *
 * A CMS that does not carry the signer's certificate (kept only in the /DSS) is
 * reported as damaged, since the digest cannot be checked without it; signing
 * tools embed the certificate, so this is rare.
 */
final class PdfSignatureInspector
{
    private const SUPPORTED_SUB_FILTERS = ['adbe.pkcs7.detached', 'ETSI.CAdES.detached', self::LEGACY_SUB_FILTER];
    /** Outdated: the CMS encapsulates the SHA-1 digest of the signed bytes. Accepted with a warning. */
    private const LEGACY_SUB_FILTER = 'adbe.pkcs7.sha1';
    private const TIMESTAMP_SUB_FILTER = 'ETSI.RFC3161';

    /** Anything in an unsigned tail that could change what a page shows or does. */
    private const FORBIDDEN_IN_DSS_TAIL = '#/Type\s*/Page(?![A-Za-z])|/Annot(?![A-Za-z])|/Contents(?![A-Za-z])|/JavaScript|/JS(?![A-Za-z])|/OpenAction|/AA(?![A-Za-z])#';
    /** A timestamp adds its own (signature) widget; any other annotation or field is refused. */
    private const FORBIDDEN_IN_TIMESTAMP_TAIL = '#/JavaScript|/JS(?![A-Za-z])|/OpenAction|/AA(?![A-Za-z])|/Subtype\s*/(Text|FreeText|Line|Square|Circle|Polygon|PolyLine|Highlight|Underline|Squiggly|StrikeOut|Stamp|Caret|Ink|Popup|FileAttachment|Sound|Movie|Screen|Watermark|Redact|Link|3D)(?![A-Za-z])|/FT\s*/(Tx|Btn|Ch)(?![A-Za-z])#';

    public function __construct(
        private readonly ProcessSandbox $sandbox,
        private readonly QpdfRunner $qpdf,
    ) {}

    public function inspect(string $path): SignatureInspection
    {
        $bytes = (string) file_get_contents($path);
        $size = strlen($bytes);
        $dump = $this->qpdf->dump($path);
        $signatures = $this->findSignatures($dump, $bytes);
        if ($signatures === []) {
            return new SignatureInspection(SignatureInspection::NONE);
        }

        foreach (array_keys($signatures) as $i) {
            if (!$this->structureIntact($signatures[$i], $bytes, $size)) {
                return $this->damaged(count($signatures));
            }
        }

        usort($signatures, static fn (array $a, array $b): int => $a['end'] <=> $b['end']);
        $last = $signatures[array_key_last($signatures)];
        $content = $last;
        $warnings = [];

        if ($last['type'] === 'DocTimeStamp') {
            $contentSignatures = array_values(array_filter($signatures, static fn (array $s): bool => $s['type'] !== 'DocTimeStamp'));
            if ($contentSignatures === [] || $last['subFilter'] !== self::TIMESTAMP_SUB_FILTER) {
                return new SignatureInspection(SignatureInspection::UNSUPPORTED, count($signatures), $last['subFilter']);
            }
            if (!$this->timestampImprintMatches($last, $bytes)) {
                return $this->damaged(count($signatures));
            }
            // Validation data for the timestamp itself (PAdES LTV) may follow it.
            if ($last['end'] < $size && !$this->tailAcceptable($dump, $bytes, $last['end'], self::FORBIDDEN_IN_DSS_TAIL, requireDss: true, allowSignatureFields: false)) {
                return $this->damaged(count($signatures));
            }
            $content = $contentSignatures[array_key_last($contentSignatures)];
            if ($content['end'] < $size && !$this->tailAcceptable($dump, $bytes, $content['end'], self::FORBIDDEN_IN_TIMESTAMP_TAIL, requireDss: false, allowSignatureFields: true)) {
                return $this->damaged(count($signatures));
            }
            $warnings[] = 'filing.signature.warning.timestamp_appended';
            if ($last['end'] < $size) {
                $warnings[] = 'filing.signature.warning.trailing_update';
            }
        } elseif ($last['end'] < $size) {
            if (!$this->tailAcceptable($dump, $bytes, $last['end'], self::FORBIDDEN_IN_DSS_TAIL, requireDss: true, allowSignatureFields: false)) {
                return $this->damaged(count($signatures));
            }
            $warnings[] = 'filing.signature.warning.trailing_update';
        }

        if (!in_array($content['subFilter'], self::SUPPORTED_SUB_FILTERS, true)) {
            return new SignatureInspection(SignatureInspection::UNSUPPORTED, count($signatures), $content['subFilter']);
        }

        $certificate = null;
        foreach ($signatures as $signature) {
            if ($signature['type'] === 'DocTimeStamp' || !in_array($signature['subFilter'], self::SUPPORTED_SUB_FILTERS, true)) {
                continue;
            }
            $signerPem = $this->verifyDigest($signature, $bytes, $signature['subFilter'] === self::LEGACY_SUB_FILTER);
            if ($signerPem === null) {
                return $this->damaged(count($signatures));
            }
            if ($signature['range'] === $content['range']) {
                $certificate = $signerPem;
            }
        }

        if ($content['subFilter'] === self::LEGACY_SUB_FILTER) {
            $warnings[] = 'filing.signature.warning.legacy_sub_filter';
        }

        $cert = $certificate !== null ? openssl_x509_parse($certificate) : false;
        $notBefore = is_array($cert) && isset($cert['validFrom_time_t']) ? (new \DateTimeImmutable())->setTimestamp((int) $cert['validFrom_time_t']) : null;
        $notAfter = is_array($cert) && isset($cert['validTo_time_t']) ? (new \DateTimeImmutable())->setTimestamp((int) $cert['validTo_time_t']) : null;

        $signingTime = CmsReader::signingTime($content['cms']);
        $source = $signingTime !== null ? 'cms' : null;
        if ($signingTime === null && $content['m'] !== null) {
            $signingTime = self::pdfDate($content['m']);
            $source = $signingTime !== null ? 'M' : null;
        }
        if ($signingTime !== null && $notBefore !== null && $notAfter !== null && ($signingTime < $notBefore || $signingTime > $notAfter)) {
            $warnings[] = 'filing.signature.warning.cert_not_valid_at_indicated_date';
        }

        return new SignatureInspection(
            SignatureInspection::INTACT,
            count($signatures),
            $content['subFilter'],
            $last['end'],
            is_array($cert) ? self::commonName($cert['subject'] ?? []) : null,
            is_array($cert) ? self::commonName($cert['issuer'] ?? []) : null,
            $notBefore,
            $notAfter,
            $signingTime,
            $source,
            $warnings,
        );
    }

    /**
     * Signature dictionaries from the form fields (referenced or inline), then from
     * any object holding a /ByteRange and /Contents, which covers a signature not
     * linked into the form even inside an object stream; a raw byte scan is the
     * last resort for a file whose objects qpdf could not tie together.
     *
     * @param array<string, mixed> $dump
     *
     * @return list<array{range: list<int>, end: int, subFilter: ?string, type: string, m: ?string, contentsHex: ?string, cms: string}>
     */
    private function findSignatures(array $dump, string $bytes): array
    {
        $found = [];
        $objects = is_array($dump['qpdf'][1] ?? null) ? $dump['qpdf'][1] : [];
        foreach ($dump['acroform']['fields'] ?? [] as $field) {
            if (($field['fieldtype'] ?? null) !== '/Sig') {
                continue;
            }
            $value = $field['value'] ?? null;
            $dict = is_string($value) && preg_match('/^\d+ \d+ R$/', $value) === 1 ? ($objects['obj:' . $value]['value'] ?? null) : $value;
            if (is_array($dict) && is_array($dict['/ByteRange'] ?? null)) {
                $found[] = self::signatureFromDictionary($dict);
            }
        }

        if ($found === []) {
            foreach ($objects as $entry) {
                $dict = is_array($entry) ? ($entry['value'] ?? null) : null;
                if (is_array($dict) && is_array($dict['/ByteRange'] ?? null) && array_key_exists('/Contents', $dict)) {
                    $found[] = self::signatureFromDictionary($dict);
                }
            }
        }

        if ($found === []) {
            preg_match_all('#/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]#', $bytes, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $offset = $match[0][1];
                $start = strrpos(substr($bytes, 0, $offset), ' obj');
                $end = strpos($bytes, 'endobj', $offset);
                $object = substr($bytes, $start === false ? $offset : $start, ($end === false ? strlen($bytes) : $end) - ($start === false ? $offset : $start));
                $found[] = self::signature(
                    [(int) $match[1][0], (int) $match[2][0], (int) $match[3][0], (int) $match[4][0]],
                    preg_match('#/SubFilter\s*/([A-Za-z0-9.]+)#', $object, $m) === 1 ? $m[1] : null,
                    preg_match('#/Type\s*/(Sig|DocTimeStamp)(?![A-Za-z])#', $object, $m) === 1 ? $m[1] : 'Sig',
                    preg_match('#/M\s*\((D:[^)]*)\)#', $object, $m) === 1 ? $m[1] : null,
                    null,
                );
            }
        }

        $unique = [];
        foreach ($found as $signature) {
            $unique[implode(',', $signature['range'])] = $signature;
        }

        return array_values($unique);
    }

    /** @param array<string, mixed> $dict */
    private static function signatureFromDictionary(array $dict): array
    {
        return self::signature(
            $dict['/ByteRange'],
            self::nameValue($dict['/SubFilter'] ?? null),
            self::nameValue($dict['/Type'] ?? null) ?? 'Sig',
            self::stringValue($dict['/M'] ?? null),
            is_string($dict['/Contents'] ?? null) && str_starts_with($dict['/Contents'], 'b:') ? substr($dict['/Contents'], 2) : null,
        );
    }

    /**
     * @param list<mixed> $range
     *
     * @return array{range: list<int>, end: int, subFilter: ?string, type: string, m: ?string, contentsHex: ?string, cms: string}
     */
    private static function signature(array $range, ?string $subFilter, string $type, ?string $m, ?string $contentsHex): array
    {
        $range = array_map(static fn (mixed $v): int => is_int($v) ? $v : -1, array_values($range));

        return [
            'range' => $range,
            'end' => count($range) === 4 ? $range[2] + $range[3] : -1,
            'subFilter' => $subFilter,
            'type' => $type,
            'm' => $m,
            'contentsHex' => $contentsHex,
            'cms' => '',
        ];
    }

    /**
     * a = 0, the hole between the two ranges is exactly the /Contents hex string,
     * and the ranges stay inside the file. Fills in the decoded CMS on success.
     *
     * @param array{range: list<int>, end: int, subFilter: ?string, type: string, m: ?string, contentsHex: ?string, cms: string} $signature
     */
    private function structureIntact(array &$signature, string $bytes, int $size): bool
    {
        if (count($signature['range']) !== 4) {
            return false;
        }
        [$a, $b, $c, $d] = $signature['range'];
        if ($a !== 0 || $b <= 0 || $c <= $b + 1 || $d < 0 || $c + $d > $size) {
            return false;
        }

        $hole = substr($bytes, $b, $c - $b);
        if (preg_match('/^<([0-9A-Fa-f\s]*)>$/', $hole, $m) !== 1) {
            return false;
        }
        $hex = strtolower((string) preg_replace('/\s+/', '', $m[1]));
        if ($signature['contentsHex'] !== null && strtolower($signature['contentsHex']) !== $hex) {
            return false;
        }

        $cms = CmsReader::trimDer((string) hex2bin(strlen($hex) % 2 === 0 ? $hex : $hex . '0'));
        if ($cms === null) {
            return false;
        }
        $signature['cms'] = $cms;

        return true;
    }

    /**
     * Digest and signature over the ByteRange, without the chain. Returns the
     * signer's certificate (PEM) when the signed bytes are unchanged. A legacy
     * adbe.pkcs7.sha1 signature signs its own content, the SHA-1 of the bytes.
     *
     * @param array{range: list<int>, cms: string} $signature
     */
    private function verifyDigest(array $signature, string $bytes, bool $legacySha1): ?string
    {
        if ($legacySha1) {
            return $this->verifyLegacySha1($signature, $bytes);
        }

        [, $b, $c, $d] = $signature['range'];
        $data = $this->sandbox->tempPath('bin');
        $sig = $this->sandbox->tempPath('der');
        $signers = $this->sandbox->tempPath('pem');
        file_put_contents($data, substr($bytes, 0, $b) . substr($bytes, $c, $d));
        file_put_contents($sig, $signature['cms']);

        try {
            $ok = openssl_cms_verify($data, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED, $signers, [], null, null, null, $sig, OPENSSL_ENCODING_DER);
        } finally {
            while (openssl_error_string() !== false) {
                // Drain OpenSSL's error queue so a refusal does not leak into later calls.
            }
            self::removeFiles($data, $sig);
        }

        $pem = $ok && is_file($signers) ? (string) file_get_contents($signers) : null;
        self::removeFiles($signers);

        return $ok ? ($pem ?? '') : null;
    }

    /** @param array{range: list<int>, cms: string} $signature */
    private function verifyLegacySha1(array $signature, string $bytes): ?string
    {
        [, $b, $c, $d] = $signature['range'];
        $sig = $this->sandbox->tempPath('der');
        $content = $this->sandbox->tempPath('bin');
        $signers = $this->sandbox->tempPath('pem');
        file_put_contents($sig, $signature['cms']);
        try {
            $ok = openssl_cms_verify($sig, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY, $signers, [], null, $content, null, null, OPENSSL_ENCODING_DER);
            $digest = $ok && is_file($content) ? (string) file_get_contents($content) : '';
            $pem = $ok && is_file($signers) ? (string) file_get_contents($signers) : '';
        } finally {
            while (openssl_error_string() !== false) {
                // Drain the error queue.
            }
            self::removeFiles($sig, $content, $signers);
        }

        return $ok && $digest !== '' && hash_equals($digest, hash('sha1', substr($bytes, 0, $b) . substr($bytes, $c, $d), true)) ? $pem : null;
    }

    /**
     * An RFC 3161 document timestamp signs a hash of its ByteRange inside its own
     * content; the token's signature and that hash together protect the file.
     *
     * @param array{range: list<int>, cms: string} $signature
     */
    private function timestampImprintMatches(array $signature, string $bytes): bool
    {
        $token = $this->sandbox->tempPath('der');
        $content = $this->sandbox->tempPath('der');
        file_put_contents($token, $signature['cms']);
        try {
            $ok = openssl_cms_verify($token, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY, null, [], null, $content, null, null, OPENSSL_ENCODING_DER);
            $tstInfo = $ok && is_file($content) ? (string) file_get_contents($content) : '';
        } finally {
            while (openssl_error_string() !== false) {
                // Drain the error queue.
            }
            self::removeFiles($token, $content);
        }
        if ($tstInfo === '') {
            return false;
        }

        $imprint = CmsReader::messageImprint($tstInfo);
        if ($imprint === null) {
            return false;
        }
        [, $b, $c, $d] = $signature['range'];

        return hash_equals($imprint['hash'], hash($imprint['algorithm'], substr($bytes, 0, $b) . substr($bytes, $c, $d), true));
    }

    /**
     * Bytes after a signature are accepted only when they add validation data and
     * leave the pages, the form and the catalog as they were signed. The byte scan
     * catches an uncompressed update quickly; the structural comparison also sees
     * objects compressed in object streams.
     *
     * @param array<string, mixed> $dump the whole file
     */
    private function tailAcceptable(array $dump, string $bytes, int $signedEnd, string $forbidden, bool $requireDss, bool $allowSignatureFields): bool
    {
        if (preg_match($forbidden, substr($bytes, $signedEnd)) === 1) {
            return false;
        }

        $revision = $this->sandbox->tempPath();
        file_put_contents($revision, substr($bytes, 0, $signedEnd));
        try {
            return PdfRevisionDiff::onlyValidationDataAdded($this->qpdf->dump($revision), $dump, $requireDss, $allowSignatureFields);
        } catch (PdfToolException) {
            return false;
        } finally {
            self::removeFiles($revision);
        }
    }

    private static function removeFiles(string ...$paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function damaged(int $count): SignatureInspection
    {
        return new SignatureInspection(SignatureInspection::DAMAGED, $count);
    }

    /** D:YYYYMMDDHHmmSSOHH'mm' */
    private static function pdfDate(string $value): ?\DateTimeImmutable
    {
        if (preg_match("/^D:(\\d{4})(\\d{2})?(\\d{2})?(\\d{2})?(\\d{2})?(\\d{2})?([Zz+\\-])?(\\d{2})?'?(\\d{2})?/", $value, $m) !== 1) {
            return null;
        }
        $offset = '+00:00';
        if (isset($m[7]) && in_array($m[7], ['+', '-'], true)) {
            $offset = $m[7] . ($m[8] ?? '00') . ':' . ($m[9] ?? '00');
        }
        $parsed = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s P',
            sprintf('%s-%s-%s %s:%s:%s %s', $m[1], ($m[2] ?? '') ?: '01', ($m[3] ?? '') ?: '01', ($m[4] ?? '') ?: '00', ($m[5] ?? '') ?: '00', ($m[6] ?? '') ?: '00', $offset),
        );

        return $parsed === false ? null : $parsed;
    }

    /** @param array<string, mixed> $dn */
    private static function commonName(array $dn): ?string
    {
        $cn = $dn['CN'] ?? null;
        if (is_array($cn)) {
            $cn = end($cn);
        }

        return is_string($cn) && $cn !== '' ? $cn : null;
    }

    private static function nameValue(mixed $value): ?string
    {
        return is_string($value) && str_starts_with($value, '/') ? substr($value, 1) : null;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) ? (string) preg_replace('/^[ub]:/', '', $value) : null;
    }
}
