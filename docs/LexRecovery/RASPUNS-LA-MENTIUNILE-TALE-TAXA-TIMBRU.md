# Taxa de timbru: ce am schimbat după mențiunile tale

> Răspuns punct cu punct la observațiile tale, ce a intrat în aplicație, ce a rămas și ce am nevoie
> să confirmi. 6 august 2026.

---

## 1. Mențiunile tale, una câte una

### 1.1. „OK" pe determinarea primăriei

Nimic schimbat. A rămas o limită: pentru creditorul fără sediu în România, taxa se plătește la
primăria de la sediul instanței, dar aplicația nu cunoaște localitatea sediului instanței, deci acolo
rămâne doar un text de îndrumare.

### 1.2. „Platforma îți dă link de plată imediat, deci cine completează și plătește. Oricum, destul de
des achită avocatul și refacturează."

Aveai dreptate, textul meu deducea greșit că plătitorul e reclamantul.

Am scos alarma de la „pe dovadă apare alt plătitor". La încărcarea dovezii există acum o bifă,
„plata a fost făcută în numele creditorului", iar mesajul devine o simplă mențiune. Dacă în practică
plătește frecvent avocatul, alerta s-ar fi declanșat pe aproape fiecare dosar și ar fi încetat să fie
citită.

### 1.3. „Dacă mergem pe plata prin registratură, documentul plății ajunge direct la dosar. Maxim
platforma ar trebui să obțină un OK că a fost achitată taxa."

Implementat, plus ceva ce decurgea din observație.

- **„Am achitat prin registratură":** confirmi cu data și, dacă ai, referința. Fără fișier, fiindcă
  portalul a trimis deja confirmarea instanței.
- **„Plătesc la depunere, prin registratură":** aici e miezul. Registratura ia taxa și cererea în
  același pas, deci plata se face **după** generarea pachetului. Până acum, ca să treci de poartă,
  erai obligat să bifezi „îmi asum timbrarea la regularizare", iar cererea afirma în fața instanței
  că taxa urmează a fi achitată potrivit art. 33 alin. 2. O afirmație falsă, pe dosarul unui avocat
  care plătea corect și anticipat.

Cererea are acum patru formulări despre taxă, nu două, și nu mai invocă o dovadă anexată decât dacă
dovada chiar e în pachet. Textele sunt la §3.1.

**Ce nu am făcut:** precompletarea formularului de pe registratură. Nu e chestiune de efort:
portalul e protejat cu Cloudflare și cere un antet de securitate imposibil de produs din afară, iar
niciun cod care rulează în browser nu poate încărca un fișier într-un formular de pe alt site. Peste
asta, ar fi automatizare pe un portal al CSM fără temei contractual.

**O corecție:** împuternicirea „pe care oricum o avem" nu exista în platformă. Acum există, §2.

### 1.4. „Aș modifica în «Timbrez la alocarea numărului de dosar», caz în care platforma îi dă
notificări să achite."

Am luat ideea, am refuzat denumirea. Alocarea numărului are loc la înregistrarea cererii, iar
regularizarea e o etapă ulterioară; butonul ar fi promis ceva ce nu ține de instanță.

Eticheta a devenit **„Depun acum, timbrez la cererea instanței"**, iar partea valoroasă a propunerii,
declanșatorul, e implementată: mementourile pornesc de la depunere.

Și am reparat cauza grijii tale. Ai spus că instanța poate comunica regularizarea la sediul
creditorului. Am verificat: mențiunea de domiciliu procedural ales exista în somație, dar **lipsea
din cererea de ordonanță de plată**, adică din actul unde contează. Acum există, textul la §3.2.

### 1.5. „Nu putem face un zip. Documentele se trimit doar pdf, fiecare cu semnătura electronică a
avocatului."

Cea mai valoroasă mențiune: îmi închide o întrebare pe care ți-o pusesem acum trei săptămâni.

