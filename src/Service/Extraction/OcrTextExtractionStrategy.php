<?php

namespace App\Service\Extraction;

use App\DTO\Extraction\ClaimExtraction;
use App\DTO\Extraction\CreditorExtraction;
use App\DTO\Extraction\DebtorExtraction;
use App\DTO\Extraction\ExtractedDocumentData;
use App\Entity\Document;
use App\Enum\LegalGroundCategory;
use App\Enum\PersonType;
use App\Service\AuditLogService;
use App\Service\Llm\LlmClientInterface;
use App\Service\Llm\LlmException;
use App\Service\Ocr\OcrException;
use App\Service\Ocr\OcrServiceInterface;
use App\Util\PiiMasker;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Treapta 2 din cascada de extracție (priority 70). Pentru documente unde
 * PdfParserExtractionStrategy (priority 100) nu se aplică — imagini scanate
 * sau PDF-uri fără strat text — combină Tesseract OCR (local, free) cu un
 * apel AI de tip text-completion via {@see LlmClientInterface} (Anthropic
 * Claude la MVP, swap-able post-MVP via interface).
 *
 * Cost mediu ~$0.002/doc. Fallback: dacă OCR returnează text prea slab,
 * sau apiKey lipsește operațional, sau LLM-ul eșuează, returnăm un DTO cu
 * `globalConfidence = 0.0` — orchestrator-ul {@see DataExtractionService}
 * preia automat (treapta 3 AiVision la 2.5.8 sau Stub).
 *
 * GDPR (art. 25 — data minimisation): CNP-urile și IBAN-urile detectate în
 * textul OCR sunt mascate ÎNAINTE de a fi trimise la Anthropic, prin
 * {@see PiiMasker::buildCnpMap()} + {@see PiiMasker::buildIbanMap()}, cu
 * round-trip garantat la restore în răspunsul AI structurat. AuditLog
 * persistă DOAR metadata (tokeni, finishReason, hash răspuns) — niciodată
 * conținut prompt sau text OCR brut.
 *
 * Skip pe `LOCAL_ONLY` mode e gestionat de orchestrator via {@see self::isAiBacked()}
 * — strategy-ul însuși nu cunoaște modul curent. Skip pe apiKey gol (D1
 * 2026-05-10): logăm WARNING + return zero-confidence; operatorul corectează
 * config-ul.
 */
final class OcrTextExtractionStrategy implements ExtractionStrategyInterface
{
    public const STRATEGY_KEY = 'ocr_text';

    public const PRIORITY = 70;

    private const SUPPORTED_IMAGE_MIME = ['image/jpeg', 'image/jpg', 'image/png'];

    private const PDF_MIME = 'application/pdf';

    /** Same threshold as {@see PdfParserExtractionStrategy::MIN_TEXT_LENGTH}. */
    private const MIN_PDF_TEXT_FOR_PARSER = 100;

    /** Below this Tesseract average confidence, OCR is unreliable enough to skip AI. */
    private const MIN_OCR_CONFIDENCE = 0.5;

    /** Below this OCR-text length, the prompt would be too short to extract anything useful. */
    private const MIN_OCR_TEXT_LENGTH = 200;

    /** Output budget for the structured JSON response — sized for full creditor + debtor + claim. */
    private const MAX_TOKENS = 2048;

