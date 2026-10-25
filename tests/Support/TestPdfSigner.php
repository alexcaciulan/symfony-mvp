<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Signs a PDF the way a local signing tool does: an incremental update that adds
 * an invisible /Sig field, a /ByteRange over everything but the /Contents hole,
 * and a detached CMS signature written into the hole. Test-only: it uses the
 * committed self-signed key, never a real certificate.
 */
final class TestPdfSigner
{
    public const FIXTURE_DIR = __DIR__ . '/../fixtures/filing';
    private const CONTENTS_BYTES = 16384;
    private const BYTE_RANGE_PLACEHOLDER = '/ByteRange [0 0000000000 0000000000 0000000000]';

    /**
     * The widget sits on the first page unless page (0-based, -1 for the last) says
     * otherwise. Without visibleRect it is invisible (a zero rectangle); with it the
     * signature text is drawn in that rectangle, as a visible signature does.
     * subFilter adbe.pkcs7.sha1 signs the SHA-1 of the bytes as encapsulated
     * content, as legacy tools do.
     *
     * @param array{subFilter?: string, certificate?: string, key?: string, visibleRect?: list<int>, page?: int} $options
     */
    public static function sign(string $input, string $output, array $options = []): void
    {
        $certificate = $options['certificate'] ?? self::FIXTURE_DIR . '/test-signer.crt';
        $key = $options['key'] ?? self::FIXTURE_DIR . '/test-signer.key';
        $subFilter = $options['subFilter'] ?? 'adbe.pkcs7.detached';
        $legacy = $subFilter === 'adbe.pkcs7.sha1';

        self::addSignature($input, $output, 'Sig', $subFilter, $options['visibleRect'] ?? null, $options['page'] ?? 0, static function (string $data, string $sig) use ($certificate, $key, $legacy): void {
            $content = $data;
            if ($legacy) {
                $content = $data . '.sha1';
                file_put_contents($content, sha1_file($data, true));
            }
            $ok = openssl_cms_sign(
                $content,
                $sig,
                'file://' . realpath($certificate),
                'file://' . realpath($key),
                null,
                $legacy ? OPENSSL_CMS_BINARY : OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY,
                OPENSSL_ENCODING_DER,
            );
            if ($legacy) {
                @unlink($content);
            }
            if (!$ok) {
                throw new \RuntimeException('openssl_cms_sign failed: ' . openssl_error_string());
            }
        });
    }

    /** An RFC 3161 document timestamp from the committed test TSA, as LTV tools add one. */
    public static function timestamp(string $input, string $output): void
    {
        self::addSignature($input, $output, 'DocTimeStamp', 'ETSI.RFC3161', null, 0, static function (string $data, string $sig): void {
            $dir = realpath(self::FIXTURE_DIR);
            $query = $sig . '.tsq';
            $reply = $sig . '.tsr';
            $serial = $sig . '.serial';
            file_put_contents($serial, "01\n");
            $config = $sig . '.cnf';
            file_put_contents($config, "[tsa]\ndefault_tsa=t\n[t]\nserial={$serial}\nsigner_digest=sha256\ndefault_policy=1.2.3.4.1\ndigests=sha256\ness_cert_id_alg=sha256\n");
            (new Process(['openssl', 'ts', '-query', '-data', $data, '-sha256', '-cert', '-no_nonce', '-out', $query]))->mustRun();
            (new Process(['openssl', 'ts', '-reply', '-queryfile', $query, '-signer', $dir . '/test-tsa.crt', '-inkey', $dir . '/test-tsa.key', '-config', $config, '-out', $reply]))->mustRun();
            (new Process(['openssl', 'ts', '-reply', '-in', $reply, '-token_out', '-out', $sig]))->mustRun();
            foreach ([$query, $reply, $serial, $config] as $file) {
                @unlink($file);
            }
        });
    }

