# Răspuns la revizia avocatului pe fluxurile de termene

> Sursa: `TERMENE-PENTRU-VALIDARE-AVOCAT.docx`, adnotat cu albastru. 26 de fragmente extrase.
> Documentul recenzat: `TERMENE-PENTRU-VALIDARE-AVOCAT.md` (2026-07-29).
> Data răspunsului: 2026-08-05.
> Metodă: fiecare mențiune verificată față de codul de pe `lexrecovery` și, unde privește legea,
> la surse publice consultate în ziua răspunsului.
> Nimic nu s-a implementat încă. Acesta este un document de decizie.

---

## 0. Rezultatul, în trei rânduri

Regula de calcul pe zile libere este **confirmată**, cu exemplele lui numerice care coincid cu ale noastre. Asta validează cea mai riscantă modificare făcută și deblochează recalcularea termenelor existente.

Din cele 14 întrebări, 6 au primit da, 4 au primit nu sau o contrapropunere, 1 a rămas fără răspuns la ce am întrebat, iar 3 au deschis subiecte noi. Peste ele, 6 mențiuni nesolicitate, dintre care două schimbă fluxuri întregi.

O singură mențiune a lui pare greșită, și e o corecție de citare pe care ne-o propune.

---

## 1. Tabel sinoptic

| # | Unde | Mențiunea, pe scurt | Verdict | Acțiune |
|---|---|---|---|---|
| M1 | §1, Î1 | Zilele libere: „Da". Prima zi e 2 iunie, se împlinește 17 iunie, OP din 18 iunie | **Confirmă implementarea** | Recalculăm termenele existente |
| M2 | §2 | „706 CPC" în loc de art. 705 pentru prescripția executării | **Pare greșit** | Contrapropunere cu dovezi |
| M3 | §2 | La cele 6 luni: nu ești obligat să depui în 6 luni; OP se depune la minim 15 zile | **Corect, nuanțează textul** | Reformulăm celula |
| M4 | §2 | Document generat cu 10 zile înainte de termen: judecare în lipsă plus recalcularea debitului | **Scop nou, util** | Backlog separat |
| M5 | §3 | Executorul introduce data reală și încarcă scanul | **Confirmă fluxul** | Verificăm că există |
| M6 | §3, Î5 | Timbrăm oricum după ce avem număr de dosar, deci regularizarea nu contează | **Contrazice modelul actual** | Discuție, vezi §5 |
| M7 | §4, Î6 | Nu afișa termen estimat, afișează „în curs de comunicare" | **Corect, mai bun decât ce avem** | Implementăm |
| M8 | §4 | Alertă ca să introduci manual data comunicării ordonanței | **Corect, gol real** | Implementăm |
| M9 | §4 | Alertă pentru data regularizării și termenul acordat | **Corect, condiționat de M6** | Depinde de M6 |
| M10 | §4 | Dacă dosarul merge în executare, nu mai discutăm despre prescripție | **Parțial corect** | Nuanțăm |
| M11 | Î2 | Răspuns despre bifarea automată, nu despre momentul care oprește cele 6 luni | **Întrebarea rămâne deschisă** | Reîntrebăm, §11 |
| M12 | Î7 | Nu poți adăuga facturi noi într-un dosar creat | **Constrângere de produs** | Blocăm în aplicație |
| M13 | Î8 | Dacă se depune cerere în anulare și hotărârea se menține, cei 3 ani curg de la a doua hotărâre | **Corect, regulă lipsă** | Implementăm |
| M14 | Î9 | Închidere automată, apoi 5 zile lucrătoare până la executare. „Nu înțeleg treaba cu fereastra" | **Întrebarea noastră a fost prost pusă** | Reformulăm, §14 |
| M15 | Î10 | Prorogarea se aplică și prescripției: „Da" | **Schimbă comportamentul actual** | Implementăm |
| M16 | Î11 | Numerotarea: „1.014 și următoarele" | **Confirmat independent** | Corectăm, cu o rezervă |
| M17 | Î12 | Cum îl alertezi că are număr de dosar și trebuie să achite taxa? | **Gol real** | Implementăm |
| M18 | Î12 | Pe cererea în anulare, 30 și 14 zile nu se potrivesc la un termen de 10 zile | **Corect** | Renunțăm |
| M19 | Î13 | Text propus: „Prescripție întreruptă până la data Z" | **Bun, îl preluăm** | Implementăm |
| M20 | Î14 | „De ce să nu vrei să mai apară în listă?" | **Pune la îndoială premisa** | Renunțăm la buton |

