# Termene: ce am schimbat după observațiile tale, și ce mai am nevoie de la tine

> Am parcurs toate cele 26 de observații din documentul întors. Am implementat ce se putea implementa,
> am corectat două lucruri care erau greșite dinainte, iar la final au rămas 10 întrebări.
>
> Prima parte se citește ca o confirmare: recunoști fiecare punct din ce ai scris tu.
> A doua parte cere răspunsuri, toate scurte.

---

## Partea I. Ce am schimbat

### Calculul termenelor rămâne cum l-ai confirmat

Ai răspuns că pe o somație comunicată luni, 1 iunie 2026, debitorul mai poate plăti valabil miercuri, 17 iunie. Asta era și modul în care calculează aplicația, deci nu am schimbat nimic la formulă.

Am adăugat în schimb o operație care corectează termenele mai vechi din aplicație, calculate înainte, pe alt mod de numărare. Este prudentă: mută un termen doar dacă poate dovedi că data lui a fost produsă de vechiul calcul. Dacă data a fost pusă de mână, o lasă neatinsă și o raportează separat.

### Somația în curs de comunicare nu mai afișează o dată

Ai scris „păi și nu mai bine apare în curs de comunicare?". Aveai dreptate, iar schimbarea a mers mai departe decât propusesem eu.

Aplicația nu mai calculează deloc un termen la generarea somației. Dosarul apare doar în lista de dosare care așteaptă ceva, cu mențiunea „în curs de comunicare" și cu acțiunea de a introduce data reală. Termenul de 15 zile se naște abia atunci. Așa dispare complet posibilitatea ca cineva să citească o dată estimativă drept termen.

### Aplicația cere activ datele care îi lipsesc

Ai cerut alerte care să îți spună să introduci data comunicării, data regularizării, și mai ales să te anunțe că ai număr de dosar și trebuie achitată taxa.

Verificând, am găsit ceva ce nu știam: pentru ordonanțele fără dată de comunicare aplicația **îți trimitea deja un email, în fiecare zi**, fără să se oprească vreodată. Nu lipsea alerta, lipsea bunul simț al ei. Am limitat-o, și am adăugat celelalte două situații, cu aceeași limitare.

La alerta despre regularizare am formulat-o condiționat: aplicația nu are cum să știe că instanța ți-a trimis o înștiințare, singura ei dovadă ar fi că o introduci tu. Deci textul spune „dacă ai primit înștiințarea, introdu data".

### Prescripția executării

Ai spus că dacă dosarul merge în executare nu mai discutăm despre prescripție. Ai dreptate, dar contează momentul exact.

Termenul se închide acum când depui cererea la executor, nu când marchezi dosarul ca trecut în executare. Data ți se cere în momentul acela. Închiderea se poate anula, pentru situațiile în care executarea se perimă și termenul redevine relevant.

Tot aici, ai semnalat că dacă se depune cerere în anulare și hotărârea se menține, cei 3 ani curg de la comunicarea celei de-a doua hotărâri. Am adăugat regula. Până când introduci acea dată, dosarul apare în lista celor care așteaptă ceva.

### Cererea în anulare se închide singură

Ai scris că termenul ar trebui să se închidă automat, calculat de la data comunicării pe care o indici tu. Se închide acum singur, la expirarea celor 10 zile.

Nu am adăugat trecerea automată spre executare după cele 5 zile lucrătoare de așteptare, pentru două motive: perioada aceea exista deja în aplicație, iar intrarea în executare presupune o decizie și o cheltuială, deci rămâne a ta.

### Prescripția se prelungește la prima zi lucrătoare

Ai răspuns „da". Am aplicat-o. Vezi însă întrebarea 7 de mai jos, pentru că e singurul loc unde consecința merge într-o direcție care nu îmi place.

### Textele

Textul de pe prescripția întreruptă e cel propus de tine: „Prescripție întreruptă până la data Z". Are două variante, pentru că după depunerea cererii data aceea nu mai spune nimic; atunci textul zice că întreruperea s-a consolidat.

Textul despre cele 6 luni nu mai sugerează că ești obligat să depui în acel interval, pentru că mi-ai explicat că nu ești. Spune acum că poți depune și după, dar că somația nu mai contează ca întrerupere.

### Alertele pe termene

Ai observat că 30 și 14 zile nu se potrivesc la un termen de 10 zile. Aveai dreptate, era o propunere absurdă. Alertele lungi rămân doar acolo unde au sens: la prescripția generală, și la termenul de 6 luni, cu praguri potrivite lungimii lui.

### Butonul de ascundere a dispărut

Ai întrebat „de ce să nu vrei să mai apară în listă?". Nu am avut un răspuns bun, așa că l-am scos.

### Două lucruri erau stricate dinainte

Le-am găsit verificând schimbările de mai sus, nu erau în observațiile tale.

Primul: după ce salvai data comunicării, ecranul continua să scrie că somația e în curs de comunicare, deși termenul de 15 zile apărea chiar dedesubt. Se corecta abia la reîncărcarea paginii.

Al doilea, mai important: alerta care îți cerea să atașezi dovada comunicării **nu s-a afișat niciodată, pe niciun dosar**, din cauza unei condiții greșite. Am reparat-o.

---

## Partea II. Cele 10 întrebări rămase

### 1. Dovada comunicării

Azi poți salva data comunicării fără să atașezi nimic. Ce preferi?

**a)** Dovada devine obligatorie: fără scanul dovezii de comunicare nu poți salva data, deci termenele nu se calculează.
**b)** Rămâne opțională, oferită în același dialog, iar dosarul apare marcat vizibil „dată introdusă fără dovadă".

