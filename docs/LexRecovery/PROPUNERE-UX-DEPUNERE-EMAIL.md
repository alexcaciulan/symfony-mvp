# Propunere UX: depunerea cererii la instanță prin e-mail

Data: 24.10.2026. Bază: capturile din `docs/LexRecovery/screenshots/verificare-live-email-instante-2026-10-24/` (subfolderele 01-07), capturile de context din `verificare-live-taxa-timbru-v3-2026-10-24/` și `verificare-live-imputernicire-2026-10-24/`, șabloanele din `templates/case/filing/`, `templates/case/overview/`, `templates/court/address/` și cheile `filing.*` din `translations/messages.ro.yaml`.

Mockup-uri: `docs/LexRecovery/mockups/v2/depunere/` (index în `index.html`, capturi în `screenshots/`). Se deschid cu `php -S 127.0.0.1:8888 -t docs/LexRecovery/mockups`, apoi http://localhost:8888/v2/depunere/.

Documentul nu modifică aplicația. Textele propuse sunt propuneri; traducerile rămân neschimbate până la decizie.

## 1. Diagnostic

### Problema de fond

Tot fluxul de depunere stă într-un singur card din bara laterală a filei „Documente” (o treime din lățime, aproximativ 320 px). În același card se adună: pachetul, adresa de depunere, panoul de trimitere, starea transmiterii, fiecare încercare SMTP, mesajele primite, formularul pentru data depunerii, încărcarea confirmării, acțiunile de reluare și închidere și 5-10 paragrafe de note juridice. Nu există o ierarhie: titlul stării, explicația, codurile tehnice și avertismentele juridice au aceeași mărime și aceeași culoare (tot cardul devine galben când ceva nu merge). Avocatul nu vede dintr-o privire ce s-a întâmplat, ce trebuie să facă și ce este doar informație.

### Probleme pe ecrane

