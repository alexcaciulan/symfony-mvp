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
     * The form fields and the pages of a file, as qpdf describes them.
     *
     * @return array<string, mixed>
     */
    public function json(string $path): array
    {
        $data = json_decode($this->sandbox->run(
            ['qpdf', '--json=2', '--json-key=acroform', '--json-key=pages', $path],
            60.0,
            [0, 3],
            self::MEMORY_LIMIT_KB,
        )->getOutput(), true);
        if (!is_array($data)) {
            throw new PdfToolException('Unreadable qpdf JSON', 'filing.package.error.corrupt');
        }

        return $data;
    }

    /**
     * One object's value (dictionary, array or scalar) in qpdf's JSON v2 notation.
     */
    public function object(string $path, string $ref): mixed
    {
        $data = json_decode($this->sandbox->run(
            ['qpdf', '--json=2', '--json-key=qpdf', '--json-object=' . $ref, '--json-stream-data=none', $path],
            60.0,
            [0, 3],
            self::MEMORY_LIMIT_KB,
        )->getOutput(), true);

        return $data['qpdf'][1]['obj:' . $ref]['value'] ?? null;
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
     * Writes a copy without the page annotations. A visible signature is drawn in a
     * widget annotation that a text extractor reads in among the page words, so the
     * page content is compared on this copy. Returns false when there was nothing to drop.
     */
    public function withoutAnnotations(string $input, string $output): bool
    {
        $pages = $this->json($input)['pages'] ?? [];
        $objects = [];
        foreach ($pages as $page) {
            $ref = $page['object'] ?? null;
            if (!is_string($ref)) {
                continue;
            }
            $value = $this->object($input, $ref);
            if (!is_array($value) || !array_key_exists('/Annots', $value)) {
                continue;
            }
            unset($value['/Annots']);
            $objects['obj:' . $ref] = ['value' => $value];
        }
        if ($objects === []) {
            return false;
        }

        $update = $this->sandbox->tempPath('json');
        file_put_contents($update, json_encode([
            'qpdf' => [['jsonversion' => 2, 'pushedinheritedpageresources' => false, 'calledgetallpages' => false, 'maxobjectid' => 0], $objects],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->sandbox->run(['qpdf', $input, '--update-from-json=' . $update, $output], 60.0, [0, 3], self::MEMORY_LIMIT_KB);

        return true;
    }

    public function readInfoKey(string $path, string $key): ?string
    {
        $dump = json_decode($this->sandbox->run(
            ['qpdf', '--json=2', '--json-key=qpdf', '--json-object=trailer', '--json-stream-data=none', $path],
            60.0,
            [0, 3],
        )->getOutput(), true);
        $infoRef = $dump['qpdf'][1]['trailer']['value']['/Info'] ?? null;
        if (!is_string($infoRef)) {
            return null;
        }
        $infoDump = json_decode($this->sandbox->run(
            ['qpdf', '--json=2', '--json-key=qpdf', '--json-object=' . $infoRef, '--json-stream-data=none', $path],
            60.0,
            [0, 3],
        )->getOutput(), true);
        $value = $infoDump['qpdf'][1]['obj:' . $infoRef]['value']['/' . $key] ?? null;
        if (!is_string($value)) {
            return null;
        }

        return preg_replace('/^[ub]:/', '', $value);
    }
}
