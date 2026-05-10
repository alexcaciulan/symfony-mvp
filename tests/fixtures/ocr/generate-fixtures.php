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

echo "Generating OCR fixtures...\n";

// Fixture 1: clean image with controlled text — used to assert exact strings appear in OCR output.
renderImageMagickPng('clean-text.png', [
    'CUI RO15193236',
    'Total: 5000.00 RON',
    'Scadenta: 15.06.2026',
]);

// Fixture 2: a scanned PDF (image-based, no text layer) so the PDF-pipeline branch
// of TesseractOcrService is exercised end-to-end (pdftoppm → tesseract per page).
renderPdfFromImage('clean-text.png', 'scanned-document.pdf');

echo "Done. Fixtures saved at: " . FIXTURES_DIR . "\n";
