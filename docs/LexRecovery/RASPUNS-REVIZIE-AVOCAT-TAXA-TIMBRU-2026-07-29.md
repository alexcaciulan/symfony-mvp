# Răspuns la revizia avocatului pe fluxul taxei de timbru

> Sursa: `FLUX-TAXA-TIMBRU-AVOCAT.docx`, adnotat cu albastru de avocatul consultant.
> Data răspunsului: 2026-07-29. Documentul recenzat: `FLUX-TAXA-TIMBRU-AVOCAT.md` (2026-07-15).
> Metodă: fiecare mențiune a fost verificată față de codul de pe branch-ul `lexrecovery`,
> față de `ANALIZA-PLATA-TAXA-TIMBRU.md` și `ANALIZA-TRANSMITERE-DOCUMENTE-INSTANTA.md`,
> și față de captura formularului real de pe registratura.rejust.ro
> (`docs/LexRecovery/rejust-inregistrare-dosar-nou-2026-07-20.png`).
> Nimic nu s-a implementat încă. Acesta este un document de decizie.

---

## 0. Tabel sinoptic

| # | Secțiune | Mențiunea, pe scurt | Verdict | Acțiune propusă |
|---|---|---|---|---|
| M1 | §2 | „OK" pe determinarea primăriei | Confirmare | Niciuna |
| M2 | §3 | Link-ul de plată apare imediat după completarea formularului, plătește cine completează; adesea plătește avocatul și refacturează | **Corect, noi greșisem** | Corectăm §3; scădem în intensitate avertismentul „plătitor ≠ creditor" |
| M3 | §4 | Plata prin registratură ajunge singură la dosar; noi să obținem doar un OK. Plus: să precompletăm formularul rejust și să urcăm împuternicirea | **Corect în principiu, parțial fezabil** | Adăugăm confirmare fără fișier; panou de date pentru rejust; automatizarea formularului rămâne NO-GO |
| M4 | §5 | Redenumire „Timbrez la regularizare" în „Timbrez la alocarea numărului de dosar", cu notificări automate după descoperirea dosarului pe portal | **Intenția e corectă, denumirea nu** | Contrapropunere: păstrăm temeiul legal, schimbăm declanșatorul și textul butonului. Plus un fix separat, mai ieftin, la cauza reală |
| M5 | §6 | Nu se poate trimite ZIP; doar PDF-uri, fiecare cu semnătura electronică a avocatului | **Acceptat, închide o întrebare deschisă** | Pachet dublu: ZIP pentru depunere fizică, set PDF pentru registratură |
| M6 | §8.2 | „Ziceam că nu indicăm primăria și contul?" | **Neîntemeiat, dar semnalează o ambiguitate reală de redactare** | Reformulăm §8; cardul rămâne, ba chiar devine mai util |
| M7 | §8.3 | Plata pe rejust se face după ce avem număr de dosar | **Parțial inexact** | Corectăm textul: plata se poate face și în formularul de înregistrare a dosarului nou |
| M8 | §8.6 | Scoatem pasul cu data înștiințării, îl înlocuim cu e-mailuri până confirmă achitarea | **Acceptat parțial** | Adăugăm dunningul, păstrăm termenul de 10 zile ca plasă de siguranță |

Două constatări noi, apărute din verificare, care nu erau în discuție (§10 de mai jos):
cererea de OP nu indică domiciliul procedural ales, iar împuternicirea avocațială nu există
deloc în platformă.

---

## 1. M1, §2: „OK"

**Mențiunea:** confirmare pe paragraful despre localitatea care nu se potrivește niciunei primării
și despre reclamantul fără sediu în România (OUG 80/2013 art. 40 alin. 2), lăsat în seama avocatului.

**Verificare:** comportamentul descris există în cod. `StampDutyUatResolver` întoarce un target
nerezolvat, iar cardul afișează `case_overview.stamp_duty.target.unmatched`, care conține exact
trimiterea la art. 40 alin. 2.

**Acțiune:** niciuna. Rămâne o singură datorie deja consemnată în analiză:
`StampDutyPaymentTarget.courtName` se calculează pentru cazul art. 40 alin. 2, dar cardul nu îl
afișează încă. Adică știm care e primăria instanței, dar nu i-o spunem avocatului.

---

## 2. M2, §3: cine primește link-ul de plată

**Mențiunea:** „în fapt, platforma îți dă link de plată imediat după ce completezi datele din
formular, deci cine completează și plătește; oricum, destul de des achită avocații și refacturează
sau au taxa înglobată în onorariu."

**Verificare:** corect, iar formularul real confirmă. Captura de pe registratura.rejust.ro arată o
bifă distinctă: „Am ales să fac plata taxei judiciare de timbru acum, însă sunt altă persoană decât
cea care înregistrează dosarul (precizez că am împuternicire să depun acțiunea în numele persoanei
respective)". Deci portalul tratează explicit plata făcută de altcineva decât titularul cererii, nu
o interzice.

