# Depunerea cererii de ordonanță de plată la instanță prin e-mail: cum funcționează

Data: 27.10.2026. Descrie ce este implementat acum în LexRecovery.

**Cum arată ecranele (machete):** <https://app.lexrecovery.ro/mockups/v2/depunere/>

| Utilizator | Parolă |
|---|---|
| avocat | LexDemo2026 |

Fiecare rând din pagina machetelor se deschide cu un clic: stările filei „Depunere la instanță”, pagina de trimitere, eșecurile, pagina adresei instanței și cardul „Fișiere de semnat”.

## 1. Pe scurt

Platforma trimite cererea de emitere a ordonanței de plată la instanța competentă prin e-mail, în numele avocatului.

| Cine | Ce face |
|-------------------|---------------------------------------------------------|
| **Avocatul** | Semnează electronic fișierele (cu semnătura lui calificată, în programul lui), verifică e-mailul înainte de trimitere, declară data depunerii |
| **Platforma** | Pregătește fișierele de semnat, verifică semnătura, trimite e-mailul, reîncearcă la erori temporare, urmărește confirmările și răspunsurile |
| **Administratorul platformei** | Ține lista adreselor de e-mail ale instanțelor: le adaugă, le confirmă, le scoate din uz |

Totul se face în fila **„Depunere la instanță”** a dosarului. Fila are 4 etape, afișate în partea de sus:

**Pachet → Semnare → Trimitere → Data depunerii**

La fiecare moment, fila arată o singură acțiune principală (ce urmează de făcut) și o explicație scurtă. Un punct colorat pe filă semnalează că se așteaptă ceva de la avocat: albastru pentru pasul următor, portocaliu pentru o decizie, roșu dacă cererea nu a ajuns la instanță.

Înainte de generarea cererii, fila explică pașii care vor urma. După plata integrală, la un dosar finalizat sau la o depunere făcută pe altă cale, fila spune de ce nu mai e nimic de depus.

## 2. Etapa 1: Pachetul (fișierele de semnat)

După generarea cererii, avocatul apasă **„Construiește pachetul”**. Platforma pregătește **trei fișiere PDF**:

| Fișier | Ce conține |
|-------------------|---------------------------------------------------------|
| Cererea | Cererea de ordonanță de plată, cu opisul la final |
| Anexele | Toate dovezile într-un singur PDF, cu paginile numerotate continuu: somația, dovada comunicării somației, contractul (dacă există), facturile, alte dovezi, dovada plății taxei de timbru (doar dacă taxa e achitată și dovada e încărcată). Pe prima pagină apare mențiunea că sunt copii conforme cu originalul |
| Împuternicirea avocațială | Fișier separat |

**Înainte de construire, platforma verifică:**

- cererea există;
- dovada comunicării somației este încărcată;
- împuternicirea este încărcată și are data completată;
- există cel puțin un înscris din care rezultă creanța (factură sau contract).

Dacă lipsește ceva, pachetul nu se construiește, iar fila spune ce lipsește și oferă butonul pentru completare.

**Dimensiunea.** Un e-mail către instanță are cel mult aproximativ 9,5 MB. Dacă anexele sunt mari, platforma le comprimă (local, fără servicii externe), iar dacă tot nu încap, le împarte în mai multe volume, trimise în e-mailuri separate. Toate acestea se fac **înainte** de semnare, pentru că un fișier semnat nu mai poate fi modificat.

**Pachetul se învechește** dacă după construire se schimbă ceva în dosar (de exemplu, se încarcă o anexă nouă sau se modifică datele împuternicirii). Fila arată motivul și butonul **„Reconstruiește pachetul”**.

## 3. Etapa 2: Semnarea

Avocatul:
1. descarcă fișierele nesemnate, unul câte unul sau toate odată (arhivă .zip);
2. le semnează cu semnătura electronică calificată, în programul pe care îl folosește;
3. încarcă înapoi fiecare fișier semnat, pe rândul lui din cardul „Fișiere de semnat” (alegere de fișier sau tragere în pagină).

**Cum recunoaște platforma fișierul semnat.** Programele de semnare diferă: unele adaugă semnătura la final, altele rescriu fișierul, altele adaugă o semnătură vizibilă peste text sau o pagină de semnătură. De aceea platforma compară conținutul, nu doar fișierul brut:

