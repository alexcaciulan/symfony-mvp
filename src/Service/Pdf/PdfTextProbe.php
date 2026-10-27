<?php

declare(strict_types=1);

namespace App\Service\Pdf;

/** Text layer of a PDF, through poppler's pdftotext. */
final class PdfTextProbe
{
    private const FOLDED_CHARACTERS = [
        "\u{015F}" => "\u{0219}", "\u{015E}" => "\u{0218}",
        "\u{0163}" => "\u{021B}", "\u{0162}" => "\u{021A}",
        "\u{201E}" => '"', "\u{201D}" => '"', "\u{201C}" => '"', "\u{00AB}" => '"', "\u{00BB}" => '"',
        // NFKC turns a double prime into two primes.
        "\u{2032}\u{2032}" => '"',
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{2032}" => "'",
        "\u{2010}" => '-', "\u{2011}" => '-', "\u{2013}" => '-',
    ];

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

    /**
     * Folds the differences text extractors and signing tools introduce without
     * changing a word: soft and line-end hyphens, compatibility forms (ligatures),
     * cedilla against comma below in ș and ț, typographic quotes and dashes, and
     * whitespace. Digits, dots, commas and diacritics stay, so a changed amount,
     * code or name stays a different word.
     */
    public static function normalize(string $text): string
    {
        $text = str_replace("\u{00AD}", '', $text);
        // Only across a real line break: a hyphen inside a line ("S.C.-urile") is a word's own.
        $text = (string) preg_replace('/(\p{L})[-\x{2010}]\n\s*(\p{Ll})/u', '$1$2', $text);
        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
        $text = is_string($normalized) ? $normalized : $text;
        $text = strtr($text, self::FOLDED_CHARACTERS);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
