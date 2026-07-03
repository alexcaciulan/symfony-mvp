# Plan de dezvoltare: integrare plăți Netopia (recurring cu token)

Data: 2026-07-01 (implementat 2026-07-02)
Status: IMPLEMENTAT + review (legal + code) trecut pe branch `lexrecovery` (necomis). Rămâne de făcut la go-live: credențiale sandbox Netopia + confirmarea formatului de sârmă v2/IPN (marcat `@wire` în `NetopiaApiClient`) + E2E pe sandbox.

## Testare live pe sandbox (2026-07-02) — descoperiri wire-format

Testat end-to-end pe sandbox real (POS `lexrecovery`, tunel cloudflared, card test `9900004810225098`). Confirmate/corectate față de presupunerile `@wire`:

- START (v2 `/payment/card/start`): `amount` trebuie **număr (float)**, nu string; `order.data` trebuie obiect sau **omis** (array `[]` → 400). Modelul **hosted funcționează fără date de card** (POST fără `payment.instrument` → Netopia întoarce `payment.paymentURL` spre pagina găzduită). Zero PCI scope. **FUNCȚIONEAZĂ**: plată reală acceptată, status `PLĂTITĂ` pe Netopia.
- IPN v2: datele sunt în **body JSON** (`order.orderID`, `payment.status` unde 3/5 = plătit, `payment.ntpID`, `payment.binding` pentru token/mask). JWT-ul de semnătură e în header **`Verification-token`** (nu în body). JWT payload = `{iss:"NETOPIA Payments", aud:[posSignature], sub:base64(sha512(body)), iat}`. Verificare: semnătură + `iss` + `aud` + `sub`==hash(body). Confirmat 1:1 cu `IPN.php` din SDK-ul oficial.
- Semnătura IPN e **RS512** cu cheie **2048-bit**. `firebase/php-jwt` refuză chei <2048-bit, iar certul sandbox e 1024-bit → verificare mutată pe **`openssl_verify` direct** (allowlist RS256/384/512, merge pe orice dimensiune). `firebase/php-jwt` rămâne dependență doar pentru testele de interop.
- BLOCANT rămas (extern): **cheia publică 2048-bit a Netopia pentru IPN v2 nu e în dashboard** (certul per-POS de 1024-bit e pentru criptarea v1 `openssl_seal`, confirmat în plugin-ul lor WP). Nu e în SDK/plugin/docs/spec/endpoint. **De cerut la `implementare@netopia.ro`**. Retest fără plată nouă: butonul **NOTIFICĂ** din „Comenzi → detalii tranzacție" retrimite IPN-ul.

Cod: `NetopiaApiClient` are logica corectă implementată; când sosește cheia corectă în `NETOPIA_PUBLIC_KEY`, fluxul se închide fără altă modificare. 16 teste client + 101 teste billing verzi.

## Rezultat review (2026-07-02)

Ambele review-uri rulate (lexrecovery-legal-reviewer + lexrecovery-code-reviewer). Toate blocker-ele + warning-urile acționabile rezolvate:
- Code BLOCKER: race check-then-act la settle → `InvoicingService::markPaid()` acum atomic cu lock pesimist (`LockMode::PESSIMISTIC_WRITE`), settle-uiește o singură dată (ca `FiscalNumberingService`); dispatch factură fiscală doar pe apelul care chiar tranzacționează.
- Code BLOCKER: `strict_types` adăugat pe `ProcessPaymentWebhookMessage`; EM nefolosit scos din handler.
- Code W: `decodeIpnJwt` prinde acum orice `\Throwable` (JWT malformat → 400, nu 500); `SubscriptionRenewalService` folosește `InvoicingService::setExternalReference` (fără duplicare/EM); `.env` comentariu EN.
- Legal BLOCKER: text divulgare recurentă lângă butonul de abonare (`subscription.plans.recurring_disclosure`, RO/EN).
- Legal W: `amount` trimis ca string 2 zecimale (nu float); expirarea token nu se mai loghează în clar; audit `invoice_paid` are `source` (webhook/reconciliation/manual).
- Teste noi: `hasChargeableToken` (entity) + `InvoicingServiceMarkPaidTest` (KernelTest: settle atomic + persist token într-un singur flush + idempotență replay). Total 41 teste noi verzi. Suita: 9 erori + 1 failure IDENTIC baseline (DocumentUpload/AdminAccess preexistente, `setStatus(string)` nealiniat la enum), zero regresii.

