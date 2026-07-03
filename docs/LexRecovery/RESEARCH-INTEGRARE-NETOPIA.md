# Research: integrare plăți Netopia în LexRecovery

Data: 2026-07-01
Status: research/analiză (nicio linie de cod modificată)
Scop: evaluează ce implică integrarea reală a plăților cu Netopia, dacă implementarea e clară, ce constrângeri și situații de risc apar.

---

## 1. Concluzie pe scurt

Implementarea este clară din punct de vedere tehnic. Infrastructura internă a fost proiectată explicit pentru acest swap: există interfața `PaymentGatewayInterface` cu un singur implementor stub, DTO-uri pregătite (`CheckoutSession`, `WebhookResult`), câmpuri `externalId` pe `Invoice` și `Subscription`, iar `InvoicingService::markPaid($invoice, $externalRef)` acceptă deja referința externă. Controllerele nu trebuie rescrise structural.

Blocajele reale nu sunt de cod, ci de business și de decizii de produs:

1. Onboarding-ul Netopia (KYC, contract, cont bancar, activare POS) este un proces cu terți care durează. Nu poate fi paralelizat complet cu dezvoltarea.
2. Trebuie decis modelul de plată recurentă: reînnoire automată cu token salvat vs. plată manuală lunară prin redirect. Aceasta schimbă semnificativ scope-ul.
3. Confirmarea plății se mută din acțiune manuală (`pay()`) în webhook (IPN) asincron. Apar cerințe noi: idempotență, verificare semnătură, rută publică fără auth, reconciliere.

Estimare efort pur tehnic (fără onboarding): moderată. Vezi §8.

---

## 2. Ce înseamnă integrarea Netopia (tehnic)

Netopia (fost mobilPay) oferă mai multe versiuni de API:

- API v2.x (recomandat pentru implementări noi): endpoint-uri JSON, securizate cu API token + POS signature. SDK oficial PHP: `composer require netopia/payment2`.
- API v1.x (legacy): criptare cu certificat X509 / chei RSA (rc4 sau aes-256-cbc), IPN cu `env_key`/`data`/`cipher`/`iv`. Recomandat DOAR pentru compatibilitate cu integrări vechi.

Recomandare: API v2 cu SDK-ul oficial `netopia/payment2`. Se aliniază cu abordarea „interface + implementor" deja existentă în cod și evită gestiunea manuală a certificatelor.

### Credențiale necesare (emise după activarea contului)

- POS Signature (identificator comerciant, format `XXXX-XXXX-XXXX-XXXX-XXXX`)
- API Key (generat din contul Netopia)
- Flag environment (sandbox `false` / live `true`)

### Fluxul de plată v2 (hosted payment page)

1. Aplicația construiește un request cu: `posSignature`, `apiKey`, `notifyUrl` (URL-ul nostru de IPN), `redirectUrl` (unde revine userul), plus datele comenzii (`orderID`, `amount`, `currency`, `description`) și datele de facturare (email, telefon, nume, adresă) și liniile de produs.
2. Se apelează `startPayment()`. Netopia returnează un URL de plată + status.
3. Userul este redirectat la pagina Netopia, introduce cardul, trece prin 3D Secure (obligatoriu SCA în UE).
4. Netopia trimite un IPN (POST) pe `notifyUrl` la fiecare schimbare de status. Aici confirmăm plata.
5. Userul e redirectat înapoi pe `redirectUrl` (dar aceasta e doar UX; sursa de adevăr e IPN-ul, nu redirectul).

### Status-uri plată relevante

- `paid` / `confirmed`: plată reușită.
- `paid_pending` / `confirmed_pending`: în procesare (nu confirma încă).
- `canceled`: anulată.
- `credit`: refund/stornare.
- În v2 există și cod de status pentru „necesită 3D Secure" (pending autorizare).

### Plăți recurente (relevant pentru SaaS pe abonament)

Netopia suportă tokenizarea cardului: la prima plată se obține un `token_id` + `token_expiration_date` + `pan_masked`. Cu acest token se pot iniția plăți recurente sau one-click fără interacțiunea userului. Comerciantul NU stochează numărul de card (PAN), doar token-ul. Acesta e mecanismul pentru reînnoire automată lunară.

---

## 3. Cum se leagă de codul existent (mapare exactă)

Infrastructura din `src/Service/Billing/` a fost gândită pentru acest swap. Componente cheie:

