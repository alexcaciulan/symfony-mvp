# Plan: cardul „Fișiere de semnat” după machetele aprobate

Data: 25.10.2026. Branch: `feature/trimitere-email-instanta` (ultimul commit `eb51511`).
Surse: machetele din `docs/LexRecovery/mockups/v2/depunere/semnare/` (`kit.js`, ecranele a-f, `README.md` cu nota de design și textele propuse, `screenshots/`), șablonul actual `templates/case/filing/_parts_to_sign.html.twig`, verificarea juridică a secțiunii (conform cu observații).

Cerința proprietarului: **nicio funcționalitate existentă nu se pierde**. Inventarul din secțiunea 2 este lista de control; fiecare punct se bifează la final cu dovada (test sau captură live).

## 1. Decizii (înainte de cod)

| # | Decizie | Motiv |
|---|---|---|
| D1 | Ultimul refuz de încărcare rămâne pe rând până la următoarea încărcare a acelui fișier. Pe `FilingPackagePart` se păstrează doar `lastRefusalKey`, `lastRefusalParams` (nesensibili: `excessBytes`, `rebuildable`) și `lastRefusalAt`. Fără conținut, fără hash, fără numele fișierului. | Decizia 1 a proprietarului; GDPR art. 5 alin. (1) lit. c) (minimizare). |
| D2 | Se persistă doar refuzurile care țin de fișier: validator (`not_pdf`, `too_large`, `failed`, `encrypted`, `active_content`, `unreadable`, `timeout`, `tool_missing`), semnătură (`refused.not_signed`, `unsupported_format`, `damaged`, `mismatch`), `over_budget` și `first_email_too_large`, plus `PdfToolException` mapat la `timeout`/`unreadable`. Nu se persistă `concurrent`, `already_signed`, `not_uploadable`, `blocked_submission_active`, `csrf`, `rate_limited` (stări de concurență sau de sesiune, nu ale fișierului); ele rămân doar în toast. | Un refuz persistat trebuie să descrie fișierul; restul ar induce în eroare la reîncărcare. |
| D3 | Înregistrarea refuzului se face în `SignedPartUploadService::auditRefusal()` (deja apelat pentru toate refuzurile de fișier și urmat de `flush`), deci auditul și starea rândului nu se pot despărți. `copyForPackage()` șterge refuzul (clona l-ar copia în versiunea nouă); `recordSignedUpload()` îl șterge. Refuzul nu schimbă `signatureStatus` (testul `testRefusedOutcomesAreNeverRecordedOnAPart` rămâne valabil). | Un singur loc; fișierul semnat anterior rămâne în vigoare (C4). |
| D4 | Remedierea la „fișier diferit”: se descarcă din nou fișierul generat și se semnează fără editare, conversie sau tipărire în PDF. Reconstruirea pachetului nu se propune (ar invalida celelalte semnături). | Decizia 2; verificarea juridică punctul 5. |
| D5 | Avertismentul de validitate apare o singură dată pe card, în subsol, mereu vizibil (inclusiv în cardul restrâns, unde subsolul nu se pliază), neînchizibil, cât timp există cel puțin o semnătură detectată. Lângă fiecare semnătură se adaugă iconița „i” cu același text prin `aria-describedby` (sprijin, nu înlocuitor), cum recomandă verificarea juridică. | Decizia 3; blocantul juridic (b). |
| D6 | „Descarcă toate (.zip)” stă în antetul cardului (doar avocatul dosarului, doar pachet `BUILT`/`AWAITING_SIGNATURES`/`SIGNED`; pentru `STALE` ruta dă 404, deci nu apare). În cardul restrâns linkul stă pe bara rezumatului, lângă „Afișează fișierele”, deci este vizibil fără deplierea listei. Ca urmare panoul de stare nu mai are nevoie de el: `to_sign` pierde acțiunea secundară `unsigned_zip`, iar `ready` și `send_blocked` rămân neschimbate (limita de două acțiuni secundare din `finish()` nu mai contează). | Decizia 4: acțiunea rămâne mereu la îndemână, într-un singur loc. Abatere mică de la macheta e (acolo linkul era în lista pliată). |
| D7 | Fișierul tras se încarcă doar pe rândul peste care este lăsat. Alegerea fișierului pornește încărcarea. Fără JavaScript formularul clasic rămâne funcțional (input vizibil pentru cititor, buton de trimitere randat și ascuns de controllerul Stimulus la `connect`). Același POST, aceeași rută, aceleași verificări (CSRF, rate limit, voter `FILING_CONTENT`). Nu se adaugă nicio rută nouă. | Decizia 5; verificarea juridică punctul 9. |
| D8 | Panoul de stare rămâne deasupra cardului și în starea `ready` (macheta e avea `status: null`). Cardul restrâns **nu** repetă „Verifică și trimite”: acțiunea principală este una singură, în panou și în antet. | C1: păstrează `CaseFilingTabTest:113-127`, linkul `#depunere-status` și regula unei singure acțiuni principale. |
| D9 | Un rând în așteptarea confirmării păstrează încărcarea altui fișier: în subsolul panoului de confirmare apare eticheta „Înlocuiește” (formularul existent, fără `replace=1`, cum este azi). | C2: textul machetei promite „Înlocuiește”; funcția există azi. |
| D10 | Nu se afișează numele fișierului încărcat sau refuzat, doar ora („încărcat la …”, „refuzat la …”, `Europe/Bucharest`). | C3 și D1. |
| D11 | Refuz pe un rând deja acceptat (înlocuire eșuată): chenarul roșu apare, pastila rămâne verde, iar textul spune „Fișierul semnat anterior rămâne în vigoare.” în loc de „Rândul rămâne nesemnat.” | C4: `SignedPartUploadServiceTest:532` păstrează fișierul anterior. |
| D12 | Depășirea limitei e-mailului: pentru anexe chenar galben cu „Reîmparte anexele” (formularul existent, `excess` din `lastRefusalParams.excessBytes`) și încărcarea rămâne posibilă prin tragere și prin eticheta „Încarcă din nou” în chenar; pentru cerere sau împuternicire (`first_email_too_large`, nereîmpărțibil) chenar roșu fără „Reîmparte”, cu „Încarcă din nou”. | C5; un fișier mai mic trebuie acceptat oricând. |
| D13 | Textul „cererea și împuternicirea își păstrează semnăturile” se folosește: verificat în cod, `FilingPackageBuilder::rebuildAnnexVolumes()` copiază părțile care nu sunt anexe cu `copyForPackage()` (același fișier semnat, aceeași stare) și refuză reîmpărțirea dacă intervalele anexelor s-ar schimba. Textul „Nu se folosesc servicii externe de compresie” se folosește: compresia este `App\Service\Pdf\GhostscriptCompressor`, proces local. Textul despre compresie nu afirmă echivalența probatorie a copiei. | Blocantul juridic (c): fiecare afirmație factuală este verificată în cod. |
| D14 | „Depunerea pe altă cale rămâne disponibilă” se folosește: panoul oferă `other_channel_archive` și `declare_other_channel`. | Verificarea juridică punctul 5. |
| D15 | Prezentarea rândului se calculează într-un serviciu pur nou `FilingPartRowPresenter` (stare, observații în ordinea note apoi avertismente, rezumat scurt, număr, refuz vizibil, card restrâns), testat cu `TestCase`. Șablonul rămâne subțire. | Logica nu stă în Twig; testabilă fără HTTP. |
| D16 | Observațiile: pe rândul care cere confirmare panoul este **deschis implicit** (`<details open>`), cu textele complete (nota cu cifre îngroșată, fundal mai închis) înaintea formularului de confirmare. Pe celelalte rânduri „N observații” deschide același tip de panou. Butonul din coloană „Verifică și confirmă” doar deschide panoul. Niciun text de observație nu stă doar în balon. | Verificarea juridică punctele 1 și 2: observațiile se citesc obligatoriu înaintea confirmării. |
| D17 | Cardul restrâns doar când pachetul este `SIGNED`, încărcările sunt deschise sau închise, și niciun rând nu are refuz vizibil. Se folosește `<details>` (merge fără JavaScript și păstrează testid-urile în DOM). Rezumatul: „Semnături detectate pe toate fișierele. Restul cerințelor se verifică înainte de trimitere.” Bara listei din machetă („Fișierele semnate, gata de trimis”) se omite. | Verificarea juridică punctele 3 și 8: fără „gata de …”, fără sugestia completitudinii dosarului. |
| D18 | Rândul de grup pentru volume apare înaintea primului `ANNEX_BUNDLE` când `annexVolumeCount > 1`. Linia `filing-package-volumes` din „Detalii tehnice” rămâne. | Nimic nu se pierde; intervalele de pagini rămân exacte. |
| D19 | Meniul „Descarcă” și balonul „i” se fac cu un controller Stimulus propriu (`disclosure-menu`), nu cu Preline, ca tastatura (săgeți, Escape cu focus înapoi, `aria-haspopup="menu"`, `aria-expanded`) să fie sub control și testabilă în browser. Fără JavaScript, meniul este un `<details>` cu cele două linkuri, deci ambele descărcări rămân accesibile. | Accesibilitate cerută; CSP `script-src nonce strict-dynamic` interzice script inline. |
| D20 | Pastila stării unui rând cu semnătură nu spune niciodată „Semnat”, „valid”, „calificat”: „Semnătură detectată”, „Semnătură detectată, document confirmat”, „Confirmarea documentului este necesară”, „Nesemnat”, „Încărcare refuzată”. | Verificarea juridică punctele 3 și 11. |

