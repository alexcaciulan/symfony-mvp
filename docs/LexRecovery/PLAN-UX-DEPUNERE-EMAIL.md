# Plan: redesign UX pentru depunerea la instanță prin e-mail (filă dedicată)

Data: 24.10.2026. Branch: `feature/trimitere-email-instanta`.
Surse: `docs/LexRecovery/PROPUNERE-UX-DEPUNERE-EMAIL.md` (inclusiv secțiunea 8, Decizii), machetele din `docs/LexRecovery/mockups/v2/depunere/`, capturile din `docs/LexRecovery/screenshots/verificare-live-email-instante-2026-10-24/`, verificarea juridică a secțiunii.

## 1. Decizii (înainte de cod)

| # | Decizie | Motiv |
|---|---|---|
| D1 | Filă nouă `depunere` („Depunere la instanță”) între „Documente” și „Termene”, lățime completă, vizibilă când dosarul este în `CERERE_GENERATA` sau are pachet ori transmitere, ascunsă după `fullPaymentDate` (aceeași regulă ca acum pentru card). | Decizia 1 a proprietarului. Regula de ascundere rămâne cea din `_tab_documente.html.twig:28`. |
| D2 | În „Documente” rămâne doar cardul compact (macheta j): o linie de stare, o propoziție, butonul „Deschide depunerea”. `_zip_package_card` rămâne neschimbat. | Decizia 1. |
| D3 | Starea panoului se calculează server-side într-un serviciu nou `FilingPanelStateResolver` (fără DB, testabil ca unitate), nu în Twig. Ordinea de prioritate este cea din `_submission_card.html.twig:43-62`: late_review > stalled cu cererea acceptată > NEEDS_REVIEW după cerere > petition_returned_closed > CLOSED_PARTIAL > retrying > receipt > fără receipt > status. | Modelul de stare din secțiunea 3 a propunerii; regula juridică: nicio stare afișată doar în JS. |
| D4 | Confirmarea trimiterii devine pagină completă (`extends base.html.twig`) pe aceeași rută `case_filing_submission_confirm`. Aceleași verificări, CSRF `filing_submit_{id}`, voter `FILING_CONTENT` + `TRANSITION`, rate limit, două declarații nebifate (`filing.send.*` textual), `require-checks`. E-mailul se afișează integral, fără casetă cu derulare. | Decizia 2. |
| D5 | Trimiterea reușită de pe pagina de confirmare răspunde cu redirect 303 la `case_overview?tab=depunere` plus flash `filing.send.flash_queued`, inclusiv pentru cereri Turbo. Refuzurile rămân turbo-stream pe `filing-send-panel` (care există acum pe pagina de confirmare). | Pe pagina nouă nu există ținta `filing-package-card`; altfel avocatul ar rămâne pe pagina de confirmare (R1). |
| D6 | Data depunerii se completează în panoul de stare: același formular și aceeași rută `case_filing_submission_filed_date`, aceeași validare. Câmpul precompletat poartă eticheta „propusă din confirmare”; nu se salvează automat. | Decizia 3 și blocantul juridic 3. |
| D7 | Rută nouă `GET /case/{id}/filing/package/{packageId}/unsigned.zip` (`case_filing_package_unsigned_zip`), doar pentru avocatul dosarului (voter `FILING_CONTENT`, evaluat înaintea bypass-ului de admin, deci admin 403), doar pentru pachetul curent în `BUILT`, `AWAITING_SIGNATURES` sau `SIGNED`, cu audit și tranziția `BUILT` → `AWAITING_SIGNATURES` ca la descărcarea individuală. Construcția arhivei într-un serviciu nou `UnsignedPackageArchiveBuilder`. | Decizia 4 a proprietarului; majorul juridic 7. |
| D8 | Butonul din antet (hero) în `CERERE_GENERATA`: dacă există pachet sau transmitere, devine link `?tab=depunere#depunere-status` cu eticheta din panou și nu execută nimic. Fără pachet și fără transmitere rămâne butonul actual de confirmare manuală (`#hs-modal-confirm-filing`). În `CERERE_DEPUSA` antetul se schimbă doar dacă filing-ul este EMAIL și (data depunerii lipsește sau `late_review`); altfel rămâne acțiunea de portal. | Implicit ales (C1, C2): păstrează depunerea manuală pentru dosarele care nu folosesc e-mailul și nu strică testele de portal. |
| D9 | „Declară depunerea pe altă cale” (deschide `#hs-modal-confirm-filing`, deja randat o dată în `overview.html.twig`) apare ca acțiune secundară în filă pentru: fără pachet, `UNDELIVERED`, eșecuri, `CANCELLED`, deblocare admin. Acțiunea recomandată din „Detalii” rămâne, ascunsă doar cât transmiterea este activă. | Depunerea manuală rămâne accesibilă după ce antetul duce la filă. |
| D10 | Declarația F1 (`filing.undelivered.ack_statement`) și `filing.undelivered.deadline_warning` rămân textual neschimbate. Bifa F1 devine vizibilă, `required` în HTML, cu `require-checks`, direct deasupra butonului. Nu se adaugă validare server-side (ar fi schimbare de logică și ar strica `CaseFilingReceiptUploadTest.php:339`). Se schimbă doar `ack_cta` („Confirmă verificarea”) și `late_headline`. | Blocantul juridic 4, C3, C7. |
| D11 | Propoziția scurtă vizibilă despre prescripție este, literal, prima propoziție din `deadline_warning`: „Aplicația nu prelungește termenul de 6 luni pentru menținerea întreruperii prescripției (NCC art. 2540).” Se obține dintr-o cheie nouă `filing.panel.legal.deadline_short` cu exact acest text (în EN: prima propoziție din EN), plus textul complet în „Note juridice”, deschise automat la `UNDELIVERED`. Chenar roșu în panou. | Decizia 6, blocantul juridic 4, observația „Termene vizibile”. |
| D12 | Titlul `UNDELIVERED` „Cererea nu a ajuns la instanță: adresa nu există” apare numai când codul extins al raportului este 5.1.1, 5.1.2, 5.1.3 sau 5.4.4; altfel „Cererea nu a ajuns la instanță”. Se expune un helper public static în `SmtpFailureClassifier` (`isUnknownMailboxStatus(?string $enhanced)`) folosit de rezolver; logica de clasificare existentă nu se schimbă. | Blocantul juridic 2. |
| D13 | „Nu am primit copia în CC”: textele descriu codul actual (`UncertainPartResolver::declareNotReceived`, `FilingPartOutcomeApplier.php:332-344`, `FilingResumeService::isResumable`). Pentru cerere (partea 1): „Declarația avocatului: copia în CC nu a fost primită. Transmiterea s-a încheiat fără confirmarea serverului; anexele în așteptare au fost anulate. Pachetul semnat rămâne valabil, iar dosarul a rămas la «Cerere generată».” Acțiune principală „Verifică și trimite” (dacă verdictul permite), secundară „Declară depunerea pe altă cale”, plus fraza de precauție despre dubla depunere din `filing.uncertain.*` (dacă există) sau din `filing.retry.deadline_warning`, în Note juridice. Pentru anexă: transmiterea rămâne activă (`PARTIALLY_SENT`), acțiuni „Trimite anexele rămase” și „Închide fără anexele rămase”. Detecția: `lastError === 'filing.uncertain.not_received'`; pentru override admin `lastError === 'filing.override.failed'`. Nu se scrie „netrimis” sau „nu a ajuns”. | Decizia 4, blocantul juridic 5, C4. |
| D14 | Două carduri egale pentru „Am primit copia în CC” și „Nu am primit copia în CC”, fiecare cu bifa, butonul și consecința proprie (rutele și cheile de confirmare existente). Testid `filing-submission-uncertain-actions` păstrat. | C6; macheta h folosea un singur formular, dar codul are două rute. |
| D15 | Deblocarea de către admin se afișează avocatului doar din date existente: încercarea `FilingAttemptSource::ADMIN_OVERRIDE` (motivul fără prefixul „Admin override: ”). Pentru închiderea sau anularea de către admin fără încercare, panoul afișează starea rezultată (CLOSED_PARTIAL / CANCELLED) fără motiv. Nu se adaugă interogări noi pe audit. | C5; minimizează schimbările și nu expune audit log-ul avocatului. |
| D16 | `flash_success_filed` se schimbă în „Data depunerii a fost salvată. Termenul de 6 luni al întreruperii prescripției (NCC art. 2540) se închide.” (sensul juridic rămâne; „înregistrată” sugera înregistrarea la instanță). | Întrebarea juridică 3, varianta recomandată; păstrează fraza despre termen. |
| D17 | Testid-urile existente se păstrează pe noul markup unde sensul este același. `#filing-package-card` devine id-ul wrapper-ului din fila nouă (conținutul filei), deci selectorii `#filing-package-card ...` din teste rămân valabili; cardul compact din „Documente” primește `#filing-documents-card`. | Păstrează ~16 referințe de test și ținta stream-urilor existente. |
| D18 | Textele noi stau într-un namespace nou `filing.panel.*` (titluri, explicații, acțiuni, etape, detalii tehnice, note juridice). Cheile vechi folosite de notificări și admin rămân. Cheile vechi folosite doar în șabloanele înlocuite se șterg numai dacă nu mai sunt referite (verificare cu grep și `debug:translation --only-unused` restrâns la `filing.`). | C8; evită ruperea notificărilor. |

