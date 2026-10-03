# Feedback avocat pe dosarele 2-6 (testare demo, 12.08.2026): ce s-a făcut și ce rămâne de decis

Data: 02.10.2026. Branch: `feature/feedback-avocat-dosare-2-6` (necomis).

Sursa: `docs/LexRecovery/260812_analiza dosare/260812_Documentare dosare 2 - 6.docx` și documentele din folderele 2-6.

Metodă: fiecare dosar a fost reîncărcat pe stack-ul de dev cu documentele avocatului și parcurs prin wizard (Pașii 0-4), cu extracție AI reală. Am verificat fiecare observație pe codul de azi: o parte fuseseră rezolvate între timp, restul au fost implementate, testate automat și verificate live în browser.

## 1. Sinteză pe dosare

| Dosar | Observația avocatului | Stare |
|---|---|---|
| 2 | Dobânda „Nedeterminată” la Pasul 3 și absentă la Pasul 4 | **Rezolvat.** Cauza: tabela de rate BNR începea abia la 08.08.2022. Am adăugat ratele 2011-2022 din circularele BNR (Monitorul Oficial). Factura din 2022 are acum dobândă (17.723,62 lei, 9 perioade). |
| 2 | Calculul dobânzii vizibil înainte de înregistrare | **Rezolvat.** La Pasul 3, fiecare poziție are „Cum s-a calculat dobânda”: perioadă, rată BNR, rată aplicată, zile, dobândă și formula cu temeiul (OG 13/2011, art. 3 alin. 2¹). |
| 2 | Tranzacția: a reținut principalul și dobânda până la o dată, fără dobânda penalizatoare ulterioară | **De decis** (§3.1). Acum extracția înțelege tranzacția, iar sistemul pune alegerea: factura (27.340 lei, scadentă 07.04.2022) sau tranzacția (31.268,72 lei, prima rată 31.05.2023). |
| 2 | Prescripția identificată | Păstrat. Avertismentul nu știe încă de tranzacție (§3.1). |
| 3 | Administrator din contract „contrazis” de emitentul facturii | **Rezolvat.** Factura nu mai contrazice contractul la reprezentant sau administrator: contractul îl numește, factura doar pe cine a întocmit-o. |
| 3 | J nou și J vechi puse în dezbatere | **Rezolvat anterior** (forma nouă ≡ forma clasică). Nu mai apare. |
| 3 | Datele nu sunt corelate cu ANAF (mfinante) | **Rezolvat parțial, conform deciziei tale din 01.10** (extracția nu apelează ANAF). Butonul „Sincronizează info ANAF” închide acum automat divergențele de denumire, adresă, județ și localitate ale părții sincronizate (§2.2). |
| 3 | Creanța 371.712,60 în loc de 169.251,60; fișa de cont neinterpretată | **Parțial.** Totalul extras e acum 202.461,00 (cele 6 facturi). Plata parțială pe 23381 (rest 456,60) nu se aplică singură; dacă o corectezi în tabel, totalul devine exact 169.251,60. **De decis** (§3.2). |
| 3 | Calculul live nu se actualiza la editarea sumelor | **Rezolvat anterior.** Verificat live. |
| 3 | Plăți făcute după termen: penalități pe perioada întârzierii | **De decis** (§3.3). |
| 3 | „Content missing” la Pasul 4 | **Rezolvat anterior.** Nu se mai reproduce. |
| 3 și 6 | „Formatul codului IBAN” | **De clarificat** (§3.6). |
| 4 | Debitor persoană fizică (B2C) acceptat, cu dobândă între profesioniști | **Rezolvat.** La Pasul 0, un debitor persoană fizică fără cod fiscal oprește dosarul cu mesaj explicit; „Continuă cu datele extrase” se dezactivează. Un PFA cu CUI trece. |
| 4 | Mențiunea BIPF trebuie modificată când vine B2C | Notat pentru momentul B2C. Nimic de făcut acum. |
| 5 | Adresa debitorului: documente diferite, ar trebui corelate cu ANAF | **Rezolvat** prin sincronizarea ANAF (§2.2). |
| 5 | Temei juridic: contract vs factură acceptată | **Rezolvat.** Cu contract la dosar, factura nu mai e prezentată ca temei concurent. |
| 5 | „Între profesioniști · BNR + 8 pp” afișat la penalități contractuale | **Rezolvat** la Pasul 3 (card, notă din sidebar) și în antetul dosarului („0,10% pe zi, plafonat la 10% din sumă”). |
| 5 | Clauza 10.3: penalitățile nu pot depăși 10% din valoarea prestației | **Rezolvat.** Câmp nou „Plafon penalități (% din sumă)”, citit automat de AI din contract (10% la acest dosar), aplicat pe fiecare factură în wizard, overview, somație și cerere. Rezultat: 2.131,89 lei în loc de 12.535,51. |
| 5 | Nu pot modifica penalitățile sau data până la care se calculează | **De decis** (§3.4). |
| 5 | Pasul 4: „Apelează ANAF” fără posibilitate din acel ecran | **Rezolvat.** Pasul 4 verifică singur statusul fiscal al debitorului PJ nesincronizat (doar statusul; datele rămân cele confirmate). Dacă ANAF nu răspunde, avertismentul rămâne. |
| 6 | Adresa creditorului (birou E8-05) și a debitorului | **Rezolvat** prin sincronizarea ANAF (§2.2). |
| 6 | IBAN și bancă alese separat; contul ING ignorat | **Rezolvat parțial.** Banca nu mai e o întrebare separată: rezultă din IBAN (afișată lângă fiecare cont) și se corectează automat la trimitere dacă nu corespunde contului. Extracția citește în continuare un singur IBAN per document (§3.5). |
| 6 | IBAN editat, dar trimis înapoi la ecranul de divergențe | Nu se mai reproduce: am modificat IBAN-ul manual (contul ING) și pasul a trecut. |
| 6 | Factura de avans, factura finală tratată ca storno, fișa ca poziție | **Rezolvat.** Factura finală cu rândul „Storno avans” e factură, nu stornare. Avansul pe care ea îl stornează e exclus automat, cu explicație (se poate reinclude dacă nu a fost plătit). Creanța: 416.855,99 lei, corect. Fișa nu mai produce poziție. |
| 6 | Penalități de 1% pe zi: 3,85 mil. lei pe 416.855,99 | **De decis** (§3.7). |
| General | Un document încărcat greșit nu putea fi șters | **Rezolvat.** Buton „Elimină” pe fiecare document la Pasul 0, cu confirmare. |
| Portal 1 | Căutare doar după debitor; „S.R.L.” rupea potrivirea | **Rezolvat.** Verificat direct pe portal: „HEALTHU WORLDWIDE S.R.L.” dă 0 dosare, „HEALTHU WORLDWIDE” dă 9. Căutarea trimite acum numele fără formă juridică, punctuație și diacritice. Dacă printre dosarele debitorului există unele cu creditorul nostru, doar acelea se afișează. |
| Portal 2 | Căutare automată după depunere, avocatul confirmă și activează | **Implementat.** Comandă zilnică nouă `app:portal-discover-cases`: caută dosarele depuse fără număr și trimite notificarea „Dosar găsit pe portal”. Activarea rămâne la avocat. Taxa de timbru după activare: **de decis** (§3.8). |
| Portal 3 | Căutarea să se oprească până la data termenului | **De decis** (§3.9). |