Varianta a e mai riguroasă. Varianta b nu te blochează când ai data de la executor, dar scanul vine peste două zile.

### 2. Numerotarea articolelor

Mi-ai răspuns „1.014 și următoarele", și am confirmat că după republicare art. 1013 a devenit 1014. Dar nu toate articolele s-au mutat, deci nu pot aplica o regulă. Confirmă-mi, te rog, numărul corect pentru fiecare, așa cum le folosim:

| Îl folosim pentru | Îl citam ca | Corect e |
|---|---|---|
| Creanța trebuie să fie exigibilă, și temeiul general invocat în somație | art. 1013 | |
| Comunicarea somației și termenul de 15 zile | art. 1015 | |
| Cuprinsul cererii și lista anexelor, litera f | art. 1016 | |
| Cererea în anulare, termenul de 10 zile, cauțiunea | art. 1024 | |

Primul și al treilea apar tipărite pe somația care ajunge la debitor și pe cererea depusă la instanță, de aceea nu schimb nimic până nu confirmi.

### 3. Prescripția executării: care articol

Pentru cei 3 ani și pentru momentul de la care curg, noi citam art. 705. Tu ai notat 706.

Din ce am verificat, art. 705 se numește „Termenul de prescripție" și conține atât durata, cât și momentul de plecare, iar art. 706 se numește „Efectele împlinirii termenului". Bănuiesc că ai aplicat aceeași deplasare cu o unitate ca la întrebarea 2, dar acolo deplasarea nu se propagă.

În ediția în care ordonanța de plată începe la art. 1014, durata de 3 ani este la art. 705 sau la art. 706?

### 4. Timbrarea

Ai spus că timbrați oricum după ce apare numărul de dosar, deci regularizarea nu contează.

Păstrez și ramura de regularizare, ca plasă de siguranță pentru instanțele care totuși trimit înștiințare, sau o scot complet?

### 5. Cele 6 luni

Întrebarea din documentul anterior a rămas fără răspuns direct.

Ce moment oprește curgerea celor 6 luni: **depunerea cererii la instanță**, sau **prima zi de judecată**?

Practic: închid termenul când marchezi cererea ca depusă, sau mai târziu? Momentan am implementat pe prima variantă.

### 6. Propriul tău termen de cerere în anulare

Când ordonanța e admisă în parte și vrei să ataci partea respinsă, ai și tu un termen de 10 zile.

Vrei ca aplicația să ți-l urmărească separat, sau te ocupi singur?

Întreb pentru că azi aplicația nu poate distinge cine a formulat cererea, iar dacă îl urmărim, va trebui să îți cerem asta la înregistrare.

### 7. Prelungirea prescripției la prima zi lucrătoare

Ai confirmat că o aplicăm, și am aplicat-o. Îți semnalez totuși o consecință.

Peste tot în aplicație am ales ca eroarea să meargă în direcția sigură: mai bine o alertă prea devreme decât un termen care pare mai lung decât e. Prelungirea la prescripție face exact invers, împinge data cu una sau două zile mai târziu.

O păstrăm așa?

### 8. Închiderea prescripției executării

Termenul se închide când marchezi dosarul ca trecut în executare, sau abia când confirmi că executorul a înregistrat cererea?

Momentan închid pe data depunerii pe care o declari tu, iar închiderea rămâne reversibilă.

### 9. Hotărârea din cererea în anulare: pronunțare sau comunicare

Mi-ai spus că, dacă s-a formulat cerere în anulare, cei 3 ani ai prescripției executării curg de la comunicarea celei de-a doua hotărâri. Am implementat așa, dar mi-a rămas o nelămurire pe care prefer să o ridic acum decât peste trei ani.

Legea leagă cei 3 ani de rămânerea definitivă a hotărârii. Iar hotărârea dată pe cererea în anulare este definitivă din chiar momentul pronunțării, nu poate fi atacată cu apel. Aplicația ta spune asta chiar ea, în notificarea pe care o primești când cererea în anulare e respinsă.

Dacă e definitivă la pronunțare, atunci cele două date nu coincid, iar între ele pot trece săptămâni.

**Cei 3 ani curg de la pronunțarea hotărârii sau de la comunicarea ei?**

### 10. O întrebare mică, de coerență

Am scos butonul care ascundea prescripțiile din agendă, pentru că ai întrebat de ce ai vrea asta.

În ecranul dosarului însă a rămas o bifă prin care poți marca orice termen ca finalizat, inclusiv o prescripție. E un mecanism mai vechi, folosit de toate tipurile de termene, de aceea nu l-am atins.

**Pe prescripții, bifa aceea mai are sens, sau o scot și de acolo?**

---

## Formular de răspuns

| Întrebarea | Răspunsul tău |
|---|---|
| 1. Dovada comunicării | |
| 2. Numerotarea (cele 4 articole) | |
| 3. Prescripția executării: 705 sau 706 | |
| 4. Timbrarea | |
| 5. Cele 6 luni | |
| 6. Propriul termen de cerere în anulare | |
| 7. Prelungirea prescripției | |
| 8. Închiderea prescripției executării | |
| 9. Pronunțare sau comunicare | |
| 10. Bifa din ecranul dosarului | |

---

## Ce nu am făcut încă

Ai propus un document generat cu 10 zile înainte de fiecare termen de judecată, prin care să cerem judecarea în lipsă și să recalculăm debitul la zi. E o funcționalitate nouă, nu ține de calculul termenelor, așa că am pus-o separat. Momentul din care se declanșează există deja în aplicație, deci partea de urmărire e gata.
