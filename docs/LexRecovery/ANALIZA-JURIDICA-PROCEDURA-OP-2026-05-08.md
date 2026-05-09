# Analiză Juridică — Aliniere Spec LexRecovery cu Procedura Ordonanței de Plată

**Data**: 2026-05-08  
**Versiune**: 1.1 (2026-05-09 — corecții citări + 6 probleme noi după verificare independentă)  
**Verificare independentă**: 2026-05-09 (agenți `avocat-senior` + `lexrecovery-legal-reviewer` rulați în paralel)  
**Domeniu**: aliniere `PLAN-DEZVOLTARE-LEXRECOVERY.md` și `ANALIZA-FLUXURI-LEXRECOVERY.md` cu legislația română  
**Scope**: probleme rămase **necorectate după Faza 2** (care a remediat deja 5 blockere)  
**Output**: read-only — nu modifică cod sau documentația spec; servește drept registru de validare juridică

**Limitare metodologică (verificare 2026-05-09)**: `legislatie.just.ro` a fost indisponibil tehnic în sesiunea de verificare (502 / JavaScript nerendabil pentru text articole). Surse primare folosite:
- `codulcivil.ro` — verbatim pentru NCC (art. 1443, 1489, 1509, 1541, 2517, 2537, 2540)
- `juridice.ro` — verbatim pentru CPC (art. 1015, 1024) + jurisprudență (curier privat la somație)
- `legislatie.just.ro` — accesat parțial pentru OG 13/2011 (doc 131085) și Legea 72/2013 (doc 146555)
- `eur-lex.europa.eu` — Directiva 2011/7/UE
- **OUG 80/2013** — neverificat online; bazat pe cunoaștere training + verificare anterioară spec internă (2026-05-08); fără motiv de îndoială asupra taxei 200 RON pentru OP

Recomandare: re-verificare la prima disponibilitate `legislatie.just.ro` pentru M7 (Legea 304/2022 articol exact), m1 (Cod Fiscal numerotare actualizată), OUG 80/2013.

---

## Cuprins

