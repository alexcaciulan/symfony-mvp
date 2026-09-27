# Decizie: mențiunile avocatului V2 (2026-08-19)

Document pentru OK înainte de implementare. Nimic nu s-a modificat în cod. Toate constatările sunt verificate pe codul de pe branch-ul curent.

---

## 1. Verdictul pe scurt

36 de mențiuni ale avocatului (A1-A5, B1-B5, C1-C5, D1-D7, E1-E10, F1-F4):

| Categorie | Număr | Ce înseamnă |
|---|---|---|
| Acceptate ca atare | 11 | Confirmări. Codul e deja corect sau nu decurge nicio schimbare: A2, C2, C3, D2, D7, E2, E6, E7, E9, F1, F3 |
| Acceptate cu corecții | 19 | Ideea e bună, execuția propusă de el sau de noi are un defect: A1, A3, A5, B3, B4, B5, C1, C4, C5, D1, D3, D4, D6, E3, E4, E5, E8, F2, F4 |
| Respinse | 1 | B2, factual falsă (vezi secțiunea 4) |
| Blocate până răspunde cineva | 5 | B1 (tu), A4, D5, E1, E10 (avocatul) |

În plus, inventarul a scos la iveală:

- **6 lucruri livrate pe care nu le-a confirmat nimeni** și pe care le-am tratat tacit ca aprobate: prima și a patra formulare de petitum, numărul de Barou în somație și opis, separarea "cerere generată" de "cerere depusă", blocajul pachetului la fișier lipsă, soarta ZIP-ului.
- **12 defecte reale în cod sau în documentele trimise lui**, dintre care 3 sunt afirmații false pe care i le-am dat noi în scris.
- **O problemă de proces**: comitul `393066a` (14 august) a schimbat aplicația sub documentul trimis lui pe 6 august. Mai multe dintre cererile lui din temele C și F sunt deja implementate. Nu el descrie o aplicație veche, noi am livrat pe o zonă aflată în consultare fără să îl anunțăm.

---

## 2. Ce trebuie decis de tine, nu de noi

### 2.1 Decizia care blochează restul: cine depune la instanță

Aceasta e prima întrebare, înaintea oricărui detaliu. B1, B2, C1, C5, NC3, NC4 și o parte din F se sprijină pe ea.

Avocatul scrie, ca premisă, "vom trimite dosarul din platforma noastră către adrese de e-mail ale fiecărei instanțe". Nu e o cerere explicită, e o inferență. Are două surse identificabile, ambele ale noastre:

1. La §7 din documentul de flux i-am scris că nu putem depune prin rejust pentru că ar cere sesiunea și semnătura lui electronică calificată. **Este fals.** Formularul de înregistrare e anonim, fără cont, iar portalul acceptă și semnătură olografă scanată. Sub acea premisă falsă, deducția lui că trebuie alt canal e corectă, iar singurul rămas e emailul.
2. În agenda de ședință din 28 iulie (comisă, `55de21d`) i-am pus explicit pe masă varianta "platforma transmite prin email către instanță, în numele avocatului, cu împuternicire" și l-am întrebat dacă e de acord. Dacă a citit-o, B1 nu e neînțelegere, e răspunsul lui.

**Nu putem trata asta ca pe o eroare de-a lui.** Aceeași întrebare a rămas fără răspuns din iunie (întrebarea 6 din MODIFICARI-REVIZIE-AVOCAT).

**Opțiunea A: rămânem pe modelul actual.** Platforma pregătește pachetul complet, avocatul depune, recomandat prin registratura.rejust.ro. Cost de adoptare: zero, e ce avem. Cost de comunicare: îi retragem în scris afirmația falsă de la §7 și îi explicăm că refuzul real e absența oricărui temei contractual de a automatiza un portal CSM, nu o imposibilitate tehnică de a depune. Restul planului de la punctul 6 rămâne valabil.

**Opțiunea B: platforma expediază actul de sesizare prin email către instanță.** Cost real, luat din analiza de transmitere:

