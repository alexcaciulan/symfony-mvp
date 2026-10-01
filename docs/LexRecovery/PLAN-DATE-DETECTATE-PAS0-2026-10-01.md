# Plan de implementare: feedback avocat pe „Date detectate” (Pasul 0)

> **Decizie 2026-10-01 (utilizator):** extracția de la Pasul 0 doar citește documentele și nu apelează ANAF. Sincronizarea cu ANAF rămâne la Pașii 1 și 2, prin butonul existent. Slice-urile S4-S6 și deciziile D5-D6 de mai jos NU se implementează. S1-S3 sunt implementate pe `feature/date-detectate-pas0`.


## 1. Rezumat

Avocatul a testat pe demo, iar demo-ul nu e în urmă. Worktree-ul `/Users/alexc/Downloads/myprojects/symfony-mvp-demo` și `lexrecovery` sunt amândouă la `25de621`, verificat acum. Nu a intrat nicio corecție după test, deci toate cele cinci puncte sunt încă valabile.

| Item | Status | Dovadă |
|---|---|---|
| F1. Număr și procent | încă valabil | `templates/case/_step0_sidecard_partial.html.twig:32-34` are numitorii scriși de mână (11/12/12). Sunt lungimile listelor din `PrefillFromExtractionService.php:66-82`. Listele includ CNP (deși părțile sunt doar PJ, `OFFERED_PERSON_TYPES`), `personType`, câmpurile de penalitate și `contractReference` (dublură a `contractNumber`). Așa că 100% nu se poate atinge: un creditor complet iese 10/11 = 91%. Eticheta aria este „Încredere” (`messages.ro.yaml:722`). Numărătorul include și rânduri ascunse de șablon (`:82`, `:88`). |
| F2. Nr. Reg. Comerțului | încă valabil (dar nu e eroare de identificare) | J2003011043402 = J + 2003 + 011043 + 40 + cifra finală 2, adică același număr ca J40/11043/2003, scris în formatul nou. Problema e în aplicație: `LookupController.php:97-116` nu întoarce `nrRegCom`, `CoherentAggregator::comparable()` (`:570-581`) compară șirul brut, iar regex-ul din `PdfParserExtractionStrategy.php:663` nu recunoaște forma compactă. |
| F3. Adresa debitorului (Sector 1 vs Sector 6) | încă valabil | Pasul 0 nu interoghează ANAF deloc. Constructorul din `PrefillFromExtractionService.php:85-89` nu are nicio sursă de registru. ANAF rulează doar la apăsarea manuală a butonului (`party-anaf-lookup_controller.js:75`). Sectorul greșit ajunge în `CompetentCourtResolver` (judecătorie greșită, fără nicio avertizare), în cererea OP, în somație și în biblioteca de debitori. |
| F4. Valoare enum brută, etichete trunchiate, „Temei juridic” | încă valabil | `_step0_sidecard_partial.html.twig:103-104` afișează `field_value.value` (deci CONTRACT_LOCATIUNE, PJ). Liniile `:90-92` pun `truncate` atât pe `dt`, cât și pe `dd`. Cheile de redenumit sunt ro:520, 538, 713, 818 și en:521, 539, 714, 819. |
| F5. Textul bannerului de conflicte | încă valabil | `_prefill_conflicts.html.twig:38` folosește `intro_readonly` (ro:774). Cheia e partajată între Pasul 0 (`sidecard:143`) și Pasul 4 (`_step4_confirmation_content.html.twig:48`), unde textul „Alegerea se face la pașii următori” este fals, pentru că nu mai urmează niciun pas. |

Corecții la premisele task-ului:
- DTO-urile sunt în `src/DTO/Wizard/`, nu în `src/DTO/Case/`.
- Branch-ul nemerge-uit `feature/taxa-timbru-sector-registratura` (`2a0bb5e`) modifică aceleași fișiere `messages.{ro,en}.yaml` și folosește sectorul creditorului pentru DITL. Trebuie merge-uit înainte de slice-urile de mai jos sau, cel puțin, recitit.

