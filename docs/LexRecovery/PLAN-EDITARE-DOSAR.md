# Plan de implementare: ciorna wizard-ului, ștergerea dosarului și editarea creanței din pagina dosarului

Data: 2026-11-03. Ramura: `lexrecovery`. Document de pornire: `ANALIZA-WIZARD-SI-EDITARE-DOSAR-2026-11-03.md` (secțiunile 2-6). Document pentru avocat: `MODIFICARE-DOSAR-DUPA-CREARE-PENTRU-AVOCAT-2026-11-03.md`. Machetă UI pentru cardul Creanță: `mockups/v2/04-dosar/creanta-editare.html`.

Planul sintetizează patru analize (ciornă + ștergere, recalcul + card Creanță, refactorizarea wizard-ului, revizia juridică), verificate pe cod. Unde analizele s-au contrazis, decizia aleasă este marcată cu **Decizie** și motivată pe loc. Referințele la cod sunt la liniile din ramura `lexrecovery` la data de mai sus.

Obiectivul declarat: totul să meargă ca înainte, nimic să nu se strice, iar funcțiile noi să meargă la fel de bine. De aici decurg toate principiile din secțiunea 2.

## Stare: LIVRAT (2026-11-04, ramura `feature/editare-dosar`)

Toate cele 7 faze sunt implementate, verificate în browser pe Docker și comise pe ramura `feature/editare-dosar` (nu încă în `lexrecovery`, fără `make demo-sync`). Suita completă după Faza 5: 4315 teste, singura eroare fiind drift-ul cunoscut al bazei de dev (`SchemaValidateTest`, coloane din ramura executorilor). Capturile din Faza 6 sunt în `capturi/editare-dosar/`, iar documentul pentru avocat a fost actualizat cu ele și cu întrebările H, I, K, L.

Abateri față de plan, acceptate pe parcurs:

- Faza 1: lacăt optimist prin `CaseDraftRepository::updateIfVersion`, nu `#[ORM\Version]`; `WizardStateStore::detach()`; plafonul de ciorne verificat și la GET pasul 0; `CaseDraftListBuilder`, `CaseDraftDiscarder`, `CaseDraftPayloadCodec` ca servicii separate.
- Faza 3: `CaseFxTrace` clasă separată; `createPrescriptionDeadlines(?notBefore)`; avertisment nou `ACCESSORY_CUTOFF_BEFORE_DUE_DATE`.
- Faza 4: refuzul facturii altor părți nu șterge fișierul (GET-ul de stare rămâne read-only, U6 acoperă ștergerea din Documente); `DocumentReferenceNormalizer` pliază diacriticele; proprietarul al cărui dosar a trecut de amiabil între timp primește pagina redesenată blocată și un toast, nu 403; după un refuz, dialogul oferă „Încarcă altă factură”.
- Faza 5: `removableDocument` rămâne în `Step0DocumentsController` și `renderConfirmation` în `Step4ConfirmationController` (un singur apelant fiecare); split-ul controllerelor s-a făcut într-un singur commit; 5.12 (`DocumentRemovalPlanner` pe `WizardBag`) nu s-a făcut; controllerele de pas rămân lungi (`confirmation()` și `documents()` peste 200 de linii), de subțiat separat.
- Faza 6: scenariul „factură în valută fără curs” nu a putut fi reprodus live (BNR are curs pentru orice dată trecută); e acoperit de teste. Întrebările pentru avocat A/B' și D' rămân în secțiunea 6, formularea cu CPC art. 99 e de confirmat de avocat (întrebarea F).

---

## 1. Scop și delimitare

### Intră

1. **Ciorna `CaseDraft`**: bag-ul wizard-ului salvat în tabelă pe utilizator, lista „Dosare neterminate” (dashboard și deasupra listei de dosare) cu „Continuă” / „Renunță”, „Dosar nou” arată ciornele existente, comandă de expirare la 30 de zile fără activitate care șterge și documentele ciornei și curăță documentele orfane de azi (`legal_case_id NULL` fără ciornă). Opțional, ca sub-pas separat: refolosirea extracției după `Document.contentHash` în interiorul aceluiași utilizator.
2. **Ștergerea dosarului în `AMIABIL`**: rută POST, voter, confirmare, `LegalCase.deletedAt` (există la `src/Entity/LegalCase.php:384`, `markAsDeleted()` la 1556, nefolosite de nicio rută), audit, listele ascund dosarele șterse.
3. **`ClaimRecalculator`**: un singur serviciu care, după orice schimbare a pozițiilor, reface totalurile, accesoriul, propunerea de instanță (fără schimbare automată), termenele de prescripție, admisibilitatea și scrie audit `claim_edited` cu before/after.
4. **Editarea facturilor direct din cardul Creanță** (overview), doar în `AMIABIL` și doar de proprietar: tabel al pozițiilor (inclusiv excluse), „Adaugă factură” (manual sau din document `FACTURA` trecut prin extracția existentă, care azi nu se declanșează după creare), „Exclude” / „Reinclude”, modal „Modifică detaliile creanței”. Răspuns Turbo Stream cu rezumatul schimbărilor.
5. **Refactorizarea `CaseWizardController`** (3231 linii, 14 acțiuni publice, 72 de metode private) în servicii mici și un controller per pas, prin extrageri mecanice.

### Nu intră

- Editarea creanței după `trimite_somatie` (Î1, Î2 din analiză, amânate la discuția cu avocatul). Rutele de editare răspund 403 în orice alt status decât `AMIABIL`.
- Schimbarea părților (creditor, debitori). Alt creditor sau alt debitor înseamnă ștergerea dosarului și unul nou.
- Redeschiderea wizard-ului pentru editare. Wizard-ul rămâne pentru creare; editarea este un set de formulare mici pe overview.
- „Înregistrează plata” pe o poziție existentă (plată tardivă cu păstrarea accesoriilor). Rămâne întrebare pentru avocat (secțiunea 6, G); până atunci excluderea cere motiv și avertizează.
- Sesiunile în DB (`PdoSessionHandler`) și cron-ul de producție: Faza 9.1 (deploy).
- Purjarea fișierelor dosarelor șterse după un termen: întrebare deschisă (secțiunea 6, J).

---

## 2. Principii de siguranță

1. **Extrageri mecanice, una pe commit.** Fiecare commit din Fazele 0 și 5 mută cod fără să-i schimbe comportamentul; dacă un test existent trebuie modificat ca să treacă, extragerea nu e mecanică și se oprește.
2. **Suita verde după fiecare commit.** Minim `tests/Controller/Case` + `tests/Cascade/WizardStep0CascadeTest.php` după fiecare extragere; suita completă (circa 4400 de teste, `phpunit` cu `failOnDeprecation`, `failOnNotice`, `failOnWarning`) înainte de fiecare commit cu risc mediu sau mare și la finalul fiecărei faze.
3. **Feature nou în spatele voter-ului și al statusului.** Toate rutele noi de editare și ștergere trec prin atribute noi de voter (`CASE_CLAIM_EDIT`, `CASE_DELETE`), restrânse la proprietar + `AMIABIL` + dosar neșters. Template-urile folosesc aceleași atribute, deci un buton nu apare niciodată fără ca ruta să permită acțiunea.
4. **Zero schimbare de comportament pe wizard.** Cele 178 de teste din `tests/Controller/Case/CaseWizard*Test.php`, `WizardRetryExtractionTest.php`, `PostedAmountParsingTest.php` și `tests/Cascade/WizardStep0CascadeTest.php` sunt plasa de siguranță; se modifică doar acolo unde semantica se schimbă intenționat (start/skip în Faza 1), explicit și înainte de schimbare.
5. **Verificare live Playwright pe fiecare felie** (memorie `feedback_verify_live_browser_each_slice`), pe stack-ul Docker, cu `make restart` după cod și `make tailwind` după template-uri; dosarele temporare se creează ca clone `TMP-LIVE-*`.
6. **Confirmare înainte de commit** (memorie `feedback_confirm_before_commit`); mesaj de commit pe un rând, în română, fără `Co-Authored-By`.
7. **Convenții de cod**: fără em-dash nicăieri, identificatori și comentarii în engleză, UI în română prin translator (RO/EN în lockstep, `lint:yaml`), fără referințe la pași de plan în cod, CLI în engleză, erorile ca Turbo Stream (nu redirect), teste pe trei niveluri pentru orice input extern (extracție AI, fișiere încărcate).
8. **Nicio schimbare de schemă fără migrare revizuită** (`doctrine:migrations:diff`, citită înainte de `migrate`; vezi memoria `ops_migrations_diff_stale_file_in_container`).

---

## 3. Fazele

Ordinea este impusă de dependențe: Faza 0 (store + bag) trebuie să existe înainte de Faza 1, altfel `CaseDraft` s-ar scrie pe `loadBag`/`saveBag` și s-ar rescrie la refactor. Fazele 2 și 3 sunt independente de wizard și pot merge în paralel cu Faza 1. Faza 4 depinde de 3. Faza 5 depinde de 0 și 1. Faza 6 încheie.

| Faza | Conținut | Estimare |
|---|---|---|
| 0 | Refactor wizard, partea 1: `WizardStateStore` + `WizardBag` | 1 zi |
| 1 | `CaseDraft`: entitate, migrare, store, listă, Dosar nou, Renunță, expirare + orfane; opțional refolosirea extracției | 3 zile (+0,5 opțional) |
| 2 | Ștergerea dosarului în `AMIABIL` | 1 zi |
| 3 | `ClaimRecalculator` + teste | 1,5 zile |
| 4 | Cardul Creanță: manual, exclude/reinclude, detalii; apoi adăugare din document cu extracție după creare | 4 zile |
| 5 | Restul refactorizării wizard-ului | 4 zile |
| 6 | Verificare live completă + documentul pentru avocat actualizat | 1,5 zile |
| | **Total** | **16 zile (+0,5 opțional)** |

Estimarea din analiza inițială (7,5 zile) nu includea refactorizarea (Fazele 0 și 5, circa 5 zile), poarta de admisibilitate la somație, concurența pe ciornă și corecțiile juridice; de aici diferența.

---

### Faza 0: refactor wizard, partea 1 (`WizardStateStore` + `WizardBag`)

**Obiectiv.** Separarea stării wizard-ului de sesiune, fără nicio schimbare de comportament, ca `CaseDraft` (Faza 1) să fie o a doua implementare a aceleiași interfețe.

**Fișiere create**
- `src/Service/Wizard/WizardStateStore.php` (interfață): `load(SessionInterface $session): WizardBag`, `save(SessionInterface $session, WizardBag $bag): void`, `reset(SessionInterface $session): void`.
- `src/Service/Wizard/SessionWizardStateStore.php`: mută verbatim `loadBag` (`CaseWizardController.php:3084-3157`), `saveBag` (3159-3166), `emptyBag` (2537-2558) și `SESSION_KEY` (122). `reset()` înlocuiește cele două scrieri directe `$session->set(self::SESSION_KEY, $this->emptyBag())` din `start()` (177) și `confirmation()` după persist (1712).
- `src/Service/Wizard/WizardBag.php`: obiect tipizat cu proprietăți publice (în stilul `Step1CreditorData`): `documentIds: list<int>`, `creditor: ?Step1CreditorData`, `debtors: ?Step2DebtorsData`, `debtorBeforePick: ?Step2DebtorsData`, `debtorLibraryRecheck: bool`, `creditorLibraryRecheck: bool`, `claim: ?Step3ClaimData`, `claimItems: ?list<ClaimItemRow>`, `claimItemsTableConfirmed: bool`, `conflictResolutions: array<string, ConflictResolution>`, `conflictsAcknowledged: array`. `fromArray(array)` preia verbatim sanitizarea din `loadBag` (chei lipsă cu default, `instanceof`, chei necunoscute aruncate); `toArray()` produce exact array-ul de azi.

