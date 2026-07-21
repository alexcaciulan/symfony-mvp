# Plan: schimbarea planului de abonament (upgrade / downgrade)

Data: 2026-07-21
Stare: IMPLEMENTAT (8/8 slice-uri), 53 teste noi, 1610 verzi in total, verificat live in browser
Context: audit pe modulul de abonamente, 5 agenti (4 zone paralele + sinteza)

> Abaterile fata de designul initial, decise in timpul implementarii, sunt
> consemnate la sectiunea 8.

## 1. Problema

Un utilizator care si-a ales primul plan platit (ex. Starter) nu poate trece pe alt plan.
Nu este o regresie: fluxul de schimbare a planului nu a fost implementat niciodata, iar
codul il refuza explicit.

### 1.1 Cauze, ordonate dupa cat de blocante sunt

1. **Guard in serviciu (blocant absolut).** `SubscriptionService::subscribeToPlan()` arunca
   `DomainException` daca abonamentul curent exista si nu e trial, fara sa compare deloc
   planul cerut cu cel curent (`src/Service/Billing/SubscriptionService.php:175-178`).
   Docblock-ul de la `:168-169` recunoaste amanarea. Nu exista `changePlan`, `switchPlan`,
   `upgradePlan` sau logica de proratie nicaieri in `src/`. Singura metoda cu "upgrade" in
   nume este `recommendUpgrade()` (`:294-310`), explicit read-only.

2. **UI-ul nu randeaza nimic.** `SubscriptionController.php:56` calculeaza
   `can_subscribe = null === $current || $current->getPlan()->isTrial()`, iar
   `templates/subscription/index.html.twig:53-82` conditioneaza intreaga sectiune de planuri
   (titlu, grila, formulare) pe acest flag. Pe un plan platit, grila dispare din DOM.
   Bannerul de nudge (`:36-40`) este text pur, fara buton.

3. **Mesajul confirma lipsa functiei.** Flash `subscription.flash.already_subscribed`:
   "Ai deja un abonament activ. Schimbarea planului va fi disponibila in curand."
   (`translations/messages.ro.yaml:1618`).

### 1.2 Agravante descoperite in audit

- **Nu exista workaround.** `SubscriptionRepository::findCurrentForUser()` (`:58-75`) considera
  "curent" si un abonament `CANCELED` cat timp `currentPeriodEnd >= now`. Iar
  `cancelSubscription()` (`SubscriptionService.php:218`) nu are nicio ruta: e apelat exclusiv
  din test (`tests/Service/Billing/SubscriptionServiceTest.php:310`). Utilizatorul e blocat
  pana la o luna, fara buton de anulare, desi textul de disclosure recurent promite
  "poti anula oricand din contul tau".

- **Abonament `ACTIVE` inainte de plata.** `subscribeToPlan` seteaza `ACTIVE` si emite factura
  PENDING (`:186-198`), redirectul la gateway vine abia la `Controller:117-125`. Un checkout
  abandonat lasa un abonament neplatit care blocheaza orice alt plan, la nesfarsit: nimic nu
  expira abonamentele cu factura PENDING (`markPastDue` e apelat doar din cronul de reinnoire).

- **Checkout pe factura gresita.** `SubscriptionController.php:108-111` ignora factura returnata
  de serviciu si re-interogheaza toate facturile PENDING de tip SUBSCRIPTION, luand `[0]`,
  ordonate dupa `createdAt DESC` cu granularitate de o secunda. Intr-un flux de schimbare de
  plan, unde exista aproape sigur o factura PENDING anterioara, ordinea e nedeterminista:
  utilizatorul poate fi trimis sa plateasca suma gresita.

- **Nedeterminism in repository.** `findCurrentForUser` ordoneaza doar dupa `currentPeriodEnd DESC`
  fara tie-break pe id; `findActiveForUser` (`:19-29`) nu are `orderBy` deloc. Cu abonamente
  suprapuse, consumul de sloturi si facturarea pot alege randuri diferite.

- **Efect economic invers intentiei.** Pe Starter (99 RON / 5 dosare / 25 RON extra), din al
  9-lea dosar lunar utilizatorul se apropie de pretul Pro (299 RON / 25 dosare / 15 RON extra),
  iar din al 13-lea il depaseste, in timp ce `recommendUpgrade()` ii arata un banner care nu
  duce nicaieri.

