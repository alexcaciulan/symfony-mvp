# Determinarea instanței competente

Document explicativ — principii și reguli, fără referințe la implementare.

---

## 1. Cadrul legal

Pentru cererile de ordonanță de plată (OP), competența se stabilește conform **Codului de procedură civilă** (CPC):

- **Art. 94 pct. 1 lit. k** — judecătoriile judecă cererile în primă instanță în materie de OP **până la 200.000 RON**;
- **Art. 95 pct. 1** — tribunalele judecă toate cererile care nu sunt date în competența altor instanțe (deci OP **peste 200.000 RON**);
- **Art. 98** — competența materială se determină în funcție de **valoarea obiectului cererii** la data sesizării (principal + accesorii scadente);
- **Art. 107** — competența teritorială: cererea se introduce la instanța de la **domiciliul / sediul pârâtului** (regula generală).

Pentru OP există și opțiunea competenței alternative (locul executării obligației, locul plății — CPC art. 113), dar în MVP se aplică **regula generală art. 107**.

---

## 2. Cele două dimensiuni ale competenței

Competența se stabilește pe **două axe ortogonale**:

### 2.1 Competența materială (verticală)

Determină **tipul instanței** — judecătorie sau tribunal — în funcție de valoarea totală a cererii.

| Valoare totală a cererii   | Instanță competentă | Temei              |
|----------------------------|---------------------|--------------------|
| ≤ 200.000 RON              | **Judecătorie**     | CPC art. 94 pct. 1 lit. k |
| > 200.000 RON              | **Tribunal**        | CPC art. 95 pct. 1 |

Pragul este de **200.000 RON inclusiv** (≤). Cererile **strict peste** 200.000 RON merg la tribunal.

### 2.2 Competența teritorială (orizontală)

Determină **care anume** judecătorie sau tribunal — pe baza domiciliului / sediului debitorului:

- **Tribunalul** — unul singur pe județ; competența teritorială coincide cu județul debitorului.
- **Judecătoria** — un județ are mai multe judecătorii, fiecare având arondată o **rază teritorială** formată dintr-o listă de localități. Identificarea se face pe **localitatea** debitorului, nu doar pe județ.

---

## 3. Calculul valorii totale a cererii

Conform CPC art. 98, valoarea care se compară cu pragul de 200.000 RON este **valoarea totală a cererii la data sesizării**, NU doar principalul.

```
Valoarea totală = Principal + Dobândă acumulată + Penalități contractuale scadente
```

unde:
- **Principal** — suma datorată conform facturii / contractului;
- **Dobândă acumulată** — dobânda legală penalizatoare calculată de la scadență până la **data sesizării instanței** (vezi `CALCUL-DOBANDA-CREANTA.md`);
- **Penalități contractuale scadente** — clauze penale exigibile la data sesizării (dacă există în contract);
- **NU se includ**: cheltuieli de judecată (taxă timbru, onorariu avocat) — acestea sunt accesorii ale judecății, nu ale creanței.

### 3.1 Exemple

| Principal | Dobândă acumulată | Penalități | Total      | Instanță      |
|-----------|-------------------|------------|------------|---------------|
| 180.000   | 15.000            | 0          | 195.000    | Judecătorie   |
| 180.000   | 25.000            | 0          | 205.000    | **Tribunal**  |
| 195.000   | 0                 | 10.000     | 205.000    | **Tribunal**  |
| 200.000   | 0                 | 0          | 200.000    | Judecătorie   |
| 200.000   | 0,01              | 0          | 200.000,01 | **Tribunal**  |

> **Atenție**: dobânda continuă să curgă după data sesizării, dar pentru determinarea competenței **se îngheață la data depunerii cererii**. Calculul ulterior (până la plata efectivă) se cere distinct în petit, dar nu afectează instanța competentă.

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
        │            + penalități         │
        └─────────────────────────────────┘
                          │
                          ▼
              ┌───────────┴───────────┐
              │  Total > 200.000 RON? │
              └───────────┬───────────┘
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
4. **Caz ≥ 2 tribunale** → ambiguitate (extrem de rar — un județ are de regulă **un singur** tribunal; excepție: București are Tribunalul București).

> În practică, fiecare județ are exact un tribunal, deci ramura 2 este cea care se aplică. Cazurile 3 și 4 sunt **gărzi defensive** pentru date master invalide.

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

### 6.5 Cazuri-limită teritoriale

- **Localitate lipsă** (numai județul e cunoscut) → se afișează **toate** judecătoriile din județ și utilizatorul alege manual.
- **Localitate prezentă, dar nu apare în niciun coverage** (date master incomplete) → idem, listă cu toate judecătoriile din județ + selecție manuală.
- **București (sectoare)** — caz aparte: cele 5 judecătorii de sector (Judecătoria Sector 1 ... Judecătoria Sector 6 exclusiv sector 5; actual: Judecătoriile Sector 1, 2, 3, 4, 5, 6) au fiecare coverage = un sector București. Localitatea trebuie să identifice sectorul (`Sector 3 București`), altfel selecția e manuală.