## 2. Ce s-a schimbat în aplicație

### 2.1 Calcule
- Rate BNR 01.09.2011-07.08.2022 (33 de rânduri, fiecare verificat pe circulara din Monitorul Oficial). Migrare `Version20261002120000` (idempotentă) + fixture. Două date diferă de ce se credea: 2,00% de la 23.03.2020 (nu 20.03) și 2,50% de la 10.02.2022 (nu 09.02).
- Gardă nouă, cerută de review-ul juridic: marja de 8 puncte procentuale între profesioniști vine din Legea 72/2013 (în vigoare din 05.04.2013, doar pentru contractele încheiate după). Pentru o scadență anterioară, dobânda rămâne „Nedeterminată”, cu mesaj care cere calcul manual, în loc de o cifră cu marja greșită. Vezi și §3.11.
- Plafonul penalităților contractuale: coloana `legal_case.contractual_penalty_cap_percent` (migrare `Version20261002111954`), câmp în Pasul 3, extras din contract, aplicat în calculator, agregator, competență, overview, somație și cerere.
- Breakdown dobândă pe poziție la Pasul 3, cu rata BNR, zilele și formula.

### 2.2 Panoul „Documentele nu spun același lucru”
- Aceeași valoare scrisă diferit nu mai e divergență: adrese („str. Bihorului, nr.10” = „Str. Bihorului, Nr. 10, C.P. 400295”), localități cu sau fără diacritice („Grumazești” = „Grumăzești”), persoane („Marchis Bogdan” = „Bogdan Marius Marchiș”), IBAN cu spații. Rămân divergențe adevărate: alt număr („24” vs „24A”), alt apartament, alt sector.
- Banca nu mai e întrebare separată; e o etichetă a contului.
- Factura nu contrazice contractul la temei juridic și la reprezentant.
- Sincronizarea ANAF a unei părți închide imediat (fără reîncărcare) divergențele de denumire, adresă, județ și localitate ale acelei părți. „Anulează” le readuce.
- Efect măsurat: Dosarul 3 de la 9 divergențe la 1 (contul IBAN, care e o alegere reală).