## 2. Decizii recomandate

**D1 (F1): păstrăm numărul și procentul, dar corecte și redenumite „Completitudine”.**
- Utilizatorul a cerut explicit „numărul și procentul”.
- Formatul afișat: `X/Y · N%`, unde Y = câmpurile necesare care se aplică acestui dosar.
- Eticheta „Încredere” dispare complet (cheia `confidence_label`).
- Sub rânduri apare lista explicită „De completat la pasul N: …”. Asta răspunde la întrebarea avocatului „ce mai vrea să afle”.
- Regula de bază: orice număr afișat trebuie să se poată număra în rândurile de dedesubt.

**D2 (F1): setul de câmpuri necesare este o decizie de produs, nu o copie a validării DTO.**
- Creditor și debitor (PJ): denumire, CUI, nr. Reg. Com., adresă, județ, localitate.
  - Județul și localitatea sunt incluse pentru instanță și DITL, deși în DTO au doar `Length`.
  - CNP și `personType` nu se afișează și nu se numără.
- Creanță: sumă, monedă, scadență, plus data facturii când moneda nu e RON (`Step3ClaimData.php:106-111`).
- Opționale (se afișează doar dacă au fost detectate, nu intră în Y și nu apar ca lipsă):
  - pentru părți: IBAN, bancă, reprezentant, administrator, email, telefon;
  - pentru creanță: izvorul creanței, nr./data facturii (cu excepția EUR), contractul (un singur rând, cu fallback pe `contractReference`), descrierea.
- Rândurile de penalitate apar doar când penalitatea e CONTRACTUAL și nu se numără niciodată.
- Cu mai multe facturi: rândul „Total N facturi” și cea mai veche scadență acoperă suma și scadența.

**D3 (F1): stări corecte pe tot parcursul.**
- Cât timp documentele se procesează, cardul arată „În procesare” fără procent, până la `all_terminal`.
- Un câmp aflat în conflict apare cu marcajul „de ales la pasul N” și nu se numără drept complet.
- Un conflict pe setul de debitori (DEBTOR_SET) arată „de ales debitorul”, nu un debitor marcat complet.
- Pe Pasul 0 scoatem procentul „încredere” afișat pe fiecare opțiune din banner (`_prefill_conflict_source.html.twig:10`).
- Procentul de pe cardul documentului (`_step0_dynamic_partial.html.twig:71`) se înlocuiește cu un avertisment doar sub prag („Verifică tipul documentului”).
- `CoverageConfidenceCalculator` rămâne neschimbat în cod. Se corectează doar docblock-ul, pentru că testul `ResponseSchemaCoverageContractTest.php:35,50-52,249` citește constantele.

**D4 (F2): nu este o diferență reală, deci nu semnalăm conflict.**
- Un value object `OnrcNumber` normalizează ambele forme la cheia `litera|județ|număr|an` (zerourile de umplere nu contează).
- Folosim cheia la comparații în trei locuri:
  - agregatorul;
  - diferențele de bibliotecă pentru creditor (`CaseWizardController.php:582-596`);
  - diferențele de bibliotecă pentru debitor (`:731`, `LIBRARY_FIELDS`).
- La Pasul 0 nu suprascriem automat numărul. Când ANAF confirmă aceeași înregistrare, afișăm „Confirmat la ANAF (J2003011043402)”.
- Ce formă se tipărește în acte rămâne cum e azi până răspunde avocatul (întrebarea Î1). Forma „registru (formă veche)” ar cere două coloane, iar valoarea ajunge și în e-Factura (`OblioEInvoicingProvider.php:163`).
- Cifra de control nu se calculează. Convertim doar din compact în clasic, niciodată invers.

**D5 (F3): registrul prevalează pentru identitatea și sediul PJ, cu ambele valori vizibile.**
Temeiul juridic, cu marcajul ⚖️ (de verificat la sursă):
- art. 194 lit. a CPC cere sediul;
- art. 107 CPC: competența aparține instanței de la sediul pârâtului, iar în București contează sectorul;
- sediul social înscris în registru este opozabil terților.