| Captură | Problemă |
|---|---|
| `01-pachet-depunere/02-pachet-construit-trei-fisiere-nesemnate.png` | Titlurile fișierelor se rup pe 3-4 rânduri; câmpul nativ „Choose File” și butonul de încărcare se repetă de 3 ori; 7 paragrafe de note juridice sub fișiere, toate vizibile. Nu se vede câte fișiere mai sunt de semnat. |
| `01-pachet-depunere/06-pachet-neactualizat-dupa-modificare.png` | „Neactualizat · v1” este un cip mic; motivul și acțiunea de reconstruire nu ies în evidență. |
| `02-fisiere-semnate/03-potrivire-probabila-cere-confirmarea-avocatului.png` | Coloana cu starea semnăturii are 10 cuvinte pe rând; cipul galben, nota de validitate și avertismentul despre certificat se amestecă într-o coloană îngustă. Butonul „Confirmă...” e pe două rânduri, sub tot textul. |
| `04-trimitere/01-fereastra-trimite-la-instanta.png`, `02-declaratiile-avocatului-bifate.png` | Confirmarea trimiterii (acțiune ireversibilă) este un panou în bara laterală, cu textul e-mailului într-o casetă cu derulare proprie. Pagina principală rămâne goală în stânga. Butonul „Transmite” e mic și cenușiu, lângă „Renunță” ca link. |
| `04-trimitere/06-cardul-transmiterii-acceptat-de-server.png` | După trimitere, cardul afișează destinatarul, nota de mediu de test, ora, nota despre oră, regula datei depunerii, formularul de dată, încărcarea confirmării și disclaimerul. Nu este clar că pasul următor este „declară data depunerii”. |
| `05-esecuri-trimitere/01-refuz-temporar-reincercare-programata.png`, `02-reincercari-epuizate-dupa-4-incercari.png` | Lista celor 4 încercări (tehnică) ocupă mai mult loc decât mesajul de stare. După epuizare nu există un buton principal clar. |
| `05-esecuri-trimitere/04-refuz-definitiv-550-adresa-scoasa-din-uz.png`, `06-refuz-pentru-dimensiune-552.png` | Codurile SMTP (550, 552) apar în corpul mesajului; acțiunea utilă (alta adresă, altă cale, reîmpărțire) e îngropată. |
| `05-esecuri-trimitere/07-anexa-refuzata-optiuni-reluare-sau-inchidere.png` | Două acțiuni opuse („Trimite părțile rămase”, „Închide fără anexele rămase”), fiecare cu bifă proprie, plus formularul de dată și încărcarea confirmării, toate în aceeași coloană, în același galben. Starea e-mailurilor (acceptat, refuzat, în așteptare) e text simplu, greu de citit. |
| `05-esecuri-trimitere/09-cerere-nelivrata-in-timpul-anexelor-transmitere-anulata.png` | Cel mai grav exemplu: aproximativ 15 paragrafe. Titlul nu se distinge; raportul serverului (`550 5.1.1 Recipient address rejected: User unknown`) vine imediat după titlu; nota despre prescripție (roșu) stă la mijloc; „Dosarul a revenit la cererea generată”, adică informația esențială, apare după ea; acțiunea concretă (depune pe altă cale) nu există ca buton. Trei disclaimere repetă că platforma raportează doar răspunsul serverului. |
| `05-esecuri-trimitere/10-F1-...png`, `11-F1-...png` | Butonul „Am verificat” este mic, cu bordură, și apare înaintea explicației despre e-mailurile anulate; dacă avocatul derulează, formularul de dată (deja completat) concurează cu el. |
| `05-esecuri-trimitere/12-rezultat-incert-avocatul-declara-din-copia-cc.png` | Cele două variante („Am primit”, „Nu am primit”) au fiecare o bifă și un buton, una sub alta; consecința fiecăreia e scrisă doar pentru prima. Titlul repetă aproape identic textul de sub el. |
| `05-esecuri-trimitere/17-inchis-fara-anexele-ramase.png` | După închidere nu se spune clar ce anexe trebuie duse pe altă cale. |
| `06-mesaje-primite/02-citire-raspuns-intarziere-anexa-nelivrata.png` | Pe un dosar cu mai multe evenimente, cardul trece de 1.500 px înălțime. Fiecare mesaj primit are expeditorul complet, nota „verificare tehnică a expeditorului, nu atestare a instanței” repetată la fiecare rând și extrasul. |
| `03-adrese-instante/01-pagina-avocatului-adresa-de-depunere.png` | Pagina începe cu 6 reguli în listă; adresa folosită la depunere vine abia după ele. Sugestia unei adrese are aceeași greutate vizuală ca adresa confirmată. |
| `05-esecuri-trimitere/15-admin-deblocare-transmitere-incerta.png` | Formular minimal fără context (ce se întâmplă cu dosarul după deblocare). |

### Cauze

1. Locul greșit: un flux cu 4 etape și peste 15 stări într-o bară laterală.
2. Lipsa unui model de stare: fiecare condiție din cod adaugă un paragraf, în loc să aleagă un singur mesaj.
3. Textele juridice nu au un loc al lor; sunt repetate în fiecare stare.
4. Informația tehnică (coduri, încercări, .eml) are aceeași greutate ca decizia cerută avocatului.

## 2. Arhitectura informației

### Decizia principală: filă dedicată „Depunere la instanță”

Pe pagina dosarului apare o filă nouă, „Depunere la instanță”, între „Documente” și „Termene”. Fila are lățime completă (aceeași grilă de 3 coloane ca „Detalii” și „Documente”) și conține tot fluxul. Lângă numele filei apare un punct colorat când starea cere o acțiune (albastru: pas următor normal, chihlimbar: decizie necesară, roșu: cererea nu a ajuns).

Fila apare când dosarul este în „Cerere generată” sau are un pachet ori o transmitere. Înainte de asta nu se afișează.

Structura filei:

```
+--------------------------------------------------------------------+
| Etape: (1) Pachet  (2) Semnare  (3) Trimitere  (4) Data depunerii  |
+--------------------------------------------+-----------------------+
| PANOUL DE STARE                            | Instanța și adresa    |
|   titlu (o propoziție)                     |   de depunere         |
|   explicație (1-2 propoziții)              +-----------------------+
|   [Acțiunea principală] [Secundară]        | Data depunerii        |
|   notă critică, doar dacă există           |   (sau „Pachetul      |
+--------------------------------------------+    semnat”, „Unde se  |
| Fișiere de semnat  SAU  E-mailuri către    |    caută copia”)      |
|   instanță (o linie pe e-mail)             +-----------------------+
+--------------------------------------------+ > Note juridice       |
| Mesaje primite (o linie pe mesaj)          |   (pliat)             |
+--------------------------------------------+-----------------------+
| > Detalii tehnice (pliat)                  |
+--------------------------------------------+
```