Ce nu se schimbă: logica de recunoaștere (`PdfSignatureInspector`, `SignedPartMatcher`), `FilingSendGuard`, rutele, voterul, rate limit-urile, CSRF-urile, auditul existent, reîmpărțirea anexelor, notele juridice din coloana laterală.

## 2. Inventar (lista de control „nimic nu se pierde”)

Fiecare punct are coloana de dovadă care se completează la final (test, captură din `docs/LexRecovery/screenshots/verificare-live-ux-semnare-2026-10-25/`).

| # | Funcționalitate de azi | Unde rămâne în varianta nouă | Dovadă | Bifat |
|---|---|---|---|---|
| I1 | Cardul apare pentru BUILT, AWAITING_SIGNATURES, SIGNED, STALE și fără transmitere (sau transmitere CANCELLED) (`_tab_frame.html.twig`) | Neschimbat | `CaseFilingTabTest` verde neschimbat; capturi a-f | [x] |
| I2 | `is_owner` și `uploads_open` (avocat, fără transmitere activă, `CERERE_GENERATA`, pachet încărcabil); STALE doar descărcări | Neschimbat, mutat în presenter/șablon | `CaseFilingControllerTest::testAStaleOrFiledPackageOnlyOffersDownloadsAndHidesTheRefusal`; captură `stale/` | [x] |
| I3 | Antet: titlu, intro, pastila `filing-package-status` („%ready% din %total% gata” sau „Neactualizat · vN”) | Antet + bară de progres | `CaseFilingControllerTest` (pastila, `v` la STALE); capturi a, e | [x] |
| I4 | Lista `filing-package-parts`, rând `id="filing-part-{id}"`, testid `filing-part-{id}`, `scroll-mt-24`; ancora folosită de `confirm_part` și `upload_parts` | Neschimbat; la hash `#filing-part-{id}` panoul rândului se deschide | `CaseFilingControllerTest` (toate testele pe `filing-part-{id}`); live: „Verifică și confirmă” deschide panoul (captură c) | [x] |
| I5 | Date rând: titlu, pagini și MB, interval în anexe (`part_annex_page`/`part_annex_range`), „Copie comprimată” `filing-part-compressed` | Rând 2 al grilei, bucăți fără rupere; „Copie comprimată” cu balon „i” | `testAnnexesInSeveralVolumesGetAGroupRowAndKeepTheirRangesAndCompressedLabel`; captură `f-volume/` | [x] |
| I6 | Iconul de stare (!, bifă, PDF) | Icon 36 px: document, bifă, triunghi, x roșu, săgeți în timpul verificării | capturi a, b, d, `a2-se-verifica` | [x] |
| I7 | Pastila `filing-part-signature-chip` cu 4 stări + numele din certificat | Pastila pe linia de stare + „Certificat: %name%”; în panou „Numele din certificat” | `testEveryObservationReadsInFullInTheRowPanelBeforeTheConfirmation`, `testWithoutTurbo…`; capturi b, c | [x] |
| I8 | Avertismentul de validitate pe fiecare rând semnat, neînchizibil | O dată în subsol (testid `filing-part-validity-notice` mutat acolo) + iconiță „i” lângă fiecare semnătură; și în notele juridice | `testASignedPackageFoldsToItsSummaryWithTheValidityNoticeAndTheArchiveOutsideTheList` (o apariție, în afara listei pliate; absentă fără semnătură); `FilingPartRowPresenterTest::testNoSignatureMeansNoValidityNoticeOnTheCard`; captură e | [x] |
| I9 | Avertismentele semnăturii: `cn_differs`, `cert_not_valid_at_indicated_date`, `timestamp_appended`, `trailing_update`, `legacy_sub_filter`, `textless_pages`, testid `filing-part-warning` | Text complet în panoul rândului, fiecare cu iconul lui, testid păstrat; scurt în rezumat | `testEveryObservationReadsInFullInTheRowPanelBeforeTheConfirmation` (toate cele 6 chei, text complet, `data-warning`) | [x] |
| I10 | Notele de potrivire `text_added`, `text_added_with_digits` (îngroșat), `pages_appended`, `part_id_missing`, cu `data-note` și parametri | Text complet în panou, înaintea formularului de confirmare, `data-note` păstrat | același test (toate cele 4 note, `data-note`, nota cu cifre îngroșată, înaintea formularului) + `testAProbableUploadWithAnAppendedPageShowsTheNoteAboveTheConfirmation` | [x] |
| I11 | Confirmarea potrivirii probabile: CSRF `filing_signed_confirm_{id}`, `sha256` ascuns, `data-turbo-frame=_top`, `filing-part-confirm-form`, audit `filing_signed_part_probable_confirmed`, refuz `concurrent` | Formularul în panoul deschis, după observații | testele de mai sus (sha256, formular); `SignedPartUploadServiceTest` (audit, concurent); live: confirmare reușită (captură c) | [x] |
| I12 | Descărcare nesemnată per fișier (`data-turbo=false`, audit, BUILT → AWAITING) | Buton „Descarcă” sau opțiunea „Fișierul nesemnat” din meniu | `testDownloadServesThePdfAuditsItAndMovesThePackageToAwaitingSignatures`, `testWithoutTurbo…` (link nesemnat în meniu); captură b2 | [x] |
| I13 | Descărcare semnată `filing-part-download-signed` (audit) | Opțiunea „Fișierul semnat · încărcat la %date%” din meniu, testid păstrat | `testSignedDownloadReturnsTheUploadedBytesToTheOwnerAndIsAudited`, `testWithoutTurbo…`; captură b2 | [x] |
| I14 | Încărcare/înlocuire: multipart, CSRF `filing_signed_upload_{id}`, `replace=1` când e acceptat, `accept=application/pdf`, `required`, `filing-part-upload-form`, eticheta accesibilă | Etichetă „Încarcă semnat”/„Înlocuiește” cu `aria-label` complet, pornire la alegere, tragere pe rând, buton de trimitere fără JS | `uploadTokenFromCard`, `testWithoutTurbo…` (`replace=1`); live: alegere, tragere pe rând, fără JS (capturi a2, `fara-js/`) | [x] |
| I15 | Server la încărcare: POST golit peste `post_max_size` → `too_large`, CSRF, limiter 30/h, voter, validator, inspector, matcher, buget, commit sub lock, audit | Neschimbat; `too_large` din POST golit se înregistrează pe rând | `testAnEmptiedOversizedPostReadsAsTooLargeNotAsAnExpiredSession` (+ refuz pe rând), `SignedPartUploadServiceTest::testAnEmptiedOversizedPostIsAuditedAndKeptOnThePart`; teste existente CSRF/voter | [x] |
| I16 | Peste buget: `filing-part-over-budget`, `filing-rebuild-annexes`, CSRF `filing_annex_rebuild_{packageId}`, `excess` limitat pe server; `first_email_too_large` doar toast | Chenar galben persistat pe rând (anexe), chenar roșu pentru cerere/împuternicire | `testAnOverBudgetRefusalOffersTheRebuildForAnnexesOnlyAndSurvivesAReload`, `testSignedAnnexOverTheEmailLimit…`, `testOversizedPetition…`; captură d2 + reîmpărțire live | [x] |
| I17 | Subsol: `filing-package-signed` la SIGNED, `skipped_documents`, `owner_only` | `filing-package-signed` pe rezumatul cardului restrâns; celelalte în subsol | `testInSeparateMode…` (`signed_summary`), `testASignedPackageFolds…`; capturi e, f, admin | [x] |
| I18 | Adminul vede cardul fără niciun link `/filing/package/` și fără .zip | Neschimbat (meniu, zip, încărcare doar pentru avocat) | `testAdminCannotDownloadAFileButStillOpensTheCase`, `CaseFilingUnsignedZipTest::testTheTabOffersTheArchiveToTheLawyerOnly`; captură `admin/` | [x] |
| I19 | Arhiva .zip nesemnată `filing-unsigned-zip` (doar ARCHIVABLE; STALE 404) | Antetul cardului / bara cardului restrâns | `CaseFilingUnsignedZipTest` (link în `#filing-parts`), `testASignedPackageFolds…` (în afara listei pliate), `testAStaleOrFiled…` (absent la STALE) | [x] |
| I20 | Turbo: `respond()` înlocuiește `#filing-package-card` + regiunile de stare + toast; fără Turbo redirect cu flash la `?tab=depunere` | Neschimbat; refuzul vine acum din DB, deci apare și după redirect | `testOwnerUploadsASignedPart…` (Turbo), `testWithoutTurbo…` (redirect + rând actualizat), `testARefusedUploadStaysOnItsRow…` (cardul se înlocuiește la refuz) | [x] |
| I21 | Toate cheile de refuz (secțiunea 1, D2) | Persistate pe rând sau toast, după D2 | `SignedPartUploadServiceTest::testFileRefusalsStayOnThePart…`, `testAToolFailureWhileInspecting…`, `testStateAndConcurrencyRefusalsDescribeNoFile…`; `FilingPartRowPresenterTest` | [x] |
| I22 | Zone legate: `_technical_details` (emails_info, volumes_banner), `_status_panel` (warnings pachet, frozen_petition la stale), `_legal_notes` | Neschimbate | `CaseFilingTabTest`, `CaseFilingSubmissionControllerTest` verzi; linia de volume din detalii tehnice verificată în `testAnnexesInSeveralVolumes…` | [x] |
| I23 | Închiderea încărcărilor în timpul transmiterii, după depunere, la STALE (fără „Înlocuiește”) | Neschimbat; refuzul persistat ascuns cât încărcările sunt închise | `testAStaleOrFiledPackageOnlyOffersDownloadsAndHidesTheRefusal`; `FilingPartRowPresenterTest::testNoRefusalIsShownOnceUploadsAreClosed`; captură `stale/` | [x] |
| I24 | `confirm.html.twig:67` folosește `chip.MISSING`/`PRESENT_MATCHED` | Textele noi capitalizate; pagina de confirmare se verifică vizual | `CaseFilingConfirmPageTest` actualizat la textul capitalizat („Semnătură detectată”) și verde | [x] |

