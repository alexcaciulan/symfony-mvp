# Termene: ce a rămas de făcut

Stare: feature-ul livrat pe `feature/termene`, necomis. Suita: 2478 teste, 0 failures.
Lista de mai jos este rezultatul a trei verificări juridice rulate pe cod la 2026-07-26, plus
review-ul final pe tot feature-ul. Ordinea este cea a riscului, nu a efortului.

Toate afirmațiile juridice de mai jos au fost verificate pe surse, dar surse secundare în bună
parte, pentru că `legislatie.just.ro` nu a răspuns constant. Înainte de implementare, punctele
marcate cu **[avocat]** cer confirmarea avocatului titular.

---

## 1. Calculul termenelor greșește sistematic cu o zi: IMPLEMENTAT **[avocat]**

Regula N+1 plus prorogarea trăiește într-un singur loc, `DeadlineService::proceduralTermEnd()`.
Constantele rămân la valorile legale (15/10/10). Poarta `isPaymentTermExpired()` se raportează
acum la comunicare + 16 prorogat, deci depunerea e permisă abia din ziua următoare împlinirii.
`ensureExecutionPrescriptionDeadline` și `CaseAutoFinalizer` refolosesc `appealTermEnd()`.

CPC art. 181 alin. 1 pct. 2 folosește sistemul zilelor libere: nu se socotește nici ziua de la
care curge termenul, nici ziua în care se împlinește. Formula corectă este `dată + N + 1`.

`DeadlineService` folosește `dată + N`:

| constantă | acum | corect |
| --- | --- | --- |
| `PAYMENT_NOTICE_DAYS` (15) | `+15` | `+16` |
| `APPEAL_DAYS` (10) | `+10` | `+11` |
| `STAMP_DUTY_DAYS` (10) | `+10` | `+11` |

Verificare pe o comunicare din luni 1 iunie 2026: răspunsul la somație se împlinește 17 iunie,
aplicația arată 16 iunie. Cererea în anulare și timbrarea: 12 iunie real, 11 iunie afișat.

`DeadlineCreationSubscriber:155` folosește `+11`, adică singura formulă corectă din cod, dar
pentru a deriva data definitivării, fără prorogare, și recalculând independent în loc să
refolosească rezultatul din `createAppealDeadline()`.

### Consecința operațională, cea mai gravă

`DeadlineService::isPaymentTermExpired()` este poarta care ar trebui să împiedice depunerea
prematură a cererii de ordonanță. Folosește aceeași formulă scurtă, deci
`CasePaymentOrderController:80` permite generarea cererii cu o zi înainte ca termenul
debitorului să expire. Sancțiunea este respingerea ca prematură (CPC art. 1015-1016). Textul
propriu al aplicației avertizează despre exact acest risc.

De rezolvat simultan: cele patru metode din `DeadlineService`, `ensureExecutionPrescriptionDeadline`
care trebuie să refolosească data calculată, testele care fixează valorile actuale, și mockup-urile.

---

## 2. Termenul de 6 luni din NCC art. 2540 nu există în aplicație: IMPLEMENTAT **[avocat]**

Implementat prin `DeadlineService::createFilingDeadline()`, tip `DEPUNERE_CERERE`, ancorat pe
`paymentNoticeCommunicationDate`, calculat pe luni (NCC art. 2552, cu clamp pe alin. 3 la ultima
zi a lunii), fără prorogare. Creat din `CaseDeadlineController::setPaymentNoticeCommunicationDate`,
recalculat dacă avocatul corectează data, închis automat la intrarea în `CERERE_DEPUSA`.
`DeadlineConsequenceResolver` îl mapează la `RIGHT_EXTINCTION`, nu la `RECORD_KEEPING`.
Rămâne de confirmat cu avocatul întrebarea de fond de mai jos.

Somația comunicată întrerupe prescripția, dar întreruperea se consideră că nu a avut loc dacă
cererea nu este introdusă în 6 luni de la comunicare (NCC art. 2540, la care CPC art. 1015
alin. 2 trimite expres).