---

## 7. Cazuri-limită globale

### 7.1 Principal invalid

- `principal < 0` → eroare (`invalid_amount_negative`);
- `principal = 0` → eroare (`invalid_amount_zero`) — nu se poate cere OP pentru sumă zero.

### 7.2 Județ debitor necunoscut

Dacă județul debitorului nu poate fi determinat (extracție eșuată, ANAF nu a returnat adresă), **se calculează totuși dobânda** și valoarea totală (pentru taxă timbru și informare), dar **instanța rămâne neidentificată** și utilizatorul completează manual.

### 7.3 Tip raport CIVIL

Calculul dobânzii eșuează cu `DomainException` (B2B-only MVP). Determinarea instanței nu poate continua. Vezi `CALCUL-DOBANDA-CREANTA.md` secțiunea 2.1.

### 7.4 Lipsa configurației BNR la scadență

Calculul dobânzii eșuează cu `RuntimeException` → utilizatorul vede eroare clară, baza de configurații BNR trebuie completată retroactiv.

---

## 8. Granița precisă a pragului

Decizia se ia cu strict mai mare (`>`):

```
total > 200.000   → TRIBUNAL
total ≤ 200.000   → JUDECĂTORIE
```

Astfel **exact 200.000,00 RON** este de competența judecătoriei. **200.000,01 RON** este deja de competența tribunalului. Granița este interpretată conform CPC art. 94 pct. 1 lit. k care folosește formularea „**până la 200.000 RON inclusiv**".

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
- **Total = 55.532 RON** → judecătorie
- Debitor: SC ALFA SRL, sediu **Cluj-Napoca, jud. Cluj**
- Candidați jud. Cluj: 5 judecătorii
- Filtrare coverage „cluj napoca" → 1 match: **Judecătoria Cluj-Napoca**
- Rezultat: `matched_judecatorie` ✔

### 10.2 Cazul „tribunal identificat automat"

- Principal: 300.000 RON
- Dobândă acumulată: 25.000 RON
- Penalități: 5.000 RON
- **Total = 330.000 RON** → tribunal
- Debitor: SC BETA SA, sediu **Iași, jud. Iași**
- Candidat unic: **Tribunalul Iași**
- Rezultat: `matched_tribunal` ✔

### 10.3 Cazul „selecție manuală — localitate negăsită"

- Total: 80.000 RON → judecătorie
- Debitor: SC GAMMA SRL, **comuna Sat Inventat, jud. Bihor**
- Candidați jud. Bihor: 4 judecătorii
- Filtrare coverage „sat inventat" → 0 match-uri
- Rezultat: `locality_unmatched_pick_manually` + listă cu toate cele 4 judecătorii din Bihor pentru selecție UI.

### 10.4 Cazul „granițe la prag"

- Principal 200.000 RON, scadent **astăzi**, dobândă 0, penalități 0
- Total = 200.000 RON exact → **Judecătorie**
- Dacă scadența ar fi fost ieri și ar exista 1 RON dobândă acumulată → Total = 200.001 RON → **Tribunal**

---

## 11. Date master necesare

Pentru ca rezolvarea automată să funcționeze, trebuie întreținute:

1. **Lista instanțelor active** (judecătorii + tribunale) cu județul fiecăreia;
2. **Raza teritorială** (`coveredLocalities`) pentru fiecare judecătorie — listă de localități în formatul standard publicat de MJ / portal.just.ro;
3. **Statusul activ/inactiv** — instanțele desființate (rare, dar există — fuziuni, reorganizări) trebuie marcate inactive ca să nu apară în rezolvare.

Sursa de adevăr este **portal.just.ro** și **HG-urile de organizare a instanțelor**. Importul se face printr-o comandă de admin care reîmprospătează datele.

---

## 12. Ce **nu** rezolvă acest serviciu (out of scope MVP)

- **Competența alternativă** CPC art. 113 (locul plății, locul executării) — nu se aplică automat; ar fi opțiune avocat → manual.
- **Convențiile atributive de competență** (clauze contractuale care derogă de la art. 107) — necesită lectură contract; nu se identifică automat.
- **Localizarea pentru persoane fizice** după domiciliu — MVP este B2B, deci debitorul este de regulă PJ cu sediu social.
- **Multipli debitori cu sedii diferite** — la coobligați solidari, reclamantul alege oricare instanță competentă pentru oricare debitor (CPC art. 116). Selecția finală e a avocatului.
- **Schimbarea sediului ulterior datei sesizării** — competența se determină la momentul introducerii cererii; nu se reverifică ulterior (`perpetuatio fori`, CPC art. 106).