### 2.3 Wizard
- Pasul 0: refuz consumator (persoană fizică fără cod fiscal); buton „Elimină” document.
- Pasul 1: banca urmează IBAN-ul.
- Pasul 3: avans/factură finală; eticheta raportului juridic și nota din sidebar țin cont de penalitatea contractuală.
- Pasul 4: verificare automată a statusului ANAF al debitorului.

### 2.4 Portal
- Termen de căutare normalizat; filtrare după creditor; descoperire automată zilnică cu notificare.
- **Ops la deploy:** de adăugat în Coolify jobul `php bin/console app:portal-discover-cases` (propunere: zilnic la 09:00, după `app:portal-check-all`).

### 2.5 Teste
- Teste noi: echivalențe de valori (20, cu valorile reale din dosare), agregator (6), cod bancar (3), rate BNR pe DB (3), plafon (4), avans/factură finală (4), refuz consumator (2), sincronizare ANAF (1), eliminare document (2), verificare ANAF la Pasul 4 (2), bancă după IBAN (2), portal (2), descoperire automată (2), somație cu plafon (1).
- O dublură offline pentru ANAF în mediul de test (`config/services_test.yaml`), ca suita să nu apeleze ANAF real.
- Suita completă: 3050+ teste verzi la ultima rulare intermediară.

### 2.6 Găsite la verificarea live finală (02.10.2026, după prima rundă de review)
Toate punctele de mai jos au fost reproduse în browser, corectate, retestate live și acoperite de teste.
- **Somația cu plafon se contrazicea:** rândul facturii arăta penalitatea neplafonată (12.535,51), iar totalul pe cea plafonată (2.131,89). Acum rândul arată 2.131,89, iar sub tabel apare nota „Penalitățile sunt plafonate, conform contractului, la 10% din valoarea fiecărei facturi.”
- **Portalul nu recunoștea dosarele de ordonanță de plată:** ECRIS le înregistrează cu obiectul „somaţie de plată” (de exemplu dosarul real 24697/211/2023, Expert Service Supply vs HealthU), iar căutarea recunoștea doar „ordonanță de plată”. Fără corectură, căutarea automată nu ar fi anunțat niciodată nimic. Acum găsește dosarul și trimite notificarea, o singură dată. „Anulare somaţie de plată” e exclus.
- **Pagina dosarului numea „Dobândă · BNR” o penalitate contractuală** și oferea un breakdown pe perioade BNR (secțiunea „Compoziție creanță”). Acum folosește denumirea din contract, rata zilnică și formula clauzei.
- **Descrierea creanței** („Contravaloare 2 facturi neachitate: …”) includea și avansul exclus. Acum numără doar pozițiile cerute.
- **Pasul 4 avertiza „scăzământ”** la factura finală care stornează avansul. Avertismentul nu mai apare în acest caz.
- **„Nedeterminată” fără motiv:** motivul apare acum sub sumă (de exemplu, garda Legii 72/2013). Mesajul vechi pentru rata lipsă („Verifică InterestRateConfigFixtures”) era scris pentru programator; e acum scris pentru avocat.
- **Mesajele de după „Elimină”, „Corectează tipul” și „Reîncearcă” la Pasul 0 nu apăreau** (se pierdeau în afara frame-ului). Apar acum în listă.

