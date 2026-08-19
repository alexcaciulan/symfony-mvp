# Runda a doua de revizie pe termene: ce a răspuns avocatul

> Sursa: `TERMENE-PENTRU-VALIDARE-AVOCAT-2.docx`, adnotat cu albastru.
> Documentul recenzat: `TERMENE-DUPA-REVIZIE-2026-08-06.md`.
> Data: 2026-08-13.
> Din cele 50 de fragmente albastre, 26 sunt cele din runda anterioară, deja tratate.
> Aici sunt centralizate cele 24 noi.
> Nimic nu s-a implementat încă.

---

## 0. Rezultatul, pe scurt

**Toată partea de schimbări e aprobată.** Nouă secțiuni, nouă „OK", fără nicio obiecție. Deci cele 11 puncte implementate rămân cum sunt.

Din cele 10 întrebări: **3 confirmă ce am făcut**, **4 cer modificări**, **2 rămân nerezolvate**, iar **una deschide un flux nou, care nu există în aplicație**.

Cel mai important lucru din runda asta nu e un răspuns, ci un răspuns care lipsește: numerotarea articolelor a rămas nerezolvată a doua oară, iar răspunsurile lui se contrazic în continuare.

---

## 1. Tabel sinoptic

| # | Întrebarea | Ce a răspuns | Verdict | Acțiune |
|---|---|---|---|---|
| Î1 | Dovada comunicării | Merg împreună, data plus dovada. Le încarcă **executorul**, printr-un link primit pe email | **Deschide scop nou** | Vezi §3, nu e o simplă bifă |
| Î2 | Numerotarea articolelor | 1014, 1015, **?**, 1024 | **Nerezolvat, a doua oară** | Schimbăm modul de a întreba, §8 |
| Î3 | Prescripția executării: 705 sau 706 | **706** | **Contrazice verificarea noastră** | Trebuie tranșat, §9 |
| Î4 | Timbrarea, ramura de regularizare | Poate fi scoasă complet, dar rămâne utilă pentru evidență | **Nuanțat** | Scoatem declanșatorul, păstrăm pasul |
| Î5 | Momentul care oprește cele 6 luni | **Depunerea cererii la instanță** | **Confirmă ce am făcut** | Niciuna |
| Î6 | Propriul termen de cerere în anulare | Simplificăm: îl întrebăm doar dacă depune sau nu | **Schimbă abordarea** | Implementăm varianta simplă |
| Î7 | Prelungirea prescripției | OK | **Confirmă** | Niciuna |
| Î8 | Închiderea prescripției executării | Cel mai curat: **numărul de înregistrare de la executor** | **Schimbă ce am implementat** | Vezi §6 |
| Î9 | Pronunțare sau comunicare | **Comunicare**, „termenele se calculează mereu de la comunicare" | **Confirmă ce am făcut** | Niciuna |
| Î10 | Bifa din ecranul dosarului | „Cred că trebuie scoasă" | **Confirmă propunerea** | O scoatem |
| M1 | Nesolicitat | Textele somației și ale cererii se vor stabili separat, iar extragerea automată trebuie să ia mai multe date | **Scop nou** | Backlog |

---

## 2. Partea de schimbări: aprobată integral

Nouă „OK", câte unul pe fiecare secțiune: recalcularea prudentă a termenelor vechi, starea „în curs de comunicare", alertele pentru dosare blocate, prescripția executării, închiderea automată a cererii în anulare, textele, alertele pe termene, eliminarea butonului, și cele două defecte preexistente reparate.

Nu e nimic de făcut aici. Merită notat că a aprobat inclusiv deciziile unde am mers mai departe decât propusese el, cum e renunțarea completă la termenul estimat.

---

## 3. Î1. Dovada comunicării: răspunsul deschide un flux întreg

**Ce a scris**: „Cred că trebuie să meargă împreună. În dosarul de OP avem nevoie de dovada comunicării. Dacă ea a fost făcută, executorul întocmește un document. Deci data plus dovada. Dovada va fi încărcată de executor prin link-ul generat pe email odată cu externalizarea dosarului. Tot executorul menționează și data comunicării în modal."

Și, separat: „Dacă clientul nostru, avocat, alege să nu transmită somația prin LexRecovery, adică serviciul de executare în platformă, atunci funcționalitatea de încărcare a dovezii și menționarea datei comunicării rămân în sarcina lui."

**Ce înseamnă**: a răspuns la întrebarea pusă, data și dovada merg împreună, dar a răspuns dintr-un model de produs pe care aplicația nu îl are. În modelul lui:

