# Pas 4.0 — Pagina overview dosar (`case_overview`)

> Plan dezvoltare detaliat — sub-pași 4.0.1 → 4.0.7
>
> **Versiune**: v1.0 — 2026-05-12
> **Sursa de adevăr (vizuală)**: [`mockups/v2/04-dosar/overview.html`](./mockups/v2/04-dosar/overview.html)
> **Effort total estimat**: 3-4 zile (7 × 0.5 zi)
> **Pre-condiții**: Pas 3.2 DONE (LegalCase complet după wizard), Pas 2.7 DONE (shell sidebar+topbar+componente design system), Pas 2.5.4 DONE (`AuditLog.category` indexed)
> **Referință în main plan**: secțiunea „PASUL 4.0" din [`PLAN-DEZVOLTARE-LEXRECOVERY.md`](./PLAN-DEZVOLTARE-LEXRECOVERY.md)

---

## 1. Scop & livrare

Pagina overview dosar e prima interfață post-wizard pe care utilizatorul (avocatul) o vede pentru un dosar deja creat. Reproduce 1:1 mockup-ul `04-dosar/overview.html` cu:

- **5 tab-uri**: Detalii / Documente / Termene / Activitate Portal / Audit.
- **Hero** cu titlu părți, badge LR-, status pill cu pulse, metadate (data creării + instanță), CTA principal („Generează cerere OP") + dropdown 3-puncte (Edit/Share/Duplicate/Close).
- **KPI grid** (4 celule): Sumă principală / Dobândă / Total creanță / Termen activ.
- **Pipeline 5-step** (Amiabil → Somație → Cerere → Monitorizare → Definitivă).
- **2 modale**: Close case + Generate OP ZIP.

**Filosofia livrării**:
- Date wired la backend acolo unde entitățile + repository-urile sunt deja livrate (LegalCase, Creditor, Debtor, Document, LegalDeadline, CourtPortalEvent, AuditLog, CaseStatusHistory, Court).
- HTML pur + `aria-disabled` pe CTA-uri care depind de pași viitori (4.3 Termene wiring, 5.x Portal monitoring, 6.x Generare PDF cerere OP).
- Zero hardcodare în Twig — toate textele prin `t()`; toate sumele/datele/procentele din entități + filtre Symfony Intl.
- Zero SVG duplicat — toate prin macro-uri în `_icons.html.twig`.
- Zero dependențe JS noi — Preline existent acoperă tabs/accordion/dropdown/modal.

---

## 2. Decizii arhitecturale & convenții

### 2.1 Rute & controller

| | |
|---|---|
| Rută | `case_overview` — URL `/dosar/{id}` (RO, alinează cu „dosar" din i18n) |
| Method | `GET` |
| ID requirements | `\d+` (LegalCase.id e int) |
| Voter | `CaseVoter::VIEW` (existent — Pas 1.x security) |
| Controller | `src/Controller/Case/CaseOverviewController.php` |
| Template | `templates/case/overview.html.twig` |
| Status codes | 200 owner / 403 alt user / 404 inexistent |

### 2.2 Structură templates

```
templates/case/overview.html.twig          # entry point — extends base.html.twig
templates/case/overview/
├── _icons.html.twig                       # ~15 macro-uri SVG (check, calendar, building, etc.)
├── _hero.html.twig                        # 4.0.2
├── _kpi_grid.html.twig                    # 4.0.2
├── _pipeline.html.twig                    # 4.0.2
├── _tabs_nav.html.twig                    # 4.0.2
├── _tab_detalii.html.twig                 # 4.0.3 orchestrator
├── _party_card.html.twig                  # 4.0.3 (creditor + debtor, prop role)
├── _claim_composition.html.twig           # 4.0.3
├── _court_summary.html.twig               # 4.0.3
├── _active_deadline_ring.html.twig        # 4.0.3
├── _recommended_actions.html.twig         # 4.0.3
├── _tab_documente.html.twig               # 4.0.4 orchestrator
├── _document_row.html.twig                # 4.0.4 (variant generated/source)
├── _documents_generated_list.html.twig    # 4.0.4
├── _documents_source_list.html.twig       # 4.0.4
├── _zip_package_card.html.twig            # 4.0.4
├── _communication_warning.html.twig       # 4.0.4
├── _tab_termene.html.twig                 # 4.0.5 orchestrator
├── _deadline_card.html.twig               # 4.0.5
├── _deadline_calendar.html.twig           # 4.0.5
├── _alerts_info.html.twig                 # 4.0.5
├── _tab_portal.html.twig                  # 4.0.6 orchestrator
├── _portal_config.html.twig               # 4.0.6
├── _portal_timeline.html.twig             # 4.0.6
├── _portal_sample_timeline.html.twig      # 4.0.6
├── _portal_how_it_works.html.twig         # 4.0.6
├── _portal_sync_status.html.twig          # 4.0.6
├── _tab_audit.html.twig                   # 4.0.7 orchestrator
├── _audit_table.html.twig                 # 4.0.7
├── _modal_close_case.html.twig            # 4.0.7
└── _modal_generate_op.html.twig           # 4.0.7
```

**Convenție**: partials sunt single-use (folosite doar în pagina overview). NU se promovează în `templates/components/` decât dacă apar în alte pagini.

### 2.3 i18n

- Prefix master: `case_overview.*` în `translations/messages.ro.yaml` și `translations/messages.en.yaml`.
- **Reutilizare chei existente** (NU duplica): `case_status.*` (Pas 1.2), `component.deadline_list.*` (Pas 2.7), `layout.brand`, `topbar.cta_new_case`, etc.
- Toate sumele/datele/procentele renderate prin filtre Symfony Intl:
  - `{{ amount|format_currency('RON', locale='ro') }}` → `47.500,00 RON`
  - `{{ date|format_datetime(pattern='dd.MM.yyyy', locale='ro') }}` → `12.04.2026`
  - `{{ pct|format_number(style='percent', locale='ro') }}` → `92,5%`
- Catalogul complet i18n e listat la final (secțiunea 4).

### 2.4 Iconuri (macro-uri SVG)

- Fișier unic: `templates/case/overview/_icons.html.twig` cu ~15 macro-uri.
- Convenții stroke 1.8 (text), 2.0 (CTA), 2.5 (heading icons + check marks).
- `currentColor` peste tot — culoarea vine din clasa `text-*` a parintelui.
- Import: `{% from 'case/overview/_icons.html.twig' import icon_check, icon_calendar %}` la începutul fiecărui partial.

Lista macro-uri necesare:
`icon_check`, `icon_calendar`, `icon_building`, `icon_clock`, `icon_eye`, `icon_download`, `icon_trash`, `icon_dots`, `icon_plus`, `icon_chevron_right`, `icon_chevron_down`, `icon_refresh`, `icon_warning`, `icon_search`, `icon_filter`, `icon_share`, `icon_copy`, `icon_lock`, `icon_external_link`, `icon_document`, `icon_money`, `icon_lightning`.

### 2.5 Pattern `aria-disabled` (CTA-uri ne-livrate)

Identic cu Pas 2.7. Link/buton cu:
- `aria-disabled="true"`
- `class="... opacity-60 cursor-not-allowed pointer-events-none"`
- atribut `title="{{ 'case_overview.tooltip.coming_soon_X'|trans }}"` pentru hover hint.
- `href="#"` (NU rută nedefinită — evită router exception).

Tooltip-uri necesare (i18n):
- `case_overview.tooltip.coming_soon_op` — „Disponibil la livrarea Generare PDF (Faza 6)"
- `case_overview.tooltip.coming_soon_portal` — „Disponibil la livrarea Monitorizare Portal (Faza 5)"
- `case_overview.tooltip.coming_soon_deadlines` — „Disponibil la livrarea Wiring Termene (Pas 4.3)"
- `case_overview.tooltip.coming_soon_close` — „Disponibil la livrarea Workflow Închidere (Faza 4.x)"
- `case_overview.tooltip.coming_soon_export` — „Export CSV — post-MVP"
- `case_overview.tooltip.coming_soon_share` — „Share & colaboratori — post-MVP"

### 2.6 Defensiv null

Toate partials trebuie să gestioneze cazuri null/empty:

| Câmp | Null/empty fallback |
|---|---|
| `case.court` | „Instanță needeterminată — verifică manual" (C5 din Pas 2.3) |
| `case.calculatedInterest` | `0,00` afișat normal |
| `case.stampDuty` | `0,00` afișat normal |
| `case.debtors[]` empty | fallback hero text `case_overview.header.no_debtor` (rar — Pas 3.2 forțează min 1 debtor) |
| `case.documents[]` empty | empty state mockup pentru tab Documente source list |
| Active deadline absent | placeholder „Niciun termen activ" în KPI + sidebar Detalii |
| `legalDeadlines[]` empty | empty state mockup tab Termene |
| `portalEvents[]` empty | empty state mockup tab Activitate Portal („Niciun eveniment monitorizat încă") |
| `auditLogs[]` empty | empty state mockup tab Audit (footer „0 din 0 acțiuni") |
| `debtor.anafStatus` null | nu afișează badge ONRC (skip) |

### 2.7 Recompute-on-render

Pentru tab Detalii (claim composition + accordion BNR), `InterestCalculatorService::calculate()` se reapelează server-side la fiecare render pentru a obține `breakdown[]` (array cu perioade BNR + zile + dobândă per perioadă). NU se persistă rezultatul — `LegalCase.calculatedInterest` deja stochează total-ul, breakdown-ul e doar pentru afișaj.

**Atenție**: dacă `case.dueDate` e null (rar — Pas 3.2 cere obligatoriu), serviciul aruncă excepție → catch în controller + renderează „— calculul indisponibil" cu warning amber.

---

## 3. Map date-binding (per secțiune mockup)

| Secțiune mockup | Sursa de date | Fallback / empty state | Sub-pas owner |
|---|---|---|---|
| Hero — titlu „Creditor vs Debtor" | `Creditor.companyName` + `Debtor[0].businessName` | „— fără debitor —" (rar) | 4.0.2 |
| Hero — badge LR-XXXX | `LegalCase.referenceNumber` (există din Pas 3.2) | n/a (obligatoriu) | 4.0.2 |
| Hero — chip „Ordonanță de plată" | hardcodat în i18n (single product MVP) | n/a | 4.0.2 |
| Hero — status pill cu pulse | `CaseStatus` enum + i18n label + culoare mapping | n/a | 4.0.2 |
| Hero — Creat 12.04.2026 | `LegalCase.createdAt` | n/a | 4.0.2 |
| Hero — Jud. Sector 3 București | `Court.name` (denormalized "Judecătoria + locality") | „Instanță needeterminată" | 4.0.2 |
| Hero — CTA „Generează cerere OP" | aria-disabled (Faza 6) | n/a | 4.0.2 |
| Hero — Dropdown 3-puncte (Edit/Share/Duplicate/Close) | aria-disabled toate (4.x / post-MVP) | n/a | 4.0.2 |
| KPI — Sumă principală | `LegalCase.amount` + currency | `0,00 RON` | 4.0.2 |
| KPI — scadență | `LegalCase.dueDate` | „—" | 4.0.2 |
| KPI — Dobândă | `LegalCase.calculatedInterest` | `0,00` | 4.0.2 |
| KPI — Dobândă hint „182 zile · BNR + 8 pp" | days = diff(now, dueDate) + label hardcodat „BNR + 8 pp" pentru `RelationshipType.COMERCIAL` | n/a | 4.0.2 |
| KPI — Total creanță | `amount + calculatedInterest` | n/a | 4.0.2 |
| KPI — Total hint „+ taxă timbru 200 RON" | `stampDuty` | n/a | 4.0.2 |
| KPI — Termen activ | `LegalDeadline` cu min dueDate viitor | „Niciun termen activ" | 4.0.2 |
| Pipeline — 5 stages | `CaseStatus` enum → indice stadiu (matrice în macro) | n/a | 4.0.2 |
| Pipeline — progress bar % | computat din indice stadiu / 5 | n/a | 4.0.2 |
| Pipeline — date sub Amiabil | `CaseStatusHistory[AMIABIL].createdAt` sau `case.createdAt` fallback | „—" | 4.0.2 |
| Pipeline — „în mers · 22.04" sub Somație | `CaseStatusHistory[SOMATIE_TRIMISA].createdAt` | „în mers" fără dată dacă lipsește history | 4.0.2 |
| Tabs nav — 5 buttons cu count badges | static labels + counts: documents=`case.documents|length`, deadlines=`legalDeadlines|length`, audit=`auditLogs|length`; portal & detalii fără count | n/a | 4.0.2 |
| Detalii — Card Părți (Creditor) | `Creditor`: companyName, cui, registeredOffice, iban | „—" per câmp null | 4.0.3 |
| Detalii — Card Părți (Debitor) + badge ANAF | `Debtor[0]`: businessName, cui, registeredOffice, administratorName, anafStatus | skip badge dacă null | 4.0.3 |
| Detalii — Claim composition bar | `principalPct = amount / (amount+interest)`, `interestPct = 1 - principalPct` | dacă 0+0 → bar gol | 4.0.3 |
| Detalii — 4-stat dl grid | `amount`, `calculatedInterest`, `stampDuty`, `total = amount+interest+stampDuty` | toate `0,00` defensiv | 4.0.3 |
| Detalii — Accordion BNR breakdown | `InterestCalculatorService::calculate()->breakdown` recomputed la render | „— calculul indisponibil" + warning amber dacă exception | 4.0.3 |
| Detalii — Card Instanță | `Court.name`, `Court.county`, hint format „Sumă < 200.000 RON · sediu debitor %locality% · CPC art. 1015" | „Instanță needeterminată — verifică manual" | 4.0.3 |
| Detalii — Card Instanță Nr. dosar | `LegalCase.courtCaseNumber` (există din Pas 1.1 entity extension) | „— încă nedepus" italic | 4.0.3 |
| Detalii — Sidebar Termen activ ring (countdown) | next `LegalDeadline` cu min dueDate viitor; daysRemaining = diff(now, dueDate) | placeholder „Niciun termen activ" | 4.0.3 |
| Detalii — Sidebar Acțiuni recomandate (4 buttons) | enum-mapped pe `CaseStatus` curent; toate cu `aria-disabled` (vor fi enable-ate ulterior) | n/a | 4.0.3 |
| Documente — Generated row Somație | check dacă `Document` cu `documentType=SOMATIE` + `generatedByApp=true` (câmpul `generatedByApp` NU există încă — vezi nota la 4.0.4) | dacă lipsă: HTML stub „— încă negenerată" | 4.0.4 |
| Documente — Generated row Cerere OP | întotdeauna stub „PREGĂTITĂ" cu CTA aria-disabled | n/a | 4.0.4 |
| Documente — Generated row Opis | întotdeauna stub „VA FI GENERAT" disabled | n/a | 4.0.4 |
| Documente — Source list | `case.documents` filtrate pe `documentType IS NOT NULL` (filtrat în macro Twig) | empty state cu dropzone aria-disabled | 4.0.4 |
| Documente — Badge type per row | `Document.documentType` enum label | „— necunoscut" | 4.0.4 |
| Documente — Hint „extracție 95%" | `Document.extractionGlobalConfidence` × 100, rounded | omis dacă null | 4.0.4 |
| Documente — Sidebar ZIP card | dark navy gradient, CTA aria-disabled | n/a | 4.0.4 |
| Documente — Sidebar warning „lipsește dovadă comunicare" | condițional: NU există `Document` cu `documentType=COMMUNICATION_PROOF` | nu se afișează dacă există proof | 4.0.4 |
| Termene — Cards active/expirate/completate | `LegalDeadlineRepository::findByCase(case)` (creat la 4.0.1) | empty state mockup | 4.0.5 |
| Termene — Priority badge | `LegalDeadline.priority` enum (HIGH/MEDIUM/LOW) | LOW default | 4.0.5 |
| Termene — Title + body | `LegalDeadline.deadlineType` enum → i18n title + body templates | „—" | 4.0.5 |
| Termene — Checkbox completion | `LegalDeadline.completedAt IS NOT NULL` | unchecked + aria-disabled (4.3 wire) | 4.0.5 |
| Termene — Sidebar Calendar 30 zile | listă derivată din deadlines cu dueDate în (now, now+30d) | „— niciun alt termen până la %date% —" cu prox prescription | 4.0.5 |
| Termene — Sidebar Alerts info | static i18n text | n/a | 4.0.5 |
| Portal — Config nr. dosar input + activate buton | aria-disabled + value=`case.courtCaseNumber` | placeholder „ex: 4521/302/2026" | 4.0.6 |
| Portal — Status badge NEACTIVATĂ/ACTIVĂ | `case.portalMonitoringActive` (câmp Pas 1.1 entity) | NEACTIVATĂ default | 4.0.6 |
| Portal — Timeline portal events | `CourtPortalEventRepository::findByCase(case)` (creat la 4.0.1 dacă nu există) | empty state mockup | 4.0.6 |
| Portal — Sample timeline (opacity-60) | static HTML cu 3 evenimente exemplu prin i18n | n/a (mereu vizibil ca previzualizare) | 4.0.6 |
| Portal — Sidebar Cum funcționează (4 steps) | static i18n list | n/a | 4.0.6 |
| Portal — Sidebar Status sincronizare | `case.portalLastCheckedAt`, `case.portalEventsCount` | „— încă nepornită" / `0` | 4.0.6 |
| Audit — Header filter dropdown | static (read-only — querystring filter post-MVP) | n/a | 4.0.7 |
| Audit — Search input | static (no submit) | n/a | 4.0.7 |
| Audit — Export CSV buton | aria-disabled | n/a | 4.0.7 |
| Audit — Tabel rows | `AuditLogRepository::findByCase(case, limit=50)` (creat la 4.0.1) | empty state cu „0 din 0 acțiuni" | 4.0.7 |
| Audit — Pill culoare per action | `AuditLog.category` enum → mapping culoare (TRANSITION=amber, DOCUMENT_GENERATE=blue, DOCUMENT_UPLOAD=green, AI_EXTRACTION=indigo, CASE_CREATE=green, WIZARD_SUBMIT=green, etc.) | slate default | 4.0.7 |
| Audit — Actor inițiale + nume | `AuditLog.userId` → User.email; inițiale din email prefix | „Sistem · %service%" pentru system actor (userId NULL) | 4.0.7 |
| Audit — Detalii column | `AuditLog.description` (sau `newData` truncated) | „—" | 4.0.7 |
| Audit — IP column | `AuditLog.ipAddress` | „localhost" italic dacă null | 4.0.7 |
| Audit — Footer count | `auditLogs|length` din `totalCount` repo | „0 din 0" | 4.0.7 |
| Modal Close case — select Motiv | 4 options enum sau static (post-MVP define exact list) | placeholder „— Selectează motiv —" | 4.0.7 |
| Modal Close case — Confirm button | aria-disabled (4.x workflow) | n/a | 4.0.7 |
| Modal Generate OP — listă 3 items | static i18n | n/a | 4.0.7 |
| Modal Generate OP — disclaimer court | dinamic cu `case.court.name` | „instanța competentă" fallback | 4.0.7 |
| Modal Generate OP — Confirm button | aria-disabled (Faza 6) | n/a | 4.0.7 |

---

## 4. Sub-pași dezvoltare

### 4.0.1 — Foundation: route + controller + repo methods + base shell

**Effort**: 0.5 zi
**Pre-condiții**: Pas 3.2 DONE.

**Scope**:
- Rută `case_overview` GET cu Voter check + 404/403 robust.
- 3 repository methods noi.
- Shell template minim + icons macro.
- i18n base keys (title, tab labels, tooltip coming_soon_*).

**Fișiere noi**:
- `src/Controller/Case/CaseOverviewController.php`:
  ```php
  #[Route('/dosar/{id}', name: 'case_overview', requirements: ['id' => '\d+'], methods: ['GET'])]
  public function __invoke(
      int $id,
      LegalCaseRepository $caseRepo,
      LegalDeadlineRepository $deadlineRepo,
      AuditLogRepository $auditRepo,
      CourtPortalEventRepository $portalRepo,
      InterestCalculatorService $interestSvc,
  ): Response
  ```
- `templates/case/overview.html.twig`:
  ```twig
  {% extends 'base.html.twig' %}
  {% block title %}{{ 'case_overview.title'|trans({'%reference%': case.referenceNumber}) }}{% endblock %}
  {% block breadcrumb_block %}
    {# ... Dashboard > Dosare > LR-XXXX ... #}
  {% endblock %}
  {% block body %}
    {# includere partials — toate stub-uri în 4.0.1, populate ulterior #}
  {% endblock %}
  ```
- `templates/case/overview/_icons.html.twig`: toate cele ~20 macro-uri stroke 1.8-2.5 `currentColor`.

**Fișiere modificate**:
- `src/Repository/LegalDeadlineRepository.php` — adaugă:
  ```php
  public function findByCase(LegalCase $case): array {
      return $this->createQueryBuilder('d')
          ->andWhere('d.legalCase = :case')
          ->setParameter('case', $case)
          ->orderBy('d.dueDate', 'ASC')
          ->getQuery()->getResult();
  }
  ```
- `src/Repository/AuditLogRepository.php` — adaugă:
  ```php
  public function findByCase(LegalCase $case, int $limit = 50): array {
      return $this->createQueryBuilder('a')
          ->andWhere('a.entityClass = :class AND a.entityId = :id')
          ->setParameter('class', LegalCase::class)
          ->setParameter('id', $case->getId())
          ->orderBy('a.createdAt', 'DESC')
          ->setMaxResults($limit)
          ->getQuery()->getResult();
  }
  ```
- `src/Repository/CourtPortalEventRepository.php` — creează repo dacă nu există + metodă `findByCase(LegalCase $case): array` ordered DESC `eventDate`.
- `translations/messages.ro.yaml` + `messages.en.yaml` — i18n base keys (vezi secțiunea 5).

**Teste**:
- `tests/Controller/Case/CaseOverviewControllerTest.php` (WebTestCase):
  - `test_get_overview_for_owned_case_returns_200`
  - `test_get_overview_for_other_user_case_returns_403`
  - `test_get_overview_for_nonexistent_id_returns_404`
- `tests/Repository/LegalDeadlineRepositoryTest.php`:
  - `test_find_by_case_returns_only_for_case_ordered_asc`
- `tests/Repository/AuditLogRepositoryTest.php`:
  - `test_find_by_case_returns_only_entries_for_case_ordered_desc_with_limit`

**Criterii DONE**:
- Ruta `/dosar/{id}` returnează 200 cu shell minim afișat + breadcrumb corect.
- Voter blochează cross-user access (403).
- 404 pe ID inexistent.
- Toate 3 repo methods cu test verde.
- `templates/case/overview/_icons.html.twig` import-uit cu success într-un partial test minim.
- `bin/phpunit tests/Controller/Case/CaseOverviewControllerTest.php` verde.

**Commit**: `feat(case): Pas 4.0.1 — case_overview route + repository methods + shell foundation`

---

### 4.0.2 — Hero + KPI grid + Pipeline + Tabs nav

**Effort**: 0.5 zi
**Pre-condiții**: 4.0.1 DONE.

**Scope**:
- Top-of-page secțiuni: hero (titlu + status + CTA-uri), KPI grid (4 celule), Pipeline 5-step, Tabs nav cu 5 buttons.
- Reutilizare `StatusBadge` (Pas 2.7) pentru hero status pill cu `pulse: true` + `show_dot: true`.
- Reutilizare `PipelineStatus` (Pas 2.7) — verifică etichetele celor 5 stadii (Amiabil/Somație/Cerere/Monitorizare/Definitivă). Dacă componentul Pas 2.7 are alte mapping-uri (Amiabil/Somație/Cerere/Decizie/Executare), adaptează prop `variant: 'overview'` în component sau folosește partial dedicat pentru această variantă mockup.

**Fișiere noi**:
- `templates/case/overview/_hero.html.twig`:
  - Outer `<section>` cu `bg-white dark:bg-slate-900 rounded-2xl shadow-hero overflow-hidden mb-6 relative` + decorative blur gradients.
  - Row 1: titlu h1 cu „Creditor vs Debtor", chip-uri metadate, badge LR + tip OP.
  - Row 2: CTA „Generează cerere OP" (aria-disabled cu tooltip coming_soon_op) + dropdown 3-puncte (Preline `hs-dropdown`) cu 4 actions toate aria-disabled (Edit/Share/Duplicate) + 1 destructive (Close → trigger `#hs-modal-close-case`).
  - SVG inline → macros din `_icons.html.twig`.
- `templates/case/overview/_kpi_grid.html.twig`:
  - `grid grid-cols-2 lg:grid-cols-4 gap-px bg-slate-100 dark:bg-slate-800` (gap-px creează separatorul thin).
  - 4 celule:
    - Sumă principală: `format_currency(amount, currency)`, hint scadență.
    - Dobândă: `+ {{ interest|format_number }}`, amber, hint zile + BNR+8pp.
    - Total creanță: accent top bar blue, total = amount+interest.
    - Termen activ: accent top bar amber-red gradient, pulse dot, days remaining derivat din next deadline.
- `templates/case/overview/_pipeline.html.twig`:
  - Header cu titlu „Procedură ordonanță de plată" + progress bar 0-100% mini.
  - `<ul class="relative grid grid-cols-5 gap-2 md:gap-4">` cu 5 `<li>`.
  - Macro `stage_state(case_status, stage_index)` returnează 'done'/'active'/'future' bazat pe matricea:
    ```
    AMIABIL → stage 0 active
    SOMATIE_TRIMISA → stage 1 active, stage 0 done
    CERERE_DEPUSA → stage 2 active, stages 0-1 done
    MONITORIZARE / DOSAR_INREGISTRAT / TERMEN_FIXAT → stage 3 active
    ORDONANTA_EMISA / DEFINITIVA / EXECUTARE → stage 4 active
    ```
  - Per stage: cerc colorat după state + label + date (din CaseStatusHistory dacă state=done, „în mers · %date%" dacă active, „—" dacă future).
  - Liniile între stages: gradient verde→amber pentru segmente done→active, dashed gri pentru segmente future.
- `templates/case/overview/_tabs_nav.html.twig`:
  - Wrapper `<div class="bg-white dark:bg-slate-900 rounded-2xl shadow-soft border ... mb-6 overflow-hidden">`.
  - `<nav class="flex gap-1 px-2 border-b ..." role="tablist">` cu 5 `<button>`:
    - Detalii (no count)
    - Documente — count = `case.documents|length`
    - Termene — count badge amber dacă count>0
    - Activitate Portal (no count)
    - Audit — count = `auditLogs|length`
  - Preline `data-hs-tab="#panel-detalii"` etc.
  - Active styling prin `hs-tab-active:` variants (deja în config Tailwind via Pas 0.2).

**Fișiere modificate**:
- `templates/case/overview.html.twig`: include `_hero.html.twig`, `_kpi_grid.html.twig`, `_pipeline.html.twig`, `_tabs_nav.html.twig` + 5 `<div id="panel-X" role="tabpanel">` stubs.
- i18n: chei sub `case_overview.header.*`, `kpi.*`, `pipeline.*`, `tab.*` (secțiunea 5).

**Teste** (WebTestCase):
- `test_hero_renders_creditor_vs_debtor_title`
- `test_hero_status_pill_matches_case_status_enum`
- `test_kpi_principal_renders_with_currency`
- `test_kpi_total_equals_amount_plus_interest`
- `test_kpi_active_deadline_placeholder_when_no_deadlines`
- `test_pipeline_active_stage_matches_case_status` (parametrized cu 3 status diferite)
- `test_pipeline_done_stages_have_history_dates`
- `test_tabs_nav_renders_5_buttons_with_correct_counts`

**Criterii DONE**:
- Hero arată identic cu mockup (titlu cu „vs" gri între părți, badge LR mono, status pill cu pulse dot).
- KPI cu 4 celule și separator thin grid-px funcțional.
- Pipeline cu state-uri done/active/future colorate corect pe cele 9 status-uri din `CaseStatus` enum.
- Tabs nav 5 buttons Preline-functional (click → comută `hidden` clasele pe panels).
- Toate 8 teste verzi.
- Dark mode toggle din topbar → toate secțiunile rămân coerente.

**Commit**: `feat(case): Pas 4.0.2 — hero + KPI grid + pipeline + tabs nav`

---

### 4.0.3 — Tab Detalii (Parties + Claim composition + Court + Sidebar)

**Effort**: 0.5 zi
**Pre-condiții**: 4.0.2 DONE.

**Scope**:
- Conținutul tab Detalii (panel-detalii): card Părți (Creditor + Debtor side-by-side), card Creanță (composition bar + 4-stat dl + accordion BNR), card Instanță, sidebar (Termen activ ring + Acțiuni recomandate).
- Layout: `grid grid-cols-1 xl:grid-cols-3 gap-6` cu main `xl:col-span-2` + sidebar `xl:sticky xl:top-20`.

**Fișiere noi**:
- `templates/case/overview/_tab_detalii.html.twig` — orchestrator.
- `templates/case/overview/_party_card.html.twig`:
  - Prop `role: 'creditor'|'debtor'`, `party: Creditor|Debtor`.
  - Accent bar stânga: blue pentru creditor, orange pentru debtor.
  - dl cu CUI / Sediu / IBAN (creditor) sau CUI / Sediu / Admin. (debtor).
  - Pentru debtor: badge ANAF conditional pe `party.anafStatus`:
    - `ACTIV` → green „ONRC ACTIV" + dot
    - `INACTIV` → amber „ONRC INACTIV"
    - `RADIAT` → red „ONRC RADIAT"
- `templates/case/overview/_claim_composition.html.twig`:
  - Header cu titlu + indicator „Tip raport: Comercial" (din enum `RelationshipType.COMERCIAL`).
  - Composition bar 2-segment cu `style="width: X%"` pentru principal/dobândă.
  - 4-stat dl grid (Sumă / Dobândă / Taxă / Total).
  - Preline accordion BNR breakdown — table cu rânduri din `interestService->calculate(...)->breakdown`. Header coloane: Perioadă / Rata BNR / Aplicabilă / Zile / Dobândă. Footer cu total.
- `templates/case/overview/_court_summary.html.twig`:
  - Card flex cu icon building + nume instanță + rule hint + nr. dosar (sau placeholder „— încă nedepus").
  - Null-safe pe `case.court` → afișează fallback complet.
- `templates/case/overview/_active_deadline_ring.html.twig`:
  - Card amber gradient cu SVG circle progress.
  - SVG: 2 cercuri (background + progress) cu `stroke-dasharray="226"` + `stroke-dashoffset` calculat din procent zile rămase.
  - `<linearGradient id="deadlineGradient">` amber→red.
  - Class `ring-fill` (verifică în `app.css` — dacă lipsește keyframe, adaugă).
  - Center text: număr zile + label „zile".
  - Side info: Expiră, Următoarea alertă, Apoi tranziție automată.
- `templates/case/overview/_recommended_actions.html.twig`:
  - Header dark navy gradient cu titlu „Acțiuni recomandate" + subtitle.
  - 4 buttons enum-mapped pe `CaseStatus` curent:
    - „Generează somație" — done dacă status ≥ SOMATIE_TRIMISA (line-through + opacity-50 + label „deja generată %date%")
    - „Generează cerere OP" — recommended badge + aria-disabled cu tooltip
    - „Marchează plată amiabilă" — aria-disabled
    - „Adaugă document" — aria-disabled

**Modificări**:
- `assets/styles/app.css` — verifică prezența keyframe `ring-fill`:
  ```css
  @keyframes ring-fill { from { stroke-dashoffset: 226; } to { stroke-dashoffset: var(--ring-target, 54); } }
  .ring-fill { animation: ring-fill 1.2s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; }
  ```
  Adaugă dacă lipsește.
- Controller: pass `interestBreakdown` array la template (recheamă `interestSvc->calculate(...)` cu try/catch — pe excepție setează `interestBreakdown = null` + flag `breakdownError = true`).

**Teste** (WebTestCase):
- `test_party_card_creditor_renders_all_fields`
- `test_party_card_debtor_anaf_radiat_renders_red_badge`
- `test_party_card_debtor_anaf_null_skips_badge`
- `test_claim_composition_bar_widths_match_amounts`
- `test_claim_breakdown_accordion_rows_match_periods`
- `test_claim_breakdown_error_renders_warning_when_due_date_null`
- `test_court_summary_null_court_renders_undetermined_label`
- `test_court_summary_court_case_number_pending_when_null`
- `test_active_deadline_ring_renders_days_remaining`
- `test_active_deadline_ring_placeholder_when_no_deadlines`
- `test_recommended_actions_generate_summons_struck_when_status_advanced`

**Criterii DONE**:
- Tab Detalii vizibil 1:1 cu mockup-ul (verificare overlay browser).
- BNR breakdown accordion expanded → tabel cu N rânduri unde N = perioade din breakdown.
- Composition bar afișează procente corecte (rounding cu 1 zecimală).
- Null safety verificată pentru court, deadline, anafStatus.
- Toate 11 teste verzi.

**Commit**: `feat(case): Pas 4.0.3 — tab detalii (parties + claim + court + sidebar)`

---

### 4.0.4 — Tab Documente (Generated + Source + ZIP + Warning)

**Effort**: 0.5 zi
**Pre-condiții**: 4.0.3 DONE.

**Scope**:
- Conținutul tab Documente: listă generated docs (3 row-uri: Somație, Cerere OP, Opis), listă source uploads (din `case.documents`), sidebar (ZIP package CTA + warning comunicare).

**Notă tehnică**:
- Câmpul `Document.generatedByApp` (boolean) NU există încă în entitate. La acest sub-pas, **NU adăugăm migration nouă** — în schimb:
  - Renderăm cele 3 generated rows ca **stub static** cu i18n labels.
  - Source list = ALL `case.documents` (toate, fără filtru).
  - La Faza 6 când livrăm generare PDF, se adaugă `generatedByApp` + migration și actualizăm aici filtrul.

**Fișiere noi**:
- `templates/case/overview/_tab_documente.html.twig` — orchestrator `xl:col-span-2` + `<aside class="space-y-6">`.
- `templates/case/overview/_document_row.html.twig`:
  - Prop `document: Document` SAU `stub_label`, `stub_status`, `stub_hint`.
  - Layout flex cu icon PDF (colored bg din enum documentType) + nume + meta + badge type + action buttons (preview/download/delete) — toate buttons aria-disabled (rute viitoare).
- `templates/case/overview/_documents_generated_list.html.twig`:
  - Card cu header „Documente generate" + ratio `2 din 3 generate` (hardcoded pentru MVP — 0/3 până la Faza 6).
  - 3 stub rows:
    - Somație: stub „GENERATĂ" sau „— încă negenerată"
    - Cerere OP: stub „PREGĂTITĂ" + CTA „Generează acum" aria-disabled
    - Opis: stub „VA FI GENERAT" + hint static
- `templates/case/overview/_documents_source_list.html.twig`:
  - Card cu header „Documente sursă (uploadate)" + buton „+ Adaugă document" aria-disabled.
  - Loop `case.documents` (filtrate pe `documentType IS NOT NULL` în Twig macro).
  - Per row: icon PDF roșu + filename + meta (tip + size + date + extracție%) + badge type + actions.
  - Footer: dropzone-stil text „Trage fișiere noi sau apasă aici" aria-disabled.
- `templates/case/overview/_zip_package_card.html.twig`:
  - Dark navy gradient (`bg-gradient-to-br from-lex-navy to-lex-navy-dark`).
  - Listă cu 4 items + green check.
  - CTA „Generează & descarcă ZIP" white bg lex-navy text, aria-disabled cu tooltip coming_soon_op.
- `templates/case/overview/_communication_warning.html.twig`:
  - Render conditional: `{% if not case.documents|filter(d => d.documentType == constant('App\\Enum\\DocumentType::COMMUNICATION_PROOF'))|length %}`.
  - Card amber cu warning icon + text + CTA „+ Adaugă dovadă comunicare" aria-disabled.

**Teste** (WebTestCase):
- `test_generated_docs_renders_3_rows_always`
- `test_source_list_count_matches_case_documents`
- `test_source_list_extraction_pct_rendered_when_present`
- `test_source_list_empty_state_when_no_documents`
- `test_communication_warning_shown_when_no_communication_proof`
- `test_communication_warning_hidden_when_communication_proof_exists`
- `test_zip_package_cta_aria_disabled`

**Criterii DONE**:
- Tab Documente vizibil 1:1 cu mockup.
- Source list goală → empty state cu dropzone afișat.
- Warning condițional verificat (test cu 2 fixtures: cu/fără proof).
- Toate 7 teste verzi.

**Commit**: `feat(case): Pas 4.0.4 — tab documente (generated + source + ZIP + warning)`

---

### 4.0.5 — Tab Termene (Cards + Calendar + Alerts info)

**Effort**: 0.5 zi
**Pre-condiții**: 4.0.4 DONE.

**Scope**:
- Conținutul tab Termene: header cu counter (active/expirate/completate) + buton add custom (aria-disabled), 3 card-uri exemplu (HIGH amber, MEDIUM yellow, COMPLETAT green opacity-60), sidebar (Calendar 30 zile + Alerts info card).

**Notă tehnică**:
- `LegalDeadline` entity există din Pas 1.1, dar `DeadlineService` (care creează termene automat la transitions) e livrat la Pas 4.1. Până atunci, dosarele NU vor avea termene reale → empty state e default.
- Pentru a verifica vizual cardurile, putem crea fixtures de test cu 3 deadlines manuale.

**Fișiere noi**:
- `templates/case/overview/_tab_termene.html.twig` — orchestrator `lg:col-span-2` + sidebar.
- `templates/case/overview/_deadline_card.html.twig`:
  - Prop `deadline: LegalDeadline`.
  - Variant style derivată din `deadline.priority` enum:
    - `HIGH` → amber-orange gradient card cu blur decorativ
    - `MEDIUM` → white card cu yellow icon badge
    - `LOW` / completat → white card cu green icon + opacity-60 + line-through pe title
  - Conținut: priority badge + type badge + due date relative + body description (i18n template per `deadlineType`).
  - Checkbox toggle completion — aria-disabled (4.3 wire).
- `templates/case/overview/_deadline_calendar.html.twig`:
  - Card sidebar cu titlu „Calendar termene · următoarele 30 zile".
  - Loop deadlines cu `dueDate` în (now, now+30d), max 5 entries.
  - Per entry: data scurtată (MAI/22) + titlu + days remaining + priority class.
  - Fallback empty: „— niciun alt termen până la %date% —" (cu data prescription dacă există).
- `templates/case/overview/_alerts_info.html.twig`:
  - Card blue static — titlu + body.

**Reutilizare**:
- Verifică dacă `DeadlineList` (component din Pas 2.7) fit-uie cardurile. Dacă da → folosește pentru lista principal. Altfel → partials noi (decizie în execuție).

**Teste** (WebTestCase):
- `test_termene_counters_render_correctly_with_3_deadlines`
- `test_termene_empty_state_when_no_deadlines`
- `test_termene_card_high_renders_amber_gradient`
- `test_termene_card_completed_renders_line_through`
- `test_calendar_shows_deadlines_within_30_days`
- `test_calendar_empty_fallback_when_no_upcoming`

**Criterii DONE**:
- Tab Termene vizibil 1:1 cu mockup pentru fiecare priority.
- Calendar sidebar afișează corect entries în 30 zile.
- Toate 6 teste verzi.

**Commit**: `feat(case): Pas 4.0.5 — tab termene (cards + calendar + alerts info)`

---

### 4.0.6 — Tab Activitate Portal (Config + Timeline + How + Status)

**Effort**: 0.5 zi
**Pre-condiții**: 4.0.5 DONE.

**Scope**:
- Conținutul tab Activitate Portal: config nr. dosar + activate buton, timeline portal events sau empty state, sample timeline (preview opacity-60), sidebar (Cum funcționează + Status sincronizare).

**Notă tehnică**:
- `CourtPortalEvent` entity există din Pas 1.1. Portal monitoring (cron + SOAP) intră la Faza 5. Până atunci 99% din dosare au `portalEvents` empty → empty state default.
- Câmpul `LegalCase.portalMonitoringActive` (bool) e setat la Faza 5; aici e mereu `false` → badge NEACTIVATĂ default.

**Fișiere noi**:
- `templates/case/overview/_tab_portal.html.twig` — orchestrator.
- `templates/case/overview/_portal_config.html.twig`:
  - Header cu „CONFIGURARE MONITORIZARE" label + titlu + descriere.
  - Status badge NEACTIVATĂ/ACTIVĂ.
  - Input nr. dosar cu placeholder + suffix „format Ecris" + buton activate — input readonly + buton aria-disabled.
  - Disclaimer text.
- `templates/case/overview/_portal_timeline.html.twig`:
  - Header cu titlu + buton „Verifică acum" aria-disabled.
  - Loop `portalEvents` dacă există, altfel empty state cu icon + titlu + body.
- `templates/case/overview/_portal_sample_timeline.html.twig`:
  - Card cu badge „previzualizare" italic.
  - Static HTML 3 entries (Ordonanță emisă, Termen judecată, Dosar înregistrat) cu opacity-60 — toate texte prin i18n.
  - Timeline vertical cu `<ol class="relative space-y-5">` + dot circles colorate.
- `templates/case/overview/_portal_how_it_works.html.twig`:
  - Card sidebar purple-indigo gradient cu icon lightning + 4 steps numerotate (i18n).
  - Footer cu source attribution „portalquery.just.ro/query.asmx".
- `templates/case/overview/_portal_sync_status.html.twig`:
  - Card sidebar cu dl: Ultima verificare / Următoarea / Evenimente totale.
  - Toate cu fallback „— încă nepornită" pentru dosare cu `portalLastCheckedAt` null.

**Teste** (WebTestCase):
- `test_portal_config_renders_neactivata_badge_default`
- `test_portal_config_inputs_are_disabled`
- `test_portal_timeline_empty_state_when_no_events`
- `test_portal_sample_timeline_renders_3_preview_entries`
- `test_portal_how_it_works_renders_4_steps`
- `test_portal_sync_status_fallback_when_never_checked`

**Criterii DONE**:
- Tab Activitate Portal vizibil 1:1 cu mockup.
- Toate 6 teste verzi.

**Commit**: `feat(case): Pas 4.0.6 — tab activitate portal (config + timeline + how + status)`

---

### 4.0.7 — Tab Audit + Modal-uri + polish final + tests E2E

**Effort**: 0.5 zi
**Pre-condiții**: 4.0.6 DONE.

**Scope**:
- Conținutul tab Audit: header (filter dropdown + search input + export CSV button) + tabel paginate + footer cu count și retention notice.
- 2 modal-uri Preline (`hs-overlay`): Close case + Generate OP ZIP.
- Wire CTA-uri din hero (`_hero.html.twig` 4.0.2) — `data-hs-overlay="#hs-modal-close-case"` și `#hs-modal-generate-op`.
- Polish final: verificare 1:1 vizuală cu mockup, dark mode toggle robust, `format_currency`/`format_datetime` cu locale corect.
- Code review final.

**Fișiere noi**:
- `templates/case/overview/_tab_audit.html.twig` — orchestrator (full-width, NU split).
- `templates/case/overview/_audit_table.html.twig`:
  - Header: filter dropdown Preline cu opțiuni enum din `AuditLogCategory` — read-only (links cu `#` aria-disabled), search input (no submit), export CSV button aria-disabled.
  - Tabel cu coloane: Timestamp, Actor, Acțiune, Detalii, IP, Actions.
  - Loop `auditLogs`:
    - Timestamp: `format_datetime(pattern='dd.MM.yyyy HH:mm:ss')`.
    - Actor: dacă `userId` → inițiale din email + nume scurt; altfel „Sistem · %service%".
    - Acțiune: pill enum-colored (TRANSITION=amber, DOCUMENT_GENERATE=blue, DOCUMENT_UPLOAD=green, AI_EXTRACTION=indigo, CASE_CREATE=green, WIZARD_SUBMIT=green, default=slate).
    - Detalii: `description` truncated 80 char.
    - IP: `ipAddress` sau „localhost" italic.
    - Actions: buton „Detalii"/„Diff"/„Payload" (link aria-disabled — payload view e post-MVP).
  - Footer: „Afișezi X din N acțiuni" + retention notice „Înregistrările sunt imutabile · stocate 5 ani".
- `templates/case/overview/_modal_close_case.html.twig`:
  - Preline `hs-overlay` cu z-[80], rounded-2xl.
  - Header cu icon roșu warning + titlu i18n.
  - Body: paragraf cu reference + select Motiv (4 options i18n).
  - Footer: button Renunță (close modal) + button Închide dosar aria-disabled cu tooltip coming_soon_close.
- `templates/case/overview/_modal_generate_op.html.twig`:
  - Preline `hs-overlay` similar.
  - Header cu icon blue + titlu.
  - Body: paragraf + listă 3 items cu icons + warning amber cu court name dinamic.
  - Footer: button Renunță + button „Generează & descarcă ZIP" aria-disabled cu tooltip coming_soon_op.

**Polish & verification final**:
- Rulează `make tailwind` să recompileze CSS.
- `php -S 127.0.0.1:8888 -t docs/LexRecovery/mockups` în paralel cu `make up`.
- Browser: deschide 2 tab-uri: mockup + dosar real → manual visual diff.
- Verifică dark mode toggle din topbar pe toate cele 5 tab-uri.
- `bin/console debug:translation ro` și `en` → zero missing keys pentru `case_overview.*`.
- `bin/phpunit` suite completă verde (toate teste 4.0.1-4.0.7 + zero regresii vs baseline Pas 3.2).

**Code review**:
- `lexrecovery-code-reviewer` — calitate cod, security, Twig conventions, Doctrine queries optimizate, n+1 evitat în loop-uri (preload `case.documents`, `case.debtors` în controller cu fetch eager dacă necesar).
- `lexrecovery-legal-reviewer` — verifică:
  - CNP/IBAN NU sunt afișate unmasked nicăieri (PartyCard pentru debtor — CNP nu apare; IBAN doar pentru creditor pentru transparență cont).
  - Text „stocate 5 ani conform reglementări" e corect (verifică art. 33 GDPR + reglementări audit profesie avocat).
  - Empty state „Instanță needeterminată" e text legal-safe (NU implică că dosarul e invalid).

**Teste**:
- `test_audit_table_renders_recent_entries`
- `test_audit_table_actor_initials_correct`
- `test_audit_category_pill_color_matches_enum`
- `test_audit_empty_state_when_no_entries`
- `test_audit_filter_dropdown_renders_all_categories`
- `test_audit_export_csv_aria_disabled`
- `test_modal_close_case_present_in_dom`
- `test_modal_generate_op_present_in_dom`
- `test_modal_generate_op_disclaimer_renders_court_name`
- E2E smoke: `test_overview_full_page_renders_5_panels_and_2_modals` — asertie unică cu count `role="tabpanel"` = 5 + 2 modal containers + zero text raw non-i18n (assert prin `crawler->filter('[data-test-no-i18n]')->count() === 0` — adăugăm `data-test-no-i18n` la text-uri hardcoded permise: badge LR, sume, date, CUI; tot ce e narrative DOAR prin trans).

**Criterii DONE Pas 4.0 complet**:
- ✅ Ruta `case_overview` (`/dosar/{id}`) cu Voter check și 404/403 robust.
- ✅ Toate 5 tab-uri prezente în DOM cu structură identică mockup-ului.
- ✅ Date wired la entități existente (hero, KPI grid, pipeline, parties, claim, court, deadline ring, audit table).
- ✅ HTML-only sau aria-disabled pentru: tab Termene CTA-uri (4.3), tab Portal config (5.x), CTA Generate OP (6.x), modal Close case confirm (4.x).
- ✅ Zero hardcodare în text (toate string-urile prin `t()`).
- ✅ Zero SVG duplicat — toate prin macros `_icons.html.twig`.
- ✅ Zero dependențe JS noi — Preline existent acoperă tabs/accordion/dropdown/modal.
- ✅ Componente Pas 2.7 reutilizate unde aplicabil.
- ✅ Defensiv null verificat pe court/deadline/portalEvents/auditLog goale.
- ✅ 2 modale prezente în DOM (CTA confirm = aria-disabled).
- ✅ ~50 teste WebTestCase + 2 teste Repository verzi.
- ✅ `bin/phpunit` suite stabilă (zero regresii vs baseline Pas 3.2).
- ✅ Code review legal + code — verdict commit-ready.
- ✅ Visual diff manual cu mockup-ul = match 1:1.
- ✅ Dark mode robust pe toate 5 tab-uri.
- ✅ `debug:translation ro`/`en` zero missing keys.

**Commit**: `feat(case): Pas 4.0.7 — tab audit + modals + polish final + tests E2E`

---

## 5. Master i18n catalog

Toate cheile sub `case_overview.*` în `translations/messages.ro.yaml` și `translations/messages.en.yaml`.

### 5.1 Header & meta

```yaml
case_overview:
  title: 'Dosar %reference%'
  breadcrumb:
    cases: 'Dosare'
  header:
    type_op: 'Ordonanță de plată'
    created_on: 'Creat %date%'
    no_debtor: '— fără debitor —'
  tab:
    details: 'Detalii'
    documents: 'Documente'
    deadlines: 'Termene'
    portal: 'Activitate Portal'
    audit: 'Audit'
  tooltip:
    coming_soon_op: 'Disponibil la livrarea Generare PDF (Faza 6)'
    coming_soon_portal: 'Disponibil la livrarea Monitorizare Portal (Faza 5)'
    coming_soon_deadlines: 'Disponibil la livrarea Wiring Termene (Pas 4.3)'
    coming_soon_close: 'Disponibil la livrarea Workflow Închidere (Faza 4.x)'
    coming_soon_export: 'Export CSV — post-MVP'
    coming_soon_share: 'Share & colaboratori — post-MVP'
    coming_soon_edit: 'Editare detalii — post-MVP'
```

### 5.2 CTA hero

```yaml
case_overview:
  cta:
    generate_op: 'Generează cerere OP'
    actions_menu: 'Acțiuni'
    edit_details: 'Editează detalii'
    share: 'Partajează cu colaborator'
    duplicate: 'Duplică dosar'
    close_case: 'Închide dosar'
```

### 5.3 KPI

```yaml
case_overview:
  kpi:
    principal: 'Sumă principală'
    principal_due: 'scadență %date%'
    interest: 'Dobândă'
    interest_hint: '%days% zile · BNR + 8 pp'
    total: 'Total creanță'
    total_hint: '+ taxă timbru %stampDuty% %currency%'
    active_deadline: 'Termen activ'
    active_deadline_unit: 'zile'
    active_deadline_expires: 'expiră %date%'
    no_active_deadline: 'Niciun termen activ'
```

### 5.4 Pipeline

```yaml
case_overview:
  pipeline:
    section_title: 'Procedură ordonanță de plată'
    progress: 'Stadiu %current% din %total% — %label%'
    stage_1_amiabil: 'Amiabil'
    stage_2_somatie: 'Somație trimisă'
    stage_3_cerere: 'Cerere depusă'
    stage_4_monitorizare: 'Monitorizare'
    stage_5_definitiva: 'Definitivă'
    stage_pending_label: '—'
    stage_in_progress_label: 'în mers · %date%'
    title_executoriu: 'titlu executoriu'
```

### 5.5 Parties

```yaml
case_overview:
  parties:
    title: 'Părți'
    edit: 'Editează'
    creditor: 'CREDITOR'
    debtor: 'DEBITOR'
    field_cui: 'CUI'
    field_seat: 'Sediu'
    field_iban: 'IBAN'
    field_admin: 'Admin.'
    anaf_active: 'ONRC ACTIV'
    anaf_inactive: 'ONRC INACTIV'
    anaf_radiat: 'ONRC RADIAT'
    no_data: '—'
```

### 5.6 Claim

```yaml
case_overview:
  claim:
    title: 'Creanță'
    report_type: 'Tip raport'
    report_type_comercial: 'Comercial'
    report_type_civil: 'Civil'
    composition: 'Compoziție creanță'
    principal: 'Principal'
    interest_pct: 'Dobândă'
    stat_principal: 'Sumă principală'
    stat_principal_hint: 'scadență %date%'
    stat_interest: 'Dobândă'
    stat_interest_hint: '%days% zile · %rate%'
    stat_stamp: 'Taxă timbru'
    stat_stamp_hint: 'OUG 80/2013'
    stat_total: 'Cerere totală'
    stat_total_hint: 'de depus la instanță'
    breakdown_toggle: 'Vezi breakdown calcul dobândă pe perioade BNR'
    breakdown_summary: '%periods% perioade · OG 13/2011'
    breakdown_period: 'Perioadă'
    breakdown_bnr: 'Rata BNR'
    breakdown_applicable: 'Aplicabilă'
    breakdown_days: 'Zile'
    breakdown_interest: 'Dobândă'
    breakdown_total: 'Total dobândă acumulată'
    breakdown_error: '— calculul indisponibil (verifică data scadenței)'
```

### 5.7 Court

```yaml
case_overview:
  court:
    title: 'INSTANȚĂ COMPETENTĂ'
    rule_hint: 'Sumă < 200.000 RON · sediu debitor %locality% · CPC art. 1015'
    case_number: 'Nr. dosar'
    case_number_pending: '— încă nedepus'
    no_court: 'Instanță needeterminată — verifică manual'
```

### 5.8 Active deadline card

```yaml
case_overview:
  active_deadline_card:
    label: 'TERMEN ACTIV'
    hint: '%type% · %duration% zile'
    expires_on: 'Expiră'
    next_alert: 'Următoarea alertă'
    next_alert_value: '%days% zile'
    then: 'Apoi'
    auto_transition: 'tranziție automată'
```

### 5.9 Recommended actions

```yaml
case_overview:
  recommended_actions:
    title: 'Acțiuni recomandate'
    subtitle: 'Pe baza statusului curent'
    generate_summons_done: 'Generează somație'
    generate_summons_done_hint: 'deja generată %date%'
    generate_op: 'Generează cerere OP'
    generate_op_hint: 'Pachet ZIP cu cerere + opis + somație'
    recommended_badge: 'recomandat'
    mark_paid: 'Marchează plată amiabilă'
    mark_paid_hint: 'Achitat înainte de instanță'
    add_document: 'Adaugă document'
    add_document_hint: 'Dovadă comunicare, alte acte'
```

### 5.10 Documents

```yaml
case_overview:
  documents:
    generated_title: 'Documente generate'
    generated_ratio: '%generated% din %total% generate'
    somatie_label: 'Somație de plată'
    somatie_status_generated: 'GENERATĂ'
    somatie_status_pending: '— încă negenerată'
    somatie_meta: '%filename% · %pages% pagini · %size% · %date%'
    cerere_label: 'Cerere ordonanță de plată'
    cerere_status_pending: 'PREGĂTITĂ'
    cerere_pending_hint: 'Va fi generată la depunere cerere · pre-completată cu datele dosarului'
    cerere_cta: 'Generează acum'
    opis_label: 'Opis documente'
    opis_status_pending: 'VA FI GENERAT'
    opis_pending_hint: 'Generat automat la pas „Generează cerere OP"'
    source_title: 'Documente sursă (uploadate)'
    source_add: '+ Adaugă document'
    source_dropzone: 'Trage fișiere noi sau apasă aici'
    source_empty: 'Niciun document încărcat încă'
    type_contract: 'CONTRACT'
    type_invoice: 'FACTURĂ'
    type_communication: 'DOVADĂ COMUNICARE'
    type_other: 'ALTUL'
    type_unknown: '— necunoscut'
    extraction_hint: '%type% · %size% · %date% · extracție %pct%%'
    extraction_hint_no_pct: '%type% · %size% · %date%'
    action_preview: 'Previzualizare'
    action_download: 'Descarcă'
    action_delete: 'Șterge'
    action_regenerate: 'Regenerează'
```

### 5.11 ZIP package + warning

```yaml
case_overview:
  zip_package:
    title: 'Pachet ZIP — depunere instanță'
    intro: 'Descarcă tot ce ai nevoie pentru a depune cererea OP la %court%:'
    intro_no_court: 'Descarcă tot ce ai nevoie pentru a depune cererea OP:'
    item_cerere: 'Cerere OP (va fi generată)'
    item_opis: 'Opis documente (va fi generat)'
    item_somatie: 'Somație trimisă'
    item_evidence: 'Contract + factură'
    cta: 'Generează & descarcă ZIP'
  communication_warning:
    title: 'Lipsește dovada comunicării'
    body: 'Adaugă recipisa de la executor / confirmarea de primire a somației înainte de depunere.'
    cta: '+ Adaugă dovadă comunicare →'
```

### 5.12 Deadlines

```yaml
case_overview:
  deadlines:
    title: 'Termene dosar'
    counter: '%active% active · %expired% expirate · %completed% completat'
    add_custom: 'Adaugă termen custom'
    priority_high: 'HIGH'
    priority_medium: 'MEDIUM'
    priority_low: 'LOW'
    status_completed: 'COMPLETAT'
    expires_in_days: '%date% — în %days% zile'
    expires_done: '%date% — făcut'
    alerts_status: 'Alerte: %sent% trimisă · %scheduled% programată'
    type_summons_reply: 'RĂSPUNS SOMAȚIE · %days% zile'
    type_prescription: 'PRESCRIPȚIE · %years% ani'
    type_communication_proof: 'DOVADĂ COMUNICARE'
    type_hearing: 'TERMEN JUDECATĂ'
    type_appeal: 'CONTESTAȚIE · %days% zile'
    body_summons_reply: 'Termen pentru ca debitorul să plătească voluntar înainte de depunerea cererii OP. La expirare, statusul tranziționează automat.'
    body_prescription: 'Termen prescripție extinctivă (Cod civil art. 2517). Întreruperea curge de la fiecare act de procedură.'
    body_communication_proof: 'Recipisa executor judecătoresc atașată la dosar.'
    body_hearing: 'Termen de judecată fixat de instanță.'
    body_appeal: 'Termen pentru contestație împotriva ordonanței.'
    empty_title: 'Niciun termen creat încă'
    empty_body: 'Termenele se creează automat la fiecare tranziție de status (Pas 4.1).'
    calendar_title: 'Calendar termene · următoarele 30 zile'
    calendar_empty: '— niciun alt termen până la %date% —'
    alerts_card_title: 'Alerte automate'
    alerts_card_body: 'Email la 7/3/1 zile înainte + alertă „expirat". Nu pierzi termene chiar dacă nu deschizi aplicația.'
```

### 5.13 Portal

```yaml
case_overview:
  portal:
    config_label: 'CONFIGURARE MONITORIZARE'
    config_title: 'Nr. dosar instanță'
    config_hint: 'Introdu numărul primit de la registratura instanței după depunere. Activează monitorizarea zilnică.'
    config_inactive: 'NEACTIVATĂ'
    config_active: 'ACTIVĂ'
    config_placeholder: 'ex: 4521/302/2026'
    config_format: 'format Ecris'
    config_activate: 'Activează monitorizare'
    config_disclaimer: 'Monitorizarea va rula zilnic la 08:00, va detecta termen de judecată / soluții / contestații și va aplica tranziții automate.'
    timeline_title: 'Timeline portal.just.ro'
    timeline_check_now: 'Verifică acum'
    empty_title: 'Niciun eveniment monitorizat încă'
    empty_body: 'Dosarul nu e încă depus la instanță. Introdu nr. dosar mai sus pentru a porni monitorizarea zilnică.'
    preview_title: 'Exemplu de timeline (după activare)'
    preview_label: 'previzualizare'
    preview_event_1_title: 'Ordonanță emisă · admisă în parte'
    preview_event_1_body: 'Termen contestație începe să curgă: 10 zile de la comunicare.'
    preview_event_2_title: 'Termen judecată fixat'
    preview_event_2_body: 'Sala C2 · complet C12. Auto-creat termen tip JUDECATĂ.'
    preview_event_3_title: 'Dosar înregistrat la registratură'
    preview_event_3_body: 'Status DOSAR_ÎNREGISTRAT.'
    how_title: 'Cum funcționează'
    how_step_1: 'Cron rulează zilnic la 08:00 — interogare SOAP la portal.just.ro'
    how_step_2: 'Detectare evenimente noi: termen, soluție, contestație, comunicări'
    how_step_3: 'Tranziții automate aplicate (TERMEN_FIXAT, ORDONANTA_EMISA, etc.)'
    how_step_4: 'Email + notificare in-app la fiecare schimbare'
    how_source: 'Sursă: portalquery.just.ro/query.asmx — gratuit, public, oficial.'
    sync_title: 'Status sincronizare'
    sync_last_check: 'Ultima verificare'
    sync_next_check: 'Următoarea'
    sync_total_events: 'Evenimente totale'
    sync_not_started: '— încă nepornită'
    sync_not_scheduled: '—'
```

### 5.14 Audit

```yaml
case_overview:
  audit:
    title: 'Audit log dosar'
    subtitle: 'Înregistrare imutabilă a tuturor acțiunilor pentru trasabilitate juridică'
    filter_all: 'Toate acțiunile'
    filter_create: 'CREATE'
    filter_update: 'UPDATE'
    filter_transition: 'TRANSITION'
    filter_doc_generate: 'DOCUMENT_GENERATE'
    filter_doc_upload: 'DOCUMENT_UPLOAD'
    filter_extraction: 'EXTRACTION'
    filter_wizard: 'WIZARD_SUBMIT'
    search_placeholder: 'Caută în audit...'
    export_csv: 'Export CSV'
    col_timestamp: 'Timestamp'
    col_actor: 'Actor'
    col_action: 'Acțiune'
    col_details: 'Detalii'
    col_ip: 'IP'
    actor_system: 'Sistem · %service%'
    details_view: 'Detalii'
    details_diff: 'Diff'
    details_payload: 'Payload'
    details_initial: 'Initial'
    footer_count: 'Afișezi %visible% din %total% acțiuni'
    footer_retention: 'Înregistrările sunt imutabile · stocate 5 ani conform reglementări'
    empty_title: 'Niciun eveniment în jurnal'
    empty_body: 'Acțiunile pe acest dosar vor apărea aici.'
```

### 5.15 Modals

```yaml
case_overview:
  modal:
    close_case_title: 'Confirmă închiderea dosarului'
    close_case_body: 'Dosarul %reference% va fi marcat ca închis. Monitorizarea automată portal.just.ro și alertele pe termene se opresc. Datele rămân în arhivă.'
    close_case_reason: 'Motiv închidere'
    close_case_reason_placeholder: '— Selectează motiv —'
    close_case_reason_paid: 'Plată integrală amiabilă'
    close_case_reason_partial: 'Recuperare parțială'
    close_case_reason_insolvent: 'Debitor insolvabil'
    close_case_reason_abandoned: 'Abandon dosar (decizie client)'
    close_case_cancel: 'Renunță'
    close_case_confirm: 'Închide dosar'
    generate_op_title: 'Generează pachet cerere OP'
    generate_op_intro: 'Pachetul ZIP va include:'
    generate_op_item_cerere: 'Cerere ordonanță de plată (PDF)'
    generate_op_item_cerere_hint: 'va fi generată'
    generate_op_item_opis: 'Opis documente (PDF)'
    generate_op_item_opis_hint: 'va fi generat'
    generate_op_item_evidence: 'somatie + contract + factură'
    generate_op_item_evidence_hint: '%count% fișiere existente'
    generate_op_disclaimer: 'Vei depune fizic la %court%. Întoarce-te aici după depunere pentru a introduce nr. dosar instanță.'
    generate_op_disclaimer_no_court: 'Întoarce-te aici după depunere pentru a introduce nr. dosar instanță.'
    generate_op_cancel: 'Renunță'
    generate_op_confirm: 'Generează & descarcă ZIP'
```

**Echivalent EN integral** în `messages.en.yaml` — same structure, English translations.

---

## 6. Reutilizare existentă (din Pas 2.7+)

| Componentă / asset | Cale | Cum se folosește |
|---|---|---|
| `StatusBadge` | `templates/components/StatusBadge.html.twig` | Hero status pill (`pulse: true`, `show_dot: true`); badge ANAF debtor; badge type document |
| `PipelineStatus` | `templates/components/PipelineStatus.html.twig` | Verifică etichetele 5 stadii; reutilizează cu prop `variant: 'overview'` sau partial nou dacă diferă |
| `DeadlineList` | `templates/components/DeadlineList.html.twig` | Tab Termene principal list (verifică în execuție dacă cardul ring+checkbox fit-uie) |
| `_sidebar.html.twig` | `templates/_sidebar.html.twig` | Shell standard, NU se modifică |
| `_topbar.html.twig` | `templates/_topbar.html.twig` | Breadcrumb override; theme toggle; dosar nou CTA |
| `_footer.html.twig` | `templates/_footer.html.twig` | Shell standard, NU se modifică |
| Tailwind tokens | `assets/styles/app.css` | `lex-navy`, `shadow-soft/card/hero`, `soft-pulse`, `tnum`, `nav-active`, `auth-bg` — verifică doar `ring-fill` |
| `CaseVoter::VIEW` | `src/Security/Voter/CaseVoter.php` | Voter check în controller |
| `InterestCalculatorService::calculate()` | `src/Service/Calculation/InterestCalculatorService.php` | Recompute BNR breakdown la render Detalii |
| i18n existente | `translations/messages.ro.yaml` | `case_status.*` (Pas 1.2), `component.deadline_list.*` (Pas 2.7), `layout.brand`, `topbar.cta_new_case` |
| Preline JS | `importmap.php` | tabs/accordion/dropdown/modal — zero JS nou |

---

## 7. Anti-pattern-uri (NU faci aceste lucruri)

1. **NU re-introduce Webpack/Node** — Asset Mapper + Tailwind standalone e fundația stack-ului (memorie + CLAUDE.md).
2. **NU adăuga rate limiter** pe `case_overview` — pagina e read-only, Voter check e suficient.
3. **NU duplica SVG inline** — toate prin macro-uri în `_icons.html.twig`. Verifică cu `grep -r '<svg' templates/case/overview/` să fie minim (doar în macros + cazuri speciale ca SVG circle progress).
4. **NU hardcode în Twig** — nicio sumă/dată/text/procent direct în template. Toate prin entități + filtre Symfony Intl + i18n.
5. **NU folosi `templates/components/`** pentru partials single-use; folosește `templates/case/overview/_*.html.twig`. `templates/components/` e rezervat componentelor reutilizabile cross-page.
6. **NU implementa logică nouă în Controller** — doar fetch + render. Recalcul BNR breakdown e doar pentru afișaj (NU re-persist `case.calculatedInterest`).
7. **NU adăuga entități/migrări noi** în acest pas — toată infra-ul există din Pas 1.1-2.7.
8. **NU schimba semantica enum-urilor existente** — dacă mockup-ul afișează „SOMAȚIE TRIMISĂ" iar enum-ul e `SOMATIE_TRIMISA`, i18n label-ul rezolvă diferența.
9. **NU implementa funcționalitate** care intră în 4.3/5.x/6.x — `aria-disabled` strict + tooltip coming_soon_*.
10. **NU șterge nimic** din componente Pas 2.7 — extinde dacă e nevoie (prop variant).
11. **NU pune query-uri în Twig** — toate datele vin din controller. Filtrarea minimă în Twig e ok (`|filter()` pe array deja preîncărcat), dar NU acces lazy la relații care declanșează query-uri suplimentare (verifică n+1 cu profiler).
12. **NU folosi `dump()` sau `var_dump`** în template — strict prod-ready.

---

## 8. Verificare end-to-end (după 4.0.7 DONE)

### 8.1 Verificare vizuală

```bash
# Tab 1: mockup
php -S 127.0.0.1:8888 -t docs/LexRecovery/mockups &
# Tab 2: app
make up
```

Deschide în browser side-by-side:
- `http://localhost:8888/v2/04-dosar/overview.html`
- `http://localhost:8080/dosar/{id}` (cu un dosar real creat din wizard)

Verifică:
- [ ] Spacing, font-size, color match pe toate cele 5 tab-uri.
- [ ] Hover states pe butoane (lex-navy pe „Dosar nou", hover-state pe tabs).
- [ ] Dark mode toggle din topbar → tot rămâne coerent (nu sunt segmente light pe fond dark sau invers).
- [ ] Animație status pulse dot funcționează (`soft-pulse` keyframe).
- [ ] Animație ring-fill funcționează (`ring-fill` keyframe).
- [ ] Preline tabs comută `hidden` clasele pe panels corespondente.
- [ ] Preline accordion BNR expand/collapse cu animație height.
- [ ] Preline dropdown 3-puncte cu rotație chevron + outside-click close.
- [ ] Preline modal Close case + Generate OP afișare/închidere.

### 8.2 Verificare funcțională

```bash
bin/phpunit tests/Controller/Case/CaseOverviewControllerTest.php
bin/phpunit tests/Repository/LegalDeadlineRepositoryTest.php
bin/phpunit tests/Repository/AuditLogRepositoryTest.php
bin/phpunit  # full suite
```

Verifică:
- [ ] Toate ~50 teste verzi.
- [ ] Zero regresii vs baseline Pas 3.2 (586/40/4 — check stat după rulare).

### 8.3 Verificare i18n

```bash
bin/console debug:translation ro --domain=messages | grep case_overview
bin/console debug:translation en --domain=messages | grep case_overview
bin/console debug:translation ro --only-missing
bin/console debug:translation en --only-missing
```

Verifică:
- [ ] Zero `missing` keys pentru `case_overview.*`.
- [ ] Toate cheile prezente atât în RO cât și în EN.

### 8.4 Verificare a11y

- Rulează Lighthouse audit în Chrome DevTools pe `/dosar/{id}`.
- Target: accessibility score ≥ 95.
- Verifică `aria-disabled` corect aplicat, label-uri pe input-uri, contrast color text/background.

### 8.5 Verificare manual cu 3 dosare diferite

Creează în baza locală (sau prin wizard) 3 dosare diferite:
1. Dosar `SOMATIE_TRIMISA` cu 2 debitori, court setat, 3 documente uploadate, 2 audit log entries → pagina full populated.
2. Dosar `AMIABIL` fără court (C5 fallback), fără debitor secondary, fără documente → empty states multiple.
3. Dosar cu debitor ANAF `RADIAT` → badge red ONRC vizibil; cu deadline expirat → priority HIGH amber.

Verifică pe fiecare:
- [ ] Hero corect populat.
- [ ] KPI defensiv null pe câmpuri lipsă.
- [ ] Pipeline highlight pe stadiul corect.
- [ ] Toate tab-urile robuste fără PHP exceptions sau Twig warnings.

---

## 9. Cross-references cu alte pași din plan

| Pas | Relația cu 4.0 |
|---|---|
| Pas 3.0 - 3.3 | Pre-condiție: LegalCase complet din wizard |
| Pas 2.7 | Pre-condiție: shell + componente design system |
| Pas 2.5.4 | Pre-condiție: `AuditLog.category` indexed |
| Pas 4.1 | `DeadlineService` — creează termene automat; Pas 4.0 doar le afișează |
| Pas 4.2 | `DeadlineCreationSubscriber` — wired la transitions |
| Pas 4.3 | UI tab Termene wiring — wire-ează CTA-urile add/edit/complete care în 4.0.5 sunt aria-disabled |
| Faza 5 (Portal) | Wire-ează input nr. dosar + activate monitoring din 4.0.6 |
| Faza 6 (PDF) | Wire-ează CTA „Generează cerere OP" + ZIP package din 4.0.4 + modal generate_op din 4.0.7 |
| Pas 4.x Workflow închidere | Wire-ează modal Close case din 4.0.7 |

---

## 10. Definition of Done — Pas 4.0 complet

Pasul 4.0 este DONE când:

- ✅ Toate 7 sub-pași (4.0.1 → 4.0.7) au commit-uri verzi.
- ✅ Mock-up `04-dosar/overview.html` e reprodus 1:1 vizual (manual diff side-by-side).
- ✅ Toate 5 tab-uri funcționale (Preline tabs comută corect).
- ✅ Toate 2 modal-uri funcționale (deschidere/închidere; CTA-urile confirm aria-disabled).
- ✅ Date wired la backend acolo unde infra-ul există; aria-disabled + tooltip pe restul.
- ✅ Zero hardcodare în text; zero SVG duplicat; zero dependențe JS noi.
- ✅ Zero regresii pe `bin/phpunit` suite vs baseline Pas 3.2.
- ✅ i18n RO + EN complete (zero missing keys `case_overview.*`).
- ✅ Dark mode robust pe toate 5 tab-uri.
- ✅ Lighthouse a11y ≥ 95.
- ✅ Code review (lexrecovery-code-reviewer + lexrecovery-legal-reviewer) cu verdict commit-ready.
- ✅ Memory file `project_lexrecovery_pas_4_0.md` scris cu sumar de livrare + decizii cheie + lessons learned (per `feedback_save_progress_per_step`).
- ✅ MEMORY.md actualizat cu link.
