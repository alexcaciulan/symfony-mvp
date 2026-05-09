<?php

namespace App\Service\Extraction;

use App\DTO\Extraction\ClaimExtraction;
use App\DTO\Extraction\CreditorExtraction;
use App\DTO\Extraction\DebtorExtraction;
use App\DTO\Extraction\ExtractedDocumentData;
use App\Entity\Document;
use App\Service\Court\LocalityNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Tier 1 extraction strategy — parses text-based PDFs (digital contracts,
 * electronic invoices) using {@link https://github.com/smalot/pdfparser}.
 *
 * Approach:
 *   1. supports() parses the PDF once and caches the text — extract() reuses it.
 *   2. Romanian-specific regex helpers find candidates for CUI/CNP/IBAN/amount/date,
 *      each with a checksum validator (CUI ANAF, CNP, IBAN mod 97).
 *   3. Contextual heuristics (50-char window after keywords) attribute each finding
 *      to creditor / debtor / dueDate. Confidence is 0.95 with context, 0.70 without.
 *   4. globalConfidence is the mean of non-zero per-field confidences (0.0 if none).
 *
 * No AI, no I/O beyond the PDF read. Cost: free, instant.
 */
final class PdfParserExtractionStrategy implements ExtractionStrategyInterface
{
    public const STRATEGY_KEY = 'pdf_parser';

    public const PRIORITY = 100;

    private const MIN_TEXT_LENGTH = 100;

    /** Max distance between a keyword and a numeric finding for a context-attributed match. */
    private const CONTEXT_WINDOW = 200;

    /** Max distance from a section keyword for section-based attribution (stronger signal). */
    private const SECTION_WINDOW = 500;

    private const CREDITOR_KEYWORDS = [
        'creditor', 'imprumutator', 'furnizor', 'locator', 'cedent', 'emitent',
    ];

    private const DEBTOR_KEYWORDS = [
        'debitor', 'imprumutat', 'client', 'locatar', 'cesionar', 'trasa',
    ];

    private const DUE_DATE_KEYWORDS = [
        'scadenta', 'data platii', 'termen plata', 'exigibilitate',
    ];

    private const AMOUNT_KEYWORDS = [
        'total', 'valoare', 'datorat', 'de plata', 'suma',
    ];

    private const CUI_WEIGHTS = [7, 5, 3, 2, 1, 7, 5, 3, 2];

    /**
     * Official CNP weights per OUG 97/2005 (Annex on the personal numeric code algorithm).
     * 12 weights are applied to the first 12 digits; the result mod 11 is the
     * check digit (or 1 if mod 11 == 10), compared against the 13th digit.
     */
    private const CNP_WEIGHTS = [2, 7, 9, 1, 4, 6, 3, 5, 8, 2, 7, 9];

    /** @var array<int, string> Cached normalized text by document id */
    private array $textCache = [];

    public function __construct(
        private string $uploadsDir,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function supports(Document $document): bool
    {
        if ($document->getMimeType() !== 'application/pdf') {
            return false;
        }

        $text = $this->loadText($document);

        return $text !== null && mb_strlen(trim($text)) >= self::MIN_TEXT_LENGTH;
    }

    public function extract(Document $document): ExtractedDocumentData
    {
        $rawText = $this->loadText($document) ?? '';
        $normalized = LocalityNormalizer::normalize($rawText) ?? '';

        $creditor = $this->buildCreditorExtraction($rawText, $normalized);
        $debtor = $this->buildDebtorExtraction($rawText, $normalized);
        $claim = $this->buildClaimExtraction($rawText, $normalized);

        $globalConfidence = $this->computeGlobalConfidence($creditor, $debtor, $claim);

        return new ExtractedDocumentData(
            sourceDocumentId: (int) $document->getId(),
            strategy: self::STRATEGY_KEY,
            globalConfidence: $globalConfidence,
            extractedAt: new \DateTimeImmutable(),
            creditor: $creditor,
            debtor: $debtor,
            claim: $claim,
        );
    }

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function isAiBacked(): bool
    {
        return false;
    }

    // ---------- text loading ----------

    private function loadText(Document $document): ?string
    {
        // spl_object_id avoids cache collisions for transient (id == null) entities
        // — the orchestrator always passes a persisted Document in production, but
        // direct invocations from tests / CLI may not, and `(int) null === 0`
        // would otherwise alias every unsaved Document to the same cache slot.
        $cacheKey = spl_object_id($document);
        if (array_key_exists($cacheKey, $this->textCache)) {
            return $this->textCache[$cacheKey];
        }

        $absolutePath = $this->absolutePath($document);

        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($absolutePath);
            $text = $pdf->getText();
            // Normalize whitespace — smalot inserts CR/LF and stray spaces from PDF layout.
            $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
            $this->textCache[$cacheKey] = $text;

            return $text;
        } catch (\Throwable $e) {
            $this->logger->debug('extraction.pdf_parser.parse_failed', [
                'documentId' => $document->getId(),
                'error' => $e->getMessage(),
            ]);
            $this->textCache[$cacheKey] = null;

            return null;
        }
    }

    private function absolutePath(Document $document): string
    {
        return rtrim($this->uploadsDir, '/') . '/' . ltrim($document->getStoredFilename(), '/');
    }

    // ---------- DTO builders ----------

    private function buildCreditorExtraction(string $rawText, string $normalized): ?CreditorExtraction
    {
        $cui = $this->extractCuiForRole($rawText, $normalized, 'creditor');
        $personalId = $this->extractCnpForRole($rawText, $normalized, 'creditor');
        $iban = $this->extractIban($rawText);

        if ($cui === null && $personalId === null && $iban === null) {
            return null;
        }

        $confidence = [];
        if ($cui !== null) {
            $confidence['cui'] = $cui['confidence'];
        }
        if ($personalId !== null) {
            $confidence['personalId'] = $personalId['confidence'];
        }
        if ($iban !== null) {
            $confidence['iban'] = $iban['confidence'];
        }

        return new CreditorExtraction(
            cui: $cui['value'] ?? null,
            personalId: $personalId['value'] ?? null,
            iban: $iban['value'] ?? null,
            confidencePerField: $confidence,
        );
    }

    private function buildDebtorExtraction(string $rawText, string $normalized): ?DebtorExtraction
    {
        $cui = $this->extractCuiForRole($rawText, $normalized, 'debtor');
        $personalId = $this->extractCnpForRole($rawText, $normalized, 'debtor');

        if ($cui === null && $personalId === null) {
            return null;
        }

        $confidence = [];
        if ($cui !== null) {
            $confidence['cui'] = $cui['confidence'];
        }
        if ($personalId !== null) {
            $confidence['personalId'] = $personalId['confidence'];
        }

        return new DebtorExtraction(
            cui: $cui['value'] ?? null,
            personalId: $personalId['value'] ?? null,
            confidencePerField: $confidence,
        );
    }

    private function buildClaimExtraction(string $rawText, string $normalized): ?ClaimExtraction
    {
        $amount = $this->extractAmount($rawText, $normalized);
        $dueDate = $this->extractDueDate($rawText, $normalized);

        if ($amount === null && $dueDate === null) {
            return null;
        }

        $confidence = [];
        if ($amount !== null) {
            $confidence['amount'] = $amount['confidence'];
        }
        if ($dueDate !== null) {
            $confidence['dueDate'] = $dueDate['confidence'];
        }

        return new ClaimExtraction(
            amount: $amount['value'] ?? null,
            currency: $amount !== null ? 'RON' : null,
            dueDate: $dueDate['value'] ?? null,
            confidencePerField: $confidence,
        );
    }

    // ---------- field extractors ----------

    /**
     * Returns the role assigned to a textual offset based on the most recent
     * preceding section keyword (creditor or debtor), or null if no keyword
     * appears within {@see self::SECTION_WINDOW} characters before the offset.
     *
     * Algorithm: Romanian legal documents tend to introduce parties with
     * "Creditor:"/"Furnizor:" then list their attributes (name, address, CUI,
     * IBAN), then introduce "Debitor:"/"Client:" with their own attributes.
     * The section-based attribution mirrors that structure: an extracted CUI
     * inherits the role of the closest preceding party keyword.
     */
    private function findSectionRole(string $normalized, int $offset): ?string
    {
        $bestOffset = -1;
        $bestRole = null;
        foreach (self::CREDITOR_KEYWORDS as $keyword) {
            $position = 0;
            while (($found = mb_stripos($normalized, $keyword, $position)) !== false && $found < $offset) {
                if ($found > $bestOffset) {
                    $bestOffset = $found;
                    $bestRole = 'creditor';
                }
                $position = $found + 1;
            }
        }
        foreach (self::DEBTOR_KEYWORDS as $keyword) {
            $position = 0;
            while (($found = mb_stripos($normalized, $keyword, $position)) !== false && $found < $offset) {
                if ($found > $bestOffset) {
                    $bestOffset = $found;
                    $bestRole = 'debtor';
                }
                $position = $found + 1;
            }
        }

        if ($bestRole === null || ($offset - $bestOffset) > self::SECTION_WINDOW) {
            return null;
        }

        return $bestRole;
    }

    /**
     * @return array{value: string, confidence: float}|null
     */
    private function extractCuiForRole(string $rawText, string $normalized, string $expectedRole): ?array
    {
        $matches = [];
        if (preg_match_all('/(?:RO\s?)?(\d{2,10})(?!\d)/i', $rawText, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        $best = null;
        foreach ($matches[1] as $match) {
            [$digits, $offset] = $match;
            if (!$this->validateCuiChecksum($digits)) {
                continue;
            }

            $sectionRole = $this->findSectionRole($normalized, $offset);
            if ($sectionRole !== $expectedRole) {
                continue;
            }

            $confidence = 0.95;
            if ($best === null || $confidence > $best['confidence']) {
                $best = ['value' => $digits, 'confidence' => $confidence];
            }
        }

        return $best;
    }

    /**
     * @return array{value: string, confidence: float}|null
     */
    private function extractCnpForRole(string $rawText, string $normalized, string $expectedRole): ?array
    {
        $matches = [];
        if (preg_match_all('/(?<!\d)(\d{13})(?!\d)/', $rawText, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        $best = null;
        foreach ($matches[1] as $match) {
            [$digits, $offset] = $match;
            if (!$this->validateCnpChecksum($digits)) {
                continue;
            }

            $sectionRole = $this->findSectionRole($normalized, $offset);
            if ($sectionRole !== $expectedRole) {
                continue;
            }

            $confidence = 0.95;
            if ($best === null || $confidence > $best['confidence']) {
                $best = ['value' => $digits, 'confidence' => $confidence];
            }
        }

        return $best;
    }

    /** @return array{value: string, confidence: float}|null */
    private function extractIban(string $rawText): ?array
    {
        // PDF text-extraction may split an IBAN across line wraps with stray spaces.
        // Search on a whitespace-stripped copy; the regex anchors on the canonical
        // RO IBAN shape (RO + 2 check digits + 4-letter bank code + 16 alphanumeric
        // BBAN — RO BBAN is alphanumeric, not digits-only). The mod-97 checksum
        // validates the candidate end-to-end.
        $compact = preg_replace('/\s+/', '', $rawText) ?? $rawText;

        $matches = [];
        if (preg_match('/RO\d{2}[A-Z]{4}[A-Z0-9]{16}/', $compact, $matches) !== 1) {
            return null;
        }

        $iban = $matches[0];
        if (!$this->validateIbanChecksum($iban)) {
            return null;
        }

        return ['value' => $iban, 'confidence' => 0.95];
    }

    /** @return array{value: float, confidence: float}|null */
    private function extractAmount(string $rawText, string $normalized): ?array
    {
        $matches = [];
        if (preg_match_all(
            '/([\d]{1,3}(?:[.\s][\d]{3})*(?:,\d{1,2})?|\d+(?:[.,]\d{1,2})?)\s*(?:RON|lei|LEI|Lei)\b/u',
            $rawText,
            $matches,
            PREG_OFFSET_CAPTURE,
        ) === false) {
            return null;
        }

        $best = null;
        foreach ($matches[1] as $match) {
            [$raw, $offset] = $match;
            $value = $this->normalizeRomanianNumber($raw);
            if ($value === null) {
                continue;
            }

            $contextDistance = $this->distanceToKeywords($normalized, $offset, self::AMOUNT_KEYWORDS);
            $confidence = ($contextDistance !== null && $contextDistance <= self::CONTEXT_WINDOW) ? 0.95 : 0.70;

            if ($best === null || $confidence > $best['confidence'] || ($confidence === $best['confidence'] && $value > $best['value'])) {
                $best = ['value' => $value, 'confidence' => $confidence];
            }
        }

        return $best;
    }

    /** @return array{value: \DateTimeImmutable, confidence: float}|null */
    private function extractDueDate(string $rawText, string $normalized): ?array
    {
        $matches = [];
        if (preg_match_all(
            '/(?<!\d)(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4})(?!\d)/',
            $rawText,
            $matches,
            PREG_OFFSET_CAPTURE,
        ) === false) {
            return null;
        }

        $best = null;
        foreach ($matches[0] as $i => $match) {
            [, $offset] = $match;
            $day = $matches[1][$i][0];
            $month = $matches[2][$i][0];
            $year = $matches[3][$i][0];

            if (mb_strlen($year) === 2) {
                $year = ((int) $year) < 50 ? '20' . $year : '19' . $year;
            }

            $date = \DateTimeImmutable::createFromFormat('!d.m.Y', sprintf('%d.%d.%s', (int) $day, (int) $month, $year));
            $errors = \DateTimeImmutable::getLastErrors();
            if (!$date instanceof \DateTimeImmutable || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                continue;
            }

            $contextDistance = $this->distanceToKeywords($normalized, $offset, self::DUE_DATE_KEYWORDS);
            if ($contextDistance === null || $contextDistance > self::CONTEXT_WINDOW) {
                continue;
            }

            $confidence = 0.95;
            if ($best === null || $confidence > $best['confidence']) {
                $best = ['value' => $date, 'confidence' => $confidence];
            }
        }

        return $best;
    }

    // ---------- checksum validators ----------

    private function validateCuiChecksum(string $digits): bool
    {
        $length = mb_strlen($digits);
        if ($length < 2 || $length > 10) {
            return false;
        }

        $checkDigit = (int) $digits[$length - 1];
        $body = substr($digits, 0, $length - 1);
        $padded = str_pad($body, 9, '0', STR_PAD_LEFT);

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $padded[$i]) * self::CUI_WEIGHTS[$i];
        }

        $computed = ($sum * 10) % 11;
        if ($computed === 10) {
            $computed = 0;
        }

        return $computed === $checkDigit;
    }

    private function validateCnpChecksum(string $digits): bool
    {
        if (mb_strlen($digits) !== 13) {
            return false;
        }

        // First digit (S) must be 1..9 (gender + century).
        $firstDigit = (int) $digits[0];
        if ($firstDigit < 1 || $firstDigit > 9) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += ((int) $digits[$i]) * self::CNP_WEIGHTS[$i];
        }

        $computed = $sum % 11;
        if ($computed === 10) {
            $computed = 1;
        }

        return $computed === (int) $digits[12];
    }

    private function validateIbanChecksum(string $iban): bool
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $iban)) {
            return false;
        }

        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        // Compute mod 97 in chunks (the numeric string is too large for native int).
        // Standard IBAN-validation trick: take 9 digits at a time, prefix with the
        // running remainder, take % 97, repeat.
        $remainder = 0;
        $length = strlen($numeric);
        for ($i = 0; $i < $length; $i += 7) {
            $chunk = (string) $remainder . substr($numeric, $i, 7);
            $remainder = ((int) $chunk) % 97;
        }

        return $remainder === 1;
    }

    // ---------- contextual helpers ----------

    /**
     * Distance (in characters) from $offset (in $rawText) to the closest occurrence of
     * any keyword in $normalizedText. Both texts must have the same length so offsets align —
     * this holds because LocalityNormalizer::normalize() preserves character positions
     * (mb_strtolower + Unicode FORM_D + combining-mark strip do not change byte length
     * for Latin alphabet + Romanian diacritics in a way that breaks ASCII offsets in
     * practice). For full correctness we re-search keywords on the normalized text and
     * compare byte offsets directly, which is robust because we only care about
     * within-window membership (≤ 50 chars), not pixel-perfect alignment.
     *
     * @param array<int, string> $keywords
     */
    private function distanceToKeywords(string $normalizedText, int $offset, array $keywords): ?int
    {
        $best = null;
        foreach ($keywords as $keyword) {
            $position = 0;
            while (($found = mb_stripos($normalizedText, $keyword, $position)) !== false) {
                $distance = abs($found - $offset);
                if ($best === null || $distance < $best) {
                    $best = $distance;
                }
                $position = $found + 1;
            }
        }

        return $best;
    }

    private function normalizeRomanianNumber(string $raw): ?float
    {
        // Romanian: thousands separator is "." or " ", decimal is ","
        // Examples: "1.000,50" → 1000.50, "5 000" → 5000, "5000.50" → 5000.50
        // Strip whitespace including non-breaking space (\xC2\xA0 in UTF-8).
        $cleaned = preg_replace('/[\s\xC2\xA0]/u', '', $raw) ?? $raw;

        if (str_contains($cleaned, ',')) {
            // Romanian format: dots are thousands, comma is decimal
            $cleaned = str_replace('.', '', $cleaned);
            $cleaned = str_replace(',', '.', $cleaned);
        } elseif (substr_count($cleaned, '.') > 1) {
            // Multiple dots → thousands separators only
            $cleaned = str_replace('.', '', $cleaned);
        }
        // Else: single dot — could be decimal (English) or thousands; we trust it as decimal.

        if (!is_numeric($cleaned)) {
            return null;
        }

        return (float) $cleaned;
    }

    private function computeGlobalConfidence(
        ?CreditorExtraction $creditor,
        ?DebtorExtraction $debtor,
        ?ClaimExtraction $claim,
    ): float {
        $values = [];
        foreach ([$creditor?->confidencePerField, $debtor?->confidencePerField, $claim?->confidencePerField] as $bucket) {
            if ($bucket === null) {
                continue;
            }
            foreach ($bucket as $value) {
                if ($value > 0.0) {
                    $values[] = $value;
                }
            }
        }

        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }
}
