# Agenda ședinței de status LexRecovery

**Data**: 2026-07-28
**Participanți**: Alexandru Caciulan (produs, dezvoltare), Laurențiu (validare juridică, piață, promovare)
**Obiectiv**: aliniere pe stadiul aplicației, deblocarea celor 6 decizii care țin dezvoltarea pe loc și împărțirea clară a sarcinilor până la următoarea sincronizare.

**Regulă de desfășurare**: discuțiile care se lungesc peste subiectul lor se notează în secțiunea 6 (parcare) și se reiau separat, ca să nu rămână puncte neatinse la final.

---

## 0. Deschidere

- Confirmarea agendei și a ordinii punctelor.
- Stabilirea datei următoarei ședințe încă de la început.
- Cine notează deciziile (propunere: Alex, cu recapitulare la punctul 7).

---

## 1. Unde suntem: status aplicație · Alex

### 1.1 Ce este funcțional astăzi

Fluxul complet al procedurii ordonanței de plată este implementat cap la cap, în mediu de dezvoltare:

| Zonă | Stare |
| --- | --- |
| Cont, autentificare, verificare email, securitate | funcțional |
| Wizard dosar nou (5 pași) cu extracție automată din documente | funcțional |
| Extracție AI din facturi și contracte (poziții de creanță, agregare) | funcțional |
| Calcul dobândă (OG 13/2011), taxă de timbru (OUG 80/2013), instanță competentă | funcțional, validat parțial de tine |
| Verificare debitor la ANAF (activ, radiat, date de identificare) | funcțional |
| Arondare judecătorii pe localități (HG 1217/2023) | funcțional, 3184 de legături localitate-instanță |
| Generare somație de plată | funcțional |
| Generare cerere de ordonanță, opis, pachet ZIP de depunere | funcțional |
| Termene procedurale, alerte, pagina globală de termene | funcțional |
| Notificări în aplicație (clopoțel, listă, marcare citit, alerte pe dosar, termene, portal, plăți) | funcțional |
| Monitorizare dosar pe portal.just.ro | funcțional |
| Abonamente, schimbare de plan, facturare, plăți online | funcțional în mediu de test |
| Panou de administrare | funcțional |

Acoperire de testare automată: 2478 de teste, fără eșecuri.

### 1.2 Ce nu este încă disponibil în producție

Aplicația rulează pe calculatorul de dezvoltare și pe un mediu de demonstrație cu link stabil. Nu există încă instalare de producție. Diferența nu ține de funcționalitate, ci de infrastructură și de contractele cu furnizorii (procesator de plăți, facturare, găzduire).

### 1.3 Demonstrație scurtă (opțional)

Un dosar parcurs de la zero până la pachetul de depunere, ca să avem imagine comună înainte de discuția despre feedback.

---

## 2. Ce mai avem de făcut pe aplicație · Alex

Împărțit pe trei categorii, în ordinea în care blochează lansarea.

### 2.1 Blocante de lansare (nu se poate lansa fără ele)

1. **Instalarea pe server de producție**: găzduire, domeniu, certificat, copii de siguranță, sarcini automate zilnice (curs valutar, verificare termene, verificare portal). Estimare: 3 până la 5 zile de lucru.
2. **Activarea plăților reale**: contract cu procesatorul, credențiale de producție, testare pe tranzacție reală de valoare mică. Depinde și de documentele de la punctul 3.2.
3. **Facturare fiscală**: cont real la furnizorul de facturare, date de firmă, verificarea cu un consultant fiscal a regimului de TVA și a datelor obligatorii pe factură.
4. **Termeni și condiții, politică de confidențialitate, acord de prelucrare a datelor**. Astăzi există doar legături moarte în subsolul paginii. Vezi punctul 3.2, este sarcină comună.
5. **Test complet manual pe fluxul real**, cu un dosar adevărat, împreună.

### 2.2 Decizii de produs care blochează dezvoltarea

Acestea nu sunt sarcini de programare, sunt decizii pe care le luăm împreună. Detaliate la punctul 3.