## 3. Pași de implementare (în ordine)

### Pas 1. Entitate

`src/Entity/FilingPackagePart.php`:
- coloane noi, nullable: `lastRefusalKey` (`VARCHAR(80)`), `lastRefusalParams` (`JSON`), `lastRefusalAt` (`DATETIME_IMMUTABLE`);
- `recordRefusal(string $key, array $params = []): static` (ora curentă), `clearRefusal(): static`, getteri;
- `recordSignedUpload()` apelează `clearRefusal()`; `copyForPackage()` apelează `clearRefusal()` pe copie;
- comentariul docblock de la `recordSignedUpload` („Refused outcomes never reach the part”) se reformulează: refuzul lasă doar motivul, nu fișierul.

### Pas 2. Migrare

`migrations/Version20261025100000.php` scrisă de mână: `ALTER TABLE filing_package_part ADD last_refusal_key VARCHAR(80) DEFAULT NULL, ADD last_refusal_params JSON DEFAULT NULL, ADD last_refusal_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'` și `down()` simetric. Se rulează pentru dev și `--env=test`, apoi `doctrine:schema:validate`. Fără backfill.

### Pas 3. Servicii

1. `src/Service/Filing/SignedPartUploadService.php`:
   - `auditRefusal()` primește `array $params = []` și apelează `$part->recordRefusal($key, $params)` înaintea `flush`;
   - `assertWithinBudget()` trimite `['excessBytes' => …, 'rebuildable' => $carriesAnnexes]` și cheia `messageKey()` a excepției (`over_budget.text` sau `first_email_too_large`), ca rândul să știe ce chenar arată;
   - `catch (PdfToolException)` înregistrează `timeout`/`unreadable` pe rând și face `flush` (azi nu este auditat; se adaugă `auditRefusal` cu aceeași cheie);
   - metodă publică `recordTooLarge(FilingPackagePart $part): void` pentru POST-ul golit din controller (audit `filing_signed_upload_refused` + refuz pe rând);
   - refuzurile de concurență și de stare rămân neatinse (D2).