Ce nu se schimbă: logica de trimitere, eșecuri, mesaje primite, reîmpărțire, starea dosarului, entitățile. Nu există migrare.

## 2. Pași de implementare (în ordine)

### Pas 1. Entitate și migrare

Nicio modificare. Toate datele necesare există (`FilingPackage`, `FilingSubmission`, `FilingEmailPart`, `FilingAttempt`, `InboundMail`). Criteriu: `doctrine:schema:validate` rămâne verde.

### Pas 2. Servicii

1. `src/Service/Filing/FilingPanelState.php` (DTO readonly) și `src/Service/Filing/FilingPanelStateResolver.php`.
   - Intrare: `LegalCase`, pachetul curent, transmiterea, plus flag-urile deja calculate în `OverviewContextBuilder` (`stalled`, `uncertain_part`, `failure`, `late_review`, `verdict`, `gate_blocker`, `receipt_proposal`, `resumable`, `closable`, `resplittable`, `cancellable`, `can_set_filed_date`, `receipt_uploadable`, `undelivered_deadline_open`, `is_owner`). Se trimit ca un value object de intrare (`FilingPanelInput`), ca rezolverul să fie pur.
   - Ieșire: `code` (ex. `no_package`, `blocked`, `stale`, `to_sign`, `ready`, `send_blocked`, `sending`, `retrying`, `exhausted`, `permanent_address`, `permanent_other`, `size`, `internal`, `cc_not_received_petition`, `cc_not_received_annex`, `admin_failed`, `stalled`, `closed_partial`, `uncertain`, `cancelled`, `sent_no_receipt`, `receipt_overdue`, `receipt_received`, `receipt_manual`, `filed`, `undelivered`, `late_review`, `petition_returned_closed`), `tone` (`blue|green|amber|red|neutral`), `titleKey` + `titleParams`, `explanationKey` + `params`, `primaryAction` și `secondaryActions` (coduri de acțiune, max 2), `steps` (4 etape cu stare `done|current|problem|todo` și cheie de rezumat + parametri), `tabDot` (`null|blue|amber|red`), `legalNotesOpen` (true doar la `undelivered`), `legalNoteKeys` (lista notelor relevante), `criticalNoteKey` (prescripție la `undelivered`, precauția de termen la eșecuri/reîncercări).
   - Prioritatea: întâi stările de transmitere în ordinea din `_submission_card.html.twig:43-62`, apoi stările de pachet (fără transmitere activă sau după `CANCELLED`/eșec fără reluare).
   - `filed` doar când `case.filedAt` există și nu este `late_review`; titlul conține „(dată declarată)”.
   - Acțiunile cu descărcare (`.zip`, arhivă pentru altă cale, `.eml`) se omit când `is_owner` este false (admin).