### 2.3 Îmbunătățiri deja analizate, gata de implementat

Fiecare are documentul de analiză scris, se poate porni imediat ce prioritizăm:

- **Transmiterea documentelor către instanță**: analiză terminată, decizia de fond este la punctul 3.4.
- **Refacerea dashboard-ului** ca listă de lucru pe dosare, în locul raportului actual cu cifre duplicate.
- **Preferințe de notificare per utilizator** și alerta la schimbarea parolei. Restul subsistemului de notificări este livrat.
- **Cont de cabinet cu mai mulți utilizatori**: astăzi fiecare avocat are cont individual, dosarele nu se pot partaja în cadrul aceluiași cabinet.
- **Export termene în calendarul avocatului** (Google Calendar, Outlook): efort mic, valoare percepută mare la demonstrații.
- **Autentificare în doi pași**: obligatorie ca argument de vânzare către cabinete mari.

### 2.4 Cum ajunge feedbackul tău în aplicație

Propunere de proces, de validat:

1. Tu notezi observațiile într-un document comun, pe măsură ce testezi, cu captură de ecran și numărul dosarului de test.
2. Fiecare observație primește o etichetă: **eroare juridică**, **eroare de funcționare**, **lipsă funcționalitate**, **observație de formulare**.
3. Erorile juridice au prioritate absolută și se rezolvă în aceeași săptămână.
4. Recapitulare a listei la fiecare ședință de status.

De stabilit: unde ținem documentul comun și cât de des îl citim.

---

## 3. Decizii care se iau în această ședință · comun

Acestea sunt punctele care opresc efectiv lucrul. Fiecare are nevoie de un răspuns, chiar și provizoriu.

### 3.1 Două întrebări juridice deschise pe termene

**(a) Termenul de 6 luni de la comunicarea somației (art. 2540 Cod civil).**
Am implementat urmărirea lui: dacă cererea nu se depune în 6 luni de la comunicarea somației, întreruperea prescripției se consideră că nu a avut loc. Este cel mai periculos termen din aplicație, pentru că depășirea lui poate duce la pierderea integrală a creanței.
**Întrebare**: confirmi că cererea de ordonanță de plată satisface condiția de „chemare în judecată" din art. 2540? Argumentul sistematic pare solid (art. 1015 alin. 2 trimite expres la art. 2540), dar nu am găsit o sursă care să tranșeze punctual.

**(b) Cererea în anulare depusă de debitor versus cea a clientului nostru.**
Aplicația păstrează un singur termen de cerere în anulare per dosar, fără să rețină cine este titularul. Pe o ordonanță admisă parțial pot exista două termene distincte, cu titulari diferiți și date de comunicare diferite. Dacă închidem automat termenul când depune debitorul, ascundem termenul propriu al clientului nostru.
**Întrebare**: în practică, cât de des apare admiterea parțială care declanșează ambele drepturi? Merită complicația de a urmări titularul, sau este suficient un avertisment vizibil?

### 3.2 Documentele juridice ale platformei

Termeni și condiții, politică de confidențialitate, acord de prelucrare a datelor cu utilizatorul avocat. Aplicația procesează date personale ale debitorilor (inclusiv CNP din documentele încărcate) și trimite documente către un serviciu extern de inteligență artificială pentru extragerea datelor.

**Punctul sensibil**: secretul profesional al avocatului. Trebuie stabilit dacă și în ce condiții documentele pot pleca spre un procesator extern, ce se cere explicit clientului avocat și cum se documentează consimțământul.

**Întrebare**: le redactezi tu, le luăm ca șablon și le adaptăm, sau externalizăm?

### 3.3 Modelul de monetizare

Propunerea actuală: abonament lunar cu prag de dosare incluse, contorizarea făcându-se la trimiterea somației (momentul în care aplicația a produs valoare reală). Peste prag se facturează suplimentar.

**De decis**: numărul de trepte, prețul fiecăreia, ce include perioada de probă și dacă există variantă pentru cabinete cu mai mulți avocați. Are nevoie de datele tale din piață, punctul 4.4.