### 1.3 Ce NU este cauza

Modelul de date. `Subscription::$user` este `ManyToOne` (`src/Entity/Subscription.php:19-21`),
migrarea creeaza `INDEX` pe `user_id`, nu `UNIQUE`. Schema suporta deja mai multe abonamente
per utilizator. Problema nu e specifica planului Starter: apare identic la orice prim plan platit.

## 2. Decizii luate

| Nr | Decizie | Ales |
|----|---------|------|
| D1 | Politica de pret la upgrade | **Fara proratie.** Se factureaza pretul integral al planului nou si perioada reporneste de la zero |
| D2 | Momentul aplicarii upgrade-ului | **Doar dupa `markPaid`.** Planul nu se schimba pana cand plata nu e confirmata |
| D3 | Contorul `casesConsumed` la upgrade | **Se reseteaza la 0.** Perioada de facturare noua platita integral inseamna cota noua integrala |
| D4 | Downgrade | **La finalul perioadei**, prin `pendingPlan` aplicat la reinnoire |
| D5 | Facturi `CASE_EXTRA` deja emise pe planul vechi | **Nu se storneaza.** Serviciul a fost consumat, storno-ul e act fiscal manual |
| D6 | Scope suplimentar in acest lot | Ruta de anulare, fix checkout pe factura corecta, expirare abonamente neplatite, determinism repository |

### 2.1 Consecinta lui D1, asumata explicit

Exemplu: Starter platit pentru 1-31 iulie (99 RON), upgrade cerut pe 16 iulie.

```
Factura emisa   : PLAN_CHANGE, 299.00 RON integral
Se aplica       : la markPaid
Perioada noua   : 16 iulie -> 16 august (repornita)
casesConsumed   : resetat la 0/25
Reinnoire       : 16 august, 299 RON (data de facturare s-a mutat definitiv)
Platit in iulie : 99 + 299 = 398 RON
```

Intervalul 16-31 iulie este platit de doua ori. Este acceptat constient in favoarea
simplitatii de implementare (fara `ProrationCalculator`, fara `periodStart`/`periodEnd` pe
`Invoice`, fara credit balance). Daca apare presiune comerciala, proratia se poate adauga
ulterior ca slice separat, fara sa rescrie `changePlan`: se schimba doar suma facturii si
faptul ca perioada nu mai reporneste.

### 2.2 Criteriul upgrade vs downgrade

`Plan` nu are camp de rang sau `sortOrder` (`src/Entity/Plan.php:15-40`). Folosim **pretul lunar**,
nu `includedCases`:

- `newPlan.priceMonthly > currentPlan.priceMonthly` => upgrade, se factureaza si se aplica la plata
- `newPlan.priceMonthly < currentPlan.priceMonthly` => downgrade, se programeaza pe `pendingPlan`
- egal => schimbare programata, fara factura

Motivatia: decizia "cine plateste acum" trebuie sa depinda de bani, nu de o dimensiune
corelata. Daca in viitor apar planuri care difera pe alte dimensiuni la acelasi pret, se
introduce un camp `tier` explicit pe `Plan` inainte de a le adauga.

## 3. Design

### 3.1 Constrangerea de arhitectura care decide totul

`markPaid()` (`src/Service/Billing/InvoicingService.php:100`) este singurul punct de decontare
pentru toate cele trei cai: webhook (`ProcessPaymentWebhookMessageHandler.php:77`),
reconciliere (`PaymentReconciliationService.php:63`) si plata manuala
(`SubscriptionController.php:203`). Ancorarea aplicarii planului in `markPaid` acopera automat
toate scenariile, inclusiv IPN pierdut recuperat de cron.

Dar `SubscriptionService` injecteaza deja `InvoicingService`, deci nu putem injecta invers fara
ciclu de dependente. Solutia: un serviciu nou, fara dependente catre niciunul dintre ele.

```
SubscriptionService ──> InvoicingService ──> PlanChangeApplier
        │                                          ▲
        └──────────────────────────────────────────┘
```