**Decizie (contradicție rezolvată): în sesiune se stochează în continuare array-ul (`toArray()`) sub aceeași cheie `case_wizard_data`, nu obiectul.** Motiv: 6 fișiere de test scriu array-uri parțiale direct în sesiune (`CaseWizardControllerStep1To4Test.php:2077,2109`, `CaseWizardConflictAuditTest.php:184`, `CaseWizardConflictRecordTest.php:366,410`, `CaseWizardConflictsTest.php:292,315,341,660,708,733,763`, `CaseWizardClaimItemsFlowTest.php:430`, `CaseWizardConflictChoiceAdversarialTest.php:512,555`), citesc înapoi cu `->get(KEY)['debtors']->debtors[0]` în peste 25 de locuri, iar `ConflictsTest:646-651` compară `serialize()` al sesiunii înainte și după preview. Analiza ciornei propunea un trait de test încă din acest pas; îl amânăm în Faza 1, unde chiar se schimbă locul de stocare. Astfel Faza 0 nu atinge niciun test.

**Fișiere modificate**
- `src/Controller/Case/CaseWizardController.php`: primește `WizardStateStore` în constructor; `$bag['x']` devine `$bag->x` în cele 14 acțiuni și în helperi; dispar referințele `&$bag` din `withoutAnafSettled` (2571), `prefillWithConflicts` (2636), `applyChosenValues` (2730), `settleConflicts` (2797). `DocumentRemovalPlanner::plan/apply` (`src/Service/Extraction/DocumentRemovalPlanner.php:38,79`) rămân pe array: controllerul trece `$bag->toArray()` și rehidratează cu `fromArray` după `apply` (testul `DocumentRemovalPlannerTest` nu se atinge).

**Teste de adăugat**
- `tests/Service/Wizard/WizardBagTest.php` (TestCase): chei lipsă cu default, valori de tip greșit aruncate (creditor string, `claimItems` cu elemente non-`ClaimItemRow` filtrate), `conflictsAcknowledged` fără `fingerprint` ignorat, `documentIds` deduplicate și `intval`; round-trip `fromArray(toArray())` egal cu `serialize()` al array-ului original.
- `tests/Service/Wizard/SessionWizardStateStoreTest.php` (KernelTestCase): bag gol, round-trip cu DTO-uri, normalizarea unui payload parțial identică cu `loadBag` de azi, `reset()`.

**Riscuri**
- Fixture-urile de test cu array-uri parțiale (mai sus). Mitigare: decizia de a stoca array-ul; dacă un test wizard pică în acest commit, extragerea se reface.
- Metodele care primesc bag prin valoare și se bazează pe semantica de copie. Verificat: `showChosenParty` (3038) modifică și salvează imediat, restul doar citesc; cu obiectul transmis prin handle nu se schimbă nimic.

**Gata când**
- [ ] `grep -n "SESSION_KEY\|emptyBag\|loadBag\|saveBag" src/Controller/Case/CaseWizardController.php` nu mai returnează nimic.
- [ ] `tests/Controller/Case` + `tests/Cascade/WizardStep0CascadeTest.php` verzi fără nicio modificare de test.
- [ ] Suita completă verde.
- [ ] Verificare live: un wizard complet (5 pași) pe Docker.

**Estimare:** 1 zi (2 commit-uri).

---

### Faza 1: `CaseDraft`

**Obiectiv.** Munca din wizard nu se mai pierde: bag-ul stă în tabelă, pe utilizator; sesiunea ține doar id-ul ciornei.

#### 1.1 Entitate și migrare

- `src/Entity/CaseDraft.php`: `id`, `user` (FK `user_id`, `onDelete: CASCADE`), `payload` (`MEDIUMBLOB`; `BLOB` are 64 KB și 50 de rânduri cu `statementPayments` pot depăși), `payloadVersion` (int), `version` (`#[ORM\Version]`, lacăt optimist; nu există precedent în proiect, se documentează), `stepReached` (string, numele rutei pasului atins), `documentsCount` (int), `creditorName` (string nullable, denormalizat pentru listă), `createdAt`, `updatedAt`, `lastActivityAt`.
- `src/Entity/Document.php`: `draft` (`ManyToOne CaseDraft`, nullable, `onDelete: SET NULL`, index). Setat la upload în pasul 0. `documentIds` rămâne în payload ca sursă de ordine (`loadOwnedDocuments` păstrează ordinea din bag, iar testele o setează).
- `migrations/Version20261103100000.php`: tabela `case_draft` + `document.draft_id`. Generată cu `doctrine:migrations:diff`, citită, apoi `doctrine:schema:validate`.

**Decizie (contradicție rezolvată): serializarea este `serialize()` PHP, nu JSON.** Analiza inițială (6.1) și revizia juridică vorbesc de JSON; analiza ciornei arată că `ConflictResolution::$value` este `mixed` (poate fi `DateTimeImmutable`, comparată ca `DateTimeInterface` în `ConflictChoiceApplier.php:211`), clasele readonly cu constructor promovat cer denormalizare cu tipuri exacte, iar `list<Step2DebtorEntry>` cere PropertyInfo din phpdoc. `serialize()` este exact mecanismul sesiunii (storage-ul mock din teste serializează la fel), deci zero schimbare de comportament. `unserialize` cu `allowed_classes` (lista DTO-urilor, enum-urilor, `DateTimeImmutable`), `__PHP_Incomplete_Class` sau `payloadVersion` necunoscut marchează ciorna ca necitibilă (lista o arată cu mesaj și buton Renunță, nu o golește tăcut cum face `loadBag` azi). JSON devine fezabil după Faza 5 (toArray/fromArray explicite per DTO) și rămâne opțional.

Regulă de cod de adăugat în `WizardBag` (docblock): proprietăți noi în DTO-urile din bag doar cu default și nepromovate (convenția deja scrisă în `ClaimItemRow` și `Step2DebtorEntry`).

GDPR: payload-ul conține CNP și adrese; coloanele denormalizate nu conțin date personale (doar numele creditorului, persoană juridică), payload-ul nu se afișează nicăieri în admin, expirarea la 30 de zile șterge rândul și documentele, audit `draft_created` / `draft_discarded` / `draft_expired`.

#### 1.2 Store-ul și comutarea

- `src/Service/Wizard/CaseDraftWizardStateStore.php` implementează `WizardStateStore`: sesiunea ține `case_wizard_draft_id`; creare lazy la prima salvare; id invalid în sesiune (ciornă ștearsă din alt tab sau expirată) întoarce bag gol și curăță sesiunea (nu 500); auto-reparare la `load`: documentele cu `draft_id` = ciorna și absente din payload se adaugă la coadă; salvare doar când `toArray()` diferă de ce a încărcat (altfel fiecare GET de la pasul 0, `CaseWizardController.php:316`, și cele două `saveBag` pe POST, 236 și 245, devin UPDATE-uri și surse de cursă); la `OptimisticLockException` reîncarcă, reunește `documentIds` și rescrie (pentru DTO-uri rămâne „ultimul scrie”, ca azi). `reset()` șterge ciorna (nu o golește; altfel rămâne o ciornă goală în listă).
- `src/Repository/CaseDraftRepository.php`: `findByUser(User): list<CaseDraft>`, `findOneByIdAndUser(int, User): ?CaseDraft`, `findInactiveSince(\DateTimeImmutable): list<CaseDraft>`, `countByUser(User): int`.
- `src/Repository/DocumentRepository.php:122` `attachToCase`: UPDATE-ul DQL setează și `d.draft = NULL`.
- `src/Controller/Case/CaseWizardController.php`: `persistWizard` (1802) șterge ciorna în aceeași tranzacție (`wrapInTransaction`, 1818) cu `attachToCase` (1892); `start()` (166) nu mai resetează dur: fără ciorne redirecționează la `documents`, cu ciorne randează lista; rută nouă POST `case_wizard_draft_new` pornește un bag gol fără să șteargă ciornele vechi (plafon 5 per utilizator; la depășire mesaj cu trimitere la listă); `skip()` (669) șterge documentele ciornei prin `DocumentUploadService::delete` (azi le lasă orfane).
- `config/services.yaml`: alias `WizardStateStore` către `CaseDraftWizardStateStore`.

**Trait de test** `tests/Support/WizardBagTestTrait.php`: `primeBag(array)`, `readBag()`, `mutateBag(callable)` care trec prin store-ul din container și setează id-ul ciornei în sesiunea clientului; înlocuirea mecanică a celor ~12 scrieri și ~25 de citiri directe din `Step1To4Test`, `ClaimItemsFlowTest`, `ConflictAuditTest`, `Step0Test`, `ConflictRecordTest`, `ConflictsTest`, `ConflictChoiceAdversarialTest`. Se face **înainte** de comutarea store-ului, pe implementarea de sesiune, ca suita să rămână verde în același commit. `tearDown`-urile primesc `DELETE FROM case_draft` (după `document`, înainte de `user`).

Teste rescrise explicit (semantica se schimbă): `CaseWizardControllerStep0Test::testStartClearsLeftoverSessionFromPreviousWizardRun` (77) și `testSkipClearsSessionAndRedirectsToCreditor` (283).

#### 1.3 UI: „Dosare neterminate”, Continuă, Renunță

- `src/Controller/Case/CaseDraftController.php`: `case_draft_continue` (POST `/case/draft/{id}/continue`, CSRF, proprietate; setează id-ul în sesiune, redirecționează la `stepReached`), `case_draft_discard` (POST `/case/draft/{id}/discard`, CSRF; șterge documentele ciornei prin `DocumentUploadService::delete`, șterge ciorna, golește id-ul din sesiune dacă era ea, audit `draft_discarded`). Alt utilizator primește 404.
- `src/Controller/DashboardController.php:14` și controllerul listei de dosare: trimit `drafts` în context.
- Template-uri: `templates/case/_drafts_band.html.twig` (inclus în `templates/dashboard/index.html.twig` și `templates/cases/index.html.twig`), `templates/case/wizard_drafts.html.twig` (ecranul „Dosar nou” cu ciorne).
- Chei de traducere (RO + EN): `case.drafts.title`, `case.drafts.continue`, `case.drafts.discard`, `case.drafts.discard_confirm`, `case.drafts.step_reached.<pas>`, `case.drafts.documents_count`, `case.drafts.unreadable`, `case.drafts.limit_reached`, `case.drafts.new`, `case.drafts.discarded_flash`.

#### 1.4 Expirare și curățarea orfanelor

- `src/Command/ExpireCaseDraftsCommand.php`, `app:drafts:expire`, opțiuni `--days=30`, `--dry-run`, `ClockInterface` injectat (precedent: `CheckDeadlinesCommand.php:50`), șablon `PruneNotificationsCommand`. Titluri și descrieri în engleză.
- Pasul 1: ciornele cu `lastActivityAt < acum - N zile`: documentele lor prin `DocumentUploadService::delete`, apoi ciorna, audit `draft_expired` cu actor null.
- Pasul 2: orfanele: `legal_case_id IS NULL AND draft_id IS NULL AND created_at < prag`, sărind `PROCESSING` și `PENDING_RETRY` (un mesaj în coadă ar scrie pe un rând șters). Pragul de vârstă protejează sesiunile vii de dinainte de migrare.
- `DocumentUploadService::delete` (136): parametru opțional `?User $actor` transmis la `AuditLogService::log` (semnătura de la `src/Service/AuditLogService.php:114` îl acceptă deja); ordinea devine audit, `remove` + `flush`, apoi `unlink` și `rmdir` pe directorul uuid dacă e gol (azi `unlink` precede `remove` și nu există `rmdir` nicăieri).

#### 1.5 Opțional: refolosirea extracției după amprentă (sub-pas separat, după 1.1-1.4)

