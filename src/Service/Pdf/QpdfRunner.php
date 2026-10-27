<?php

declare(strict_types=1);

namespace App\Service\Pdf;

/**
 * Lossless PDF structure operations through qpdf: inspection, merge, compaction,
 * overlay and the Info dictionary. qpdf never re-renders content, which is why
 * it, and not Ghostscript, handles everything that must stay as it was.
 */
final class QpdfRunner
{
    public const PART_ID_KEY = 'LexRecoveryPartId';
    /** Virtual memory cap for qpdf parsing an untrusted file. */
    public const MEMORY_LIMIT_KB = 524_288;
    private const XMP_MAX_BYTES = 1_048_576;

    public function __construct(
        private readonly ProcessSandbox $sandbox,
    ) {}

    /**
     * Refuses what the court's systems or a signing tool would choke on, or what
     * could run code: encrypted files, XFA forms, embedded JavaScript, broken files.
     */
    public function inspect(string $path): PdfInspection
    {
        $check = $this->sandbox->run(['qpdf', '--check', $path], 60.0, [0, 2, 3], self::MEMORY_LIMIT_KB);
        if ($check->getExitCode() === 2) {
            $output = $check->getOutput() . $check->getErrorOutput();
            if (stripos($output, 'password') !== false || stripos($output, 'encrypt') !== false) {
                throw new PdfToolException('Encrypted PDF', 'filing.package.error.encrypted');
            }

            throw new PdfToolException('Damaged PDF: ' . mb_substr(trim($output), 0, 300), 'filing.package.error.corrupt');
        }

        $process = $this->sandbox->run([
            'qpdf', '--json=2', '--json-key=encrypt', '--json-key=pages', '--json-key=acroform', '--json-key=qpdf', '--json-stream-data=none', $path,
        ], 60.0, [0, 3], self::MEMORY_LIMIT_KB);
        $json = $process->getOutput();
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new PdfToolException('Unreadable qpdf JSON', 'filing.package.error.corrupt');
        }

        if (($data['encrypt']['encrypted'] ?? false) === true) {
            throw new PdfToolException('Encrypted PDF', 'filing.package.error.encrypted');
        }

        // Key names in the object dump; a JavaScript action or an XFA form anywhere is refused.
        if (preg_match('#"/(JavaScript|JS|XFA)"#', $json) === 1) {
            throw new PdfToolException('PDF with JavaScript or XFA', 'filing.package.error.active_content');
        }

        $pages = count($data['pages'] ?? []);
        if ($pages === 0) {
            throw new PdfToolException('PDF without pages', 'filing.package.error.corrupt');
        }