`PlanChangeApplier` depinde doar de `AuditLogService`. Nu are nevoie nici macar de entity
manager: `AuditLogService::log()` doar persista, iar flush-ul apartine tranzactiei apelantului.

### 3.2 Intentia de upgrade traieste pe factura, nu pe abonament

Pentru ca aplicarea se face la `markPaid`, iar `markPaid` primeste un `Invoice`, planul tinta
se stocheaza pe factura. Downgrade-ul, care nu are factura, foloseste `pendingPlan` pe abonament.
Cele doua mecanisme sunt distincte si nu se confunda.

### 3.3 Modificari de model

`src/Entity/Invoice.php`:

| Camp | Tip | Rol |
|---|---|---|
| `targetPlan` | `ManyToOne(Plan::class)`, nullable | planul care se aplica la decontarea acestei facturi |

`src/Entity/Subscription.php`:

| Camp | Tip | Rol |
|---|---|---|
| `pendingPlan` | `ManyToOne(Plan::class)`, nullable | planul programat pentru perioada urmatoare (downgrade) |
| `planChangedAt` | `datetime_immutable`, nullable | momentul ultimei schimbari efective de plan |

`src/Enum/InvoiceType.php`: caz nou `PLAN_CHANGE = 'plan_change'` cu `label()`, plus cheile de
traducere `enum.invoice_type.plan_change` in `ro` si `en`. Necesar pentru ca filtrul
`type = SUBSCRIPTION` folosit azi sa nu amestece facturile de schimbare cu cele de reinnoire.

`src/Enum/PlanChangeOutcome.php` (nou, backed string enum cu `label()`, in linie cu
`SubscriptionSlotConsumptionOutcome`): `UPGRADE_PENDING_PAYMENT`, `DOWNGRADE_SCHEDULED`, `NO_CHANGE`.

`src/DTO/Billing/PlanChangeResult.php` (nou):

```php
final readonly class PlanChangeResult
{
    public function __construct(
        public PlanChangeOutcome $outcome,
        public Subscription $subscription,
        public ?Invoice $invoice = null,      // doar la upgrade, de platit
        public ?Plan $scheduledPlan = null,   // doar la downgrade
    ) {}
}
```

Toate coloanele sunt nullable, deci migrarea nu are nevoie de backfill.

### 3.4 Serviciu nou: `PlanChangeApplier`

```php
/**
 * Applies a paid plan change to its subscription. Called from the single
 * settlement point (InvoicingService::markPaid) so every path (webhook,
 * reconciliation, manual) applies the change exactly once.
 */
public function applyPaidPlanChange(Invoice $invoice): void
```

Comportament: no-op daca `type !== PLAN_CHANGE`, daca `targetPlan` e null sau daca
`subscription` e null. Altfel, pe abonament: `setPlan(target)`, `setStatus(ACTIVE)`,
`setCurrentPeriodStart(now)`, `setCurrentPeriodEnd(now + 1 luna)`, `setCasesConsumed(0)`,
`setPendingPlan(null)` (un upgrade platit anuleaza un downgrade programat),
`setPlanChangedAt(now)`. Scrie audit `subscription_plan_changed`.

Se apeleaza **din interiorul tranzactiei** lui `markPaid`, imediat dupa `$locked->markPaid()`,
inainte de flush. Astfel decontarea si schimbarea planului sunt atomice: nu exista stare in
care factura e PAID dar planul a ramas cel vechi.

Constanta `SUBSCRIPTION_PERIOD` se muta din `SubscriptionService` intr-un loc partajat
(sau applier-ul o primeste ca parametru), ca sa nu fie duplicata.

### 3.5 Metoda noua in `SubscriptionService`

```php
/**
 * Requests a move to another paid plan. An upgrade issues a full-price invoice
 * and only takes effect once that invoice is settled; a downgrade is scheduled
 * for the next renewal.
 *
 * @throws \DomainException when the subscription is a trial, when the target
 *                          plan is inactive or a trial, or when it is the
 *                          current plan
 */
public function changePlan(Subscription $subscription, Plan $newPlan): PlanChangeResult
```

`subscribeToPlan` si guardul de la `:175-178` raman **neatinse**: continua sa insemne strict
"primul abonament platit / conversie din trial". Testul existent
`testSubscribeToPlanRejectsWhenAlreadyPaid` ramane valid.

