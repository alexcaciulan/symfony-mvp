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
| 1.2 | Domain | Enum-uri noi (CaseStatus, CaseTransition, PersonType, RelationshipType, DeadlineType, DeadlinePriority) | 0.5z | 0.1 | 0% | ✅ |
| 1.3 | Domain | Workflow YAML refăcut + ajustare `CaseWorkflowService` | 0.5z | 1.1, 1.2 | 70% | ✅ |
| 1.4 | Domain | Migrare baseline + fixtures + `app:seed-demo-cases` | 0.5z | 1.1, 1.2 | 80% | ✅ |
| 1.5 | Domain | Foundație i18n + backfill (enum labels → trans keys, homepage, Stimulus messages, ANAF exceptions) | 1z | 1.4 | 30% | ✅ |
| 2.1 | Calcule | `InterestCalculatorService` (OG 13/2011) | 0.75z | 1.1 | 0% | ⏳ |
| 2.2 | Calcule | `StampDutyCalculator` (OUG 80/2013) | 0.25z | — | 0% | ⏳ |
| 2.3 | Calcule | `CompetentCourtResolver` | 0.5z | 1.1 | 30% | ⏳ |
| 2.4 | Calcule | `OnrcLookupService` (V1 stub) | 0.25z | 1.1 | 0% | ⏳ |
| 2.5 | Extracție | `DataExtractionService` + 4 strategii cascadă (PdfParser, OcrText cu Tesseract, AiVision, Stub) + `TesseractOcrService` + DTO `ExtractedDocumentData` + setup Dockerfile cu tesseract-ocr-ron + ImageMagick | 2z | 1.1 | 0% | ⏳ |
| 2.6 | Extracție | `ExtractDataMessage` async (Symfony Messenger) + handler + persist `Document.extractedData` + emit `DataExtractedEvent` | 0.5z | 2.5 | 30% | ⏳ |
| 3.0 | Wizard | Step 0 wizard "Documente sursă": upload + procesare async + preview valori extrase + Turbo Stream polling status | 1z | 2.5, 2.6 | 0% | ⏳ |
| 3.1 | Wizard | DTOs + Forms 5 pași (Documente, Creditor, Debitor, Creanță, Confirmare) cu pre-populare din `Document.extractedData` + indicator vizual câmp auto-completat | 1z | 1.1, 2.1-2.3, 3.0 | 50% | ⏳ |
| 3.2 | Wizard | `CaseWizardController` + session storage + templates | 1z | 3.1 | 60% | ⏳ |
| 3.3 | Wizard | Stimulus controllers (`live-calc`, `creditor-search`, `debtor-search`) + endpoint-uri AJAX | 1z | 3.2, 2.1-2.4 | 20% | ⏳ |
| 4.1 | Termene | `DeadlineService` (creare automată somație/contestație/prescripție) | 0.5z | 1.1 | 0% | ⏳ |
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

> **Pași marcați ca paralelizabili**: 1.1 ‖ 1.2; 2.1 ‖ 2.2 ‖ 2.3 ‖ 2.4 ‖ 2.5; **Faza 5 ‖ Faza 6 după Faza 4**; 7.3 ‖ Faza 8.

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

### PASUL 1.2 | Enum-uri noi | 0.5 zi | 0% reutilizare | **paralel cu 1.1** ✅ DONE 2026-05-08 (`70c92f9`)

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

---

### PASUL 1.3 | Workflow YAML refăcut | 0.5 zi | 70% reutilizare ✅ DONE 2026-05-08 (`7a2fdf0`)

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
> - Mută în `src/Service/Dosar/DosarWorkflowService.php` (rename).
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

---

### PASUL 1.4 | Migrare baseline + fixtures + seed | 0.5 zi | 80% reutilizare ✅ DONE 2026-05-08 (`5084f1b`)

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

---

### PASUL 1.5 | Foundație i18n + retroactive backfill | 1 zi | 30% reutilizare ✅ DONE 2026-05-08 (`8cc92a6`)

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

---

## Faza 2: Servicii calcul & lookup

> Toate cele 4 servicii sunt **paralelizabile** — pot fi implementate în 4 sub-branch-uri sau 4 prompt-uri consecutive fără dependențe între ele (în afara Pas 1.1 care e prerequisit pentru toate).

### PASUL 2.1 | `InterestCalculatorService` | 0.75 zi | 0% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**Scop**: calcul dobândă legală conform OG 13/2011 cu istoric BNR.

