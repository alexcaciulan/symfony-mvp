# Plan: modificările cerute de avocat în Pasul 2 și biblioteca de debitori (model normalizat)

Acest plan înlocuiește versiunea anterioară a `docs/LexRecovery/PLAN-BIBLIOTECA-DEBITORI.md`. Partea I (C, A, B) rămâne, cu corecțiile din review integrate. Partea II, construită pe o entitate `SavedDebtor` separată, e **respinsă** și înlocuită cu modelul normalizat decis de utilizator: tabelul `debtor` devine societatea (un rând per avocat, ca `creditor`), iar legătura cu dosarul și starea de verificare trec într-un tabel de legătură nou, `legal_case_debtor`.

Planul pornește de la working tree-ul curent al branch-ului `feature/biblioteca-debitori`, nu de la HEAD. Aici checkbox-ul cosmetic `_save_to_library_cosmetic.html.twig` e deja șters, dar nu e comis.

## 1. Rezumat și decizii

### 1.1 Ce cere avocatul

| Cod | Cerere | Efect în produs |
|---|---|---|
| C | „bpi.just.ro nu există” | Blocul „Verificare BPI obligatorie (Legea 85/2014)” păstrează o singură frază. Toate mențiunile bpi.just.ro dispar din UI, din cod și din prompturi. |
| A | „De eliminat buton persoană fizică” | Debitorul e mereu persoană juridică, la fel ca creditorul din `e6c4a8e`. `PersonType::PF` rămâne în enum, în entitate, în PDF-uri și în admin. |
| B | „Debitori secundari post lansare” | Un dosar are exact un debitor. Colecția rămâne în cod, dar e plafonată la 1. |

### 1.2 Ordinea de livrare

Fiecare slice se livrează separat, cu testele verzi. Pentru fiecare: `make test`, `make restart`, verificare live cu Playwright, `/review-step`, apoi commit, dar numai după confirmarea explicită a utilizatorului (mesaj pe un singur rând).

1. **C**: textul BPI.
2. **A**: debitor doar PJ, împreună cu **G**, garda de identitate (golirea atestării la schimbarea CUI-ului), cerută de review să nu aștepte biblioteca.
3. **B**: un singur debitor și alegerea explicită a clusterului.
4. **R**: refactorizarea modelului (`debtor` devine societate, plus `legal_case_debtor`, plus migrarea M1). E un slice fără schimbare de comportament: actele generate, overview-ul, termenele și validarea arată la fel.
5. **L1**: pagina `/debtors`.
6. **L2**: selectorul din Pasul 2.
7. **L3**: salvarea automată la trimitere (căutare după CUI, completarea câmpurilor goale, migrarea de deduplicare M2 și UNIQUE).

### 1.3 Decizii luate

1. **PF se ascunde, nu se șterge.** Rămân enum-ul, coloanele PF din `PartyContactInfoTrait`, ramura de domiciliu din somație (`templates/pdf/summons/_parties.html.twig:9-15`), alegerile din admin pentru entitățile deja PF, prompturile de extracție și regulile de identitate din `CoherentAggregator`. Dosarele existente cu debitor PF se randează și se administrează ca azi.
2. **Colecția rămâne, plafonul devine 1** (`Step2DebtorsData::MAX_DEBTORS = 1`, `src/DTO/Wizard/Step2DebtorsData.php:24`).
3. **Nicio parte din documente nu dispare fără știrea avocatului.** Când documentele descriu mai mulți debitori, avocatul alege explicit unul. Nu se mai face `array_slice` după o bifă.
4. **Model normalizat.** Societatea (`debtor`) e partajată între dosarele aceluiași avocat, la fel ca `creditor`. Starea de verificare (ANAF, insolvență, atestare, dovadă BPI) stă doar pe rândul `legal_case_debtor`, deci aparține unui singur dosar și nu se transferă niciodată la altul.
5. **Cheia bibliotecii este CUI-ul canonic** (`cui_key`), calculat de o singură funcție PHP (`CuiNormalizer::canonical`), cu un echivalent SQL identic în migrare. Regulile sunt: fără spații, majuscule, fără prefixul `RO` și fără zerouri la început (a patra regulă e o rafinare, justificată în §4.4).
6. **Selectorul din Pasul 2 stă în afara Live Component-ului.** E un formular mic cu UX Autocomplete care trimite POST la o rută a wizard-ului. Serverul scrie în sesiune identitatea societății, cu verificările goale.
7. **Salvarea în bibliotecă se face în tranzacția dosarului**, nu după commit: rândul `legal_case_debtor` are nevoie de id-ul societății. Cursa pe UNIQUE (două taburi) anulează tranzacția, iar avocatul primește un mesaj și retrimite. Bag-ul rămâne în sesiune.
8. **O societate folosită într-un dosar nu se poate șterge** (FK `RESTRICT` pe `legal_case_debtor.debtor_id` plus o verificare explicită, cu mesaj tradus). O societate nefolosită se șterge definitiv.
9. **Textul de la editare spune adevărul, la creditor și la debitor:** „Modificările se folosesc la actele generate de acum înainte, inclusiv la regenerarea actelor din dosarele existente în care apare societatea. Actele deja generate nu se schimbă.” Subtitlul actual de la creditor („se aplică la dosarele viitoare”) e fals și se corectează într-un commit separat, în L1.
10. **Actele deja generate nu se schimbă** la editarea societății. Somația, cererea OP și opisul se generează la acțiunea explicită a avocatului și se stochează ca fișiere (`CaseSummonsController.php:92`, `CasePaymentOrderController.php:145-146`), iar instanța stă pe `legal_case.court_id`. O regenerare ulterioară folosește datele curente ale societății, exact ca la creditor azi. Acesta e compromisul acceptat (§3).
11. **Datele existente devin automat bibliotecă** prin migrare. Decizia D9 din planul vechi (backfill opțional) dispare.

### 1.4 Decizii deschise (cu recomandare)

| # | Întrebare | Cui | Recomandare |
|---|---|---|---|
| D1 | Debitorul PFA/II/IF trece acum pe calea PJ (CUI, ONRC, atestare Legea 85/2014) și nu mai primește mențiunea despre Legea 151/2015, emisă azi doar pe ramura PF (`OpAdmissibilityValidator.php:204-213`). Sub ce regim îl verificăm? | avocat | Blocant pentru A. Până la răspuns, calea PJ, dar mesajul de admisibilitate nu se aliniază definitiv. |
| D2 | Ce facem cu o sesiune veche care are un debitor PF în bag? | utilizator | O singură gardă, apelată din toate punctele de intrare (§2.2 A.5): redirect la Pasul 2 cu flash. Nicio conversie tacită. |
| D3 | Extracția a citit debitorul ca PF. | utilizator | Prefill ca PJ plus nota INFO „Documentele par să descrie o persoană fizică. Momentan se acceptă doar debitori persoane juridice.” |
| D4 | Documentele numesc doi debitori, dar doar unul apare pe documentele cu creanțe (de exemplu un fideiusor). | avocat | Alegere explicită, printr-un conflict blocant cu opțiuni. |
| D5 | Documentele cu creanțe numesc debitori diferiți. | avocat | Blocare fără bifă, cu mesaj neutru (§2.3). |
| D6 | Formularea atestării: „procedurile prevăzute de Legea 85/2014” (include concordatul preventiv și mandatul ad-hoc) sau doar „insolvență”? Procedurile preventive blochează depunerea? | avocat | Fraza din caseta informativă e a avocatului și se preia ca atare. Eticheta căsuței și mesajele de blocare (`OP_BLOCKED_INSOLVENCY`, `messages.ro.yaml:2467`) așteaptă răspunsul. |
| D7 | La trimitere, societatea există deja după CUI, iar datele din Pasul 2 diferă de cele din bibliotecă. Decizia utilizatorului: se completează **doar câmpurile goale**. | utilizator | Decizia rămâne, dar are un risc concret. Creditorul a avut exact acest bug: wizard-ul arăta datele noi, dosarul păstra adresa veche, iar somația pleca la sediul vechi (docblock `CaseWizardController.php:1330-1340`; `refreshCreditorFromDto` a fost introdus ca reparație). De aceea, la Pasul 4, diferențele se afișează ca un conflict blocant cu două opțiuni explicite: „Folosește datele din bibliotecă” (implicit, conform deciziei) sau „Actualizează societatea cu datele din acest pas” (auditat și afectează regenerările din celelalte dosare). Nicio suprascriere tacită și nicio pierdere tacită. |
| D8 | Societatea aleasă din bibliotecă are alt CUI decât debitorul din documente. | avocat | Conflict ERROR cu bifă explicită, auditată. |
| D10 | Adminul restricționează societățile noi la PJ? | utilizator | Da, ca în `CreditorType`. PF rămâne alegere doar pentru o entitate deja PF. |
| D11 | Reparăm în același pas conflictele pe câmpuri ascunse de la creditor? | utilizator | Da, e aceeași modificare de 5 rânduri în `PrefillFromExtractionService`. |
| D12 | Dovada BPI devine upload opțional? | avocat | Nu acum. Atestarea e o declarație a avocatului. `bpi_proof_document_id` se mută pe `legal_case_debtor` fără logică nouă. |
| D13 | Regula de supraviețuire la deduplicarea din M2: care rând rămâne când doi debitori ai aceluiași avocat au același CUI canonic? | utilizator | PJ înaintea PF, apoi `debtor.updated_at DESC`, `legal_case.created_at DESC`, `id DESC`. Grupurile cu conflicte se loghează și se păstrează în `debtor_merge_map`. În dev sunt 6 grupuri (24 de rânduri): 3 diferă pe denumire sau adresă, iar unul amestecă PF și PJ. |
| D14 | Rândurile moștenite fără CUI (PF din seed, 4 în dev și 4 în lexdemo) primesc `cui_key` NULL și nu se deduplică. | utilizator | Rămân societăți separate, câte una pe dosar. Nu apar în selector și nici în `/debtors` (filtru: PJ și `cui_key` nenul). Se văd doar în admin. |
| D15 | Debitorii dosarelor soft-deleted (0 azi) devin societăți la migrare? | utilizator | Da, pentru că dosarul păstrează FK-ul. Sunt însă excluși din contorul „dosare în care apare”. |
| D16 | Ținta pentru `ClaimItem.debtor`. | utilizator | `LegalCaseDebtor`: pozițiile de creanță țin de dosar, iar id-ul societății ar fi ambiguu între dosare. |
| D17 | O societate folosită se poate „arhiva” (ascunsă din selector) în loc de ștearsă? | utilizator | Nu în MVP: ștergerea e blocată cu mesaj. Arhivarea intră în backlog. |

