# Analiză juridică — Multi-debitor în procedura ordonanței de plată

**Data**: 2026-05-13
**Sursă**: consultație agent `avocat-senior` (răspuns formulat pe baza cunoștințelor de training pe CPC + practică procesuală; sursele Tier 1/2 — legislatie.just.ro, legeaz.net, juridice.ro — au fost indisponibile la momentul consultației, deci citatele verbatim necesită re-verificare la prima ocazie)
**Context produs**: Pas 3.3 LexRecovery — wizard step 2 acceptă 1-5 debitori per cerere de OP (`Step2DebtorsData::$debtors` cu `Assert\Count(min: 1, max: 5)`)
**Întrebare originală**: e justificat juridic să permitem cumularea mai multor pârâți (debitori) într-o singură cerere de ordonanță de plată?

---

## TL;DR

**DA**, multi-debitor (1-5) e justificat juridic — codul actual e corect — dar UI-ul are **4 lacune** care trebuie adresate înainte de production launch. Recomandare: păstrăm intervalul 1-5 (max 5 = product cap, nu juridic), adresăm lacunele A + B într-un follow-up imediat (UI-only, fără migrare DB), lăsăm C + D în backlog Pas 4.x cuplat cu `DeadlineService`.

---

## 1. Baza legală

### 1.1. Lipsa unei norme derogatorii în Titlul IX CPC (procedura OP)

Procedura OP (CPC art. 1013-1024) **NU conține nicio normă specială** care să permită sau să interzică expres cumularea mai multor pârâți într-o singură cerere.

**Art. 1013 alin. (1) CPC** definește domeniul:

> „Procedura ordonanței de plată se aplică creanțelor certe, lichide și exigibile constând în obligații de plată a unor sume de bani care rezultă dintr-un contract civil, inclusiv din cele încheiate între un profesionist și o autoritate contractantă, constatate printr-un înscris ori determinate potrivit unui statut, regulament sau altui înscris însușit de părți prin semnătură ori în alt mod admis de lege."

**Art. 1016 CPC** (conținutul cererii) folosește sintagma „debitor" la singular — aceasta e însă **convenție de redactare a normei**, nu restricție numerică (la fel cum „reclamantul" din alte articole nu exclude coreclamanții).

**Concluzie**: lipsind o normă derogatorie în Titlul IX, se aplică dreptul comun procesual — inclusiv litisconsorțiul.

### 1.2. Litisconsorțiul (CPC art. 59-66) ca temei general

**Art. 59 CPC** — norma permisivă de bază:

> „Mai multe persoane pot fi împreună reclamante sau pârâte dacă obiectul procesului este un drept ori o obligație comună sau dacă drepturile sau obligațiile lor au aceeași cauză ori dacă între ele există o strânsă legătură."

**Art. 60 CPC** distinge:
- **Litisconsorțiul obligatoriu** — natura raportului juridic impune judecata împreună (ex: obligație indivizibilă CC art. 1424+)
- **Litisconsorțiul facultativ** — legătura există dar nu e indispensabilă

### 1.3. Concluzia juridică

Multi-pârât în OP este **permis** prin aplicarea art. 59 CPC, **cu condiția** să fie întrunite ipotezele de coparticipare (obligație comună / aceeași cauză / strânsă legătură).

---

## 2. Ipoteze valide vs riscante

### 2.1. Cazuri sigure (legalitate clară)

| # | Ipoteza | Bază legală | Risc |
|---|---|---|---|
| 1 | **Codebitori solidari** pentru aceeași obligație (doi asociați care semnează același împrumut cu clauză de solidaritate, garanți solidari) | CC art. 1443+; art. 59 CPC (obligație comună) | Niciun risc |
| 2 | **Societate + administrator garant personal** (cu contract de garanție / fidejusiune) | CC art. 2279+ (fidejusiune); art. 59 CPC (cauză conexă) | Atenție: garantul fidejusor are beneficiul de discuțiune (CC art. 2294) dacă nu e solidar — instanța poate ridica excepția |
| 3 | **PF + PJ amestecați** dacă există legătură juridică (ex: PJ debitor principal + PF administrator garant solidar) | Tipul de persoană NU e criteriu de inadmisibilitate | Niciun risc per se |