---

## 2. M1. Regula de calcul: confirmată

**Ce a scris**: la 15 zile, „prima zi din termen este 2 iunie 2026, termenul se împlinește pe 17 iunie, începând cu 18 iunie se poate genera și transmite OP la instanță". La 10 zile, „prima zi 2 iunie, se împlinește pe 12 iunie". A adăugat și un exemplu propriu, cu un termen de 11 zile care s-ar împlini sâmbătă 13 iunie, se prorogă la luni 15 iunie, iar actul următor se poate emite din 16 iunie.

**Verificare**: modelul lui este „prima zi a termenului e ziua următoare comunicării, iar termenul se împlinește după N zile de la ea". Adică data comunicării plus N plus 1. Este exact formula implementată. Cele trei exemple ale lui coincid cu ce produce aplicația azi.

**Ce facem**: nimic la calcul, e corect. Se deblochează însă recalcularea termenelor create înainte de modificare, care sunt încă scurte cu o zi. Propun să o rulăm o singură dată, pe toate dosarele active, cu urmă în jurnalul de audit pe fiecare termen atins.

**Un beneficiu colateral**: fraza „începând cu 18 iunie se poate genera și transmite OP" confirmă și poarta care blochează depunerea prematură, nu doar calculul.

---

## 3. M2. „706 CPC": credem că nu

**Ce a scris**: lângă „Prescripția executării, 3 ani (CPC art. 705 alin. 1)" a notat „706 CPC".

**De ce credem că e o scăpare**: art. 705 se numește „Termenul de prescripție" și conține atât durata de 3 ani, cât și momentul de la care curge, iar pentru hotărâri judecătorești acela este rămânerea definitivă. Art. 706 se numește „Efectele împlinirii termenului de prescripție" și tratează stingerea dreptului și pierderea puterii executorii a titlului. Sunt două articole diferite, iar noi citam durata, deci art. 705.

**Bănuiala noastră despre cauză**: la întrebarea 11 ne-a confirmat că numerotarea în vigoare mută procedura ordonanței de plată de la 1013 la 1014. Probabil a aplicat aceeași deplasare cu o unitate și aici. Dar deplasarea nu este uniformă pe tot codul: sursele care arată ordonanța de plată începând la art. 1014 arată în același timp prescripția executării la art. 705. Adică articolul care a fost inserat la republicare se află între cartea executării silite și cartea procedurilor speciale, deci mișcă numai ce vine după el.

**Ce facem**: păstrăm art. 705 pentru durată, folosim art. 706 doar unde vorbim despre efectul împlinirii, adică în avertismentul de la închiderea termenului. Îi returnăm întrebarea, formulată strict: în ediția în care ordonanța de plată începe la art. 1014, prescripția executării silite este la art. 705 sau la art. 706?

---

## 4. M3. Cele 6 luni: nu e o obligație, e o condiție

**Ce a scris**: „Dacă nu introduci OP în cele 6 luni, practic nu-ți operează întreruperea prescripției și termenul general de 3 ani se calculează ca și cum somația nu ar fi existat, dar nu ești obligat să introduci în cele 6 luni OP." Și: „OP se depune în minim 15 zile de la data comunicării somației, dată regăsită pe dovada de comunicare."

**Verificare**: corect, și e o precizare care merită preluată în text. Cele 6 luni nu sunt un termen de decădere din dreptul de a depune, ci condiția de care depinde menținerea întreruperii. Poți depune și în luna a șaptea, doar că prescripția se socotește ca și cum somația nu ar fi întrerupt-o.

**Ce facem**: reformulăm celula din tabel. Azi scrie „somația nu mai întrerupe prescripția, deci creanța se poate prescrie". Devine: „poți depune și după, dar somația nu mai contează ca întrerupere, iar prescripția se socotește ca și cum nu ar fi existat". Ajustăm și textul din aplicație, ca termenul să nu fie prezentat ca o interdicție.

**Nu schimbăm**: prioritatea termenului rămâne critică. Efectul practic, pierderea creanței, e același.

---

## 5. M6. Timbrarea: modelul lui e altul decât al nostru

**Ce a scris**, în două locuri: „Timbrăm oricum după ce avem număr de dosar identificat de platformă, deci nu contează regularizarea." Și la întrebarea 5: „Nu, pentru că noi o să cerem utilizatorului să timbreze în Z+1 de când s-a stabilit termenul, unde Z este ziua în care platforma identifică numărul dosarului. De discutat oportunitatea propusă."