## 2. Partea I: modificările cerute de avocat

### 2.1 C: textul BPI (doar text)

| Fișier | Modificare |
|---|---|
| `translations/messages.ro.yaml:315`, `messages.en.yaml:316` (`wizard.step2.bpi.body`) | RO: „Înainte de a depune ordonanța de plată trebuie să verifici dacă debitorul nu se află în procedurile prevăzute de Legea 85/2014.” EN: „Before filing the payment order you must check that the debtor is not subject to any of the proceedings provided for by Law 85/2014.” |
| `templates/components/Step2DebtorsLiveComponent.html.twig:262` | Se scoate `\|raw`, pentru că textul nu mai conține HTML. |
| `wizard.step2.bpi.title` | Rămâne „Verificare BPI obligatorie (Legea 85/2014)”. Se introduce cheia `wizard.step4.bpi_label`, ca Pasul 4 să nu mai taie titlul la `(` (`_step4_confirmation_content.html.twig:203`). |
| `messages.ro.yaml:353`, `messages.en.yaml:354` (`wizard.step2.field.bpi_verified_today`) | RO: „Am verificat: debitorul nu se află în procedurile prevăzute de Legea 85/2014”. **Condiționat de D6.** |
| `validators.ro.yaml:54`, `validators.en.yaml:54` (`bpi_verification_required`) | RO: „Confirmă verificarea debitorului pentru a continua. Ordonanța de plată nu poate fi depusă împotriva unui debitor aflat în procedurile prevăzute de Legea 85/2014.” **Condiționat de D6.** |
| `messages.ro.yaml:2471-2473`, `messages.en.yaml:2474-2476` | Cele trei texte de mai jos. **Condiționat de D6.** |
| `src/Service/Validation/OpAdmissibilityValidator.php:28-38, 50-53, 207, 209, 249` | Docblock-ul și comentariile se rescriu în engleză, fără bpi.just.ro. |
| `.claude/agents/lexrecovery-legal-reviewer.md:273` | „BPI (Legea 85/2014), consultat de avocat; nu se numește un site.” |
| Documente interne | O notă de corecție în `CONSULTATIE-AVOCAT-2026-05-09.md:69`, `PLAN-DEZVOLTARE-LEXRECOVERY.md:927,944,2593`, `ANALIZA-FLUXURI-LEXRECOVERY.md:160` și `ANALIZA-JURIDICA-PROCEDURA-OP-2026-05-08.md:746,753`. |

Textele noi pentru mesajele de admisibilitate:
- `OP_INSOLVENCY_NOT_VERIFIED`: „Nu ai confirmat că debitorul nu se află în procedurile prevăzute de Legea 85/2014. Revino la pasul Debitor și bifează confirmarea înainte de depunere.”
- `OP_INSOLVENCY_STALE`: „Verificarea privind procedurile prevăzute de Legea 85/2014 este mai veche de 7 zile. Reverifică debitorul și confirmă din nou înainte de depunere, deoarece situația se poate schimba rapid.”
- `OP_PF_BIPF_MANUAL_CHECK`: „Debitor persoană fizică: verifică manual, înainte de depunere, dacă debitorul nu se află în procedurile prevăzute de Legea 151/2015.”
- Dispare instrucțiunea „atașează / actualizează dovada PDF”. Atestarea e o declarație a avocatului (D12).

Etichetele `enum.document_type.bpi_proof` și badge-urile „BPI verificat / neverificat” rămân. BPI e un termen legal (art. 5 din Legea 85/2014), nu un site.

**Teste:**
- Un test în `CaseWizardControllerStep1To4Test` că Pasul 2 nu conține `bpi.just.ro` și nici `<a` în blocul BPI.
- `lint:yaml translations/` și paritatea cheilor RO/EN.
- `grep -rn "bpi.just" src templates translations .claude docs` nu întoarce nimic în afara notelor de corecție.

**Verificare live:** Pasul 2 arată o frază fără link. Pasul 4 arată mesajele noi când bifa lipsește și când verificarea e mai veche de 7 zile.

**Efort:** 0,25 zile.

### 2.2 A: debitorul este doar persoană juridică (plus G: garda de identitate)

**Modificări (după tiparul din `e6c4a8e`):**

1. **Formularul** `src/Form/Wizard/Step2DebtorEntryType.php`:
   - `personType` (44-50) primește `'choices' => [PersonType::PJ]`, fără placeholder.
   - Se scot atributele `data-required-on` (55-73).
   - În PRE_SUBMIT (129-167) dispare ramura PF (149-160). Rămâne `$data['personalId'] = null` necondiționat.
   - Docblock-ul (24-37) se rescrie în engleză.
2. **Template-ul** `Step2DebtorsLiveComponent.html.twig`:
   - Cardurile radio (70-103) sunt înlocuite de un input hidden cu `PersonType::PJ`, plus `setRendered` pe `personType` și `personalId`.
   - Se șterg blocul CNP (168-172), atributele `data-person-type-toggle-target` (136, 162, 176, 257) și `data-controller="person-type-toggle"` (39).
   - Comentariile de la 1-6, 34-37, 105-108, 174-175 și 248-255 se rescriu în engleză, fără referințe de pas.
3. **`assets/controllers/person-type-toggle_controller.js`** se șterge după confirmarea prin grep. Urmează `importmap:install` și `assets:install`.
4. **Prefill-ul** în `PrefillFromExtractionService.php`:
   - `buildDebtorEntry` (492-509) setează `personType: PJ` și nu mai setează `personalId`. `toPersonType` (584-591) se șterge.
   - D11: la `aggregate()` (101 pentru creditor, 121 pentru debitor) se trimite o listă de câmpuri conflictuale fără `personType` și `personalId`. Ele rămân în clusterizare (`toSource`, 290; `CoherentAggregator::samePerson`, 398-414).
   - D3: nota INFO `wizard.conflict.debtor.read_as_natural_person`, fără opțiuni și fără blocare.
5. **Garda pentru sesiuni vechi (D2)**, în `CaseWizardController`:
   - O singură metodă privată, `guardStaleDebtorBag()`, apelată din toate punctele de intrare după Pasul 2: GET și POST la Pasul 3, `confirmation()` (880-915) și orice POST de după Pasul 2.
   - Dacă bag-ul are un debitor cu `personType !== PJ` sau mai mult de un debitor: redirect la `case_wizard_debtor`, cu flash-ul `wizard.step2.flash.natural_person_not_supported` sau `...too_many_debtors`. Bag-ul nu se rescrie.
   - Orice proprietate nouă din DTO-urile serializate în sesiune (de exemplu `debtorId`, în L2) are o valoare implicită, ca un bag vechi să se deserializeze.
6. **DTO-ul** `Step2DebtorEntry.php` își păstrează structura. Se rescriu docblock-ul (14-28) și comentariul PFA (68-78): un PFA, II sau IF intră ca PJ (vezi D1).
7. **Validatorul** (`OpAdmissibilityValidator.php:204-213`): ramura PF rămâne pentru dosarele existente.
8. **Pasul 4** (`_step4_confirmation_content.html.twig:181-184`): rândul „Tip persoană” se scoate.
9. **Adminul (D10):** la creare se oferă doar PJ. Formularul adminului se refactorizează în R, dar regula se aplică deja din A.
10. **Seed-ul demo** (`SeedDemoCasesCommand.php:43-46,76-79,87-90`, plus `setPersonalId` la 166): Popescu Ion, Ionescu Maria și Georgescu Vasile devin PJ cu CUI și ONRC valide. Urmează `make demo-sync`.
11. **Traduceri:** după grep, se șterg `wizard.step2.placeholder.person_type` și `wizard.step2.field.personal_id`.

**G. Garda de identitate** (în același slice, pentru că repară o problemă existentă):
- Locul este PRE_SUBMIT în `Step2DebtorEntryType`, pe calea controllerului `CaseWizardController::debtor()`, unde formularul e legat de DTO-ul din sesiune. În Live Component, `initialFormData` nu e `#[LiveProp]`, deci acolo comparația nu are sens.
- Se declanșează când DTO-ul legat are CUI și `CuiNormalizer::canonical(cui trimis) !== canonical(cui legat)`. Atunci:
  - pe DTO-ul legat se golesc `insolvencyCheckedAt`, `anafStatus`, `anafCheckedAt`, `inInsolvency` și `autoFilled`;
  - `bpiVerifiedToday` se scoate din datele trimise, iar NotNull cere bifa din nou;
  - `anafStatus` și `anafCheckedAt` trimise se ignoră când `anafCheckedAt` trimis e identic cu cel legat, adică nu a avut loc o sincronizare nouă.
- `CuiNormalizer` (§4.4) se introduce în acest slice, ca funcție pură fără dependențe de model.

**Teste:**
- `Step2DebtorEntryTypeTest.php:141-210`: 141 și 166 devin „PF e respins ca alegere invalidă”; 196 se șterge.
- `CaseWizardControllerStep1To4Test`:
  - `testDebtorFormOffersOnlyLegalPerson`;
  - 383/396 trec pe `anafStatus: INACTIV`;
  - `testStaleNaturalPersonDebtorInSessionRedirectsToDebtorStep`, la Pasul 3, la Pasul 4 și la POST;
  - un test web: CUI schimbat după bifă și după sincronizare cere din nou bifa, iar același CUI o păstrează.
- `PrefillFromExtractionServiceTest`: PF extras devine PJ; dezacordul pe CNP sau pe tipul de persoană nu produce conflict (debitor și creditor); nota INFO.
- `CuiNormalizerTest`: `RO123`, ` ro 123 `, `0123`, `RO0123`, gol devine null.

**Verificare live:**
- Pasul 2 nu mai oferă PF, iar un POST manipulat cu `personType=PF` e respins.
- Un document de PF afișează nota și cere CUI.
- O sesiune cu PF injectat e trimisă înapoi.
- Schimbarea CUI-ului după bifă golește atestarea.
- Consola nu arată erori de controller lipsă.

**Efort:** 1,5 zile (A: 1 zi, G: 0,5 zile).

### 2.3 B: un singur debitor pe dosar

