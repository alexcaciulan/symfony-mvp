# Plan de release LexRecovery

Checklist al pașilor necesari înainte de lansarea aplicației în producție.
Document viu: se completează pe măsură ce se identifică pași noi. Fiecare pas
are stare (`[ ]` de făcut / `[x]` făcut), responsabil și note.

Legendă: 🔴 blocant lansare · 🟡 recomandat înainte de lansare · 🟢 post-lansare.

## Referințe

- Branch de release: `lexrecovery` (urcat pe `origin`).
- Merge request: https://github.com/alexcaciulan/symfony-mvp/pull/new/lexrecovery
  (link de deschidere a PR-ului; înlocuiește-l cu URL-ul PR-ului după creare).

---

## 1. Date și seed

### 1.1 🔴 Backfill istoric cursuri valutare BNR

**De ce**: conversia creanțelor în valută (EUR) în RON folosește cursul de
referință BNR din **ziua emiterii facturii**. Importul zilnic aduce doar
cursurile curente; pentru facturile mai vechi (până la limita prescripției de
3 ani, art. 2517 Cod civil) trebuie încărcat istoricul, altfel garda de
prospețime din `CurrencyConverter` (prag 7 zile) va afișa „curs indisponibil".

**Ce se rulează** (o singură dată, la provisioning-ul producției):

```bash
# În containerul PHP de producție, pentru fiecare an din fereastra de prescripție
php bin/console app:import-exchange-rates --year=2023
php bin/console app:import-exchange-rates --year=2024
php bin/console app:import-exchange-rates --year=2025
php bin/console app:import-exchange-rates --year=2026
```

- Sursa: arhivele anuale oficiale `https://www.bnr.ro/files/xml/years/nbrfxrates{AN}.xml`.
- Comanda e idempotentă: re-rularea sare peste cursurile existente (folosește
  `--update` doar dacă vrei să suprascrii).
- Filtrează automat EUR/USD, normalizează multiplicatorul.
- Fereastra: an curent − 3 ani (acoperă prescripția). Extinde dacă preiei
  facturi mai vechi.

**Verificare**:

```bash
# Trebuie să existe ~250 de cursuri/an/monedă (zile bancare)
php bin/console dbal:run-sql "SELECT currency, YEAR(rate_date) AS an, COUNT(*) FROM bnr_exchange_rate GROUP BY currency, an ORDER BY an"
```

Test funcțional: în wizard, o factură în EUR cu dată din anul backfill-at trebuie
să afișeze cursul exact al zilei, nu „curs indisponibil".

**Responsabil**: DevOps la provisioning · **Stare**: [ ]

### 1.2 🟡 Import date de referință (instanțe, orașe)

`app:import-courts`, `app:import-cities`, `app:import-court-portal-codes` rulează
deja în `docker-entrypoint.sh`. De confirmat că rulează pe DB de producție.

**Stare**: [ ]

---

## 2. Job-uri programate (cron Coolify)

Se înregistrează în Coolify (nu prin Symfony Scheduler). Vezi comentariile din
`docker-entrypoint.sh`.

- [ ] 🔴 `app:import-exchange-rates` — zilnic ~06:00 (curs valutar la zi)
- [ ] 🔴 `app:check-deadlines` — zilnic 07:00 (alerte termene)
- [ ] 🔴 `app:portal-check-all` — zilnic 08:00 (monitorizare portal.just.ro)
- [ ] confirmare worker Messenger activ (consumă `CheckCasePortalMessage`)

---

## 3. Plăți și facturare

- [ ] 🔴 Gateway Netopia real în locul stub-ului (înainte de orice plată reală).
      Momentan `PAYMENT_GATEWAY=stub`; stub-ul nu are gardă de mediu.
- [ ] 🔴 Credențiale Netopia în env (POS signature, API key, notify/return URL).
- [ ] 🟡 Emitere factură fiscală + ToS (follow-up din stratul de facturare).

---

## 4. Configurare mediu și secrete

- [ ] `.env.local` producție: `DATABASE_URL`, `MAILER_DSN`, `APP_SECRET`.
- [ ] `ANTHROPIC_API_KEY` (extracție AI) + verificare rate limiters.
- [ ] `APP_ENV=prod`, `APP_DEBUG=0`.
- [ ] HTTPS + domenii (Mercure hub, return/notify URLs).

---

## 5. Calitate și verificări finale

- [ ] Suită de teste verde (rezolvă cele 10 eșecuri baseline din workstream-ul
      workflow: `setStatus(string)` în `AdminAccessTest` + `DocumentUploadServiceTest`).
- [ ] `doctrine:schema:validate` OK pe DB producție.
- [ ] `lint:yaml translations/` + `lint:twig templates/` OK.
- [ ] Smoke test manual pe fluxul complet: dosar nou → somație → cerere OP.

---

## 6. Decizii de produs de tranșat pre-lansare

- [ ] 🟡 Organization tenancy (multi-user per cabinet) — de decis cât timp DB e
      încă drop+recreate (re-ancorare 4 entități + CaseVoter).
- [ ] 🟡 Model de monetizare (abonament hibrid, contorizare la `trimite_somatie`).

---

> Următorii pași se adaugă aici pe măsură ce sunt identificați.
