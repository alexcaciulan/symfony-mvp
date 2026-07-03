# PLAN REVIZIE WORKFLOW DOSAR — Completări avocat (2026-06)

> Plan pe pași progresivi pentru Claude Code, în continuarea `PLAN-DEZVOLTARE-LEXRECOVERY.md`.
> Sursă: comentariile avocatului (text albastru `#1155cc`) din `PREZENTARE-WORKFLOW-DOSAR-AVOCAT.docx`.
> Status: **planificat**. Execuția se face pas cu pas, fiecare cu commit propriu + review legal + code.

## Context

Avocatul a recenzat `docs/LexRecovery/PREZENTARE-WORKFLOW-DOSAR-AVOCAT.docx` și a inserat **25 de completări** scrise cu albastru, direct în fluxul prezentat. Sunt corecții juridice și de produs la comportamentul actual. Acest document le structurează în pași executabili (R0–R10), izolând **delta-ul real** față de ce există deja implementat.

**Constatare cheie**: o bună parte din ce cere avocatul există deja în cod:
- auto-discovery ECRIS pe portal: `src/Service/Portal/PortalCaseMatcher.php`
- detectare automată soluție: `src/Service/Portal/PortalEventDetector.php`
- tip document dovadă comunicare: `DocumentType::DOVADA_COMUNICARE`
- buffer auto-finalizare configurabil: `src/Service/Deadline/CaseAutoFinalizer.php`
- notificări portal (email + in-app + Mercure): `src/EventSubscriber/EmailNotificationSubscriber.php`

### Decizii confirmate cu utilizatorul (2026-06-28)
1. **Statusuri terminale**: elimin `INCHIS_PARTIAL_INSOLVABIL` + `CloseReason::INSOLVENT` din OP; cablez faza `EXECUTARE` (azi declarată dar necablată).
2. **Depunere rejust.ro**: pas de **spike de fezabilitate** + reformulare copy; integrarea electronică efectivă rămâne pas separat condiționat de rezultat.
3. **Comunicare somație**: **păstrez ambele** modalități (executor + Poșta Română R+CD+AR); adaug doar recalculul termenului de 15 zile din data reală a dovezii.
4. **Livrabil**: acest document.

---

## Hartă: comentariu avocat → stare actuală → acțiune

| # | Comentariu avocat (PARA în docx) | Stare actuală în cod | Acțiune | Pas |
|---|---|---|---|---|
| 1 | Etapa amiabilă nu e necesară ca pas (19, 166) | `AMIABIL` = status inițial; primul CTA e „Generează somație" | Clarificare label, fără pas amiabil forțat | R10 ⚠️ opțional, decizie pendentă |
| 2 | Comunicare somație + recalcul 15 zile din dovadă (24, 78, 168) | Termen 15 zile estimat de la generare; `DOVADA_COMUNICARE` există | Câmp `somatieCommunicationDate` + recalcul | R3 |
| 3 | Validare termen + acord generare OP (28, 83) | `depune_cerere` fără guard pe termen | Guard „termen expirat" + modal acord | R4 |
| 4 | Depunere prin platformă / rejust.ro (30, 85, 170) | ZIP depunere fizică; copy „fizic la registratură" | Spike fezabilitate + reformulare copy | R9 |
| 5 | Auto-discovery nr. dosar + tragere soluție + confirmare (35, 88, 39, 103, 110, 127, 135) | Auto-discovery + detectare soluție DEJA există; tranziții manuale | Bridge soluție → tranziție sugerată cu confirmare | R7 |
| 6 | Hotărâre semnată + termen 10 zile + buffer (43, 47, 51, 143, 175, 145) | NU există `DocumentType::HOTARARE`; buffer = 1 zi | Tip doc HOTARARE + upload; buffer 1→5 | R5, R6 |
| 7 | „Insolvabil" greșit la OP + executare după ~30 zile (7, 64, 60) | `INCHIS_PARTIAL_INSOLVABIL` terminal; `EXECUTARE` necablat | Elimin insolvabil + cablez EXECUTARE | R1 |
| 8 | Executare în paralel cu cererea în anulare (123) | Executarea posibilă doar din DEFINITIVA | Tranziții `trece_la_executare` multi-from + avertisment | R2 |
| 9 | Monitorizare necesară + se oprește după tragere (54, 58) | `portalMonitoringActive` flag manual; fără auto-stop | Auto-stop la status terminal / eveniment relevant | R8 |

---

## Implicații și riscuri transversale

- **Migrare DB + date legacy**: eliminarea `INCHIS_PARTIAL_INSOLVABIL` cere remaparea rândurilor existente. DB e drop+recreate în dezvoltare, dar migrarea se scrie pentru mediul pre-prod.
- **Validare juridică obligatorie**: R1–R5 și R7 ating substanță juridică (workflow OP, termene, executare). Fiecare trece prin `avocat-senior` / `lexrecovery-legal-reviewer` înainte de commit.
- **`config/packages/workflow.yaml` atins de R1 + R2**: se execută R1 apoi R2; testele workflow se re-rulează la final.
- **i18n RO/EN în lockstep** + `php bin/console lint:yaml translations/` după fiecare pas care atinge `translations/`.
- **OPcache Docker**: `make restart` după modificări PHP/config (per CLAUDE.md).
- **Convenții copy**: fără em-dash în textele traduse (per CLAUDE.md); cod/CLI în engleză.

---

## ⚖️ Revizie juridică 2026-06-28 (avocat-senior + lexrecovery-legal-reviewer)

Ambii agenți juridici au revizuit acest plan ÎNAINTE de execuție. Verdict de ansamblu: **direcția e corectă, dar R2 are 2 corecții obligatorii**. Referințele legale au fost validate față de `ANALIZA-JURIDICA-PROCEDURA-OP-2026-05-08.md` (re-verificare pe legislatie.just.ro recomandată pentru textul exact al CPC art. 1021/1024 la prima ocazie).

| Pas | Verdict | Observații cheie |
|---|---|---|
| R1 | CONFORM | eliminarea insolvabil din OP e corectă; **lipsește** termenul prescripție executare (CPC art. 706) → adăugat mai jos |
| R2 | NECONFORM până la fix | (1) „cauțiune obligatorie" greșit juridic; (2) workflow rupt la executare din IN_ANULARE |
| R3 | CONFORM CU OBSERVAȚII | 15 zile = termen substanțial; prorogarea CPC art. 181 e decizie de produs aici, nu obligație |
| R4 | CONFORM CU OBSERVAȚII | „estimatul" nu trebuie să fie data generării (risc OP prematur, inadmisibil) |
| R5 | CONFORM CU OBSERVAȚII | „hotărâre" impropriu: documentul OP = „ordonanță de plată" |
| R6 | CONFORM | buffer 5 zile conservator, fără risc juridic |
| R7, R8, R10 | CONFORM | confirmarea umană / auto-stop / label amiabilă: corecte |

**Baze legale confirmate**: CPC art. 1015 alin. (1) (15 zile de la primire + modalități comunicare), art. 1016 (procedură prealabilă), art. 1021 alin. (1) (OP executorie chiar atacată), art. 1024 alin. (1) (10 zile cerere în anulare de la comunicare) și alin. (4) (anularea nu suspendă executarea de drept; suspendarea cu cauțiune e facultativă), art. 181 alin. (2) (prorogare termene procedurale), art. 706 (prescripție executare 3 ani).