**Server:**
1. **`MAX_DEBTORS = 1`** (`Step2DebtorsData.php:24`). Docblock-ul spune: „single debtor for now; the collection stays for joint debtors after launch”. `Assert\Count(max: 1)` respinge la submit un `addCollectionItem` falsificat (se adaugă un test explicit). Docblock-ul componentei (`Step2DebtorsLiveComponent.php:15-34`) se rescrie în engleză.
2. **Prefill-ul** (`PrefillFromExtractionService.php:114-156`):
   - Când `count($clusters) > 1`, se emite un singur conflict blocant `DEBTOR_SET`, `wizard.conflict.debtor_set.choose`, cu câte o `ConflictOption` pentru fiecare cluster (denumire, CUI, documente sursă) și fără sugestie automată.
   - Se agregă doar clusterul ales, cu `entityKey 'debtor-0'`.
   - Ramurile `too_many` (141-149) și `multiple` (150-156) dispar.
3. **Schimbarea clusterului ales reconstruiește debitorul din sesiune** (blocantul 3 din review). `debtor()` folosește `$bag['debtors'] ?? $prefill->debtors`, iar `ConflictChoiceApplier::applyToDebtors` mută doar câmpurile cu pin. De aceea, la schimbarea alegerii:
   - `bag['debtors']` se reconstruiește integral din clusterul nou;
   - rezoluțiile `DEBTOR`-scope pentru `debtor-0` se șterg;
   - verificările din entry (ANAF, bifă) se golesc, pentru că e altă societate.
   Un test confirmă că toate câmpurile se înlocuiesc.
4. **`claims_span_debtors`** (163-171) devine blocare fără bifă:
   - flag-ul `acknowledgeable: false` pe `PrefillConflict`, respectat în toate straturile: `pendingAcknowledgement` (317-322), `settleConflicts`, `conflictViewVars` și căsuța din Twig;
   - conflictul se calculează față de clusterul ales;
   - mesajul e neutru: „Documentele cu creanțe privesc mai mulți debitori. Păstrează la Pasul 0 doar documentele debitorului pe care îl urmărești în acest dosar.” Nu mai spune „sunt un dosar separat”, pentru că la debitori solidari (Codul civil, art. 1443 și urm.) sau la fideiusor avocatul poate urmări legitim un singur debitor.
5. **Pasul 0:** cardul lateral arată clusterul ales (`CaseWizardController.php:254, 294`).
6. **Pasul 4:** o mențiune vizibilă a părților excluse din documente, ca o alegere greșită să nu treacă neobservată.
7. **Efectul asupra modelului normalizat** (după R): la trimitere, entry-ul clusterului ales devine singurul rând `legal_case_debtor`, cu `position = 0`. Clusterele respinse nu se scriu nicăieri, nici ca societăți în bibliotecă. Ele rămân doar în înregistrarea conflictului (audit).

**UI:**
- Butonul „Adaugă debitor” nu se randează când `entry_count >= MAX_DEBTORS` (283-299).
- Butonul de eliminare (garda `entry_count > 1`, 55) rămâne, pentru bag-urile vechi.
- Titlul cardului devine „Debitor” (42), iar la Pasul 4 „Debitor(i) · %count%” devine „Debitor” (`messages.ro.yaml:530`).
- Traduceri, RO și EN împreună, fără em-dash:
  - nouă: `debtor_set.choose`;
  - reformulată: `claims_span_debtors`;
  - `too_many_debtors`: „Un dosar are un singur debitor.”;
  - se șterg `add_debtor`, `max_reached`, `add_debtor_hint`, `debtor_set.multiple` și `debtor_set.too_many`.

**Înainte de implementare:**
- `grep -rn "debtors\]\[1\]\|debtors\[1\]" tests/`, pentru testele care construiesc doi debitori.
- Se verifică dacă `CoherentAggregator::samePerson` separă corect documentele PF fără CUI, ca nicio parte să nu se piardă tăcut.

**Teste:**
- `Step2DebtorsDataTest`: 2 intrări sunt invalide.
- `Step2DebtorsLiveComponentTest`: butonul de adăugare lipsește; eliminarea merge pe o stare cu 2 intrări.
- `MultiDebtorPrefillTest`, `ClaimsAcrossDebtorsTest`, `T5DeterminismClusteringReviewTest`, `ConflictResolutionTest`:
  - conflictul de alegere înlocuiește `multiple`/`too_many`;
  - clusterul 1 ales aduce valorile lui, cu cheia `debtor-0`;
  - schimbarea alegerii șterge pin-urile și reconstruiește entry-ul;
  - `claims_span_debtors` nu poate fi bifat (în controller și la randare).
- `CaseWizardControllerStep1To4Test`: un POST cu `debtors[1]` e respins; un bag cu 2 debitori e redirecționat.

**Verificare live:**
- Două facturi către debitori diferiți blochează pasul.
- Un contract cu fideiusor cere alegerea, iar cardul, Pasul 0 și Pasul 4 arată aceeași parte.
- O sesiune veche cu 2 carduri poate fi redusă la 1.

**Efort:** 2,5 zile. Estimarea inițială de 1,5-2 zile era subestimată (review).

## 3. De ce tabel de legătură și nu `SavedDebtor`

| Criteriu | `SavedDebtor` (respins) | Tabel de legătură (ales) |
|---|---|---|
| Coloane de identitate | Duplicate: `debtor` și `saved_debtor` au același trait și trebuie sincronizate. | O singură sursă: `debtor` e societatea. |
| Tipar | Nou, fără precedent în cod. | Același ca `creditor` (`Creditor.php:13`, un rând per avocat, referit din dosar). |
| Debitori multipli după lansare | Cer câte o legătură `saved_debtor_id` pe fiecare card și deduplicare în memorie. | Nativ: N rânduri `legal_case_debtor` cu `position`. |
| Starea de verificare | Trebuia ținută explicit în afara bibliotecii. | Stă pe rândul de legătură. Prin construcție nu poate trece la alt dosar. |
| Sincronizarea | O a doua tranzacție după commit, cu legătură „provenance only”. | Nu există: dosarul referă societatea. |

**Compromisul acceptat:** o regenerare a actelor după editarea societății folosește datele curente, la fel ca la creditor. Actele deja generate rămân fișierele stocate, iar instanța rămâne cea de pe `legal_case.court_id`. Consecința pe care planul o tratează explicit: datele societății pot diferi de ce a văzut avocatul în wizard. De aceea există conflictul de la Pasul 4 (D7) și textul adevărat de pe pagina de editare (decizia 9).

## 4. Partea II: modelul normalizat

### 4.1 Entități și coloane

**`App\Entity\Debtor`** (tabel `debtor`) devine societatea:
- `id`, `user` (ManyToOne `User`, NOT NULL, fără `onDelete`, ca la creditor), `use PartyContactInfoTrait` (person_type, name, cui, personal_id, onrc_number, address, email, phone, iban), `addressCounty`, `addressLocality`, `administrator`, `createdAt`, `updatedAt`.
- `cuiKey` VARCHAR(20) NULL, setat din `cui` prin `CuiNormalizer::canonical` în `setCui()` și pe PrePersist/PreUpdate. `cui` păstrează forma introdusă, pentru că aceasta se copiază în acte.
- Dispar `legalCase`, cu `setLegalCase`/`getLegalCase`, și cele șase câmpuri de verificare, cu accesorii lor.
- Inversul `legalCaseLinks` (OneToMany spre `LegalCaseDebtor`, fără cascade) există doar pentru contorul de utilizare și garda de ștergere.
- Indexuri:
  - în R (M1): index simplu `idx_debtor_user_cui_key (user_id, cui_key)`;
  - în L3 (M2): acesta devine UNIQUE `uniq_debtor_user_cui_key`.
- `__toString()` întoarce numele.
- Docblock-ul din `PartyContactInfoTrait.php:13` se actualizează: verificarea debitorului stă pe `LegalCaseDebtor`.

**`App\Entity\LegalCaseDebtor`** (tabel `legal_case_debtor`, `#[ORM\HasLifecycleCallbacks]`):
- `id`.
- `legalCase`: ManyToOne, `inversedBy: 'debtors'`, NOT NULL, `onDelete: 'CASCADE'`.
- `debtor`: ManyToOne `Debtor`, NOT NULL, fără cascade remove, FK fără acțiune (RESTRICT).
- `position` SMALLINT: 0 e debitorul din somație.
- `anafStatus` (enumType `AnafStatus`, VARCHAR(20)), `anafCheckedAt`, `inInsolvency` (default false), `insolvencyCheckedAt`, `bpiVerifiedNote` VARCHAR(500), `bpiProofDocument` (ManyToOne `Document`, `onDelete: 'SET NULL'`, ca azi în `Debtor.php:46-48`).
- `createdAt`, `updatedAt`.
- UNIQUE `uniq_lcd_case_debtor (legal_case_id, debtor_id)`.

**`App\Entity\LegalCase`** (46-48, 469-490):
- `$debtors` își păstrează numele și retrimite la `LegalCaseDebtor`, cu același cascade persist/remove și același orphanRemoval, plus `#[ORM\OrderBy(['position' => 'ASC'])]`.
- `addDebtor(LegalCaseDebtor)` setează referința inversă și `position = count()` când poziția lipsește.
- Nou: `getPrimaryDebtor(): ?LegalCaseDebtor`, care sortează în PHP după `position` și deci nu depinde de ordinea hidratării.

**`App\Entity\ClaimItem`** (49-55, 158-167, D16): `debtor` retrimite la `LegalCaseDebtor`, nullable, `onDelete: 'SET NULL'`. Coloana are 0 valori setate în dev și în lexdemo, iar în `src/` nu există niciun apelant care să o scrie.

### 4.2 API-ul delegat: alegere

Am analizat trei variante:
- **(A) Recomandată:** `LegalCase::$debtors` întoarce rânduri `LegalCaseDebtor`. Rândul are `getDebtor()` (societatea) și getteri de identitate **doar de citire**, care delegă spre societate: `getName`, `getCui`, `getPersonType`, `getPersonalId`, `getOnrcNumber`, `getAddress`, `getAddressCounty`, `getAddressLocality`, `getAdministrator`, `getEmail`, `getPhone`, `getIban` și `__toString`. Getterii de verificare sunt proprii rândului.
- **(B)** Proprietatea se redenumește `caseDebtors`, iar `getDebtors()` întoarce societățile ordonate.
- **(C)** Fără delegare: template-urile citesc `.debtor.name`.