Verificate live, fără probleme: banca derivată din IBAN (ING), statusul ANAF verificat automat la Pasul 4 (debitor „ACTIV” pe pagina dosarului), salvarea Dosarelor 5 și 6, notificarea în clopoțel și trimiterea la tab-ul Portal.

Nu am generat live cererea de ordonanță de plată (cere data comunicării somației și curgerea termenului); calculul ei folosește același agregator cu plafon, acoperit de teste.

### 2.7 A doua tură (02.10.2026): puncte din §3 cu soluție clară, implementate
- **§3.7, penalități vădit excesive:** avertisment la Pasul 4 când penalitățile contractuale depășesc principalul, cu sumele și cu trimitere la C. civ. art. 1541 alin. 1 lit. b și CPC art. 1021 alin. 2. Suma rămâne cea din clauză; decizia e a avocatului. Verificat live la Dosarul 6 (3.847.580,79 lei față de 416.855,99 lei).
- **§3.11, contracte dinainte de 05.04.2013:** când data contractului e cunoscută și e anterioară, dobânda cu marja de +8 pp nu se mai calculează (mesaj: „calculează dobânda manual”). Totodată, instanța competentă se stabilește acum și când dobânda nu poate fi calculată (competența depinde doar de principal).
- **§3.5, mai multe conturi:** extracția întoarce toate conturile creditorului de pe fiecare document. La Pasul 1, sub IBAN, apar toate conturile găsite, fiecare cu banca lui, și un buton „Folosește” care completează contul și banca împreună. Verificat live: la Dosarul 6 apar 4 conturi (Trezoreria sectorului 1, Trezoreria Ilfov, Banca Transilvania, ING).
- **§3.4 A, data până la care se calculează:** câmp opțional „Accesorii calculate până la” la Pasul 3, salvat pe dosar și aplicat în wizard, în pagina dosarului, în somație și în cerere. Somația păstrează data ei, iar textul „calculate până la data de …” folosește data aleasă. Verificat live: până la 02.04.2024, penalitatea Dosarului 6 e 41.685,60 lei.
- **Din review-ul tehnic:** lista de câmpuri închise de ANAF nu mai e duplicată în JS; vine din constanta PHP.
- **Din review-ul juridic al turei a doua:**
  - Avertismentul pentru penalitate excesivă spune acum explicit că depășirea principalului nu e un prag legal, iar criteriul din art. 1541 e prejudiciul previzibil la încheierea contractului.
  - O dobândă pe care calculul o refuză (contract dinainte de 2013, rată BNR lipsă) apare acum ca avertisment la Pasul 4, cu motivul. Somația și cererea nu mai pot pleca fără ea fără ca avocatul să știe.
  - Data-limită trebuie să fie după scadență și nu în viitor.
- Review final: juridic `LEGAL-CLEAN`, tehnic `COMMIT-READY`. Rămâne o sugestie de structură: mutarea a două funcții din controller în servicii.