Reguli de implementare:
- Sursa este blocul `adresa_sediu_social` din ANAF, nu domiciliul fiscal.
- Suprascrierea se face doar dacă denumirea din ANAF se potrivește cu cea extrasă (`CoherentAggregator::normalizedName`). Un CUI greșit la OCR poate trece de verificarea cifrei de control și ar aduce altă firmă.
- Adresa, județul și localitatea se suprascriu împreună. Altfel actele ar tipări „Str. Azurului 25, Sector 1, Sector 6” (`RomanianAddressFormatter.php:114-156`).
- Un debitor din bibliotecă nu se suprascrie (modul `statusOnly`).
- Conflictele document-document rămân alegere la Pasul 2. Valoarea din registru devine opțiunea sugerată (`suggestedIndex`), nu decizia.
- Divergența document-registru este informativă: prevalează ANAF, cu anulare. Asta continuă decizia deja luată pentru sincronizarea manuală.
- Același mecanism se aplică și creditorului (sectorul creditorului dă DITL-ul). Creditorul nu are `anafStatus`, așa că primește doar adresa și `anafCheckedAt`.
- Clauza de competență și adresa de corespondență din contract nu se suprimă (vezi Î3).

**D6 (F3): ANAF nu se apelează în calea de randare.**
- Serviciul este sincron (`timeout 10`) și fără cache, iar cardul se reîmprospătează la 3 s.
- Instantaneul se obține o singură dată, printr-un mesaj Messenger, când agregarea vede un CUI valid fără instantaneu. Se păstrează într-un cache global per CUI (24 h; datele sunt publice). Randarea doar citește din cache.
- Limitator separat față de `company_lookup` (10/h), ca să nu consume bugetul utilizatorului.
- Dacă ANAF nu răspunde, se afișează datele din documente cu o notă neutră și nimic nu se blochează.
- Sursa de registru intră în `PrefillFromExtractionService::aggregate()`, ca provider injectat opțional, nu la locurile de apel din controller. Altfel cele 7 apeluri (`:262, :301, :1325, :2326, :2342, :2425`) ar diverge, iar `applyChosenValues` ar readuce „Sector 1”.

**D7 (F4): eticheta devine „Izvor creanță”, exact formularea avocatului, peste tot unde înseamnă tipul actului din care provine creanța.**
- Locuri: cardul lateral, formularul Pasului 3, confirmarea de la Pasul 4 și mesajul de conflict.
- Nu se schimbă `pdf.payment_order.legal_basis_title` și frazele „în temeiul art.”. Acolo „temei juridic” înseamnă motivarea în drept.
- Identificatorii din cod (`legalGround`, `LegalGroundCategory`) rămân la fel.
- Afișarea folosește `label()`, nu `.value`.
- Pe card, eticheta stă deasupra valorii, valoarea se rupe pe mai multe rânduri și nicio etichetă nu se mai trunchiază.

**D8 (F5): pe Pasul 0 punem fraza avocatului, pe un singur rând.**
- Textul: „Documentele nu spun același lucru, informațiile vor fi validate în pașii următori.”
- Lista detaliată pe câmp rămâne, pentru că arată ce diferă (contează pentru F2 și F3).
- Textul de la Pasul 4 se corectează obligatoriu, pentru că acum indică pași care nu mai urmează.

## 3. Slice-uri de implementare (în ordine)

Înainte de fiecare slice: recitește fișierele, pentru că alte sesiuni pot lucra în paralel. Precondiție: merge pentru `feature/taxa-timbru-sector-registratura` sau rebase peste el. După fiecare slice: `cache:clear` + `kill -USR2 1` în containerul php, apoi `make tailwind` dacă apar clase noi.

### S1. Texte: F5 + redenumirea din F4 (risc mic)

**Fișiere:**
- `translations/messages.{ro,en}.yaml`
- `templates/case/_prefill_conflicts.html.twig`
- `templates/case/_step0_sidecard_partial.html.twig`