**Justificarea pentru A:**
- Template-urile (`_party_card`, `_hero`, cele trei PDF-uri, cele patru partials de termene), `PortalCaseMatcher.php:57-63` și `CaseSummonsController.php:66` rămân neschimbate.
- `_party_card.html.twig:6` (`anafStatus`) citește automat valoarea de pe dosar, care e cea corectă.
- Toate join-urile DQL `lc.debtors` rămân valide.
- La B, `anafStatus` din overview trebuie rescris, iar `getDebtors()` devine o colecție derivată.
- La C se modifică circa 15 locuri, inclusiv actele juridice.

**Costul variantei A:** numele `getDebtors()` întoarce legături, nu societăți. Se atenuează cu docblock, cu `@return Collection<int, LegalCaseDebtor>` și cu `getPrimaryDebtor()`. **Rândul nu are setteri de identitate delegați**, ca o editare pe dosar să nu poată scrie societatea din greșeală. Scrierea identității se face doar prin `Debtor`, din `/debtors`, din resolver sau din admin.

**Ajustări punctuale:**
- `OpAdmissibilityValidator.php:6, 204-270`: tipul și importul trec pe `LegalCaseDebtor`; comentariile de la 209 și 249 trec în engleză.
- `SummonsContextBuilder.php:100` folosește `getPrimaryDebtor()`.

### 4.3 Repository și interogări

**`DebtorRepository`** (azi gol), fără a copia lookup-ul creditorului pe `cui` brut:
- `findOwned(User, int): ?Debtor`.
- `findOneByUserAndCuiKey(User, string): ?Debtor`, cu `setMaxResults(1)` până la UNIQUE-ul din M2.
- `createAutocompleteQueryBuilder(User)`: user, `personType = PJ`, `cuiKey IS NOT NULL`, ordonat după `updatedAt DESC`, apoi după `name`.
- `createTableQueryBuilder(User)`: include `casesCount` = `COUNT(DISTINCT lc.id)` prin `legalCaseLinks`, cu `lc.deletedAt IS NULL`.
- `countLinks(Debtor)`: toate legăturile, inclusiv cele din dosare soft-deleted, pentru garda de ștergere.
- `casesUsing(Debtor)`: pentru cardul „Dosare în care apare”.

**`LegalCaseDebtorRepository`**: gol la început.

**Fetch join-uri:** calea `lc.debtors` rămâne. Pe lângă ea se adaugă join-ul spre societate și o ordonare explicită, pentru că `#[OrderBy]` e ignorat la hidratarea cu join (docblock-ul `LegalCaseRepository.php:29-30`):
- `LegalCaseRepository.php:36` (`findWithOverviewRelations`) și `:655` (`blockedCasesQueryBuilder`): `->leftJoin('db.debtor', 'dbf')->addSelect('dbf')->addOrderBy('db.position', 'ASC')`.
- `LegalDeadlineRepository.php:253-255` (`findAgendaForUser`), `:388-402` (`warmDebtors`) și `:413-415` (`overdueQueryBuilder`): același join spre societate, plus ordonarea.

Filtrele de soft delete (`lc.deletedAt IS NULL`) rămân neschimbate: societățile nu au soft delete.

**Test de nerevenire:** numărul de interogări pentru overview și pentru `/termene` nu crește (profiler sau `DebugStack` în test).

### 4.4 `CuiNormalizer`

- `App\Service\Party\CuiNormalizer::canonical(?string): ?string`: elimină spațiile, trece la majuscule, elimină prefixul `RO` și zerourile de la început. Un rezultat gol devine null.
- **De ce și zerourile:** `ValidCuiValidator` și `PiiMasker::isValidCui` (`src/Util/PiiMasker.php:109-123`) acceptă zerouri la început, iar normalizatoarele existente le elimină (`CoherentAggregator.php:594`, `ClaimItemDeduplicator.php:261`). Fără această regulă, `0123` și `123` ar deveni două societăți.
- **Echivalentul SQL** folosit în M1:
  `NULLIF(TRIM(LEADING '0' FROM CASE WHEN UPPER(REGEXP_REPLACE(cui,'[[:space:]]','')) LIKE 'RO%' THEN SUBSTRING(UPPER(REGEXP_REPLACE(cui,'[[:space:]]','')),3) ELSE UPPER(REGEXP_REPLACE(cui,'[[:space:]]','')) END), '')`
- **Test de paritate** (KernelTestCase): pentru un set de intrări (`RO123`, `ro 123`, `R O123`, `0123`, `RO0123`, `123 `, gol, null), rezultatul PHP e identic cu cel al expresiei SQL rulate prin DBAL.
- Celelalte patru normalizări ad hoc (`LookupController:57`, `ValidCuiValidator:29`, `OblioImportInvoicesCommand:224`, `CoherentAggregator:594`) și cheia creditorului pe `cui` brut (`Creditor.php:13`) se notează pentru un pas separat.

### 4.5 Migrările

Migrările se scriu de mână: `doctrine:migrations:diff` nu produce pașii de date. Ambele sunt `isTransactional(): false`, pentru că în MySQL fiecare DDL face commit implicit.

**Verificarea obligatorie înainte de orice `migrate`** (incidentul cu migrarea stale; host-ul dă EPERM, deci se lucrează prin container):
1. `docker exec symfony-mvp-php cat migrations/VersionX.php` confirmă că fișierul din container e cel revizuit.
2. Backup: `docker compose exec database mysqldump ... > var/backup-pre-debtor-<data>.sql`.
3. `docker exec symfony-mvp-php php bin/console doctrine:migrations:migrate --dry-run` și citirea SQL-ului.
4. Migrare în dev, apoi interogările de control de mai jos, `doctrine:schema:validate` și `make restart`.
5. `down()` testat pe o copie a bazei dev, apoi `migrate` din nou.
6. Baza de test se aduce la zi la fel. Lexdemo se migrează doar după merge, prin `make demo-sync`, cu backup propriu și restart php-fpm (`up -d` nu repornește php-fpm).

**M1 (în R): structurală, fără pierdere de date, 1:1.**
0. `abortIf`: există tabelul `legal_case_debtor`. Apoi `CREATE TABLE debtor_premerge_backup AS SELECT * FROM debtor`.
1. `CREATE TABLE legal_case_debtor` cu coloanele din §4.1, plus coloana temporară `legacy_debtor_id`. FK-uri: `legal_case_id` cu CASCADE, `debtor_id` fără acțiune, `bpi_proof_document_id` cu SET NULL.
2. `ALTER TABLE debtor ADD user_id INT NULL, ADD cui_key VARCHAR(20) NULL`. Umplere prin join cu `legal_case` (`user_id`) și cu expresia din §4.4 (`cui_key`).
3. `INSERT INTO legal_case_debtor`: câte un rând pentru fiecare debitor, cu `debtor_id = id`, `legacy_debtor_id = id` și cele 6 coloane de verificare copiate. `position = ROW_NUMBER() OVER (PARTITION BY legal_case_id ORDER BY id) - 1`.
4. `claim_item`: se șterge FK-ul spre `debtor`, `debtor_id` se remapează prin `legacy_debtor_id` la `legal_case_debtor.id` (0 rânduri azi), apoi se adaugă FK-ul spre `legal_case_debtor` cu SET NULL.
5. Se șterg FK-urile `FK_EDCC8CAE82B4A9B` și `FK_EDCC8CAE6679949B` (MySQL cere FK-ul șters înaintea indexului și a coloanei). Apoi se șterg din `debtor` coloana `legal_case_id` și cele 6 coloane de verificare.
6. `user_id` devine NOT NULL, cu FK spre `user` și `idx_debtor_user_cui_key`. Se adaugă UNIQUE `(legal_case_id, debtor_id)` pe `legal_case_debtor`, apoi se șterge `legacy_debtor_id`.
7. **`down()` exact:** reface coloanele din `legal_case_debtor` (fiecare societate are cel mult o legătură la M1, deci inversarea e 1:1), apoi șterge tabelul de legătură, `user_id` și `cui_key`.

**Control după M1:**
- `COUNT(legal_case_debtor)` = numărul vechi de debitori (44 în dev).
- Fiecare dosar cu debitor are exact un rând cu `position = 0`.
- `COUNT(debtor)` e neschimbat.
- Nu există `debtor.user_id` NULL.
- `claim_item.debtor_id` nu are valori orfane.

**M2 (în L3): deduplicare și UNIQUE.**
0. `abortIf`:
   - există deja `debtor_merge_map`;
   - două rânduri `legal_case_debtor` ale aceluiași dosar ar ajunge la aceeași societate (ar încălca UNIQUE-ul din M1).
   Apoi `CREATE TABLE debtor_premerge_backup_m2 AS SELECT * FROM debtor`.
1. Tabelul (non-temporar) `debtor_merge_map(old_id, survivor_id, conflict)`:
   - supraviețuitorul e rândul cu `ROW_NUMBER() = 1` pe `(user_id, cui_key)`, după regula D13;
   - rândurile cu cheie NULL se mapează la ele însele;
   - `conflict = 1` când grupul are mai multe valori distincte pentru denumire, adresă, ONRC sau tip de persoană.
2. Pentru fiecare coloană de identitate, supraviețuitorul se completează doar unde are NULL sau `''`, din cel mai recent membru al grupului care are valoare. Tehnica: `ROW_NUMBER` pe rândurile nevide, scris întâi într-un tabel temporar (evită eroarea 1093), apoi `UPDATE ... JOIN`.
3. `UPDATE legal_case_debtor` redirecționează `debtor_id` spre `survivor_id` prin hartă.
4. `DELETE` pentru rândurile absorbite.
5. `DROP INDEX idx_debtor_user_cui_key`, apoi `ADD UNIQUE uniq_debtor_user_cui_key (user_id, cui_key)`.
6. Fiecare grup cu conflict se scrie cu `$this->write()` (id-uri și câmpurile în conflict, fără valori). Tabelele `debtor_merge_map` și cele de backup se păstrează până la o migrare de curățenie, după verificarea în producție.
7. **`down()` cu pierderi, restaurabil din backup:** se reface fiecare rând absorbit din `debtor_premerge_backup_m2`, legăturile se readuc prin hartă, iar UNIQUE-ul redevine index simplu.

**Control după M2:**
- `COUNT(debtor)` = numărul de grupuri distincte `(user_id, cui_key)` plus rândurile cu cheie NULL.
- Nicio legătură orfană, iar numărul de legături e neschimbat.
- În dev sunt așteptate 6 grupuri absorbite, iar log-ul listează cele 3 grupuri cu conflicte și grupul PF/PJ.