- 260-280 de rânduri de adrese colectate manual (223 de instanțe plus secțiile celor 42 de tribunale), fără sursă unică descărcabilă. Astăzi avem 0 din 223. `Court.email` există ca proprietate și nu e citit nicăieri, `data/courts.json` nu are cheia. Verificat.
- 5-10 zile-om de colectare plus întreținere recurentă, 8-11 zile de cod, total 3-5 săptămâni.
- Nu putem pune `From: avocat` fără să spargem SPF, DKIM și DMARC. Actul de sesizare pleacă vizibil de la un terț.
- Nu putem produce proba cerută de CPC art. 183 alin. 3. Putem promite cel mult dovadă de expediere.
- Riscul dominant nu e adresa moartă, care se vede, ci eșecul tăcut pe secție: cele 42 de tribunale rutează pe secție (`tr-bacau-reg1` față de `tr-bacau-reg2`), iar un email pe secția greșită ajunge într-o cutie reală, e citit de un om real și moare acolo, fără bounce.
- Platforma devine participant activ la actul de procedură. Schimbare calitativă de răspundere.
- Antecedentul evaluării: opțiunea "email asistat" a ieșit ultima, 3,3 din 10, față de 7,2 pentru modelul actual.

**Recomandarea noastră: opțiunea A**, dar decizia e a ta, pentru că nu e o corecție de detaliu, e o schimbare de model de produs și de răspundere.

Indiciul cel mai puternic că B1 e inferență, nu cerere: în același set de mențiuni, B5 și F1 presupun că el completează formularul rejust, iar F1 vine cu captură de ecran a formularului de înregistrare dosar nou. Cele două modele nu pot coexista.

### 2.2 A doua decizie: cât de departe merge redenumirea "mandat de reprezentare"

D5 admite oficial ipoteza consilierului juridic. Problema: platforma nu are nicio noțiune de consilier juridic. Zero apariții ale cuvântului în `src/`, `templates/`, `translations/`. Clauza de reprezentare din cerere, cea din somație și blocul de semnătură din opis sunt hardcodate pe avocat ("reprezentată convențional prin av. X, înscris în Tabloul Baroului cu nr. Y"). În momentul în care admitem scenariul, cererea îi spune judecătorului ceva neadevărat.

**Opțiunea A**: declarăm explicit că utilizatorul platformei e avocat, iar consilierul juridic e în afara scopului. Câmpurile serie și număr rămân opționale doar pentru mandatele fără serie. Cost: zero.

**Opțiunea B**: adăugăm o calitate profesională pe `User`, de care depind clauza de reprezentare și semnătura din trei PDF-uri. Cost: circa 1,5 zile, plus texte noi în RO și EN.

### 2.3 A treia decizie: mai livrăm pe zona aflată în consultare?

Comitul din 14 august a implementat deja C1 și C2 în timp ce avocatul citea documentul din 6 august. Propunerea: câtă vreme o temă e în consultare, orice livrare pe ea se consemnează într-o listă de delta care pleacă odată cu următorul document. Altfel fiecare rundă cere o reconciliere de acest fel.

---

## 3. Mențiune cu mențiune

### Tema A: confirmări (A1-A5)

| # | Ce cere | Verdict | Ce facem | Efort |
|---|---|---|---|---|
| A1 | Plata la primăria sediului instanței e caz rar, nu construiți nimic | Accept cu corecție | **Ștergem** `courtName` din `StampDutyPaymentTarget`, din resolver și asserțiunea din test. Corectat față de propunerea inițială de a-l afișa: numele instanței e deja pe pagină de două ori, interpolarea nu adaugă nimic. | 0,1 z |
| A2 | Bifa de plătitor e OK cum e | Accept | Nimic. Se retranșează la tema F. | 0 |
| A3 | Mementourile OK | Accept cu corecție | Butonul "oprește mementourile" nu oprește alerta săptămânală. **Corecție esențială față de propunerea inițială**: filtrul de mute se pune DOAR pe `findStampDutyDueAfterCaseNumber()`, nu pe scheletul comun. Pe schelet ar stinge și alerta care cere data înștiințării, singurul mecanism care pornește termenul de 10 zile sancționat cu anularea. | 0,25 z |
| A4 | Glumă, nu răspuns | Clarificare | Nu o tratăm ca aprobare. Blocajul pachetului la fișier lipsă rămâne neconfirmat. | 0 |
| A5 | Contul se cheamă la fel la orice primărie | Accept cu corecție | Ștergem constanta moartă `ACCOUNT_NAME`. **Bug real găsit**: `messages.en.yaml:1067` traduce denumirea contului bugetar în engleză. Locale EN e activ și are comutator în bară, deci cineva poate copia într-un ordin de plată numele unui cont care nu există. Se pune literalul românesc. | 0,1 z |

