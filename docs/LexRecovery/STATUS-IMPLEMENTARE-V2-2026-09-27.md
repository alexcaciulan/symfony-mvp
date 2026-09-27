# Status după implementarea rundei V2, taxa de timbru

> Ce s-a livrat efectiv, ce nu, și ce așteaptă un răspuns. Verificat pe cod la data documentului,
> nu reconstituit din planuri.
> Branch: `feature/taxa-timbru-revizie-avocat`, la zi cu `lexrecovery` (merge `49697de`).
> **Toate modificările de mai jos sunt NECOMISE.** Suita: 2747 teste, 0 eșecuri.
>
> Documentul de analiză care a stat la baza lotului: `DECIZIE-MENTIUNI-AVOCAT-V2-2026-08-19.md`.
> Mențiunile transcrise ale avocatului: `MENTIUNI-AVOCAT-V2-2026-08-19.md`.

---

## 1. Pe scurt

Din 36 de mențiuni: **11 nu cereau nimic** (codul era deja corect), **6 s-au implementat**,
**1 s-a respins argumentat**, **18 au rămas**, dintre care majoritatea blocate pe o decizie de
produs și pe răspunsuri de la avocat.

Ce s-a livrat este partea ieftină și necontroversată. **Miezul reviziei lui, lotul mandatului de
reprezentare, nu e implementat.**

Amprenta: 31 de fișiere modificate, 4 șterse, 4 noi. 398 de linii adăugate, 714 șterse.

---

## 2. Ce s-a implementat

### 2.1. Textele amânării (C2, C3)

Eticheta a devenit **„Depun acum, timbrez ulterior"**, varianta neutră pe care a ales-o explicit.
Textele din modal și de pe card au fost aliniate, RO și EN.

S-a adăugat justificarea lui ca text de produs: sunt instanțe care au comunicat înștiințarea la
domiciliul clientului, nu la cabinet, deci plata timpurie elimină acest motiv de înștiințare.
Formularea **nu** promite că nu mai vine nicio regularizare și **nu** afirmă că instanța procedează
nelegal ca regulă.

### 2.2. Retragerea mementourilor (C4)

Cadența de 3, 10 și 24 de zile fusese aleasă arbitrar de noi. Avocatul a spus că nu există termen
practic, fiecare instanță procesează când îi vine rândul.

Șterse: `StampDutyReminderService`, comanda `app:check-stamp-duty`, șablonul de e-mail, tipul de
notificare `STAMP_DUTY_UNPAID`, testul dedicat, și cele trei coloane de urmărire de pe `legal_case`
(migrarea `Version20260821090000`).

Verificat înainte de ștergere: comanda **nu era programată nicăieri**, deci retragerea nu e o
regresie funcțională. Zona rămâne acoperită de alertele de blocaj pe dosar, care existau deja.

### 2.3. Deep-link către formularul corect de pe registratură (F2)

Bug real: pe un dosar depus dar fără număr alocat, cardul și e-mailul trimiteau avocatul la
formularul „Înregistrează un dosar nou". Dacă îl urma, **înregistra un al doilea dosar** pentru
aceeași cerere.

Regula trăiește acum într-un singur loc, `LegalCase::rejustStampDutyForm()`, cu enum-ul nou
`RejustStampDutyForm`:

| Starea dosarului | Destinație |
|---|---|
| Nedepus | formularul de înregistrare dosar nou |
| Depus, fără număr | **niciun formular potrivit**, deci niciun link; apare o explicație |
| Cu număr alocat | formularul de plată într-un dosar existent |

### 2.4. Numărul din Tabloul Baroului (E4, parțial)

Iese din fraza de reprezentare și din subsolul cererii de ordonanță de plată. **Rămâne în somație și
în opis**, pentru că avocatul a comentat doar cererea. Câmpul din profil nu s-a atins.

### 2.5. Corecții de adevăr și cod mort (consecințe ale A1 și A5)

- denumirea contului bugetar nu se mai traduce în engleză. Localea EN e comutabilă din interfață,
  deci cineva putea copia într-un ordin de plată numele unui cont care nu există
- constanta moartă `ACCOUNT_NAME` și proprietatea moartă `courtName` din DTO, șterse
- două docblock-uri lipite în `LegalCaseRepository`, dintre care primul documenta metoda greșită

### 2.6. Poarta de extracție pentru tipurile neclasificabile

Verificat pe cod și confirmat: predicatul `isClassifiable()` era consultat doar în vocabularul
clasificatorului, nu și pe traseul de dispecerizare. Mandatul de reprezentare, care poate conține
numele clientului și eventual CNP, ajungea la procesatorul extern fără niciun beneficiu.

Poarta e acum în cascadă, cu motiv propriu de oprire. Consecință tratată: eticheta de status nu mai
poate spune „extracție dezactivată", fiindcă acoperă două cauze, iar linkul către acordul AI se
afișează doar când cauza e chiar o alegere de confidențialitate.

---

## 3. Ce a fost respins

**B2**, „nu se poate achita prin registratură odată cu depunerea, pentru că nu ai încă număr de
dosar".

Formularul „Înregistrează un dosar nou" conține el însuși opțiunile de taxă. Plata se face în același
pas cu depunerea, înainte să existe orice număr. Este chiar formularul din captura pe care ne-a
trimis-o el la F1, deci îl cunoaște și îl folosește.

B2 e coerentă doar sub premisa de la §4.1. Defectul e în premisă, nu în raționamentul lui.

---

## 4. Ce a rămas, și de ce

### 4.1. Blocant, decizie de produs: cine depune la instanță

