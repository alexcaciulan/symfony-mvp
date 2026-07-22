# Extracție AI-only multi-document: decizie și plan

Status: VALIDAT parțial. Deciziile 2-5 sunt luate (vezi secțiunea 7). Blocantul 1 (secret profesional, DPA) rămâne deschis.
Data: 2026-07-20, actualizat 2026-07-21 cu deciziile proprietarului de produs.
Metodă: analiză în 3 direcții paralele, sinteză, review adversarial (arhitect Symfony + avocat OP). Corecțiile din review sunt deja aplicate în acest document.

---

## 1. Rezumat executiv

Extracția trece pe un singur motor, AI Vision. `PdfParserExtractionStrategy` și `OcrTextExtractionStrategy` rămân doar pentru conturile existente; conturile noi merg exclusiv pe AI Vision.

Trei probleme de fond se rezolvă simultan:

1. **AI-ul nu știe ce tip de document citește.** Toate fișierele sunt marcate `ALT_DOCUMENT` la încărcare (`CaseWizardController.php:142`) și primesc același prompt. De acum AI-ul clasifică documentul și primește instrucțiuni specifice tipului.
2. **Un dosar are astăzi o singură sumă și o singură scadență.** Cu trei facturi cu scadențe diferite, dobânda este greșit calculată, iar viciul se vede în cerere și poate fi contestat în opoziție. Introducem entitatea `ClaimItem`, o poziție de creanță per factură.
3. **Când două documente se contrazic, sistemul alege tacit maximul pe fiecare câmp separat** (`PrefillFromExtractionService.php:189-210`) și poate fabrica o parte care nu există: nume din contract, CUI din factură. Înlocuim cu agregare coerentă pe grupuri de câmpuri, plus un card de neconcordanțe unde avocatul alege.

**Corecție importantă, descoperită la implementarea T1 (2026-07-21).** Versiunea inițială a acestui plan afirma că `extractionMode` este `LOCAL_ONLY` peste tot, deci că AI Vision nu a rulat niciodată în producție. **Fals.** Default-ul de coloană este `MAX_ACCURACY` (`User.php:106`, migrarea `Version20260721093336`).

Realitatea era inversul, și mai gravă: un cont nou trimitea primul PDF încărcat către Anthropic, cu CNP-uri de debitori în clar, **fără niciun acord înregistrat**. `hasAcceptedAiProcessing()` exista pe entitate, dar nu avea niciun call-site în `src/`. Nu era o funcție inactivă, era o funcție activă fără poartă.

Închis în T1: acordul este acum condiție tehnică în `DataExtractionService`, independentă de mod, aplicată tuturor pipeline-urilor.

---

## 2. Decizii arhitecturale

### D1. Separarea conturilor noi (AI-only) de cele legacy

Opțiuni luate în calcul: feature flag global de mediu; reutilizarea `EXTRACTION_FORCE_STRATEGY`; regulă pe data creării contului; flag pe `LegalCase`; flag pe `User`.

**Ales: câmp nou pe `User`, enum `ExtractionPipeline` cu `LEGACY_CASCADE` și `AI_ONLY`, default de coloană `AI_ONLY`.**

Motivare: default-ul de coloană rezolvă exact cerința "conturile noi merg pe AI Vision" fără cod de dată; migrarea setează explicit `LEGACY_CASCADE` pe rândurile existente, deci conturile vechi nu își schimbă comportamentul; e reversibil per cont printr-un singur UPDATE.

Flag pe `LegalCase` nu merge: documentele din Step 0 se încarcă înainte să existe dosarul. `EXTRACTION_FORCE_STRATEGY` are semantică greșită: `DataExtractionService.php:180-197` face bypass la `supports()`, deci ar sări inclusiv garda de cheie API lipsă din `AiVisionExtractionStrategy.php:99-101`.

Implementare: `resolvePipeline(Document)` în `DataExtractionService`, care refolosește lanțul existent dosar, user, `uploadedBy` din `resolveExtractionMode()`, plus un `continue` în bucla de strategii pentru orice cheie diferită de `ai_vision` când pipeline-ul e `AI_ONLY`. Nu se atinge tag-ul `app.extraction_strategy`, nu se atinge `priority()`, nu se atinge DI.

### D2. Temeiul juridic al transferului către Anthropic

Aceasta este decizia cea mai delicată, și prima versiune a planului a greșit-o. Corectat după review.

**Nu este o problemă de consimțământ GDPR art. 7.** Persoana vizată din documente este **debitorul** (CNP, adresă), nu utilizatorul-avocat. Consimțământul avocatului nu are nicio valoare juridică față de debitor.

**Arhitectura corectă:**
- avocatul este **operator**, platforma este **persoană împuternicită** (GDPR art. 28), Anthropic este **sub-împuternicit**;
- temeiul față de debitor este interesul legitim sau executarea contractului, **nu** consimțământul;
- bifa din UI nu este "consimțământ GDPR", este **acordul avocatului la sub-împuternicire** plus asumarea unei derogări de la secretul profesional.

**Riscul principal nu e GDPR, e secretul profesional al avocatului** (Legea 51/1995 art. 11 + Statutul profesiei). Trimiterea contractului unui client către un procesator din SUA poate constitui divulgare a secretului profesional dacă clientul avocatului nu a acceptat-o. Un avocat prudent nu va bifa până nu are asta rezolvat.

Ce trebuie să existe înainte de livrare:
- contract art. 28 platformă-avocat;
- clauze contractuale standard art. 46 pentru transferul în SUA;
- DPA cu Anthropic;
- mențiune în registrul de prelucrări art. 30;
- politică de retenție (Anthropic reține 30 de zile, aluzie deja în `AnthropicApiClient.php:37`);
- clarificarea cine face informarea art. 13/14 către debitor (răspunsul corect: avocatul, nu platforma).

Se persistă `aiProcessingAgreementAt` și `aiProcessingAgreementVersion` pe `User`, ca dovadă a acordului de sub-împuternicire.

`LOCAL_ONLY` rămâne o alegere validă. În pipeline AI-only înseamnă că nu rulează nicio strategie, dar rezultatul **nu** trebuie să fie `FAILED` (sugerează defecțiune). Se adaugă `ExtractionStatus::SKIPPED_BY_POLICY`, iar Step 0 afișează "Extracția automată este dezactivată în setările tale de confidențialitate", cu link spre setări. Completarea manuală rămâne un flux valid. Conturile legacy pe `LOCAL_ONLY` rămân pe `LEGACY_CASCADE` și nu se ating.

### D3. Clasificarea tipului de document

Opțiuni: apel LLM separat de clasificare; clasificare în același apel cu extracția; alegere manuală în UI.

**Ales: clasificare în același apel, cu override manual opțional în UI.**

Cost: documentul pleacă ca bloc binar. Un apel separat retrimite același payload și dublează tokenii de input, care domină costul la scanuri, pentru zero câștig de acuratețe. Latență: `AnthropicApiClient::REQUEST_TIMEOUT` este 60s iar un apel vision durează 20-30s; la 10 documente în Step 0, un al doilea apel per document adaugă minute de coadă Messenger.