Regula: coloana principală spune ce s-a întâmplat și ce urmează; coloana dreaptă dă context stabil (instanța, adresa, data); tot ce este tehnic sau juridic general e pliat.

### Ce rămâne în alte locuri

- **Fila „Documente”, bara laterală**: un card compact „Depunere la instanță prin e-mail” cu o linie de stare, o propoziție și butonul „Deschide depunerea” (mockup `j-documente-card-compact.html`). Pachetul ZIP pentru alte canale rămâne acolo, neschimbat.
- **Antetul dosarului (hero)**: eticheta de stare și butonul principal urmează starea depunerii (ex. „Trimitere nereușită” și „Trimite din nou”). Butonul din antet duce la fila „Depunere la instanță”, la panoul de stare; nu execută singur acțiunea.
- **Acțiuni recomandate (fila „Detalii”)**: o singură linie cu titlul stării și link spre filă.
- **Confirmarea trimiterii**: pagină dedicată „Verifică și trimite la instanță” (vezi 4.6), nu panou în bara laterală.

## 3. Modelul de stare

Pentru fiecare stare, interfața afișează un singur titlu, o explicație scurtă, o acțiune principală și cel mult două secundare. Ce nu încape aici merge în „Detalii tehnice” sau în „Note juridice”. Ordinea de prioritate când mai multe condiții sunt adevărate rămâne cea din `_submission_card.html.twig` (F1 înaintea blocajului, blocajul înaintea reîncercării etc.).

Ton vizual: albastru (în lucru, pas normal), verde (încheiat), chihlimbar (decizie necesară), roșu (cererea nu a ajuns). Doar panoul de stare primește culoarea; restul paginii rămâne alb.

### 3.1 Pachet

| Stare (cod) | Titlu | Explicație | Acțiune principală | Secundare | Detalii tehnice | Note juridice |
|---|---|---|---|---|---|---|
| Pachet neconstruit (`CERERE_GENERATA`, fără pachet) | Construiește pachetul de depunere | Pachetul reunește cererea cu opisul, împuternicirea și anexele numerotate, gata de semnat. | Construiește pachetul | Descarcă arhiva pentru altă cale | - | Comprimarea locală (Legea 51/1995, GDPR) |
| Pachet blocat (`filing.package.blocked_*`) | Pachetul nu se poate construi încă | Lista cauzelor, fiecare cu butonul ei (ex. „Completează datele împuternicirii”). | Prima cauză de rezolvat | - | - | - |
| Pachet neactualizat (`STALE`) | Pachetul nu mai corespunde dosarului | Motivul (ex. „Împuternicirea s-a schimbat după construirea pachetului”). Fișierele semnate nu mai sunt valabile. | Reconstruiește pachetul | - | versiunea veche, data construirii | Reconstruirea nu modifică cererea generată |
| De semnat (`BUILT`, `AWAITING_SIGNATURES`) | Semnează fișierele pachetului | „X din N fișiere sunt gata.” plus ce lipsește. | Prima acțiune necesară pe un fișier (ex. „Încarcă anexele semnate” sau „Confirmă împuternicirea”) | Descarcă fișierele nesemnate (.zip) | versiune, număr de e-mailuri, volume | Validitatea semnăturii, validatorul DSS, pagina de gardă provizorie, răspunderea avocatului |
| Fișier refuzat la încărcare | (rândul fișierului, nu panoul) „Fișierul nu are semnătură” / „Fișierul s-a modificat după semnare” | Motivul, o propoziție. | Încarcă din nou (pe rând) | - | detaliul tehnic al verificării | - |
| Semnat, gata de trimis (`SIGNED`, verdict permis) | Pachetul este semnat și gata de trimis | Toate fișierele au semnătura detectată. Înainte de trimitere se verifică e-mailul complet. | Verifică și trimite | Descarcă arhiva pentru altă cale | - | Semnătura detectată nu înseamnă validitate calificată |
| Semnat, trimitere blocată (verdict cu blocaje) | Trimiterea nu este disponibilă încă | Lista blocajelor, fiecare cu link de rezolvare (ex. „Adaugă data primirii somației”). | Prima rezolvare | Descarcă arhiva pentru altă cale | - | - |