- `src/Service/Extraction/ExtractionReuseService.php`: `findReusable(User $user, string $contentHash, DocumentType $declared): ?Document` (indexul `idx_document_uploader_content_hash` din `src/Entity/Document.php:19` există exact pentru asta), `copyInto(Document $source, Document $target): void`.
- Reguli: sursă cu `uploadedBy` = utilizatorul curent (niciodată între utilizatori), `contentHash` egal, `extractionStatus = COMPLETED`, tip declarat `ALT_DOCUMENT` sau egal cu `source.documentType` (promptul depinde de tipul declarat, `DataExtractionService::extract`). Se copiază `extractedData` cu `sourceDocumentId` rescris (`ExtractedDocumentData::toArray`, `src/DTO/Extraction/ExtractedDocumentData.php:103`), `extractionConfidence`, `extractionStrategy`, `detectedType`, `detectedTypeConfidence`, status `COMPLETED`. Nu se copiază: `legalCase`, `draft`, împuternicirea, conflictele, rezolvările, deciziile pe rânduri. Nu se dispatch-uiește `ExtractDataMessage`, nu se taxează `UploadRateLimiter::acceptsExtractions` (`src/Service/Document/UploadRateLimiter.php:49`). Audit `extraction_reused`, categoria `AI_EXTRACTION`.
- `ExtractDataMessageHandler::promoteDetectedType` (`src/MessageHandler/ExtractDataMessageHandler.php:193`, privată) se extrage în `src/Service/Extraction/DetectedTypePromoter.php` și se folosește din ambele locuri.
- Dedup în interiorul ciornei rămâne skip (`UploadDeduplicator::partition`, `src/Service/Document/UploadDeduplicator.php:42`); între ciorne/dosare devine rând nou + copie.

**Teste de adăugat (Faza 1)**
- `tests/Entity/CaseDraftTest.php` (TestCase): denormalizările din bag (pas atins, număr documente, nume creditor).
- `tests/Service/Wizard/CaseDraftWizardStateStoreTest.php` (KernelTestCase): round-trip; id invalid în sesiune întoarce bag gol; două salvări pornite din aceeași versiune păstrează ambele id-uri de documente; payload necitibil semnalat; `reset()` șterge ciorna; fixture de payload serializat la versiunea curentă (`tests/Fixtures/case_draft_payload_v1.bin`) care pică dacă un DTO primește o proprietate fără default.
- `tests/Controller/Case/CaseDraftControllerTest.php` (WebTestCase): lista arată doar ciornele utilizatorului; Continuă setează id-ul și deschide pasul atins; Renunță șterge rândurile, ciorna și fișierele din `var/uploads_test/cases/_pending/{userId}/`; utilizatorul B primește 404 pe ciorna lui A; plafonul de 5.
- `CaseWizardControllerStep1To4Test`: la submit rândul `case_draft` dispare și `document.draft_id` devine NULL.
- `tests/Command/ExpireCaseDraftsCommandTest.php`: ciornă de 31 de zile ștearsă cu fișier, ciornă de 2 zile păstrată, orfan vechi șters, orfan `PROCESSING` păstrat, `--dry-run` nu șterge.
- Opțional 1.5, trei niveluri: `tests/Service/Extraction/ExtractionReuseServiceTest.php` (copiat/necopiat, tip incompatibil, sursă `FAILED`, `sourceDocumentId` rescris); `CaseWizardControllerStep0Test` (al doilea upload al aceluiași fișier în altă ciornă: 0 mesaje pe transportul in-memory, `COMPLETED` imediat, buget netaxat; utilizatorul B nu refolosește de la A, extinzând `CaseWizardStep0DedupAdversarialTest`); `tests/Cascade/WizardStep0CascadeTest.php` (documentul refolosit apare în preview-ul de date detectate).

**Riscuri (Faza 1)**
- Cursa POST upload vs GET-uri de frame la pasul 0 (`_step0_dynamic_partial.html.twig:9-11` poll-frame, 42-45 Mercure): handler-ul de sesiune pe fișiere serializa cererile aceleiași sesiuni, tabela nu. Mitigare: salvare doar la schimbare, `draft_id` cu auto-reparare, `#[ORM\Version]` cu reuniune. Test pe store cu două salvări concurente.
- Pierderea tăcută a bag-ului la schimbare de DTO (o ciornă trăiește 30 de zile, peste mai multe deploy-uri). Mitigare: `payloadVersion`, `allowed_classes`, stare „necitibil”, fixture de payload.
- Comanda de expirare pe documente ale unor wizard-uri încă vii. Mitigare: prag de 30 de zile pe `created_at`, excluderea `PROCESSING`/`PENDING_RETRY`, `--dry-run`.
- Două ciorne per utilizator și o singură sesiune: Continuă pe B dintr-un tab mută toate taburile pe B. Se acceptă („ultimul Continuă câștigă”) și se spune în listă care ciornă e activă.
- Refolosirea extracției între utilizatori ar scurge date (CNP, IBAN). Mitigare: `uploadedBy` obligatoriu în interogare, test adversarial.

**Gata când**
- [ ] Migrarea aplicată și `doctrine:schema:validate` curat.
- [ ] Toate scrierile/citirile directe din sesiune în teste trec prin trait; suita verde pe sesiune, apoi verde pe `CaseDraft`.
- [ ] Logout/login pe alt browser reia wizard-ul de la pasul atins, cu documentele și datele detectate.
- [ ] `app:drafts:expire --dry-run` listează corect; rularea reală șterge fișierele și directoarele goale.
- [ ] Live: pasul 0 cu Mercure și ciornă în DB, Renunță, Dosar nou cu ciorne existente, expirare prin comandă.

**Estimare:** 3 zile (+0,5 pentru 1.5).

---

### Faza 2: ștergerea dosarului în `AMIABIL`

**Obiectiv.** Avocatul poate scoate din liste un dosar pentru care nimic nu a plecat prin aplicație, cu urmă completă.

**Decizie (contradicție rezolvată): atribut nou `CASE_DELETE`, nu `CASE_EDIT`.** Contextul spune „voter `CASE_EDIT` + status `AMIABIL`”; `CASE_EDIT` permite azi și `SOMATIE_TRIMISA`, `CERERE_GENERATA`, `CERERE_DEPUSA` (`src/Security/Voter/CaseVoter.php:49-55`) și împarte `EDITABLE_STATUSES` cu `CASE_UPLOAD`; a-l restrânge ar schimba încărcarea de documente și ar pica `CaseVoterTest:135-188`. Un atribut dedicat (model: `STAMP_DUTY_MANAGE`) ține regula într-un singur loc pentru controller și template. Este în spiritul deciziei (voter + status), nu împotriva ei.

**Fișiere modificate**
- `src/Security/Voter/CaseVoter.php`: `DELETE = 'CASE_DELETE'` (proprietar, `AMIABIL`, neșters, nu blocat de `FilingLockQuery::isLocked`); în plus, orice atribut întoarce `false` când `isDeleted()`, înainte de bypass-ul de admin (91).
- `src/Repository/LegalCaseRepository.php:34` `findWithOverviewRelations`: filtru `lc.deletedAt IS NULL`. Este singura metodă de încărcare fără filtru; `CaseOverviewController.php:28` nu verifică `isDeleted` (spre deosebire de `CaseSummonsController`, `CaseTransitionController` și celelalte 10 controllere de dosar).
- `src/Service/Table/Definition/CreditorTableDefinition.php:94`: `casesCount` prin interogare cu `deletedAt IS NULL`, nu `getLegalCases()->count()`.
- `src/Service/Case/CaseDeletionService.php` (nou): `delete(LegalCase $case, User $actor, ?string $reason): void` în `wrapInTransaction`: reverifică statusul `AMIABIL` cu `LockMode::PESSIMISTIC_WRITE` pe rând (cursă cu `trimite_somatie` din alt tab), `markAsDeleted()`, notificările dosarului marcate citite prin `NotificationRepository::markReadByDedupKeyPrefix` (`src/Repository/NotificationRepository.php:64`) ca să nu trimită spre 404, audit `case_deleted` cu categoria nouă `CATEGORY_CASE_DELETED` și snapshot (creditor, debitori, sume, număr și nume documente, `paymentNoticeDate`, motiv). Fără ștergere de fișiere; fără rambursare (slotul se consumă la `trimite_somatie`, `SubscriptionService::consumeCaseSlot`, 67).
- `src/Controller/Case/CaseDeletionController.php`: `case_delete` (POST `/case/{id}/delete`, CSRF, `CASE_DELETE`); la succes redirect la lista de dosare cu flash; la eroare Turbo Stream cu toast (nu redirect).
- `templates/case/overview/_modal_delete_case.html.twig` + buton în `_hero_actions.html.twig` doar când `is_granted('CASE_DELETE', case)`; dialogul enumeră ce se pierde și avertizează explicit când somația e deja generată (`paymentNoticeDate` setat: `CaseSummonsController` generează în `AMIABIL`) sau dovada de taxă e încărcată (`STAMP_DUTY_MANAGE` nu depinde de status); câmp opțional „motiv” salvat în audit.
- `templates/case/overview/_tab_audit.html.twig`: paleta pentru categoria nouă.
- Traduceri RO/EN: `case.delete.button`, `case.delete.title`, `case.delete.body`, `case.delete.warn_summons_generated`, `case.delete.warn_stamp_duty_proof`, `case.delete.reason`, `case.delete.confirm`, `case.delete.flash_success`, `case.delete.flash_forbidden_status`, `audit.category.case_deleted`.

**Decizie: `DebtorRepository::countLinks` (108) rămâne neschimbat** (numără intenționat și dosarele șterse: „those still hold the company”). Dosarele șterse în `AMIABIL` n-au somat pe nimeni, dar debitorul tot a fost în ele; regula actuală este mai sigură. Întrebare deschisă dacă avocatul vrea altfel.

**Teste de adăugat**
- `tests/Security/Voter/CaseVoterTest.php`: `CASE_DELETE` doar proprietar + `AMIABIL`; dosar șters => `false` pe toate atributele, inclusiv pentru admin.
- `tests/Repository/LegalCaseRepositoryTest.php`: `findWithOverviewRelations` întoarce `null` pe dosar șters.
- `tests/Service/Case/CaseDeletionServiceTest.php` (KernelTestCase): `deletedAt` setat, notificări citite, audit cu snapshot, refuz în alt status.
- `tests/Controller/Case/CaseDeletionControllerTest.php` (WebTestCase): după ștergere dosarul lipsește din dashboard, lista de dosare, KPI (`countActiveByUser`), agenda de termene, biblioteca creditorului (N-1); `GET /case/{id}` 404; POST pe o tranziție 404; refuz în `SOMATIE_TRIMISA`; refuz pentru alt utilizator; CSRF invalid; dialogul arată avertismentul când `paymentNoticeDate` e setat.

**Riscuri**
- Dosarul șters rămâne accesibil pe `/case/{id}` (critic). Mitigare: filtrul din repository + refuzul global din voter + test.
- Notificările și legăturile de bibliotecă arată dosare șterse. Mitigare: `markReadByDedupKeyPrefix`, `casesCount` filtrat, teste.
- Ștergere cu somație deja generată. Mitigare: avertisment în dialog + bifă explicită.

**Gata când**
- [ ] Toate testele de mai sus verzi; suita completă verde.
- [ ] Live: ștergere din overview, dosarul dispare din toate listele, link-ul vechi dă 404, auditul arată intrarea cu snapshot.

**Estimare:** 1 zi.

---

### Faza 3: `ClaimRecalculator`

**Obiectiv.** Un singur serviciu, testat direct, care readuce dosarul la consistență după orice schimbare a pozițiilor. Nu are UI.