**Comportament:**
- `_prefill_conflicts` primește doi parametri opționali, `conflicts_title_key` și `conflicts_intro_key`. Când intro-ul e `null`, paragraful nu se mai randează.
- Cardul lateral (Pasul 0) trimite `conflicts_title_key: 'wizard.conflict.title_step0'` și `conflicts_intro_key: null`.
- Pasul 4 păstrează `intro_readonly`, dar cu text reformulat.

**Chei de traducere:**

| Cheie | RO | EN |
|---|---|---|
| `wizard.conflict.title_step0` (nouă) | 'Documentele nu spun același lucru, informațiile vor fi validate în pașii următori.' | 'The documents do not agree; the information will be validated in the following steps.' |
| `wizard.conflict.intro_readonly` | 'Valorile de mai jos diferă între înscrisurile încărcate. Alegerea a fost făcută la pasul care deține câmpul.' | 'The values below differ between the uploaded documents. The choice was made on the step that owns the field.' |
| `wizard.step3.field.legal_ground` | 'Izvor creanță' | 'Source of claim' |
| `wizard.step3.placeholder.legal_ground` | '-- Selectează izvorul creanței --' | '-- Select source of claim --' |
| `wizard.step0.detected_data.field.legalGround` | 'Izvor creanță' | 'Source of claim' |
| `wizard.conflict.*.legalGround` | 'Documentele indică izvoare diferite ale creanței.' | 'The documents give different sources for the claim.' |

- Se șterg `wizard.step4.legal_basis` și `legal_basis_hint` (ro:608-609, en:609-610), după un grep de confirmare că nu le mai folosește nimic.
- Verificări: `lint:yaml translations/` și `tests/I18n/EnumLabelKeysExistTest.php`.

**Teste:** în `CaseWizardConflictsTest`, două documente divergente:
- cardul lateral de la Pasul 0 conține fraza avocatului și nu conține „Alegerea se face”;
- intrările `prefill-conflict` se randează în continuare;
- Pasul 4 nu conține fraza de la Pasul 0;
- aserția de titlu de la `:75` rămâne verde.

**Live:** Playwright pe dev, Pasul 0 cu factură și contract divergente, apoi Pasul 3 (eticheta) și Pasul 4.

### S2. F1 + afișarea din F4: model de previzualizare pe server

**Fișiere noi:**
- `src/Service/Extraction/DetectedDataPreviewBuilder.php` (final, pur)
- `src/DTO/Extraction/DetectedDataPreview.php`
- `src/DTO/Extraction/DetectedDataSection.php`
- `src/DTO/Extraction/DetectedDataRow.php`

**Fișiere modificate:**
- `CaseWizardController.php` (cele două locuri de randare, `:262` și `:301`; `$viewVars` servește și ramura cardului lateral)
- `_step0_sidecard_partial.html.twig`
- `_prefill_conflict_source.html.twig` (scoate procentul pe Pasul 0)
- `_step0_dynamic_partial.html.twig:71`
- docblock-ul din `CoverageConfidenceCalculator.php`

**Comportament:**
- **Intrare:** `WizardPrefillResult` + `claim_positions_preview` + starea `all_terminal`.
- **Ieșire:** trei secțiuni. Fiecare are `rows` (cheie de etichetă, valoare formatată, id document), `missingRequired`, `inConflict`, `filled` și `applicable`.
- **Formatare:**
  - enum: cheia lui `label()`;
  - dată: `d.m.Y`;
  - sumă: `number_format(2, ',', '.')` + monedă;
  - CNP și IBAN: mascate;
  - fără ramura `json_encode`.
- **Rânduri:** se construiesc doar din valori ne-nule. Proveniența se mapează `county`/`locality` → `addressCounty`/`addressLocality`.
- **Șablon:**
  - antet `X/Y · N%` cu titlul „Completitudine” și o bară fără „Încredere”;
  - stare „În procesare” până la `all_terminal`;
  - etichetă deasupra valorii, cu `break-words` și `line-clamp-3` + `title` pe textele lungi;
  - linia de chihlimbar „De completat la pasul N: …”;
  - rândurile în conflict marcate „de ales la pasul N”.