### 3.2 Trimitere

| Stare (cod) | Titlu | Explicație | Acțiune principală | Secundare | Detalii tehnice | Note juridice |
|---|---|---|---|---|---|---|
| În așteptare / se trimite (`QUEUED`, `SENDING`, `PARTIALLY_SENT` activ) | Se trimite la instanță: e-mailul 1 din 3 | Pagina se actualizează singură. Documentele și taxa nu se pot modifica în acest timp. | - (fără acțiune) | Anulează transmiterea (doar înainte de acceptarea primului e-mail) | - | - |
| Reîncercare programată (`RETRY_SCHEDULED`) | Serverul instanței nu a primit încă e-mailul. Se reîncearcă automat | Încercarea 2 din 4 a primit o eroare temporară. Următoarea încercare: ora. Nu este necesară nicio acțiune. | - | Arhiva pentru altă cale (link) | încercări, coduri | Retrimiterea automată nu garantează livrarea |
| Reîncercări epuizate (`FAILED_TEMPORARY_EXHAUSTED`) | E-mailul nu a putut fi trimis după 4 încercări | Cererea nu a ajuns la instanță. Pachetul semnat rămâne valabil și poate fi trimis din nou. | Trimite din nou | Descarcă arhiva pentru altă cale | tabel încercări, coduri, .eml | Platforma raportează doar răspunsul serverului |
| Refuz definitiv, adresa respinsă de serverul nostru (`FAILED_PERMANENT`, adresă scoasă din uz) | Adresa instanței a fost respinsă | Adresa a fost scoasă din uz automat. Dosarul nu mai are o adresă de depunere până la o adresă nouă confirmată. | Descarcă arhiva pentru registratură | Sugerează o adresă | cod 550, .eml | Până la decizia administratorului, cererea se depune pe altă cale |
| Refuz definitiv, alt motiv (`FAILED_PERMANENT`) | Serverul instanței a refuzat e-mailul | Refuzul nu privește neapărat adresa, care rămâne în uz. | Descarcă arhiva pentru altă cale | Trimite din nou (dacă e permis) | cod, .eml | - |
| Refuz pentru dimensiune (`FAILED_SIZE`) | E-mailul este prea mare pentru serverul instanței | Dacă se poate: „Anexele se pot împărți în volume mai mici, care se semnează din nou.” Altfel: „O nouă împărțire nu ajută.” | Reîmparte anexele (sau Descarcă arhiva pentru altă cale) | - | cod 552, dimensiuni | - |
| Eroare internă (`FAILED_INTERNAL`) | Un fișier semnat nu a putut fi trimis | Fișierul lipsește sau s-a modificat după încărcare. | Încarcă din nou fișierul semnat | Descarcă arhiva pentru altă cale | motivul tehnic | - |
| Parțial: anexă refuzată (`NEEDS_REVIEW` după cerere, `stalled`) | Cererea a ajuns, dar nu și toate anexele | E-mailul cu cererea a fost acceptat; e-mailul 2 a fost refuzat definitiv, iar e-mailul 3 nu a plecat. | Trimite anexele rămase | Închide fără anexele rămase (fereastră de confirmare, buton roșu) | cod 554, încercări, .eml | După închidere, anexele se depun pe altă cale |
| Închisă parțial (`CLOSED_PARTIAL`) | Cererea a fost trimisă fără toate anexele | Lista anexelor rămase (volumele 2 și 3) se depune pe altă cale. | Descarcă anexele rămase | Declară data depunerii | - | - |
| Rezultat necunoscut (`NEEDS_REVIEW`, parte incertă) | Nu se știe dacă e-mailul a ajuns. Verifică copia din CC | Conexiunea s-a întrerupt; nimic nu a fost retrimis. Copia din CC arată doar dacă mesajul a plecat. | Înregistrează declarația (după alegerea între „Am primit copia în CC” și „Nu am primit copia în CC”, două carduri egale, cu consecința scrisă sub fiecare) | - | ultima încercare SMTP | Declarația aparține avocatului; copia CC nu dovedește primirea |
| Anulată (`CANCELLED`) | Transmiterea a fost anulată | Nimic nu a plecat spre instanță. | Verifică și trimite | - | - | - |
| Deblocată de administrator | Transmiterea a fost închisă de administrator | Dosarul a revenit la „Cerere generată”. Motivul: (textul adminului). Cererea se poate trimite din nou sau depune pe altă cale. | Verifică și trimite | Descarcă arhiva pentru altă cale | data, motivul | Deblocarea nu declară primirea și nu schimbă data depunerii |