### Corecții obligatorii la R2 (înainte de execuție)
1. **Reformulare „cauțiune obligatorie"**: cauțiunea NU e automată. Text corect pentru modal: „Cererea în anulare nu suspendă executarea de drept. Debitorul poate cere instanței suspendarea, pe care aceasta o poate acorda condiționat de depunerea unei cauțiuni (CPC art. 1024 alin. 4 coroborat cu art. 719/1057). Suspendarea nu e garantată."
2. **Fix workflow**: tranzițiile `admite_cerere_anulare` / `respinge_cerere_anulare` trebuie să accepte și `EXECUTARE` ca sursă (`from: [IN_ANULARE, EXECUTARE]`), altfel o anulare admisă în timpul executării lasă dosarul blocat cu titlu anulat. Necesită și o cale de tratare a sumelor restituite (notificare „executare anulată, restituiți sumele").

### Decizii deschise (necesită confirmare user înainte de R2/R4)
- **D-R2**: cum tratăm cererea în anulare admisă în timp ce dosarul e deja în EXECUTARE (tranziție `EXECUTARE→RESPINSA` cu notificare restituire vs. gestionare manuală cu notă)?
- **D-R2b**: `trece_la_executare` din `ORDONANTA_EMISA` se condiționează de existența `rulingCommunicationDate` (recomandat, evită ambiguitatea termenului de 10 zile) sau se permite și fără, doar cu avertisment?
- **D-R4**: în lipsa `somatieCommunicationDate`, guard-ul în mod „estimat" blochează complet și cere confirmarea explicită a avocatului că termenul de 15 zile de la primire a expirat (recomandat, mai sigur), sau folosește un tranzit poștal estimat configurabil?

---

## 🔁 Revizie post-validare avocat 2026-06-30 (răspunsuri a + b) | ✅ DONE (necomis)

După R1/R2, partenerul avocat a răspuns la două întrebări deschise. Rafinări implementate:

- **(a) Fereastra de plată voluntară înainte de executare**: avocatul a cerut ~35-40 zile (nu 30), de la comunicarea hotărârii **către avocat** (data către debitor necunoscută), iar trecerea în executare să fie **alegerea avocatului**. `trece_la_executare` era deja manuală. Adăugat: `DeadlineService::recommendedExecutionDate()` (`rulingCommunicationDate + 40 zile` configurabil via `app.legal_deadlines.voluntary_payment_days`, prorogat la prima zi lucrătoare, doar pe DEFINITIVA), expus în `OverviewContextBuilder` (`execution_recommended_date`) + bloc informativ în `_modal_executare` (indicator, NU blocaj). Decizii user: 40 configurabil / reutilizare `rulingCommunicationDate` / indicator informativ.
- **(b.1) „Cauțiune obligatorie" greșit juridic**: deja corect din R2 (`executare_risk_body` = „cu sau fără cauțiune CPC art. 1024"). Nicio modificare.
- **(b.2) Anulare respinsă reachable + executare în paralel**: `admite_cerere_anulare` avea deja `from:[IN_ANULARE,EXECUTARE]`. Adăugat self-loop `respinge_cerere_anulare_executare` (EXECUTARE→EXECUTARE) + `respinge_cerere_anulare` făcută reachable (era declarată dar fără modal/acțiune): nou `CaseTransition::RESPINGE_CERERE_ANULARE_EXECUTARE`, `RulingProposalResolver` (ramură EXECUTARE + 2 intrări `MODAL_BY_TRANSITION`→`#hs-modal-annulment-rejected`), `CaseTransitionController::annulmentRejected` (POST `/transition/annulment-rejected`, voter+CSRF+status guard), modal nou `_modal_annulment_rejected` + 2 CTA-uri, `AuditLogService::CATEGORY_ANNULMENT_REJECTED`.
- **Fix W2 legal (lacună prescripție)**: pe căile directe ORDONANTA_EMISA/IN_ANULARE → EXECUTARE (fără DEFINITIVA), `PRESCRIPTIE_EXECUTARE` nu se crea. Adăugat listener `entered.EXECUTARE` în `DeadlineCreationSubscriber` (idempotent, DRY cu `onDefinitiva`). Închide follow-up-ul R1 de creare a termenului pe calea de executare paralelă.
- **Deschis pentru avocat (W1 legal)**: platforma reține o singură dată a comunicării hotărârii (către avocat), folosită pentru termenul de 10 zile, fereastra de 40 zile și prescripția executării. Diferența față de comunicarea către debitor (≤ 1-2 zile) e imaterială pe termenul de 3 ani, dar un câmp separat `debtorCommunicationDate` ar crește precizia termenului cererii în anulare. Listat ca întrebare în documentul de validare.

**Verificare**: suite 1206 cu 9E/1F = baseline (zero regresii noi). lint:yaml clean, schema:validate în sync. Reviews: **legal CONFORM CU OBSERVAȚII** (0 blockers; W3 reformulare „dosarul devine definitiv"→„rămâne valabilă și nu mai poate fi atacată pe această cale" aplicat; W1 deferit avocat; W2 fixat), **code COMMIT-READY** (0 blockers; W em-dash docblock + comentariu modal fixate).

---

## PASUL R0 | Pre-condiții: enum-uri + câmpuri entitate + migrare | ~3h | ✅ DONE 2026-06-29 (necomis)

