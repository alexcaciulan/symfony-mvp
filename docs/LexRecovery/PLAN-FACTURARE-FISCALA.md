# PLAN FACTURARE FISCALĂ — thin mirror prin provider extern (Oblio / SmartBill)

> Document de planificare pe faze. Companion al `PLAN-DEZVOLTARE-LEXRECOVERY.md` (extinde Faza 8 - Monetizare).
> Statut: **IMPLEMENTAT (necomis) 2026-07-01** — P1 + P2 + P3 livrate pe stub + adaptoare Oblio/SmartBill.
> Suită 1268 teste (9E/1F baseline, zero regresii). Reviews code: toate NEEDS-FIX → fix-uite (COMMIT-READY).
> Rămâne pentru pre-prod: credențiale reale provider în `.env.local` + validare cont test Oblio + confirmări
> consultant fiscal (TVA, date furnizor, CIF avocați). Vezi secțiunea 7.
>
> **Revizie de direcție (2026-07-01):** versiunea anterioară (7 faze) construia un motor fiscal în aplicație.
> A fost înlocuită cu modelul **thin mirror** după investigarea API-urilor Oblio și SmartBill. Faza F1 din
> vechiul plan a fost deja construită (necomisă) și se reîncadrează aici (vezi secțiunea 5).

---

## 1. Context și motivație

LexRecovery facturează abonamentele și dosarele suplimentare către clienții săi (cabinete de avocatură /
avocați individuali). Din ian. 2024, raportarea **e-Factura B2B prin SPV este obligatorie**, iar clienții
fiind persoane impozabile (CIF), toate facturile sunt B2B.

**De ce thin mirror.** Software-ul de facturare certificat (Oblio, SmartBill) face deja partea grea:
numerotare legală (serie + număr), calcul TVA, PDF oficial, transmitere la e-Factura/SPV, arhivă contabilă.
A reimplementa toate astea în aplicație înseamnă cod dublat, risc de desincronizare cu contabilitatea (exact
bug-ul de numerotare prins la reviewul F1) și efort inutil.

**Direcția:** providerul = motor + arhivă oficială; aplicația = **declanșator + oglindă + UX**. Rezultă ~o
treime din efortul planului original, fără desincronizare, conform e-Factura automat, livrare mai rapidă.

---

## 2. Decizii confirmate cu utilizatorul

| # | Decizie | Valoare |
|---|---------|---------|
| D1 | Nivel de conformitate | Factură fiscală completă + e-Factura (SPV) |
| D2 | Mecanism | Provider contabil extern face numerotarea, TVA, PDF, SPV |
| D3 | Arhitectură | Thin mirror: aplicația ține doar oglinda + UX, NU motorul fiscal |
| D4 | Suport ambii provideri | Cod care suportă **Oblio ȘI SmartBill** în spatele unei interfețe |
| D5 | Switch între provideri | **Toggle live din admin** (setare în DB + resolver), fără restart; env ca fallback |

---

## 3. API-uri provider (rezumat investigație)

| Criteriu | Oblio | SmartBill |
|---|---|---|
| Bază | `https://www.oblio.eu/api` | `https://ws.smartbill.ro/SBORO/api` |
| Auth | OAuth2 `POST /authorize/token` (token 1h, cache+refresh) | Basic auth `base64(email:token)` |
| Creare | `POST /docs/invoice` → `{seriesName, number, link}` | `POST /invoice` → serie/număr + PDF |
| TVA | `vatPercentage` per produs, calculat de provider | `taxPercentage` per produs, calculat de provider |
| PDF | câmp `link` (URL) în răspuns | `GET /invoice/pdf?cif&seriesname&number` (bytes) |
| Încasare | obiect `collect` în create sau `PUT /docs/invoice/collect` | endpoint plată/chitanță |
| e-Factura SPV | `POST /docs/einvoice` (apel explicit; auto = preferință cont) | apel explicit / auto dacă modulul e activ |
| Status SPV | **polling** `GET /docs/einvoice` (cod -1/0/1/2) | **polling** (GET status) |
| Storno real | factură nouă `referenceDocument`+`refund:1` (`cancel` doar pre-SPV) | storno/cancel |
| Draft prin API | **NU** (doar UI) | **DA** (`isDraft:true`) |
| Idempotență | `idempotencyKey` în create | gardă pe `providerInvoiceId` |
| Webhooks | DA, dar topicuri `Invoice/SaveDraft\|Update\|Cancel`, `Collect/Inserted`, `stock` (NU status SPV) | nu |
| Rate limit | 30 doc/100s, 30 non-doc/10s | nepublicat (~200/min → 429) |