### 3.3 După trimitere

| Stare (cod) | Titlu | Explicație | Acțiune principală | Secundare | Detalii tehnice | Note juridice |
|---|---|---|---|---|---|---|
| Trimisă, fără confirmare (`SENT`, fără dovadă) | Cererea a fost trimisă. Se așteaptă confirmarea de primire | Serverul instanței a acceptat e-mailul la ora X. Asta nu înseamnă înregistrarea cererii. Data depunerii se declară după înregistrarea pe portal sau după confirmarea instanței. | Salvează data depunerii (câmp de dată în panou) | Verifică pe portal.just.ro; Încarcă o confirmare primită pe altă cale | .eml, încercări | Adresa platformei nu este procedurală; art. 148 CPC; răspunderea pentru termene |
| Confirmare întârziată (`RECEIPT_OVERDUE`) | Nu a sosit confirmarea de primire | Înregistrarea se verifică la instanță. O confirmare primită pe altă cale se poate încărca. | Încarcă o confirmare | Verifică pe portal.just.ro | - | idem |
| Confirmare sosită (`RECEIPT_RECEIVED`) | A sosit confirmarea de primire de la instanță | Mesajul automat a sosit la ora X. Arată că e-mailul a ajuns, nu că cererea a fost înregistrată. | Salvează data depunerii (precompletată, cu mențiunea „propusă din confirmare”) | Verifică pe portal.just.ro | verificarea expeditorului | idem |
| Confirmare încărcată de avocat (`RECEIPT_MANUAL`) | Confirmarea de primire a fost încărcată | Pentru .eml: „Data din antet: X, propusă.” Pentru .eml cu dată nepotrivită sau PDF/imagine: „Data se introduce manual.” | Salvează data depunerii | - | validarea fișierului (tehnică) | - |
| Data declarată (dosar „Cerere depusă”) | Cerere depusă pe 24.10.2026 (dată declarată) | - | - | Descarcă copia e-mailului | .eml, încercări | - |
| Cerere nelivrată (`UNDELIVERED`, captura 09) | Cererea nu a ajuns la instanță: adresa nu există | Serverul a returnat e-mailul cu cererea; nu este o respingere a instanței. Transmiterea a fost anulată și dosarul a revenit la „Cerere generată”. Adresa a fost raportată administratorului; până la decizia lui, cererea se depune pe altă cale. | Descarcă arhiva pentru registratură | Vezi adresele instanței | raportul serverului (550 5.1.1) | Textul complet despre prescripție (vezi 3.4) |
| Returnat după depunere, F1 (`late_review`) | Verificare necesară: e-mailul cu cererea a fost returnat după depunere | Raportul a sosit după declararea datei depunerii. Dosarul nu a fost modificat; e-mailurile rămase nu se mai trimit. Se verifică la registratură dacă cererea a fost înregistrată, apoi se confirmă. | Confirmă verificarea (cu bifa declarației) | Descarcă raportul de nelivrare | raport 5.1.1 | Raportul nu este o respingere a instanței; art. 148 CPC |
| Returnat și închis după F1 (`petition_returned_closed`) | Transmiterea s-a închis după verificarea raportului de nelivrare | Data depunerii declarată rămâne. | - | Raportează adresa ca greșită | raport | - |

### 3.4 Notele juridice vizibile

