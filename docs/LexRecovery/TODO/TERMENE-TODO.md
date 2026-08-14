# Termene: lista de lucru după revizia avocatului

Stare: pagina de termene și corecțiile de calcul sunt comise pe `lexrecovery`.
Sursa acestei liste: `RASPUNS-REVIZIE-AVOCAT-TERMENE-2026-08-05.md`, care analizează cele 26 de
mențiuni albastre din documentul întors de avocat. Lista anterioară, dinaintea reviziei, este
închisă: punctele ei sunt fie implementate, fie preluate mai jos în altă formă.

Ordinea din fiecare grup este a riscului, nu a efortului. Efort: S sub o zi, M una până la două zile,
L peste două zile.

---

## A. Se pot face acum, fără alte răspunsuri

| # | Ce | De ce | Efort |
|---|---|---|---|
| A1 | **Recalcularea termenelor existente** pe regula zilelor libere, o singură dată, pe dosarele active, cu urmă în audit pe fiecare termen atins | Avocatul a confirmat regula. Azi coexistă termene vechi, scurte cu o zi, și termene noi | M |
| A2 | **„În curs de comunicare" în loc de termen estimat.** Rândul nu mai afișează dată până la introducerea datei reale; acțiunea devine „Introdu data comunicării"; dosarul rămâne în lista de blocaje | O dată calculată de la generarea documentului nu are valoare juridică, iar marcajul „estimat" nu împiedică citirea ei ca termen | M |
| A3 | **Alerte pentru dosarele blocate.** Trei declanșatoare: ordonanță emisă fără data comunicării, dosar descoperit pe portal fără taxă achitată, înștiințare de regularizare fără dată. Cu limitare de frecvență per dosar | Zona de blocaje e pasivă azi. Alertele există doar pentru termene calculate, adică fix pentru cazurile care nu sunt blocate | M |
| A4 | **Închiderea prescripției executării la trecerea efectivă în executare.** Implementat: închiderea se face pe data depunerii cererii la executor, cerută în modalul de trecere în executare, nu pe status; fără dată, termenul rămâne deschis. Închiderea se poate anula din card, pe cazurile CPC art. 708 alin. 3 | Cererea către executor întrerupe termenul (CPC art. 708 alin. 1 pct. 2). Azi îl creăm la intrarea în executare, adică exact invers | S |
| A5 | **Ancora prescripției executării mutată pe hotărârea din cererea în anulare**, când aceasta există. Până la introducerea datei, dosarul intră în blocaje | Ordonanța nu rămâne definitivă la expirarea celor 10 zile dacă s-a depus cerere în anulare | M |
| A6 | **Închiderea automată a termenului de cerere în anulare** la expirarea celor 10 zile de la data comunicării, plus o perioadă de așteptare configurabilă de 5 zile lucrătoare înainte de a împinge dosarul spre executare | Cerut explicit. Înlocuiește modelul cu două ferestre, care oricum nu era derivabil din date | M |
| A7 | **Prorogarea aplicată celor două termene de prescripție** | Confirmat cu „Da". Azi nu o aplicăm | S |
| A8 | **Legarea dovezii de comunicare de câmpul de dată.** Verificat: azi setarea datei nu cere și nu leagă niciun document | O dată fără dovadă nu are valoare la dosar | S |
| A9 | **Textul „Prescripție întreruptă până la data Z"**, unde Z este data comunicării somației plus 6 luni | Propunerea lui, mai bună decât marcajul de incertitudine pe care îl aveam | S |
| A10 | **Eliminarea butonului de ascundere din listă** | „De ce să nu vrei să mai apară în listă?" Nu avem un răspuns bun. Cu textul de la A9, termenul nu mai deranjează | S |
| A11 | **Renunțarea la alertele de 30 și 14 zile pe cererea în anulare**, păstrarea lor pe prescripție, cu praguri diferite: 30 și 14 zile pentru cea generală, 60 și 30 pentru cele 6 luni | Termenul cererii în anulare e de 10 zile, deci pragurile propuse de noi erau absurde aritmetic | S |
| A12 | **Reformularea textului despre cele 6 luni**, ca să nu apară ca interdicție de a depune | Nu e termen de decădere din dreptul de a depune, ci condiția de care depinde menținerea întreruperii | S |