- Fără afirmații despre ANAF în acest slice. Proveniența se afișează discret: „Factura 1”, „Contract 1”.

**Chei** (sub `wizard.step0.detected_data`, RO / EN):

| Cheie | RO | EN |
|---|---|---|
| `completeness_label` | 'Completitudine' | 'Completeness' |
| `processing` | 'În procesare' | 'Processing' |
| `missing_hint` | 'De completat la pasul %step%: %fields%' | 'To fill in at step %step%: %fields%' |
| `in_conflict` | 'De ales la pasul %step%' | 'To choose at step %step%' |
| `choose_debtor` | 'De ales debitorul la pasul 2' | 'Choose the debtor at step 2' |
| `source_document` | '%type% %n%' | '%type% %n%' |
| `multi_invoice_total` | dacă nu există deja | dacă nu există deja |
| `wizard.step0.document.verify_type` | 'Verifică tipul documentului' | 'Check the document type' |

Se șterge `confidence_label`.

**Teste:**
- **Unit** (`DetectedDataPreviewBuilderTest`, TestCase):
  - un creditor PJ complet dă 6/6 și 100%, fără rânduri CNP sau `personType`;
  - când lipsește județul, acesta apare în `missingRequired`;
  - dobânda legală nu produce rânduri de penalitate și nu reduce procentul;
  - `contractReference` fără `contractNumber` dă un singur rând;
  - cu 2 facturi sau mai multe, câmpurile per factură nu se numără;
  - EUR fără dată de factură apare ca lipsă;
  - un câmp în conflict nu se numără complet;
  - o valoare null din `autoFilled` nu produce niciun rând;
  - un enum se afișează prin eticheta lui;
  - suma se formatează;
  - cât timp procesarea nu e gata, procentul nu se calculează.
- **Integrare** (`CaseWizardControllerStep0Test`, cu fixture-ul existent `FACTURA_ACCEPTATA`):
  - fluxul conține „Factură acceptată” și „Izvor creanță”;
  - nu conține `FACTURA_ACCEPTATA`, „Încredere” sau `>PJ<`;
  - fracția afișată este egală cu numărul de rânduri necesare prezente.
- **Live:** Playwright pe dev cu o pereche reală factură + contract, la 1024 px și la xl, în modurile luminos și întunecat. Verifici că nicio etichetă nu e trunchiată și că documentul avocatului ajunge la 100% când datele sunt complete.

### S3. F2: `OnrcNumber` și comparații normalizate

**Fișiere:**
- `src/Util/OnrcNumber.php` (nou)
- `CoherentAggregator.php:570-581`
- `CaseWizardController.php:582-596` și `:731`
- `CreditorLibraryService.php:25` și `DebtorLibraryService.php:27`: diff-ul de audit ignoră schimbările doar de format
- `PdfParserExtractionStrategy.php:655-693`: regex `[JFC]` + forma compactă `[JFC]\d{13}`, docblock actualizat
- prompturi: `SharedPromptFragments.php:150, 170, 348` și `OcrTextExtractionStrategy.php:331, 345, 369`, cu instrucțiunea „returnează numărul exact cum e scris; forma compactă J2003011043402 e validă”. Subșirul `J40/1234/2025` se păstrează, pentru că îl verifică `ResponseSchemaCoverageContractTest.php:209`.

**Comportament:**
- `parse()` acceptă forma clasică (cu zerouri și spații) și forma compactă. `key()` și `classic()` dau cheia canonică și forma clasică. `sameRegistration()` compară două numere.
- O valoare neparsabilă cade pe comparația strictă cu majuscule.
- Valoarea stocată și actele nu se schimbă.

**Chei:** niciuna în acest slice.

**Teste:**
- **Unit** (`OnrcNumberTest`):
  - J2003011043402 ≡ J40/11043/2003 ≡ J40/011043/2003;
  - prefixele F și C;
  - alt județ, alt număr sau alt an înseamnă înregistrări diferite;
  - un șir fără sens dă null.