O notă juridică rămâne vizibilă în panou doar când schimbă decizia avocatului în acel moment. În toate celelalte cazuri stă în „Note juridice”, pliat.

- **Cerere nelivrată**: o singură propoziție vizibilă, într-un chenar roșu sub acțiuni: „Aplicația nu prelungește termenul de 6 luni pentru menținerea întreruperii prescripției (NCC art. 2540). Termenul se verifică înainte de noua depunere.” Restul textului (`filing.undelivered.deadline_warning`) e în „Note juridice”, deschis automat în această stare.
- **Reîncercări și eșecuri**: „Dacă un termen este aproape de expirare, depunerea pe altă cale este mai sigură decât o nouă încercare.” (o propoziție, în panou).
- Disclaimerul general („Platforma raportează doar răspunsul serverului de e-mail...”) apare o singură dată, în „Note juridice”, nu în fiecare bloc.

## 4. Componente

### 4.1 Etapele (stepper)

Patru pași: Pachet, Semnare, Trimitere, Data depunerii. Fiecare are un cerc (bifă verde când e încheiat, număr pe fundal bleumarin pentru pasul curent, „!” chihlimbar sau roșu pentru o problemă) și o linie de rezumat sub nume („Versiunea 2”, „1 din 3 fișiere gata”, „Acceptat 24.10.2026 16:11”, „Nedeclarată”). Stepper-ul nu este clicabil; rolul lui e orientarea.

### 4.2 Panoul de stare

Componenta centrală. Conține: pictogramă de ton, titlu (o propoziție, 18 px, semibold), explicație (1-2 propoziții), rând de butoane (o acțiune principală bleumarin, cel mult două secundare cu bordură), opțional o notă critică. Fundalul se colorează discret doar pentru chihlimbar și roșu. Acțiunile distructive (închide fără anexe) nu stau ca buton roșu în panou; deschid o fereastră de confirmare cu bifa declarației și buton roșu (mockup `e-anexa-refuzata.html#inchide`).

Acțiunile cu declarație (F1, rezultat necunoscut) păstrează bifa obligatorie, dar o pun direct deasupra butonului, în panou.

### 4.3 Cronologia pe e-mailuri

Un card „E-mailuri către instanță”, câte o linie pe e-mail: pictogramă de stare, „E-mailul 2 din 3”, conținutul („Anexe, volumul 2 din 3”), în dreapta starea în cuvinte („Acceptat de serverul instanței”, „Refuzat definitiv”, „Oprit, nu a plecat”, „Anulat, netrimis”, „Returnat: adresa nu există”) și ora. Încercările individuale, codurile și copiile .eml merg în „Detalii tehnice”. În stările de pachet, cardul este înlocuit de „Fișiere de semnat”.

### 4.4 Mesaje primite

Un card cu câte o linie pe mesaj: eticheta tipului („Confirmare de primire”, „Răspuns”, „Raport de nelivrare”, „Notificare de afișare”, „Livrare întârziată”), sursa scurtă („de la adresa instanței”, „de la serverul de e-mail al instanței”, „încărcată de avocat”), ora, „Descarcă”. Extrasul se afișează doar pentru răspunsuri și confirmări, sub linie, cu bordură stângă. Nota „verificare tehnică a expeditorului, nu atestare a instanței” trece în „Note juridice” o singură dată. Stare goală: „Niciun mesaj primit încă. Confirmarea de primire și răspunsurile instanței apar aici automat.” Linkul „Încarcă o confirmare primită pe altă cale” stă în antetul cardului.

### 4.5 Blocul instanței și al adresei

Coloana dreaptă: numele instanței, adresa (monospace), scopul și data confirmării, starea adresei când nu e normală („Raportată ca inexistentă, în verificare”), o propoziție despre efect, link „Adresele instanței”. Mediul de test apare ca o mențiune albastră mică în acest bloc, nu ca paragraf în fiecare stare.

### 4.6 Confirmarea trimiterii: pagină dedicată

