# Plan: redesign pagina /termene (v3, varianta listă)

Machete: `docs/LexRecovery/mockups/v3/05-termene/varianta-lista.html` (note de implementare în `<details>`) și `stari-lista.html`.
Branch: `feature/termene-redesign-v3`. Analiză: workflow cu 4 agenți (cod, teste, juridic, critic), 2026-11-06.

## Decizii de arhitectură

1. **Comasare doar pe `PRESCRIPTIE`, global, în SQL.** Rândul este termenul deschis cel mai apropiat al dosarului. Predicatul stă o singură dată în `agendaQueryBuilder`, ca listele, contoarele, insigna și KPI-ul să nu poată diverge. Grupul se plasează după data minimă, iar scadențele următoare merg cu el, chiar dacă trec de orizont.
2. **Filtrul devine `tab`, `fatal`, `page`.** Linkurile vechi: `completed` truthy duce la Finalizate; `f=blocked` singur la De completat; `f` cu `fatal30` la De făcut cu `fatal=1`; orice alt `f` la De făcut. Un `tab` valid câștigă. `fatal` contează doar pe De făcut și acoperă toate tipurile din `irreversibleTypes()`, inclusiv depășitele.
3. **Filele și comutatorul fatal sunt linkuri server cu `data-turbo="false"`.** Importmap-ul cu nonce ar produce altfel două GET-uri pe click. Se construiește doar fila activă; contoarele se calculează mereu.
4. **Panoul nu este overlay Preline.** Preline închide overlay-ul precedent, deci dialogul de confirmare ar închide panoul. Panoul e un `aside` propriu, cu `turbo-frame`, încărcat la cerere de pe `GET /termene/{id}/detalii` (CaseVoter::VIEW). Blocajele au `GET /termene/dosar/{id}/completare`, pentru că acolo termenul nu există încă.
5. **Pașii următori au o singură regulă.** `CaseNextStepResolver` preia logica din `_hero_actions.html.twig`; `DeadlineNextStepResolver` o traduce pentru rând și stabilește ținta butonului din slotul 1.
6. **Acțiunile pe rând:** `open` plus `close` opțional. Ramura de agendă din `CaseSummonsController` și parametrii rămași fără apelant se șterg.

## Decizii implicite, de confirmat de proprietar

| # | Decizie | Implicit |
|---|---------|----------|
| 1 | Eticheta Fatal pe cele cinci motive din De completat cu termen fatal necreat | Da, propunere juridică |
| 2 | Rând comasat consumat | Estompat doar când ultima scadență a trecut |
| 3 | Timbrare achitată în termen, nemarcată, după dată | Comportament neschimbat, rămâne consumată; de discutat cu avocatul |
| 4 | Finalizate exclude dosarele terminale | Da, ca azi; textul stării goale spune „închise”, fără „de aplicație” |
| 5 | Depunere cerere pe agendă | Fără închidere, dar ruta și fila din dosar o permit în continuare |
| 6 | Sugestii pe rând | Doar pe Prescripție, Depunere cerere, Răspuns somație; nimic pe prescripție consumată |

## Formulări stabilite la analiza juridică

- „depunerea cererii la instanță” în loc de „confirmarea depunerii” pe rând: confirmarea unui act nefăcut închide termenul.
- „Întreruptă, cerere până la <dată>”; după dată, fără cerere: „Întrerupere pierdută (6 luni depășite)”, cu frază nouă în panou.
- Chip-ul Fatal are `title` și `aria-label` pe tip. Pentru Depunere cerere nu se folosește „Stingerea dreptului”; maparea din `DeadlineConsequenceResolver` rămâne.
- Sugestia pentru cererea de OP folosește `DeadlineService::isPaymentTermExpired`, nu calcule proprii.
- Panoul De completat randează `reason.*`, cu părțile la persoana a doua rescrise impersonal și „curge oricum” la comunicarea ordonanței.
- Textele per tip (consecință, calcul, temei) sunt chei noi și cer confirmare juridică la review.

## Probleme juridice rămase deschise

- După pierderea întreruperii, prescripția rămâne ESTIMAT în `DeadlineCertaintyResolver`, deci nu devine consumată. Pentru acum se schimbă doar eticheta și panoul.
- Numerotarea CPC Titlul IX și diferența art. 705/706 nu au fost verificate la sursa primară în această sesiune.
- Cele trei întrebări pentru avocat: „Nu depun cerere în anulare”, eticheta Fatal, titlul de închidere.

## Pași

| Pas | Conținut |
|-----|----------|
| S1 | `CaseNextStepResolver`, antetul dosarului neschimbat |
| S2 | Comasare `PRESCRIPTIE`, contoare noi, fapte despre scadențe pe `DeadlineAgendaItem` |
| S3 | Grupuri noi: overdue, today, unblocked, week, later |
| S4 | Filtru `tab`/`fatal`/`page`, verdict, casete, luni în română, ștergere pastile |
| S5 | Filele Prescripții și Finalizate |
| S6 | Acțiuni pe rând: open plus close, ștergere butoane generatoare |
| S7 | Sugestia „Următorul pas” pe rând |
| S8 | Rând și grup nou, tooltip |
| S9 | Rândurile De completat |
| S10 | Panoul de termen |
| S11 | Panoul de blocaj |
| S12 | Nota de întrerupere pierdută, mobil, corecturi de text |
| S13 | Curățenie, suită completă, review, verificare live |

Fiecare pas vine cu teste și verificare în browser. Testele se schimbă în pasul care schimbă comportamentul. Pentru prescripții comasate se folosesc dosare `TMP-LIVE-*`, pentru că seed-ul demo nu creează termene `PRESCRIPTIE`.

Note de implementare: `data-testid` pentru hook-uri noi, `aria-current` pe fila activă, `aria-pressed` pe comutator; regiunile `deadline-riskbar`, `deadline-agenda` și `deadline-row-{id}` rămân; `overflow-clip` pe cardurile cu benzi lipicioase; panoul se închide la orice răspuns diferit de `error`; lipsa debitorului trece pe numărul dosarului.

## Actualizare: selecția se schimbă fără reîncărcare

Filele, filtrul Fatal și „Arată mai multe” se încarcă în `turbo-frame#deadline-page` (`target="_top"`, `data-turbo-action="advance"`), iar URL-ul rămâne actualizat. Acoperă `data-turbo="false"` de pe linkuri, care ocolea dublul GET provocat de nonce-ul importmap. Dialogurile rămân în afara frame-ului și primesc selecția prin inputuri cu atributul `form`, randate în regiunea agendei. Un răspuns fără frame (sesiune expirată, eroare) devine vizită completă, iar o cerere fără răspuns lasă pagina neschimbată și arată un mesaj de eroare. Un răspuns de tip stream golește cache-ul Turbo, ca Înapoi să nu readucă agenda dinainte de acțiune.

## Actualizare: comasarea prescripțiilor, după review

Un dosar are cel mult două rânduri de prescripție: unul pentru scadențele trecute și unul pentru cele încă în curs, fiecare condus de cea mai veche scadență din grupul lui. Regula anterioară (un singur rând, cea mai veche scadență deschisă) putea ascunde un termen viu sub unul trecut, în afara numărătorilor Azi și Această săptămână. Înlocuiește regula de „consumat” pe rând comasat. Eticheta Fatal apare pe cinci motive din De completat, inclusiv lipsa datei comunicării somației.