### Tema B: cine depune (B1-B5)

| # | Ce cere | Verdict | Ce facem | Efort |
|---|---|---|---|---|
| B1 | Platforma trimite emailuri la instanțe | Decizie de la tine | Vezi 2.1. Nu se implementează nimic până la răspuns. | 0,5 z (redactare) |
| B2 | Nu se poate plăti la registratură odată cu depunerea | **Respins** | Vezi secțiunea 4. Păstrăm `ACHITARE_LA_DEPUNERE`. | 0 |
| B3 | Comasez, apoi semnez | Accept cu corecție | Deblochează arhitectura pachetului pe cele 3 sloturi. **Corecție**: comasarea NU cere FPDI. Containerul are deja `poppler-utils` și `ghostscript` (verificat în Dockerfile), iar FPDI liber cade tocmai pe PDF 1.5+ cu cross-reference streams, adică pe exporturile de bancă. Estimarea se recalculează pe uneltele existente. | 0,25 z (consemnare) |
| B4 | Limită 10 MB pe email | Accept cu corecție | Bugetul de mărime se construiește pe limitele **verificate ale rejust** (11 MB pe fișier, 13 MB total), nu pe 10 MB, cifră fără sursă. Se livrează doar totalul estimat, contorul pe fișier are sens abia după împachetarea pe sloturi. **Nu recomandăm nicio unealtă externă de compresie.** | 0,5 z |
| B5 | De ce nu precompletați formularul? | Accept cu corecție | E o repetare: i-am răspuns pe jumătate, alternativa fezabilă a căzut la scurtare. Îi trimitem jumătatea tăiată, deja scrisă. Panoul de handoff, **fără câmpul "Secția"** (nu există în modelul de date și ar cere colectarea secțiilor pentru 42 de tribunale). | 0,75 z panou + dependențe |

### Tema C: momentul timbrării (C1-C5)

| # | Ce cere | Verdict | Ce facem | Efort |
|---|---|---|---|---|
| C1 | Plătește când dosarul primește număr | Accept cu corecție | Declanșatorul există deja și rulează. **Corecție blocantă**: trebuie extins la toate cele trei stări restante (`isOutstanding()`), nu la două. Astăzi `ACHITARE_LA_DEPUNERE` nu apare în nicio interogare de alertă, iar `NEACHITATA` e practic mulțime vidă pe fluxul normal (poarta de depunere o refuză). Textul alertei se **rescrie**, nu se propagă: afirmă temeiul plății anticipate, care nu se aplică după înregistrare. | 0,75 z |
| C2 | Plata timpurie evită regularizarea trimisă la client acasă | Accept | Preluăm justificarea ca text de produs, fără să promitem că nu mai vine nicio regularizare. | 0,25 z |
| C3 | Etichetă neutră "timbrez ulterior" | Accept | Se rescriu `cta_defer`, `deferred_body`, `modal_defer.intro`, `risk_body`, `consent`, RO și EN, la persoana a doua plural, ca restul cardului. `deferred_title` **rămâne neatins**, e deja exact textul propus. | 0,25 z |
| C4 | Mementourile 3/10/24 n-au fundament | Accept cu corecție | **Confirmat pe cod**: `app:check-stamp-duty` nu e în lista de joburi programate (verificat în `docker-entrypoint.sh`). Mementourile sunt cod mort, retragerea nu e o regresie de comunicat. **Corecție**: nu trecem alerta la `once`, ar contrazice regula documentată în trei locuri (`once` e pentru condiții care depind de un terț). Trecem la cadență lunară plus buton de oprire efectiv. Varianta ieftină: golim `SCHEDULE_DAYS` și lăsăm serviciul inert (0,25 z); varianta curată: ștergem tot, cu migrare și curățare de chei orfane (1 z). | 0,25 sau 1 z |
| C5 | O confirmare la termen, sau rubrică permanentă | Accept cu corecție | Trei suprafețe. **Corecții**: butonul "Nu încă" se elimină (ar cere o coloană nouă și ar muta stare printr-un GET dintr-un email); rândul din agendă **nu e nou**, se rescrie blocajul `STAMP_DUTY_NOTICE_MISSING`, un al doilea motiv ar strica numărătoarea din bara de risc. | 1,5 z pentru (1)+(3) |