### Follow-ups (neblocante, pre-launch)
- Consimțământ recurent FORMAL (checkbox + text stocat cu timestamp, à la `generate_op_consent_label`) + pagină Termeni și condiții: decizie de produs; acum e doar text informativ (suficient pentru informare precontractuală).
- `trusted_proxies` neconfigurat → rate limiter `payment_webhook` bucketează pe IP-ul nginx (gap preexistent app-wide, și la Registration/ResetPassword); ticket separat.
- Edge: `chargeToken` accepted cu `ntpID` null ar lăsa invoice fără externalId (nerecuperabil de reconcile); log de alertă dacă apare.
Documente conexe: `RESEARCH-INTEGRARE-NETOPIA.md`, `RESEARCH-PROCESATORI-PLATI-RO.md`

---

## Context

LexRecovery este un SaaS B2B pe abonament lunar. Astăzi „plata" este un stub: `StubPaymentGateway` redirectează spre o pagină internă cu buton „mark as paid", iar `SubscriptionController::pay()` confirmă manual invoice-ul, fără bani reali. Infrastructura a fost proiectată explicit pentru swap (interfața `PaymentGatewayInterface`, DTO-urile `CheckoutSession`/`WebhookResult`, câmpurile `externalId`, `InvoicingService::markPaid($invoice, $externalRef)` care declanșează deja factura fiscală async prin Oblio/SmartBill).

Scopul: înlocuim stub-ul cu integrarea reală Netopia (procesatorul local liderul de piață), confirmată prin webhook (IPN), cu abonament recurent automat pe token (charge off-session lunar, fără interacțiunea userului, cu dunning pe eșec). Rezultatul: userul plătește o dată prin redirect la Netopia, iar reînnoirile lunare se fac automat.

Cerința: implementare corectă, eficientă și robustă, proiectată de la zero pe fluxul corect (webhook-first), nu improvizată peste confirmarea manuală existentă.

## Decizii asumate (confirmate cu utilizatorul)