2. `src/Service/Filing/FilingPartRowPresenter.php` (nou, fără dependențe): `present(FilingPackagePart $part, bool $uploadsOpen): FilingPartRow` (DTO readonly) cu `state` (`missing`, `matched`, `confirmed`, `awaiting`, `refused`, `refused_kept_previous`), `observations` (lista `{key, params, short, strong, note}` cu notele întâi, apoi avertismentele; mapează atât șirurile simple din `getSignatureWarnings()`, cât și `{key, params}` din `getSignatureMatchNotes()`), `observationCount`, `refusal` (null când încărcările sunt închise), `panelOpen` (awaiting sau refuz). Plus `collapsed(FilingPackage $package, array $rows): bool` după D17 și `anySignature`.
3. `src/Service/Filing/FilingPanelStateResolver.php`: `to_sign` cu acțiuni secundare `[]` (D6). Restul neschimbat.
4. `OverviewContextBuilder` (sau unde se construiește contextul filei): adaugă `filing_part_rows` din presenter, ca șablonul să nu calculeze.

### Pas 4. Controller

`src/Controller/Case/CaseFilingController.php`:
- `uploadSigned()`: la POST golit apelează `signedUploads->recordTooLarge($part)` înainte de `respond()` (rândul se găsește înaintea verificării, deja azi);
- parametrul `$overBudget` din `respond()` și variabila `filing_over_budget` se elimină: chenarul vine din refuzul persistat; toast-ul rămâne cu `%excess%`;
- `rebuildAnnexes()` neschimbat (primește `excess` din formular, limitat pe server).
`CaseFilingSubmissionController` nu se schimbă.

