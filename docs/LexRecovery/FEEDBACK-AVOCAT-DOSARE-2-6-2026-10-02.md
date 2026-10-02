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

## 3. De decis cu tine

Pentru fiecare punct: întrebarea, opțiunile și recomandarea mea.

### 3.1 Dosarul 2: cum se cere o creanță recunoscută prin tranzacție
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

### 3.4 Dosarul 5: data până la care se calculează și modificarea manuală
- **A.** Câmp „Calculează accesoriile până la data de” (implicit: azi sau data somației/cererii).
- **B.** Suprascriere manuală a sumei accesoriilor pe poziție, cu mențiune în audit.
- **Recomandare:** A da (simplu, verificabil). B doar cu motiv obligatoriu, pentru că o sumă tastată nu mai poate fi verificată de instanță din formulă.
- Tot aici: plafonul de 10% se aplică acum pe fiecare factură („valoarea prestației” = factura). Dacă avocatul înțelege valoarea întregului contract, trebuie schimbat.

### 3.5 Dosarul 6: facturi cu mai multe conturi
Factura are 3 IBAN-uri (Trezorerie, Transilvania, ING); extracția îl reține doar pe unul.
- **Recomandare:** extracția să întoarcă toate conturile de pe document, iar Pasul 1 să le ofere ca listă (cu banca alături). Cere schimbarea schemei AI. Efort: aproximativ 1 zi.

### 3.6 „Formatul codului IBAN” (Dosarele 3 și 6)
Nu e clar ce înseamnă. Posibilități: afișarea în grupe de câte 4 („RO24 CECE NT04 …”) în acte și în ecran; acceptarea spațiilor (deja funcționează); un format greșit citit de AI. **De întrebat avocatul**, cu un exemplu.

### 3.7 Dosarul 6: penalități vădit excesive
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

### 3.11 Contracte încheiate înainte de 05.04.2013
Garda folosește scadența, nu data contractului, pe care calculul nu o primește. O factură scadentă după 05.04.2013, emisă pe un contract mai vechi, primește încă marja de +8. Cazul e rar, din cauza prescripției.
- **Recomandare:** dacă data contractului e cunoscută și e anterioară datei de 05.04.2013, aplicăm aceeași gardă. Aștept confirmarea că regula de aplicare în timp este data încheierii contractului (art. 16 din Legea 72/2013).

### 3.12 Somația: „vor curge în continuare” după atingerea plafonului
Textul (scris de avocat) spune că penalitățile „vor fi calculate în continuare, în condițiile și la nivelul stabilite prin contract”. Când plafonul e deja atins, nu mai curge nimic. Formularea „în condițiile … stabilite prin contract” acoperă tehnic plafonul, dar poate induce în eroare. **De întrebat avocatul** dacă vrea o variantă de frază pentru cazul cu plafon atins.

## 4. Ce nu am făcut și de ce
- Din review-ul tehnic au rămas două sugestii de structură, nefăcute. Prima: mutarea verificării ANAF și a filtrului de divergențe din controller în servicii dedicate. A doua: lista de câmpuri închise de ANAF e duplicată între PHP și JS. Nu schimbă comportamentul, așa că le pot face separat.
- Nu am comis: aștept confirmarea ta.
- Dosarele de test create pe dev: #82 (Dosarul 5), #83 (Dosarul 6) și #84 (Dosarul 2, trecut manual în „cerere depusă” și cu data creării mutată în 2024, ca să testez căutarea pe portal). Au depășit limita planului contului de test (7/5), așa că pe dev s-au generat facturi „Dosar suplimentar”. Le pot șterge pe toate, cu facturile lor.
- Jobul cron nou trebuie adăugat în Coolify la deploy (§2.4).