**Ce înseamnă**: în modelul lui, taxa se plătește imediat ce platforma descoperă numărul de dosar pe portal, fără să aștepte înștiințarea de regularizare de la instanță. Termenul de 10 zile din OUG 80/2013 art. 33 alin. 2 devine o plasă de siguranță pentru cazurile în care instanța totuși cere regularizarea, nu declanșatorul principal.

**Observația noastră**: cele două nu se exclud. Există dosare în care instanța nu emite nicio înștiințare, pentru că taxa a fost achitată la depunere, și dosare în care o emite. Modelul lui acoperă primul caz, al nostru pe al doilea.

**Ce propunem**: păstrăm ambele. Declanșatorul principal devine descoperirea numărului de dosar, cu un memento la Z+1, iar termenul legal de 10 zile rămâne și se activează doar dacă apare o înștiințare de regularizare. Aceasta e și legătura cu M9: dacă păstrăm ramura de regularizare, avem nevoie de alerta prin care el introduce data înștiințării și termenul acordat de instanță.

**De confirmat cu el**: dacă vrea să renunțe complet la ramura de regularizare, sau doar să nu mai fie ea declanșatorul.

---

## 6. M7. „În curs de comunicare" în loc de termen estimat

**Ce a scris**, în două locuri: „Păi și nu mai bine apare în curs de comunicare?" Și la întrebarea 6: „Nu cred, aș afișa în curs de comunicare și după ce completează executorul să fie afișat termenul de plată de 15 zile."

**Verificare**: are dreptate, iar motivul e mai bun decât al nostru. Noi afișam o dată estimată ca să existe ceva în agendă. Dar o dată calculată de la generarea documentului nu are nicio valoare juridică, iar marcajul „dată estimată" nu împiedică pe cineva grăbit să o citească drept termen.

**Ce facem**: rândul din agendă afișează starea „în curs de comunicare", fără dată, cu acțiunea „Introdu data comunicării". Termenul de 15 zile apare abia după completarea datei reale. Dosarul rămâne în lista de dosare blocate, deci nu dispare din vizor.

**Efect secundar bun**: dispare cea mai frecventă sursă de date estimate din aplicație.

---

## 7. M8, M9, M17. Trei alerte care lipsesc

Trei mențiuni diferite spun același lucru: acolo unde aplicația nu poate calcula un termen, ar trebui să ceară activ data care îi lipsește, nu doar să afișeze dosarul într-o listă.

- **M8**: „Ai putea să primești alertă să introduci manual data comunicării" (ordonanța emisă).
- **M9**: „Ideal ar fi bine să primești alertă să introduci manual data primirii regularizării de la instanță și termenul acordat de aceasta."
- **M17**: „Păi și cum îl alertezi, de exemplu, că are număr de dosar și trebuie să achite taxa?"

**Verificare**: toate trei sunt goluri reale. Azi zona de dosare blocate e pasivă: apare în pagină, dar nu trimite nimic. Alertele pe email există doar pentru termene deja calculate, adică exact pentru cazurile care nu sunt blocate.

**Ce facem**: extindem jobul zilnic de alerte ca să trimită și pentru dosare blocate, nu doar pentru termene. Trei declanșatoare noi: ordonanță emisă fără data comunicării, dosar descoperit pe portal fără taxă achitată, înștiințare de regularizare fără dată introdusă. Cu o limită de frecvență, ca să nu devină zgomot zilnic pe același dosar.

---

## 8. M10. Executarea și prescripția: parțial corect

**Ce a scris**: „Dacă dosarul merge în executare, nu mai discutăm despre prescripție."

**Verificare**: corect în esență, dar nu pentru tot intervalul. Prescripția dreptului de a cere executarea silită contează exact între rămânerea definitivă și momentul în care executarea chiar începe. Cererea de executare adresată executorului întrerupe acest termen. Deci după ce executarea a pornit, are dreptate, termenul nu mai are obiect. Cât timp dosarul e definitiv dar nimeni nu a pornit executarea, termenul e singurul lucru care mai atrage atenția că titlul se stinge.

**Ce facem**: păstrăm termenul, dar îl închidem automat când dosarul trece efectiv în executare, în loc să îl lăsăm deschis. Azi îl creăm la intrarea în executare, ceea ce e chiar invers decât ar trebui.