2. `src/Service/Filing/SmtpFailureClassifier.php`: helper public static `isUnknownMailboxStatus(?string $enhanced): bool` cu lista 5.1.1, 5.1.2, 5.1.3, 5.4.4; `rejectsRecipient()` îl refolosește. Fără schimbare de comportament.
3. `src/Service/Filing/FilingPartDownloadName.php` (sau metodă statică pe un helper existent): logica `downloadName()` din `CaseFilingController.php:291-297` mutată aici, folosită de controller și de arhivă.
4. `src/Service/Filing/UnsignedPackageArchiveBuilder.php`: primește pachetul curent, scrie un `.zip` temporar cu `\ZipArchive` din `$uploadsDir` (modelul `CaseFilesPackager.php:29-61`), intrările numite cu sufixul `_nesemnat`, întoarce calea; ridică excepție dacă un fișier lipsește. Auditul se face în serviciu prin `AuditLogService` (`filing_package_unsigned_archive_downloaded`, `CATEGORY_FILING_PACKAGE`, versiunea, rol/volum/sha256 per fișier) și tranziția `BUILT` → `AWAITING_SIGNATURES` reutilizează metoda folosită la descărcarea individuală.
5. `src/Service/Case/OverviewContextBuilder.php`: adaugă `filing_panel` (rezolverul), `filing_tab_visible`, `filing_court_live` și `filing_recipient_override` din `RecipientOverrideGuard`, `filing_admin_override_reason` (din ultima încercare `ADMIN_OVERRIDE`, D15). Include `tabDot` în `filingStateKey()` ca punctul să se reîmprospăteze când se schimbă doar panoul.

