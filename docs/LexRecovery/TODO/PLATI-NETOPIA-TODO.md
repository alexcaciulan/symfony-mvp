# TODO Plăți Netopia: ce mai trebuie făcut / de avut în vedere

Data: 2026-07-03
Stare integrare: **funcțională end-to-end pe sandbox** (plată inițială + recurent + IPN + factură fiscală), comisă pe `lexrecovery` (`62d59bc` + `6bbb392`). Docs: `../PLAN-INTEGRARE-NETOPIA.md`, `../RESEARCH-INTEGRARE-NETOPIA.md`.

Acest fișier listează ce a rămas, grupat pe prioritate. Nimic din listă nu blochează dezvoltarea; sunt pași de pre-lansare/operațional.

---

## 1. BLOCANT pentru go-live (plăți reale, live)

- [ ] **Activare flag recurent pe contul LIVE.** Pe sandbox Netopia a setat flag-ul la cerere. Pentru live e nevoie de aprobare comercială + mandat SCA. Cere-le explicit activarea pe contul de producție (username + semnătura POS live).
- [ ] **Credențiale live în mediul de producție** (`.env.local` pe server, NU comis):
  - `PAYMENT_GATEWAY=netopia`
  - `NETOPIA_IS_LIVE=true`
  - `NETOPIA_POS_SIGNATURE`, `NETOPIA_API_KEY` (din contul LIVE, diferite de sandbox)
  - `NETOPIA_NOTIFY_URL`, `NETOPIA_RETURN_URL` cu domeniul real (https public), nu tunel.
  - Cheia publică NU trebuie configurată: e hardcodată ca default în `NetopiaApiClient::PLATFORM_PUBLIC_KEY` (fixă sandbox+live, confirmat de Netopia). Setează `NETOPIA_PUBLIC_KEY` doar dacă Netopia o rotește.
- [ ] **URL public real pentru IPN** (nu cloudflared, care e doar pentru testare locală). Domeniul de producție trebuie să răspundă la `POST /webhook/netopia`.
- [ ] **Programare cron zilnic** pentru:
  - `app:subscriptions:renew` (trage reînnoirile scadente pe token, off-session).
  - `app:payments:reconcile` (backstop pentru IPN-uri pierdute).
  - Momentan sunt doar comenzi, NU sunt planificate. De adăugat în crontab / systemd timer / Symfony Scheduler.

## 2. Conformitate / hardening pre-lansare (recomandat înainte de bani reali)

- [ ] **Consimțământ recurent FORMAL** (gap de probă la chargeback, semnalat de legal review W4). Acum e doar text de divulgare (`subscription.plans.recurring_disclosure`), fără bifă. De adăugat:
  - bifă explicită la abonare („Sunt de acord cu debitarea automată lunară...");
  - persistare `consentGivenAt` (DateTimeImmutable) + `consentIp` pe `Subscription` la `subscribeToPlan()`.
  - Notă: LexRecovery e B2B (avocați/PJ), deci regula „buton cu obligație de plată" din OUG 34/2014 (consumatori) probabil nu se aplică strict; dar mandatul e util ca dovadă pentru dispute Netopia/rețele de carduri.
- [ ] **Confirmare scrisă de la Netopia** că token-ul NU e emis pe un 3DS eșuat (legal review W5). Baza scutirii SCA pentru MIT depinde ca tranzacția de referință să fie autentificată SCA reușit. De documentat răspunsul în `../RESEARCH-INTEGRARE-NETOPIA.md`.
- [ ] **Job de retenție token** pentru abonamentele `CANCELED` mai vechi de N zile (curăță `recurringToken` rămase). La anulare token-ul se șterge deja (`cancelSubscription`), dar un sweep periodic acoperă cazurile vechi/edge.
- [ ] **Verificare furnizor fiscal la go-live**: la fiecare plată reală se emite factură fiscală prin provider (Oblio/SmartBill). Confirmă că `EINVOICE_PROVIDER` + credențialele reale sunt setate ÎNAINTE de prima plată live (altfel factura rămâne pe stub / ERROR). Datele furnizor (`app.invoice.supplier`) au încă placeholder-e (CUI/IBAN reale + sign-off consultant fiscal).

## 3. Operațional / robustețe

- [ ] **`trusted_proxies`** neconfigurat (gap app-wide preexistent). În spatele nginx-ului, rate limiterul `payment_webhook` (și cele de la Registration/ResetPassword) bucketează pe IP-ul nginx, nu al clientului real. De configurat `framework.trusted_proxies`.
- [ ] **Lock pesimist pe scrierea token-ului** (`captureRecurringToken`) — cod review MEDIUM. Acum: `iat`-freshness acoperă replay-ul; scrierea concurentă pe același abonament e risc redus (cadență lunară). De adăugat lock dacă apare volum mare/concurență.
- [ ] **Edge: charge acceptat cu `ntpID` null** → factura ar rămâne fără `externalId`, nerecuperabilă de reconciliere. De adăugat log de alertă dacă apare.
- [ ] **Pagina de retur** după plată: pe modul netopia, `NETOPIA_RETURN_URL` duce userul pe domeniul de retur; dacă e alt domeniu decât cel cu sesiunea, poate cere re-login (cosmetic). Confirmă că în producție return + app sunt pe același domeniu.

## 4. Opțional / nice-to-have

- [ ] Criptare la nivel de coloană pentru `recurringToken` (defense-in-depth; e token opac, nu PAN, deci nu urgent).
- [ ] `StripePaymentGateway` ca al doilea implementor al `PaymentGatewayInterface` (dacă recurentul/churn-ul cer o alternativă; arhitectura permite fără rescriere).
- [ ] Refactor: extragere `NetopiaIpnVerifier` din `NetopiaApiClient` (clasa are ~500 linii; opțional).

## 5. Decizii de business (de clarificat cu partenerul/avocatul)

- [ ] Politică **refund/storno** la anulare abonament (acum: fără refund, rămâne valabil până la finalul perioadei plătite). Dacă se dorește refund, de legat de `credit` Netopia + stornare `FiscalInvoice`.
- [ ] Politică **eșec plată overage** (CASE_EXTRA): dosarul e deja activat când plata de overage eșuează. De decis: blocare retroactivă vs. datorie de recuperat.
- [ ] Negociere **comisioane** Netopia pe volumul estimat (pentru pricing planuri).

---

## Checklist go-live plăți (rezumat)

1. [ ] Flag recurent activat pe cont LIVE
2. [ ] `.env.local` prod: `PAYMENT_GATEWAY=netopia`, `NETOPIA_IS_LIVE=true`, credențiale live, URL-uri publice reale
3. [ ] Cron zilnic `app:subscriptions:renew` + `app:payments:reconcile`
4. [ ] Provider fiscal real configurat + date furnizor reale (CUI/IBAN)
5. [ ] Consimțământ recurent formal (bifă + consentGivenAt/IP)
6. [ ] Confirmare Netopia: token doar pe 3DS reușit
7. [ ] `trusted_proxies` configurat
8. [ ] Test smoke cu un card real de valoare mică (după activare live)