Diferențele reale care ating codul (absorbite în adaptoare): **auth** (OAuth vs Basic), **PDF** (URL vs bytes),
**draft prin API** (doar SmartBill). **Statusul SPV se ia prin polling la AMBII** (webhook-urile Oblio nu acoperă
SPV). Restul (numerotare, TVA, încasare, storno, e-Factura) sunt echivalente conceptual, doar căi diferite.

---

## 4. Principii de arhitectură

1. **Provider-agnostic, cu toggle live din admin.** O interfață `EInvoicingProviderInterface` + adaptoare
   `OblioEInvoicingProvider`, `SmartBillEInvoicingProvider`, `StubEInvoicingProvider` (toate tagged), rezolvate la
   runtime de un `EInvoicingProviderResolver` în funcție de o **setare în DB** (`AppSetting` cheie/valoare), editabilă
   dintr-un **toggle în EasyAdmin**, fără restart. `EINVOICE_PROVIDER` env rămâne **default/fallback** dacă setarea
   din DB lipsește. Restul aplicației injectează doar interfața (de fapt resolver-ul, care deleagă la adaptorul activ);
   codul de emitere/storno/status e identic indiferent de provider.
2. **Aplicația nu vede niciodată JSON specific de provider.** DTO-uri normalizate
   (`EInvoiceIssueRequest`/`EInvoiceIssueResult`/`EInvoiceStatusResult`/`EInvoiceCancelResult`).
3. **Providerul deține:** serie + număr, calcul TVA, PDF oficial, transmitere SPV, arhivă contabilă.
4. **Aplicația deține:** captura datelor fiscale ale clientului + poartă, declanșarea emiterii async la plată,
   oglinda locală (referință + snapshot pentru afișare), UI listă/download/status, butonul de storno,
   ingestia statusului (webhook Oblio / polling SmartBill).
5. **Identificarea plătitorului:** avocat individual sau cabinet de avocatură profesează printr-o formă cu **CIF**
   (Cabinet Individual de Avocat / SCA), înregistrată la **Barou/UNBR, NU la ONRC**. Deci: identificare prin **CIF**,
   **fără număr de registrul comerțului**. Poarta impune **CIF + denumire** la `AVOCAT`/`PJ`; nu se facturează ca
   B2C/CNP. Statutul de plătitor TVA al clientului e irelevant pentru factura noastră (TVA-ul îl punem după statutul
   nostru). (Confirmare consultant fiscal.)
6. **Async via Messenger** (worker existent): se potrivește cu rate-limit-urile providerilor și cu retry la
   reînnoiri în lot.
7. **Reutilizare:** `HttpClientInterface` (ca `AnafLookupService`/`AnthropicApiClient`), pattern `ExtractDataMessage`
   pentru async, `AuditLogService::CATEGORY_BILLING`, `PaymentGatewayInterface` ca model de abstracție+DI.

---

## 5. Reîncadrarea muncii F1 (deja construită, necomisă)

| Componentă F1 | Soartă |
|---|---|
| Enum-uri `EInvoiceStatus`/`FiscalInvoiceKind`/`FiscalInvoiceStatus` | **PĂSTRĂM** |
| DTO `PartySnapshot` (+`country`) | **PĂSTRĂM** (snapshot client, util și cu provider) |
| Entitate `FiscalInvoice` | **PĂSTRĂM, slăbită** (câmpuri provider; vezi P1) |
| `FiscalInvoiceLine` | **PĂSTRĂM minimal** (construire request + afișare; totalurile vin de la provider) |
| `VatCalculator` | **RETRAGEM din producție**; eventual păstrat doar pentru afișare „preț + TVA" la checkout |
| `FiscalNumberingService` + `InvoiceSeries` | **ELIMINĂM** (providerul ține seria/numărul) |
| PDF local (era F3) | **ELIMINĂM** (PDF-ul oficial e al providerului) |

Migrarea F1 nu e comisă: o ajustăm la forma slim înainte de primul commit (eliminăm `invoice_series`,
adăugăm câmpurile provider pe `fiscal_invoice`).

---

## 6. Faze de implementare (3)

### P1 — Oglindă slim + abstracție provider + normalizare (fără rețea)
**Scop:** fundația thin-mirror + interfața + stub, fără apeluri externe; flux complet verificabil pe stub.

- Reformează `FiscalInvoice` la forma thin-mirror: `providerName`, `providerInvoiceId`, `seriesName`,
  `number` (string de la provider), `currency`, `issueDate`, `dueDate`, `taxPointDate`, snapshot `buyer`+`supplier`
  (JSON), `netTotal`/`vatTotal`/`grossTotal` (din request, le calculăm noi; providerul le recalculează identic),
  `pdfUrl` + `pdfPath` (nullable), `eInvoiceStatus`/`spvId`/`eInvoiceError`, `status`/`kind`/`stornoOf`, timestamps.