Avocatul presupune, în mai multe mențiuni, că platforma trimite dosarul prin e-mail către instanțe.

**Nu e greșeala lui.** Are două surse, ambele ale noastre:

1. La §7 din documentul de flux i-am scris că depunerea prin rejust cere sesiunea și semnătura lui
   electronică calificată. **Este fals.** Formularul de înregistrare e anonim, fără cont, iar
   portalul acceptă și acte semnate olograf și scanate.
2. În agenda de ședință din 28 iulie, comisă, i-am propus explicit varianta în care platforma
   transmite prin e-mail în numele avocatului.

**Prima acțiune, înaintea oricărei discuții: retractare scrisă a afirmației de la §7.**

Opțiunile, cu costul fiecăreia, sunt în secțiunea 2.1 a documentului de decizie. Pe scurt: modelul
actual costă zero, varianta în care platforma expediază înseamnă 3 până la 5 săptămâni, colectarea a
223 de adrese de la zero, imposibilitatea de a semna `From: avocat` fără să spargem SPF și DKIM, și
riscul de eșec tăcut pe secția greșită la tribunale.

Această decizie blochează aproximativ 9 zile de lucru.

### 4.2. Lotul mandatului de reprezentare, circa 5 zile

Cea mai substanțială cerere din rundă, neimplementată:

- **D3**, lipsa mandatului să **blocheze** depunerea, nu doar să avertizeze. Argumentul lui e mai
  bun decât al nostru: lipsa dovezii calității de reprezentant duce la respingere pe excepție, nu la
  regularizare. Azi e tot avertisment
- **D1**, câmpuri serie, număr și dată de emitere. Nu există pe `Document`
- **D6**, redenumirea în „mandat de reprezentare", ca să acopere și consilierii juridici
- **D4**, cele trei câmpuri afișate în opis

Depinde de decizia 2.2 din documentul de decizie (dacă admitem scenariul consilierului juridic, ceea
ce ar face ca fraza de reprezentare din cerere să spună ceva neadevărat) și de trei răspunsuri.
Fiind peste pragul de 3 zile, cere document de plan dedicat înainte de cod.

### 4.3. Textele care ajung la judecător

- **E5**, textul exact al domiciliului ales, cu e-mail și telefon și cu „persoană desemnată". În cod
  e tot varianta noastră veche. Are o precondiție blocantă: garda actuală înfășoară și declararea
  reprezentării, nu doar adresa, deci extinsă naiv ar produce cereri în care creditorul apare
  nereprezentat
- **E8**, formatul adresei cu județ obligatoriu, „Sector 5, București" sau „Municipiul Iași, județ
  Iași"
- **E1** și **E3**, formularea de petitum pentru plata prin registratură și numirea serviciului
- **E10**, numerotarea articolelor. El spune 1017, verificarea noastră pe sursă primară dăduse 1016.
  Nu e o greșeală, sunt două forme publicate ale codului. **Nicio citare nu se atinge** până nu se
  tranșează, altfel ajungem la acte în care cererea invocă un articol și somația altul

### 4.4. Mecanismul de plată la alocarea numărului (C1, C5)

Ideea lui centrală din rundă. Depinde de un răspuns: primește numărul de dosar în confirmarea de la
portal, sau îl află abia căutând pe portal.just.ro după câteva zile? Platforma nu îl descoperă
singură, deci de asta depinde tot declanșatorul.

---

## 5. Ce trebuie clarificat cu avocatul

Lista completă, 14 întrebări, e în secțiunea 5 a documentului de decizie. Blocante sunt trei:

1. **Modelul de depunere.** Rămâne cum e, sau cere explicit ca platforma să expedieze actul?
2. **Numărul de dosar.** Îl primește în confirmarea de la portal, sau îl caută pe portal?
3. **Numerotarea codului.** Care formă e în vigoare, ca să mutăm toate citările deodată.

Plus, de la utilizator, nu de la avocat: dacă admitem scenariul consilierului juridic, pentru că de
asta depinde ce poate spune cererea despre reprezentare.

---

## 6. Ce s-a prins la review și nu la teste

Două lucruri care au trecut de suita verde și au fost găsite abia de review-ul adversarial:

- **cardul se contrazicea singur.** Paragraful nou spunea că plata prin registratură nu e posibilă
  încă, iar imediat sub el rămânea butonul „Plătesc la depunere, prin registratură"
- **o gaură de acoperire produsă de ștergerea mementourilor.** Un dosar ajuns la instanță cu taxa în
  starea „plătesc la depunere" nu mai era prins de nicio alertă, adică exact avocatul care a spus că
  plătește și nu a plătit

Ambele reparate, a doua cu test propriu.

---

## 7. Verificare

- suita completă: **2748 teste, 0 eșecuri**, 1 skip preexistent
- lint YAML și Twig: curate
- review de cod și review juridic pe diff, cu verificare adversarială a constatărilor: fără defecte
  funcționale sau juridice
- verificare live în browser: cardul în cele trei stări de deep-link, modalul de amânare (anulare și
  trimitere), denumirea contului pe interfața EN, modul întunecat, pagina de notificări. Cererea OP
  randată fără efecte: fraza de reprezentare nu mai conține numărul din Barou
- prins în browser și reparat: pe un dosar depus fără număr, cu taxa „se achită la depunere", cardul
  îi spunea avocatului să aștepte numărul ca să plătească, sub instrucțiunea de a confirma plata
  făcută la depunere. Nota apare acum doar în stările în care plata chiar așteaptă numărul, cu test
  propriu
- netestat în browser: eticheta de extracție din pasul 0 al wizard-ului (cere încărcarea unui
  document); cheile de traducere există în ambele limbi