**Fișiere create**
- `src/DTO/Case/ClaimRecalculationResult.php`: `totals: ClaimTotals`, `accessoryChange: ?array{from: ?string, to: string}`, `fxChange: ?array`, `courtProposal: ?CourtProposal` (`current: ?Court`, `proposed: ?Court`, `explanationKey: ?string`, `candidates: list<Court>`), `deadlinesCreated: list<LegalDeadline>`, `deadlinesRemoved: list<int>`, `deadlinesUnmatched: list<LegalDeadline>`, `issues: array{errors: list<AdmissibilityIssue>, warnings: list<AdmissibilityIssue>}`, `referenceDate: \DateTimeImmutable`.
- `src/Service/Case/ClaimRecalculator.php`: `recalculate(LegalCase $case, string $reason, array $before, User $actor): ClaimRecalculationResult`, rulat de apelant în interiorul unei singure tranzacții, în ordinea:
  1. `ClaimTotalsService::recalculate($case)` (`src/Service/Case/ClaimTotalsService.php:105`).
  2. Câmpurile FX ale dosarului (`originalAmount`, `originalCurrency`, `exchangeRate`, `exchangeRateDate`, scrise azi doar de `persistWizard`, `CaseWizardController.php:1835-1848`, și citite de `SummonsContextBuilder` și `SummonsTableBuilder`): cu o singură poziție în valută care contează se derivă din ea; cu mai multe poziții sau monede diferite se golesc (tabelul somației tipărește oricum conversia pe poziție).
  3. `CaseAccessoryService::refreshStoredTotal($case)` (`src/Service/Case/CaseAccessoryService.php:45`); când `aggregate()` e `null` (nicio poziție care contează) `calculatedInterest` se setează explicit `'0.00'` (azi rămâne vechi).
  4. Termene: `flush` înainte (`findOneByCaseTypeAndDate` interoghează DB), apoi `DeadlineService::createPrescriptionDeadlines($case)` (`src/Service/Deadline/DeadlineService.php:802`, idempotent; `DeadlineCreationSubscriber::postPersist`, 237, rulează doar la persist-ul inițial). Orfane: mulțimea așteptată din `prescriptionDueDates($case)` (841, sare peste excluse și note de credit) trecută prin `substantiveTermEnd`; un `LegalDeadline` `PRESCRIPTIE` necompletat cu `deadlineDate` în afara mulțimii și egal exact cu data calculată pentru o scadență care tocmai a ieșit din creanță se șterge cu audit `DEADLINE_DELETED` (ca `CaseDeadlineController.php:527-558`); orice alt termen `PRESCRIPTIE` nepotrivit rămâne și intră în `deadlinesUnmatched`.
  5. `CompetentCourtResolver::resolveForItems(...)` (`src/Service/Court/CompetentCourtResolver.php:133`) pe pozițiile care contează, cu `referenceDate`, `relationshipType ?? COMERCIAL`, județul și localitatea debitorului principal, tipul de penalitate, rata, plafonul și data contractului. Trei cazuri: identic (nimic), diferit și determinat (propunere), `null` cu candidați sau cheie `art99_*` (avertisment cu `explanationKey`; instanța curentă NU se golește). Nimic nu se scrie pe `court`.
  6. `OpAdmissibilityValidator::validate($case)` (`src/Service/Validation/OpAdmissibilityValidator.php:63`) + `AccessoryComputabilityChecker::issues($case, $referenceDate)` (`src/Service/Case/AccessoryComputabilityChecker.php:38`), împărțite în erori și avertismente.
  7. Audit `claim_edited`, categoria nouă `CATEGORY_CLAIM_EDITED`: `reason`, status, before/after pe poziții (id, număr, dată, scadență, sumă, monedă, curs + data cursului, `amountRon`, `paidAmount`, `kind`, `excludedByLawyer`, `confirmed`, debitor, `causeReference`, `dedupKey`, motivul excluderii), sursa (document id, nume, `contentHash`, origine AI/manual, valorile extrase originale dacă au fost suprascrise), totaluri before/after (principal, accesoriu, dată de referință), `court_id` before/after și propunerea, termene create/șterse/nepotrivite, codurile de admisibilitate; totul prin `PiiMasker::maskCnpInArray` (`src/Util/PiiMasker.php:351`).

**Decizie (contradicție rezolvată): termenele orfane se șterg fizic doar la potrivire exactă, cu audit; nu se introduce o stare „anulat”.** Revizia juridică cerea marcare, nu ștergere; `LegalDeadline` nu are nici coloană de proveniență (decizie amânată, memoria `project_portal_case_number_correction_deferred`) nici stare de anulare, iar a le adăuga lărgește schema dincolo de scop. Ștergerea doar la potrivire exactă + audit `DEADLINE_DELETED` este exact calea pe care avocatul o are azi manual; termenele editate de avocat nu se ating. Documentat în docblock ca limită cunoscută.

**Decizie: data de referință a accesoriului rămâne `paymentNoticeDate ?? createdAt`** (`CaseAccessoryService::referenceDate`, 29). O factură nouă cu scadență după `createdAt` primește accesoriu 0 până la somație, când totul se rebazează pe data somației. Schimbarea ar afecta cardul și somația fără decizia avocatului (întrebarea L). Răspunsul UI spune explicit data de referință și că se recalculează la somație; `accessoryCutoffDate` setat înaintea scadenței noii facturi produce avertisment.

**Fișiere modificate**
- `src/Service/Case/ClaimTotalsService.php:105-133`: ramură nouă pentru `countedCount = 0` și `needsManualFxCount = 0` (testele din `ClaimTotalsDenormalizationAdversarialTest` nu se modifică). Apelantul din Faza 4 refuză oricum excluderea ultimei poziții care contează.
- `src/Service/AuditLogService.php`: `CATEGORY_CLAIM_EDITED`; `templates/case/overview/_tab_audit.html.twig`: paletă + cheie `audit.category.claim_edited` (RO/EN).

**Teste de adăugat**
- `tests/Service/Case/ClaimRecalculatorTest.php` (KernelTestCase): dosar cu 3 poziții: totaluri, accesoriu, termene create; excluderea unei poziții: termen orfan șters doar la potrivire exactă, termen editat păstrat și raportat; două facturi cu aceeași scadență: termenul rămâne până la excluderea ambelor; trecere peste 200.000 lei: propunere fără schimbare; resolver `null` (`art99_divergent_competence`): instanța păstrată; toate excluse: `calculatedInterest = 0.00`; factură cu scadență între `createdAt` și azi: accesoriu 0 și dată de referință raportată; `accessoryCutoffDate` înaintea scadenței: avertisment; factură prescrisă: `OP_ITEM_PRESCRIBED` ca avertisment, fără termen în trecut; factură cu scadență viitoare: `OP_ITEM_NOT_YET_DUE` ca eroare; audit cu before/after și CNP mascat.
- `tests/Service/Case/ClaimRecalculatorFxTest.php` (TestCase): derivarea câmpurilor FX (o poziție EUR, două poziții, monede mixte).
- `tests/Service/Case/ClaimTotalsServiceTest.php`: ramura `countedCount = 0`.
- `tests/Service/Case/CaseAccessoryServiceTest.php`: `refreshStoredTotal` când `aggregate()` e `null`; poziție cu scadență după data de referință.

**Riscuri**
- Scalari vechi după excluderea tuturor pozițiilor (`dueDate`, `invoiceNumber`, `calculatedInterest`). Mitigare: ramura nouă + refuzul din Faza 4.
- Urma FX la nivel de dosar devine greșită la dosare în valută. Mitigare: recalculatorul devine proprietarul câmpurilor; test pe contextul somației.
- Termen orfan neidentificabil sigur. Mitigare: potrivire exactă, restul raportat.
- `persistDeadline` face `flush` și audit propriu în interiorul tranzacției (`DeadlineService.php:980-1023`): poziția nouă trebuie în UnitOfWork și flush-uită înainte de termene.

**Gata când**
- [ ] Toate testele de mai sus verzi; suita completă verde.
- [ ] Nicio rută nu apelează încă serviciul (faza e pur de serviciu); `bin/console lint:container` curat.

**Estimare:** 1,5 zile.

---

### Faza 4: cardul Creanță

**Obiectiv.** Avocatul adaugă, exclude sau reinclude facturi și modifică detaliile creanței direct din overview, în `AMIABIL`, cu recalcul și audit. Machetă: `mockups/v2/04-dosar/creanta-editare.html`.

#### 4.1 Securitate

- `src/Security/Voter/CaseVoter.php`: `CLAIM_EDIT = 'CASE_CLAIM_EDIT'` (proprietar, `AMIABIL`, neșters, inclus în `LOCKED_BY_FILING`, 47). **Decizie:** admin fără bypass pentru editarea creanței (ca `FILING_CONTENT`): o cifră pe care o va cita instanța aparține avocatului. `CASE_EDIT` rămâne neatins (motivul de la Faza 2).
- `tests/Security/Voter/CaseVoterTest.php`: `CASE_CLAIM_EDIT` pe fiecare status, alt utilizator, admin, blocaj de depunere.

#### 4.2 Serviciul de editare

- `src/Service/Case/ClaimPositionEditor.php`: `addManual(LegalCase, ClaimPositionInput, User): ClaimRecalculationResult`, `addFromDocument(LegalCase, Document, ClaimPositionInput, User)`, `exclude(LegalCase, ClaimItem, string $reason, User)`, `reinclude(LegalCase, ClaimItem, User)`, `updateDetails(LegalCase, ClaimDetailsInput, User)`. Totul în `wrapInTransaction`, cu `LockMode::PESSIMISTIC_WRITE` pe `LegalCase` și reverificarea statusului `AMIABIL` în interior (cursă cu `trimite_somatie`).
- `src/DTO/Case/ClaimPositionInput.php` (număr, dată, scadență obligatorie, sumă, monedă din `Step3ClaimData::SUPPORTED_CURRENCIES`, debitor obligatoriu când dosarul are mai mulți, `causeReference`, `sourceDocumentId` opțional) și `src/DTO/Case/ClaimDetailsInput.php` (`claimDescription`, `contractReference`, `penaltyClauseArticle`, `penaltyClauseText`, `paymentNoticeNumber`), cu constrângeri Symfony cu argumente numite.
- `src/Service/Case/ClaimItemFactory.php`: metodă publică nouă `rowFromInput(ClaimPositionInput, ?string $issuerCui): ClaimItemRow` care aplică conversia (`applyConversion`, 456, este privată și singurul loc unde lipsa cursului BNR devine `needsManualFx`); `materialize` (514) rămâne calea de persistare (StringCapper, formatare, `addClaimItem`). `dedupKey` (570) pentru poziția manuală cu `issuerCui` = CUI-ul creditorului dosarului, ca să coincidă cu rândurile extrase.
- Deduplicare: `ClaimItemRepository::findOneByDedupKey` (`src/Repository/ClaimItemRepository.php:20`) **înainte** de `materialize` (care sufixează `#2` doar în lotul curent, 526-531, nu față de DB; coliziunea ar lovi `uniq_claim_item_case_dedup`, `src/Entity/ClaimItem.php:36`, și ar închide EntityManager-ul). Refuz cu mesaj care numește poziția existentă și oferă „Reinclude” dacă e exclusă. Niciodată sufixare tăcută.
- Reguli de refuz: excluderea ultimei poziții care contează (**Decizie**: se refuză; un dosar fără creanță înseamnă ștergerea dosarului, Faza 2); dosar fără nicio poziție care contează (moștenit, cu curs lipsă); o poziție nouă care introduce o eroare de admisibilitate **nu** se refuză la adăugare (o scadență peste câteva zile e legitimă înainte de somație), ci se salvează cu avertisment și blochează somația (4.6). Excluderea cere motiv (enum + text liber) și avertizează că accesoriile până la data plății nu se mai cer.
- `src/Service/Case/LegalCaseClaimFieldsMapper.php` (din `applyAccessoryFields` + `trimmedOrNull`, `CaseWizardController.php:2108-2151`; extragere mecanică folosită și de `persistWizard`, apoi refolosită de `updateDetails`). `updateDetails` rulează și recalculatorul (cel puțin instanța și admisibilitatea), pentru că `contractReference` alimentează gruparea pe cauze (`ClaimCauseGrouper`).