### 4.6 Pagina `/debtors` (L1)

- **Controllerul** `App\Controller\DebtorLibraryController` (`#[Route('/debtors')]`, `IS_AUTHENTICATED_FULLY`), subțire, după `CreditorLibraryController` (34/42/69). Rute:
  - `app_debtors`;
  - `app_debtors_new`;
  - `app_debtors_edit` (`/{id<\d+>}/edit`);
  - `app_debtors_delete` (POST, CSRF `debtor_delete_{id}`).
- **Ownership:** `DebtorVoter` (VIEW, EDIT, DELETE, doar pentru owner). Un rând inexistent dă 404; rândul altui user dă 403.
- **DTO-ul** `App\DTO\Library\DebtorLibraryData`: constrângerile de identitate și contact din `Step2DebtorEntry`, cu CUI și ONRC obligatorii. Nu are ANAF, BPI, `autoFilled`, `personType` sau CNP.
- **Formularul** `DebtorLibraryType`:
  - PRE_SUBMIT normalizează IBAN-ul și CUI-ul;
  - sincronizarea ANAF (`party-anaf-lookup`) completează denumirea, adresa, județul și localitatea, dar statusul nu se salvează nicăieri (verificarea ține de dosar);
  - `csrf_token_id` = `debtor_library`.
- **Serviciul** `App\Service\Debtor\DebtorLibraryService`: create, update, delete, findDuplicate. Scrie audit `debtor_created`, `debtor_updated` (cu lista câmpurilor) și `debtor_deleted`, cu id-uri, prin `AuditLogService`.
- **Duplicate:** un `cuiKey` existent la același user dă `FormError` pe `cui`, cu link spre intrare. La editare se exclude rândul propriu. Formularul se re-randează cu 422 prin Turbo („erorile nu dau refresh”).
- **Ștergerea (decizia 8):**
  - dacă `countLinks > 0`, nu se șterge nimic: apare flash-ul „Societatea apare în %count% dosare și nu poate fi ștearsă.”, iar butonul de ștergere nu se randează pentru rândurile folosite (întâi nu oferi acțiunea imposibilă);
  - altfel, ștergere definitivă cu modal de confirmare;
  - `ForeignKeyConstraintViolationException` rămâne prinsă defensiv (cursă).
- **Grid-ul** `DebtorTableDefinition` (cheia `debtors`): denumire, CUI, județ, `casesCount`, ultima modificare, acțiuni. Căutare după denumire sau CUI. Filtre: user, PJ și `cuiKey` nenul (D14).
- **Template-urile** `templates/debtors/{index,new,edit,_form,_cases_used}.html.twig`, după `templates/creditors/*`. Pe pagina de editare apar cardul „Dosare în care apare (N)” și nota din decizia 9.
- **Sidebar-ul** (`templates/_sidebar.html.twig:102-107`): span-ul dezactivat devine link spre `app_debtors`, activ pe `app_debtors*`.
- **Creditorul:** subtitlul de editare se corectează la textul din decizia 9, într-un commit separat.
- Până la M2 (L3), istoricul poate conține duplicate pe CUI. Lista le arată așa cum sunt, iar verificarea de duplicat folosește `setMaxResults(1)`. Aplicația e pre-producție, deci L1-L3 ajung în producție împreună.

### 4.7 Selectorul din Pasul 2 (L2)

**Locul:** bara de toggle dezactivată din `templates/case/_step2_debtor_content.html.twig:19-34` devine un card de selecție în afara Live Component-ului, analog cu `_step1_creditor_content.html.twig:22-38`. Cheile `wizard.step2.toggle.*` se șterg. Dacă biblioteca e goală, cardul arată un hint cu link spre `/debtors`.

**Mecanismul (fără LiveAction):**
1. **`DebtorPickType`**, cu CSRF propriu și câmpul `DebtorAutocompleteType` (`#[AsEntityAutocompleteField]`, după `CreditorAutocompleteType`):
   - `query_builder` = `createAutocompleteQueryBuilder(user)`;
   - `min_characters: 2`, `preload: false`;
   - eticheta „Denumire (CUI 123)”.
   Endpoint-ul Autocomplete cere autentificare; un test confirmă că rândurile altui user nu apar.
2. **POST la `case_wizard_debtor_pick`.** Dacă Pasul 2 are deja un debitor completat, `data-turbo-confirm` spune: „Datele debitorului din acest pas se înlocuiesc cu cele din bibliotecă.”
3. **`DebtorWizardPicker::pick(array &$bag, User, int $id)`**:
   - `findOwned`, sau 404 fără scurgere de date;
   - intrarea curentă se salvează în `bag['debtorBeforePick']`, pentru restaurare la renunțare;
   - se construiește un `Step2DebtorEntry` cu identitatea copiată și `debtorId` setat;
   - verificările pornesc goale: `anafStatus = null`, `anafCheckedAt = null`, `insolvencyCheckedAt = null`, `inInsolvency = false`, `autoFilled = []`;
   - rezoluțiile `DEBTOR`-scope pentru `debtor-0` se șterg;
   - redirect 303 la `case_wizard_debtor`.
4. **În Live Component:**
   - `debtorId` e `HiddenType`, mapat, cu transformer (gol înseamnă null), deci supraviețuiește re-randărilor;
   - când `debtorId` e setat, câmpurile de identitate sunt **doar de citire** (ca la creditorul ales, care își ascunde câmpurile manuale), cu link „Editează în bibliotecă” și butonul „Renunță”;
   - sincronizarea ANAF rămâne disponibilă, dar scrie doar statusul pe entry;
   - apar badge-ul „Din bibliotecă” și nota `wizard.step2.library.reverify_notice`: „Verifică din nou pentru acest dosar starea ANAF, procedurile prevăzute de Legea 85/2014 și sediul, de care depinde instanța competentă.”;
   - badge-ul ANAF din header (33, 49-54) citește `entry.anafStatus.vars.value`.
   Un test cu `InteractsWithLiveComponents` confirmă că `debtorId` și badge-urile supraviețuiesc unei re-randări.
5. **„Renunță”** (POST `case_wizard_debtor_unpick`) restaurează `bag['debtorBeforePick']`, dacă există; altfel pune null și Pasul 2 revine la prefill. Ce a tastat avocatul nu se pierde.

**Sanitizarea pe POST, în `debtor()`:**
- un `debtorId` care nu aparține userului devine null;
- dacă `canonical(cui trimis) !== debtor.cuiKey`, legătura se abandonează, iar garda G golește verificările.

**Conflictele de extracție când debitorul vine din bibliotecă:**
- conflictele `DEBTOR`-scope pe câmpuri nu se afișează și nu cer confirmare;
- `ConflictChoiceApplier::applyToDebtors` sare peste pin-uri pentru acel entry, la ambele apeluri (`CaseWizardController.php:896`, `1936`);
- `DEBTOR_SET` rămâne;
- **D8:** dacă CUI-ul extras al clusterului ales, adus la forma canonică, diferă de `cuiKey`, apare conflictul ERROR `wizard.conflict.debtor.library_mismatch`, cu bifă auditată. Biblioteca câștigă pentru identitate, iar avocatul vede nepotrivirea. Se testează explicit.

**Salvarea la trimitere în L2:** cu `debtorId` valid, resolverul (§4.8) folosește acea societate și completează doar câmpurile goale. Identitatea fiind doar de citire, nu apar diferențe de raportat.

### 4.8 Salvarea la trimitere (R, apoi L3)

**Serviciul** `App\Service\Case\DebtorFirmResolver` ține controllerul subțire. Se apelează din `persistWizard` (`CaseWizardController.php:1177-1181`), în `wrapInTransaction`. Înlocuiește `buildDebtor` (1401-1424).

**În R (fără schimbare de comportament):** `resolve()` creează mereu o societate nouă (`user`, identitate, `cuiKey`), iar `buildCaseDebtor(firm, entry, position)` creează rândul `legal_case_debtor` cu cele patru valori de verificare din entry. Fără UNIQUE pe societate, rezultatul e echivalent cu azi: un rând de identitate per dosar.

**În L2:** `debtorId` valid, deținut de user și cu același `cuiKey`, înseamnă reutilizare. Se completează doar câmpurile goale, iar audit-ul `debtor_filled` listează câmpurile completate.

**În L3:**
1. Fără `debtorId` valid se caută `findOneByUserAndCuiKey`.
2. Dacă societatea există, se completează doar câmpurile goale (D7). Dacă, **pe lângă asta**, entry-ul are valori nevide diferite de cele ale societății, Pasul 4 arată conflictul blocant `wizard.conflict.debtor.library_differs`, care listează câmpurile, cu cele două opțiuni din D7:
   - „Folosește datele din bibliotecă”: rezumatul de la Pasul 4 afișează valorile societății, adică exact ce intră în acte;
   - „Actualizează societatea cu datele din acest pas”: scrie și auditează `debtor_updated_from_case`.
   Conflictul se calculează în `confirmation()`, pe aceeași interogare, și nu e în Live Component.
3. Dacă societatea nu există, se creează (`debtor_created`).
4. `persist` explicit al societății noi: `LegalCase` nu are cascade spre `Debtor`.
5. **Cursa pe UNIQUE** (două taburi, aceeași societate nouă): `UniqueConstraintViolationException` anulează tranzacția. Controllerul o prinde, face `ManagerRegistry::resetManager()`, păstrează bag-ul și afișează `wizard.step4.flash.retry_submit` („Trimiterea nu a reușit. Trimite din nou.”). La retrimitere, societatea e găsită. De verificat înainte: `persistWizard` nu lasă efecte în afara bazei de date (fișiere mutate) la rollback.
6. Flash-ul `wizard.step4.flash.debtor_saved` („Debitorul a fost salvat în bibliotecă.” sau „Datele lipsă ale debitorului din bibliotecă au fost completate.”) apare lângă `creditor_reused` (1023), doar la succes.

**Scheletul tranzitoriu** (`buildLegalCaseSkeleton`, 1556-1565): construiește mereu o societate **nouă, negestionată**, din entry, plus un `LegalCaseDebtor` negestionat. Nu apelează niciodată repository-ul și nu atinge colecția inversă a unei societăți gestionate. Altfel, un flush în aceeași cerere (rezolvarea conflictelor) ar scrie în bibliotecă sau ar arunca „A new entity was found through the relationship”.

