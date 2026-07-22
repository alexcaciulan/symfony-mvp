<?php

namespace App\Service\Extraction;

use App\DTO\Extraction\ClaimExtraction;
use App\DTO\Extraction\CreditorExtraction;
use App\DTO\Extraction\DebtorExtraction;
use App\DTO\Extraction\DocumentClassification;
use App\DTO\Extraction\ExtractedDocumentData;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\Enum\ExtractionFailureReason;
use App\Enum\LegalGroundCategory;
use App\Enum\LlmFinishReason;
use App\Enum\PenaltyType;
use App\Enum\PersonType;
use App\Service\AuditLogService;
use App\Service\Extraction\Prompt\ExtractionPromptInterface;
use App\Service\Extraction\Prompt\ExtractionPromptRegistry;
use App\Service\Llm\LlmClientInterface;
use App\Service\Llm\LlmException;
use App\Util\PiiMasker;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Treapta 3 din cascada de extracție (priority 50). Ultimul fallback înainte
 * de Stub: trimite documentul (imagine sau PDF) DIRECT la Claude vision,
 * fără OCR intermediar. Acoperă cazurile unde OcrText (priority 70) eșuează —
 * scan prost, layout multi-coloană, scris de mână, formulare cu bifări.
 *
 * Cost ~$0.01-0.05/doc — cea mai scumpă treaptă, dar singura care procesează
 * documente unde Tesseract nu poate recupera text. Skip pe `LOCAL_ONLY` mode
 * e gestionat de orchestrator via {@see self::isAiBacked()} === true.
 *
 * GDPR — diferit fundamental față de OcrText:
 *   - OcrText: textul OCR e mascat (CNP+IBAN→placeholder) ÎNAINTE de prompt;
 *     CNP/IBAN nu trec niciodată boundary-ul către Anthropic.
 *   - AiVision: imaginea/PDF-ul **pleacă NEMASCAT** la Anthropic — mascarea
 *     binar-ului cere computer vision intermediar (redactare vizuală cu
 *     bounding box pe CNP/IBAN), nefezabil pentru MVP. Strategy logează
 *     explicit `extraction.ai_vision.binary_sent_unmasked` ca event de
 *     transparență. Utilizatorul a acceptat acest risc prin alegerea modului
 *     `BALANCED` sau `MAX_ACCURACY`; `LOCAL_ONLY` skipuie strategia complet.
 *
 * Pattern majoritar reutilizat din {@see OcrTextExtractionStrategy} — JSON
 * parsing, AI→DTO mapping, confidence resolution sunt copy-paste 1:1 fără
 * trait shared (acceptăm duplicarea pentru independență dacă AiVision
 * evoluează diferit, ex. confidence calibration vision-specific).
 */
final class AiVisionExtractionStrategy implements ExtractionStrategyInterface
{
    public const STRATEGY_KEY = 'ai_vision';

    public const PRIORITY = 50;

