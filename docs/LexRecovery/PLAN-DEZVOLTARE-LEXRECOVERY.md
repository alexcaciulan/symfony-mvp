# PLAN DE DEZVOLTARE LEXRECOVERY — Pași progresivi pentru Claude Code

> Pivot de la "Cerere cu Valoare Redusă" către LexRecovery — platformă SaaS pentru avocați.
> Scope MVP: Fazele 1-5 (Amiabil → Somație → Cerere OP → Monitorizare → Definitivă). Faza 6 (executare silită) — POST-MVP.
> 20 pași | 10 faze tehnice | ~22 zile dev | ~18 calendar (cu paralelism Faza 5 ‖ Faza 6)
>
> **Documente complementare**:
> - [`ANALIZA-FLUXURI-LEXRECOVERY.md`](./ANALIZA-FLUXURI-LEXRECOVERY.md) — specificația funcțională (entități, fluxuri, calcule, workflow). Citește-l înainte să începi orice pas.
> - [`mockups/v2/`](./mockups/v2/) — mock-up-uri vizuale HTML/Tailwind pentru zonele cheie ale aplicației (vezi tabel mai jos).

---

## Mock-up-uri vizuale disponibile

În `docs/LexRecovery/mockups/v2/` există mock-up-uri HTML statice (Tailwind v4 + Preline UI) care **sugerează direcția vizuală** (layout, paletă, interacțiuni) pentru zonele principale ale aplicației. Sunt **scop de design, nu contract** — implementarea Twig se inspiră din ele dar poate diferi unde e justificat (constrângeri tehnice, simplificări MVP, feedback ulterior).

| Zonă | Mock-up disponibil | Pas care îl consumă | Coverage |
|---|---|---|---|
| **Auth — login** | `01-auth/login.html` | reutilizare din MVP existent | ✅ |
| **Dashboard — gol** | `02-dashboard/empty.html` | 7.1 | ✅ |
| **Dashboard — populated** | `02-dashboard/populated.html` | 7.1 | ✅ |
| **Wizard Step 0 (Documente sursă)** | `03-wizard/step0-documente.html` | 3.0 | ✅ |
| **Wizard Step 1 (Creditor)** | `03-wizard/step1-creditor.html` | 3.1, 3.2 | ✅ |
| **Wizard Step 2 (Debtor)** | `03-wizard/step2-debitor.html` | 3.1, 3.2 | ✅ |
| **Wizard Step 3 (Creanță cu calc live)** | `03-wizard/step3-creanta.html` | 3.1, 3.2, 3.3 | ✅ |
| **Wizard Step 4 (Confirmare)** | `03-wizard/step4-confirmare.html` | 3.1, 3.2 | ✅ |
| **View Dosar — overview** | `04-dosar/overview.html` | 7.2 | ✅ |
| Index navigație | `index.html` | n/a (browser) | ✅ |

**Lipsesc** (de produs ad-hoc în pașii respectivi sau acceptat ca derivare directă din mock-up-urile existente):
- Auth: register, email verify, reset password (refolosire UI MVP existent acceptabilă)
- Tab-uri view dosar: Documente, Termene, Activitate Portal, Audit (pas 7.2 le derivă din `overview.html`)
- Modal-uri tranziții workflow (trimite somație, generează cerere OP)
- Pagini admin (`/admin/*` — EasyAdmin default e suficient)
- Pagini monetizare (`/abonament`, `/facturile-mele`)
- Email templates (HTML simplu, fără mock-up dedicat necesar)

**Cum se folosesc mock-up-urile la fiecare pas relevant**:
1. Deschide mock-up-ul corespunzător în browser (sau direct în IDE) ca să înțelegi intenția vizuală.
2. Identifică componentele Preline + componentele Twig custom (din §12.5 ANALIZA-FLUXURI) sugerate de design.
3. Folosește-le ca punct de plecare pentru structura Twig — clase Tailwind, paletă, ierarhie. Le poți ajusta unde implementarea cere (ex: integrare cu Live Components, accesibilitate, simplificări MVP).
4. Date dinamice (Twig variables, loops) se integrează firesc în structura sugerată — fără să reinventezi de la zero, dar nici fără să rămâi blocat în mock-up dacă apare un caz mai bun.
5. Dacă mock-up-ul lipsește pentru un caz (ex: status nou, modal de tranziție) → propui interpretarea în reanaliză și continui.

---

## Hartă de dezvoltare

| Pas | Faza | Descriere | Durată est. | Depinde de | % reutilizare | Status |
|-----|------|-----------|-------------|------------|---------------|--------|
| 0.1 | Bootstrap | Branch `lexrecovery` + ștergere cod mort | 0.5z | — | 0% | ✅ |
| 0.2 | Bootstrap | Frontend Foundations: **Preline UI (free, MIT)** + UX bundles (Live Components, Autocomplete, Icons), Mercure Hub container, Stimulus controllers comune (toast, autosave, shortcuts, dark-mode, optimistic, mercure, dialog, tabs, skeleton, **preline-init**), Twig components refolosibile (wrapper-e Preline), Tailwind dark mode, View Transitions API | 1.5z | 0.1 | 0% | ✅ |
| 1.1 | Domain | Entități noi + extindere `LegalCase` | 1.5z | 0.1 | 50% | ✅ |
| 1.2 | Domain | Enum-uri noi (CaseStatus, CaseTransition, PersonType, RelationshipType, DeadlineType, DeadlinePriority) | 0.5z | 0.1 | 0% | ✅ DONE + REVIZIE C2 |
| 1.3 | Domain | Workflow YAML refăcut + ajustare `CaseWorkflowService` | 0.5z | 1.1, 1.2 | 70% | ✅ DONE + REVIZIE C2 |
| 1.4 | Domain | Migrare baseline + fixtures + `app:seed-demo-cases` | 0.5z | 1.1, 1.2 | 80% | ✅ DONE + REVIZIE C2 |
| 1.5 | Domain | Foundație i18n + backfill (enum labels → trans keys, homepage, Stimulus messages, ANAF exceptions) | 1z | 1.4 | 30% | ✅ DONE + REVIZIE C2 |
| 2.1 | Calcule | `InterestCalculatorService` (OG 13/2011) | 0.75z | 1.1 | 0% | ✅ DONE + REVIZIE C3 (Opțiunea a — B2B-only) |
| 2.2 | Calcule | `StampDutyCalculator` (OUG 80/2013) | 0.25z | — | 0% | ✅ |
| 2.3 | Calcule | `CompetentCourtResolver` | 0.5z | 1.1 | 30% | ✅ DONE 2026-05-09 (`8f44e05`) — N1 aplicat (prag 200k pe valoare totală) |
| 2.4 | Calcule | `AnafLookupService` integration + `OpAdmissibilityValidator` (CPC art. 1014, L 85/2014) + Debitor ANAF/BPI fields + rename `taxId`→`cui` & `tradeRegistryNumber`→`onrcNumber` pe Creditor/Debtor | 0.5z | 1.1 | 80% | ✅ DONE 2026-05-09 (`6b9a815`) — N4 enforcement (BPI = ERROR), 27 teste noi |
| 2.5.1 | Extracție | Pre-condiții: enum-uri (`ExtractionMode`, `LegalGroundCategory`) + entity fields (`Document.extractionStrategy`, `User.extractionMode`, `LegalCase.extractionModeOverride`) + migrare | 3h | 1.1 | 0% | ✅ DONE 2026-05-09 (`c9c455d`) |
| 2.5.2 | Extracție | DTO `ExtractedDocumentData` (+ Creditor/Debtor/Claim) + `ExtractionStrategyInterface` + `StubExtractionStrategy` + `DataExtractionService` orchestrator (cu tagged iterator) | 4h | 2.5.1 | 0% | ✅ DONE 2026-05-09 (`a0fe48a`) |
| 2.5.3 | Extracție | `PdfParserExtractionStrategy` (priority 100) — smalot/pdfparser + regex CUI/CNP/sume/date/IBAN cu validare checksum + heuristici contextuale RO | 5h | 2.5.2 | 0% | ✅ DONE 2026-05-10 (`8fc31c3`) |
| 2.5.4 | Extracție | GDPR foundation: `AuditLogService::log()` cu `?string $category` + entity `AuditLog.category` + `PiiMasker` utility (mask/restore CNP + mask CUI) | 3h | 2.5.1 | 30% | ✅ DONE 2026-05-10 (`2300b0a`) |
| 2.5.5 | Extracție | Docker OCR setup (Alpine: tesseract-ocr + tesseract-ocr-data-ron + poppler-utils + imagemagick + ghostscript) + `OcrServiceInterface` + `TesseractOcrService` + `OcrResult` DTO | 4h | — | 0% | ✅ DONE 2026-05-10 (`32262e3`) |
| 2.5.6 | Extracție | `LlmClientInterface` + `AnthropicApiClient implements LlmClientInterface` (HttpClient + DTO `LlmResponse` neutral provider) + rate limiters `extraction_ai_text` (200/zi) + `extraction_ai_vision` (50/zi) + env vars (`ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`, `EXTRACTION_CONFIDENCE_THRESHOLD`) | 3.5h | — | 60% (pattern AnafLookup + OcrServiceInterface) | ✅ DONE 2026-05-10 (`5d1095e`) — Opțiunea 2 (Interface + Impl); 17 teste verzi (15 unit + 2 integration) |
| 2.5.7 | Extracție | `OcrTextExtractionStrategy` (priority 70) — OCR + mask CNP + IBAN round-trip + Claude API text + restore + audit `AI_EXTRACTION` + skip-on-empty-apiKey + extindere `PiiMasker::maskIban/buildIbanMap/restoreIban` | 5h | 2.5.4, 2.5.5, 2.5.6 | 40% (pattern PdfParser + reuse LlmClientInterface) | ✅ DONE 2026-05-10 (`0636006`) — D1 skip + D2 IBAN; 24 teste verzi (14 unit + 3 integration + 2 cascade Nivel 3 + 5 PiiMasker IBAN) |
| 2.5.8 | Extracție | `AiVisionExtractionStrategy` (priority 50) — Claude vision direct pe document + skip pe LOCAL_ONLY + audit + cascadă completă funcțională end-to-end | 4h | 2.5.4, 2.5.6 | 50% (pattern OcrText) | ⏳ |
| 2.6 | Extracție | `ExtractDataMessage` async (Symfony Messenger) + handler + persist `Document.extractedData` + emit `DataExtractedEvent` | 0.5z | 2.5.8 | 30% | ⏳ |
| 3.0 | Wizard | Step 0 wizard "Documente sursă": upload + procesare async + preview valori extrase + Turbo Stream polling status | 1z | 2.5.8, 2.6 | 0% | ⏳ |
| 3.1 | Wizard | DTOs + Forms 5 pași (Documente, Creditor, Debitor, Creanță, Confirmare) cu pre-populare din `Document.extractedData` + indicator vizual câmp auto-completat | 1z | 1.1, 2.1-2.3, 3.0 | 50% | ⏳ |
| 3.2 | Wizard | `CaseWizardController` + session storage + templates | 1z | 3.1 | 60% | ⏳ |
| 3.3 | Wizard | Stimulus controllers (`live-calc`, `creditor-search`, `debtor-search`) + endpoint-uri AJAX | 1z | 3.2, 2.1-2.4 | 20% | ⏳ |
| 4.1 | Termene | `DeadlineService` (creare automată somație/cerere în anulare/prescripție) | 0.5z | 1.1 | 0% | ⏳ |
| 4.2 | Termene | `DeadlineCreationSubscriber` + refactor `CaseWorkflowSubscriber`→`CaseWorkflowSubscriber` | 0.5z | 4.1, 1.3 | 80% | ⏳ |
| 4.3 | Termene | UI tab "Termene" în view dosar + mark complete | 0.5z | 4.1 | 0% | ⏳ |
| 5.1 | PDF | `PaymentNoticeGeneratorService` + template `payment_notice.html.twig` | 0.5z | 1.1 | 80% | ⏳ |
| 5.2 | PDF | `PaymentOrderRequestGeneratorService` + opis + `CaseFilesPackager` | 1z | 5.1 | 50% | ⏳ |
| 6.1 | Monitorizare | `PortalMonitoringSubscriber` + adaptare `CaseMonitoringService`/`PortalEventDetector` | 0.5z | 1.1, 1.3 | 95% | ⏳ |
| 6.2 | Monitorizare | `DeadlineAlertService` + `app:check-deadlines` + `app:portal-check-all` | 0.75z | 4.1, 6.1 | 70% | ⏳ |
| 6.3 | Monitorizare | `EmailNotificationSubscriber` + templates email | 0.75z | 1.3, 4.1 | 30% | ⏳ |
| 7.1 | UI | Dashboard avocat (filtre, urgențe, search) | 1z | 1.1, 4.1 | 50% | ⏳ |
| 7.2 | UI | View dosar cu tab-uri (Detalii / Documente / Termene / Activitate Portal / Audit) | 0.75z | 7.1, 4.3 | 40% | ⏳ |
| 7.3 | UI | EasyAdmin: rename + CRUDs noi (Plan, Subscription, Invoice, InterestRateConfig) | 0.25z | 1.1 | 100% (pattern) | ⏳ |
| 8.1 | Monetizare | `SubscriptionService` + `InvoicingService` (logica de consum) | 1z | 1.1 | 30% | ⏳ |
| 8.2 | Monetizare | `PaymentGatewayInterface` + stub + UI `/abonament`, `/facturile-mele` | 0.5z | 8.1 | 0% | ⏳ |
| 9.1 | Deploy | Coolify staging + cron setup | 1z | 8.x | 80% | ⏳ |

> **Pași marcați ca paralelizabili**: 1.1 ‖ 1.2; 2.1 ‖ 2.2 ‖ 2.3 ‖ 2.4; 2.5.5 ‖ 2.5.6 (după 2.5.4); **Faza 5 ‖ Faza 6 după Faza 4**; 7.3 ‖ Faza 8.

---

## Reguli pentru lucrul cu Claude Code

- **Un pas = un prompt.** Nu combina mai mulți pași într-un singur prompt.
- **Citește întâi specificația.** La începutul fiecărui prompt, atașează secțiunea relevantă din `ANALIZA-FLUXURI-LEXRECOVERY.md`.
- **Reanalizează fezabilitatea pasului înainte să-l execuți.** Verifică dacă pasul mai are sens în starea curentă a codului: dependențele s-au schimbat? specificația a evoluat? există o cale mai simplă acum (ex: un pachet nou, un cod existent care acoperă deja parte din scope)? Estimarea de durată și `% reutilizare` sunt încă realiste? Dacă răspunsul la oricare e da, ajustează abordarea sau ridică problema **înainte** să scrii cod — nu execuții oarbe pe baza planului inițial.
- **Verifică după fiecare pas.** Rulează aplicația, testează manual, apoi treci mai departe.
- **Commit după fiecare pas — DAR cere confirmare explicită înainte.** După ce pasul e implementat și verificat (teste verzi, manual sanity), Claude **NU rulează `git commit` din proprie inițiativă**. Pregătește mesajul de commit și `git status`, apoi întreabă user-ul ("Comit acum cu mesajul `<...>`?"). User-ul răspunde cu "commit", "da", "ok" sau alt acord explicit înainte ca `git add` + `git commit` să ruleze. Excepție: dacă user-ul a dat în prompt-ul curent acord standing ("execută complet pasul X.Y, inclusiv commit"), poți comite direct. Motivație: commit-urile sunt acțiuni vizibile în istoric care, deși local-only, se cer revizuite de user înainte să devină permanente — `git reset` e mai costisitor decât 5 secunde de confirmare.
- **Marchează pasul DONE în acest fișier — în 3 locuri.** Imediat după ce commit-ul aterizează:
  1. **Tabelul "Hartă de dezvoltare"** (sus): schimbă coloana Status de la `⏳` la `✅` pentru pasul respectiv.
  2. **Heading-ul secțiunii pasului** (`### PASUL X.Y | ...`): adaugă sufixul `✅ DONE YYYY-MM-DD (\`<commit-sha>\`)`.
  3. **Linia `**Rezultat**:`** sub heading: înlocuiește placeholder-ul `_(va fi completat la marcarea ca DONE)_` cu 1–2 propoziții despre ce s-a livrat + decizii cheie + pointer la `~/.claude/projects/.../memory/project_lexrecovery_pas_X_Y.md`.
  Plan-ul și memoria împreună sunt source-of-truth pentru starea proiectului între sesiuni.
- **Dă context.** Menționează ce s-a făcut deja și care e starea curentă a branch-ului.
- **Teste incremental.** Scrie teste unitare pentru logica de business și teste funcționale pentru controller-e.
- **🔴 Testare riguroasă pe 3 niveluri pentru orice cod care procesează input extern (PDF, OCR, AI, ANAF, BPI, portal, email, fișiere uploadate).** Stabilit la Pas 2.5.3 (commit `86dd6df` + `7bdc150`) după ce 2 bug-uri critice au scăpat de tests unitare cu fixturi triviale și au fost prinse abia pe documente realiste (substring digits din IBAN tratate ca CUI, "art. 1014 CPC" prinsă ca CUI 4-cifre, data emiterii confundată cu scadența). Regula:
  1. **Unit tests** (`TestCase` pur, no kernel) — logica algoritmică (regex, checksum, parsing) cu fixturi minime/sintetice (generate la runtime, ex. DomPDF din HTML). Acoperă edge cases controlabile.
  2. **Integration tests per-componentă** (`TestCase` sau `KernelTestCase`) — un singur service real (NU mocks/fakes) împotriva fixturilor **statice REALISTE** committed în git. Pentru documente: layout complex (header companie, tabel line items, multipage, sections numerotate, footer cu IBAN). Generate o singură dată via script `tests/fixtures/<feature>/generate-fixtures.php` și committed binar pentru determinism.
  3. **End-to-end / cascade tests** (`TestCase` cu DI manuală, no mocks) — toate piesele integrate: orchestrator + toate strategiile reale + fixturi statice. Acoperă contractul "tagged iterator → priority sort → mode resolution → supports → extract → confidence threshold → persist".
- **🔴 Lessons learned de la 2.5.3 (citat în memory `feedback_test_coverage_3_layers.md`)**: testele unitare cu fixturi triviale **pierd pattern-uri uzuale ale documentelor reale**. Bug-uri descoperite la rulare pe fixturi reale → fix imediat în cod + adăugare regression test diferential (CNP `1980715221232` ales ca să departajeze între ponderi corecte și ponderi greșite). NICIODATĂ nu marca un sub-pas DONE doar pe baza tests unitare cu PDF generated-trivial dacă feature-ul lucrează cu documente reale (PDF, imagini scanate, etc.).
- **🔴 Aplicabilitate selectivă per sub-pas — NU blanket pe toți pași**. Regula se aplică **doar pe sub-pașii care procesează input extern** (parsing fișiere uploadate, HTTP API integrators, OCR pe imagini, AI calls, SOAP portal). Mapare explicită per sub-pas (cu nivel obligatoriu marcat 🔴 / 🟡 / Nivel 1) în memory persistentă `feedback_test_coverage_3_layers.md` secțiunea "Mapare explicită pentru toți sub-pașii rămași în plan". Sub-pașii 🔴 (toate 3 niveluri) pentru Pas 2.5+: **2.5.5** (Tesseract), **2.5.6** (AnthropicApiClient), **2.5.7** (OcrText), **2.5.8** (AiVision), **3.0** (wizard upload), **6.1** (portal SOAP), **8.2 implementare reală Netopia**. Restul sub-pași: Nivel 1 sau 1+2 — logică pură, DTOs, controllers fără I/O extern. La fiecare trigger "Execută Pasul X.Y", se consultă tabelul din memory pentru a stabili nivelul aplicabil ÎNAINTE de a scrie cod. Reviewer-ul code-reviewer respinge sub-pași 🔴 fără integration + cascade tests pe fixturi reale.
- **Re-verifică reutilizarea.** Înainte să scrii cod nou, verifică fișierele marcate ca "refolosibile" în `ANALIZA-FLUXURI-LEXRECOVERY.md` secțiunea 15.
- **Identificatori în engleză, cu termeni potriviți contextului proiectului.** Numele de clase, metode, variabile, constante și fișiere se scriu în engleză, alegând termeni cât mai aproape de domeniul juridic/de recuperare creanțe (`LegalCase`, `Creditor`, `Debtor`, `LegalDeadline`, `lawyer`, `caseNumber`, `EnumMarkingStore`). Excepție: dacă un termen românesc are sens legal/operațional clar și nu are echivalent englez bun, **se păstrează în română** — ex: valorile enum `App\Enum\CaseStatus::SOMATIE_TRIMISA`, `App\Enum\DeadlineType::RASPUNS_SOMATIE`, `App\Enum\CaseTransition::trimite_somatie`, `barNumber: 'B-12345'`. Stringurile UI (Twig templates, flash messages, EasyAdmin labels, command `description:`) rămân în română — audiența e română.
- **Stringuri user-facing non-admin prin translator.** Orice text afișat utilizatorului final (avocat în dashboard/wizard/view dosar, vizitator pe homepage, conținut email, mesaje din Stimulus controllers, exception messages user-friendly) se adaugă ca **chei de traducere** în `translations/messages.ro.yaml` (+ best-effort în `messages.en.yaml`), nu literali în cod. Convenție chei: `dot.notation` ierarhic (`enum.case_status.SOMATIE_TRIMISA`, `home.hero.title`, `flash.case.transition_invalid`, `exception.anaf.cui_invalid`). **Excepție**: stringurile **admin-only** (EasyAdmin entity/field/action labels, template-uri sub `templates/admin/`, flash messages din admin controllers) pot rămâne hardcoded în română — adminul e audiență tech, nu va consuma EN. Chei tehnice (CSRF token errors, log messages, exception messages internal) rămân în engleză literal. Enum-urile expun `label()` care returnează **cheia** (`'enum.case_status.SOMATIE_TRIMISA'`), iar caller-ul aplică `|trans` (Twig) sau `$translator->trans()` (PHP) — nu inject translator în enum.

---

## Strategia bazei de date

**Decizie**: pe branch-ul `lexrecovery` facem **drop + recreate** — nu migrare progresivă.

Motivație: schimbările sunt prea profunde (rename `LegalCase`→`LegalCase`, JSON `defendants` → entitate `Debtor`, enum-uri rescrise, entități noi pentru monetizare). O migrare incrementală peste cele 7 migrări existente (`Version20251124214729` … `Version20260227134617`) ar produce o istorie incoerentă.

**Procedură**:
1. La Pas 0.1: șterge toate `migrations/Version*.php` vechi.
2. La Pas 1.4: după ce entitățile sunt fixate, generează **o singură migrare baseline**: `bin/console make:migration` → `Version2026XXXX_lexrecovery_baseline.php`.
3. În dev (Docker): `make composer && bin/console doctrine:database:drop --force && bin/console doctrine:database:create && bin/console doctrine:migrations:migrate`.
4. Seed-uri:
   - `app:import-courts` (existent, refolosit ca atare).
   - `app:create-test-users` (extins cu un avocat de test cu `barNumber`).
   - `app:seed-demo-cases` (nou — 3-5 dosare în statusuri diferite pentru screenshots și teste manuale).
5. Test DB: `phpunit.dist.xml` are deja setup; după baseline, `bin/console --env=test doctrine:schema:create` în CI înainte de teste.

**La merge `lexrecovery` → `develop`** (post-validare business): regenerăm baseline curat. Datele MVP-ului anterior se pierd — acceptabil deoarece pivotul e strategic.

---

## Faza 0: Bootstrap

### PASUL 0.1 | Branch `lexrecovery` + ștergere cod mort | 0.5 zi | 0% reutilizare ✅ DONE 2026-05-07 (`23c82e1`)

**Rezultat**: Branch `lexrecovery` creat; șters codul small-claims din spec (TaxCalculator, CaseWizardController, PaymentController, CaseSubmissionService, PaymentProcessingService, Payment entity+repo, PaymentType/PaymentStatus enums, toate Step* DTO/Form, templates `_step*.twig`/`cerere_valoare_redusa.twig`/`payment.twig`, toate `migrations/Version*.php`, 4 teste vizate). Păstrate intacte toate dependențele din lista "NU șterge". `LegalCase` a pierdut `OneToMany Payment`; `DashboardController` a scos widgeturile Payment. Aplicația bootează, parse errors zero. Detalii: `~/.claude/projects/-Users-alexc-Downloads-myprojects-symfony-mvp/memory/project_lexrecovery_pas_0_1.md`.

**Scop**: pornim un branch curat și eliminăm codul care nu mai e relevant pentru LexRecovery.

**PROMPT**:
> Pornind din branch-ul `develop`, creează branch nou `lexrecovery` și execută următoarele ștergeri:
>
> Fișiere cod sursă:
> - `src/Service/Case/TaxCalculatorService.php`
> - `src/Controller/Case/CaseWizardController.php`
> - `src/DTO/Case/Step*.php` (toate fișierele Step1*, Step2*, Step3*, Step4*, Step5*, Step6*)
> - `src/Form/Case/Step*.php` (toate Step*Type)
> - `src/Service/Case/CaseSubmissionService.php` (logica e specifică small claims)
> - `src/Service/Payment/PaymentProcessingService.php` (înlocuit ulterior cu InvoicingService)
> - `src/Controller/Case/PaymentController.php` (înlocuit ulterior cu SubscriptionController)
> - `src/Entity/Payment.php` + repository-ul (fuzionat în `Invoice` ulterior)
> - `src/Enum/PaymentType.php`, `PaymentStatus.php` (vor fi înlocuite)
>
> Templates:
> - `templates/case/_step*.html.twig`
> - `templates/pdf/cerere_valoare_redusa.html.twig`
> - `templates/case/payment.html.twig`
>
> Migrări: șterge **toate** fișierele din `migrations/Version*.php`.
>
> Teste: șterge testele care depindeau strict de cod șters: `tests/Service/Case/TaxCalculatorServiceTest.php`, `tests/Controller/Case/CaseWizardControllerTest.php`, `tests/Controller/Case/PaymentControllerTest.php`, `tests/Service/Payment/PaymentProcessingServiceTest.php`. Lasă restul testelor.
>
> NU șterge: User, Court, Document, AuditLog, Notification, CaseStatusHistory, CourtPortalEvent, AnafLookupService, PortalJustClient, CaseMonitoringService, PortalEventDetector, AuditLogService, DocumentUploadService, CaseWorkflowService, CaseVoter, RegistrationController, ResetPasswordController, EasyAdmin DashboardController, RateLimiter config, fișierele de auth.
>
> După ștergere, rulează `composer dump-autoload` și `php bin/console cache:clear`. Verifică prin `php bin/console list` că aplicația încă bootează (multe controllere vor da erori — e ok, vor fi rezolvate la pașii următori).
>
> Commit: `chore(lexrecovery): bootstrap branch — remove small claims code`.