| Situație | Rezultat |
|--------------------------------------|--------------------------------------|
| Fișierul semnat este exact cel generat, plus semnătura | Acceptat |
| Fișierul a fost rescris de program, dar textul e același | Acceptat |
| Programul a adăugat text (semnătură vizibilă), pagini la final sau a pierdut identificatorul intern | Acceptat **doar după confirmarea avocatului**: platforma arată ce diferă (numărul paginii, câte cuvinte sau pagini), iar avocatul bifează că este documentul generat |
| Textul documentului e schimbat sau lipsește o parte din el | Refuzat: „Fișierul diferă de cel generat” |
| Fișierul nu are semnătură | Refuzat |
| Semnătura este deteriorată (fișierul a fost modificat după semnare) | Refuzat |

Platforma afirmă doar că o semnătură **a fost detectată**. Nu certifică faptul că este o semnătură calificată validă: verificarea aceasta aparține avocatului și instanței. Fila oferă link spre validatorul public al Comisiei Europene.

Dacă un fișier este refuzat, motivul rămâne afișat pe rândul lui (și după reîncărcarea paginii) până la următoarea încărcare. Când toate fișierele sunt acceptate, cardul se restrânge și etapa e bifată.

## 4. Etapa 3: Trimiterea

Avocatul apasă **„Verifică și trimite”** și ajunge pe o pagină separată, unde vede **e-mailul complet**, exact cum pleacă.

**Trimiterea este permisă doar dacă:**

- toate fișierele sunt semnate și acceptate, iar pachetul nu s-a învechit;
- instanța are o adresă de e-mail confirmată de administrator;
- data comunicării somației este completată, iar termenul de 15 zile din somație a expirat (zile libere, prelungit până la prima zi lucrătoare; în ultima zi a termenului trimiterea este încă blocată);
- contul avocatului e verificat, iar abonamentul e activ;
- cererea nu a fost deja depusă pe altă cale și nu există deja un dosar găsit pe portal pentru ea;
- instanța competentă, recalculată la trimitere, e aceeași cu cea din dosar și cu cea a adresei.

**Pe pagina de trimitere, avocatul bifează două declarații:**

- a verificat documentele și adresa instanței;
- cererea nu a fost depusă pe altă cale.

**Cum arată e-mailul:**

| Element | Valoare |
|-------------------|---------------------------------------------------------|
| De la | „Av. [Nume] prin LexRecovery”, de pe adresa platformei |
| Către | Adresa confirmată a instanței |
| Copie (CC) | Avocatul (fără avocat în copie, e-mailul nu pleacă) |
| Răspunsurile | Merg la avocat |
| Subiect | „Cerere de emitere a ordonanței de plată: [Creditor] contra [Debitor]”. La o persoană fizică, numele debitorului apare doar cu inițiale |
| Atașamente | Fișierele semnate |

Textul e-mailului (pe scurt):

- „Către [instanță],”;
- creditorul, cu sediul și CUI, „prin avocat [nume]”, transmite cererea de emitere a ordonanței de plată, semnată electronic de avocat, împreună cu documentele care o însoțesc;
- lista documentelor atașate;
- fraza despre taxa de timbru, după situația din dosar:
  - achitată: „Dovada achitării taxei judiciare de timbru este inclusă în anexe.”;
  - plătită prin registratura electronică: „Mențiunea privind taxa judiciară de timbru se regăsește în cerere (plată prin registratura electronică a instanțelor).”;
  - amânată: „Taxa judiciară de timbru va fi achitată prin registratura electronică după comunicarea numărului unic de dosar, dovada urmând a fi depusă la dosar.”;
- rugămintea de a confirma primirea și de a comunica numărul dosarului la adresa avocatului din copie;
- semnătura avocatului (nume, împuternicirea cu serie, număr și dată, telefon, e-mail);
- la final: „Mesaj transmis prin platforma LexRecovery în numele avocatului. Actele sunt semnate electronic de avocat; acest e-mail asigură doar transmiterea.”

**Mai multe e-mailuri.** Dacă anexele au fost împărțite, primul e-mail conține cererea și împuternicirea și anunță că anexele urmează. Celelalte pleacă în ordine, în același fir de discuție, cu „(partea k din n)” în subiect. Un e-mail pleacă doar după ce serverul instanței l-a acceptat pe cel dinainte.

**Când trece dosarul în „Cerere depusă”.** În momentul în care serverul instanței acceptă **primul** e-mail (cel cu cererea). Tot atunci aplicația închide urmărirea termenului de 6 luni pentru menținerea întreruperii prescripției (vezi mai jos). Data depunerii **nu** se completează automat (vezi etapa 4).