Pachetul pentru registratură rămâne pentru iterația următoare, fiindcă depinde de un răspuns al tău
(§4.1). Am reparat însă acum un defect conex: pachetul refuză să se construiască dacă un document
obligatoriu a dispărut din stocare. Până acum era omis în tăcere, deci putea pleca fără dovada plății
sau fără dovada comunicării somației, în timp ce cererea din același pachet le descria ca anexate.

### 1.6. „Păi ziceam că nu indicăm primăria și contul?"

Neînțelegere din felul în care scrisesem. Nu indicam **IBAN-ul**. Aplicația arată primăria,
plătitorul și **denumirea** contului bugetar, niciodată un număr de cont. Am schimbat eticheta în
**„În contul (denumire, nu IBAN)"**.

Contează mai mult decât pare: formularul de pe registratură cere județul și localitatea, iar de acolo
rezultă primăria care încasează taxa. Legat de asta, întrebarea de la §4.2 e cea mai importantă din
document.

### 1.7. „Plata pe rejust după ce avem număr de dosar."

Parțial inexact. Portalul are două formulare: înregistrare dosar nou, unde taxa se plătește în chiar
acel formular, deci înainte de orice număr, și plata într-un dosar existent. Aplicația trimitea la
pagina principală când dosarul nu avea număr; acum duce direct la formularul corect.

### 1.8. „Aș scoate pasul cu data înștiințării și l-aș viola cu e-mailuri."

Am adăugat e-mailurile, nu am scos termenul.

Mementourile pornesc de la depunere, sunt cel mult trei și le poți opri pe dosar dintr-un clic. Pe
fișă apare și un avertisment permanent cât timp cererea e depusă și taxa neachitată.

Nu am scos termenul de 10 zile fiindcă insistența nu are efect juridic: dacă înștiințarea sosește și
taxa nu se plătește, în fața instanței contează ziua 10 de la comunicare. Cele două se acoperă
reciproc, iar dacă plătești la primul memento termenul nu se mai naște. Dacă rămâi pe poziție, e
decizia ta.

---

## 2. Trei lucruri descoperite pe parcurs

- **Împuternicirea avocațială nu exista ca tip de document.** O puteai încărca doar ca „alt
  document", deci nu intra în opis și nu îi puteam semnala lipsa. Acum are tip propriu, intră în opis
  și în pachet, iar la generarea cererii apare un avertisment dacă lipsește. Avertisment, nu blocaj.
- **Numărul din Tabloul Baroului nu se putea completa nicăieri.** Era tipărit pe somație, pe cerere
  și pe opis, dar niciun ecran nu îl scria, deci în practică nu apărea. Acum se completează din
  profil.
- **Documentele purtau datele avocatului conectat, nu ale celui din dosar.** Reparat.

---

## 3. Texte care ajung la judecător

Sunt deja în aplicație ca să le poți vedea, dar sunt formulări de lucru.

### 3.1. Taxa în petitum

| Situația | Textul propus |
|---|---|
| Dovada e în pachet | „achitată conform dovezii anexate" |
| Achitată prin registratură, fără fișier | „achitată prin registratura electronică a instanțelor, confirmarea plății fiind transmisă instanței pe această cale" |
| Se achită odată cu depunerea | „care se achită odată cu înregistrarea prezentei cereri, prin registratura electronică a instanțelor" |
| Amânată la regularizare | neschimbat |

1. Formularea a doua e corectă procedural, dat fiind că instanța primește confirmarea pe canalul
   portalului, nu ca anexă?
2. La a treia, se poate cere restituirea taxei ca cheltuială de judecată (art. 453) când, la data
   cererii, taxa urmează a fi achitată în chiar actul de înregistrare?