### Tema D: mandatul de reprezentare (D1-D7)

| # | Ce cere | Verdict | Ce facem | Efort |
|---|---|---|---|---|
| D1 | Serie, număr, dată de emitere | Accept cu corecție | Trei coloane pe `Document`, nu pe `LegalCase`. Flux dedicat cu modal propriu. **Corecții**: CTA-ul din poartă trebuie să deschidă modalul dedicat, `data-upload-preset-type` nu funcționează odată ce tipul iese din dropdown-ul generic; se adaugă acțiune de editare a celor trei câmpuri și se extinde guard-ul de ștergere, altfel mandatul poate fi șters după ce a trecut poarta. | 2,5 z |
| D2 | Mandatul rămâne al avocatului | Accept | Nimic. Lanțul e complet în cod. | 0 |
| D3 | Condiționează depunerea de mandat | Accept, inversează decizie | Poartă tare la `generate` și la `downloadZip`, avertisment la confirmarea depunerii. **Corecții**: decizia se scrie explicit ca revizuire a M4 și E1 (care puneau validatorul pe `depune_cerere`), nu ca simplă inversare a notei din iulie; argumentul GDPR se scoate din motivare, platforma nu citește niciodată mandatul și documentele clientului sunt deja procesate la pasul 0. Motivare pur procedurală. | 1 z |
| D4 | Serie, număr, dată în opis | Accept cu corecție | Rând `meta` sub denumire, nu în coloana "Data adăugării", care tipărește data încărcării. Textul se compune din fragmentele prezente, fără locuri goale. | 0,5 z |
| D5 | Un singur tip pentru avocat și consilier | Accept cu corecție | Taxonomia e corectă. Vezi decizia 2.2: nu se poate accepta izolat fără să tranșăm ce scriem în cererea semnată de un consilier. | vezi 2.2 |
| D6 | Redenumire "mandat de reprezentare" | Accept cu corecție | Trei straturi: etichete RO și EN, intrarea din ZIP, valoarea de enum, plus redenumirea metodei `hasPowerOfAttorney()`. **Corecții**: se actualizează și SQL-ul din procedura de restaurare a bazei, care conține valoarea veche; se face preferabil DUPĂ merge-ul lui `72dff12`, care are deja conflicte pe `DocumentType` și pe traduceri. | 1 z |
| D7 | Slot 1 = cerere + mandat + act de identitate | Accept | Nimic în tema D. Închide întrebarea 15 parțial. | 0 |

### Tema E: textele care ajung la judecător (E1-E10)