**Termenul de 6 luni (NCC art. 2540).** Curge de la comunicarea somației către debitor. Dacă în 6 luni cererea nu ajunge la instanță, somația nu mai întrerupe prescripția: prescripția se socotește ca și cum somația nu ar fi existat. Aplicația consideră termenul respectat când serverul instanței acceptă e-mailul cu cererea și îl redeschide dacă e-mailul este returnat definitiv. Aceasta este o simplificare: un e-mail acceptat de server, dar nepreluat de grefă, nu este semnalat (vezi întrebarea 3 din secțiunea 10).

## 5. Etapa 4: Confirmarea și data depunerii

**Confirmarea de primire.** Instanțele răspund de obicei cu o confirmare automată de primire. Platforma:

- o recunoaște și o leagă de dosar;
- o redirecționează avocatului, dacă nu a primit-o deja direct;
- propune data din confirmare ca dată a depunerii (doar o sugestie).

Confirmarea dovedește doar că mesajul a ajuns. Înregistrarea cererii la instanță se vede pe portal.just.ro.

**Data depunerii o declară avocatul**, în fila de depunere. Câmpul vine precompletat cu data propusă din confirmare, dar nu se salvează singur. Avocatul declară data pe care o consideră relevantă: data trimiterii, a confirmării automate și a înregistrării la grefă pot fi diferite, mai ales seara sau în weekend. Data declarată se păstrează în dosar; nu închide și nu modifică niciun termen în aplicație.

**Dacă nu vine confirmarea** în termen de o zi lucrătoare, avocatul primește o alertă și poate încărca manual confirmarea primită pe altă cale (fișier .eml, PDF sau imagine).

**Numărul de dosar** nu vine pe e-mail. Aplicația caută automat pe portal.just.ro dosarele noi ale instanței și propune avocatului potrivirea.

## 6. Ce se poate întâmpla pe parcurs

| Situație | Ce face platforma | Ce face avocatul |
|--------------------------|--------------------------|--------------------------|
| Eroare temporară a serverului instanței | Reîncearcă automat după 5 minute, 30 de minute, apoi 2 ore. Dacă tot nu merge, anunță avocatul | Poate trimite din nou sau depune pe altă cale |
| Adresa instanței nu există | Oprește trimiterea, nu reîncearcă. Raportează adresa administratorului. Dacă cererea fusese considerată depusă, dosarul revine la „Cerere generată” | Așteaptă corectarea adresei de către administrator sau depune pe altă cale |
| E-mailul este prea mare pentru instanță | Nu reîncearcă identic. Propune reîmpărțirea anexelor în volume mai mici | Semnează doar volumele noi; cererea și împuternicirea nu se mai semnează |
| Cererea a plecat, dar o anexă nu | Transmiterea rămâne deschisă | „Trimite anexele rămase” sau „Închide fără anexele rămase” |
| Rezultat incert (serverul platformei s-a oprit chiar în timpul trimiterii) | Nu retrimite singură, ca să nu ajungă două cereri la instanță | Verifică dacă a primit copia în CC și declară „Am primit” sau „Nu am primit” |
| Cererea a fost returnată după ce dosarul a avansat | Deschide o verificare | Confirmă că a verificat raportul de nelivrare |
| Instanța răspunde pe adresa platformei (un grefier, de exemplu) | Redirecționează imediat mesajul avocatului, cu mențiunea că adresa platformei nu este adresă de comunicare procedurală | Răspunde instanței de pe adresa proprie |
| Cererea nu a ajuns la instanță | Redeschide urmărirea termenului de 6 luni și arată, cu chenar roșu, avertismentul (text provizoriu): aplicația nu prelungește termenul | Retrimite după corectarea adresei sau depune pe altă cale, în termen |

Toate notificările apar în aplicație și pe e-mail.

## 7. Adresele de e-mail ale instanțelor

- **Doar administratorul decide** ce adresă se folosește la o instanță. O adresă confirmată servește depunerilor tuturor avocaților la acea instanță.
- Când administratorul adaugă o adresă, bifează declarația că a verificat-o pe o sursă oficială a instanței și, opțional, lasă link-ul paginii. Adresa intră direct confirmată.
- **Avocatul** poate, din pagina „Adresa instanței” a dosarului:
  - să **sugereze** o adresă: ajunge la administrator și nu se folosește până nu o confirmă;
  - să **raporteze** o adresă ca greșită: adresa rămâne în uz până la decizia administratorului.