Pe `Document` se adaugă `detectedType` (nullable) și `detectedTypeConfidence`, separate de `type` ales de utilizator. Nu se suprascrie `type` automat sub prag; peste 0.7 și când `type` este încă `alt_document`, se promovează automat. Se păstrează ambele pentru trasabilitate.

`DocumentType` se extinde cu (valori lowercase snake_case, conform convenției existente): `'extras_cont'`, `'confirmare_sold'`, `'proces_verbal'`, `'comanda'`, `'notificare'`, `'somatie_anterioara'`, `'act_aditional'`, `'titlu_valoare'`.

`somatie_anterioara` este obligatoriu distinct de `somatie`, pentru că `SOMATIE` e marcat `isAutoGenerated()` și nu apare în `uploadableTypes()`.

### D4. Structura prompturilor

**Ales: registry tagged `app.extraction_prompt`, cu același mecanism ca `app.extraction_strategy`.**

`ExtractionPromptInterface` cu `supports(DocumentType)`, `priority()`, `systemPrompt()`, `userInstructions()`, `outputSchema()`, `maxTokens()`. Fragmentele comune (glosar roluri creditor/debitor, reguli de adresă, reguli de penalitate, reguli de confidence, enum `LegalGroundCategory`) într-un `SharedPromptFragments`, ca să rămână identice byte cu byte.

Ordinea conține o decizie de cost: tot ce e stabil merge în `system` (identic pentru toate tipurile), instrucțiunile per tip în `user`, după blocul de document. Prefixul minim cacheabil este 1024 tokeni pe Opus 4.8; glosarul actual îl depășește confortabil, deci consolidarea în `system` face diferența între cache read (aproximativ 0.1x) și plata integrală pe fiecare dintre cele 10 documente. Cache write costă 1.25x, deci pragul de rentabilitate e la 2 documente. Cu Opus 4.8 la $5/$25, câștigul din caching e mai mare în valoare absolută decât ar fi fost pe Sonnet.

`outputSchema()` din interfață alimentează `output_config.format` (structured outputs), disponibil pe Opus 4.8. Modelul garantează conformitatea cu schema, deci `parseAiResponse()` nu mai are nevoie de parser tolerant cu reparare de JSON: rămâne doar `json_decode` plus validarea semantică a valorilor (enum-uri, date, sume).

**Fluxul în două faze**, care rezolvă circularitatea clasificare-vs-prompt-specializat: prima trecere pe un document necunoscut folosește `GenericDocumentPrompt`, care clasifică și extrage în același apel. Promptul specializat se folosește doar când tipul e deja cunoscut (ales la upload, sau corectat de avocat cu reprocesare explicită). Nu se face al doilea apel automat.

**Corecție din review (B1):** `cache_control` se pune pe blocuri de text, deci `AnthropicApiClient.php:198-199`, care face `$body['system'] = implode("\n\n", $systemParts)`, trebuie schimbat din string simplu în listă de blocuri. `LlmResponse` (azi 4 câmpuri: `content`, `tokensIn`, `tokensOut`, `finishReason`) primește `cacheReadInputTokens` și `cacheCreationInputTokens`, altfel criteriul de acceptare al T3 e neverificabil. `LlmClientInterface::complete()` primește un mod de a marca breakpoint-ul de cache.

### D5. Modelul de date pentru creanța multi-factură

**Ales: entitate `ClaimItem`**, cu `LegalCase::$amount`, `$dueDate`, `$invoiceNumber`, `$invoiceDate` păstrate ca denormalizări calculate, nu ca sursă de adevăr.

Entitate și nu JSON: pentru agregarea SQL în dashboard, ordonarea stabilă în tabelul din cerere și opis, și constraint-ul unic necesar deduplicării.

Denormalizări, recalculate într-un singur loc (`ClaimTotalsService`):
- `dueDate = MIN(item.dueDate)`, relevantă pentru prescripție și pentru garda de exigibilitate;
- `invoiceNumber` / `invoiceDate` de la itemul cu cea mai veche scadență, doar pentru compatibilitatea template-urilor existente.

**Corecție din review (B4) privind `amount`:** formula inițială `SUM(item.amount - paidAmount)` decidea tacit imputația plății pe capital, contrazicând art. 1507-1509 C.civ., care impune în lipsa acordului ordinea cheltuieli, dobânzi, capital. Efectul ar fi fost că creditorul cere mai puțin decât i se cuvine, iar debitorul poate arăta în opoziție că însuși calculul reclamantului contrazice legea.

**Decizie corectată:** `paidAmount` **nu** reduce automat principalul. Se marchează ca plată neimputată, cu conflict WARNING "plată parțială neimputată", iar alocarea rămâne explicit a avocatului. `amount = SUM(item.amount)`. Vezi întrebarea deschisă 6.

Conversia FX se face per item, la cursul BNR din data facturii acelui item, în `ClaimItemFactory`. `CurrencyConverter::convertToRon(float, string, DateTimeInterface)` acceptă deja o dată istorică, deci nu cere serviciu nou. Vezi însă I4 la riscuri.

Dobânda: `InterestCalculatorService` și `ContractualPenaltyCalculator` rămân neschimbate (sunt corecte per principal și scadență, și segmentează deja pe schimbările de rată BNR). Deasupra lor se adaugă `ClaimInterestAggregator`, care iterează itemii, calculează per item și rotunjește o singură dată la final. Itemii fără scadență sau cu sold zero intră în `skippedItemIds` și se semnalează.

Taxa de timbru rămâne 200 RON fix, independentă de numărul de facturi (OUG 80/2013 art. 6 alin. 2). `StampDutyCalculator::calculate()` nu primește niciun argument, deci rămâne neatins.

### D5b. Competența teritorială la multi-factură

**Corecție din review (B3).** Versiunea inițială spunea "competența se rezolvă pe totalul principal (art. 98 alin. 2)". Citarea era greșită și regula incompletă.

Art. 98 alin. (2) spune că la stabilirea valorii nu se au în vedere accesoriile. Asta e deja implementat corect în `CompetentCourtResolver.php:18-21`, pentru excluderea accesoriilor, nu pentru însumare.

Regula de însumare este **art. 99 CPC**:
- alin. (1): cumul când capetele sunt întemeiate pe **același** titlu sau cauză;
- alin. (2): când capetele principale sunt întemeiate pe **fapte ori cauze diferite**, competența se stabilește în raport cu valoarea sau natura **fiecărei** pretenții.

Exemplu concret al riscului: 5 facturi din 5 contracte diferite, 60.000 RON fiecare. Rezolvarea pe total (300.000) trimite la tribunal; art. 99(2) spune că fiecare pretenție se evaluează separat, deci judecătorie. Cerere depusă la instanța greșită = excepție de necompetență materială, ordine publică, invocabilă din oficiu.