| # | Ce cere | Verdict | Ce facem | Efort |
|---|---|---|---|---|
| E1 | "achitată prin registratura.rejust.ro, confirmarea fiind depusă la dosar" | **Blocat** | **Nu se schimbă acum.** Fraza lui e scrisă pentru plata de DUPĂ alocarea numărului. Cererea se generează exclusiv din `SOMATIE_TRIMISA`, deci înainte să existe dosarul, și nu se poate regenera. La data actului nu există niciun dosar în care ceva să fi fost depus. Formularea corectă e prospectivă. Se tranșează împreună cu tema C. | 0,25 z după decizie |
| E2 | Taxa se cere în toate cazurile | Accept | Nimic. Comportamentul e deja acesta. NU transcriem "până când judecătorul hotărăște" în textul amânat, ar fi mai permisiv decât termenul legal citat în aceeași frază. | 0 |
| E3 | Numiți serviciul | Accept cu corecție | O singură denumire, `registratura.rejust.ro`, în ambele texte PDF. **Corecție de argument**: nu respingem "Portalul CSM" pentru că ar fi neverificat (documentația noastră îl folosește deja), ci pentru că denumirea de domeniu e ce apare pe dovada emisă de portal. | 0,25 z (comun cu E1) |
| E4 | Scoate numărul din Barou din fraza de reprezentare | Accept cu corecție | Se șterge ramura și cheia. **Corecție de inventar**: numărul mai apare în subsolul aceleiași cereri, în somație de două ori și în opis. Îl scoatem din ambele locuri ale cererii; pentru somație și opis, întrebare (vezi 5). Coloana din profil NU se șterge. | 0,25 z |
| E5 | Text nou pentru domiciliul ales, cu email și telefon | Accept cu corecție | **Corecție blocantă**: garda de la linia 96 înfășoară ȘI declararea reprezentării convenționale, nu doar adresa. Extinderea ei ar produce cereri în care creditorul apare nereprezentat. Se separă întâi cele două elemente. Telefonul se randează condiționat pe segment, nu intră în gardă. **Nu punem constrângeri blocante pe formularul de profil**: e singurul formular de profil și blochează completarea datelor fiscale necesare abonamentului. | 1 z |
| E6 | Persoana desemnată = avocatul dosarului | Accept | Nimic. Deja implementat deliberat. | 0 |
| E7 | Forma de organizare nu apare pe acte | Accept | Nimic. Verificat, `companyName` nu apare în niciun PDF. | 0 |
| E8 | "Municipiul Iași, județ Iași", "Sector 5, București" | Accept cu corecție | Serviciu nou de formatare, cu lookup în nomenclatorul de localități (rangul UAT e populat 3186 din 3186). **Corecții**: ramura București trebuie să păstreze deduplicarea, altfel produce "București, București"; prefixele "Municipiul", "Orașul", "Comuna" sunt text de utilizator și trec prin translator, nu se hardcodează în PHP. **Alternativă de evaluat**: legarea localității din profil de nomenclator elimină clasa întreagă de eșec tăcut al potrivirii fuzzy pe text liber, cu costul unei migrări. | 2,5 z |
| E9 | Domiciliul ales nu e redundant | Accept | Nimic. Închide întrebarea 7. | 0 |
| E10 | Articolul e 1.017, nu 1016 | **Blocat** | **Nu schimbăm nicio citare.** Nu e o greșeală, sunt două forme publicate ale codului. Răspunsurile lui din toate rundele se potrivesc parțial cu fiecare. O corecție izolată ar produce acte în care cererea invocă 1017 alături de somație care invocă 1015. Vezi întrebarea din secțiunea 5. Amprenta reală: 55 de apariții în RO plus 55 în EN plus docblock-uri în peste 30 de fișiere PHP, deci 2 zile, nu 0,75. | 0 acum |

**Bug găsit incidental**: codul amestecă deja cele două forme. Prescripția executării apare ca art. 705 în textele către avocat și ca art. 706 în două comentarii. Se aliniază indiferent de răspuns.

### Tema F: formularul de pe registratură (F1-F4)

| # | Ce cere | Verdict | Ce facem | Efort |
|---|---|---|---|---|
| F1 | Titularul e clientul, plătitorul e avocatul | Accept | Închide întrebarea 16 (blocantă). `StampDutyUatResolver` rămâne neschimbat, premisa lui era corectă. **Nuanță**: e practica lui, nu norma. Panoul trebuie să spună explicit "județul și localitatea sunt ale CREDITORULUI". | 0 |
| F2 | Portalul generează dovada | Accept cu corecție | "În ce dosar" nu se susține pe formularul de dosar nou. **Bug găsit**: pe un dosar depus dar fără număr alocat, cardul și mementoul trimit avocatul la formularul "Înregistrează un dosar nou", ceea ce l-ar face să înregistreze un duplicat. Condiția corectă e starea de depunere, nu prezența numărului. | 0,5 z |
| F3 | Semnătura digitală are valoare de original | Accept | Închide întrebările 12 și 13. Nu afirmăm asta în UI fără verificare juridică proprie. | 0 |
| F4 | Folosiți ilovepdf pentru compresie | Accept faptul, **respingem soluția** | Vezi secțiunea 4. Recomprimare locală cu ghostscript, care e deja în imagine. Element al pachetului de comasare pe slot, nu item separat. | 0 acum |