- `src/Service/Billing/EInvoicing/EInvoicingProviderInterface`:
  - `issue(EInvoiceIssueRequest): EInvoiceIssueResult` (create + marcare încasat + trimitere la SPV, vezi P2),
  - `fetchEInvoiceStatus(FiscalInvoice): EInvoiceStatusResult` (**polling** — la ambii provideri),
  - `storno(FiscalInvoice, string $reason): EInvoiceCancelResult` (factură de corecție reală),
  - `getPdf(FiscalInvoice): string|null` (URL sau bytes, normalizat),
  - `isEFacturaEnabled(): bool`.
- DTO-uri normalizate în `src/DTO/Billing/EInvoicing/`. `EInvoiceIssueRequest` poartă: `supplierCif`, `seriesName`,
  `client` (cif, name, `rc` opțional = gol la avocați, address, city, country, vatPayer, email), `products`
  (name, price, quantity, vatPercentage, vatName), `currency`, `language`, `issueDate`, `dueDate`,
  `collect` (type+value+date, factura emisă deja încasată), `idempotencyKey` (= id intern `Invoice`).
- `StubEInvoicingProvider` (dev/teste; **gardă env prod**), tagged `app.einvoicing_provider`.
- **Switch live:** entitate `AppSetting` (cheie/valoare) + `EInvoicingProviderResolver` (implementează interfața,
  primește toate adaptoarele ca tagged iterator + setarea activă din DB, deleagă la cel ales; fallback pe
  `%env(EINVOICE_PROVIDER)%`). Aplicația injectează resolver-ul ca `EInvoicingProviderInterface`.
- Elimină `FiscalNumberingService` + `InvoiceSeries`; `VatCalculator` → șters sau redus la helper de afișare (D-deschisă).
- Migrare ajustată (slim, aditivă, + tabel `app_setting`). Teste (entitate slim, stub, DTO-uri, resolver alege corect adaptorul).

### P2 — Adaptoare reale (Oblio + SmartBill) + emitere async la plată
**Scop:** emitere reală la confirmarea plății, prin oricare provider, comutabil din config.

- **`OblioEInvoicingProvider`** (confidență mare, doc verificată): OAuth `POST /authorize/token` (cache token 1h);
  emitere = `POST /docs/invoice` cu `collect` inline (tip „Card"/„Ordin de plata" → factura iese deja încasată) +
  `idempotencyKey`; mapează `data.{seriesName,number,link}` → `EInvoiceIssueResult`; trimitere SPV explicită
  `POST /docs/einvoice` (NU ne bazăm pe preferința auto); status `GET /docs/einvoice` (cod -1/0/1/2);
  storno = factură nouă cu `referenceDocument`+`refund:1` (corecție reală), `PUT /docs/invoice/cancel` doar
  pre-SPV; PDF din `link`. Scutire TVA (furnizor neplătitor): `vatPercentage:0` + `vatName:"SFDD"`.
- **`SmartBillEInvoicingProvider`** (confidență medie, căi exacte de confirmat la implementare): Basic auth,
  `POST /invoice` (+ marcare plată), `GET /invoice/pdf` (bytes), trimitere SPV + status (polling), cancel/storno.
  Notă: SmartBill suportă `isDraft:true` (Oblio NU prin API).
- **Serii**: `seriesName` trebuie să PRE-EXISTE în contul providerului (nu se creează prin API); validăm/listăm via
  `GET /nomenclature/series` (Oblio); config-ul referă o serie reală.
- Selecție DI prin resolver + `AppSetting` (P1); stub exclus în prod.
- `FiscalInvoiceService::issueForPaidInvoice(Invoice)` **idempotent**: return-early dacă `FiscalInvoice` are deja
  `providerInvoiceId`; pasează `idempotencyKey` la provider. + `IssueFiscalInvoiceMessage` + handler async
  (pattern `ExtractDataMessage`), dispatch din `InvoicingService::markPaid()`.
- Poarta date fiscale: `User::hasCompleteFiscalData()` cu **CIF obligatoriu la AVOCAT/PJ** (fără ONRC); blocare la
  checkout înainte de `markPaid`, redirect la profil.
- **Pre-condiție operațională** (nu cod): contul providerului trebuie să aibă modulul e-Factura activ + token SPV.
- Teste cu `MockHttpClient` + fixturi răspuns Oblio/SmartBill (succes + 4xx business + 5xx retry). Zero rețea reală.