**Decizie (contradicție rezolvată): `paymentNoticeNumber` rămâne editabil în `AMIABIL`.** Revizia juridică l-a citit ca numărul somației deja trimise; în cod este numărul din registrul de ieșire al cabinetului pe care somația îl va purta (`translations/messages.ro.yaml:410`, setat la pasul 3 înainte de orice somație). Dacă somația a fost deja generată (`paymentNoticeDate` setat), modalul avertizează că trebuie regenerată.

#### 4.3 Controller, formulare, template-uri

- `src/Controller/Case/CaseClaimController.php` (prefix `/case/{id}/claim`, toate POST cu CSRF și `CASE_CLAIM_EDIT`, răspuns 200 `text/vnd.turbo-stream.html` atât la succes cât și la eroare de validare, model `DocumentController::respondDocument`, 285):
  - `case_claim_add_manual` POST `/add`
  - `case_claim_exclude` POST `/item/{itemId}/exclude`
  - `case_claim_reinclude` POST `/item/{itemId}/reinclude`
  - `case_claim_details` POST `/details`
  - `case_claim_court_accept` POST `/court/accept`: re-rulează resolver-ul pe server și setează instanța doar dacă rezultatul coincide cu id-ul postat (oglinda verificării „instanță activă” din wizard, `CaseWizardController.php:1680-1695`); audit cu `court_id` before/after.
- `src/Form/Case/ClaimPositionType.php`, `src/Form/Case/ClaimDetailsType.php`, formulare minime cu CSRF pentru exclude/reinclude. **Decizie:** formulare Symfony clasice în modale Preline (ca `_modal_upload_document.html.twig`), nu `Step3ClaimLiveComponent` (cuplat cu `Step3ClaimType`, stare mare serializată, randări cu nonce CSP diferit care fac reload complet, memoria `ops_turbo_csp_nonce_full_reload`). Cifrele vin de la server după salvare.
- Template-uri: `_claim_composition.html.twig` primește `id="case-claim-card"` și randează tabelul și cu o singură poziție (azi doar `claim_items|length > 1`, linia 110) și cu pozițiile excluse (azi `claim_items` = doar cele care contează); `_court_summary.html.twig` primește `id="case-court-card"` și zona de propunere; `_modal_claim_position.html.twig`, `_modal_claim_details.html.twig`, `_modal_claim_exclude.html.twig`; `_claim_actions_turbo_stream.html.twig` (replace `case-claim-card`, `case-court-card`, `case-kpi-grid`, `case-hero`, update `case-detalii-sidebar`, `panel-termene`, append `toasts`, închiderea modalului, rezumatul „ce s-a schimbat”: sumă, accesoriu cu data de referință, instanță propusă, termene create/șterse, avertismente). Contextul vine din `OverviewContextBuilder::build` (`src/Service/Case/OverviewContextBuilder.php:180`) după recalcul.
- Traduceri RO/EN (prefix `case.claim.edit.`): `add_invoice`, `add_manual`, `add_from_document`, `exclude`, `reinclude`, `exclude_reason`, `exclude_reason.*`, `exclude_warning_accessories`, `details_title`, `details_payment_notice_regenerate`, `changed.amount`, `changed.accessory`, `changed.accessory_reference`, `changed.court_proposed`, `changed.court_unresolved`, `changed.deadlines_created`, `changed.deadlines_removed`, `changed.deadlines_unmatched`, `court_accept`, `error.duplicate`, `error.duplicate_excluded`, `error.last_counting_position`, `error.no_counting_position`, `error.fx_missing`, `error.party_mismatch`, `warning.not_yet_due`, `warning.prescribed`, `warning.other_contract`, `warning.cutoff_before_due`, `manual_no_partial_payment`.

#### 4.4 Adăugare din document cu extracție după creare

- `case_claim_invoice_upload` POST `/case/{id}/claim/invoice`: tip `FACTURA`, plafon 10 (`DocumentRepository::countCappedAttachments`, 71; la atingere același mesaj `document.upload.max_files_reached` și trimitere la manual), `documentUploadLimiter` (`config/packages/rate_limiter.yaml:22`) și `UploadRateLimiter::acceptsExtractions` înainte de stocare, `UploadDeduplicator::partition` față de documentele dosarului (`contentHash`), `DocumentUploadService::upload($case, ...)`, apoi `$bus->dispatch(new ExtractDataMessage($document->getId()))` (rutat `async`, `config/packages/messenger.yaml:59`; in-memory în test). Handler-ul (`ExtractDataMessageHandler.php:73`) nu condiționează nimic de `legalCase`.
- `case_claim_invoice_status` GET `/case/{id}/claim/invoice/{documentId}` (turbo-frame): verifică proprietatea documentului și apartenența la dosar (endpointul `documents_status` din wizard citește bag-ul, nu se refolosește); la `COMPLETED` întoarce formularul precompletat din `ClaimItemFactory::rowsFromDocuments([$docId])` (69) cu conversia și `warningKeys`; la `FAILED` / `SKIPPED_BY_POLICY` formularul manual gol cu mesaj; cât timp e `PROCESSING` rândul „în așteptare” cu `data-controller="mercure"` pe topicul `user/{uid}/document/{docId}/extraction-status` (`MercureTokenService.php:53`, `EventSubscriber/ExtractionMercurePublisher.php`) și fallback `poll-frame`.
- Verificarea părților, `src/Service/Case/ClaimDocumentPartyMatcher.php`: `extractedData['creditor']['cui']` vs CUI-ul creditorului dosarului, `extractedData['debtor']['cui']` vs CUI-urile debitorilor; normalizare CUI extrasă din `ClaimItemDeduplicator::normalizedCui` (261, privată) într-un util public (`src/Util/` nu are unul). Refuz doar la nepotrivire certă (ambele CUI-uri prezente și diferite); CUI lipsă = avertisment cu compararea numelui. La refuz documentul tocmai stocat se șterge prin `DocumentUploadService::delete` (FACTURA nu e ștergibilă de avocat: `DocumentType::isDeletable`, 128).
- Pierdere asumată: extracția unui singur document nu vede contractul (`ContractualPaymentTermApplier`) și nici extrasele (`StatementPaymentSettler`); scadența e câmp obligatoriu, textul de ajutor spune că plățile din extrase nu se aplică automat și că `paidAmount` rămâne 0 pe poziția nouă.

#### 4.5 Factură din alt contract

Când `causeReference`/`contractReference` al poziției noi diferă de cele existente: avertisment bloc cu opțiunile „Confirm că e aceeași cauză” sau „Dosar nou”; dacă resolver-ul întoarce `null` cu `art99_*`, instanța curentă rămâne, cardul instanței arată motivul, somația și cererea OP arată același avertisment. Pentru accesoriu contractual se cere confirmarea explicită că clauza dosarului se aplică noii facturi.

#### 4.6 Poarta de admisibilitate la somație

`src/Controller/Case/CaseSummonsController.php` rulează azi doar `AccessoryComputabilityChecker` (linia 81 în fișier); `OpAdmissibilityValidator::validate` abia la cererea OP (`CasePaymentOrderController.php:143`) și la `FilingGate.php:58`. Se adaugă refuzul `trimite_somatie` pe erori de admisibilitate (`OP_ITEM_NOT_YET_DUE`), cu Turbo Stream și test în `CaseSummonsControllerTest`. Fără aceasta, o factură neexigibilă adăugată în `AMIABIL` ar intra în somație (CPC art. 1014).

**Decizie:** `court = null` din resolver **nu** blochează somația (somația nu numește instanța; cererea OP o verifică deja), ci rămâne avertisment vizibil în card și în modalul somației. Revizia juridică propunea blocarea; ar fi un comportament nou pe un flux existent, fără decizia avocatului (întrebarea F).

**Teste de adăugat**
- Unit: `tests/Service/Case/ClaimDocumentPartyMatcherTest.php` (CUI cu `RO`, zerouri, spații; CUI lipsă; nume diferit); `tests/Util/CuiNormalizerTest.php`; `ClaimItemFactoryTest`: `rowFromInput` cu și fără curs.
- Integrare: `tests/Service/Case/ClaimPositionEditorTest.php` (KernelTestCase): coliziune `inv:` și `amt:`; poziție existentă exclusă oferită la reincludere; EUR fără curs raportat `needsManualFx`; dosar fără poziții care contează refuzat; excluderea ultimei poziții refuzată; motiv obligatoriu; `updateDetails` cu `contractReference` schimbat produce propunere de instanță; POST dublu (idempotență).
- Controller: `tests/Controller/Case/CaseClaimControllerTest.php` (WebTestCase, model `DocumentControllerTest:205-300`): Turbo Stream la succes și la eroare de validare; CSRF; 403 în `SOMATIE_TRIMISA`; 403 alt utilizator; 404 dosar șters; butoanele absente pentru alt status (`CaseOverviewControllerTest`); acceptarea instanței cu id manipulat refuzată; upload factură: dispatch asertat pe transportul in-memory, refuz la plafon, refuz la limită fără dispatch, dedup pe `contentHash`; prefill cu `extractedData` simulat (`COMPLETED`, `FAILED`, `SKIPPED_BY_POLICY`); refuz la nepotrivire de CUI cu documentul șters.
- Handler: `tests/MessageHandler/ExtractDataMessageHandlerTest.php`: document deja atașat unui dosar.
- Somație: `CaseSummonsControllerTest`: poziție cu scadență viitoare blochează `trimite_somatie`.
- Cascadă: `tests/Cascade/ClaimEditCascadeTest.php`: upload → extracție (handler sincron în test) → prefill → salvare → card și KPI reîmprospătate, termen nou creat.

**Riscuri**
- Coliziune de `dedupKey` la flush (critic). Mitigare: `findOneByDedupKey` înainte, test pe ambele tipuri de cheie.
- Erorile din modale care fac reload complet. Mitigare: 200 Turbo Stream la toate răspunsurile; test cu `Accept: text/vnd.turbo-stream.html` pentru eroare.
- Punct nou de extracție fără limitare. Mitigare: aceleași limitatoare ca wizard-ul, test fără dispatch la limită.
- Cursă editare vs somație. Mitigare: lock pesimist + reverificare status în tranzacție; test cu două EntityManager-e sau asertare pe lock.
- `FACTURA` refuzată rămâne pe dosar și consumă plafonul. Mitigare: ștergere la refuzul părților; abandonul după `COMPLETED` rămâne întrebare deschisă.

**Gata când**
- [ ] Toate testele verzi; suita completă verde; `lint:yaml translations/`; `make tailwind`.
- [ ] Live Playwright: adăugare manuală, adăugare din factură cu extracție reală pe un document de test, excludere cu motiv, reincludere, detalii, propunere de instanță la depășirea pragului, somație blocată de scadență viitoare; KPI, card, sidebar și termene verificate după fiecare acțiune.

**Estimare:** 4 zile (4.1-4.3 două zile, 4.4-4.6 două zile).

---

### Faza 5: restul refactorizării wizard-ului

