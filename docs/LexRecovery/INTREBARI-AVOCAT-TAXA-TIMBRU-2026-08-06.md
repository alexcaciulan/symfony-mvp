# Întrebări pentru avocat, după implementarea reviziei pe taxa de timbru

> Ce s-a construit pe baza observațiilor dumneavoastră, ce a rămas deschis și ce texte au nevoie
> de validarea dumneavoastră înainte să ajungă în fața unui judecător.
> Data: 2026-08-06. Branch: `feature/taxa-timbru-revizie-avocat`.

---

## 1. Texte care ajung la instanță și pe care nu le scriem fără acordul dumneavoastră

Toate cele de mai jos sunt deja în cod, ca să poată fi văzute și încercate, dar sunt formulări de
lucru. Vă rugăm să le confirmați sau să le rescrieți.

### 1.1. Taxa în petitum, patru situații

Până acum cererea avea două formulări. Realitatea are patru.

| Situația | Textul propus |
|---|---|
| Dovada e în pachet | „Taxă judiciară de timbru: 200,00 RON, achitată conform dovezii anexate." |
| Achitată prin registratura electronică, fără fișier la noi | „Taxă judiciară de timbru: 200,00 RON, achitată prin registratura electronică a instanțelor, confirmarea plății fiind transmisă instanței pe această cale." |
| Se achită odată cu depunerea | „Taxă judiciară de timbru: 200,00 RON, care se achită odată cu înregistrarea prezentei cereri, prin registratura electronică a instanțelor." |
| Amânată la regularizare | (neschimbat) „urmând a fi achitată potrivit art. 33 alin. 2 din OUG 80/2013, cu depunerea dovezii la dosar." |

**Întrebări:**
1. Textul de la a doua situație e corect procedural, dat fiind că instanța primește confirmarea pe
   canalul portalului, nu ca anexă la cerere?
2. La a treia situație, se poate cere restituirea taxei ca cheltuială de judecată (art. 453) când,
   la data cererii, taxa nu e încă achitată, ci urmează a fi achitată în chiar actul de înregistrare?