**Decizie:** `ClaimItem` poartă legătura cu titlul sau cauza (contractul sursă). Rezolvarea competenței devine: același contract sau aceeași cauză, cumul; cauze diferite, per pretenție, cu conflict ERROR când rezultatele diverg.

### D6. Algoritmul de agregare coerentă

**Ales: agregare coerentă deterministică. Reconcilierea LLM se amână.**

`FieldAuthorityMatrix` dă o pondere pe perechea (tip document, grup de câmpuri), unde grupurile sunt `PARTY_IDENTITY`, `PARTY_CONTACT`, `PARTY_BANKING`, `CLAIM_AMOUNT`, `CLAIM_BASIS`, `CLAIM_PENALTY`. Factura e autoritară pe sumă și scadență, contractul pe temei și penalitate, extrasul de cont pe date bancare.

`CoherentAggregator` alege un document câștigător **per grup**, după scorul `autoritate * 1000 + confidence * 10 + acoperire`, cu tie-break determinist pe `documentId ASC`, și ia grupul **întreg** de la el. Completarea din alte documente se face doar pentru câmpurile lipsă la câștigător și doar dacă trece garda anti-himeră: CUI-uri diferite sau CNP-uri diferite blochează completarea; fără CUI comun se cade pe similaritate de nume peste 0.85.

`loadDocuments()` din `PrefillFromExtractionService` primește `ORDER BY id ASC`, altfel tie-break-ul e nedeterminist.

Multi-debitor: `ExtractedDocumentData::$debtor` devine listă `$debtors`, cu citire tolerantă la forma veche (obiect singular normalizat la listă de un element). `aggregateForDebtors()` **există deja** (`PrefillFromExtractionService.php:85-88`, returnează azi exact o intrare) și se **modifică**, nu se creează: face clustering peste toți candidații (prag de identitate 0.90, mai strict decât pragul de completare 0.85, pentru că a separa greșit doi debitori costă două click-uri, iar a-i contopi greșit produce o cerere cu parte greșit identificată), apoi rulează `CoherentAggregator` în interiorul fiecărui cluster.

**De ce se amână reconcilierea LLM:** ar rezolva potrivirile fuzzy ("SC ALFA SRL" vs "Alfa S.R.L.", "achitare parțială fact. 123" spre factura MJ-123/2025) și costă puțin, fiind text-only peste JSON. Dar adaugă un al doilea transfer de date către Anthropic și nedeterminism într-un pas care produce cifre juridice. Se reevaluează după ce agregarea deterministică rulează în producție. Dacă se implementează, JSON-ul trece obligatoriu prin `PiiMasker` cu restore la întoarcere, exact ca la `OcrTextExtractionStrategy`.

### D7. Deduplicarea

Două niveluri, complementare, ambele necesare.

**Nivel fișier:** `Document::$contentHash`, sha256 al conținutului, calculat în `DocumentUploadService::upload()` după `move()` și după validarea MIME. La upload în Step 0, dacă hash-ul există deja pentru același utilizator în bagul curent, fișierul se șterge, nu se creează `Document`, nu se dispecerizează `ExtractDataMessage`, și se afișează un avertisment. Câștig direct: nu se plătește un apel LLM pentru un fișier deja extras.

**Nivel poziție de creanță:** `ClaimItem::$dedupKey`, cu cheie tare `inv:sha1(numarFacturaNormalizat|cuiEmitent)` și cheie slabă `amt:sha1(data|suma|valuta)` când nu există număr de factură. Normalizarea scoate spații, `#`, `NR.`, și zerourile din fața segmentului numeric, ca `FF 0012/2025` și `FF12/2025` să colideze.

**Duplicatele nu se însumează niciodată.** Se păstrează itemul primar (cel mai autoritar tip de document, apoi confidence, apoi `documentId`), se completează câmpurile lipsă din duplicate, și orice divergență de sumă sau scadență produce conflict blocant. Colapsarea pe cheia slabă `amt:` produce întotdeauna warning cu buton "sunt două facturi diferite", pentru că două facturi legitime pot avea aceeași zi și aceeași sumă.

Constraint unic `(legal_case_id, dedup_key)` în migrare, ca plasă de siguranță.

Notă de nomenclatură: `src/Entity/Invoice.php` și `FiscalInvoice` există deja, pentru facturarea abonamentului SaaS. Termenul "invoice" e ocupat în domeniu. `ClaimItem` este alegerea corectă; `InvoiceExtraction` din stratul de extracție se referă la factura-creanță a clientului, nu la factura noastră.

### D8. Detectarea și afișarea conflictelor

Astăzi conflictele nu sunt detectate nicăieri. Este cea mai gravă problemă din pipeline, mai gravă decât promptul unic.

**Ales: model explicit de conflict, cu severități, rezolvat de avocat în wizard, nu automat.**

`PrefillConflict` cu `scope` (creditor / debtor / claim / claim_item / debtor_set), `field`, `entityKey`, `severity`, `messageKey`, listă de `ConflictOption` (valoare, document sursă, tip document, confidence) și indexul opțiunii sugerate.

| Regulă | Scope | Severitate |
|---|---|---|
| Două CUI diferite pentru aceeași parte | creditor / debtor | ERROR |
| Două CNP diferite pentru aceeași parte | debtor | ERROR |
| Două sume diferite pe același `dedupKey` | claim_item | ERROR |
| Două scadențe diferite pe același `dedupKey` | claim_item | ERROR |
| Scadență în viitor (art. 1013 alin. 1 CPC, creanță neexigibilă) | claim_item | ERROR |
| Peste 5 clustere de debitori | debtor_set | ERROR |
| Debitori cu seturi disjuncte de facturi | debtor_set | ERROR |
| Competență divergentă între pretenții cu cauze diferite (art. 99 alin. 2) | claim | ERROR |
| Conversie FX eșuată (curs BNR indisponibil) | claim_item | ERROR |
| `PenaltyType` diferit între contract și alte surse | claim | WARNING |
| Rată penalitate diferită între surse | claim | WARNING |
| Nume diferite cu același CUI | creditor / debtor | WARNING |
| Adrese diferite pentru debitor (determină competența) | debtor | WARNING |
| Colapsare pe cheie slabă `amt:` | claim_item | WARNING |
| Plată parțială neimputată | claim_item | WARNING |
| Item fără scadență (exclus din dobândă) | claim_item | WARNING |
| `dueDate` anterior lui `invoiceDate` | claim_item | WARNING |
| Cea mai veche scadență peste 3 ani (prescripție, art. 2517 C.civ.) | claim | WARNING |
| Doi debitori detectați | debtor_set | INFO |

`ERROR` blochează avansarea din Step 0 către Step 1 și reapare la Step 4. `WARNING` și `INFO` nu blochează.