**Derivat, avertismentul de plătitor**: bifa `payerOnBehalf` nu produce niciun efect persistent. Nu ajunge în serviciu, nu ajunge în audit, nu are coloană. Singurul test o acoperă verifică doar comutarea unui toast. Se rezolvă odată cu tema F, dar **corecție**: suprimarea avertismentului se face doar pe canalul care are declarație de plătitor (registratura), nu și pe ghișeu, unde chitanța poartă numele titularului de cont, exact cazul în care prezumția de plată contează. Și **nu** schimbăm prepopularea cu numele creditorului pe modalul de dovadă: acel modal servește viramentul bancar, unde creditorul e plătitorul preferabil.

**Panoul de handoff**: mockup-ul existent nu mai poate fi folosit ca referință. Are un câmp inexistent în captură ("Secția") și îi lipsesc 11 câmpuri reale. Nu se refac mockup-ul și panoul până nu avem captura variantei **persoană juridică** (majoritatea creditorilor noștri sunt societăți) și pe cea a formularului de plată într-un dosar existent. **Nu adăugăm CNP-ul avocatului în profil**: câmpul e deja citit de lanțul de facturare fiscală și ar intra în snapshotul de cumpărător și în cererea către furnizorul de e-Factura, pentru un beneficiu nul (un avocat își știe propriul CNP).

---

## 4. Ce respingem, cu argumentul de susținut în fața avocatului

**B2, "nu se poate achita prin registratură odată cu depunerea, pentru că nu ai încă număr de dosar".**

Argumentul: formularul "Înregistrează un dosar nou" conține el însuși cele trei opțiuni de taxă de timbru. Plata se face în același pas cu depunerea, înainte să existe orice număr de dosar. **Este chiar formularul din captura pe care el ne-a trimis-o la F1**, cel cu bifa "Am ales să fac plata taxei judiciare de timbru acum, însă sunt altă persoană decât cea care înregistrează dosarul". Deci îl cunoaște și îl folosește.

B2 e coerent doar sub premisa de la B1: dacă emailul îl trimitem noi, el nu ajunge niciodată la formularul de înregistrare, deci chiar nu poate plăti la depunere. Defectul e în premisă, nu în raționamentul lui. Formularea de folosit: "exact formularul din captura ta este formularul de înregistrare dosar nou, iar taxa se achită acolo, înainte să existe număr de dosar".

Dacă am accepta B2, am scoate o cale corectă juridic și folosită de el în practică, și l-am împinge înapoi spre timbrarea la regularizare, adică fix scenariul de care se plânge la C2.

**F4, recomandarea ilovepdf ca unealtă de compresie.**

Fișierele sunt probele clientului, conțin frecvent CNP și sunt acoperite de secretul profesional. Ce face el pe cont propriu e treaba lui. Ce recomandă platforma într-un ecran devine practica implicită a tuturor utilizatorilor și, la un control, poziția noastră. Avem deja un incident de acest tip în istoric (conturi noi trimiteau PDF cu CNP la un procesator extern fără acord).

Nuanță de spus, nu de ascuns: portalul rejust însuși listează link-uri către astfel de unelte sub slotul de încărcare. Tăcerea noastră nu elimină expunerea. Deci nu doar că nu recomandăm, ci spunem de ce, într-o propoziție: fișierele conțin date ale clientului, nu le urca pe unelte online, nici pe cele indicate de portal.

**Cadența `once` pentru alerta de taxă** (propunere internă, nu a lui). Ar contrazice o regulă de design documentată în trei locuri: `once` e rezervat condițiilor care depind de un terț. Taxa depinde exclusiv de avocat. Reducem la lunar, nu la unic.

---

## 5. Întrebări pentru avocat

Ordinea contează. Prima întrebare condiționează răspunsurile la mai multe dintre celelalte.

**0. Retragere, înaintea oricărei întrebări.** La §7 din documentul de flux ți-am scris că nu putem depune prin rejust fiindcă ar cere sesiunea și semnătura ta calificată. Am verificat: e fals, formularul e anonim și portalul acceptă și scan olograf. Motivul real al refuzului e altul: nu avem niciun temei contractual să automatizăm un portal al CSM. Depunerea prin rejust e perfect posibilă, doar că o faci tu, manual.

