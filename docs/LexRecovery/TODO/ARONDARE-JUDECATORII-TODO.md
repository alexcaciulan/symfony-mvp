# TODO Arondare judecătorii (coveredLocalities): stare și ce a mai rămas

Data: 2026-07-14
Stare: **IMPLEMENTAT** pentru release. Arondarea completă (HG 1217/2023) a fost populată: `court_covered_city` are ~3.184 legături (de la 177), 0 suprapuneri, 0 goluri neașteptate. Determinarea automată a instanței funcționează acum pentru localitățile non-reședință (verificat: Pângarați, jud. Neamț → Judecătoria Piatra Neamț).
Sursă confirmată de avocat: **HG nr. 1217/2023** (Monitorul Oficial 1102/07.12.2023), vendorizată în `data/sources/hotarare-1217-2023.pdf` (+ `.txt` extras).
Referințe: `../DETERMINARE-INSTANTA-COMPETENTA.md` (§6, §11), `../ANALIZA-JURIDICA-PROCEDURA-OP-2026-05-08.md`.

Punctul C din runda 2 de review avocat. Fluxul: anexa HG → `data/courts.json` (`coveredLocalities`) → `app:import-courts --update` → `court_covered_city`.

---

## 1. Popularea datelor: DONE

- [x] Sursă confirmată: HG 1217/2023, vendorizată în `data/sources/`.
- [x] Generator `app:build-court-coverage` (dev): parsează anexa + reconciliază numele prin `data/coverage-aliases.json`, rescrie `coveredLocalities` în `data/courts.json`. Validare integrată: 0 nerezolvate, 0 suprapuneri.
- [x] Reconciliere: 3.152/3.188 UAT (98,9%) potrivite automat cu `data/cities.json`; restul acoperite prin harta de alias-uri (cratime/punctuație/diacritice + comuna redenumită Petreu→Abramuț).
- [x] `data/courts.json` extins (169 judecătorii acoperite, 3.178 localități; sectoarele București rămân `["Sector N"]`).
- [x] `app:import-courts --update` rulat: 0 warning-uri „city not found".
- [x] DB: 3.184 legături în `court_covered_city`, 175 instanțe cu acoperire.

## 2. Verificare acuratețe: DONE

- [x] Comandă de audit `app:audit-court-coverage` (rulabilă și în prod): raportează goluri, suprapuneri, judecătorii goale; exit non-zero la suprapuneri sau goluri nedocumentate.
- [x] Rezultat audit: 0 suprapuneri, 0 goluri neașteptate, 2 goluri documentate.
- [x] Teste de regresie fără DB: `tests/Service/Court/CourtCoverageDataTest.php` (0 suprapuneri în date + comune-capcană: Pângarați→Piatra Neamț, Ponoarele→Baia de Aramă, Râșca→Huedin, Însurăței→Brăila). Suita `tests/Service/Court/` verde (23 teste).

## 3. Instanțe suspendate: verificate cu avocatul (portal.just.ro, 2026-07-14)

- [x] **Judecătoria Baia de Aramă: OPERAȚIONALĂ** (avocatul a confirmat: are ședințe programate, fără notă de suspendare, portal id 181). CORECȚIE față de presupunerea inițială: adăugată în `courts.json` ca instanță activă cu cele 7 localități proprii (Baia de Aramă, Bala, Balta, Isverna, Obârșia-Cloșani, Ponoarele, Șovarna). NU se mai rutează la Strehaia.
- [x] Judecătoria Însurăței: **suspendată** (anunț pe portal id 247). Localitățile la Brăila/Făurei, cum indică și HG prin nota „în prezent".
- [x] Judecătoria Bocșa → orașul la Reșița; Judecătoria Murgeni → orașul la Bârlad (avocatul a confirmat: nu apar printre instanțele active din Caraș-Severin/Vaslui). Rămân în `courts.json` fără acoperire proprie.

## 4. Goluri cunoscute (documentate, selecție manuală)

Comune prezente în `data/cities.json` dar absente din HG 1217/2023 (comune create în 2004, neincluse în anexă). Lăsate neacoperite intenționat (fallback selecție manuală); allow-list în `data/coverage-aliases.json`:
- [ ] Gologanu (Vrancea), Capu Câmpului (Suceava). De arondat doar când un HG ulterior modifică anexa.

## 5. Deploy: DONE

- [x] `docker-entrypoint.sh` cablat: `app:import-cities` (județe + orașe) ÎNAINTE de `app:import-courts`, apoi `app:audit-court-coverage` (non-fatal) ca gard post-import. La release, datele se importă automat, fără pași manuali.

## 6. Rămâne în afara scopului (secundar, amânat)

- [ ] **Rezolvare sat → comună** pentru adrese ANAF la nivel de sat (ex. „Sat Dancu, Com. Holboca"). Arondarea e la nivel de comună (UAT); un debitor cu localitatea = satul cade pe selecție manuală chiar dacă comuna e acoperită. Necesită nomenclator SIRUTA sat → UAT sau extragerea comunei din stringul de adresă înainte de matching. Nu blochează punctul C.
- [ ] Marcarea `active=false` pentru Bocșa, Murgeni, Însurăței (suspendate). Acum sunt active cu acoperire goală (nu se potrivesc niciodată, deci inofensiv), dar ar fi mai curat inactive. Necesită mică modificare la `ImportCourtsCommand` (să onoreze un flag `active` din JSON).
- [ ] Câteva nume din `data/cities.json` au inconsecvențe minore (ex. „I.c.bratianu", „Sârbii - Magura"); acoperirea se leagă corect, dar merită normalizate cândva la sursă.

## Reproducere (când se modifică HG)

1. Reîmprospătează `data/sources/hotarare-1217-2023.txt` (via `pdftotext -layout` pe noul PDF).
2. Ajustează `data/coverage-aliases.json` dacă apar nume noi.
3. `php bin/console app:build-court-coverage` (validează + rescrie `courts.json`).
4. `php bin/console app:import-courts --update` apoi `php bin/console app:audit-court-coverage`.