### Pas 5. Șabloane și front-end

1. `templates/case/filing/_parts_to_sign.html.twig`, rescris după `kit.js` (`card()`, `row()`, `statusLine()`, `panel()`, `refusal()`, `groupRow()`):
   - antet: titlu, intro nou, pastila `filing-package-status`, bară de progres (`aria-hidden`, procentul e în pastilă), „Descarcă toate (.zip)” `filing-unsigned-zip` (D6);
   - rând: grilă `grid-cols-[2.25rem_minmax(0,1fr)_auto]`, coloana de acțiune `sm:w-[11rem]` (goală când nu e acțiune), coloana „Descarcă” `sm:w-[8.25rem]`; pe telefon 2×2, butoane pe toată lățimea;
   - linia de stare: pastila `filing-part-signature-chip`, „Certificat: %name%”, iconița „i” de validitate, `<summary>` „N observații”/„Detalii” pentru `<details id="filing-part-panel-{id}">`;
   - panou: `compare_title`, `compare_intro`, observațiile complete (`filing-part-warning`, `data-note`), „Numele din certificat”, ora încărcării, formularul de confirmare `filing-part-confirm-form`, apoi „Înlocuiește” (D9);
   - refuz: chenar `role="alert"` cu titlu, text, „Cum se rezolvă”, „refuzat la %time%”, „Rândul rămâne nesemnat.” sau „Fișierul semnat anterior rămâne în vigoare.” (D11); peste buget: chenarul `filing-part-over-budget` cu `filing-rebuild-annexes` (D12);
   - încărcare: formularul `filing-part-upload-form` cu eticheta-buton, input `sr-only` cu `aria-label`, focus vizibil prin `has-[:focus-visible]:ring-2`, buton de trimitere fallback;
   - rând de grup pentru volume (D18); cardul restrâns `<details>` (D17) cu `filing-package-signed` pe rezumat;
   - subsol: validitate (o singură dată, `filing-part-validity-notice`), `skipped_documents`, `owner_only`;
   - variante `dark:` pentru toate culorile noi.