1. dosarul se „externalizează" către un executor judecătoresc;
2. executorul primește un link pe email;
3. **executorul**, nu avocatul, completează data comunicării și încarcă dovada;
4. dacă avocatul nu folosește serviciul, face el ambele.

**Verificat în cod**: nu există niciun flux de externalizare, niciun acces pentru un terț, niciun link cu durată limitată. „Executor" apare azi doar ca una dintre modalitățile de comunicare pe care le poate bifa avocatul.

**Ce propunem**: separăm întrebarea de flux.

- **Acum**, pe fluxul existent: data și dovada devin obligatorii împreună, completate de avocat, în același dialog. Asta e răspunsul lui aplicat la ce avem.
- **Separat**, ca scop nou: accesul executorului prin link. Cere autentificare pentru un terț, o durată de valabilitate, un domeniu de acces limitat la un singur dosar, și o decizie despre datele cu caracter personal pe care le vede. Nu e o extindere de ecran, e o suprafață nouă.

---

## 4. Î4. Timbrarea: scoatem declanșatorul, păstrăm pasul

**Ce a scris**: dacă platforma identifică dosarul la Z+1 de la încărcarea pe portal, instanța nu are cum să trimită atât de repede regularizarea, deci obligația se îndeplinește înainte să ajungă comunicarea, iar etapa „poate fi scoasă complet". Dar adaugă: prin regularizare instanța poate pune în vedere și alte lucruri, care nu se pot estima și automatiza, deci etapa „poate fi utilă avocatului, pentru evidență".

**Cum îl citim**: a răspuns la două întrebări diferite deodată. Ca **declanșator al timbrării**, regularizarea iese. Ca **eveniment de înregistrat pe dosar**, rămâne utilă, pentru că instanța poate cere și altceva decât taxa.

**Ce propunem**: scoatem regularizarea din lanțul care produce termenul de timbrare. Timbrarea se declanșează la descoperirea numărului de dosar. Păstrăm posibilitatea de a înregistra manual o înștiințare de regularizare, ca eveniment cu dată și text liber, fără să mai genereze termenul de 10 zile.

---

## 5. Î6. Cererea în anulare proprie: varianta simplă

**Ce a scris**: „Cred că putem simplifica cumva în sensul de a întreba avocatul dacă depune sau nu cerere în anulare. Oricum ar fi, platforma nu îi mai poate emite un draft de document în acel moment, pentru că nu discutăm încă de analiza hotărârilor emise de instanță și motive de cerere în anulare, deci e pe cont propriu."

**Ce înseamnă**: renunțăm la ideea de a distinge titularul căii de atac și de a urmări două ferestre paralele. În schimb, o singură întrebare pe dosar: depui cerere în anulare, da sau nu. Dacă da, urmărim termenul; dacă nu, nu.

**Ce propunem**: exact asta. E mai simplu decât ce aveam în plan și nu cere câmp de titular.

---

## 6. Î8. Închiderea prescripției executării se mută

**Ce a scris**: „Practica este că avocatul generează cererea de începere a executării silite și executorul o înregistrează de îndată. Cel mai clean ar fi când avem număr de înregistrare de la executor."

**Ce schimbă**: azi închidem termenul pe data depunerii, declarată de avocat. El preferă ancorarea pe **numărul de înregistrare primit de la executor**, adică pe o confirmare venită din afară, nu pe o declarație.

**Ce propunem**: câmpul de dată rămâne, dar i se adaugă numărul de înregistrare, iar închiderea termenului se face la completarea lui. Până atunci, dosarul rămâne cu termenul deschis și apare în lista celor care așteaptă ceva. Asta e coerent și cu răspunsul de la Î1: preferă fapte confirmate de terți, nu declarații.

---

## 7. Î5, Î7, Î9, Î10: confirmări

**Î5, cele 6 luni.** „Depunerea cererii la instanță." Exact ce am implementat. Întrebarea a rămas fără răspuns o rundă, acum e închisă.

**Î7, prelungirea prescripției.** „OK", inclusiv după ce i-am semnalat că e singurul loc unde eroarea merge în direcția nefavorabilă. Rămâne cum e.

**Î9, pronunțare sau comunicare.** „Totul se raportează la comunicare. Desigur, avocatul poate începe executarea pe baza hotărârii din OP plus certificat de grefă din dosarul de cerere în anulare, deci în lipsa hotărârii din cererea în anulare, dar termenele se calculează mereu de la comunicare." Confirmă ce am implementat, și adaugă o precizare practică utilă: executarea poate porni fără a doua hotărâre, dar termenul tot de la comunicarea ei curge.