**Obiectiv.** `CaseWizardController` dispare, înlocuit de servicii în `App\Service\Wizard\` și controllere per pas în `App\Controller\Case\Wizard\`. Fiecare pas este o extragere mecanică, un commit, suita verde.

**Decizie:** namespace-urile de mai sus se fixează acum (Faza 0 creează deja `App\Service\Wizard\`), ca o redenumire ulterioară să nu atingă 9 clase.

| Pas | Clasă | Linii mutate din `CaseWizardController.php` | Teste de rulat | Risc |
|---|---|---|---|---|
| 5.1 | `ClaimRowsPostBinder` (`postedAmount`, `postedDate`, `applyPostedRows`, `validateRows`, `anyRowCounts`; parametrul **`$confidenceThreshold`** cu același nume, altfel bind-ul global din `config/services.yaml:63` nu se aplică și pragul cade tăcut pe 0.6) | 1395-1515 | `ClaimItemsFlowTest`, `tests/Controller/Case` | scăzut |
| 5.2 | `WizardDocumentReader` (`loadOwnedDocuments`, `allTerminal`, `conflictDocumentNames/Descriptors`, `creditorBankAccounts`, `consumerDebtorName`, `positionsPreview`; 9 apelanți) | 427-469, 1359-1393, 2956-3002, 3168-3216 | `Step0Test`, `DedupAdversarial`, `WizardRetryExtractionTest`, `Cascade`, `ConflictAuditTest` | scăzut |
| 5.3 | `LibraryFieldComparer` (`sameLibraryValue`, 822-837, peste `ConflictValueEquivalence::same` și `OnrcNumber::sameRegistration`); `LegalCaseClaimFieldsMapper` dacă nu a fost deja extras în Faza 4 | 822-837, 2108-2151 | `tests/Controller/Case` | scăzut |
| 5.4 | `WizardCreditorResolver` (`CREDITOR_LIBRARY_FIELDS`, `matchLibraryCreditor`, `creditorDifferences`, `takeLibraryCreditor`, `settleCreditorWithLibrary`, `reuseOrCreateCreditor`, `parseAnafCheckedAt`) | 758, 769-890, 1970-2028 | `Step1To4Test` | scăzut-mediu |
| 5.5 | `WizardDebtorResolver` (`LIBRARY_FIELDS`, `checkAnafStatus` cu `?AnafLookupService` și Logger, `matchLibraryDebtors`, `libraryDifferences`, `debtorReachedLibrary`, `bindLibraryDebtors`, `refreshPickedDebtors`, `entryFromLibrary`, `buildDebtorLink`, `libraryDataFrom`, `linkWithChecks`) | 380-408, 964, 978-1068, 1121-1218, 2038-2106 | `Step1To4Test`, `ConflictsTest` | mediu |
| 5.6 | `WizardFigures` (`accessoryReferenceDate`, `resolveConversion`, `ronAmount`, `splitIssues`, `safeComputeForSidebar`, `computeFromItems`, `itemsPrincipal`, `safeStampDuty`) | 415-425, 2156-2191, 2263-2476 | `Step1To4Test`, `ClaimItemsFlowTest`, `WizardFiguresTest` nou | mediu (fallback-uri tăcute) |
| 5.7 | `CaseWizardPersister` (`persistWizard`, `auditClaimItems`, `aggregateAutoFilledFields`, `dtoFieldNames`, `resolveClaimRows`, `buildLegalCaseSkeleton`); returnează `WizardPersistResult(case, creditorReused, creditorSource)` în loc de `&$creditorOutcome` (flash-ul `creditor_reused` după commit) | 1802-1968, 2193-2261, 2478-2535 | `Step1To4Test`, `ConflictAuditTest`, `ClaimItemsFlowTest`, suita completă | ridicat |
| 5.8 | `WizardConflictCoordinator` (2560-2954 + `chosenParty` 3017-3036); `ANAF_SETTLED_FIELDS` se mută și `templates/case/_prefill_conflicts.html.twig:35` (care o citește prin `constant()`) se actualizează **în același commit**; `settleConflicts` întoarce rezultat tipizat (`settled: bool`, `flashKey: ?string`) și controllerul face `addFlash` în același loc; `showChosenParty` devine `discardChosenParty(bag)` + salvare/redirect în controller; `redirectForDebtorsNoLongerOffered` devine `WizardBag::debtorsNoLongerOfferedReason(): ?string` | 2560-2954, 3017-3036 | `ConflictsTest`, `ConflictRecordTest`, `ConflictChoiceAdversarialTest`, `ConflictAuditTest`, apoi tot | ridicat |
| 5.9 | `TranslatorInterface` injectat (pentru `claimWithPositionAwareDescription` 1324, `trans` 3218, `removalMessage` 601); `getSubscribedServices` (3225) șters | 1346, 3218-3230 | `Step0Test`, `ClaimItemsFlowTest` | scăzut |
| 5.10 | `AbstractWizardStepController` (fără atribute de rută; clasele abstracte sunt sărite de `AttributeFileLoader`): constructor cu `WizardStateStore`, `TranslatorInterface`; helperi `stepRejected`, `removableDocument`, `renderConfirmation` | 587-624, 1753-1800, 3004-3015 | `tests/Controller/Case` | scăzut |
| 5.11 | Split, câte un commit: `Step0DocumentsController` (start, documents, documents_status, documents_retry, documents_remove_preview, documents_remove, documents_type, skip, draft_new), `Step1CreditorController`, `Step2DebtorController` (debtor, debtor_pick, debtor_unpick), `Step3ClaimController` (claim + `claimWithPositionAwareDescription`), `Step4ConfirmationController` (confirmation, `$caseCreationLimiter` cu același nume: autowired după numele parametrului, `rate_limiter.yaml:18`). Fiecare clasă repetă `#[Route('/case/new', name: 'case_wizard_')]` și `#[IsGranted('ROLE_USER')]` (atributele nu se moștenesc). Comentariile din `templates/base.html.twig:115` și `_step1_creditor_content.html.twig:286` se actualizează. `CaseWizardController` dispare la ultimul commit | tot restul | după fiecare: `bin/console debug:router \| grep case_wizard_` = 14 rute (+1 `draft_new`) cu aceleași path-uri și metode; `tests/Controller/Case` + `Cascade`; la final suita + `lint:container` | mediu |
| 5.12 | Opțional: `DocumentRemovalPlanner::plan/apply` pe `WizardBag` (actualizează `DocumentRemovalPlannerTest`) | | | scăzut |

Numele de rute (14 + `case_wizard_draft_new`) rămân identice: consumatorii sunt template-urile (12 nume distincte) și `Step2DebtorsLiveComponent` (postează la `case_wizard_debtor`). `Step3ClaimLiveComponent` nu atinge nimic din controller.

**Teste de adăugat** (doar unde logica e acoperită azi numai prin HTTP)
- `tests/Service/Wizard/ClaimRowsPostBinderTest.php`: `applyPostedRows` (potrivire pe `dedupKey`, rând absent rămâne neconfirmat, sumă neparsabilă păstrează valoarea extrasă, `amountRon` recalculat doar cu `exchangeRate`, confirmare individuală vs tabel după prag) și `validateRows` (cele 4 chei, inclusiv `no_countable_row`); `PostedAmountParsingTest` (care instanțiază controllerul cu `newInstanceWithoutConstructor` și apelează `postedAmount` prin reflexie, linia 25) se mută aici pe metoda publică.
- `tests/Service/Wizard/WizardFiguresTest.php`: FX nerezolvat → principal null; `InterestCalculator` aruncă `DomainException` → interest null, `accessoryTotal 0`; aggregator aruncă → `AggregatedAccessoryResult(0.0)`; un singur item → desfacere din `forItem`.
- `tests/Service/Wizard/WizardCreditorResolverTest.php` (KernelTestCase): fără diferențe → link automat; diferențe fără alegere → întrebare; `update` pe companie somată → refuzat; strict păstrează valorile bibliotecii.
- `tests/Service/Wizard/WizardDebtorResolverTest.php` (KernelTestCase): județ/localitate din pas doar cu aceeași adresă; companie înghețată nu primește completări; CUI schimbat retrage verificările ANAF/insolvență.
- `tests/Service/Wizard/CaseWizardPersisterTest.php` (KernelTestCase): rollback la excepție în `attachToCase` (fără `LegalCase`, creditor nou, audit); `creditorReused` corect după commit.
- `tests/Service/Wizard/WizardConflictCoordinatorTest.php`: amprenta recunoașterii; rezultatul tipizat cu cheia de flash corectă.

**Riscuri**
- `constant()` din template pe `ANAF_SETTLED_FIELDS` (ridicat): eroare Twig doar când există conflicte. Mitigare: același commit, `grep -rn ANAF_SETTLED_FIELDS src tests templates`, `ConflictsTest` + `ConflictChoiceAdversarialTest`.
- `PostedAmountParsingTest` prin reflexie (ridicat). Mitigare: mutat în 5.1.
- Bind-ul `$confidenceThreshold` și `$caseCreationLimiter` după numele parametrului (mediu). Mitigare: nume păstrate; `debug:container --show-arguments`.
- Out-param `&$creditorOutcome` prin closure (mediu). Mitigare: `WizardPersistResult`.
- Comentarii cu referințe la pași, română și em-dash în codul mutat (108-117, 907-911, 1283-1287, 1727-1738, 3218-3221). Mitigare: curățate la mutare, fără schimbare de cod.

**Gata când**
- [ ] `src/Controller/Case/CaseWizardController.php` nu mai există; `debug:router` arată aceleași rute; `lint:container` curat.
- [ ] Suita completă verde; nicio schimbare de asertiune în testele wizard existente (în afara mutării `PostedAmountParsingTest`).
- [ ] Live: un wizard complet cu conflicte între documente, bibliotecă de debitori și ANAF (offline în test, real pe Docker).

**Estimare:** 4 zile.

---

### Faza 6: verificare live completă + documentul pentru avocat

**Obiectiv.** Fluxurile noi și cele vechi verificate în browser pe Docker, documentul pentru avocat corectat și ilustrat.

- Scenarii Playwright (clonă `TMP-LIVE-*`): S1 (factură uitată, adăugare din document), S3 (excludere cu motiv), S5 (factură refuzată: CUI diferit, dublură), S7 (prag 200.000 lei, propunere de instanță acceptată), S8 (ștergere dosar), S9 (detalii creanță), plus: factură plătită tardiv (avertisment la excludere), factură din alt contract (avertisment + instanță nerezolvată), factură prescrisă, factură în valută fără curs, factură cu scadență viitoare (somație blocată), cursa editare vs somație (două taburi), ciornă reluată de pe alt browser, ciornă expirată prin comandă, wizard complet după refactor.
- `MODIFICARE-DOSAR-DUPA-CREARE-PENTRU-AVOCAT-2026-11-03.md` (fără diacritice, cu „tu”, conform memoriei `feedback_mesaje_catre_avocat`). Corecturile din revizia juridică de mai jos și întrebările F, G, J, M au fost deja aplicate la 2026-11-03, înainte de trimiterea documentului; în Faza 6 rămân capturile și întrebările H, I, K, L dacă se dovedesc necesare: linia 20 („nimic nu a plecat” → „nimic nu a plecat prin aplicație; istoricul și documentele se păstrează pentru evidența ta”), linia 29 S3 (plată la timp → exclude; plată cu întârziere → păstrezi și înregistrezi plata, întrebarea G), linia 21 (instanța „îți arată ce ar rezulta, fără să o schimbe fără confirmarea ta”; dobânda „până la data creării dosarului, recalculată la somație”), linia 35 S9 („sumele nu se modifică, dar referința contractului poate schimba instanța propusă”), linia 31 S5 (refuz doar la alt emitent/destinatar sau dublură; alt contract = avertisment), linia 15 (termenul de 15 zile curge de la comunicare). Capturi din scenariile de mai sus, apoi `.docx` prin pandoc (memoria `feedback_documents_as_presented`).
- Întrebările noi pentru avocat (secțiunea 6) adăugate la finalul documentului.

**Gata când**
- [ ] Toate scenariile trec; capturile în `docs/LexRecovery/capturi/editare-dosar/`.
- [ ] Documentul `.md` + `.docx` regenerate, fără em-dash (`grep -cP "\x{2014}"` = 0).
- [ ] `make demo-sync` după confirmare.

**Estimare:** 1,5 zile.

---

## 4. Registru de riscuri consolidat

