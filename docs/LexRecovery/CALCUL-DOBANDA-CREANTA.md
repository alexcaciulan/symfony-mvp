# Calculul dobânzii pentru creanță

Document explicativ — principii și formule, fără referințe la implementare.

---

## 1. Cadrul legal

Calculul dobânzilor pentru obligații pecuniare neonorate la scadență este reglementat în România de **OG 13/2011** privind dobânda legală remuneratorie și penalizatoare, modificată prin **Legea 72/2013** (combaterea întârzierii în executarea obligațiilor de plată).

Rata de referință (rata BNR) este publicată periodic de Banca Națională a României și se actualizează ori de câte ori intervine o modificare.

---

## 2. Concepte de bază

### 2.1 Tipul raportului juridic

- **COMERCIAL** — între profesioniști (B2B). Aici se aplică OG 13/2011 art. 3 alin. 1 și alin. 2¹.
- **CIVIL** — raporturi non-profesionale (B2C / P2P). În scope-ul MVP **nu este suportat**.

### 2.2 Felul dobânzii

- **REMUNERATORIE** — dobânda datorată pentru folosința unei sume (împrumut, credit). Compensează creditorul pentru lipsa de folosință.
- **PENALIZATOARE** — dobânda datorată pentru întârzierea executării obligației de plată. Sancționează întârzierea.

În procedura ordonanței de plată (OP), dobânda calculată este **întotdeauna PENALIZATOARE**: creanța dedusă judecății este, prin definiție, exigibilă și neachitată, iar din momentul scadenței curge exclusiv dobânda de întârziere (daune moratorii de la scadență, art. 1535 NCC; rata penalizatoare B2B, art. 3 alin. 2¹ OG 13/2011 coroborat cu Legea 72/2013). Dobânda remuneratorie este pre-scadentă prin natura ei și nu apare în calculul OP. Eventualele dobânzi remuneratorii acumulate înainte de scadență fac parte din principalul dedus judecății, nu din dobânda calculată aici.

### 2.3 Date relevante

- **Scadența** (`dueDate`) — data la care obligația de plată a devenit exigibilă. Dobânda curge **începând cu ziua imediat următoare**.
- **Data emiterii facturii** (`invoiceDate`) — data la care a fost emisă factura. Coroborată cu termenul de plată, permite verificarea modului în care s-a stabilit scadența (și, pentru creanțele în valută, este data cursului BNR de referință). Se reține pentru auditabilitate.
- **Data de referință** (`referenceDate`) — data până la care se calculează dobânda (în practică: data emiterii somației, data introducerii cererii, data calculului).

> **Ciclu de date de calcul.** În viața unei creanțe, dobânda penalizatoare se recalculează succesiv la mai multe momente: (1) data emiterii somației, (2) data introducerii cererii pe rolul instanței (cu capăt de cerere prin care se solicită dobânda penalizatoare *până la plata efectivă*), (3) data introducerii cererii de executare silită, (4) data fiecărei plăți efective (parțiale sau integrale). La o plată parțială se aplică **imputația plății** (art. 1509 alin. (2) NCC): din suma plătită se sting întâi cheltuielile, apoi dobânzile și penalitățile, și la urmă capitalul; pe capitalul rezidual dobânda penalizatoare continuă să curgă. *Etapele (1) și (2) sunt suportate în MVP (orice `referenceDate`). Etapele (3) și (4), inclusiv imputația plății, sunt **backlog post-MVP** (vezi §11) — necesită modelarea istoricului de plăți pe componente (capital / dobânzi / cheltuieli).*

---

## 3. Rata aplicabilă

Pe baza tipului raportului și felului dobânzii, rata aplicabilă se construiește pornind de la rata de referință BNR:

| Raport       | Fel dobândă     | Rata aplicabilă   | Temei                                    |
|--------------|-----------------|-------------------|------------------------------------------|
| COMERCIAL    | PENALIZATOARE   | **BNR + 8 p.p.**  | OG 13/2011 art. 3 alin. 2¹ (L. 72/2013) |
| COMERCIAL    | REMUNERATORIE   | **BNR**           | OG 13/2011 art. 3 alin. 1                |
| CIVIL        | oricare         | nesuportat în MVP | scope post-MVP                           |

Unde **p.p.** = puncte procentuale (adunate, nu înmulțite).

Exemplu: dacă rata BNR = 7,00 %, atunci pentru COMERCIAL + PENALIZATOARE rata aplicabilă = 7,00 + 8,00 = **15,00 %** pe an.

---

## 4. Formula de bază

Dobânda pe un interval în care rata aplicabilă este constantă se calculează după formula clasică a dobânzii simple:

```
            Suma × Rata × Zile
Dobânda  =  ─────────────────
                 365
```

unde:
- **Suma** — valoarea principalului datorat (în RON);
- **Rata** — rata aplicabilă pe an, exprimată **în procente** (împărțită la 100 în calcul);
- **Zile** — numărul de zile efective scurse în interval;
- **365** — convenția de calcul (an comercial / financiar uzual; nu se folosește 360 sau 366).

Forma echivalentă cu rata ca fracție:

```
Dobânda  =  Suma × (Rata / 100) × (Zile / 365)
```

**Numărarea zilelor — convenția Z+1.** Ziua scadenței (Z) **nu se numără**; dobânda curge începând cu ziua imediat următoare (art. 1535 NCC). Exemplu: factură scadentă pe 15 iunie → prima zi de dobândă este 16 iunie. Capătul superior al intervalului (data de referință) este inclus. Numărul de zile dintr-un interval este, așadar, numărul de zile din `(scadență, referință]`.

---

## 5. Tratarea modificărilor ratei BNR

Rata BNR **nu este constantă** pe parcursul perioadei dintre scadență și data de referință. Atunci când rata se modifică, perioada totală se **segmentează** în sub-perioade omogene, fiecare cu rata sa proprie.

### 5.1 Identificarea ratelor relevante

1. Se selectează **rata de pornire** = rata BNR în vigoare la data scadenței (cea mai recentă configurație cu `validFrom ≤ scadență`).
2. Se selectează **toate modificările** intervenite strict după scadență și până la (inclusiv) data de referință.
3. Aceste modificări împart perioada totală în intervale.

### 5.2 Segmentarea în perioade

Pentru fiecare modificare a ratei, intervalul curent se închide la data noii rate și se deschide un interval nou cu noua rată. Ultimul interval se închide la data de referință.

Exemplu vizual (scadență 01.01.2024, referință 01.10.2024, două modificări BNR):

```
   01.01    15.03            10.07              01.10
     │        │                │                  │
     │ rata 1 │     rata 2     │      rata 3      │
     │ (74 z) │     (117 z)    │      (83 z)      │
     │        │                │                  │
   scadență  schimb 1        schimb 2          referință
```

### 5.3 Compunerea totalului

Dobânda totală este **suma aritmetică** a dobânzilor parțiale:

```
Dobânda_totală  =  Σ Dobânda_i

unde Dobânda_i = Suma × (Rata_i / 100) × (Zile_i / 365)
```

Nu se capitalizează dobânda între perioade (nu este dobândă compusă). Principalul rămâne neschimbat — dobânda se aplică mereu la **suma inițială**, nu la sume ce includ dobânzi anterioare. Aceasta corespunde regulii **anatocismului interzis** pentru dobânda legală, în lipsa unei convenții exprese între părți.

---

## 6. Cazuri-limită

### 6.1 Scadența ≥ data de referință

Dacă scadența este în viitor sau egală cu data de referință, **nu curge dobândă**. Rezultatul este 0 și lista de perioade e goală.