Ruta propusă: o pagină separată „Verifică și trimite la instanță” (mockup `b-confirmare-trimitere.html`), cu breadcrumb spre dosar. Stânga: previzualizarea e-mailului ca într-un client de e-mail (Către, De la, Copie și răspuns, Subiect, textul complet fără casetă cu derulare, atașamentele ca fișe cu starea semnăturii). Dreapta, fixă la derulare: rezumatul (instanța, adresa, data dobânzii), avertismentele (ex. numele din certificat), cele două declarații nebifate, butonul „Trimite la instanță” (inactiv până la bifarea ambelor) și „Renunță”. Fraza despre ireversibilitate stă în subtitlul paginii. Alternativă, dacă se preferă rămânerea pe pagină: fereastră modală mare (max-w-5xl) cu aceeași structură.

### 4.7 Detalii tehnice

Un `details` pliat la baza coloanei principale, cu un rezumat în titlu („cod SMTP 554, 3 încercări, copii .eml”). Deschis: destinatarul efectiv și cel din producție (mediu de test), copia .eml pe fiecare e-mail, tabelul încercărilor (încercare, oră, sursă, rezultat, cod), raportul brut al serverului în monospace, nota „Orele sunt ora României și reprezintă răspunsul serverului de e-mail, nu data depunerii.” (mockup `d-esec-temporar.html`).

### 4.8 Note juridice

Un `details` pliat în coloana dreaptă, cu lista notelor relevante pentru starea curentă (nu toate notele în toate stările). Se deschide automat doar pentru „Cerere nelivrată”.

### 4.9 Pagina adresei instanței (avocat)

Mockup `i-adresa-instantei.html`. Ordinea nouă: titlul este numele instanței; un rând de explicație; cardul „Adresa folosită la depunere” cu adresa mare, starea și „Raportează adresa ca greșită”; tabelul „Toate adresele instanței” (adresă, scop, sursă, stare în cuvinte); formularul „Sugerează o adresă” (deschis din linkul din antetul tabelului); regulile („Cum se validează adresele”) pliate la final.

## 5. Ghid pentru texte

- Titlul spune ce s-a întâmplat, nu starea internă: „Cererea nu a ajuns la instanță: adresa nu există”, nu „E-mailul cu cererea a fost returnat de serverul de e-mail”.
- Explicația are cel mult două propoziții și răspunde la „ce înseamnă pentru dosar”.
- Butonul spune exact ce face: „Trimite din nou”, „Trimite anexele rămase”, „Salvează data depunerii”, „Confirmă verificarea”. Același verb rămâne în mesajul de confirmare („Anexele rămase au fost puse în așteptare.”).
- Codurile SMTP și numele tehnice (VERP, DSN, MDN) nu apar în panou; doar în „Detalii tehnice”.
- Texte impersonale, fără „dumneavoastră” și fără persoana a doua în explicații. Excepție: declarațiile la persoana I, care aparțin avocatului („Am verificat...”, „Declar că...”).
- Distincția juridică se păstrează în cuvinte simple: „acceptat de serverul instanței” (nu „primit de instanță”), „confirmare de primire” (nu „înregistrare”), „data depunerii declarată”.
- Fără liniuță lungă; fără majuscule pentru etichete.

Texte propuse (de comparat cu cheile actuale, nemodificate):

| Cheie actuală | Text actual | Text propus |
|---|---|---|
| `filing.status.headline.UNDELIVERED` | E-mailul cu cererea a fost returnat de serverul de e-mail | Cererea nu a ajuns la instanță: adresa nu există (pentru 5.1.1-5.1.3, 5.4.4); altfel „Cererea nu a ajuns la instanță” |
| `filing.status.headline.retrying` | Trimiterea nu a funcționat; se reîncearcă automat | Serverul instanței nu a primit încă e-mailul. Se reîncearcă automat |
| `filing.status.headline.FAILED_TEMPORARY_EXHAUSTED` | Transmiterea nu a reușit | E-mailul nu a putut fi trimis după 4 încercări |
| `filing.status.headline.stalled` | Cererea a fost trimisă, dar nu toate anexele au fost acceptate | Cererea a ajuns, dar nu și toate anexele |
| `filing.undelivered.late_headline` | Raport de nelivrare după avansarea dosarului | Verificare necesară: e-mailul cu cererea a fost returnat după depunere |
| `filing.undelivered.ack_cta` | Am verificat | Confirmă verificarea |
| `filing.status.transmitted_no_receipt` | Transmisă instanței, fără confirmare de primire | Cererea a fost trimisă. Se așteaptă confirmarea de primire |
| `filing.status.transmitted_with_receipt` | Transmisă instanței; a sosit o confirmare de primire (...) | A sosit confirmarea de primire de la instanță |
| `filing.uncertain.title` | E-mailul %k% din %n%: rezultat necunoscut | Nu se știe dacă e-mailul a ajuns. Verifică copia din CC |
| `filing.resume.cta` | Trimite părțile rămase | Trimite anexele rămase |
| `filing.send.submit` | Transmite | Trimite la instanță |
| `filing.send.cta` | Trimite la instanță | Verifică și trimite |