1. **Model de depunere.** Rămâi pe modelul actual (noi pregătim pachetul, tu depui prin registratura.rejust.ro, care e tot un email către instanță, doar trimis de CSM și rutat automat pe secție), sau ceri explicit ca platforma să devină cea care expediază actul de sesizare?
2. **Forma codului.** În codul pe care îl folosești tu, procedura ordonanței de plată începe la art. 1013 sau la 1014? Și articolul despre prescripția executării silite în 3 ani se numește "Termenul de prescripție" sau "Efectele împlinirii termenului"? Cu răspunsul ăsta mutăm toate citările deodată, niciodată una câte una.
3. **Numerotarea, acum sau la rescriere.** Ai spus că textele somației și ale cererii urmează să fie stabilite separat. Rescriem numerotarea acum, sau o luăm odată cu rescrierea integrală?
4. **Cele patru formulări de petitum.** Ai rescris a doua. Prima ("achitată conform dovezii anexate") și a patra (timbrare amânată) rămân cum sunt, sau le rescrii pe toate ca să sune la fel? Și: în actul depus scriem "registratura.rejust.ro", "Portalul CSM", sau formularea generică actuală?
5. **Numărul din Barou.** L-ai scos din fraza de reprezentare a cererii. Îl scoatem și din subsolul aceleiași cereri (facem asta oricum), dar rămâne pe somație, de două ori, și pe opis. Iese de peste tot sau doar din cerere?
6. **Fraza de la E1.** E scrisă pentru plata făcută după alocarea numărului de dosar. Cererea se scrie înainte să existe dosarul și nu se poate regenera. Ce scriem în cerere?
7. **Consilierul juridic.** E utilizator al platformei, sau doar reprezentantul creditorului în dosare pe care le depune un avocat? De asta depinde dacă cererea mai poate spune "reprezentată convențional prin av. X, înscris în Tabloul Baroului".
8. **Fișier lipsă.** Când un document obligatoriu a dispărut din stocare, pachetul refuză complet să se construiască (așa e azi), sau preferi pachet parțial cu avertisment vizibil despre ce lipsește?
9. **Mandat per dosar sau reutilizabil.** Același mandat se reîncarcă pe fiecare dosar al aceluiași client, sau un mandat acoperă mai multe dosare? De asta depinde dacă cele trei câmpuri produc retastare la fiecare dosar.
10. **Data emiterii mandatului.** Poate fi ulterioară somației, sau trebuie să preceadă primul act făcut în numele clientului?
11. **Numărul de dosar.** Când depui prin registratură, îl primești în confirmarea de la portal, sau îl afli abia căutând pe portal.just.ro după câteva zile? Platforma nu îl descoperă singură, deci de asta depinde tot mecanismul de la C1.
12. **Capturi.** Ne trimiți captura formularului cu "Persoana juridică" bifat și pe cea a formularului de plată într-un dosar existent? Majoritatea creditorilor noștri sunt societăți, iar acele ramuri nu le-am văzut niciodată.
13. **Limita de 10 MB pe email.** E o limită pe care ai lovit-o efectiv sau o regulă de prudență? Pe rejust limitele afișate sunt 11 MB pe fișier și 13 MB pe total.
14. **Adresa de email din formularul rejust**: a creditorului (titularul) sau a ta?

---

## 6. Plan de implementare pe loturi

### Lot 0: corecții de adevăr și curățenie ieftină. Se poate porni imediat.

Nu depinde de nicio decizie. Sunt lucruri care sunt false sau moarte astăzi.

- Retragerea în scris a afirmației false din §7 al documentului de flux și a promisiunii din MODIFICARI-REVIZIE-AVOCAT că platforma trimite prin email "Cerere de acces la dosarul electronic" (se sprijină pe `Court.email`, populat 0 din 223, deci e imposibil de onorat).
- `messages.en.yaml:1067`: literalul românesc pentru denumirea contului bugetar.
- Ștergerea constantei moarte `ACCOUNT_NAME` și a lui `courtName` din DTO și resolver.
- Filtrul de mute pe interogarea corectă, cu un test care demonstrează că alerta de regularizare NU e afectată.
- Fix pentru linkul care trimite spre "înregistrează dosar nou" pe un dosar deja depus.
- Alinierea celor două comentarii cu art. 706 la citarea folosită în textele către utilizator.
- Pipeline-ul de extracție sare peste tipurile neclasificabile (astăzi mandatul, cu numele clientului și eventual CNP, pleacă la procesatorul extern fără niciun beneficiu).
- Docblock-uri și comentarii Twig în română, rescrise în engleză în fișierele oricum atinse.