**Direcțiile de copiere:**
- din bibliotecă spre wizard: doar la alegere, doar identitatea;
- din wizard spre bibliotecă: la trimitere, doar în câmpurile goale, sau integral doar prin opțiunea explicită din D7;
- starea de verificare: întotdeauna doar din wizard spre `legal_case_debtor`;
- editările din `/debtors` se văd în regenerările ulterioare ale tuturor dosarelor societății, nu și în actele deja generate.

### 4.9 Garanții juridice

1. **Verificarea stă doar pe `legal_case_debtor`.** Societatea nu are nicio coloană de verificare, deci nimic nu se poate transfera la alt dosar.
2. **Alegerea din bibliotecă golește verificările pe server** (§4.7, pasul 3). Bifa BPI e goală și obligatorie, iar ANAF e nesincronizat.
3. **Schimbarea identității** golește atestarea, ANAF și `inInsolvency` pe calea de submit a controllerului (G, §2.2). Se aplică oricărui debitor, din bibliotecă sau nu.
4. **Actele reflectă ce a confirmat avocatul:** diferențele dintre entry și societate se văd și se decid explicit la Pasul 4 (D7). Rezumatul arată valorile care intră în acte.
5. **Instanța** se rezolvă din județul și localitatea din entry, ca la completarea manuală. Instanța altui dosar nu se copiază niciodată, iar cea stocată pe `legal_case.court_id` nu se schimbă la editarea societății.
6. **Actele deja generate** sunt fișiere stocate și nu se schimbă. Pagina de editare spune explicit că regenerările folosesc datele curente.
7. **Debitorul din somație** e `getPrimaryDebtor()` (poziția 0), determinist.
8. **Extracția AI** nu primește date din bibliotecă, iar selecția nu declanșează o extracție.
9. **Atestarea e o declarație a avocatului.** `bpiVerifiedToday ?? new \DateTimeImmutable()` o datează la trimiterea formularului, nu la verificarea reală. Punctul slab e existent și se tratează separat.

### 4.10 EasyAdmin

- **`DebtorCrudController`** (27-111), refăcut în R: delegarea nu ajută, pentru că filtrele construiesc DQL pe proprietăți mapate.
  - Adaugă câmpul și filtrul `user`, plus `cuiKey` doar de citire. Scoate câmpul și filtrul `legalCase`, câmpurile de verificare și filtrele `anafStatus`/`inInsolvency`.
  - Căutare pe name, cui, cuiKey, onrcNumber.
  - PJ la creare (D10).
  - DELETE dezactivat sau blocat prin aceeași gardă de utilizare.
- **`LegalCaseDebtorCrudController`** nou, doar citire: dosar, societate, poziție, `anafStatus`, `anafCheckedAt`, `inInsolvency`, `insolvencyCheckedAt`, `bpiVerifiedNote` și filtre pe `anafStatus`, `inInsolvency`, `legalCase`. Se leagă din `DashboardController.php:52`.
- `AdminAccessTest.php:211`: se adaugă ruta nouă.

### 4.11 Teste

**Unitare și de serviciu:**
- `DebtorEntityTest` (`cuiKey`, `__toString`, fără verificare) și `LegalCaseDebtorEntityTest` (delegare, verificare proprie, fără setteri de identitate).
- `LegalCaseEntityTest`: `addDebtor` atribuie poziția; `getPrimaryDebtor`.
- `CuiNormalizerTest` și testul de paritate PHP/SQL.
- `DebtorRepositoryTest`: filtrare pe user; `RO123`/`123`/`0123` dau aceeași cheie; `COUNT DISTINCT`; dosare soft-deleted excluse din contor, dar incluse în `countLinks`.
- `DebtorFirmResolverTest`:
  - R: creare 1:1;
  - L2: `debtorId` străin ignorat, `cuiKey` diferit ignorat, completare doar a câmpurilor goale;
  - L3: găsire după cheie, conflict de diferențe, opțiunea de actualizare auditată, câmpurile de verificare niciodată pe societate.
- `DebtorVoterTest`, `DebtorLibraryServiceTest`.

**Nerevenire pentru R (lipsa schimbării de comportament):**
- HTML-ul randat pentru somație, cererea OP și opis pe un dosar fixat e identic înainte și după R (snapshot comparat în test).
- Overview-ul și `/termene` au același număr de interogări.
- `OpAdmissibilityValidatorTest` trece pe `LegalCaseDebtor`, cu aceleași coduri.

**Controller:**
- `CaseWizardControllerStep1To4Test.php:558`: `findBy(['legalCase' => $case])` trece pe `LegalCaseDebtor` și verifică societatea (denumire, user).
- Noi:
  - verificarea nu se transferă la al doilea dosar cu aceeași societate;
  - pick cu verificări goale, 404 pentru id străin, CSRF lipsă respins, unpick restaurează, `debtorId` falsificat devine null;
  - cursa pe UNIQUE produce flash, nu 500.
- `DebtorLibraryControllerTest`: CRUD; duplicat cu 422 (inclusiv `RO` vs fără și zerouri); 403/404; ștergerea blocată pentru o societate folosită, reușită pentru una nefolosită; cardul „Dosare în care apare”.

**Construcția în teste:** 20 de apeluri `new Debtor(` în 16 fișiere trec pe un helper comun, `tests/Support/DebtorFixtures::attach(LegalCase, User, array $identity, array $verification = [])`, care creează societatea și legătura. Setterii de verificare se mută pe `LegalCaseDebtor`:
- `DebtorEntityTest:33-81`;
- `OpAdmissibilityValidatorTest:307-309`;
- `CaseOverviewControllerTest:185`.

`LegalDeadlineAgendaTest` (341-348, 380-387) creează doi debitori fără CUI pe același dosar. După M2, cheile NULL rămân distincte, deci testul rămâne valid.

**Ordinea de teardown** (18 fișiere cu `DELETE FROM debtor` brut):
1. Neschimbate: `audit_log`, `notification`, `document`, `legal_deadline`, `court_portal_event`, `case_status_history`.
2. `DELETE lcd FROM legal_case_debtor lcd JOIN legal_case lc ON lcd.legal_case_id = lc.id WHERE lc.user_id = ?`. E redundant cu CASCADE, dar se păstrează explicit.
3. `DELETE FROM legal_case WHERE user_id = ?`.
4. **Nou și obligatoriu:** `DELETE FROM debtor WHERE user_id = ?`. Fără el, FK-ul spre user blochează ștergerea userului.
5. `DELETE FROM creditor WHERE user_id = ?`.
6. `DELETE FROM user`.

**`SeedDemoCasesCommandTest.php:79-83`:** rulează pe userul comun de dev `avocat@test.com`. Șterge legăturile dosarelor `LR-DEMO-%`, apoi dosarele, apoi doar societățile cu `cui_key IN (cheile seed)` și `NOT EXISTS` o legătură, la fel ca ștergerea creditorului de la linia 83.

**Seed-ul** (`SeedDemoCasesCommand.php:38-90, 160-176`) primește `getOrCreateDemoDebtor(user, cui)`, ca `getOrCreateDemoCreditor`. CUI-urile demo care se repetă exersează reutilizarea. Rândurile PF devin PJ în A.

**Final, la fiecare slice:** `make test`, `make tailwind`, `make restart`, `lint:yaml translations/`, paritatea cheilor RO/EN și un grep pentru U+2014 în fișierele atinse.

## 5. Slice-uri de implementare și efort

| Slice | Conținut | Fișiere principale | Verificare live | Efort |
|---|---|---|---|---|
| C | Textul BPI | traduceri, `Step2DebtorsLiveComponent.html.twig`, `OpAdmissibilityValidator.php` (comentarii), `.claude/agents/...` | Pasul 2 fără link; mesajele de la Pasul 4 | 0,25 z |
| A + G | Debitor doar PJ; garda pentru sesiuni vechi; `CuiNormalizer`; garda de identitate | `Step2DebtorEntryType`, template-ul componentei, `PrefillFromExtractionService`, `CaseWizardController`, `SeedDemoCasesCommand`, `CuiNormalizer` | PF absent, POST manipulat respins, nota PF, sesiunea veche redirecționată, CUI schimbat cere bifa | 1,5 z |
| B | Un debitor; alegerea clusterului; conflict fără bifă în toate straturile | `Step2DebtorsData`, `PrefillFromExtractionService`, `PrefillConflict`, `ConflictResolutionService`, controller, template-uri | Blocarea pe facturi către debitori diferiți; alegerea cu fideiusor; recuperarea unei sesiuni vechi | 2,5 z |
| R | Societate + `LegalCaseDebtor` + M1 (1:1), API delegat, fetch join-uri, resolverul în modul „creează mereu”, schelet negestionat, admin refăcut, `ClaimItem`, seed, helper de test și teardown în 18 fișiere | §4.1-4.5, §4.10, §4.11 | Overview, `/termene`, somația, cererea OP și opisul arată identic; adminul pe ambele CRUD-uri; migrarea verificată în container | 2,5 z |
| L1 | Pagina `/debtors`, sidebar, voter, serviciu, grid; corectura subtitlului de la creditor (commit separat) | §4.6 | CRUD, sincronizare ANAF fără status salvat, ștergere blocată pentru o societate folosită | 1 z |
| L2 | Selectorul, pick/unpick cu restaurare, identitate doar de citire, `debtorId` hidden, sanitizare, skip pentru pin-uri, conflictul D8, reutilizare prin id | §4.7 | Alegere cu extracție activă, confirmarea Turbo, bifa goală, nepotrivirea de CUI, instanța din județul copiat | 1,25 z |
| L3 | M2 (deduplicare, UNIQUE), căutare după `cui_key`, conflictul de diferențe de la Pasul 4 (D7), cursa pe UNIQUE, flash și audit | §4.5 M2, §4.8 | Al doilea dosar cu același CUI reutilizează societatea; diferențele cer alegere; retrimiterea după cursă; `/debtors` fără duplicate | 1,5 z |
| **Total** | | | | **aproximativ 10,5 zile** |

Planul vechi estima aproximativ 7,5 zile. Diferența vine din B realist (+0,5 z), din refactorizarea modelului cu migrarea de date și cele 18 teardown-uri (R) și din conflictul de diferențe de la Pasul 4.

## 6. Ce rămâne pentru după lansare