**VERIFICARE MANUALĂ**:
- [ ] Branch `lexrecovery` activ
- [ ] `composer dump-autoload` rulează fără fatal errors
- [ ] `php bin/console cache:clear` rulează fără fatal errors
- [ ] Suite-ul existent de teste poate fi rulat cu `make test` (poate avea erori, dar fără fatal/parse errors)

---

### PASUL 0.2 | Frontend Foundations | 1.5 zile | 0% reutilizare (de la zero) ✅ DONE 2026-05-08 (`4f0a562`)

**Rezultat**: UX foundation livrat: pachete `ux-live-component`/`ux-autocomplete`/`ux-icons`/`mercure-bundle` + Preline (importmap), 10 Stimulus controllers comuni (toast, autosave, dark-mode-toggle, optimistic-action, mercure, dialog, tabs, confirm, skeleton, preline-init), Tailwind v4 cu dark mode + view transitions, container Mercure în `compose.yaml` cu healthcheck în `docker-entrypoint.sh`, 7/7 Twig components (EmptyState, Toast, StatusBadge, Stepper, Skeleton, ConfirmDialog, DeadlineCard), `ToastService` + `MercureTokenService` + extensia Twig `MercureExtension`, base layout updatat, 3 teste (Toast, Mercure, smoke layout). **Out of scope (decizie user 2026-05-08)**: `keyboard-shortcuts_controller.js` + modal help — tăiate; verificarea manuală corespunzătoare nu se aplică. Detalii: `~/.claude/projects/-Users-alexc-Downloads-myprojects-symfony-mvp/memory/project_lexrecovery_pas_0_2.md`.

**Scop**: livrăm o singură dată fundația UX folosită de toate fazele ulterioare — bibliotecă de componente Preline UI, bundle-uri Symfony UX, container Mercure, Stimulus controllers comune, Twig components refolosibile, Tailwind dark mode. Toate fără npm/Node.js.

**Specificație**: secțiunea 12 din `ANALIZA-FLUXURI-LEXRECOVERY.md`. Decizia de bibliotecă: **Preline UI (free, MIT)** — vezi §12.6.

**Referință vizuală**: mock-up-urile din `docs/LexRecovery/mockups/v2/` sunt sugestive — folosește-le ca direcție pentru paleta de culori (primary, accent, surface), tipografie, spațiere, header/footer pattern. Componentele Twig din pasul curent (Stepper, StatusBadge, EmptyState, Skeleton, Toast etc.) se inspiră din ele, cu libertatea de a ajusta unde e justificat tehnic.