### Pas 3. Controllere

1. `CaseFilingSubmissionController`:
   - `confirm()` randează `case/filing/confirm.html.twig` (pagină completă) cu `confirmContext()`, extins cu starea semnăturii fiecărui atașament (`isSignatureAccepted()` / `getSignatureStatus()`), adresa efectivă și cea din producție în mediul de test.
   - `submit()` la succes: `addFlash` + `redirectToRoute('case_overview', [id, tab => depunere], 303)` pentru toate cererile (D5). Refuzurile: `panelError` neschimbat ca mecanism.
   - `respondCard()` și `panelError()`: `tab => depunere` în loc de `documente`; `respondCard` țintește noile regiuni (vezi Pas 4, `_package_turbo_stream`).
   - `card()` randează noul frame al filei (același id `filing-submission-card`).
   - `stateStream()` rămâne; șablonul de regiuni adaugă cardul compact și punctul.
2. `CaseFilingController`:
   - `respond()` redirect cu `tab => depunere`.
   - acțiunea nouă `unsignedZip()` (D7): `denyAccessUnlessGranted(FILING_CONTENT)`, pachetul trebuie să fie `findCurrentForCase()` și în stările permise, altfel 404; răspuns `BinaryFileResponse` `application/zip`, `deleteFileAfterSend(true)`, nume `pachet_v{N}_nesemnat.zip`; rate limit refolosit de la descărcări dacă există, altfel niciunul (ca la descărcarea individuală).
3. `Admin/FilingSubmissionOverrideController::show()`: trimite șablonului care cale se aplică (`late_review`, `cancellable`, `closable`), după aceleași condiții ca `FilingSubmissionService::closeByAdmin` (:301-333), pentru textul de efect exact.
4. Opțional, fără risc: linkurile din `FilingNotifier.php:274` și `InboundForwarder.php:70` primesc `tab=depunere`.

### Pas 4. Șabloane

1. `templates/case/overview.html.twig`: `depunere` în lista de tab-uri (:37); `<div id="panel-depunere">` între documente și termene, randat mereu (gol și ascuns când fila nu e vizibilă) ca stream-urile să aibă țintă.
2. `templates/case/overview/_tabs_nav.html.twig`: fila condiționată de `filing_tab_visible`, cu `<span id="filing-tab-dot">` (randat mereu, gol când nu cere acțiune).
3. `templates/case/filing/_tab.html.twig` (nou, wrapper `id="filing-package-card"`, D17):
   - `_stepper.html.twig`: 4 etape, nu e clicabil.
   - coloana principală (2/3): frame-ul `filing-submission-card` (poll, aceleași atribute `data-poll-frame-*`, `data-status`, `data-stalled`) care conține `_status_panel.html.twig` (`id="depunere-status"`), apoi `_parts_to_sign.html.twig` (stările de pachet; rândurile de fișier din `_package_card` :134-238 cu upload, confirmare, rebuild peste buget, cip de semnătură „semnătură detectată”, nota de validitate per parte) sau `_email_timeline.html.twig` (un rând per e-mail), apoi `_inbound_mail_list.html.twig` (un rând per mesaj; extrasul doar pentru proprietar; nota `auth_note` mutată o dată în Note juridice), apoi `_technical_details.html.twig` (`<details>` cu coduri SMTP, încercări `filing-submission-attempts-N`, `.eml`, raportul brut `filing-submission-undelivered-diagnostic`, `filing-submission-failure-code`, destinatarul efectiv, nota despre ora României). Toate formularele din frame au `data-turbo-frame="_top"`.
   - coloana dreaptă (1/3): `_court_block.html.twig` (instanța, adresa monospace, testid-urile `filing-recipient*`, mențiunea „Mediu de test”, link „Adresele instanței”), `_filed_date_block.html.twig` (data declarată sau „Nedeclarată”), `_legal_notes.html.twig` (`<details>` cu `open` din `legalNotesOpen`, doar notele din `legalNoteKeys`, disclaimerul general o singură dată, DSS link `rel="noopener noreferrer"`).
   - Panoul de stare: titlu, explicație, o acțiune principală, max două secundare, notă critică opțională. Formulare existente reutilizate: construire/reconstruire, data depunerii (`filing-filed-date-form`, `#filing-filed-at`, `filing-filed-date-proposal`, eticheta „propusă din confirmare”), încărcare confirmare (`filing-receipt-upload-form`), reluare, reîmpărțire, F1 (`filing-submission-late-bounce-ack` cu bifa `ack_statement`), declarațiile CC (două carduri), anulare. „Închide fără anexele rămase” deschide un `<dialog>` prin `dialog_controller` (nu Preline) cu bifa și butonul roșu. Testid-urile din testele existente rămân pe aceleași elemente.