### 2.2. Caz cu risc ridicat

**Ipoteza 3**: debitori cu **contracte distincte** (facturi separate, scadențe separate), legați **doar** de identitatea creditorului.

> Legătura de tip „același creditor" **nu e suficientă** — trebuie identitate sau conexitate a cauzei juridice. Instanțele resping cererile de OP cu pârâți multipli fără nicio legătură juridică între ei, calificându-le ca **inadmisibile prin nerespectarea art. 59** sau impunând **disjungerea**.

### 2.3. Caz INADMISIBIL fără ambiguitate

**Ipoteza 4**: pârâți complet independenți, fără nicio legătură juridică (ex: doi clienți care datorează sume din contracte total separate, aduși în aceeași cerere pur pentru comoditate). Art. 59 CPC e clar — această situație **NU** e o chestiune de practică variabilă, e inadmisibilitate fără echivoc.

### 2.4. Litisconsorțiul obligatoriu (rar în OP)

Apare când obligația este **indivizibilă** (CC art. 1424+) sau când prin natura ei nu poate fi executată parțial față de un singur debitor. Omiterea unuia atrage excepție de procedură.

---

## 3. Cerințe procedurale derivate pentru multi-debitor

### 3.1. Somația — comunicare **SEPARATĂ** obligatorie per debitor

**Art. 1015 alin. (1) CPC** impune comunicarea somației cu termen minim 15 zile. Textul **NU prevede somație colectivă**. Prin principiul dreptului la apărare + regulile de comunicare a actelor procedurale (art. 153+ CPC):

- **Fiecare debitor trebuie somat separat**, la **adresa sa proprie**
- Dovadă **individuală** de comunicare per debitor (scrisoare recomandată cu conținut declarat și confirmare de primire — R+CD+AR la Poșta Română, SAU executor judecătoresc)
- O singură somație adresată „debitorului 1 și debitorului 2" trimisă la **o singură adresă** este **inadmisibilă** ca dovadă față de debitorul care nu a primit-o la adresa sa

### 3.2. Termenul de 15 zile — **curge individual**, calculator ia MAX

- Termenul curge **de la data la care fiecare debitor a primit** somația (data confirmării de primire / proces-verbal executor)
- Exemplu: debitor 1 primește pe 1 octombrie, debitor 2 pe 10 octombrie → cererea OP **nu poate fi depusă** înainte de 25 octombrie (15 zile de la ultima primire)
- Calculator de termen: **maximul** datelor de primire confirmate (NU prima)

### 3.3. Taxa de timbru — 200 RON fix per **cerere**, indiferent de pârâți

**OUG 80/2013 art. 6 alin. (2)** instituie taxa fixă de 200 RON pentru „cererea privind ordonanța de plată". Nu există multiplicator per pârât — taxa se aplică **cererii**, nu pârâților.

**Concluzie pentru produs**: `StampDutyCalculator::calculate()` rămâne corect (200 RON fix) chiar cu multi-debitor — NU modifică formula.

### 3.4. Competența teritorială — instanța de la sediul ORICĂRUI pârât

**Art. 107 CPC** (regulă generală): competența teritorială revine instanței de la domiciliul/sediul pârâtului.

**Art. 112 CPC**: când există mai mulți pârâți, reclamantul poate sesiza instanța de la domiciliul/sediul **oricăruia** dintre ei, la alegere. Ceilalți sunt atrași la acea instanță prin efectul conexității.

**Alternativ**: art. 113 alin. (1) pct. 3 CPC permite competența de la locul executării obligației contractuale, independent de domiciliul pârâților.

**Consecință pentru `CompetentCourtResolver`**: când debtors.count ≥ 2 cu sedii în județe diferite, avocatul alege instanța strategică — produsul trebuie să afișeze opțiunile, NU să auto-aleagă.

---

## 4. Lacunele actuale ale produsului (Pas 3.3) — gap analysis

### Lacuna A — Lipsește **temeiul coparticipării** la debitor 2+

