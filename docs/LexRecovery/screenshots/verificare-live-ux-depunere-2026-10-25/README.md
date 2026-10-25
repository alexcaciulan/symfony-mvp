# Verificare live: fila „Depunere la instanță” (redesign UX)

Data: 25.10.2026. Branch `feature/trimitere-email-instanta`, stack Docker de dezvoltare, Mailpit ca singur SMTP, nicio cerere externă.

Dosare folosite: clone `TMP-LIVE-UXA` (164), `TMP-LIVE-UXB` (165), `TMP-LIVE-UXC` (166) ale dosarului 55 (status inițial „Somație trimisă”, comunicare 01.09.2026 prin executor, taxă amânată), cu somația și dovada comunicării copiate din dosarul 55 și cu factură și împuternicire (seria B nr. 654321 din 05.01.2026) adăugate. Cererea s-a generat din interfață, pachetul s-a construit din filă, fișierele s-au semnat cu `TestPdfSigner` și s-au încărcat semnate. Mesajele instanței sunt produse de `app:filing:simulate-court-reply` și citite cu `app:filing:poll-inbound`. Abonamentul 37 a fost prelungit temporar și readus la final; clonele au fost șterse.

| Captură | Stare (cod panou) | Machetă comparată |
|---|---|---|
| `01-pachet/01-documente-card-compact-fara-pachet.png` | Fila „Documente” cu cardul compact, fără pachet; antetul păstrează confirmarea manuală | `j-documente-card-compact.html` |
| `01-pachet/02-fila-depunere-pachet-neconstruit.png` | `no_package`: „Construiește pachetul de depunere” | `a-pachet-de-semnat.html` (etapa 1) |
| `01-pachet/03-pachet-neactualizat-dupa-incarcare-document-fara-reincarcare.png` | `stale`: încărcarea unui document actualizează fila prin Turbo Stream, fără reîncărcare | `a-pachet-de-semnat.html` |
| `02-semnare/01-pachet-construit-fisiere-de-semnat-pagina-completa.png` | `to_sign`, 0 din 3; antetul duce la filă („Continuă semnarea”) | `a-pachet-de-semnat.html` |
| `02-semnare/02-un-fisier-semnat-din-trei.png` | `to_sign`, 1 din 3, „semnătură detectată” | `a-pachet-de-semnat.html` |
| `02-semnare/03-pachet-semnat-gata-de-trimis.png` | `ready`: „Verifică și trimite” | `a-pachet-de-semnat.html` |
| `03-confirmare/01-verifica-si-trimite-buton-inactiv.png` | Pagina „Verifică și trimite la instanță”: e-mailul integral, mediul de test, declarația nebifată, buton inactiv | `b-confirmare-trimitere.html` |
| `04-trimisa/00-in-asteptare-se-trimite-pagina-se-actualizeaza.png` | `sending` (worker oprit temporar): panoul se actualizează singur prin poll | `c-trimisa-fara-confirmare.html` |
| `04-trimisa/01-dupa-trimitere-redirect-in-fila.png` | După trimitere: redirect 303 în filă | `c-trimisa-fara-confirmare.html` |
| `04-trimisa/02-trimisa-fara-confirmare-data-in-panou.png` | `sent_no_receipt`: data depunerii în panou, nesalvată | `c-trimisa-fara-confirmare.html` |
| `04-trimisa/03-confirmare-primita-data-propusa.png` | `receipt_received`: data propusă din confirmare, salvarea cere acțiune | `c-trimisa-confirmare.html` |
| `04-trimisa/04-data-declarata.png` | `filed`: „Cerere depusă pe … (dată declarată)”, fără punct pe filă | `c-trimisa-confirmare.html` |
| `05-esecuri/01-F1-returnat-dupa-depunere.png` | `late_review` (F1): bifa declarației actuale deasupra „Confirmă verificarea” | `g-returnat-dupa-depunere.html` |
| `05-esecuri/02-F1-verificare-confirmata-transmitere-inchisa.png` | `petition_returned_closed` | `g-returnat-dupa-depunere.html` |
| `05-esecuri/03-cerere-nelivrata-adresa-nu-exista.png` | `undelivered` (5.1.1): chenar roșu cu NCC art. 2540, „Note juridice” deschise, punct roșu | `f-cerere-nelivrata.html` |
| `05-esecuri/04-rezultat-necunoscut-doua-declaratii-cc.png` | `uncertain`: două carduri egale | `h-rezultat-incert.html` |
| `05-esecuri/05-dupa-declaratia-copie-cc-neprimita.png` | `cc_not_received_petition`: textul descrie codul actual, „Verifică și trimite” din nou | `h-rezultat-incert.html` |
| `05-esecuri/06-documente-card-compact-cerere-nelivrata.png` | Cardul compact din „Documente” pentru cererea nelivrată | `j-documente-card-compact.html` |
| `06-mesaje/01-mesaje-primite-detalii-tehnice-si-note-deschise.png` | Mesaje primite (un rând per mesaj), „Detalii tehnice” și „Note juridice” deschise manual | `d-esec-temporar.html` |
| `07-adresa-instantei/01-pagina-adresei-instantei.png` | Pagina adresei instanței în ordinea nouă | `i-adresa-instantei.html` |
| `08-admin/01-deblocare-efect-scris-inainte-buton-numit.png` | Deblocarea admin: efectul scris înainte, buton „Marchează ca netrimis” | secțiunea 6 a propunerii |
| `08-admin/02-admin-vede-fila-fara-descarcari.png` | Adminul vede fila fără descărcări și fără extrase | - |

