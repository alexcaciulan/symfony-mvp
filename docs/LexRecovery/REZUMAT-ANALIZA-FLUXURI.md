# LexRecovery — Rezumat executiv

> Versiune condensată a [`ANALIZA-FLUXURI-LEXRECOVERY.md`](./ANALIZA-FLUXURI-LEXRECOVERY.md). Pentru detalii pe orice secțiune, urmărește link-urile **§** către documentul integral.

---

## TL;DR (în 5 puncte)

1. **Ce este**: SaaS pentru avocați care automatizează **Ordonanța de Plată** (CPC art. 1014-1025) — recuperare creanțe end-to-end, de la somație până la titlu executoriu.
2. **Pentru cine**: avocat de drept comercial/civil care vrea să gestioneze **50-100+ dosare** simultan în loc de 5-10.
3. **Promisiunea**: zero risc de pierdere termene + reducere muncă administrativă de la ore la minute (somație 30-45 min → 2 min, cerere OP 2-3 ore → 5 min).
4. **Scope MVP**: Faze 1-5 (până la `DEFINITIVA` = titlu executoriu). Executarea silită (Faza 6) e post-MVP.
5. **Stack**: Symfony 7.3 + PHP 8.4 + MySQL 8 + Twig/Stimulus/Turbo + Tailwind v4 + **Preline UI** + Mercure.

---

## Persona