**Corecție din review (B5):** regula de exigibilitate este art. 1013 alin. (1), nu art. 1014 (care este comunicarea somației, termen 15 zile). Mai important, regula **există deja** în `OpAdmissibilityValidator.php:19,59-70`, cu `OP_DEBT_NOT_YET_DUE` la nivel de dosar. Nu se creează a doua implementare: se **extinde** `OpAdmissibilityValidator` să itereze `ClaimItem`. Altfel apar două surse de adevăr pe o condiție de admisibilitate, care pot diverge (`LegalCase.dueDate = MIN(item.dueDate)` va fi mereu în trecut dacă o singură factură e scadentă, deci validatorul ar trece în timp ce conflictul per item blochează wizardul).

UI: card "Neconcordanțe detectate" sub cardul existent "Date detectate" din `_step0_sidecard_partial.html.twig`, cu radio per variantă, numele fișierului sursă, tipul documentului și procentul de încredere, varianta sugerată preselectată, plus opțiune "Introduc eu valoarea". Alegerea se persistă în bagul de sesiune sub `conflictResolutions` și se aplică drept override cu prioritate maximă înainte de agregare. La `persistWizard()`, rezolvările intră în `auditLog` sub `conflict_resolutions`, alături de `fields_auto` și `fields_manual`. Este relevant profesional: avocatul trebuie să poată proba de ce a reținut o valoare și nu alta.

Notă juridică: doi debitori pe același dosar de ordonanță de plată sunt corecți doar în solidaritate sau coproprietate pe aceeași obligație. Doi debitori cu facturi separate înseamnă două dosare, două taxe de timbru și posibil două instanțe competente. Vezi întrebarea deschisă 3.

### D9. Comportamentul la eșec AI

Astăzi fiecare mod de eșec întoarce `zeroConfidence()` și cascada cade pe Stub. În AI-only, Stub nu mai rulează, `$bestSoFar` rămâne null și rezultatul e `FAILED` cu confidence 0. Statusul e corect, dar **cauza se pierde**: avocatul vede același badge pentru "nu avem cheie API" (problemă de ops) și pentru "PDF-ul tău e prea mare" (acționabil).

**Ales: enum `ExtractionFailureReason` persistat pe `Document`**, cu `API_UNAVAILABLE`, `RATE_LIMIT_EXCEEDED`, `FILE_TOO_LARGE`, `FILE_UNREADABLE`, `RESPONSE_TRUNCATED`, `RESPONSE_MALFORMED`, `UNSUPPORTED_MIME`, `LOCAL_ONLY_MODE`. `zeroConfidence()` primește motivul ca argument în toate cele 6 call-site-uri (`:123`, `:136`, `:144`, `:156`, `:187`, `:198`).

`RESPONSE_TRUNCATED` e distinct și important. `finishReason` este azi doar scris în audit log (`AiVisionExtractionStrategy.php:219`), dar nu gatează nimic: un răspuns tăiat cade în `parseAiResponse()`, eșuează la verificarea `}` final și arată identic cu un JSON corupt. Se verifică explicit înainte de parsare.

Retriabil vs permanent în `ExtractDataMessageHandler`, care azi înghite tot și face ACK deliberat: `API_UNAVAILABLE` și `RATE_LIMIT_EXCEEDED` se re-aruncă drept `RecoverableMessageHandlingException` (pentru rate limit cu delay lung, nu backoff exponențial scurt, altfel se arde bugetul). Restul rămân FAILED plus ACK.

**Corecție din review (I8):** `DataExtractionService::extract()` persistă rezultatul înainte să returneze, iar handler-ul face `flush()` imediat după. Dacă handler-ul re-aruncă după acel flush, documentul rămâne vizibil `FAILED` în UI cât timp mesajul stă în retry, apoi devine `COMPLETED` la a doua încercare. Se adaugă `ExtractionStatus::PENDING_RETRY`, setat înainte de re-aruncare.

UI: badge-ul din Step 0 afișează motivul tradus plus acțiunea. Butonul "reîncearcă extracția" apare doar pe motivele tranzitorii.

### D10. Praguri, limite și costuri

**Pragul 0.6 se redefinește, nu se șterge.** Cu o singură strategie nu mai există "treapta următoare", deci pragul nu mai decide nimic în cascadă. Devine `EXTRACTION_REVIEW_THRESHOLD`: sub el, extracția se marchează "necesită verificare" în UI.

**Coverage devine dăunător odată cu multi-tip.** `CoverageConfidenceCalculator` împarte suma încrederilor la un numitor fix. Cifrele reale (corectate după review, I2): `CORE_CREDITOR_FIELDS` are 10 elemente, `CORE_DEBTOR_FIELDS` 12, `CORE_CLAIM_FIELDS` 5, total 27; numitorul 25 vine din `EXPECTED_CREDITOR_FIELDS = 10` + `EXPECTED_DEBTOR_FIELDS = 10` + `EXPECTED_CLAIM_FIELDS = 5`, iar docblock-ul de la `:36-39` documentează divergența 27 vs 25 ca decizie deliberată, cu clamp la 1.0.

Problema: un extras de cont sau un proces-verbal, care legitim nu conține date de creditor, primește mecanic un scor mic și e tratat ca extracție proastă. Se adaugă o metodă nouă `computeForType(DocumentType, ...)` cu hărți de câmpuri așteptate per tip. **Nu** se schimbă semnătura lui `compute()`, care e `static` și apelat din `PdfParserExtractionStrategy.php:1150` și `OcrTextExtractionStrategy.php:527` (corecție din review, I1: altfel cele două strategii "doar deprecate" se sparg).

**`MAX_TOKENS = 2048` este prea mic.** Un `InvoiceExtraction` complet cu `confidencePerField` e 250-350 tokeni JSON; creditor plus debitor plus contract plus 5 facturi depășește 2500. Devine per prompt, via `ExtractionPromptInterface::maxTokens()`: 8192 implicit, 4096 pentru contract / somație / extras, 16000 pentru factură și borderou.

**`MAX_FILE_BYTES = 3_700_000` e corect pentru imagini și greșit pentru PDF.** Limita derivă din plafonul de 5 MB pe payload base64 al blocurilor `image`. Blocurile `document` (PDF nativ) au 32 MB pe request și 600 pagini pe context 1M. Rezultat actual: PDF-uri de 5 MB perfect procesabile cad în `zeroConfidence()`. Se sparge în `MAX_IMAGE_BYTES = 3_700_000` și `MAX_PDF_BYTES`.

**Corecție din review (I5):** valoarea de 20 MB pentru PDF nu a fost verificată față de `memory_limit=256M` (`Dockerfile:47`). Fluxul face `file_get_contents` + `base64_encode`, apoi `json_encode` pe tot body-ul: pentru 20 MB înseamnă vârf realist 80-120 MB pentru un singur document. Se **măsoară** înainte de a fixa valoarea, în T1. Dacă nu încape confortabil, se pornește de la 10 MB și se trece pe Files API (T7) mai devreme.

**`REQUEST_TIMEOUT = 60` e subdimensionat** pentru un PDF de 20 de pagini. Se ridică la 300 pentru apelurile vision.