4. `templates/case/filing/_documents_compact_card.html.twig` (nou, macheta j, `id="filing-documents-card"`): titlul stării din panou, o propoziție, link „Deschide depunerea” (`?tab=depunere`, `data-controller="activate-tab"` sau link simplu). Înlocuiește includerea `_package_card` în `_tab_documente.html.twig:28-33`, cu aceeași condiție.
5. `templates/case/filing/confirm.html.twig` (nou, macheta b): breadcrumb spre dosar (`?tab=depunere`), subtitlu cu ireversibilitatea, stânga previzualizarea e-mailului (Către, De la, Copie și răspuns, Subiect, corpul integral, atașamentele cu „semnătură detectată”), dreapta sticky: rezumat (instanță, adresă, taxa în formularea actuală), avertismente, `<turbo-frame id="filing-send-panel">` cu declarațiile, CSRF, butonul „Trimite la instanță” și „Renunță” (spre `tab=depunere`), bannerul de dev. Testid-urile `filing-send-dev-banner`, `-competence`, `-address-meta`, `-subject`, `filing-send-confirm-*` păstrate. `_send_modal_turbo_stream.html.twig` randează doar fragmentul panoului (partial extras `_send_confirm_panel.html.twig`). `_send_modal.html.twig` se șterge după ce nu mai e referit.
6. Turbo-stream-uri:
   - `_package_turbo_stream.html.twig`: replace `filing-package-card`, `filing-documents-card`, `filing-tab-dot`.
   - `_state_regions.html.twig`: adaugă `filing-documents-card` și `filing-tab-dot`.
   - `_documents_actions_turbo_stream`, `_documents_generate_turbo_stream` (aici apare fila: replace și `case-tabs-nav` cu `active_tab` transmis), `_stamp_duty_turbo_stream`, `_termene_actions_turbo_stream`, `_full_payment_turbo_stream` (fila dispare): fiecare `update panel-documente` primește și `update panel-depunere` plus `filing-tab-dot`/nav unde nav-ul se înlocuia deja.
7. `templates/case/overview/_hero_actions.html.twig`: D8. Link cu `href="?tab=depunere#depunere-status"`, eticheta `filing.panel.hero.<code>` (sau o cheie generică „Deschide depunerea”), fără `form` și fără `data-hs-overlay`.
8. `templates/case/overview/_recommended_actions.html.twig`: când există pachet sau transmitere, o linie cu titlul panoului și link spre filă; acțiunea de confirmare manuală rămâne cu regula actuală.
9. `templates/court/address/index.html.twig` + `_list.html.twig` (macheta i): titlul este numele instanței, un rând de intro, cardul „Adresa folosită la depunere” (stare, raportare), tabelul tuturor adreselor, formularul de sugestie, regulile pliate la final. `#court-address-list` și testid-urile `court-address-*` păstrate; breadcrumb spre `tab=depunere`.
10. `templates/admin/filing_submission/override.html.twig`: efectul fiecărei căi scris înainte de buton (exact după `closeByAdmin`; „Deblocarea nu declară primirea și nu schimbă data depunerii.”), `<select>` + „Aplică” înlocuit cu butoane `name="outcome" value="failed"` („Marchează ca netrimis”) și `value="accepted"` („Marchează ca trimis”). Rămâne română hard-codată (traducerea adminului e în afara scopului).
11. `make tailwind`, apoi `make restart`.