| Componentă | Fișier | Rol în integrare |
|---|---|---|
| Contractul gateway | `PaymentGatewayInterface.php` | 2 metode: `startCheckout(Invoice): CheckoutSession` și `handleWebhook(Request): WebhookResult`. Comentariul citează explicit „Netopia". |
| Implementor curent | `StubPaymentGateway.php` | Nu mută bani; redirect intern spre o pagină cu buton „mark as paid". `handleWebhook()` aruncă excepție. |
| DTO checkout | `DTO/Billing/CheckoutSession.php` | `url` + `externalId` (referința gateway). |
| DTO webhook | `DTO/Billing/WebhookResult.php` | `invoiceId`, `paid`, `externalRef`. Comentariu: „shape ready for a real processor". |
| Confirmare plată | `InvoicingService::markPaid($invoice, ?$externalRef)` | Acceptă deja referința Netopia, setează `externalId`, scrie audit, declanșează factura fiscală async. |
| Câmp tranzacție | `Invoice.externalId` (string 255 nullable) | Aici se stochează transaction ID-ul Netopia. |
| Câmp abonament extern | `Subscription.externalId` (nullable) | Pregătit pentru ID de abonament recurent (momentan neutilizat). |

Punctul unde pleacă plata azi: `SubscriptionController::subscribe()` linia 106 apelează `paymentGateway->startCheckout($invoice)->url` și redirect. Acesta rămâne neschimbat: doar implementorul din spate se schimbă.

Punctul unde se „plătește" azi: `SubscriptionController::pay()` linia 152 apelează `invoicingService->markPaid($invoice)` (manual, fără bani). Acesta se mută în webhook.

Binding DI: azi interfața se rezolvă automat la `StubPaymentGateway` (autowiring, un singur implementor). Pentru Netopia se adaugă un alias în `config/services.yaml`, ideal un resolver cu toggle stub/netopia pe variabilă de mediu, exact ca pattern-ul deja folosit pentru e-invoicing (`EInvoicingProviderInterface` alias, liniile 99-100).

Model de monetizare (context): paywall-ul e la activarea dosarului (`trimite_somatie` în `CaseSummonsController::generate()`), prin `SubscriptionService::consumeCaseSlot()`. Draft-urile (AMIABIL) sunt gratuite. Integrarea Netopia NU atinge paywall-ul; atinge doar plata invoice-urilor (abonament + overage CASE_EXTRA).

Atenție la documentație învechită: `docs/TECH-STACK-MVP.md`, `docs/PLAN-DEZVOLTARE-MVP.md`, `docs/PREZENTARE-MVP.md` descriu un model VECHI, neimplementat (entitate `Payment`, `PaymentController`, `NetopiaService`, plată per-dosar a taxei judiciare). Acel model NU există în cod. Modelul real este Subscription + Invoice. A nu se folosi acele docs ca sursă pentru structura curentă.

---

## 4. Fluxul propus end-to-end (cu Netopia real)

1. User apasă „Subscribe" pe un plan → `SubscriptionController::subscribe()` → `SubscriptionService::subscribeToPlan()` creează `Subscription` ACTIVE + `Invoice` PENDING.
2. `NetopiaPaymentGateway::startCheckout($invoice)` construiește request-ul Netopia (amount = `invoice.amount`, currency RON, `orderID` = referință internă a invoice-ului, billing = datele fiscale ale userului) și returnează `CheckoutSession` cu URL-ul Netopia + `externalId`.
3. Redirect userul la pagina Netopia. 3D Secure.
4. Netopia trimite IPN pe `POST /webhook/netopia` (rută publică nouă).
5. `NetopiaPaymentGateway::handleWebhook($request)` verifică semnătura, decodează payload-ul, întoarce `WebhookResult(invoiceId, paid, externalRef)`.
6. Dacă `paid` și invoice-ul e PENDING (verificare idempotentă): `invoicingService->markPaid($invoice, $externalRef)` → status PAID + `externalId` + dispatch `IssueFiscalInvoiceMessage` (factura fiscală Oblio/SmartBill emisă async).
7. Răspundem lui Netopia cu confirmarea așteptată (format specific v2, altfel Netopia reia IPN-ul).
8. `redirectUrl` aduce userul înapoi pe o pagină „plata în procesare / confirmată" (nu confirmă nimic, doar afișează starea curentă a invoice-ului).