**De confirmat**: dacă închiderea se face la trecerea în starea de executare, sau abia la confirmarea că executorul a înregistrat cererea.

---

## 9. M12. Nu se pot adăuga facturi într-un dosar creat

**Ce a scris**: „Nu poți să adaugi facturi noi într-un dosar deja creat. Dacă trimiți somație cu 10 lei, OP nu poate să fie cu 15 lei."

**Verificare**: corect, și e mai important decât întrebarea la care răspunde. Somația comunicată fixează întinderea creanței pentru care s-a parcurs procedura prealabilă. O cerere de ordonanță pe o sumă mai mare decât cea somată nu este acoperită de somație pentru diferență.

**Ce facem**: întrebarea 7 devine fără obiect. Dar apare o verificare pe care nu o avem: aplicația ar trebui să împiedice adăugarea de poziții noi după generarea somației, sau cel puțin să avertizeze că depășirea sumei somate cere o somație nouă.

**De verificat în cod**: dacă adăugarea de poziții după generarea somației e posibilă azi. Dacă da, e un defect, nu o funcționalitate.

---

## 10. M13. Cererea în anulare mută punctul de plecare

**Ce a scris**: „Da, dar mai ai nevoie de o regulă în caz că se depune cerere în anulare. Pentru că dacă se depune cerere în anulare și noua hotărâre menține prima hotărâre, cei 3 ani se calculează de la comunicarea celei de-a doua hotărâri."

**Verificare**: corect. Ordonanța nu rămâne definitivă la expirarea termenului de 10 zile dacă în interiorul lui s-a depus cerere în anulare. Definitivarea se produce la soluționarea acesteia, iar cei 3 ani curg de la comunicarea hotărârii date pe cererea în anulare.

**Ce facem**: regula actuală, „prima zi după expirarea termenului de cerere în anulare", devine cazul implicit. Adăugăm ramura: dacă s-a depus cerere în anulare, ancora se mută pe data comunicării hotărârii pronunțate asupra ei, dată pe care trebuie să o introducă el. Până o introduce, dosarul intră în lista de blocaje, la fel ca celelalte trei situații.

**Notă**: el adaugă și că executarea poate începe mai devreme, cu asumarea riscului. Corect, dar nu schimbă calculul prescripției, cum spune și el.

---

## 11. M11. Întrebarea despre cele 6 luni a rămas fără răspuns

**Ce am întrebat**: care moment oprește curgerea celor 6 luni, depunerea cererii la instanță sau prima zi de judecată.

**Ce a răspuns**: „Am stabilit că odată cu completarea datei comunicării somației de plată, informația din tabul Termene se bifează automat, ceea ce înseamnă că prescripția este întreruptă și nu se mai calculează termenul de prescripție. Art. 2540 stabilește modalitatea de calcul, termenul rămâne întrerupt timp de 6 luni, perioadă în care pentru a beneficia de întrerupere trebuie să introduci OP."

**Analiză**: a descris mecanica de afișare și a confirmat regula generală, dar nu a ales între cele două momente. Diferența e practică: dacă momentul e depunerea, termenul se închide când el marchează cererea ca depusă. Dacă e prima zi de judecată, se închide mai târziu, iar între cele două există un interval în care afișăm greșit.

**Ce facem**: implementăm pe varianta depunerii, care e cea uzuală, și îi returnăm întrebarea reformulată, cu efectul practic explicitat, ca să poată răspunde în trei cuvinte.

---

## 12. M15. Prorogarea se aplică și prescripției

**Ce a scris**: „Da", la întrebarea dacă aplicăm prorogarea la prima zi lucrătoare și celor două termene de prescripție.

**Verificare**: schimbă comportamentul actual. Azi nu prorogăm niciunul dintre cele două termene de prescripție, cu motivarea că sunt de drept substanțial, iar afișarea datei brute e conservatoare.

**Ce facem**: aplicăm prorogarea. Efectul e că unele date de prescripție se mută cu una sau două zile mai târziu.

**Rezerva pe care i-o semnalăm**: la termenele procedurale, prorogarea are un temei explicit. La prescripție, temeiul e mai puțin ferm, iar direcția erorii e cea nefavorabilă: o dată afișată mai târziu decât cea reală liniștește fără temei. Îi cerem confirmarea că acceptă acest compromis, având în vedere că e singurul loc unde ne îndepărtăm de regula pe care am adoptat-o peste tot, adică mai bine o alertă prea devreme.