---

## B. Blocate pe răspunsul avocatului

| # | Ce | Ce așteptăm |
|---|---|---|
| B1 | **Corectarea numerotării articolelor**, 1013 în 1014 și celelalte. Apare în traduceri, validatori, patru șabloane de documente și circa zece fișiere de cod. Patru apariții sunt text tipărit pe somația trimisă debitorului și pe cererea depusă la instanță | Lista completă, articol cu articol. Deplasarea nu e uniformă, deci nu se poate deduce prin regulă |
| B2 | **Modelul de timbrare.** Declanșatorul devine descoperirea numărului de dosar, cu memento la Z+1. Întrebarea e dacă ramura de regularizare dispare sau rămâne ca plasă de siguranță | Dacă renunțăm complet la ramura de regularizare |
| B3 | **Momentul care oprește cele 6 luni.** Implementăm pe varianta depunerii, care e cea uzuală, dar întrebarea a rămas fără răspuns | Depunerea la instanță sau prima zi de judecată |
| B4 | **Prescripția executării: art. 705 sau 706.** Mențiunea lui pare o scăpare, probabil a aplicat deplasarea de la 1013 în 1014 și aici | Confirmarea articolului, în ediția în care ordonanța de plată începe la 1014 |
| B5 | **Urmărirea propriului termen de cerere în anulare**, când ordonanța e admisă în parte | Dacă are nevoie de el sau se ocupă singur |
| B6 | **Prorogarea la prescripție.** A confirmat, dar e singurul loc unde data afișată devine mai târzie decât cea brută, deci mai puțin conservatoare | Confirmarea că acceptă compromisul |
| B7 | **Închiderea prescripției executării**, la marcarea trecerii în executare sau la confirmarea că executorul a înregistrat cererea. Până la răspuns, aplicația închide pe data depunerii declarată de avocat și lasă închiderea reversibilă; dacă răspunsul e „la confirmarea executorului", data devine cea din confirmare | Care dintre cele două momente |

---

## C. Scop nou, backlog separat

| # | Ce | Observație |
|---|---|---|
| C1 | **Document generat cu 10 zile înainte de fiecare termen de judecată**, prin care se cere judecarea în lipsă, cu recalcularea debitului la zi | Nu ține de calculul termenelor. Declanșatorul, adică termenul de judecată minus 10 zile, există deja în agendă, deci partea de urmărire e gata |

---

## D. Verificate, nu necesită lucru

| # | Ce | Rezultat |
|---|---|---|
| D1 | Adăugarea de facturi pe un dosar existent | **Nu e posibilă azi** în fluxul avocatului. Pozițiile se pot edita doar prin wizardul de dosar nou și prin panoul de administrare. Constrângerea semnalată de el se respectă deja structural |
| D2 | Regula de calcul pe zile libere | **Confirmată** de avocat, cu trei exemple numerice care coincid cu implementarea |
| D3 | Poarta care blochează depunerea prematură | **Confirmată** implicit: „începând cu 18 iunie se poate genera și transmite OP" |
| D4 | Data de judecată nepromovată la zi lucrătoare | **Confirmată**, cu observația lui că oricum nu are cum să apară într-o zi nelucrătoare |

---

## E. Rămas din runda anterioară

| # | Ce | Stare |
|---|---|---|
| E1 | Închiderea termenului de cerere în anulare, în forma discutată înainte de revizie, cu titular al căii de atac | Depășită de A6, care implementează varianta cerută de avocat. Modelul cu titular rămâne relevant doar dacă răspunsul la B5 e afirmativ |
| E2 | Cod mort: rutele calculate pentru motivele de blocaj, nefolosite de interfață | Curățenie, fără impact funcțional |