Pentru reînnoire recurentă (dacă se alege token): un cron lunar apelează `SubscriptionService::renewSubscription()` (există deja, „not yet wired") → emite invoice → `NetopiaPaymentGateway` face charge pe `token_id` salvat → IPN confirmă la fel ca la prima plată.

---

## 5. Constrângeri și situații de risc

### 5.1 Business / onboarding (blocant, non-tehnic)
- Cont Netopia + verificare telefon/email, apoi KYC (Know Your Customer) conform reglementărilor bancare, apoi activare POS online. Durează și depinde de terți. De pornit din timp.
- Necesită persoană juridică cu cont bancar (comerciant). Contract comercial cu Netopia + comisioane per tranzacție (impact pe pricing-ul planurilor).
- Fără POS activat nu se pot testa plăți reale; sandbox-ul se poate folosi între timp pentru dezvoltare.

### 5.2 Webhook / IPN (cea mai delicată zonă tehnică)
- Rută publică, fără autentificare: trebuie exclusă din regula `^/subscription` (roles: IS_AUTHENTICATED_FULLY) din `security.yaml:45`. De pus pe un path separat (ex. `/webhook/netopia`), NU sub `/subscription`.
- Verificarea semnăturii este obligatorie: fără ea, oricine poate marca invoice-uri ca plătite. Sursa de adevăr e IPN-ul verificat, nu redirectul de browser.
- Idempotență: Netopia poate trimite același IPN de mai multe ori. `markPaid` trebuie apelat DOAR dacă invoice-ul e încă PENDING; altfel s-ar emite factură fiscală duplicat (dispatch `IssueFiscalInvoiceMessage` la fiecare apel). Verificare `if ($invoice->getStatus() === PENDING)` înainte de markPaid.
- CSRF: rutele actuale de plată au protecție CSRF; webhook-ul NU poate avea CSRF (vine de la Netopia). De exclus explicit.
- Procesare async recomandată: IPN-ul ar trebui doar validat + pus pe coadă (`ProcessPaymentWebhookMessage` via Messenger), răspuns rapid către Netopia, procesare în worker. Reduce riscul de timeout și retry.

### 5.3 Gate-ul de date fiscale (situație subtilă)
- Azi `SubscriptionController::pay()` verifică `user->hasCompleteFiscalData()` ÎNAINTE de a marca plata (altfel redirect la profil). Webhook-ul Netopia ocolește acest gate (ajunge direct la `markPaid`). Comentariul din `IssueFiscalInvoiceMessageHandler:56-58` semnalează exact acest risc.
- Soluție: mutarea verificării datelor fiscale ÎNAINTE de `startCheckout` (nu lăsa userul să plece spre Netopia fără date fiscale complete). Astfel, când vine IPN-ul, datele există garantat.

### 5.4 Recurring vs. plată manuală (decizie de produs)
- Opțiunea A (manual): la fiecare reînnoire lunară userul primește un invoice și plătește din nou prin redirect. Simplu de implementat, dar friction mare, churn crescut.
- Opțiunea B (token recurent): salvezi `token_id` la prima plată, cron lunar face charge automat. UX mult mai bun pentru SaaS, dar necesită: stocare token (`Subscription.externalId` sau câmp nou), gestiune expirare token (`token_expiration_date`), gestiune eșec plată (card expirat/fonduri insuficiente → tranziție `PAST_DUE` → dunning/retry), conformitate SCA pentru plăți recurente. Scope semnificativ mai mare.
- Recomandare: MVP plăți = Opțiunea A (manual), apoi Opțiunea B ca fază separată. `renewSubscription()` există deja pregătit pentru ambele.

### 5.5 Refund / anulare / storno
- La `cancelSubscription()` abonamentul rămâne folosibil până la `currentPeriodEnd` (fără refund). Coerent cu absența refund-ului. Dacă se dorește refund, trebuie legat de `credit`/`canceled` din Netopia + stornare `FiscalInvoice` (există self-ref storno).
- Cazul overage (CASE_EXTRA): dacă un dosar consumat generează invoice de overage și plata eșuează, dosarul e deja activat. De decis politica (blocare retroactivă vs. datorie de recuperat).

### 5.6 Reconciliere și stări intermediare
- `paid_pending` / `confirmed_pending`: nu marca invoice-ul plătit până la status final. Invoice-ul rămâne PENDING până la IPN-ul final.
- Plăți „orfane" (userul plătește dar IPN-ul se pierde): nevoie de un mecanism de reconciliere (query status la Netopia prin API v2 pentru invoice-urile PENDING vechi, sau buton „am plătit, verifică").
- Audit: `markPaid` scrie deja audit log; de păstrat trace-ul complet al IPN-urilor pentru dispute.

