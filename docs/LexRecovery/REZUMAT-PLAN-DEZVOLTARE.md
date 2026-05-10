# LexRecovery — Rezumat plan de dezvoltare

> Versiune condensată a [`PLAN-DEZVOLTARE-LEXRECOVERY.md`](./PLAN-DEZVOLTARE-LEXRECOVERY.md). Pentru pași detaliați (PROMPT, teste, commit message), urmărește link-urile către pașii individuali.

---

## TL;DR

- **31 pași** grupați în **10 faze tehnice** (Bootstrap → Domain → Calcule → Extracție + Aliniere UI → Wizard → Termene → PDF → Monitorizare → UI → Monetizare → Deploy)
- **~23.5 zile dezvoltare** efectiv | **~19 zile calendar** cu paralelism
- **Strategia DB**: drop+recreate pe branch `lexrecovery` (NU migrare progresivă) — pivotul e prea profund
- **Reguli cheie**: un pas = un prompt; un commit per pas; teste incremental
- **Specificația funcțională**: [`ANALIZA-FLUXURI-LEXRECOVERY.md`](./ANALIZA-FLUXURI-LEXRECOVERY.md) ([rezumat](./REZUMAT-ANALIZA-FLUXURI.md))
- **Mock-up-uri vizuale** (sugestive): [`mockups/v2/`](./mockups/v2/) — login, dashboard (gol+populated), wizard 5 pași, view dosar overview. Direcție de design, nu contract — implementarea Twig se inspiră din ele cu libertate de adaptare.

---

## Reguli de lucru cu Claude Code

1. **Un pas = un prompt** (nu combina mai mulți)
2. **Citește specificația întâi** — atașează secțiunea relevantă din `ANALIZA-FLUXURI` la fiecare prompt
3. **Reanalizează fezabilitatea înainte să execuți** — dependențele/spec/durată mai sunt valide? există cale mai simplă? Ajustează sau ridică problema înainte să scrii cod
4. **Verifică după fiecare pas** — rulează aplicația, testează manual
5. **Commit după fiecare pas** reușit (rollback ușor)
6. **Re-verifică reutilizarea** înainte să scrii cod nou (vezi §16 din analiză)
7. **Teste incremental** — minim unitare pentru business logic + funcționale pentru controllers

---

## Mock-up-uri vizuale disponibile

În [`mockups/v2/`](./mockups/v2/) — HTML static (Tailwind v4 + Preline UI) ca **direcție de design** pentru implementarea Twig:

| Zonă | Fișier | Pas care îl consumă |
|---|---|---|
| Design tokens + sidebar + topbar | `shared/tokens.html` + `02-dashboard/*` | **2.7** (shell + componente reutilizabile) |
| Login (split layout) | `01-auth/login.html` | 2.7 (re-implementat split 2-col) |
| Dashboard gol | `02-dashboard/empty.html` | 2.7 (livrat parțial) + 7.1 (filtre + Live Component) |
| Dashboard populated | `02-dashboard/populated.html` | 2.7 (livrat parțial: KPI + deadlines + tabel) + 7.1 |
| Wizard Step 0-4 | `03-wizard/step{0..4}-*.html` | 3.0, 3.1, 3.2, 3.3 |
| View dosar | `04-dosar/overview.html` | 7.2 (consumă `PipelineStatus` livrat la 2.7) |

**Lipsesc** (de derivat ad-hoc din direcția mock-up-urilor existente sau acceptat UI MVP refolosit): register/email-verify/reset, tab-uri view dosar (Documente/Termene/Activitate/Audit derivate din overview), modal-uri tranziții, pagini admin (EasyAdmin default), pagini monetizare, email templates.

**Regula**: mock-up-urile sunt sugestive (direcție pentru paletă, layout, ierarhie, micro-interacțiuni). Implementarea Twig se inspiră din ele dar poate adapta unde e justificat tehnic (constrângeri Live Components, accesibilitate, simplificări MVP) sau funcțional (feedback ulterior).

---

## Strategia bazei de date

