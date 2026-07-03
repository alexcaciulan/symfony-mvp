# Determinarea instanței competente

Document explicativ — principii și reguli, fără referințe la implementare.

---

## 1. Cadrul legal

Pentru cererile de ordonanță de plată (OP), competența se stabilește conform **Codului de procedură civilă** (CPC):

- **Art. 94 pct. 1 lit. k** — judecătoriile judecă cererile în primă instanță în materie de OP **până la 200.000 RON**;
- **Art. 95 pct. 1** — tribunalele judecă toate cererile care nu sunt date în competența altor instanțe (deci OP **peste 200.000 RON**);
- **Art. 98 alin. 2** — la stabilirea valorii pentru competența materială **nu se iau în calcul accesoriile** pretenției principale (dobânzi, penalități, fructe, cheltuieli), **indiferent de data scadenței**. Valoarea care contează este, prin urmare, **principalul** la data sesizării;
- **Art. 107** — competența teritorială: cererea se introduce la instanța de la **domiciliul / sediul pârâtului** (regula generală), **dacă în contract nu s-a prevăzut altfel** (o clauză atributivă poate stabili, de pildă, sediul reclamantului — CPC art. 126).

Pentru OP există și opțiunea competenței alternative (locul executării obligației, locul plății — CPC art. 113 alin. 1 pct. 3), dar în MVP se aplică **regula generală art. 107**.

---

## 2. Cele două dimensiuni ale competenței

Competența se stabilește pe **două axe ortogonale**:

### 2.1 Competența materială (verticală)

Determină **tipul instanței** — judecătorie sau tribunal — în funcție de **valoarea principalului** (CPC art. 98 alin. 2 exclude accesoriile din acest calcul).

| Valoarea principalului     | Instanță competentă | Temei              |
|----------------------------|---------------------|--------------------|
| ≤ 200.000 RON              | **Judecătorie**     | CPC art. 94 pct. 1 lit. k |
| > 200.000 RON              | **Tribunal**        | CPC art. 95 pct. 1 |

Pragul este de **200.000 RON inclusiv** (≤). Cererile cu principalul **strict peste** 200.000 RON merg la tribunal. Dobânda acumulată și penalitățile contractuale **nu** influențează această decizie.

### 2.2 Competența teritorială (orizontală)

Determină **care anume** judecătorie sau tribunal — pe baza domiciliului / sediului debitorului:

- **Tribunalul** — unul singur pe județ; competența teritorială coincide cu județul debitorului.
- **Judecătoria** — un județ are mai multe judecătorii, fiecare având arondată o **rază teritorială** formată dintr-o listă de localități. Identificarea se face pe **localitatea** debitorului, nu doar pe județ.

---

## 3. Două valori distincte: principalul (competență) și totalul cererii (petit)

Trebuie separate clar două mărimi care, în trecut, erau confundate:

- **Valoarea de competență** = **principalul** singur. Aceasta este singura comparată cu pragul de 200.000 RON. Conform CPC art. 98 alin. 2, accesoriile **nu se iau în calcul**, „indiferent de data scadenței" (deci nici dobânda deja acumulată la data sesizării).
- **Valoarea totală a cererii** = Principal + Dobândă acumulată + Penalități contractuale scadente. Aceasta este suma pe care creditorul o **cere efectiv** în petit și care servește la afișare și la informare; **nu** determină instanța.

```
Competență:      compară PRINCIPALUL cu 200.000 RON
Total cerut:     Principal + Dobândă acumulată + Penalități contractuale scadente
```

unde:
- **Principal** — suma datorată conform facturii / contractului;
- **Dobândă acumulată** — dobânda legală penalizatoare calculată de la scadență până la **data sesizării instanței** (vezi `CALCUL-DOBANDA-CREANTA.md`);
- **Penalități contractuale scadente** — clauze penale exigibile la data sesizării (dacă există în contract);
- **NU se includ** nici în total: cheltuieli de judecată (taxă timbru, onorariu avocat) — acestea sunt accesorii ale judecății, nu ale creanței.

### 3.1 Exemple

Coloana decisivă pentru competență este **Principalul**, nu Totalul.

| Principal | Dobândă acumulată | Penalități | Total (petit) | Instanță (după principal) |
|-----------|-------------------|------------|---------------|---------------------------|
| 180.000   | 15.000            | 0          | 195.000       | Judecătorie               |
| 180.000   | 25.000            | 0          | 205.000       | Judecătorie               |
| 195.000   | 0                 | 10.000     | 205.000       | Judecătorie               |
| 200.000   | 0                 | 0          | 200.000       | Judecătorie               |
| 200.000   | 0,01              | 0          | 200.000,01    | Judecătorie               |
| 201.000   | 0                 | 0          | 201.000       | **Tribunal**              |

