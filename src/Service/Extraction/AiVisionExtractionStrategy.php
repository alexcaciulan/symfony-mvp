<?php

namespace App\Service\Extraction;

use App\DTO\Extraction\ClaimExtraction;
use App\DTO\Extraction\CreditorExtraction;
use App\DTO\Extraction\DebtorExtraction;
use App\DTO\Extraction\ExtractedDocumentData;
use App\Entity\Document;
use App\Enum\LegalGroundCategory;
use App\Enum\PenaltyType;
use App\Enum\PersonType;
use App\Service\AuditLogService;
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
     * Hard limit on RAW file size sent to vision. Anthropic enforces a 5 MB
     * limit on the BASE64-ENCODED payload (`messages.N.content.M.image.source.
     * base64: image exceeds 5 MB maximum`). Base64 inflates by ~33% (4 bytes
     * encode 3 raw bytes), so a raw file of `5 MB * 3/4 ≈ 3.75 MB` is the
     * largest that fits under the encoded ceiling. We use 3.7 MB to leave
     * headroom for the JSON envelope (`source.type`, `source.media_type`,
     * `type: image` etc.) which adds ~80 bytes per request.
     *
     * Files exceeding this fall through to Stub (FAILED status, lawyer
     * completes manually). Users get an upstream hint via the upload form
     * which still allows up to 10 MB per file, so large originals can still
     * be persisted for the wizard — they just won't reach the AI vision tier.
     */
    private const MAX_FILE_BYTES = 3_700_000;

    private const MAX_TOKENS = 2048;

    public function __construct(
        private readonly LlmClientInterface $llmClient,
        private readonly AuditLogService $auditLogService,
        private readonly RateLimiterFactory $extractionAiVisionLimiter,
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
        // D1 reuse from OcrText (Pas 2.5.7) — apiKey empty operationally → skip
        // strategy. Cascade picks up via Stub. Operator notices the warning at
        // first invocation and corrects the env. Avoids duplicating fallback
        // regex logic for an edge case that's better fixed at the config layer.
        if ($this->anthropicApiKey === '') {
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

        // 1. File guards — exists + size limit (cost control + Anthropic recommended <5MB).
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            $this->logger->warning('extraction.ai_vision.file_unreadable', [
                'documentId' => $document->getId(),
                'path' => $absolutePath,
            ]);

            return $this->zeroConfidence($document);
        }
        $fileSize = filesize($absolutePath);
        if ($fileSize === false || $fileSize > self::MAX_FILE_BYTES) {
            $this->logger->warning('extraction.ai_vision.file_too_large', [
                'documentId' => $document->getId(),
                // Disambiguate "filesize() failed (rare race)" from "0 bytes" or any
                // legitimate size — handlers that stringify booleans turn `false`
                // into '' and the log entry becomes meaningless at triage time.
                'fileSize' => $fileSize === false ? 'unknown' : $fileSize,
                'limit' => self::MAX_FILE_BYTES,
            ]);

            return $this->zeroConfidence($document);
        }

        // 2. Build the appropriate Anthropic content block based on MIME type.
        $documentPart = $this->buildVisionContentBlock($absolutePath, $document->getMimeType());
        if ($documentPart === null) {
            // Defensive — supports() should have filtered this, but if MIME
            // changed between supports() and extract() somehow, fail closed.
            return $this->zeroConfidence($document);
        }

        // 3. Per-user rate limit (extraction_ai_vision: 50/day, sliding_window).
        // Prefer LegalCase->getUser() when the case is attached (post-wizard flow);
        // fall back to Document.uploadedBy for Pas 3.0 wizard step 0 uploads where
        // the case doesn't exist yet. Both resolve to the same User in production.
        $owner = $document->getLegalCase()?->getUser() ?? $document->getUploadedBy();
        $userId = (string) $owner->getId();
        if (!$this->extractionAiVisionLimiter->create($userId)->consume(1)->isAccepted()) {
            $this->logger->warning('extraction.ai_vision.rate_limit_exhausted', ['userId' => $userId]);

            return $this->zeroConfidence($document);
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
        try {
            $response = $this->llmClient->complete(
                messages: $this->buildMessages(),
                maxTokens: self::MAX_TOKENS,
                documentParts: [$documentPart],
            );
        } catch (LlmException $e) {
            $this->logger->error('extraction.ai_vision.llm_failed', [
                'documentId' => $document->getId(),
                'exceptionClass' => $e::class,
                'code' => $e->getCode(),
            ]);

            return $this->zeroConfidence($document);
        }

        // 5. Parse JSON. NO PII restore step — vision returns plain values
        // because it never received masked input.
        $parsed = $this->parseAiResponse($response->content);
        if ($parsed === null) {
            $this->logger->warning('extraction.ai_vision.malformed_ai_response', [
                'documentId' => $document->getId(),
            ]);

            return $this->zeroConfidence($document);
        }

        $sourceDocumentId = (int) $document->getId();
        $creditor = $this->buildCreditorFromAi($parsed['creditor'] ?? null);
        $debtor = $this->buildDebtorFromAi($parsed['debtor'] ?? null);
        $claim = $this->buildClaimFromAi($parsed['claim'] ?? null);
        $globalConfidence = $this->resolveGlobalConfidence($creditor, $debtor, $claim);

        // 6. Audit — metadata only. PiiMasker::maskCnpInArray defense-in-depth
        // on the persisted payload; even though we log mimeType + fileSize
        // (non-PII metadata), the AI's structured response (fed downstream)
        // may contain CNPs and must be sanitised before audit persistence.
        $this->auditLogService->log(
            action: 'AI_EXTRACTION_COMPLETED',
            entityType: 'Document',
            entityId: (string) $sourceDocumentId,
            newData: PiiMasker::maskCnpInArray([
                'strategy' => self::STRATEGY_KEY,
                'tokensIn' => $response->tokensIn,
                'tokensOut' => $response->tokensOut,
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
            debtor: $debtor,
            claim: $claim,
            // Vision doesn't OCR — there's no rawOcrText to persist. PdfParser
            // and OcrText already attempted earlier in the cascade and either
            // succeeded (we're not here) or failed without producing usable text.
            rawOcrText: null,
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
     * @return array<int, array{role: 'system'|'user', content: string}>
     */
    private function buildMessages(): array
    {
        $system = 'Ești un expert în extracție de date din documente juridice și comerciale '
            . 'românești (facturi, contracte, recunoașteri de datorie). Returnează DOAR JSON pur, '
            . 'fără text liber sau markdown. Schema strictă. Câmpuri opționale dacă lipsesc din document.';

        $legalGrounds = implode('|', array_map(static fn (LegalGroundCategory $c) => $c->value, LegalGroundCategory::cases()));
        $penaltyTypes = implode('|', array_map(static fn (PenaltyType $p) => $p->value, PenaltyType::cases()));

        $user = <<<PROMPT
Analizează DOCUMENTUL ATAȘAT (imagine sau PDF) și extrage datele structurate.

CONTEXT JURIDIC: documentul stă la baza unei cereri de ordonanță de plată
(CPC art. 1013-1024). Identifică ROLURILE PĂRȚILOR FOLOSIND ACEST GLOSAR
STRICT — niciodată nu inversa rolurile:

CREDITOR (cel care PRETINDE plata, livrează bunul/serviciul, este partea
neplătită) = oricare dintre acești termeni contractuali RO:
  • Prestator (în contract de prestări servicii)
  • Furnizor / Vânzător (în factură sau contract de vânzare)
  • Executant / Antreprenor (în contract de antrepriză/lucrări)
  • Locator (în contract de locațiune — proprietarul)
  • Imprumutător / Creditor (în contract de împrumut)
  • Cedent (în cesiune de creanță)
  • Emitent / Trăgător (în cambie / bilet la ordin / cec)
  • Mandant (în mandat)
  • Producător

DEBITOR (cel care DATOREAZĂ plata, primește bunul/serviciul) = oricare dintre:
  • Beneficiar (în contract de prestări servicii)
  • Client / Cumpărător / Achizitor (în factură sau contract de vânzare)
  • Locatar / Chiriaș (în locațiune)
  • Imprumutat / Debitor (în împrumut)
  • Cesionar (în cesiune)
  • Trasă (în cambie)
  • Mandatar (în mandat — dacă datorează contravaloare servicii)

REGULA-CHEIE: în contractele de prestări servicii românești tipice,
"Prestator" e CREDITOR și "Beneficiar" e DEBITOR — chiar dacă în text
Prestator apare cu un cont bancar (acela e contul în care primește plata),
NU îl confunda cu Debitor. Plata curge DE LA Beneficiar (debitor) CĂTRE
Prestator (creditor).

Returnează JSON cu această schemă (toate câmpurile opționale dacă nu apar — returnează `null` pentru câmpuri lipsă):
{
  "creditor": {
    "personType": "PJ"|"PF",
    "name": "...",
    "cui": "doar cifre, fără prefix RO",
    "isVatPayer": true|false,
    "personalId": "13 cifre CNP dacă e persoană fizică",
    "onrcNumber": "format canonic J/F + jud/seq/an, ex: J40/1234/2025",
    "address": "...",
    "email": "format valid email",
    "phone": "format compact RO: 0XXXXXXXXX sau +40XXXXXXXXX",
    "iban": "RO + 22 caractere",
    "legalRepresentative": "...",
    "bankName": "denumirea băncii unde e deschis contul creditorului, ex: Banca Transilvania",
    "confidencePerField": {"personType": 0.98, "name": 0.95, "cui": 0.99, "onrcNumber": 0.92, "address": 0.9, "iban": 0.9, "bankName": 0.9}
  },
  "debtor": {
    "personType": "PJ"|"PF",
    "name": "...",
    "cui": "...",
    "isVatPayer": true|false,
    "personalId": "...",
    "onrcNumber": "format canonic J/F + jud/seq/an",
    "address": "...",
    "county": "județul din adresă, ex: Cluj (fără prefix jud.)",
    "locality": "localitatea din adresă, ex: Cluj-Napoca (fără prefix mun./oraș/comuna)",
    "email": "format valid email",
    "phone": "format compact RO",
    "iban": "RO + 22 caractere",
    "administrator": "nume reprezentant legal / administrator (PJ)",
    "confidencePerField": {"personType": 0.98, "name": 0.95, "cui": 0.99, "onrcNumber": 0.92, "address": 0.9}
  },
  "claim": {
    "amount": 5000.50,
    "currency": "RON"|"EUR"|"USD",
    "dueDate": "YYYY-MM-DD",
    "legalGround": "{$legalGrounds}",
    "description": "...",
    "invoiceNumber": "seria și numărul facturii, ex: MJ 2024-00123",
    "invoiceDate": "YYYY-MM-DD (data emiterii facturii)",
    "contractNumber": "numărul contractului, ex: 45/2024",
    "contractDate": "YYYY-MM-DD (data încheierii contractului)",
    "contractReference": "denumirea/obiectul contractului, ex: contract de prestări servicii",
    "penaltyType": "{$penaltyTypes}",
    "contractualPenaltyRate": 0.1,
    "confidencePerField": {"amount": 0.98, "currency": 0.98, "dueDate": 0.9, "invoiceNumber": 0.92}
  },
  "globalConfidence": 0.85
}

Confidence per câmp: 0..1, reflectă cât de sigur ești pe baza vizuală
(text + sigil clare = 0.95+; text obscurat parțial sau ambiguu = 0.5-0.7;
ghicit din context = 0.3-0.5).
OBLIGATORIU: pentru FIECARE câmp pe care îl completezi cu o valoare non-null
(inclusiv onrcNumber, personType, address, iban etc., nu doar câmpurile din
exemple), adaugă o intrare corespunzătoare în `confidencePerField`. Câmpurile
fără scor de confidence sunt IGNORATE la pre-completarea formularului.
Pentru email/phone returnează `null` dacă nu apar explicit — nu inventa.
Pentru onrcNumber respectă format `J40/1234/2025` (acceptă și „Nr. ORC",
„Reg. Com.", „J40/...", „C.U.I./J..." din antetul documentului).

PENALITĂȚI (penaltyType + contractualPenaltyRate):
  • Returnează `penaltyType="CONTRACTUAL"` ȘI `contractualPenaltyRate` (rata zilnică
    ca procent, ex: 0.1 pentru „0,1% pe zi") STRICT DOAR dacă documentul conține o
    clauză explicită de penalități sau majorări cu rată PER ZI / PER ZI CALENDARISTICĂ,
    formulată explicit în procente pe zi (ex: „penalități de 0,1%/zi de întârziere",
    „0,15% pentru fiecare zi de întârziere", „majorări de 0,1% pe zi calendaristică
    de întârziere").
  • Dacă rata e exprimată PER LUNĂ (ex: „1% pe lună", „1%/lună"), PER AN (ex: „18%
    pe an", „dobândă de întârziere de 18% anual") sau ca SUMĂ FIXĂ (ex: „100 RON
    pe zi"), returnează `penaltyType=null` și `contractualPenaltyRate=null`. NU
    converti rate lunare sau anuale în rate zilnice și NU confunda dobânda
    remuneratorie (pe durata contractului) cu penalitatea de întârziere.
  • Dacă NU există nicio clauză de penalitate sau majorare de întârziere, returnează
    `penaltyType=null` și `contractualPenaltyRate=null`. NU presupune
    `LEGAL_PENALIZATOARE` și NU deduce o rată din context, nici din dobânda legală.
    Câmpul gol = aplicarea valorii implicite din formular (aleasă de avocat).
PROMPT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * Tolerant JSON decoder — strips Markdown code fences and any prose
     * surrounding the JSON body. Returns null when nothing JSON-shaped can
     * be recovered. Identical contract to OcrText's parseAiResponse;
     * deliberate copy rather than shared trait so the two strategies can
     * evolve independently.
     *
     * @return array<string, mixed>|null
     */
    private function parseAiResponse(string $content): ?array
    {
        $trimmed = trim($content);

        if (preg_match('/^```(?:json)?\s*(.+?)\s*```$/s', $trimmed, $m)) {
            $trimmed = trim($m[1]);
        }

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

        if (!isset($decoded['creditor']) && !isset($decoded['debtor']) && !isset($decoded['claim'])) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $raw
     */
    private function buildCreditorFromAi(?array $raw): ?CreditorExtraction
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        return new CreditorExtraction(
            personType: $this->coercePersonType($raw['personType'] ?? null),
            name: $this->coerceString($raw['name'] ?? null),
            cui: $this->coerceString($raw['cui'] ?? null),
            isVatPayer: $this->coerceNullableBool($raw['isVatPayer'] ?? null),
            personalId: $this->coerceString($raw['personalId'] ?? null),
            onrcNumber: $this->coerceString($raw['onrcNumber'] ?? null),
            address: $this->coerceString($raw['address'] ?? null),
            email: $this->coerceEmail($raw['email'] ?? null),
            phone: $this->coercePhone($raw['phone'] ?? null),
            iban: $this->coerceString($raw['iban'] ?? null),
            legalRepresentative: $this->coerceString($raw['legalRepresentative'] ?? null),
            bankName: $this->coerceString($raw['bankName'] ?? null),
            confidencePerField: $this->coerceConfidenceMap($raw['confidencePerField'] ?? null),
        );
    }

    /**
     * @param array<string, mixed>|null $raw
     */
    private function buildDebtorFromAi(?array $raw): ?DebtorExtraction
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        return new DebtorExtraction(
            personType: $this->coercePersonType($raw['personType'] ?? null),
            name: $this->coerceString($raw['name'] ?? null),
            cui: $this->coerceString($raw['cui'] ?? null),
            isVatPayer: $this->coerceNullableBool($raw['isVatPayer'] ?? null),
            personalId: $this->coerceString($raw['personalId'] ?? null),
            onrcNumber: $this->coerceString($raw['onrcNumber'] ?? null),
            address: $this->coerceString($raw['address'] ?? null),
            county: $this->coerceString($raw['county'] ?? null),
            locality: $this->coerceString($raw['locality'] ?? null),
            email: $this->coerceEmail($raw['email'] ?? null),
            phone: $this->coercePhone($raw['phone'] ?? null),
            iban: $this->coerceString($raw['iban'] ?? null),
            administrator: $this->coerceString($raw['administrator'] ?? null),
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

        return new ClaimExtraction(
            amount: $amount,
            currency: $this->coerceString($raw['currency'] ?? null),
            dueDate: $this->coerceDate($raw['dueDate'] ?? null),
            legalGround: $legalGround,
            description: $this->coerceString($raw['description'] ?? null),
            invoiceNumber: $this->coerceString($raw['invoiceNumber'] ?? null),
            invoiceDate: $this->coerceDate($raw['invoiceDate'] ?? null),
            contractNumber: $this->coerceString($raw['contractNumber'] ?? null),
            contractDate: $this->coerceDate($raw['contractDate'] ?? null),
            contractReference: $this->coerceString($raw['contractReference'] ?? null),
            penaltyType: $penaltyType,
            contractualPenaltyRate: $penaltyRate,
            confidencePerField: $this->coerceConfidenceMap($raw['confidencePerField'] ?? null),
        );
    }

    /**
     * Parses an `YYYY-MM-DD` calendar date from AI output. The leading `!` in
     * the format resets time-of-day to 00:00:00 so createFromFormat does not
     * seed the value with the wall-clock current time. Returns null on any
     * non-string, empty, or malformed input.
     */
    private function coerceDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $dt instanceof \DateTimeImmutable ? $dt : null;
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
    ): float {
        return CoverageConfidenceCalculator::compute(
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

    private function zeroConfidence(Document $document): ExtractedDocumentData
    {
        return new ExtractedDocumentData(
            sourceDocumentId: (int) $document->getId(),
            strategy: self::STRATEGY_KEY,
            globalConfidence: 0.0,
            extractedAt: new \DateTimeImmutable(),
            // No rawOcrText — vision doesn't OCR.
            rawOcrText: null,
        );
    }
}