- **Ce adresă se folosește:** la judecătorie, în ordine: registratura, secția civilă, secția pentru profesioniști. La tribunal: secția care judecă litigiile cu profesioniști.
- Confirmarea unei adrese **expiră după 180 de zile** (regulă internă a platformei, nu o cerință legală), după care trebuie confirmată din nou.
- Adresele nominale (de tipul prenume.nume@just.ro) nu se acceptă.
- **Lista inițială** de adrese o va trimite avocatul și va fi importată în aplicație. Portalul just.ro blochează preluarea automată.

## 8. Depunerea pe altă cale

Avocatul poate depune oricând cererea pe altă cale (registratură, poștă, registratura electronică). Pentru asta descarcă **„Pachetul pentru depunere pe alt canal”** (arhivă .zip cu toate documentele), îl depune, apoi declară depunerea în dosar, cu data și canalul. La declarare, dosarul trece în „Cerere depusă” și termenul de 6 luni se închide.

După o depunere declarată pe altă cale, trimiterea prin e-mail nu mai este posibilă (ca să nu ajungă două cereri la instanță).

## 9. Ce nu face platforma

- Nu certifică validitatea semnăturii calificate (doar detectează prezența ei).
- Nu stabilește singură data depunerii: o declară avocatul.
- Nu află numărul dosarului din e-mail: îl caută pe portal.
- Adresa platformei nu este adresă de comunicare procedurală: termenele nu curg de la mesajele primite acolo.
- Nu prelungește termenul de 6 luni pentru menținerea întreruperii prescripției.
- Nu trimite nimic către instanțe reale până la activarea explicită, la lansare. Până atunci, pe mediile de test, toate e-mailurile ajung într-o căsuță de test.

## 10. De clarificat cu avocatul

Doar punctele de care depinde funcționarea corectă la lansare.

| # | Întrebare | De ce contează |
|----|------------------------------------------------|------------------------|
| 1 | Instanțele primesc **cererea de ordonanță de plată pe e-mail** și o înregistrează? Există instanțe care nu acceptă acest canal? | Dacă o instanță nu acceptă, depunerea prin e-mail trebuie oprită pentru ea |
| 2 | **Taxa de timbru plătită după primirea numărului de dosar:** instanțele acceptă înregistrarea cererii netimbrate, cu plata ulterioară prin registratura electronică? Ce se întâmplă dacă nu o acceptă? | E-mailul anunță plata ulterioară; dacă instanța nu o acceptă, cererea poate fi anulată pentru netimbrare |
| 3 | **Ce dată contează** pentru termenul de 6 luni (NCC art. 2540) și pentru data depunerii: momentul trimiterii, confirmarea automată de primire sau înregistrarea la grefă? Ajunge acceptarea e-mailului de către serverul instanței, sau trebuie așteptată o dovadă a înregistrării? | Aplicația închide acum urmărirea termenului la acceptarea e-mailului de către server și propune ca dată a depunerii data confirmării automate |
| 4 | Sunt suficiente **cele 3 semnături** ale avocatului (cerere, anexe, împuternicire)? Împuternicirea trebuie semnată electronic și de client? | Dacă trebuie și semnătura clientului, fluxul de semnare se schimbă |
| 5 | **Copiile „conforme cu originalul”:** ajunge certificarea avocatului prin semnătura electronică pe volumul de anexe? Ce text acceptă instanțele pe prima pagină a anexelor? | Apare pe fiecare volum de anexe trimis |
| 6 | Este corect textul (provizoriu) despre **termenul de 6 luni**: „Aplicația nu prelungește termenul de 6 luni pentru menținerea întreruperii prescripției (NCC art. 2540). Dacă acest termen a trecut, somația nu mai întrerupe prescripția, iar cererea se poate depune în continuare cât timp prescripția nu s-a împlinit. Data de la care curge prescripția se verifică de avocat.” | Apare când cererea nu a ajuns la instanță |

**De verificat de avocat, fără urgență:**

- textul e-mailului din secțiunea 4, inclusiv cele trei variante pentru taxa de timbru;
- dacă știe instanțe cu o limită de mărime a e-mailului sub 10 MB (platforma folosește acum aproximativ 9,5 MB pe e-mail);
- durata minimă de păstrare a e-mailurilor trimise, a confirmărilor și a fișierelor semnate.

**De trimis de avocat:**

- **lista adreselor de e-mail ale instanțelor**: instanța, adresa, scopul (registratură, secția civilă, secția pentru profesioniști) și, dacă există, link-ul paginii sursă;
- câte un **fișier semnat real** din fiecare program de semnare folosit (certSIGN, Trans Sped, Adobe sau altul), ca recunoașterea semnăturii să fie testată pe fișiere reale.