3. Numim serviciul („registratura.rejust.ro") sau rămânem generic? Am ales generic, ca să nu pun o
   denumire comercială într-un act.

### 3.2. Domiciliul procedural ales

> „Reprezentată convențional prin av. X, înscris în Tabloul Baroului cu nr. Y. Cu domiciliul
> procedural ales, potrivit art. 158 din Codul de procedură civilă, pentru comunicarea tuturor
> actelor de procedură, la sediul profesional al avocatului, situat în [adresă], persoana
> însărcinată cu primirea actelor de procedură fiind av. X."

4. Persoana însărcinată cu primirea: o numim nominal, cum am făcut, sau e suficientă trimiterea la
   cabinet? Înțeleg că fără această mențiune alegerea de domiciliu nu redirecționează comunicările.
   Confirmi?
5. Când cabinetul are altă denumire decât numele avocatului, arătăm ambele sau doar adresa?
6. Adresa trebuie să conțină obligatoriu județul? Azi îl adaug doar când spune ceva în plus, deci nu
   la București și la sectoare.
7. Mențiunea e redundantă, dat fiind că reprezentarea prin avocat e oricum declarată în cerere?

### 3.3. Alte două texte

8. Confirmi eticheta „Depun acum, timbrez la cererea instanței"? Alternativa neutră: „Depun acum,
   timbrez ulterior".
9. Avertismentul pentru împuternicirea lipsă spune că cererea intră în regularizare. E corectă
   consecința, sau sancțiunea e mai severă?
10. Împuternicirea se trece în opis, cum am făcut, sau e act al cererii și se menționează în corpul
    ei?
11. Când creditorul e reprezentat de consilier juridic propriu, ce act dovedește calitatea?

---

## 4. Ce a rămas

### 4.1. Pachetul pentru registratură

Formularul portalului are trei sloturi, maximum 11 MB pe fișier și 13 MB pe total.

12. **Blocant.** Cele două cerințe se bat cap în cap: dacă fiecare fișier e semnat individual,
    comasarea desface semnăturile, dar trei sloturi nu încap un dosar cu 8 anexe. Cum procedezi?
13. Semnătura pe fiecare fișier e cerință a portalului sau prudența ta? Întreb pentru că, potrivit
    descrierii Curții de Apel Galați, portalul acceptă și acte semnate olograf și scanate, ca
    alternativă.
14. Confirmi limitele de mărime? Ce faci când scanurile le depășesc?
15. În care slot urci împuternicirea și o urci de fiecare dată?

### 4.2. Panoul de asistență la completarea formularului

16. **Blocant, și cel mai important din document.** Când depui prin registratură, la „Județul de
    domiciliu sau de reședință" completezi datele tale sau ale creditorului? De acolo rezultă
    primăria care încasează taxa, iar plata în contul altei primării e tratată, potrivit analizei
    Institutului Național al Magistraturii, ca taxă neefectuată. De răspuns depinde și dacă
    avertismentul de plătitor mai are rost.

### 4.3. Calibrare

17. Mementourile pleacă în zilele 3, 10 și 24 de la depunere. Cifrele sunt alese de mine. La cât
    timp de la depunere sosește în practică înștiințarea de regularizare?
18. Are sens să insist pe o alegere pe care ai făcut-o conștient, când ai bifat amânarea la
    regularizare?
19. Când plătește avocatul în numele creditorului, ce document rămâne la dosar ca dovadă a legăturii?
20. **Blocant.** Rămâne deschisă și întrebarea din iulie: care formă a Codului de procedură civilă e
    în vigoare, cea în care cuprinsul cererii de OP e art. 1016 sau cea în care e art. 1017? Până
    răspunzi, nu modific nicio citare de articol.

---

## 5. Rezumat

**Implementat:** separarea „cerere generată" de „cerere depusă"; plata la depunere; confirmarea
plății prin registratură; cererea nu mai invocă o dovadă inexistentă; pachetul refuză să plece
incomplet; împuternicirea ca document propriu; domiciliul procedural ales; mementouri; deep-link
corect; corecțiile de copy.

**Amânat:** pachetul de PDF-uri pentru registratură și semnătura electronică; panoul de asistență.

**De la tine:** 20 de întrebări, blocante fiind 12, 16 și 20.
