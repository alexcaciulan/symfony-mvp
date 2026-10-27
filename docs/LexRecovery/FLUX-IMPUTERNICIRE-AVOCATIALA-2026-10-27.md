# Împuternicirea avocațială în dosar: cum funcționează

Data: 27.10.2026. Descrie ce este implementat acum în LexRecovery.

## 1. Pe scurt

Cererea de ordonanță de plată se depune de avocat în numele creditorului, deci dosarul are nevoie de **împuternicirea avocațială**. Platforma nu generează împuternicirea și nu îi citește conținutul: avocatul o încarcă, împreună cu **seria, numărul și data** ei.

| Cine | Ce face |
|-------------------|---------------------------------------------------------|
| **Avocatul** | Încarcă împuternicirea (în mod normal una pentru fiecare dosar; platforma nu împiedică încărcarea mai multor exemplare), completează seria, numărul și data, le corectează dacă e nevoie |
| **Platforma** | Verifică dacă împuternicirea există și are dată, avertizează dacă data e după somație, o trece în opis, în pachetul de depunere și în e-mailul către instanță |

Împuternicirea este **obligatorie** pentru generarea cererii de ordonanță de plată și pentru tot ce urmează după ea (opis, pachet, trimitere).

## 2. Încărcarea

Avocatul încarcă împuternicirea din fila **„Documente”** a dosarului, cu „Adaugă document”, sau direct din butonul **„Încarcă împuternicirea”**, care apare acolo unde lipsa ei blochează ceva. Din acest buton, tipul documentului vine deja ales.

La încărcare se completează:

| Câmp | Regulă |
|-------------------|---------------------------------------------------------|
| Fișierul | PDF sau imagine, ca orice document |
| Seria | Opțională la încărcare, cel mult 20 de caractere |
| Numărul | Opțional la încărcare, cel mult 30 de caractere |
| **Data împuternicirii** | **Obligatorie**, nu poate fi în viitor |

Seria și numărul sunt opționale la încărcare, dar sunt cerute la **trimiterea prin e-mail** (vezi secțiunea 4).

În lista de documente, împuternicirea apare cu datele ei, de exemplu: „seria BU nr. 445566 din 10.01.2026”. O împuternicire mai veche, încărcată fără dată, poartă eticheta **„Data împuternicirii lipsește”**.

## 3. Ce verifică platforma

Verificările rulează la generarea cererii, la construirea pachetului de depunere prin e-mail și la descărcarea arhivei .zip pentru depunere pe alt canal (aceasta doar cât dosarul este în „Cerere generată”; după depunere arhiva se descarcă fără verificări).

| Situație | Rezultat | Ce vede avocatul |
|-------------------|-------------------|--------------------------------------|
| Dosarul nu are împuternicire | **Blocat** | „Lipsește împuternicirea avocațială”, cu butonul „Încarcă împuternicirea” |
| Împuternicirea nu are data completată | **Blocat** (data apare în opis) | „Împuternicirea nu are data completată”, cu butonul „Completează datele împuternicirii” |
| Mai sunt și alte exemplare încărcate, unul fără dată | Doar avertisment | „Un alt exemplar al împuternicirii nu are data completată. Opisul îl listează fără dată.” |
| Data împuternicirii este după data generării somației | Doar avertisment | „Data împuternicirii (...) este ulterioară datei generării somației (...). Dacă somația a fost semnată de avocat, se verifică existența mandatului la data semnării.” |
| Lipsește seria sau numărul | Blocat doar la **trimiterea prin e-mail** | „Seria, numărul sau data împuternicirii avocațiale lipsesc.” |

**Despre compararea cu somația.** Platforma cunoaște doar ziua în care a generat somația, nu și ziua în care avocatul a semnat-o sau a trimis-o. De aceea compară data împuternicirii cu data generării somației. O împuternicire din aceeași zi nu produce avertisment. Dacă somația se generează din nou, comparația se reface automat.

**Mai multe exemplare.** Dacă dosarul are mai multe împuterniciri încărcate:

- pachetul de depunere prin e-mail și semnătura e-mailului folosesc **cea mai recent încărcată care are dată**;
- opisul le listează pe toate (cele fără dată, „fără dată”);
- arhiva .zip pentru depunere pe alt canal le conține pe toate (`05_imputernicire_avocatiala`, `05_imputernicire_avocatiala_2` și așa mai departe).