**Avocat single-user** (cont independent, fără entitate `Cabinet`). Job-uri principale ([§2](./ANALIZA-FLUXURI-LEXRECOVERY.md#2-personă-și-jobs-to-be-done)):
- Reduce timp redactare somații/cereri OP
- Elimină risc termene pierdute (30 zile somație, 10 zile contestație, 3 ani prescripție)
- Vizibilitate portofoliu (dashboard cu filtre + sortare urgență)
- Documente standardizate (PDF pre-completat, gata de depus)

---

## Fluxul end-to-end (7 fraze)

1. Avocat creează **Dosar nou** (status `AMIABIL`) — wizard 5 pași cu auto-extracție date din contracte/facturi încărcate.
2. Generează **Somație de plată** (PDF) → status `SOMATIE_TRIMISA` + termen 30 zile monitorizat cu alerte 7/3/1 zile.
3. Dacă debitorul nu plătește → generează **Cerere OP + opis + ZIP** → status `CERERE_DEPUSA`; avocatul depune fizic la instanță și introduce nr dosar → `DOSAR_INREGISTRAT`.
4. **Monitorizare automată portal.just.ro** (cron zilnic 08:00) — detectează termene de judecată, hotărâri, contestații → propune tranziții.
5. **Ordonanță emisă** → status `ORDONANTA_EMISA` + termen 10 zile contestație.
6. Fără contestație timp de 10 zile → tranziție automată → status `DEFINITIVA` (titlu executoriu).
7. Cazuri terminale: `INCHIS_SUCCES` (plată) / `INCHIS_PARTIAL_INSOLVABIL` / `RESPINSA`.

Vezi diagrama mermaid completă în [§3](./ANALIZA-FLUXURI-LEXRECOVERY.md#3-fluxul-end-to-end-al-unui-dosar).

---

## Numere cheie

### Termene legale ([§8](./ANALIZA-FLUXURI-LEXRECOVERY.md#8-sistemul-de-termene))

| Termen | Durata | Sursă | Prioritate |
|---|---|---|---|
| Răspuns somație | 30 zile | Cerință procedurală | HIGH |
| Contestație ordonanță | 10 zile | CPC art. 1023 | CRITICAL |
| Prescripție creanță | 3 ani | Cod civil art. 2517 | CRITICAL |
| Prescripție executare | 3 ani de la definitivă | — | MEDIUM |

**Alerte automate**: 7/3/1 zile înainte + email "expirat" la depășire (cron `app:check-termene` 07:00 zilnic).

### Taxe & praguri ([§7](./ANALIZA-FLUXURI-LEXRECOVERY.md#7-calcule-automate))

| Element | Regula |
|---|---|
| Taxa timbru OP (OUG 80/2013 art. 6) | **50 RON** dacă creanța ≤ 500 RON; **200 RON** dacă > 500 RON |
| Instanță competentă (CPC art. 1015) | ≤ 200.000 RON → **Judecătorie**; > 200.000 RON → **Tribunal** |
| Dobândă legală COMERCIAL (OG 13/2011) | rata BNR + **8 puncte procentuale** |
| Dobândă legală CIVIL | rata BNR + **4 puncte procentuale** |

**Formula dobândă**: `dobanda = suma × (rata_aplicabila / 100) × zile / 365`, calculată pe perioade în care rata BNR a fost constantă.

---

## Statusuri & tranziții ([§6](./ANALIZA-FLUXURI-LEXRECOVERY.md#6-workflow--state-machine))

12 statusuri `CaseStatus`:

```
AMIABIL → SOMATIE_TRIMISA → CERERE_DEPUSA → DOSAR_INREGISTRAT
       → TERMEN_FIXAT → ORDONANTA_EMISA → DEFINITIVA → [INCHIS_SUCCES | INCHIS_PARTIAL_INSOLVABIL]
                                       ↓
                                CONTESTATA → [DEFINITIVA | RESPINSA]

       Terminale: RESPINSA, INCHIS_SUCCES, INCHIS_PARTIAL_INSOLVABIL
```

**Cine declanșează ce**:
- **Manual** (avocat): `trimite_somatie`, `depune_cerere`, `inregistreaza_dosar`, `respinge_contestatie`, `admite_contestatie`, `inchide_succes`, `inchide_insolvabil`
- **Automat din portal.just.ro**: `fixeaza_termen`, `emite_ordonanta`, `respinge`, `contesta`
- **Automat din timer** (cron): `marcheaza_definitiva` (după 10 zile fără contestație)

---

## Modelul de domeniu ([§5](./ANALIZA-FLUXURI-LEXRECOVERY.md#5-modelul-de-domeniu))

**Single-user**: dosarele aparțin direct user-ului (FK `user_id`), nu există entitate `Cabinet`.

Entități principale: `User`, `LegalCase` (creanță), `Creditor` (clientul avocatului), `Debtor`, `Document` (cu `extractedData` JSON), `LegalDeadline`, `CourtPortalEvent`, `CaseStatusHistory`, `AuditLog`, `Notification`, `Plan` / `Subscription` / `Invoice` (monetizare), `InterestRateConfig` (istoric BNR pentru calcul retroactiv dobândă).

---

## Diferențiatorul: extracție date din documente ([§7.4](./ANALIZA-FLUXURI-LEXRECOVERY.md#74-extracție-automată-date-din-documente-sursă))

Avocat încarcă contract/factură/somație → sistem pre-populează formularele wizard.

**Cascadă 4 trepte (ordonată cost ascendent)**:
1. **PDF text-based parser** (smalot/pdfparser) — gratis, instant
2. **OCR local + AI text** — Tesseract local + Claude API text → ~$0.002/doc, GDPR-friendly (imaginea NU pleacă)
3. **AI vision multimodal** — Claude vision direct pe imagine → $0.01-0.05/doc, fallback pentru cazuri grele
4. **Stub manual** — avocat completează manual

**Setting GDPR per cont**: `LOCAL_ONLY` / `BALANCED` (default) / `MAX_ACCURACY`.

**Cost mediu ponderat**: ~$0.005/dosar (inclus în abonament, fără facturare separată).

---

## Documente generate ([§9](./ANALIZA-FLUXURI-LEXRECOVERY.md#9-documente-generate))

| Document | Trigger | Output |
|---|---|---|
| Somație de plată | `trimite_somatie` | PDF (DomPDF) |
| Cerere OP + Opis | `depune_cerere` | 2 PDF-uri |
| ZIP pachet instanță | `depune_cerere` | ZIP cu toate documentele dosarului |

---

## Stack tehnic ([§12](./ANALIZA-FLUXURI-LEXRECOVERY.md#12-frontend-stack--ux-standards))

**Backend**: Symfony 7.3 + PHP 8.4 + MySQL 8 + Doctrine + Symfony Workflow + Messenger.

**Frontend** (zero npm/Node.js):
- Twig + Tailwind v4 + Asset Mapper (importmap)
- **Preline UI (free, MIT)** — 300+ primitive ([§12.6](./ANALIZA-FLUXURI-LEXRECOVERY.md#126-decizia-componentelor-ui))
- Turbo Drive/Frames + Stimulus + Symfony UX (Live Components, Autocomplete, Icons)
- Mercure Hub pentru server push real-time (status extracție, evenimente portal, deadline alerts)

**Externe**:
- portal.just.ro SOAP (`PortalJustClient` existent, refolosit)
- ANAF API (`AnafLookupService` existent, pentru CUI lookup la blur)
- Anthropic Claude API (text + vision pentru extracție)
- Tesseract OCR local + ImageMagick (în Docker container)

---

## Monetizare ([§13](./ANALIZA-FLUXURI-LEXRECOVERY.md#13-monetizare))

Model **hibrid**: abonament cu N dosare incluse + plată per dosar suplimentar.

Exemple plan: Starter (5 dosare/lună 99 RON), Pro (25 dosare/lună 299 RON).

Gateway plăți (Stripe / Netopia / MobilPay) e **post-MVP**; în MVP doar interfață `PaymentGatewayInterface` + stub manual.

---

## Auth & rate limiting ([§14](./ANALIZA-FLUXURI-LEXRECOVERY.md#14-auth--autorizare))

- Email + parolă + email verification (refolosit din MVP)
- Voters (`CaseVoter`) cu permisiuni `DOSAR_VIEW/EDIT/TRANSITION/UPLOAD`
- Rate limiters: `registration` 3/h IP, `forgot_password` 3/h IP, `case_creation` 10/h user, `document_upload` 20/h user, `company_lookup` 10/h user

---

## Riscuri principale ([§17](./ANALIZA-FLUXURI-LEXRECOVERY.md#17-riscuri-și-unknowns))

| # | Risc | Impact | Strategie |
|---|---|---|---|
| R6 | Migrare DB la merge `lexrecovery` → `develop` | **Mare** | Drop+recreate baseline; pierdem date MVP (acceptabil — pivot strategic) |
| R9 | Acuratețe extracție AI poate induce avocat în eroare | **Mare** | Confidence ≥ 0.8 pentru pre-populare; sub asta, doar "sugestie"; audit log per câmp |
| R10 | GDPR — documente prin Anthropic API | Mediu | Tesseract local pe treapta 2 (text only la AI); setting `LOCAL_ONLY`; mascare CNP |
| R8 | Ambiguitate termene legale (zile lucrătoare?) | Mediu | Validare juridică; default zile calendaristice |
| R4 | Taxa timbru OP — verificare valori exacte | Mic | Validare cu avocat pre-Pas 2.2; configurabil în `parameters.yaml` |

Restul (R1, R2, R3, R5, R7, R11, R12) sunt mici/medii — vezi tabelul complet pentru mitigări.

---

## Diferențe față de MVP-ul actual ([§15](./ANALIZA-FLUXURI-LEXRECOVERY.md#15-diferențe-față-de-mvp-ul-actual))

| Aspect | MVP actual | LexRecovery |
|---|---|---|
| Target | Creditori | **Avocați** |
| Scop | Cerere VR unică | **Dosar continuu pe 5-6 faze** |
| Documente | 1 (Anexa 1) | **3-4** (somație, cerere OP, opis, ZIP) |
| Statusuri | 9 (legate de plata taxei) | **12 specializate OP** |
| Calcule | Taxa judiciară small claims | **Dobândă OG 13/2011 + taxa timbru OP** |
| Termene | Niciunul | **Sistem complet 4 tipuri + cron alerte** |
| Multi-dosar | Nu prioritar | **Esențial** (50-100+) |
| Monetizare | Per dosar | **Abonament + per dosar extra** |

---

## Cod existent reutilizabil ([§16](./ANALIZA-FLUXURI-LEXRECOVERY.md#16-cod-existent-reutilizabil-vs-de-refăcut))

**100% refolosibil**: User, Court, Document, AuditLog, Notification, CourtPortalEvent entities + AuditLogService, CaseWorkflowService, DocumentUploadService, **PortalJustClient + PortalEventDetector**, AnafLookupService, rate limiters, auth complet, întreg setup-ul Tailwind/Stimulus/Turbo.

**Adaptare ușoară**: `LegalCase` (extins cu noi câmpuri), `CaseVoter` (refolosit), `PdfGeneratorService` → bază pentru noile generatoare, `MonitorCourtCasesCommand` → `PortalCheckAllCommand`.

**De rescris**: `InterestCalculatorService`, `StampDutyCalculator`, `DataExtractionService` + 4 strategii, `DeadlineService`, generatoare somație/cerere OP, workflow YAML, wizard 5 pași.

**De eliminat**: `TaxCalculatorService` (small claims), `CaseWizardController` 6 pași + DTOs/Forms aferente, `Payment` entity (fuzionată în `Invoice`), toate migrările existente (drop + recreate).