**Î10, bifa din ecranul dosarului.** „Cred că trebuie scoasă." O scoatem, pe prescripții.

---

## 8. Î2. Numerotarea: nerezolvată a doua oară

**Ce a răspuns**: 1013 devine **1014**. 1015 rămâne **1015**. La 1016 a pus **semnul întrebării**. 1024 rămâne **1024**.

**Problema**: răspunsurile se contrazic între ele, la fel ca în prima rundă. Dacă titlul procedurii începe acum la 1014 în loc de 1013, atunci s-a deplasat tot, iar intervalul devine 1014-1025: comunicarea somației la 1016, cuprinsul cererii la 1017, cererea în anulare la 1025. Nu se poate ca primul articol să se mute și restul să rămână pe loc.

**De ce credem că e vina noastră**: i-am cerut de două ori să completeze o coloană cu numere, rupte de context. Un avocat nu ține minte numerotarea articol cu articol; o recunoaște când vede textul.

**Ce propunem**: schimbăm complet forma întrebării. Nu îi mai cerem numere. Îi dăm **cele patru fraze exact cum se tipăresc azi** pe somație și pe cerere, și îl rugăm să le rescrie corect, în cuvintele lui. Ce corectează el, aia punem.

Adăugăm și un context care lipsea: la a doua întrebare a notat că „urmează să stabilim textele Somației și OP separat de ceea ce este deja integrat". Deci textele oricum se vor reface. Atunci merită întrebat direct dacă are rost să corectăm numerotarea acum, sau așteptăm rescrierea completă a documentelor.

**Până atunci nu schimbăm nimic.** Textele astea se tipăresc pe somația care ajunge la debitor și pe cererea depusă la judecător.

---

## 9. Î3. Prescripția executării: 705 sau 706, a doua oară

**Ce a răspuns**: „706", deși i-am arătat raționamentul nostru și i-am spus de ce bănuim că e o scăpare.

**Situația**: a doua oară când spune 706. Verificarea noastră arată art. 705 „Termenul de prescripție", cu durata și momentul de plecare, și art. 706 „Efectele împlinirii termenului", cu stingerea dreptului și pierderea puterii executorii.

**Ipoteza care le împacă**: dacă în ediția pe care o folosește el numerotarea din cartea executării silite e deplasată cu o unitate, la fel ca la ordonanța de plată, atunci ce numim noi 705 el numește 706, și amândoi avem dreptate în propria ediție. Asta ar explica și de ce a răspuns 1014 la prima linie.

**Ce propunem**: nu îi mai cerem un număr. Îi cerem **titlul articolului**. Întrebarea devine: „articolul care spune că dreptul de a obține executarea silită se prescrie în 3 ani se numește «Termenul de prescripție» sau «Efectele împlinirii termenului»?" La asta răspunde din memorie, fără să deschidă codul, iar răspunsul ne spune sigur despre ce ediție vorbește.

Aceeași metodă rezolvă și numerotarea de la §8.

---

## 10. M1. Un scop nou, semnalat în treacăt

**Ce a scris**, lângă întrebarea despre numerotare: „Urmează să stabilim textele Somației și OP separat de ceea ce este deja integrat. În acest context, AI-ul platformei va trebui instruit să extragă datele puțin mai complet."

Două lucruri, ambele mari, niciunul legat de termene: rescrierea textelor celor două documente generate, și extinderea a ceea ce extrage automat aplicația din documentele încărcate. Le trecem în backlog ca scop separat.

---

## 11. Ce se schimbă

**Implementăm fără alte întrebări:**
1. Data comunicării și dovada devin obligatorii împreună, pe fluxul existent, completate de avocat.
2. Regularizarea iese din lanțul care produce termenul de timbrare; rămâne ca eveniment de înregistrat manual.
3. Cererea în anulare proprie: o singură întrebare pe dosar, depui sau nu.
4. Închiderea prescripției executării se mută pe numărul de înregistrare de la executor.
5. Bifa de finalizare dispare de pe prescripții și din ecranul dosarului.

**Blocate, până răspunde:**
6. Numerotarea articolelor, cu întrebarea reformulată.
7. Prescripția executării, 705 sau 706, cu întrebarea reformulată.

**Backlog, scop nou:**
8. Accesul executorului prin link, cu externalizarea dosarului.
9. Rescrierea textelor somației și ale cererii, plus extinderea extragerii automate.
10. Documentul de judecare în lipsă cu recalcularea debitului, rămas din runda anterioară.