- **Integrare:**
  - test de agregator: forma clasică vs compactă nu produce conflict, un număr real diferit produce conflict;
  - test de extracție pdf_parser pe forma compactă;
  - WebTestCase la Pasul 1: un creditor din bibliotecă salvat în forma compactă vs valoarea clasică din pas nu deschide dialogul de diferențe.
- **Live:** încarcă factura de demo cu J40/11043/2003 și creditorul din bibliotecă, apoi verifici că nu apare niciun conflict și niciun dialog.

### S4. F2/F3: serviciul de instantaneu ANAF și plasa de teste

**Fișiere:**
- `src/Service/Company/AnafPartySnapshotService.php` (nou): corpul mutat din `LookupController.php:93-118`
- `src/DTO/Company/AnafPartySnapshot.php` (nou): include acum și `onrcNumber` și `onrcClassic`
- `LookupController.php`: rămâne un apelant subțire. JSON-ul existent se păstrează, se adaugă doar `onrcNumber` și `onrcClassic`.
- `config/packages/test/services.yaml`: alias pentru un client ANAF offline (stub), ca `OfflineLlmClient`
- `config/packages/rate_limiter.yaml`: limitator nou `registry_snapshot`
- un pool de cache `registry.snapshot` (24 h)

**Comportament:**
- `snapshot(cui)` citește din cache. Pe miss întoarce null.
- `fetch(cui)` (doar pentru worker) apelează ANAF, scrie în cache și consumă limitatorul separat.

**Chei:** niciuna în acest slice.

**Teste:**
- **Unit:** maparea instantaneului din fixture-uri ANAF reale. CUI 14399840: sediu social în Sector 6, domiciliu fiscal în Sector 2. Plus o fixture cu `nrRegCom` compact.
- **Integrare:**
  - `LookupControllerTest` rămâne verde fără modificări și primește aserțiile noi pentru `onrcNumber` și `onrcClassic`;
  - un test că suita Step0/1/2 nu face HTTP real (stub-ul aruncă excepție la orice apel neașteptat).
- **Live:** butonul „Sincronizează ANAF” la Pasul 2 se comportă identic.

### S5. F3 (+ afișarea din F2): îmbogățire din registru în prefill

**Fișiere:**
- `src/Service/Extraction/RegistryEnrichment.php` (nou, pur, primește un provider de instantaneu)
- `PrefillFromExtractionService.php`: provider opțional injectat, aplicat în `aggregate()`, deci pe toate cele 7 apeluri
- `WizardPrefillResult.php`: `registryFilled`, `registryDivergences`, `registryPending`
- un mesaj Messenger și handler-ul lui, `RefreshRegistrySnapshot(cui)`, care după fetch publică actualizarea existentă a cardului de la Pasul 0
- `DetectedDataPreviewBuilder`: sursa ANAF pe rând
- `_step0_sidecard_partial.html.twig`
- `Step2DebtorsLiveComponent.html.twig` + `party-anaf-lookup_controller.js`:
  - pornește în starea „sincronizat”;
  - valorile din document stau în atribute `data-*`, ca anularea să supraviețuiască re-randărilor;
  - ținta nouă `onrcNumber` se folosește doar pentru comparație și toast, fără suprascriere.
- Step 1 pentru creditor: același mecanism.

**Comportament** (pentru fiecare parte PJ cu CUI valid și instantaneu disponibil):
1. **Poarta de identitate.** Denumirea normalizată trebuie să corespundă cu cea din ANAF. Dacă nu corespunde, nu se suprascrie nimic: se înregistrează divergența „CUI-ul indică altă firmă”.
2. **Suprascriere.** Se suprascriu împreună denumirea, adresa, județul și localitatea. Se setează `anafStatus` și `anafCheckedAt` la debitor, respectiv doar `anafCheckedAt` la creditor.
3. **Divergențe.** Se compară prin `RomanianAddressNormalizer`. „Sector 1” vs „Sector 6” este divergență. „Bucuresti” vs „Municipiul București” nu este.
4. **Excepții.** Debitorul din bibliotecă nu se atinge. Conflictele document-document rămân, cu opțiunea din registru marcată ca sugerată.
5. **`onrcNumber`.** Nu se suprascrie niciodată. Dacă e aceeași înregistrare, se afișează „Confirmat la ANAF”. Dacă e alta, apare o divergență.
6. **Statut ANAF.** RADIAT sau INACTIV se afișează pe card, pentru că schimbă admisibilitatea la Pasul 4.

