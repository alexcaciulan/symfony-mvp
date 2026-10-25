<?php

declare(strict_types=1);

namespace App\Service\Pdf;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * First line for a PDF that comes from outside: size, real type (magic bytes and
 * finfo, never the extension), no encryption or active content, and a read-only
 * parse by Ghostscript in the sandbox. The file is moved under a generated name
 * and its bytes are never rewritten: a signed file must stay exactly as uploaded.
 * Accepting a file does not mean it is harmless, only that it could be processed.
 */
final class UploadedPdfValidator
{
    public const DEFAULT_MAX_BYTES = 20_971_520;
    /** Virtual memory cap for Ghostscript parsing an untrusted file. */
    private const GS_MEMORY_LIMIT_KB = 1_048_576;

    private const TOOL_KEYS = [
        'filing.package.error.encrypted' => 'filing.upload.error.encrypted',
        'filing.package.error.active_content' => 'filing.upload.error.active_content',
        'filing.package.error.corrupt' => 'filing.upload.error.unreadable',
        'filing.package.error.timeout' => 'filing.upload.error.timeout',
        'filing.package.error.tool_missing' => 'filing.upload.error.tool_missing',
    ];

    public function __construct(
        private readonly ProcessSandbox $sandbox,
        private readonly QpdfRunner $qpdf,
        private readonly int $filingSignedUploadMaxBytes = self::DEFAULT_MAX_BYTES,
    ) {}

    public function maxBytes(): int
    {
        return $this->filingSignedUploadMaxBytes;
    }

    /**
     * Runs inside the caller's sandbox session; the stored copy lives in its work dir.
     *
     * @throws UploadedPdfException
     */
    public function validate(UploadedFile $file): ValidatedPdf
    {
        if (!$file->isValid()) {
            throw new UploadedPdfException(in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'filing.upload.error.too_large'
                : 'filing.upload.error.failed');
        }
        if ((int) $file->getSize() > $this->filingSignedUploadMaxBytes) {
            throw new UploadedPdfException('filing.upload.error.too_large');
        }

        // The client's file name is never used for storage.
        $name = bin2hex(random_bytes(16)) . '.pdf';
        try {
            $moved = $file->move($this->sandbox->workDir(), $name);
        } catch (\Throwable $e) {
            throw new UploadedPdfException('filing.upload.error.failed', $e);
        }
        $path = $moved->getPathname();

        $head = (string) file_get_contents($path, false, null, 0, 1024);
        if (!str_contains($head, '%PDF-')) {
            throw new UploadedPdfException('filing.upload.error.not_pdf');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime !== 'application/pdf') {
            throw new UploadedPdfException('filing.upload.error.not_pdf');
        }

        try {
            $inspection = $this->qpdf->inspect($path);
            $this->parseWithGhostscript($path);
        } catch (PdfToolException $e) {
            throw new UploadedPdfException(self::TOOL_KEYS[$e->messageKey] ?? 'filing.upload.error.unreadable', $e);
        }

        return new ValidatedPdf($path, (int) filesize($path), (string) hash_file('sha256', $path), $inspection->pageCount);
    }

    /**
     * Interprets every page without producing output. Ghostscript repairs what it
     * can and still exits 0, so the messages for a file it could not open count too.
     */
    private function parseWithGhostscript(string $path): void
    {
        $process = $this->sandbox->run(
            ['gs', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dQUIET', '-sDEVICE=nullpage', $path],
            60.0,
            [0],
            self::GS_MEMORY_LIMIT_KB,
        );
        $output = $process->getOutput() . $process->getErrorOutput();
        if (str_contains($output, "Couldn't initialise file") || str_contains($output, 'No pages will be processed')) {
            throw new PdfToolException('Ghostscript could not open the file', 'filing.package.error.corrupt');
        }
    }
}
