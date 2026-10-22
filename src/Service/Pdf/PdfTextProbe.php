<?php

declare(strict_types=1);

namespace App\Service\Pdf;

/** Text layer of a PDF, through poppler's pdftotext. */
final class PdfTextProbe
{
    public function __construct(
        private readonly ProcessSandbox $sandbox,
    ) {}

    public function text(string $path): string
    {
        return $this->sandbox->run(['pdftotext', '-enc', 'UTF-8', $path, '-'], 60.0)->getOutput();
    }

    /**
     * One entry per page, whitespace collapsed. pdftotext ends every page with a
     * form feed, so the split keeps empty pages in place.
     *
     * @return list<string>
     */
    public function pageTexts(string $path): array
    {
        $pages = explode("\f", $this->text($path));
        array_pop($pages);

        return array_map(self::normalize(...), $pages);
    }

    /**
     * Page sizes as displayed, after /Rotate.
     *
     * @return list<array{width: float, height: float}>
     */
    public function pageSizes(string $path, int $pageCount): array
    {
        $output = $this->sandbox->run(['pdfinfo', '-f', '1', '-l', (string) $pageCount, $path], 60.0)->getOutput();
        $sizes = [];
        $rotations = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^Page\s+(\d+)\s+size:\s+([\d.]+) x ([\d.]+)/', $line, $m) === 1) {
                $sizes[(int) $m[1]] = [(float) $m[2], (float) $m[3]];
            } elseif (preg_match('/^Page\s+(\d+)\s+rot:\s+(\d+)/', $line, $m) === 1) {
                $rotations[(int) $m[1]] = (int) $m[2];
            }
        }

        $result = [];
        for ($i = 1; $i <= $pageCount; $i++) {
            [$w, $h] = $sizes[$i] ?? [595.0, 842.0];
            if (in_array(($rotations[$i] ?? 0) % 360, [90, 270], true)) {
                [$w, $h] = [$h, $w];
            }
            $result[] = ['width' => $w, 'height' => $h];
        }

        return $result;
    }

    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