> **Atenție — inadmisibilitate, nu doar dobândă nulă.** Dacă scadența este în **viitor**, creanța este **neexigibilă**, iar procedura OP este **inadmisibilă** (CPC art. 1013: creanță certă, lichidă și *exigibilă*). Această situație trebuie semnalată ca **eroare blocantă de admisibilitate** înainte de generarea somației sau a cererii, nu lăsată să treacă pe motiv că „dobânda este oricum 0". Returnarea valorii 0 de către calculator rămâne o protecție matematică defensivă, dar garanția juridică se aplică la stratul de validare a admisibilității.

### 6.2 Aceeași zi

Dacă scadența = data de referință (după normalizare la 00:00:00), numărul de zile = 0 → dobânda = 0.

### 6.3 Lipsa unei rate de pornire

Dacă în baza de date **nu există o configurație BNR validă la scadență** (caz teoretic — toate configurațiile sunt mai noi decât scadența), calculul nu se poate face și se semnalează eroare. În practică, baza trebuie să acopere istoric ratele BNR cel puțin până la cea mai veche scadență posibil de invocat (uzual: 1 ianuarie an curent − 3 ani, pentru a acoperi prescripția).

În practică această situație **nu apare** dacă baza este populată corect cu ratele BNR pe ultimii 3 ani (acoperind prescripția de drept comun, art. 2517 NCC). Eroarea semnalată în acest caz indică **date lipsă în configurație**, nu o eroare de calcul, și nu trebuie tratată ca un scenariu de utilizare normal.

### 6.4 Monedă diferită de RON

OG 13/2011 reglementează dobânda legală **în lei**. Calculul în alte monede (EUR, USD) nu intră în scope-ul MVP și este respins explicit.

> **Direcție post-MVP (vezi §11).** Facturile în valută indică de regulă cursul BNR de la data emiterii și echivalentul în RON. O extensie viitoare ar putea permite avocatului să introducă suma în valută plus cursul BNR la o dată aleasă, calculând echivalentul în RON, cu mențiune transparentă în document. **Alegerea cursului (data emiterii / scadenței / introducerii cererii) este o decizie juridică ce rămâne a avocatului** — aplicația nu trebuie să infereze automat cursul, fiindcă jurisprudența este neuniformă și un curs greșit poate fi contestat de debitor.

### 6.5 Normalizarea datelor

Toate datele se aduc la **00:00:00** înainte de calcul (componenta de oră este ignorată). Astfel, două timestamp-uri din aceeași zi calendaristică sunt considerate egale, iar diferența în zile este număr întreg.

---

## 7. Exemplu numeric

**Date de intrare**:
- Suma datorată: **50.000 RON**
- Tip raport: COMERCIAL
- Fel dobândă: PENALIZATOARE
- Scadența: **01.01.2024**
- Data de referință: **01.10.2024**
- Configurații BNR (ipotetice):
  - 01.01.2024 → 7,00 % (în vigoare la scadență — rata de pornire)
  - 15.03.2024 → 6,75 %
  - 10.07.2024 → 6,50 %

**Pasul 1 — calcul rate aplicabile** (COMERCIAL + PENALIZATOARE = BNR + 8):
- Rata 1 = 7,00 + 8,00 = **15,00 %**
- Rata 2 = 6,75 + 8,00 = **14,75 %**
- Rata 3 = 6,50 + 8,00 = **14,50 %**

**Pasul 2 — segmentare și calcul pe perioade**:

| Perioadă             | Zile | Rata aplicabilă | Dobândă parțială                                  |
|----------------------|------|-----------------|---------------------------------------------------|
| 01.01.2024 – 15.03.2024 | 74   | 15,00 %         | 50.000 × 0,15 × 74 / 365 = **1.520,55 RON**       |
| 15.03.2024 – 10.07.2024 | 117  | 14,75 %         | 50.000 × 0,1475 × 117 / 365 = **2.363,53 RON**    |
| 10.07.2024 – 01.10.2024 | 83   | 14,50 %         | 50.000 × 0,145 × 83 / 365 = **1.648,29 RON**      |

