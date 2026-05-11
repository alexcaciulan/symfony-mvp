<?php

namespace App\Service\Extraction;

use App\DTO\Extraction\ClaimExtraction;
use App\DTO\Extraction\CreditorExtraction;
use App\DTO\Extraction\DebtorExtraction;
use App\DTO\Extraction\ExtractedDocumentData;
use App\Entity\Document;
use App\Enum\PersonType;
use App\Service\Court\LocalityNormalizer;
use App\Util\PiiMasker;
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

    /**
     * Section keywords for party attribution. Covers the canonical Romanian
     * contract roles plus operational synonyms encountered in real documents:
     * service contracts ("Prestator"/"Beneficiar"), sale contracts ("Vanzator"/
     * "Cumparator"), construction/work ("Executant"/"Achizitor"), mandate
     * ("Mandant"/"Mandatar"). All entries are diacritic-stripped because
     * findSectionRole walks the LocalityNormalizer-normalized text.
     */
    private const CREDITOR_KEYWORDS = [
        'creditor', 'imprumutator', 'furnizor', 'locator', 'cedent', 'emitent',
        'prestator', 'executant', 'vanzator', 'mandant', 'producator', 'antreprenor',
    ];

    private const DEBTOR_KEYWORDS = [
        'debitor', 'imprumutat', 'client', 'locatar', 'cesionar', 'trasa',
        'beneficiar', 'cumparator', 'achizitor', 'mandatar',
    ];

    private const DUE_DATE_KEYWORDS = [
        'scadenta', 'data platii', 'termen plata', 'exigibilitate',
    ];

    private const AMOUNT_KEYWORDS = [
        'total', 'valoare', 'datorat', 'de plata', 'suma',
    ];

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
        $name = $this->extractNameForRole($rawText, $normalized, 'creditor');
        $onrc = $this->extractOnrcForRole($rawText, $normalized, 'creditor');
        $iban = $this->extractIbanForRole($rawText, $normalized, 'creditor');
        $email = $this->extractEmailForRole($rawText, $normalized, 'creditor');
        $phone = $this->extractPhoneForRole($rawText, $normalized, 'creditor');
        $administrator = $this->extractAdministratorForRole($rawText, $normalized, 'creditor');

        if ($cui === null && $personalId === null && $iban === null && $onrc === null
            && $name === null && $email === null && $phone === null && $administrator === null) {
            return null;
        }

        $confidence = [];
        foreach ([
            'cui' => $cui,
            'personalId' => $personalId,
            'name' => $name,
            'personType' => $name, // personType derived from name entity-suffix match
            'onrcNumber' => $onrc,
            'iban' => $iban,
            'email' => $email,
            'phone' => $phone,
            'legalRepresentative' => $administrator,
        ] as $field => $extracted) {
            if ($extracted !== null) {
                $confidence[$field] = $extracted['confidence'];
            }
        }

        return new CreditorExtraction(
            personType: $name['personType'] ?? null,
            name: $name['value'] ?? null,
            cui: $cui['value'] ?? null,
            isVatPayer: $cui['isVatPayer'] ?? null,
            personalId: $personalId['value'] ?? null,
            onrcNumber: $onrc['value'] ?? null,
            email: $email['value'] ?? null,
            phone: $phone['value'] ?? null,
            iban: $iban['value'] ?? null,
            legalRepresentative: $administrator['value'] ?? null,
            confidencePerField: $confidence,
        );
    }

    private function buildDebtorExtraction(string $rawText, string $normalized): ?DebtorExtraction
    {
        $cui = $this->extractCuiForRole($rawText, $normalized, 'debtor');
        $personalId = $this->extractCnpForRole($rawText, $normalized, 'debtor');
        $name = $this->extractNameForRole($rawText, $normalized, 'debtor');
        $onrc = $this->extractOnrcForRole($rawText, $normalized, 'debtor');
        $iban = $this->extractIbanForRole($rawText, $normalized, 'debtor');
        $email = $this->extractEmailForRole($rawText, $normalized, 'debtor');
        $phone = $this->extractPhoneForRole($rawText, $normalized, 'debtor');
        $administrator = $this->extractAdministratorForRole($rawText, $normalized, 'debtor');

        if ($cui === null && $personalId === null && $onrc === null && $iban === null
            && $name === null && $email === null && $phone === null && $administrator === null) {
            return null;
        }

        $confidence = [];
        foreach ([
            'cui' => $cui,
            'personalId' => $personalId,
            'name' => $name,
            'personType' => $name,
            'onrcNumber' => $onrc,
            'iban' => $iban,
            'email' => $email,
            'phone' => $phone,
            'administrator' => $administrator,
        ] as $field => $extracted) {
            if ($extracted !== null) {
                $confidence[$field] = $extracted['confidence'];
            }
        }

        return new DebtorExtraction(
            personType: $name['personType'] ?? null,
            name: $name['value'] ?? null,
            cui: $cui['value'] ?? null,
            isVatPayer: $cui['isVatPayer'] ?? null,
            personalId: $personalId['value'] ?? null,
            onrcNumber: $onrc['value'] ?? null,
            email: $email['value'] ?? null,
            phone: $phone['value'] ?? null,
            iban: $iban['value'] ?? null,
            administrator: $administrator['value'] ?? null,
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
        /** @var list<array{pos: int, role: 'creditor'|'debtor'}> $rawHits */
        $rawHits = [];
        // Anchored at the keyword start (left lookbehind: no word char before)
        // and at a word boundary after an optional RO definite-article suffix
        // (`-ul`, `-a`, `-i`, `-e`, `-le`). Catches articulated forms like
        // "Prestatorul" / "Beneficiarul" / "Imprumutatul" while preventing
        // shorter keywords from substring-matching longer ones.
        foreach (self::CREDITOR_KEYWORDS as $keyword) {
            foreach ($this->collectKeywordPositions($normalized, $keyword, $offset) as $pos) {
                $rawHits[] = ['pos' => $pos, 'role' => 'creditor'];
            }
        }
        foreach (self::DEBTOR_KEYWORDS as $keyword) {
            foreach ($this->collectKeywordPositions($normalized, $keyword, $offset) as $pos) {
                $rawHits[] = ['pos' => $pos, 'role' => 'debtor'];
            }
        }

        usort($rawHits, static fn ($a, $b) => $a['pos'] <=> $b['pos']);

        // Compound headers (e.g. "Prestatorul si Beneficiarul") introduce both
        // parties at once via a conjunction — the actual party-specific
        // sections that follow are numbered "1. ..." and "2. ...". We:
        //   1) Detect compound-header pairs and capture their roles in order
        //      (first role = the role of the numbered "1." section that
        //      follows; second = "2.").
        //   2) Remove the header keywords from the hit list so they don't
        //      misattribute the parties beneath.
        //   3) Apply a synthetic role for offsets that fall inside the
        //      "1. ..." / "2. ..." numbered ranges following the header.
        $compoundContext = $this->extractCompoundHeaderContext($rawHits, $normalized);
        $hits = $this->dropCompoundHeaders($rawHits, $normalized);

        $bestRole = null;
        $bestOffset = -1;
        foreach ($hits as $hit) {
            if ($hit['pos'] > $bestOffset) {
                $bestOffset = $hit['pos'];
                $bestRole = $hit['role'];
            }
        }

        if ($bestRole !== null && ($offset - $bestOffset) <= self::SECTION_WINDOW) {
            return $bestRole;
        }

        // Fallback: no keyword hit covers this offset. Check if a compound
        // header precedes the offset and the offset falls inside one of its
        // numbered child sections ("1. ..." → first role; "2. ..." → second).
        foreach ($compoundContext as $ctx) {
            if ($ctx['headerPos'] >= $offset) {
                continue;
            }
            // Limit the numbered-list scan to the section window — beyond that
            // the compound header's influence is too speculative.
            if (($offset - $ctx['headerPos']) > self::SECTION_WINDOW * 2) {
                continue;
            }
            $synthetic = $this->resolveNumberedSubsection($normalized, $ctx['headerPos'], $offset, $ctx['firstRole'], $ctx['secondRole']);
            if ($synthetic !== null) {
                return $synthetic;
            }
        }

        return null;
    }

    /**
     * Walks the keyword hits in order and returns the contexts of compound
     * headers (keyword + conjunction + opposite-keyword) along with the role
     * of each side, in their textual order. Used by findSectionRole to apply
     * synthetic role attribution to numbered subsections beneath the header.
     *
     * @param list<array{pos: int, role: 'creditor'|'debtor'}> $hits
     * @return list<array{headerPos: int, firstRole: 'creditor'|'debtor', secondRole: 'creditor'|'debtor'}>
     */
    private function extractCompoundHeaderContext(array $hits, string $haystack): array
    {
        $contexts = [];
        $count = count($hits);
        $compoundJoiner = '/^(?:ul|a|le|i|e)?\s*(?:si|și|şi|&|,|\/)\s*$/iu';
        for ($i = 0; $i < $count - 1; $i++) {
            if ($hits[$i]['role'] === $hits[$i + 1]['role']) {
                continue;
            }
            $startA = $hits[$i]['pos'];
            $startB = $hits[$i + 1]['pos'];
            $endA = $startA;
            $haystackLen = strlen($haystack);
            while ($endA < $haystackLen && $endA < $startB && !ctype_space($haystack[$endA])) {
                $endA++;
            }
            if ($endA >= $startB) {
                continue;
            }
            $between = trim(substr($haystack, $endA, $startB - $endA));
            if (preg_match($compoundJoiner, $between) === 1) {
                $contexts[] = [
                    'headerPos' => $startA,
                    'firstRole' => $hits[$i]['role'],
                    'secondRole' => $hits[$i + 1]['role'],
                ];
            }
        }

        return $contexts;
    }

    /**
     * Inside a compound-header context, finds the numbered subsection
     * ("1. ...", "2. ...") that contains the target offset and returns the
     * corresponding role. Returns null if no numbered marker precedes the
     * offset or the offset is past the third party (we only attribute the
     * first two — beyond that the contract is multi-party and falls outside
     * PdfParser's heuristic coverage).
     */
    private function resolveNumberedSubsection(
        string $normalized,
        int $headerPos,
        int $offset,
        string $firstRole,
        string $secondRole,
    ): ?string {
        // Find numbered list markers ("1. ", "2. ") after the header.
        // Note: $normalized is lowercased by LocalityNormalizer, so the letter
        // lookahead is case-insensitive (`\p{L}` matches any unicode letter
        // including a-z + diacritics).
        if (preg_match_all('/(?<!\d)([12])\.\s+(?=\p{L})/u', substr($normalized, $headerPos, $offset - $headerPos + 1), $m, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }
        $markers = [];
        foreach ($m[1] as $hit) {
            $markers[] = ['n' => (int) $hit[0], 'pos' => $headerPos + $hit[1]];
        }
        // Pick the latest marker preceding the target offset.
        $latest = null;
        foreach ($markers as $marker) {
            if ($marker['pos'] < $offset && ($latest === null || $marker['pos'] > $latest['pos'])) {
                $latest = $marker;
            }
        }
        if ($latest === null) {
            return null;
        }

        return $latest['n'] === 1 ? $firstRole : $secondRole;
    }

    /**
     * Returns all offsets where `$keyword` occurs as a whole word (with an
     * optional RO definite-article suffix), filtered to positions strictly
     * before `$cutoff`. Used by section-role attribution; see findSectionRole.
     *
     * @return list<int>
     */
    private function collectKeywordPositions(string $haystack, string $keyword, int $cutoff): array
    {
        $pattern = '/(?<!\w)' . preg_quote($keyword, '/') . '(?:ul|a|le|i|e)?\b(?!\w)/iu';
        $matches = [];
        if (preg_match_all($pattern, $haystack, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $positions = [];
        foreach ($matches[0] as [$_, $pos]) {
            if ($pos < $cutoff) {
                $positions[] = $pos;
            }
        }

        return $positions;
    }

    /**
     * @param list<array{pos: int, role: 'creditor'|'debtor'}> $hits
     * @return list<array{pos: int, role: 'creditor'|'debtor'}>
     */
    private function dropCompoundHeaders(array $hits, string $haystack): array
    {
        $drop = [];
        $count = count($hits);
        // "between" must be just an optional definite-article suffix on the
        // first keyword + a conjunction + (optional spaces). Any other content
        // (period/colon, free text, multiple words) means the two keywords are
        // logically separate sections, not a compound title.
        $compoundJoiner = '/^(?:ul|a|le|i|e)?\s*(?:si|și|şi|&|,|\/)\s*$/iu';
        for ($i = 0; $i < $count - 1; $i++) {
            if ($hits[$i]['role'] === $hits[$i + 1]['role']) {
                continue;
            }
            $startA = $hits[$i]['pos'];
            $startB = $hits[$i + 1]['pos'];
            // Compute end of keyword A — search for the next whitespace from
            // its start (the keyword itself is alpha, suffix is alpha).
            $endA = $startA;
            $haystackLen = strlen($haystack);
            while ($endA < $haystackLen && $endA < $startB && !ctype_space($haystack[$endA])) {
                $endA++;
            }
            if ($endA >= $startB) {
                continue;
            }
            $between = substr($haystack, $endA, $startB - $endA);
            if (preg_match($compoundJoiner, trim($between)) === 1) {
                $drop[$i] = true;
                $drop[$i + 1] = true;
            }
        }

        if ($drop === []) {
            return $hits;
        }

        $filtered = [];
        foreach ($hits as $idx => $hit) {
            if (!isset($drop[$idx])) {
                $filtered[] = $hit;
            }
        }

        return $filtered;
    }

    /**
     * @return array{value: string, confidence: float, isVatPayer: bool}|null
     */
    private function extractCuiForRole(string $rawText, string $normalized, string $expectedRole): ?array
    {
        // Two-alternative match:
        //   (1) RO prefix → any 2-10 digits (VAT payer per Codul Fiscal art. 316
        //       — registered in scopuri TVA; documents tag this with `RO`).
        //   (2) Standalone digits → require ≥ 6 digits to avoid catching
        //       legal-article numbers like "1014", "1015" from "art. 1014 CPC".
        //       This is the CIF (Cod de Identificare Fiscală) for entities NOT
        //       registered as VAT payers.
        // The `isVatPayer` flag in the returned array preserves this distinction
        // for downstream consumers (audit logs must not falsify VAT status).
        // Lookbehind `(?<![A-Z0-9])` prevents catching digit slices inside an
        // IBAN/account number: `7593840000` from `...AAAA1B31007593840000` would
        // otherwise checksum-validate (sum = 0, mod 11 = 0 = check digit).
        $matches = [];
        if (preg_match_all(
            '/(?<![A-Z0-9])(?:RO\s?(\d{2,10})|(\d{6,10}))(?!\d)/i',
            $rawText,
            $matches,
            PREG_OFFSET_CAPTURE,
        ) === false) {
            return null;
        }

        $best = null;
        $count = count($matches[0]);
        for ($i = 0; $i < $count; $i++) {
            $withRo = $matches[1][$i][0] ?? '';
            $standalone = $matches[2][$i][0] ?? '';
            $isVatPayer = $withRo !== '';
            $digits = $isVatPayer ? $withRo : $standalone;
            if ($digits === '') {
                continue;
            }
            $offset = $matches[0][$i][1];

            if (!PiiMasker::isValidCui($digits)) {
                continue;
            }

            $sectionRole = $this->findSectionRole($normalized, $offset);
            if ($sectionRole !== $expectedRole) {
                continue;
            }

            $confidence = 0.95;
            if ($best === null || $confidence > $best['confidence']) {
                $best = ['value' => $digits, 'confidence' => $confidence, 'isVatPayer' => $isVatPayer];
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
            if (!PiiMasker::isValidCnp($digits)) {
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
     *
     * Role-aware IBAN extractor. RO IBANs are canonically printed in 4-character
     * blocks separated by spaces (`RO49 AAAA 1B31 0075 9384 0000`) and PDF text
     * extraction tends to preserve those spaces — so the regex MUST accept
     * optional whitespace between groups. We capture greedily, then strip the
     * whitespace before checksum + length validation. The section role is
     * computed from the offset of the first character of the match in $rawText
     * (same surface findSectionRole() walks via $normalized).
     *
     * Anti-regression: an earlier revision matched only contiguous
     * `RO\d{2}[A-Z]{4}[A-Z0-9]{16}` which silently dropped every space-separated
     * IBAN in production contracts.
     */
    private function extractIbanForRole(string $rawText, string $normalized, string $expectedRole): ?array
    {
        $matches = [];
        if (preg_match_all('/RO\d{2}(?:\s*[A-Z]){4}(?:\s*[A-Z0-9]){16}/u', $rawText, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        $best = null;
        foreach ($matches[0] as $match) {
            [$raw, $offset] = $match;
            $iban = preg_replace('/\s+/', '', $raw) ?? $raw;
            if (strlen($iban) !== 24 || !$this->validateIbanChecksum($iban)) {
                continue;
            }
            $sectionRole = $this->findSectionRole($normalized, $offset);
            if ($sectionRole !== $expectedRole) {
                continue;
            }
            $confidence = 0.95;
            if ($best === null || $confidence > $best['confidence']) {
                $best = ['value' => $iban, 'confidence' => $confidence];
            }
        }

        return $best;
    }

    /**
     * @return array{value: string, confidence: float}|null
     *
     * Romanian Trade Registry number (Registrul Comerțului): `J/F + county/4 +
     * sequence/4-5 + year/4` per OUG 99/2006. Canonical form `J40/1234/2025`.
     * The leading letter is J (companies) or F (sole proprietorships).
     */
    private function extractOnrcForRole(string $rawText, string $normalized, string $expectedRole): ?array
    {
        $matches = [];
        if (preg_match_all(
            '/\b([JF])\s*(\d{1,5})\s*\/\s*(\d{1,5})\s*\/\s*(\d{4})\b/',
            $rawText,
            $matches,
            PREG_OFFSET_CAPTURE,
        ) === false) {
            return null;
        }

        $best = null;
        $count = count($matches[0]);
        for ($i = 0; $i < $count; $i++) {
            $offset = $matches[0][$i][1];
            $canonical = sprintf(
                '%s%s/%s/%s',
                strtoupper($matches[1][$i][0]),
                $matches[2][$i][0],
                $matches[3][$i][0],
                $matches[4][$i][0],
            );

            $sectionRole = $this->findSectionRole($normalized, $offset);
            if ($sectionRole !== $expectedRole) {
                continue;
            }

            $confidence = 0.9;
            if ($best === null || $confidence > $best['confidence']) {
                $best = ['value' => $canonical, 'confidence' => $confidence];
            }
        }

        return $best;
    }

    /**
     * @return array{value: string, confidence: float}|null
     *
     * Section-attributed email extraction. Uses a permissive regex (RFC 5322 is
     * overkill for legal documents) then validates with `filter_var` so we don't
     * leak garbage from OCR into the DTO. Confidence is lower than CUI/IBAN
     * because the format alone doesn't certify ownership of the address.
     */
    private function extractEmailForRole(string $rawText, string $normalized, string $expectedRole): ?array
    {
        $matches = [];
        if (preg_match_all('/\b[\w.+-]+@[\w.-]+\.[a-zA-Z]{2,}\b/u', $rawText, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        $best = null;
        foreach ($matches[0] as $match) {
            [$email, $offset] = $match;
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $sectionRole = $this->findSectionRole($normalized, $offset);
            if ($sectionRole !== $expectedRole) {
                continue;
            }
            $confidence = 0.85;
            if ($best === null || $confidence > $best['confidence']) {
                $best = ['value' => $email, 'confidence' => $confidence];
            }
        }

        return $best;
    }

    /**
     * @return array{value: string, confidence: float}|null
     *
     * Romanian phone numbers: landline (021/...), mobile (07x/...), or +40
     * international form. Accepts spaces/dashes between groups, normalizes to
     * compact form. Length check after stripping separators rejects too-short
     * (timestamps, postal codes) and too-long (long IDs) sequences.
     */
    private function extractPhoneForRole(string $rawText, string $normalized, string $expectedRole): ?array
    {
        $matches = [];
        if (preg_match_all(
            '/(?:\+?40[\s-]?|0)(?:[2-9]\d{1,2})[\s-]?\d{3}[\s-]?\d{3,4}/',
            $rawText,
            $matches,
            PREG_OFFSET_CAPTURE,
        ) === false) {
            return null;
        }

        $best = null;
        foreach ($matches[0] as $match) {
            [$raw, $offset] = $match;
            $compact = preg_replace('/[\s-]+/', '', $raw) ?? $raw;
            // RO national numbers: 10 digits starting with 0; international: 11
            // digits starting with 40. Anything else is noise.
            $digitsOnly = ltrim($compact, '+');
            if (!preg_match('/^(0\d{9}|40\d{9})$/', $digitsOnly)) {
                continue;
            }
            $sectionRole = $this->findSectionRole($normalized, $offset);
            if ($sectionRole !== $expectedRole) {
                continue;
            }
            $confidence = 0.8;
            if ($best === null || $confidence > $best['confidence']) {
                $best = ['value' => $compact, 'confidence' => $confidence];
            }
        }

        return $best;
    }

    /**
     * @return array{value: string, confidence: float, personType: ?PersonType}|null
     *
     * Romanian commercial entity name (PJ). Matches the canonical legal forms:
     * `S.R.L.`, `S.A.`, `S.N.C.`, `S.C.S.`, `P.F.A.`, `I.I.`, `I.F.`, with or
     * without the optional `S.C.` prefix. Captures the entity body + suffix
     * and normalizes punctuation. Section-attributed like the other extractors.
     *
     * Returns personType=PJ on success since every entity-suffix match is by
     * definition a legal person (PFA/II/IF are professional natural-person
     * forms but we treat them as PJ for OP procedure — they own a separate
     * legal identity for credit/debit attribution).
     *
     * Natural persons (pure PF without an entity form) are not extracted by
     * this regex — AI strategies (OcrText / AiVision) handle that fuzzier
     * case. PdfParser stays conservative to avoid grabbing street names or
     * party-keyword adjacents.
     */
    private function extractNameForRole(string $rawText, string $normalized, string $expectedRole): ?array
    {
        // Pattern strategy: limit the "core" (entity body) to 1-5 ALL-CAPITAL
        // tokens immediately preceding the legal-form suffix. Real Romanian
        // legal names use uppercase ("HN SERVICES DEVELOPMENT S.R.L.",
        // "TECHEDGE SOLUTIONS SRL", "ALPHA SA"). Restricting to uppercase
        // tokens avoids dragging in preceding noise like "CAP. II ...
        // PARTILE CONTRACTANTE Art.1 X S.R.L." or "BENEFICIAR si 2. Y SRL".
        //
        // Suffix capture handles all canonical commercial forms; trailing
        // optional `\b` anchor avoids partial matches like "SRL-uri".
        $regex = '/(?<![A-ZĂÂÎȘȚ\w])([A-ZĂÂÎȘȚ][A-ZĂÂÎȘȚ0-9.&\-]{1,}(?:\s+[A-ZĂÂÎȘȚ][A-ZĂÂÎȘȚ0-9.&\-]{1,}){0,5})\s+(S\.?\s*R\.?\s*L\.?|S\.?\s*A\.?|S\.?\s*N\.?\s*C\.?|S\.?\s*C\.?\s*S\.?|P\.?\s*F\.?\s*A\.?|I\.?\s*I\.?|I\.?\s*F\.?)\b/u';

        $matches = [];
        if (preg_match_all($regex, $rawText, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        $best = null;
        $count = count($matches[0]);
        $sectionKeywordsLower = array_map('mb_strtolower', [...self::CREDITOR_KEYWORDS, ...self::DEBTOR_KEYWORDS]);
        for ($i = 0; $i < $count; $i++) {
            $offset = $matches[0][$i][1];
            $core = trim($matches[1][$i][0]);
            $suffix = strtoupper(preg_replace('/[\s.]+/', '', $matches[2][$i][0]) ?? '');

            // Strip leading numbering ("2. TECHEDGE SOLUTIONS" → "TECHEDGE SOLUTIONS").
            $core = preg_replace('/^\d+\.\s*/', '', $core) ?? $core;
            // Reject if any token in the core EXACTLY equals a section keyword
            // (e.g. "BENEFICIAR si TECHEDGE" — the "BENEFICIAR" token leaks the
            // section label into the captured name). Exact-equality check (not
            // prefix) so a real company "LOCATOR GRUP SRL" or "BENEFICIAR
            // HOLDING SRL" isn't filtered: the legal name happens to start
            // with a role keyword but is otherwise a distinct legal person.
            $tokensLower = array_map('mb_strtolower', preg_split('/\s+/', $core) ?: []);
            $hasKeywordContamination = false;
            foreach ($tokensLower as $token) {
                if (in_array($token, $sectionKeywordsLower, true)) {
                    $hasKeywordContamination = true;
                    break;
                }
            }
            if ($hasKeywordContamination) {
                continue;
            }
            // Reject obvious legal-document artifacts.
            if (preg_match('/^(?:CAP|ART|CAPITOLUL|ARTICOL)/i', $core) === 1) {
                continue;
            }

            $fullName = $core . ' ' . $suffix;

            $sectionRole = $this->findSectionRole($normalized, $offset);
            if ($sectionRole !== $expectedRole) {
                continue;
            }

            // Earliest match in section wins — Romanian documents introduce
            // the legal name immediately after the party keyword. Lower offset
            // = closer to the keyword.
            $confidence = 0.88;
            if ($best === null || $offset < $best['offset']) {
                $best = ['value' => $fullName, 'confidence' => $confidence, 'personType' => PersonType::PJ, 'offset' => $offset];
            }
        }

        if ($best !== null) {
            unset($best['offset']);
        }

        return $best;
    }

    /**
     * @return array{value: string, confidence: float}|null
     *
     * Keyword-based extraction for the named representative (administrator /
     * reprezentant legal / director general) of a PJ party. Matches the keyword
     * followed by an optional separator and a name pattern (2-4 capitalized
     * tokens). Confidence is intentionally lower than CUI/IBAN/ONRC because
     * the name pattern is fuzzy and may match street names ("Str. Popescu Ion").
     */
    private function extractAdministratorForRole(string $rawText, string $normalized, string $expectedRole): ?array
    {
        $matches = [];
        // Capture: keyword + optional separators + name pattern (2-4 capitalized
        // tokens). Reject if the FIRST token after the keyword is a connector
        // word ("sau", "si", "și", "ori") — common in legal boilerplate like
        // "reprezentant legal sau imputernicit, Y POPESCU" — we want the actual
        // name, not the connector.
        if (preg_match_all(
            '/(?:administrator|reprezentant\s+legal|director\s+general)\b[\s:,.\-]*([A-ZĂÂÎȘȚ][\wĂÂÎȘȚăâîșț.\-]+(?:\s+[A-ZĂÂÎȘȚ][\wĂÂÎȘȚăâîșț.\-]+){1,3})/iu',
            $rawText,
            $matches,
            PREG_OFFSET_CAPTURE,
        ) === false) {
            return null;
        }

        $best = null;
        $count = count($matches[0]);
        $stopwords = ['sau', 'si', 'și', 'ori', 'imputernicit', 'imputernicita', 'imputernicitul'];
        for ($i = 0; $i < $count; $i++) {
            $offset = $matches[0][$i][1];
            $name = trim($matches[1][$i][0]);
            if ($name === '') {
                continue;
            }
            // Drop connector-prefixed matches.
            $firstToken = mb_strtolower(preg_split('/\s+/', $name)[0] ?? '');
            if (in_array($firstToken, $stopwords, true)) {
                continue;
            }
            // Trim trailing "denumit"/"denumita" / "in" artefacts that the
            // greedy capture sometimes grabs from "... POPESCU ION, denumit in
            // continuare ...". Allow up to 3 trailing chars (`?`, `.`, U+FFFD
            // replacement char from corrupt PDF encoding) on the keyword
            // because Smalot's text extraction can emit garbage adjacent to
            // diacritic-stripped words.
            $name = preg_replace('/\s+(?:denumit|denumita|denumitul|denumiti)\S{0,3}\b.*$/iu', '', $name) ?? $name;
            $name = trim($name);
            $tokenCount = count(preg_split('/\s+/', $name) ?: []);
            if ($tokenCount < 2) {
                continue;
            }

            $sectionRole = $this->findSectionRole($normalized, $offset);
            if ($sectionRole !== $expectedRole) {
                continue;
            }
            $confidence = 0.65;
            if ($best === null || $confidence > $best['confidence']) {
                $best = ['value' => $name, 'confidence' => $confidence];
            }
        }

        return $best;
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

            // Directional distance: only accept dates that appear AFTER a
            // due-date keyword (e.g. "Scadenta: 31.05.2026"). Dates that
            // precede the keyword (e.g. "01.05.2026 ... Scadenta:") are
            // invoice-emission or other context, not the due date.
            $contextDistance = $this->distanceAfterKeyword($normalized, $offset, self::DUE_DATE_KEYWORDS);
            if ($contextDistance === null || $contextDistance > self::CONTEXT_WINDOW) {
                continue;
            }

            // Closer keyword = stronger signal. Pick the date with the smallest
            // (positive) distance to its due-date keyword, not the first one seen.
            if ($best === null || $contextDistance < ($best['distance'] ?? PHP_INT_MAX)) {
                $best = ['value' => $date, 'confidence' => 0.95, 'distance' => $contextDistance];
            }
        }

        if ($best !== null) {
            unset($best['distance']);
        }

        return $best;
    }

    // ---------- checksum validators ----------
    // CUI + CNP checksums delegated to App\Util\PiiMasker (single source of
    // truth, also used by 2.5.7 OcrTextExtractionStrategy for prompt masking).

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

    /**
     * Like {@see self::distanceToKeywords()} but only returns the (positive) distance
     * when the keyword appears *before* the offset. Used for fields whose meaning
     * is direction-sensitive (e.g. a due date is the date that follows "Scadenta:",
     * not the one that precedes it).
     *
     * @param array<int, string> $keywords
     */
    private function distanceAfterKeyword(string $normalizedText, int $offset, array $keywords): ?int
    {
        $best = null;
        foreach ($keywords as $keyword) {
            $position = 0;
            while (($found = mb_stripos($normalizedText, $keyword, $position)) !== false) {
                if ($found <= $offset) {
                    $distance = $offset - $found;
                    if ($best === null || $distance < $best) {
                        $best = $distance;
                    }
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
        // Coverage-weighted confidence: divide the SUM of per-field confidences
        // by the TOTAL expected-field count across all sections — not by the
        // count of fields we managed to extract.
        //
        // Rationale: the cascade short-circuits when globalConfidence ≥
        // threshold. Under the older mean-over-extracted formula, extracting
        // 3 fields at confidence 0.95 each gave globalConfidence = 0.95 — even
        // though 17/20 fields remained empty — and the cascade would stop,
        // leaving the lawyer to fill 17 fields manually.
        //
        // The new formula answers the right question: "how much of the wizard
        // form did this strategy auto-fill?" 3 fields × 0.95 / 20 expected =
        // 0.14, which lets the cascade move on to a higher-coverage AI tier.
        //
        // EXPECTED_TOTAL_FIELDS aligns with the per-section counts shown in
        // the wizard sidecard (Step1CreditorData has 10 prefillable fields,
        // Step2DebtorEntry has 10, Step3ClaimData has 5 — total 25).
        return \App\Service\Extraction\CoverageConfidenceCalculator::compute(
            $creditor?->confidencePerField,
            $debtor?->confidencePerField,
            $claim?->confidencePerField,
        );
    }
}
