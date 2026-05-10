<?php

/**
 * One-shot generator for OCR test fixtures committed under tests/fixtures/ocr/.
 * Run inside Docker (Tesseract + ImageMagick installed in the PHP container):
 *
 *   docker compose exec php php tests/fixtures/ocr/generate-fixtures.php
 *
 * Re-run only when fixture content needs to change. The resulting images and
 * PDF are committed so test runs are deterministic across machines (re-rendering
 * via different ImageMagick / GD versions can produce slightly different pixel
 * values — Tesseract's confidence on those would drift, breaking assertions).
 *
 * Layout: large, anti-aliased text on white background — what a clean
 * digital-printed page or a high-quality scan would look like. NOT a
 * worst-case scan with noise, skew, or low DPI; that family of fixtures is
 * out of scope here (would require pre-built scanned samples).
 *
 * Fictive identifiers used (no real persons / companies):
 *   - CUI 15193236 (checksum-valid per ANAF)
 *   - Sum + due date constants — no real-world equivalent.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

const FIXTURES_DIR = __DIR__;

function renderTextPng(string $filename, array $lines, int $width = 1400, int $lineHeight = 80): void
{
    // GD is built into the PHP image (no separate extension needed for basic ops).
    $height = $lineHeight * count($lines) + 40;
    $image = imagecreate($width, $height);
    if ($image === false) {
        throw new RuntimeException('GD imagecreate failed');
    }

    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    if ($white === false || $black === false) {
        throw new RuntimeException('GD imagecolorallocate failed');
    }

    imagefilledrectangle($image, 0, 0, $width, $height, $white);

    // Use the largest built-in GD font (5) — Tesseract still picks it up reliably
    // at 1400px width even though the bitmap font is small. For more realistic
    // text rendering, ImageMagick's `convert -font` would be preferred; GD
    // avoids the external dependency for this minimal generator.
    $fontSize = 5;
    $charWidth = imagefontwidth($fontSize);
    $charHeight = imagefontheight($fontSize);

    foreach ($lines as $i => $line) {
        $x = 40;
        $y = 30 + $i * $lineHeight + ($lineHeight - $charHeight) / 2;
        imagestring($image, $fontSize, $x, (int) $y, $line, $black);
    }

    if (imagepng($image, FIXTURES_DIR . '/' . $filename) === false) {
        throw new RuntimeException("imagepng failed for $filename");
    }
    imagedestroy($image);

    echo "  ✓ {$filename}\n";
}

function renderImageMagickPng(string $filename, array $lines): void
{
    // Higher-quality alternative using ImageMagick's `magick` CLI (available in
    // the Docker image after Pas 2.5.5 Dockerfile update). Produces anti-aliased
    // text at a font size Tesseract recognises with high confidence.
    $tempLabel = tempnam(sys_get_temp_dir(), 'ocrlabel-') . '.txt';
    file_put_contents($tempLabel, implode("\n", $lines));

    $output = FIXTURES_DIR . '/' . $filename;
    $cmd = sprintf(
        'magick -size 1400x600 -background white -fill black -font DejaVu-Sans -pointsize 36 -gravity NorthWest label:@%s %s 2>&1',
        escapeshellarg($tempLabel),
        escapeshellarg($output),
    );
    exec($cmd, $stderr, $code);
    @unlink($tempLabel);

    if ($code !== 0) {
        // Fall back to GD if ImageMagick isn't available (host run, no Docker).
        echo "  ⚠ ImageMagick failed (code $code), falling back to GD for {$filename}: " . implode("\n", $stderr) . "\n";
        renderTextPng($filename, $lines);
        return;
    }

    echo "  ✓ {$filename} (via ImageMagick)\n";
}

function renderPdfFromImage(string $imageName, string $pdfName): void
{
    $imgPath = FIXTURES_DIR . '/' . $imageName;
    $pdfPath = FIXTURES_DIR . '/' . $pdfName;

    if (!is_file($imgPath)) {
        throw new RuntimeException("Source image $imgPath not found; generate it first");
    }

    // ImageMagick wraps a raster PNG into a PDF page. The result has NO text
    // layer — Tesseract must OCR it pixel-by-pixel, which is the exact scenario
    // we want to exercise (mimics a flatbed-scanned document).
    $cmd = sprintf('magick %s %s 2>&1', escapeshellarg($imgPath), escapeshellarg($pdfPath));
    exec($cmd, $stderr, $code);

    if ($code !== 0) {
        throw new RuntimeException("magick failed for $pdfName (code $code): " . implode("\n", $stderr));
    }

    echo "  ✓ {$pdfName} (scanned-style PDF, no text layer)\n";
}

// Helper: render an HTML payload via DomPDF, then re-process the resulting
// PDF through ImageMagick at 200 DPI to strip the text layer. Result mimics
// what a flatbed scanner produces: image-only PDF, NO embedded text — exactly
// the input shape that TesseractOcrService must handle in production when a
// lawyer uploads a scanned contract / invoice / loan agreement.
//
// Two-step approach:
//   1. DomPDF renders rich HTML → native PDF (with text layer).
//   2. `magick -density 200 native.pdf scanned.pdf` re-rasterises every page
//      to an image and re-wraps as PDF, dropping the text layer entirely.
//
// The resulting PDF is what production OCR sees: pixels only, no shortcut
// path through smalot/pdfparser; TesseractOcrService must call `pdftoppm`
// and `tesseract` page-by-page.
function renderScannedPdfFromHtml(string $filename, string $html, int $dpi = 200): void
{
    $nativePath = tempnam(sys_get_temp_dir(), 'ocr-native-') . '.pdf';
    $scannedPath = FIXTURES_DIR . '/' . $filename;

    // Step 1: HTML → native PDF (text layer present).
    $dompdf = new Dompdf\Dompdf(['isPhpEnabled' => false, 'isRemoteEnabled' => false]);
    $dompdf->loadHtml('<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>');
    $dompdf->setPaper('A4');
    $dompdf->render();
    file_put_contents($nativePath, $dompdf->output());

    // Step 2: native PDF → image-based PDF (text layer stripped).
    $cmd = sprintf(
        'magick -density %d %s %s 2>&1',
        $dpi,
        escapeshellarg($nativePath),
        escapeshellarg($scannedPath),
    );
    exec($cmd, $stderr, $code);
    @unlink($nativePath);

    if ($code !== 0) {
        throw new RuntimeException("magick (native→scanned) failed for $filename (code $code): " . implode("\n", $stderr));
    }

    echo "  ✓ {$filename} (" . filesize($scannedPath) . " bytes, scanned-style PDF)\n";
}

echo "Generating OCR fixtures...\n";

// Fixture 1: clean PNG with controlled text — minimal smoke-test fixture
// covering the image branch (extractFromImage). Asserts pass when the OCR
// pipeline correctly returns CUI / amount / due-date substrings.
renderImageMagickPng('clean-text.png', [
    'CUI RO15193236',
    'Total: 5000.00 RON',
    'Scadenta: 15.06.2026',
]);

// Fixture 2: minimal scanned PDF wrapping the same image — exercises the
// pdftoppm → tesseract pipeline at the simplest level.
renderPdfFromImage('clean-text.png', 'scanned-document.pdf');

// Fixture 3: realistic scanned INVOICE — ERP-style layout with header,
// 3-row line-item table, totals section, footer with bank account + RO 13/2011
// late-payment-interest reference. Mirrors what a lawyer would receive after
// a client scans a paper invoice through their office printer.
renderScannedPdfFromHtml('scanned-invoice.pdf', <<<'HTML'
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #000; }
  .header { border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 12px; }
  .header h1 { margin: 0; font-size: 14pt; }
  .meta { font-size: 9pt; }
  .parties { width: 100%; margin: 10px 0; border-collapse: collapse; }
  .parties .col { display: inline-block; width: 48%; vertical-align: top; padding: 6px; border: 1px solid #555; }
  .parties h3 { margin: 0 0 4px 0; font-size: 10pt; }
  table.lines { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 9pt; }
  table.lines th, table.lines td { border: 1px solid #555; padding: 3px 5px; }
  .totals { width: 50%; margin-left: auto; margin-top: 8px; font-size: 10pt; }
  .totals td { padding: 2px 6px; }
  .footer { margin-top: 16px; border-top: 1px solid #000; padding-top: 6px; font-size: 9pt; }
</style>
<div class="header">
  <h1>Alpha Servicii Comerciale SRL</h1>
  <div class="meta">Sediu: Cluj-Napoca, str. Memorandumului nr. 28 | CUI: RO15193236</div>
</div>
<h2 style="text-align:center; margin: 6px 0;">FACTURA FISCALA</h2>
<p style="text-align:center; margin: 0 0 12px 0;">
  Seria ALP nr. 2026-0042 | Data: 01.05.2026 | Scadenta: 31.05.2026
</p>
<div class="parties">
  <div class="col">
    <h3>Furnizor</h3>
    Alpha Servicii Comerciale SRL<br>
    CUI: RO15193236<br>
    IBAN: RO49AAAA1B31007593840000
  </div>
  <div class="col">
    <h3>Client</h3>
    Beta Distribution SRL<br>
    CUI: RO14186770<br>
    Adresa: Bucuresti, sector 3
  </div>
</div>
<table class="lines">
  <thead><tr><th>#</th><th>Descriere</th><th>Cant.</th><th>Pret</th><th>Total</th></tr></thead>
  <tbody>
    <tr><td>1</td><td>Consultanta IT mai 2026</td><td>40</td><td>85,00</td><td>3.400,00</td></tr>
    <tr><td>2</td><td>Mentenanta servere</td><td>1</td><td>1.200,00</td><td>1.200,00</td></tr>
    <tr><td>3</td><td>Licente software</td><td>3</td><td>150,00</td><td>450,00</td></tr>
  </tbody>
</table>
<table class="totals">
  <tr><td>Subtotal:</td><td>5.050,00 RON</td></tr>
  <tr><td>TVA 19%:</td><td>959,50 RON</td></tr>
  <tr><td><b>Total:</b></td><td><b>6.009,50 RON</b></td></tr>
</table>
<div class="footer">
  Termen plata: 31.05.2026. Pentru intarziere se aplica dobanda penalizatoare
  conform OG 13/2011 art. 3 alin. 2.<br>
  Plata in contul IBAN RO49AAAA1B31007593840000 la Banca Transilvania.
</div>
HTML);

// Fixture 4: realistic scanned CONTRACT (single page, B2B service contract).
// Contains multiple keyword sections, party identification with CUI/IBAN/legal
// representative, and an explicit reference to CPC art. 1014 (payment-order
// procedure) — the kind of document a lawyer attaches as the underlying claim
// when filing an OP request.
renderScannedPdfFromHtml('scanned-contract.pdf', <<<'HTML'
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; line-height: 1.4; }
  h1 { text-align: center; font-size: 13pt; margin: 0 0 6px 0; }
  h2 { font-size: 11pt; margin: 12px 0 6px 0; }
  .article { margin: 8px 0; text-align: justify; }
  .article-head { font-weight: bold; }
  .signatures { width: 100%; margin-top: 24px; }
  .signatures td { width: 50%; padding-top: 30px; border-top: 1px solid #333; vertical-align: top; }
</style>
<h1>CONTRACT DE PRESTARI SERVICII</h1>
<p style="text-align:center; margin: 0 0 14px 0; font-size: 10pt;">Nr. 158 / 12.04.2026</p>

<h2>I. Partile</h2>
<div class="article">
  <span class="article-head">Furnizor:</span>
  Alpha Servicii Comerciale SRL, persoana juridica romana, cu sediul in
  Cluj-Napoca, str. Memorandumului nr. 28, judet Cluj, inregistrata la
  Registrul Comertului sub J12/1234/2018, CUI <b>RO15193236</b>,
  cont bancar IBAN <b>RO49AAAA1B31007593840000</b> la Banca Transilvania,
  reprezentata legal de Maria Ionescu, administrator.
</div>
<div class="article">
  <span class="article-head">Client (Beneficiar):</span>
  Beta Distribution SRL, persoana juridica romana, cu sediul in Bucuresti,
  sector 3, bd. Decebal nr. 17, J40/9876/2020, CUI <b>RO14186770</b>,
  reprezentata de Ion Popescu, director general.
</div>

<h2>II. Obiectul contractului</h2>
<div class="article">
  <span class="article-head">Art. 1.</span> Prestatorul se obliga sa furnizeze
  Beneficiarului servicii de consultanta IT, mentenanta sisteme si suport
  tehnic, conform Anexei 1.
</div>

<h2>III. Pret si plata</h2>
<div class="article">
  <span class="article-head">Art. 2.</span> Pretul total al serviciilor:
  <b>6.009,50 RON</b> (sasemii noua 50/100 lei), TVA inclus.
</div>
<div class="article">
  <span class="article-head">Art. 3.</span> Plata se efectueaza in contul
  Furnizorului in termen de <b>30 zile</b> de la emiterea facturii.
  Termen final: <b>31.05.2026</b>.
</div>

<h2>IV. Sanctiuni</h2>
<div class="article">
  <span class="article-head">Art. 4.</span> In caz de intarziere, Beneficiarul
  datoreaza dobanda penalizatoare BNR + 8 puncte procentuale, conform OG
  13/2011 art. 3 alin. 2 (modificat prin Legea 72/2013).
</div>
<div class="article">
  <span class="article-head">Art. 5.</span> Prezentul contract constituie titlu
  pentru solicitarea ordonantei de plata in conditiile art. 1014 si urm. CPC.
</div>

<table class="signatures">
  <tr>
    <td><b>Furnizor:</b><br>Alpha Servicii Comerciale SRL<br>Maria Ionescu, administrator</td>
    <td><b>Beneficiar:</b><br>Beta Distribution SRL<br>Ion Popescu, director general</td>
  </tr>
</table>
HTML);

echo "Done. Fixtures saved at: " . FIXTURES_DIR . "\n";