- [Context](#context)
- [Metodologie](#metodologie)
- [Rezumat executiv](#rezumat-executiv)
- [A. Probleme CRITICE](#a-probleme-critice)
  - [C1 — Termen somație 30 vs 15 zile](#c1--termen-somație-30-zile-spec-vs-15-zile-cpc-art-1015-)
  - [C2 — Terminologie "cerere în anulare"](#c2--terminologie-ambiguă-contestatie--contestata-în-loc-de-cerere-în-anulare-cpc-art-1024-)
  - [C3 — Atribuire articol legal pentru +8 pp](#c3--atribuire-articol-legal-incorectă-pentru-8-puncte-procentuale-)
  - [C4 — "Conținut declarat" lipsă](#c4--conținut-declarat-lipsă-din-modul-de-comunicare-somație-cpc-art-1015-)
  - [C5 — Prorogare termene CPC art. 181](#c5--prorogare-termene-la-zile-nelucrătoare-lipsă-cpc-art-181-)
- [B. Probleme MEDII](#b-probleme-medii)
- [C. Probleme MICI](#c-probleme-mici)
- [D. Validare avocat — listă prioritizată](#d-validare-avocat--listă-prioritizată)
- [E. Impact pe pașii deja implementați](#e-impact-pe-pașii-deja-implementați)
- [F. Impact pe pașii viitori](#f-impact-pe-pașii-viitori-2229)
- [G. Concluzii operaționale](#g-concluzii-operaționale)
- [H. Probleme adăugate la verificare 2026-05-09](#h-probleme-adăugate-la-verificare-2026-05-09)

---

## Context

Analiză de aliniere juridică a documentelor de spec LexRecovery (`docs/LexRecovery/PLAN-DEZVOLTARE-LEXRECOVERY.md` și `docs/LexRecovery/ANALIZA-FLUXURI-LEXRECOVERY.md`) cu legislația română pentru procedura de recuperare creanțe prin **ordonanță de plată** (CPC art. 1014–1025), **dobânda legală** (OG 13/2011, Legea 72/2013), **taxa de timbru** (OUG 80/2013) și **prescripția** (NCC art. 2517, 2540).

Această analiză merge **dincolo de Faza 2** (care a corectat deja 5 blockere — formula CIVIL = `(BNR+8)×0.80`, taxă timbru 200 RON fix, raza teritorială pe localitate, scope OCR, ANAF în loc de ONRC). Pas 2.1 (`f9e84dc`) și Pas 2.2 (`8cffd87`) sunt deja DONE și nu sunt invalidate de raport.

Output-ul e un raport read-only — codul și documentele existente NU se modifică. Severitate acoperită: **toate** (CRITIC + MEDIU + MIC), cu marker pentru ce necesită validare avocat extern.

## Metodologie

- Citate exacte din spec cu numere de linie (`PLAN` și `ANALIZA`).
- Norma legală citată (CPC, OG, NCC, OUG) cu articol specific.
- Severitate: **CRITIC** (eroare care duce la respingere dosar / nulitate / interpretare greșită a procedurii) → **MEDIU** (lacună de procedură care va apărea în execuție) → **MIC** (nuanță / îmbunătățire / clarificare).
- Marker ⚖️ = necesită validare avocat extern înainte de orice corecție.

---

## Rezumat executiv

| Severitate | Nr. | Marker validare ⚖️ |
|---|---|---|
| CRITIC | 5 | 4 din 5 |
| MEDIU | 10 | 6 din 10 |
| MIC | 8 | 4 din 8 |
| Probleme noi (verificare 2026-05-09) — secțiunea H | 6 | 2 din 6 |
| **TOTAL** | **29** | **16 ⚖️** |

Cele 5 probleme **CRITICE** se referă la termen somație, terminologie cale de atac, atribuire articol legal, mod comunicare somație, prorogare termene la sărbători. Niciuna nu invalidează codul deja livrat (Pas 2.1 — InterestCalculator, Pas 2.2 — StampDuty), dar **două afectează enum-uri și constante** care urmează să fie folosite în Pașii 4.1, 5.1, 5.2 (DeadlineService, somație, cerere OP).

---

## A. Probleme CRITICE

### C1 — Termen somație: 30 zile (spec) vs **15 zile** (CPC art. 1015) ⚖️

**Citate din spec**:
- `PLAN` linia 1039: `Termen răspuns: **30 zile calendaristice**`
- `ANALIZA` linia 131: `Termen plată 30 zile — de la comunicare`
- `ANALIZA` linia 437: tabel `RASPUNS_SOMATIE | paymentNoticeDate + 30 zile | HIGH`

**Norma legală corectă**:
> CPC art. 1015 alin. (1): *"Creditorul îi va comunica debitorului, prin intermediul executorului judecătoresc sau prin scrisoare recomandată, cu conținut declarat și confirmare de primire, **o somație, prin care îi va pune în vedere să plătească suma datorată în termen de 15 zile de la primirea acesteia**."*

**Origine probabilă a erorii în spec**: confuzie cu Legea 72/2013 art. 3 alin. (1) — *"termenul de plată este de 30 de zile calendaristice"* — care e termenul **supletiv contractual** între profesioniști pentru declanșarea dobânzii penalizatoare, **NU** termenul somației CPC 1015.

**Impact**:
- DeadlineType `RASPUNS_SOMATIE` cu durată 30 zile (Pas 4.1) → calculează termen greșit.
- Somația generată (Pas 5.1) va menționa "15 zile" în text (per template legal), DAR sistemul va planifica `LegalDeadline` la +30 zile → tranziție `depune_cerere` permisă prematur sau, invers, depunere prea târziu.
- Prematur: dacă depui cererea OP în 16-29 zile după somație, debitorul ar putea invoca lipsă procedură prealabilă (deja era de drept să pată în 15z).

**Recomandare**: schimb `RASPUNS_SOMATIE = +15 zile`. Adițional, oferă opțiune avocat pentru "termen extins" (uzanță practică unele somații dau 15-30 zile la latitudinea creditorului — legea cere **minim** 15 zile).

⚖️ **Validare avocat**: confirmă dacă termenul minim 15 zile e și termenul standard sau dacă uzanța admite termene mai lungi pentru rigurozitate.

---

### C2 — Terminologie ambiguă: "CONTESTATIE" / "CONTESTATA" în loc de **"cerere în anulare"** (CPC art. 1024) ⚖️

**Citate din spec**:
- `PLAN` linia 365: enum `CaseStatus::CONTESTATA`
- `PLAN` linia 366: `CaseTransition::contesta`, `respinge_contestatie`, `admite_contestatie`
- `PLAN` linia 1041: `Termen contestație: **10 zile calendaristice**`
- `PLAN` linia 1191: "Marcaj automat DEFINITIVA dacă termen expirat și fără contestație"
- `ANALIZA` linia 167: `APPEAL_FILED → contesta`
- `ANALIZA` linia 440: `CONTESTATIE | rulingDate + 10 zile | CRITICAL`

**Norma legală corectă**:
> CPC art. 1024: *"(1) Împotriva ordonanței de plată debitorul poate formula **cerere în anulare** în termen de 10 zile de la data înmânării sau comunicării acesteia. (2) Cererea în anulare poate fi introdusă și de creditor, dacă privește încheierile prevăzute la art. 1.022 alin. (1) și (2). (3) Cererea în anulare se soluționează de instanța care a pronunțat ordonanța de plată, în complet format din 2 judecători."*

**Confuzie de terminologie**:
- "Contestație" se referă la 2 căi de atac diferite în Cod: **contestația la executare** (art. 712+) — NU se aplică aici — și **contestația în anulare** (art. 503+) — cale extraordinară, nu e ce se întâmplă la OP.
- "**Cerere în anulare**" e termenul EXACT pentru calea de atac împotriva ordonanței de plată (CPC 1024).

**Impact**:
- Enum-uri (`CaseStatus`, `CaseTransition`, `DeadlineType`) folosesc terminologie greșită → texte UI, email-uri, audit log, PDF-uri vor afișa "contestație" → ambiguitate juridică pentru avocați.
- Template-uri PDF (Pas 5.1, 5.2) și email-uri (Pas 6.3) vor folosi terminologia greșită.
- Termenul de 10 zile e **CORECT**, dar se aplică pentru "cerere în anulare", nu "contestație".

**Recomandare**:
- Rename enums: `CaseStatus::CONTESTATA` → `CaseStatus::IN_ANULARE`, `CaseTransition::contesta` → `formuleaza_cerere_anulare`, `DeadlineType::CONTESTATIE` → `DeadlineType::CERERE_IN_ANULARE`.
- Sau, alternativ, păstrează enums cu nume tehnice neutre (ex: `APPEAL_FILED`) și mapează în i18n la "cerere în anulare".
- Documentația spec (`PLAN` și `ANALIZA`) trebuie să folosească exclusiv "cerere în anulare" în text liber.

⚖️ **Validare avocat**: e oportun rename-ul enum-urilor sau e suficient ca i18n să afișeze "cerere în anulare" peste cheia `CONTESTATA`?

---

### C3 — Atribuire articol legal incorectă pentru "+8 puncte procentuale" ⚖️

**Citate din spec**:
- `PLAN` linia 511, 555: formula `BNR + 8 pp` atribuită lui `OG 13/2011 art. 3`
- `ANALIZA` linia 314: `dobânda = suma × (rata_BNR + 8%) × zile / 365` cu mențiune `OG 13/2011 art. 3 alin. 2`

**Norma legală corectă**:
- **OG 13/2011 art. 3 alin. (2)** prevede: *"Rata dobânzii legale penalizatoare se stabilește la nivelul ratei dobânzii de referință **plus 4 puncte procentuale**."*
- **OG 13/2011 art. 3 alin. (2¹)** (introdus prin **Legea 72/2013 art. 20**, Capitolul IX Dispoziții tranzitorii și finale; transpune Directiva 2011/7/UE): *"În raporturile dintre profesioniști și între aceștia și autoritățile contractante, dobânda legală penalizatoare se stabilește la nivelul ratei dobânzii de referință **plus 8 puncte procentuale**."*

> **Notă metodologică verificare 2026-05-09**: textul alin (2¹) este citat verbatim din **Legea 72/2013 art. 20** (verificat pe `legislatie.just.ro` doc 146555 — fetched 2026-05-09). În prezent, **forma consolidată a OG 13/2011** afișată pe `legislatie.just.ro` (doc 131085, inclusiv FormaPrintabila) **nu reflectă încă această modificare** — afișează doar alin (1)-(4) fără (2¹). Aceasta pare problemă de actualizare a consolidării pe portal, nu problemă de drept material — Legea 72/2013 e în vigoare, art. 20 e în vigoare, alin (2¹) se aplică de către instanțe din 2013. Avocații care verifică pe portal trebuie să consulte direct Legea 72/2013 (doc 146555), nu consolidatul OG 13/2011.

**Impact**:
- Formula `BNR + 8` e **CORECTĂ pentru raporturi între profesioniști** (cazul tipic LexRecovery — recuperare creanțe B2B), dar atribuirea spec-ului la art. 3 alin. (2) standard e **eronată**.
- Pentru raporturi **profesional-consumator** (ex: bancă vs. PF), rata penalizatoare e **BNR + 4 pp**, nu BNR + 8.
- Codul (Pas 2.1) aplică `BNR + 8` în branch-ul `COMERCIAL` și `(BNR+8)×0.80` în branch-ul `CIVIL`. Dacă "CIVIL" e interpretat ca raport non-profesional (ex: PF-PF, sau profesionist-consumator), formula `(BNR+8)×0.80` e **GREȘITĂ** — ar trebui `(BNR+4)×0.80`.

**Cauza confuziei**:
Enum-ul `RelationshipType::COMERCIAL | CIVIL` colapsează 3 cazuri legale distincte:
1. **Profesionist ↔ profesionist** (B2B): BNR + 8 pp (alin. 2¹).
2. **Profesionist ↔ consumator** (B2C): BNR + 4 pp (alin. 2 standard).
3. **Particular ↔ particular** (P2P): BNR + 4 pp diminuat 20% = `(BNR+4) × 0.80` (alin. 3).

Spec curent tratează doar 1 vs 3 (omițând 2).

**Recomandare**:
- Extinde enum la 3 valori: `B2B_PROFESIONAL` / `B2C_CONSUMER` / `NON_PROFESIONAL`.
- Sau (mai simplu) păstrează `COMERCIAL/CIVIL` dar precizează în spec că:
  - `COMERCIAL` = B2B între profesioniști (cu Legea 72/2013), NU "comercial" în sensul larg.
  - Adaugă caz `B2C_PROFESIONAL` separat (BNR + 4) pentru când creditorul profesionist recuperează de la consumator.
- Atribuie corect articolul: pentru BNR + 8 → **OG 13/2011 art. 3 alin. (2¹)** + Legea 72/2013.

**Impact pe Pas 2.1 implementat**: dacă MVP scope = doar recuperare B2B (cazul tipic în LexRecovery), formulele actuale sunt OK, dar **scope-ul trebuie documentat explicit**: "Doar raporturi între profesioniști — pentru cazuri profesionist-consumator post-MVP".

**Nuanță critică adăugată la verificare 2026-05-09** (sursa: `avocat-senior` cu verificare verbatim OG 13/2011 + Legea 72/2013 art. 20): chiar dacă scope-ul MVP rămâne strict B2B, formula actuală `CIVIL+PENALIZATOARE = (BNR+8)×0.80` din `RelationshipType::applicableRate` rămâne **fără temei legal** — în OG 13/2011 nu există nicio combinație care să atribuie simultan factorul +8pp (alin 2¹, exclusiv profesional) și diminuarea 20% (alin 3, exclusiv non-profesional). Două opțiuni de fix la **Pas 4.x** (decizie strategică amânată):

- **(a) Restrângere la B2B** (preferat dacă scope confirmat strict B2B): ramura CIVIL aruncă `UnsupportedRelationshipException` cu mesaj clar — formula inventată actuală e eliminată, nu ascunsă.
- **(b) Implementare 3 ramuri B2B / B2C / P2P** cu formulele legal corecte:
  - B2B PEN: `BNR + 8` (alin 2¹) — REM: `BNR` (alin 1)
  - B2C PEN: `BNR + 4` (alin 2) — REM: `BNR` (alin 1)
  - P2P PEN: `(BNR + 4) × 0.80` (alin 2 + alin 3) — REM: `BNR × 0.80` (alin 1 + alin 3)

Decizia rămâne strategică (depinde de scope produs confirmat cu avocat-utilizator) — **nu se modifică Pas 2.1 acum**.

⚖️ **Validare avocat**: cazurile de uz reale ale platformei (avocat recuperează B2B, B2C, P2P sau toate trei?). Decide ce ramuri sunt necesare în MVP.

---

### C4 — "Conținut declarat" lipsă din modul de comunicare somație (CPC art. 1015) ⚖️

**Citate din spec**:
- `PLAN` linia 127: `Avocatul descarcă PDF, semnează, trimite debitorului prin executor judecătoresc/poștă/curier`
- `ANALIZA` linia 127: identic

**Norma legală corectă**:
> CPC art. 1015 alin. (1): *"prin intermediul executorului judecătoresc sau prin **scrisoare recomandată, cu conținut declarat și confirmare de primire**"*

**Definiție "conținut declarat"** (regulament poștal): expeditorul depune la oficiul poștal copie identică a documentului trimis; oficiul certifică conținutul pe duplicat — dovadă irefutabilă a conținutului scrisorii.

**Impact**:
- Spec menționează generic "scrisoare recomandată" și "curier" — DAR curierul privat (Cargus, FAN, DPD, etc.) **NU oferă serviciu de conținut declarat** (e exclusiv Poșta Română).
- Dacă debitorul contestă conținutul somației ("nu am primit somația, am primit o hârtie albă"), creditorul nu are dovadă fără conținut declarat.
- DocumentType `DOVADA_COMUNICARE` (Pas 1.2) e prea generic — nu validează că dovada întrunește cerințele CPC 1015.

**Recomandare**:
- Spec trebuie să precizeze cele 2 modalități legale: (a) executor judecătoresc, (b) scrisoare recomandată cu conținut declarat de la **Poșta Română**.
- UI Pas 5.1 (generare somație) trebuie să afișeze un memento avocat cu instrucțiuni concrete: "*Comunică prin executor SAU scrisoare recomandată cu conținut declarat (R+CD) la Poșta Română. Curierul privat NU e admisibil.*"
- DocumentType nou opțional: `DOVADA_COMUNICARE_AR_CD` (cu confirmare AR + CD) vs simplă `DOVADA_COMUNICARE`.

⚖️ **Validare avocat**: e necesară diferențiere fină în spec, sau e suficient ghid avocat în UI?

---

### C5 — Prorogare termene la zile nelucrătoare lipsă (CPC art. 181) ⚖️

**Citate din spec**:
- `PLAN` linia 1039-1041: termene exprimate doar în "zile calendaristice"
- `PLAN` linia 1191: `Marcaj automat DEFINITIVA dacă termen expirat`
- `ANALIZA` linia 180: `app:check-deadlines aplică tranziție marcheaza_definitiva`
- `ANALIZA` linia 449: `Dacă deadlineDate < azi (expirat) → email`

**Norma legală corectă**:
> CPC art. 181 alin. (1) pct. 2: *"Când termenul se socotește pe zile, ziua în care a început să curgă, precum și ziua când acesta s-a împlinit nu se socotesc."* (calcul "exclusiv-exclusiv")
>
> CPC art. 181 alin. (2): *"Termenul care **se sfârșește într-o zi de sărbătoare legală sau când serviciul este suspendat se va prelungi până la sfârșitul primei zile de lucru următoare**."*

**Impact**:
- Spec definește termen "10 zile calendaristice" pentru cererea în anulare — DAR dacă a 10-a zi cade sâmbătă/duminică/sărbătoare legală (Paște, Crăciun, 1 Mai, etc.), termenul se prelungește la prima zi lucrătoare.
- Tranziția automată `marcheaza_definitiva` (Pas 6.2) poate fi **prematură** dacă cron-ul rulează duminica seara și ziua 10 cade luni — cererea în anulare poate fi încă depusă luni!
- Aceeași problemă pentru `RASPUNS_SOMATIE` (15z), `PRESCRIPTIE` (3 ani), etc.

**Recomandare**:
- `DeadlineService` (Pas 4.1) trebuie să implementeze metodă `nextWorkingDay(DateTimeImmutable $candidate): DateTimeImmutable` care prorogă la prima zi lucrătoare următoare.
- Lista sărbătorilor legale conform Codului Muncii art. 139: 1-2 ian, 24 ian, Vinerea Mare + Paștele (calendar ortodox), 1 mai, 1 iun, Rusalii (calendar ortodox), 15 aug, 30 nov, 1 dec, 25-26 dec — și posibil zile speciale per OG.
- Prag de toleranță pentru tranziție automată `marcheaza_definitiva`: 1-2 zile suplimentare buffer (mai bine să marcăm definitiv târziu decât prematur).

⚖️ **Validare avocat**: confirmă regulile prorogării termenelor procedurale OP. Sărbătorile legale se aplică integral CPC 181? Există nuanțe pentru dosare cu termene critice (ex: zilele de luni-week-end = "weekend extins" în vacanță judecătorească iulie-august)?

---

## B. Probleme MEDII

### M1 — Lipsă etapă **întâmpinare debitor** în workflow (CPC art. 1018) ⚖️

**Citate din spec**: niciuna — workflow trece direct `DOSAR_INREGISTRAT → TERMEN_FIXAT → ORDONANTA_EMISA` fără stadiul în care debitorul depune întâmpinare.

**Norma legală corectă**:
> CPC art. 1018 alin. (3): *"Întâmpinarea nu este obligatorie. Dacă debitorul contestă creanța, instanța va verifica dacă contestația este întemeiată, în baza înscrisurilor aflate la dosar și a explicațiilor și lămuririlor părților."*

**Impact**:
- Dacă debitorul depune întâmpinare contestând creanța (parțial sau total), portalul.just.ro ar putea publica eveniment "ÎNTAMPINARE DEPUSĂ" — sistem nu are logica de detecție/tranziție.
- Avocatul nu primește alertă explicită: trebuie să verifice manual portal-ul pentru întâmpinare.
- Status implicit "TERMEN_FIXAT" rămâne valid, DAR fluxul de informare e lacunar.

**Recomandare**:
- Adăugare event portal `OBJECTION_FILED` (sau rename `APPEAL_FILED` la fază pre-ordonanță vs post-ordonanță).
- Notificare email: "Debitor a depus întâmpinare — verifică conținut".
- Opțional, status nou `INTAMPINARE_DEPUSA` (intermediar între TERMEN_FIXAT și ORDONANTA_EMISA) — dar poate fi doar metadată pe `LegalCase`, nu state separat.

⚖️ **Validare avocat**: necesar status separat sau notificare e suficientă?

---

### M2 — Lipsă **cheltuieli de judecată** în cererea OP

**Citate din spec**:
- `PLAN` linia 1121: cerere OP cu "principal+dobândă+cheltuieli" — *MENȚIUNE DAR LIPSĂ DETALII*
- `ANALIZA` linia 110: `Date creanță: amount, contractualInterest, penalties` — fără cheltuieli

**Norma legală**:
> CPC art. 451-453: cheltuielile de judecată se includ în pretenția dosarului. Pentru OP, taxa timbru + onorariu avocat (rezonabil) + alte cheltuieli necesare sunt parte din pretenție.

**Impact**:
- DTO `Step3ClaimData` (Pas 3.1) nu are câmp `legalCosts`.
- Cererea OP generată (Pas 5.2) NU va include cheltuielile → instanța le poate respinge dacă nu sunt cerute.
- Avocatul trebuie să introducă onorariul + taxa timbru într-un câmp "alte cheltuieli", iar PDF-ul cerere OP trebuie să le însumeze separat.

**Recomandare**:
- Step 3 wizard: câmpuri opționale `attorneyFee`, `bailiffFee`, `otherLegalCosts`.
- Cerere OP afișează tabel pretenții: principal | dobândă | penalități contractuale | taxa timbru | onorariu avocat | TOTAL.
- Total al pretenției = principal + dobândă + cheltuieli — afișat clar.

---

### M3 — Lipsă **plăți parțiale** din partea debitorului

**Citate din spec**: niciuna directă — workflow asumă debitorul plătește integral sau nimic.

**Realitate practică**:
- Foarte des, debitorul plătește parțial (ex: 50% din principal), apoi avocatul actualizează creanța.
- NCC art. 1509: ordinea imputației plăților în lipsa convenției — întâi cheltuielile/dobânzi, apoi capital.

**Impact**:
- LegalCase nu are evidență `paidAmount`, `remainingAmount`, `paymentHistory`.
- Dobândă recalculată dinamic la sumă diminuată — `InterestCalculatorService` (Pas 2.1) nu suportă scenariu "amount variabil în timp".
- Workflow nu are tranziție "PLATA_PARTIALA" sau status "PARTIAL_RECUPERAT".

**Recomandare**:
- Entity nouă `Payment` (subtilă — diferită de `Invoice` din monetizare): `legalCase_id`, `amount`, `paidAt`, `notes`, `imputatie` (CHELTUIELI/DOBANDA/CAPITAL).
- Workflow: status terminal `INCHIS_PARTIAL` (alături de `INCHIS_SUCCES`, `INCHIS_PARTIAL_INSOLVABIL`).
- UI: tab "Plăți" în view dosar; butoane "Înregistrează plată".
- Recalcul dobândă pe segmente: `[dueDate, payment1] cu amount0`, `[payment1, payment2] cu amount0-payment1`, etc.

⚖️ **Validare avocat**: nu necesită neapărat — flow standard de practică.

---

### M4 — Lipsă **împuternicire avocațială** ca anexă obligatorie ⚖️

**Citate din spec**: niciuna — anexele cererii OP sunt menționate generic.

**Norma legală**:
> CPC art. 85 alin. (3): *"Avocatul, în temeiul împuternicirii avocațiale, poate face orice acte de dispoziție și de procedură în condițiile legii."*
>
> Practica: la depunerea cererii OP, instanța solicită copia **împuternicirii avocațiale** (formularul standard al baroului) și **delegația de reprezentare**.

**Impact**:
- DocumentType nu include `IMPUTERNICIRE_AVOCATIALA` sau `DELEGATIE`.
- Opisul (Pas 5.2) nu enumeră aceste documente ca obligatorii.
- ZIP-ul instanță generat va fi incomplet → registratura solicită completări → lipsă timp și risc respingere termenului 45z (CPC 1019).

**Recomandare**:
- Adăugare valori enum: `DocumentType::IMPUTERNICIRE_AVOCATIALA`.
- UI step 0 sau modal pre-depunere cerere OP: "Atașează împuternicirea avocațială (PDF)".
- Validator: înainte de tranziție `depune_cerere`, verifică prezența documentului.

⚖️ **Validare avocat**: confirmă că împuternicirea + delegația sunt obligatorii la OP. (Particularitate: pentru avocații care au împuternicire generală în baro, se aplică doar formular bar; pentru cazuri speciale, delegație separată.)

---

### M5 — Lipsă termenul "**6 luni**" pentru întreruperea prescripției prin somație (NCC art. 2540) ⚖️

**Citate din spec**:
- `PLAN` linia 116: `LegalDeadline tip PRESCRIPTIE creat (data scadență + 3 ani)`
- `ANALIZA` linia 327: `prescripție 3 ani — NU se verifică în InterestCalculatorService`

**Norma legală corectă**:
> NCC art. 2540: *"Prescripția este întreruptă prin punerea în întârziere a celui în folosul căruia curge, **dacă punerea în întârziere este urmată de chemarea în judecată în termen de 6 luni** de la data acesteia."*
>
> CPC art. 1015 alin. (2): *"Această somație întrerupe prescripția extinctivă potrivit dispozițiilor art. 2.540 din Codul civil"*.

**Impact**:
- Somația întrerupe prescripția DOAR DACĂ urmează cerere OP în 6 luni.
- Sistemul actual creează `LegalDeadline::PRESCRIPTIE` la `dueDate + 3 ani`, dar NU monitorizează termenul de 6 luni post-somație.
- Dacă avocatul trimite somație și amână cererea OP > 6 luni, prescripția continuă să curgă din scadența originală — risc creanță prescrisă fără să-și dea seama.

**Recomandare**:
- `DeadlineService` (Pas 4.1): la tranziția `trimite_somatie`, creare suplimentar `DeadlineType::TERMEN_DEPUNERE_OP_INTRERUPERE_PRESCRIPTIE` cu data = `paymentNoticeDate + 6 luni`, prioritate `CRITICAL`.
- Alert avocat: "Pentru a păstra întreruperea prescripției, depune cererea OP până la [dată]".
- La tranziția `depune_cerere`, marchează deadline-ul ca `completed` (cererea introdusă în judecată întrerupe definitiv prescripția per NCC art. 2537).

⚖️ **Validare avocat**: confirmă cu siguranță că întreruperea prescripției prin somație CPC 1015 e condiționată de 6 luni — există jurisprudență care interpretează diferit?

---

### M6 — Lipsă **termen 45 zile soluționare** OP (CPC art. 1019) ⚖️

**Citate din spec**: niciuna.

**Norma legală corectă**:
> CPC art. 1019 alin. (3): *"Ordonanța de plată se va emite în termen de **cel mult 45 de zile de la introducerea cererii**."*

**Impact**:
- Avocatul nu are alertă dacă instanța întârzie peste 45z — caz în care ar putea recurge la cerere reexaminare sau alt instrument.
- LegalDeadline nu are tip `TERMEN_SOLUTIONARE_INSTANTA` (45z post depunere).

**Recomandare**:
- Adăugare `DeadlineType::TERMEN_SOLUTIONARE` la tranziția `inregistreaza_dosar`.
- Alert soft (informativ, nu blocant): "Au trecut 45 zile fără emitere ordonanță — verifică portal".

⚖️ **Validare avocat**: relevant pentru avocat? Sau e doar informativ?

---

### M7 — Tribunale specializate — opt-in confuz și incomplet ⚖️

**Citate din spec**:
- `ANALIZA` linia 371: `Cluj, Mureș, Argeș — NU se aplică default; necesită opt-in explicit pentru raporturi între profesioniști (post-MVP)`

**Realitate**:
- Tribunalele Specializate Cluj, Mureș, Argeș sunt înființate prin **Legea nr. 304/2022 privind organizarea judiciară** (în vigoare 2022, abrogă Legea 304/2004) și au competență exclusivă pe raporturi între profesioniști în jurisdicțiile respective.
- Pentru creanță > 200.000 RON din raport B2B în Cluj: competența e Tribunalul Specializat Cluj (NU Tribunalul Cluj). Sistemul trebuie să facă routing corect.

> **Corecție citare 2026-05-09** (confirmare independentă ambii agenți): versiunea inițială a acestei secțiuni cita "Legea 304/2004 art. 36 alin. (3)" — act **abrogat**. Referința legală corectă în vigoare 2026 este Legea 304/2022. Articolul exact pentru tribunalele specializate din noua lege rămâne de verificat la prima disponibilitate `legislatie.just.ro`. Tribunalele Specializate Cluj/Mureș/Argeș există și funcționează în continuare, dar codul care implementează routing nu trebuie să citeze actul abrogat.

**Impact**:
- `CompetentCourtResolver` (Pas 2.3) — dacă routing e doar pe județ + valoare, pentru cele 3 județe cu tribunale specializate, va alege greșit instanța.

**Recomandare**:
- Court entity: câmp `isSpecialized` (boolean) și `specializedFor` (enum: PROFESIONALI, MARITIM, etc.).
- Resolver: dacă `relationshipType = COMERCIAL/B2B` și county ∈ {Cluj, Mureș, Argeș} și amount > 200.000 → Tribunalul Specializat (NU Tribunalul standard).

⚖️ **Validare avocat**: interpretare jurisdicțională (sunt cele 3 tribunale specializate exclusive sau alternative? jurisprudența curentă?).

---

### M8 — Workflow incomplet: tranziții lipsă

**Stări/tranziții lipsă**:

(a) **DOSAR_INREGISTRAT → RESPINSA** la verificare regularitate (CPC art. 200): după depunere, instanța verifică formal cererea; dacă lipsesc anexe sau e neclar, dispune anularea cererii.

(b) **CERERE_DEPUSA → DOSAR_INREGISTRAT_INCOMPLET → RESPINSA**: dacă registratura cere completări (în 10 zile) și avocatul nu le furnizează → cererea se anulează.

(c) **TERMEN_FIXAT → AMANARE**: instanța amână ședința.

(d) **ORDONANTA_EMISA → COMUNICATA**: stadiul intermediar de la emitere până la comunicare debitorului (poate dura zile-săptămâni). Termenul de 10z pentru cerere în anulare începe doar de la **comunicare**, nu de la emitere — vezi M9.

**Impact**:
- Workflow actual asumă fluxul "fericit" — dar pierde scenarii frecvente practic.

**Recomandare**:
- Adăugare opțională a stadiilor de mai sus (sau metadată pe `LegalCase` pentru amânări/comunicare ordonanță).

---

### M9 — Termen cerere în anulare: de la **comunicare**, nu de la emitere ⚖️

**Citate din spec**:
- `PLAN` linia 1041: `Termen contestație: 10 zile calendaristice`
- `ANALIZA` linia 165: `creare LegalDeadline CONTESTATIE (10 zile)` la tranziția `emite_ordonanta`

**Norma legală corectă**:
> CPC art. 1024 alin. (1): *"Împotriva ordonanței de plată debitorul poate formula cerere în anulare în termen de 10 zile **de la data înmânării sau comunicării acesteia**."*

**Impact**:
- Sistemul creează termenul la `rulingDate` (data emiterii). DAR data comunicării poate fi cu zile-săptămâni mai târziu (instanța comunică prin executor sau poștă).
- Marcaj automat `marcheaza_definitiva` la `rulingDate + 10z` poate fi prematur.

**Recomandare**:
- Adăugare câmp `LegalCase::rulingCommunicationDate` (date, nullable).
- Avocatul completează manual data comunicării.
- Termenul cererii în anulare = `rulingCommunicationDate + 10z` (cu prorogare CPC 181 conf. C5).
- Tranziție `marcheaza_definitiva` blocată dacă `rulingCommunicationDate IS NULL`.

⚖️ **Validare avocat**: data comunicării e mereu disponibilă pe portal? Sau avocatul trebuie să o introducă manual?

---

### M10 — Lipsă **anatocism procesual** (NCC art. 1489 alin. (2) teza finală) ⚖️

> **Corecție citare 2026-05-09** (verificat verbatim `codulcivil.ro` 2026-05-08): art. 1489 NCC are **doar 2 alineate**, nu 3. Norma anatocismului procesual ("dobânzile curg numai de la data cererii de chemare în judecată") este la **art. 1489 alin. (2) teza finală**, nu la "alin. (3)" cum apărea în versiunea inițială. Fondul problemei rămâne corect — doar referința de alineat era greșită.

**Citate din spec**:
- `PLAN` linia 547: `dobândă simplă, NU compusă (art. 1489 NCC — anatocism necesită convenție expresă)`
- `ANALIZA` linia 323: identic

**Norma legală corectă**:
> NCC art. 1489 alin. (2): *"Dobânzile scadente produc ele însele dobânzi numai atunci când legea sau contractul, în limitele permise de lege, o prevede ori, în lipsă, atunci când sunt cerute în instanță. **În acest din urmă caz, dobânzile curg numai de la data cererii de chemare în judecată**."*

**Impact**:
- Spec acuzat că NU permite anatocism — DAR NCC explicit permite anatocism PROCESUAL (de la data cererii de chemare în judecată) chiar fără convenție contractuală.
- Sistemul actual aplică dobândă simplă pe toată perioada — pentru perioada post-cerere OP, ar putea aplica dobândă la dobânda acumulată la data cererii.

**Recomandare**:
- Spec trebuie să clarifice: "Pentru perioada **anterioară** cererii OP — dobândă simplă; pentru perioada **post cerere** — dobândă cu posibilitate anatocism (la cererea creditorului în acțiune)."
- `InterestCalculatorService` poate primi flag opțional `enableProceduralAnatocism = true` pentru a calcula dobândă pe `principal + dobandaAcumulataLaCerereData` în perioada `[cerereDate, plataDate]`.

⚖️ **Validare avocat**: avocații cer practic anatocism procesual la OP? E uzanță sau excepție?

---

## C. Probleme MICI

### m1 — TVA pe dobândă neclarificat ⚖️

**Citate**: `PLAN` linia 569 nu menționează; `ANALIZA` nu menționează.

**Norma**:
- Cod Fiscal art. 286 alin. (4) lit. e): nu se include în baza de impozitare TVA *"dobânzile, după data scadenței, percepute pentru plățile cu întârziere"* — deci **dobânda penalizatoare e SCUTITĂ TVA**.
- Dobânda remuneratorie: scutită de TVA conform art. 292 alin. (2) lit. a) (operațiuni financiare).

**Impact**: minor — calculator afișează doar suma dobânzii. Daca avocatul facturează clientul, separă TVA-ul.

**Recomandare**: clarificare în spec — dobânda nu se TVA-ează în pretenția dosarului.

⚖️ **Validare avocat**: confirmă pentru orice caz de uz.

---

### m2 — **Multipli debitori** / debit solidar — workflow limitat

**Citate**:
- `PLAN` linia 906-908: `Step2DebtorsType (CollectionType)` — multiple debitori la nivel de DTO.
- `ANALIZA` linia 109: `Pasul 2: Completare Debitor(i) — posibil multipli`

**Realitate**:
- Dacă există 2-3 codebitori solidari, plata unuia stinge obligația tuturor (NCC art. 1443).
- Cererea OP se poate face în solidar contra tuturor — DAR jurisprudența cere ca toți să fie citați.

**Impact**:
- Workflow `LegalCase` are status unitar — dacă un debitor plătește, statusul nu reflectă plată parțială per debitor (interconectat cu M3).
- ANAF lookup, BPI, OpAdmissibilityValidator (Pas 2.4) trebuie să ruleze **per debitor**, nu per LegalCase.

**Recomandare**:
- Validatorii (ANAF, BPI, etc.) iterează pe lista debitorilor.
- UI afișează tabel cu status per debitor.

---

### m3 — Penalități contractuale: tratament cumul cu dobândă ⚖️

**Citate**: `PLAN` linia 314 (`penalties` opțional), `ANALIZA` linia 110.

**Realitate**:
- NCC art. 1538 (clauză penală) — sancțiune contractuală.
- **Reducere instanță: NCC art. 1541** — instanța poate reduce penalitatea vădit excesivă față de prejudiciul ce putea fi prevăzut de părți la încheierea contractului. (Art. 1538 reglementează clauza penală ca instituție; reducerea e la art. 1541, nu la "art. 1538 alin. (2)" cum apărea în versiunea inițială — verificat verbatim `codulcivil.ro` 2026-05-08.)
- Cumul cu dobândă legală — admis în limite, dar instanța poate reduce.

**Recomandare**: `Step3ClaimData` permite penalități contractuale + dobândă; cerere OP afișează ambele coloane.

⚖️ **Validare avocat**: necesare scoring/avertizare automat dacă penalități > 100% din creanță (suspect)?

---

### m4 — **Depunere electronică** (e-procedură) lipsă

**Citate**: `PLAN` linia 143: avocatul depune fizic dosarul.

**Realitate**:
- Multe instanțe acceptă depunere electronică prin **portal.just.ro / SmartGate**.
- Avocații preferă depunere electronică (timp scurt, dovadă imediată).

**Recomandare**:
- Post-MVP: integrare cu SmartGate (necesită API instituțional).
- MVP: documentație clară "ZIP-ul se depune fizic la registratură".

---

### m5 — Ani bisecți tratament neclar

**Citate**: `PLAN` linia 547 — `days / 365`.

**Realitate**:
- Calcul dobândă în an bisect (366 zile): standardul este `days / 365` indiferent. DAR există argumente pentru `days / actuals` (act/act) — neuzual în jurisprudență RO.

**Recomandare**: păstrează `act/365` (uzanță), documentează în spec.

---

### m6 — **Imputația plăților parțiale** (NCC art. 1509)

**Citate**: spec nu discută.

**Norma**:
> NCC art. 1509: *"Plata parțială (...) se impută succesiv, în următoarea ordine: cheltuielile, dobânzile (în ordinea — penalizatoare, apoi remuneratorie), apoi capitalul."*

**Recomandare**: la implementare M3 (plăți parțiale), aplica imputația automat per art. 1509.

---

### m7 — Cerere reexaminare (CPC art. 200 alin. 4) — lipsă

**Realitate**: dacă cererea OP e anulată la regularitate, avocatul poate face cerere reexaminare în 15 zile (CPC art. 200 alin. 4).

**Recomandare**: post-MVP — workflow scenariu marginal.

---

### m8 — **CNP** debitor: protecție GDPR ⚖️

**Citate**: spec menționează CNP în formulare debitor.

**Realitate**: CNP-ul e date personale de identificare strict — necesită protecție: criptare în DB, mascare în log-uri, acces restricționat.

**Recomandare**:
- DB: CNP encriptat la rest (Symfony Encryption).
- Log-uri: regex masking automat (`***-***-XXXX`).
- Audit: orice acces la CNP loggat.

⚖️ **Validare avocat / DPO**: confirmă cerințele GDPR pentru CNP în context recuperare creanțe.

---

## D. Validare avocat — listă prioritizată

Probleme care necesită confirmare juridică externă **înainte** de orice corecție:

| # | Problemă | Tip validare |
|---|---|---|
| ⚖️ C1 | Termen somație 15z minim — uzanță 30z admisă? | Practică |
| ⚖️ C2 | Rename enum-uri sau doar i18n peste cheia veche? | Decizie tehnică legală |
| ⚖️ C3 | Scope MVP: B2B doar / B2C / P2P? | Strategic |
| ⚖️ C4 | Curier privat admisibil sau doar Poșta R+CD? | Practică |
| ⚖️ C5 | Reguli prorogare CPC 181 — vacanța judecătorească? | Procedural |
| ⚖️ M1 | Întâmpinare debitor — status sau notificare? | Decizie UX |
| ⚖️ M4 | Împuternicire avocațială + delegație separată? | Procedural |
| ⚖️ M5 | Întreruperea prescripției 6 luni — jurisprudență? | Doctrinal |
| ⚖️ M6 | Termen 45z soluționare — relevant pentru avocat? | Practică |
| ⚖️ M7 | Tribunale specializate — exclusive sau alternative? | Jurisdicțional |
| ⚖️ M9 | Data comunicării ordonanței — pe portal sau manual? | Procedural |
| ⚖️ M10 | Anatocism procesual — uzanță la OP? | Practică |
| ⚖️ m1 | TVA dobândă — confirmare scoring | Fiscal |
| ⚖️ m3 | Plafon penalități contractuale — alertă? | Decizie UX |
| ⚖️ m8 | CNP — cerințe GDPR specifice? | DPO |

---

## E. Impact pe pașii deja implementați

### Pas 2.1 — InterestCalculatorService (DONE, commit `f9e84dc`)

| Problemă | Impact |
|---|---|
| C3 (atribuire +8 pp) | Nu invalidează codul DAR scope-ul trebuie documentat: doar B2B. Pentru B2C ar trebui ramură suplimentară `BNR + 4`. |
| M3 (plăți parțiale) | Calculator nu suportă amount variabil — refactor necesar la implementare M3. |
| M10 (anatocism) | Calculator e dobândă simplă; pentru anatocism procesual, parametru opțional viitor. |
| m5 (ani bisecți) | Comportament curent OK (act/365). Doar documentație. |

### Pas 2.2 — StampDutyCalculator (DONE, commit `8cffd87`)

Niciun impact din raport. Taxa fixă 200 RON e corectă (OUG 80/2013 art. 6 alin. 2).

### Pas 1.2 — Enums (DONE, commit `70c92f9`)

| Problemă | Impact |
|---|---|
| C2 (cerere în anulare) | `CaseStatus::CONTESTATA`, `CaseTransition::contesta`, `DeadlineType::CONTESTATIE` — decizie: rename sau i18n peste? |
| C3 | `RelationshipType::COMERCIAL/CIVIL` — colapsă 3 cazuri legale. Decizie scope. |
| M1 | Lipsă valori pentru ÎNTÂMPINARE / AMANARE / DOSAR_INREGISTRAT_INCOMPLET. |
| M8 | Lipsă tranziții `DOSAR_INREGISTRAT → RESPINSA`, etc. |

### Pas 1.4 — Migrare baseline (DONE)

| Problemă | Impact |
|---|---|
| M3 | Lipsă tabel `payment` (independent de Invoice). |
| M9 | Lipsă câmp `LegalCase::rulingCommunicationDate`. |
| m8 | CNP în clear în `debtor.personal_id` — nu encriptat. |

### Pas 1.5 — i18n (DONE)

| Problemă | Impact |
|---|---|
| C2 | Cheile RO vorbesc despre "contestație" — corectare la "cerere în anulare". |

---

## F. Impact pe pașii viitori (2.2–9.1)

### Pas 2.3 (CompetentCourtResolver) — impact M7 (tribunale specializate Cluj/Mureș/Argeș).

### Pas 2.4 (OpAdmissibilityValidator) — impact m2 (multipli debitori — itera pe listă).

### Pas 4.1 (DeadlineService) — **CRITIC IMPACT**:
- C1: `RASPUNS_SOMATIE = +15z`, nu +30.
- C5: implementare `nextWorkingDay()` cu sărbători legale.
- M5: nou `DeadlineType::TERMEN_DEPUNERE_OP_INTRERUPERE_PRESCRIPTIE` la +6 luni post somație.
- M6: nou `DeadlineType::TERMEN_SOLUTIONARE` la +45 zile post depunere.
- M9: termenul cerere în anulare = `rulingCommunicationDate + 10z` (nu `rulingDate`).

### Pas 5.1 (PaymentNoticeGenerator) — **CRITIC IMPACT**:
- C1: textul somației spune "15 zile" (nu 30).
- C4: instrucțiuni avocat în UI: "comunică prin executor sau scrisoare R+CD Poșta R".

### Pas 5.2 (PaymentOrderRequestGenerator) — **MEDIU IMPACT**:
- M2: cerere OP afișează cheltuieli judecată (taxa timbru + onorariu + alte).
- M4: opisul include împuternicire avocațială + delegație.

### Pas 6.2 (DeadlineAlertService) — **CRITIC IMPACT**:
- C5: `marcheaza_definitiva` cu prorogare la zi lucrătoare.
- M9: blocaj tranziție dacă `rulingCommunicationDate IS NULL`.

### Pas 7.2 (View dosar tab-uri) — **MEDIU**:
- M3: tab "Plăți" cu istoric și recalcul dobândă.
- M9: input `rulingCommunicationDate` în tab "Activitate Portal".

---

## G. Concluzii operaționale

1. **Pașii 0.x, 1.x, 2.1, 2.2 sunt validate juridic** cu mențiunile din E (în special C3 — clarificare scope B2B/B2C în doc, fără cod modificat).

2. **Pașii 4.1, 5.1, 6.2 trebuie revizuiți juridic ÎNAINTE de implementare** — au impact direct din C1, C4, C5, M5, M6, M9.

3. **Pas 7.2 + retroactiv schimbări la entități** pentru M3 (plăți parțiale) și M9 (data comunicării ordonanței) vor cere migrare DB suplimentară.

4. **Decizia critică pentru rename enum-uri (C2)** trebuie luată acum — ulterior costă mai mult (fixtures, teste, i18n keys, audit log entries existente).

5. **Validarea avocat** (15 puncte ⚖️) e blockerul pentru efectuarea oricăror corecții pe documentație. Recomandat: review extern legal sau consultanță avocat practician OP, înainte de continuarea pașilor 2.3+.

---

## H. Probleme adăugate la verificare 2026-05-09

Următoarele 6 probleme au fost identificate de agentul `avocat-senior` la verificarea independentă din 2026-05-09 ca **gap-uri în analiza inițială** (probleme reale juridic, nemenționate în secțiunile A-C). Sunt propuse pentru includere în roadmap-ul de implementare cu severitatea indicată.

---

### N1 — Competența materială: valoarea totală vs principal (CPC art. 98) ⚖️

**Norma legală**:
> CPC art. 98: valoarea cererii se determină la data sesizării instanței și include accesoriile scadente la acea dată (dobânzi, penalități acumulate până atunci).

**Problemă**:
- Analiza inițială (în M7 și prin extensie pentru `CompetentCourtResolver` Pas 2.3) menționează pragul 200.000 RON între judecătorie și tribunal **fără a clarifica** dacă valoarea se calculează pe **principal singular** sau pe **principal + accesorii** la data sesizării.
- Exemplu critic: principal 190.000 RON + dobânzi acumulate 15.000 RON la data sesizării = total 205.000 RON → **competent tribunalul**, nu judecătoria. Dacă routing-ul se face doar pe principal, dosarul se depune la instanța greșită → necompetență materială ridicabilă din oficiu, dosar declinat, întârziere.

**Severitate**: **CRITIC** — gap de logică în `CompetentCourtResolver` (Pas 2.3).

**Recomandare**:
- `CompetentCourtResolver` calculează `valoareaTotalaCerere = principal + dobandaScadentaLaSesizare + penalitatiContractualeScadente` înainte de a aplica pragul 200.000 RON.
- UI step 3 wizard afișează calculul transparent: principal | dobândă | penalități | TOTAL competență.

⚖️ **Validare avocat**: confirmă cu jurisprudența curentă că art. 98 CPC se aplică inclusiv accesoriilor, nu doar principalului — există nuanțe doctrinare?

---

### N2 — Forma scrisă + semnătura electronică calificată (SEQ) (CPC art. 1016 + Legea 455/2001)

**Norma legală**:
> CPC art. 1016: cererea OP trebuie semnată.
> Legea 455/2001 privind semnătura electronică: semnătura electronică calificată (SEQ) are efectele juridice ale semnăturii olografe.

**Problemă**:
- Spec-ul actual nu menționează explicit cerința de semnătură pe PDF-ul generat de platformă înainte de depunere.
- PDF-ul generat de `PaymentOrderRequestGenerator` (Pas 5.2) este **document neopozabil** până când avocatul îl semnează — fie olograf (printează → semnează → scanează / depune fizic), fie cu SEQ (fișier PDF cu semnătură digitală calificată).

**Severitate**: **MEDIU** — nu blochează implementarea, dar trebuie clarificat în UI și în documentația user-facing pentru avocați.

**Recomandare**:
- Spec Pas 5.2: notă explicită că PDF-ul produs de platformă necesită semnătură (olografă sau SEQ) înainte de depunere.
- UI step "Generare cerere OP": link la pagina de ajutor cu cele 2 modalități + opțional integrare cu provideri SEQ (DigiSign, certSIGN, Trans Sped) — post-MVP.

---

### N3 — Prescripția dreptului de a cere executarea silită (CPC art. 706)

**Norma legală**:
> CPC art. 706: dreptul de a cere executarea silită se prescrie în termen de **3 ani** (10 ani pentru drepturi reale), care curge de la data nașterii dreptului de a cere executarea silită (în general data definitivării titlului executoriu).

**Problemă**:
- Analiza inițială (M5) discută prescripția materială (NCC art. 2517 — 3 ani de la scadență) și termenul de 6 luni post-somație, dar **nu discută** prescripția distinctă a dreptului de a cere executarea silită.
- Avocatul care obține ordonanța de plată definitivă și o lasă neexecutată 3 ani **pierde dreptul de executare** — chiar dacă titlul executoriu rămâne valid juridic, executorul nu mai poate fi sesizat.
- Sistemul actual nu creează niciun deadline pentru această fază post-procedurală.

**Severitate**: **MEDIU** — gap complet în spec; afectează `DeadlineService` (Pas 4.1) și fluxul post-OP.

**Recomandare**:
- `DeadlineService` la tranziția `marcheaza_definitiva`: creare suplimentar `DeadlineType::PRESCRIPTIE_EXECUTARE` cu `dueDate = definitiveDate + 3 ani`, prioritate `HIGH`.
- Alert avocat la T-90 zile, T-30 zile: "Dreptul de executare silită expiră la [dată] — sesizează executor sau întrerupe prescripția prin acte de executare."

---

### N4 — Verificare insolvență debitor obligatorie (Legea 85/2014) ⚖️

**Norma legală**:
> Legea 85/2014 (Legea insolvenței): creanțele împotriva unui debitor în procedura insolvenței se înscriu la **masa credală** prin cerere de admitere a creanței la administratorul/lichidatorul judiciar — NU se recuperează prin proceduri individuale (OP, executare silită).

**Problemă**:
- Dacă debitorul este în procedura insolvenței la data depunerii cererii OP, **cererea e inadmisibilă** — instanța o respinge / o suspendă / declină.
- Memoria proiectului menționează deja BPI (Buletinul Procedurilor de Insolvență — `bpi.just.ro`) ca verificare manuală, dar **nu există enforcement în spec** — `OpAdmissibilityValidator` (Pas 2.4) ar trebui să refuze tranziția dacă debitorul are status BPI `IN_PROCEDURA`.

**Severitate**: **CRITIC** — risc real ca aplicația să permită depunerea unei cereri inadmisibile, urmând să fie respinsă în instanță.

**Recomandare**:
- `OpAdmissibilityValidator` (Pas 2.4): verificare obligatorie status insolvență înainte de tranziția `depune_cerere`.
- BPI nu are API public — workflow:
  1. UI cere avocatului să verifice manual `bpi.just.ro` și să bifeze "Confirm că debitorul nu este în insolvență la data X"
  2. Atașament PDF opțional cu screenshot/extras BPI
  3. Audit log cu data verificării
- Status BPI cunoscut → blocaj tranziție; status necunoscut → warning + confirmare explicită.

⚖️ **Validare avocat**: dacă există jurisprudență ÎCCJ/curți de apel pe admisibilitatea OP în concurs cu insolvență (RIL/HP).

---

### N5 — Clauze compromisorii / arbitraj (CPC art. 1013) ⚖️

**Norma legală**:
> CPC art. 1013: OP se aplică creanțelor "certe, lichide și exigibile". Dacă contractul conține o clauză compromisorie validă (art. 542 și urm. CPC — arbitraj), instanța de drept comun poate decline competența la arbitraj.
> RIL ICCJ 4/2017 — relevant pentru relația arbitraj vs procedură judiciară (de verificat aplicabilitate la OP).

**Problemă**:
- Sistemul nu verifică / nu avertizează asupra existenței unei clauze de arbitraj în contractul-bază al creanței.
- Dacă există clauză de arbitraj validă și debitorul o invocă, OP poate fi respinsă pe excepția de necompetență — avocatul trebuie să aleagă între OP la instanță (cu risc) și arbitraj.

**Severitate**: **MEDIU** — caz frecvent în B2B cu contracte cadru.

**Recomandare**:
- Step 3 wizard `Step3ClaimData`: câmp boolean obligatoriu `existsArbitrationClause` (default `false`) cu help text: "Bifează dacă contractul conține clauză de arbitraj. OP poate fi inadmisibilă în acest caz."
- Dacă bifat → warning UI + audit log + confirmare avocat că își asumă riscul.

⚖️ **Validare avocat**: aplicabilitatea RIL ICCJ 4/2017 la procedura OP — există decizii relevante?

---

### N6 — Creanțe în valută (OG 13/2011 referă rata BNR — RON) ⚖️

**Norma legală / lacună**:
> OG 13/2011 art. 3 referă "rata dobânzii de referință" stabilită de BNR — aceasta este rata de politică monetară pentru RON, nu se aplică direct la EUR/USD.

**Problemă**:
- Codul Pas 2.1 (`InterestCalculatorService`) ridică explicit excepție pentru creanțe non-RON — corect ca guard, dar specificația nu clarifică **scenariul de uz pentru creanțe în valută** (frecvent în B2B internațional).
- Întrebări nerezolvate:
  - Pentru creanță în EUR: dobânda se calculează pe rata EURIBOR + marjă (per Directiva 2011/7/UE — rate de referință BCE) sau se converte la RON la cursul BNR și se aplică OG 13/2011?
  - Taxa timbru OP: pe ce contravaloare RON se calculează? Cursul BNR la data sesizării? La scadență?
  - Plata sumelor în valută: instanța admite dispozitiv în EUR sau impune conversie obligatorie la RON?

**Severitate**: **MEDIU** — gap de scenariu, nu blochează MVP B2B intern, dar trebuie definit înainte de extindere internațională.

**Recomandare**:
- Spec: documentare explicită că **MVP nu acoperă creanțe în valută**.
- UI step 3: validation `currency = RON only` cu mesaj clar ("Pentru creanțe în valută, contactează asistența — disponibilitate post-MVP").
- Backlog post-MVP: implementare ramură EUR cu EURIBOR + marjă conform Directivei 2011/7/UE (echivalent OG 13/2011 alin (2¹) la nivel UE).

⚖️ **Validare avocat**: practica instanțelor RO pe OP în EUR — instanța dispune plata în valută sau în RON? Există jurisprudență relevantă?

---

### Sumar prioritate sprinturi (cele 6 probleme noi)

| # | Severitate | Pas afectat | Sprint propus |
|---|---|---|---|
| **N1** | CRITIC | 2.3 — `CompetentCourtResolver` | Sprint 1 (înainte de Pas 2.3) |
| **N4** | CRITIC | 2.4 — `OpAdmissibilityValidator` | Sprint 1 (înainte de Pas 2.4) |
| **N3** | MEDIU | 4.1 — `DeadlineService` (post-definitivare) | Sprint 2 (cu 4.1) |
| **N2** | MEDIU | 5.2 — `PaymentOrderRequestGenerator` | Sprint 2 (cu 5.2) |
| **N5** | MEDIU | 3.x — wizard step 3 + 2.4 | Sprint 2 (cu 2.4 / 3.1) |
| **N6** | MEDIU | spec + validare currency | Documentare imediată; implementare post-MVP |

---

## Anexă — fișiere referință (read-only, nu modificate)

- [`docs/LexRecovery/PLAN-DEZVOLTARE-LEXRECOVERY.md`](PLAN-DEZVOLTARE-LEXRECOVERY.md)
- [`docs/LexRecovery/ANALIZA-FLUXURI-LEXRECOVERY.md`](ANALIZA-FLUXURI-LEXRECOVERY.md)
- `src/Service/InterestCalculatorService.php` (Pas 2.1 implementat)
- `src/Service/StampDutyCalculatorService.php` (Pas 2.2 implementat)
- `src/Enum/CaseStatus.php`, `CaseTransition.php`, `RelationshipType.php`, `DeadlineType.php`, `DocumentType.php`
- `config/packages/workflow.yaml`