**Specificație**: secțiunea 7.1 din `ANALIZA-FLUXURI-LEXRECOVERY.md`.

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
> ): DobandaResult
> ```
>
> Returnează un DTO `DobandaResult` (record class) cu:
> - `total` (float)
> - `breakdown` (array of `DobandaPerioada` cu `dataStart, dataEnd, nbrRate, applicableRate, zile, periodInterest`)
>
> Algoritm:
> 1. Obține toate `InterestRateConfig` cu `validFrom <= referenceDate`, sortat ascendent.
> 2. Construiește perioade de la `dueDate` la `referenceDate`, segmentate pe schimbările de rată BNR.
> 3. Pentru fiecare perioadă: applicableRate = nbrRate + relationshipType->nbrPercentagePoints().
> 4. periodInterest = amount * (applicableRate / 100) * days / 365.
> 5. total = sum(periodInterest).
>
> Edge cases:
> - dueDate > referenceDate → total = 0, breakdown = [].
> - Niciun InterestRateConfig anterior dueDate → throw `\RuntimeException` cu mesaj clar.
>
> Teste: `tests/Service/Calculation/InterestCalculatorServiceTest.php` cu minim 6 scenarii:
> 1. Raport COMERCIAL, perioadă o singură rată, 90 zile.
> 2. Raport CIVIL, perioadă peste 2 schimbări de rată BNR.
> 3. dueDate = referenceDate → 0.
> 4. Sub o zi (≤ 0 zile) → 0.
> 5. Peste prescripție (3+ ani) — calculul rulează, prescripția e tratată separat.
> 6. InterestRateConfig lipsă → exception.
>
> Commit: `feat(calc): InterestCalculatorService implementing OG 13/2011`.

---

### PASUL 2.2 | `StampDutyCalculator` | 0.25 zi | 0% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**PROMPT**:
> Implementează `src/Service/Calculation/StampDutyCalculator.php`:
> ```php
> public function calculate(float $amount): float
> ```
> Reguli (OUG 80/2013 art. 6 — verifică valori înainte!):
> - suma ≤ 500 RON → 50 RON
> - suma > 500 RON → 200 RON
>
> Configurabil prin `config/services.yaml` parameters (`app.taxa_timbru_op.under_500` și `app.taxa_timbru_op.over_500`).
>
> Teste: 4 cazuri (sub prag, exact prag, peste prag, suma 0).
>
> Commit: `feat(calc): StampDutyCalculator (OUG 80/2013)`.

---

### PASUL 2.3 | `CompetentCourtResolver` | 0.5 zi | 30% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**PROMPT**:
> Implementează `src/Service/Court/CompetentCourtResolver.php`.
>
> Constructor: `CourtRepository`.
>
> Metoda:
> ```php
> public function resolve(float $amount, string $county): ?Court
> ```
> Reguli (CPC art. 1015):
> - suma ≤ 200_000 → tip = JUDECATORIE, judet matchat.
> - suma > 200_000 → tip = TRIBUNAL, judet matchat.
>
> Adaugă în `CourtRepository`:
> ```php
> public function findOneByTypeAndCounty(CourtType $type, string $county): ?Court
> ```
>
> Edge cases:
> - județ neexistent → returnează null (avocatul alege manual).
> - mai multe judecătorii pe județ → returnează prima activă (sau cea mai populară — TBD; pentru MVP: prima activă).
>
> Teste: 4 scenarii (sub prag, peste prag, județ inexistent, sumă 0).
>
> Commit: `feat(court): CompetentCourtResolver with CPC art. 1015 thresholds`.

---

### PASUL 2.4 | `OnrcLookupService` (V1 stub) | 0.25 zi | 0% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**PROMPT**:
> Implementează `src/Service/Company/OnrcLookupService.php`:
> ```php
> interface OnrcLookupServiceInterface {
>     public function lookup(string $cui): ?OnrcResult;
> }
> ```
>
> `OnrcResult` (record): `cui, status (ACTIV|DIZOLVAT|INSOLVENTA|null), checkedAt`.
>
> Implementare V1 — `ManualOnrcLookupService`: returnează mereu `null` (avocatul setează manual `Debitor.onrcStatus` în UI). Loghează intenția.
>
> Pregătire V2 (post-MVP): un al doilea implementor, `ApiOnrcLookupService`, care va face HTTP call. Lasă constructorul gol și un TODO clar.
>
> Adaugă entitate `OnrcCheck` (cache 24h) la Pas 1.1 — sări pentru moment, V1 stub nu are nevoie.
>
> Teste: contract test pe interface (mock + null result).
>
> Commit: `feat(company): OnrcLookupService V1 stub (manual entry)`.

---

### PASUL 2.5 | `DataExtractionService` + 4 strategii cu OCR | 2 zile | 0% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**Scop**: serviciu cu cascadă în 4 trepte care extrage automat date (creditor, debitor, sumă, scadență) din documente sursă uploadate. Treapta 2 (OCR + AI text) este "calul de povară" pentru documente scanate.

**Specificație**: secțiunea 7.4 din `ANALIZA-FLUXURI-LEXRECOVERY.md`.

**PROMPT**:
> Implementează sistem de extracție date documente cu cascadă în 4 trepte.
>
> 1. **Setup Docker** (`Dockerfile`): adaugă pachete OCR în RUN apt-get install:
>    ```dockerfile
>    RUN apt-get update && apt-get install -y \
>        tesseract-ocr \
>        tesseract-ocr-ron \
>        imagemagick \
>        poppler-utils \
>        && rm -rf /var/lib/apt/lists/*
>    ```
>    `poppler-utils` pentru `pdftoppm` (PDF → PNG). Update `compose.yaml` dacă e nevoie.
>
> 2. **Composer require**: `smalot/pdfparser` (parser PDF text-based), `thiagoalessio/tesseract_ocr` (PHP wrapper Tesseract — opțional, alternativa e shell exec direct).
>
> 3. **DTO** `src/DTO/Extraction/ExtractedDocumentData.php` (readonly class) cu:
>    - `creditor` (?CreditorExtraction): personType, name, taxId, personalId, address, iban, legalRepresentative — toate cu `?string` și `?float $confidence` per câmp.
>    - `debtor` (?DebtorExtraction): aceleași câmpuri.
>    - `claim` (?ClaimExtraction): amount, currency, dueDate, legalGround, description — cu confidence.
>    - `sourceDocumentId` (int), `extractedAt`, `strategy` (string: "pdf_parser"|"ocr_text"|"ai_vision"|"stub"), `globalConfidence` (0-1), `rawOcrText` (?string — text complet OCR pentru audit).
>
> 4. **OCR Service** `src/Service/Ocr/OcrServiceInterface.php`:
>    ```php
>    public function extractText(string $filePath): OcrResult;  // text, confidence (avg per word), pageCount
>    ```
>    Implementare default `TesseractOcrService`:
>    - Pentru imagini (JPG/PNG): direct `tesseract input.jpg - -l ron+eng` via shell exec.
>    - Pentru PDF: convert pagină cu pagină via `pdftoppm -r 300 input.pdf output -png` → loop pe paginile generate, concatenează text.
>    - Pre-procesare opțională: `convert -density 300 -depth 8 -strip -background white -alpha off input.png output.png` (deskew + denoise).
>    - Confidence: media confidence per cuvânt din output Tesseract (`-c tessedit_create_tsv=1`).
>    - Fișiere temp curate cu `register_shutdown_function`.
>
> 5. **Interface strategii** `src/Service/Extraction/ExtractionStrategyInterface.php`:
>    ```php
>    public function supports(Document $document): bool;
>    public function extract(Document $document): ExtractedDocumentData;
>    public function priority(): int;  // pentru ordonare cascadă
>    ```
>
> 6. **Implementări (4 strategii)**:
>
>    a. **`PdfParserExtractionStrategy`** (priority 100). Folosește `smalot/pdfparser`. `supports()`: doar dacă `mime = application/pdf` și parser-ul găsește >= 100 caractere text. Logică: parse text → regex pentru CUI (`/(?:RO\s?)?(\d{2,10})/i` + validare checksum), CNP (13 cifre + checksum), sume (`/([\d.,]+)\s*(?:RON|lei)/i`), date (`/(\d{1,2})[./](\d{1,2})[./](\d{2,4})/`). Heuristici contextuale: cuvinte cheie ("creditor"/"împrumutător"/"furnizor"/"locator" → secțiune creditor; "debitor"/"împrumutat"/"client"/"locatar" → debitor; "scadență"/"data plății"/"termen plată" → data scadenței). Confidence per câmp: 0.95 dacă pattern + context match, 0.7 dacă doar pattern.
>
>    b. **`OcrTextExtractionStrategy`** (priority 70). `supports()`: imagine (JPG/PNG) sau PDF fără strat text suficient. Logică:
>       - Apel `OcrServiceInterface::extractText()` → text brut.
>       - Mascare CNP-uri în text (`***-***-XXXX`) înainte de prompt AI (security).
>       - Apel Claude API text-only (HTTP `https://api.anthropic.com/v1/messages`) cu prompt structurat:
>         ```
>         Extrage din acest text juridic românesc (extras prin OCR, posibil cu erori):
>         - creditor: { personType (INDIVIDUAL|LEGAL_ENTITY), name, taxId, personalId, address, iban, legalRepresentative }
>         - debtor: { personType, name, taxId, personalId, address }
>         - claim: { amount (number), currency (RON), dueDate (YYYY-MM-DD), legalGround, description }
>         Răspunde DOAR cu JSON valid. Pentru câmpuri incerte: null. Pentru fiecare valoare, indică confidence (0-1).
>         Text:
>         ---
>         {ocrText}
>         ---
>         ```
>       - Model: `claude-sonnet-4-6` (env var `ANTHROPIC_MODEL`). Max tokens 2048. Cost estimat: ~$0.002/doc.
>       - Re-mapare CNP-uri în răspuns (înlocuiește placeholder cu valori originale).
>       - Dacă `OCR confidence < 0.5` sau `text.length < 200` → forțează fallback la treapta 3 (returnează `globalConfidence = 0` ca semnal).
>       - Fallback fără API key: aplică heuristici regex pe textul OCR (similar PdfParser) — `globalConfidence` mediu.
>
>    c. **`AiVisionExtractionStrategy`** (priority 50). `supports()`: orice document. Logică: Claude API cu vision (input file PDF/imagine direct) — același prompt ca treapta 2. Cost: ~$0.01-0.05/doc. Folosit ca fallback când treapta 2 returnează `globalConfidence < 0.5`.
>
>    d. **`StubExtractionStrategy`** (priority 10). Returnează `ExtractedDocumentData` cu toate câmpurile null. Activ când nu e setat `ANTHROPIC_API_KEY` și OCR nu produce text utilizabil.
>
> 7. **Orchestrator** `src/Service/Extragere/DataExtractionService.php`:
>    - Constructor: `iterable $strategii` (tagged via `app.extragere_strategie`), `EntityManagerInterface`.
>    - `extract(Document $document, ?float $confidenceThreshold = 0.6): ExtractedDocumentData`.
>    - Citește setting cont `extractionMode` (LOCAL_ONLY / BALANCED / MAX_ACCURACY) — adăugare câmp pe `User`.
>    - Iterează strategiile descrescător după priority. Pentru fiecare: `supports()` → `extract()` → dacă `globalConfidence >= threshold` → return. Pentru `LOCAL_ONLY`: skip strategiile AI (OcrText fără AI fallback regex; AiVision niciodată).
>    - Persist rezultatul: `Document.extractedData` (JSON), `Document.extractionConfidence`, `Document.extractionStatus = COMPLETED`, `Document.extractionStrategy` (string).
>
> 8. **User entity**: adaugă câmp `extractionMode` (string default 'BALANCED', enum `ExtractionMode`).
>
> 9. **Configurare** `config/services.yaml`: tag-uri pe strategii. `DataExtractionService` primește `!tagged_iterator app.extragere_strategie`.
>
> 10. **Env vars** `.env`: `ANTHROPIC_API_KEY=` (gol în dev), `ANTHROPIC_MODEL=claude-sonnet-4-6`, `EXTRAGERE_CONFIDENCE_THRESHOLD=0.6`, `OCR_LANGUAGES=ron+eng`.
>
> 11. **Update Document entity**: adaugă `extractedData` (json nullable), `extractionStatus` (enum `ExtractionStatus`: PENDING/PROCESSING/COMPLETED/FAILED), `extractionConfidence` (decimal 3,2 nullable), `extractionStrategy` (string nullable). Index pe `extractionStatus`.
>
> 12. **Rate limiters noi** `config/packages/rate_limiter.yaml`:
>     - `extragere_ai_text` — 200/zi per user (treapta 2, mai ieftin).
>     - `extragere_ai_vision` — 50/zi per user (treapta 3, mai scump).
>
> 13. **Logging**: maschează CNP-uri în toate log-urile (replace cu `***-***-XXXX`).
>
> Teste:
> - `tests/Service/Ocr/TesseractOcrServiceTest.php` cu 2 fișiere fixture (imagine clară + scan de calitate medie).
> - `tests/Service/Extragere/PdfParserExtractionStrategyTest.php` — 3 PDF-uri (contract digital, factură, PDF fără text).
> - `tests/Service/Extragere/OcrTextExtractionStrategyTest.php` — 2 imagini + Claude HTTP client mocked.
> - `tests/Service/Extragere/AiVisionExtractionStrategyTest.php` — fixture imagine + Claude vision mocked.
> - `tests/Service/Extragere/DataExtractionServiceTest.php` — verifică cascada: PdfParser low confidence → OcrText → AiVision → Stub. Plus scenariu `LOCAL_ONLY` skip AI.
>
> Commit: `feat(extragere): 4-tier extraction cascade with Tesseract OCR + Claude AI`.

**TESTE MINIME**: minim 8 teste integrate (2 OCR + 3 PdfParser + 2 OcrText + 1 cascada).

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
> Update `config/packages/rate_limiter.yaml`: NU mai e nevoie de `live_calc` (Live Components fac POST direct la endpoint server, nu API custom).
>
> Teste: `tests/Twig/Components/Step3ClaimLiveComponentTest.php` cu schimbare props + assertion pe valori calculate.
>
> Rulează `bin/console importmap:install` și `make tailwind` la final.
>
> Commit: `feat(wizard): Step3ClaimLiveComponent + UX Autocomplete creditor + ANAF lookup Stimulus`.
>
> Endpoint-uri:
> - `src/Controller/Api/CalculationController.php`: ruta `/api/case/live-calc` (POST, rate-limited cu un nou rate limiter `live_calc` — 60/oră per user). Folosește serviciile de la Faza 2.
> - `src/Controller/Api/LookupController.php`: rutele `/api/creditor/search` (GET, returnează creditori ai user-ului curent care match query) și `/api/anaf-lookup/{cui}` (GET, refolosește `AnafLookupService` cu rate limiter `company_lookup` existent).
>
> Update `config/packages/rate_limiter.yaml`: adaugă `live_calc` (60/h per user, sliding window).
>
> Update `assets/controllers.json` cu cei 3 controllers noi.
>
> Teste funcționale: `tests/Controller/Api/CalculationControllerTest.php` și `LookupControllerTest.php` — verificare răspunsuri JSON + rate limiting + auth.
>
> Rulează `bin/console importmap:install` și `make tailwind` la final.
>
> Commit: `feat(wizard): live calculation + creditor/debtor autocomplete (Stimulus + API endpoints)`.

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
> - Somație: paymentNoticeDate + 30 zile, prioritate HIGH, tip RASPUNS_SOMATIE.
> - Contestație: rulingDate + 10 zile, prioritate CRITICAL, tip CONTESTATIE.
> - Prescripție: legalCase.dueDate + 3 ani, prioritate CRITICAL, tip PRESCRIPTIE.
> - Judecată: data dată + descriere opțională, prioritate MEDIUM, tip JUDECATA.
>
> AuditLog la fiecare creare + completare.
>
> Teste: `tests/Service/Termen/DeadlineServiceTest.php` cu cazuri pentru fiecare metodă + verificare calculate corect ale datelor.
>
> Commit: `feat(termene): DeadlineService for automated deadline creation`.

---

### PASUL 4.2 | `DeadlineCreationSubscriber` + `CaseWorkflowSubscriber` | 0.5 zi | 80% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**PROMPT**:
> 1. Refactorizează `src/EventSubscriber/CaseWorkflowSubscriber.php` → `src/EventSubscriber/CaseWorkflowSubscriber.php`. Schimbă tag-ul `workflow.legal_case.completed` în `workflow.dosar.completed`. Persistă `CaseStatusHistory` (rebrand intern: relația devine `LegalCase` în loc de `LegalCase` la nivel FK; nume tabel rămâne `case_status_history` pentru compatibilitate). Persistă `AuditLog`.
>
> 2. Creează `src/EventSubscriber/DeadlineCreationSubscriber.php`. Ascultă `workflow.dosar.entered` evenimente specifice:
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
> 3. Template `payment_notice.html.twig` — somație de plată cu: antet (creditor: denumire/CUI/adresă/telefon), data emiterii, destinatar (debitor), corp text formal cerând plata în 30 zile, sumă principală + dobândă calculată + total, semnătură. Font DejaVu Sans, format A4.
> 4. Generare se apelează la tranziția `trimite_somatie` (vezi Pas 7.2 buton). Salvare ca `Document` cu type `PAYMENT_NOTICE`.
>
> Teste: `tests/Service/Document/PaymentNoticeGeneratorServiceTest.php` — verificare PDF generat (dimensiune > 0, conține "SOMAȚIE DE PLATĂ", Document persistat).
>
> Commit: `feat(pdf): PaymentNoticeGeneratorService with payment_notice.html.twig template`.

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
>    - Update `findActiveForMonitoring()` să selecteze dosare cu status în `CaseStatus::isActiveOnPortal()` (DOSAR_INREGISTRAT, TERMEN_FIXAT, ORDONANTA_EMISA, CONTESTATA) + `courtCaseNumber != null`.
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
>    - APPEAL_FILED → `contesta`.
>
> 5. Tranzițiile sensibile (respinge, admite_contestatie) NU se aplică automat — doar logate ca "propuneri" și avocatul decide manual din UI (Pas 7.2). Tranzițiile sigure (`fixeaza_termen`, `emite_ordonanta`) se pot aplica automat dacă starea curentă permite.
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
>    - Apelează `app:auto-marcheaza-definitiva` (sub-logică integrată): pentru dosare în `ORDONANTA_EMISA` cu termen CONTESTATIE expirat și fără tranziție `contesta` aplicată → aplică `marcheaza_definitiva` automat.
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

---

### PASUL 6.3 | `EmailNotificationSubscriber` + Mercure push + templates email | 0.75 zi | 30% reutilizare

**Rezultat**: _(va fi completat la marcarea ca DONE)_

**PROMPT**:
> 1. Creează `src/EventSubscriber/EmailNotificationSubscriber.php`. Ascultă:
>    - `workflow.dosar.entered.somatie_trimisa` → email "Somație generată".
>    - `workflow.dosar.entered.cerere_depusa` → email "Cerere OP gata, descarcă ZIP".
>    - `workflow.dosar.entered.ordonanta_emisa` → email "Ordonanță emisă! 10 zile termen contestație".
>    - `workflow.dosar.entered.definitiva` → email "Titlu executoriu obținut".
>    - `workflow.dosar.entered.respinsa` → email "Cerere/contestație respinsă".
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
> În header view dosar: butoane tranziții valide (cu helper `DosarWorkflowService::getAvailableTransitions`). Click pe tranziție → modal cu confirmare + câmp opțional "Data X" (pentru tranziții care au nevoie: paymentNoticeDate pentru `trimite_somatie`, rulingDate pentru `emite_ordonanta`). POST CSRF la `/dosar/{id}/transition` (rută nouă în controller).
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
   - Document SOMATIE generat → download → PDF se deschide cu datele corecte.
   - Termen RASPUNS_SOMATIE creat (data + 30 zile).
   - Email primit în Mailpit "Somație generată".
4. **Tranziție depune_cerere**: după 30+ zile (sau forțat din admin) → buton "Generează cerere OP" → submit. Verifică:
   - Documents CERERE_OP + OPIS create.
   - Buton "Descarcă ZIP instanță" disponibil → ZIP conține cerere + opis + somație + anexele uploadate.
5. **Tranziție inregistreaza_dosar**: introdu `courtCaseNumber` (ex: "1234/302/2026"). Verifică status `DOSAR_INREGISTRAT`.
6. **Cron manual**:
   - `bin/console app:portal-check-all` → query SOAP la portal.just.ro pentru nr dosar real → events detectate (sau zero pentru dosar fictiv).
   - `bin/console app:check-deadlines` → email-uri pentru termene la 7/3/1 zile.
7. **Detective workflow**: marchează manual `emite_ordonanta` cu data → status `ORDONANTA_EMISA`, termen CONTESTATIE 10 zile creat. După 10 zile (sau forțează cu MockClock în test) → `app:check-deadlines` aplică `marcheaza_definitiva` automat → status `DEFINITIVA`, email "Titlu executoriu obținut".
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

- 0.1 (ștergeri), 1.2 (enum-uri noi), 2.1 (InterestCalculator), 2.2 (StampDuty), 2.4 (OnrcStub), **2.5 (DataExtractionService cu strategii AI/PdfParser/Stub)**, **3.0 (Step 0 wizard cu polling Turbo)**, 4.1 (DeadlineService), 4.3 (UI termene), 8.2 (gateway stub).

---

## Riscuri (rezumat)

| # | Risc | Mitigare |
|---|---|---|
| R1 | API ONRC | V1 stub manual; V2 post-MVP |
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