    private const SUPPORTED_IMAGE_MIME = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];

    private const PDF_MIME = 'application/pdf';

    /**
     * Hard limit on RAW image size. Anthropic enforces a 5 MB limit on the
     * BASE64-ENCODED payload of an `image` block (`messages.N.content.M.image.
     * source.base64: image exceeds 5 MB maximum`). Base64 inflates by ~33%
     * (4 bytes encode 3 raw bytes), so a raw file of `5 MB * 3/4 ≈ 3.75 MB` is
     * the largest that fits under the encoded ceiling. 3.7 MB leaves headroom
     * for the JSON envelope (`source.type`, `source.media_type`, `type: image`)
     * which adds ~80 bytes per request.
     */
    private const MAX_IMAGE_BYTES = 3_700_000;

    /**
     * Hard limit on RAW PDF size. `document` blocks are not bound by the 5 MB
     * image ceiling (the request limit is 32 MB), so the old shared 3.7 MB cap
     * rejected PDFs the API would have accepted.
     *
     * The binding constraints are two, and both land on 10 MB:
     *   - The upload forms cap a single file at 10M, so nothing larger can
     *     reach this code in the first place.
     *   - Memory. The request path holds the raw bytes, their base64 form and
     *     the encoded JSON body at once. Measured in the PHP container
     *     (memory_limit 256M): a 9.5 MB raw file peaks at 35.5 MB on a warm
     *     kernel, 19.1 MB peaks at 60.9 MB, and a cold-cache boot adds ~70 MB
     *     of baseline. So even 20 MB would fit, but 10 MB keeps the worst case
     *     near 100 MB and leaves the rest of the limit to the worker.
     *
     * Files above the applicable limit yield FILE_TOO_LARGE and the lawyer
     * completes those fields manually.
     */
    private const MAX_PDF_BYTES = 10_000_000;

    /**
     * The top-level sections an answer may carry. An answer with none of them
     * is not an extraction, whatever else it decoded to.
     */
    private const RESPONSE_SECTIONS = ['creditor', 'debtor', 'debtors', 'claim', 'classification'];

    /**
     * How much of an undecodable answer is logged. Enough to see the shape of
     * the opening, short enough that a runaway answer cannot flood the log.
     */
    private const MALFORMED_SAMPLE_CHARS = 400;

    public function __construct(
        private readonly LlmClientInterface $llmClient,
        private readonly ExtractionPromptRegistry $promptRegistry,
        private readonly AuditLogService $auditLogService,
        private readonly RateLimiterFactory $extractionAiVisionLimiter,
        private readonly RateLimiterFactory $extractionAiVisionBurstLimiter,
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

    /**
     * Whether the installation has API credentials at all. The orchestrator asks
     * so it can tell "operator forgot the key" (permanent) from "provider is
     * down" (retriable) when nothing ran.
     */
    public function isConfigured(): bool
    {
        return $this->anthropicApiKey !== '';
    }

    public function supports(Document $document): bool
    {
        // D1 reuse from OcrText (Pas 2.5.7) — apiKey empty operationally → skip
        // strategy. Cascade picks up via Stub. Operator notices the warning at
        // first invocation and corrects the env. Avoids duplicating fallback
        // regex logic for an edge case that's better fixed at the config layer.
        if (!$this->isConfigured()) {
            return false;
        }
        $mime = strtolower($document->getMimeType() ?? '');

        // Vision is the LAST AI-backed strategy in the cascade. Unlike OcrText,
        // which is selective on "PDF without text layer", AiVision accepts ANY
        // PDF — PdfParser (priority 100) already had a chance and gave up; if
        // the cascade is now at priority 50, the PDF needs vision regardless of
        // whether it has a text layer. Same for images.
        return in_array($mime, self::SUPPORTED_IMAGE_MIME, true) || $mime === self::PDF_MIME;
    }

    public function extract(Document $document): ExtractedDocumentData
    {
        $absolutePath = $this->absolutePath($document);

        // 1. File guards: exists, plus a per-format size limit (the API caps image
        // and document blocks differently, and memory caps both).
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            $this->logger->warning('extraction.ai_vision.file_unreadable', [
                'documentId' => $document->getId(),
                'path' => $absolutePath,
            ]);

            return $this->zeroConfidence($document, ExtractionFailureReason::FILE_UNREADABLE);
        }
        $sizeLimit = $this->sizeLimitFor($document->getMimeType());
        $fileSize = filesize($absolutePath);
        // A failed filesize() is a read problem, not an oversized file. Telling
        // the lawyer to re-export the document smaller would send them after the
        // wrong fix.
        if ($fileSize === false) {
            $this->logger->warning('extraction.ai_vision.file_size_unknown', [
                'documentId' => $document->getId(),
                'path' => $absolutePath,
            ]);

            return $this->zeroConfidence($document, ExtractionFailureReason::FILE_UNREADABLE);
        }
        if ($fileSize > $sizeLimit) {
            $this->logger->warning('extraction.ai_vision.file_too_large', [
                'documentId' => $document->getId(),
                'fileSize' => $fileSize,
                'limit' => $sizeLimit,
            ]);

            return $this->zeroConfidence($document, ExtractionFailureReason::FILE_TOO_LARGE);
        }

        // 2. Build the appropriate Anthropic content block based on MIME type.
        $documentPart = $this->buildVisionContentBlock($absolutePath, $document->getMimeType());
        if ($documentPart === null) {
            // Defensive — supports() should have filtered this, but if MIME
            // changed between supports() and extract() somehow, fail closed.
            return $this->zeroConfidence($document, ExtractionFailureReason::UNSUPPORTED_MIME);
        }

        // 3. Per-user rate limits: a daily budget plus a per-minute anti-burst
        // window. Both are consumed only once the file guards above have passed,
        // so a rejected upload never spends budget.
        // Prefer LegalCase->getUser() when the case is attached (post-wizard flow);
        // fall back to Document.uploadedBy for Pas 3.0 wizard step 0 uploads where
        // the case doesn't exist yet. Both resolve to the same User in production.
        $owner = $document->getLegalCase()?->getUser() ?? $document->getUploadedBy();
        $userId = (string) $owner->getId();
        if (!$this->extractionAiVisionBurstLimiter->create($userId)->consume(1)->isAccepted()) {
            $this->logger->warning('extraction.ai_vision.burst_limit_exhausted', ['userId' => $userId]);

            return $this->zeroConfidence($document, ExtractionFailureReason::RATE_LIMIT_EXCEEDED);
        }
        if (!$this->extractionAiVisionLimiter->create($userId)->consume(1)->isAccepted()) {
            $this->logger->warning('extraction.ai_vision.rate_limit_exhausted', ['userId' => $userId]);

            return $this->zeroConfidence($document, ExtractionFailureReason::RATE_LIMIT_EXCEEDED);
        }

        // 4. AI call. GDPR transparency: log explicitly that the binary leaves
        // the boundary unmasked. There's no caller-side masking option for raw
        // image / PDF bytes; the user accepted the risk by picking BALANCED or
        // MAX_ACCURACY mode (LOCAL_ONLY would have filtered this strategy out
        // upstream via isAiBacked()). The audit log carries the same context.
        // GDPR Reg. UE 2016/679 art. 30 — registru activități de prelucrare. The
        // operator-of-record (the lawyer-user) MUST be identifiable in the
        // transparency event so an ANSPDCP audit can reconstruct who triggered
        // each transfer of personal data to Anthropic.
        $this->logger->info('extraction.ai_vision.binary_sent_unmasked', [
            'documentId' => $document->getId(),
            'userId' => $userId,
            'mimeType' => $document->getMimeType(),
            'fileSize' => $fileSize,
        ]);

        // Two-phase flow. A document whose type nobody declared gets the
        // generic prompt, which classifies and extracts in the same call; a
        // document with a declared type gets the prompt written for it. There
        // is no automatic second pass once a classification comes back: that
        // would resend the binary, the expensive half of the request, to
        // re-read a page already read.
        $declaredType = $document->getDocumentType();
        $prompt = $this->promptRegistry->forType($declaredType);
        try {
            $response = $this->llmClient->complete(
                messages: $this->buildMessages($prompt),
                maxTokens: $prompt->maxTokens(),
                documentParts: [$documentPart],
                // The system block is identical for every prompt, so a batch of
                // documents uploaded together reads it from the cache instead of
                // paying for it once per document.
                cacheSystemPrompt: true,
                outputSchema: $prompt->outputSchema(),
            );
        } catch (LlmException $e) {
            // Only a transient failure earns a retry. A rejected request (bad
            // model id, payload the provider refuses, invalid key) answers the
            // same way every time and would burn rate-limit budget on each pass.
            $reason = match (true) {
                $e->isProviderUnavailable() => ExtractionFailureReason::PROVIDER_UNAVAILABLE,
                $e->isTransient() => ExtractionFailureReason::API_UNAVAILABLE,
                $e->getStatusCode() !== null => ExtractionFailureReason::PROVIDER_REJECTED,
                default => ExtractionFailureReason::RESPONSE_MALFORMED,
            };
            $this->logger->error('extraction.ai_vision.llm_failed', [
                'documentId' => $document->getId(),
                'exceptionClass' => $e::class,
                'status' => $e->getStatusCode(),
                'transient' => $e->isTransient(),
                'reason' => $reason->value,
            ]);

            return $this->zeroConfidence($document, $reason);
        }

        // 5. Truncation guard, BEFORE parsing. A response cut off at the token
        // cap is not corrupt output: it is a budget problem, and the operator
        // fix (raise maxTokens) differs from the one for malformed JSON. The
        // parser cannot tell them apart, so decide here while finishReason is
        // still meaningful.
        if ($response->finishReason === LlmFinishReason::MAX_TOKENS) {
            $this->logger->warning('extraction.ai_vision.response_truncated', [
                'documentId' => $document->getId(),
                'prompt' => $prompt->key(),
                'maxTokens' => $prompt->maxTokens(),
                'tokensOut' => $response->tokensOut,
            ]);

            return $this->zeroConfidence($document, ExtractionFailureReason::RESPONSE_TRUNCATED);
        }

        // 6. Parse JSON. NO PII restore step: vision returns plain values
        // because it never received masked input.
        $parsed = $this->parseAiResponse($response->content);
        if ($parsed === null) {
            // A hash alone made the last occurrence of this undiagnosable: it
            // proves two answers differ and says nothing about how. The head of
            // the answer, masked and short, is what tells an operator whether
            // the model wrapped it in prose, split it in two, or refused.
            $this->logger->warning('extraction.ai_vision.malformed_ai_response', [
                'documentId' => $document->getId(),
                'prompt' => $prompt->key(),
                'responseLength' => strlen($response->content),
                'responseHead' => PiiMasker::maskIban(PiiMasker::maskCnp(
                    mb_substr(trim($response->content), 0, self::MALFORMED_SAMPLE_CHARS),
                )),
            ]);

            return $this->zeroConfidence($document, ExtractionFailureReason::RESPONSE_MALFORMED);
        }

        $sourceDocumentId = (int) $document->getId();
        $creditor = $this->buildCreditorFromAi($parsed['creditor'] ?? null);
        $debtors = $this->buildDebtorsFromAi($parsed);
        $claim = $this->buildClaimFromAi($parsed['claim'] ?? null);
        $classification = $this->buildClassificationFromAi($parsed['classification'] ?? null);
        // Coverage is scored against what this kind of document can be expected
        // to carry. A bank statement holds no creditor identity by nature, and
        // scoring it against the full field set would report an accurate
        // extraction as a poor one.
        $globalConfidence = $this->resolveGlobalConfidence(
            $creditor,
            // Coverage is scored against the party the case is filed against.
            // A second co-debtor adds parties, not coverage.
            $debtors[0] ?? null,
            $claim,
            $this->scoringType($declaredType, $classification),
        );

        // 7. Audit, metadata only. PiiMasker::maskCnpInArray defense-in-depth
        // on the persisted payload; even though we log mimeType + fileSize
        // (non-PII metadata), the AI's structured response (fed downstream)
        // may contain CNPs and must be sanitised before audit persistence.
        $this->auditLogService->log(
            action: 'AI_EXTRACTION_COMPLETED',
            entityType: 'Document',
            entityId: (string) $sourceDocumentId,
            newData: PiiMasker::maskCnpInArray([
                'strategy' => self::STRATEGY_KEY,
                'prompt' => $prompt->key(),
                'detectedType' => $classification?->type->value,
                'detectedTypeConfidence' => $classification?->confidence,
                'tokensIn' => $response->tokensIn,
                'tokensOut' => $response->tokensOut,
                'cacheReadInputTokens' => $response->cacheReadInputTokens,
                'cacheCreationInputTokens' => $response->cacheCreationInputTokens,
                'finishReason' => $response->finishReason->value,
                // 32 hex chars (128 bits) — birthday-bound collision after ~2^64
                // operations, comfortable for evidentiary use should an extraction
                // dispute reach the audit trail. 16-hex was sufficient for cost
                // telemetry but would risk collision in long-lived multi-tenant logs.
                'responseHash' => substr(hash('sha256', $response->content), 0, 32),
                'globalConfidence' => $globalConfidence,
                'fileSize' => $fileSize,
                'mimeType' => $document->getMimeType(),
            ]),
            category: AuditLogService::CATEGORY_AI_EXTRACTION,
        );

        return new ExtractedDocumentData(
            sourceDocumentId: $sourceDocumentId,
            strategy: self::STRATEGY_KEY,
            globalConfidence: $globalConfidence,
            extractedAt: new \DateTimeImmutable(),
            creditor: $creditor,
            debtors: $debtors,
            claim: $claim,
            // Vision doesn't OCR — there's no rawOcrText to persist. PdfParser
            // and OcrText already attempted earlier in the cascade and either
            // succeeded (we're not here) or failed without producing usable text.
            rawOcrText: null,
            classification: $classification,
        );
    }

    // ---------- helpers ----------

    private function absolutePath(Document $document): string
    {
        return rtrim($this->uploadsDir, '/') . '/' . ltrim($document->getStoredFilename(), '/');
    }

    /**
     * Builds the Anthropic content block matching the document's MIME type.
     * Image MIMEs → `image` block. `application/pdf` → `document` block
     * (Claude 3.5+ native PDF support — accepts up to ~32MB but we cap at 5MB
     * to keep latency + cost predictable). Returns null only as a defensive
     * guard; supports() should have filtered any unsupported MIME upstream.
     *
     * @return array<string, mixed>|null
     */
    private function buildVisionContentBlock(string $absolutePath, ?string $mimeType): ?array
    {
        $mime = strtolower($mimeType ?? '');
        $bytes = file_get_contents($absolutePath);
        if ($bytes === false) {
            return null;
        }
        $base64 = base64_encode($bytes);

        if (in_array($mime, self::SUPPORTED_IMAGE_MIME, true)) {
            // Anthropic accepts only the IANA-registered `image/jpeg`. Some upload
            // pipelines emit `image/jpg` (a common but non-standard alias) — normalize
            // here so the request body uses the variant the API recognises.
            $apiMime = $mime === 'image/jpg' ? 'image/jpeg' : $mime;

            return [
                'type' => 'image',
                'source' => ['type' => 'base64', 'media_type' => $apiMime, 'data' => $base64],
            ];
        }

        if ($mime === self::PDF_MIME) {
            return [
                'type' => 'document',
                'source' => ['type' => 'base64', 'media_type' => self::PDF_MIME, 'data' => $base64],
            ];
        }

        return null;
    }

    /**
     * The provider-neutral message list for one extraction call. The system
     * turn carries the stable block, identical across prompts and therefore
     * cacheable; the per-type instructions ride in the user turn, after the
     * document block the client splices in.
     *
     * @return array<int, array{role: 'system'|'user', content: string}>
     */
    private function buildMessages(ExtractionPromptInterface $prompt): array
    {
        return [
            ['role' => 'system', 'content' => $prompt->systemPrompt()],
            ['role' => 'user', 'content' => $prompt->userInstructions()],
        ];
    }

    /**
     * Decodes the model's answer.
     *
     * With constrained decoding the response is JSON by construction, so the
     * normal path is a plain decode. The salvage below is the degradation path
     * for a model that does not support it: such a model still answers with
     * JSON, only wrapped in a code fence or a sentence of prose. It runs only
     * after the plain decode has failed, so it costs nothing on the normal
     * path, and it repairs the envelope only. The values themselves are still
     * validated field by field afterwards, because a schema constrains shapes
     * and not meaning: a date can conform and still be nonsense.
     *
     * @return array<string, mixed>|null
     */
    private function parseAiResponse(string $content): ?array
    {
        $trimmed = trim($content);

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            // Presence of the keys, not of values behind them. A document that
            // was read fine and carries nothing usable comes back as all-null:
            // that is a valid answer, and reporting it as a parse failure would
            // send the lawyer after the wrong cause. It still scores zero
            // coverage.
            foreach (self::RESPONSE_SECTIONS as $section) {
                if (array_key_exists($section, $decoded)) {
                    return $decoded;
                }
            }
        }

        $salvaged = $this->salvageJsonObject($trimmed);
        if ($salvaged !== null) {
            return $salvaged;
        }

        // Last resort: the answer may be well formed apart from a quote the
        // model failed to escape inside one of its own sentences. Repairing
        // that is the difference between a complete extraction and none.
        $repaired = $this->escapeStrayQuotes($trimmed);
        if ($repaired === $trimmed) {
            return null;
        }

        $decoded = json_decode($repaired, true);
        if (is_array($decoded)) {
            foreach (self::RESPONSE_SECTIONS as $section) {
                if (array_key_exists($section, $decoded)) {
                    return $decoded;
                }
            }
        }

        return $this->salvageJsonObject($repaired);
    }

    /**
     * Escapes double quotes that appear inside a JSON string value.
     *
     * The free-text fields are written by the model in Romanian, and a citation
     * of a document title routinely comes back as `„FACTURĂ FISCALĂ"`: an
     * opening typographic quote closed with a straight one. That straight quote
     * ends the JSON string three words early and the entire answer, every party
     * and every figure in it, decodes to nothing. The instructions now ask for
     * balanced typographic quotes, but the request is advisory in a way the
     * grammar is not, and losing a whole extraction to one character is too
     * expensive to leave to phrasing alone.
     *
     * A quote is read as the real end of the string when the next thing that
     * matters is punctuation that can legally follow one, and as stray text
     * otherwise. Runs only after a strict decode has already failed, so a
     * well-formed answer never reaches it, and a wrong guess costs nothing: the
     * result was unparseable to begin with.
     */
    private function escapeStrayQuotes(string $content): string
    {
        $out = '';
        $inString = false;
        $escaped = false;

        $length = strlen($content);
        for ($i = 0; $i < $length; ++$i) {
            $char = $content[$i];

            if (!$inString) {
                $out .= $char;
                if ($char === '"') {
                    $inString = true;
                }
                continue;
            }

            if ($escaped) {
                $escaped = false;
                $out .= $char;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                $out .= $char;
                continue;
            }
            if ($char !== '"') {
                $out .= $char;
                continue;
            }

            if ($this->closesJsonString($content, $i + 1)) {
                $inString = false;
                $out .= $char;
                continue;
            }
            $out .= '\\"';
        }

        return $out;
    }

    /**
     * Whether the quote at the given offset is followed by something only a
     * finished string can be followed by.
     */
    private function closesJsonString(string $content, int $offset): bool
    {
        $length = strlen($content);
        for ($i = $offset; $i < $length; ++$i) {
            $char = $content[$i];
            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                continue;
            }

            return $char === ',' || $char === ':' || $char === '}' || $char === ']';
        }

        // Trailing quote at the very end of the answer closes the string.
        return true;
    }

    /**
     * Pulls the extraction out of an answer that is not a single bare JSON
     * object. Only reached when constrained decoding did not apply, which is
     * the case whenever the schema is too wide for the provider to compile a
     * grammar from.
     *
     * Two shapes cost real extractions before this existed, and both come from
     * asking one call to do two things. A model told to classify and extract
     * answers with two objects, one per task, often each in its own fence; and
     * a model that adds a closing remark puts braces after the object it was
     * asked for. The old salvage took everything from the first `{` to the last
     * `}`, which in the first case spans the gap between two objects and in the
     * second swallows the remark, so both decoded as nothing at all. Scanning
     * for balanced objects and merging the ones that carry a section handles
     * both, and costs nothing on the normal path because it runs only after a
     * plain decode has already failed.
     *
     * @return array<string, mixed>|null
     */
    private function salvageJsonObject(string $content): ?array
    {
        $merged = [];
        foreach ($this->balancedJsonObjects($content) as $candidate) {
            $decoded = json_decode($candidate, true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach (self::RESPONSE_SECTIONS as $section) {
                // Only the known sections are taken, and only the first reading
                // of each: a second object repeating a section is the model
                // restating itself, not a correction, and nothing here is in a
                // position to judge which reading is better.
                if (array_key_exists($section, $decoded) && !array_key_exists($section, $merged)) {
                    $merged[$section] = $decoded[$section];
                }
            }
        }

        return $merged === [] ? null : $merged;
    }

    /**
     * Every balanced `{...}` run in the text, in order, ignoring braces inside
     * strings so a description containing one does not end the object early.
     *
     * @return list<string>
     */
    private function balancedJsonObjects(string $content): array
    {
        $objects = [];
        $depth = 0;
        $start = null;
        $inString = false;
        $escaped = false;

        $length = strlen($content);
        for ($i = 0; $i < $length; ++$i) {
            $char = $content[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }
            if ($char === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                ++$depth;
                continue;
            }
            if ($char === '}' && $depth > 0) {
                --$depth;
                if ($depth === 0 && $start !== null) {
                    $objects[] = substr($content, $start, $i - $start + 1);
                    $start = null;
                }
            }
        }

        return $objects;
    }

    /**
     * @param array<string, mixed>|null $raw
     */
    private function buildClassificationFromAi(?array $raw): ?DocumentClassification
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }
        $type = $this->coerceString($raw['type'] ?? null);
        $documentType = $type !== null ? DocumentType::tryFrom($type) : null;
        // An auto-generated type is not something a lawyer uploads, so a model
        // returning one has misread the document rather than found a rare case.
        if ($documentType === null || $documentType->isAutoGenerated()) {
            return null;
        }
        $confidenceRaw = $raw['confidence'] ?? null;
        $confidence = is_numeric($confidenceRaw) ? (float) $confidenceRaw : 0.0;

        return new DocumentClassification(
            type: $documentType,
            confidence: max(0.0, min(1.0, $confidence)),
            subtype: $this->coerceString($raw['subtype'] ?? null),
            rationale: $this->coerceString($raw['rationale'] ?? null),
        );
    }

    /**
     * The type to score coverage against, or null when nothing reliable says
     * what the document is.
     *
     * The declared type comes first: a type someone chose is a decision, and
     * the same precedence governs whether a detection may overwrite it on the
     * entity. A detection is used only where nobody declared anything, and only
     * once it is confident enough to be adopted: an unsure guess would
     * otherwise pick a narrow field set and turn three fields into a
     * fully-read document.
     */
    private function scoringType(?DocumentType $declared, ?DocumentClassification $classification): ?DocumentType
    {
        $known = $this->knownType($declared);
        if ($known !== null) {
            return $known;
        }

        return $classification !== null && $classification->isActionable() ? $classification->type : null;
    }

    /**
     * The type to score coverage against, or null when nobody has declared one.
     * `ALT_DOCUMENT` is the wizard's "unspecified", not a statement about the
     * document, so it must not narrow the expected field set.
     */
    private function knownType(?DocumentType $type): ?DocumentType
    {
        return $type === null || $type === DocumentType::ALT_DOCUMENT ? null : $type;
    }

    /**
     * @param array<string, mixed>|null $raw
     */
    private function buildCreditorFromAi(?array $raw): ?CreditorExtraction
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        $values = $this->coerceParty($raw) + [
            'legalRepresentative' => $this->coerceString($raw['legalRepresentative'] ?? null),
            'bankName' => $this->coerceString($raw['bankName'] ?? null),
        ];

        return new CreditorExtraction(
            personType: $values['personType'],
            name: $values['name'],
            cui: $values['cui'],
            isVatPayer: $values['isVatPayer'],
            personalId: $values['personalId'],
            onrcNumber: $values['onrcNumber'],
            address: $values['address'],
            county: $values['county'],
            locality: $values['locality'],
            email: $values['email'],
            phone: $values['phone'],
            iban: $values['iban'],
            legalRepresentative: $values['legalRepresentative'],
            bankName: $values['bankName'],
            confidencePerField: $this->coerceConfidenceMap($raw['confidencePerField'] ?? null, $values),
        );
    }

    /**
     * Every debtor the answer names.
     *
     * The response schema asks for one `debtor` object, which is what a Romanian
     * invoice or contract carries in the overwhelming majority of cases. The
     * reader also accepts a `debtors` list, so a document with co-debtors starts
     * producing two parties the moment the schema offers the model somewhere to
     * put the second one, without a second pass through this file.
     *
     * @param array<string, mixed> $parsed
     * @return list<DebtorExtraction>
     */
    private function buildDebtorsFromAi(array $parsed): array
    {
        $raw = $parsed['debtors'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            $single = $this->buildDebtorFromAi(is_array($parsed['debtor'] ?? null) ? $parsed['debtor'] : null);

            return $single !== null ? [$single] : [];
        }

        $debtors = [];
        foreach ($raw as $entry) {
            $debtor = $this->buildDebtorFromAi(is_array($entry) ? $entry : null);
            if ($debtor !== null) {
                $debtors[] = $debtor;
            }
        }

        return $debtors;
    }

    /**
     * @param array<string, mixed>|null $raw
     */
    private function buildDebtorFromAi(?array $raw): ?DebtorExtraction
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        $values = $this->coerceParty($raw) + [
            'administrator' => $this->coerceString($raw['administrator'] ?? null),
        ];

        return new DebtorExtraction(
            personType: $values['personType'],
            name: $values['name'],
            cui: $values['cui'],
            isVatPayer: $values['isVatPayer'],
            personalId: $values['personalId'],
            onrcNumber: $values['onrcNumber'],
            address: $values['address'],
            county: $values['county'],
            locality: $values['locality'],
            email: $values['email'],
            phone: $values['phone'],
            iban: $values['iban'],
            administrator: $values['administrator'],
            confidencePerField: $this->coerceConfidenceMap($raw['confidencePerField'] ?? null, $values),
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

        $legalGround = null;
        if (is_string($raw['legalGround'] ?? null) && $raw['legalGround'] !== '') {
            $legalGround = LegalGroundCategory::tryFrom($raw['legalGround']);
        }

        $penaltyType = null;
        if (is_string($raw['penaltyType'] ?? null) && $raw['penaltyType'] !== '') {
            $penaltyType = PenaltyType::tryFrom($raw['penaltyType']);
        }
        $penaltyRateRaw = $raw['contractualPenaltyRate'] ?? null;
        $penaltyRate = is_numeric($penaltyRateRaw) ? (float) $penaltyRateRaw : null;

        $values = [
            'amount' => $amount,
            'currency' => $this->coerceString($raw['currency'] ?? null),
            'dueDate' => $this->coerceDate($raw['dueDate'] ?? null),
            'legalGround' => $legalGround,
            'description' => $this->coerceString($raw['description'] ?? null),
            'invoiceNumber' => $this->coerceString($raw['invoiceNumber'] ?? null),
            'invoiceDate' => $this->coerceDate($raw['invoiceDate'] ?? null),
            'contractNumber' => $this->coerceString($raw['contractNumber'] ?? null),
            'contractDate' => $this->coerceDate($raw['contractDate'] ?? null),
            'contractReference' => $this->coerceString($raw['contractReference'] ?? null),
            'penaltyType' => $penaltyType,
            'contractualPenaltyRate' => $penaltyRate,
        ];

        return new ClaimExtraction(
            amount: $values['amount'],
            currency: $values['currency'],
            dueDate: $values['dueDate'],
            legalGround: $values['legalGround'],
            description: $values['description'],
            invoiceNumber: $values['invoiceNumber'],
            invoiceDate: $values['invoiceDate'],
            contractNumber: $values['contractNumber'],
            contractDate: $values['contractDate'],
            contractReference: $values['contractReference'],
            penaltyType: $values['penaltyType'],
            contractualPenaltyRate: $values['contractualPenaltyRate'],
            confidencePerField: $this->coerceConfidenceMap($raw['confidencePerField'] ?? null, $values),
        );
    }

    /**
     * Parses an `YYYY-MM-DD` calendar date from AI output. The leading `!` in
     * the format resets time-of-day to 00:00:00 so createFromFormat does not
     * seed the value with the wall-clock current time.
     *
     * A date that does not exist is rejected rather than accepted: PHP rolls
     * `2024-02-31` forward to 2 March instead of failing, and a due date moved
     * by two days moves the default date and the interest with it. The
     * round-trip comparison is what catches that, because a rolled-over value
     * no longer formats back to what the model wrote.
     */
    private function coerceDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $dt instanceof \DateTimeImmutable && $dt->format('Y-m-d') === $value ? $dt : null;
    }

    /**
     * Coverage-weighted global confidence — see the analogous comment in
     * {@see OcrTextExtractionStrategy::resolveGlobalConfidence()} for the
     * rationale. AI-returned `globalConfidence` is discarded on purpose.
     */
    private function resolveGlobalConfidence(
        ?CreditorExtraction $creditor,
        ?DebtorExtraction $debtor,
        ?ClaimExtraction $claim,
        ?DocumentType $documentType,
    ): float {
        return CoverageConfidenceCalculator::computeForType(
            $documentType,
            $creditor?->confidencePerField,
            $debtor?->confidencePerField,
            $claim?->confidencePerField,
        );
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
        return is_bool($value) ? $value : null;
    }

    private function coercePersonType(mixed $value): ?PersonType
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return PersonType::tryFrom($value);
    }

    /**
     * Validates AI-returned email; same shape as the OcrText sibling so the two
     * AI strategies behave identically when promoted/demoted by the cascade
     * (prevents hallucinated emails reaching the DTO).
     */
    private function coerceEmail(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || filter_var($trimmed, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Accepts RO phone in canonical compact form. Strips separators the AI may
     * still emit (despite the prompt asking for compact form), then validates
     * digit count. See OcrTextExtractionStrategy::coercePhone for rationale.
     */
    private function coercePhone(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $compact = preg_replace('/[\s-]+/', '', trim($value));
        if ($compact === null || $compact === '') {
            return null;
        }
        $digitsOnly = ltrim($compact, '+');
        if (!preg_match('/^(0\d{9}|40\d{9})$/', $digitsOnly)) {
            return null;
        }

        return $compact;
    }

    /**
     * Scores, restricted to the fields that actually carry a value.
     *
     * The closed response schema requires every confidence key to be present,
     * so a model may return a score of 1.0 next to a field it left null.
     * Coverage counts what was extracted, not what was scored, so a score
     * without a value behind it is dropped here rather than downstream: the
     * calculators only ever see the confidence maps.
     *
     * @param array<string, mixed> $extractedValues field name => coerced value
     *
     * @return array<string, float>
     */
    private function coerceConfidenceMap(mixed $value, array $extractedValues): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $key => $score) {
            if (!is_string($key) || !is_numeric($score)) {
                continue;
            }
            if (($extractedValues[$key] ?? null) === null) {
                continue;
            }
            $result[$key] = max(0.0, min(1.0, (float) $score));
        }

        return $result;
    }

    /**
     * The party fields both roles share, coerced once.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function coerceParty(array $raw): array
    {
        return [
            'personType' => $this->coercePersonType($raw['personType'] ?? null),
            'name' => $this->coerceString($raw['name'] ?? null),
            'cui' => $this->coerceString($raw['cui'] ?? null),
            'isVatPayer' => $this->coerceNullableBool($raw['isVatPayer'] ?? null),
            'personalId' => $this->coerceString($raw['personalId'] ?? null),
            'onrcNumber' => $this->coerceString($raw['onrcNumber'] ?? null),
            'address' => $this->coerceString($raw['address'] ?? null),
            'county' => $this->coerceString($raw['county'] ?? null),
            'locality' => $this->coerceString($raw['locality'] ?? null),
            'email' => $this->coerceEmail($raw['email'] ?? null),
            'phone' => $this->coercePhone($raw['phone'] ?? null),
            'iban' => $this->coerceString($raw['iban'] ?? null),
        ];
    }

    /**
     * Empty result carrying the cause. The reason is what lets the UI say
     * something actionable and lets the async handler decide between retrying
     * and giving up, so it is required rather than optional.
     */
    private function zeroConfidence(Document $document, ExtractionFailureReason $reason): ExtractedDocumentData
    {
        return new ExtractedDocumentData(
            sourceDocumentId: (int) $document->getId(),
            strategy: self::STRATEGY_KEY,
            globalConfidence: 0.0,
            extractedAt: new \DateTimeImmutable(),
            // No rawOcrText — vision doesn't OCR.
            rawOcrText: null,
            failureReason: $reason,
        );
    }

    /**
     * The API caps base64 image payloads at 5 MB but allows far larger PDF
     * document blocks, so a single shared ceiling would reject PDFs it would
     * have accepted.
     */
    private function sizeLimitFor(?string $mimeType): int
    {
        return strtolower($mimeType ?? '') === self::PDF_MIME
            ? self::MAX_PDF_BYTES
            : self::MAX_IMAGE_BYTES;
    }
}