| # | Risc | Severitate | Faza | Mitigare | Cum verificăm |
|---|---|---|---|---|---|
| R1 | Dosarul șters rămâne accesibil pe `/case/{id}` și pe acțiuni (`findWithOverviewRelations` fără filtru, `CaseOverviewController:28`) | critic | 2 | filtru `deletedAt IS NULL` + refuz global în voter înainte de bypass-ul de admin | WebTestCase: 404 pe GET și pe tranziții după ștergere |
| R2 | Coliziune `dedupKey` cu poziție existentă lovește `uniq_claim_item_case_dedup` la flush și închide EM | critic | 4 | `findOneByDedupKey` înainte de `materialize`; refuz explicit, niciodată sufixare | KernelTestCase pe `inv:` și `amt:`; poziție exclusă oferită la reincludere |
| R3 | Somația nu re-validează admisibilitatea: factură neexigibilă intră în somație (CPC art. 1014) | ridicat | 4 | poartă `OpAdmissibilityValidator::validate` la `trimite_somatie` | `CaseSummonsControllerTest` cu scadență viitoare |
| R4 | Cursa editare vs `trimite_somatie` în două taburi: cererea citează altă sumă decât somația (CPC art. 1015) | ridicat | 4 | lock pesimist pe `LegalCase` + reverificare status în tranzacție; 409 Turbo Stream după somație | test cu două EM sau asertare pe lock; live cu două taburi |
| R5 | Cursa POST upload vs GET-uri de frame la pasul 0 pierde `documentIds` (sesiunea pe fișiere serializa cererile, tabela nu) | ridicat | 1 | salvare doar la schimbare, `draft_id` cu auto-reparare, `#[ORM\Version]` cu reuniune | KernelTestCase: două salvări din aceeași versiune păstrează ambele id-uri |
| R6 | Pierderea tăcută a ciornei la schimbare de DTO (30 de zile peste mai multe deploy-uri) | ridicat | 1 | `payloadVersion`, `allowed_classes`, stare „necitibil” afișată, regula „proprietăți noi doar cu default” | fixture de payload serializat care pică la DTO fără default |
| R7 | Comanda de expirare șterge documente ale wizard-urilor vii sau în `PROCESSING` | ridicat | 1 | prag 30 de zile pe `created_at`, excludere `PROCESSING`/`PENDING_RETRY`, `--dry-run` | Command test cu Clock mock |
| R8 | Refolosirea extracției între utilizatori scurge CNP/IBAN | ridicat | 1.5 | `uploadedBy` obligatoriu în interogare | test adversarial (B după A) |
| R9 | Refolosirea cu tip declarat diferit sau sursă `FAILED` produce date greșite | ridicat | 1.5 | sursă `COMPLETED`, tip `ALT_DOCUMENT` sau egal, `sourceDocumentId` rescris | unit pe serviciu + Step0 WebTestCase |
| R10 | Scalari vechi după excluderea tuturor pozițiilor (`dueDate`, `invoiceNumber`, `calculatedInterest`) | ridicat | 3, 4 | refuzul excluderii ultimei poziții + ramura `countedCount = 0` + `calculatedInterest 0.00` | `ClaimTotalsServiceTest`, `ClaimRecalculatorTest` |
| R11 | Câmpurile FX ale dosarului greșite la dosare în valută (somația afirmă „X EUR x curs” neactual) | ridicat | 3 | recalculatorul derivă sau golește câmpurile | `ClaimRecalculatorFxTest` + test pe contextul somației |
| R12 | Termen `PRESCRIPTIE` orfan neidentificabil sigur (fără proveniență, editabil) | ridicat | 3 | ștergere doar la potrivire exactă, restul raportat; două scadențe egale → termenul rămâne | `ClaimRecalculatorTest` (editat păstrat, două facturi aceeași scadență) |
| R13 | Factură din alt contract cu clauze diferite și competență divergentă (CPC art. 99) | ridicat | 4 | avertisment bloc + confirmare; `court = null` nu golește instanța; confirmarea clauzei contractuale | `ClaimPositionEditorTest`, live S alt contract |
| R14 | `constant()` din `_prefill_conflicts.html.twig:35` pe `ANAF_SETTLED_FIELDS` după mutare | ridicat | 5 | același commit + grep + testele de conflicte | `ConflictsTest`, `ConflictChoiceAdversarialTest` |
| R15 | `PostedAmountParsingTest` prin reflexie pe controller | ridicat | 5 | mutat pe `ClaimRowsPostBinder::postedAmount` | testul nou verde |
| R16 | Fixture-uri de test cu array-uri parțiale în sesiune | ridicat | 0, 1 | array stocat în Faza 0; trait de test înainte de comutare în Faza 1 | zero modificări de test în Faza 0 |
| R17 | Erorile din modale fac reload complet (nonce CSP) și pierd mesajul | mediu | 2, 4 | 200 Turbo Stream la toate răspunsurile, inclusiv erori | WebTestCase cu `Accept: text/vnd.turbo-stream.html` |
| R18 | Punct nou de extracție fără limitare și buget AI | mediu | 4 | `documentUploadLimiter` + `acceptsExtractions` înainte de stocare | test: refuz la limită, fără dispatch |
| R19 | Accesoriu 0 pentru factura cu scadență după `createdAt` (referința rămâne data creării) | mediu | 3, 4 | data de referință afișată; recalcul la somație; întrebarea L | `ClaimRecalculatorTest` documentează comportamentul |
| R20 | `CASE_EDIT` permite și statusurile după somație | mediu | 2, 4 | atribute noi `CASE_CLAIM_EDIT`, `CASE_DELETE`; `CASE_EDIT` neatins | `CaseVoterTest` pe fiecare status |
| R21 | Instanța propusă acceptată pe id manipulat | mediu | 4 | ruta de acceptare re-rulează resolver-ul și compară | WebTestCase cu id greșit |
| R22 | Notificări și biblioteci arată dosare șterse | mediu | 2 | `markReadByDedupKeyPrefix`, `casesCount` filtrat | WebTestCase: KPI și creditor N-1 |
| R23 | Ștergere cu somație generată sau dovadă de taxă încărcată | mediu | 2 | dialog cu avertisment + bifă | test pe `paymentNoticeDate` setat |
| R24 | Ciorna stochează CNP/adrese 30 de zile | mediu | 1 | coloane denormalizate fără PII, payload neafișat, expirare cu ștergere, audit | review + test de expirare |
| R25 | Bind global `$confidenceThreshold` și `$caseCreationLimiter` după numele parametrului | mediu | 5 | nume păstrate | `debug:container --show-arguments` |
| R26 | Metodele coordinatorului fac flash/redirect | mediu | 5 | rezultat tipizat cu `flashKey`; redirect-urile rămân în controller | testele de conflicte (textul flash-ului) |
| R27 | Out-param `&$creditorOutcome` prin closure | mediu | 5 | `WizardPersistResult` | `Step1To4Test` (flash `creditor_reused`) |
| R28 | Extracția unui singur document nu vede contractul și extrasele | mediu | 4 | scadența obligatorie, avertismente, text de ajutor | test pe prefill fără scadență |
| R29 | `FACTURA` refuzată rămâne pe dosar și consumă plafonul | scăzut | 4 | ștergere la refuzul părților | WebTestCase |
| R30 | Fișiere rămase pe disc dacă `remove` reușește și `unlink` nu; directoare uuid goale | scăzut | 1 | ordinea audit, remove+flush, unlink, rmdir; warning în log | test pe `var/uploads_test` |
| R31 | Două ciorne și o singură sesiune | scăzut | 1 | „ultimul Continuă câștigă”, plafon 5, ciorna activă marcată | WebTestCase |
| R32 | Comentarii cu pași de plan, română și em-dash propagate în 9 clase | scăzut | 5 | curățate la mutare | `grep -rnP "Pas [0-9]|\x{2014}" src/Service/Wizard src/Controller/Case/Wizard` = 0 |

---

## 5. Puncte de atenție juridice

Sursa: revizia juridică din workflow. Disclaimer: textul oficial CPC art. 1014-1018 nu a putut fi extras de pe legislatie.just.ro în sesiune; numerotarea art. 1016/1017 și NCC art. 1506-1509, 1488, 2537 se re-verifică pe sursa primară înainte de a fi scrisă în UI sau în audit (Faza 6). Decizia finală rămâne a avocatului.

### Avertismente de arătat la confirmare (cu severitate)

| Situație | Severitate | Temei | Unde |
|---|---|---|---|
| Instanța propusă diferă de cea stocată (prag 200.000 lei) | avertisment + acțiune „Acceptă” | CPC art. 94 pct. 1 lit. k, art. 95, art. 98 alin. 2 (competența pe principal, la data sesizării; instanța din dosar e „propusă” până la depunere) | card instanță, toast |
| Resolver `null` (`art99_divergent_competence`, `art99_cause_unattributable`, `county_unknown`, `locality_unmatched`) | avertisment, instanța curentă păstrată | CPC art. 99 alin. 1-2 (cauze diferite = cereri separate), art. 218 (cumul, de verificat) | card instanță, modal somație, cerere OP |
| Factură din alt contract decât cel al dosarului | bloc cu confirmare („aceeași cauză” sau „Dosar nou”); confirmare explicită că clauza penală a dosarului se aplică | CPC art. 99; dosarul are un singur set de clauze (`CaseAccessoryService.php:68-78`) | modal adăugare |
| Scadență în viitor | avertisment la adăugare; **eroare** la `trimite_somatie` | CPC art. 1014 (creanță exigibilă) | modal, somație |
| Scadență lipsă | câmp obligatoriu; nu se presupune tacit 30 de zile | Legea 72/2013 art. 4 (de verificat) doar dacă avocatul confirmă (întrebarea K) | modal |
| Factură prescrisă (> 3 ani de la scadență) sau la < 3 luni de prescriere | avertisment (`OP_ITEM_PRESCRIBED`), fără termen cu dată în trecut | NCC art. 2517, 2523-2524, 2512 (nu operează de drept), 2554 (prorogare); somația nu întrerupe prescripția (art. 2537, de verificat) | modal, card |
| Accesoriu calculat până la data de referință X (data creării), recalculat la somație; `accessoryCutoffDate` înaintea scadenței noi | informativ / avertisment | OG 13/2011 art. 3; Legea 72/2013 | rezumat Turbo Stream |
| Excludere a unei facturi plătite cu întârziere | avertisment: „accesoriile până la data plății nu se mai cer”; motiv obligatoriu | NCC art. 1506-1509 (imputația: cheltuieli, accesorii, capital; de verificat) | modal excludere |
| Factură în altă monedă fără curs (`needsManualFx`) sau curs mai vechi de 7 zile | eroare (poziția nu ar conta) | NCC art. 1488 (de verificat); regula BNR din `CurrencyConverter` | modal |
| Notă de credit fără factură legată sau principal total ≤ 0 | eroare; ofertă „Închide la plată integrală” (`inchide_plata_integrala`, disponibil din `AMIABIL`) | CPC art. 1014 alin. 1 (sumă certă) | modal |
| Emitent/destinatar (CUI) diferit de părțile dosarului | refuz, documentul șters | părțile sunt fixe | upload |
| Aceeași factură în alt dosar al aceluiași utilizator | avertisment (întrebarea M: avertisment sau interdicție) | risc de dublă pretenție | modal |
| Debitor în insolvență sau alte erori din `validate()` | ca azi, re-rulate după editare | | rezumat |
| Taxa de timbru nu se recalculează | informativ | OUG 80/2013 art. 6 alin. 2 (200 lei fix) | nimic de schimbat |

### Conținutul auditului `claim_edited`

Append-only, un rând per operațiune, în aceeași tranzacție cu schimbarea: actor (user + IP, automat), moment, tip acțiune (`add_manual`, `add_from_document`, `exclude`, `reinclude`, `edit_details`, `court_accepted`), statusul dosarului; per poziție before/after complet (număr, dată, scadență, sumă, monedă, curs + data cursului, `amountRon`, `paidAmount`, `kind`, `excludedByLawyer`, `confirmed`, debitor, `causeReference`, `dedupKey`, motivul excluderii); sursa (document id, nume, `contentHash`, origine AI/manual, per câmp auto vs manual, valorile extrase originale dacă au fost suprascrise, ca în `wizard_submit`); totaluri before/after (principal, accesoriu, data de referință, versiunea cursului BNR); instanța before/after și dacă propunerea a fost acceptată; termene create/șterse/nepotrivite cu id; codurile de admisibilitate afișate și cele confirmate explicit; zile până la prescriere pentru facturile adăugate. CNP mascat prin `PiiMasker::maskCnpInArray`. Fără ștergere fizică de poziții.