---

## 13. M16. Numerotarea: confirmată, cu o rezervă

**Ce a scris**: „1.014 și următoarele".

**Verificare independentă**: confirmat. După republicare, art. 1013 a devenit art. 1014, iar articolul care poartă azi numărul 1013 tratează cu totul altceva, măsurile provizorii în materia drepturilor de proprietate intelectuală. Sursele care folosesc numerotarea nouă arată constant domeniul de aplicare al ordonanței de plată la art. 1014.

**Ce facem**: corectăm 1013 în 1014 peste tot. Nu e o operație de două linii: numerotarea apare în traduceri, în validatori, în patru șabloane de documente și în vreo zece fișiere de cod, iar patru dintre apariții sunt text tipărit pe somația trimisă debitorului și pe cererea depusă la instanță.

**Rezerva, și de aceea nu pornim încă**: deplasarea nu e uniformă. Trebuie stabilit, articol cu articol, care dintre citările noastre se mută. Ne interesează în special art. 1015, comunicarea somației, art. 1016, cuprinsul cererii, și art. 1024, cererea în anulare. Îi trimitem lista completă a articolelor pe care le citează aplicația și îi cerem să confirme numărul corect pentru fiecare, o singură dată, ca să nu revenim.

---

## 14. M14. Întrebarea noastră despre cererea în anulare a fost prost pusă

**Ce a scris**: „Pentru utilizatorul nostru ar trebui să se închidă automat, calculat de la data pe care el o indică ca fiind data de comunicare. Ulterior ar trebui să mai așteptăm 5 zile lucrătoare să vedem dacă se înregistrează cerere în anulare de debitor și apoi să mergem cu dosarul către executare." Și, explicit: „Nu înțeleg treaba cu partea și cu fereastra."

**Ce ne spune asta**: întrebarea noastră era prea abstractă. Vorbeam despre două ferestre paralele și despre titularul căii de atac, fără să spunem ce se schimbă practic.

**Ce reținem din răspuns**: termenul se închide automat la expirarea celor 10 zile calculate de la data comunicării pe care o introduce el. După aceea, o perioadă de așteptare de 5 zile lucrătoare înainte de a împinge dosarul spre executare, ca să se vadă dacă debitorul a depus cerere în anulare.

**Ce facem**: implementăm închiderea automată și perioada de așteptare. Renunțăm la modelul cu două ferestre, care oricum nu era derivabil din datele pe care le avem. Îi returnăm o întrebare mult mai simplă: dacă el însuși vrea să atace ordonanța pe partea respinsă, are nevoie ca aplicația să îi urmărească propriul termen, sau se ocupă singur?

**De verificat**: cele 5 zile lucrătoare sunt o marjă practică, nu un termen legal. Le tratăm ca atare, configurabile, nu ca pe o regulă.

---

## 15. M18, M19, M20. Alerte, text, buton

**M18, ritmul alertelor.** A scris: „Pe cererea în anulare nu se potrivesc 30 și 14 zile pentru că termenul de introducere e 10 zile." Corect, propunerea noastră era absurdă aritmetic. Renunțăm la alertele suplimentare pe cererea în anulare. Pe prescripție le păstrăm, dar a cerut o precizare: dacă ne referim la cea de 6 luni sau la cea generală. Răspunsul nostru: la ambele, cu praguri diferite, 30 și 14 zile pentru cea generală de 3 ani, iar pentru cele 6 luni un prag la 60 și unul la 30 de zile, pentru că fereastra e mai scurtă.

**M19, textul de pe prescripția întreruptă.** A propus: „Prescripție întreruptă până la data Z, unde Z este data comunicării somației plus 6 luni." Îl preluăm ca atare. E mai bun decât ce aveam, pentru că înlocuiește un marcaj de incertitudine cu o dată concretă și acționabilă.

**M20, butonul.** A întrebat: „De ce să nu vrei să mai apară în listă?" Întrebare corectă, la care nu avem un răspuns bun. Butonul exista ca să poți scoate din agendă un termen pe care îl consideri gestionat. Dar dacă textul de la M19 afișează o dată reală, termenul nu mai deranjează, deci butonul nu mai are rost. **Renunțăm la el.** Cade și întrebarea 14, și eticheta pe care i-o ceream.

---

## 16. M4 și M5. Două mențiuni care ies din scopul termenelor