**Decizie**: pe branch-ul `lexrecovery` se face **drop + recreate** — nu migrare progresivă. Cele 7 migrări existente (`Version20251124214729` ... `Version20260227134617`) se șterg la Pas 0.1; o migrare baseline unică se generează la Pas 1.4.

**Procedura dev (Docker)**:
```bash
make composer
bin/console doctrine:database:drop --force
bin/console doctrine:database:create
bin/console doctrine:migrations:migrate
```

**Seed-uri**: `app:import-courts` (existent) + `app:create-test-users` (extins cu `barNumber`) + `app:seed-demo-dosare` (nou — 3-5 dosare în statusuri diferite).

**La merge `lexrecovery` → `develop`**: regenerăm baseline curat. Datele MVP-ului anterior se pierd (acceptabil — pivot strategic).

---

## Hartă pași

### Faza 0 — Bootstrap (2 zile)

| # | Pas | Durată | Reutilizare |
|---|---|---|---|
| 0.1 | Branch `lexrecovery` + ștergere cod mort | 0.5z | 0% |
| 0.2 | Frontend Foundations: Preline UI + UX bundles + Mercure + Stimulus + Twig components | 1.5z | 0% |

### Faza 1 — Domain Model + DB (3 zile, 1.1‖1.2)

| # | Pas | Durată | Reutilizare |
|---|---|---|---|
| 1.1 | Entități noi + rename `LegalCase` → `LegalCase` | 1.5z | 30% |
| 1.2 | Enum-uri noi (CaseStatus, CaseTransition, PersonType, RelationshipType, DeadlineType, DeadlinePriority) | 0.5z | 0% |
| 1.3 | Workflow YAML refăcut + ajustare `CaseWorkflowService` | 0.5z | 70% |
| 1.4 | Migrare baseline + fixtures + `app:seed-demo-dosare` | 0.5z | 80% |

### Faza 2 — Calcule & Extracție + Aliniere UI (5.75 zile, 2.1-2.5 paralelizabile)

| # | Pas | Durată | Reutilizare |
|---|---|---|---|
| 2.1 | `InterestCalculatorService` (OG 13/2011) | 0.75z | 0% |
| 2.2 | `StampDutyCalculator` (OUG 80/2013) | 0.25z | 0% |
| 2.3 | `CompetentCourtResolver` (Judecătorie/Tribunal) | 0.5z | 30% |
| 2.4 | `OpAdmissibilityValidator` (CPC art. 1014, L 85/2014) + integrare `AnafLookupService` existent + Debitor ANAF/BPI fields | 0.5z | 80% |
| 2.5 | **`DataExtractionService` + 4 strategii cascadă** (PdfParser/OcrText/AiVision/Stub) + Tesseract + ImageMagick | **2z** | 0% |
| 2.6 | `ExtractDataMessage` async (Messenger) | 0.5z | 30% |
| 2.7 | **Aliniere shell + design system bază cu mockup-urile v2** (sidebar + topbar + 4 componente reutilizabile + split auth + dashboard KPI) — pas intermediar ÎNAINTE de Faza 3 | **1.5z** | 0% |

> **De ce 2.7 între 2.6 și 3.0?** Pas 3.0 introduce primele template-uri Twig de wizard. Înainte, `base.html.twig` era topbar-only DM Sans cu 6 design tokens — disonant cu mockup-urile v2 (sidebar 256px Inter lex-navy + Preline + shadow-soft/card). Fără aliniere prealabilă, Pas 3.0+ ar fi cerut rescriere UI ulterioară. Pas 2.7 livrează: design tokens complete (`@theme` Tailwind v4), `_sidebar`/`_topbar`/`_footer` partials, 4 componente (`KpiCard`, `DeadlineList`, `HeroEmptyState`, `PipelineStatus`) + extensie `StatusBadge` enum-aware, refactor login/register la split 2-col, dashboard cu 4 KPI + DeadlineList + how-it-works empty state, i18n ~80 chei noi. Zero regresii test suite (baseline 41/4 neschimbat).

### Faza 3 — Wizard creare dosar (4 zile)

