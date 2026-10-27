<?php

declare(strict_types=1);

namespace App\Service\Pdf;

/**
 * Turns an image annex (a photographed invoice, a scanned receipt) into one A4
 * page. ImageMagick downsizes and strips metadata, so GPS tags of a phone photo
 * do not travel to the court; dompdf lays it out on the page.
 */
final class ImageToPdfConverter
{
    /** A4 minus 10 mm margins, in millimetres. */
    private const BOX_WIDTH_MM = 190.0;
    private const BOX_HEIGHT_MM = 277.0;

    public function __construct(
        private readonly ProcessSandbox $sandbox,
        private readonly HtmlToPdfRenderer $renderer,
    ) {}

    public function convert(string $imagePath, string $outputPath): void
    {
        $jpeg = $this->sandbox->tempPath('jpg');
        // [0]: the first frame of an animated GIF or WebP; `>` only ever shrinks.
        $this->sandbox->run(['magick', $imagePath . '[0]', '-auto-orient', '-resize', '2000x2000>', '-quality', '80', '-strip', $jpeg], 60.0);

        $size = @getimagesize($jpeg);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new PdfToolException('Unreadable image', 'filing.package.error.corrupt');
        }

        $scale = min(self::BOX_WIDTH_MM / $size[0], self::BOX_HEIGHT_MM / $size[1]);

        // A data URI keeps dompdf away from the filesystem and the network.
        $this->renderer->render('pdf/annex_image_page.html.twig', [
            'image_data_uri' => 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($jpeg)),
            'width_mm' => round($size[0] * $scale, 2),
            'height_mm' => round($size[1] * $scale, 2),
        ], $outputPath);
    }
}