**PROMPT**:
> 1. **Composer + Importmap require**:
>    ```bash
>    composer require symfony/ux-live-component symfony/ux-autocomplete symfony/ux-icons symfony/mercure-bundle
>    php bin/console importmap:require @symfony/ux-live-component @symfony/ux-autocomplete
>    php bin/console importmap:require preline
>    ```
>    Pachet iconuri: `composer require ux/heroicons` sau referință direct CDN-ul Heroicons în `ux_icons.yaml`.
>    Pentru Preline: import în `assets/app.js` (`import 'preline'`) și plugin Tailwind în `app.css` (vezi pasul 3).
>
> 2. **Stimulus controllers comune** în `assets/controllers/` (livrate o singură dată, refolosite în toate fazele):
>    - `toast_controller.js` — afișare toast bottom-right cu stack + auto-dismiss 4s, ascultă `window` event `toast:show` cu `{message, variant}`.
>    - `autosave_controller.js` — debounce blur 500ms, POST la `data-autosave-url-value`, indicator vizual "Salvat la HH:MM" în `data-autosave-indicator-target`.
>    - `keyboard-shortcuts_controller.js` — global pe `<body>`. Mapare: `g d` → /dashboard, `g n` → /case/new, `/` → focus search, `?` → modal help, `esc` → close modal.
>    - `dark-mode-toggle_controller.js` — toggle class `dark` pe `<html>`, persistă în localStorage, sync cu `prefers-color-scheme` la prima încărcare.
>    - `optimistic-action_controller.js` — pattern: aplică UI change instant + Turbo Stream pentru revert pe error (folosit la mark complete termen, toggle citit notificare).
>    - `mercure_controller.js` — subscribe la topic via `data-mercure-topic-value`, dispatch DOM events când vin mesaje.
>    - `dialog_controller.js` — modal cu focus trap, close on esc, click-outside-to-close.
>    - `tabs_controller.js` — tab navigation cu Turbo Frames lazy-load + URL hash sync.
>    - `confirm_controller.js` — confirmation dialog înainte de submit destructive (`data-confirm-message-value`).
>    - `skeleton_controller.js` — show/hide elemente cu `data-skeleton-target` în funcție de state-ul Turbo Frame loading.
>    - `preline-init_controller.js` — reinit Preline pe `turbo:load` event (autoinit-ul Preline e pe `DOMContentLoaded`, care nu se emite la navigare Turbo Drive). Implementare: `connect()` ascultă `turbo:load` pe `document` și apelează `window.HSStaticMethods.autoInit()`. Atașat global pe `<body>` în `base.html.twig`.
>
> 3. **Tailwind v4 dark mode + plugin Preline**: în `assets/styles/app.css`:
>    ```css
>    @import "tailwindcss";
>    @plugin "preline/plugin";
>    @custom-variant dark (&:where(.dark, .dark *));
>    ```
>    Configurare class-based (controlată de `dark-mode-toggle`). Plugin-ul Preline expune utilities pentru componentele lui (`hs-overlay`, `hs-dropdown`, `hs-toast`, `hs-skeleton` etc.) și se integrează cu dark mode automat.
>
> 4. **Mercure Hub container** — update `compose.yaml`:
>    ```yaml
>    mercure:
>      image: dunglas/mercure
>      restart: unless-stopped
>      environment:
>        SERVER_NAME: ':80'
>        MERCURE_PUBLISHER_JWT_KEY: '${MERCURE_JWT_SECRET}'
>        MERCURE_SUBSCRIBER_JWT_KEY: '${MERCURE_JWT_SECRET}'
>        MERCURE_EXTRA_DIRECTIVES: |
>          cors_origins http://localhost:8080
>          anonymous
>      ports: ['3000:80']
>    ```
>    Update `.env`:
>    ```
>    MERCURE_URL=http://mercure/.well-known/mercure
>    MERCURE_PUBLIC_URL=http://localhost:3000/.well-known/mercure
>    MERCURE_JWT_SECRET=!ChangeThisInProd!aMinimum32CharsSecret!
>    ```
>
> 5. **Twig components refolosibile** în `templates/components/` (toate sunt **wrapper-e peste primitive Preline** cu logică LexRecovery-specific peste):
>    - `EmptyState.html.twig` — props: `icon`, `title`, `description`, `cta_label?`, `cta_url?`. Folosit pe dashboard gol, view dosar fără docs, notificări goale.
>    - `Toast.html.twig` — fragment renderable peste `hs-toast`.
>    - `StatusBadge.html.twig` — prop `status` (CaseStatus), randare badge Preline cu culoare din `CaseStatus::color()`.
>    - `Stepper.html.twig` — prop `currentStep`, `totalSteps`, `labels[]`. Wizard stepper peste primitiva Preline `hs-stepper`.
>    - `Skeleton.html.twig` — props `lines`, `width` — placeholder pulsant peste `hs-skeleton`.
>    - `ConfirmDialog.html.twig` — wrapper Stimulus `confirm` controller peste Preline `hs-overlay`.
>    - `DeadlineCard.html.twig` — card termen cu prioritate vizuală (folosit la Pas 4.3).
>
> 6. **`ToastService`** `src/Service/Ui/ToastService.php`:
>    ```php
>    public function success(string $message): void
>    public function error(string $message): void
>    public function warning(string $message): void
>    public function info(string $message): void
>    ```
>    Setează în session flash + (optional) emit Mercure pentru toast push real-time. Twig template `base.html.twig` randează toast-urile la load + ascultă `toast:show` event pentru toast-uri runtime.
>
> 7. **`base.html.twig`** updates:
>    - Header cu dark mode toggle button.
>    - Body cu `data-controller="keyboard-shortcuts preline-init"` (multi-controller pe același element).
>    - Container fix bottom-right pentru toast-uri.
>    - Default Mercure subscribe global pentru `user/{id}/notification` (dacă logat).
>    - Meta tag pentru View Transitions: `<meta name="view-transition" content="same-origin">`.
>    - Modal help cu lista shortcuts (afișat la `?`).
>
> 8. **CSS view transitions** în `app.css`:
>    ```css
>    @view-transition { navigation: auto; }
>    ::view-transition-old(root), ::view-transition-new(root) {
>      animation-duration: 200ms;
>      animation-timing-function: ease-out;
>    }
>    ```
>
> 9. **Mercure JWT generator** `src/Service/Mercure/MercureTokenService.php` — generează JWT cu topic-uri restrictive per user (avocatul X primește doar topic-uri pentru dosarele lui).
>
> 10. **Helper Twig** pentru subscribe Mercure în template-uri:
>    ```twig
>    <div data-controller="mercure"
>         data-mercure-topic-value="{{ mercure_topic_for_case(legalCase) }}"
>         data-mercure-token-value="{{ mercure_token_for_user(app.user) }}">
>    ```
>
> 11. **Update `docker-entrypoint.sh`** — verificare așteptare Mercure Hub disponibil (curl la http://mercure/.well-known/mercure înainte să continue).
>
> Teste:
> - `tests/Service/Ui/ToastServiceTest.php` — verificare flash + Mercure publish.
> - `tests/Service/Mercure/MercureTokenServiceTest.php` — JWT corect generat cu topic restrictions.
> - Smoke test funcțional: `base.html.twig` render fără erori cu user logat și cu user anonim.
>
> Commit: `feat(ux): foundations — Live Components + Autocomplete + Mercure + Stimulus library + Twig components`.

**VERIFICARE MANUALĂ**:
- [ ] Bundle-urile UX se încarcă fără erori.
- [ ] Componentele Preline funcționează (test: dropdown + modal + datepicker pe o pagină de smoke test).
- [ ] Componentele Preline rămân funcționale după navigare Turbo (click pe link → dropdown se redeschide corect, fără reload).
- [ ] Dark mode toggle funcționează și persistă (componentele Preline comută corect culorile).
- [ ] Toast notification apare la flash success.
- [ ] Keyboard shortcuts `g d` redirect la dashboard.
- [ ] Mercure container rulează (`docker compose ps mercure`).
- [ ] EventSource în browser se conectează la Mercure (vezi în DevTools Network → EventStream).
- [ ] View transitions vizibile pe Chrome la navigare între pagini.

**TESTE MINIME**: 3 teste (ToastService, MercureTokenService, smoke base layout).

---

## Faza 1: Domain Model + DB Baseline

### PASUL 1.1 | Entități noi + extindere `LegalCase` | 1.5 zile | 50% reutilizare ✅ DONE 2026-05-08 (`349a440`)

**Rezultat**: `LegalCase` extinsă cu cele 17+ câmpuri din spec (caseNumber generat ca `LR-{timestamp}-{rand4}` în constructor, `markAsDeleted()` + `isDeleted()`, lifecycle `PreUpdate` pe `updatedAt`). 7 entități noi cu mapping Doctrine corect: `Creditor`, `Debtor`, `LegalDeadline`, `InterestRateConfig`, `Plan`, `Subscription`, `Invoice`. Repositories pentru fiecare; `LegalCaseRepository` are `findActiveByUser()` + `findByStatusGroupedByUrgency()`. `Notification` extinsă cu FK opțional spre LegalCase; `Document` extins cu `extractedData`/`extractionStatus`/`extractionConfidence` + index. 8 teste de entitate. Naming `legal_case`/`LegalCase` păstrat intenționat (decizie user) în loc de rename la `dosar`/`Dosar`. Patch ulterior: `InterestRateConfig.validFrom` aliniat la `DATE_IMMUTABLE` (2026-05-08). Detalii: `~/.claude/projects/-Users-alexc-Downloads-myprojects-symfony-mvp/memory/project_lexrecovery_pas_1_1.md`.

**Scop**: definim modelul de domeniu LexRecovery — entități noi (Creditor, Debitor, Termen, InterestRateConfig, Plan, Subscription, Invoice) și restructurăm `LegalCase` în `LegalCase`.

**Specificație**: secțiunea 5 din `ANALIZA-FLUXURI-LEXRECOVERY.md`.

**PROMPT**:
> Implementează modelul de domeniu LexRecovery conform secțiunii 5 din `docs/LexRecovery/ANALIZA-FLUXURI-LEXRECOVERY.md`.
>
> 1. **Extinde `src/Entity/LegalCase.php`** existent (refolosim entitatea, scoatem JSON `defendants` care devine entitate `Debtor` separată). Câmpuri finale: `caseNumber` (string, unique, generat la persist), `user` (FK), `court` (FK), `creditor` (FK), `debtors` (OneToMany către Debtor), `status` (string mapped la `CaseStatus` enum — vine la Pas 1.2), `relationshipType` (string mapped la `RelationshipType`), `amount` (decimal 12,2), `currency` (string default RON), `calculatedInterest` (decimal 12,2 nullable), `stampDuty` (decimal 8,2 nullable), `dueDate` (date), `paymentNoticeDate` (date nullable), `courtCaseNumber` (string nullable), `hearingDate` (date nullable), `finalRulingDate` (date nullable), `notes` (text nullable), `createdAt`, `updatedAt`, `deletedAt` (soft delete păstrat).
>
> 2. **Entități noi** (toate cu `id` int auto-increment + `createdAt`/`updatedAt`):
>    - `Creditor`: user (FK, owner), personType (string mapped la `PersonType`), name, taxId (nullable, unique compus user+taxId — corespunde CUI), personalId (nullable — corespunde CNP), tradeRegistryNumber (nullable — corespunde nr. ONRC), address, email (nullable), phone (nullable), iban (nullable), legalRepresentative (nullable, doar PJ).
>    - `Debtor`: legalCase (FK), personType, name, taxId (nullable), personalId (nullable), tradeRegistryNumber (nullable), address, email (nullable), phone (nullable), iban (nullable), administrator (nullable), onrcStatus (string nullable: ACTIVE/DISSOLVED/INSOLVENT/null).
>      - 🔴 **REVIZIE 2026-05-08** (vezi Pas 2.4): `onrcStatus` se redenumește în `anafStatus` (sursa reală e ANAF API, nu ONRC); valori reale tipate cu enum `AnafStatus` = ACTIV / INACTIV / RADIAT (NU ACTIVE/DISSOLVED/INSOLVENT cum era spec-ul inițial). Câmpuri suplimentare: `anafCheckedAt`, `nrRegCom`, `inInsolvency` (manual din BPI), `insolvencyCheckedAt`, `bpiProofDocumentId`. Migrarea concretă a tipului + rename-ul se aplică la execuția Pas 2.4.
>    - `LegalDeadline`: legalCase (FK), type (string mapped la `DeadlineType`), deadlineDate (date), description (string nullable), priority (string mapped la `DeadlinePriority`, default MEDIUM), completed (boolean default false), completedAt (datetime nullable), alertSent7 (boolean default false), alertSent3 (boolean default false), alertSent1 (boolean default false), alertSentExpired (boolean default false).
>    - `InterestRateConfig`: validFrom (date, unique), referenceRate (decimal 5,2) — istoric BNR.
>    - `Plan`: name (string unique), priceMonthly (decimal 8,2), includedCases (int), pricePerExtra (decimal 8,2), isActive (boolean default true).
>    - `Subscription`: user (FK), plan (FK), status (string: active/cancelled/past_due), currentPeriodStart (datetime), currentPeriodEnd (datetime), casesConsumed (int default 0), externalId (string nullable — gateway ref).
>    - `Invoice`: user (FK), subscription (FK nullable), legalCase (FK nullable, doar pentru `case_extra`), amount (decimal 8,2), type (string: subscription/case_extra), status (string: pending/paid/failed), paidAt (datetime nullable), externalId (string nullable).
>
> 3. **Repositories** corespunzătoare în `src/Repository/`: pentru fiecare entitate nouă + extindere `LegalCaseRepository` existent cu metode noi `findActiveByUser(User $user)`, `findByStatusGroupedByUrgency(User $user)`.
>
> 4. **Update Notification entity**: adaugă FK opțional către `LegalCase` (`legal_case_id` nullable). Restul rămâne neatins.
>
>    **Update Document entity**: adaugă câmpuri pentru extracție automată (vezi Pas 2.5):
>    - `extractedData` (json nullable) — JSON cu date extrase (creditor, debtor, claim + confidence per câmp).
>    - `extractionStatus` (string default 'PENDING', mapped la `ExtractionStatus` enum: PENDING/PROCESSING/COMPLETED/FAILED).
>    - `extractionConfidence` (decimal 3,2 nullable) — global confidence score 0-1.
>    - Index pe `extractionStatus` pentru query rapid în endpoint polling.
>
> 5. **Soft delete pe LegalCase** (păstrat ca atare): metoda `markAsDeleted()` setează `deletedAt = now()`.
>
> 6. **Lifecycle callbacks**: `LegalCase::__construct()` setează `caseNumber` = "LR-{timestamp}-{rand4}" sau similar.
>
> 7. **Audit log** la creare/modificare LegalCase: nu adăuga listener încă — vine în Pas 4.2 cu `CaseWorkflowSubscriber`.
>
> 8. **Teste unitare** în `tests/Entity/`: pentru fiecare entitate nouă verifică getters/setters, pentru `LegalCase` verifică `caseNumber` generat automat la `__construct`.
>
> NU genera migrare încă — vine la Pas 1.4 după ce și enum-urile sunt fixate.
>
> Commit: `feat(domain): add Dosar/Creditor/Debitor/Termen + monetization entities`.

**VERIFICARE MANUALĂ**:
- [ ] `bin/console doctrine:schema:validate` raportează doar diff schema vs entități (fără erori de mapping).
- [ ] Toate testele unitare entități trec.
- [ ] Repository-urile sunt autowired corect (`bin/console debug:autowiring | grep Repository`).

**TESTE MINIME**: 1 test unitar per entitate nouă pentru getters/setters și `caseNumber` auto-gen la `LegalCase`.

---

### PASUL 1.2 | Enum-uri noi | 0.5 zi | 0% reutilizare | **paralel cu 1.1** ✅ DONE 2026-05-08 (`70c92f9`) + REVIZIE C2 aplicată 2026-05-09 (`f5a9214`)

**Rezultat**: 8 enum-uri create în `src/Enum/`: `CaseStatus` (12 valori cu `label`/`color`/`isTerminal`/`isActiveOnPortal`), `CaseTransition` (12 tranziții), `PersonType` (PF/PJ), `RelationshipType` (COMERCIAL/CIVIL cu `nbrPercentagePoints` 8/4), `DeadlineType` (6 valori cu `defaultPrioritate`), `DeadlinePriority` (LOW/MEDIUM/HIGH/CRITICAL cu `color`), `ExtractionStatus` (PENDING/PROCESSING/COMPLETED/FAILED — cerut implicit de Pas 1.1). `DocumentType` extins cu SOMATIE/CERERE_OP/OPIS/DOVADA_COMUNICARE/ACT_CONSTATATOR/ANEXA; CERERE_PDF șters; valorile vechi (DOVADA, CONTRACT, FACTURA, ALT_DOCUMENT) păstrate. Vechiul `CaseStatus`/`CaseTransition` small-claims șters. 8 teste de enum. Detalii: `~/.claude/projects/-Users-alexc-Downloads-myprojects-symfony-mvp/memory/project_lexrecovery_pas_1_2.md`.

**Scop**: definim enum-urile specifice LexRecovery.

**PROMPT**:
> Creează în `src/Enum/`:
> - `CaseStatus` (PHP backed string enum) cu cele 12 valori: AMIABIL, SOMATIE_TRIMISA, CERERE_DEPUSA, DOSAR_INREGISTRAT, TERMEN_FIXAT, ORDONANTA_EMISA, CONTESTATA, DEFINITIVA, EXECUTARE, RESPINSA, INCHIS_SUCCES, INCHIS_PARTIAL_INSOLVABIL. Helper-i: `label()`, `color()` (pentru badge UI), `isTerminal()`, `isActiveOnPortal()` (returnează true pentru DOSAR_INREGISTRAT, TERMEN_FIXAT, ORDONANTA_EMISA, CONTESTATA).
> - `CaseTransition` (PHP backed string enum) cu cele 12 tranziții: trimite_somatie, depune_cerere, inregistreaza_dosar, fixeaza_termen, emite_ordonanta, contesta, respinge_contestatie, admite_contestatie, marcheaza_definitiva, respinge, inchide_succes, inchide_insolvabil.
> - `PersonType`: PF, PJ. Helper `label()`.
> - `RelationshipType`: COMERCIAL, CIVIL. Helper `label()` și `nbrPercentagePoints()` (returnează 8 pentru COMERCIAL, 4 pentru CIVIL).
> - `DeadlineType`: RASPUNS_SOMATIE, DEPUNERE_CERERE, JUDECATA, CONTESTATIE, PRESCRIPTIE, OTHER. Helper `label()`, `defaultPrioritate()`.
> - `DeadlinePriority`: LOW, MEDIUM, HIGH, CRITICAL. Helper `label()`, `color()`.
>
> Extinde `DocumentType` (existent) cu valorile noi: SOMATIE, CERERE_OP, OPIS, DOVADA_COMUNICARE, ACT_CONSTATATOR, ANEXA. Păstrează valorile existente (DOVADA, CONTRACT, FACTURA, ALT_DOCUMENT) — pot fi folosite și în LexRecovery. Șterge CERERE_PDF (era specific small claims).
>
> Șterge `src/Enum/CaseStatus.php` și `src/Enum/CaseTransition.php`.
>
> Update entitățile la Pas 1.1 ca să folosească aceste enum-uri (Doctrine enum mapping).
>
> Teste: `tests/Enum/` — un test per enum care verifică toate label-urile, color-urile și helper-ii.
>
> Commit: `feat(domain): add LexRecovery enums (CaseStatus, CaseTransition, PersonType, RelationshipType, DeadlineType, DeadlinePriority)`.

**TESTE MINIME**: 1 test per enum.

**🔴 REVIZIE JURIDICĂ 2026-05-09 — Modificări necesare** (ref: `ANALIZA-JURIDICA-PROCEDURA-OP-2026-05-08.md` C2)

**Problemă**: enum-urile folosesc terminologia "contestație/contestată" pentru calea de atac împotriva ordonanței de plată. **CPC art. 1024** numește această cale **"cerere în anulare"** — distinctă de "contestația la executare" (art. 712+) și "contestația în anulare" (art. 503+, cale extraordinară). Termenii afectați apar în UI, audit log, PDF-uri și emails.

**Decizie**: RENAME enum (în consistență cu memoria proiectului `feedback_no_romanian_in_code` — enum cases sunt excepția pentru termeni legali, deci termenii legali români trebuie corecți). Alternativa "i18n peste cheia veche" lasă în cod, audit log și fixtures un termen juridic eronat.

**Fișiere afectate**:

1. `src/Enum/CaseStatus.php:13` — `case CONTESTATA = 'CONTESTATA';` → `case IN_ANULARE = 'IN_ANULARE';`
2. `src/Enum/CaseStatus.php:34` — color map: cheia `CONTESTATA` → `IN_ANULARE` (păstrează aceeași culoare orange).
3. `src/Enum/CaseStatus.php:54` — `isActiveOnPortal()` array include `IN_ANULARE` în loc de `CONTESTATA`.
4. `src/Enum/CaseTransition.php:12-14`:
   - `CONTESTA = 'contesta'` → `FORMULEAZA_CERERE_ANULARE = 'formuleaza_cerere_anulare'`
   - `RESPINGE_CONTESTATIE = 'respinge_contestatie'` → `RESPINGE_CERERE_ANULARE = 'respinge_cerere_anulare'`
   - `ADMITE_CONTESTATIE = 'admite_contestatie'` → `ADMITE_CERERE_ANULARE = 'admite_cerere_anulare'`
5. `src/Enum/DeadlineType.php:10` — `CONTESTATIE = 'CONTESTATIE'` → `CERERE_IN_ANULARE = 'CERERE_IN_ANULARE'`
6. `src/Enum/DeadlineType.php:25` — actualizare `priority()` mapping pentru noul case (păstrează `CRITICAL`).

**Plan testing**:
- Update `tests/Enum/CaseStatusTest.php`, `tests/Enum/CaseTransitionTest.php`, `tests/Enum/DeadlineTypeTest.php` — assertions cu noile constante.
- Add 1 test per enum care verifică prezența noului case + absența celui vechi.
- `vendor/bin/phpunit tests/Enum/` trebuie să treacă verde.
- Search global: `rg "CONTESTATA|CONTESTATIE" src/` și `rg "transition.contesta\b|respinge_contestatie|admite_contestatie" src/` returnează zero linii (în afară de comentarii istorice opționale).

**Cascade obligatoriu**: după acest pas trebuie executate **Pas 1.3** (workflow YAML), **Pas 1.4** (migrare DB) și **Pas 1.5** (i18n) — vezi subsecțiunile lor de revizie. Fără cascade, sistemul e inconsistent (cod vs DB vs YAML vs traduceri).

---

### PASUL 1.3 | Workflow YAML refăcut | 0.5 zi | 70% reutilizare ✅ DONE 2026-05-08 (`7a2fdf0`) + REVIZIE C2 aplicată 2026-05-09 (`67abc5c`)

**Rezultat**: 12 places + 12 tranziții cablate la `CaseStatus` enum prin `EnumMarkingStore` custom (BackedEnum support). Workflow rămâne `legal_case` (rename la `dosar` evitat per decizia user). Voter `CASE_TRANSITION` adăugat. 43/43 teste in scope verzi (37 ✅ + 6 ⏭️ pending Pas 1.4 baseline). Detalii: `~/.claude/projects/-Users-alexc-Downloads-myprojects-symfony-mvp/memory/project_lexrecovery_pas_1_3.md`.

**Scop**: rescriem state machine pentru fluxul LexRecovery.

**Specificație**: secțiunea 6 din `ANALIZA-FLUXURI-LEXRECOVERY.md`.

**PROMPT**:
> Refă `config/packages/workflow.yaml` pentru noua mașină de stări `dosar` (state machine, nu workflow).
>
> Configurare:
> - `type: state_machine`
> - `marking_store: { type: 'method', property: 'status' }`
> - `supports: [App\Entity\Dosar]`
> - `places`: cele 12 valori `CaseStatus` (folosește valorile string ale enum-ului).
> - `initial_marking: amiabil`
> - `transitions`: cele 12 tranziții listate în secțiunea 6 din ANALIZA-FLUXURI-LEXRECOVERY.md, cu `from` și `to` exact.
>
> Adaptează `src/Service/Case/CaseWorkflowService.php`:
> - Renamează tagging-ul din `legal_case` în `dosar`.
> - Schimbă type hints din `LegalCase` în `LegalCase`.
> - Update `getAvailableTransitions(LegalCase $legalCase): array` și `apply(LegalCase $legalCase, string $transition): void`.
> - Mută în `src/Service/Dosar/CaseWorkflowService.php` (rename).
>
> Update `src/Security/Voter/CaseVoter.php` → `CaseVoter.php`:
> - Permisiuni: `CASE_VIEW`, `CASE_EDIT`, `CASE_TRANSITION`, `CASE_UPLOAD`.
> - Verificare: `$dosar->getUser() === $user` sau `ROLE_ADMIN`.
> - `CASE_EDIT` permis doar dacă status în `[AMIABIL, SOMATIE_TRIMISA, CERERE_DEPUSA]`.
>
> Teste: `tests/Service/Case/CaseWorkflowServiceTest.php` cu cazuri pentru fiecare tranziție validă/invalidă (12 + cazuri negative). `tests/Security/Voter/CaseVoterTest.php` cu cazuri pentru fiecare permisiune.
>
> Commit: `feat(workflow): replace legal_case workflow with dosar state machine`.

**TESTE MINIME**: 12 teste pentru tranziții valide + 4 negative + 4 voter cases.

**🔴 REVIZIE JURIDICĂ 2026-05-09 — Modificări necesare** (ref: `ANALIZA-JURIDICA-PROCEDURA-OP-2026-05-08.md` C2)

**Problemă**: workflow YAML conține state `CONTESTATA` și tranzițiile `contesta` / `respinge_contestatie` / `admite_contestatie` — terminologie juridic eronată (vezi Pas 1.2 revizie). Trebuie aliniat cu rename-ul enum-ului `CaseStatus` și `CaseTransition`.

**Fișier afectat**: `config/packages/workflow.yaml`

**Modificări**:

| Linia | Înainte | După |
|---|---|---|
| 19 | `- CONTESTATA` | `- IN_ANULARE` |
| 44-46 | `contesta:`<br>`  from: ORDONANTA_EMISA`<br>`  to: CONTESTATA` | `formuleaza_cerere_anulare:`<br>`  from: ORDONANTA_EMISA`<br>`  to: IN_ANULARE` |
| 50-52 | `respinge_contestatie:`<br>`  from: CONTESTATA`<br>`  to: DEFINITIVA` | `respinge_cerere_anulare:`<br>`  from: IN_ANULARE`<br>`  to: DEFINITIVA` |
| 53-55 | `admite_contestatie:`<br>`  from: CONTESTATA`<br>`  to: RESPINSA` | `admite_cerere_anulare:`<br>`  from: IN_ANULARE`<br>`  to: RESPINSA` |

**Plan testing**:
- `php bin/console workflow:dump legal_case` (sau `make shell` + comandă) — diagrama trebuie să afișeze state `IN_ANULARE` + cele 3 tranziții cu nume nou.
- `php bin/console cache:clear` — fără erori de validare config.
- `vendor/bin/phpunit tests/EventSubscriber/CaseWorkflowSubscriberTest.php` — actualizat la noile nume tranziții.
- Voter `CASE_TRANSITION` (Pas 1.3) — verifică că accept noile nume (deja generic prin `CaseTransition` enum, dar revalidare).

**Atenție migrare cascade**: după modificarea YAML, **Pas 1.4** (migrare DB) trebuie executat înainte de orice deploy — altfel dosarele cu status `CONTESTATA` în DB vor crăpa la încărcare (workflow-ul nu mai recunoaște state-ul).

---

### PASUL 1.4 | Migrare baseline + fixtures + seed | 0.5 zi | 80% reutilizare ✅ DONE 2026-05-08 (`5084f1b`) + REVIZIE C2 aplicată 2026-05-09 (`d299411` — migrare `Version20260509112007` UPDATE legacy values)

**Rezultat**: Single baseline migration (17 tables), `doctrine/doctrine-fixtures-bundle` instalat, `InterestRateConfigFixtures` (5 valori BNR) + `PlanFixtures` (Starter+Pro) cu `--group=baseline`, comandă `app:seed-demo-cases` (5 dosare `LR-DEMO-*` + creditor demo + debitori + 1-2 termene per dosar, idempotentă), `docker-entrypoint.sh` actualizat. Plus: `LegalCaseCrudController` aliniat la noul shape (in scope). 46/46 teste in scope (1.3+1.4) verzi — `CaseWorkflowSubscriberTest` deblocat după baseline. Detalii: `~/.claude/projects/-Users-alexc-Downloads-myprojects-symfony-mvp/memory/project_lexrecovery_pas_1_4.md`.

**Scop**: o singură migrare baseline curată, plus seed-uri pentru BNR și planuri default.

**PROMPT**:
> 1. **Migrare baseline**: rulează `bin/console make:migration`. Numele fișierului: `Version2026XXXX_lexrecovery_baseline.php`. Verifică conținutul — trebuie să creeze toate tabelele: `users` (refolosit), `courts` (refolosit), `legal_case` (extins), `creditor`, `debtor`, `legal_deadline`, `document` (refolosit), `case_status_history` (refolosit), `audit_log` (refolosit), `notification` (refolosit + adăugare `legal_case_id`), `court_portal_event` (refolosit), `interest_rate_config`, `plan`, `subscription`, `invoice`, `messenger_messages` (refolosit), `reset_password_request` (refolosit).
>
> 2. **Fixtures pentru InterestRateConfig**: creează `src/DataFixtures/InterestRateConfigFixtures.php` cu 4-5 valori istorice BNR (ex: 2024-01-01 → 7%, 2024-08-01 → 6.5%, 2025-01-01 → 6.5%, 2025-08-01 → 6%, 2026-02-01 → 6%). Valorile reale se actualizează manual ulterior.
>
> 3. **Fixtures pentru Plan**: `src/DataFixtures/PlanFixtures.php` cu 2 planuri: Starter (99 RON/lună, 5 dosare incluse, 25 RON/dosar extra), Pro (299 RON/lună, 25 dosare incluse, 15 RON/dosar extra).
>
> 4. **Comandă `app:seed-demo-cases`** în `src/Command/SeedDemoDosareCommand.php` (idempotentă): creează 5 dosare demo în statusuri diferite (AMIABIL, SOMATIE_TRIMISA, ORDONANTA_EMISA, DEFINITIVA, RESPINSA) pentru avocatul de test (`avocat@test.com` din `app:create-test-users`).
>
> 5. **Update `app:create-test-users`** să creeze și un user avocat: `avocat@test.com` cu `barNumber=12345`, parolă `password`, isVerified=true.
>
> 6. **Update `docker-entrypoint.sh`**: după `doctrine:migrations:migrate` adaugă `doctrine:fixtures:load --no-interaction --append --group=baseline` (group baseline = InterestRateConfigFixtures + PlanFixtures), apoi `app:import-courts` și `app:create-test-users` (existente).
>
> 7. Rulează: `make composer && bin/console doctrine:database:drop --force --if-exists && bin/console doctrine:database:create && bin/console doctrine:migrations:migrate --no-interaction && bin/console doctrine:fixtures:load --no-interaction --append --group=baseline && bin/console app:import-courts && bin/console app:create-test-users && bin/console app:seed-demo-cases`.
>
> 8. Verifică `bin/console doctrine:schema:validate` — trebuie să fie verde.
>
> Commit: `feat(domain): baseline migration + fixtures (InterestRateConfig, Plan) + seed demo dosare`.

**VERIFICARE MANUALĂ**:
- [ ] `bin/console doctrine:schema:validate` verde.
- [ ] Login cu `avocat@test.com` / `password` funcțional.
- [ ] În DB: 5 dosare demo create în statusuri diverse.

**TESTE MINIME**: smoke test command + assertion că `doctrine:schema:validate` returnează exit 0.

**🔴 REVIZIE JURIDICĂ 2026-05-09 — Modificări necesare** (ref: `ANALIZA-JURIDICA-PROCEDURA-OP-2026-05-08.md` C2)

**Problemă**: enum-urile sunt stocate ca string-uri în DB (Doctrine BackedEnum). Rename-ul PHP-ului (Pas 1.2 revizie) **nu** schimbă automat valorile din coloanele:
- `legal_case.status` (poate avea `'CONTESTATA'`)
- `legal_deadline.type` (poate avea `'CONTESTATIE'`)
- `case_status_history.transition` (poate avea `'contesta'` / `'respinge_contestatie'` / `'admite_contestatie'`)

Migrare nouă obligatorie pentru a UPDATE-a aceste valori în-place; altfel ORM aruncă `\ValueError` la load.

**Plan migrare DB nouă** (după rename enum la Pas 1.2):

```bash
make migrate-diff
```

Fișier așteptat: `migrations/VersionXXXXXXXXX_rename_contestatie_to_cerere_anulare.php`

```php
public function up(Schema $schema): void
{
    // C2 — CPC art. 1024: "cerere în anulare" e termenul corect (NU "contestație").
    $this->addSql("UPDATE legal_case SET status = 'IN_ANULARE' WHERE status = 'CONTESTATA'");
    $this->addSql("UPDATE legal_deadline SET type = 'CERERE_IN_ANULARE' WHERE type = 'CONTESTATIE'");
    $this->addSql("UPDATE case_status_history SET transition = 'formuleaza_cerere_anulare' WHERE transition = 'contesta'");
    $this->addSql("UPDATE case_status_history SET transition = 'respinge_cerere_anulare' WHERE transition = 'respinge_contestatie'");
    $this->addSql("UPDATE case_status_history SET transition = 'admite_cerere_anulare' WHERE transition = 'admite_contestatie'");
}

public function down(Schema $schema): void
{
    // Reverse SQL identic invers (UPDATE 'IN_ANULARE' → 'CONTESTATA' etc.).
    $this->addSql("UPDATE legal_case SET status = 'CONTESTATA' WHERE status = 'IN_ANULARE'");
    $this->addSql("UPDATE legal_deadline SET type = 'CONTESTATIE' WHERE type = 'CERERE_IN_ANULARE'");
    $this->addSql("UPDATE case_status_history SET transition = 'contesta' WHERE transition = 'formuleaza_cerere_anulare'");
    $this->addSql("UPDATE case_status_history SET transition = 'respinge_contestatie' WHERE transition = 'respinge_cerere_anulare'");
    $this->addSql("UPDATE case_status_history SET transition = 'admite_contestatie' WHERE transition = 'admite_cerere_anulare'");
}
```

**Decizie audit log** (`audit_log` entity): valorile vechi RĂMÂN ca-i (păstrate exact cum erau la momentul tranziției istorice) — audit log-ul reflectă ce s-a întâmplat la acel moment, nu valorile curente. Doar `case_status_history.transition` se UPDATE-ează (e parte din state machine activ, NU istoric audit).

**Fixtures afectate**: `src/DataFixtures/*.php` și `src/Command/SeedDemoDosareCommand.php` — căutare:
```bash
rg "CONTESTATA|CONTESTATIE|'contesta'|respinge_contestatie|admite_contestatie" src/DataFixtures/ src/Command/
```
Înlocuire la noile constante enum (`CaseStatus::IN_ANULARE`, `CaseTransition::FORMULEAZA_CERERE_ANULARE`, etc.).

**Plan testing**:
- Re-rulare `make migrate` (aplică migrarea nouă).
- Verificare DB: `mysql> SELECT DISTINCT status FROM legal_case` — niciun `CONTESTATA` rămas.
- Verificare DB: `mysql> SELECT DISTINCT type FROM legal_deadline` — niciun `CONTESTATIE` rămas.
- Re-rulare `make shell` + `bin/console doctrine:fixtures:load --no-interaction --append --group=baseline` + `bin/console app:seed-demo-cases` — fixtures se încarcă curat.
- `vendor/bin/phpunit tests/Repository/LegalCaseRepositoryTest.php` — verde.
- `vendor/bin/phpunit tests/Controller/Case/` — integration tests cu workflow trec cu nume tranziții noi.

---

### PASUL 1.5 | Foundație i18n + retroactive backfill | 1 zi | 30% reutilizare ✅ DONE 2026-05-08 (`8cc92a6`) + REVIZIE C2 aplicată 2026-05-09 (`9e7fb49`)

**Rezultat**: 12 enum-uri refactorizate (`label()` returnează cheie `enum.<bucket>.<value>`), ~80 chei noi în `messages.{ro,en}.yaml` (best-effort EN), homepage rescris pentru LexRecovery via `|trans`, 4 Stimulus controllers cu data attributes pattern, AnafLookupService aruncă chei. Admin call-sites consumă cheile prin `TranslatorInterface` injectat — admin UI labels rămân hardcoded RO (decizie scope, admin = audiență tech). 100/100 teste in scope verzi (incl. nou `EnumLabelKeysExistTest` cu 154 assertion-uri anti-drift). Detalii: `~/.claude/projects/-Users-alexc-Downloads-myprojects-symfony-mvp/memory/project_lexrecovery_pas_1_5.md`.

**Scop**: toate stringurile user-facing **non-admin** trec prin Symfony Translator; deblochează scalarea pe en/de/etc. Backfill pentru pașii 1.1–1.4 deja livrați.

**Specificație**: regula "Stringuri user-facing non-admin prin translator" din "Reguli pentru lucrul cu Claude Code".

**PROMPT**:
> 1. **Refactor enum `label()` (12 enum-uri în `src/Enum/`)**: schimbă fiecare metodă `label()` să returneze **cheia de traducere**, nu textul. Pattern: `return 'enum.case_status.' . $this->value;`. Aplicabil pentru `CaseStatus`, `CaseTransition`, `DeadlineType`, `DeadlinePriority`, `PersonType`, `RelationshipType`, `CourtType`, `NotificationChannel`, `PortalEventType`, `UserType`, `DocumentType`, `ExtractionStatus`. Metodele `color()` rămân neatinse.
>
> 2. **Adaugă cheile noi** în `translations/messages.ro.yaml` (+ best-effort EN în `messages.en.yaml`). ~56 chei `enum.<bucket>.<value>`, plus chei pentru homepage (`home.hero.*`, `home.steps.*`, `home.benefits.*`), Stimulus (`stimulus.collection.remove`, `stimulus.confirm.default`, `stimulus.company_lookup.*`, `stimulus.optimistic.action_failed`), AnafLookup (`exception.anaf.cui_invalid`, `exception.anaf.unavailable`, `exception.anaf.not_found`).
>
> 3. **Update admin call-sites care consumă `enum.label()`** — păstrăm vizibilul prin `trans()` la call-site, NU adăugăm chei admin-specifice:
>    - `src/Controller/Admin/LegalCaseCrudController.php`: inject `TranslatorInterface`; în `statusChoices()` apelează `$this->translator->trans($status->label())` pentru cheia mapului.
>    - `src/Controller/Admin/CaseStatusController.php`: inject `TranslatorInterface`; liniile 70-71 schimbă din `CaseStatus::tryFrom(...)?->label()` la `$this->translator->trans($status->label())`. Restul flash messages rămân hardcoded RO.
>    - `templates/admin/case_change_status.html.twig`: dacă afișează `status.label`, adaugă `|trans`. Restul textului rămâne RO hardcoded.
>
> 4. **SKIP — EasyAdmin labels** (decizie scope): NU refactoriza `setEntityLabelInSingular/Plural`, field labels, action labels, filter labels în CRUD controllers. Rămân hardcoded RO.
>
> 5. **Extract `templates/home/index.html.twig`**: wrap toate stringurile RO cu `|trans` și adaugă cheile în yaml.
>
> 6. **Stimulus controllers** (4 fișiere): mută stringurile hardcoded la data attributes. Pattern: `this.data.get('messageName')` în controller; `data-{controller}-message-name-value="{{ 'key'|trans }}"` în twig. Aplicabil: `collection_controller.js`, `confirm_controller.js`, `company_lookup_controller.js`, `optimistic-action_controller.js`.
>
> 7. **`src/Service/Company/AnafLookupService.php`**: aruncă cu cheia ca message: `throw new \DomainException('exception.anaf.cui_invalid')`. La consumer (endpoint AJAX), catch + `$translator->trans()` la JSON response.
>
> 8. **Test defensiv**: `tests/I18n/EnumLabelKeysExistTest.php` — pentru fiecare enum case, verifică `getCatalogue('ro')->has($enum->label())`. Previne drift între enum + yaml.
>
> Commit: `refactor(i18n): route non-admin user-facing strings through translator (Pas 1.5)`.

**VERIFICARE MANUALĂ**:
- [ ] Login `admin@test.com` / `password` → admin UI afișează identic cu pre-refactor (badge-urile de status au textul tradus din chei).
- [ ] Homepage `/` → texte din chei.
- [ ] `/?_locale=en` pe homepage → texte EN best-effort.
- [ ] `confirm_controller` cu `data-confirm-message-value` setat → mesaj tradus la apăsare.

**TESTE MINIME**: 1 test (`EnumLabelKeysExistTest`) acoperă toate cele 12 enum-uri × N cazuri.

**🔴 REVIZIE JURIDICĂ 2026-05-09 — Modificări necesare** (ref: `ANALIZA-JURIDICA-PROCEDURA-OP-2026-05-08.md` C2)

**Problemă**: cheile i18n folosesc terminologia "contestație/contestată" pentru calea de atac. Trebuie aliniate cu rename-ul enum-urilor (Pas 1.2 + 1.3 + 1.4 revizii) — altfel `EnumLabelKeysExistTest` se sparge (cheia nouă nu există în catalogul YAML).

**Fișiere afectate**: `translations/messages.ro.yaml`, `translations/messages.en.yaml`

**Modificări `messages.ro.yaml`** (citate aproximative — verifică liniile exacte la executare):

```yaml
# Înlocuire (cheile vechi se șterg, cele noi se adaugă):
enum.case_status.CONTESTATA: 'Contestată'
  → enum.case_status.IN_ANULARE: 'În anulare (cerere depusă)'

enum.case_transition.contesta: 'Contestă'
  → enum.case_transition.formuleaza_cerere_anulare: 'Formulează cerere în anulare'

enum.case_transition.respinge_contestatie: 'Respinge contestația'
  → enum.case_transition.respinge_cerere_anulare: 'Respinge cererea în anulare'

enum.case_transition.admite_contestatie: 'Admite contestația'
  → enum.case_transition.admite_cerere_anulare: 'Admite cererea în anulare'

enum.deadline_type.CONTESTATIE: 'Termen contestație'
  → enum.deadline_type.CERERE_IN_ANULARE: 'Termen cerere în anulare'
```

**Modificări `messages.en.yaml`** (best-effort EN, pattern identic):

```yaml
enum.case_status.IN_ANULARE: 'Annulment requested'
enum.case_transition.formuleaza_cerere_anulare: 'File annulment request'
enum.case_transition.respinge_cerere_anulare: 'Reject annulment request'
enum.case_transition.admite_cerere_anulare: 'Grant annulment request'
enum.deadline_type.CERERE_IN_ANULARE: 'Annulment request deadline'
```

**Verificare templates Twig + admin call-sites**:

```bash
# Nu trebuie să mai existe referințe directe la cheile vechi:
rg "case_status\.CONTESTATA" templates/ src/
rg "case_transition\.contesta\b|case_transition\.respinge_contestatie|case_transition\.admite_contestatie" templates/ src/
rg "deadline_type\.CONTESTATIE" templates/ src/
```

Dacă există referințe directe la chei (în loc să folosească `$enum->label()`), trebuie actualizate.

**Plan testing**:
- `vendor/bin/phpunit tests/I18n/EnumLabelKeysExistTest.php` — verde (testul iterează enum cases și verifică `getCatalogue('ro')->has($enum->label())`).
- Verificare manuală homepage `/` și view dosar — texte afișate corect.
- `php bin/console translation:debug ro` — fără chei "missing" sau "unused" pe enum-urile afectate.

---

## Faza 2: Servicii calcul & lookup

> Toate cele 4 servicii sunt **paralelizabile** — pot fi implementate în 4 sub-branch-uri sau 4 prompt-uri consecutive fără dependențe între ele (în afara Pas 1.1 care e prerequisit pentru toate).

### PASUL 2.1 | `InterestCalculatorService` | 0.75 zi | 0% reutilizare ✅ DONE 2026-05-08 (`f9e84dc`) + REVIZIE C3 aplicată 2026-05-09 (Opțiunea a — restrângere MVP la B2B; CIVIL aruncă `\DomainException`)

**Rezultat**: livrat `InterestCalculatorService` cu signature revizuit (penalizator/remuneratoriu + currency guard); enum nou `InterestKind`; `RelationshipType::nbrPercentagePoints()` înlocuit cu `applicableRate(BNR, kind)` care implementează cele 4 formule OG 13/2011 art. 3 (CIVIL+PENALIZATOARE = `(BNR+8)×0.80`, NU `BNR+4`); DTOs `InterestResult`/`InterestPeriod`; 9 scenarii de test verzi. Detalii: `~/.claude/projects/-Users-alexc-Downloads-myprojects-symfony-mvp/memory/project_lexrecovery_pas_2_1.md`.

**Scop**: calcul dobândă legală conform OG 13/2011 cu istoric BNR.

**Specificație**: secțiunea 7.1 din `ANALIZA-FLUXURI-LEXRECOVERY.md`.

> 🔴 **REVIZIE JURIDICĂ 2026-05-08** (din analiza Faza 2):
> - **Formula CIVIL era greșită**: era `BNR + 4 pp`. Corect conform OG 13/2011 art. 3 alin. (3): **`(BNR + 8) × 0,80`** (diminuat cu 20% din rata penalizatoare comercială). `RelationshipType::nbrPercentagePoints()` se elimină în favoarea `applicableRate(float $bnrRate, InterestKind $kind): float` care încapsulează formula completă.
> - **Distincție remuneratoriu vs penalizator**: adaugă enum `InterestKind` (REMUNERATORIE | PENALIZATOARE) și parametrul corespunzător pe `calculate()`. Default = PENALIZATOARE (cazul tipic OP). Pentru REMUNERATORIE: `applicableRate = BNR × ($relType === CIVIL ? 0,80 : 1,0)` (fără +8 pp).
> - **Limitare valută**: dobânda se calculează DOAR pentru creanțe RON. Pentru altă monedă → throw `\InvalidArgumentException`. Suport valută (art. 4 OG 13/2011) — post-MVP.
> - **Convenție**: zile elapsed (`act/365`), dobândă **simplă** (NU compusă — anatocismul cere convenție expresă conform NCC art. 1489).

**PROMPT**:
> Implementează `src/Service/Calculation/InterestCalculatorService.php`.
>
> Constructor: injectează `InterestRateConfigRepository`.
>
> Metoda principală:
> ```php
> public function calculate(
>     float $amount,
>     \DateTimeImmutable $dueDate,
>     \DateTimeImmutable $referenceDate,
>     RelationshipType $relationshipType,
>     InterestKind $kind = InterestKind::PENALIZATOARE,
>     string $currency = 'RON',
> ): DobandaResult
> ```
>
> Returnează un DTO `DobandaResult` (record class) cu:
> - `total` (float)
> - `breakdown` (array of `DobandaPerioada` cu `dataStart, dataEnd, nbrRate, applicableRate, zile, periodInterest`)
>
> Algoritm:
> 1. Validare: `$currency !== 'RON'` → throw `\InvalidArgumentException` (limitare MVP, art. 4 OG 13/2011 post-MVP).
> 2. Obține toate `InterestRateConfig` cu `validFrom <= referenceDate`, sortat ascendent.
> 3. Construiește perioade de la `dueDate` la `referenceDate`, segmentate pe schimbările de rată BNR.
> 4. Pentru fiecare perioadă: `applicableRate = $relationshipType->applicableRate($nbrRate, $kind)`.
>    - COMERCIAL + PENALIZATOARE: `BNR + 8 pp` (OG 13/2011 art. 3 alin. 2¹).
>    - CIVIL + PENALIZATOARE: `(BNR + 8) × 0,80` (art. 3 alin. 3 — diminuat 20% din comercial).
>    - COMERCIAL + REMUNERATORIE: `BNR` (art. 3 alin. 2).
>    - CIVIL + REMUNERATORIE: `BNR × 0,80` (art. 3 alin. 3 aplicat la remuneratoriu).
> 5. `periodInterest = amount * (applicableRate / 100) * days / 365` (simplă, NU compusă).
> 6. `total = sum(periodInterest)`.
>
> Edge cases:
> - dueDate > referenceDate → total = 0, breakdown = [].
> - Niciun InterestRateConfig anterior dueDate → throw `\RuntimeException` cu mesaj clar.
> - Creanță în altă monedă decât RON → throw `\InvalidArgumentException` (suport valută post-MVP).
>
> **Pre-requisit Pas 1.2**: adaugă enum `InterestKind` (REMUNERATORIE | PENALIZATOARE) cu `label()`.
>
> Teste: `tests/Service/Calculation/InterestCalculatorServiceTest.php` cu minim 8 scenarii:
> 1. Raport COMERCIAL + PENALIZATOARE, perioadă o singură rată, 90 zile.
> 2. Raport CIVIL + PENALIZATOARE, perioadă peste 2 schimbări de rată BNR — verifică formula `(BNR + 8) × 0,80` (NU `BNR + 4`).
> 3. Raport COMERCIAL + REMUNERATORIE — verifică = `BNR` (fără +8 pp).
> 4. Raport CIVIL + REMUNERATORIE — verifică = `BNR × 0,80`.
> 5. dueDate = referenceDate → 0.
> 6. Sub o zi (≤ 0 zile) → 0.
> 7. Peste prescripție (3+ ani) — calculul rulează, prescripția e tratată separat (vezi `PrescriptionCalculator` propus în analiza Faza 2).
> 8. InterestRateConfig lipsă → exception.
> 9. Currency != RON → `\InvalidArgumentException`.
>
> Commit: `feat(calc): InterestCalculatorService implementing OG 13/2011 (penalizator/remuneratoriu, formula CIVIL corectă)`.

**🔴 REVIZIE JURIDICĂ 2026-05-09 — Modificări necesare** (ref: `ANALIZA-JURIDICA-PROCEDURA-OP-2026-05-08.md` C3)

**Problemă (verificare verbatim OG 13/2011 + Legea 72/2013 art. 20 — `legislatie.just.ro` doc 146555 fetched 2026-05-09)**: ramura `CIVIL+PENALIZATOARE` aplică formula `(BNR+8) × 0.80` — **combinație fără temei legal**:
- Factorul **+8 pp** este exclusiv pentru raporturi profesionale (B2B), per **OG 13/2011 art. 3 alin. (2¹)** introdus prin Legea 72/2013 art. 20.
- Diminuarea **× 0.80** este exclusiv pentru raporturi non-profesionale (P2P), per **OG 13/2011 art. 3 alin. (3)**.

În OG 13/2011 nu există nicio combinație care să atribuie simultan ambele. Cele 3 cazuri legale distincte:
1. **B2B** (profesionist↔profesionist): PEN = `BNR + 8 pp` (alin 2¹) | REM = `BNR` (alin 1).
2. **B2C** (profesionist↔consumator): PEN = `BNR + 4 pp` (alin 2 standard) | REM = `BNR` (alin 1).
3. **P2P** (particular↔particular): PEN = `(BNR + 4) × 0.80` (alin 2 + alin 3) | REM = `BNR × 0.80` (alin 1 + alin 3).

**Decizie (de luat la executare)** — 2 opțiuni:

#### Opțiunea (a) — Restrângere strictă la B2B *(recomandat)*

Formula inventată e eliminată (NU ascunsă). Scope MVP devine explicit "B2B exclusiv".

`src/Enum/RelationshipType.php` — refactor `applicableRate()`:

```php
public function applicableRate(float $nbrRate, InterestKind $kind): float
{
    return match (true) {
        // OG 13/2011 art. 3 alin. (2¹) introdus prin Legea 72/2013 art. 20:
        // "În raporturile dintre profesioniști [...] dobânda legală penalizatoare se stabilește la
        // nivelul ratei dobânzii de referință plus 8 puncte procentuale."
        $this === self::COMERCIAL && $kind === InterestKind::PENALIZATOARE => $nbrRate + 8.0,
        // OG 13/2011 art. 3 alin. (1):
        $this === self::COMERCIAL && $kind === InterestKind::REMUNERATORIE => $nbrRate,
        $this === self::CIVIL => throw new \DomainException(
            'Raporturile non-profesionale (CIVIL) nu sunt suportate în MVP. Scope curent: B2B exclusiv.'
            . ' Pentru B2C/P2P, vezi backlog post-MVP (Opțiunea b din PLAN Pas 2.1 revizie 2026-05-09).'
        ),
    };
}
```

**Fișiere afectate**:
- `src/Enum/RelationshipType.php` — refactor `applicableRate()` (linia 23-32 aprox).
- `src/Service/Calculation/InterestCalculatorService.php` — propagare `\DomainException` (let it bubble; UI-ul wizard-ului catch + mesaj clar avocat).
- `tests/Enum/RelationshipTypeTest.php` — drop teste `CIVIL+PENALIZATOARE` și `CIVIL+REMUNERATORIE`; add `testCivilRelationshipThrowsUnsupportedException`.
- `tests/Service/Calculation/InterestCalculatorServiceTest.php` — drop scenariile 2 și 4 (CIVIL); add scenariu nou: `testCivilRelationshipThrowsDomainException`.
- UI Pas 3.1+ (wizard step 2/3) — opțional, ascunde sau desactivează opțiunea "CIVIL" în dropdown `RelationshipType`. La afișare, scoateți eticheta "Civil" sau marcați-o "(post-MVP)".

#### Opțiunea (b) — Implementare completă 3 ramuri B2B/B2C/P2P *(post-MVP)*

Refactor enum la 3 valori distincte (ex: `B2B_PROFESIONAL`, `B2C_CONSUMER`, `NON_PROFESIONAL`), sau menținere binar `COMERCIAL/CIVIL` cu adăugare a unui flag `BusinessRelationshipKind` separat (B2B/B2C). Plan migrare DB necesar (legal_case.relationship_type values). Mai mult de muncă; aliniat cu acoperirea legală completă.

**Recomandare plan**: **Opțiunea (a)** acum (restrânge MVP la B2B); Opțiunea (b) = backlog post-MVP după validare cu avocat-utilizator pe scope produs.

**Plan testing (Opțiunea a)**:
- `vendor/bin/phpunit tests/Service/Calculation/InterestCalculatorServiceTest.php` — toate testele B2B trebuie să treacă neschimbate.
- Test nou: `testCivilRelationshipThrowsDomainException` — confirmă `\DomainException` cu mesaj clar pentru CIVIL.
- Test fixture `app:seed-demo-cases` (Pas 1.4) — verifică că dosare demo nu folosesc CIVIL (sau sunt actualizate la COMERCIAL).
- Search global: `rg "RelationshipType::CIVIL" src/` — apariții doar în testul exception + documentație.

⚖️ **Validare avocat-utilizator înainte de execuție**: confirmă scope MVP — strict B2B, sau e necesar B2C/P2P de la lansare? Dacă scope strict B2B, execută Opțiunea (a). Altfel, escaladeaz la Opțiunea (b) (sprint suplimentar).

---

### PASUL 2.2 | `StampDutyCalculator` | 0.25 zi | 0% reutilizare ✅ DONE 2026-05-08 (`8cffd87`) + doc mark done (`b79034c`) — REVIZIE JURIDICĂ 2026-05-09 a confirmat CORECT (taxă fixă 200 RON per OUG 80/2013 art. 6 alin. 2; fără modificări de cod)

**Rezultat**: ✅ DONE 2026-05-08 (commit `8cffd87`). Implementare Variantă A (taxă fixă **200 RON** pentru orice cerere OP, conform OUG 80/2013 art. 6 alin. 2 — formă curentă). Livrate: `src/Service/Calculation/StampDutyCalculator.php` (signature `calculate(): StampDutyResult` — fără argument `$amount` întrucât Variantă A e constantă; YAGNI peste Variantă B) + DTO readonly `src/DTO/Calculation/StampDutyResult.php` (`amount`, `lawVersion`); parametri configurabili `app.taxa_timbru_op.fixed = 200.0` și `app.taxa_timbru_op.law_version` în `config/services.yaml`; 2 teste unit (`testReturnsConfiguredFixedFee` + `testResultCarriesConfiguredLawVersion`). DI verificat via `debug:container` (argumentele rezolvă la `200` și string-ul juridic). **Out-of-scope (amânat)**: coloana `stampDutyLawVersion` pe `LegalCase` — se va adăuga când wizard-ul scrie pe entitate. Dacă revizia juridică viitoare confirmă Variantă B (praguri), se reintroduce parametrul `float $amount` la momentul respectiv.

> 🔴 **REVIZIE JURIDICĂ 2026-05-08** (din analiza Faza 2):
> - **Pragul `500 RON` din spec inițial NU corespunde nici unei forme cunoscute a OUG 80/2013**. Cea mai probabilă realitate juridică actuală: **taxă fixă 200 RON** (forma actuală art. 6 alin. 2). Forma cu praguri (50/200 RON la 2.000 RON) a existat în versiuni anterioare, NU pe pragul 500 RON.
> - **VALIDARE JURIDICĂ INDISPENSABILĂ**: înainte de implementare, avocatul/responsabilul juridic verifică pe legislatie.just.ro forma actuală OUG 80/2013 art. 6 (cu toate modificările) și confirmă valoarea/regula curentă.
> - **Audit**: persistă pe `LegalCase` câmp `stampDutyLawVersion` (string, ex: `"OUG 80/2013 art. 6 alin. 2 — text aplicabil 2026-05-01"`) populat la calcul, pentru justificare retroactivă.

**PROMPT**:
> Implementează `src/Service/Calculation/StampDutyCalculator.php`:
> ```php
> public function calculate(float $amount): StampDutyResult
> // StampDutyResult = record { float amount; string lawVersion; }
> ```
> Reguli (OUG 80/2013 art. 6 — DUPĂ validare juridică):
> - **Variantă A (recomandată — cel mai probabil corect)**: taxă fixă **200 RON** pentru orice cerere OP.
> - **Variantă B (dacă verificarea confirmă praguri)**: ajustează valori pe text legal actualizat.
>
> Configurabil prin `config/services.yaml` parameter `app.taxa_timbru_op.fixed = 200` (sau parametri suplimentari pentru praguri dacă Variantă B).
>
> Teste: 4 cazuri (suma 0, suma mică, suma medie, suma foarte mare — toate returnează 200 RON pentru Variantă A).
>
> Commit: `feat(calc): StampDutyCalculator (OUG 80/2013 — taxă fixă, audit version)`.

---

### PASUL 2.3 | `CompetentCourtResolver` | 0.75 zi | 30% reutilizare ✅ DONE 2026-05-09 (`8f44e05`) — REVIZIE N1 (2026-05-09) aplicată: pragul 200k pe valoare totală (principal + dobândă acumulată + penalități scadente) per CPC art. 98, NU pe principal singular

**Rezultat**: ✅ DONE 2026-05-09 (commit `8f44e05`). Livrate: `src/Service/Court/CompetentCourtResolver.php` (signature extinsă per N1 — injectează `InterestCalculatorService`; calculează `total = principal + accruedInterest + scadentPenalties` ÎNAINTE de a aplica pragul 200k; locality matching cu alternatives + explanationKey i18n, niciodată "prima activă" silent), `src/Service/Court/LocalityNormalizer.php` (Normalizer::FORM_D + strip Mn + lowercase pentru matching diacritic-insensitive), DTOs readonly `src/DTO/Court/{ClaimValueBreakdown,CourtResolveResult}.php`, `Court::$coveredLocalities` JSON nullable + getter/setter, `CourtRepository::findActiveByTypeAndCounty(CourtType, county)`, migrare aditivă `Version20260509130308` (ADD covered_localities JSON), `ImportCourtsCommand --update` flag, `data/courts.json` extins cu `coveredLocalities` (HQ city per judecătorie + 6 sectoare București — 219 instanțe), 10 chei i18n RO/EN `court.resolver.*` cu placeholder `%county%`, `ext-intl` declarat în composer.json. **Tests**: 11 scenarii — 6 PROMPT + 2 N1 critice (`testThresholdAppliesOnTotalClaimValueIncludingAccessories`, `testThresholdAppliesOnPrincipalPlusScadentPenaltiesEvenWithoutInterest`) + 3 review gap-fixes (negative principal, county null, CIVIL → DomainException propagation). **Convenție identificatori**: redenumit `localitatiArondate`→`coveredLocalities` și `resolveJudecatorie()`→`resolveLocalCourt()` per regula "no Romanian identifiers"; enum cases `CourtType::JUDECATORIE/TRIBUNAL` rămân (exempte). **Out-of-scope (post-MVP)**: BucharestSectorParser (wizard step 3), tribunale specializate Cluj/Mureș/Argeș (opt-in L 304/2022), date arondate complete (suburbii/comune — admin task), `CourtCrudController` admin field. Review iterații: 4 (2 fix rounds + 2 rename rounds), verdict final LEGAL-CLEAN + COMMIT-READY.

> 🔴 **REVIZIE JURIDICĂ 2026-05-08** (din analiza Faza 2):
> - **Pragul valoric (200.000 RON) e CORECT** ✓ conform CPC art. 94 pct. 1 lit. k și art. 95 pct. 1.
> - **BLOCKER fixat**: signature originală `resolve(amount, county)` cu fallback "prima judecătorie activă pe județ" garantează cerere depusă la instanță necompetentă teritorial în 70%+ din cazuri reale (multe județe au 2-4 judecătorii; București are 6 judecătorii pe sectoare). Risc real de declinare CPC art. 130-131.
> - **Refactor obligatoriu**: signature primește **localitate** (nu doar județ), iar `Court` entity primește câmp `localitatiArondate` (raza teritorială). Resolver returnează DTO `CourtResolveResult` (court principal + alternative + explicație) — NICIODATĂ "prima activă" silent.
> - **Pre-requisit Pas 1.1/1.4**: extinde `Court.localitatiArondate` (JSON) și fixtures cu raze pentru București (6 sectoare) + Cluj/Iași/Constanța (multiple judecătorii).
> - **Domeniu service**: determină DOAR competența default (CPC art. 107 — domiciliu/sediu debitor). Competența alternativă (art. 113 — locul executării; art. 126 — clauză contractuală) se gestionează în wizard step 4 prin override manual cu câmp `motivareCompetenta`.
> - Tribunale specializate (Cluj/Mureș/Argeș) — NU se aplică default; necesită opt-in explicit la wizard pentru raporturi între profesioniști. Post-MVP.

> 🔴 **REVIZIE JURIDICĂ 2026-05-09 — N1: Calcul valoare cerere (CPC art. 98)** (ref: `ANALIZA-JURIDICA-PROCEDURA-OP-2026-05-08.md` N1)
>
> **Problemă**: pragul 200.000 RON între judecătorie și tribunal NU se aplică pe principal singular, ci pe **valoarea totală a cererii la data sesizării instanței** = principal + dobânzi acumulate la data sesizării + penalități contractuale scadente (CPC art. 98).
>
> **Exemplu critic**: principal 190.000 RON + dobânzi acumulate 15.000 RON la data sesizării = 205.000 RON → competent **tribunalul**, nu judecătoria. Dacă routing-ul se face doar pe principal, dosarul se depune la instanță greșită → necompetență materială ridicabilă din oficiu, dosar declinat, întârziere semnificativă.
>
> **Implementare obligatorie**:
> - Service injectează `InterestCalculatorService` în plus față de `CourtRepository`.
> - Calculează `totalClaimValue = principal + interest(referenceDate=now()) + penaltiesScadente` ÎNAINTE de a aplica pragul 200.000 RON.
> - Aplică pragul pe `totalClaimValue`, nu pe `principal`.
> - Returnează în `CourtResolveResult.explanation` defalcarea numerică, astfel încât UI să afișeze transparent.
>
> **UI step 3 wizard** (cuplaj cu Pas 3.1): afișează tabelul transparent înainte de a alege instanța:
> ```
> Principal:                  190.000 RON
> Dobândă acumulată:           15.000 RON  (scadență → azi)
> Penalități contractuale:        500 RON
> ─────────────────────────────────────
> TOTAL valoare cerere:       205.500 RON
>
> Competentă: Tribunal (peste pragul 200.000 RON, CPC art. 95 pct. 1)
> ```
>
> **Test critic adăugat** (în plus de cele 6 existente):
> - `testThresholdAppliesOnTotalClaimValueIncludingAccessories`: principal 190k + dobândă 15k → tribunal (NU judecătorie).
> - `testThresholdAppliesOnPrincipalAloneRejected`: confirmă că routing-ul nu mai se bazează pe principal singular.

**PROMPT**:
> Implementează `src/Service/Court/CompetentCourtResolver.php`.
>
> Constructor: `CourtRepository`.
>
> Metoda:
> ```php
> public function resolve(
>     float $amount,
>     ?string $debtorCounty,
>     ?string $debtorLocality = null,
> ): CourtResolveResult
> // CourtResolveResult = record { ?Court court; list<Court> alternatives; string explanation; }
> ```
> Reguli (CPC art. 1015 + art. 94/95 + art. 107):
> - suma > 200_000 → tip = TRIBUNAL, județ debitor (un singur tribunal pe județ — caz simplu).
> - suma ≤ 200_000 → tip = JUDECATORIE, **localitate** debitor matchată pe `Court.localitatiArondate`.
>   - București: localitate = "Sector N" (parser pe adresă debitor — vezi `BucharestSectorParser` la wizard step debitor).
>   - Județe cu mai multe judecătorii: match pe localitate exactă (Cluj-Napoca vs Turda vs Huedin).
>
> **Pre-requisit Pas 1.1**: pe entity `Court` adaugă câmp `localitatiArondate` (JSON, listă de localități/sectoare). Migrare suplimentară 2.3a.
>
> Adaugă în `CourtRepository`:
> ```php
> public function findCandidatesByTypeAndLocality(CourtType $type, ?string $county, ?string $locality): array
> ```
>
> Edge cases:
> - localitate nematchată sau ambiguu → `CourtResolveResult` cu `court=null + alternatives=[lista]` + mesaj clar pentru avocat să aleagă manual (NICIODATĂ "prima activă" — risc juridic).
> - județ neexistent → `CourtResolveResult(court=null, alternatives=[], explanation='court.resolver.county_unknown')`.
>
> Teste: 6 scenarii:
> 1. Sub prag, județ cu match unic localitate → court ales.
> 2. Sub prag, județ cu match multiplu (Cluj-Napoca / Turda / Huedin) → alternative.
> 3. Sub prag, fără match → court=null + alternative=[].
> 4. București + parsing sector ("str. X, sector 3") → Judecătoria Sectorului 3.
> 5. Peste prag → tribunal pe județ.
> 6. Sumă 0 → exception sau return cu explanation.
>
> Commit: `feat(court): CompetentCourtResolver — CPC art. 1015, raza teritorială pe localitate, multi-candidate handling`.

---

### PASUL 2.4 | `AnafLookupService` integration + `OpAdmissibilityValidator` | 0.5 zi | 80% reutilizare ✅ DONE 2026-05-09 (`6b9a815`)

**Rezultat**: `OpAdmissibilityValidator` (`src/Service/Validation/`) cu 8 reguli (7 post-N4 pentru PJ + 1 WARNING static pentru PF) — pure service, fără deps. DTO `AdmissibilityIssue` + enum `IssueSeverity` (ERROR/WARNING). Enum nou `AnafStatus` (ACTIV/INACTIV/RADIAT) tipează `Debtor.anafStatus` (rename de la `onrcStatus`). Adăugate pe `Debtor`: `anafCheckedAt`, `inInsolvency`, `insolvencyCheckedAt`, `bpiVerifiedNote` (varchar 500), `bpiProofDocument` (FK ManyToOne nullable la `Document`). `DocumentType::BPI_PROOF` adăugat. **Scope extins (cerere user):** rename `taxId`→`cui` și `tradeRegistryNumber`→`onrcNumber` pe `Creditor` + `Debtor`. Migrarea folosește `CHANGE COLUMN` ca să păstreze datele. 25 teste verzi noi. **Validare juridică avocat-senior 2026-05-09: CONFORM CU OBSERVATII.** Aplicat în 2.4: O2 (WARNING `OP_PF_BIPF_MANUAL_CHECK` pentru PF) + O3 (DocBlock pe pragurile 7d/30d clarifică natura lor — decizie produs, nu termen legal). Deferred la Pas 3.x: M1 (wizard trebuie să prevadă `confirmInsolvencyCheck()` care setează `insolvencyCheckedAt`; altfel ERROR Rule 6 devine blocaj permanent). `AnafLookupService` neatins. Audit log `BPI_VERIFICATION` și UI bifare BPI — Pas 3.x. Detalii: `~/.claude/projects/-Users-alexc-Downloads-myprojects-symfony-mvp/memory/project_lexrecovery_pas_2_4.md`.

> 🔴 **REVIZIE JURIDICĂ 2026-05-08** (din analiza Faza 2 + clarificare user):
> - **ELIMINAT**: `OnrcLookupService` + `OnrcLookupServiceInterface` + `ManualOnrcLookupService` + entitate `OnrcCheck`. **ONRC NU oferă API public oficial**. Propunerea inițială pe `openapi.ro` a fost o ipoteză greșită — openapi.ro e un scraper terț neoficial cu cost/risc juridic. Forma originală a Pasului 2.4 (V1 stub care returnează mereu null) era ceremonie inutilă.
> - **PĂSTRAT din analiza inițială**:
>   - **`OpAdmissibilityValidator`** (serviciu nou) — blochează generarea PDF / submit wizard pentru debitori PJ în stare incompatibilă cu OP.
>   - Câmpuri pe `Debitor` pentru audit diligență profesională.
> - **NOU**: integrare cu **`AnafLookupService` deja existent** (`src/Service/Company/AnafLookupService.php` — apelează API-ul oficial ANAF, gratuit, returnează companyName, CUI, nrRegCom, adresa, stare ACTIV/INACTIV/RADIAT, codCAEN, plătitor TVA). Acoperă automat **denumire + adresă + status fiscal + status RADIAT**.
> - **Limitare ANAF**: NU returnează insolvența (L 85/2014). Pentru insolvență sursa este **BPI — Buletinul Procedurilor de Insolvență** (bpi.just.ro), care **nu are API**. Avocatul verifică manual și marchează în UI flag `inInsolvency = true` + atașează PDF publicare BPI ca `Document` (probă audit).
> - **Persoană fizică debitor**: Buletinul Insolvenței Persoanelor Fizice (L 151/2015) — manual + PDF, post-MVP.

> 🔴 **REVIZIE JURIDICĂ 2026-05-09 — N4: Enforcement insolvență obligatoriu (Legea 85/2014)** (ref: `ANALIZA-JURIDICA-PROCEDURA-OP-2026-05-08.md` N4)
>
> **Problemă**: regula 6 din `OpAdmissibilityValidator` actual (PJ + `insolvencyCheckedAt IS NULL` → WARNING) e **prea permisivă**. Dacă debitorul e în insolvență la data depunerii cererii OP, **cererea e inadmisibilă** (Legea 85/2014 — creanțele se înscriu la masa credală, nu se recuperează prin OP). Risc: aplicația permite depunerea unei cereri inadmisibile, urmând să fie respinsă de instanță cu pierdere de timp și taxă timbru.
>
> **Întărire obligatorie**: verificarea insolvenței devine **ERROR (blocant)**, nu WARNING.
>
> **Modificări regulă 6** (linia ~690 din PROMPT existent):
> - `inInsolvency = true` → ERROR `OP_BLOCKED_INSOLVENCY` *(deja exista în regula 2)*.
> - **NOU regulă 6 (înlocuiește regula 6 actuală)**: PJ + `insolvencyCheckedAt IS NULL` → ERROR `OP_INSOLVENCY_NOT_VERIFIED` (NU WARNING). Mesaj: "Verifică status insolvență debitor pe bpi.just.ro și confirmă în UI înainte de depunere."
> - **NOU regulă 6 bis**: PJ + `insolvencyCheckedAt` mai vechi de **7 zile** → ERROR `OP_INSOLVENCY_STALE`. (Insolvența se schimbă rapid; verificare > 7z e neacceptabilă pentru depunere.)
>
> **UI workflow obligatoriu** (cuplaj cu Pas 3.0+ wizard):
> 1. Înainte de tranziția `depune_cerere`, modal sau alert blochează submit dacă regulile 1, 2, 6, 6bis returnează ERROR.
> 2. UI afișează checklist explicit pentru avocat:
>    - [ ] Am verificat manual status pe `bpi.just.ro` (la {data} {ora}).
>    - [ ] Am atașat PDF extras BPI ca probă (`Document` cu `type = BPI_PROOF`).
>    - [ ] Confirm că debitorul NU e în insolvență.
> 3. La bifare → setează `Debitor.insolvencyCheckedAt = now()`, link FK `bpiProofDocumentId`.
> 4. Audit log obligatoriu: `AuditLog` cu category `BPI_VERIFICATION`, payload `{debtorId, checkedAt, proofDocumentId, hash}`.
>
> **Câmp nou pe `Debitor`** (Pas 1.1 backfill sau migrare 2.4b suplimentară):
> - `bpiVerifiedNote` (string nullable, max 500) — observații avocat la verificare (ex: "verificat 2026-05-09, nu apare în lista insolvenței").
>
> **Test critic adăugat** (în plus de cele 8 existente):
> - `testInsolvencyNotVerifiedBlocksWithError`: PJ + `insolvencyCheckedAt = null` → ERROR (NU WARNING). Confirmă cod `OP_INSOLVENCY_NOT_VERIFIED`.
> - `testInsolvencyStaleVerificationBlocksWithError`: PJ + `insolvencyCheckedAt = now() - 8 days` → ERROR `OP_INSOLVENCY_STALE`.
> - `testInsolvencyVerifiedRecentlyPasses`: PJ + `insolvencyCheckedAt = now() - 3 days` + `inInsolvency = false` → no issue.
>
> **Decizie produs**: dacă avocatul vrea să trimită cerere fără verificare BPI (caz extrem, ex: debitor doar PF unde BPI nu se aplică), cu wizard step "Acoperire risc" cere confirmare explicită cu disclaimer juridic și audit log dedicat. Implementare: post-MVP. MVP: blocaj strict.

**PROMPT**:
> 1. **Enum `AnafStatus`** la Pas 1.2 backfill: ACTIV, INACTIV, RADIAT + `label()` i18n. Folosit pentru tipare câmp pe `Debitor`.
>
>    Notă: `Debitor.onrcStatus` (string nullable, deja existent din Pas 1.1) se redenumește semantic în `anafStatus` (sau lasă numele dacă e cost mare de migrare — important e tipul). Status-ul provine din ANAF, nu ONRC. Migrare Doctrine: rename column + tipare cu `enumType: AnafStatus::class`.
>
> 2. **Adaugă pe `Debitor`** (Pas 1.1 backfill sau migrare suplimentară 2.4a):
>    - `anafCheckedAt` (\DateTimeImmutable nullable) — momentul ultimei interogări ANAF.
>    - `inInsolvency` (bool default false) — manual entry, debitor în procedură insolvență (L 85/2014).
>    - `insolvencyCheckedAt` (\DateTimeImmutable nullable) — momentul verificării manuale BPI.
>    - `bpiProofDocumentId` (FK la `Document` nullable) — PDF publicare BPI atașat ca probă.
>    - `nrRegCom` (string nullable, ex: "J40/12345/2020") — numărul Registrului Comerțului, populat din ANAF.
>
> 3. **Reuse existent**: `AnafLookupService::lookupByCui()` returnează `stare` (ACTIV / INACTIV / RADIAT). Wizard step "Debitor" apelează deja (sau urmează să apeleze) acest serviciu la introducerea CUI și pre-populează: `name`, `address` (street/city/county/postalCode), `nrRegCom`, `anafStatus = stare`, `anafCheckedAt = now()`. NU se construiește service nou.
>
> 4. **Validator** `src/Service/Validation/OpAdmissibilityValidator.php`:
>    - Constructor: nimic (reguli pure).
>    - `validate(LegalCase $case): list<AdmissibilityIssue>` — DTO `AdmissibilityIssue` cu `severity (ERROR|WARNING)`, `code`, `messageKey` (i18n).
>    - Reguli per debitor PJ:
>      1. `anafStatus = RADIAT` → ERROR `OP_BLOCKED_DEREGISTERED` (fără personalitate juridică).
>      2. `inInsolvency = true` → ERROR `OP_BLOCKED_INSOLVENCY` (L 85/2014 — creanța la masa credală, nu OP).
>      3. `anafStatus = INACTIV` → WARNING `OP_DEFENDANT_FISCALLY_INACTIVE` (recuperare improbabilă, dar OP nu e blocat juridic).
>      4. `anafStatus IS NULL` (debitor PJ neverificat ANAF) → WARNING `OP_ANAF_NOT_VERIFIED`.
>      5. `anafCheckedAt` mai vechi de 30 zile → WARNING `OP_ANAF_STALE` (datele ANAF se schimbă).
>      6. PJ + `inInsolvency` flag NU a fost setat (`insolvencyCheckedAt IS NULL`) → WARNING `OP_INSOLVENCY_NOT_VERIFIED` (avocatul trebuie să fi verificat BPI).
>      7. PF debitor — fără validări automate; mențiune în UI că BIPF (L 151/2015) trebuie verificat manual de avocat.
>    - Apelat în Pas 5 (PDF generation — refuză generarea dacă există ERROR) și Pas 7 (submit wizard — afișează ERROR/WARNING block).
>
> 5. **Teste**:
>    - `OpAdmissibilityValidatorTest` — 8 scenarii: ACTIV+insolvency=false (no issue), RADIAT (ERROR), inInsolvency=true (ERROR), INACTIV (WARNING), anafStatus null (WARNING), anafCheckedAt > 30 zile (WARNING), insolvencyCheckedAt null (WARNING), PF debitor (no errors auto).
>    - Tests pentru `AnafLookupService` deja există (`tests/Service/Company/AnafLookupServiceTest.php`).
>
> Commit: `feat(company): OpAdmissibilityValidator (CPC art. 1014, L 85/2014) + Debitor ANAF/BPI fields`.

---

### PASUL 2.5 — Pipeline extracție date din documente (8 sub-pași) | total ~30h | 0% reutilizare

> 🟢 **REVIZIE 2026-05-09 — split granular**: Pas 2.5 a fost spart în 8 sub-pași implementabili 1-by-1, conform planului `/Users/alexc/.claude/plans/analizeaza-pasul-2-5-din-nifty-crab.md`. Decizia utilizator: **full pipeline** (toate 4 strategiile), DPA Anthropic NU e blocker, naming **EN** (`Extraction/`), default `extractionMode = LOCAL_ONLY` (per principiul minimizării GDPR — REVIZIE 2026-05-08 confirmată).

**Scop global**: serviciu cu cascadă în 4 trepte (PdfParser priority 100 → OcrText priority 70 → AiVision priority 50 → Stub priority 10) care extrage automat date din documente sursă (contracte, facturi, somații) pentru pre-popularea wizard step 1-3. Spec referință: secțiunea 7.4 din `ANALIZA-FLUXURI-LEXRECOVERY.md`.

**Decizii arhitecturale (validate la kickoff 2.5.1)**:
- **Naming EN consistent**: `src/Service/Extraction/`, `DataExtractionService`, `ExtractionStrategyInterface`, tag DI `app.extraction_strategy`, audit category `AI_EXTRACTION`. Termenii juridici în enum păstrează RO ca convenție (ex. `LegalGroundCategory::CONTRACT_VANZARE`).
- **Default `extractionMode = LOCAL_ONLY`** (NU `BALANCED`) per GDPR art. 25 (privacy by default). Avocatul activează explicit BALANCED/MAX_ACCURACY din setting cont.
- **Audit AI**: `AuditLogService::log()` extins cu `?string $category` (compatibil înapoi); coloana `AuditLog.category` indexată; pentru fiecare apel AI logăm `documentId, strategy, tokensIn, tokensOut, responseHash`.
- **GDPR PII**: `PiiMasker` mascarează CNP-uri înainte de prompt AI (`***-***-XXXX`) + restore după răspuns; CUI mascat în logs ca convenție.
- **Reflex juridic**: extracția = pre-populare; avocatul re-verifică TOATE câmpurile. UI Pas 3.0 marcheaza pre-populările cu badge "auto" + cere confirmare per câmp critic (CUI, sumă, scadență).

**DAG dependențe**:
```
2.5.1 → 2.5.2 → 2.5.3 (MVP livrabil până aici + 2.5.4)
              ↘ 2.5.4 ──┐
                        ├─→ 2.5.7 → 2.5.8 (cascadă completă)
              2.5.5 ‖ 2.5.6 (paralelizabili)
```

**Pre-condiții la kickoff 2.5.1** (verificate 2026-05-09):
- ✅ `Document.extractedData` (JSON), `extractionStatus` (enum), `extractionConfidence` (DECIMAL 3,2) — există deja în entity.
- ✅ `ExtractionStatus` enum (PENDING/PROCESSING/COMPLETED/FAILED) — există.
- ✅ `Creditor`/`Debtor`/`LegalCase` câmpuri pentru DTO mapping — există.
- ✅ `AnafLookupService` pattern integrare HTTP externă — refolosit pentru `AnthropicApiClient`.
- ✅ Messenger transport `async` (Doctrine) — folosit la Pas 2.6 (post-2.5).
- ✅ Mercure hub în `compose.yaml` — folosit la Pas 3.0 (post-2.5).
- ❌ Lipsesc: `extractionStrategy` field, enum-uri `ExtractionMode`/`LegalGroundCategory`, composer dep `smalot/pdfparser`, Docker OCR (Alpine `apk add`), env vars Anthropic, rate limiters AI, `PiiMasker`, `AuditLog.category`.

**Boundary cu sub-pașii adiacenți**:
- **Pas 2.5 livrează**: `DataExtractionService::extract(Document)` apelabil sincron + 4 strategii + DTO + persistare pe `Document`.
- **Pas 2.6 adaugă**: `ExtractDataMessage` async + handler + `DataExtractedEvent` (NU în 2.5).
- **Pas 3.0 adaugă**: UI wizard step 0 + Mercure push + pre-populare formular (NU în 2.5).

---

#### PASUL 2.5.1 — Pre-condiții: enum-uri + entity fields + migrare | ~3h | 0% reutilizare ✅ DONE 2026-05-09 (`c9c455d`)

**Rezultat**: 2 enum-uri noi (`ExtractionMode` cu `isAiAllowed()`, `LegalGroundCategory` cu `isOpEligible()` + `isDirectlyEnforceable()` post-review legal pentru CEC/CAMBIE/BILET_LA_ORDIN per Legea 58/1934 + 59/1934); 3 entity fields adăugate; migrare `Version20260509193449.php` aplicată curat; translations RO+EN; 13 tests noi (43/43 verzi pe scope). Schema in sync. Reviews: legal-clean + code commit-ready.

**Scop**: pune în baza de date toate câmpurile noi necesare strategiilor și introduce modurile de extracție. Sub-pas pur infrastructure, fără logică de business.

**Files create**:
- `src/Enum/ExtractionMode.php` — cases `LOCAL_ONLY`, `BALANCED`, `MAX_ACCURACY` + `label()` (RO) + `isAiAllowed(): bool`.
- `src/Enum/LegalGroundCategory.php` — cases `CONTRACT_VANZARE`, `CONTRACT_PRESTARI_SERVICII`, `CONTRACT_LOCATIUNE`, `CONTRACT_IMPRUMUT`, `FACTURA_ACCEPTATA`, `BILET_LA_ORDIN`, `CEC`, `CAMBIE`, `ALTE_INSCRISURI` + `label()` (RO) + `isOpEligible(): bool` (per CPC art. 1014).
- `migrations/Version20260509XXXXXX.php` — generată cu `make:migration`.

**Files modificate**:
- `src/Entity/Document.php` — adaugă `private ?string $extractionStrategy` (nullable string, max 50).
- `src/Entity/User.php` — adaugă `private string $extractionMode = 'LOCAL_ONLY'` (default GDPR-friendly).
- `src/Entity/LegalCase.php` — adaugă `private ?ExtractionMode $extractionModeOverride` (override per dosar).

**Teste**:
- `tests/Enum/ExtractionModeTest.php` — `isAiAllowed()` corect (LOCAL_ONLY=false, restul=true) + `label()` non-null.
- `tests/Enum/LegalGroundCategoryTest.php` — toate 9 cases au `label()` + `isOpEligible()` corect.
- `tests/Entity/DocumentTest.php` — getter/setter `extractionStrategy`.
- `tests/Entity/UserTest.php` — default `extractionMode === 'LOCAL_ONLY'`.

**Dependențe**: niciuna.

**Boundary**:
- ✅ Schema DB ready, enum-uri folosibile.
- ❌ Nu implementează DTO/interface/strategii/orchestrator (vine la 2.5.2).

**Commit**: `feat(extraction): pre-conditions — ExtractionMode + LegalGroundCategory enums + entity fields`

---

#### PASUL 2.5.2 — DTO + Interface + Stub + Orchestrator skeleton | ~4h | 0% reutilizare ✅ DONE 2026-05-09 (`a0fe48a`)

**Rezultat**: 4 DTOs readonly (CreditorExtraction, DebtorExtraction, ClaimExtraction, ExtractedDocumentData cu `toArray()` JSON-friendly); `ExtractionStrategyInterface` cu 4 metode (`supports`, `extract`, `priority`, `isAiBacked`); `StubExtractionStrategy` Null Object; `DataExtractionService` orchestrator (Strategy + Tagged Iterator + Chain of Responsibility + Template Method). Tracking `$bestSoFar` post-review (best-below-threshold preferat over Stub 0.0). Status `COMPLETED` pentru confidence > 0, `FAILED` pentru confidence 0. 20 tests verzi pe scope (5 DTO + 4 Stub + 11 orchestrator). Reviews: legal-clean + code commit-ready.

**Scop**: scaffolding-ul cascadei. Orchestrator-ul iterează strategiile; o singură strategie reală (Stub) — PdfParser/OcrText/AiVision vin la sub-pașii următori. Dă infrastructura DI tagged complet, testabil end-to-end fără dependențe externe.

**Files create**:
- `src/DTO/Extraction/ExtractedDocumentData.php` (readonly): `?CreditorExtraction $creditor`, `?DebtorExtraction $debtor`, `?ClaimExtraction $claim`, `int $sourceDocumentId`, `\DateTimeImmutable $extractedAt`, `string $strategy`, `float $globalConfidence`, `?string $rawOcrText = null`.
- `src/DTO/Extraction/CreditorExtraction.php` (readonly): `?PersonType $personType`, `?string $name`, `?string $cui`, `?string $personalId`, `?string $address`, `?string $iban`, `?string $legalRepresentative`, `array $confidencePerField`.
- `src/DTO/Extraction/DebtorExtraction.php` (readonly): similar, fără `iban`/`legalRepresentative`.
- `src/DTO/Extraction/ClaimExtraction.php` (readonly): `?float $amount`, `?string $currency`, `?\DateTimeImmutable $dueDate`, `?LegalGroundCategory $legalGround`, `?string $description`, `array $confidencePerField`.
- `src/Service/Extraction/ExtractionStrategyInterface.php` — `supports(Document): bool`, `extract(Document): ExtractedDocumentData`, `priority(): int`.
- `src/Service/Extraction/StubExtractionStrategy.php` — priority 10, `supports() = true` mereu, returnează DTO cu null + `globalConfidence = 0.0` + `strategy = 'stub'`.
- `src/Service/Extraction/DataExtractionService.php`:
  - Constructor: `iterable $strategies` (tagged `app.extraction_strategy`), `EntityManagerInterface`, `LoggerInterface`.
  - `extract(Document $document, ?float $confidenceThreshold = null): ExtractedDocumentData`.
  - Citește `User.extractionMode` + `LegalCase.extractionModeOverride` (override prioritate).
  - Threshold default din env `EXTRACTION_CONFIDENCE_THRESHOLD` (fallback 0.6).
  - Sortare strategii descrescător după `priority()`. Pentru fiecare: `supports()` → dacă LOCAL_ONLY și strategia e AI → skip; altfel `extract()`; dacă `globalConfidence >= threshold` → return; altfel continue.
  - Persist: `extractedData` (array serializabil din DTO), `extractionStatus = COMPLETED`, `extractionConfidence`, `extractionStrategy`.
  - **NU** dispatch event aici (event = Pas 2.6).

**Files modificate**:
- `config/services.yaml` — `instanceof: ExtractionStrategyInterface: tags: ['app.extraction_strategy']`; `DataExtractionService` cu `arguments: [!tagged_iterator app.extraction_strategy]`.

**Teste**:
- `tests/DTO/Extraction/ExtractedDocumentDataTest.php` — sanity check readonly.
- `tests/Service/Extraction/StubExtractionStrategyTest.php` — `supports()=true`, returnează DTO cu null și confidence 0.
- `tests/Service/Extraction/DataExtractionServiceTest.php` — cu doar Stub în iterator: returnează DTO + persistă; verifică LOCAL_ONLY skip pe strategii AI (mock placeholder).

**Dependențe**: 2.5.1.

**Boundary**:
- ✅ Tagged iterator + cascadă funcționale end-to-end.
- ❌ Nicio strategie reală — toate documentele primesc DTO gol din Stub.

**Commit**: `feat(extraction): DTO + ExtractionStrategyInterface + Stub strategy + DataExtractionService orchestrator`

---

#### PASUL 2.5.3 — `PdfParserExtractionStrategy` | ~5h | 0% reutilizare ✅ DONE 2026-05-10 (`8fc31c3`)

**Rezultat**: prima strategie reală (priority 100, NU AI-backed); composer require smalot/pdfparser:^2.0 (v2.12.5 LGPL-3.0); 5 helpers private extracție (CUI/CNP/IBAN/Amount/Date) + 3 validators checksum (CUI ANAF, CNP per OUG 97/2005 ponderi `[2,7,9,1,4,6,3,5,8,2,7,9]`, IBAN mod 97 chunked manual fără bcmath); section-based attribution heuristic (cel mai recent keyword anterior, window 500 chars); cache `spl_object_id($document)` text parsat; refolosit `LocalityNormalizer` pentru normalizare diacritice. **REVIZIE LEGAL CRITICĂ**: ponderile CNP corectate de la `[2,7,5,7,9,1,3,5,7,2,4,6,8]` (greșite, copiate din PLAN spec) la cele oficiale; fixture CNP `1980715221232` ca regression test diferential (valid cu pond corecte, invalid cu pond greșite). 13 tests verzi pe scope. Reviews: legal-clean + code commit-ready (1 W minor pe `(int) getId()` la `sourceDocumentId` — pre-existent în Stub/orchestrator, scope post-2.5.3).

> 🟢 **REVIZIE PLAN 2026-05-10**: PLAN linia 1100 conținea ponderi CNP greșite `2.7.5.7.9.1.3.5.7.2.4.6.8`. Implementarea corectă folosește ponderile oficiale per OUG 97/2005: `[2,7,9,1,4,6,3,5,8,2,7,9]`. PLAN trebuie corectat la trigger 2.5.4. De asemenea regex IBAN — PLAN cere `\d{16}`, BBAN RO real e alfanumeric `[A-Z0-9]{16}` (verificat cu IBAN ISO 7064 standard).

**Scop**: prima strategie reală — extracție din PDF text-based (contracte digitale, facturi electronice). Acoperă 60-80% din volumul real de documente avocat.

**Files create**:
- `src/Service/Extraction/PdfParserExtractionStrategy.php` — priority 100:
  - `supports(Document)`: doar `mime === 'application/pdf'` ȘI parser găsește >= 100 caractere text.
  - `extract(Document)`: parse cu `Smalot\PdfParser\Parser::parseFile()`; helper-uri pentru câmpuri:
    - `extractCui(string)`: regex `/(?:RO\s?)?(\d{2,10})/i` + validare checksum CUI ANAF.
    - `extractCnp(string)`: regex 13 cifre + validare checksum CNP (algoritm 2.7.5.7.9.1.3.5.7.2.4.6.8).
    - `extractAmount(string)`: regex `/([\d.,]+)\s*(?:RON|lei)/i` + normalizare separatori.
    - `extractDate(string)`: regex `/(\d{1,2})[./](\d{1,2})[./](\d{2,4})/`.
    - `extractIban(string)`: regex `/RO\d{2}[A-Z]{4}\d{16}/` + checksum mod 97.
  - Heuristici contextuale: ferestre de text (50 chars) după keywords RO (`creditor|împrumutător|furnizor|locator|cedent|emitent` → creditor; `debitor|împrumutat|client|locatar|cesionar|trasă` → debitor; `scadență|data plății|termen plată|exigibilitate` → dueDate; `temei|cauză|obiect contract` → `LegalGroundCategory`).
  - Confidence per câmp: 0.95 (pattern + context), 0.70 (doar pattern), 0.0 (absent).
  - `globalConfidence` = media confidence-urilor non-zero.
  - `strategy = 'pdf_parser'`, `rawOcrText = null`.

**Files modificate**:
- `composer.json` — `composer require smalot/pdfparser`.

**Fixtures** (`tests/fixtures/extraction/`):
- `contract-prestari-servicii.pdf` (PJ-PJ, sumă clară, scadență).
- `factura-emitent.pdf` (SC X SRL → SC Y SRL, 5000 RON).
- `pdf-fara-text.pdf` (PDF scanat — `supports()` returnează false).

**Teste** (`tests/Service/Extraction/PdfParserExtractionStrategyTest.php`):
- `testSupportsReturnsTrueForTextPdf()`
- `testSupportsReturnsFalseForScannedPdf()`
- `testExtractsCuiCnpAmountDateFromContract()`
- `testExtractsCreditorVsDebtorByContextualKeywords()`
- `testGlobalConfidenceReflectsFieldsFound()`
- `testInvalidCuiChecksumIsRejected()`

**Dependențe**: 2.5.1, 2.5.2.

**Boundary**:
- ✅ PDF text-based extras corect.
- ❌ NU acoperă scan/imagine (cade pe Stub până la 2.5.7), NU folosește AI.

**Commit**: `feat(extraction): PdfParserExtractionStrategy with Romanian patterns + context heuristics`

---

#### PASUL 2.5.4 — AuditLog category + `PiiMasker` (GDPR foundation) | ~3h | 30% reutilizare ✅ DONE 2026-05-10 (`2300b0a`)

**Rezultat**: `App\Util\PiiMasker` (final class, all-static; pattern LocalityNormalizer) cu validators `isValidCnp` (OUG 97/2005) + `isValidCui` (ANAF) + maskers `maskCnp` (last-4 preserved) + `maskCui` (full mask) + round-trip `buildCnpMap`/`restoreCnp` (cu sort DESC pe placeholders pentru a preveni corupție prin prefix match la coliziune last-4) + helper defense-in-depth `maskCnpInArray` (recursiv pe payload-uri); refactor DRY `PdfParserExtractionStrategy` → apelează direct `PiiMasker::isValidCui/isValidCnp`. `AuditLog` câmp `?string $category` indexed + `AuditLogService::log()` cu `?string $category = null` ca ultim param + constantă `CATEGORY_AI_EXTRACTION = 'AI_EXTRACTION'` + docblock care direcționează caller-ii Pas 2.5.7+ să apeleze `PiiMasker::maskCnpInArray()` ÎNAINTE de log. Migrare `Version20260509223127` aplicată dev + test. **20 tests verzi pe scope** (PiiMasker) + 7 AuditLogService + 13 AuditLogEntity = 40 noi. Reviews: legal-clean (W1 GDPR rezolvat prin helper opt-in + docblock); code: 1 BLOCKER critic prins (`restoreCnp` collision corruption) → fix-uit + regression test cu CNP-uri valide same-last-4 (`1980715221232` + `2800101031232`).

**Scop**: pune fundația GDPR înainte ca strategiile AI să trimită date externe. PiiMasker mascarează CNP-uri în log-uri și în texte trimise la AI; AuditLogService câștigă parametru `category`.

**Files create**:
- `src/Util/PiiMasker.php` (clasă utilitară pură):
  - `static maskCnp(string $text): string` — regex 13 cifre cu checksum CNP valid → `***-***-XXXX` (păstrează ultimele 4 cifre pentru disambiguare).
  - `static maskCui(string $text): string` — regex CUI cu checksum valid → `RO******`.
  - `static buildCnpMap(string $text): array<string, string>` — original → placeholder (pentru re-mapare la 2.5.7).
  - `static restoreCnp(string $maskedText, array $map): string` — re-mapare placeholder → original.

**Files modificate**:
- `src/Service/AuditLogService.php` — adaugă `?string $category = null` ca ultim parametru la `log()`.
- `src/Entity/AuditLog.php` — adaugă coloana `?string $category` (string nullable, max 50, indexat).
- `migrations/Version20260509YYYYYY.php` — `ALTER TABLE audit_log ADD category VARCHAR(50) NULL, ADD INDEX idx_audit_category (category)`.

**Teste**:
- `tests/Util/PiiMaskerTest.php`: `testMaskCnpReplacesValidCnpsAndKeepsLast4()`, `testMaskCnpIgnoresInvalidChecksum()`, `testBuildCnpMapAndRestoreRoundTrip()`, `testMaskCuiReplacesValidCui()`.
- `tests/Service/AuditLogServiceTest.php` — extindere: `testLogPersistsCategory()`.

**Dependențe**: 2.5.1, 2.5.2.

**Boundary**:
- ✅ AuditLog acceptă category fără să spargă cod existent.
- ✅ PiiMasker = utilitar pur, fără side-effects.
- ❌ NU se apelează încă din strategii — pregătește terenul pentru 2.5.7.

**Commit**: `feat(audit): category param + PiiMasker (GDPR foundation for AI extraction)`

---

#### PASUL 2.5.5 — Docker OCR + `TesseractOcrService` | ~4h | 0% reutilizare ✅ DONE 2026-05-10 (`32262e3`)

**Rezultat**: Dockerfile extins (Alpine `apk add`: tesseract-ocr 5.5.1 + tesseract-ocr-data-ron + tesseract-ocr-data-eng + poppler-utils 25.12 + imagemagick 7.1.2 + ghostscript + font-dejavu); `App\Service\Ocr\TesseractOcrService` (final class) cu pipeline image direct + PDF prin pdftoppm 300dpi → loop tesseract → parse TSV; `OcrServiceInterface`, `OcrException`, `OcrResult` DTO. Process array form (command-injection safe). Cleanup temp dir prin `try/finally`. Env `OCR_LANGUAGES=ron+eng` (ISO 639-3, NU `ro`/`rum`/`rom`) bind via `services.yaml`. 10 tests verzi (Nivel 1+2 mixed: real fixturi PNG + scanned PDF + sanity guard independent de tesseract; rejects-invalid-lang ca proof că `-l` parametrul ajunge la binary). Nivel 3 cascade — TRANSFERAT la 2.5.7 (TesseractOcrService NU implementează `ExtractionStrategyInterface` — e service utility consumat de `OcrTextExtractionStrategy` la 2.5.7). Reviews: legal-clean (GDPR art. 25 — Tesseract local, no AI extern, no PII în log); code: 2 BLOCKERS prinși și fix-uiți (`catch (ProcessFailedException)` cod mort cu `Process::run()` → schimbat la `\RuntimeException` care prinde și ProcessTimedOutException; sanity guard fixturi committed adăugat).

**Scop**: pune Tesseract + dependențele OCR în Docker (Alpine) și implementează wrapperul PHP. Sub-pas izolat — testabil fără strategii AI.

**Files modificate**:
- `Dockerfile` — Alpine `apk add` (NU `apt-get`):
  ```dockerfile
  RUN apk add --no-cache \
      tesseract-ocr \
      tesseract-ocr-data-ron \
      tesseract-ocr-data-eng \
      poppler-utils \
      imagemagick \
      ghostscript
  ```
- `compose.yaml` — verificare mount `/tmp` (default OK).

**Files create**:
- `src/DTO/Ocr/OcrResult.php` (readonly): `string $text`, `float $confidence`, `int $pageCount`.
- `src/Service/Ocr/OcrServiceInterface.php` — `extractText(string $filePath): OcrResult`.
- `src/Service/Ocr/TesseractOcrService.php`:
  - Constructor: `LoggerInterface`, `string $tesseractLanguages` (din env, default `'ron+eng'`).
  - `extractText()`: detect MIME (`finfo`); imagine → `tesseract input.jpg - -l ron+eng -c tessedit_create_tsv=1` via `Symfony\Component\Process\Process`; PDF → `pdftoppm -r 300 input.pdf prefix -png` în temp dir → loop pagini → tesseract → concat. Confidence = media `conf` per cuvânt din TSV. Cleanup cu `register_shutdown_function`.
- `src/Exception/OcrException.php` — exception custom.

**Teste** (`tests/Service/Ocr/TesseractOcrServiceTest.php`):
- `testExtractsTextFromCleanImage()` — fixture PNG simplu cu "Test 1234".
- `testExtractsTextFromMediumQualityScan()`.
- `testThrowsOcrExceptionWhenTesseractMissing()`.
- **NOTE**: marcăm cu `markTestSkipped()` la `setUp()` dacă `which tesseract` returnează nimic (CI fără Docker).

**Fixtures** (`tests/fixtures/ocr/`): 2 imagini PNG.

**Dependențe**: niciuna.

**Boundary**:
- ✅ TesseractOcrService injectabil oriunde.
- ❌ NU e folosit încă de vreo strategie (la 2.5.7).
- ❌ Docker rebuild necesar (`make up --build`) după pull.

**Commit**: `feat(ocr): Docker setup (tesseract+poppler+imagemagick) + TesseractOcrService`

---

#### PASUL 2.5.6 — `LlmClientInterface` + `AnthropicApiClient` + rate limiters + env vars | ~3.5h | 60% reutilizare (pattern AnafLookupService + OcrServiceInterface) ✅ DONE 2026-05-10 (`5d1095e`)

> 🟢 **REVIZIE 2026-05-10 — Opțiunea 2 (Interface + Implementation)**: Pe baza pattern-ului consacrat la Pas 2.5.5 (`OcrServiceInterface` + `TesseractOcrService`), abstracția AI a fost introdusă prin `App\Service\Llm\LlmClientInterface` + `AnthropicApiClient implements LlmClientInterface`. Caller-ii Pas 2.5.7-2.5.8 vor primi `LlmClientInterface` (NU clasa concretă). Justificare: `ANALIZA-FLUXURI-LEXRECOVERY.md:448` documentează "Ollama + Qwen2-VL ... V2 post-MVP" → swap probabil. Cost extra ~30min acum, ~2h savings la pivot. Namespace `App\Service\Llm\` (NU `App\Service\Anthropic\` din spec original) — neutral provider naming. Plus: retry logic deferred la Pas 2.6 async messenger handler (NOT implementat în client la nivel HTTP; un singur request fără retry intern).

**Rezultat**: 17 teste verzi (15 unit + 2 integration), baseline full-suite 41/4 neschimbat. Layer-uri livrate: Nivel 1 unit (`MockHttpClient`) + Nivel 2 integration (`KernelTestCase` DI bind + sentinel guard). Nivel 3 cascade end-to-end **deferred la 2.5.7** (precedent identic cu 2.5.5: service utility consumat indirect prin strategie viitoare; pseudo-strategy wrap = false coverage). Documentat explicit în memory `feedback_test_coverage_3_layers.md`.

**Scop**: client HTTP Anthropic abstractizat + rate limiters specifici extracției AI + env vars. Sub-pas izolat — independent testabil. Pre-condiție pentru Pas 2.5.7 (`OcrTextExtractionStrategy`) și 2.5.8 (`AiVisionExtractionStrategy`).

**Files create**:
- `src/Service/Llm/LlmClientInterface.php` — contract neutral provider:
  - `complete(array $messages, int $maxTokens = 2048, ?array $documentParts = null): LlmResponse`
  - `@note GDPR` în docblock — caller-ul MUST mascara CNP/IBAN cu `PiiMasker::maskCnp()` ÎNAINTE de a apela.
- `src/Service/Llm/AnthropicApiClient.php` — `final class implements LlmClientInterface`:
  - Constructor: `HttpClientInterface`, `string $anthropicApiKey` (bind), `string $anthropicModel` (bind), `LoggerInterface = NullLogger` — toate `private readonly`.
  - POST `https://api.anthropic.com/v1/messages` cu headers `x-api-key`, `anthropic-version: 2023-06-01` (pinned), `content-type: application/json`. Timeout 60s.
  - Mapping răspuns: `content[*].type === 'text'` concatenat → `LlmResponse.content`; `usage.input_tokens / output_tokens`; `stop_reason` → `LlmFinishReason` enum.
  - Vision: `documentParts` injectate pe ultimul mesaj `user` ca structured content blocks.
  - Throw `LlmException` pe: `apiKey === ''`, HTTP ≥ 400 (cu `errorType` din envelope), `TransportException` (DNS/TCP/TLS), JSON malformat, `content` array lipsă, `content` fără text blocks (tool_use-only).
  - Logger error: clasa exception + cod, NICIODATĂ `getMessage()` raw sau prompt content (GDPR).
  - **NU consumă rate limiter** (separation of concerns — caller-ul Pas 2.5.7+ aplică limiter-ul ÎNAINTE de a apela).
- `src/Service/Llm/LlmException.php` — `final class extends \RuntimeException`.
- `src/DTO/Llm/LlmResponse.php` — `final readonly class { string $content, int $tokensIn, int $tokensOut, LlmFinishReason $finishReason }`.
- `src/Enum/LlmFinishReason.php` — backed string enum: `COMPLETED, MAX_TOKENS, STOP_SEQUENCE, OTHER` + `label(): string` (chei i18n `enum.llm_finish_reason.*`).
- `tests/Service/Llm/AnthropicApiClientTest.php` (15 tests, `TestCase` + `MockHttpClient`):
  - Happy path: `testCompleteReturnsParsedLlmResponseOnSuccess`, `testCompleteMapsMaxTokensFinishReason`, `testCompleteSendsExpectedRequestShape`, `testCompleteAppendsDocumentPartsToLastUserMessage`.
  - Error paths: `testCompleteThrowsOnMissingApiKey`, `testCompleteThrowsLlmExceptionOn401AuthError`, `testCompleteThrowsLlmExceptionOn429RateLimit`, `testCompleteThrowsLlmExceptionOnMalformedJsonResponse`, `testCompleteThrowsLlmExceptionOnMissingContentBlock`, `testCompleteThrowsWhenDocumentPartsHaveNoUserMessage`, `testCompleteThrowsLlmExceptionOnTransportError`, `testCompleteThrowsLlmExceptionOn503ServerError`, `testCompleteThrowsWhenContentHasNoTextBlocks`.
  - Sanity: `testFinishReasonEnumLabelsAreI18nKeys`, `testFixturesAreCommittedAndReadable`.
- `tests/Service/Llm/AnthropicApiClientIntegrationTest.php` (2 tests, `KernelTestCase`):
  - `testContainerWiresLlmClientInterfaceAliasToAnthropicApiClient` — DI alias pe interfață → implementare concretă.
  - `testTestEnvUsesMockSentinelApiKey` — defense-in-depth: `.env.test` API key = `__MOCK_DO_NOT_USE__` (sentinel non-valid).
- `tests/fixtures/llm/anthropic-success-text.json` — fixture extragere text (CUI 15193236 + 14186770).
- `tests/fixtures/llm/anthropic-success-vision.json` — fixture vision response.
- `tests/fixtures/llm/anthropic-error-401.json` — `authentication_error`.
- `tests/fixtures/llm/anthropic-error-429.json` — `rate_limit_error`.
- `tests/fixtures/llm/anthropic-max-tokens.json` — `stop_reason: max_tokens`.
- `config/packages/test/services.yaml` — alias `LlmClientInterface` + `AnthropicApiClient` declarate `public: true` ONLY în test (test container fetch). TODO drop la 2.5.7 când caller-ul real va keep alive aliasul.

**Files modificate**:
- `.env`:
  ```
  ANTHROPIC_API_KEY=
  ANTHROPIC_MODEL=claude-sonnet-4-6
  EXTRACTION_CONFIDENCE_THRESHOLD=0.6
  ```
- `.env.test` — `ANTHROPIC_API_KEY=__MOCK_DO_NOT_USE__` (sentinel defense-in-depth), `ANTHROPIC_MODEL=claude-sonnet-4-6`, `EXTRACTION_CONFIDENCE_THRESHOLD=0.6`.
- `config/services.yaml` — bind `$anthropicApiKey: '%env(ANTHROPIC_API_KEY)%'`, `$anthropicModel: '%env(ANTHROPIC_MODEL)%'`, `$confidenceThreshold: '%env(float:EXTRACTION_CONFIDENCE_THRESHOLD)%'` (ready pentru 2.5.7).
- `config/packages/rate_limiter.yaml`:
  ```yaml
  extraction_ai_text: { policy: sliding_window, limit: 200, interval: '1 day' }
  extraction_ai_vision: { policy: sliding_window, limit: 50, interval: '1 day' }
  ```
  Plus override `when@test: policy: no_limit` pentru ambele (consistent cu pattern existent).

**Dependențe**: niciuna (paralel cu 2.5.5).

**Boundary**:
- ✅ Client HTTP funcțional + testat cu mocks (15 unit) + DI verified (2 integration).
- ✅ `LlmClientInterface` neutral — caller-ii viitori NU cunosc Anthropic.
- ✅ Rate limiters declarate, gata de consumat.
- ❌ Nicio strategie nu îl folosește încă (la 2.5.7 `OcrTextStrategy` e primul caller real).
- ❌ NU mascare PII în client (caller responsibility — Pas 2.5.7 mascară ÎNAINTE).
- ❌ NU retry intern (deferred Pas 2.6 messenger).

**Commit**: `feat(llm): LlmClientInterface + AnthropicApiClient + AI rate limiters`

---

#### PASUL 2.5.7 — `OcrTextExtractionStrategy` + `PiiMasker` IBAN round-trip | ~5h | 40% reutilizare ✅ DONE 2026-05-10 (`0636006`)

> 🟢 **REVIZIE 2026-05-10 — Două decizii înainte de cod**:
> - **D1 (apiKey absent operațional)**: când `ANTHROPIC_API_KEY === ''` (NU LOCAL_ONLY mode), strategia returnează zero-confidence DTO + log WARNING. Cascade preia automat (AiVision skip → Stub). NU duplicăm regex helpers din PdfParser. Justificare: regex fallback la nivel de strategy = duplicare logică ~200 linii (helpers private în PdfParser nu pot fi reutilizați direct). LOCAL_ONLY mode oferă deja regex-only path prin orchestrator skip + PdfParser priority 100.
> - **D2 (`PiiMasker` IBAN extension)**: adăugat `buildIbanMap` / `maskIban` / `restoreIban` (round-trip identic CNP). Închide legal W1 din review Pas 2.5.6 (`LlmClientInterface @note GDPR` menționa IBAN dar `PiiMasker` nu avea masker). 5 tests noi în `PiiMaskerTest`. IBAN placeholder format `IBAN_PLACEHOLDER_001` fixed-width (3 digits zero-padded).
> - Plus W1 legal corectat la implementare: `rawOcrText` persistat în `Document.extractedData` JSON e mascat (`PiiMasker::maskCnp` + `maskIban`) ÎNAINTE de DTO — GDPR art. 5(1)(c) data minimisation. Coloana DB nu mai stochează CNP/IBAN raw.

**Rezultat**: 24 teste verzi (14 unit + 3 integration + 2 cascade Nivel 3 + 5 PiiMasker IBAN). Baseline full-suite 41/4 NESCHIMBAT (477 vs 453 tests). Nivel 3 cascade end-to-end OBLIGATORIU exersat aici (transferat de la 2.5.5 + 2.5.6 per memory `feedback_test_coverage_3_layers.md`): 2 tests în `CascadeIntegrationTest` — `testScannedImageCascadesToOcrTextStrategy` (PdfParser supports=false → OcrText preia → MockHttpClient cu fixtură realistă → globalConfidence ≥ 0.6 → short-circuit) și `testLocalOnlyModeSkipsOcrTextStrategyAndFallsToStub` (LOCAL_ONLY → orchestrator filtrează AI-backed → Stub; MockHttpClient EXPLODES dacă AI call ar fi făcut). Drop workaround `config/packages/test/services.yaml` din 2.5.6 — `OcrTextExtractionStrategy` consumă `LlmClientInterface` direct → alias supraviețuiește compilation. Reviews: legal LEGAL-CLEAN (3 W transferabile), code NEEDS-FIX → fix-uit (rawOcrText masking, 8 imports neutilizate, setAccessible no-op, setOriginalFilename lipsă cascade).

**Scop**: a doua strategie reală — pentru documente scanate (imagine sau PDF fără strat text). Combinație Tesseract OCR (local) + Claude API text (după mascare CNP). Treapta cea mai eficientă cost/acuratețe (~$0.002/doc).

**Files create**:
- `src/Service/Extraction/OcrTextExtractionStrategy.php` — priority 70:
  - Constructor: `OcrServiceInterface`, `AnthropicApiClient`, `AuditLogService`, `LoggerInterface`, `RateLimiterFactory $extractionAiTextLimiter`, `string $apiKey` (bind).
  - `supports(Document)`: imagine (jpg/png) SAU PDF cu strat text < 100 caractere SAU forțat de MAX_ACCURACY.
  - `extract(Document)`:
    1. `OcrServiceInterface::extractText()` → `OcrResult`.
    2. Dacă `OCR confidence < 0.5` SAU `text.length < 200` → DTO cu `globalConfidence = 0` (signal pentru fallback).
    3. `PiiMasker::buildCnpMap()` → păstrează map.
    4. Dacă `apiKey` absent: fallback regex pe textul OCR → `globalConfidence` 0.5-0.7.
    5. Altfel: `RateLimiter::create($userId)->consume()`.
    6. `AnthropicApiClient::messages()` cu prompt structurat (vezi prompt original RO; max_tokens 2048, model `claude-sonnet-4-6`).
    7. Parse JSON → DTO. `PiiMasker::restoreCnp()` pe valori sensibile.
    8. `AuditLogService::log(category: 'AI_EXTRACTION', ...)` cu `documentId, strategy, tokensIn, tokensOut, responseHash`.
    9. `strategy = 'ocr_text'`, `rawOcrText = $ocrResult->text`.

**Teste** (`tests/Service/Extraction/OcrTextExtractionStrategyTest.php`):
- `testSupportsImageDocuments()`, `testSupportsScannedPdfWithoutTextLayer()`.
- `testFallbackToRegexWhenApiKeyAbsent()`.
- `testCallsAnthropicAndReturnsExtractedData()` — `MockHttpClient` + fixture JSON.
- `testMasksCnpInPromptToAi()` — spy verifică textul trimis NU conține CNP-uri originale.
- `testRestoresCnpInExtractedDataAfterAiResponse()`.
- `testAuditsCallWithAiExtractionCategory()`.
- `testReturnsZeroConfidenceWhenOcrTooLow()` — signal cascade.

**Dependențe**: 2.5.1, 2.5.2, 2.5.4 (PiiMasker), 2.5.5 (TesseractOcrService), 2.5.6 (AnthropicApiClient).

**Boundary**:
- ✅ Documentele scanate extrase prin OCR + Claude text.
- ✅ GDPR: CNP mascate înainte de AI.
- ✅ LOCAL_ONLY: regex fallback (fără AI).
- ❌ Documente unde OCR e prea slab → cad pe AiVision (next).

**Commit**: `feat(extraction): OcrTextExtractionStrategy with CNP masking + Anthropic text + audit`

---

#### PASUL 2.5.8 — `AiVisionExtractionStrategy` | ~4h | 50% reutilizare (pattern OcrText)

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**Scop**: ultima strategie — Claude vision direct pe document (PDF/imagine). Fallback când OcrText returnează `globalConfidence < 0.5` (scan prost, layout multi-coloană, text scris de mână). Cost ~$0.01-0.05/doc.

**Files create**:
- `src/Service/Extraction/AiVisionExtractionStrategy.php` — priority 50:
  - Constructor: `AnthropicApiClient`, `AuditLogService`, `LoggerInterface`, `RateLimiterFactory $extractionAiVisionLimiter`, `string $apiKey`.
  - `supports(Document)`: orice document, **doar dacă** `extractionMode != LOCAL_ONLY` ȘI apiKey prezent.
  - `extract(Document)`:
    1. Citește fișier (PDF/imagine) → base64.
    2. `RateLimiter::consume()` (50/zi).
    3. `AnthropicApiClient::messages()` cu `documentParts` (file input) + prompt identic OcrText.
    4. Parse JSON → DTO.
    5. `AuditLogService::log(category: 'AI_EXTRACTION', strategy: 'ai_vision', ...)`.
    6. `strategy = 'ai_vision'`, `rawOcrText = null`.

**Teste** (`tests/Service/Extraction/AiVisionExtractionStrategyTest.php`):
- `testSupportsAnyDocumentWhenAiAllowed()`.
- `testSkipsWhenLocalOnlyMode()`.
- `testCallsAnthropicWithDocumentInput()` — `MockHttpClient` + verify request body conține file part.
- `testRespectsVisionRateLimit()`.
- `testAuditsWithAiExtractionCategoryAndVisionStrategy()`.

**Fixture**: 1 imagine scan complexă la `tests/fixtures/extraction/`.

**Dependențe**: 2.5.1, 2.5.2, 2.5.4, 2.5.6.

**Boundary**:
- ✅ Cascada COMPLETĂ funcțională end-to-end (PdfParser → OcrText → AiVision → Stub).
- ✅ Cost-control prin rate limiters.
- ✅ Audit complet pentru toate apelurile AI.

**Commit**: `feat(extraction): AiVisionExtractionStrategy + completes 4-tier cascade`

---

**TESTE MINIME TOTAL Pas 2.5** (la final 2.5.8): ~30 teste (4 enum + 4 DTO + 6 PdfParser + 4 PiiMasker + 4 Tesseract + 4 Anthropic + 8 OcrText + 5 AiVision + cascadă orchestrator).

---

### PASUL 2.6 | Procesare async via Messenger | 0.5 zi | 30% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**Scop**: extracția AI poate dura 5-30 secunde — rulează async, UI face polling.

**PROMPT**:
> 1. **Message** `src/Message/ExtractDataMessage.php` (record): `documentId` (int).
>
> 2. **Handler** `src/MessageHandler/ExtractDataMessageHandler.php`:
>    - Constructor: `DocumentRepository`, `DataExtractionService`, `EventDispatcherInterface`, `EntityManagerInterface`.
>    - `__invoke(ExtractDataMessage $message)`:
>      - Load Document; setează `extractionStatus = PROCESSING` + flush.
>      - Apel `DataExtractionService::extract(document)`. Pe success: `extractionStatus = COMPLETED`, persist + flush, dispatch `DataExtractedEvent($document)`.
>      - Pe exception: `extractionStatus = FAILED`, log, NU re-throw (dă chance la fallback manual).
>
> 3. **Event** `src/Event/DataExtractedEvent.php` (clasă simplă cu `Document`).
>
> 4. **Routing Messenger** `config/packages/messenger.yaml`: `App\Message\ExtractDataMessage: async` (transport Doctrine existent, refolosit).
>
> 5. **Trigger**: la upload în step 0 wizard (vezi Pas 3.0), după persist Document → dispatch `ExtractDataMessage($document->getId())`.
>
> 6. **Worker**: actualizează `docker-entrypoint.sh` să pornească worker în dev mode: `bin/console messenger:consume async --time-limit=3600 -vv` (ca background process). În prod (Coolify): supervised worker cu auto-restart.
>
> Teste: `tests/MessageHandler/ExtractDataMessageHandlerTest.php` cu serviciu mocked, verifică status transitions și event dispatch.
>
> Commit: `feat(extragere): async processing via Messenger + DataExtractedEvent`.

---

### PASUL 3.0 | Step 0 wizard "Documente sursă" | 1 zi | 0% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**Scop**: prim step wizard — upload documente, procesare async, preview valori extrase.

**Mock-up de referință** (sugestiv): [`docs/LexRecovery/mockups/v2/03-wizard/step0-documente.html`](./mockups/v2/03-wizard/step0-documente.html) — direcție pentru layout, drag-and-drop area, listă status per document, preview valori extrase. Adaptează după nevoie.

**PROMPT**:
> 1. **Update `CaseWizardController`**: ruta `GET/POST /case/new/documente` ca step 0. Behaviour:
>    - GET: dacă session bag are deja `documentIds`, afișează preview cu valorile extrase pentru fiecare document; altfel afișează formular upload.
>    - POST upload: Symfony FormType cu `FileType` multiple (max 10 fișiere, max 10MB fiecare, doar PDF/JPG/PNG). Pentru fiecare fișier: persist `Document` (legat temporar de session, nu de Dosar — dosarul nu există încă) + dispatch `ExtractDataMessage`. Salvează `documentIds` în session bag.
>    - Buton "Sări peste" — direct la step 1 cu session bag gol pentru extracție.
>
> 2. **Mercure push în loc de polling** (folosește `mercure_controller.js` din Pas 0.2):
>    - Document upload → publică pe topic `case/temp/{sessionId}/extraction-status` evenimente cu `{documentId, status, confidence}`.
>    - În UI step 0: container cu `data-controller="mercure"` și `data-mercure-topic-value="case/temp/{sessionId}/extraction-status"`.
>    - La fiecare event Mercure → update HTML element corespunzător documentului (Turbo Stream `replace` pe element).
>    - Indicator vizual: skeleton pulsant pentru PROCESSING, ✓ verde pentru COMPLETED, ⚠ portocaliu pentru FAILED.
>    - Fallback polling: dacă EventSource fail după 5s, fall back la `extracted-data-poll_controller.js` (polling la 3s).
>
> 3. **Endpoint Mercure publish** după dispatch `ExtractDataMessage` la upload + în `ExtractDataMessageHandler` la fiecare schimbare status (PROCESSING/COMPLETED/FAILED).
>
> 4. **Template** `templates/case/_step0_documente_content.html.twig`:
>    - Drop zone upload + lista documente uploadate cu badge status și preview valori extrase (dacă COMPLETED).
>    - Pentru fiecare doc COMPLETED: card cu valorile principale extrase (denumire creditor, denumire debitor, sumă, scadență) și badge confidence.
>    - Buton "Continuă" disabled până când toate documentele sunt în status terminal (COMPLETED sau FAILED).
>    - Notă GDPR explicită: "Documentele sunt procesate cu un serviciu AI. Vezi politica noastră de confidențialitate."
>
> 5. **Logică pre-populare**: la GET step 1, controller-ul agregă `extractedData` din toate documentele session și pre-populează formularul `Step1CreditorData` cu valorile cele mai înalt-confidence per câmp. Idem step 2 (debitori) și step 3 (creanță). Câmpurile pre-populate sunt marcate cu un atribut HTML data care declanșează stilizare specială (badge "auto" lângă input).
>
> 6. **Asociere documente la dosar**: la finalul wizard (Pas 3.2 step 4 submit), documentele session se asociază definitiv cu Dosar-ul nou creat (FK `legalCase_id` setat).
>
> 7. **Update `app:seed-demo-cases`**: include 1-2 dosare demo cu documente pre-uploadate și extracție simulată (status COMPLETED, extractedData populat manual).
>
> Teste funcționale:
> - Upload 1 document → status PENDING → simulare worker → COMPLETED → preview vizibil → continuă la step 1 → câmpuri pre-populate.
> - Upload document corupt → status FAILED → continuă posibilă, fără pre-populare.
> - Skip step 0 → wizard merge ca înainte (manual fill).
>
> Commit: `feat(wizard): step 0 source documents with async extraction and pre-fill`.

---

## Faza 3: Wizard creare dosar

### PASUL 3.1 | DTOs + Forms 5 pași (cu pre-populare) | 1 zi | 50% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**Scop**: structură DTO + Form pentru wizard cu 5 pași (step 0 deja livrat la Pas 3.0; aici DTOs/Forms pentru 1-4) și logică de pre-populare din `Document.extractedData`.

**Mock-up-uri de referință** (sugestive — toate în [`docs/LexRecovery/mockups/v2/03-wizard/`](./mockups/v2/03-wizard/)):
- `step1-creditor.html` — direcție pentru câmpuri PF/PJ + autocomplete creditor + badge "auto-completat"
- `step2-debitor.html` — direcție pentru collection cu adăugare/ștergere debitor + ANAF lookup la blur CUI
- `step3-creanta.html` — direcție pentru câmpuri creanță + zona de calcul live
- `step4-confirmare.html` — direcție pentru sumar cu câmpuri auto vs manuale + checkboxes acceptare

Câmpurile vizibile în mock-up-uri sunt un punct de plecare pentru DTO-uri/Forms; ajustează (adaugă/elimini) dacă apare o nevoie justificată.

**PROMPT**:
> Creează în `src/DTO/Dosar/`:
> - `Step1CreditorData`: `creditorId` (int nullable — pentru autocomplete), sau câmpuri Creditor: `personType`, `name`, `taxId`, `personalId`, `address`, `email`, `phone`, `iban`. Plus câmpuri meta `_autoFilled` (array string field names — populated from extraction). Validări: dacă `creditorId == null`, câmpurile sunt obligatorii.
> - `Step2DebtorsData`: `debtors` (array of `Step2DebtorEntry`). Min 1, max 5.
> - `Step2DebtorEntry`: `personType`, `name`, `taxId` (validare format cu Symfony Validator), `personalId`, `address`, `email`, `phone`. Plus `_autoFilled`.
> - `Step3ClaimData`: `amount`, `dueDate`, `relationshipType`, `legalGround`, `contractualInterest` (optional %), `penalties` (optional %), `description`. Plus `_autoFilled`. Validări: amount > 0, dueDate în trecut.
> - `Step4ConfirmationData`: `acceptTerms` (bool, IsTrue), `acceptDataAccuracy` (bool, IsTrue), `_extractionSummary` (info-only — sumar câmpuri auto-completate vs manuale, afișat în confirmare).
>
> Forms în `src/Form/Dosar/`:
> - `Step1CreditorType`, `Step2DebtorsType` (CollectionType cu `Step2DebtorEntryType`), `Step3ClaimType`, `Step4ConfirmationType`.
> - Form types adaugă `data-auto-filled` attribute pe câmpurile pre-completate (folosit de Stimulus pentru badge vizual).
>
> Helper service `src/Service/Extragere/PrefillFromExtractionService.php`:
> - `prefillCreditor(array $documentIds): Step1CreditorData` — agregă `extractedData` din documente, alege per câmp valoarea cu cel mai mare confidence (≥ 0.8); câmpurile cu confidence < 0.8 lăsate goale.
> - `prefillDebtors(array $documentIds): Step2DebtorsData`.
> - `prefillClaim(array $documentIds): Step3ClaimData`.
> - Returnează DTO cu `_autoFilled` populat cu numele câmpurilor pre-completate.
>
> Teste: `tests/Form/Dosar/` — 1 test per FormType, verificare validări. `tests/Service/Extragere/PrefillFromExtractionServiceTest.php` cu 3 scenarii (multiple docs cu valori conflictuale, confidence variat, niciun doc).
>
> Commit: `feat(wizard): DTOs + Forms 5-step + prefill from extraction`.

---

### PASUL 3.2 | `CaseWizardController` + session storage | 1 zi | 60% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**Mock-up-uri de referință** (sugestive): aceleași 5 fișiere din [`docs/LexRecovery/mockups/v2/03-wizard/`](./mockups/v2/03-wizard/) (step0-documente.html → step4-confirmare.html). Template-urile Twig se inspiră din ele pentru pattern-ul de wizard (header cu Stepper, container layout, butoane back/next pe footer), cu libertate de adaptare.

**PROMPT**:
> Creează `src/Controller/Case/CaseWizardController.php` cu rute:
> - `GET /case/new` → redirect la step 0 (documente).
> - `GET/POST /case/new/{step}` (step = documents|creditor|debtor|claim|confirmation).
>
> Foloseste `$request->getSession()` pentru session bag `case_wizard_data` (similar cu pattern-ul din vechiul `CaseWizardController` — referință git history).
>
> Algoritm:
> 0. Step 0 deja implementat la Pas 3.0 (upload + dispatch ExtractDataMessage + persistă `documentIds` în session).
> 1. La GET step 1: dacă `documentIds` în session → apel `PrefillFromExtractionService::prefillCreditor()` și folosește returnul ca `data` pentru form. La POST: salvează în session.
> 2. La GET step 2: idem `prefillDebtors()`. La POST (după ANAF lookup live): salvează.
> 3. La GET step 3: idem `prefillClaim()`. La POST: validare + calcul auto (folosește `InterestCalculatorService`, `StampDutyCalculator`, `CompetentCourtResolver`).
> 4. La step 4 submit: persist entități (Creditor — dacă nou, Debitori, Dosar) + asociere documente session cu Dosar nou (set `legalCase_id`) + audit log per câmp auto-completat (sursa: AI/manual) + tranziție inițială (status implicit `AMIABIL`).
>
> Rate limiting: aplică `case_creation` rate limiter (existent, 10/oră per user) la endpoint final.
>
> Templates:
> - `templates/case/wizard.html.twig` — layout cu stepper 4 pași.
> - `_step1_creditor_content.html.twig`, `_step2_debtor_content.html.twig`, `_step3_claim_content.html.twig`, `_step4_confirmation_content.html.twig`.
> - `_stepper.html.twig` (refolosit din vechiul wizard cu adaptare la 4 pași).
>
> Teste funcționale: `tests/Controller/Case/CaseWizardControllerTest.php` — happy path complet (login → 4 pași → dosar persistat cu status AMIABIL).
>
> Commit: `feat(wizard): CaseWizardController with 5-step session storage`.

---

### PASUL 3.3 | Live Component pentru calc + UX Autocomplete + ANAF lookup | 1 zi | 20% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**Mock-up-uri de referință** (sugestive):
- [`step3-creanta.html`](./mockups/v2/03-wizard/step3-creanta.html) — direcție pentru zona de calcul live (carduri cu dobândă/taxă/instanță); Live Component înlocuiește JS-ul mock-up-ului
- [`step1-creditor.html`](./mockups/v2/03-wizard/step1-creditor.html) — direcție pentru dropdown autocomplete creditor (Tom Select via UX Autocomplete)
- [`step2-debitor.html`](./mockups/v2/03-wizard/step2-debitor.html) — direcție pentru feedback vizual la blur CUI (loading spinner + auto-fill rows)

**PROMPT**:
> Reactivitate wizard step 1-3 — folosește bundle-urile UX din Pas 0.2 unde aplicabil:
>
> 1. **`Step3ClaimLiveComponent`** (Symfony UX Live Component) în `src/Twig/Components/Step3ClaimLiveComponent.php` cu template `templates/components/Step3ClaimLiveComponent.html.twig`:
>    - Props live: `amount`, `dueDate`, `relationshipType`, `county` (din debtor).
>    - La fiecare schimbare câmp (debounce 300ms automat de Live Components), serviciul recalculează: dobânda (`InterestCalculatorService`), taxa timbru (`StampDutyCalculator`), instanța sugerată (`CompetentCourtResolver`).
>    - Render server-side instant fără JS custom — beneficiu Live Components: zero endpoint AJAX manual, zero Stimulus pentru asta.
>
> 2. **Step 1 creditor — UX Autocomplete** (Symfony UX Autocomplete):
>    - În `Step1CreditorType`: câmpul `creditorId` ca `EntityType` cu opțiunea `autocomplete: true`. Setup `AsEntityAutocompleteField` pe entitate.
>    - UX Autocomplete generează dropdown searchable cu Tom Select (zero cod custom).
>    - La selectare existent → ascunde câmpurile manual fill via Stimulus controller mic `creditor-mode_controller.js`.
>
> 3. **`debitor-anaf-lookup_controller.js`** (Stimulus, custom):
>    - La blur pe câmp CUI din step 2 (cu validare format înainte), fetch `/api/anaf-lookup/{cui}` cu rate limiter `company_lookup` (existent).
>    - La răspuns success: populează automat name + address, cu indicator vizual "completat din ANAF" (badge).
>    - La eroare: toast cu `toast:show` event (controller existent din Pas 0.2).
>
> 4. **Endpoint AJAX rămas** doar pentru ANAF lookup:
>    - `src/Controller/Api/LookupController.php`: `/api/anaf-lookup/{cui}` (GET) — refolosește `AnafLookupService` cu rate limiter `company_lookup` existent.
>
> 5. **Skeleton loading** pe câmpurile pre-populate din extracție (Pas 3.0 prefill): folosește `skeleton_controller.js` din Pas 0.2 — afișat până când extracția documentelor session e COMPLETED.
>
> Update `assets/controllers.json` cu controller-ele noi (creditor-mode, debitor-anaf-lookup).
>
> Notă: NU se mai adaugă rate limiter `live_calc` — Live Components fac POST direct la endpoint server intern, nu prin API custom expus.
>
> Teste: `tests/Twig/Components/Step3ClaimLiveComponentTest.php` cu schimbare props + assertion pe valori calculate.
>
> Rulează `bin/console importmap:install` și `make tailwind` la final.
>
> Commit: `feat(wizard): Step3ClaimLiveComponent + UX Autocomplete creditor + ANAF lookup Stimulus`.

---

## Faza 4: Termene + Workflow Subscribers

### PASUL 4.1 | `DeadlineService` | 0.5 zi | 0% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**Specificație**: secțiunea 8 din `ANALIZA-FLUXURI-LEXRECOVERY.md`.

**PROMPT**:
> Creează `src/Service/Termen/DeadlineService.php`.
>
> Constructor: `EntityManagerInterface`, `AuditLogService`.
>
> Metode:
> ```php
> public function createPaymentNoticeDeadline(LegalCase $legalCase, \DateTimeImmutable $paymentNoticeDate): LegalDeadline
> public function createAppealDeadline(LegalCase $legalCase, \DateTimeImmutable $rulingDate): LegalDeadline
> public function createPrescriptionDeadline(LegalCase $legalCase): LegalDeadline
> public function createHearingDeadline(LegalCase $legalCase, \DateTimeImmutable $date, ?string $description = null): LegalDeadline
> public function markCompleted(LegalDeadline $deadline, User $user): void
> ```
>
> Reguli:
> - Somație: paymentNoticeDate + **15 zile** (CPC art. 1015 alin. 1 — termen minim legal), prorogat la prima zi lucrătoare (CPC art. 181 alin. 2), prioritate HIGH, tip RASPUNS_SOMATIE.
> - Cerere în anulare: **rulingCommunicationDate** (NU rulingDate) + 10 zile (CPC art. 1024 alin. 1 — "de la data înmânării sau comunicării"), prorogat la prima zi lucrătoare, prioritate CRITICAL, tip CERERE_IN_ANULARE.
> - Prescripție: legalCase.dueDate + 3 ani (NCC art. 2517), prioritate CRITICAL, tip PRESCRIPTIE.
> - Judecată: data dată + descriere opțională, prioritate MEDIUM, tip JUDECATA.
>
> AuditLog la fiecare creare + completare.
>
> Teste: `tests/Service/Termen/DeadlineServiceTest.php` cu cazuri pentru fiecare metodă + verificare calculate corect ale datelor.
>
> Commit: `feat(termene): DeadlineService for automated deadline creation`.

> 🔴 **REVIZIE JURIDICĂ 2026-05-09 — C1 + C5** (ref: `ANALIZA-JURIDICA-PROCEDURA-OP-2026-05-08.md` C1, C5)
>
> **C1 — Termen somație: 15 zile (NU 30)** (CPC art. 1015 alin. 1):
> - Citat verbatim CPC art. 1015 alin. (1): *"Creditorul îi va comunica debitorului [...] o somație, prin care îi va pune în vedere să plătească suma datorată în termen de **15 zile** de la primirea acesteia."*
> - Cele 30 zile din **Legea 72/2013 art. 3 alin. (1)** sunt termen supletiv contractual între profesioniști pentru declanșarea dobânzii penalizatoare — **distinct** de termenul somației CPC. Confuzia inițială venea din această suprapunere.
> - Pentru termen extins (uzanță avocațială poate fi 20-30 zile), oferă opțiune wizard: avocatul setează manual termenul, dar minim e 15 zile. Codul: `min(15, $userInput)` cu validare.
>
> **C5 — Implementare obligatorie metodă `nextWorkingDay()` (prorogare termene)**:
>
> ```php
> public function nextWorkingDay(\DateTimeImmutable $candidate): \DateTimeImmutable
> ```
>
> Aplicat pe **TOATE** deadline-urile create (RASPUNS_SOMATIE, CERERE_IN_ANULARE, PRESCRIPTIE, JUDECATA).
>
> **Lista sărbătorilor legale RO** (Codul Muncii art. 139, hard-coded ca constant + override prin config YAML pentru zile speciale):
> - 1-2 ianuarie (Anul Nou)
> - 24 ianuarie (Unirea Principatelor)
> - Vinerea Mare + Paștele (calendar ortodox — calculat algoritmic)
> - 1 mai (Ziua Muncii)
> - 1 iunie (Ziua Copilului)
> - Rusalii (calendar ortodox — Paște + 50 zile)
> - 15 august (Adormirea Maicii Domnului)
> - 30 noiembrie (Sf. Andrei)
> - 1 decembrie (Ziua Națională)
> - 25-26 decembrie (Crăciunul)
>
> Plus zile suplimentare prin OG (ex: vacanță judecătorească iulie-august) — override prin config:
> ```yaml
> # config/packages/lexrecovery.yaml
> lexrecovery:
>   working_days:
>     additional_holidays:
>       - '2026-07-15'  # exemplu OG vacanță
> ```
>
> **CPC art. 181 alin. (2)** — citat verbatim: *"Termenul care se sfârșește într-o zi de sărbătoare legală sau când serviciul este suspendat se va prelungi până la sfârșitul primei zile de lucru următoare."*
>
> **Algoritm `nextWorkingDay()`**:
> 1. Dacă `$candidate` e zi lucrătoare (luni-vineri) și NU e sărbătoare → return `$candidate`.
> 2. Altfel: increment cu 1 zi și recursiv check.
> 3. Edge case: dacă cad 3+ sărbători consecutive → continue increment (nu e cap maxim).
>
> **Test critic adăugat** (în plus de cele existente):
> - `testNextWorkingDaySkipsSaturdaySunday`: candidate sâmbătă → returnează luni.
> - `testNextWorkingDaySkipsHolidays`: candidate 25 dec (joi) → returnează 27 dec sau prima zi lucrătoare.
> - `testNextWorkingDaySkipsLongHolidayChain`: candidate 24 dec (joi) → 25-26 dec sărbători + 27-28 weekend → returnează 29 dec luni.
> - `testPaymentNoticeDeadlineUses15DaysProrogated`: paymentNoticeDate luni 1 dec → 1+15=16 dec (marți) zi lucrătoare → return 16 dec.
> - `testAppealDeadlineUsesRulingCommunicationDateNotRulingDate`: rulingDate setat, rulingCommunicationDate null → throws sau skip; ambele setate → folosește communicationDate.
>
> **Cuplaj cu M9** (din analiza juridică, nepriorizat în CRITIC dar conex cu C5): metoda `createAppealDeadline()` primește `rulingCommunicationDate` (NU `rulingDate`). Câmp `LegalCase::rulingCommunicationDate` (DateTimeImmutable nullable) — adaugă la backfill Pas 1.1 sau migrare 4.1a suplimentară. Vezi cuplaj cu Pas 6.2 revizie (blocaj `marcheaza_definitiva` dacă communicationDate IS NULL).

---

### PASUL 4.2 | `DeadlineCreationSubscriber` + `CaseWorkflowSubscriber` | 0.5 zi | 80% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**PROMPT**:
> 1. Adaptează `src/EventSubscriber/CaseWorkflowSubscriber.php` (existent) la noul workflow: tag-ul evenimentelor rămâne `workflow.legal_case.*` (workflow-ul Symfony se numește `legal_case` per `config/packages/workflow.yaml` — NU rebrand la `dosar` în cod, conform regulii "no Romanian identifiers"). Persistă `CaseStatusHistory` + `AuditLog` la fiecare tranziție (logică deja existentă).
>
> 2. Creează `src/EventSubscriber/DeadlineCreationSubscriber.php`. Ascultă `workflow.legal_case.entered` evenimente specifice:
>    - `entered.somatie_trimisa` → `DeadlineService::createPaymentNoticeDeadline(dosar, dosar.paymentNoticeDate)`. **paymentNoticeDate** trebuie setată de avocat la tranziție; vezi pas 7.2 (UI butoane tranziție).
>    - `entered.ordonanta_emisa` → `DeadlineService::createAppealDeadline(dosar, rulingDate)`. **rulingDate** = data evenimentului portal sau setată manual.
>    - `entered.dosar_inregistrat` → reminder DEPUNERE_CERERE (deja a fost depusă) — nu, sărim acest reminder.
>
>   La creare Dosar (eveniment Doctrine `postPersist`) → `createPrescriptionDeadline(dosar)`.
>
> 3. Update `services.yaml` să declare cei doi subscriber-i (autowiring va prelua, dar verifică).
>
> Teste: `tests/EventSubscriber/CaseWorkflowSubscriberTest.php` și `DeadlineCreationSubscriberTest.php` cu integration tests verificând că tranzițiile creează `CaseStatusHistory`, `AuditLog` și `LegalDeadline` corespunzător.
>
> Commit: `feat(workflow): CaseWorkflowSubscriber + DeadlineCreationSubscriber`.

---

### PASUL 4.3 | UI tab "Termene" în view dosar (cu optimistic UI) | 0.5 zi | 0% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**PROMPT**:
> Creează `src/Controller/Dosar/TermenController.php`:
> - `POST /dosar/{id}/termen/{termenId}/complete` (CSRF) → marchează `Termen.completed = true` + audit log. Verificare voter `CASE_EDIT`. Răspuns: Turbo Stream care înlocuiește card-ul (mută din "necompletate" în "completate").
>
> Template: `templates/case/_tab_termene.html.twig` foloseste `DeadlineCard.html.twig` Twig component (din Pas 0.2). Listă termene grupate pe (necompletate / completate). Pentru fiecare: tip + label, deadlineDate formatată, badge prioritate, zile rămase (verde >7, galben 3-7, portocaliu 1-3, roșu ≤0).
>
> **Optimistic UI** pentru "Marchează completat":
> - Buton cu `data-controller="optimistic-action"` (controller existent din Pas 0.2).
> - La click: bifează checkbox + opacity 0.5 + dispatch toast `toast:show` "Termen completat" instant.
> - Form POST trimis în fundal cu Turbo Stream.
> - Pe error: revert UI + toast error.
>
> Va fi inclus în view dosar la Pas 7.2.
>
> Teste funcționale: 3 cazuri (complete propriu, complete cu user diferit → 403, complete deja completat).
>
> Commit: `feat(termene): UI tab + mark complete action`.

---

## Faza 5: Generare documente PDF *(paralelizabilă cu Faza 6)*

### PASUL 5.1 | `PaymentNoticeGeneratorService` + template | 0.5 zi | 80% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**PROMPT**:
> 1. Refactorizează `src/Service/Document/PdfGeneratorService.php` în clasă abstractă `AbstractPdfGenerator` (cu logica DomPDF + persist Document).
> 2. Creează `src/Service/Document/PaymentNoticeGeneratorService.php` care extinde `AbstractPdfGenerator`. Metoda `generate(LegalCase $legalCase): Document`. Template: `templates/pdf/payment_notice.html.twig`.
> 3. Template `payment_notice.html.twig` — somație de plată cu: antet (creditor: denumire/CUI/adresă/telefon), data emiterii, destinatar (debitor), corp text formal cerând plata în **15 zile** (CPC art. 1015 alin. 1 — citare verbatim "în termen de 15 zile de la primirea acesteia"), sumă principală + dobândă calculată + total, semnătură. Font DejaVu Sans, format A4.
> 4. Generare se apelează la tranziția `trimite_somatie` (vezi Pas 7.2 buton). Salvare ca `Document` cu type `PAYMENT_NOTICE`.
>
> Teste: `tests/Service/Document/PaymentNoticeGeneratorServiceTest.php` — verificare PDF generat (dimensiune > 0, conține "SOMAȚIE DE PLATĂ", conține string "15 zile", NU conține "30 zile" în textul somației, Document persistat).
>
> Commit: `feat(pdf): PaymentNoticeGeneratorService with payment_notice.html.twig template`.

> 🔴 **REVIZIE JURIDICĂ 2026-05-09 — C1 + C4** (ref: `ANALIZA-JURIDICA-PROCEDURA-OP-2026-05-08.md` C1, C4)
>
> **C1 — Text somație: "15 zile" (verbatim CPC art. 1015 alin. 1)**:
> - Template `payment_notice.html.twig` trebuie să spună EXACT *"prin prezenta somație vă punem în vedere să plătiți suma datorată în termen de **15 zile** de la primirea acesteia"*.
> - NU 30 zile. Cele 30 zile din Legea 72/2013 art. 3 alin. (1) sunt termen supletiv contractual, distinct de somația CPC.
> - Snapshot test obligatoriu: `tests/snapshot/payment_notice_15days.pdf` — content trebuie să conțină exact "15 zile" în paragraful relevant.
>
> **C4 — Modul de comunicare somație: "conținut declarat" obligatoriu (CPC art. 1015 alin. 1)**:
>
> CPC art. 1015 alin. (1) precizează cele 2 modalități legale de comunicare:
> 1. Prin **executor judecătoresc**.
> 2. Prin **scrisoare recomandată cu conținut declarat și confirmare de primire** (R+CD+AR).
>
> **"Conținut declarat" e serviciu specific al Poștei Române** — oficiul poștal certifică pe duplicat conținutul exact al scrisorii. **Curierul privat (Cargus, FAN, DPD, etc.) NU oferă acest serviciu** și NU e admisibil ca dovadă în fața instanței.
>
> **UI după generare PDF — modal sau alert obligatoriu** (cuplaj cu Pas 7.2 view dosar):
>
> ```html
> <!-- Memento avocat post-descărcare PDF somație -->
> <div class="alert alert-warning" data-controller="modal">
>   <h3>Comunicare somație — modalități legale (CPC art. 1015 alin. 1)</h3>
>   <ol>
>     <li><strong>Prin executor judecătoresc</strong> (recomandat pentru creanțe sensibile).</li>
>     <li><strong>Prin scrisoare recomandată cu conținut declarat (R+CD+AR) la Poșta Română</strong> — exclusiv Poșta R oferă serviciul "conținut declarat".</li>
>   </ol>
>   <p class="text-red-600"><strong>ATENȚIE</strong>: curierul privat (Cargus, FAN, DPD, etc.) <strong>NU</strong> e admisibil ca dovadă. Conținutul declarat presupune că oficiul poștal certifică pe duplicat conținutul exact al scrisorii — dovadă irefutabilă în fața instanței.</p>
> </div>
> ```
>
> **DocumentType nuanțat (opțional, decizie executare)**: la Pas 1.2 backfill, separare:
> - `DOVADA_COMUNICARE_EXECUTOR` (proces verbal executor)
> - `DOVADA_COMUNICARE_AR_CD` (recipisă Poșta Română cu conținut declarat + AR)
> - `DOVADA_COMUNICARE` (legacy, fallback) — marcat deprecated; UI cere avocatului să atașeze tipul specific.
>
> Validare upload în Pas 1.2 (DocumentUploadService): refuză upload cu type `DOVADA_COMUNICARE` dacă există tip specific disponibil — forțează precizia.
>
> **Audit log**: la upload `DOVADA_COMUNICARE_AR_CD` → câmpuri suplimentare în `Document.metadata`:
> - `postageDate`: data depunerii la oficiu.
> - `postOfficeId`: identificator oficiu (din recipisă).
> - `arNumber`: numărul AR.
>
> Test critic adăugat: `testRefusesUploadOfPrivateCourierProof`: încearcă upload cu metadata `courier=Cargus` → refuz cu mesaj clar.

---

### PASUL 5.2 | `PaymentOrderRequestGeneratorService` + opis + ZIP | 1 zi | 50% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**PROMPT**:
> 1. Creează `src/Service/Document/PaymentOrderRequestGeneratorService.php` (extinde `AbstractPdfGenerator`). Template `templates/pdf/payment_order_request.html.twig` — cerere ordonanță de plată conform CPC art. 1016: instanța competentă, părți, expunere de fapt, sume cerute (principal + dobândă + cheltuieli judiciare), temei juridic, anexe (referință la opis), semnătură avocat (cu barNumber).
>
> 2. Creează `src/Service/Document/OpisGeneratorService.php` cu template `document_index.html.twig` — listă numerotată cu toate documentele atașate dosarului.
>
> 3. Creează `src/Service/Document/CaseFilesPackager.php`:
>    ```php
>    public function package(LegalCase $legalCase): string  // returnează path ZIP temporar
>    ```
>    Conținut ZIP: `payment_order_request.pdf`, `document_index.pdf`, `payment_notice.pdf`, plus toate `Document.filePath` ale dosarului grupate în `anexe/`.
>
> 4. Update `src/Controller/Dosar/DocumentController.php` (refactor din `src/Controller/Case/DocumentController.php` existent) cu rută nouă `GET /dosar/{id}/zip-instanta` care returnează ZIP-ul ca download (Content-Disposition attachment).
>
> 5. Generarea cererii OP + opis e apelată la tranziția `depune_cerere`. ZIP-ul se descarcă pe-cerere.
>
> Teste: integration test că ZIP conține fișierele așteptate + PDF cerere generat corect.
>
> Commit: `feat(pdf): PaymentOrderRequestGeneratorService + OpisGeneratorService + CaseFilesPackager`.

---

## Faza 6: Monitorizare portal + Notificări *(paralelizabilă cu Faza 5)*

### PASUL 6.1 | `PortalMonitoringSubscriber` + adaptare CaseMonitoringService | 0.5 zi | 95% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**Specificație**: secțiunea 10 din `ANALIZA-FLUXURI-LEXRECOVERY.md`.

**PROMPT**:
> 1. Refactorizează `src/Service/Portal/CaseMonitoringService.php`:
>    - Update `findActiveForMonitoring()` să selecteze dosare cu status în `CaseStatus::isActiveOnPortal()` (DOSAR_INREGISTRAT, TERMEN_FIXAT, ORDONANTA_EMISA, IN_ANULARE — rename C2 per Pas 1.2 revizie) + `courtCaseNumber != null`.
>
> 2. Refactorizează `src/Service/Portal/PortalEventDetector.php` pentru noul model — folosește `CaseStatus`/`CaseTransition` enum-uri în loc de string-uri hardcoded.
>
> 3. Creează `src/EventSubscriber/PortalMonitoringSubscriber.php`. Ascultă evenimente Doctrine `postUpdate` pe `LegalCase`:
>    - Dacă `courtCaseNumber` a fost setat acum (era null, devine non-null) → log "monitoring activated for dosar X" și (opțional) imediat un check sincron.
>
> 4. Update `MonitoringEventApplier` (creează nou dacă nu există): pentru fiecare `CourtPortalEvent` nou detectat, propune tranziție automată în workflow:
>    - HEARING_SCHEDULED → `fixeaza_termen` (dacă status permite) + `DeadlineService::createHearingDeadline`.
>    - RULING_ISSUED admis → `emite_ordonanta` + `DeadlineService::createAppealDeadline`.
>    - RULING_ISSUED respins → `respinge`.
>    - APPEAL_FILED → `formuleaza_cerere_anulare` (rename C2 per Pas 1.2 revizie).
>
> 5. Tranzițiile sensibile (respinge, admite_cerere_anulare — rename C2) NU se aplică automat — doar logate ca "propuneri" și avocatul decide manual din UI (Pas 7.2). Tranzițiile sigure (`fixeaza_termen`, `emite_ordonanta`) se pot aplica automat dacă starea curentă permite.
>
> Teste: refolosește `tests/Service/Portal/*Test.php` cu refactor de tipuri.
>
> Commit: `feat(portal): PortalMonitoringSubscriber + adapt CaseMonitoringService for Dosar`.

---

### PASUL 6.2 | `DeadlineAlertService` + comenzi cron | 0.75 zi | 70% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**PROMPT**:
> 1. Creează `src/Service/Termen/DeadlineAlertService.php`:
>    ```php
>    public function processAlerts(\DateTimeImmutable $now): TermenAlertReport
>    ```
>    - Iterează termene necompletate.
>    - Pentru fiecare: calculează zile rămase (`deadlineDate.diff(now)`).
>    - Dacă zile == 7 și `alertSent7 == false` → trimite email + setează flag.
>    - Idem 3 zile, 1 zi.
>    - Dacă zile < 0 și `alertSentExpirat == false` → email "Termen expirat" + flag.
>    - Returnează raport cu count alerte trimise.
>
> 2. Creează `src/Command/CheckTermeneCommand.php` (`app:check-deadlines`):
>    - Cron 07:00 zilnic.
>    - Apelează `DeadlineAlertService::processAlerts(now)`.
>    - Apelează `app:auto-marcheaza-definitiva` (sub-logică integrată): pentru dosare în `ORDONANTA_EMISA` cu termen `CERERE_IN_ANULARE` (rename per Pas 1.2 revizie) expirat și fără tranziție `formuleaza_cerere_anulare` aplicată → aplică `marcheaza_definitiva` automat. **VEZI REVIZIA C5 DE MAI JOS — termenul se calculează cu prorogare și buffer**.
>    - Output structured (cu `--format=json` pentru CI).
>
> 3. Refactorizează `src/Command/MonitorCourtCasesCommand.php` → `src/Command/PortalCheckAllCommand.php` (`app:portal-check-all`):
>    - Cron 08:00 zilnic.
>    - Iterează dosare cu `courtCaseNumber != null` și `status->isActiveOnPortal()`.
>    - Pentru fiecare: query `PortalJustClient` → `PortalEventDetector` → propagare în workflow.
>    - Sleep aleatoriu 2-5 secunde între requests (rate limiting external).
>    - Retry pe failure prin Symfony Messenger (max 3 retries cu backoff exponențial).
>
> 4. Actualizează `docker-entrypoint.sh` — NU rula cron-urile la pornire (vor fi setate explicit în Coolify la Pas 9.1).
>
> Teste: `tests/Command/CheckTermeneCommandTest.php` cu MockClock (Symfony 7+), `tests/Command/PortalCheckAllCommandTest.php` cu mocked PortalJustClient.
>
> Commit: `feat(cron): DeadlineAlertService + check-termene + portal-check-all commands`.

> 🔴 **REVIZIE JURIDICĂ 2026-05-09 — C5: Prorogare în `marcheaza_definitiva`** (ref: `ANALIZA-JURIDICA-PROCEDURA-OP-2026-05-08.md` C5)
>
> **Problemă**: tranziția automată `marcheaza_definitiva` (sub-logică `app:auto-marcheaza-definitiva` în CheckTermeneCommand) NU trebuie să ruleze pe baza `rulingDate + 10z` brut — e prematur dacă a 10-a zi cade sâmbătă/duminică/sărbătoare legală (cererea în anulare poate fi încă depusă luni). Aceasta marchează dosarul ca DEFINITIVA prematur, blocând o cerere în anulare validă.
>
> **CPC art. 181 alin. (2)** — citat verbatim: *"Termenul care se sfârșește într-o zi de sărbătoare legală sau când serviciul este suspendat se va prelungi până la sfârșitul primei zile de lucru următoare."*
>
> **Algoritm corectat** pentru sub-logica `app:auto-marcheaza-definitiva`:
>
> 1. Pentru fiecare dosar în `ORDONANTA_EMISA`:
>    - Dacă `rulingCommunicationDate IS NULL` → **NU marca definitiv**; în schimb, creează alert avocat: "Completează data comunicării ordonanței pentru a începe termenul cererii în anulare." (eveniment `App\Event\MissingCommunicationDateEvent` → email + bell notification).
>    - Dacă `rulingCommunicationDate` setat: calculează `deadline = DeadlineService::nextWorkingDay(rulingCommunicationDate + 10 zile)` (vezi C5 din Pas 4.1).
> 2. Adaugă **buffer de 1 zi suplimentară** înainte de marcaj automat: `marcheaza_definitiva` se rulează DOAR dacă `now() >= deadline + 1 day`. Mai bine întârziere 1z decât tranziție prematură.
> 3. Dacă a fost aplicată tranziția `formuleaza_cerere_anulare` (rename per Pas 1.2) între timp → SKIP (cazul tipic — debitorul a contestat la timp).
>
> **Constanta buffer** în service:
> ```php
> private const AUTO_FINAL_BUFFER_DAYS = 1; // C5 — prorogare CPC art. 181 + safety
> ```
>
> Configurabil prin `services.yaml` (override pentru testing/preprod):
> ```yaml
> parameters:
>   app.legal_deadlines.auto_final_buffer_days: 1
> ```
>
> **Test critic adăugat** (în plus de cele existente):
> - `testAutoMarkFinalSkipsWhenCommunicationDateMissing`: dosar fără `rulingCommunicationDate` → NU marca; trigger eveniment alert.
> - `testAutoMarkFinalRespectsWeekendProrogation`: rulingCommunicationDate vineri 1 → 1+10=11 (luni); 11 + buffer 1z = 12 (marți). Cron rulează duminică 11 dimineața → SKIP. Cron rulează miercuri 13 → mark definitiv.
> - `testAutoMarkFinalRespectsHolidayProrogation`: rulingCommunicationDate astfel încât 10z cade pe 25 dec → prorogat la 27 dec (sau prima zi lucrătoare) + buffer 1z = 28 dec.
> - `testAutoMarkFinalSkipsIfAppealAlreadyFiled`: dosar deja în `IN_ANULARE` → SKIP (deja a fost contestat).
>
> **Cuplaj cu Pas 7.2** (view dosar): UI tab "Activitate Portal" sau "Detalii" trebuie să aibă input `rulingCommunicationDate` (DateTime, opțional cu sursă "manual" sau "portal"). Avocatul completează manual din portal.just.ro sau din comunicarea poștală.

---

### PASUL 6.3 | `EmailNotificationSubscriber` + Mercure push + templates email | 0.75 zi | 30% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**PROMPT**:
> 1. Creează `src/EventSubscriber/EmailNotificationSubscriber.php`. Ascultă:
>    - `workflow.legal_case.entered.somatie_trimisa` → email "Somație generată".
>    - `workflow.legal_case.entered.cerere_depusa` → email "Cerere OP gata, descarcă ZIP".
>    - `workflow.legal_case.entered.ordonanta_emisa` → email "Ordonanță emisă! 10 zile termen contestație".
>    - `workflow.legal_case.entered.definitiva` → email "Titlu executoriu obținut".
>    - `workflow.legal_case.entered.respinsa` → email "Cerere/contestație respinsă".
>    - Eveniment custom `App\Event\TermenAlertEvent` (dispatch din `DeadlineAlertService`) → email "Termen X — N zile rămase".
>    - Eveniment custom `App\Event\PortalEventDetectedEvent` → email "Activitate nouă pe portal — Dosar X".
>
> 2. Templates email Twig în `templates/emails/`:
>    - `dosar_status_change.html.twig` (cu variante pe status).
>    - `dosar_termen_alert.html.twig` (cu N zile rămase).
>    - `dosar_portal_event.html.twig`.
>    - Layout email comun: `templates/emails/_layout.html.twig` cu logo, footer, link unsubscribe (mock pentru MVP).
>
> 3. Persistă și `Notification` in-app (entity existent, FK `dosar` adăugat la Pas 1.1).
>
> 4. **Push real-time via Mercure** (folosește `MercureTokenService` și `mercure_controller.js` din Pas 0.2):
>    - La fiecare `Notification` persistată → publish pe topic `user/{userId}/notification` cu payload `{title, body, link, severity}`.
>    - La `TermenAlertEvent` → publish pe topic `user/{userId}/deadline-alert` (toast urgent).
>    - La `PortalEventDetectedEvent` → publish pe topic `dosar/{dosarId}/portal-event`.
>    - La schimbare status workflow → publish pe topic `dosar/{dosarId}/status-change`.
>    - Bell icon din `base.html.twig` (Pas 0.2) ascultă topic-ul user și incrementează counter + afișează toast `toast:show`.
>    - View dosar deschis ascultă topic-uri specifice dosarului → tab "Activitate Portal" se update automat fără reload.
>
> 4. Configurare `MAILER_DSN` în `.env.example`: `smtp://mailer:1025` pentru dev, `resend+api://${RESEND_API_KEY}@default` pentru prod.
>
> Teste: `tests/EventSubscriber/EmailNotificationSubscriberTest.php` cu `assertEmailCount` + `assertEmailHtmlBodyContains`.
>
> Commit: `feat(notif): EmailNotificationSubscriber + email templates`.

---

## Faza 7: Dashboard + View Dosar + Admin

### PASUL 7.1 | Dashboard avocat (cu Live Component filtre + empty state + skeleton) | 1 zi | 50% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**Mock-up-uri de referință** (sugestive):
- [`docs/LexRecovery/mockups/v2/02-dashboard/empty.html`](./mockups/v2/02-dashboard/empty.html) — direcție pentru starea fără dosare (`EmptyState` cu CTA primar "Începe primul dosar")
- [`docs/LexRecovery/mockups/v2/02-dashboard/populated.html`](./mockups/v2/02-dashboard/populated.html) — direcție pentru KPI cards, lista dosare cu filtre, badge prioritate, widget "Termene urgente"

Twig template-ul randat de `DashboardController` se inspiră din mock-up-uri (cele 2 stări — gol vs cu dosare — comutate condițional), cu libertate de adaptare.

**PROMPT**:
> Refactorizează `src/Controller/DashboardController.php` și `templates/dashboard/`:
>
> Rute:
> - `GET /dashboard` — overview cu KPI-uri (dosare active, alerte critice, sume în recuperare lună curentă).
> - `GET /dashboard/dosare` — listă paginată dosare cu filtre.
>
> Filtre dashboard dosare — folosește **Symfony UX Live Component** `DosareListLiveComponent`:
> - Props live: `status[]` (multi-select), `search` (string), `sortBy`, `page`.
> - Re-render server instant la fiecare schimbare filtru, fără page reload.
> - Component template extends `templates/dashboard/dosare.html.twig`.
>
> Add `LegalCaseRepository` queries:
> - `findActiveByUserPaginated(User $user, array $filters, int $page = 1)`.
> - `countByStatus(User $user): array` (pentru KPI-uri).
>
> Widget "Termene urgente" pe homepage dashboard: top 10 termene necompletate sortat după proximitate, cu badge prioritate (folosește `DeadlineCard.html.twig` Twig component din Pas 0.2).
>
> **Empty state** (folosește `EmptyState.html.twig` Twig component din Pas 0.2):
> - Avocat nou fără dosare → ilustrație + "Începe primul dosar de recuperare" + CTA primary "Dosar nou".
> - Filtre fără rezultate → "Niciun dosar pentru filtrele alese" + CTA "Resetează filtrele".
>
> **Skeleton loading** la încărcare lazy a tabelului via Turbo Frame (folosește `Skeleton.html.twig`).
>
> Templates: `templates/dashboard/index.html.twig`, `dosare.html.twig`, `_card_termene_urgente.html.twig`.
>
> Teste funcționale: `tests/Controller/DashboardControllerTest.php`.
>
> Commit: `feat(dashboard): avocat dashboard with filters and urgent deadlines widget`.

---

### PASUL 7.2 | View dosar cu tab-uri (lazy load + view transitions + Mercure live) | 0.75 zi | 40% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**Mock-up de referință** (sugestiv): [`docs/LexRecovery/mockups/v2/04-dosar/overview.html`](./mockups/v2/04-dosar/overview.html) — direcție pentru header dosar (caseNumber, status badge, sume), zona acțiuni (butoane tranziții workflow), structura tab-urilor.

**Notă**: tab-urile Documente / Termene / Activitate Portal / Audit nu au mock-up dedicat — derivă-le din direcția vizuală a `overview.html` (container, pattern carduri/liste). Pentru Termene poți te inspira și din [`step3-creanta.html`](./mockups/v2/03-wizard/step3-creanta.html) (carduri cu prioritate vizuală).

**PROMPT**:
> Refactorizează view dosar (din `templates/case/view.html.twig` referință) în `src/Controller/Dosar/CaseController.php` cu rută `GET /dosar/{id}` și template `templates/case/view.html.twig`.
>
> Tabs (folosind `tabs_controller.js` din Pas 0.2 — Turbo Frames lazy load + URL hash sync):
> 1. **Detalii**: creditor, debitor(i), date creanță, calculare automată actualizată (la cerere "Recalculează"), `StatusBadge.html.twig` Twig component (Pas 0.2).
> 2. **Documente**: listă cu icon per tip (UX Icons) + upload form (refolosire `DocumentUploadService`) + butoane download + delete (cu `confirm_controller.js` pentru ștergere) + buton "Descarcă ZIP instanță" (vizibil doar pentru status >= `CERERE_DEPUSA`).
> 3. **Termene**: include `_tab_termene.html.twig` (Pas 4.3).
> 4. **Activitate Portal**: timeline cronologic cu `CourtPortalEvent`. **Live update via Mercure**: container cu `data-controller="mercure"` și `data-mercure-topic-value="case/{id}/portal-event"` — la fiecare event nou detectat de cron, timeline se update automat fără reload (Pas 6.3).
> 5. **Audit**: listă AuditLog filtrat pe acest dosar.
>
> **Modal-uri tranziții workflow** (folosind `dialog_controller.js` din Pas 0.2):
> - Buton "Trimite somația" → modal cu form (data trimitere + confirmare) → submit → Turbo Stream replace pentru status badge + nou termen + document.
> - Toate modal-urile cu focus trap și close on esc.
> - Tranziții destructive ("Marchează respinsă", "Închide insolvabil") cu `confirm_controller.js`.
>
> **View transitions** la schimbare tab — animație slide subtilă via View Transitions API (Pas 0.2 setup).
>
> **Status badge live update**: container cu `data-mercure-topic-value="case/{id}/status-change"` — dacă alt avocat sau cron schimbă statusul, badge-ul se actualizează fără reload.
>
> În header view dosar: butoane tranziții valide (cu helper `CaseWorkflowService::getAvailableTransitions`). Click pe tranziție → modal cu confirmare + câmp opțional "Data X" (pentru tranziții care au nevoie: paymentNoticeDate pentru `trimite_somatie`, rulingDate pentru `emite_ordonanta`). POST CSRF la `/dosar/{id}/transition` (rută nouă în controller).
>
> La tranziția `trimite_somatie`: setează `dosar.paymentNoticeDate` din formular + apel `PaymentNoticeGeneratorService::generate(dosar)` + apel workflow.
> La tranziția `depune_cerere`: apel `PaymentOrderRequestGeneratorService::generate(dosar)` + `OpisGeneratorService::generate(dosar)` + apel workflow.
> La tranziția `inregistreaza_dosar`: setează `dosar.courtCaseNumber` din formular + apel workflow.
> La tranziția `emite_ordonanta`: setează `dosar.rulingDate` din formular + apel workflow.
>
> Teste funcționale: 5 cazuri tab-uri afișate corect + 4 cazuri tranziții (happy path + invalid).
>
> Commit: `feat(dosar): view with tabs + transition modals`.

---

### PASUL 7.3 | EasyAdmin: rename + CRUDs noi | 0.25 zi | 100% reutilizare pattern

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**PROMPT**:
> În `src/Controller/Admin/`:
> - Rename `LegalCaseCrudController` → `CaseCrudController`. Update câmpuri (status enum, creditor, debitor lista, sumă, dueDate).
> - Adaugă: `CreditorCrudController`, `DebitorCrudController`, `TermenCrudController`, `PlanCrudController`, `SubscriptionCrudController`, `InvoiceCrudController`, `InterestRateConfigCrudController`. Toate cu lista + filtre + edit (cu excepția AuditLog care rămâne readonly).
> - `DashboardController` admin: actualizează meniu cu legăturile noi. KPI-uri: total dosare, dosare active, dosare definitive ultima lună, total facturi pending.
> - Permisiuni: toți CRUD-urile cer `ROLE_ADMIN`.
>
> Teste: smoke test (admin acces 200, non-admin 403) pentru fiecare CRUD nou.
>
> Commit: `feat(admin): EasyAdmin CRUDs for new entities + dashboard refresh`.

---

## Faza 8: Monetizare *(schelet — gateway real TBD)*

### PASUL 8.1 | `SubscriptionService` + `InvoicingService` | 1 zi | 30% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**PROMPT**:
> 1. `src/Service/Billing/SubscriptionService.php`:
>    - `getActiveSubscription(User $user): ?Subscription`.
>    - `consumeDosarSlot(LegalCase $legalCase): SlotConsumption` — la creare dosar nou (apel din `CaseWizardController` Pas 3.2 după persist).
>      - Dacă subscription activ și `casesConsumed < plan.includedCases` → increment + audit log + returnează `SlotConsumption(consumed_from_plan: true)`.
>      - Altfel → creează `Invoice` `case_extra` cu `amount = plan.pricePerExtra`, status `pending`, FK către dosar și user → returnează `SlotConsumption(invoice_created: $invoice)`.
>    - `cancelSubscription(Subscription $sub): void`.
>    - `renewSubscription(Subscription $sub): void` — la final perioadă, generează factură `subscription` și extinde perioada.
>
> 2. `src/Service/Billing/InvoicingService.php`:
>    - `createSubscriptionInvoice(Subscription $sub): Invoice`.
>    - `markPaid(Invoice $invoice, ?string $externalRef = null): void`.
>    - `getInvoicesByUser(User $user, array $filters): array`.
>
> 3. Update `CaseWizardController` step 4 submit: după persist Dosar → apel `SubscriptionService::consumeDosarSlot($legalCase)`. Dacă rezultatul are `invoice_created`, redirect la `/abonament/checkout/{invoiceId}` (Pas 8.2). Altfel redirect normal la dashboard.
>
> Teste: `tests/Service/Billing/SubscriptionServiceTest.php` cu cazuri (consum exact pe plan, depășire pachet, fără subscription activ — ce facem? Pentru MVP: blocăm creare dosar fără sub activ).
>
> Commit: `feat(billing): SubscriptionService + InvoicingService with hybrid pricing`.

---

### PASUL 8.2 | Gateway stub + UI subscription/facturi | 0.5 zi | 0% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**PROMPT**:
> 1. Creează `src/Service/Billing/PaymentGatewayInterface.php`:
>    ```php
>    public function startCheckout(Invoice $invoice): CheckoutSession;  // url, externalId
>    public function handleWebhook(Request $request): WebhookResult;     // verifies signature, returns invoice update
>    ```
>
> 2. `src/Service/Billing/StubPaymentGateway.php` — implementare default:
>    - `startCheckout`: returnează URL la `/abonament/checkout/{invoiceId}` (pagina internă cu buton "Marchează ca plătit" — doar pentru dev/MVP).
>    - `handleWebhook`: nu se folosește în stub.
>
> 3. `src/Controller/SubscriptionController.php`:
>    - `GET /abonament` → vede plan curent + dosare consumate + buton "Schimbă plan" (V2).
>    - `GET /abonament/facturile-mele` → listă facturi cu status și download.
>    - `GET /abonament/checkout/{invoiceId}` → pagină stub cu buton "Marchează plătit" (dev only).
>    - `POST /abonament/checkout/{invoiceId}` → `InvoicingService::markPaid` + flash.
>
> 4. Templates `templates/subscription/`: `index.html.twig`, `facturi.html.twig`, `checkout.html.twig`.
>
> 5. Update meniu navigare în `base.html.twig` cu link "Abonament" pentru user logat.
>
> Teste: smoke functional + StubPaymentGateway test.
>
> Commit: `feat(billing): SubscriptionController + StubPaymentGateway + UI`.

---

## Faza 9: Deploy & QA

### PASUL 9.1 | Coolify staging + Mercure Hub + cron setup | 1 zi | 80% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**PROMPT**:
> 1. Verifică `Dockerfile` (existent) — adaptări: tesseract-ocr + ron + imagemagick + poppler-utils (adăugate la Pas 2.5).
>
> 2. `docker-entrypoint.sh`: păstrează (deja face migrate + tailwind build + cache clear). Adaugă wait pentru Mercure Hub disponibil. Adaugă comentarii unde sunt cron-urile setate (Coolify).
>
> 3. Coolify configuration:
>    - Service `app` (php-fpm + nginx) — refolosit.
>    - Service `database` (MySQL 8) — refolosit.
>    - **Service `mercure` (Mercure Hub)** — image `dunglas/mercure`, environment `MERCURE_PUBLISHER_JWT_KEY` și `MERCURE_SUBSCRIBER_JWT_KEY` din Coolify secrets, healthcheck activ, restart unless-stopped.
>    - Service `mailer` (Resend prin SMTP relay sau API DSN).
>    - Cron jobs (Coolify Scheduled Tasks):
>      - `0 7 * * *` → `bin/console app:check-deadlines`.
>      - `0 8 * * *` → `bin/console app:portal-check-all`.
>    - Backup: dacă Coolify nu suportă cron nativ, folosește `symfony/scheduler` (in-process). Fallback: cron extern pe VPS.
>
> 4. Env vars producție: `APP_ENV=prod`, `APP_SECRET`, `DATABASE_URL` (MySQL Coolify), `MAILER_DSN` (Resend), `MESSENGER_TRANSPORT_DSN` (doctrine), `STORAGE_PATH=/var/uploads`, `MERCURE_URL=http://mercure/.well-known/mercure`, `MERCURE_PUBLIC_URL=https://lexrecovery.app/.well-known/mercure` (proxy via Caddy/nginx la container), `MERCURE_JWT_SECRET` (32+ chars random), `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL=claude-sonnet-4-6`, `EXTRAGERE_CONFIDENCE_THRESHOLD=0.6`, `OCR_LANGUAGES=ron+eng`.
>
>    Reverse proxy nginx/Caddy: `/.well-known/mercure` → forward la container Mercure (păstrează SSE long-lived connections, dezactivează buffering).
>
> 5. Volume persistent pentru `var/uploads/` (stocare PDF-uri și documente uploadate).
>
> 6. Smoke test E2E pe staging conform secțiunii "Verificare end-to-end" de mai jos.
>
> Commit: `chore(deploy): Coolify staging config + cron setup`.

---

## Verificare end-to-end (smoke test pe staging)

După Pas 9.1, rulează manual următorul flow complet:

1. **Auth**: register avocat (cu `barNumber`) → primește email Mailpit/Resend → click verify → login.
2. **Wizard creare dosar** (5 pași):
   - **Step 0 (Documente sursă)**: încarcă un PDF contract (din `tests/fixtures/contracts/`) → spinner procesare → după ~10-30s status `COMPLETED` cu preview valori extrase (denumire creditor, denumire debitor, sumă, scadență). Verifică confidence > 0.7.
   - Step 1: form pre-populat din extracție (badge "auto" pe câmpuri) → ajustează manual unde confidence scăzut → continuă.
   - Step 2: debitor PJ cu CUI pre-populat → ANAF lookup confirmă/actualizează adresa.
   - Step 3: sumă pre-populată din contract (ex: 5000 RON), relationshipType=COMERCIAL, scadență din contract → verifică în UI: dobânda calculată ≈ 5000 × ~14% × 0.5 = ~350 RON, taxa timbru = 200 RON, instanța sugerată = Judecătoria pe județul debitorului.
   - Step 4: confirmare → vezi sumar cu numărul de câmpuri auto-completate vs manuale → submit → redirect dashboard cu flash success.
   - Verifică: status `AMIABIL`, termen PRESCRIPTIE creat automat, documentele uploadate la step 0 sunt asociate cu Dosar-ul (vizibile în tab Documente al view dosar).
   - **Test alternativ — fără extracție**: creează un al doilea dosar sărind step 0 → wizard funcționează identic ca pre-extracție (manual fill).
3. **Tranziție trimite_somatie**: din view dosar → buton "Trimite somația" → modal cu data → submit. Verifică:
   - Status `SOMATIE_TRIMISA`.
   - Document SOMATIE generat → download → PDF se deschide cu datele corecte și conține string "15 zile" (NU "30 zile"; CPC art. 1015 — vezi Pas 5.1 revizie 2026-05-09).
   - Termen RASPUNS_SOMATIE creat (data + **15 zile**, prorogat la prima zi lucrătoare CPC art. 181).
   - Email primit în Mailpit "Somație generată".
4. **Tranziție depune_cerere**: după 15+ zile (sau forțat din admin) → buton "Generează cerere OP" → submit. Verifică:
   - Documents CERERE_OP + OPIS create.
   - Buton "Descarcă ZIP instanță" disponibil → ZIP conține cerere + opis + somație + anexele uploadate.
   - Pre-condiție: avocatul a confirmat verificare BPI (insolvență) pe `bpi.just.ro` și a atașat probă (vezi Pas 2.4 revizie 2026-05-09 N4).
5. **Tranziție inregistreaza_dosar**: introdu `courtCaseNumber` (ex: "1234/302/2026"). Verifică status `DOSAR_INREGISTRAT`.
6. **Cron manual**:
   - `bin/console app:portal-check-all` → query SOAP la portal.just.ro pentru nr dosar real → events detectate (sau zero pentru dosar fictiv).
   - `bin/console app:check-deadlines` → email-uri pentru termene la 7/3/1 zile.
7. **Detective workflow**: marchează manual `emite_ordonanta` cu data + introdu `rulingCommunicationDate` (M9/Pas 4.1 revizie) → status `ORDONANTA_EMISA`, termen `CERERE_IN_ANULARE` 10 zile creat (de la `rulingCommunicationDate`, prorogat CPC art. 181). După 10 zile + buffer 1z (sau forțează cu MockClock) → `app:check-deadlines` aplică `marcheaza_definitiva` automat → status `DEFINITIVA`, email "Titlu executoriu obținut".
8. **Tests**: `make test` — toate testele PHPUnit trec (>225 din MVP-ul anterior + minim 50 noi).
9. **Admin**: `/admin` → CRUD-uri Dosar/Plan/Subscription/Invoice/InterestRateConfig funcționale.
10. **Monetizare**: creează al 6-lea dosar pentru un user cu plan Starter (5 incluse) → verifică Invoice `case_extra` create cu status pending → mark paid din UI checkout stub → status devine `paid`.

**Criteriu de acceptare MVP**: toate cele 10 puncte trec cu succes pe staging, fără intervenție tehnică.

---

## Pași marcați ca ≥ 80% reutilizabili (priorizare resurse)

| Pas | Reutilizare | Notă |
|---|---|---|
| 1.3 | 70% | Workflow YAML — structură reutilizată, doar valori schimbate |
| 1.4 | 80% | Migrare baseline + seed pattern existent |
| 4.2 | 80% | WorkflowSubscriber refactorizat |
| 5.1 | 80% | PdfGeneratorService bază |
| 6.1 | 95% | PortalMonitoring — aproape integral refolosit |
| 6.2 | 70% | Cron commands — refactorizate |
| 7.3 | 100% | EasyAdmin — pattern identic |
| 9.1 | 80% | Dockerfile + entrypoint refolosite |

## Pași 100% de la zero (efort maxim)

- 0.1 (ștergeri), 1.2 (enum-uri noi), 2.1 (InterestCalculator), 2.2 (StampDuty), 2.4 (OpAdmissibilityValidator + integrare AnafLookupService existent — 80% reuse), **2.5 (DataExtractionService cu strategii AI/PdfParser/Stub)**, **3.0 (Step 0 wizard cu polling Turbo)**, 4.1 (DeadlineService), 4.3 (UI termene), 8.2 (gateway stub).

---

## Riscuri (rezumat)

| # | Risc | Mitigare |
|---|---|---|
| R1 | ~~API ONRC~~ ELIMINAT — ONRC nu are API; date companie via ANAF API oficial (deja integrat); insolvență via BPI manual + PDF | — |
| R2 | Coolify cron | Backup `symfony/scheduler` |
| R3 | DomPDF layout complex | Migrare Gotenberg dacă apare nevoia |
| R4 | Taxa timbru OP exactă | Validare juridică pre-Pas 2.2 |
| R5 | Gateway plăți | Pas 8.2 stub; alegerea reală post-MVP |
| R6 | Migrare DB la merge develop | Drop+recreate baseline |
| R7 | Performanță cron portal-check-all | Messenger queue + sleep între requests |
| R8 | Termene legale (zile lucrătoare?) | Validare juridică; default zile calendaristice |
| R9 | Acuratețe extracție AI (date greșite pre-populate) | Confidence threshold ≥ 0.8; indicator vizual obligatoriu; test set contracte reale |
| R10 | GDPR — documente prin Anthropic API | Consimțământ explicit; setting "fără AI"; mascare CNP în log; Anthropic nu reține input |
| R11 | Cost variabil Claude API la volum | Cascadă 4-trepte: PdfParser ($0) → OCR+AI text ($0.002) → AI vision ($0.01-0.05); rate limiters separate `extragere_ai_text` (200/zi) și `extragere_ai_vision` (50/zi); cost inclus în plan |
| R12 | Acuratețe Tesseract pe scan-uri proaste | Pachet `tesseract-ocr-ron`; pre-procesare ImageMagick (deskew/denoise); fallback automat la treapta 3 dacă text < 200 chars sau confidence < 0.5 |
| R13 | Preline UI proiect de agenție mică (HtmlStream) — risc abandonare | Licență MIT permite fork dacă proiectul stagnează. Migrare ulterioară la Tailwind Plus (€249) posibilă fără refactor major — ambele sunt HTML pur peste Tailwind v4. Componentele Twig custom (§12.5) rămân stabile indiferent de bibliotecă. |

---

## Total estimare

- **~17 zile dezvoltare** distribuite în 9 faze tehnice.
- **~14 zile calendar** dacă paralelizăm Faza 5 (PDF) cu Faza 6 (Monitorizare) după ce Faza 4 e gata.
- **MVP livrabil**: aplicație funcțională pe staging, avocat poate gestiona un dosar end-to-end de la AMIABIL la DEFINITIVA, cu toate automatizările active.

---

> **Specificație de referință**: [`ANALIZA-FLUXURI-LEXRECOVERY.md`](./ANALIZA-FLUXURI-LEXRECOVERY.md). Schimbările de scope se reflectă întâi în acel document, apoi se propagă aici.
>
> **Fiecare pas construit pe cel anterior. Nu sări pași. Commit după fiecare reușită. Teste la fiecare pas.**