## 6. Ecranele de administrare (pe scurt)

- **Lista transmiterilor**: coloană de stare în cuvinte cu culoarea tonului de mai sus și filtru „Necesită acțiune” (incert, blocat, raport de adresă deschis).
- **Deblocarea operațională** (captura 15): sus, rezumatul dosarului (instanța, starea, ce a declarat avocatul); sub el, efectul fiecărei opțiuni scris înainte de aplicare („Dosarul revine la «Cerere generată». Nu se declară primirea și nu se schimbă data depunerii.”); butonul numit după acțiune („Marchează ca netrimis”), nu „Aplică”.
- **Adrese și rapoarte**: același vocabular ca la avocat („Confirmată”, „Neverificată”, „Scoasă din uz”, „Raportată, în verificare”), cu dosarele afectate de un raport deschis listate lângă raport.
- Conținutul răspunsurilor instanței rămâne invizibil adminului, ca acum.

## 7. Întrebări deschise

1. Fila nouă „Depunere la instanță” sau o secțiune lată în fila „Documente”? Propunerea preferă fila (un singur loc, link direct din notificări).
2. Confirmarea trimiterii: pagină dedicată sau fereastră modală mare? Pagina permite citirea completă a e-mailului și se poate deschide direct din notificare.
3. „Descarcă fișierele nesemnate (.zip)” într-o singură arhivă este o acțiune nouă; se acceptă?
4. „Nu am primit copia în CC”: textul propus spune că e-mailul se consideră netrimis și că cererea se poate trimite din nou sau depune pe altă cale. Codul marchează e-mailul ca eroare internă; trebuie confirmat ce acțiune primește avocatul imediat după (reluare directă sau așteptarea adminului).
5. Formularea declarației pentru F1 („Am verificat situația cererii la instanță după acest raport de nelivrare”) diferă de `filing.undelivered.ack_statement`; are nevoie de validarea avocatului.
6. Nota despre prescripție: este suficientă propoziția scurtă vizibilă, cu textul complet în „Note juridice” deschis automat? Textul este marcat provizoriu și în curs de validare juridică.
7. Butonul din antetul dosarului: să ducă la filă (propunerea) sau să execute direct acțiunea în stările fără declarație (ex. „Trimite din nou”)?
8. Câmpul de dată din panoul de stare (după trimitere): se păstrează inline sau se folosește o fereastră modală, ca la celelalte date ale dosarului?

## 8. Decizii (24.10.2026)

| # | Întrebare | Decizie |
|---|---|---|
| 1 | Filă sau secțiune în „Documente” | Filă nouă „Depunere la instanță”; în „Documente” rămâne cardul compact cu link |
| 2 | Confirmarea trimiterii | Pagină separată „Verifică și trimite la instanță” |
| 3 | Arhiva .zip cu fișierele nesemnate | Da, pe lângă descărcarea fiecărui fișier |
| 4 | După „Nu am primit copia în CC” | Textul descrie exact comportamentul actual al codului (fără acțiuni noi) |
| 5 | Declarația pentru F1 | Rămâne textul actual `filing.undelivered.ack_statement`; formularea nouă trece la întrebările pentru avocat |
| 6 | Nota despre prescripție | Propoziția scurtă vizibilă, textul complet în „Note juridice” deschis automat; textul rămâne provizoriu |
| 7 | Butonul din antetul dosarului | Duce la filă; nu execută acțiuni direct |
| 8 | Data depunerii | Direct în panoul de stare |