2. `templates/case/filing/_panel_action.html.twig`: `confirm_part` rămâne ancoră `#filing-part-{id}`; controllerul deschide panoul la hash.
3. `assets/controllers/signed-upload_controller.js`: `change` → `requestSubmit()` (o singură dată, cu gardă cât timp trimiterea e în curs, ca drop + change să nu consume două încercări din limita de 30/h); `dragenter/dragover/dragleave/drop` doar pe rând, cu suprapunerea „Eliberează fișierul pentru a-l încărca la …”; `drop` atribuie `input.files` și trimite; stare „Se verifică” la `turbo:submit-start`, revenire la `turbo:submit-end` cu eroare; ascunde butonul de trimitere la `connect`; animație oprită cu `motion-reduce:`. Deschide panoul rândului dacă `location.hash` este `#filing-part-{id}`.
4. `assets/controllers/disclosure-menu_controller.js`: meniul „Descarcă” (săgeți, Home/End, Escape cu focus înapoi, clic în afară închide) și balonul „i” (focus, hover, atingere, Escape, `aria-describedby`). Fallback fără JS: `<details>`.
5. `make tailwind` după clasele noi, `make restart`.

### Pas 6. Traduceri

`translations/messages.ro.yaml` și `messages.en.yaml`, în lockstep, fără em-dash, impersonal:
- modificate după tabelul „Texte propuse” din README: `filing.panel.parts.intro`, `filing.signature.chip.*` (inclusiv `PRESENT_PROBABLE_unconfirmed` → „Confirmarea documentului este necesară”), `filing.signature.signer` → „Certificat: %name%”, `filing.signature.notice.validity` (punct în loc de punct și virgulă, „avocatului” înaintea „instanței”), `filing.upload.button` → „Încarcă semnat”, `filing.upload.replace` → „Înlocuiește”, `filing.upload.over_budget.rebuild_button` → „Reîmparte anexele”, `filing.upload.over_budget.text` (+ `title` nou), `filing.signature.refused.mismatch` (+ `title`, `fix`), `filing.upload.error.already_signed` (numele nou al butonului);
- noi: `filing.signature.awaiting_explain`, `compare_title`, `compare_intro`, `observations` (plural), `certificate_name_label`, `uploaded_at`, `short.*` (cele 10 chei: `cn_differs`, `cert_not_valid_at_indicated_date`, `timestamp_appended`, `trailing_update`, `textless_pages`, `legacy_sub_filter`, `text_added`, `text_added_with_digits`, `pages_appended`, `part_id_missing`), `filing.upload.refused_status`, `refused_at`, `refused_row_unsigned`, `refused_kept_previous`, `fix_label` („Cum se rezolvă”), un `fix` scurt pentru fiecare cheie de refuz persistată (`not_signed`, `unsupported_format`, `damaged`, validator), `uploading`, `checking`, `drop_here`, `upload_aria` („Încarcă fișierul semnat: %title%”), `upload_again`, `filing.package.compressed_explain`, `filing.panel.parts.show_files`, `signed_summary`, `filing.package.download_all`, `download_menu`, `download_signed_option`, `download_unsigned_option`, `annex_volumes_group`, `details`;
- cheile eliminate (`filing.signature.package_signed`, eventual `download_unsigned`) se șterg doar dacă grep nu le mai găsește (notificări, admin, confirm);
- `php bin/console lint:yaml translations/`.