### Pas 5. Traduceri

`translations/messages.ro.yaml` și `messages.en.yaml`, în paralel:
- `case_overview.tab.filing`: „Depunere la instanță” / „Court filing”.
- `filing.panel.*`: `step.*` (Pachet, Semnare, Trimitere, Data depunerii + rezumate), `title.<code>`, `explanation.<code>`, `action.*` (Construiește pachetul, Reconstruiește pachetul, Descarcă fișierele nesemnate (.zip), Verifică și trimite, Trimite din nou, Trimite anexele rămase, Închide fără anexele rămase, Reîmparte anexele, Salvează data depunerii, Încarcă o confirmare, Confirmă verificarea, Declară depunerea pe altă cale, Descarcă arhiva pentru altă cale, Deschide depunerea), `timeline.*`, `inbound.*` (inclusiv starea goală), `technical.*`, `legal.*` (inclusiv `deadline_short` literal, D11), `court.*`, `compact.*`, `hero.*`, `confirm.*` (titlul paginii, subtitlul cu ireversibilitatea).
- Schimbări pe chei existente: `filing.undelivered.ack_cta` („Confirmă verificarea”), `filing.undelivered.late_headline` („Verificare necesară: e-mailul cu cererea a fost returnat după depunere”), `filing.send.cta` („Verifică și trimite”), `filing.send.submit` („Trimite la instanță”), `filing.resume.cta` („Trimite anexele rămase”), `flash_success_filed` (D16). `ack_statement`, `deadline_warning` neschimbate.
- `court_address.*` noi pentru macheta i.
- Fără em-dash, texte impersonale, vocabularul permis: „trimisă”, „acceptat de serverul instanței”, „confirmare de primire (mesaj automat)”, „data depunerii declarată”; interzis „depusă/înregistrată/primită de instanță” pentru `SENT` și `RECEIPT_RECEIVED`. Fără numere noi de articole OP. Fără „semnătură validă/calificată”, doar „semnătură detectată”.
- `php bin/console lint:yaml translations/`, `debug:translation ro --only-missing` restrâns la domeniul `messages`.

### Pas 6. Teste

Actualizări (selectori și texte):
- `CaseFilingControllerTest.php`: selectorii `#filing-package-card` rămân valabili (D17); verifică că textele de la :196-199 sunt în noua filă; :115 adminul nu are linkuri `/filing/package/` (inclusiv `.zip`).
- `CaseFilingSubmissionControllerTest.php`: :59-68 pe pagina completă; :78 stream `filing-send-panel` pe refuz; :122-134 succesul devine redirect 303 la `tab=depunere` și o singură transmitere în coadă; :184 `filing-send-open` are `data-turbo-frame="_top"`; :199 noul titlu; :190, :216 hero fără `#hs-modal-confirm-filing` și cu link spre filă.
- `CaseFilingSubmissionActionsTest.php`, `CaseFilingReceiptUploadTest.php`: textele schimbate (`late_headline`, titluri), testid-urile păstrate; :339 formularul F1 trimis prin crawler funcționează cu bifa (crawlerul nu aplică `required`).
- `CaseCourtAddressControllerTest.php`: testid-urile păstrate.
- `FilingSubmissionOverrideControllerTest.php`: `option[value="accepted"]` → `button[value="accepted"]`.
- După fiecare tăiere de bloc de test: recontrolează lista metodelor (`--list-tests`).