**Chei** (RO / EN):

| Cheie | RO | EN |
|---|---|---|
| `wizard.registry.source_anaf` | 'ANAF' | 'ANAF' |
| `wizard.registry.confirmed` | 'Confirmat la ANAF' | 'Confirmed with ANAF' |
| `wizard.registry.pending` | 'Verificare ANAF în curs' | 'ANAF check in progress' |
| `wizard.registry.unavailable` | 'ANAF nu a putut fi consultat. Datele provin din documente.' | 'ANAF could not be reached. The data comes from the documents.' |
| `wizard.registry.divergence_title` | 'Datele din documente diferă de ANAF' | 'Document data differs from ANAF' |
| `wizard.registry.divergence_row` | '%field%: documentul indică „%document%”, ANAF (sediu social) indică „%registry%”. Am folosit datele ANAF.' | '%field%: the document says "%document%", ANAF (registered office) says "%registry%". ANAF data was used.' |
| `wizard.registry.identity_mismatch` | 'La CUI-ul %cui% ANAF indică altă firmă (%name%). Verifică CUI-ul.' | 'For CUI %cui% ANAF shows a different company (%name%). Check the CUI.' |
| `wizard.registry.onrc_same_registration` | 'Același număr în formatul nou ONRC: %value%' | 'Same number in the new ONRC format: %value%' |
| `wizard.registry.onrc_differs` | 'La ANAF figurează alt număr de înregistrare: %value%' | 'ANAF records a different registration number: %value%' |
| `wizard.registry.status` | 'Stare ANAF: %status%' | 'ANAF status: %status%' |

**Teste:**
- **Unit** (`RegistryEnrichmentTest`):
  - factura spune Sector 1, ANAF spune Sector 6: rezultatul e Sector 6, cu divergență și `anafStatus` setat;
  - grafii echivalente nu produc divergență;
  - o denumire diferită nu suprascrie nimic și produce `identity_mismatch`;
  - instantaneu null: datele rămân neschimbate, starea e pending sau unavailable;
  - debitorul din bibliotecă nu se atinge;
  - `onrcNumber` nu se suprascrie;
  - adresa și localitatea se suprascriu împreună.
- **Integrare:**
  - WebTestCase pe Pasul 0 cu instantaneu în cache: cardul arată ANAF și divergența;
  - `applyChosenValues` după un conflict nu readuce Sector 1;
  - Pasul 2 primește Sector 6;
  - handler-ul Messenger cu `MockHttpClient` face un singur fetch per CUI.
- **Live:** Playwright pe dev cu factura demo (Str. Azurului, Sector 1, CUI-ul real al debitorului):
  - cardul arată Sector 6 + divergența;
  - Pasul 2 e precompletat cu Sector 6, iar anularea funcționează;
  - Pasul 4 indică Judecătoria Sectorului 6.

### S6. F3: plasa de siguranță la instanță (Pasul 4)

**Fișiere:**
- consumatorii lui `CompetentCourtResolver` de la Pasul 4
- `_step4_confirmation_content.html.twig`
- `OpAdmissibilityValidator.php` (dacă se întărește avertismentul pentru București)

**Comportament:** când debitorul PJ nu are adresa confirmată de ANAF sau are o divergență pe județ ori localitate, instanța rămâne cea calculată, dar sub ea apare explicația.

**Cheie:**
- `court.resolver.address_unverified`: 'Instanța a fost stabilită după o adresă neconfirmată de ANAF. Verifică sectorul sau localitatea debitorului.' / 'The court was resolved from an address ANAF has not confirmed. Check the debtor's sector or locality.'

