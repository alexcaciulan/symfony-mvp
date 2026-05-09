# Consultanță juridică — proiect software pentru procedura ordonanței de plată

**Data**: 2026-05-09
**Scope produs**: LexRecovery — aplicație SaaS B2B pentru cabinete de avocatură, automatizează **procedura ordonanței de plată** (CPC art. 1013-1024). Strict OP, NU include cererea cu valoare redusă (Titlul X) sau drept comun.
**Estimat răspuns**: ~30 min (14 întrebări, majoritar de bifat)

## Cum răspundeți

Pentru fiecare întrebare am inclus 2-3 variante. Bifați varianta corectă (sau scrieți „varianta corectă: X" alături) și, dacă aveți observații punctuale, adăugați-le sub întrebare. Variantele marcate cu **⬅ presupunerea noastră** sunt cele pe care le-am dedus din analiza juridică internă — confirmarea sau infirmarea dvs. ne ajută să decidem rapid.

Răspunsurile vor sta la baza unei aplicații folosite zilnic de cabinete pentru a depune cereri OP la instanță — deci precizia juridică e esențială. Nu cer opinii definitive, ci practica și interpretarea dvs. dominantă.

---

## A. Decizii strategice de scope

### A1. Tipuri de raporturi pe care platforma trebuie să le acopere

**Context**: Rata dobânzii legale penalizatoare diferă în funcție de natura raportului (B2B = profesionist↔profesionist, B2C = profesionist↔consumator, P2P = particular↔particular). Fiecare caz cere o formulă diferită de calcul. În practica dvs., ce tip de creanțe recuperați prin OP?

- [ ] **Doar B2B** (creditori profesioniști vs. debitori profesioniști — facturi neîncasate B2B). Formulă unică: `BNR + 8 pp`. **⬅ presupunerea noastră, dat fiind targetul cabinetelor specializate**
- [ ] **B2B + B2C** (incluzând recuperare de la consumatori — bănci, telecom, servicii). Două formule.
- [ ] **Toate trei (B2B + B2C + P2P)**. Cinci formule (penalizatoare + remuneratorie pe fiecare tip + diminuare 20% pentru P2P).

### A2. Citarea legii pentru rata penalizatoare +8pp în cererea OP

**Context**: Norma se găsește la Legea 72/2013 art. 20, care a introdus alin (2¹) în art. 3 OG 13/2011. **Pe `legislatie.just.ro`, consolidatul OG 13/2011 nu afișează alin (2¹)** — judecătorul care verifică citarea pe portal nu îl va găsi. Cum redactăm citarea în textul cererii OP, pentru a evita ambiguitate?

- [ ] **„art. 3 alin (2¹) OG 13/2011"** — scurt, dar potențial confuz dacă judecătorul caută pe portal
- [ ] **„art. 3 alin (2¹) OG 13/2011, astfel cum a fost introdus prin art. 20 din Legea 72/2013"** — neambiguu **⬅ presupunerea noastră**
- [ ] **„art. 20 din Legea 72/2013 (modificare la OG 13/2011)"** — direct la actul în vigoare
- [ ] Altă formulare preferată: ____________________________________________

---

## B. Procedură comunicare

### B1. Termen somație prealabilă (CPC art. 1015)

**Context**: Legea cere minim 15 zile. Unii avocați dau 15 zile fix, alții 30 zile pentru rigurozitate (în caz de probleme cu poșta). Care este uzanța dvs. și ce default ar trebui să propună platforma?

- [ ] **15 zile (default, modificabil)** — termenul minim legal, ridică șansele unei cereri OP rapide **⬅ presupunerea noastră**
- [ ] **30 zile (default, modificabil)** — pentru siguranță; reduce riscul ca debitorul să invoce neprimire
- [ ] **Nu există default — avocatul trebuie să aleagă explicit la fiecare dosar**

### B2. Modalități de comunicare a somației

**Context**: CPC art. 1015 prevede „executor judecătoresc SAU scrisoare recomandată cu conținut declarat și confirmare de primire". Curierul privat (Cargus, FAN, DPD etc.) **nu oferă serviciu de conținut declarat** — doar Poșta Română. Există jurisprudență divergentă: unele instanțe au admis curierul privat, altele au respins OP pe motiv de inadmisibilitate.

În practica dvs., platforma ar trebui să:
- [ ] **Permită doar 2 opțiuni: executor sau Poșta R+CD+AR** — restricție strictă, fără riscuri
- [ ] **Permită și curier privat, cu warning explicit** asupra riscului de inadmisibilitate **⬅ presupunerea noastră (dă flexibilitate, dar avertizează)**
- [ ] **Permită orice mod, fără warning** — răspunderea e a avocatului

### B3. Data comunicării ordonanței către părți (M9)

**Context**: Termenul de 10 zile pentru cererea în anulare (CPC 1024) curge de la data **comunicării** ordonanței, NU de la data emiterii. Sistemul are nevoie să cunoască data comunicării ca să marcheze automat ordonanța ca definitivă.

- [ ] **Data apare pe portal.just.ro** și avocatul o copiază manual în aplicație **⬅ presupunerea noastră (verificat empiric)**
- [ ] **Data trebuie cerută separat de la grefa instanței** (portal nu e fiabil)
- [ ] **Aplicația nu trebuie să marcheze automat ca definitivă** — avocatul confirmă manual, e prea riscant

---

## C. Admisibilitate cerere OP (verificări pre-depunere)

### C1. Verificare insolvență debitor (BPI)

**Context**: Dacă debitorul e în procedura insolvenței (Legea 85/2014), creanțele se înscriu la masa credală — cererea OP e inadmisibilă. BPI (`bpi.just.ro`) nu are API public, deci verificarea e manuală.

Ce nivel de enforcement în platformă?
- [ ] **Blocaj total**: bifă obligatorie + atașament PDF cu extras BPI; fără ele, butonul „Depune cerere" e dezactivat
- [ ] **Blocaj soft**: bifă obligatorie cu confirmare („Am verificat BPI la data X, debitorul nu este în insolvență"); atașament opțional **⬅ presupunerea noastră**
- [ ] **Doar warning informativ**: avocatul își asumă responsabilitatea, fără bifă

### C2. Clauze compromisorii (arbitraj)

**Context**: Dacă contractul-bază al creanței conține o clauză de arbitraj validă (CPC art. 542+), cererea OP la instanță poate fi respinsă pe excepția de necompetență. Aplicabilitatea RIL ÎCCJ 4/2017 la procedura OP — cum o interpretați?

- [ ] **Bifă opțională „contractul conține clauză de arbitraj"** + warning UI dacă bifată; fără blocaj **⬅ presupunerea noastră**
- [ ] **Blocaj** dacă bifată — cererea OP e inadmisibilă în prezența unei clauze de arbitraj valide
- [ ] **Nu e necesar nici warning** — în practică debitorii rar invocă

### C3. Valoarea pentru pragul 200.000 RON judecătorie/tribunal (CPC art. 94/95)

**Context**: Pragul determină instanța competentă. Întrebarea critică: pragul se aplică pe **principal singular** sau pe **principal + accesorii** (dobânzi, penalități acumulate la data sesizării — per art. 98 CPC)?

- [ ] **Principal + accesorii la data sesizării** — total = principal + dobândă scadentă + penalități **⬅ presupunerea noastră**
- [ ] **Doar principal** — accesoriile se calculează separat și nu intră în prag
- [ ] **Există jurisprudență divergentă** — vă rog să indicați direcția dominantă în practica dvs.: ____________

---

## D. Termene și calcul

### D1. Întrerupere prescripție prin somație (NCC art. 2540)

**Context**: Norma cere ca somația (punerea în întârziere) să fie urmată de cerere de chemare în judecată în 6 luni, altfel întreruperea nu operează. Confirmați?

- [ ] **DA, condiția de 6 luni se aplică strict** — sistemul trebuie să creeze deadline „depune cerere OP până la [somatie + 6 luni]" pentru a păstra întreruperea **⬅ presupunerea noastră**
- [ ] **DA, dar uzanța admite excepții** (vă rog să detaliați): __________
- [ ] **NU se aplică în acest mod la OP** (vă rog să clarificați): __________

### D2. Tribunale specializate Cluj, Mureș, Argeș (Legea 304/2022)

**Context**: Pentru creanțe B2B > 200.000 RON din jurisdicțiile celor 3 județe, competent este Tribunalul Specializat (NU Tribunalul standard).

- [ ] **Competență exclusivă** pe profesional în jurisdicțiile lor — sistemul face routing automat **⬅ presupunerea noastră**
- [ ] **Competență alternativă** — avocatul alege; sistemul oferă ambele opțiuni
- [ ] **În practică nu mai funcționează** — toate dosarele B2B merg la Tribunalul standard

### D3. Anatocism procesual (NCC art. 1489 alin (2) teza finală)

**Context**: Norma permite ca dobânzile cerute în instanță să producă ele însele dobânzi, începând de la data cererii de chemare în judecată — chiar fără convenție contractuală. Întrebarea: în practica OP, avocații cer acest anatocism procesual?

- [ ] **Frecvent — implementăm în MVP** ca opțiune bifabilă în wizard
- [ ] **Rar — out of scope MVP**, post-MVP eventual **⬅ presupunerea noastră**
- [ ] **Niciodată — instanțele de OP nu acordă** chiar dacă e cerut

---

## E. Conținut documente / depunere

### E1. Împuternicire avocațială

**Context**: La depunerea cererii OP, registratura cere copia împuternicirii avocațiale (formular bar). Întrebări:
- (a) Este obligatoriu anexată la **orice** dosar OP, sau doar dacă există încuviințare specială?
- (b) E suficient formularul standard al baroului, sau e nevoie și de o delegație de reprezentare separată?

Răspunsul dvs. (text liber, scurt): __________________________________________________________

(Implementăm validator pre-depunere care refuză tranziția fără atașament dacă e obligatoriu.)

### E2. Cheltuieli de judecată în cererea OP (CPC art. 451-453)

**Context**: Cererea OP poate include taxa timbru + onorariu avocat + alte cheltuieli. În practica dvs., instanțele de OP acordă onorariul avocațial și care e plafonul rezonabil?

- [ ] **Da, onorariul se acordă constant**, dacă e proporțional cu complexitatea — fără plafon fix, dar instanța poate reduce. **⬅ presupunerea noastră**
- [ ] **Da, dar instanțele aplică un plafon practic** de _____% din creanță sau valoarea fixă de _____ RON
- [ ] **Rar acordat** — depinde mult de instanță; mai bine nu îl includem automat

Sugestie cuantum default pentru avocat (aplicația poate sugera): ____________

### E3. Creanțe în valută

**Context**: OG 13/2011 referă rata BNR (RON). Pentru creanțe în EUR/USD, calculul dobânzii și taxa timbru devin neclare (EURIBOR? curs BNR la sesizare?). Putem **exclude** creanțele în valută din MVP și să le adăugăm post-MVP?

- [ ] **DA, exclude din MVP** — în practica B2B internă, sub 5% din dosare sunt în valută **⬅ presupunerea noastră**
- [ ] **NU, sunt frecvente** — trebuie acoperite din MVP (vă rog să indicați cum se calculează dobânda în EUR la o cerere OP)
- [ ] **Avocații preferă să convertească la RON** la cursul BNR din ziua somației și să trateze ca RON

---

## Întrebare deschisă

Există vreun aspect important pe care nu l-am ridicat în întrebările de mai sus, dar care credeți că ar trebui clarificat înainte ca aplicația să fie folosită în producție pentru cereri OP reale? (ex: nuanță procedurală frecventă, capcană tipică, o decizie ÎCCJ recentă pe OP, modificări de practică din 2025-2026 etc.)

Răspuns liber:
_____________________________________________________________________________
_____________________________________________________________________________
_____________________________________________________________________________

---

## Mulțumiri

Vă mulțumim pentru timpul acordat. Răspunsurile dvs. vor sta direct la baza fluxului implementat în aplicație și a textelor generate automat (somație, cerere OP, opis). Pentru clarificări sau dacă o întrebare nu se aplică în context, vă rog să ne contactați.