**Rate limit `extraction_ai_vision` 50/zi.** La 5-10 documente per dosar înseamnă 5-10 dosare pe zi, plauzibil în regim normal, dar exact ziua de onboarding, când avocatul își încarcă portofoliul, îl lovește. Se ridică la 200/zi (aliniat cu `extraction_ai_text`), plus un limiter secundar pe minut anti-burst, și contorul rămas se expune în UI.

Precizare (corecție din review, I6): un document respins pentru dimensiune sau MIME **nu** consumă contorul, pentru că limiterul e după gărzile de fișier (`:118-156`). Doar apelurile care ajung efectiv la LLM îl consumă. Argumentul pentru 200/zi rămâne valid pe onboarding.

Costul real, la vreo 8 documente PDF pe dosar, e de ordinul câtorva cenți pe dosar, deci limita se calibrează pe abuz, nu pe cost.

### D11. Ce se păstrează din PdfParser / OcrText / Stub

Toate trei rămân servicii înregistrate și tagged. Auto-tagging-ul e pe `_instanceof` pe interfață, deci de-registrarea ar cere excludere explicită: mai mult cod pentru zero câștig. Se marchează `@deprecated Legacy pipeline only; new accounts use ai_vision.` în docblock.

Atenție: `OcrTextExtractionStrategy` **nu** e "locală", folosește Claude pe text mascat. Contorul ei `extraction_ai_text` 200/zi devine mort pentru conturi noi.

Stub rămâne în DI, dar în AI-only nu mai e plasă de siguranță. Comentariul din `DataExtractionService.php:237-239`, "In production the Stub strategy ensures $bestSoFar is never null", devine fals și trebuie corectat.

**Citirea datelor vechi: zero migrare de date, și trebuie să rămână așa.** `PrefillFromExtractionService` citește JSON-ul agnostic față de strategie și nu ramifică nicăieri pe `extractionStrategy`. Documentele extrase cu `pdf_parser` sau `ocr_text` continuă să se preumple identic. Nu se rescrie `extractionStrategy` în bază: e evidență de proveniență, valoroasă exact când cineva întreabă de ce un dosar vechi are date de calitate diferită. `ExtractedDocumentData` primește `schemaVersion` (2), fără de care nu poți distinge "document vechi cu claim plat" de "document nou fără facturi".

### D12. Confirmarea pozițiilor de creanță de către avocat

Opțiuni: bifă explicită per `ClaimItem`; intrare automată peste prag cu posibilitate de excludere; confirmare pe tabel.

**Ales: confirmare pe tabel, cu excepție pentru itemii riscanți.**

Bifa per item costă N click-uri la fiecare dosar, ceea ce în practică duce la bifat mecanic, deci la o protecție iluzorie. Intrarea complet automată lasă avocatul să semneze poziții pe care nu le-a văzut individual.

Mecanismul:
- Step 3 afișează tabelul complet al pozițiilor: număr factură, dată emitere, scadență, sumă, valută, document sursă, dobândă calculată.
- Un singur checkbox obligatoriu, "Am verificat pozițiile de creanță", confirmă întregul tabel și setează `confirmedByLawyer = true` pe toți itemii fără probleme.
- **Excepție, bifă individuală obligatorie:** itemii cu conflict ERROR nerezolvat, itemii sub `EXTRACTION_REVIEW_THRESHOLD`, itemii colapsați pe cheia slabă `amt:`, și itemii cu plată parțială neimputată. Acolo unde e riscul, atenția e forțată.
- Fiecare rând are acțiune de excludere ("nu include această factură în cerere"), care setează `excludedByLawyer` fără să șteargă itemul, păstrând urma.

`auditLog` reține la `persistWizard()` ce a fost confirmat pe tabel, ce a fost confirmat individual și ce a fost exclus, deci probatoriu este echivalent cu bifa per item.

Un `ClaimItem` neconfirmat sau exclus nu intră în totaluri, nu intră în petitum și nu apare în opis.

---

## 3. Contradicții între analize, transate

**C1. Prompt specializat per tip vs clasificare în același apel.** Circular dacă ambele se aplică la prima trecere. Transat: `GenericDocumentPrompt` clasifică și extrage simultan la prima trecere; promptul specializat doar când tipul e deja cunoscut. Fără al doilea apel automat.

**C2. Reconciliere LLM vs agregare deterministică.** Transat în favoarea deterministicului pentru livrare, cu LLM amânat: pasul LLM adaugă un al doilea transfer de date și nedeterminism într-un calcul cu efecte juridice.

**C3. `MAX_TOKENS` unic vs per tip.** Transat pe per tip, cu 8192 ca implicit. Un borderou cu 8 facturi are nevoie de 16000; un contract nu, și ar plăti degeaba riscul de timeout.

**C4. Un debitor vs listă de debitori.** Transat pe listă: cauza rădăcină a entității himerice este chiar câmpul singular. Payload-urile vechi (obiect singular) se normalizează la listă de un element.

Deduplicarea la două niveluri nu era o contradicție reală, ci două mecanisme complementare, integrate în D7.

---

## 4. Impact asupra codului

