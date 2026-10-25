<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Reproduces what signing and PDF tools do to a file before or while signing it:
 * rewriting, linearizing, re-distilling, PDF/A conversion, dropping metadata,
 * drawing a stamp into the page, adding pages or comments. The negative variants
 * change the document's text or pages. Everything is synthetic; the binaries run
 * in the PHP container.
 */
final class TestPdfVariants
{
    public static function qpdfRewrite(string $in, string $out): void
    {
        self::run(['qpdf', '--object-streams=disable', $in, $out]);
    }

    public static function linearize(string $in, string $out): void
    {
        self::run(['qpdf', '--linearize', $in, $out]);
    }

    public static function objectStreams(string $in, string $out): void
    {
        self::run(['qpdf', '--object-streams=generate', $in, $out]);
    }

    /** Re-distilled, as "print to PDF" or a tool's optimiser does: new objects, new fonts, no custom Info keys guaranteed. */
    public static function gsPdfwrite(string $in, string $out): void
    {
        self::run(['gs', '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=pdfwrite', '-sOutputFile=' . $out, $in]);
    }

    public static function gsPdfA(string $in, string $out): void
    {
        self::run(['gs', '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-dPDFA=2', '-dPDFACompatibilityPolicy=1', '-sColorConversionStrategy=RGB', '-sDEVICE=pdfwrite', '-sOutputFile=' . $out, $in]);
    }

    public static function removeInfo(string $in, string $out): void
    {
        self::run(['qpdf', '--remove-info', $in, $out]);
    }

    public static function removeInfoAndMetadata(string $in, string $out): void
    {
        self::run(['qpdf', '--remove-info', '--remove-metadata', $in, $out]);
    }

    /** XMP metadata carrying the part token, as Adobe products copy custom Info keys. */
    public static function withXmpToken(string $in, string $out, string $token, bool $asAttribute = false): void
    {
        $property = $asAttribute
            ? sprintf('<rdf:Description rdf:about="" xmlns:pdfx="http://ns.adobe.com/pdfx/1.3/" pdfx:LexRecoveryPartId="%s"/>', htmlspecialchars($token, ENT_XML1 | ENT_QUOTES))
            : sprintf('<rdf:Description rdf:about="" xmlns:pdfx="http://ns.adobe.com/pdfx/1.3/"><pdfx:LexRecoveryPartId>%s</pdfx:LexRecoveryPartId></rdf:Description>', htmlspecialchars($token, ENT_XML1));
        $xmp = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . $property . '</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';

        TestPdfSigner::appendCatalogEntry($in, $out, '/Metadata', '{1} 0 R', [
            sprintf("<< /Type /Metadata /Subtype /XML /Length %d >>\nstream\n%s\nendstream", strlen($xmp), $xmp),
        ]);
    }

    /**
     * Draws text into the page content, near the bottom where the fixtures have no
     * text: a visible signature or a stamp flattened into the page.
     */
    public static function stampIntoContent(string $in, string $out, string $text = 'Semnat electronic de TEST SIGNER', int $page = 1): void
    {
        $stamp = self::onePage($text, 40, 60);
        try {
            self::run(['qpdf', $in, '--overlay', $stamp, '--to=' . $page, '--from=1', '--', $out]);
        } finally {
            @unlink($stamp);
        }
    }

    /** A rewrite that appends a separate signature page after the document. */
    public static function appendPageRewrite(string $in, string $out, string $text = 'Pagina de semnaturi'): void
    {
        $page = self::onePage($text, 72, 760);
        try {
            self::run(['qpdf', $in, '--pages', '.', '1-z', $page, '1', '--', $out]);
        } finally {
            @unlink($page);
        }
    }

    public static function reversePages(string $in, string $out): void
    {
        self::run(['qpdf', $in, '--pages', '.', 'z-1', '--', $out]);
    }

    /** @param int $page 1-based */
    public static function dropPage(string $in, string $out, int $page): void
    {
        self::run(['qpdf', $in, '--pages', '.', '1-z,x' . $page, '--', $out]);
    }

    /**
     * Replaces text inside the page content without changing its length. The
     * fixtures are rendered by DomPDF, which writes each character as its 4-digit
     * Unicode code in hex strings, so $from and $to must have the same length.
     */
    public static function changeText(string $in, string $out, string $from, string $to): void
    {
        $fromHex = self::hexText($from);
        $toHex = self::hexText($to);
        if (strlen($fromHex) !== strlen($toHex)) {
            throw new \InvalidArgumentException('The replacement must have the same length.');
        }
        self::editContent($in, $out, static function (string $qdf) use ($fromHex, $toHex): string {
            $pos = stripos($qdf, $fromHex);
            if ($pos === false) {
                throw new \RuntimeException('Text not found in the page content.');
            }

            return substr_replace($qdf, $toHex, $pos, strlen($toHex));
        });
    }

    /** Blanks the whole text run (one line) that contains $text, keeping the content length. */
    public static function deleteLineContaining(string $in, string $out, string $text): void
    {
        $hex = self::hexText($text);
        self::editContent($in, $out, static function (string $qdf) use ($hex): string {
            if (preg_match('/<([0-9a-fA-F]*' . preg_quote($hex, '/') . '[0-9a-fA-F]*)>/i', $qdf, $m, PREG_OFFSET_CAPTURE) !== 1) {
                throw new \RuntimeException('Text not found in the page content.');
            }

            return substr_replace($qdf, '<>' . str_repeat(' ', strlen($m[0][0]) - 2), $m[0][1], strlen($m[0][0]));
        });
    }

    private static function hexText(string $text): string
    {
        return bin2hex((string) mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'));
    }

    /** @param callable(string): string $edit */
    private static function editContent(string $in, string $out, callable $edit): void
    {
        $qdf = $out . '.qdf';
        self::run(['qpdf', '--qdf', '--object-streams=disable', '--compress-streams=n', '--decode-level=generalized', $in, $qdf]);
        file_put_contents($qdf, $edit((string) file_get_contents($qdf)));
        try {
            self::run(['qpdf', $qdf, $out]);
        } finally {
            @unlink($qdf);
        }
    }

    /** A one-page A4 PDF with one line of Helvetica text at (x, y), through Ghostscript. */
    private static function onePage(string $text, int $x, int $y): string
    {
        $base = sys_get_temp_dir() . '/variant-' . bin2hex(random_bytes(6));
        $ps = $base . '.ps';
        $pdf = $base . '.pdf';
        file_put_contents($ps, sprintf(
            "<< /PageSize [595 842] >> setpagedevice\n/Helvetica findfont 9 scalefont setfont\n%d %d moveto (%s) show\nshowpage\n",
            $x,
            $y,
            addcslashes($text, '()\\'),
        ));
        try {
            self::run(['gs', '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=pdfwrite', '-sOutputFile=' . $pdf, $ps]);
        } finally {
            @unlink($ps);
        }

        return $pdf;
    }

    /** @param list<string> $command */
    private static function run(array $command): void
    {
        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();
        // qpdf exits 3 on warnings with a usable output.
        if (!in_array($process->getExitCode(), [0, 3], true)) {
            throw new \RuntimeException(sprintf('%s failed (%d): %s', $command[0], (int) $process->getExitCode(), $process->getErrorOutput()));
        }
    }
}