- API Netopia v2 (JSON + API token), NU v1 legacy (certificate RSA).
- Client HTTP CUSTOM peste API v2 (Symfony HttpClient, pattern `AnthropicApiClient`) + `firebase/php-jwt:^7.0` pentru verificarea semnăturii IPN. Decizie schimbată de la SDK-ul oficial în urma spike-ului (vezi Pas 0): SDK-ul `netopia/payment2` v1.0.6 pinuiește `firebase/php-jwt:^6.0`, interval afectat integral de CVE-2025-45769 („weak encryption", fix doar în 7.0.0 pe care `^6.0` îl exclude), iar composer blochează instalarea (`block-insecure` ON implicit). Nu acceptăm un CVE criptografic în calea de verificare a plăților.
- Recurring automat complet cu token: token salvat la prima plată, charge off-session lunar, retry + dunning pe eșec.
- Confirmare webhook-first (IPN e sursa de adevăr, nu redirectul de browser). Confirmarea manuală rămâne DOAR pe modul stub (dev/test).
- `StubPaymentGateway` se păstrează; selecția stub/netopia prin toggle de mediu (pattern-ul resolver de la e-invoicing).

## Constrângeri și riscuri cheie (de tratat explicit)

1. Dependență SDK cu CVE (VERIFICAT prin spike, 2026-07-02): SDK-ul oficial `netopia/payment2` NU are problemă de PHP (composer.json = `php: >=7.4`, se rezolvă curat pe 8.4). Blocajul real: v1.0.6 cere `firebase/php-jwt:^6.0`, iar tot intervalul 6.x e sub advisory PKSA-y2cr-5h3j-g3ys / CVE-2025-45769 („weak encryption"), fix doar în 7.0.0 pe care `^6.0` îl exclude. Composer blochează instalarea (`block-insecure` ON implicit, fără config care să-l slăbească). Decizie: renunțăm la SDK, mergem client custom peste API v2 + `firebase/php-jwt:^7.0` (curat). Rămas de confirmat la implementare: schema exactă de semnare IPN (aproape sigur JWT, din moment ce SDK-ul folosea firebase/php-jwt).
2. Webhook = suprafață publică fără sesiune: rută nouă în afara `^/subscription` (altfel cade sub `IS_AUTHENTICATED_FULLY` din `security.yaml:45`); fără CSRF; cu verificare semnătură obligatorie (IPN nesemnat/invalid respins).
3. Idempotență: Netopia poate trimite același IPN de mai multe ori și în stări intermediare. `markPaid` doar dacă invoice-ul e `PENDING` (altfel s-ar re-emite factura fiscală). Stările `pending` nu confirmă plata.
4. Gate date fiscale: azi `hasCompleteFiscalData()` e verificat în `pay()`, dar webhook-ul îl ocolește. Îl mutăm ÎNAINTE de `startCheckout`. Handler-ul fiscal are deja defense-in-depth (`IssueFiscalInvoiceMessageHandler:59`).
5. SCA recurring: prima plată necesită 3D Secure; charge-ul recurent off-session pe token trebuie marcat corect ca „merchant-initiated / recurring" pentru conformitate PSD2. De validat în sandbox.
6. GDPR: NU stocăm PAN. Stocăm doar `token_id`, `token_expiration`, `pan_masked` (afișare) și `ntpID`. De documentat în politica de confidențialitate.
7. Onboarding Netopia (blocant non-tehnic): cont comerciant + KYC + activare POS. Dezvoltarea merge pe sandbox; go-live-ul real depinde de activarea POS.

## Arhitectură țintă (flux)

```
Prima plată (on-session):
subscribe → creează Subscription ACTIVE + Invoice PENDING
  → gate date fiscale → NetopiaPaymentGateway::startCheckout(invoice)
  → NetopiaApiClient::startPayment (orderID = INV-{id}-{attempt}, amount, RON, billing)
  → redirect user la pagina Netopia → 3D Secure
  → IPN POST /webhook/netopia (semnat) → ProcessPaymentWebhookMessage (async)
      → verifică semnătură → dacă status paid/confirmed & invoice PENDING:
          markPaid(invoice, ntpID) → factură fiscală async
          + salvează token pe Subscription (recurringToken, expiry, cardMask)
  → redirectUrl aduce userul pe pagină „plata în procesare"

Reînnoire lunară (off-session):
cron app:subscriptions:renew → pentru fiecare Subscription scadentă:
  → renewSubscription() emite Invoice PENDING
  → NetopiaPaymentGateway::chargeToken(subscription, invoice)
  → IPN confirmă la fel → markPaid
  → eșec: retry (messenger) → după N eșecuri: SubscriptionStatus PAST_DUE + email dunning
```

## Pași de implementare

### Pas 0 — Spike SDK (DONE 2026-07-02) + acces sandbox
- DONE: `composer require netopia/payment2` pe containerul PHP 8.4.21 → instalare BLOCATĂ de composer, nu din PHP (php `>=7.4` OK), ci pentru că `firebase/php-jwt:^6.0` (cerut de SDK) e integral sub CVE-2025-45769. `firebase/php-jwt` 7.x curat există. Concluzie: NU folosim SDK-ul; mergem client custom peste API v2 + `firebase/php-jwt:^7.0`.
- TODO: obține credențiale sandbox Netopia (POS signature + API key sandbox) și confirmă schema de semnare IPN pe un răspuns real.

### Pas 1 — Config, env, toggle gateway
- `.env`: bloc placeholder, `.env.local` pentru credențiale reale: `NETOPIA_POS_SIGNATURE`, `NETOPIA_API_KEY`, `NETOPIA_IS_LIVE=false`, `NETOPIA_NOTIFY_URL`, `NETOPIA_RETURN_URL`, `PAYMENT_GATEWAY=stub|netopia`.
- `config/services.yaml`: `bind` pentru credențialele Netopia (ca la Oblio, liniile 67-76) + `$paymentGatewayDefault: '%env(PAYMENT_GATEWAY)%'`.
- Selecția gateway-ului: `PaymentGatewayResolver implements PaymentGatewayInterface` care alege stub/netopia din env, aliasat exact ca `EInvoicingProviderResolver` (`config/services.yaml:99-100` + `AutowireIterator`).

### Pas 2 — Persistență token + audit IPN
- Câmpuri noi pe `Subscription`: `recurringToken` (string, nullable), `recurringTokenExpiresAt` (date, nullable), `cardMask` (string 20, nullable). Refolosim `Subscription.externalId` pentru referința de customer/subscription a gateway-ului.
- `Invoice.externalId` (există) stochează `ntpID`-ul tranzacției.
- Migrare Doctrine (`make:migration` + review).
- Audit IPN pentru dispute: `AuditLogService` cu `CATEGORY_BILLING` (ntpID + status + orderID la fiecare webhook). Fără entitate nouă.

### Pas 3 — NetopiaApiClient + NetopiaPaymentGateway
- `src/Service/Billing/Netopia/NetopiaApiClient.php` (client custom pe Symfony HttpClient, pattern `AnthropicApiClient`; singurul punct care atinge API-ul Netopia):
  - `startPayment(Invoice, User, string $orderId): NetopiaStartResult` (POST JSON `/payment/card/start`; URL redirect + ntpID).
  - `verifyIpn(Request): NetopiaIpnResult` (verifică semnătura IPN cu `firebase/php-jwt:^7.0`; orderID + status + ntpID + token + pan_masked).
  - `chargeToken(Subscription, Invoice, string $orderId): NetopiaChargeResult` (off-session, merchant-initiated recurring).
  - Testabil nativ cu `MockHttpClient` + fixturi JSON (exact ca `AnthropicApiClient`). Fără dependență vendor SDK.
- `src/Service/Billing/NetopiaPaymentGateway.php` (`implements PaymentGatewayInterface`):
  - `startCheckout(Invoice): CheckoutSession` → `orderId = INV-{invoiceId}-{attempt}` → `startPayment` → `CheckoutSession(url, ntpID)`.
  - `handleWebhook(Request): WebhookResult` → `verifyIpn` → mapare la `WebhookResult(invoiceId, paid, externalRef)` (extrage invoiceId din orderID; `paid = status ∈ {paid, confirmed}`).
- DTO-uri readonly noi în `src/DTO/Billing/Netopia/` (start/ipn/charge). `CheckoutSession`/`WebhookResult` rămân contractul generic.

### Pas 4 — Endpoint webhook + procesare async + idempotență
- `src/Controller/PaymentWebhookController.php`: rută publică `POST /webhook/netopia` (NU sub `/subscription`). Fără CSRF; validează semnătura via `paymentGateway->handleWebhook()`; dacă e validă, dispatch `ProcessPaymentWebhookMessage` și răspunde imediat `200` în formatul așteptat de Netopia. Procesare grea în worker.
- `config/packages/security.yaml`: `- { path: '^/webhook', roles: PUBLIC_ACCESS }` ÎNAINTE de regulile autentificate (prima potrivire câștigă).
- `src/Message/ProcessPaymentWebhookMessage.php` + `ProcessPaymentWebhookMessageHandler.php` (`#[AsMessageHandler]`): reîncarcă invoice-ul; idempotent (`markPaid` doar dacă `PENDING`); la succes salvează token pe `Subscription`; swallow + ACK pe erori de business, re-throw pe tranzient (retry 3× din `messenger.yaml`).
- `config/packages/messenger.yaml`: rutează `App\Message\ProcessPaymentWebhookMessage: async`.
- Rate limiter: `payment_webhook` (per IP) în `rate_limiter.yaml` + `no_limit` în `when@test`.

### Pas 5 — Ajustare flux checkout (webhook-first)
- Mută `hasCompleteFiscalData()` din `pay()` în `subscribe()`, ÎNAINTE de `startCheckout`.
- `subscribe()`: rămâne `redirect(startCheckout(invoice)->url)` (neschimbat structural).
- `pay()` (confirmare manuală): păstrată DOAR pentru stub (dev/test). Pe netopia, `checkout.html.twig` devine „plata în procesare" (revenire după redirect), afișează doar starea invoice-ului.
- Rută nouă: `app_subscription_return` (GET) pentru landing după redirect.

### Pas 6 — Recurring off-session + dunning
- Salvare token la prima plată reușită (în handler webhook).
- Cablare `SubscriptionService::renewSubscription()` (există, „not yet wired"): emite `Invoice` PENDING + `chargeToken`.
- `src/Command/RenewSubscriptionsCommand.php` (`app:subscriptions:renew`, cron zilnic): abonamente cu `currentPeriodEnd` scadent + ACTIVE + token valid → `renewSubscription` → `chargeToken`. Rezultatul tot prin IPN.
- Dunning: eșec charge → după retry Messenger → `SubscriptionStatus::PAST_DUE` + email „actualizează cardul" (Mailer sync). Token expirat → skip charge + email re-autorizare (link checkout on-session).
- `SubscriptionRepository::findDueForRenewal(\DateTimeImmutable $on)` (nou).

### Pas 7 — Reconciliere plăți orfane
- `src/Command/ReconcilePaymentsCommand.php` (`app:payments:reconcile`, cron): invoice-uri PENDING vechi cu `ntpID` setat → interoghează statusul la Netopia → confirmă/expiră. Acoperă IPN-uri pierdute.
- `InvoiceRepository::findStalePending(\DateTimeImmutable $before)` (nou; azi există doar `countPending`).

### Pas 8 — Teste
- Unit: `NetopiaApiClient` cu MockHttpClient, fixturi JSON start/IPN/charge (pattern `AnthropicApiClient`); verificare semnătură IPN cu `firebase/php-jwt:^7.0` (fixturi cu token valid/invalid/expirat).
- Webhook: semnătură validă → markPaid; invalidă → respins fără efect; IPN duplicat → un singur markPaid; IPN `pending` → invoice rămâne PENDING.
- Recurring: `chargeToken` succes → PAID; eșec → PAST_DUE + email; token expirat → skip + re-autorizare.
- Reconciliere: PENDING orfan confirmat la interogare.
- Idempotență fiscală: markPaid de două ori nu emite două facturi (gard `PAID` din handler).
- Convenții `CLAUDE.md`: emailuri unice `uniqid()`, `tearDown` cu DELETE în ordinea FK, `failOnDeprecation` verde.

### Pas 9 — i18n, docs, verificare E2E sandbox
- i18n RO+EN: `subscription.flash.payment_processing`, `subscription.dunning.*`, mesaje return page (fără em-dash, RO/EN în lockstep, `lint:yaml`).
- Docs: actualizează `RESEARCH-INTEGRARE-NETOPIA.md` cu decizia finală; marchează docs vechi (`PLAN-DEZVOLTARE-MVP.md`, `TECH-STACK-MVP.md`) ca depășite pe secțiunea plăți.
- Memorie: notă de progres în MEMORY.md.

## Fișiere critice (create/modificate)

Noi: `src/Service/Billing/NetopiaPaymentGateway.php`, `src/Service/Billing/Netopia/NetopiaApiClient.php`, `src/Service/Billing/PaymentGatewayResolver.php`, `src/DTO/Billing/Netopia/*`, `src/Controller/PaymentWebhookController.php`, `src/Message/ProcessPaymentWebhookMessage.php`, `src/MessageHandler/ProcessPaymentWebhookMessageHandler.php`, `src/Command/RenewSubscriptionsCommand.php`, `src/Command/ReconcilePaymentsCommand.php`, migrare Doctrine.

Modificate: `config/services.yaml`, `config/packages/messenger.yaml`, `config/packages/security.yaml`, `config/packages/rate_limiter.yaml`, `.env`/`.env.local`, `src/Entity/Subscription.php`, `src/Service/Billing/SubscriptionService.php`, `src/Controller/SubscriptionController.php`, `src/Repository/SubscriptionRepository.php`, `src/Repository/InvoiceRepository.php`, `templates/subscription/checkout.html.twig`, traduceri RO/EN.

Reutilizat neschimbat: `PaymentGatewayInterface`, `CheckoutSession`, `WebhookResult`, `InvoicingService::markPaid()`, `IssueFiscalInvoiceMessageHandler`.

## Verificare end-to-end

1. `PAYMENT_GATEWAY=stub` + `./bin/phpunit` → suita verde (zero regresii, `failOnDeprecation`).
2. Spike SDK (DONE 2026-07-02): `composer require netopia/payment2` blocat de composer pe CVE-2025-45769 (firebase/php-jwt 6.x cerut de SDK). Decizie: client custom + `firebase/php-jwt:^7.0`. `composer require firebase/php-jwt:^7.0` se instalează curat pe 8.4.
3. Sandbox Netopia (`NETOPIA_IS_LIVE=false`): subscribe → pagina test Netopia → card test 3DS → IPN prin tunel (ngrok) către `/webhook/netopia` → invoice PAID → factură fiscală (Oblio sandbox) → token salvat pe Subscription.
4. Recurring: `app:subscriptions:renew` pe abonament cu token → charge off-session → IPN → PAID fără interacțiune.
5. Idempotență: replay același IPN → invoice rămâne PAID, fără a doua factură fiscală.
6. Dunning: forțează eșec charge → Subscription PAST_DUE + email dunning în Mailpit.
7. Reconciliere: blochează un IPN, rulează `app:payments:reconcile` → invoice PENDING confirmat prin interogare status.
8. Securitate: `/webhook/netopia` fără auth trece de firewall; IPN cu semnătură falsă → respins, invoice neschimbat.