**Pasul 3 — total**:

```
Dobânda totală = 1.520,55 + 2.363,53 + 1.648,29 = 5.532,37 RON
```

**Verificare**: 74 + 117 + 83 = 274 zile (= număr zile de la 01.01.2024 la 01.10.2024). ✔

---

## 8. Ce intră în creanța totală cerută în instanță

Creanța dedusă judecății prin ordonanța de plată cuprinde, de regulă:

1. **Principalul** (suma de bani datorată conform contractului/facturii);
2. **Dobânzile penalizatoare** calculate până la data introducerii cererii;
3. (Opțional) **penalități contractuale** distincte, dacă sunt prevăzute în contract;
4. **Cheltuieli de judecată** (taxa de timbru — 200 RON fix pentru OP, conform OUG 80/2013 art. 6 alin. 2 — și onorariu avocat dacă e cazul).

Dobânda calculată conform acestei metodologii constituie **punctul (2)** și se cere distinct față de principal, cu mențiunea în cerere că dobânda continuă să curgă **și după data calculului**, până la plata efectivă.

---

## 9. Auditabilitatea calculului

Pentru a fi reproductibil și apărabil în fața instanței, rezultatul calculului trebuie să cuprindă:

- suma principalului;
- data emiterii facturii, data scadenței și data de referință (cu normalizare 00:00:00);
- lista perioadelor segmentate (start, end, zile, rata BNR, rata aplicabilă, dobânda parțială);
- totalul dobânzii;
- temeiul legal (OG 13/2011 art. 3 alin. 2¹, L. 72/2013 art. 20);
- versiunea / sursa configurațiilor BNR folosite (data publicării BNR pentru fiecare rată).

Această descompunere permite verificarea independentă a calculului de către debitor, instanță sau expert.

---

## 10. Limitări curente (MVP)

- Doar moneda **RON**.
- Doar raporturi **COMERCIAL** (B2B). Raporturile civile (B2C, P2P) sunt în backlog post-MVP.
- Convenție de calcul **act / 365** (zile efective / 365). Variantele 30/360 sau act/360 nu sunt suportate.
- **Dobândă simplă** — fără capitalizare (anatocism interzis în lipsa convenției exprese).
- **Fără plafon legal al ratei în raporturile B2B.** Plafonul de +50 % peste dobânda legală din OG 13/2011 **art. 5 alin. (1)** se aplică *exclusiv* raporturilor care nu decurg din exploatarea unei întreprinderi (raporturi civile / non-profesionale, în sensul art. 3 alin. 3). Creanțele B2B din scope-ul MVP **nu intră** sub acest plafon: rata convențională poate fi orice rată agreată contractual. Singura limitare reală este o eventuală **clauză contractuală** care plafonează ea însăși dobânda (de ex. „dobânda nu poate depăși X % din principal").

---

## 11. Backlog post-MVP

Funcționalități identificate dar amânate explicit, fiindcă necesită modelare de date nouă și au risc juridic dacă sunt implementate superficial:

- **Imputația plății (art. 1509 alin. (2) NCC)** — la plăți parțiale primite în cursul procedurii/executării, stingerea pe ordinea cheltuieli → dobânzi → capital și recalculul dobânzii pe capitalul rezidual. Necesită un istoric de plăți cu data fiecărei plăți și sumele alocate pe componente (capital / dobânzi / cheltuieli), pentru a fi auditabil. Relevant mai ales în faza de executare silită (CPC art. 622 și urm.), în afara scope-ului OP propriu-zis.
- **Creanțe în valută** — conversie în RON pe baza cursului BNR la o dată aleasă de avocat, cu mențiune transparentă în document (vezi §6.4). Necesită sursă de curs valutar BNR și input manual confirmat de avocat.