### 3.4 Cine depune documentele la instanță

Analiza este terminată: depunerea automată pe portalul instanțelor nu este fezabilă tehnic (protecții antibot, fără interfață de programare publică). Rămân două variante:

1. **Avocatul depune singur**, aplicația îl ghidează pas cu pas și îi pregătește pachetul complet. Fără risc de răspundere pentru platformă.
2. **Platforma transmite prin email** către instanță, în numele avocatului, cu împuternicire.

**Recomandarea mea**: varianta 1 pentru lansare. Este mai puțin spectaculoasă comercial, dar nu ne asumăm răspunderea pentru depunere.
**Întrebare**: ești de acord, și cum comunicăm asta către avocați fără să pară o limitare?

### 3.5 Un punct de citare pe care nu îl schimb fără tine

În analiza privind transmiterea documentelor există o discuție despre numerotarea articolelor din Codul de procedură civilă (art. 1016 versus 1017). Nu am modificat nimic în cod. Am nevoie de confirmarea ta directă.

### 3.6 Cont de cabinet cu mai mulți utilizatori

Astăzi un cont înseamnă un avocat. Un cabinet cu 4 avocați nu poate partaja dosarele.

**De ce se decide acum**: modificarea este mult mai ieftină înainte de a avea date reale în producție. După lansare devine o migrare riscantă.
**Întrebare pentru tine**: cabinetele pe care le cunoști ar cumpăra un abonament individual sau unul de cabinet? Răspunsul decide dacă facem această modificare înainte de lansare.

---

## 4. Ce îți revine ție · Laurențiu

### 4.1 Testarea și verificarea fluxurilor

- Parcurgerea completă a fluxului, de la crearea contului până la pachetul de depunere, cu un dosar realist.
- Verificarea documentelor generate: somația, cererea de ordonanță, opisul. Fiecare element obligatoriu, fiecare formulare.
- Verificarea calculelor pe cazuri concrete: dobândă, taxă de timbru, instanță competentă, termene.
- Verificarea textelor din interfață care conțin afirmații juridice (avertismente, explicații de termene, texte de confirmare).

**De stabilit**: câte dosare de test, până când, și dacă lucrezi pe mediul de demonstrație sau îți pregătesc un cont separat.

### 4.2 Centralizarea feedbackului

Un singur document, structurat conform punctului 2.4. Important este ca observațiile să ajungă într-un loc unic și să nu se piardă în conversații.

### 4.3 Pregătirea conținutului aplicației

Zone unde textul are nevoie de mâna unui avocat:

- Textele explicative din wizard (ce înseamnă fiecare pas, ce documente sunt necesare).
- Șabloanele de documente: verificare finală și eventuale variante alternative de formulare.
- Secțiunea de ajutor sau întrebări frecvente.
- Textele de avertizare juridică (unde aplicația estimează și unde afirmă cu certitudine).
- Materialul de prezentare pentru pagina publică: ce promitem și, mai important, ce nu promitem.

### 4.4 Prezentarea către avocați și colectarea feedbackului din piață

- **Lista țintă**: câți avocați, ce profil (cabinet individual, societate, specializare pe recuperare creanțe).
- **Materialul de prezentare**: de stabilit dacă demonstrație live pe link-ul stabil, prezentare, sau înregistrare video.
- **Întrebările de pus fiecăruia**: câte ordonanțe de plată gestionează lunar, cât timp le ia astăzi un dosar, ce ar plăti pentru a-l reduce, ce îi lipsește din ce a văzut.
- **Formă unitară de notare a răspunsurilor**, altfel nu putem compara.

**Legătura cu punctul 3.3**: fără aceste date nu putem fixa prețul.

### 4.5 Documente și date necesare

- Date de firmă pentru facturare (denumire, cod fiscal, cont bancar, regim TVA).
- Contract cu procesatorul de plăți: cine semnează, pe ce entitate.
- Modele reale de dosare (anonimizate) pentru testare pe date realiste.
- Eventuale relații utile: consultant fiscal, barouri, asociații profesionale.

### 4.6 Strategia de lansare