Pentru `case_deleted`: snapshot (creditor, debitori mascați, sume, număr și nume documente, `paymentNoticeDate`, dovada taxei), motiv, actor. Soft delete; documentele și auditul rămân (UI promite deja „înregistrări imutabile, stocate 5 ani”, `PLAN-PAS-4-0-OVERVIEW-DOSAR.md`; GDPR art. 5 alin. 1 lit. e și art. 17 alin. 3 lit. e permit păstrarea pentru apărarea unui drept, nu nelimitat: întrebarea J).

### Articole de citat în UI (chei de traducere, după re-verificare)

CPC art. 1014 (certă, lichidă, exigibilă), art. 1015 (somația, 15 zile de la comunicare: motivul blocării editării după somație), art. 1016 (cererea trebuie să corespundă somației; de verificat numerotarea), art. 94 pct. 1 lit. k / art. 95 / art. 98 alin. 2 / art. 99; NCC art. 2517, 2523-2524, 2512, 2554, 2537, 1506-1509, 1488; OG 13/2011 art. 3; Legea 72/2013; OUG 80/2013 art. 6 alin. 2; GDPR art. 5, 17, 30 și Legea 190/2018 (ciornă, ștergere). Comentariul din `AccessoryComputabilityChecker` („art. 1.015 together with art. 1.017”) se verifică odată cu numerotarea.

---

## 6. Întrebări deschise rămase

### Decise (nu se rediscută)

Editare doar în `AMIABIL`; părțile fixe; fără wizard la editare; ciorna automată, fără buton, nu e status de dosar; `CaseDraft` înaintea editării; documentele din Tab Documente rămân anexe (fluxul de adăugare în creanță e separat, din cardul Creanță); serializare `serialize()` cu `allowed_classes`; atribute noi de voter; refuzul excluderii ultimei poziții; termenele orfane șterse doar la potrivire exactă; data de referință a accesoriului neschimbată; instanța niciodată schimbată automat; `court = null` nu blochează somația; namespace-urile `App\Service\Wizard` și `App\Controller\Case\Wizard`; `countLinks` neschimbat; `paymentNoticeNumber` editabil în `AMIABIL`; admin fără bypass pe `CASE_CLAIM_EDIT`.

### Pentru user (produs), înainte de faza respectivă

| # | Întrebare | Faza | Propunere |
|---|---|---|---|
| U1 | `skip()` la pasul 0: documentele deja încărcate se șterg imediat sau doar se detașează (`draft_id NULL`) și le ia mătura la 30 de zile? | 1 | ștergere imediată (avocatul a ales să continue fără ele; azi devin oricum inaccesibile) |
| U2 | Plafon de ciorne per utilizator și comportament la depășire: refuz cu mesaj sau expirarea celei mai vechi? | 1 | 5, refuz cu trimitere la listă |
| U3 | Refolosirea extracției (1.5) intră în această livrare sau rămâne după? Regula „tip `ALT_DOCUMENT` sau egal” e acceptabilă sau doar tip identic? | 1.5 | după 1.1-1.4, regula propusă |
| U4 | Comanda de expirare: cron zilnic separat sau în același job cu `app:check-deadlines`? | 1, 9.1 | separat, zilnic, decis la deploy |
| U5 | Ștergerea cere un motiv (text liber în audit) sau doar confirmare? E reversibilă din EasyAdmin (`deletedAt = NULL`)? | 2 | motiv opțional; reversibilă doar de admin, nedocumentată ca funcție |
| U6 | O `FACTURA` încărcată pentru o poziție, rămasă fără poziție (extracție eșuată, abandon după `COMPLETED`): permitem ștergerea ei din Tab Documente când nicio poziție nu o referă? | 4 | da, regulă pe dosar, nu pe tip |
| U7 | Dosarele moștenite fără poziții care contează (doar poziție cu curs lipsă): editarea refuzată sau se oferă întâi rezolvarea cursului manual (inexistentă azi)? | 4 | refuz cu mesaj dedicat |
| U8 | Termenele `PRESCRIPTIE` nepotrivite (editate de avocat): doar în rezumatul Turbo Stream sau și etichetă în tab-ul Termene „nu mai acoperă o poziție”? | 3, 4 | doar în rezumat acum |
| U9 | Documentele generate în `AMIABIL` (somație generată, previzualizări): se marchează „depășite” la editare sau se regenerează automat? | 4 | marcate depășite + avertisment în modal; regenerarea rămâne manuală |
| U10 | Split-ul pe controllere (5.11) se face în această livrare sau după? Restul planului nu depinde de el. | 5 | în această livrare, după Faza 4 |
| U11 | Durata suitei complete în Docker: dacă depășește 10 minute, la commit-urile cu risc scăzut rulăm doar `tests/Controller/Case` + `Cascade` + `Service/Extraction` și suita completă la fiecare 2-3 commit-uri? | toate | da |
| U12 | `WizardBag` cu proprietăți publice (ca DTO-urile) sau getter/setter? | 0 | publice, ca mutarea să rămână mecanică |

### Pentru avocat (de adăugat în documentul lui, Faza 6)

| # | Întrebare |
|---|---|
| F | Factură din alt contract sau altă cauză, același debitor: în același dosar (o somație, o cerere) sau dosar separat? Dacă instanța s-ar schimba sau ar rezulta competențe diferite? |
| G | Factură plătită cu întârziere: ce faci cu dobânda și penalitățile până la plată (le ceri, renunți) și cum imputezi plata (întâi pe accesorii)? Vrei să înregistrezi plăți parțiale pe factură în loc să excluzi? |
| H | Facturi în altă monedă: în ce monedă ceri suma și la ce curs (scadență, somație, depunere)? |
| I | Note de credit / storno: reducere a facturii, poziție separată sau excludere? |
| J | Dosar șters: cât timp rămâne în evidența ta (istoric, documente)? Ai obligații de păstrare din Statutul profesiei sau din contractul cu clientul? |
| K | Factură fără scadență: 30 de zile de la emitere/primire (Legea 72/2013) sau contractul? |
| L | Dobânda înainte de somație: estimată până azi sau până la data creării dosarului (regula actuală), cu recalcul la somație? |
| M | Aceeași factură în două dosare ale tale: avertisment sau interdicție? |
| D' | După depunere, un document din aplicație (opis, cerere) rămâne consultabil și imutabil; completarea cererii (CPC art. 204) se face în afara aplicației. Confirmi? |
| A/B' | Rămân amânate: factura adăugată sau eliminată după somație (somație nouă pentru toată creanța? două somații, o cerere?). |

---

## 7. Ordinea commit-urilor propuse

Un commit per rând, mesaj pe un rând, în română, fără `Co-Authored-By`; confirmare înainte de fiecare.

**Faza 0**
1. `refactor(wizard): starea wizard-ului trece printr-un WizardStateStore cu implementare pe sesiune, fără schimbare de comportament`
2. `refactor(wizard): bag-ul wizard-ului devine obiectul tipizat WizardBag, stocat în sesiune ca același array`

**Faza 1**
3. `feat(ciorna): entitatea CaseDraft și legătura document.draft_id, cu migrare, fără cale de cod care să le folosească`
4. `test(wizard): scrierile și citirile directe din sesiune din testele wizard-ului trec printr-un trait comun`
5. `feat(ciorna): wizard-ul își salvează starea în CaseDraft, sesiunea ține doar id-ul ciornei, documentele se detașează la creare`
6. `feat(ciorna): Dosar nou arată ciornele existente, skip șterge documentele ciornei, plafon de ciorne per utilizator`
7. `feat(ciorna): banda Dosare neterminate pe dashboard și în lista de dosare, cu Continuă și Renunță`
8. `feat(ciorna): comanda de expirare a ciornelor după 30 de zile și de curățare a documentelor orfane`
9. (opțional) `feat(extractie): refolosirea extracției când același avocat a încărcat deja fișierul în alt dosar sau altă ciornă`

**Faza 2**
10. `fix(dosare): dosarele șterse nu mai pot fi deschise sau acționate și nu mai apar în biblioteca creditorilor`
11. `feat(dosare): ștergerea dosarului în amiabil, cu confirmare, audit și notificările marcate citite`

**Faza 3**
12. `feat(creanta): ClaimRecalculator reface totalurile, accesoriul, câmpurile valutare, termenele de prescripție și propunerea de instanță după o schimbare a pozițiilor`
13. `fix(creanta): totalurile și accesoriul se golesc explicit când nicio poziție nu mai contează`

**Faza 4**
14. `feat(securitate): atributul CASE_CLAIM_EDIT pentru editarea creanței, doar proprietar și doar în amiabil`
15. `refactor(wizard): maparea câmpurilor creanței pe dosar iese din controller în LegalCaseClaimFieldsMapper`
16. `feat(creanta): ClaimPositionEditor adaugă manual, exclude cu motiv, reinclude și modifică detaliile, cu deduplicare față de pozițiile salvate`
17. `feat(creanta): cardul Creanță din pagina dosarului permite adăugarea manuală, excluderea și reincluderea facturilor, cu răspuns Turbo Stream`
18. `feat(creanta): modalul Modifică detaliile creanței și propunerea de instanță cu acceptare explicită`
19. `feat(creanta): factura încărcată din cardul Creanță trece prin extracție și precompletează formularul, cu verificarea părților`
20. `fix(somatie): somația se refuză când o poziție nu este încă exigibilă`
21. `feat(creanta): traducerile, paleta de audit și stilurile pentru editarea creanței`

**Faza 5**
22. `refactor(wizard): legarea rândurilor postate la pasul 3 iese în ClaimRowsPostBinder, cu testul de parsare mutat pe serviciu`
23. `refactor(wizard): citirea documentelor wizard-ului iese în WizardDocumentReader`
24. `refactor(wizard): compararea câmpurilor de bibliotecă iese în LibraryFieldComparer`
25. `refactor(wizard): potrivirea și reutilizarea creditorului ies în WizardCreditorResolver`
26. `refactor(wizard): potrivirea debitorilor, ANAF și legăturile de bibliotecă ies în WizardDebtorResolver`
27. `refactor(wizard): cifrele din bara laterală și calculele de confirmare ies în WizardFigures`
28. `refactor(wizard): persistarea dosarului iese în CaseWizardPersister, care întoarce rezultatul în loc de parametru prin referință`
29. `refactor(wizard): coordonarea conflictelor iese în WizardConflictCoordinator, cu constanta câmpurilor ANAF mutată și template-ul actualizat`
30. `refactor(wizard): translatorul se injectează în constructor, fără ServiceSubscriber`
31. `refactor(wizard): controllerul abstract de pas cu helperii comuni`
32. `refactor(wizard): pasul 1 creditor într-un controller propriu`
33. `refactor(wizard): pasul 2 debitori într-un controller propriu`
34. `refactor(wizard): pasul 3 creanță într-un controller propriu`
35. `refactor(wizard): pasul 4 confirmare într-un controller propriu`
36. `refactor(wizard): pasul 0 documente într-un controller propriu, CaseWizardController dispare`
37. (opțional) `refactor(wizard): DocumentRemovalPlanner lucrează pe WizardBag`

**Faza 6**
38. `docs(lexrecovery): documentul pentru avocat despre modificarea dosarului, corectat juridic, cu capturi și întrebările noi`
39. `docs(lexrecovery): planul de editare a dosarului marcat livrat, cu deciziile și întrebările rămase`
