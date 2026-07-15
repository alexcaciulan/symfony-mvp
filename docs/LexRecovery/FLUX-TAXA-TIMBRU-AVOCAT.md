# Fluxul taxei judiciare de timbru

> Descrie ce face aplicația în privința taxei de timbru, în ce moment și pe ce temei legal. Data: 2026-07-15.

---

## 1. Principiul: platforma nu încasează banii

Aplicația nu colectează cei 200 lei și nu îi virează mai departe. Motivele: manipularea fondurilor clientului intră în regimul fiduciar din Statutul profesiei de avocat, iar colectarea de la plătitor cu transfer către bugetul local se apropie de prestarea de servicii de plată (autorizare BNR).

În locul colectării, platforma spune cine plătește, cât și unde, trimite plata către canalul oficial și cere dovada înapoi ca să o pună în pachetul depus la instanță.

---

## 2. Cine plătește și unde: determinarea primăriei

**Regula (OUG 80/2013 art. 40 alin. 1):** taxa se plătește de reclamant (creditorul), în contul „Taxe judiciare de timbru și alte taxe de timbru” al primăriei (UAT) unde acesta are sediul social. Nu contul instanței, nu al debitorului.

Aplicația determină automat primăria din datele de sediu ale creditorului (aduse de la ANAF la căutarea după CUI) și o afișează pe fișa dosarului, împreună cu denumirea contului bugetar. **Nu afișează niciun IBAN:** conturile de trezorerie nu au un registru oficial consolidat, iar plata în contul altei primării e tratată ca taxă neefectuată. Indicăm primăria corectă și trimitem verificarea contului la portalul oficial.

Cazuri speciale tratate:

- **Sediul într-un sat.** ANAF întoarce adesea satul, nu comuna (de exemplu „Sat Dancu Com. Holboca”). Taxa se plătește la comună, iar aplicația o extrage din adresă și indică primăria corectă.
- **Localitate care nu se potrivește niciunei primării.** Aplicația nu ghicește: afișează un avertisment și cere verificarea manuală. În acest mesaj este amintită și regula pentru reclamantul fără sediu în România, la care taxa se plătește la primăria de la sediul instanței (art. 40 alin. 2). Aplicația nu calculează automat această primărie; o lasă în seama avocatului.

---

## 3. Plata efectivă: canalul oficial

Pe fișa dosarului există un buton către registratura.rejust.ro (portalul CSM), unde taxa se achită cu cardul prin ghiseul.ro. Dovada generată de portal are aceeași valoare cu chitanța de la primărie (OUG 80/2013 art. 40 alin. 3) și, când depunerea se face tot prin portal, ajunge automat la instanță.

Portalul permite plata pentru terți: avocatul completează formularul, iar clientul primește un link și achită el însuși, deci plătitorul e reclamantul. Când dosarul are deja număr, butonul trimite direct la formularul de plată într-un dosar existent.

---

## 4. Încărcarea dovezii și avertismentul de plătitor

După plată, avocatul încarcă dovada în platformă (chitanță, ordin de plată vizat de bancă, sau dovada de la ghiseul.ro), cu data plății, suma, plătitorul de pe dovadă și numărul ordinului sau al chitanței.

Art. 40 alin. 3 prezumă plata dintr-un ordin semnat de debitorul taxei, adică reclamantul. Dacă pe dovadă apare alt plătitor decât creditorul, aplicația afișează un avertisment (nu blochează), fiindcă instanța poate cere lămuriri.

---

## 5. Momentul depunerii: blocaj, cu o singură excepție permisă

**Temeiul (CPC art. 197):** dovada achitării se atașează cererii, iar netimbrarea atrage anularea. Deci dovada în pachet e literă de lege.

Când avocatul apasă „Generează cererea OP”, aplicația verifică starea taxei:

- **Achitată (dovadă încărcată):** pachetul se generează, dovada intră în el.
- **Neachitată, fără alegere:** pachetul nu se generează. Butonul e blocat, cu două ieșiri oferite: „Încarcă dovada” sau „Timbrez la regularizare”.
- **Excepția „Depun fără dovadă, timbrez la regularizare”:** singurul caz în care pachetul se generează deși taxa nu e achitată. E permisă de lege (OUG 80/2013 art. 33 alin. 2: instanța pune în vedere timbrarea, cu termen de 10 zile de la comunicare, sub sancțiunea anulării). Avocatul o alege explicit, bifând asumarea riscului; alegerea se înregistrează în auditul dosarului, iar pachetul se generează.

Astfel, o depunere netimbrată e o alegere conștientă și documentată, nu o scăpare.

**Termenul de 10 zile.** Curge de la comunicarea instanței, pe care platforma nu o cunoaște. La primirea înștiințării, avocatul introduce data, iar aplicația calculează termenul (10 zile, prorogat la prima zi lucrătoare) și îl adaugă la termenele dosarului, cu prioritate critică. Până atunci, fișa arată că se așteaptă comunicarea.

---

## 6. Ce ajunge la instanță

**Dovada în pachet.** Dacă a fost încărcată, intră în ZIP-ul de depunere (numerotată alături de cerere, opis și somație) și e listată în opis. Dacă taxa a fost amânată la regularizare, dovada lipsește din pachet, corect, pentru că nu există încă.

**Cererea OP cere cheltuielile de judecată (CPC art. 453),** pe care instanța nu le acordă din oficiu. Petitum-ul cuprinde:

- **taxa de timbru**, cu formulare care urmează starea reală: dacă e achitată, „achitată conform dovezii anexate”; dacă e amânată, „urmând a fi achitată potrivit art. 33 alin. 2 din OUG 80/2013, cu depunerea dovezii la dosar”. Nu afirmăm o dovadă care nu există în pachet.
- **onorariul avocațial**, cu mențiunea „urmând a fi dovedit cu înscrisurile depuse la dosar” (art. 452 CPC).

---

## 7. Ce nu face platforma

- Nu încasează și nu virează taxa.
- Nu afirmă IBAN-uri de trezorerie.
- Nu depune electronic în numele avocatului: depunerea prin registratura.rejust.ro cere sesiunea și semnătura electronică calificată a avocatului. Platforma pregătește pachetul, avocatul depune.

---

## 8. Fluxul într-o singură privire

1. Creditor introdus (cu ANAF): aplicația știe primăria de plată.
2. Somația trimisă: apare pe fișa dosarului cardul „Taxa de timbru” cu suma, primăria și contul.
3. Avocatul plătește pe registratura.rejust.ro (sau clientul, prin link) și încarcă dovada. Sau alege „timbrez la regularizare”, asumat.
4. La „Generează cererea OP”: pachetul se produce doar dacă taxa e achitată sau amânarea a fost aleasă explicit.
5. Dovada intră în ZIP și în opis. Cererea cere cheltuielile de judecată (taxă + onorariu).
6. Dacă s-a amânat: la primirea înștiințării, avocatul introduce data, iar termenul de 10 zile apare între termenele dosarului.

---

## Temeiuri legale

| Aspect | Temei |
|---|---|
| Cuantum 200 lei fix | OUG 80/2013 art. 6 alin. 2 |
| Plată anticipată | OUG 80/2013 art. 33 alin. 1 |
| Dovada se atașează cererii; netimbrarea = anulare | CPC art. 197 |
| Regularizare: 10 zile de la comunicare, sub sancțiunea anulării | OUG 80/2013 art. 33 alin. 2 (trimitere la CPC art. 200 alin. 2 teza I) |
| Plătitor = reclamantul; primăria sediului său | OUG 80/2013 art. 40 alin. 1 |
| Reclamant fără sediu în România, primăria instanței | OUG 80/2013 art. 40 alin. 2 |
| Ce constituie dovadă; prezumția de plată | OUG 80/2013 art. 40 alin. 3 |
| Cheltuieli de judecată la cerere (nu din oficiu) | CPC art. 453 |
| Dovada întinderii cheltuielilor | CPC art. 452 |
| Restituire (taxă nedatorată), termen 1 an | OUG 80/2013 art. 45 |