> **Atenție**: dobânda continuă să curgă după data sesizării și se cere distinct în petit (până la plata efectivă), dar **nu** afectează nici competența (care urmează exclusiv principalul, art. 98 alin. 2), nici instanța deja sesizată.

---

## 4. Algoritmul de determinare

```
┌──────────────────────────────────────────────────────────┐
│ INTRARE: principal, scadență, data referinței,           │
│          tip raport, județ debitor, localitate debitor,  │
│          penalități scadente                             │
└──────────────────────────────────────────────────────────┘
                          │
                          ▼
        ┌─────────────────────────────────┐
        │ 1. Validări de bază             │
        │    - principal < 0 → eroare     │
        │    - principal = 0 → eroare     │
        │    - județ lipsă → necunoscut   │
        └─────────────────────────────────┘
                          │
                          ▼
        ┌─────────────────────────────────┐
        │ 2. Calcul dobândă acumulată     │
        │    până la data sesizării       │
        └─────────────────────────────────┘
                          │
                          ▼
        ┌─────────────────────────────────┐
        │ 3. Total = principal + dobândă  │
        │    + penalități (doar afișare)  │
        └─────────────────────────────────┘
                          │
                          ▼
              ┌───────────┴────────────┐
              │ Principal > 200.000 RON?│
              │ (accesorii excluse,     │
              │  art. 98 alin. 2)       │
              └───────────┬────────────┘
                ┌─────────┴──────────┐
              DA                    NU
                │                    │
                ▼                    ▼
        ┌───────────────┐    ┌─────────────────┐
        │ TRIBUNAL      │    │ JUDECĂTORIE     │
        │ pe județ      │    │ pe localitate   │
        │ debitor       │    │ debitor         │
        └───────┬───────┘    └────────┬────────┘
                │                     │
                ▼                     ▼
        ┌───────────────┐    ┌─────────────────┐
        │ 1 candidat?   │    │ filtrează pe    │
        │ → MATCHED     │    │ localitate      │
        │ 0 candidați?  │    │ în coverage     │
        │ → MISSING     │    └────────┬────────┘
        │ >1 candidat?  │             │
        │ → AMBIGUOUS   │             ▼
        └───────────────┘    ┌─────────────────┐
                             │ 1 match → OK    │
                             │ 0 match → pick  │
                             │ >1 → AMBIGUOUS  │
                             └─────────────────┘
```

---

## 5. Rezolvarea tribunalului

Pentru cereri > 200.000 RON:

1. Se caută toate tribunalele active din **județul debitorului**.
2. **Caz normal** (1 tribunal găsit) → instanța competentă identificată ✔.
3. **Caz 0 tribunale** → date master deficitare; necesită completare admin (sau county invalid).
4. **Caz ≥ 2 tribunale** → ambiguitate (extrem de rar — un județ are exact **un singur** tribunal cu competență civilă).

> În practică, fiecare județ are exact un tribunal civil, deci ramura 2 este cea care se aplică. Cazurile 3 și 4 sunt **gărzi defensive** pentru date master invalide.
>
> **Notă București**: deși în București funcționează și un Tribunal Militar, acesta are competență exclusiv penal-militară (Legea 304/2022) și **nu** intervine în cereri de OP. Pentru produs, București are un singur tribunal relevant: Tribunalul București. Tribunalele militare trebuie excluse la importul datelor master (marcate inactive sau filtrate la interogare), ca să nu activeze fals ramura AMBIGUOUS.

---

## 6. Rezolvarea judecătoriei (cu raza teritorială)

Pentru cereri ≤ 200.000 RON, pașii sunt mai complecși fiindcă un județ are **multiple judecătorii**, fiecare cu raza ei teritorială:

### 6.1 Lista candidaților

Se obțin toate judecătoriile active din **județul debitorului**. Exemplu pentru județul Cluj:
- Judecătoria Cluj-Napoca
- Judecătoria Dej
- Judecătoria Gherla
- Judecătoria Huedin
- Judecătoria Turda

Fiecare are o listă proprie de localități arondate (`coveredLocalities`) — comune, orașe, sate din raza ei teritorială.

### 6.2 Normalizarea localității