### 2.8 Reverificare live Dosarul 2 (03.10.2026)
Am creat dosare noi cu documentele Dosarului 2 (#86 de către utilizator, #87 cap-coadă până la somație). Feedback-ul e adresat. Am găsit și reparat trei bug-uri:
- **Totalul de la Pasul 0 aduna dublura:** când tranzacția era clasificată „confirmare de sold”, cardul arăta „Total 2 facturi 58.608,72” (factura + tranzacția). Acum numără doar pozițiile care rămân în creanță și marchează suma „de ales la pasul 3” când documentele dau sume diferite pentru aceeași factură.
- **Biblioteca de creditori întreba de diacritice:** „Paval Marco Gabriel” față de „Pavăl Marco – Gabriel”. Comparația cu biblioteca (creditori și debitori) folosește acum aceeași regulă de echivalență ca panoul de divergențe, extinsă la denumirea firmei („SC … S.R.L.” = „… SRL”) și la bancă („UNICREDIT BANK SA” = „UniCredit Bank”).
- **„Cauză / contract: 453”:** AI-ul punea numărul somației anterioare drept număr de contract. Promptul interzice acum explicit numărul unei somații, notificări sau facturi în acest câmp. Verificat la re-extracție.

### 2.9 Reverificare live Dosarul 3 (03.10.2026, dosarul #91)
- **Reprodusă și reparată eroarea raportată de avocat (371.712,60 în loc de 169.251,60):** când fișa de cont e clasificată „confirmare de sold”, soldul ei devenea a 7-a poziție, adunată peste cele 6 facturi pe care le rezumă. Acum o confirmare de sold fără număr propriu, care pomenește facturi aflate deja în dosar, e exclusă implicit, cu explicație. Dacă soldul ei e mai mic decât totalul facturilor, poziția semnalează că diferența sunt plăți încasate, de imputat de avocat. Ieri bug-ul nu apăruse, pentru că fișa fusese clasificată „alt document”.
- Flux complet verificat: ANAF închide divergențele ambelor părți (sediul real al creditorului: Târgu Neamț, Str. Cuza Vodă 100A), cele 3 conturi apar cu banca lor, penalitatea 0,02%/zi e preluată din contract, iar după corectarea facturii 23381 la 456,60 principalul e exact 169.251,60. Instanța e Judecătoria Buftea. Somația e coerentă.
- Text corectat pe pagina dosarului: nota de sub tabelul de poziții spunea „Dobânda se calculează…” și la penalitățile contractuale.
- Rămân deschise, conform documentului de decizii: plățile din fișă aplicate automat (punctul 9), penalitățile pentru facturile plătite cu întârziere (punctul 2), scadența din contract (punctul 3).

### 2.10 Reverificare live Dosarul 4 (03.10.2026)
- Factura avocatului (debitor ALINA BIANCA BALAN, persoană fizică) oprește dosarul la Pasul 0: apare mesajul despre debitorul consumator, iar „Continuă cu datele extrase” e dezactivat.
- Blocarea nu poate fi ocolită prin adresă: la Pasul 2 debitorul e forțat ca PJ, cu mesajul că persoanele fizice neautorizate nu sunt acceptate, iar pasul cere CUI și număr de înregistrare.
- Corectat: cardul de la Pasul 0 spunea totuși „De completat la pasul 2: CUI, Reg. Comerțului” pentru acest debitor. Nu mai apare.

### 2.11 Reverificare live Dosarul 5 (03.10.2026, dosarul #92)
Contractul și factura sunt clasificate corect; AI-ul citește plafonul de 10% și penalitatea de 0,1% pe zi. Nicio divergență la creditor; la debitor, sincronizarea ANAF închide divergența de adresă. Pasul 3 arată „Între profesioniști · penalități din contract” și 2.131,89 lei „Plafonat conform contractului”; data „Accesorii calculate până la” 23.03.2025 dă 639,57 lei. Pasul 4 fără avertismente false: 21.318,90 + 2.131,89 = 23.450,79 lei, Judecătoria Sectorului 3 București. Somația are rândul plafonat, nota de plafon și totaluri concordante. Constatat nou: contractul spune că penalitatea curge „din prima zi lucrătoare după scadență”, platforma pornește din ziua următoare scadenței (punctul 7b din documentul de decizii).

### 2.12 Reverificare live Dosarul 6 (03.10.2026, dosarul #93)
Contractul, cele două facturi și fișa sunt clasificate corect (fișa ca extras de cont, fără poziție proprie). Avansul BMI2022730 e exclus automat, cu explicație; fișa confirmă că a fost încasat pe 04.12.2023. Pasul 1 oferă toate cele 4 conturi ale creditorului (două la Trezorerie, Transilvania, ING); am ales ING și contul a rămas până în somație. Sincronizarea ANAF închide divergențele de adresă la creditor (biroul E8-05) și la debitor (sediul actual: Str. Răscoalei 46, Pantelimon, Ilfov, diferit de ambele documente). Pasul 4 afișează avertismentul de penalități excesive (3.851.749,35 lei față de 416.855,99 lei); instanța e Tribunalul Ilfov (principal peste 200.000 lei). Somația: 924 de zile × 1% × 416.855,99 = 3.851.749,35 lei, plata în contul ING.

Corectate la reverificare:
- Cardul de la Pasul 0 arăta suma avansului exclus (73.555,85) în loc de factura finală. Acum arată „De cerut: 1 factură 416.855,99 RON” când o altă factură a fost exclusă. Aceeași factură citită din două documente rămâne ca înainte, cu divergența ei.
- Ecranul de comparare cu biblioteca întreba de bancă pentru „ING Bank” față de „ING BANK ROMANIA”. Numele țării nu mai contează la comparare.
- Somația scria „titular BLUEBOX MEDICAL S.R.L..” (punct dublu). Punctul final al denumirii nu se mai dublează.

Observație: avertismentul „Extrasul de cont conține încasări care nu au putut fi legate” apare și aici, deși fișa leagă fiecare încasare de factura ei (niciuna de factura finală). AI-ul rezumă fișa fără lista plăților, deci aplicația nu poate verifica. Ține de punctul 9 (plățile din fișă).

### 2.13 Detalierea penalităților contractuale (03.10.2026)
Detalierea pe poziție adăugată la Dosarul 2 exista doar pentru dobânda legală. Acum și penalitățile din contract au tabelul „Cum s-au calculat penalitățile” (perioadă, sumă, rată pe zi, zile, penalități calculate și, când se aplică, linia de plafon) la Pasul 3 (pe fiecare factură și în panoul live la completarea manuală) și pe pagina dosarului. Procentul plafonului se scrie ca în contract (10%, 7,5%), inclusiv în somație, care înainte rotunjea 7,5% la 8%. La generarea somației, totalul accesoriilor din dosar se recalculează la data somației (cu mențiune în audit), iar pagina dosarului calculează la aceeași dată, ca antetul, detalierea și somația să arate aceeași sumă.

## 3. De decis cu tine

Pentru fiecare punct: întrebarea, opțiunile și recomandarea mea.

### 3.1 Dosarul 2: cum se cere o creanță recunoscută prin tranzacție
*Constatat la reverificare:* clasificarea tranzacției variază de la o rulare la alta („contract” sau „confirmare de sold”). În primul caz tranzacția nu devine poziție și nu se pune nicio întrebare; în al doilea, avocatul alege între factură și tranzacție. În ambele cazuri, somația numește „Contractul încheiat la 16.05.2023” (data tranzacției), iar cauza poziției devine tranzacția, deși factura vine din contractul de vânzare-cumpărare. O regulă clară pentru tranzacții le-ar elimina pe toate.
Tranzacția din 16.05.2023 recunoaște 31.268,72 lei (27.340 principal + 3.928,72 dobândă legală până la 27.04.2023), plătibili în 5 rate, cu decădere din beneficiul termenului.
- **A.** Factura: 27.340 lei cu dobândă legală de la 08.04.2022 până azi. Simplu și fără dobândă la dobândă, dar ignoră tranzacția.
- **B.** Tranzacția: 31.268,72 lei, cu dobândă legală pe fiecare rată de la scadența ei (sau pe tot de la prima rată neplătită, prin decădere). Include dobânda capitalizată de 3.928,72, iar dobânda la dobândă (anatocismul) cere acordul părților (OG 13/2011, art. 10). Trebuie confirmat dacă tranzacția îl conține.
- Legat: tranzacția întrerupe prescripția (recunoaștere, C. civ. art. 2537 pct. 1). Avertismentul de prescripție ar trebui să o ia în calcul.
- **Recomandare:** B pentru principalul de 27.340 cu scadențele ratelor, iar cei 3.928,72 ceruți separat, fără dobândă pe ei, până confirmă avocatul anatocismul. Plus un avertisment de prescripție care să țină cont de un act de recunoaștere. De confirmat cu avocatul.

### 3.2 Dosarul 3: plățile din fișa de cont
AI-ul citește corect fișa („23381 rest 456,60; achitate integral: 20951, 20954, 22090, 22644, 23275”), dar decizia de produs din iulie e „imputația plății o face avocatul” (C. civ. art. 1507-1509), așa că sistemul doar avertizează.
- **A.** Păstrăm imputația manuală (azi).
- **B.** Când fișa atribuie explicit plata unei facturi (ca aici, „Incas. … nr.: 20954,23275,23381”), pre-completăm suma rămasă și arătăm plata pe poziție („încasat 33.209,40 la 30.12.2025”). Avocatul poate corecta.
- **Recomandare:** B. Imputația e făcută chiar de debitor în ordinul de plată, deci nu o decide platforma. Efort: aproximativ 2 zile (schema de extracție pentru fișă, legarea pe număr de factură, teste).

### 3.3 Dosarul 3: penalități pentru facturi plătite cu întârziere
20954 (89 de zile), 23275 (34), 22090 (12), 22644 (9), plus 23381 (33 de zile pe suma plătită). Avocatul vrea penalitățile pe perioada întârzierii.
- Necesită poziții „doar accesoriu” (principal 0, penalitate între scadență și data plății) și texte noi în somație și cerere.
- **Recomandare:** de făcut, cu plan dedicat (aproximativ 3 zile): depinde de 3.2 (data plății) și de formularea juridică a capătului de cerere pentru accesorii la sume deja plătite. De confirmat cu avocatul.
- Legat: contractul spune „plata în 4 zile de la facturare”, iar fișa are scadența egală cu data facturii. Care scadență se folosește? Recomandare: termenul din contract, când facturile nu au alt termen explicit.

### 3.4 Dosarul 5: modificarea manuală a penalităților (data-limită e făcută, §2.7)
- **A.** Câmp „Calculează accesoriile până la data de” (implicit: azi sau data somației/cererii).
- **B.** Suprascriere manuală a sumei accesoriilor pe poziție, cu mențiune în audit.
- **Recomandare:** A da (simplu, verificabil). B doar cu motiv obligatoriu, pentru că o sumă tastată nu mai poate fi verificată de instanță din formulă.
- Tot aici: plafonul de 10% se aplică acum pe fiecare factură („valoarea prestației” = factura). Dacă avocatul înțelege valoarea întregului contract, trebuie schimbat.

### 3.5 Dosarul 6: facturi cu mai multe conturi (făcut, §2.7)
Factura are 3 IBAN-uri (Trezorerie, Transilvania, ING); extracția îl reține doar pe unul.
- **Recomandare:** extracția să întoarcă toate conturile de pe document, iar Pasul 1 să le ofere ca listă (cu banca alături). Cere schimbarea schemei AI. Efort: aproximativ 1 zi.

### 3.6 „Formatul codului IBAN” (Dosarele 3 și 6)
Nu e clar ce înseamnă. Posibilități: afișarea în grupe de câte 4 („RO24 CECE NT04 …”) în acte și în ecran; acceptarea spațiilor (deja funcționează); un format greșit citit de AI. **De întrebat avocatul**, cu un exemplu.

### 3.7 Dosarul 6: penalități vădit excesive (avertismentul e făcut, §2.7; rămâne de confirmat textul cu avocatul)
1% pe zi duce la 3,85 mil. lei pe un principal de 416.855,99 lei. Instanța poate reduce penalitatea vădit excesivă (C. civ. art. 1541 alin. 1 lit. b), iar în ordonanța de plată o apărare serioasă a debitorului poate trimite creditorul la dreptul comun.
- **Recomandare:** avertisment la Pasul 4 când accesoriile depășesc principalul, cu trimitere la art. 1541. Fără plafonare automată. De confirmat cu avocatul.

### 3.8 Portal: taxa de timbru după activarea monitorizării
Avocatul spune: „după activare monitorizare, avocatul trebuie să plătească taxa de timbru”. Azi taxa e ghidată înainte de depunere (dovadă în pachet; regularizarea e permisă).
- **De confirmat:** schimbăm ordinea implicită (taxa după înregistrare, cu numărul de dosar) sau doar oferim ambele căi? Atinge modulul de taxă de timbru și decizia din 27.09 despre cine depune la instanță.

### 3.9 Portal: ritmul interogărilor
Avocatul propune oprirea căutării după ce se cunoaște primul termen și reluarea după acea dată.
- Risc: între timp termenul se poate schimba (preschimbare), iar ordonanța se poate da în camera de consiliu fără ședință publică.
- **Recomandare:** frecvență redusă (o dată pe săptămână) până cu 3 zile înainte de termen, apoi zilnic. Nu oprire completă.

### 3.10 Confirmări pentru ce am implementat deja
1. **Sincronizarea ANAF închide divergențele de adresă și denumire** ale părții. E compatibilă cu decizia ta din 01.10 (extracția nu apelează ANAF; ANAF rămâne pe buton)?
2. **Pasul 4 verifică singur statusul ANAF al debitorului** (doar ACTIV/INACTIV/RADIAT, fără să schimbe datele). E în spiritul aceleiași decizii?
3. **Avansul stornat în factura finală e exclus implicit**, cu explicație și posibilitate de reincludere. Alternativa e să rămână inclus, cu avertisment.
4. **Debitorul persoană fizică e oprit la Pasul 0.** Un PFA cu CUI trece; o persoană fizică profesionistă fără CUI pe documente ar fi oprită (poate continua manual).

### 3.11 Contracte încheiate înainte de 05.04.2013 (făcut, §2.7; rămâne de confirmat regula de aplicare în timp)
Garda folosește scadența, nu data contractului, pe care calculul nu o primește. O factură scadentă după 05.04.2013, emisă pe un contract mai vechi, primește încă marja de +8. Cazul e rar, din cauza prescripției.
- **Recomandare:** dacă data contractului e cunoscută și e anterioară datei de 05.04.2013, aplicăm aceeași gardă. Aștept confirmarea că regula de aplicare în timp este data încheierii contractului (art. 16 din Legea 72/2013).

### 3.12 Somația: „vor curge în continuare” după atingerea plafonului
Textul (scris de avocat) spune că penalitățile „vor fi calculate în continuare, în condițiile și la nivelul stabilite prin contract”. Când plafonul e deja atins, nu mai curge nimic. Formularea „în condițiile … stabilite prin contract” acoperă tehnic plafonul, dar poate induce în eroare. **De întrebat avocatul** dacă vrea o variantă de frază pentru cazul cu plafon atins.

## 4. Ce nu am făcut și de ce
- Din review-ul tehnic au rămas două sugestii de structură, nefăcute. Prima: mutarea verificării ANAF și a filtrului de divergențe din controller în servicii dedicate. A doua: lista de câmpuri închise de ANAF e duplicată între PHP și JS. Nu schimbă comportamentul, așa că le pot face separat.
- Nu am comis: aștept confirmarea ta.
- Dosarele de test create pe dev: #82 (Dosarul 5), #83 (Dosarul 6) și #84 (Dosarul 2, trecut manual în „cerere depusă” și cu data creării mutată în 2024, ca să testez căutarea pe portal). Au depășit limita planului contului de test (7/5), așa că pe dev s-au generat facturi „Dosar suplimentar”. Le pot șterge pe toate, cu facturile lor.
- Jobul cron nou trebuie adăugat în Coolify la deploy (§2.4).