### Pas 7. Teste

Actualizări:
- `tests/Controller/Case/CaseFilingControllerTest.php`: l.315 `filing.signature.package_signed` → `filing.panel.parts.signed_summary`; l.205-232 ordinea notă înaintea `filing-part-confirm-form` rămâne; `uploadTokenFromCard` caută `form[data-testid=filing-part-upload-form]` pe un rând MISSING.
- `tests/Controller/Case/CaseFilingUnsignedZipTest.php:121`: `#filing-parts a[data-testid=filing-unsigned-zip]`; l.131-132 rămân.
- `tests/Service/Filing/FilingPanelStateResolverTest.php:83`: `[]` pentru `to_sign`; `ready` neschimbat.

Noi (minimul):
- `tests/Entity/FilingPackagePartTest.php`: refuzul se înregistrează cu ora și parametrii, nu schimbă `signatureStatus`; `recordSignedUpload` îl șterge; `copyForPackage` nu îl copiază.
- `tests/Service/Filing/SignedPartUploadServiceTest.php`: `not_signed`, `mismatch`, validator (`not_pdf`), `over_budget` (cu `excessBytes`, `rebuildable`), `first_email_too_large`, `PdfToolException` se persistă; `already_signed`, `not_uploadable`, `concurrent` nu se persistă; refuzul la înlocuire păstrează fișierul anterior și scrie refuzul; `recordTooLarge` auditează și persistă.
- `tests/Service/Filing/FilingPartRowPresenterTest.php` (TestCase): fiecare stare; ordinea observațiilor; maparea ambelor formate; `strong` pentru `text_added_with_digits`; `refusal` null cu încărcări închise; `collapsed` doar SIGNED fără refuz.
- `tests/Controller/Case/CaseFilingControllerTest.php`: după un POST refuzat și un GET nou rândul are `[role=alert]` cu titlul refuzului și ora, fără numele fișierului trimis; chenarul peste buget apare după GET și are `filing-rebuild-annexes`; zip absent în antet pentru STALE și pentru admin; rândul de grup la mai mult de un volum; cardul SIGNED are `details` închis și `filing-package-signed`, iar avertismentul de validitate apare exact o dată și în afara listei pliate; cardul se deschide când un rând are refuz; POST golit → refuz `too_large` pe rând; încărcarea fără JS (POST clasic) → redirect la `?tab=depunere` și rândul actualizat.
- `CaseFilingTabTest`, `CaseFilingSubmissionControllerTest` rămân verzi neschimbate.

Rulare: `docker compose exec -T php ./bin/phpunit` (timeout 600000 ms).

### Pas 8. Verificare live și capturi

Clonă `TMP-LIVE-UXS` din dosarul 55 (procedura din context), semnare în container cu `App\Tests\Support\TestPdfSigner::sign()` (inclusiv `visibleRect`) și `TestPdfVariants`. Dosarele 170-173 nu se ating. Certificatul de test are nume de test, deci capturile nu conțin numele real al proprietarului. Capturi la 1440 px și 390 px în `docs/LexRecovery/screenshots/verificare-live-ux-semnare-2026-10-25/` pe subfoldere (`a-construit`, `a2-incarcare`, `b-mixt`, `c-confirmare`, `d-refuzuri`, `e-semnate`, `f-volume`, `stale`, `admin`, `fara-js`), cu README care leagă fiecare captură de machetă. La final clona se șterge în ordinea FK, abonamentul 37 revine la `2026-10-21 00:00:00`.