Localitatea debitorului (care vine de la ANAF sau introdusă manual) este normalizată:
- transformată la litere mici;
- diacriticele înlăturate (`ă` → `a`, `â` → `a`, `î` → `i`, `ș` → `s`, `ț` → `t`);
- spațiile multiple comprimate;
- prefixele uzuale (`mun.`, `oraș`, `comuna`, `sat`) eliminate.

Aceeași normalizare se aplică **și** localităților din coverage-ul instanțelor — pentru a permite comparație robustă (`Cluj-Napoca` ≡ `cluj napoca` ≡ `Mun. Cluj-Napoca`).

### 6.3 Filtrarea pe coverage

Se păstrează doar judecătoriile a căror listă de localități **conține** localitatea normalizată a debitorului.

### 6.4 Rezultate posibile

| Nr. candidați după filtrare | Acțiune                                                                |
|----------------------------|------------------------------------------------------------------------|
| **1**                      | MATCHED — judecătoria competentă identificată ✔                        |
| **0**                      | Localitatea nu apare în nicio rază — utilizatorul alege manual         |
| **≥ 2**                    | AMBIGUOUS — localitate apare în mai multe coverage-uri (caz patologic) |

> Juridic, o localitate aparține **exact unei** judecătorii (circumscripțiile teritoriale sunt disjuncte, stabilite prin HG). Cazul ≥ 2 nu poate apărea prin lege; apariția lui indică **date master incorecte** (duplicări în coverage). Ramura rămâne ca gardă defensivă.

### 6.5 Cazuri-limită teritoriale

- **Localitate lipsă** (numai județul e cunoscut) → se afișează **toate** judecătoriile din județ și utilizatorul alege manual.
- **Localitate prezentă, dar nu apare în niciun coverage** (date master incomplete) → idem, listă cu toate judecătoriile din județ + selecție manuală.
- **București (sectoare)** — caz aparte: cele 6 judecătorii de sector (Judecătoriile Sector 1, 2, 3, 4, 5, 6) au fiecare coverage = un sector București. Localitatea trebuie să identifice sectorul (`Sector 3 București`), altfel selecția e manuală. **Recomandare practică (avocat)**: adresa debitorului trebuie verificată cu atenție la București, fiindcă o selecție greșită de sector trece de validare, dar abia după luni de așteptare instanța se poate declara necompetentă teritorial.

---

## 7. Cazuri-limită globale

### 7.1 Principal invalid

- `principal < 0` → eroare (`invalid_amount_negative`);
- `principal = 0` → eroare (`invalid_amount_zero`) — nu se poate cere OP pentru sumă zero.

### 7.2 Județ debitor necunoscut

Dacă județul debitorului nu poate fi determinat (extracție eșuată, ANAF nu a returnat adresă), **se calculează totuși dobânda** și valoarea totală (pentru taxă timbru și informare), dar **instanța rămâne neidentificată** și utilizatorul completează manual.

> **Fallback posibil (de implementat)**: județul poate fi dedus din prefixul `J` al numărului ONRC al societății (de ex. `J40/...` = București, `J12/...` = Cluj). Atenție: prefixul `J` reflectă județul de **înregistrare**, nu neapărat sediul **curent** (o firmă mutată își păstrează numărul). De folosit doar ca sugestie, cu avertisment către avocat să verifice adresa reală.

### 7.3 Tip raport CIVIL

Calculul dobânzii eșuează cu `DomainException` (B2B-only MVP). Determinarea instanței nu poate continua. Vezi `CALCUL-DOBANDA-CREANTA.md` secțiunea 2.1.

### 7.4 Lipsa configurației BNR la scadență

Calculul dobânzii eșuează cu `RuntimeException` → utilizatorul vede eroare clară, baza de configurații BNR trebuie completată retroactiv.

---

## 8. Granița precisă a pragului

Decizia se ia cu strict mai mare (`>`), aplicată pe **principal**:

```
principal > 200.000   → TRIBUNAL
principal ≤ 200.000   → JUDECĂTORIE
```

Astfel un principal de **exact 200.000,00 RON** este de competența judecătoriei. Un principal de **200.000,01 RON** este deja de competența tribunalului. Granița este interpretată conform CPC art. 94 pct. 1 lit. k care folosește formularea „**până la 200.000 RON inclusiv**". Accesoriile (dobândă, penalități) nu mută această graniță (art. 98 alin. 2).

---

## 9. Structura rezultatului

Rezolvarea returnează o structură unitară cu:

- **`court`** — instanța identificată univoc (sau `null` dacă rezultatul este ambiguu / lipsă);
- **`candidates`** — lista alternativelor când nu e univoc (pentru selecție manuală în UI);
- **`explanationKey`** — cheie i18n cu motivul rezultatului (de ex. `matched_judecatorie`, `locality_unmatched_pick_manually`, `tribunal_ambiguous`);
- **`breakdown`** — descompunerea valorii cererii (principal, dobândă acumulată, penalități, total), reutilizabilă pentru taxa de timbru și pentru UI.

Această structură permite UI-ului să răspundă uniform — fie afișează instanța identificată automat (happy path), fie oferă o listă de selecție cu motiv clar (caz incert).

---

## 10. Exemple end-to-end

### 10.1 Cazul „judecătorie identificată automat"

- Principal: 50.000 RON
- Dobândă acumulată: 5.532 RON
- Penalități: 0
- **Principal = 50.000 RON ≤ 200.000** → judecătorie (total cerut: 55.532 RON, irelevant pentru competență)
- Debitor: SC ALFA SRL, sediu **Cluj-Napoca, jud. Cluj**
- Candidați jud. Cluj: 5 judecătorii
- Filtrare coverage „cluj napoca" → 1 match: **Judecătoria Cluj-Napoca**
- Rezultat: `matched_judecatorie` ✔

### 10.2 Cazul „tribunal identificat automat"

- Principal: 300.000 RON
- Dobândă acumulată: 25.000 RON
- Penalități: 5.000 RON
- **Principal = 300.000 RON > 200.000** → tribunal (total cerut: 330.000 RON)
- Debitor: SC BETA SA, sediu **Iași, jud. Iași**
- Candidat unic: **Tribunalul Iași**
- Rezultat: `matched_tribunal` ✔

### 10.3 Cazul „selecție manuală — localitate negăsită"

- Principal: 80.000 RON → judecătorie
- Debitor: SC GAMMA SRL, **comuna Sat Inventat, jud. Bihor**
- Candidați jud. Bihor: 4 judecătorii
- Filtrare coverage „sat inventat" → 0 match-uri
- Rezultat: `locality_unmatched_pick_manually` + listă cu toate cele 4 judecătorii din Bihor pentru selecție UI.

### 10.4 Cazul „granițe la prag"

- Principal 200.000 RON, scadent **astăzi**, dobândă 0, penalități 0
- Principal = 200.000 RON exact → **Judecătorie**
- Dacă scadența ar fi fost ieri și ar exista 1 RON dobândă acumulată → principalul rămâne 200.000 RON → **tot Judecătorie** (dobânda nu se ia în calcul, art. 98 alin. 2). Tribunalul ar fi competent doar dacă **principalul** însuși depășea 200.000 RON.

---

## 11. Date master necesare

Pentru ca rezolvarea automată să funcționeze, trebuie întreținute:

1. **Lista instanțelor active** (judecătorii + tribunale) cu județul fiecăreia;
2. **Raza teritorială** (`coveredLocalities`) pentru fiecare judecătorie — listă de localități în formatul standard publicat de MJ / portal.just.ro;
3. **Statusul activ/inactiv** — instanțele desființate (rare, dar există — fuziuni, reorganizări) trebuie marcate inactive ca să nu apară în rezolvare.

Sursa de adevăr este **portal.just.ro** și **HG-urile de organizare a instanțelor**. Importul se face printr-o comandă de admin care reîmprospătează datele.

---

## 12. Ce **nu** rezolvă acest serviciu (out of scope MVP)

- **Competența alternativă** CPC art. 113 alin. 1 pct. 3 (locul plății, locul executării obligației) — nu se aplică automat. Rămâne opțiune a avocatului (selecție manuală), eventual sugerată ulterior printr-o analiză AI a contractului.
- **Convențiile atributive de competență** (clauze contractuale care derogă de la art. 107, în temeiul art. 126) — necesită lectură contract; nu se identifică automat.
- **Localizarea pentru persoane fizice** după domiciliu — MVP este B2B, deci debitorul este de regulă PJ cu sediu social.
- **Multipli debitori cu sedii diferite** — la coobligați solidari, reclamantul alege oricare instanță competentă pentru oricare debitor (CPC art. 116). Selecția finală e a avocatului.
- **Schimbarea sediului ulterior datei sesizării** — competența se determină la momentul introducerii cererii; nu se reverifică ulterior (`perpetuatio fori`, CPC art. 106).