Formularea noastră din §3 („avocatul completează formularul, iar clientul primește un link și achită
el însuși, **deci plătitorul e reclamantul**") deducea un fapt din altul. Concluzia nu se susține:
link-ul e generat pe loc, iar cine îl folosește e o chestiune de organizare între avocat și client.

**Consecința care contează, dincolo de text:** dacă în practică plătește frecvent avocatul, atunci
avertismentul „plătitor ≠ creditor" din §4 se va declanșa pe majoritatea dosarelor. Un avertisment
care apare mereu încetează să fie citit, iar noi îl folosim exact ca să semnalăm cazul rar și
periculos.

**Răspuns propus:**

1. Corectăm §3 din document: portalul generează link-ul imediat, plătitorul poate fi avocatul sau
   clientul, iar plata pentru terți este o funcție explicită a portalului.
2. Rescriem avertismentul din informativ, nu din alarmant, și îl suprimăm complet atunci când
   referința de plată indică o plată prin ghiseul.ro sau registratură. Motivul juridic: Legea
   268/2024 a introdus art. 40 alin. 3 în OUG 80/2013, iar plata online cu confirmare transmisă
   direct instanței prezumă efectuarea plății până la proba contrară. Când plata a trecut pe canalul
   recunoscut expres de lege, discuția „cine figurează pe ordin" își pierde miza.
3. Adăugăm, în modalul de încărcare a dovezii, o opțiune explicită „plata a fost făcută de avocat în
   numele creditorului", care înlocuiește avertismentul cu o mențiune neutră.

**Efort:** 0,25 zile (copy plus o condiție în `StampDutyService`).

---

## 3. M3, §4: dovada ajunge singură la dosar, iar noi să primim doar un OK

**Mențiunea:** „Dacă mergem pe plata prin registratura.rejust.ro, documentul plății ajunge direct la
dosar, îl trimite această platformă. Maxim platforma noastră ar trebui să obțină un OK că a fost
achitată taxa în așa fel încât să nu mai apară notificare în sensul acesta. Mă întreb dacă cumva
putem să îi precompletăm avocatului (cu datele din platformă) câmpurile din formularul de pe rejust
și să îi încărcăm împuternicirea avocațială pe care oricum o avem. Pe registratură sunt 3 câmpuri
pentru încărcat documente, doar primul este obligatoriu."

Sunt două cereri distincte. Le tratez separat, pentru că răspunsul diferă radical.

### 3.1. Confirmarea plății fără încărcarea unui fișier

**Verificare:** corect, cu o rezervă de care depinde design-ul. Astăzi `CaseStampDutyController`
acceptă starea `ACHITATA` doar prin încărcarea unui document `DOVADA_TAXA_TIMBRU` (fișier
obligatoriu, `case_overview.stamp_duty.error.file_required`). Dacă plata s-a făcut prin registratură
odată cu depunerea, fișierul e la instanță, iar noi îl cerem a doua oară fără să avem nevoie de el.

Rezerva: **CPC art. 197 cere ca dovada să fie atașată cererii.** Când depunerea se face prin
registratură, portalul chiar atașează plata la ce transmite, deci cerința e îndeplinită fără noi.
Când depunerea se face pe alt canal (poștă, curier, registratură fizică, e-mail direct), dovada
trebuie să fie fizic în pachetul nostru, altfel cererea pleacă necompletă. Deci confirmarea fără
fișier este corectă **numai** pe calea rejust.

**Răspuns propus:** adăugăm o a treia acțiune pe card, „Am achitat prin registratură", care:

- setează starea `ACHITATA` fără document, cu `stampDutyPaymentReference` completată de avocat;
- oprește notificările și scoate blocajul de la generarea pachetului;
- marchează pe dosar canalul plății, ca opisul și petitum-ul să spună adevărul
  („achitată prin registratura.rejust.ro, dovada transmisă instanței odată cu cererea", în loc de
  „achitată conform dovezii anexate", care ar fi fals dacă în pachet nu e niciun fișier);
- afișează un avertisment dacă avocatul alege ulterior alt canal de depunere decât rejust, pentru că
  în acel scenariu dovada chiar lipsește din ce ajunge la instanță.

**Efort:** 0,5 zile, plus 0,25 zile pentru varianta de petitum.

### 3.2. Precompletarea formularului rejust și urcarea împuternicirii

**Verificare, partea tehnică: nu se poate, și nu e o chestiune de efort.** Concluzia e deja
consemnată: automatizarea portalului nu funcționează prin POST, bot sau replay de API. Portalul stă
în spatele Cloudflare, folosește CSRF cu cookie double-submit și verifică amprenta TLS, iar
endpoint-urile interne răspund 401 chiar și cu token propriu. În plus, o restricție de securitate a
browserelor face ca **niciun cod JavaScript să nu poată popula un câmp de tip fișier**, oricât de
mult ne-am dori. Deci împuternicirea nu poate fi urcată de noi în formular, nici măcar printr-o
extensie de browser.

Peste bariera tehnică stă cea de fond, aceeași care a produs NO-GO-ul din R9: nu există API public
și niciun temei contractual pentru a automatiza un portal al CSM. Ce am construi ar fi scraping pe
infrastructură publică, cu răspundere procedurală pe capul nostru.

**Verificare, partea de premisă: împuternicirea avocațială nu există în platformă.** `DocumentType`
nu are un tip pentru ea, iar în tot codul și în UI nu apare nicio referire la împuternicire în afara
textelor GDPR (unde „împuternicit" are alt sens, cel din art. 28 GDPR). Deci afirmația „pe care
oricum o avem" descrie o stare pe care ne-o dorim, nu una existentă. Detaliez la §10.1, pentru că
lipsa ei e o problemă în sine, independentă de taxa de timbru.

**Ce este fezabil, și rezolvă aceeași durere:** un panou „Pregătit pentru registratură", deschis
lângă butonul de plată, care conține exact câmpurile formularului, în ordinea lui, cu buton de
copiere pe fiecare:

- instanța (o avem, rezolvată de `CompetentCourtResolver`);
- tip persoană, nume, prenume, CNP, e-mail, telefon ale celui care înregistrează;
- **țara, județul și localitatea de domiciliu sau reședință**, adică exact ce determină primăria
  beneficiară a taxei, pe care noi o calculăm deja;
- cuantumul taxei (200 lei);
- fișierele de urcat, deja pregătite în cele 3 sloturi (vezi M5).

Avocatul face copy-paste, nu tastează. Câștigăm cea mai mare parte din beneficiul precompletării
fără să atingem portalul.

**Efort:** 0,75 zile pentru panou, dependent de M5 pentru partea de fișiere.

---

## 4. M4, §5: redenumirea „Timbrez la regularizare"

**Mențiunea:** „Aș modifica din «Timbrez la regularizare» în «Timbrez la alocarea numărului de
dosar», caz în care, după ce platforma identifică numărul de dosar pe portal just, îi dă notificări
să achite și îl trimite să facă asta prin rejust. Cu cât lăsăm mai multe chestiuni manuale, ele pot
genera dificultăți, spre exemplu, din eroare instanța poate comunica cererea de regularizare la
sediul creditorului și nu la sediul avocatului."

**Verificare.** Intenția e bună și diagnosticul e corect, dar denumirea propusă amestecă două
momente procedurale distincte:

- **alocarea numărului de dosar** are loc la înregistrarea cererii (CPC art. 199), imediat, prin
  aplicarea ștampilei de intrare;
- **regularizarea** e o etapă ulterioară (CPC art. 200), în care completul verifică cererea și pune
  în vedere completarea ei, inclusiv timbrarea, cu termenul de 10 zile din OUG 80/2013 art. 33
  alin. 2.

Butonul denumit după primul moment ar promite ceva ce nu ține de instanță, ci de noi: că vom vedea
numărul pe portal. Iar temeiul legal al amânării rămâne art. 33 alin. 2, nu alocarea numărului.

E util de știut și că portalul însuși oferă amânarea, dar formulată exact în sensul pe care mențiunea
vrea să îl evite. Bifa din formular spune: „Am plătit deja taxa judiciară de timbru **sau doresc să o
plătesc mai târziu, după ce va fi calculată de instanța de judecată și după ce voi fi încunoștințat
asupra cuantumului ei și a termenului maxim de plată**". Deci calea oficială de amânare presupune
tocmai așteptarea înștiințării.

**Cauza reală a riscului semnalat, și un fix mult mai ieftin.** Grija exprimată este că înștiințarea
de regularizare ajunge la sediul creditorului, nu la avocat. Remediul procedural pentru asta este
domiciliul procedural ales (CPC art. 158). Am verificat: mențiunea există în **somație**
(`payment_notice.html.twig:110`, cheia `pdf.summons.domiciliu_ales`), dar **lipsește din cererea de
ordonanță de plată**, unde de fapt contează, pentru că acolo se stabilesc comunicările din proces.
Blocul de semnătură al cererii are numele avocatului și numărul din Barou, dar nicio adresă de
comunicare și nicio mențiune de domiciliu ales. Detaliu la §10.2.

Cu alte cuvinte: înainte să reconstruim fluxul ca să compensăm o comunicare greșit adresată, să
punem în cerere mențiunea care o adresează corect.

**Contrapropunere:**

1. Păstrăm temeiul și explicația („timbrare în procedura de regularizare, OUG 80/2013 art. 33
   alin. 2"), fiindcă asta scrie în lege și asta va scrie în încheierea instanței.
2. Schimbăm **eticheta butonului** în ceva care descrie decizia, nu procedura:
   **„Depun acum, timbrez după înregistrarea dosarului"**. Descrie ce face avocatul, nu ce va face
   instanța, și nu se lovește de distincția art. 199 față de art. 200.
3. Schimbăm **declanșatorul**, ceea ce e miezul valoros al mențiunii: în clipa în care numărul de
   dosar e confirmat (fie de avocat, fie prin candidatul propus de `PortalCaseMatcher`), pornim
   notificările de plată și deep-link-ul către „plata taxei într-un dosar existent". Nu mai așteptăm
   înștiințarea ca să acționăm.
4. Adăugăm mențiunea art. 158 CPC în cererea de OP (fix independent, vezi §10.2).

**Ce nu recomand:** să legăm întregul flux de descoperirea automată a dosarului pe portal.
`PortalCaseMatcher` propune candidați și cere confirmare umană, cu bună dreptate: numele părților se
potrivesc aproximativ, iar un dosar confirmat greșit ar contamina termene și notificări. Deci
declanșatorul rămâne „număr de dosar confirmat", care poate veni și din tastarea manuală a
avocatului. Automat unde se poate, confirmat unde trebuie.

**Efort:** 0,5 zile pentru declanșator plus notificare, 0,25 zile pentru copy, 0,25 zile pentru
art. 158 în cerere.

---

## 5. M5, §6: nu se poate trimite ZIP

**Mențiunea:** „Nu putem face un zip pentru instanță. Documentele se trimit doar în format pdf,
fiecărui fișier fiindu-i asociată semnătura electronică a avocatului."

**Verificare: acceptat, și e cea mai valoroasă mențiune din tot documentul.** Închide o întrebare pe
care o aveam deschisă explicit către dumneavoastră: `ANALIZA-TRANSMITERE-DOCUMENTE-INSTANTA.md`, §8,
întrebarea 4, „acceptă portalul un fișier `.zip`?", cu observația că răspunsul determină dacă
checklist-ul e utilizabil ca atare sau are nevoie de comasare în PDF.

Captura formularului adaugă trei constrângeri numerice pe care mențiunea nu le spune, dar care
schimbă implementarea:

- exact **3 sloturi**: „Cererea de chemare în judecată" (obligatoriu), „Urcă documente 2"
  (opțional), „Urcă documente 3" (opțional);
- **maximum 11 MB pe fișier**;
- **maximum 13 MB pe total**.

Un dosar cu facturi scanate depășește ușor 13 MB, deci avem nevoie și de compresie, nu doar de
conversie.

**Ce nu se schimbă:** ZIP-ul rămâne, pentru depunerea fizică, pentru curier și ca arhivă a dosarului.
Nu îl scoatem, îl completăm.

**Răspuns propus, „Pachet pentru registratură" alături de ZIP:**

1. fiecare document generat de noi este deja PDF (DomPDF), deci se descarcă individual, numerotat;
2. anexele încărcate ca JPG sau PNG se convertesc automat în PDF, altfel nu pot fi urcate;
3. propunem o repartizare implicită pe cele 3 sloturi: slot 1 cererea de OP, slot 2 opisul împreună
   cu somația și dovada comunicării, slot 3 anexele probatorii;
4. afișăm dimensiunea fiecărui slot și a totalului, cu avertisment înainte de 11 MB și de 13 MB, și
   oferim recomprimare a scanurilor;
5. semnătura rămâne integral a avocatului, aplicată în unealta proprie. Noi nu semnăm nimic și nu
   deținem certificate.

**Tensiune reală, pe care nu o pot rezolva singur:** dacă fiecare fișier trebuie semnat individual,
atunci comasarea mai multor documente într-un slot le desface semnăturile, pentru că un PDF comasat
este un fișier nou. Iar 3 sloturi nu încap într-un dosar cu 8 anexe. Cele două cerințe se bat cap în
cap. Întrebarea corespunzătoare e la §11.2.

**Notă de nuanță, nu de contrazicere:** Curtea de Apel Galați, autorul portalului, descrie serviciul
ca acceptând acte „semnate olograf și scanate **ori** semnate în formă electronică", deci ca
alternative, nu cumulativ. Iar Legea 216/2025 a modificat CPC art. 150 alin. 2, permițând
certificarea „conform cu originalul" prin semnătură electronică. Nu contest practica dumneavoastră,
care e prudentă și probabil corectă în relația cu registratura; întreb doar dacă semnătura pe fiecare
fișier este o cerință a portalului sau o măsură de prudență, pentru că răspunsul decide dacă putem
comasa.

**Efort:** 1,5 până la 2 zile, exact cum estimasem în analiza de transmitere pentru scenariul
„comasare în PDF".

---

## 6. M6, §8.2: „ziceam că nu indicăm primăria și contul?"

**Mențiunea:** pe rândul „Somația trimisă: apare pe fișa dosarului cardul «Taxa de timbru» cu suma,
primăria și contul."

**Verificare: obiecția e neîntemeiată, dar are o cauză reală în felul în care am scris noi.**

Ce spune §2 din document: „Nu afișează niciun **IBAN**". Ce spune §7: „Nu afirmă **IBAN-uri de
trezorerie**". Deci angajamentul a fost întotdeauna despre IBAN, nu despre primărie sau despre
denumirea contului.

Ce face codul azi, verificat în `_stamp_duty_card.html.twig`:

- „Se plătește de": numele creditorului;
- „La primăria": numele UAT-ului plus județul, sau „Nedeterminată" cu avertisment;
- „În contul": textul „Taxe judiciare de timbru și alte taxe de timbru", adică **denumirea contului
  bugetar, nu un IBAN**;
- disclaimer: „Platforma nu încasează taxa. Verificați contul primăriei pe portalul oficial înainte
  de plată."

Nicăieri un IBAN. Confuzia vine din cuvântul „contul" din §8, care în context financiar se citește
firesc ca „numărul de cont". Vina e a redactării noastre, care în rezumat a scurtat exact termenul
care trebuia păstrat lung.

**Răspuns propus:** reformulăm rândul din §8 în „cu suma, primăria de plată și denumirea contului
bugetar, fără IBAN", și adăugăm aceeași precizare în textul cardului.

**Și un argument pentru care cardul devine mai important, nu mai puțin, dacă adoptăm plata prin
registratură:** formularul rejust cere „Județul de domiciliu sau de reședință" și „Localitate de
domiciliu sau de reședință", iar de acolo se determină primăria beneficiară a taxei, conform art. 40
alin. 1. Deci valoarea calculată de `StampDutyUatResolver` nu e o informație de vitrină: e exact ce
trebuie selectat în două dropdown-uri de pe portal. Fără cardul nostru, avocatul le completează din
memorie sau din adresa greșită.

Aici apare și un risc pe care vreau să îl semnalez, pentru că nu l-am văzut discutat nicăieri: dacă
avocatul se înregistrează în formular ca persoană fizică, cu domiciliul lui, județul și localitatea
selectate vor fi ale avocatului, nu ale creditorului. Taxa ar ajunge atunci în contul altei UAT
decât cea prevăzută de art. 40 alin. 1, iar analiza INM (Solomon C.M.) tratează acest scenariu ca
taxă neefectuată. Probabil de aceea există în formular bifa despre plata în numele altei persoane.
Întrebarea aferentă e la §11.1.

**Efort:** 0,25 zile.

---

## 7. M7, §8.3: „după ce avem număr de dosar"

**Mențiunea:** pe rândul „Avocatul plătește pe registratura.rejust.ro (după ce avem număr de dosar)
(sau clientul, prin link) și încarcă dovada (dovada se trimite prin registratură la dosarul cauzei)."

**Verificare: parțial inexact, în forma generală.** Captura formularului „Înregistrează un dosar nou
pe rolul instanței de judecată" arată că plata taxei se face **în chiar formularul de înregistrare**,
înainte să existe vreun număr de dosar: există câmpul „Cuantumul taxei judiciare de timbru pe care îl
plătesc" și bifele aferente, în același pas cu urcarea cererii.

Deci registratura oferă două căi, iar ele corespund celor două scenarii din fluxul nostru:

| Situație | Formular | Momentul plății |
|---|---|---|
| Depunerea se face prin registratură | „Înregistrează un dosar nou" | Odată cu depunerea, **înainte** de numărul de dosar, deci plată anticipată conform art. 33 alin. 1 |
| Dosarul e deja pe rol, taxa nu e achitată | „Plata taxei într-un dosar existent" | După alocarea numărului, deci scenariul din M4 |

Codul face deja distincția, în `_stamp_duty_card.html.twig`: fără `courtCaseNumber` trimite la
rădăcina portalului, cu `courtCaseNumber` trimite direct la formularul de plată într-un dosar
existent. Ce lipsește e deep-link-ul către formularul de înregistrare a dosarului nou pe prima ramură,
unde astăzi lăsăm avocatul pe pagina principală.

**Răspuns propus:**

1. corectăm §8.3 din document: plata se poate face fie odată cu depunerea, în formularul de dosar
   nou, fie ulterior, în formularul de dosar existent;
2. înlocuim link-ul către rădăcina portalului cu link-ul direct către înregistrarea dosarului nou;
3. partea a doua a mențiunii, „dovada se trimite prin registratură la dosarul cauzei", e corectă și
   se rezolvă prin confirmarea fără fișier de la §3.1.

**Efort:** 0,25 zile.

---

## 8. M8, §8.6: dunning în loc de pasul manual

**Mențiunea:** „După cum ziceam, aș scoate pasul ăsta și l-aș viola cu e-mail-uri până confirmă
achitarea prin registratură."

**Verificare: acceptat în partea de dunning, respins în partea de eliminare.**

Partea de dunning e nu doar corectă, ci și ieftină: modelul există deja în cod. Pentru cererea în
anulare avem `MissingCommunicationDateEvent`, care produce notificare in-app plus e-mail cu subiectul
„Completează data comunicării pentru dosarul %case%". Același traseu, cu alt text, acoperă timbrarea.

Partea de eliminare e cea la care aș rezista, din trei motive:

1. **Termenul de 10 zile e singura măsură a sancțiunii.** E-mailurile noastre sunt insistență, nu
   procedură. Dacă înștiințarea chiar sosește și avocatul nu plătește, ceea ce contează în fața
   instanței e ziua 10 de la comunicare, nu al treilea e-mail trimis de noi. Scoțând termenul, am
   scoate exact informația care spune cât de rău stă dosarul.
2. **Dunningul proactiv și termenul nu se exclud, se acoperă reciproc.** Dunningul pleacă de la
   descoperirea numărului de dosar, deci mai devreme. Termenul pleacă de la comunicare, deci mai
   târziu, dar cu efect juridic. Dacă avocatul plătește la primul e-mail, termenul nu se mai naște
   niciodată, ceea ce e rezultatul dorit. Dacă nu plătește, avem plasa de siguranță.
3. **Am ridicat deja acest lucru în analiza inițială, și tot dumneavoastră ne-ați împins spre el.**
   Ambii revizori au insistat atunci că supapa fără termen mută riscul în loc să îl reducă: avocatul
   știe că poate amâna, iar noi îl lăsăm singur exact pe veriga care duce la anulare. Nu aș desface
   asta la un an distanță pe motiv de comoditate a unui pas manual.

**Răspuns propus, care păstrează ce e valoros din mențiune:**

- pasul manual „Am primit înștiințarea instanței" **rămâne, dar nu mai e calea principală**. Devine
  o acțiune secundară, folosită doar dacă înștiințarea chiar sosește înainte de plată;
- se adaugă dunningul, pornit de la confirmarea numărului de dosar, cu cadență descrescătoare
  (de exemplu la 1, 4, 8 și 15 zile), oprit imediat ce starea devine `ACHITATA`, indiferent dacă asta
  s-a întâmplat prin încărcarea dovezii sau prin confirmarea „am achitat prin registratură";
- fiecare e-mail conține deep-link-ul către formularul de plată în dosar existent, cu numărul de
  dosar deja în text, ca avocatul să nu îl caute;
- banner persistent pe dosar cât timp starea e `AMANATA_REGULARIZARE`, element deja consemnat ca
  rămas deschis în `ANALIZA-PLATA-TAXA-TIMBRU.md` §10.

**Efort:** 0,75 zile, refolosind traseul de notificare existent.

---

## 9. Plan final, după verificarea pe cod (2026-08-05)

Prima versiune a acestei secțiuni lista șapte livrabile independente. Verificarea codului a arătat
că cinci dintre ele depind de aceeași piesă lipsă, deci planul e reorganizat în blocuri, cu
dependențe explicite.

### 9.0. Piesa lipsă din care decurge restul

Platforma tratează „am generat pachetul" și „am depus la instanță" ca fiind același moment:
`CasePaymentOrderController::generate()` aplică `depune_cerere` imediat după ce PDF-urile sunt
scrise, iar `LegalCase` nu are `filedAt`, `filingChannel` sau `filingReference`. Nu există niciun
loc `CERERE_GENERATA` în `config/packages/workflow.yaml`.

Consecința pentru revizia de față: **nu există momentul „avocatul a depus"**, deci nu există unde
agăța nici confirmarea plății făcute la depunere (M3, M7), nici pornirea urmăririi (M8). Aceeași
lipsă a obligat deja migrarea taxei de timbru să excludă `CERERE_DEPUSA` din backfill, pentru că
statusul nu însemna ce spune numele lui.

Este exact D3 din `ANALIZA-TRANSMITERE-DOCUMENTE-INSTANTA.md` §6, punctul 1, marcat acolo
„fereastra se închide la lansare". Două analize independente, la trei săptămâni distanță, ajung la
aceeași piesă. Se face prima.

### 9.1. Blocurile

| Bloc | Livrabil | Efort | Depinde de | Sursa |
|---|---|---|---|---|
| **A** | D3: loc `CERERE_GENERATA`, tranziție `genereaza_cerere`, `depune_cerere` mutat pe confirmarea reală, `inregistreaza_dosar` din ambele stări, câmpuri `filedAt` / `filingChannel` / `filingReference`, modal „Am depus", migrare cu backfill | 1,5 z | nimic | §9.0, M3, M7, M8 |
| **B1** | Cale nouă la poartă: „plătesc la depunere, prin registratură", cu petitum propriu și confirmare cerută după depunere | 1 z | A | M3, M7 |
| **B2** | Deep-link către formularul de înregistrare dosar nou, în locul rădăcinii portalului | 0,25 z | nimic | M7 |
| **B3** | Copy: eticheta amânării, „denumirea contului bugetar, fără IBAN", avertisment de plătitor coborât în informativ, afișarea primăriei instanței pentru art. 40 alin. 2 | 0,5 z | validare avocat | M2, M4, M6 |
| **C1** | Pachet pentru registratură: PDF pe cele 3 sloturi, conversie imagini, contoare față de 11 și 13 MB, ZIP păstrat pentru depunerea fizică | 2 z | A, întrebarea 2 | M5 |
| **C2** | `CaseFilesPackager`: eroare tare pe document obligatoriu lipsă de pe disc, în loc de `continue` tăcut | 0,25 z | nimic | §10.3 |
| **D1** | `DocumentType::IMPUTERNICIRE_AVOCATIALA`, în opis, în pachet, avertisment când lipsește, fără poartă | 0,75 z | nimic | §10.1 |
| **D2** | Domiciliul procedural ales în cererea de OP, cu persoana însărcinată cu primirea actelor și guard pe adresa incompletă | 0,5 z | validare avocat | §10.2, M4 |
| **E** | Urmărirea taxei neachitate: două declanșatoare (depunere confirmată, respectiv număr de dosar confirmat), plafon de cadență și oprire pe dosar, banner persistent | 1 z | A, B1 | M8 |
| **F** | Panoul de handoff către rejust, mockup existent în `docs/LexRecovery/mockups/handoff-rejust-panou.html` | 0,75 z | C1, întrebarea 1 | M3.2 |

Ordinea de execuție: A, apoi B și D în paralel, apoi C, apoi F, la final E.
Total aproximativ 8,5 zile.

**În documentul de flux (0,5 zile):** §3 despre link-ul de plată; §4 despre plătitor; §5 despre
denumirea și declanșatorul amânării; §6 despre ZIP; §8.2 despre cont; §8.3 despre momentul plății;
§8.6 despre dunning.

**Ce nu facem, și de ce:** nu automatizăm formularul rejust (imposibil tehnic și fără temei
contractual), nu semnăm documente în locul avocatului, nu eliminăm termenul de 10 zile, nu afișăm
IBAN-uri de trezorerie.

---

## 10. Trei constatări apărute din verificare

### 10.1. Împuternicirea avocațială nu există în platformă

Mențiunea M3 presupune că o avem. Nu o avem. `DocumentType` conține 21 de valori, de la `SOMATIE` la
`ALT_DOCUMENT`, niciuna pentru împuternicire. În tot codul, cuvântul apare doar în textele GDPR, cu
sensul de „persoană împuternicită" din art. 28 GDPR, care e cu totul altceva.

Consecința depășește taxa de timbru: cererea formulată prin avocat trebuie însoțită de dovada
calității de reprezentant (CPC art. 151), iar împuternicirea avocațială este acel act. Astăzi
avocatul o poate încărca doar ca `ALT_DOCUMENT` sau `ANEXA`, deci noi nu știm că există, nu o punem
în opis, nu o numerotăm în pachet și nu putem verifica dacă lipsește.

**Propunere:** tip nou `IMPUTERNICIRE_AVOCATIALA`, listat în opis, inclus în pachet și verificat în
checklist-ul de depunere. Este și fișierul pe care mențiunea M3 voia să îl urce în slotul 2 de pe
rejust, deci cele două se rezolvă împreună.

### 10.2. Cererea de OP nu indică domiciliul procedural ales

Aceasta e cauza directă a riscului semnalat în M4, „instanța poate comunica cererea de regularizare
la sediul creditorului și nu la sediul avocatului".

Verificat în cod: cheia `pdf.summons.domiciliu_ales` („Cu domiciliul procedural ales, potrivit
art. 158 din Codul de procedură civilă, pentru comunicarea actelor de procedură la adresa avocatului
%lawyer%") este folosită **numai** în `payment_notice.html.twig:110`, adică în somație. În
`payment_order_request.html.twig`, blocul creditorului cuprinde nume, CUI, ONRC, adresă, e-mail,
telefon și IBAN, fără nicio mențiune de reprezentare convențională și fără domiciliu ales. Blocul de
semnătură are numele avocatului și numărul din Barou, dar nicio adresă de comunicare.

Ironia e că mențiunea stă exact pe actul unde contează mai puțin, somația fiind extrajudiciară, și
lipsește de pe actul care declanșează procesul și toate comunicările din el.

**Propunere:** adăugăm în cererea de OP, în blocul creditorului, mențiunea reprezentării
convenționale plus domiciliul procedural ales la sediul avocatului, cu indicarea persoanei
însărcinate cu primirea actelor. Cheile de traducere există deja, `conventional_rep`,
`conventional_rep_bar` și `domiciliu_ales`, deci e în cea mai mare parte reutilizare.

**De confirmat cu dumneavoastră:** formularea exactă și dacă indicarea persoanei însărcinate cu
primirea corespondenței trebuie să fie nominală sau e suficientă trimiterea la cabinet.

**Precizare de așteptări:** când reprezentarea prin avocat e declarată în cerere, actele se comunică
oricum reprezentantului, deci mențiunea poate fi redundantă în drept. Rămâne în plan ca redactare
standard, ieftină, nu ca remediu decisiv al riscului semnalat.

### 10.3. Pachetul poate pleca fără dovadă, în tăcere

`CaseFilesPackager::addDocumentsToZip()` sare peste orice document al cărui fișier lipsește de pe
disc, printr-un `continue` fără log și fără eroare. Dacă fișierul dovezii dispare (mutare de
storage, ștergere manuală, upload eșuat parțial), ZIP-ul se produce fără el, iar cererea de OP din
același pachet afirmă „achitată conform dovezii anexate".

Este exact clasa de defect reparată în iulie, când petitum-ul afirma o dovadă inexistentă în cazul
amânării la regularizare, doar că ajunsă acolo pe alt drum. Riscul e mai larg decât taxa: la fel
poate dispărea dovada comunicării somației, a cărei lipsă atrage respingerea cererii ca
inadmisibilă.

**Propunere:** eroare tare pe documentele obligatorii lipsă, cu mesaj care spune care fișier
lipsește. Recomandare deja formulată în `ANALIZA-TRANSMITERE-DOCUMENTE-INSTANTA.md` §6, punctul 3,
neimplementată.

---

## 11. Întrebări de returnat

1. **Cine figurează ca titular în formularul rejust?** Dacă avocatul se înregistrează ca persoană
   fizică, cu domiciliul lui, județul și localitatea din formular sunt ale avocatului, iar taxa pare
   să ajungă la altă UAT decât cea din art. 40 alin. 1. În practică, ce se completează la „Județul de
   domiciliu sau de reședință": datele avocatului care depune sau ale creditorului reclamant? Decide
   dacă panoul nostru de la §3.2 afișează sediul creditorului sau altceva, și dacă avertismentul
   „plătitor ≠ creditor" mai are rost.
2. **Semnătura pe fiecare fișier este cerință a portalului sau prudență proprie?** Dacă e cerință,
   nu putem comasa anexele, dar atunci un dosar cu 8 anexe nu încape în 3 sloturi. Cum procedați azi
   în practică: semnați un PDF comasat pe slot, sau urcați anexele grupate altfel?
3. **Confirmați limitele?** 11 MB pe fișier și 13 MB pe total, conform textului din formular. Ce
   faceți când scanurile depășesc? Există și avertismentul portalului că instanța poate cere
   tipărirea sau plata costului tipăririi.
4. **Împuternicirea avocațială**, în care slot o urcați și o urcați întotdeauna? Vrem să o pregătim
   noi, numerotată în opis.
5. **Merită să oferim și calea „plătesc anticipat, înainte de depunere"?** Adică plata la primărie
   sau prin ghiseul.ro, cu dovada în pachet, așa cum cere art. 33 alin. 1 și CPC art. 197. Sau, în
   practică, plata prin registratură odată cu depunerea a înlocuit-o complet și putem simplifica
   fluxul?
6. **Rămâne valabilă întrebarea 3 din analiza de transmitere**, care blochează orice atingere a
   citărilor de articole din cod: care formă a Codului de procedură civilă este autoritativă, cea în
   care cuprinsul cererii de OP este art. 1016 sau cea în care este art. 1017? Până la răspuns nu
   modificăm nicio citare din documentele care ajung la judecător.

---

## 12. Surse folosite la verificare

- Captura formularului de înregistrare dosar nou, registratura.rejust.ro:
  `docs/LexRecovery/rejust-inregistrare-dosar-nou-2026-07-20.png` (2026-07-20)
- `docs/LexRecovery/ANALIZA-PLATA-TAXA-TIMBRU.md`, §2, §5, §10
- `docs/LexRecovery/ANALIZA-TRANSMITERE-DOCUMENTE-INSTANTA.md`, §2, §4, §6, §7, §8
- Cod: `templates/case/overview/_stamp_duty_card.html.twig`,
  `templates/pdf/payment_order_request.html.twig`, `templates/pdf/payment_notice.html.twig`,
  `src/Enum/DocumentType.php`, `src/Enum/DeadlineType.php`,
  `src/Service/Portal/PortalCaseMatcher.php`, `src/EventSubscriber/EmailNotificationSubscriber.php`,
  `translations/messages.ro.yaml`
- OUG 80/2013 art. 6 alin. 2, art. 33 alin. 1 și 2, art. 40 alin. 1, 2 și 3
- CPC art. 151, art. 158, art. 197, art. 199, art. 200
- Legea 268/2024 (plata online a taxei, prezumția de plată), Legea 216/2025 (certificare „conform cu
  originalul" prin semnătură electronică)
- INM, Solomon C.M., consecințele plății taxei în contul altei UAT