Reguli suplimentare in `changePlan`:

- Daca exista deja o factura `PLAN_CHANGE` PENDING pe acest abonament, se marcheaza `CANCELED`
  inainte de a emite una noua. `InvoiceStatus::CANCELED` exista in enum
  (`src/Enum/InvoiceStatus.php:11`) dar nu e setat nicaieri azi; aceasta e prima utilizare.
- La downgrade se seteaza `pendingPlan` si nu se emite nicio factura.
- Metoda noua in `InvoicingService`: `createPlanChangeInvoice(Subscription $s, Plan $target): Invoice`,
  cu `type = PLAN_CHANGE`, `amount = target.priceMonthly`, `targetPlan = target`.

### 3.6 Aplicarea downgrade-ului la reinnoire

In `SubscriptionService::renewSubscription()` (`:248-272`), `pendingPlan` se aplica **inainte**
de `createSubscriptionInvoice`, altfel factura iese pe pretul vechi:

```php
if (null !== ($pending = $subscription->getPendingPlan())) {
    $subscription->setPlan($pending)->setPendingPlan(null)->setPlanChangedAt($now);
}
$invoice = $this->invoicing->createSubscriptionInvoice($subscription);
```

### 3.7 Tokenul recurent Netopia: nimic de facut

Tokenul ramane pe acelasi rand `Subscription` (`src/Entity/Subscription.php:47-56`), nu se
atinge si nu se re-autorizeaza. Tokenul nu poarta suma: fiecare incasare e un start de plata
nou cu `scaExemptionInd = 'MIT'` (`src/Service/Billing/Netopia/NetopiaApiClient.php:289-304`),
iar suma se recalculeaza din `plan.priceMonthly` la crearea facturii de reinnoire
(`InvoicingService.php:49`). Acesta este argumentul principal pentru a schimba planul pe
abonamentul existent in loc sa cream unul nou.

### 3.8 De ce NU cream un abonament nou

Varianta naiva (ridicam guardul si lasam `subscribeToPlan` sa creeze un al doilea abonament)
produce, conform auditului:

- **Dubla taxare lunara**: `findDueForRenewal` (`SubscriptionRepository.php:39-50`) filtreaza
  pe `status = ACTIVE` + token, fara unicitate per utilizator. Doua abonamente cu token egal
  doua incasari off-session pe luna.
- **Doua facturi fiscale reale**, corectabile doar prin storno manual din EasyAdmin.
- **Abonament nou fara token**, deci exclus din `findDueForRenewal`: nu devine `PAST_DUE`, nu
  declanseaza dunning, expira in tacere.
- **Token pierdut definitiv** daca upgrade-ul trece prin `cancelSubscription()`, care sterge
  `recurringToken`, `recurringTokenExpiresAt` si `cardMask` din motive GDPR (`:224-226`).

## 4. Fluxuri rezultate

### 4.1 Upgrade (Starter -> Pro)

1. Utilizatorul apasa "Treci la Pro" pe `/subscription`.
2. `changePlan` valideaza, anuleaza eventuala factura `PLAN_CHANGE` PENDING anterioara,
   emite factura `PLAN_CHANGE` 299.00 PENDING cu `targetPlan = Pro`. **Abonamentul ramane Starter.**
3. Redirect la checkout **cu factura returnata de serviciu** (nu re-interogata).
4. Utilizatorul plateste. Webhook -> `markPaid` -> in aceeasi tranzactie
   `PlanChangeApplier::applyPaidPlanChange` muta abonamentul pe Pro, reporneste perioada,
   reseteaza contorul.
5. Post-commit: factura fiscala asincrona pe 299.00 si notificarea de plata reusita, prin
   mecanismul existent.

Daca utilizatorul abandoneaza checkout-ul, ramane pe Starter cu o factura PENDING vizibila,
pe care o poate plati mai tarziu sau anula. Nu se pierde nimic.

### 4.2 Downgrade (Pro -> Starter)

1. `changePlan` seteaza `pendingPlan = Starter`. Nicio factura.
2. UI afiseaza "Planul Starter intra in vigoare la <data>", cu buton de renuntare
   (`setPendingPlan(null)`).