**✅ Livrat (necomis, conform fluxului fără-commit-per-pas)**: `migrations/Version20260629201401.php` (4 coloane additive), enum nou `PaymentNoticeCommunicationMethod` (EXECUTOR/POSTA_RCD), enum nou `DebitAcknowledgedStatus` (PARTIAL/UNPAID), `DocumentType::ORDONANTA_PLATA`, 4 câmpuri pe `LegalCase` + accesori, i18n RO/EN, 3 fișiere teste enum. Suite 1124 cu 9E/1F = baseline (zero regresii). Reviews: legal LEGAL-CLEAN, code COMMIT-READY → fix aplicat (rename `Somatie*`→`PaymentNotice*` per convenția `paymentNoticeDate`; eticheta EN „judicial bailiff").

**⚠️ Ajustare scope R0→R1 (decisă la execuție)**: eliminarea `CloseReason::INSOLVENT` + migrarea de remapare `INCHIS_PARTIAL_INSOLVABIL→INCHIS_SUCCES` au ramificații în 8+ fișiere (modal, `CloseCaseType`, controller, workflow, teste) și sunt intercorelate cu eliminarea place-ului din workflow. Mutate **integral în R1** (schimbare atomică). R0 a rămas **pur aditiv** (zero ștergeri, zero breaking changes). Naming: `ORDONANTA_PLATA` (nu `HOTARARE`, per D2); `PaymentNoticeCommunicationMethod` (nu `Somatie*`, convenție EN clase).

**Scop**: pregătesc enum-urile și câmpurile de care depind R1–R5, într-o singură migrare baseline.

**Modificări**:
- `src/Enum/CloseReason.php`: elimin `INSOLVENT`; păstrez `PAID`, `PARTIAL`, `ABANDONED`; `targetTransition()` rămâne pe `inchide_succes` (ruta executare se tratează în R1).
- `src/Enum/DocumentType.php`: adaug `HOTARARE = 'hotarare'` + label i18n + includere în grupurile relevante (uploadabil de avocat).
- `src/Entity/LegalCase.php`: adaug `?\DateTimeImmutable $somatieCommunicationDate` (oglindă `rulingCommunicationDate`, docblock CPC art. 1015 alin. 1) + `?bool $opGenerationConsent` + `?string $debitAcknowledgedStatus` (valori PARTIAL/UNPAID).
  - **⚖️ Revizie 2026-06-28 (N5)**: docblock-ul + label-ul `somatieCommunicationDate` trebuie să specifice explicit „data la care debitorul a PRIMIT somația (confirmată prin AR/proces-verbal executor)", NU data expedierii. Confuzia expediere/primire = sursa bug-ului C1 din analiza juridică.
  - **⚖️ Revizie 2026-06-28 (N1)**: adaug și `?string $somatieCommunicationMethod` (enum EXECUTOR / POSTA_RCD) pentru audit trail complet, întrucât dovada prin executor are valoare probatorie diferită de AR-ul poștal (CPC art. 1015 alin. 1).
- Migrare via `doctrine:migrations:diff`: coloane noi + `UPDATE legal_case SET status='INCHIS_SUCCES' WHERE status='INCHIS_PARTIAL_INSOLVABIL'`.

**Fișiere**: `src/Enum/CloseReason.php`, `src/Enum/DocumentType.php`, `src/Entity/LegalCase.php`, `migrations/Version2026XXXX.php`, `translations/messages.{ro,en}.yaml`.

**PROMPT**:
> Elimină `INSOLVENT` din `CloseReason` și actualizează `targetTransition()`. Adaugă `HOTARARE = 'hotarare'` în `DocumentType` cu label i18n. Adaugă pe `LegalCase` câmpurile `somatieCommunicationDate` (DATE_IMMUTABLE nullable), `opGenerationConsent` (bool nullable), `debitAcknowledgedStatus` (string nullable). Generează migrarea cu `doctrine:migrations:diff`, adaugă în ea `UPDATE` pentru remaparea statusului legacy. Rulează `doctrine:schema:validate`. Commit: `feat(domain): pre-conditions for workflow revision (enums + LegalCase fields)`.

**Teste**: enum cases + label-uri; `doctrine:schema:validate` exit 0.

**Verificare**: nicio referință reziduală la `INSOLVENT` (grep); schema validă.

---

## PASUL R1 | Statusuri terminale: elimin insolvabil + cablez EXECUTARE | ~4h | substanță juridică | ✅ DONE 2026-06-30 (necomis)

**✅ Livrat (necomis)**: workflow.yaml (EXECUTARE cablat: `trece_la_executare` DEFINITIVA→EXECUTARE + `inchide_succes`/`inchide_fara_recuperare` multi-from [DEFINITIVA, EXECUTARE]); `CaseStatus` (swap INCHIS_PARTIAL_INSOLVABIL→INCHIS_FARA_RECUPERARE, 3 terminale); `CaseTransition` (drop inchide_insolvabil, add trece_la_executare + inchide_fara_recuperare); `CloseReason` (drop INSOLVENT, add INSOLVENT_EXECUTARE, targetTransition bifurcat pe motiv); `DeadlineType::PRESCRIPTIE_EXECUTARE` + `DeadlineService::createExecutionPrescriptionDeadline` (CPC art. 706, 3 ani, fără prorogare) + listener `entered.DEFINITIVA` (definitiveDate = rulingCommunicationDate+10z sau today); controller (`close()` guard [DEFINITIVA,EXECUTARE] + gardă INSOLVENT_EXECUTARE doar din EXECUTARE; acțiune `transitionToExecution`); UI (`_modal_executare` nou, close modal status-gated, CTA-uri hero+recommended, pipeline-uri); migrare data-only `Version20260630090000` (remap legal_case + case_status_history + audit_log JSON_SET); i18n RO/EN; ~19 teste noi. Suite 1143 cu 9E/1F = baseline (zero regresii). Reviews: **legal LEGAL-CLEAN** (W1 guard, W2 definitiveDate precis, N1 docblock „prescripție executivă" adresate), **code COMMIT-READY după fix** (3 blockers strict_types + W1 guard + W2 audit JSON + comentarii EN fix-uite).

**⚠️ Follow-up-uri juridice transferate:**
- **R2/R8 (legal N2)**: la `entered.EXECUTARE`, PRESCRIPTIE_EXECUTARE devine stală (sesizarea executorului întrerupe prescripția, NCC art. 2537 via CPC art. 707). De adăugat notă/actualizare termen la pornirea executării.
- **Independent (legal W3)**: copy `court.matched_tribunal` spune „principalul depășește 200.000 RON" (corect per CPC art. 98 alin. 2), DAR memoria Pas 2.3 zice că `CompetentCourtResolver` folosește valoarea totală. De verificat/aliniat resolver-ul înainte de producție.

**Scop**: aliniez statusurile la realitatea juridică (PARA 7, 60, 64). OP nu se închide „insolvabil" (asta e a dosarului execuțional); după DEFINITIVA dosarul fie se închide cu succes, fie trece în executare silită.

**Modificări**:
- `config/packages/workflow.yaml`:
  - elimin place `INCHIS_PARTIAL_INSOLVABIL` + tranziția `inchide_insolvabil`;
  - cablez place `EXECUTARE`: `trece_la_executare: from: DEFINITIVA, to: EXECUTARE`;
  - `inchide_executare: from: EXECUTARE, to: INCHIS_SUCCES`.
- `src/Enum/CaseStatus.php`: elimin `INCHIS_PARTIAL_INSOLVABIL`; `isTerminal()` = `RESPINSA, INCHIS_SUCCES`; `EXECUTARE` activ (nu terminal); ajustez `color()` și `isActiveOnPortal()`.
- `src/Enum/CaseTransition.php`: elimin `INCHIDE_INSOLVABIL`; adaug `TRECE_LA_EXECUTARE`, `INCHIDE_EXECUTARE`.
- **`src/Enum/CloseReason.php` (mutat din R0)**: elimin `INSOLVENT`; `targetTransition()` remapat (`PAID/PARTIAL/ABANDONED` → `inchide_succes` din DEFINITIVA, `inchide_executare` din EXECUTARE per M4); actualizez `CloseCaseType` + docblock.
- **Migrare de remapare (mutată din R0)**: `UPDATE legal_case SET status='INCHIS_SUCCES' WHERE status='INCHIS_PARTIAL_INSOLVABIL'`, executată ÎNAINTE de eliminarea place-ului. Plus referințe legacy în `SeedDemoCasesCommand`, `PipelineStatus`/`_pipeline` templates, `LegalCaseRepository:118`.
- UI: `templates/case/overview/_modal_close_case.html.twig` (scot opțiunea insolvabil), `_recommended_actions.html.twig` + `_hero_actions.html.twig` (CTA „Trece la executare" pe DEFINITIVA).
- `src/Controller/Case/CaseTransitionController.php`: handlere `trece_la_executare` + `inchide_executare`.
- i18n: chei pentru statusuri/tranziții noi; șterg `INCHIS_PARTIAL_INSOLVABIL` / `inchide_insolvabil` / `INSOLVENT`.
- Teste afectate de actualizat: `CaseStatusTest`, `CaseTransitionTest`, `CaseWorkflowServiceTest`, `CaseTransitionControllerTest`, `CaseOverviewControllerTest`.

**Fișiere**: `config/packages/workflow.yaml`, `src/Enum/CaseStatus.php`, `src/Enum/CaseTransition.php`, `src/Controller/Case/CaseTransitionController.php`, `templates/case/overview/_modal_close_case.html.twig`, `_recommended_actions.html.twig`, `_hero_actions.html.twig`, `translations/messages.{ro,en}.yaml`.

**PROMPT**:
> Refă `workflow.yaml`: scoate `INCHIS_PARTIAL_INSOLVABIL` și `inchide_insolvabil`; cablează `EXECUTARE` cu `trece_la_executare` (DEFINITIVA→EXECUTARE) și `inchide_executare` (EXECUTARE→INCHIS_SUCCES). Aliniază `CaseStatus`/`CaseTransition`. Adaugă handlerele în `CaseTransitionController` cu voter `CASE_TRANSITION` + status guard. Scoate opțiunea insolvabil din modalul de închidere și adaugă CTA „Trece la executare". Verifică toate referințele la statusul vechi (grep) inclusiv `EmailNotificationSubscriber`. Commit: `feat(workflow): remove insolvency closure, wire EXECUTARE phase`.

**⚖️ Revizie juridică 2026-06-28**:
- **(M1/N3) Prescripția executării lipsește din plan**: la tranziția `marcheaza_definitiva`, creează termen `DeadlineType::PRESCRIPTIE_EXECUTARE` cu `dueDate = definitiveDate + 3 ani` (CPC art. 706 alin. 1), prioritate HIGH, alertă la T-90/T-30. Altfel avocatul poate pierde dreptul de a cere executarea fără avertisment. (Era N3 MEDIU în analiza juridică, neinclus inițial.)
- **(M3/N2) CloseReason::ABANDONED → INCHIS_SUCCES denaturează raportarea**: un dosar abandonat/nerecuperat numărat ca „succes" falsifică statisticile. Decizie de produs: ori status terminal distinct `INCHIS_FARA_RECUPERARE`, ori `ABANDONED` rămâne distinct în routing. Nu blochează juridic, dar de decis.
- **(M4) `CloseReason::targetTransition()` bifurcă pe status**: closure din EXECUTARE folosește `inchide_executare`, din DEFINITIVA `inchide_succes`. Metoda trebuie să primească statusul curent ca parametru (sau separare în controller).
- **(N2) EXECUTARE fără terminal pentru recuperare parțială/eșec**: dacă e decizie de scope (platforma nu urmărește granular rezultatul executării), documentează explicit; altfel modelează (b) recuperare parțială și (c) imposibilitate executare.

**Teste**: tranziții valide DEFINITIVA→EXECUTARE→INCHIS_SUCCES; negative (insolvabil inexistent); voter; subscriber; creare PRESCRIPTIE_EXECUTARE la definitivare.

**Verificare**: `php bin/console workflow:dump legal_case` arată EXECUTARE cablat fără insolvabil; `cache:clear` fără erori.

---

## PASUL R2 | Executare în paralel cu cererea în anulare | ~2h | substanță juridică | ✅ DONE 2026-06-30 (necomis)

**✅ Livrat (necomis)**: `trece_la_executare` multi-from [ORDONANTA_EMISA, IN_ANULARE, DEFINITIVA]→EXECUTARE (din ORDONANTA_EMISA gardat pe `rulingCommunicationDate` per D-R2b); `admite_cerere_anulare` multi-from [IN_ANULARE, EXECUTARE]→RESPINSA (D-R2; `reject()` mapează EXECUTARE→admite_cerere_anulare); modal `_modal_executare` cu avertisment risc condiționat (ORDONANTA_EMISA/IN_ANULARE) cu text corect (suspendare facultativă + cauțiune CPC art. 1024 alin. 4/719, NU „obligatorie"); CTA-uri UI (executare pe ORDONANTA_EMISA gated + IN_ANULARE; admite anulare pe EXECUTARE); audit flag `annulmentPending`; i18n RO/EN; 7 teste noi + 3 available-transitions actualizate. **Decizie** (⚠️ revizuită 2026-06-30, vezi secțiunea post-validare): inițial `respinge_cerere_anulare` rămânea doar din IN_ANULARE; după validarea avocatului s-a adăugat self-loop `respinge_cerere_anulare_executare` (EXECUTARE→EXECUTARE) ca soluția de respingere a anulării să fie înregistrabilă și în timpul executării. Suite 1150 cu 9E/1F = baseline. Reviews: **legal LEGAL-CLEAN** (text avertisment corect, gardă rulingCommunicationDate corectă), **code COMMIT-READY** (W1 comentariu Twig EN, W2 docblock audit multi-from, W3 assertResponseRedirects + strict_types — fix-uite).

**⚠️ Follow-up-uri (pre-producție):**
- **Notificare restituire (legal nota 2)**: la `entered.RESPINSA` cu `fromStatus===EXECUTARE`, o notificare „executare anulată, restituiți sumele" ar fi utilă (modalul avertizează deja înainte de pornire; notificare post-hoc = opțional). De adăugat la R8/notificări.
- **Verificare art. 1057 (legal W2)**: textul modalului citează doar CPC art. 719 (suficient pentru suspendare+cauțiune). De confirmat pe legislatie.just.ro dacă art. 1057 adaugă substanță înainte de lansare.
- **Audit gap anulare respinsă în executare (legal W1)**: respingerea anulării când dosarul e în EXECUTARE nu se înregistrează ca eveniment de stare (acceptat prin design). Opțional: tip document `DECIZIE_ANULARE_RESPINSA` uploadabil.
- **✅ REZOLVAT — prag instanță (R1 follow-up W3)**: `CompetentCourtResolver` folosește `$principal` (corect, CPC art. 98 alin. 2); nota MEMORY.md Pas 2.3 corectată.

**Scop**: PARA 123 — OP își păstrează caracterul executoriu chiar dacă e atacată cu cerere în anulare. Avocatul poate iniția executarea înainte de soluționare, asumându-și riscul restituirii.

**Bază legală**: CPC art. 1021 alin. (1) — „Ordonanța de plată este executorie, chiar dacă a fost atacată cu cerere în anulare." CPC art. 1024 alin. (4) — cererea în anulare nu suspendă executarea de drept; suspendarea se poate obține la cererea debitorului, condiționat de cauțiune.

**Modificări**:
- `config/packages/workflow.yaml`: `trece_la_executare` cu `from: [ORDONANTA_EMISA, IN_ANULARE, DEFINITIVA]`.
  - **⚖️ FIX OBLIGATORIU (W1/B2)**: pentru ca o anulare admisă/respinsă în timpul executării să rămână înregistrabilă, tranzițiile `admite_cerere_anulare` și `respinge_cerere_anulare` trebuie să accepte și `EXECUTARE` ca sursă: `from: [IN_ANULARE, EXECUTARE]`. Fără asta, dosarul trecut în EXECUTARE din IN_ANULARE rămâne blocat cu titlu potențial anulat, fără audit trail. Decizia exactă de modelare = D-R2 (vezi revizia de sus).
  - **⚖️ (M2/D-R2b)**: `trece_la_executare` din `ORDONANTA_EMISA` se recomandă condiționat de `rulingCommunicationDate` setat (altfel termenul de 10 zile al anulării e ambiguu când comunicarea judiciară coincide cu somația executorului).
- `src/Enum/CaseTransition.php` / `CaseStatus.php`: ajustare consecventă.
- UI: CTA „Inițiază executarea" cu **modal de avertisment**.
  - **⚖️ FIX OBLIGATORIU (W2/B1) — text corect** (NU „cauțiune obligatorie"): „Cererea în anulare nu suspendă executarea de drept. Debitorul poate cere instanței suspendarea, pe care aceasta o poate acorda condiționat de depunerea unei cauțiuni (CPC art. 1024 alin. 4 coroborat cu art. 719/1057). Suspendarea nu e garantată. Dacă cererea în anulare e admisă, sumele recuperate se restituie."
- `src/Security/Voter/CaseVoter.php`: `trece_la_executare` permis pe statusurile de mai sus.

**Fișiere**: `config/packages/workflow.yaml`, `src/Enum/CaseTransition.php`, `src/Security/Voter/CaseVoter.php`, `templates/case/overview/` (modal nou avertisment), `translations/messages.{ro,en}.yaml`.

**PROMPT**:
> Extinde `trece_la_executare` la multi-from `[ORDONANTA_EMISA, IN_ANULARE, DEFINITIVA]` ȘI extinde `admite_cerere_anulare`/`respinge_cerere_anulare` la `from: [IN_ANULARE, EXECUTARE]` (per D-R2). Condiționează `trece_la_executare` din ORDONANTA_EMISA de `rulingCommunicationDate`. Adaugă modalul de avertisment cu textul juridic corect despre suspendare facultativă + cauțiune (NU „obligatorie"). Citează CPC art. 1021 alin. 1 + 1024 alin. 4 în comentarii. Commit: `feat(workflow): allow enforcement in parallel with annulment request`.

**Teste**: tranziție din fiecare from valid; anulare admisă/respinsă DIN EXECUTARE (audit trail păstrat); coexistența IN_ANULARE + executare.

**Verificare**: `workflow:dump`; avertismentul (text corect) apare în UI înainte de aplicare; din EXECUTARE se poate aplica `admite_cerere_anulare`.

---

## PASUL R3 | Recalcul termen 15 zile somație din dovada comunicării | ~3h | substanță juridică | ✅ DONE 2026-06-30 (necomis)

**✅ Livrat (necomis)**: `DeadlineService::recalculatePaymentNoticeDeadline` (actualizează RASPUNS_SOMATIE la `communicationDate + 15z → nextWorkingDay`, șterge disclaimerul; creează dacă lipsește; injectează `LegalDeadlineRepository`); `PaymentNoticeCommunicationDateType` (data ≤ azi + metoda required `PaymentNoticeCommunicationMethod`); `CaseDeadlineController::setPaymentNoticeCommunicationDate` (rută `case_deadline_summons_communication_date`, mirror ordonanță, audit + recalc); `_modal_set_summons_communication_date` + CTA „Confirmă data comunicării" în alerta somației; i18n RO/EN. Naming `paymentNotice*` (nu `somatie*`, convenția R0). Suite 1156 cu 9E/1F = baseline (+6 teste). Reviews: **legal LEGAL-CLEAN** (recalc corect, prorogare = decizie produs documentată, captură metodă pentru audit) + **code COMMIT-READY după fix** (import enum, 2 teste paritate CSRF+403, test createExecutionPrescriptionDeadline, docblock-uri comprimate).

**⚖️ Bonus fix legal**: textul R2 `executare_risk_body` simplificat la doar „CPC art. 1024 alin. 4" (art. 719 e pentru contestația la executare = drept comun, nu anularea OP care e lex specialis autonomă) — **rezolvă follow-up-ul art. 1057 din R2**.

**⚠️ Follow-up (backlog)**: validare `paymentNoticeCommunicationDate ≥ paymentNoticeDate` (debitorul nu poate primi somația înainte de generare) — necesită injectarea datei în form (legal N2, non-blocant).

**Scop**: PARA 78, 168 — termenul de 15 zile curge de la **primirea efectivă** a somației, nu de la generare. Recalculez din data reală a dovezii (executor sau Poșta Română, ambele păstrate).

**Modificări**:
- `src/Service/Deadline/DeadlineService.php`: `recalculatePaymentNoticeDeadline(LegalCase, \DateTimeImmutable $communicationDate)` — actualizează termenul `RASPUNS_SOMATIE` la `somatieCommunicationDate + 15 zile → nextWorkingDay`, șterge disclaimerul „estimativ".
- Declanșator: la upload `DOVADA_COMUNICARE` sau la completarea manuală a `somatieCommunicationDate`. Creez `SomatieCommunicationDateType` + handler (oglindă `RulingCommunicationDateType`).
- `src/EventSubscriber/DeadlineCreationSubscriber.php`: termenul la `trimite_somatie` rămâne estimativ/provizoriu până la dovadă.
- UI: `templates/case/overview/_communication_warning.html.twig` câștigă câmp dată comunicare.

**Fișiere**: `src/Service/Deadline/DeadlineService.php`, `src/EventSubscriber/DeadlineCreationSubscriber.php`, `src/Form/Case/SomatieCommunicationDateType.php` (nou), `src/Controller/Case/` (handler), `templates/case/overview/_communication_warning.html.twig`, `translations/messages.{ro,en}.yaml`.

**PROMPT**:
> Adaugă `recalculatePaymentNoticeDeadline` în `DeadlineService` (oglindă `createAppealDeadline`, bază `somatieCommunicationDate`). Creează `SomatieCommunicationDateType` + handler care setează data și recalculează termenul `RASPUNS_SOMATIE`, eliminând disclaimerul estimativ. Leagă-l de fluxul de upload `DOVADA_COMUNICARE`. Commit: `feat(deadlines): recompute summons 15-day term from actual communication date`.

**⚖️ Revizie juridică 2026-06-28 (W3)**: termenul de 15 zile al somației e termen **substanțial** (grația de plată acordată debitorului), NU termen procedural. Prorogarea CPC art. 181 alin. (2) vizează termenele procedurale (acte la instanță). Deci `nextWorkingDay` pe cele 15 zile = **decizie de produs conservatoare** (avantajează debitorul), nu obligație legală: de marcat explicit în docblock că nu se invocă art. 181 ca temei. Prorogarea CPC art. 181 rămâne OBLIGATORIE doar la termenul procedural de 10 zile (cererea în anulare, R5).

**Teste**: recalcul estimat→real; idempotență; (dacă se păstrează) prorogare zi lucrătoare ca opțiune de produs.

**Verificare**: completare dată comunicare → termenul se mută la data reală + 15 zile; disclaimer dispare.

---

## PASUL R4 | Guard generare OP: termen plată expirat + parțial/neachitat + acord | ~3h | substanță juridică | ✅ DONE 2026-06-30 (necomis)

**✅ Livrat (necomis)**: `DeadlineService::isPaymentTermExpired(case, today)` (false dacă data comunicării null; altfel `today > nextWorkingDay(date+15z)` — strict după, OP admisibilă din D16); gărzi `CasePaymentOrderController::generate()` (termen neexpirat blochează DOAR dacă data setată; status debit required; acord required; persistă `debitAcknowledgedStatus`+`opGenerationConsent`; audit extins); `OverviewContextBuilder.payment_term_expired`; modal OP cu radio status debit + checkbox acord + hint termen; pill „termen plată expirat" pe hero (amber); i18n RO/EN. D-R4: fără data comunicării, bifa de acord = confirmarea explicită. Suite 1162 cu 9E/1F = baseline (+6 teste). Reviews: **legal LEGAL-CLEAN** + **code COMMIT-READY după fix** (W1 off-by-one `>=`→`>` admisibilitate D16 + onDefinitiva +10→+11 zile prescripție; W2 timezone Europe/Bucharest în Dockerfile; N1 copy „puteți genera" în loc de „admisibilă"; N2 pill roșu→amber; comentariu `(D-R4)` scos).

**⚠️ Note (pre-prod):** W2 timezone activă la următorul `make build` (fix sistemic, beneficiază toate `new DateTimeImmutable('today')`). N3 (R8): la `entered.EXECUTARE`, avertisment că prescripția se întrerupe prin sesizarea executorului (NCC art. 2537). DOC-FIX court matched_tribunal = deja REZOLVAT (cod folosește `$principal`, memorie corectată).

**Scop**: PARA 28, 83 — platforma validează împlinirea termenului de plată (min 15 zile) înainte de generarea cererii OP; avocatul bifează parțial/neachitat și dă acord explicit. Statusul reflectă „Somație trimisă · termen expirat".

**Modificări**:
- `src/Controller/Case/CasePaymentOrderController.php`: guard înainte de `depune_cerere` — termenul `RASPUNS_SOMATIE` trebuie depășit (folosesc `somatieCommunicationDate` dacă există).
  - **⚖️ FIX (W4) — modul „estimat"**: dacă `somatieCommunicationDate` lipsește, „estimatul" NU trebuie să fie data generării somației (riscă OP prematur = inadmisibil per CPC art. 1016, somația ajunge fizic la debitor în 3-5 zile). Recomandat (D-R4): guard-ul în mod estimat **blochează** și cere confirmarea explicită a avocatului că termenul de 15 zile **de la primire** a expirat, fără a calcula automat. Alternativ: tranzit poștal estimat configurabil.
  - **⚖️ (N3) Termen somație > 15 zile**: dacă avocatul acordă în somație un termen mai lung (20/30 zile), guard-ul hardcodat la 15 ar permite OP prematur. Ori câmp `somatiePaymentTermDays` (default 15), ori se documentează că platforma impune mereu 15 zile prin template-ul somației.
- Modal de confirmare nou: captează `debitAcknowledgedStatus` (PARTIAL/UNPAID) + `opGenerationConsent`. Persist pe `LegalCase` (câmpuri R0).
- `src/Service/Case/OverviewContextBuilder.php`: expune flag `paymentTermExpired`.
- StatusBadge / UI: label derivat „Somație trimisă · termen expirat" (stare derivată, **nu** place nou în workflow).

**Fișiere**: `src/Controller/Case/CasePaymentOrderController.php`, `src/Service/Case/OverviewContextBuilder.php`, `src/Form/Case/` (modal acord), `templates/case/overview/`, `translations/messages.{ro,en}.yaml`.

**PROMPT**:
> Adaugă în `CasePaymentOrderController` un guard care blochează generarea OP dacă termenul de plată (15 zile de la comunicare) nu a expirat. Adaugă un modal care captează statusul debitului (parțial/neachitat) și un acord explicit, persistate pe `LegalCase`. Expune `paymentTermExpired` din `OverviewContextBuilder` pentru label-ul derivat. Commit: `feat(case): guard OP generation on expired payment term + lawyer consent`.

**Teste**: blocare înainte de termen; deblocare după; persistă acordul + statusul debitului.

**Verificare**: generare OP înainte de 15 zile → blocat; după → modal acord → generare.

---

## PASUL R5 | Tip document ORDONANTA_PLATA + upload la emitere ordonanță | ~2h | substanță juridică | ✅ DONE 2026-06-30 (necomis)

**✅ Livrat (necomis)**: câmp upload opțional `rulingDocument` în `IssueRulingType` (FileType + Assert\File PDF/imagini); `CaseTransitionController::issueRuling` injectează `DocumentUploadService`, atașează `DocumentType::ORDONANTA_PLATA` post-tranziție (best-effort în try-catch + flash warning); modal `_modal_issue_ruling` cu enctype multipart + input file + hint; i18n RO/EN. Fluxul termenului de anulare (setRulingCommunicationDate → createAppealDeadline 10z + auto-finalizare) **exista deja, neschimbat**. Suite 1163 cu 9E/1F = baseline (+1 test). Reviews: **legal LEGAL-CLEAN** (terminologie „ordonanță de plată" corectă, upload opțional fără impact procedural) + **code COMMIT-READY după fix** (BLOCKER try-catch best-effort + flash; W1 makePdf orphan tempnam→rename; W2 mesaj test EN; W3 tearDown curăță fișiere fizice var/uploads).

**⚖️ Bonus fix legal**: `executare_risk_body` (R2) corectat „condiționat de cauțiune" → „cu sau fără cauțiune" (CPC art. 1024 alin. 4 — instanța are putere discreționară). DOC-FIX court matched_tribunal = deja REZOLVAT (cod `$principal`).

**Scop**: PARA 43 — avocatul încarcă documentul instanței (ordonanța de plată); platforma calculează termenul pentru cererea în anulare și abia apoi califică definitivă.

**⚖️ Revizie juridică 2026-06-28**:
- **(D2) Terminologie**: documentul emis la finalul OP NU se numește „hotărâre", ci **„ordonanță de plată"** (CPC art. 1020). Naming-ul `DocumentType::HOTARARE` e impropriu pentru OP. Recomandat: redenumire în `ORDONANTA_PLATA` sau `DOCUMENT_INSTANTA`, ori cel puțin docblock care clarifică „acoperă ordonanța de plată și orice decizie judecătorească în dosar". Copy UI: „Ordonanța de plată (copie scanată / extras portal)", nu „hotărâre semnată electronic" (instanțele fără sistem digital complet emit scan/extras, nu impun SEC).
- **(N4) Confirmă prorogarea în `createAppealDeadline`**: înainte de a considera R5 gata, verifică că metoda include `nextWorkingDay()` (CPC art. 181 alin. 2). Bufferul de 5 zile (R6) absoarbe weekendul, dar nu sărbătorile legale mobile (Paște ortodox) dacă prorogarea lipsește.

**Modificări**:
- `DocumentType::HOTARARE` (din R0; vezi D2 pentru naming) disponibil în formularul de upload.
- Modal „Emite ordonanță" (`_modal_issue_ruling.html.twig` + `IssueRulingType`): câmp upload ordonanță + `rulingCommunicationDate`. La completarea datei → `DeadlineService::createAppealDeadline` (există).
- Confirm că `CaseAutoFinalizer` rulează doar cu `rulingCommunicationDate` setat (deja implementat).

**Fișiere**: `src/Form/Case/IssueRulingType.php`, `templates/case/overview/_modal_issue_ruling.html.twig`, `src/Controller/Case/CaseTransitionController.php`, `translations/messages.{ro,en}.yaml`.

**PROMPT**:
> Adaugă în modalul „Emite ordonanță" un câmp de upload pentru hotărârea semnată electronic (tip `HOTARARE`) plus câmpul `rulingCommunicationDate`. La salvare, creează termenul `CERERE_IN_ANULARE` prin `DeadlineService::createAppealDeadline`. Commit: `feat(documents): ruling upload at issuance + appeal deadline trigger`.

**Teste**: upload HOTARARE; setare dată comunicare → termen 10 zile creat; auto-finalizare nu rulează fără dată.

**Verificare**: flux emitere ordonanță cu hotărâre atașată + termen anulare vizibil.

---

## PASUL R6 | Buffer termen 10 zile 1→5 zile | ~0.5h | config | ✅ DONE 2026-06-30 (necomis)

**✅ Livrat (necomis)**: `config/services.yaml` `auto_final_buffer_days: 1→5` + comentariu; `CaseAutoFinalizer` default `1→5` + docblock; `LegalCase` docblock „buffer 5 zile" (+ scos referința la pas „Pas 6.2"); comentariu clarificator pe `CaseAutoFinalizerTest::BUFFER_DAYS` (decuplat de config); actualizate exemplele stale din PLAN-DEZVOLTARE. Suite 1163 cu 9E/1F = baseline. Reviews: **legal LEGAL-CLEAN** (buffer pozitiv = imposibilă marcarea prematură, conservator) + **code COMMIT-READY** (blocker `VerifyCreantaCommand` = orfan pre-existent, user a decis să-l lase; warning-uri docblock RO = cod R0 acceptat, consistent cu convenția entității).

**Scop**: PARA 145, 175 — buffer de 5 zile (nu 1) față de termenul de 10 zile, pentru a absorbi întârzierea portalului și necontrolul datei de comunicare.

**Modificări**:
- `config/services.yaml`: `app.legal_deadlines.auto_final_buffer_days: 5` (azi 1). Folosit de `CaseAutoFinalizer` prin bind.
- `src/Entity/LegalCase.php`: actualizez docblock-ul care menționa „buffer 1 zi" → 5.

**Fișiere**: `config/services.yaml`, `src/Entity/LegalCase.php`.

**PROMPT**:
> Schimbă `app.legal_deadlines.auto_final_buffer_days` din 1 în 5 și actualizează docblock-ul aferent din `LegalCase`. Commit: `chore(deadlines): widen auto-finalization buffer to 5 days`.

**Teste**: `CaseAutoFinalizer` nu finalizează înainte de `termen + 5 zile`.

**Verificare**: caz cu `rulingCommunicationDate` recent → DEFINITIVA abia după 10 + prorogare + 5 zile.

---

## PASUL R7 | Bridge soluție portal → tranziție sugerată cu confirmare | ~4h | UX + portal | ✅ DONE 2026-06-30 (necomis)

**✅ Livrat (necomis)**: `RulingProposalResolver` (nou) — extras DRY din `MonitoringEventApplier` (`suggestedTransition` heuristic pe „Tip soluție") + `actionableProposal` (cel mai recent HEARING_COMPLETED cu tranziție aplicabilă `can()` + modal mapat); `RulingProposal` DTO readonly; `MonitoringEventApplier` deleagă la resolver (comportament identic); `OverviewContextBuilder.portal_ruling_proposal`; card `_portal_ruling_proposal` cu CTA „Confirmă și aplică" (deschide modalul, NU auto-aplică) + prefill data pronunțării în `_modal_issue_ruling` (doar pentru emite_ordonanta); i18n RO/EN. Mapare modal: emite_ordonanta→issue-ruling, respinge/admite_cerere_anulare→reject; `respinge_cerere_anulare` fără modal = follow-up. Suite 1173 cu 9E/1F = baseline (+10 teste). Reviews: **legal LEGAL-CLEAN** (confirmare umană robustă CPC art. 1024, heuristică corectă, prefill delimitat) + **code COMMIT-READY după fix** (W2 test admite_cerere_anulare în actionableProposal; W1 docblock-uri EN pe enum-urile R0; blocker = orfanul VerifyCreantaCommand pe care user l-a lăsat).

**Scop**: PARA 103, 110, 127, 135 — platforma trage soluția de pe portal și cere confirmarea avocatului (în loc de introducere pur manuală), pentru `emite_ordonanta` și `marcheaza_definitiva`.

**Modificări**:
- `src/Service/Portal/PortalEventDetector.php` (detectare soluție există) → expun soluția ca **sugestie de tranziție** cu date prefilate (tip soluție + data pronunțării + data comunicării dacă portalul o oferă).
- UI overview: card „Soluție detectată pe portal" + buton „Confirmă și aplică" care pre-completează modalul corespunzător. **Nu** aplică automat (riscul declanșării greșite a termenului de 10 zile = motivul confirmării, per design existent validat juridic).
- `src/Service/Portal/CaseMonitoringService.php` / `EmailNotificationSubscriber`: notificare „soluție detectată, confirmă".

**Fișiere**: `src/Service/Portal/PortalEventDetector.php`, `src/Service/Portal/CaseMonitoringService.php`, `src/EventSubscriber/EmailNotificationSubscriber.php`, `templates/case/overview/`, `translations/messages.{ro,en}.yaml`.

**PROMPT**:
> Folosind soluția detectată de `PortalEventDetector`, afișează în overview un card cu soluția trasă de pe portal și un buton care pre-completează modalul de tranziție corespunzător (`emite_ordonanta` / `marcheaza_definitiva`), fără aplicare automată. Extinde notificarea portal cu mesaj de confirmare. Commit: `feat(portal): suggest workflow transition from detected ruling with confirmation`.

**Teste**: soluție detectată → sugestie cu prefill; confirmarea aplică tranziția corectă.

**Verificare**: fixture portal cu soluție → card + confirmare aplică tranziția.

---

## PASUL R8 | Auto-stop monitorizare portal după eveniment relevant / status terminal | ~1.5h | portal | ✅ DONE 2026-06-30 (necomis)

**✅ Livrat (necomis)**: `CaseWorkflowSubscriber::onCompleted` dezactivează `portalMonitoringActive` când dosarul intră într-un status nemonitorabil (`!isActiveOnPortal()` = DEFINITIVA/EXECUTARE/terminal) + audit `portal_monitoring_auto_stopped` (gardă pe flag deja activ, fără audit redundant). Cron-ul `findActiveForMonitoring` filtra deja pe status; R8 face flag-ul + UI-ul oneste. 4 teste noi (DEFINITIVA stop+audit, RESPINSA stop+audit, rămâne monitorabil→nu, deja false→fără audit) + fix tearDown (DELETE legal_deadline înainte de legal_case pentru PRESCRIPTIE_EXECUTARE din R1). Suite 1177 cu 9E/1F = baseline (+4 teste). Reviews: **legal LEGAL-CLEAN** (corect juridic, niciun termen nu depinde de monitorizare post-definitiva) + **code COMMIT-READY după fix** (scos referința la pas + condensat comentariu).

**Scop**: PARA 54, 58 — monitorizarea e necesară, dar se oprește după ce s-a tras informația relevantă.

**Modificări**:
- `src/Service/Portal/CaseMonitoringService.php` (sau `CaseWorkflowSubscriber`): dezactivez `portalMonitoringActive` la status terminal (`RESPINSA`, `INCHIS_SUCCES`) sau după evenimentul așteptat.
- Confirm `CaseStatus::isActiveOnPortal()` reflectă modelul post-R1.

**Fișiere**: `src/Service/Portal/CaseMonitoringService.php`, `src/Enum/CaseStatus.php`.

**PROMPT**:
> Dezactivează `portalMonitoringActive` automat când dosarul atinge un status terminal sau evenimentul monitorizat a fost tras. Aliniază `isActiveOnPortal()`. Commit: `feat(portal): auto-stop monitoring on terminal status`.

**Teste**: caz terminal → `portalMonitoringActive=false`; cron îl sare.

**Verificare**: `app:portal-check-all` nu interoghează cazurile terminale.

---

## PASUL R9 | Depunere rejust.ro: spike fezabilitate + reformulare copy | ~3h | research + copy | ✅ DONE 2026-06-30 (necomis)

**✅ Livrat (necomis)**: `docs/LexRecovery/SPIKE-DEPUNERE-ELECTRONICA-REJUST.md` (nou) cu research live + recomandare **NO-GO** integrare automată rejust.ro (fără API public; necesită sesiunea + semnătura calificată a avocatului, Legea 455/2001/eIDAS) + **path recomandat (clarificare user)**: scopul real = acces dosar electronic; fallback = platforma generează „Cerere de acces la dosarul electronic" + o trimite la `Court.email` după tragerea nr. dosar de pe portal.just (pas viitor, NU implementat). Copy reformulat neutru (RO+EN): `generate_op_disclaimer_body` (scos „fizic") + footer PDF cerere OP (scos „fizic la registratură sau prin curier"). ZIP-ul include deja dovada comunicării (CaseFilesPackager). Suite 1184 cu 9E/1F = baseline. Reviews: **legal LEGAL-CLEAN** (procedura acces dosar electronic corectă; NO-GO fondat) + **code COMMIT-READY** (lockstep, fără em-dash). Fix-uri aplicate: generalizat `executare_risk_body` la „CPC art. 1024" (fără alineat, incertitudine alin.4 vs 5); scos em-dash din spike doc + resolver (convenție user). **Lecție ops**: cache Doctrine stale → restart loop seed; fix `rm var/cache` (memorie `ops_stale_doctrine_cache_restart`).

**Scop**: PARA 30, 85, 170 — depunere prin platformă via `registratura.rejust.ro` + dovada comunicării în pachet.

**Modificări**:
- **Spike** (research, fără integrare): document `docs/LexRecovery/SPIKE-DEPUNERE-ELECTRONICA-REJUST.md` cu fezabilitatea (canal/API, autentificare, formate, semnătură electronică) + recomandare GO/NO-GO.
- **Copy**: reformulez textele „depunere fizică la registratură / prin curier" (footer `payment_order_request.html.twig` + translations) în formulare neutră până la decizia GO/NO-GO.
- **ZIP**: confirm că `DOVADA_COMUNICARE` e deja inclusă (prin `CaseFilesPackager`); actualizez doar copy-ul opisului dacă e cazul.
- Integrarea electronică efectivă = pas viitor separat, condiționat de spike.

**Fișiere**: `docs/LexRecovery/SPIKE-DEPUNERE-ELECTRONICA-REJUST.md` (nou), `templates/pdf/payment_order_request.html.twig`, `translations/messages.{ro,en}.yaml`.

**PROMPT**:
> Cercetează fezabilitatea depunerii electronice prin `registratura.rejust.ro` și scrie un document de spike cu recomandare GO/NO-GO. Reformulează copy-ul care afirmă categoric „depunere fizică". Confirmă includerea dovezii comunicării în ZIP. Commit: `docs(portal): rejust.ro e-filing feasibility spike + copy neutralization`.

**Teste**: niciun cod de integrare; lint copy + ZIP neschimbat funcțional.

**Verificare**: documentul de fezabilitate există; copy-ul nu mai afirmă „depunere fizică".

---

## PASUL R10 | Clarificare scope etapă amiabilă (fără pas forțat) | ~1h | copy/UX | ⏭️ SKIPPED 2026-06-30 (decizie user)

> **Status: SKIPPED.** Utilizatorul a decis 2026-06-30 să NU execute acest pas. Motiv: din explorare nu există UI care forțează acțiuni amiabile (primul CTA pe AMIABIL e „Generează somația"), deci modificarea era pur cosmetică (label) și opțională. Statusul `AMIABIL` rămâne cu label-ul actual. Revizia workflow e completă cu R0-R9.

**Scop**: PARA 19, 166 — avocatul vine strict pentru OP; etapa amiabilă se prezumă consumată.

**Observație**: din explorare, nu există deja UI care forțează acțiuni amiabile (primul CTA pe `AMIABIL` e „Generează somație"). Deci modificarea e pur cosmetică (label) și poate fi considerată opțională.

**Modificări (dacă se confirmă)**:
- Confirm (deja așa) că nu există UI care forțează acțiuni amiabile.
- Ajustez label-ul i18n al statusului `AMIABIL` (ex. „Dosar nou / pregătire somație"), fără a schimba valoarea enum/DB.

**Fișiere**: `translations/messages.{ro,en}.yaml`.

**PROMPT**:
> Redenumește label-ul afișat al statusului `AMIABIL` ca să nu sugereze un pas amiabil obligatoriu, fără a schimba valoarea enum/DB. Commit: `chore(i18n): clarify AMIABIL status label (no forced amicable step)`.

**Teste**: smoke — label nou afișat; flux pornește direct cu somația.

**Verificare**: dosar nou → direct opțiunea de somație.

---

## Ordinea de execuție și dependențe

```
R0 (fundație: enums + câmpuri + migrare)
 ├─ R1 (statusuri: insolvabil out, EXECUTARE in)  ─┐ ambele ating workflow.yaml,
 ├─ R2 (executare în paralel cu anularea)          ─┘ R1 înainte de R2
 ├─ R3 (recalcul 15 zile somație)
 ├─ R4 (guard generare OP)  ← depinde de R3
 ├─ R5 (tip doc HOTARARE + upload)
 ├─ R6 (buffer 1→5)  [independent, rapid]
 ├─ R7 (bridge soluție portal)  ← după R5
 ├─ R8 (auto-stop monitorizare)  ← după R1
 ├─ R9 (spike rejust.ro + copy)  [independent]
 └─ R10 (clarificare amiabilă)  [independent, rapid] ⚠️ OPȚIONAL — decizie pendentă, se sare dacă nu se confirmă
```

Fiecare pas: commit propriu + `avocat-senior` / `lexrecovery-legal-reviewer` (R1–R5, R7) + `lexrecovery-code-reviewer`, apoi `make restart` în Docker.

---

## Verificare end-to-end (după execuția tuturor pașilor)

1. `php bin/console doctrine:schema:validate` verde.
2. `php bin/console workflow:dump legal_case` — fără `INCHIS_PARTIAL_INSOLVABIL`, cu `EXECUTARE` cablat + `trece_la_executare` din ORDONANTA_EMISA/IN_ANULARE/DEFINITIVA.
3. Flux complet pe dosar demo: somație → upload dovadă (recalcul 15 zile) → guard OP (acord) → emitere ordonанță + hotărâre → termen 10 zile cu buffer 5 → executare în paralel cu anularea → închidere cu succes / executare.
4. `vendor/bin/phpunit` — suite verde la nivelul baseline (zero regresii).
5. `php bin/console lint:yaml translations/` + Twig lint clean.
6. Manual: nu mai există „insolvabil" în UI OP; copy depunere reformulat; label amiabilă actualizat.

---

## Estimare totală

| Pas | Efort | Substanță juridică |
|---|---|---|
| R0 | ~3h | — |
| R1 | ~4h | da |
| R2 | ~2h | da |
| R3 | ~3h | da |
| R4 | ~3h | da |
| R5 | ~2h | da |
| R6 | ~0.5h | — |
| R7 | ~4h | da |
| R8 | ~1.5h | — |
| R9 | ~3h | — (research) |
| R10 | ~1h | — (⚠️ opțional, decizie pendentă) |
| **Total** | **~26h** (+~1h dacă se confirmă R10) | |