**Problemă**: wizard-ul curent nu cere avocatului să justifice de ce adaugă un debitor secundar. Risc: avocat completează ipoteza 4 (debitori independenți) → cerere disjunsă/respinsă de instanță → pierderea timpului + taxa de timbru.

**Soluție recomandată**:
- Câmp nou pe `Step2DebtorEntry` (DTO + entity `Debtor`): `coparticipationGround` (enum nullable)
  - `SOLIDAR` — codebitori solidari (aceeași obligație, contract unic)
  - `FIDEJUSOR` — garant/fidejusor al debitorului principal
  - `CODEBITOR_CONTRACT` — codebitor din contract de asociere / parteneriat
  - `OTHER` — alt temei (câmp text liber, risc explicit pe avocat)
- Câmpul devine obligatoriu pentru entries[1..n] (NU pentru entries[0] — debitorul principal)
- UI: select dropdown afișat condițional când `loop.index0 > 0`

**Scope**: Pas 3.3.1 (UI-only) sau Pas 3.4 (cu migrare DB pentru entity).

### Lacuna B — Lipsește **avertisment juridic** la debtor.count ≥ 2

**Problemă**: avocatul nu e informat că art. 59 CPC are condiții și că debitori fără legătură = inadmisibilitate.

**Soluție recomandată** — warning amber permanent vizibil când count ≥ 2 (NU eroare blocantă, ci informație):

> ⚠️ **Atenție: procedura ordonanței de plată cu mai mulți pârâți este admisibilă doar dacă există o obligație comună sau o legătură juridică strânsă între debitori (CPC art. 59).**
>
> Debitori din contracte distincte fără legătură între ei pot determina disjungerea cauzei sau respingerea cererii.
>
> **Fiecare debitor trebuie somat separat**, cu dovadă individuală de comunicare (CPC art. 1015 alin. 1) — scrisoare recomandată cu conținut declarat la Poșta Română SAU executor judecătoresc.

**Scope**: Pas 3.3.1 (UI-only, 0 cod backend).

### Lacuna C — `paymentNoticeDate` **global per dosar**, NU per debitor

**Problemă**: actualmente `LegalCase::$paymentNoticeDate` (sau equivalent în deadlineService) e un singur câmp pentru tot dosarul. Pentru multi-debitor cu somații primite la date diferite, termenul de 15 zile trebuie calculat din MAX al primirilor.

**Soluție recomandată**:
- Câmp nou pe entity `Debtor`: `paymentNoticeReceivedAt: ?\DateTimeImmutable`
- `DeadlineService::createPaymentNoticeDeadline()` (Pas 4.1) ia `MAX(debtors.paymentNoticeReceivedAt)` în loc de un câmp global
- Migrare DB: ADD COLUMN pe `debtor` + backfill cu `legal_case.payment_notice_date` (placeholder pe legacy)
- Validare la depunere cerere: blocaj dacă vreun `Debtor::$paymentNoticeReceivedAt` IS NULL (nu putem depune cerere fără confirmarea primirii pentru toți)

**Scope**: Pas 4.x — cuplat cu `DeadlineService::createPaymentNoticeDeadline` (Pas 4.1) și UI pentru somație (Faza 5).

### Lacuna D — `OpAdmissibilityValidator` NU verifică temeiul coparticipării

**Problemă**: validatorul curent (Pas 2.4) verifică admisibilitatea per debitor independent (ANAF radiat, insolvență, etc.) — dar NU validează că multi-debitor are temei legal de coparticipare.

**Soluție recomandată**:
- Adăugat issue nou: `OP_NO_COPARTICIPATION_GROUND` (severity WARNING)
- Trigger: `debtors.count ≥ 2` AND vreun `Debtor::$coparticipationGround` IS NULL pentru entries[1..n]
- Mesaj: "Debitor secundar fără temei de coparticipare selectat — risc disjungere conform CPC art. 59."

**Scope**: Pas 3.3.1 sau Pas 4.x (cuplat cu Lacuna A — necesită câmpul `coparticipationGround` să existe înainte).

---

## 5. Caveat metodologic + verificări de făcut

### 5.1. Citatele verbatim CPC