Aplicația nu urmărește acest termen nicăieri. Exemplu de prejudiciu: scadență 01.09.2023,
prescripție 01.09.2026, somație comunicată 20.02.2026, cerere depusă 15.09.2026. Cele 6 luni
expiraseră pe 20.08.2026, deci întreruperea cade retroactiv, prescripția s-a împlinit pe
01.09.2026, cererea se respinge ca prescrisă. Creanța se pierde integral.

Ancora corectă este `paymentNoticeCommunicationDate + 6 luni`, niciodată data generării somației.
Tipul `DEPUNERE_CERERE` există deja în enum și nu e creat de nimeni: acesta este temeiul lui real.

De confirmat: dacă cererea de ordonanță de plată satisface „chemarea în judecată" din art. 2540.
Argumentul sistematic e solid (art. 1015 alin. 2 trimite la art. 2540 chiar în capitolul dedicat
procedurii OP), dar nu s-a găsit o sursă care să tranșeze punctual.

---

## 3. `PRESCRIPTIE_EXECUTARE` derivată din ziua curentă: IMPLEMENTAT **[avocat]**

Ancora este acum, în ordine: `rulingCommunicationDate`, apoi `finalRulingDate`, apoi nimic.
Ziua curentă nu se mai folosește. Când nu există niciuna dintre cele două date, termenul NU se
creează, iar dosarul apare în zona Blocaje cu al patrulea motiv, `EXECUTION_ANCHOR_MISSING`.
Rămâne deschisă întrebarea de fond de mai jos (art. 706 vs. executorialitatea de la comunicare).

Când `rulingCommunicationDate` lipsește, `DeadlineCreationSubscriber:156` folosește ziua curentă
ca ancoră. „Azi" e aproape întotdeauna ulterior comunicării reale, deci termenul afișat e mai
lung decât cel real. Pe un termen ireversibil, e cea mai periculoasă direcție de eroare.

Ordinea opțiunilor, de la cea mai sigură:
1. să nu se creeze termenul deloc fără dată reală, iar dosarul să apară în zona Blocaje;
2. ancoră conservatoare: `finalRulingDate` există pe `LegalCase` și, fiind data pronunțării, e
   întotdeauna anterioară comunicării, deci produce alertă prematură, nu siguranță falsă;
3. niciodată ziua curentă.

Întrebarea de fond s-a închis la review-ul din 27.07.2026, și a închis-o o corectură de citare.
Textul relevant nu e art. 706, ci **art. 705**: alin. 1 dă cei 3 ani, iar alin. 2 spune expres că
pentru hotărâri judecătorești termenul curge de la rămânerea definitivă. Art. 706 tratează altceva,
efectele împlinirii termenului. Executorialitatea imediată din art. 1021 guvernează când se poate
începe executarea, nu de când curge prescripția, deci ancora actuală (împlinirea căii de atac plus
o zi) este cea corectă. Citările au fost corectate peste tot în cod și în traduceri.

---

## 4. `CERERE_IN_ANULARE` care nu se închide: **nu implementa închiderea necondiționată** [avocat]

Concluzia inițială era că e un bug simplu. Este mai subtil.

CPC art. 1024 dă și creditorului dreptul la cerere în anulare, împotriva soluțiilor de la
art. 1021 alin. 1-2. Statusul `IN_ANULARE` înseamnă „cererea a fost deja depusă", nu „suntem în
fereastra de 10 zile": tranziția `formuleaza_cerere_anulare` se declanșează de faptul depunerii.

Pe o ordonanță admisă parțial pot exista două termene distincte, cu titulari diferiți și
eventual date de comunicare diferite. Modelul are un singur rând per dosar, fără câmp de titular
(`findOneByCaseAndType`). Dacă debitorul depune primul și sistemul închide orbește singurul rând,
ascunde termenul propriu al clientului nostru, adică exact riscul ireversibil.

Deci: fie se rezolvă întâi titularul, fie se închide automat doar pe admiterea integrală, iar pe
admiterea parțială termenul rămâne deschis cu avertisment explicit. Niciodată închidere silențioasă.