3. La reinnoire, cronul aplica `pendingPlan` si emite factura pe 99.00.

Motivatia pentru finalul perioadei: evita rambursari si storno fiscal, si evita situatia in
care `casesConsumed` depaseste noul `includedCases`, ceea ce ar transforma dosare deja
activate in datorie de overage.

## 5. Plan de implementare pe slice-uri

**Slice 1: model de date.**
`src/Entity/Invoice.php` (+`targetPlan`), `src/Entity/Subscription.php` (+`pendingPlan`,
`+planChangedAt`), `src/Enum/InvoiceType.php` (+`PLAN_CHANGE`),
`src/Enum/PlanChangeOutcome.php` (nou), traduceri pentru etichetele noi, migrare.
Verificare: `doctrine:schema:validate` + teste de entitate.

**Slice 2: `PlanChangeApplier` + hook in `markPaid`.**
`src/Service/Billing/PlanChangeApplier.php` (nou),
`src/Service/Billing/InvoicingService.php` (apel in tranzactia din `markPaid`
+ `createPlanChangeInvoice`). Testabil izolat, fara UI.

**Slice 3: `changePlan` in serviciu.**
`src/Service/Billing/SubscriptionService.php` (metoda noua + aplicarea `pendingPlan` in
`renewSubscription` + `cancelScheduledPlanChange`), `src/DTO/Billing/PlanChangeResult.php`.

**Slice 4: ruta + controller.**
`src/Controller/SubscriptionController.php`: actiune `changePlan` (CSRF, rate limiter, gate de
date fiscale, redirect la checkout **cu factura returnata direct de serviciu**), actiune
`cancelScheduledPlanChange`, adnotarea planurilor in `index()` (`is_current`, `is_upgrade`,
`is_downgrade`, `pending_change`), eliminarea lui `can_subscribe`.
Include fixul de la `:108-111`.

**Slice 5: UI.**
`templates/subscription/index.html.twig`: grila se randeaza mereu; planul curent cu badge si
buton dezactivat; planurile superioare cu "Treci la %plan%" plus mentiunea explicita ca se
factureaza pretul integral si perioada reporneste; cele inferioare cu "Treci la %plan% de la
<data>"; bannerul de nudge devine CTA real; card pentru schimbarea in asteptarea platii cu
butoane plateste / renunta. Chei noi in `ro` si `en`. Rulare `make tailwind`.

**Slice 6: anulare abonament.**
Ruta `app_subscription_cancel` + buton + confirmare + traduceri, expunand `cancelSubscription()`
existent. Inchide promisiunea neonorata din `subscription.plans.recurring_disclosure`.

**Slice 7: igiena facturare.**
Comanda de expirare a abonamentelor `ACTIVE` cu factura `SUBSCRIPTION` PENDING mai veche de N
zile (marcheaza abonamentul si anuleaza facturile lui deschise).

Limitare cunoscuta, asumata: comanda selecteaza doar abonamente care **nu au avut niciodata** o
factura platita, deci nu atinge un client existent care abandoneaza un checkout de upgrade.
Pentru acel caz, curatarea vine din alta parte: `renewSubscription` anuleaza orice factura
`PLAN_CHANGE` ramasa PENDING la rostogolirea perioadei (vezi 8.8), deci factura invechita
dispare cel tarziu la urmatoarea reinnoire.

**Slice 8: determinism repository.**
`findCurrentForUser` cu `addOrderBy('s.id', 'DESC')`, `findActiveForUser` cu `orderBy` explicit.

Verificare live in browser dupa slice 5 si slice 6, conform conventiei de proiect.

## 6. Teste

**Unit (`TestCase`)**
- `tests/Enum/PlanChangeOutcomeTest.php`: `testAllCasesHaveLabels`
- `tests/Enum/InvoiceTypeTest.php` (extindere): `testPlanChangeHasLabel`
- `tests/Entity/SubscriptionEntityTest.php`: `testPendingPlanDefaultsToNull`, `testSetPendingPlanIsFluent`
- `tests/Entity/InvoiceEntityTest.php`: `testTargetPlanDefaultsToNull`