3. Preferați să numim serviciul („registratura.rejust.ro") sau să rămânem la formularea generică
   („registratura electronică a instanțelor")? Am ales generic, ca să nu punem o denumire
   comercială într-un act.

### 1.2. Domiciliul procedural ales

Mențiunea lipsea din cererea de ordonanță de plată, deși exista în somație. Textul propus:

> „Reprezentată convențional prin av. X, înscris în Tabloul Baroului cu nr. Y. Cu domiciliul
> procedural ales, potrivit art. 158 din Codul de procedură civilă, pentru comunicarea tuturor
> actelor de procedură, la sediul profesional al avocatului, situat în [adresă], persoana
> însărcinată cu primirea actelor de procedură fiind av. X."

**Întrebări:**
4. Persoana însărcinată cu primirea actelor: o numim nominal pe avocat, cum am făcut, sau e
   suficientă trimiterea la cabinet? Fără această mențiune, alegerea de domiciliu nu redirecționează
   comunicările, deci nu e un detaliu de stil.
5. Când cabinetul are altă denumire decât numele avocatului, mențiunea trebuie să le arate pe
   amândouă sau doar adresa?
6. Adresa de comunicare trebuie să conțină obligatoriu județul? Azi îl adăugăm doar când spune ceva
   în plus, deci nu la București și la sectoare.
7. Mențiunea e redundantă în drept, dat fiind că reprezentarea prin avocat e oricum declarată în
   cerere? O păstrăm ca redactare standard, dar vrem să știm dacă are efectul pe care îl așteptăm.

### 1.3. Eticheta amânării

„Timbrez la regularizare" a devenit **„Depun acum, timbrez la cererea instanței"**.

8. Confirmați formularea? Alternativa neutră ar fi „Depun acum, timbrez ulterior", care nu numește
   niciun moment procedural.

### 1.4. Avertismentul pentru împuternicirea lipsă

Textul propus, scris deliberat fără număr de articol:

> „Nu ai atașat împuternicirea avocațială. Cererea formulată prin avocat se depune împreună cu
> dovada calității de reprezentant. Poți continua, dar dacă împuternicirea nu ajunge la instanță,
> cererea intră în regularizare."

9. E corectă consecința descrisă (regularizare), sau lipsa ei atrage o sancțiune mai severă?
10. Împuternicirea se trece în opis, alături de anexe, sau e considerată act al cererii și se
    menționează în corpul ei? Azi o punem în opis și în pachet, numerotată `05`.

---

## 2. Întrebări care blochează lucruri neimplementate

### 2.1. Cine figurează ca titular în formularul de pe registratură

11. Când depuneți prin registratura electronică, la „Județul de domiciliu sau de reședință"
    completați datele dumneavoastră sau ale creditorului? De aici rezultă primăria care încasează
    taxa (OUG 80/2013 art. 40 alin. 1), iar o primărie greșită înseamnă, potrivit analizei INM, taxă
    neefectuată.

De răspunsul acesta depinde panoul de asistență la completare, care rămâne neimplementat, și tot de
el depinde dacă avertismentul de plătitor mai are rost în forma actuală.

### 2.2. Plata de către avocat

Ne-ați spus că destul de des achită avocatul și refacturează. Am adăugat o bifă prin care declarați
asta, iar avertismentul devine o mențiune neutră.

12. Ce document rămâne la dosar ca dovadă a legăturii dintre plătitor și reclamant? Textul propus
    spune „păstrați la dosar documentul din care rezultă legătura", ceea ce e vag intenționat.

### 2.3. Cadența mementourilor

Platforma trimite acum cel mult trei mementouri pentru taxa neachitată, în zilele 3, 10 și 24 de la
depunere, cu posibilitatea de a le opri pe dosar.

13. Cifrele sunt alese de noi. Din experiența dumneavoastră, la cât timp de la depunere sosește în
    practică înștiințarea de regularizare?
14. Are sens să insistăm pe o alegere pe care ați făcut-o conștient, când ați bifat amânarea la
    regularizare? Sau acolo ar trebui să tăcem?
15. Permitem oprirea mementourilor pe un risc care se termină cu anularea cererii. Rămâne așa, sau
    ultimul mesaj din serie ar trebui să fie neoprbil?

---

## 3. Ce am respins argumentat din propunerile dumneavoastră

**Redenumirea în „Timbrez la alocarea numărului de dosar".** Alocarea numărului are loc la
înregistrarea cererii, iar regularizarea e o etapă ulterioară. Eticheta ar fi promis ceva ce nu ține
de instanță, ci de noi. Am păstrat temeiul și am schimbat în schimb declanșatorul: mementourile
pornesc de la depunere, nu de la înștiințare.

**Eliminarea termenului de 10 zile.** Mementourile noastre sunt insistență, nu procedură. Dacă
înștiințarea sosește și taxa nu se plătește, ce contează în fața instanței e ziua 10 de la
comunicare. Am adăugat urmărirea peste termen, nu în locul lui.

**Precompletarea formularului de pe registratură.** Nu e fezabilă: portalul e protejat cu Cloudflare,
cere un antet CSRF și amprentă TLS, iar niciun cod din browser nu poate popula un câmp de fișier.
Peste asta, ar însemna automatizare pe un portal al CSM fără temei contractual.

**Împuternicirea „pe care oricum o avem".** Nu o aveam: platforma nu avea niciun tip de document
pentru ea. Acum îl are.

---

## 4. Ce am construit, pe scurt

- Separarea „cerere generată" de „cerere depusă", cu data și canalul depunerii declarate de
  dumneavoastră. Platforma nu mai presupune că un PDF generat înseamnă un act depus.
- Calea „plătesc la depunere, prin registratură", care nu vă mai obligă să bifați o amânare pe care
  nu o faceți.
- Confirmarea plății prin registratură, fără fișier.
- Cererea nu mai invocă o dovadă anexată decât dacă dovada chiar e în pachet.
- Pachetul refuză să se construiască dacă un document obligatoriu a dispărut din stocare, în loc să
  plece incomplet în tăcere.
- Împuternicirea avocațială ca tip propriu, în opis și în pachet.
- Domiciliul procedural ales în cererea de ordonanță de plată.
- Numărul din Tabloul Baroului se poate completa în profil. Până acum nu se putea completa nicăieri,
  deci nu apărea pe niciun document.
- Mementouri pentru taxa neachitată, pornite de la depunere.

## 5. Ce a rămas pentru o iterație viitoare, prin decizia dumneavoastră

Pachetul pentru registratură (fișiere PDF în cele trei sloturi, sub limitele de 11 și 13 MB) și tot
ce ține de semnătura electronică. Ne-ați spus că instanța nu acceptă arhive ZIP, ci fișiere PDF cu
semnătura dumneavoastră pe fiecare. Rămâne deschisă întrebarea cum încap 8 anexe semnate individual
în 3 sloturi, la care avem nevoie de răspunsul dumneavoastră înainte să construim ceva.