### Pas 9. Bifarea inventarului

Coloana „Bifat” din secțiunea 2 se completează cu testul sau captura pentru fiecare punct, în implementare și din nou în review.

## 4. Criterii DONE

- Toate punctele I1-I24 bifate cu dovadă.
- Suita completă verde; `doctrine:schema:validate` verde pentru dev și test; `lint:yaml translations/` verde; niciun em-dash nou (căutare cu `grep -rnP '\x{2014}'` în `translations`, `templates/case/filing`, `assets/controllers`, `src`, fără apariții noi în afara comentariilor YAML și a placeholderului).
- Aspectul la 390 px și 1440 px urmează machetele a-f (cu abaterile D6, D8, D10, D17 documentate).
- Meniul „Descarcă” și balonul „i” funcționează din tastatură; focusul este vizibil pe eticheta de încărcare.
- Încărcarea funcționează prin alegere, prin tragere pe rând și fără JavaScript.
- Observațiile sunt citibile integral înaintea confirmării; avertismentul de validitate vizibil și în cardul restrâns.

## 5. Amânat

- Plasarea fișierului oriunde pe card cu potrivire după identificatorul intern: respinsă (decizia 5; risc de atribuire greșită când lipsește identificatorul).
- Numele fișierului încărcat sau refuzat: nu se stochează (D1, D10).
- Persistarea refuzurilor de concurență, CSRF și rate limit: rămân toast (D2).
- Recunoașterea semnăturii, `FilingSendGuard`: în afara secțiunii.
- Confirmarea juridică finală a avertismentului unic (decizia 3): implementat cu iconiță suplimentară pe fiecare semnătură; dacă reviewerul juridic cere textul complet pe rând, se adaugă înapoi fără alte schimbări.

## 6. Rezultatul implementării (25.10.2026)

Inventarul I1-I24 este bifat mai sus, cu testul sau captura pentru fiecare punct. Capturile și tabelul care le leagă de machete: `docs/LexRecovery/screenshots/verificare-live-ux-semnare-2026-10-25/README.md`.

Abateri față de pașii de mai sus:
- **D6 / panoul de stare**: în starea `to_sign` locul arhivei .zip îl ia `other_channel_archive` („Descarcă arhiva pentru altă cale”), nu o listă goală. Textele de remediere spun „Depunerea pe altă cale rămâne disponibilă”, iar în `to_sign` panoul nu oferea până acum nicio cale alternativă; afirmația trebuie să fie adevărată în aceeași filă (verificarea juridică, punctul 5).
- **Migrare**: fără `COMMENT '(DC2Type:datetime_immutable)'`, pentru că proiectul folosește DBAL 4 (schema validă pentru dev și test).
- **Ora refuzului**: „Refuzat la %date%” cu data și ora (`d.m.Y, H:i`, `Europe/Bucharest`), nu doar ora, ca un refuz din altă zi să nu fie ambiguu.
- **Răspunsul la un refuz**: controllerul înlocuiește acum cardul și la refuzurile de încărcare (înainte doar toast), ca rândul să arate chenarul imediat; toast-ul rămâne.
- **Auditul depășirii limitei e-mailului**: motivul din `filing_signed_upload_refused` este acum cheia excepției (`filing.upload.over_budget.text` sau `filing.upload.error.first_email_too_large`) în loc de `filing.upload.error.over_budget`, care nu era folosită nicăieri altundeva.
- **Rândurile cardului restrâns** nu sunt compacte (datele fișierului rămân afișate), ca nimic să nu se piardă.
- **Panoul de stare „ready”** păstrează titlul existent („Pachetul este semnat și gata de trimis”); nu face parte din card și nu a fost modificat. De revăzut juridic separat (verificarea juridică, punctul 3, despre „gata de …”).

Rămase deschise:
- Confirmarea juridică a avertismentului unic (decizia 3): implementat o dată pe card, mereu vizibil, plus iconița „i” cu același text lângă fiecare semnătură.
- POST-ul golit peste `post_max_size` nu poartă token CSRF (corpul este eliminat de PHP), deci refuzul `too_large` se înregistrează pe rând doar sub voterul `FILING_CONTENT`. Efectul unei cereri falsificate ar fi doar un chenar de refuz pe rând.