**Service (`KernelTestCase`)**
- `tests/Service/Billing/PlanChangeApplierTest.php`: `testAppliesTargetPlanToSubscription`,
  `testRestartsBillingPeriodFromNow`, `testResetsCasesConsumedToZero`,
  `testClearsPendingPlan`, `testIgnoresInvoicesWithoutTargetPlan`,
  `testIgnoresNonPlanChangeInvoiceTypes`
- `tests/Service/Billing/SubscriptionServiceChangePlanTest.php`:
  `testUpgradeDoesNotChangePlanBeforePayment`,
  `testUpgradeIssuesFullPricePlanChangeInvoice`,
  `testUpgradeAppliesPlanOnceInvoiceIsPaid`,
  `testUpgradeKeepsRecurringTokenAndCardMask`,
  `testUpgradeDoesNotCreateSecondSubscriptionRow`,
  `testSecondUpgradeRequestCancelsThePreviousPendingInvoice`,
  `testDowngradeSchedulesPendingPlanWithoutInvoice`,
  `testRenewalAppliesPendingPlanAndInvoicesNewPrice`,
  `testPaidUpgradeClearsScheduledDowngrade`,
  `testChangePlanRejectsTrialSubscription`,
  `testChangePlanRejectsInactiveOrTrialTargetPlan`,
  `testChangePlanToSamePlanIsNoChange`,
  `testChangePlanWritesAuditLog`
- `tests/Repository/SubscriptionRepositoryTest.php`:
  `testFindCurrentForUserIsDeterministicWithOverlappingSubscriptions`,
  `testFindDueForRenewalReturnsAtMostOneRowPerUserAfterPlanChange`
- `tests/Service/Billing/SubscriptionRenewalServiceTest.php` (extindere):
  `testRenewalChargesNewPlanPriceAfterUpgrade`

**Controller (`WebTestCase`)**
- `tests/Controller/SubscriptionControllerTest.php` (extindere):
  `testIndexRendersPlansForPaidSubscriber`,
  `testIndexMarksCurrentPlanAsCurrent`,
  `testChangePlanRedirectsToCheckoutForUpgrade`,
  `testCheckoutUsesTheInvoiceCreatedByThePlanChange`,
  `testChangePlanSchedulesDowngradeAndRedirectsBack`,
  `testChangePlanRequiresValidCsrfToken`,
  `testChangePlanRequiresCompleteFiscalData`,
  `testUpgradeNudgeRendersActionableCta`,
  `testCancelSubscriptionRouteCancelsAndRedirects`

## 8. Abateri fata de design, decise la implementare

1. **`InvoiceType::PLAN_CHANGE`, nu `PLAN_UPGRADE`.** Tipul acopera orice schimbare facturabila,
   nu doar upgrade-ul, iar `Invoice::$targetPlan` este singurul camp adaugat pe factura.
   Campurile `periodStart` / `periodEnd` din designul initial au picat odata cu proratia (D1):
   fara calcul pe zile nu mai exista interval de justificat.

2. **`PlanChangeOutcome::CHANGE_SCHEDULED` in loc de `NO_CHANGE`.** Planul identic este respins
   cu `DomainException`, deci `NO_CHANGE` nu avea cum sa apara. Cazul ramas, doua planuri diferite
   la acelasi pret, se programeaza ca un downgrade.

3. **Comparatia de pret se face in bani intregi**, ca in `VatCalculator`, nu cu `bccomp`:
   extensia `bcmath` nu este instalata in imagine. Aceeasi formula in serviciu si in controller,
   ca eticheta de pe card sa nu poata diverge de suma efectiv facturata.

4. **`SubscriptionService::SUBSCRIPTION_PERIOD` a devenit publica** ca `PlanChangeApplier` sa o
   citeasca fara sa o duplice. Referinta la constanta de clasa nu introduce dependenta DI, deci
   nu reapare ciclul.

5. **`InvoiceRepository::findPendingByType(Subscription, InvoiceType)`, generic.** Aceeasi metoda
   deserveste si `subscribe()`, deci fixul de determinism acopera acum si fluxul vechi de prima
   abonare, nu doar schimbarea de plan.