**Teste:**
- **Integrare:** cu adresă neconfirmată apare explicația; cu adresă confirmată nu apare.
- **Live:** pe Pasul 4, cu un debitor din București nesincronizat.

## 4. Întrebări deschise pentru avocat

1. **Forma numărului în acte (F2).** Ce scriem în cererea OP, în somație și în opis?
   - forma din registru (J2003011043402);
   - forma clasică (J40/11043/2003);
   - ambele, „J2003011043402 (J40/11043/2003)”. Asta cere stocarea ambelor forme, iar valoarea ajunge și pe factura fiscală (e-Factura).
2. **Ce e obligatoriu pentru creanță.** Numărul și data facturii trebuie să fie obligatorii pentru „complet”, având în vedere că înscrisul este proba creanței în procedura OP? Recomandarea noastră: nu, cu excepția datei facturii la valută.
3. **Adresa din contract (F3).** Când contractul are o adresă de corespondență sau de notificare diferită de sediul social, somația se trimite la ambele adrese sau doar la sediul din registru? Clauza de competență teritorială (art. 126 CPC ⚖️) trebuie oferită ca alternativă explicită la alegerea instanței?
4. **Când datele ANAF sunt depășite.** La o mutare recentă de sediu, ANAF poate rămâne în urma ONRC. E suficient un avertisment, sau vreți posibilitatea de a atașa certificatul constatator sau extrasul ONRC ca probă a sediului?
5. **Domiciliul fiscal.** Când domiciliul fiscal diferă de sediul social, ce adresă trece pe somație? Propunerea: sediul social.

## 5. Riscuri

- **Editări concurente.** `CaseWizardController.php`, `PrefillFromExtractionService.php`, cardul lateral și `messages.{ro,en}.yaml` sunt fișiere fierbinți. S1-S5 le modifică pe toate, iar branch-ul taxa-timbru le modifică și el. Facem merge-ul întâi, apoi slice-urile secvențial, cu recitire.
- **Setul de câmpuri necesare (S2)** devine o a treia sursă de adevăr, lângă DTO și formular. Riscul se reduce prin teste unitare și prin derivarea regulii „doar PJ” din `OFFERED_PERSON_TYPES`.
- **`CoverageConfidenceCalculator`** alimentează pragul cascadei și e fixat prin test. Schimbarea constantelor ar schimba strategia care câștigă, deci în acest plan se modifică doar docblock-ul.
- **O eroare de parsare în `OnrcNumber`** ar putea uni două înregistrări diferite. Cheia include obligatoriu județul, numărul și anul, iar o valoare neparsabilă cade pe comparația strictă.
- **ANAF ca dependență în fluxul de încărcare.** Apelul nu se face în randare (worker + cache). Rămân totuși riscurile de stare „pending” prelungită și de date ANAF depășite. Le acoperim cu nota neutră, cu anularea și cu afișarea ambelor valori.
- **Un CUI greșit la OCR care trece verificarea cifrei de control** ar aduce altă firmă. Poarta pe denumire este obligatorie înainte de orice suprascriere.
- **Suprascrierea parțială a adresei** ar tipări adrese contradictorii în acte. Adresa, județul și localitatea se suprascriu doar împreună.
- **`anafStatus` setat automat** schimbă admisibilitatea la Pasul 4 fără ca avocatul să apese butonul. Starea trebuie afișată pe card.
- **Anularea din JS** pe Live Component poate restaura valori greșite (regresia corectată în `a9c1d73`). Valorile anterioare vin din server, în atribute `data-*`.
- **Teste care ar ajunge la ANAF real** după S5. Stub-ul offline din S4 este precondiție.
- **Biblioteca de debitori** poate conține deja sectoare greșite, inclusiv la debitori înghețați prin `hasSummonedCase`. Acest plan nu le corectează retroactiv.
- **Livrarea pe demo:** după `demo-sync` trebuie rulat `demo.sh restart php worker` (OPcache).