Legat: `DeadlineConsequenceResolver` mapează `CERERE_IN_ANULARE` uniform la decădere, indiferent
de titular, deci un termen al debitorului apare roșu în loc de „deblocat", inconsecvent cu
tratamentul deja aplicat lui `RASPUNS_SOMATIE`. De rezolvat împreună.

---

## 5. Prorogarea datei de judecată: IMPLEMENTAT

`createHearingDeadline` nu mai trece prin `nextWorkingDay()`: data se stochează exact cum vine.
O dată nelucrătoare se semnalează în două locuri, fără să fie corectată: `logger->warning` (pentru
cine urmărește sincronizarea cu portalul) și flag `nonWorkingDay` în payload-ul de audit, care
rămâne atașat de înregistrare după ce log-urile se rotesc.

---

## 6. Confirmate ca fiind corecte, nu se schimbă

- Eticheta „Nu mai urmări" pe prescripții, și textul dialogului de confirmare, evaluat frază cu
  frază. Este unul dintre cele mai solide texte juridice din aplicație.
- Ordinea numerică a gravității (50/40/30/20/10/0), inclusiv `RASPUNS_SOMATIE` ultimul.
- Prescripția marcată ESTIMAT după comunicarea somației. În plus, data afișată este întotdeauna
  un prag minim sigur: termenul real nu poate fi mai devreme, doar mai târziu.
- Termenul expirat al debitorului scos din restanțe, în blocul „Deblocate".
- Textul despre ora împlinirii (CPC art. 182 alin. 1 și 2, art. 183 alin. 1) este corect și
  distinge corect cele trei situații.

## 7. Corectate deja, la 2026-07-26

- `deadlines.confirm.forfeiture.body`: CPC art. 186 cere **motive temeinic justificate**, nu
  „motiv mai presus de voința părții", care e standardul din vechiul cod. Textul afișat făcea
  repunerea în termen să pară mai greu de obținut decât este, deci putea determina un avocat să
  renunțe la un remediu real. Corectat în ro și en.
- `DeadlineConsequence::severityRank()` docblock: OUG 80/2013 art. 39 este reexaminarea
  **cuantumului** taxei, în 3 zile, nu un remediu împotriva anulării deja dispuse. Motivarea
  treptei se sprijină acum doar pe lipsa autorității de lucru judecat.
- `ANALIZA-PAGINA-TERMENE.md`: aceeași corecție, plus NCC art. 2556 înlocuit cu **art. 2554**
  („Prorogarea termenului"); art. 2556 e prezumția depunerii la poștă.
- `DeadlineService` docblock: CPC art. 181 alin. 4 nu există, termenele pe zile sunt la
  alin. 1 pct. 2.

## 8. Tehnic, minor

- Cod mort: ȘTERS. `DeadlineBlockage::actionRoute()`, `DeadlineBlockageReason::actionRoute()` și
  `actionRouteParameterName()` nu mai există, împreună cu testul care le exercita. Deep-link-ul a
  fost respins: rutele care înregistrează data sunt POST, deci un `path()` construit din ele ar
  răspunde 405, iar un deep-link real ar cere ca pagina de dosar să deschidă un dialog dintr-un
  parametru de query, adică stare de pagină nouă pentru un beneficiu marginal.
- `countUpcomingByUser`: REPARAT. Are acum limită inferioară (de azi inclusiv) și exclude
  dosarele terminale. `findUpcomingByUser` folosește exact același query builder, fiindcă lista
  se afișează sub un subtitlu care poartă contorul: dacă divergeau, subtitlul mințea. Ambele sunt
  apelate doar din `DashboardController`.
- `countAgendaBuckets()` rulează de două ori pe `/termene`, o dată pentru bară și o dată pentru
  badge. Recomandarea este să NU se repare: eliminarea cere cuplarea extensiei Twig de controller,
  iar cuplajul costă mai mult decât economisesc 2 query-uri din 15.

## 9. Amânate prin specificație, nu sunt datorie

Vederea Registru, export CSV, feed `.ics`, coloana de proveniență pe termen, operații în masă,
vederi salvate. Dintre ele, `.ics` are argumentul cel mai bun: nu concurăm cu agenda avocatului,
îi trimitem termenele în ea.