Agentul `avocat-senior` a livrat răspunsul fără acces la legislatie.just.ro (eroare 404/500 în sesiune). Citatele art. 1013, 59, 1015, 107, 112, 113, OUG 80/2013 art. 6 sunt din cunoștința de training a modelului.

**Acțiune înainte de production**: verifică la prima ocazie textul exact pe legislatie.just.ro pentru:
- CPC art. 59 (formularea exactă a „obligație comună / aceeași cauză / strânsă legătură")
- CPC art. 1013 alin. (1)
- CPC art. 1015 alin. (1) — modurile de comunicare a somației
- CPC art. 107, 112, 113 — competența teritorială
- OUG 80/2013 art. 6 alin. (2) — taxa fixă 200 RON

### 5.2. Practica judiciară (verificare necesară)

Agentul **NU a putut accesa scj.ro / rejust.ro / portal.just.ro** în sesiune. Concluziile despre practică („instanțele admit", „instanțele disjung") sunt afirmații generale, NU citate cu număr decizie.

**Acțiune înainte de production**:
- Verifică **rejust.ro** după cuvinte-cheie: "ordonanță de plată" + "litisconsorțiu" / "art. 59" / "disjungere"
- Caută hotărâri ICCJ / curte de apel care confirmă/resping multi-debitor în OP
- Verifică dacă există RIL (recurs în interesul legii) sau HP (hotărâre prealabilă) ICCJ care tranșează chestiunea — agentul a indicat că **nu cunoaște** astfel de decizii publicate, ceea ce înseamnă că practica instanțelor inferioare poate varia

---

## 6. Decizia operațională

### Imediat — păstrăm 1-5 debitori în produs

Codul actual (Pas 3.3) e **corect juridic** pentru intervalul 1-5. Nu se schimbă nimic în logica de business.

### Follow-up urgent (Pas 3.3.1) — UI-only

1. **Lacuna A** — adăugat dropdown `coparticipationGround` (enum 4 valori) afișat când `loop.index0 > 0` în Step 2 wizard
2. **Lacuna B** — warning amber permanent în `_step2_debtor_content.html.twig` când count ≥ 2

Scope: 1-2h, fără migrare DB (`coparticipationGround` ca string pe DTO, persistat ca JSON column pe Debtor sau ignorat la persist pentru moment).

### Backlog Pas 4.x — cuplat cu DeadlineService + somație

3. **Lacuna C** — `paymentNoticeReceivedAt` per Debtor + DeadlineService MAX logic
4. **Lacuna D** — `OP_NO_COPARTICIPATION_GROUND` issue în OpAdmissibilityValidator

Scope: 4-6h cu migrare DB + backfill + update validator + update fixtures.

---

## 7. Referințe încrucișate

- **PLAN-DEZVOLTARE-LEXRECOVERY.md** — Pas 3.3 (Live Components + multi-debitor) — codul curent care necesită adresarea acestor lacune
- **ANALIZA-JURIDICA-PROCEDURA-OP-2026-05-08.md** — analiza juridică inițială pe procedura OP (NU acoperă explicit multi-debitor)
- **CONSULTATIE-AVOCAT-2026-05-09.md** — consultație anterioară cu avocat-utilizator (de verificat dacă acoperă chestiunea)
- **Pas 2.4** — `OpAdmissibilityValidator` (locul unde se va integra Lacuna D — issue `OP_NO_COPARTICIPATION_GROUND`)
- **Pas 4.1** — `DeadlineService` (locul unde se va integra Lacuna C — `paymentNoticeReceivedAt` per Debtor MAX)

---

## 8. Concluzie finală

> **Multi-debitor 1-5 e JUSTIFICAT juridic pentru procedura OP**, cu condiția implementării celor 4 îmbunătățiri (A + B urgent, C + D backlog). Reducerea la 1 debitor strict ar elimina cazuri de utilizare legitime și frecvente (societate + administrator garant solidar) și ar dezavantaja produsul față de practica reală a avocaților. Codul actual e ok pentru MVP; lacunele A + B trebuie închise înainte de prima sesiune de production cu avocați-utilizatori reali.