### 5.7 GDPR / date card
- Nu stocăm PAN. Netopia gestionează datele cardului (PCI-DSS pe partea lor). Noi stocăm doar `externalId`, `token_id` (dacă recurent), `pan_masked` pentru afișare. De documentat în politica de confidențialitate.

### 5.8 Testare
- Sandbox Netopia (`https://secure.sandbox.netopia-payments.com/spec`) pentru dezvoltare fără bani reali.
- Teste automate: `NetopiaPaymentGateway` cu `MockHttpClient` (pattern deja folosit pentru AnthropicApiClient și e-invoicing). Webhook: fixtures cu payload IPN semnat corect + payload manipulat (semnătură invalidă) + payload duplicat (idempotență).

---

## 6. Ce trebuie făcut concret (checklist tehnic)

1. `composer require netopia/payment2` (SDK oficial v2).
2. `NetopiaPaymentGateway implements PaymentGatewayInterface` în `src/Service/Billing/`:
   - `startCheckout()`: construiește request v2, apelează Netopia, întoarce `CheckoutSession(url, externalId)`.
   - `handleWebhook()`: verifică semnătura, decodează, întoarce `WebhookResult(invoiceId, paid, externalRef)`.
3. Alias/resolver în `config/services.yaml` cu toggle `PAYMENT_GATEWAY=stub|netopia` (pattern e-invoicing).
4. Rută publică nouă `POST /webhook/netopia` (controller nou `PaymentWebhookController` sau resolver), exclusă din auth în `security.yaml`, fără CSRF.
5. Idempotență în handler: `markPaid` doar dacă invoice PENDING.
6. Mută `hasCompleteFiscalData()` înainte de `startCheckout` (nu la `pay()`).
7. Variabile `.env`: `NETOPIA_POS_SIGNATURE`, `NETOPIA_API_KEY`, `NETOPIA_ENV=sandbox|live`, `NETOPIA_NOTIFY_URL`, `NETOPIA_REDIRECT_URL` (+ `.env.local` pentru credențiale reale).
8. `pay()` manual: păstrat DOAR pe modul stub (dev/test) sau eliminat pe modul netopia.
9. Opțional (recomandat): `ProcessPaymentWebhookMessage` async în `messenger.yaml`.
10. Opțional (fază 2 recurring): stocare `token_id`, cron `renewSubscription`, dunning pe eșec plată.
11. Teste: gateway (MockHttpClient) + webhook (semnătură validă/invalidă/duplicat) + integrare flux.
12. Reconciliere: comandă/cron pentru invoice-uri PENDING vechi (query status Netopia).

---

## 7. Decizii deschise (necesită input)

1. API v2 (recomandat) confirmat? SDK `netopia/payment2`.
2. Recurring automat (token) în scope-ul de plăți sau plată manuală lunară pentru început?
3. Politică refund la anulare abonament (fără refund vs. pro-rata)?
4. Politică eșec plată overage (dosar deja activat)?
5. Onboarding Netopia: cine deschide contul comerciant și când? (blocant pentru go-live)
6. Toggle stub/netopia pe env sau eliminăm complet stub-ul?

---

## 8. Estimare efort (pur tehnic, fără onboarding)

- Gateway v2 (`startCheckout` + `handleWebhook` + semnătură): mediu.
- Webhook endpoint + securitate + idempotență + async: mediu.
- Mutare gate date fiscale + config + env + resolver: mic.
- Teste (unit + integrare): mediu.
- Reconciliere PENDING: mic-mediu.
- Recurring cu token (fază separată): mare (dunning, expirare token, SCA recurent).

MVP plăți reale (fără recurring automat): realizabil ca un pas focusat, cu condiția ca sandbox-ul Netopia să fie disponibil. Go-live-ul real depinde de onboarding-ul comerciantului (non-tehnic, de pornit imediat).

---

## Surse

- [NETOPIA Development Portal — Getting Started](https://doc.netopia-payments.com/docs/get-started/)
- [NETOPIA — Payment API](https://doc.netopia-payments.com/docs/payment-api/)
- [NETOPIA — PHP SDK](https://doc.netopia-payments.com/docs/payment-sdks/php/)
- [NETOPIA — Payment API v1.x](https://doc.netopia-payments.com/docs/payment-api/v1.x/)
- [Packagist — netopia/payment2](https://packagist.org/packages/netopia/payment2)
- [GitHub — NETOPIA Payments](https://github.com/netopiapayments)