**Efort: 1 zi.**

### Lot 1: taxa de timbru, urmărirea. Depinde de răspunsurile 1 și 11.

C1 extins la toate cele trei stări restante, cu textul alertei rescris. C3, etichete și copy. C2, textul din modal. C4, retragerea sau inertizarea mementourilor. C5, punctele (1) și (3), fără butonul "Nu încă". Rescrierea celor trei teste care codifică invariantele rupte.

**Efort: 3 zile** (3,75 dacă se alege ștergerea completă a serviciului de mementouri, cu migrare).

### Lot 2: mandatul de reprezentare. Depinde de răspunsurile 7, 9, 10 și de decizia 2.2.

Efort peste pragul de 3 zile, deci **cere document de plan dedicat** înainte de implementare. Ordinea: D6 (redenumire, cât timp există un singur rând în baza locală, dar după merge-ul lui `72dff12`), apoi D1 (câmpuri și flux dedicat), apoi D3 (poarta, care depinde de existența fluxului ca remediu), apoi D4 (opisul, care citește câmpurile de la D1). Tema nu are astăzi niciun test comportamental: poarta, slotul din ZIP, poziția din opis și exceptarea de la plafonul de atașamente sunt toate netestate.

**Efort: 5 zile, incluzând testele.**

### Lot 3: textele care ajung la judecător. Depinde de răspunsurile 4, 5, 6 și de decizia 1.

Separarea gărzii care înfășoară reprezentarea de cea a adresei (precondiție blocantă). E3, E4, E5. E8, formatarea adresei procedurale.

**Efort: 3,5 zile** pentru varianta cu serviciu și potrivire pe nomenclator. Mai mult pentru varianta cu legătură la nomenclator, dar cu risc mult mai mic de eșec tăcut. De ales explicit.

### Lot 4: handoff spre registratură. Blocat până la răspunsurile 1, 12, 14.

Panoul refăcut pe structura reală a formularului, contoarele de mărime (B4), avertismentul de plătitor pe canal. Se leagă de punctul 9 din auditul de securitate (CNP necriptat la repaus), deci se face după el, nu înaintea lui.

**Efort: 2,5 zile.**

### Lot 5: renumerotarea citărilor. Blocat până la răspunsurile 2 și 3.

Dacă răspunde "la rescriere", cele 2 zile nu se cheltuie deloc. Ce se poate face acum fără risc: inventarul generat al celor circa 110 citări, cu articol, fișier, linie și tip de suprafață (text către judecător, text către avocat, comentariu intern), fiindcă doar prima categorie e urgentă.

**Efort: 0,25 zile inventar, 2 zile execuție condiționată.**

### Lot 6: comasarea pe slot. Amânat.

Pe `pdfunite` sau `ghostscript`, deja în imagine. Consecință de spus explicit: comasarea distruge orice semnătură deja aplicată, deci pachetul pleacă nesemnat și avocatul semnează la final, exact cum lucrează el (B3). Recomprimarea scanurilor e element al acestui lot, nu item separat.

**Efort: 2 zile.**

---

### Totaluri

| | Efort | Stare |
|---|---|---|
| Se poate începe azi (Lot 0) | 1 z | Liber |
| Blocat de decizia ta de la 2.1 | 9 z (loturi 1, 3, 4) | Blocat |
| Blocat de decizia ta de la 2.2 și de răspunsurile avocatului | 5 z (lot 2) | Blocat |
| Condiționat de răspunsul pe numerotare | 2 z (lot 5) | Blocat |
| Amânat deliberat | 2 z (lot 6) | Amânat |
| **Total** | **19 zile** | |

**Nu se poate începe nimic din loturile 1-5 până nu răspunzi la punctul 2.1.** Motivul: dacă alegem opțiunea B, starea `ACHITARE_LA_DEPUNERE`, a treia formulare de petitum, dialogul de confirmare a depunerii, panoul de handoff și o parte din alertele de taxă descriu o situație imposibilă și se rescriu, nu se rafinează.