| # | Pas | Durată | Reutilizare |
|---|---|---|---|
| 3.0 | **Step 0** "Documente sursă" cu polling Turbo | 1z | 0% |
| 3.1 | DTOs + Forms 5 pași cu pre-populare din `extractedData` | 1z | 50% |
| 3.2 | `CaseWizardController` + session storage | 1z | 60% |
| 3.3 | Stimulus controllers (`live-calc`, autocomplete, ANAF lookup) | 1z | 20% |

### Faza 4 — Termene + Workflow Subscribers (1.5 zile)

| # | Pas | Durată | Reutilizare |
|---|---|---|---|
| 4.1 | `DeadlineService` (creare automată) | 0.5z | 0% |
| 4.2 | `DeadlineCreationSubscriber` + `CaseWorkflowSubscriber` | 0.5z | 80% |
| 4.3 | UI tab "Termene" în view dosar + mark complete | 0.5z | 0% |

### Faza 5 — PDF (‖ Faza 6) (1.5 zile)

| # | Pas | Durată | Reutilizare |
|---|---|---|---|
| 5.1 | `PaymentNoticeGeneratorService` + template | 0.5z | 80% |
| 5.2 | `PaymentOrderRequestGeneratorService` + opis + `CaseFilesPackager` | 1z | 50% |

### Faza 6 — Monitorizare + Notificări (‖ Faza 5) (2 zile)

| # | Pas | Durată | Reutilizare |
|---|---|---|---|
| 6.1 | `PortalMonitoringSubscriber` + adaptare CaseMonitoring | 0.5z | **95%** |
| 6.2 | `DeadlineAlertService` + `app:check-termene` + `app:portal-check-all` | 0.75z | 70% |
| 6.3 | `EmailNotificationSubscriber` + Mercure push + email templates | 0.75z | 30% |

### Faza 7 — Dashboard + View Dosar + Admin (2 zile)

| # | Pas | Durată | Reutilizare |
|---|---|---|---|
| 7.1 | Dashboard avocat (filtre Live Component, urgențe, search) | 1z | 50% |
| 7.2 | View dosar cu tab-uri (Detalii / Documente / Termene / Activitate / Audit) | 0.75z | 40% |
| 7.3 | EasyAdmin: rename + CRUDs Plan/Subscription/Invoice/InterestRateConfig | 0.25z | **100% pattern** |

### Faza 8 — Monetizare (1.5 zile)

| # | Pas | Durată | Reutilizare |
|---|---|---|---|
| 8.1 | `SubscriptionService` + `InvoicingService` | 1z | 30% |
| 8.2 | `PaymentGatewayInterface` + stub + UI `/abonament`, `/facturile-mele` | 0.5z | 0% |

### Faza 9 — Deploy (1 zi)

| # | Pas | Durată | Reutilizare |
|---|---|---|---|
| 9.1 | Coolify staging + Mercure Hub + cron setup | 1z | 80% |

---

## Paralelisme posibile

- **1.1 ‖ 1.2** — entități și enum-uri pot merge concomitent
- **2.1 ‖ 2.2 ‖ 2.3 ‖ 2.4 ‖ 2.5** — toate calculele și extracția pot fi paralelizate (depind toate de 1.1)
- **Faza 5 ‖ Faza 6** după ce Faza 4 e gata — câștig major (~3.5 zile calendar)
- **7.3 ‖ Faza 8** — admin CRUD-uri și monetizare independente

---

## Pași cu prioritate maximă (cele mai grele, 100% de la zero)

| Pas | De ce e greu |
|---|---|
| **2.5** DataExtractionService cu 4 strategii | OCR Tesseract local + Claude API (text + vision) + cascada cu fallback + DTO + setup Docker tesseract-ocr-ron + ImageMagick |
| **3.0** Step 0 wizard | Upload + procesare async + polling Turbo Stream + preview valori extrase + indicator confidence |
| 0.2 Frontend Foundations | Preline UI + 13 Stimulus controllers + 7 Twig components + Mercure + dark mode + view transitions — toate într-un singur pas |
| 2.1 InterestCalculator | Logică nouă OG 13/2011 cu istoric BNR + breakdown pe perioade |
| 4.1 DeadlineService | Sistem nou cu calculul automat al deadline-urilor pe tip |
| 4.3 UI termene | Optimistic UI mark complete + revert pe error |

