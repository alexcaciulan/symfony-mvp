# Modificări implementate în urma reviziei tale, pentru revalidare

> Pe baza completărilor tale (textul albastru) din `PREZENTARE-WORKFLOW-DOSAR-AVOCAT.docx`, am implementat modificările de mai jos. Pentru fiecare am citat mențiunea ta, ca s-o recunoști ușor. Te rog să confirmi că direcția e corectă sau să indici ce trebuie ajustat.
>
> Trimiteri legale: CPC = Codul de procedură civilă.

## Cum citești documentul

Fiecare punct are două părți:
- **Ai cerut**: citatul mențiunii tale.
- **Ce s-a schimbat**: comportamentul schimbat.

Toate modificările au trecut printr-o verificare juridică internă. Punctele care necesită confirmarea ta sunt marcate cu **⚠️ De validat**.

---

## 1. Statusurile dosarului: fără „insolvabil" la ordonanța de plată

**Ai cerut**: „Nu putem discuta de închidere OP ca insolvabil. Doar un dosar execuțional se închide ca urmare a constatării insolvabilității debitorului. Din definitivă se poate doar închidere cu succes" sau trecere în executare silită „după trecerea termenului în care instanța pune în vedere debitorului achitarea".

**Ce s-a schimbat**:
- Am eliminat statusul terminal „închis insolvabil" din procedura OP. Dosarul OP se închide cu **„Închis cu succes"** sau **„Închis fără recuperare"**.
- Insolvabilitatea se înregistrează **doar ca rezultat al executării silite** (motivul „Debitor insolvabil, constatat în executare"), niciodată ca rezultat al OP în sine.
- Din DEFINITIVĂ, dosarul poate fi închis cu succes **sau** poate trece în **faza de executare silită** (status nou „Executare silită", care înainte nu era folosit).
- Trecerea în executare este **alegerea ta**, nu automată. În baza răspunsului tău, am adăugat o **fereastră de plată voluntară de 40 de zile** (valoare configurabilă), calculată de la data comunicării hotărârii. În ecranul de executare apare „Executare recomandată după {dată}". Este **doar un indicator**, nu un blocaj: poți porni executarea și mai devreme, fiindcă ordonanța definitivă e executorie imediat.

**⚠️ De validat**: 40 de zile e valoarea potrivită pentru fereastra de plată voluntară (ai indicat „în jur de 35-40")? Și modelul de închidere (succes / fără recuperare / insolvabil doar în executare) reflectă corect intenția ta?

---

## 2. Executarea silită în paralel cu cererea în anulare

**Ai cerut**: „Ordonanța de plată, chiar dacă este atacată cu cerere în anulare, își păstrează caracterul executoriu. Avocatul ar trebui să poată decide dacă dorește să inițieze executarea, înaintea soluționării cererii în anulare, asumându-și riscul restituirii."

**Ce s-a schimbat**:
- Avocatul poate trece dosarul în executare silită din stările „Ordonanță emisă" și „Cerere în anulare depusă" (nu doar din „Definitivă").
- Din „Ordonanță emisă", trecerea în executare este permisă doar după ce e completată data comunicării ordonanței (altfel termenul de 10 zile al cererii în anulare ar fi nedeterminat).
- La inițierea executării apare un avertisment: cererea în anulare nu suspendă de drept executarea; debitorul poate cere instanței suspendarea, pe care aceasta o poate acorda, cu sau fără cauțiune (CPC art. 1024); dacă cererea în anulare e admisă, sumele recuperate se restituie.
- Dacă, în timp ce dosarul e în executare, cererea în anulare este admisă, dosarul poate fi marcat corespunzător (ordonanța anulată), păstrând istoricul.
- Am tratat și cazul invers: dacă, în timp ce dosarul e în executare, cererea în anulare este **respinsă**, există o acțiune „Cerere în anulare respinsă" care înregistrează soluția (ordonanța rămâne valabilă, executarea continuă). Astfel, indiferent de soluția instanței la cererea în anulare, dosarul nu rămâne blocat după ce a intrat în executare.

**⚠️ De validat**: textul avertismentului afișat la inițierea executării. Verbatim, este: „Cererea în anulare nu suspendă executarea de drept. Debitorul poate cere instanței suspendarea, pe care aceasta o poate acorda, cu sau fără cauțiune (CPC art. 1024). Suspendarea nu e garantată. Dacă cererea în anulare e admisă, sumele recuperate se restituie." E corect și complet?

---

## 3. Comunicarea somației și termenul de 15 zile

**Ai cerut**: „Am stabilit deja că doar prin executor judecătoresc, prin intermediul platformei. Acesta încarcă dovada comunicării somației, cu data efectivă, și de la această dată se calculează cele 15 zile."

**Ce s-a schimbat**:
- Avocatul introduce **data reală la care debitorul a primit somația** (din dovada de comunicare), iar termenul de 15 zile (CPC art. 1015) se **recalculează de la primire**, nu de la generarea documentului. Disclaimerul „termen estimativ" dispare după confirmarea datei reale.
- Platforma înregistrează și **modalitatea de comunicare** pentru audit (valoare probatorie diferită: proces-verbal executor vs. confirmare poștală).

**⚠️ De validat**: tu ai cerut „doar prin executor judecătoresc". Deocamdată sunt păstrate **ambele modalități** legale (executor judecătoresc **și** Poșta Română, recomandată cu conținut declarat și confirmare de primire), pentru că ambele sunt admisibile per CPC art. 1015 alin. (1). Curierul privat rămâne **neadmisibil**. Rămâne **doar executor**, sau ambele opțiuni?

---

## 4. Generarea cererii OP doar după expirarea termenului + acordul avocatului

**Ai cerut**: „Platforma validează împlinirea termenului de plată din somație (minim 15 zile). Dacă avocatul bifează că debitul a fost parțial achitat sau neachitat, se cere acord pentru generare OP." Statusul reflectă „Somație trimisă și termen de plată expirat".

**Ce s-a schimbat**:
- Generarea cererii OP este **blocată** până la expirarea termenului de 15 zile (calculat din data comunicării; dacă data nu e completată, avocatul confirmă explicit, prin bifă, că termenul de la primire a expirat).
- Avocatul bifează **statusul debitului** (neachitat / achitat parțial) și dă **acord explicit** înainte de generare. Ambele se înregistrează în audit.
- Pe dosar apare indicatorul „termen plată expirat" când termenul a trecut.

---

## 5. Încărcarea ordonanței emise de instanță

**Ai cerut**: „și încarcă hotărârea emisă de instanța în platformă, semnată electronic. Platforma calculează termenul pentru o eventuală cerere în anulare și abia apoi o califică ca fiind definitivă."

**Ce s-a schimbat**:
- La pasul „Emite ordonanța", avocatul poate **atașa ordonanța de plată** emisă de instanță (PDF semnat electronic sau scan). Atașarea e opțională (se poate face și ulterior din secțiunea Documente).
- Calculul termenului de cerere în anulare (10 zile) și calificarea ca „Definitivă" funcționau deja: termenul curge de la **data comunicării** ordonanței, iar dosarul devine definitiv abia după expirarea acestuia.

Notă de terminologie: documentul emis de instanță în această procedură se numește **„ordonanță de plată"** (CPC art. 1020), nu „hotărâre". Am folosit acest termen.

**⚠️ De validat**, două aspecte:
1. **Terminologia**: tu ai scris „hotărârea emisă de instanță". Documentul prin care instanța admite cererea se numește tehnic „ordonanță de plată" (CPC art. 1020); la respingere instanța dă o încheiere. Am folosit „ordonanță de plată". E corect, sau preferi „hotărâre"?
2. **Atașarea opțională**: termenul de 10 zile se calculează de la data comunicării (pe care o introduci tu), independent de atașarea PDF-ului, deci atașarea ordonanței nu e obligatorie (se poate face și ulterior). E OK așa, sau vrei ca pasul „Emite ordonanța" să fie blocat până atașezi documentul?

---

## 6. Termenul de 10 zile pentru cererea în anulare și buffer-ul de siguranță

**Ai cerut**: „De analizat cum calculăm termenul de 10 zile, pentru că nu mai este în controlul nostru. Debitorul are 10 zile de când primește hotărârea... dosarul poate apărea pe portal zile mai târziu." și „Cred că e în regulă un termen de 5 zile de buffer."

**Ce s-a schimbat**:
- Marcarea automată ca „Definitivă" se face abia după: termenul de 10 zile (de la comunicare) + prorogare la prima zi lucrătoare + **buffer de 5 zile** (înainte era 1 zi). Aceasta absoarbe întârzierea portalului și faptul că data comunicării nu e în controlul avocatului. Marcarea mai târzie este conservatoare (în favoarea dreptului debitorului la cererea în anulare).
- Suplimentar, la rămânerea definitivă se creează automat un **termen de prescripție a executării silite** (3 ani, CPC art. 706), ca să nu se piardă dreptul de a cere executarea.

**⚠️ De validat**: buffer-ul de 5 zile e suficient în practica ta?

---

## 7. Identificarea numărului de dosar și tragerea soluției de pe portal, cu confirmare

**Ai cerut**: „Nu spuneam că identificăm noi numărul dosarului, cercetând portal.just pe bază de părți și instanță?", „Platforma trage informația de pe portalul instanțelor", „și notificat avocatului", „Platforma ar putea trage soluția și cere o confirmare avocatului."

**Ce s-a schimbat**:
- **Identificarea numărului de dosar** pe portal (căutare după părți + instanță) și **detectarea automată a soluției** existau deja, plus notificarea avocatului la fiecare eveniment de portal.
- Nou: când platforma detectează o soluție pe portal, afișează un **card „Soluție detectată"** cu textul soluției și un buton **„Confirmă și aplică"** care deschide formularul de tranziție corespunzător, **pre-completat cu data pronunțării**. Tranziția **NU se aplică automat**: rămâne la confirmarea avocatului (o aplicare greșită ar declanșa pe date eronate termenul critic de 10 zile).


---

## 8. Monitorizarea portalului se oprește după ce s-a tras informația

**Ai cerut**: „Eu cred că este necesară monitorizare" și „Se oprește ulterior tragerii informației."

**Ce s-a schimbat**:
- Monitorizarea zilnică a portalului **se oprește automat** când dosarul iese din faza contencioasă (devine definitiv, trece în executare sau e închis/respins). Cron-ul nu mai interoghează aceste dosare, iar indicatorul de monitorizare reflectă corect realitatea.

---

## 9. Depunerea prin platformă și accesul la dosarul electronic

**Ai cerut**: depunerea cererii prin platformă via `registratura.rejust.ro`, cu dovada comunicării inclusă în pachet. Clarificarea ulterioară: scopul real e că depunerea acordă concomitent avocatului **acces la dosarul electronic**.

**Ce am stabilit (document de fezabilitate)**:
- `registratura.rejust.ro` este un **portal manual** (depunere cereri, acte, plată taxă timbru). **Nu are un API public** și necesită sesiunea autentificată + **semnătura electronică calificată a avocatului** (Legea 455/2001 / eIDAS). O platformă terță **nu poate depune în numele avocatului** fără certificatul lui. Recomandarea: integrare automată = **NU** pentru moment.
- **Accesul la dosarul electronic** se obține printr-o **„Cerere de acces la dosarul electronic"** (formular standard pe site-ul instanței), depusă la registratură sau trimisă prin email; instanța returnează un cod de acces pe email. Variază de la o instanță la alta.
- **Direcția propusă (pas viitor)**: după ce platforma trage numărul de dosar de pe portal, **generează „Cerere de acces la dosarul electronic" și o trimite prin email la instanță**. Acest pas nu e încă implementat; e documentat ca dezvoltare ulterioară.
- Dovada comunicării este deja inclusă în pachetul (ZIP-ul) de depunere.
- Textele care afirmau categoric „depunere fizică la registratură / prin curier" au fost neutralizate (depunere la instanța competentă, cu confirmare de primire).

**⚠️ De validat**: direcția pasului viitor (email „Cerere de acces la dosarul electronic" către instanță, după tragerea numărului de dosar). Confirmi că aceasta e abordarea corectă?

---

## 10. Etapa amiabilă

**Ai cerut**: „Nu cred că e necesar pasul acesta în platformă. Avocatul vine la noi strict pentru OP."

**Ce s-a schimbat**:
- Nu există și nu s-a adăugat niciun pas amiabil obligatoriu. Primul pas pentru un dosar nou este direct „Generează somația". Modificarea de etichetă a statusului inițial a fost considerată pur cosmetică și a fost lăsată deoparte la cererea ta.

---

## Puncte care necesită decizia ta

1. **Comunicarea somației (pct. 3)**: doar executor judecătoresc, sau și Poșta Română (R+CD+AR)?
2. **Modelul de închidere a dosarului (pct. 1)**: succes / fără recuperare / insolvabil doar în executare. Corect?
3. **Buffer-ul de 5 zile la definitivare (pct. 6)**: suficient?
4. **Fereastra de plată voluntară (pct. 1)**: 40 de zile e potrivit, sau preferi altă valoare în intervalul 35-40?
5. **Data de referință a hotărârii**: platforma reține o singură dată a comunicării hotărârii (cea către tine, avocatul), pe care o folosește atât pentru termenul de 10 zile al cererii în anulare, cât și pentru fereastra de plată voluntară și termenul de prescripție a executării. În practică, comunicarea către debitor poate fi cu 1-2 zile diferită. E suficientă o singură dată, sau ai nevoie să introduci separat data comunicării către debitor (mai exactă pentru termenul cererii în anulare)?
6. **Direcția pentru accesul la dosarul electronic (pct. 9)**: email „Cerere de acces la dosarul electronic" către instanță, după tragerea numărului de dosar?