| Fișier | Tip | Ce se schimbă |
|---|---|---|
| `src/Enum/ExtractionPipeline.php` | nou | `LEGACY_CASCADE` / `AI_ONLY` cu `label()` |
| `src/Enum/ExtractionFailureReason.php` | nou | 8 motive de eșec cu `label()` |
| `src/Enum/ClaimItemKind.php` | nou | `INVOICE` / `CONTRACT_INSTALMENT` / `OTHER` |
| `src/Enum/FieldGroup.php` | nou | 6 grupuri de câmpuri pentru matricea de autoritate |
| `src/Enum/ConflictSeverity.php` | nou | `ERROR` / `WARNING` / `INFO` |
| `src/Enum/ConflictScope.php` | nou | 5 scope-uri |
| `src/Enum/ExtractionStatus.php` | modificat | adaugă `SKIPPED_BY_POLICY` și `PENDING_RETRY` |
| `src/Enum/DocumentType.php` | modificat | 8 tipuri noi (lowercase snake_case); `uploadableTypes()` extins |
| `src/Entity/User.php` | modificat | `extractionPipeline`, `aiProcessingAgreementAt`, `aiProcessingAgreementVersion` |
| `src/Entity/Document.php` | modificat | `contentHash`, `detectedType`, `detectedTypeConfidence`, `extractionFailureReason` |
| `src/Entity/ClaimItem.php` | nou | poziție de creanță, `dedupKey`, FX per item, proveniență, legătură cu titlul/cauza, `confirmedByLawyer`, `excludedByLawyer` |
| `src/Entity/LegalCase.php` | modificat | relație `claimItems`; scalarii devin denormalizări documentate |
| `src/Repository/ClaimItemRepository.php` | nou | interogări per dosar, ordonate după scadență |
| `src/Service/Extraction/DataExtractionService.php` | modificat | filtru de pipeline; persistă `failureReason`; corectat comentariul Stub |
| `src/Service/Extraction/AiVisionExtractionStrategy.php` | modificat | prompt din registry; motive de eșec; gardă `finishReason`; split constante fișier; `maxTokens` per prompt |
| `src/Service/Extraction/PdfParserExtractionStrategy.php` | deprecat | docblock `@deprecated` |
| `src/Service/Extraction/OcrTextExtractionStrategy.php` | deprecat | docblock `@deprecated` |
| `src/Service/Extraction/StubExtractionStrategy.php` | modificat | comentariu; rămâne în DI |
| `src/Service/Extraction/CoverageConfidenceCalculator.php` | modificat | metodă nouă `computeForType()`; `compute()` rămâne intact |
| `src/Service/Extraction/PrefillFromExtractionService.php` | modificat | `pickBest()` înlocuit; `ORDER BY id ASC`; `aggregateForDebtors()` cu clustering |
| `src/Service/Extraction/CoherentAggregator.php` | nou | agregare pe grupuri, gardă anti-himeră |
| `src/Service/Extraction/FieldAuthorityMatrix.php` | nou | ponderi (tip document, grup câmp) |
| `src/Service/Extraction/ClaimItemDeduplicator.php` | nou | `dedupKey()` + colapsare cu conflicte |
| `src/Service/Extraction/Prompt/*.php` | noi | interfață, registry, fragmente comune, 6 prompturi |
| `src/Service/Case/ClaimTotalsService.php` | nou | `recalculate()` + `totals()` |
| `src/Service/Calculation/ClaimInterestAggregator.php` | nou | dobândă și penalitate per item, rotunjire finală unică |
| `src/Service/Calculation/InterestCalculatorService.php` | neschimbat | apelat per item |
| `src/Service/Calculation/ContractualPenaltyCalculator.php` | neschimbat | apelat per item |
| `src/Service/Calculation/StampDutyCalculator.php` | neschimbat | 200 RON fix |
| `src/Service/Court/CompetentCourtResolver.php` | modificat | regula art. 99: cumul pe aceeași cauză, per pretenție pe cauze diferite |
| `src/Service/Validation/OpAdmissibilityValidator.php` | modificat | iterează `ClaimItem` pentru exigibilitate |
| `src/Service/Document/DocumentUploadService.php` | modificat | calcul `contentHash` după validarea MIME |
| `src/Service/Document/SummonsContextBuilder.php` | modificat | totaluri din `ClaimTotalsService`; tabel per factură |
| `src/Service/Document/PaymentOrderRequestGeneratorService.php` | modificat | petitum enumeră facturile individual (art. 1016 alin. 1 lit. c CPC) |
| `src/Service/Document/OpisGeneratorService.php` | modificat | corelează pozițiile cu `ClaimItem.sourceDocument` |
| `src/Service/Llm/AnthropicApiClient.php` | modificat | `system` ca listă de blocuri; `cache_control`; timeout 300 |
| `src/DTO/Llm/LlmResponse.php` | modificat | `cacheReadInputTokens`, `cacheCreationInputTokens` |
| `src/DTO/Extraction/ExtractedDocumentData.php` | modificat | `classification`, `invoices[]`, `contract`, `payments[]`, `priorNotice`, `acknowledgement`, `debtors[]`, `failureReason`, `schemaVersion` |
| `src/DTO/Extraction/*.php` | noi | `DocumentClassification`, `InvoiceExtraction`, `ContractExtraction`, `PaymentExtraction`, `PriorNoticeExtraction`, `AcknowledgementExtraction`, `ClaimItemDraft`, `PrefillConflict`, `ConflictOption`, `WizardPrefillResult` |
| `src/DTO/Extraction/ClaimExtraction.php` | deprecat | păstrat pentru `schemaVersion` 1 |
| `src/DTO/Calculation/ClaimTotals.php`, `AggregatedAccessoryResult.php` | noi | totaluri, accesorii per item |
| `src/DTO/Wizard/Step2DebtorsData.php` | modificat | primește N intrări din prefill |
| `src/DTO/Wizard/Step3ClaimData.php` | modificat | derivat din itemi |
| `src/MessageHandler/ExtractDataMessageHandler.php` | modificat | retriabil vs permanent; `PENDING_RETRY`; promovare `detectedType` |
| `src/Controller/Case/CaseWizardController.php` | modificat | tip din clasificare; dedup la upload; totaluri; competență art. 99; `conflictResolutions` |
| `src/Twig/Components/ExtractionConflictsLiveComponent.php` | nou | `LiveAction resolveConflict()` |
| `src/Twig/Components/Step2DebtorsLiveComponent.php` | neschimbat | `MAX_DEBTORS = 5` rămâne (`:44`) |
| `templates/case/_step0_sidecard_partial.html.twig` | modificat | card "Neconcordanțe detectate" |
| `config/packages/rate_limiter.yaml` | modificat | `extraction_ai_vision` 200/zi + limiter pe minut |
| `config/services.yaml` | modificat | tag `app.extraction_prompt`; rebotezare prag |
| `.env` | modificat | `EXTRACTION_REVIEW_THRESHOLD`; `ANTHROPIC_MODEL=claude-opus-4-8` |
| `translations/messages.ro.yaml` + `.en.yaml` | modificate | `wizard.conflict.*`, `enum.extraction_failure_reason.*`, `enum.extraction_pipeline.*`, `enum.claim_item_kind.*`, tipuri noi, copy acord procesare |

### Migrări Doctrine

- **M1** (T1): `document.content_hash` + index `(uploaded_by_id, content_hash)`; `document.detected_type`, `detected_type_confidence`, `extraction_failure_reason`; `user.extraction_pipeline` (default `AI_ONLY`) cu `UPDATE user SET extraction_pipeline = 'LEGACY_CASCADE'` pe rândurile existente; `user.ai_processing_agreement_at`, `ai_processing_agreement_version`.
- **M2** (T4): `CREATE TABLE claim_item` cu FK către `legal_case`, `debtor`, `document`; unic `(legal_case_id, dedup_key)`; index `(legal_case_id, due_date)`; backfill din `legal_case.amount` pentru dosarele existente, cu `dedup_key = CONCAT('legacy:', id)`, `confirmed_by_lawyer = 1`.
- **M3** (T7, opțională): `document.parent_document_id`, `document.page_range`.

Câmpurile `legal_case.amount`, `due_date`, `invoice_number`, `invoice_date` **nu se șterg** în acest ciclu.

---

## 5. Plan de implementare pe tranșe

Fiecare tranșă lasă aplicația funcțională și poate fi comisă singură.

### T1. Pipeline AI-only, acord de procesare și diagnostic de eșec (3 zile)

Obiectiv: conturile noi rulează AI Vision, cele vechi rămân pe cascadă, iar când AI-ul eșuează avocatul află de ce.

Fișiere: enum-urile noi, `User`, `Document`, M1, `DataExtractionService`, `AiVisionExtractionStrategy`, `ExtractDataMessageHandler`, `AnthropicApiClient`, `rate_limiter.yaml`, formularul de acord, `.env`, traduceri. Include **măsurarea consumului de memorie** pentru a fixa `MAX_PDF_BYTES`.