**Debitori persoane fizice:**
- Codul care se păstrează:
  - `PersonType::PF` și coloanele PF din trait;
  - ramura PF din `Step2DebtorEntry` și din `Step1CreditorData`;
  - ramura PF din validator și `OP_PF_BIPF_MANUAL_CHECK`;
  - ramura de domiciliu din somație;
  - prompturile de extracție.
- Pentru reactivare: `choices`, cardurile radio și `person-type-toggle_controller.js`, recuperate din git (`e6c4a8e` și commit-ul A), plus `toPersonType` și conflictele pe CNP.
- Pentru bibliotecă, înainte de a accepta PF:
  - opt-in explicit pentru fiecare debitor;
  - temeiul legal: Legea 51/1995, cu avocatul ca operator și LexRecovery ca persoană împuternicită (art. 28 GDPR);
  - CNP mascat, cu excepția formularului de editare;
  - cheie UNIQUE `(user_id, personal_id)`;
  - retenție și purjare;
  - riscul #9 din auditul de securitate (CNP în clar la repaus).

**Debitori multipli:**
- Modelul e deja pregătit: N rânduri `legal_case_debtor` cu `position`.
- Pentru reactivare:
  - `MAX_DEBTORS` înapoi la 5;
  - butonul de adăugare;
  - `choose` devine `multiple` (INFO) pentru clusterele fără creanțe separate;
  - un selector pe fiecare card, cu cheia citită la selecție;
  - blocarea aceleiași societăți pe două carduri (UNIQUE `(legal_case_id, debtor_id)` o impune deja la persistare);
  - butonul de eliminare pe `entry.vars.name`;
  - problema `input` vs `change` din `party-anaf-lookup_controller.js:219-224`.
- Probleme deschise:
  - instanța se rezolvă doar din primul debitor (`CaseWizardController.php:1671, 1732`);
  - somația ajunge doar la `getPrimaryDebtor()`;
  - obligația solidară (Codul civil, art. 1443 și urm.) și împărțirea creanțelor pe debitori prin `ClaimItem.debtor`.

**Altele:**
- Arhivarea societăților folosite (D17).
- Cheia canonică pentru creditor.
- Unificarea celor patru normalizări ad hoc ale CUI-ului.
- Migrarea de curățenie pentru `debtor_merge_map` și tabelele de backup.

## 7. Riscuri

| Risc | Impact | Mitigare |
|---|---|---|
| Completarea doar a câmpurilor goale la reutilizare (D7) | Wizard-ul arată datele noi, dar actele folosesc adresa veche: bug-ul deja reparat la creditor (`CaseWizardController.php:1330-1340`) | Conflict blocant la Pasul 4 cu alegere explicită; rezumatul arată valorile care intră în acte |
| Regenerarea după editarea societății | Un act regenerat pentru un dosar vechi are datele noi | Compromis acceptat; textul de pe pagina de editare îl spune explicit; actele generate sunt fișiere stocate |
| Interogări în plus prin delegare | Overview-ul și `/termene` fac o interogare pe rând | Join spre `db.debtor` în cele 5 locuri; test pe numărul de interogări |
| Ordinea `first()` | Destinatarul somației nedeterminist la N > 1 | `getPrimaryDebtor()`, `OrderBy` și `addOrderBy` explicit în fetch join-uri |
| Canonicalizare divergentă PHP/SQL | Duplicate trecute prin UNIQUE sau M2 eșuat | O singură funcție PHP, expresie SQL identică, test de paritate |
| Migrare neatomică (DDL cu commit implicit) | Schemă parțial migrată | Verificări `abortIf`, tabele de backup, M1 separat de M2, rulare în dev și pe copie, verificare în container înainte de `migrate` |
| Migrare stale în container | Rulează alt SQL decât cel revizuit | `cat` în container înainte de `migrate` |
| Grupuri absorbite cu date diferite (3 din 6 în dev; unul PF/PJ) | O regenerare pe dosarul vechi tipărește datele supraviețuitorului | Regula D13, log de conflicte, `debtor_merge_map` păstrat pentru revizuire |
| Scheletul tranzitoriu atașează o societate gestionată | Scriere în bibliotecă din greșeală sau excepție la flush | Societate nouă, negestionată; fără repository în schelet; test |
| Cursa pe UNIQUE la trimitere | 500 și EM închis | Catch, `resetManager`, flash de retrimitere, bag păstrat |
| Ștergerea unei societăți folosite | Excepție de FK sau pierderea istoricului | Garda de utilizare, butonul nerandat, FK RESTRICT |
| Adminul rupt după R | 500 pe `/admin` la debitori | Adminul refăcut în R; `AdminAccessTest` |
| Teardown-uri incomplete | FK spre user blochează ștergerea; teste instabile | Ordinea din §4.11 în toate cele 18 fișiere; helper comun |
| Seed pe userul comun de dev | Testul șterge societăți reale ale avocatului de test | Ștergere doar pe cheile seed nereferite |
| Rânduri PF moștenite fără CUI | Apar în bibliotecă ca duplicate | Filtru PJ și `cuiKey` nenul în selector și în `/debtors` (D14) |
| Sesiuni vechi (PF, 2+ debitori, fără `debtorId`) | Dosar salvat în afara scopului sau eroare la deserializare | Garda unică din toate punctele de intrare; valori implicite pe proprietățile noi |
| Formularea Legea 85/2014 | Se schimbă sensul juridic al atestării | D6 confirmat înainte de merge-ul pentru C |
| Cache DI sau OPcache stale după o entitate nouă | „Unrecognized field” sau 500 | `make restart` după fiecare slice; restart php-fpm în demo |

## 8. Corecțiile din review-ul adversarial (2026-09-30): unde au ajuns

| Punct din review | Unde e tratat |
|---|---|
| D6 condiționează textul atestării | §2.1 (etichete și mesaje marcate „Condiționat de D6”), §1.4 |
| PFA, II și IF sub regimul PJ (D1) | §1.4 D1, blocant pentru A |
| Schimbarea clusterului nu schimbă debitorul din sesiune | §2.3, punctul 3 (reconstruire integrală și golirea verificărilor) |
| `acknowledgeable: false` în toate straturile | §2.3, punctul 4 |
| O singură gardă pentru sesiunile vechi; valori implicite în DTO | §2.2, A.5 |
| `$initialFormData` nu e `#[LiveProp]` | §2.2 G (garda pe calea controllerului), §4.7 (test de re-randare) |
| Mesaj neutru pentru D5; Codul civil, nu CPC art. 59 | §2.3, punctul 4; §6 |
| Mențiunea părților excluse la Pasul 4 | §2.3, punctul 6 |
| Atestarea e o declarație a avocatului | §2.1, §4.9 punctul 9 |
| `bpiVerifiedToday ?? now` | §4.9 punctul 9, tratat separat |
| Garda de identitate livrată odată cu A | slice-ul A + G |
| Test pentru `addCollectionItem` falsificat | §2.3, punctul 1 |
| Grep pentru `debtors[1]` în teste | §2.3, „Înainte de implementare” |
| Clusterizarea documentelor PF fără CUI | §2.3, „Înainte de implementare” |
| Cine câștigă între bibliotecă și clusterul ales | §4.7 (biblioteca pentru identitate, conflictul D8 vizibil, test) |
| Deselectarea nu șterge ce a tastat avocatul | §4.7, pasul 5 (`debtorBeforePick`) |
| Endpoint-ul Autocomplete limitat la user | §4.7, pasul 1 |
| Grep-ul pentru `bpi.just` include `docs/` și validators | §2.1, teste |
| Efortul pentru B | §2.3 și §5: 2,5 zile |
## 9. Review adversarial pe modelul normalizat (2026-09-30)

Ambii revieweri: NEEDS-CHANGES. Punctele au prioritate față de secțiunile de mai sus.

### Juridic (blocante)
1. **Regenerarea din date comune.** `court_id` se calculează o singură dată, la trimitere, din județul și localitatea debitorului (`CaseWizardController.php:1174`, `1668-1740`). Cererea OP, opisul (`CasePaymentOrderController.php:145-146, 235`) și somația (`SummonsContextBuilder.php:100`) se regenerează însă din datele curente ale debitorului. O editare a societății în `/debtors` face ca un act regenerat într-un dosar vechi să poarte alt sediu decât cel după care s-a stabilit instanța. De decis: blocarea editării câmpurilor de identitate (denumire, CUI, adresă, județ, localitate) pentru o societate folosită în dosare cu acte generate, sau avertizare explicită plus audit pe fiecare dosar afectat.
2. **Deduplicarea datelor existente (M2) schimbă identitatea debitorului în dosare vechi.** În dev, 3 din 6 grupuri cu același CUI diferă pe denumire sau adresă, iar unul amestecă PF cu PJ. `PortalCaseMatcher.php:57-63` potrivește dosarele de instanță după denumirea curentă, deci o denumire schimbată poate rupe sau greși potrivirea (cu tranziții automate). Recomandare: M2 NU comasează rândurile existente; fiecare debitor existent devine o societate proprie, iar deduplicarea se aplică doar de acum înainte.
3. **A și C nu se comit înainte de răspunsul avocatului la D1 și D6.**

### Juridic (avertismente)
- Debitor ales din bibliotecă fără sincronizare ANAF în dosar: ERROR sau blocare la Pasul 4, nu doar nota de reverificare.
- La D7 („folosește datele din bibliotecă”), instanța se calculează din valorile finale care intră în acte, după rezolvarea conflictului.
- Garda de identitate invalidează și instanța calculată când se schimbă județul sau localitatea.
- Dosarele soft-deleted: aceeași protecție ca la blocantul 1; excluse din alegerea supraviețuitorului.
- Cursa pe UNIQUE la trimitere: de verificat că rollback-ul nu lasă fișiere orfane și nu consumă slot de abonament.
- Citarea „art. 5 din Legea 85/2014” se verifică la sursă.