6. **Predicatul de expirare a fost restrans dupa prima rulare.** Varianta initiala („niciun
   invoice PAID") a eliberat doua abonamente seed in baza de dev, pentru ca datele semanate nu
   au deloc facturi. Regula finala cere si existenta unei facturi PENDING, adica un checkout
   chiar inceput: `findAbandonedCheckoutsCreatedBefore`. Prins doar la rulare, nu la citirea codului.

7. **Slice 6 a intrat impreuna cu 4 si 5**, fiind acelasi controller si acelasi template.

8. **Reinnoirea anuleaza o factura de upgrade neplatita** (`renewSubscription`). Prins de review,
   nu de design. Fara asta: utilizatorul cere upgrade la finalul perioadei, abandoneaza
   checkout-ul, cronul reinnoieste pe planul vechi si rostogoleste perioada, iar o decontare
   ulterioara a facturii de upgrade ar fi taxat a doua oara si ar fi suprascris perioada si
   contorul tocmai setate. O oferta de upgrade neplatita moare odata cu perioada in care a fost
   emisa; utilizatorul o poate cere din nou.

9. **Facturile PENDING se citesc cu lock pesimist inainte de a fi anulate**
   (`findPendingByType(..., forUpdate: true)`), simetric cu `markPaid`. Altfel o decontare care
   commita intre citire si scriere ar fi fost suprascrisa tacut: factura platita marcata
   `CANCELED`, cu factura fiscala deja emisa. Lock-ul e opt-in fiindca apelantul read-only din
   controller ruleaza in afara unei tranzactii, unde Doctrine il refuza.

10. **`FiscalInvoiceFactory` avea doua `match` neexhaustive.** Adaugarea cazului `PLAN_CHANGE` in
    `InvoiceType` le-a spart: `buildDraft()` arunca `UnhandledMatchError` inainte ca vreun rand
    `FiscalInvoice` sa fie persistat, iar `markError()` si cronul de reconciliere cauta ambele un
    rand existent, deci nu exista recuperare. Bani incasati, nicio factura fiscala. `PLAN_CHANGE`
    foloseste seria de abonament (economic e tot o taxa de abonament) si o cheie de traducere
    proprie bazata pe `targetPlan`. Testul `testEveryInvoiceTypeCanBuildADraft` itereaza
    `InvoiceType::cases()` ca urmatorul caz adaugat sa nu mai poata trece nedetectat.

11. **Confirmarea de anulare foloseste controller-ul Stimulus `confirm`**, nu `onsubmit` inline:
    `script-src` nu are `unsafe-inline`, iar nonce-ul nu acopera atributele de eveniment, deci
    handler-ul inline nu ar fi rulat niciodata in productie.

12. **Comparatia de pret s-a mutat pe `Plan::comparePriceTo()`**, ca serviciul si cardurile din UI
    sa nu poata diverge. `is_downgrade` a fost scos din adnotare: nu il citea nimeni in template.

### Verificare live (Playwright, cont pe Starter)

- Grila de planuri se randeaza pe plan platit; Starter marcat „Planul tau" cu buton dezactivat,
  Pro cu „Treci la Pro" si mentiunea de pret integral plus repornirea perioadei.
- „Treci la Pro" ajunge la checkout-ul Netopia sandbox, deci `form-action` din CSP acopera si
  ruta noua. In DB: factura `plan_change` 299.00 PENDING, `target_plan_id = 2`, `external_id`
  salvat, abonamentul **neschimbat** pe Starter cu `cases_consumed = 1`.
- La revenire, bannerul „schimbare in asteptarea platii" cu buton „Plateste acum".
- Downgrade Pro -> Starter: banner „Planul Starter intra in vigoare la ...", badge „Programat"
  pe card, buton „Renunta la schimbare", zero facturi emise, iar factura `plan_change` anterioara
  a trecut pe `canceled` (prima utilizare reala a lui `InvoiceStatus::CANCELED`).

## 9. Ramas deschis

- **Camp `tier` pe `Plan`**: necesar daca apar planuri care difera pe alte dimensiuni decat
  pretul. Azi criteriul pe pret (2.2) este suficient.
- **Proratie**: exclusa prin D1. Se poate adauga ulterior ca slice separat fara a rescrie
  `changePlan`.
- **Notificare dedicata pentru schimbarea planului**: azi se refoloseste notificarea de plata
  reusita. De evaluat impreuna cu planul de notificari in-app.