Acceptare: un cont nou, după acordul de procesare, încarcă un PDF și primește `extractionStrategy = ai_vision`; un cont `LEGACY_CASCADE` primește în continuare `pdf_parser`; un cont fără acord primește `SKIPPED_BY_POLICY` cu mesaj de setări, nu `FAILED`; un PDF de 5 MB se procesează (azi cade); un fișier peste limită dă `FILE_TOO_LARGE` cu text acționabil; cu cheia API goală, mesajul intră în retry cu status `PENDING_RETRY`, nu `FAILED` tăcut.

### T2. Deduplicarea fișierelor la upload (1 zi)

Obiectiv: același fișier urcat de două ori nu mai costă două apeluri LLM.

Fișiere: `DocumentUploadService`, `CaseWizardController`, `_step0_upload_stream.html.twig`, traduceri.

Acceptare: al doilea upload al aceluiași fișier nu creează `Document`, nu dispecerizează mesaj, afișează avertismentul; contorul nu se consumă.

### T3. Clasificare document și prompturi specializate (4 zile)

Obiectiv: AI-ul spune ce document citește și primește instrucțiuni potrivite.

Fișiere: `DocumentType`, `Document.detectedType`, registry-ul cu 6 prompturi, `SharedPromptFragments`, `AiVisionExtractionStrategy`, `AnthropicApiClient` (`system` ca blocuri, `cache_control`, `output_config.format`), `LlmResponse` (câmpuri cache), `CoverageConfidenceCalculator::computeForType()`, `ExtractedDocumentData`, `ExtractDataMessageHandler`, dropdown de tip la upload, `.env` (`ANTHROPIC_MODEL=claude-opus-4-8`), traduceri.

Include trecerea pe Opus 4.8 și pe structured outputs, cu simplificarea lui `parseAiResponse()`.

Acceptare: o factură scanată primește `detectedType = 'factura'` cu confidence peste 0.7 și `type` promovat automat; un extras de cont nu mai primește coverage penalizat; `cacheReadInputTokens` e nenul la al doilea document din același batch; răspunsul respectă schema fără reparare de JSON; documentele extrase înainte de tranșă se citesc corect (`schemaVersion` 1); `PdfParserExtractionStrategy` și `OcrTextExtractionStrategy` compilează neatinse.

### T4. `ClaimItem`, totaluri, dobândă per factură și competență art. 99 (6 zile)

Estimare crescută de la 5 la 6 zile față de planul inițial, din cauza regulii de competență art. 99 și a imputației plății, ambele descoperite la review.

Obiectiv: dosarul suportă N facturi, cu dobândă corect calculată de la scadența fiecăreia și instanță corect determinată.

Fișiere: `ClaimItem`, `ClaimItemKind`, `ClaimItemRepository`, M2 cu backfill, `ClaimTotalsService`, `ClaimTotals`, `ClaimInterestAggregator`, `AggregatedAccessoryResult`, `ClaimItemFactory`, `LegalCase`, `CompetentCourtResolver`, `OpAdmissibilityValidator`, `CaseWizardController`, `SummonsContextBuilder`, `PaymentOrderRequestGeneratorService`, `OpisGeneratorService`, template-urile de somație și cerere, traduceri.

Include tabelul de poziții din Step 3 cu confirmarea pe tabel (D12).

Acceptare: un dosar cu 3 facturi cu scadențe diferite produce o dobândă egală cu suma dobânzilor individuale, verificabilă manual; dosarele existente, după backfill, produc **exact** aceleași cifre ca înainte (regresie pe cel puțin 5 dosare seed); taxa de timbru rămâne 200 RON; 5 facturi din același contract cumulează pentru competență, 5 facturi din contracte diferite se evaluează per pretenție; o plată parțială nu reduce automat principalul; un item neconfirmat sau exclus nu apare în totaluri, petitum sau opis; somația listează tabelul factură / dată / scadență / sumă / dobândă.

### T5. Agregare coerentă, multi-debitor și deduplicare de facturi (5 zile)

Obiectiv: două documente care se contrazic nu mai produc o parte inexistentă, iar doi debitori rămân doi.

Fișiere: `FieldGroup`, `FieldAuthorityMatrix`, `CoherentAggregator`, `ClaimItemDeduplicator`, `ClaimItemDraft`, `PrefillFromExtractionService`, `ExtractedDocumentData.debtors[]`, DTO-urile per tip, `WizardPrefillResult`, `Step2DebtorsData`.

Acceptare: cu un contract și o factură care indică CUI-uri diferite pentru debitor, agregatorul nu combină numele din unul cu CUI-ul din celălalt; rata contractuală vine întotdeauna din contract, nu din maximul de confidence; doi debitori reali produc două intrări în Step 2; aceeași factură prezentă în factură și în extrasul de cont produce un singur `ClaimItem`; rularea de două ori pe același set produce rezultat identic.

### T6. UI de conflicte (3 zile)

Obiectiv: avocatul vede și rezolvă neconcordanțele înainte să meargă mai departe.

Fișiere: `PrefillConflict`, `ConflictOption`, `ConflictSeverity`, `ConflictScope`, `ExtractionConflictsLiveComponent` plus template, `_step0_sidecard_partial.html.twig`, `CaseWizardController`, traduceri RO+EN.

Acceptare: două documente cu CUI-uri diferite blochează "Continuă" până la alegere; alegerea supraviețuiește navigării înainte și înapoi; la submit, `auditLog` conține `conflict_resolutions` cu documentul sursă al fiecărei valori reținute; `php bin/console lint:yaml translations/` trece.

### T7 (opțională, după feedback). Files API, segmentare PDF, reconciliere LLM (5 zile)

Obiectiv: PDF-uri comasate de 50 de pagini și potriviri fuzzy între documente.

Acceptare: un scan comasat de 12 documente distincte produce 12 `Document` copil cu tipuri corecte; frontierele vin din clasificare, nu din numărarea paginilor; la retry nu se retrimite binarul.

### Dependențe și total

T1 și T2 sunt independente. T3 depinde de T1. T4 e independentă de T3 și poate merge în paralel. T5 depinde de T3 și T4. T6 depinde de T5. T7 depinde de T3.

Total până la T6 inclusiv: aproximativ 22 de zile.

---

## 6. Plan de testare

Extracția are input extern, deci se aplică regula celor 3 niveluri, obligatoriu pe T3, T4 și T5.

### Unit (`TestCase`)

- `ExtractionPipelineTest`, `ExtractionFailureReasonTest`, `ClaimItemKindTest`, `ConflictSeverityTest`: valori, `label()`, chei de traducere existente (`EnumLabelKeysExistTest` extins).
- `FieldAuthorityMatrixTest`: fiecare pereche (tip, grup) întoarce ponderea așteptată; tipurile necunoscute cad pe valoarea implicită.
- `ClaimItemDeduplicatorTest`: `FF 0012/2025` și `FF12/2025` dau aceeași cheie; cheia slabă doar în absența numărului; normalizarea CUI scoate `RO` și zerourile.
- `ClaimItemEntityTest`, `DocumentEntityTest`, `UserEntityTest`; `ClaimTotalsTest`, `AggregatedAccessoryResultTest`.