        return new PdfInspection($pages, (bool) ($data['acroform']['hasacroform'] ?? false));
    }

    /**
     * Form fields, pages and the whole object table (stream dictionaries only) in
     * one call, for comparing structures.
     *
     * @return array<string, mixed>
     */
    public function dump(string $path): array
    {
        $data = json_decode($this->sandbox->run(
            ['qpdf', '--json=2', '--json-key=acroform', '--json-key=pages', '--json-key=qpdf', '--json-stream-data=none', $path],
            60.0,
            [0, 3],
            self::MEMORY_LIMIT_KB,
        )->getOutput(), true);
        if (!is_array($data)) {
            throw new PdfToolException('Unreadable qpdf JSON', 'filing.package.error.corrupt');
        }

        return $data;
    }

    public function pageCount(string $path): int
    {
        return (int) trim($this->sandbox->run(['qpdf', '--show-npages', $path], 30.0, [0, 3])->getOutput());
    }

    /** @param list<string> $inputs */
    public function merge(array $inputs, string $output): void
    {
        $this->sandbox->run(['qpdf', '--empty', '--pages', ...$inputs, '--', $output], 90.0, [0, 3]);
    }

    /** Lossless: object streams and Flate on uncompressed streams, no re-rendering. */
    public function compact(string $input, string $output): void
    {
        $this->sandbox->run(['qpdf', '--object-streams=generate', '--compress-streams=y', $input, $output], 90.0, [0, 3]);
    }

    /** Stamps page i of the overlay over page i of the base. */
    public function overlay(string $base, string $overlay, string $output): void
    {
        $this->sandbox->run(['qpdf', $base, '--overlay', $overlay, '--', $output], 90.0, [0, 3]);
    }

    /**
     * Picks pages from several files into one, in the given order.
     *
     * @param list<array{0: string, 1: string}> $selection [path, page range]
     */
    public function selectPages(array $selection, string $output): void
    {
        $args = [];
        foreach ($selection as [$path, $range]) {
            $args[] = $path;
            $args[] = $range;
        }
        $this->sandbox->run(['qpdf', '--empty', '--pages', ...$args, '--', $output], 90.0, [0, 3]);
    }

    /** Sets one key of the document Info dictionary, creating the dictionary when absent. */
    public function setInfoKey(string $input, string $output, string $key, string $value): void
    {
        $dump = json_decode($this->sandbox->run(
            ['qpdf', '--json=2', '--json-key=qpdf', '--json-object=trailer', '--json-stream-data=none', $input],
            60.0,
            [0, 3],
        )->getOutput(), true);
        if (!is_array($dump) || !isset($dump['qpdf'][0], $dump['qpdf'][1]['trailer']['value'])) {
            throw new PdfToolException('Cannot read PDF trailer');
        }

        $header = $dump['qpdf'][0];
        $trailer = $dump['qpdf'][1]['trailer']['value'];
        $infoRef = is_string($trailer['/Info'] ?? null) ? $trailer['/Info'] : null;
        $info = [];
        if ($infoRef !== null) {
            $infoDump = json_decode($this->sandbox->run(
                ['qpdf', '--json=2', '--json-key=qpdf', '--json-object=' . $infoRef, '--json-stream-data=none', $input],
                60.0,
                [0, 3],
            )->getOutput(), true);
            $info = $infoDump['qpdf'][1]['obj:' . $infoRef]['value'] ?? [];
        }
        $info['/' . $key] = 'u:' . $value;

        $objects = [];
        if ($infoRef !== null) {
            $objects['obj:' . $infoRef] = ['value' => $info];
        } else {
            $newRef = ((int) ($header['maxobjectid'] ?? 0) + 1) . ' 0 R';
            $objects['obj:' . $newRef] = ['value' => $info];
            $trailer['/Info'] = $newRef;
            $objects['trailer'] = ['value' => $trailer];
        }

        $update = $this->sandbox->tempPath('json');
        file_put_contents($update, json_encode([
            'qpdf' => [['jsonversion' => 2, 'pushedinheritedpageresources' => false, 'calledgetallpages' => false, 'maxobjectid' => $header['maxobjectid'] ?? 0], $objects],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->sandbox->run(['qpdf', $input, '--update-from-json=' . $update, $output], 60.0, [0, 3]);
    }

    /**
     * Writes a copy without the signature widgets. A visible signature is drawn in
     * a widget annotation that a text extractor reads in among the page words, so
     * the page content is compared on this copy. Every other annotation stays, so
     * a stamp or a comment still counts as text the signed file added. Returns
     * false when the file has no signature widget on any page.
     */
    public function withoutSignatureWidgets(string $input, string $output): bool
    {
        $dump = json_decode($this->sandbox->run(
            ['qpdf', '--json=2', '--json-key=qpdf', '--json-key=pages', '--json-stream-data=none', $input],
            60.0,
            [0, 3],
            self::MEMORY_LIMIT_KB,
        )->getOutput());
        if (!$dump instanceof \stdClass || !isset($dump->qpdf[1])) {
            throw new PdfToolException('Unreadable qpdf JSON', 'filing.package.error.corrupt');
        }
        $objects = $dump->qpdf[1];
        $resolve = static fn (mixed $value): mixed => is_string($value) && preg_match('/^\d+ \d+ R$/', $value) === 1
            ? ($objects->{'obj:' . $value}->value ?? null)
            : $value;

        $updates = [];
        foreach ($dump->pages ?? [] as $page) {
            $ref = $page->object ?? null;
            $value = is_string($ref) ? ($objects->{'obj:' . $ref}->value ?? null) : null;
            if (!$value instanceof \stdClass || !isset($value->{'/Annots'})) {
                continue;
            }
            $annots = $resolve($value->{'/Annots'});
            if (!is_array($annots)) {
                continue;
            }
            $kept = array_values(array_filter($annots, static fn (mixed $annot): bool => !self::isSignatureWidget($resolve($annot), $resolve)));
            if (count($kept) === count($annots)) {
                continue;
            }
            if ($kept === []) {
                unset($value->{'/Annots'});
            } else {
                $value->{'/Annots'} = $kept;
            }
            $updates['obj:' . $ref] = ['value' => $value];
        }
        if ($updates === []) {
            return false;
        }

        $update = $this->sandbox->tempPath('json');
        file_put_contents($update, json_encode([
            'qpdf' => [['jsonversion' => 2, 'pushedinheritedpageresources' => false, 'calledgetallpages' => false, 'maxobjectid' => 0], $updates],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->sandbox->run(['qpdf', $input, '--update-from-json=' . $update, $output], 60.0, [0, 3], self::MEMORY_LIMIT_KB);

        return true;
    }

    /**
     * The part token as the signed file carries it: in the Info dictionary, or,
     * when a tool dropped Info, in the XMP metadata, where Adobe products copy
     * custom Info keys as pdfx properties.
     *
     * @return array{info: ?string, xmp: ?string}
     */
    public function readPartToken(string $path): array
    {
        $trailer = $this->objects($path, ['trailer'])['trailer'] ?? null;
        $infoRef = is_string($trailer['/Info'] ?? null) ? $trailer['/Info'] : null;
        $rootRef = is_string($trailer['/Root'] ?? null) ? $trailer['/Root'] : null;
        $objects = $this->objects($path, array_values(array_filter([$infoRef, $rootRef], is_string(...))));

        $info = $infoRef !== null ? self::stringValue($objects['obj:' . $infoRef]['/' . self::PART_ID_KEY] ?? null) : null;
        if ($info !== null) {
            return ['info' => $info, 'xmp' => null];
        }

        $metadataRef = $rootRef !== null ? ($objects['obj:' . $rootRef]['/Metadata'] ?? null) : null;
        if (!is_string($metadataRef) || preg_match('/^(\d+) (\d+) R$/', $metadataRef, $m) !== 1) {
            return ['info' => null, 'xmp' => null];
        }
        // Capped: the packet is a few kilobytes, an untrusted stream may not be.
        $xmp = $this->sandbox->run(
            ['sh', '-c', 'qpdf --show-object="$1" --filtered-stream-data "$2" | head -c ' . self::XMP_MAX_BYTES, 'sh', $m[1] . ',' . $m[2], $path],
            60.0,
            [0],
            self::MEMORY_LIMIT_KB,
        )->getOutput();

        return ['info' => null, 'xmp' => self::xmpPartToken($xmp)];
    }

    public static function xmpPartToken(string $xmp): ?string
    {
        $key = 'pdfx:' . self::PART_ID_KEY;
        if (preg_match('#<' . $key . '>([^<]*)</' . $key . '>#', $xmp, $m) !== 1
            && preg_match('#' . $key . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')#', $xmp, $m) !== 1) {
            return null;
        }
        $value = trim(html_entity_decode(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8'));

        return $value === '' ? null : $value;
    }

    /**
     * A widget belongs to a signature field when its own field type, or an
     * ancestor's (the type is inherited), is /Sig.
     *
     * @param callable(mixed): mixed $resolve
     */
    private static function isSignatureWidget(mixed $annot, callable $resolve): bool
    {
        if (!$annot instanceof \stdClass || ($annot->{'/Subtype'} ?? null) !== '/Widget') {
            return false;
        }
        $node = $annot;
        for ($depth = 0; $depth < 32 && $node instanceof \stdClass; ++$depth) {
            if (isset($node->{'/FT'})) {
                return $node->{'/FT'} === '/Sig';
            }
            $node = $resolve($node->{'/Parent'} ?? null);
        }

        return false;
    }

    public function readInfoKey(string $path, string $key): ?string
    {
        $trailer = $this->objects($path, ['trailer'])['trailer'] ?? null;
        $infoRef = $trailer['/Info'] ?? null;
        if (!is_string($infoRef)) {
            return null;
        }

        return self::stringValue($this->objects($path, [$infoRef])['obj:' . $infoRef]['/' . $key] ?? null);
    }

    /**
     * Several objects' values in one qpdf call, keyed as qpdf keys them
     * ("trailer", "obj:12 0 R").
     *
     * @param list<string> $refs
     *
     * @return array<string, mixed>
     */
    private function objects(string $path, array $refs): array
    {
        if ($refs === []) {
            return [];
        }
        $args = array_map(static fn (string $ref): string => '--json-object=' . $ref, $refs);
        $dump = json_decode($this->sandbox->run(
            ['qpdf', '--json=2', '--json-key=qpdf', ...$args, '--json-stream-data=none', $path],
            60.0,
            [0, 3],
            self::MEMORY_LIMIT_KB,
        )->getOutput(), true);

        return array_map(static fn (mixed $entry): mixed => is_array($entry) ? ($entry['value'] ?? null) : null, $dump['qpdf'][1] ?? []);
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) ? (string) preg_replace('/^[ub]:/', '', $value) : null;
    }
}