Teste noi (minim):
- `tests/Service/Filing/FilingPanelStateResolverTest.php` (TestCase, entități în memorie): câte un caz pentru fiecare cod din Pas 2.1, plus ordinea de prioritate (late_review peste stalled, stalled peste retrying, receipt peste no receipt), `legalNotesOpen` doar la `undelivered`, titlul cu „adresa nu există” doar la 5.1.1-5.1.3/5.4.4, `cc_not_received_petition` vs generic `internal`, acțiunile de descărcare omise pentru non-owner.
- `tests/Service/Filing/UnsignedPackageArchiveBuilderTest.php` (KernelTestCase): N intrări cu numele `_nesemnat`, eșec curat pe fișier lipsă.
- `tests/Controller/Case/CaseFilingTabTest.php` (WebTestCase): fila absentă înainte de `CERERE_GENERATA` și după plata integrală; prezentă cu pachet; `?tab=depunere` activă; cardul compact cu linkul „Deschide depunerea”; hero cu link spre filă fără `form`; punctul `filing-tab-dot` per ton (amber la stalled, roșu la undelivered, absent la filed); adminul vede fila fără linkuri de descărcare și fără extrase; panoul pe stările sent, undelivered (titlu, chenar prescripție cu „NCC art. 2540”, Note juridice `open`), uncertain (două carduri), stalled (acțiune principală „Trimite anexele rămase”, dialogul de închidere), prezența „Detalii tehnice” pliate cu codurile în DOM; stream-ul de generare cerere actualizează `panel-depunere`.
- `tests/Controller/Case/CaseFilingConfirmPageTest.php` (WebTestCase): pagină completă cu breadcrumb, corpul integral fără clasa de derulare, atașamente cu „semnătură detectată”, declarații nebifate, buton dezactivat implicit, redirect la `tab=depunere` după submit, refuz → stream `filing-send-panel`, alt avocat 403, admin 403.
- `tests/Controller/Case/CaseFilingUnsignedZipTest.php` (WebTestCase): owner primește `application/zip` cu N intrări `*_nesemnat.pdf`; rând de audit `filing_package_unsigned_archive_downloaded`; `BUILT` → `AWAITING_SIGNATURES`; admin și alt avocat 403; pachet STALE sau necurent 404.
- `tests/Controller/Admin/FilingSubmissionOverrideControllerTest.php`: textul efectului corect pe cale și butoanele numite.

Suita completă: `docker compose exec -T php ./bin/phpunit` (timeout 600000 ms), verde, fără deprecieri.

### Pas 7. Verificare live și capturi

Pe clone `TMP-LIVE` din dosarul 55 (scriptul existent), abonamentul 37 prelungit temporar și readus, Mailpit ca singur SMTP, simulatorul `app:filing:simulate-court-reply` + `app:filing:poll-inbound`. Nu se rulează `app:check-deadlines` pe dev. Capturi în `docs/LexRecovery/screenshots/verificare-live-ux-depunere-2026-10-25/` pe subfoldere (01-pachet, 02-semnare, 03-confirmare, 04-trimisa, 05-esecuri, 06-mesaje, 07-adresa-instantei, 08-admin), cu `README.md` (tabel captură, stare, machetă comparată). Curățenie la final în ordinea FK din contextul sarcinii.

## 3. Criterii DONE

- [ ] Fila „Depunere la instanță” apare corect, cu punctul colorat, și conține tot fluxul; „Documente” are doar cardul compact.
- [ ] Panoul de stare are pentru fiecare stare un titlu, 1-2 propoziții, o acțiune principală, max două secundare; logica este în `FilingPanelStateResolver`, acoperită de teste unitare.
- [ ] Pagina „Verifică și trimite la instanță” are aceleași protecții; succesul duce la filă; refuzul rămâne pe pagină.
- [ ] Data depunerii se salvează din panou, cu propunerea etichetată și fără salvare automată.
- [ ] Arhiva `.zip` funcționează doar pentru avocatul dosarului, cu audit și tranziția corectă.
- [ ] Polling-ul, `case_filing_state_stream`, toate stream-urile și blocarea în timpul transmiterii funcționează în filă fără reîncărcare.
- [ ] Textele juridice provizorii (`ack_statement`, `deadline_warning`) neschimbate; propoziția scurtă vizibilă la `UNDELIVERED`.
- [ ] Pagina adresei instanței și ecranul de deblocare admin reorganizate.
- [ ] Suita completă verde; `lint:yaml` verde; `schema:validate` verde; `make tailwind` rulat.
- [ ] Capturile live și README-ul în folderul de verificare; clonele șterse.

## 4. Amânat și de ce

- Validarea server-side a bifei F1: ar fi schimbare de logică de business (D10).
- Afișarea motivului adminului pentru închidere/anulare fără încercare: ar cere interogarea audit log-ului spre avocat (D15).
- Lista transmiterilor din admin (filtru „Necesită acțiune”, culori) și vocabularul unificat pentru adrese în admin: secțiunea 6 a propunerii, nu este „simplu”; se face separat.
- Formularea nouă a declarației F1 și a notei despre prescripție: așteaptă avocatul.
- Corecția centralizată a numerotării articolelor OP: ticket separat (verificarea juridică, blocantul 1).
- Importul adreselor de pe portal, reîmpărțirea anexelor după acceptarea părții 1, traducerea adminului: în afara scopului.