### Tehnic (blocante)
1. **Inventarul e incomplet.** Referă debitorul și: `CompetentCourtResolver`, `StampDutyUatResolver`, `StampDutyService`, `RomanianAddressNormalizer`, `DeadlineService`, `CaseAutoFinalizer`, `PaymentOrderRequestGeneratorService`, `UploadDeduplicator`, `ClaimItemDeduplicator`, `ConflictChoiceApplier`. Cele cu type-hint pe `App\Entity\Debtor` dau TypeError după R. Inventarul se refac complet înainte de R.
2. **`|first` pe `case.debtors` în 6 template-uri** (`_hero`, `_party_card`, `deadlines/_row`, `_rail_prescriptions`, `_modal_add_deadline`, `_blockages`): `#[OrderBy]` e ignorat la hidratarea cu fetch join. Se folosește `getPrimaryDebtor()`.
3. **Migrarea M1:** pre-check (`abortIf`) înainte de `user_id NOT NULL`; migrarea MySQL nu e tranzacțională.
4. **`M1.down()`** e invalid după ce există societăți folosite în mai multe dosare; `down()` se oprește cu `abortIf` dacă o societate are mai mult de o legătură.
5. **Mapping Doctrine:** nimic din cod nu trebuie să șteargă societatea ca efect secundar al reconstruirii debitorilor unui dosar (de re-auditat `persistWizard`).
6. **Paritatea PHP/SQL pentru `cui_key`:** setul de test include spațiu neseparabil (U+00A0), tab, „ro” cu litere mici, „RO” singur și „0”.

### Tehnic (avertismente)
- Rândurile PF fără CUI (cheie NULL) rămân orfane după ștergerea definitivă a dosarului; cale de curățare sau ștergere împreună cu dosarul.
- Rate limit și test de izolare per utilizator pentru endpoint-ul de autocomplete și pentru ruta de alegere.
- Entitățile nu intră niciodată în sesiunea wizard-ului; snapshot-ul de dinaintea alegerii e DTO.
- R înainte de B ar evita rescrierea de două ori a controllerului; altfel testul de neutralitate al lui R rulează pe comportamentul final al lui B.
- Teardown-urile din teste: de căutat și `INSERT INTO debtor` brut, nu doar `new Debtor(`.
- Efortul pentru R (2,5 zile) e probabil subestimat; realist 3,5-4 zile. Totalul realist: aproximativ 12 zile.

## 10. Decizii luate după review (2026-09-30)

1. **Editarea unei societăți folosite în dosare (blocantul juridic 1 din §9): varianta B.**
   - Editarea din `/debtors` rămâne permisă pentru toate câmpurile.
   - La salvare, dacă societatea apare în dosare, avocatul vede: „Această firmă apare în N dosare. Actele deja generate nu se schimbă, dar o regenerare va folosi noile date.” Schimbarea câmpurilor de identitate (denumire, CUI, adresă, județ, localitate) se înregistrează în auditul fiecărui dosar afectat, cu valorile vechi și noi.
   - La regenerarea somației, a cererii OP sau a opisului, aplicația recalculează instanța din sediul curent al debitorului. Dacă diferă de `legal_case.court_id`, avocatul vede un avertisment înainte de regenerare și decide explicit (regenerează sau renunță). Decizia se auditează.
   - Efort suplimentar: aproximativ 0,5 zile, în L1.
2. **Datele existente nu se comasează (blocantul juridic 2 din §9).** Migrarea transformă fiecare rând `debtor` existent într-o societate proprie, cu legătura lui `legal_case_debtor`. M2 nu mai deduplică datele existente. Indexul UNIQUE `(user_id, cui_key)` se aplică doar rândurilor create de acum înainte: fie printr-o coloană generată care e NULL pentru rândurile migrate (de exemplu `legacy = 1`), fie prin verificare în serviciu, de ales la implementare. Reutilizarea după CUI la trimitere caută doar printre rândurile nemigrate sau, dacă avocatul alege explicit din bibliotecă, printre toate.
3. **Rămân la avocat:** D1 (regimul PFA, II, IF) și D6 (sensul „procedurilor prevăzute de Legea 85/2014”), care condiționează commit-ul pentru C și A.

## 11. Stare implementare (2026-09-30)

Toate slice-urile sunt implementate pe `feature/biblioteca-debitori`, necomise. Suita completă: 2870 teste verzi (1 sărit, preexistent). Fiecare slice a trecut prin review tehnic și juridic, cu corecturile aplicate, și prin verificare live în dev.

| Slice | Stare | Verificare live |
|---|---|---|
| C: text BPI | gata | textul avocatului, fără link |
| A+G: doar PJ, garda de identitate | gata | carduri PF ascunse (logica PF păstrată, și la creditor) |
| B: un singur debitor | gata | alegere explicită a părții când documentele numesc mai mulți debitori |
| R: `debtor` + `legal_case_debtor` | gata, migrare `Version20260930150000` | dev și test migrate; demo NEmigrat |
| L1: `/debtors` | gata | editare cu avertisment, audit pe dosare, confirmare la cererea OP |
| L2: selector în Pasul 2 | gata | dosarul 58 refolosește firma 58 |
| L3: salvare la trimitere | gata | dosarul 59 refolosește firma 57, fără copie |

Reguli L3, așa cum au rămas după review:
- Un debitor cu CUI existent în biblioteca avocatului se leagă de firma existentă (a altui avocat, niciodată). Dacă datele diferă, Pasul 2 întreabă per debitor: datele din bibliotecă sau actualizarea firmei.
- O firmă deja somată (un dosar cu status diferit de AMIABIL) nu se modifică din wizard, nici prin actualizare, nici prin completarea câmpurilor goale. Regula se verifică din nou la Pasul 4 și la trimitere. Corectura se face din `/debtors`, unde e auditată și cere confirmare la generarea cererii OP.
- Județul și localitatea se completează din pas doar împreună cu adresa lor, fiindcă decid instanța.
- Auditul `wizard_submit` notează sursa: `new`, `library` sau `library_updated`.
- Abatere de la §10.2: reutilizarea după CUI caută printre toate firmele avocatului, inclusiv cele migrate. Nu se comasează nimic automat; dacă datele diferă, decide avocatul în panou.

Rămase:
- Commit (doar la confirmarea utilizatorului), merge în `lexrecovery`, apoi `make migrate` pe demo prin `demo-sync` și `demo.sh restart php worker`.
- D1 și D6 la avocat (condiționează C și A).
- Fix-ul global Turbo + nonce CSP (azi ocolit cu `data-turbo=false` pe formularele bibliotecilor).
- W4 juridic, amânat: o somație trimisă în afara platformei, cu dosarul încă la AMIABIL, nu declanșează refuzul.

### 11.1 Comasarea copiilor vechi (decizia utilizatorului, 2026-09-30)

Decizia §10.2 (fără comasare) a fost schimbată: rândurile aceluiași avocat cu același CUI și același nume se comasează în rândul modificat cel mai recent; dosarele se mută pe el, iar fiecare dosar mutat primește în istoric `debtor_identity_changed` (valori vechi și noi) și `debtor_merged`. Același CUI cu alt nume nu se comasează (NEXT GEN MEDICAL SOLUTIONS SRL poartă CUI-ul LH CONSULTANCY, probabil greșit). Făcută o singură dată, fără cod permanent în aplicație (producția nu are date vechi): în dev 5 grupuri, 14 rânduri (backup `var/backup-pre-merge-debtors-20260930.sql`); pe demo o singură pereche, cu SQL-ul din `DEMO-COMASARE-DEBITORI.sql`, de rulat după migrare, la merge.

## 12. Review final pe tot branch-ul (2026-10-01)

Tehnic COMMIT-READY, juridic LEGAL-NEEDS-FIX (blocant doar SQL-ul de demo). Rezolvate după review:
- SQL-ul de comasare pentru demo: toate câmpurile de identitate în istoric, fiecare pas condiționat de aceeași firmă și același avocat, sigur la o a doua rulare (testat pe o bază temporară).
- Backup obligatoriu înainte de migrare, notat în `DEMO-STACK.md`.
- Comentarii învechite (debitor prin id, bifa de insolvență în admin).
- La Pasul 4, o firmă introdusă manual care a ajuns între timp în bibliotecă: legată dacă datele coincid, altfel avocatul e trimis la Pasul 2 să aleagă (fără rând dublu la salvare).
- Etichetele „BPI” rămase în textele pentru avocat, aliniate la Legea 85/2014.
- 404 pentru rândurile vechi de persoană fizică în `/debtors/{id}/edit`; permisiunea VIEW nefolosită scoasă.
- Teste: ștergere cu token invalid, sesiune veche de wizard, firmă ajunsă în bibliotecă după Pasul 2.

De discutat cu avocatul:
- C1 avertisment și confirmare și la regenerarea somației după o editare în bibliotecă (concordanța somație/cerere).
- C2 buton „Reconfirm” pentru atestarea Legea 85/2014 în locul indicației „debifează, salvează, bifează din nou”.
- C3 forma juridică pentru PFA, II, IF (D1).
- C4 coloana `in_insolvency` (nescrisă de nimeni, citită de validator): scoasă complet sau legată de o acțiune pe dosar.

După lansare: numărarea dosarelor în lista bibliotecii fără N+1; avertismentele din fereastra cererii OP calculate doar când acțiunea e disponibilă; test pentru mutarea datelor din migrare; logica bibliotecii mutată din controller într-un serviciu.

## 13. Creditorul aliniat la regulile debitorului (2026-10-01, branch `feature/creditor-aliniere-debitor`)

- `creditor.cui_key` (CUI normalizat) + migrare `Version20261001120000` (completare SQL, oprire dacă un avocat are același CUI scris în două feluri, unicitate pe `(user_id, cui_key)`).
- `CreditorLibraryService`: dubluri după cheie, CUI blocat după somație, editare și completare cu istoric pe fiecare dosar (`creditor_identity_changed`).
- `/creditors`: dubluri refuzate oricum ar fi scris CUI-ul, avertisment „apare în N dosare”, CUI blocat după somație.
- Pasul 1: creditor tastat/extras existent în bibliotecă se leagă dacă datele coincid; altfel panou bibliotecă/actualizare (actualizarea refuzată după somație). Suprascrierea tăcută la salvare a dispărut. Revenirea în Pasul 1 după „actualizează” păstrează datele tastate și întreabă din nou.
- Pasul 4: creditorul ales arată datele curente din bibliotecă (strict, pentru un creditor somat); unul apărut în bibliotecă după Pasul 1 se leagă sau trimite înapoi la Pasul 1.
- Cererea de ordonanță: modificările creditorului după prima somație (nume, CUI, ONRC, adresă, județ, localitate, reprezentant, IBAN) se listează și cer aceeași confirmare ca la debitor; IBAN-ul schimbat are avertisment separat (debitorul a fost somat să plătească în alt cont).
- Demo: migrarea rulează la următorul `demo-sync`; pe demo nu există dubluri de creditor în același cont, deci `abortIf` nu se declanșează.