- **Momentul**: legat de disponibilitatea infrastructurii și de finalizarea documentelor juridice.
- **Formatul**: grup restrâns de avocați în perioadă de probă gratuită, sau lansare deschisă.
- **Canalele**: recomandare directă, barouri, evenimente profesionale, prezență online.
- **Pagina publică**: cine o scrie, cine o construiește, până când.
- **Ce măsurăm în prima lună** ca să știm dacă merge.

---

## 5. Cum lucrăm mai departe · comun

- **Ritmul ședințelor**: propunere de o dată la două săptămâni, cu aceeași structură.
- **Canalul pentru urgențe juridice**: când găsești o eroare de fond, cum ajunge la mine în aceeași zi.
- **Termenul-țintă de lansare**: îl fixăm după ce închidem punctul 3, chiar dacă provizoriu.
- **Documentul comun de feedback**: unde stă, cine îl actualizează.

---

## 6. Parcare (subiecte apărute în ședință, reluate separat)

> Se completează în timpul discuției.

---

## 7. Recapitulare finală

La final se citește cu voce tare, ca să nu rămână interpretări diferite:

| Decizie luată | Cine | Până când |
| --- | --- | --- |
| | | |

| Sarcină | Responsabil | Termen |
| --- | --- | --- |
| | | |

**Următoarea ședință**: data și ora, stabilite acum.

---

## Anexă: întrebările juridice deschise, pe scurt

De folosit ca temă între ședințe. Toate au nevoie de confirmarea ta înainte de a intra în cod.

1. Cererea de ordonanță de plată satisface „chemarea în judecată" din art. 2540 Cod civil? (termenul de 6 luni)
2. Cererea în anulare cu titulari diferiți pe admiterea parțială: urmărim titularul sau doar avertizăm?
3. Numerotarea art. 1016 versus 1017 CPC în analiza privind transmiterea documentelor.
4. Imputația plăților parțiale asupra dobânzii și principalului: regula de aplicat în calculator.
5. Creanțele în valută: momentul conversiei și cursul aplicabil în cererea depusă.
6. Prescripția de 10 ani (art. 2518 Cod civil): în ce situații se aplică și dacă o urmărim distinct.
7. Împuternicirea avocațială: o generăm noi sau rămâne integral în sarcina avocatului?
8. Cheltuielile de judecată (art. 453 CPC): ce se cere și cum se justifică în cerere.

---

## Lista scurtă a subiectelor propuse

Versiunea de trimis înainte de ședință.

**Status și dezvoltare (Alex)**

1. Unde suntem: ce este funcțional astăzi și ce lipsește pentru a putea lansa.
2. Ce mai avem de făcut: blocante de lansare, îmbunătățiri pregătite, ordinea lor.
3. Cum ajunge feedbackul tău în aplicație: proces și priorități.

**Decizii de luat împreună**

4. Două întrebări juridice deschise pe termene: termenul de 6 luni de la comunicarea somației și cererea în anulare cu titulari diferiți.
5. Termeni și condiții, politică de confidențialitate, secretul profesional la prelucrarea documentelor.
6. Modelul de monetizare: trepte, prețuri, perioadă de probă.
7. Cine depune documentele la instanță: avocatul, ghidat de aplicație, sau platforma.
8. Cont individual sau cont de cabinet cu mai mulți avocați.
9. Confirmarea unui punct de citare din Codul de procedură civilă.

**Validare și piață (Laurențiu)**

10. Testarea fluxurilor și verificarea documentelor generate.
11. Centralizarea feedbackului într-un document comun.
12. Pregătirea conținutului: texte din aplicație, ajutor, material de prezentare.
13. Prezentarea către avocați și colectarea feedbackului din piață.
14. Documente și date necesare: firmă, facturare, contract cu procesatorul de plăți.
15. Strategia de lansare: moment, format, canale, ce măsurăm.

**Închidere**

16. Ritmul de lucru, canalul pentru urgențe juridice, termenul-țintă de lansare.
17. Recapitularea deciziilor și a sarcinilor. Data următoarei ședințe.