## Pași cu prioritate scăzută (≥ 80% reutilizabil — execuție rapidă)

| Pas | Reutilizare |
|---|---|
| 6.1 PortalMonitoring | **95%** — PortalJustClient + PortalEventDetector există |
| 7.3 EasyAdmin | **100% pattern** — copy/paste din CRUD-urile existente |
| 9.1 Deploy | 80% — Dockerfile + entrypoint refolosite |
| 1.4 Migrare baseline | 80% |
| 4.2 WorkflowSubscriber | 80% |
| 5.1 PaymentNoticeGenerator | 80% — `PdfGeneratorService` ca bază |

---

## Top 5 riscuri

| # | Risc | Strategie |
|---|---|---|
| **R6** | Migrare DB la merge `lexrecovery` → `develop` | Drop+recreate baseline curat |
| **R9** | Acuratețe extracție AI poate induce avocat în eroare | Confidence ≥ 0.8 pentru pre-populare; sub asta doar "sugestie"; audit log per câmp |
| **R10** | GDPR — documente prin Anthropic | Tesseract local (text only la AI) + setting `LOCAL_ONLY` + mascare CNP |
| **R11** | Cost variabil Claude API la volum | Cascadă 4 trepte ($0 → $0.002 → $0.01-0.05); rate limiters `extragere_ai_text` 200/zi + `extragere_ai_vision` 50/zi |
| **R13** | Preline UI proiect mic — risc abandonare | MIT permite fork; migrare la Tailwind Plus posibilă fără refactor major |

Vezi tabelul complet (R1-R13) la [§Riscuri în plan](./PLAN-DEZVOLTARE-LEXRECOVERY.md#riscuri-rezumat).

---

## Smoke test E2E (după Pas 9.1)

10 puncte de validat pe staging înainte ca MVP-ul să fie considerat livrat:

1. **Auth** — register avocat → email verify → login
2. **Wizard 5 pași** cu extracție AI: upload contract → preview valori detectate → form pre-populat (badge "auto") → ANAF lookup pe debitor → calcul live dobândă/taxă/instanță → submit → status `AMIABIL`
3. **Trimite somația** — modal data → PDF generat corect → termen 30 zile creat → email Mailpit
4. **Generează cerere OP** — PDF cerere + opis + ZIP cu toate documentele
5. **Înregistrează courtCaseNumber** — status `DOSAR_INREGISTRAT`, monitorizare activă
6. **Cron-uri**: `app:portal-check-all` (events SOAP) + `app:check-termene` (alerte 7/3/1)
7. **Workflow detective**: `emite_ordonanta` → `ORDONANTA_EMISA` → după 10 zile auto `marcheaza_definitiva` → `DEFINITIVA`
8. **Tests**: `make test` — toate trec (>225 din MVP + ≥50 noi)
9. **Admin** `/admin` — CRUD-uri funcționale (Dosar, Plan, Subscription, Invoice, InterestRateConfig)
10. **Monetizare**: dosar peste plan → Invoice `case_extra` pending → mark paid → status `paid`

**Criteriu acceptare**: toate cele 10 puncte trec fără intervenție tehnică.

---

## Calendar realist (cu paralelism Faza 5 ‖ Faza 6)

```
Săpt 1: 0.1 → 0.2 → 1.1‖1.2 → 1.3 → 1.4
Săpt 2: 2.1‖2.2‖2.3‖2.4 → 2.5 → 2.6 → 2.7 (aliniere UI înainte de wizard)
Săpt 3: 3.0 → 3.1 → 3.2 → 3.3 → 4.1 → 4.2 → 4.3
Săpt 4: [5.1 → 5.2] ‖ [6.1 → 6.2 → 6.3] → 7.1 → 7.2 → 7.3‖8.1 → 8.2 → 9.1 → smoke test
```

**MVP livrabil**: aplicație pe staging, avocat poate gestiona un dosar end-to-end de la `AMIABIL` la `DEFINITIVA` cu toate automatizările active.