Recomandarea rămâne un singur exemplar pe dosar, ca la instanță să ajungă doar împuternicirea valabilă.

## 4. Unde apare împuternicirea

| Unde | Cum apare |
|--------------------------|----------------------------------------------------|
| **Opisul** (lista documentelor) | „Împuternicire avocațială seria B nr. 123456 din 05.01.2026” (fără serie sau număr, doar ce e completat) |
| **Pachetul de depunere prin e-mail** | Fișier separat, semnat electronic de avocat. Pleacă în **primul** e-mail, împreună cu cererea |
| **E-mailul către instanță** | În semnătura avocatului: „Împuternicire avocațială seria ... nr. ... din ...” |
| **Arhiva pentru depunere pe alt canal** (.zip) | Fișier separat în arhivă (`05_imputernicire_avocatiala`); opisul din arhivă o listează |
| **Fila „Documente”** | În lista de documente, cu datele ei; în cardul arhivei, rândul „Împuternicirea avocațială” e bifat sau marcat cu „!” |

## 5. Modificarea datelor

Seria, numărul și data se pot corecta cu **„Completează datele împuternicirii”** sau **„Modifică datele împuternicirii”** (fereastra „Datele împuternicirii”).

**Când se pot modifica:** cât timp cererea nu a fost depusă, adică dosarul este în etapa „Amiabil”, „Somație trimisă” sau „Cerere generată”, cu condiția ca:

- să nu fie înregistrată plata integrală;
- să nu fie în curs o transmitere prin e-mail.

**După depunerea cererii** datele nu se mai modifică: instanța are opisul cu datele de atunci, iar platforma trebuie să descrie același document. O cerere de modificare trimisă direct este refuzată: „Datele împuternicirii nu se mai modifică după depunerea cererii sau după închiderea dosarului.”

**Ce se întâmplă după o modificare:**

- **opisul** se regenerează cu datele noi la următoarea descărcare a pachetului;
- **pachetul de depunere prin e-mail**, dacă era deja construit, devine învechit („Împuternicirea avocațială (fișierul, seria, numărul sau data) s-a schimbat după construirea pachetului”) și trebuie reconstruit și semnat din nou;
- dacă noua dată este după somație, apare același avertisment ca la încărcare;
- modificarea rămâne în **jurnalul de audit**, cu valorile vechi și noi.

## 6. Ce nu face platforma

- Nu generează împuternicirea și nu îi citește conținutul: seria, numărul și data sunt cele declarate de avocat.
- Nu verifică ce acoperă mandatul (procedura, debitorul) și nici dacă este pe numele avocatului care semnează pachetul: acestea rămân în răspunderea avocatului.
- Nu verifică semnătura clientului pe împuternicire și nici dacă împuternicirea e înregistrată la barou.
- Nu cunoaște ziua în care somația a fost semnată sau trimisă: compară doar cu ziua generării ei.
- Nu blochează o împuternicire datată după somație: doar avertizează.

## 7. De clarificat cu avocatul

Doar punctele de care depinde funcționarea corectă.

| # | Întrebare | De ce contează |
|----|------------------------------------------------|------------------------|
| 1 | Data împuternicirii trebuie să fie cel mult ziua semnării somației. Aplicația cunoaște însă doar **ziua generării** somației, nu și ziua semnării, și acum doar **avertizează** când data împuternicirii este după ea. Ajunge avertismentul, sau aplicația trebuie să **blocheze** generarea cererii (de exemplu, cel puțin când somația se comunică prin executorul LexRecovery)? Ar ajuta ca avocatul să declare ziua semnării somației? | O împuternicire datată după somație creează un risc de contestare a valabilității somației |
| 2 | Copia împuternicirii depusă la instanță trebuie să poarte, pe lângă semnătura clientului, și **mențiunea de conformitate cu originalul** semnată de avocat? Dacă da, ajunge semnătura electronică a avocatului pe fișierul împuternicirii din pachet? | Stabilește ce trebuie să conțină fișierul de semnat |
| 3 | Să cerem **numele avocatului mandatat** (sau să verificăm că împuternicirea e pe numele avocatului care semnează)? Și să cerem **seria și numărul** încă de la încărcare, nu doar la trimiterea prin e-mail? | Acum aplicația nu leagă împuternicirea de avocatul care semnează, iar seria și numărul pot lipsi până la trimitere |