Note despre simulare:

- Rezultatul necunoscut (`05-esecuri/04`) nu are simulator: transmiterea dosarului 166 a fost pusă în așteptare cu workerul oprit, apoi e-mailul a fost marcat direct în baza de date ca incert (încercare SMTP „Incert”, transmitere „Necesită verificare”). Declarația „Nu am primit copia în CC” din captura 05 a trecut prin codul real.
- Stările cu mai multe e-mailuri (anexă refuzată, fereastra „Închide fără anexele rămase”, reluarea anexelor) și reîncercările temporare nu au putut fi reproduse live: pachetele de test intră într-un singur e-mail, iar simulatorul nu produce erori SMTP temporare. Sunt acoperite de `CaseFilingTabTest` și `FilingPanelStateResolverTest`.
- Arhiva „Descarcă fișierele nesemnate (.zip)” a fost descărcată din filă pe dosarul 164: `application/zip`, `Pachet_TMP-LIVE-UXA_v1_nesemnat.zip`, rând de audit `filing_package_unsigned_archive_downloaded`, pachet trecut din „Construit” în „Așteaptă semnături”.

## 09-mai-multe-emailuri-si-reincercari (verificare suplimentară, 25.10.2026)

Stări care nu fuseseră verificate live: pachete împărțite în 3 e-mailuri, refuzuri simulate cu funcția „chaos” a Mailpit, intervale de reîncercare scurtate temporar. Setările temporare au fost refăcute la final.

| Captură | Ce arată | Rezultat |
|---|---|---|
| `01-reincercare-automata-in-curs.png` | Refuz temporar 451: „Serverul instanței nu a acceptat încă e-mailul 1 din 1. Se reîncearcă automat”, ora următoarei încercări, fără acțiune obligatorie | ✅ |
| `02-reincercari-epuizate-trimite-din-nou.png` | După reîncercări: „E-mailul nu a putut fi trimis după încercările automate”, acțiunea „Verifică și trimite” | ✅ |
| `03-anexa-refuzata-trimite-anexele-ramase.png` | E-mailul 1 acceptat, al 2-lea refuzat definitiv, al 3-lea în așteptare: „Cererea a fost trimisă, dar nu și toate anexele”, cu „Trimite anexele rămase” și „Închide fără anexele rămase” | ✅ după reparație (vezi mai jos) |
| `04-fereastra-inchide-fara-anexele-ramase.png` | Fereastra de confirmare pentru închiderea fără anexele rămase | ✅ |
| `05-anexele-retrimise-toate-acceptate.png` | După „Trimite anexele rămase”: toate e-mailurile acceptate, se așteaptă confirmarea de primire | ✅ |
| `06-inchisa-fara-anexele-ramase.png` | După închidere: „Cererea a fost trimisă fără toate anexele”, data depunerii în panou | ✅ |

Defect găsit la această verificare și reparat: când un e-mail cu anexe era refuzat definitiv și un e-mail ulterior rămânea în așteptare, panoul arăta „Se trimite la instanță: e-mailul 2 din 3” fără nicio acțiune (trimiterea se oprește la e-mailul refuzat, deci cel următor nu mai pleacă singur). Acum starea este recunoscută ca oprită și oferă reluarea sau închiderea. Test nou: `FilingPanelStateResolverTest::testARefusedAnnexWithLaterEmailsPendingAsksToResumeOrClose`.