### Service (`KernelTestCase`)

- `DataExtractionServiceTest` extins: `AI_ONLY` sare PdfParser, OcrText și Stub; `LEGACY_CASCADE` le rulează; `LOCAL_ONLY` plus `AI_ONLY` dă `SKIPPED_BY_POLICY`.
- `AiVisionExtractionStrategyTest` extins: un test per mod de eșec; `finishReason = MAX_TOKENS` dă `RESPONSE_TRUNCATED`, nu `RESPONSE_MALFORMED`; PDF de 5 MB trece, peste limită nu.
- `ExtractionPromptRegistryTest`: `forType()` corect, fallback generic, `maxTokens()` per tip.
- `CoverageConfidenceCalculatorTest`: `computeForType()` dă coverage rezonabil unui extras de cont; `compute()` rămâne compatibil cu strategiile legacy.
- `CoherentAggregatorTest`: fixture-uri JSON reale; grupul câștigător se ia întreg; garda anti-himeră respinge completarea la CUI-uri diferite; determinism la egalitate.
- `ClaimTotalsServiceTest`; `ClaimInterestAggregatorTest` (3 facturi, sumă egală cu calculele individuale, `skippedItemIds`, rotunjire unică).
- `CompetentCourtResolverTest` extins: cumul pe aceeași cauză, per pretenție pe cauze diferite.
- `OpAdmissibilityValidatorTest` extins: exigibilitate per `ClaimItem`.
- `PrefillFromExtractionServiceTest` extins: două clustere de debitori; payload vechi singular citit corect; `loadDocuments()` ordonat.
- `DocumentUploadServiceTest`: `contentHash` stabil.
- `ExtractDataMessageHandlerTest`: motive tranzitorii re-aruncă `RecoverableMessageHandlingException` cu `PENDING_RETRY`; motive permanente fac ACK.

### Integrare (`WebTestCase`)

- `WizardStep0CascadeTest` extins: 3 documente conflictuale produc conflictele așteptate cu severitățile corecte; ERROR blochează tranziția.
- `CaseWizardControllerStep0Test` extins: upload duplicat ignorat; contorul neconsumat.
- Test nou de flux complet: contract plus 3 facturi, `ClaimItem` creați și dedublați, totaluri corecte în Step 3, cerere cu tabel per factură, `auditLog` cu `conflict_resolutions`.
- `CascadeIntegrationTest`, `DataExtractionServiceDiTest` actualizate; `ExtractedDataPersistenceTest` citește `schemaVersion` 1 și 2.

Toate testele rulează cu izolare față de serviciile externe (client LLM stubuit), conform auditului de izolare deja făcut.

### Verificare live în browser (Playwright), per tranșă

- **T1**: cont nou, ecranul de acord, upload PDF, badge de succes; cont fără acord, badge "extracție dezactivată" cu link; fișier peste limită, badge acționabil fără buton de retry.
- **T2**: același fișier de două ori, toast de duplicat, un singur card.
- **T3**: upload amestecat (contract, factură, extras), dropdown de tip prepopulat corect, corecție manuală și reprocesare.
- **T4**: dosar cu 3 facturi, Step 3 arată totalul și cea mai veche scadență, PDF-ul de somație conține tabelul cu dobânzi individuale.
- **T5**: contract plus factură cu CUI-uri diferite, fără parte hibridă; doi debitori reali, două carduri.
- **T6**: cardul de neconcordanțe, alegere pe radio, "Continuă" deblocat, alegerea păstrată la navigare, mesaj de blocare la Step 4.

---

## 7. Riscuri și întrebări deschise pentru proprietarul de produs

### Blocant rămas deschis

**1. Secretul profesional și lanțul de împuternicire.** AI Vision nu a rulat niciodată în producție; trecerea la AI-only e prima activare reală a transferului de documente către Anthropic (SUA), inclusiv CNP-uri de debitori persoane fizice, nemascate. Trimiterea contractului unui client către un procesator din SUA poate constitui divulgare a secretului profesional (Legea 51/1995 art. 11) dacă clientul avocatului nu a acceptat-o.

De clarificat: cine redactează contractul art. 28, clauzele art. 46, textul de acord din UI, actualizarea politicii de confidențialitate. Implementarea tehnică din T1 poate porni în paralel (câmpurile `aiProcessingAgreementAt` / `Version` și formularul există independent de textul juridic), dar **nu se activează în producție** fără aceste documente.

### Decizii luate (2026-07-21)

**2. Model: Opus 4.8.** `ANTHROPIC_MODEL` devine `claude-opus-4-8`. Suportă structured outputs (`output_config.format`), deci parser-ul tolerant din `parseAiResponse()` se simplifică radical și clasa de erori `RESPONSE_MALFORMED` aproape dispare. Cost $5/$25 per milion tokeni, aproximativ 1.7x față de Sonnet 5, dar la câțiva cenți pe dosar diferența e neglijabilă față de câștigul de acuratețe pe scanuri proaste și pe raționament juridic. Impact: T3 implementează direct pe structured outputs, fără parser tolerant.

**3. Debitori cu facturi disjuncte: eroare blocantă.** Confirmat. Mesaj: "aceste documente par să privească două dosare distincte". Rămâne ERROR în tabelul D8.

**4. Confirmarea itemilor de creanță: confirmare pe tabel, nu per item.** Vezi D12.

**5. Imputația plății: manuală, cu warning.** Confirmat. `paidAmount` nu reduce automat principalul; se marchează plată neimputată cu WARNING, alocarea o face avocatul. `amount = SUM(item.amount)`. Imputația explicită pe cheltuieli / dobândă / capital rămâne în backlog, alături de revizia de dobândă existentă.

### Neblocante

**6. Data de referință a calculului.** Azi e implicit `now()` în wizard și `paymentNoticeDate` în somație. Cu N facturi merită expusă explicit, pentru că o dobândă calculată la o dată și o somație comunicată la alta produc discrepanțe pe care partea adversă le poate exploata.

**7. Limita de 5 debitori.** Rămâne `MAX_DEBTORS = 5`? Cu clustering, extracția poate detecta mai mulți; peste 5 se generează eroare blocantă.

**8. Rate limit.** 200/zi și limiter anti-burst pe minut sunt suficiente pentru onboarding-ul unui cabinet cu portofoliu mare?

**9. Acoperirea istorică a cursurilor BNR.** `CurrencyConverter::MAX_RATE_STALENESS_DAYS = 7` aruncă excepție dacă nu există curs la mai puțin de 7 zile de data facturii. O factură EUR din 2023 va eșua dacă importul BNR nu a fost backfilled. Propunerea: item marcat needs-manual-fx, exclus din totaluri, conflict ERROR. Se verifică acoperirea reală a tabelei `BnrExchangeRate` în T4.