**M4, judecarea în lipsă.** A scris: „Chiar dacă judecarea în lipsă se cere prin OP, vom genera un document cu 10 zile înainte de termenul stabilit prin care cerem instanței să judece în lipsă și recalculăm debitul asupra căruia trebuie să se pronunțe."

Sunt două lucruri: un document nou, generat automat înainte de fiecare termen de judecată, și recalcularea debitului la zi. Amândouă sunt utile, dar niciuna nu ține de calculul termenelor. Le trecem în backlog ca scop separat, cu observația că declanșatorul, adică termenul de judecată minus 10 zile, există deja în agendă, deci partea de urmărire e gata.

**M5, executorul.** A scris: „Executorul va introduce data reală a comunicării și va încărca scan pe dosar." Confirmă fluxul pe care îl presupuneam. Verificăm în aplicație dacă încărcarea dovezii de comunicare e legată de câmpul de dată sau sunt două acțiuni independente. Dacă sunt independente, le legăm, pentru că data fără dovadă nu are valoare la dosar.

---

## 17. Ce se schimbă, pe scurt

**Implementăm fără alte întrebări:**
1. Recalcularea termenelor existente pe regula confirmată.
2. „În curs de comunicare" în loc de termen estimat.
3. Alerte pentru dosarele blocate, trei declanșatoare noi.
4. Închiderea prescripției executării la trecerea efectivă în executare.
5. Ancora prescripției executării mutată pe hotărârea dată în cererea în anulare, când există.
6. Închiderea automată a termenului de cerere în anulare plus perioada de așteptare.
7. Prorogarea aplicată celor două termene de prescripție.
8. Textul „Prescripție întreruptă până la data Z".
9. Eliminarea butonului de ascundere din listă.
10. Renunțarea la alertele de 30 și 14 zile pe cererea în anulare.
11. Reformularea textului despre cele 6 luni.

**Implementăm după o verificare în cod:**
12. Blocarea adăugării de poziții după generarea somației.
13. Legarea dovezii de comunicare de câmpul de dată.

**Așteaptă răspunsul lui:**
14. Numerotarea completă a articolelor.
15. Modelul de timbrare: renunțăm la ramura de regularizare sau doar la declanșator.
16. Momentul care oprește cele 6 luni.
17. Prescripția executării: art. 705 sau 706.
18. Dacă vrea urmărirea propriului termen de cerere în anulare.
19. Dacă acceptă prorogarea la prescripție, cu direcția de eroare nefavorabilă.

**Backlog separat:**
20. Documentul de judecare în lipsă cu recalcularea debitului.

---

## 18. Întrebări de returnat

Pentru a doua rundă, formulate strict, ca să poată răspunde scurt.

1. **Numerotarea.** Îți trimitem lista articolelor pe care aplicația le citează. Confirmă numărul corect pentru fiecare, în ediția în vigoare. Ne interesează mai ales comunicarea somației, cuprinsul cererii și cererea în anulare.
2. **Prescripția executării.** În aceeași ediție în care ordonanța de plată începe la art. 1014, durata de 3 ani și momentul de la care curge sunt la art. 705 sau la art. 706?
3. **Cele 6 luni.** Termenul se oprește când depui cererea la instanță, sau la prima zi de judecată? Practic: închidem termenul când marchezi cererea ca depusă, sau mai târziu?
4. **Timbrarea.** Renunțăm complet la ramura de regularizare, sau o păstrăm ca plasă de siguranță pentru instanțele care totuși emit înștiințare?
5. **Cererea în anulare, calea ta.** Când ordonanța e admisă în parte și vrei să ataci partea respinsă, ai nevoie ca aplicația să îți urmărească propriul termen de 10 zile, sau te ocupi singur?
6. **Prorogarea prescripției.** Ai confirmat că o aplicăm. Semnalăm că e singurul loc unde data afișată devine mai târzie decât cea brută, deci mai puțin conservatoare. O păstrăm așa?
7. **Executarea.** Termenul de prescripție a executării se închide când marchezi dosarul ca trecut în executare, sau abia când confirmi că executorul a înregistrat cererea?

---

<sub>Verificări făcute în ziua răspunsului pe surse publice consolidate, pentru numerotarea articolelor din procedura ordonanței de plată și pentru distincția dintre art. 705 și art. 706. Restul mențiunilor au fost verificate față de codul de pe branch-ul `lexrecovery`.</sub>