### P3 — UI + ingestie status + storno
**Scop:** produs vizibil end-to-end: listă, download, status e-Factura, storno.

- `FiscalInvoiceController` (`/subscription/fiscal-invoices`): `index` (listă), `show`/redirect, `download`
  (proxy/redirect la PDF), `FiscalInvoiceVoter` (ownership/admin).
- **Sincronizare status SPV = polling la AMBII** (webhook-urile Oblio NU acoperă statusul e-Factura; topicurile
  sunt `Invoice/SaveDraft|Update|Cancel`, `Collect/Inserted`, `stock`). Comandă cron `app:einvoice:sync-status`
  care interoghează facturile în tranzit (`GET /docs/einvoice` la Oblio, status la SmartBill), staggered ca
  `app:portal-check-all`, → `applyEInvoiceStatus(FiscalInvoice, status)`. (Webhook Oblio = opțional, doar pentru
  anulări/editări făcute în UI-ul providerului.)
- **Reconciliere**: aceeași comandă (sau una dedicată) reîncearcă facturile **plătite dar neemise** (provider
  căzut la momentul plății; `FiscalInvoice` în ERROR / fără `providerInvoiceId`).
- Storno: buton (CSRF) → `FiscalInvoiceService::storno()` → `provider->storno()` (factură de corecție reală,
  `referenceDocument`+`refund` la Oblio); admin-only (act fiscal).
- **Toggle provider în admin:** UI EasyAdmin pentru `AppSetting` (selectează providerul activ: oblio/smartbill/stub),
  cu indicator al providerului curent. Flip instant, fără restart.
- Templates `templates/fiscal_invoice/`, i18n RO/EN lockstep (fără em-dash), badge `EInvoiceStatus`.
- Actualizează `templates/subscription/invoices.html.twig` (link facturi fiscale; disclaimer doar pe legacy).

---

## 7. Decizii deschise

| # | Subiect | Default / direcție | Necesită |
|---|---------|--------------------|----------|
| 1 | Provider activ (Oblio vs SmartBill) | Comutabil live din admin (toggle); default Oblio (webhooks, rate-limit publicat, cont test gratuit). Ambele adaptoare coexistă | decizie produs |
| 2 | Plătitor TVA + date reale furnizor (CUI/IBAN LexRecovery) | placeholder acum; dacă neplătitor → `vatPercentage:0` + `vatName:"SFDD"` (scutit) | consultant fiscal |
| 3 | Identificare avocat/cabinet (CIF obligatoriu, fără ONRC; date obligatorii per formă) | CIF + denumire la AVOCAT/PJ | consultant fiscal |
| 4 | `VatCalculator` | păstrat doar ca helper afișare preț+TVA la checkout | decizie tehnică |
| 5 | PDF (URL Oblio vs bytes SmartBill) | uniformizat prin `EInvoiceIssueResult` | decizie tehnică |

---

## 8. Verificare end-to-end

- `./bin/phpunit` (stub + `MockHttpClient`), `doctrine:schema:validate`, `lint:container`, `lint:yaml translations/`.
- Live (dev, provider=stub din toggle/env): register → completează CIF cabinet → subscribe → checkout (mark paid) →
  worker emite prin stub → `/subscription/fiscal-invoices` arată serie/număr/total + download + status.
- **Strategie testare provider real (niciunul NU are sandbox izolat):** (1) `stub` in-app = zero rețea, default dev;
  (2) **cont Oblio gratuit SEPARAT de test** = integrare reală izolată de firma reală (recomandat, pentru că Oblio
  **NU poate crea drafts prin API** — orice emitere pe contul real e factură reală); (3) contul real doar cu serie
  de test + auto-SPV OPRIT + cancel/delete după. SmartBill: poate `isDraft:true` + serie test.
  ⚠️ „SmartBill sandbox" găsit pe `docs.smartbills.io` = ALT produs, irelevant.
- ⚠️ Pe cont real, o factură finalizată cu auto-SPV activ pleacă la ANAF (greu reversibil). Credențiale în
  `.env.local`, NU în chat.
- Reviews finale: `lexrecovery-legal-reviewer` (CIF avocat, TVA, e-Factura) +
  `lexrecovery-code-reviewer` (HttpClient, OAuth cache, normalizare DTO, async, voter).

---

## 9. Rezumat ordine de livrare

```
P1 oglindă slim + interfață + stub      → fundație + flux complet pe stub
P2 adaptoare Oblio/SmartBill + async     → emitere reală la plată
P3 UI + status (webhook/poll) + storno   → produs vizibil end-to-end
```