    /**
     * Appends a page with one line of text in an incremental update, as a tool
     * that adds a signature page before signing does. The earlier bytes stay a prefix.
     */
    public static function appendPage(string $input, string $output, string $text): void
    {
        $max = self::maxObjectId($input);
        $rootRef = (string) self::jsonObject($input, 'trailer')->{'/Root'};
        $pagesRef = (string) self::jsonObject($input, $rootRef)->{'/Pages'};
        $pages = self::jsonObject($input, $pagesRef);
        $pageRef = ($max + 2) . ' 0 R';
        $pages->{'/Kids'} = [...$pages->{'/Kids'}, $pageRef];
        $pages->{'/Count'} = (int) $pages->{'/Count'} + 1;
        $content = sprintf('BT /F1 12 Tf 72 760 Td (%s) Tj ET', $text);

        file_put_contents($output, self::appendUpdate((string) file_get_contents($input), [
            self::objectNumber($pagesRef) => self::serialize($pages),
            $max + 1 => sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($content), $content),
            $max + 2 => sprintf('<< /Type /Page /Parent %s /MediaBox [0 0 595 842] /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> /Contents %d 0 R >>', $pagesRef, $max + 1),
        ], $max + 3));
    }

    /**
     * Appends a free text annotation, with an appearance that draws the text, to
     * the first page: a comment a reader adds before signing.
     *
     * @param list<int> $rect
     */
    public static function addFreeText(string $input, string $output, string $text, array $rect): void
    {
        $max = self::maxObjectId($input);
        $pageRef = self::pageRef($input, 0);
        $page = self::jsonObject($input, $pageRef);
        [$x1, $y1, $x2, $y2] = $rect;
        $content = sprintf('BT /F1 10 Tf 2 4 Td (%s) Tj ET', $text);
        $annotRef = ($max + 2) . ' 0 R';
        $objects = [
            $max + 1 => sprintf("<< /Type /XObject /Subtype /Form /BBox [0 0 %d %d] /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> /Length %d >>\nstream\n%s\nendstream", $x2 - $x1, $y2 - $y1, strlen($content), $content),
            $max + 2 => sprintf('<< /Type /Annot /Subtype /FreeText /Rect [%d %d %d %d] /Contents (%s) /DA (/Helv 10 Tf 0 g) /AP << /N %d 0 R >> /P %s >>', $x1, $y1, $x2, $y2, $text, $max + 1, $pageRef),
        ];
        $annots = $page->{'/Annots'} ?? [];
        if (is_string($annots)) {
            $objects[self::objectNumber($annots)] = self::serialize([...self::jsonObject($input, $annots), $annotRef]);
        } else {
            $page->{'/Annots'} = [...$annots, $annotRef];
            $objects[self::objectNumber($pageRef)] = self::serialize($page);
        }

        file_put_contents($output, self::appendUpdate((string) file_get_contents($input), $objects, $max + 3));
    }

    /**
     * Appends a signature dictionary compressed in an object stream, linked to no
     * field. It can never be a valid signature (the /Contents hole is not in the
     * file's bytes), but it has to be found rather than read as "no signature".
     */
    public static function appendSignatureInObjectStream(string $input, string $output): void
    {
        $max = self::maxObjectId($input);
        file_put_contents($output, self::appendCompressedUpdate((string) file_get_contents($input), [
            $max + 1 => sprintf('<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached /ByteRange [0 100 200 50] /Contents <%s> >>', str_repeat('00', 64)),
        ]));
    }

    /**
     * Appends validation data in an update whose objects are all compressed in a
     * Flate object stream, as tools writing PDF 1.5+ updates do. $extraObjects use
     * "{1}" for the first object number after the file's last one; $annotation, when
     * given, is added to the first page in the same update.
     */
    public static function appendCompressedDss(string $input, string $output, ?string $annotation = null): void
    {
        $max = self::maxObjectId($input);
        $rootRef = (string) self::jsonObject($input, 'trailer')->{'/Root'};
        $root = self::jsonObject($input, $rootRef);
        $root->{'/DSS'} = ($max + 1) . ' 0 R';
        $objects = [
            self::objectNumber($rootRef) => self::serialize($root),
            $max + 1 => '<< /Type /DSS /Certs [] /OCSPs [] /CRLs [] >>',
        ];
        if ($annotation !== null) {
            $pageRef = self::pageRef($input, 0);
            $page = self::jsonObject($input, $pageRef);
            $annots = $page->{'/Annots'} ?? [];
            if (is_string($annots)) {
                throw new \RuntimeException('Indirect /Annots is not supported here.');
            }
            $page->{'/Annots'} = [...$annots, ($max + 2) . ' 0 R'];
            $objects[self::objectNumber($pageRef)] = self::serialize($page);
            $objects[$max + 2] = $annotation;
        }

        file_put_contents($output, self::appendCompressedUpdate((string) file_get_contents($input), $objects));
    }

    /**
     * One incremental update: the objects (no streams) in a Flate object stream,
     * indexed by an xref stream.
     *
     * @param array<int, string> $objects
     */
    private static function appendCompressedUpdate(string $bytes, array $objects): string
    {
        if (preg_match_all('/startxref\s+(\d+)/', $bytes, $m) < 1) {
            throw new \RuntimeException('No startxref');
        }
        $prev = (int) end($m[1]);
        ksort($objects);
        $streamNum = max(array_keys($objects)) + 1;
        $xrefNum = $streamNum + 1;

        $header = '';
        $body = '';
        foreach ($objects as $num => $source) {
            $header .= $num . ' ' . strlen($body) . ' ';
            $body .= $source . "\n";
        }
        $data = (string) gzcompress($header . $body);

        $out = str_ends_with($bytes, "\n") ? $bytes : $bytes . "\n";
        $streamOffset = strlen($out);
        $out .= sprintf("%d 0 obj\n<< /Type /ObjStm /N %d /First %d /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream\nendobj\n", $streamNum, count($objects), strlen($header), strlen($data), $data);
        $xrefOffset = strlen($out);

        $index = [];
        $rows = '';
        $i = 0;
        foreach (array_keys($objects) as $num) {
            $index[] = $num . ' 1';
            $rows .= chr(2) . pack('N', $streamNum) . pack('n', $i++);
        }
        $index[] = $streamNum . ' 2';
        $rows .= chr(1) . pack('N', $streamOffset) . pack('n', 0) . chr(1) . pack('N', $xrefOffset) . pack('n', 0);

        $entries = '';
        foreach (self::trailerFromBytes($bytes) as $name => $value) {
            $entries .= ' ' . $name . ' ' . $value;
        }

        return $out . sprintf(
            "%d 0 obj\n<< /Type /XRef /Size %d /Prev %d /W [1 4 2] /Index [%s] /Length %d%s >>\nstream\n%s\nendstream\nendobj\nstartxref\n%d\n%%%%EOF\n",
            $xrefNum,
            $xrefNum + 1,
            $prev,
            implode(' ', $index),
            strlen($rows),
            $entries,
            $rows,
            $xrefOffset,
        );
    }

    /**
     * Appends an update that sets one catalog entry, with extra objects numbered
     * after the file's last one: "{1}" in the sources stands for the first of them.
     *
     * @param list<string> $extraObjects
     */
    public static function appendCatalogEntry(string $input, string $output, string $key, string $value, array $extraObjects = []): void
    {
        $max = self::maxObjectId($input);
        $resolve = static fn (string $source): string => (string) preg_replace_callback('/\{(\d+)\}/', static fn (array $m): string => (string) ($max + (int) $m[1]), $source);
        $trailer = self::jsonObject($input, 'trailer');
        $rootRef = (string) $trailer->{'/Root'};
        $root = self::jsonObject($input, $rootRef);
        $root->{$key} = $resolve($value);

        $objects = [self::objectNumber($rootRef) => self::serialize($root)];
        foreach ($extraObjects as $i => $source) {
            $objects[$max + $i + 1] = $resolve($source);
        }

        file_put_contents($output, self::appendUpdate((string) file_get_contents($input), $objects, $max + count($extraObjects) + 1));
    }

    /**
     * @param list<int>|null                $visibleRect
     * @param callable(string, string): void $signer writes the DER signature of the data file
     */
    private static function addSignature(string $input, string $output, string $type, string $subFilter, ?array $visibleRect, int $pageIndex, callable $signer): void
    {
        $bytes = (string) file_get_contents($input);
        $trailer = self::jsonObject($input, 'trailer');
        $max = self::maxObjectId($input);
        $rootRef = (string) $trailer->{'/Root'};
        $root = self::jsonObject($input, $rootRef);
        $pageRef = self::pageRef($input, $pageIndex);
        $page = self::jsonObject($input, $pageRef);

        $sigNum = $max + 1;
        $fieldNum = $max + 2;
        $objects = [];

        $placeholder = '<' . str_repeat('0', self::CONTENTS_BYTES * 2) . '>';
        $objects[$sigNum] = sprintf(
            "<< /Type /%s /Filter /Adobe.PPKLite /SubFilter /%s %s /Contents %s /M (D:%sZ) >>",
            $type,
            $subFilter,
            self::BYTE_RANGE_PLACEHOLDER,
            $placeholder,
            gmdate('YmdHis'),
        );
        $objects[$fieldNum] = sprintf(
            '<< /Type /Annot /Subtype /Widget /FT /Sig /T (Signature%d) /V %d 0 R /Rect [0 0 0 0] /F 132 /P %s >>',
            $sigNum,
            $sigNum,
            $pageRef,
        );
        $size = $max + 3;
        if ($visibleRect !== null) {
            [$x1, $y1, $x2, $y2] = $visibleRect;
            $appearanceNum = $max + 3;
            $size = $max + 4;
            $content = "BT /F1 9 Tf 4 22 Td (Digitally signed by Test Signer) Tj 0 -11 Td (Date: 2026.10.25 11:46:32 +02'00') Tj ET";
            $objects[$appearanceNum] = sprintf(
                "<< /Type /XObject /Subtype /Form /BBox [0 0 %d %d] /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> /Length %d >>\nstream\n%s\nendstream",
                $x2 - $x1,
                $y2 - $y1,
                strlen($content),
                $content,
            );
            $objects[$fieldNum] = sprintf(
                '<< /Type /Annot /Subtype /Widget /FT /Sig /T (Signature%d) /V %d 0 R /Rect [%d %d %d %d] /F 4 /AP << /N %d 0 R >> /P %s >>',
                $sigNum,
                $sigNum,
                $x1,
                $y1,
                $x2,
                $y2,
                $appearanceNum,
                $pageRef,
            );
        }

        // Field list: in the AcroForm dictionary, inline or referenced.
        $fieldRef = $fieldNum . ' 0 R';
        $acroForm = $root->{'/AcroForm'} ?? null;
        if (is_string($acroForm)) {
            $form = self::jsonObject($input, $acroForm);
            $form->{'/Fields'} = [...($form->{'/Fields'} ?? []), $fieldRef];
            $form->{'/SigFlags'} = 3;
            $objects[self::objectNumber($acroForm)] = self::serialize($form);
        } else {
            $form = $acroForm instanceof \stdClass ? $acroForm : new \stdClass();
            $form->{'/Fields'} = [...($form->{'/Fields'} ?? []), $fieldRef];
            $form->{'/SigFlags'} = 3;
            $root->{'/AcroForm'} = $form;
            $objects[self::objectNumber($rootRef)] = self::serialize($root);
        }

        $annots = $page->{'/Annots'} ?? [];
        if (is_string($annots)) {
            $objects[self::objectNumber($annots)] = self::serialize([...self::jsonObject($input, $annots), $fieldRef]);
        } else {
            $page->{'/Annots'} = [...$annots, $fieldRef];
            $objects[self::objectNumber($pageRef)] = self::serialize($page);
        }

        $extraTrailer = ['/Root' => $rootRef];
        if (isset($trailer->{'/Info'})) {
            $extraTrailer['/Info'] = (string) $trailer->{'/Info'};
        }
        $signed = self::appendUpdate($bytes, $objects, $size, $extraTrailer);

        // ByteRange: everything except the hex string, delimiters included in the hole.
        $holeStart = strpos($signed, $placeholder, strlen($bytes));
        $holeEnd = $holeStart + strlen($placeholder);
        $range = sprintf('/ByteRange [0 %010d %010d %010d]', $holeStart, $holeEnd, strlen($signed) - $holeEnd);
        $rangePos = strpos($signed, self::BYTE_RANGE_PLACEHOLDER, strlen($bytes));
        $signed = substr_replace($signed, $range, $rangePos, strlen($range));

        $data = tempnam(sys_get_temp_dir(), 'sigdata');
        $sig = tempnam(sys_get_temp_dir(), 'sigout');
        file_put_contents($data, substr($signed, 0, $holeStart) . substr($signed, $holeEnd));
        $signer($data, $sig);
        $hex = bin2hex((string) file_get_contents($sig));
        @unlink($data);
        @unlink($sig);
        if (strlen($hex) > self::CONTENTS_BYTES * 2) {
            throw new \RuntimeException('Signature larger than the reserved hole');
        }

        $signed = substr_replace($signed, $hex, $holeStart + 1, strlen($hex));
        file_put_contents($output, $signed);
    }

    /**
     * Appends one incremental update with the given new or replaced objects.
     *
     * @param array<int, string>    $objects object number => dictionary source
     * @param array<string, string> $trailer extra trailer entries
     */
    public static function appendUpdate(string $bytes, array $objects, ?int $size = null, array $trailer = []): string
    {
        if (preg_match_all('/startxref\s+(\d+)/', $bytes, $m) < 1) {
            throw new \RuntimeException('No startxref');
        }
        $prev = (int) end($m[1]);
        $size ??= max(array_keys($objects)) + 1;
        if (!isset($trailer['/Root'])) {
            $trailer = [...self::trailerFromBytes($bytes), ...$trailer];
        }

        $out = $bytes;
        if (!str_ends_with($out, "\n")) {
            $out .= "\n";
        }
        ksort($objects);
        $offsets = [];
        foreach ($objects as $num => $source) {
            $offsets[$num] = strlen($out);
            $out .= sprintf("%d 0 obj\n%s\nendobj\n", $num, $source);
        }

        $entries = '';
        foreach ($trailer as $name => $value) {
            $entries .= ' ' . $name . ' ' . $value;
        }

        $xref = strlen($out);
        if (str_starts_with(ltrim(substr($bytes, $prev, 20)), 'xref')) {
            $out .= "xref\n";
            foreach ($offsets as $num => $offset) {
                $out .= sprintf("%d 1\n%010d 00000 n \n", $num, $offset);
            }
            $out .= sprintf("trailer\n<< /Size %d /Prev %d%s >>\nstartxref\n%d\n%%%%EOF\n", $size, $prev, $entries, $xref);

            return $out;
        }

        // The previous section is an xref stream: keep the same kind, as signing tools do.
        $streamNum = max($size, max(array_keys($objects)) + 1);
        $offsets[$streamNum] = $xref;
        $index = [];
        $data = '';
        foreach ($offsets as $num => $offset) {
            $index[] = $num . ' 1';
            $data .= chr(1) . pack('N', $offset) . pack('n', 0);
        }
        $out .= sprintf(
            "%d 0 obj\n<< /Type /XRef /Size %d /Prev %d /W [1 4 2] /Index [%s] /Length %d%s >>\nstream\n%s\nendstream\nendobj\nstartxref\n%d\n%%%%EOF\n",
            $streamNum,
            $streamNum + 1,
            $prev,
            implode(' ', $index),
            strlen($data),
            $entries,
            $data,
            $xref,
        );

        return $out;
    }

    /** @return array<string, string> */
    private static function trailerFromBytes(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sigtrl');
        file_put_contents($path, $bytes);
        try {
            $trailer = self::jsonObject($path, 'trailer');
        } finally {
            @unlink($path);
        }
        $out = ['/Root' => (string) $trailer->{'/Root'}];
        if (isset($trailer->{'/Info'})) {
            $out['/Info'] = (string) $trailer->{'/Info'};
        }

        return $out;
    }

    private static function maxObjectId(string $path): int
    {
        $dump = json_decode(self::qpdf(['--json=2', '--json-key=qpdf', '--json-object=trailer', '--json-stream-data=none', $path]));

        return (int) $dump->qpdf[0]->maxobjectid;
    }

    /** 0-based; negative counts from the end. */
    private static function pageRef(string $path, int $index): string
    {
        $pages = json_decode(self::qpdf(['--json=2', '--json-key=pages', $path]))->pages;

        return (string) $pages[$index < 0 ? count($pages) + $index : $index]->object;
    }

    private static function jsonObject(string $path, string $ref): mixed
    {
        $dump = json_decode(self::qpdf(['--json=2', '--json-key=qpdf', '--json-object=' . $ref, '--json-stream-data=none', $path]));
        $key = $ref === 'trailer' ? 'trailer' : 'obj:' . $ref;

        return $dump->qpdf[1]->{$key}->value;
    }

    private static function objectNumber(string $ref): int
    {
        return (int) explode(' ', $ref)[0];
    }

    /** qpdf JSON v2 value back to PDF syntax. */
    private static function serialize(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            return '[' . implode(' ', array_map(self::serialize(...), $value)) . ']';
        }
        if ($value instanceof \stdClass) {
            $out = '<<';
            foreach (get_object_vars($value) as $name => $item) {
                $out .= ' ' . $name . ' ' . self::serialize($item);
            }

            return $out . ' >>';
        }
        $value = (string) $value;
        if (preg_match('/^\d+ \d+ R$/', $value) === 1 || str_starts_with($value, '/')) {
            return $value;
        }
        if (str_starts_with($value, 'b:')) {
            return '<' . substr($value, 2) . '>';
        }
        if (str_starts_with($value, 'u:')) {
            return '<FEFF' . strtoupper(bin2hex((string) mb_convert_encoding(substr($value, 2), 'UTF-16BE', 'UTF-8'))) . '>';
        }

        // qpdf v2 writes reals as JSON numbers already; anything else is a number in a string.
        return $value;
    }

    /** @param list<string> $args */
    private static function qpdf(array $args): string
    {
        $process = new Process(['qpdf', ...$args]);
        $process->run();
        if (!in_array($process->getExitCode(), [0, 3], true)) {
            throw new \RuntimeException('qpdf failed: ' . $process->getErrorOutput());
        }

        return $process->getOutput();
    }
}