    public function __construct(
        private readonly OcrServiceInterface $ocrService,
        private readonly LlmClientInterface $llmClient,
        private readonly AuditLogService $auditLogService,
        private readonly RateLimiterFactory $extractionAiTextLimiter,
        private readonly string $uploadsDir,
        private readonly string $anthropicApiKey,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function isAiBacked(): bool
    {
        return true;
    }

    public function supports(Document $document): bool
    {
        $mime = strtolower($document->getMimeType() ?? '');
        if (in_array($mime, self::SUPPORTED_IMAGE_MIME, true)) {
            return true;
        }
        if ($mime !== self::PDF_MIME) {
            return false;
        }

        // PDF: only if the PDF parser can't recover enough text on its own —
        // otherwise PdfParserExtractionStrategy (priority 100) handles it.
        return $this->isPdfWithoutTextLayer($this->absolutePath($document));
    }

    public function extract(Document $document): ExtractedDocumentData
    {
        // 1. OCR — local Tesseract pipeline.
        try {
            $ocrResult = $this->ocrService->extractText($this->absolutePath($document));
        } catch (OcrException $e) {
            $this->logger->warning('extraction.ocr_text.ocr_failed', [
                'documentId' => $document->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->zeroConfidence($document);
        }

        // 2. Quality gate — signal cascade rather than pollute downstream with low-grade text.
        if ($ocrResult->confidence < self::MIN_OCR_CONFIDENCE
            || mb_strlen($ocrResult->text) < self::MIN_OCR_TEXT_LENGTH) {
            $this->logger->info('extraction.ocr_text.quality_too_low', [
                'documentId' => $document->getId(),
                'ocrConfidence' => $ocrResult->confidence,
                'textLength' => mb_strlen($ocrResult->text),
            ]);

            return $this->zeroConfidence($document, $ocrResult->text);
        }

        // 3. apiKey absent operationally (D1 2026-05-10) — skip strategy. Cascade
        // takes over: AiVision (2.5.8) likely also skipped → Stub. Operator sees
        // the warning in logs and fixes the config.
        if ($this->anthropicApiKey === '') {
            $this->logger->warning('extraction.ocr_text.api_key_missing', [
                'documentId' => $document->getId(),
            ]);

            return $this->zeroConfidence($document, $ocrResult->text);
        }

        // 4. Mask PII before the prompt leaves the server boundary (GDPR art. 25).
        $cnpMap = PiiMasker::buildCnpMap($ocrResult->text);
        $ibanMap = PiiMasker::buildIbanMap($ocrResult->text);
        $maskedText = $this->applyPiiMaps($ocrResult->text, $cnpMap, $ibanMap);

        // 5. Per-user rate limit. Bucket key = userId so one tenant can't drain
        // the daily allowance for everyone (sliding_window 200/day). Prefer
        // LegalCase->getUser() when the case is attached (post-wizard flow);
        // fall back to Document.uploadedBy for Pas 3.0 wizard step 0 uploads
        // where the case doesn't exist yet.
        $owner = $document->getLegalCase()?->getUser() ?? $document->getUploadedBy();
        $userId = (string) $owner->getId();
        if (!$this->extractionAiTextLimiter->create($userId)->consume(1)->isAccepted()) {
            $this->logger->warning('extraction.ocr_text.rate_limit_exhausted', ['userId' => $userId]);

            return $this->zeroConfidence($document, $ocrResult->text);
        }

        // 6. AI call — interface-typed, never references AnthropicApiClient directly.
        try {
            $response = $this->llmClient->complete(
                messages: $this->buildMessages($maskedText),
                maxTokens: self::MAX_TOKENS,
            );
        } catch (LlmException $e) {
            // Class + code only — message may include URL/headers per Symfony conventions.
            $this->logger->error('extraction.ocr_text.llm_failed', [
                'documentId' => $document->getId(),
                'exceptionClass' => $e::class,
                'code' => $e->getCode(),
            ]);

            return $this->zeroConfidence($document, $ocrResult->text);
        }

        // 7. Parse + restore PII in the structured response.
        $parsed = $this->parseAiResponse($response->content);
        if ($parsed === null) {
            $this->logger->warning('extraction.ocr_text.malformed_ai_response', [
                'documentId' => $document->getId(),
            ]);

            return $this->zeroConfidence($document, $ocrResult->text);
        }

        $sourceDocumentId = (int) $document->getId();
        $creditor = $this->buildCreditorFromAi($parsed['creditor'] ?? null, $cnpMap, $ibanMap);
        $debtor = $this->buildDebtorFromAi($parsed['debtor'] ?? null, $cnpMap, $ibanMap);
        $claim = $this->buildClaimFromAi($parsed['claim'] ?? null);
        $globalConfidence = $this->resolveGlobalConfidence($parsed, $creditor, $debtor, $claim);

        // 8. Audit — metadata only, defense-in-depth maskCnpInArray on the persisted payload.
        $this->auditLogService->log(
            action: 'AI_EXTRACTION_COMPLETED',
            entityType: 'Document',
            entityId: (string) $sourceDocumentId,
            newData: PiiMasker::maskCnpInArray([
                'strategy' => self::STRATEGY_KEY,
                'tokensIn' => $response->tokensIn,
                'tokensOut' => $response->tokensOut,
                'finishReason' => $response->finishReason->value,
                // 32 hex chars (128 bits) of SHA-256 — birthday-bound collision
                // after ~2^64 operations, comfortable for evidentiary use should
                // an extraction dispute reach the audit trail. Aligned with
                // AiVisionExtractionStrategy at Pas 2.5.8 W1 legal fix; previously
                // 16 hex was sufficient for cost telemetry alone but would risk
                // collision in long-lived multi-tenant audit logs.
                'responseHash' => substr(hash('sha256', $response->content), 0, 32),
                'globalConfidence' => $globalConfidence,
            ]),
            category: AuditLogService::CATEGORY_AI_EXTRACTION,
        );

        return new ExtractedDocumentData(
            sourceDocumentId: $sourceDocumentId,
            strategy: self::STRATEGY_KEY,
            globalConfidence: $globalConfidence,
            extractedAt: new \DateTimeImmutable(),
            creditor: $creditor,
            debtor: $debtor,
            claim: $claim,
            // GDPR art. 5(1)(c) — data minimisation. The DTO is persisted as
            // `Document.extractedData` JSON, so any CNP/IBAN left in `rawOcrText`
            // would land in the database verbatim. Mask both before persistence;
            // structured PII still lives in `creditor`/`debtor`/`claim` for the
            // wizard, raw form is no longer needed downstream.
            rawOcrText: PiiMasker::maskIban(PiiMasker::maskCnp($ocrResult->text)),
        );
    }

    // ---------- helpers ----------

    private function absolutePath(Document $document): string
    {
        return rtrim($this->uploadsDir, '/') . '/' . ltrim($document->getStoredFilename(), '/');
    }

    private function isPdfWithoutTextLayer(string $absolutePath): bool
    {
        try {
            $parser = new PdfParser();
            $text = $parser->parseFile($absolutePath)->getText();
            $normalized = preg_replace('/\s+/u', ' ', $text) ?? '';

            return mb_strlen(trim($normalized)) < self::MIN_PDF_TEXT_FOR_PARSER;
        } catch (\Throwable $e) {
            // PDF couldn't be parsed at all → very likely a scan, OCR is the right path.
            return true;
        }
    }

    /**
     * @param array<string, string> $cnpMap
     * @param array<string, string> $ibanMap
     */
    private function applyPiiMaps(string $text, array $cnpMap, array $ibanMap): string
    {
        $result = $text;
        foreach ($cnpMap as $original => $placeholder) {
            $result = str_replace($original, $placeholder, $result);
        }
        foreach ($ibanMap as $original => $placeholder) {
            $result = str_replace($original, $placeholder, $result);
        }

        return $result;
    }

    /**
     * @return array<int, array{role: 'system'|'user', content: string}>
     */
    private function buildMessages(string $maskedOcrText): array
    {
        $system = 'Ești un expert în extracție de date din documente juridice și comerciale '
            . 'românești (facturi, contracte, recunoașteri de datorie). Returnează DOAR JSON pur, '
            . 'fără text liber sau markdown. Schema strictă. Câmpuri opționale dacă lipsesc din document.';

        $legalGrounds = implode('|', array_map(static fn (LegalGroundCategory $c) => $c->value, LegalGroundCategory::cases()));

        $user = <<<PROMPT
Analizează textul OCR al documentului și extrage datele structurate.

CONTEXT JURIDIC: documentul stă la baza unei cereri de ordonanță de plată
(CPC art. 1013-1024). Ai grijă la rolurile părților (creditor = cel ce
pretinde plata; debitor = cel ce datorează).

NOTĂ MASCARE: CNP-urile au fost mascate cu placeholder începând cu `***-***-`
și IBAN-urile cu placeholder `IBAN_PLACEHOLDER_xxx`. Folosește placeholder-ul
EXACT în răspuns — îl voi restaura ulterior.

TEXT OCR:
---
{$maskedOcrText}
---

Returnează JSON cu această schemă (toate câmpurile opționale dacă nu apar):
{
  "creditor": {
    "personType": "PJ"|"PF",
    "name": "...",
    "cui": "doar cifre, fără prefix RO",
    "isVatPayer": true|false,
    "personalId": "***-***-XXXX placeholder",
    "address": "...",
    "iban": "IBAN_PLACEHOLDER_xxx",
    "legalRepresentative": "...",
    "confidencePerField": {"name": 0.95, "cui": 0.99}
  },
  "debtor": {
    "personType": "PJ"|"PF",
    "name": "...",
    "cui": "...",
    "isVatPayer": true|false,
    "personalId": "***-***-XXXX placeholder",
    "address": "...",
    "confidencePerField": {}
  },
  "claim": {
    "amount": 5000.50,
    "currency": "RON"|"EUR"|"USD",
    "dueDate": "YYYY-MM-DD",
    "legalGround": "{$legalGrounds}",
    "description": "...",
    "confidencePerField": {}
  },
  "globalConfidence": 0.85
}

Confidence per câmp: 0..1, reflectă cât de sigur ești pe baza textului OCR
(text clar = 0.95+; ambiguu sau OCR cu erori = 0.5-0.7; ghicit din context = 0.3-0.5).
PROMPT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * Tolerant JSON decoder — strips Markdown code fences and any prose surrounding
     * the JSON body. Returns null when nothing JSON-shaped can be recovered.
     *
     * @return array<string, mixed>|null
     */
    private function parseAiResponse(string $content): ?array
    {
        $trimmed = trim($content);

        // Strip ```json ... ``` or ``` ... ``` code fences if present.
        if (preg_match('/^```(?:json)?\s*(.+?)\s*```$/s', $trimmed, $m)) {
            $trimmed = trim($m[1]);
        }

        // If Claude wrapped JSON in narrative ("Iată JSON-ul: { ... } sper că e util"),
        // grab the substring between the first `{` and the last `}` and try that.
        if (!str_starts_with($trimmed, '{') || !str_ends_with($trimmed, '}')) {
            $start = strpos($trimmed, '{');
            $end = strrpos($trimmed, '}');
            if ($start === false || $end === false || $end <= $start) {
                return null;
            }
            $trimmed = substr($trimmed, $start, $end - $start + 1);
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return null;
        }

        // Minimum schema gate: at least one of creditor/debtor/claim present
        // (otherwise the response is meaningless and we'd be surfacing an empty
        // DTO with a fabricated confidence).
        if (!isset($decoded['creditor']) && !isset($decoded['debtor']) && !isset($decoded['claim'])) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $raw
     * @param array<string, string> $cnpMap
     * @param array<string, string> $ibanMap
     */
    private function buildCreditorFromAi(?array $raw, array $cnpMap, array $ibanMap): ?CreditorExtraction
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        return new CreditorExtraction(
            personType: $this->coercePersonType($raw['personType'] ?? null),
            name: $this->coerceString($raw['name'] ?? null),
            cui: $this->coerceString($raw['cui'] ?? null),
            isVatPayer: $this->coerceNullableBool($raw['isVatPayer'] ?? null),
            personalId: $this->restoreString($raw['personalId'] ?? null, $cnpMap, []),
            address: $this->coerceString($raw['address'] ?? null),
            iban: $this->restoreString($raw['iban'] ?? null, [], $ibanMap),
            legalRepresentative: $this->coerceString($raw['legalRepresentative'] ?? null),
            confidencePerField: $this->coerceConfidenceMap($raw['confidencePerField'] ?? null),
        );
    }

    /**
     * @param array<string, mixed>|null $raw
     * @param array<string, string> $cnpMap
     * @param array<string, string> $ibanMap
     */
    private function buildDebtorFromAi(?array $raw, array $cnpMap, array $ibanMap): ?DebtorExtraction
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        return new DebtorExtraction(
            personType: $this->coercePersonType($raw['personType'] ?? null),
            name: $this->coerceString($raw['name'] ?? null),
            cui: $this->coerceString($raw['cui'] ?? null),
            isVatPayer: $this->coerceNullableBool($raw['isVatPayer'] ?? null),
            personalId: $this->restoreString($raw['personalId'] ?? null, $cnpMap, []),
            address: $this->coerceString($raw['address'] ?? null),
            confidencePerField: $this->coerceConfidenceMap($raw['confidencePerField'] ?? null),
        );
    }

    /**
     * @param array<string, mixed>|null $raw
     */
    private function buildClaimFromAi(?array $raw): ?ClaimExtraction
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        $amountRaw = $raw['amount'] ?? null;
        $amount = is_numeric($amountRaw) ? (float) $amountRaw : null;

        $dueDate = null;
        if (is_string($raw['dueDate'] ?? null) && $raw['dueDate'] !== '') {
            // The leading `!` resets time-of-day to 00:00:00 — dueDate is a calendar
            // date, not a moment, so we don't want createFromFormat seeding it with
            // the current wall-clock time (which would defeat equality assertions
            // against `new \DateTimeImmutable('2026-06-15')`).
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw['dueDate']);
            $dueDate = $dt instanceof \DateTimeImmutable ? $dt : null;
        }

        $legalGround = null;
        if (is_string($raw['legalGround'] ?? null) && $raw['legalGround'] !== '') {
            $legalGround = LegalGroundCategory::tryFrom($raw['legalGround']);
        }

        return new ClaimExtraction(
            amount: $amount,
            currency: $this->coerceString($raw['currency'] ?? null),
            dueDate: $dueDate,
            legalGround: $legalGround,
            description: $this->coerceString($raw['description'] ?? null),
            confidencePerField: $this->coerceConfidenceMap($raw['confidencePerField'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $parsed
     */
    private function resolveGlobalConfidence(
        array $parsed,
        ?CreditorExtraction $creditor,
        ?DebtorExtraction $debtor,
        ?ClaimExtraction $claim,
    ): float {
        $explicit = $parsed['globalConfidence'] ?? null;
        if (is_numeric($explicit)) {
            return max(0.0, min(1.0, (float) $explicit));
        }

        // Fall back to the average of all per-field confidence values across sub-DTOs.
        $values = [];
        foreach ([$creditor?->confidencePerField, $debtor?->confidencePerField, $claim?->confidencePerField] as $map) {
            if (!is_array($map)) {
                continue;
            }
            foreach ($map as $score) {
                if (is_numeric($score)) {
                    $values[] = max(0.0, min(1.0, (float) $score));
                }
            }
        }

        if ($values === []) {
            // We have *something* (one of the sub-DTOs is non-null per parseAiResponse
            // gating) but no usable confidence info — give it the default threshold so
            // the cascade can still short-circuit if no higher-confidence strategy exists.
            return DataExtractionService::DEFAULT_CONFIDENCE_THRESHOLD;
        }

        return array_sum($values) / count($values);
    }

    private function coerceString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function coerceNullableBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return null;
    }

    private function coercePersonType(mixed $value): ?PersonType
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return PersonType::tryFrom($value);
    }

    /**
     * @param mixed $value
     * @return array<string, float>
     */
    private function coerceConfidenceMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $key => $score) {
            if (is_string($key) && is_numeric($score)) {
                $result[$key] = max(0.0, min(1.0, (float) $score));
            }
        }

        return $result;
    }

    /**
     * Applies CNP and IBAN restoration to a single string field returned by the AI.
     * Both maps are optional so the helper is reusable for fields where only one
     * applies (e.g. `personalId` only needs CNP, `iban` only needs IBAN).
     *
     * @param array<string, string> $cnpMap
     * @param array<string, string> $ibanMap
     */
    private function restoreString(mixed $value, array $cnpMap, array $ibanMap): ?string
    {
        $coerced = $this->coerceString($value);
        if ($coerced === null) {
            return null;
        }
        if ($cnpMap !== []) {
            $coerced = PiiMasker::restoreCnp($coerced, $cnpMap);
        }
        if ($ibanMap !== []) {
            $coerced = PiiMasker::restoreIban($coerced, $ibanMap);
        }

        return $coerced;
    }

    private function zeroConfidence(Document $document, ?string $rawOcrText = null): ExtractedDocumentData
    {
        return new ExtractedDocumentData(
            sourceDocumentId: (int) $document->getId(),
            strategy: self::STRATEGY_KEY,
            globalConfidence: 0.0,
            extractedAt: new \DateTimeImmutable(),
            // GDPR — same masking rationale as the success path (data minimisation
            // before the DTO is serialized into the Document.extractedData JSON).
            rawOcrText: $rawOcrText !== null
                ? PiiMasker::maskIban(PiiMasker::maskCnp($rawOcrText))
                : null,
        );
    }
}
