# Verificare live: trimiterea la instanță prin e-mail și adresele instanțelor

Data: 24.10.2026. Testat pe 8 dosare clonă `TMP-LIVE-*` (din dosarul 55), pe branch-ul `feature/trimitere-email-instanta`. Trimiterea folosește transportul simulat: e-mailurile ajung doar în Mailpit, la adresa de simulare. Mesajele instanței sunt generate de simulatorul de dev (`app:filing:simulate-court-reply`). Clonele au fost șterse după test, iar datele de dev au fost readuse la starea inițială.

Setări temporare folosite doar în timpul testului și refăcute la final:
- refuzurile SMTP au fost simulate cu funcția „chaos” a Mailpit;
- intervalele de reîncercare au fost scurtate la câteva secunde;
- limita de dimensiune a unui e-mail a fost micșorată, ca anexele să se împartă în 3 volume (scenariile cu mai multe e-mailuri și F1).

Capturile sunt grupate în subfoldere, în ordinea fluxului.

## 01-pachet-depunere

| Captură | Ce arată | Rezultat |
|---|---|---|
| `01-card-pachet-inainte-de-construire.png` | Cardul „Pachet de depunere” cu adresa de depunere și butonul „Construiește pachetul” | ✅ |
| `02-pachet-construit-trei-fisiere-nesemnate.png` | Pachetul v1: cererea cu opisul, împuternicirea și anexele, fiecare cu „Descarcă (nesemnat)” și încărcarea fișierului semnat | ✅ |
| `03-cererea-cu-opisul-in-acelasi-pdf.png` | Opisul e în același PDF cu cererea (pct. 9) | ✅ |
| `04-anexe-pagina-de-garda-conform-cu-originalul.png` | Pagina de gardă a anexelor: instanța, părțile, paginile 1-11 și mențiunea „conform cu originalul” (text provizoriu) | ✅ |
| `05-anexe-numerotare-pagini.png` | Numerotarea paginilor anexelor, în colțul din dreapta jos | ✅ |
| `06-pachet-neactualizat-dupa-modificare.png` | După modificarea împuternicirii: „Neactualizat · v1”, cu motivul afișat | ✅ |
| `07-pachet-reconstruit-versiunea-2.png` | Pachetul reconstruit: „Construit · v2” | ✅ |
| `08-anexe-impartite-in-trei-volume.png` | Anexe mari: 3 volume, 3 e-mailuri, numerotare continuă | ✅ |

## 02-fisiere-semnate

| Captură | Ce arată | Rezultat |
|---|---|---|
| `01-fisier-nesemnat-refuzat.png` | Fișier nesemnat: „Nu a fost detectată nicio semnătură în fișier.” | ✅ refuzat |
| `02-fisier-modificat-dupa-semnare-refuzat.png` | Un octet schimbat după semnare: „Conținutul fișierului s-a modificat după semnare...” | ✅ refuzat |
| `03-potrivire-probabila-cere-confirmarea-avocatului.png` | Fișier rescris înainte de semnare: document recunoscut prin text, cere confirmarea avocatului; avertisment că numele din certificat diferă | ✅ |
| `04-fisier-semnat-corect-acceptat.png` | Fișierul semnat corect: „semnătură detectată” | ✅ |
| `05-pachet-semnat-toate-fisierele.png` | Pachetul: „Semnături detectate · v2” | ✅ |
| Verificare fără captură | Adminul nu poate descărca fișierele avocatului (403) | ✅ |

## 03-adrese-instante

| Captură | Ce arată | Rezultat |
|---|---|---|
| `01-pagina-avocatului-adresa-de-depunere.png` | Pagina avocatului: doar adminul validează; avocatul poate sugera sau raporta | ✅ |
| `02-sugestie-avocat-ramane-neverificata.png` | Sugestia intră ca „Sugestie avocat, neverificată”, nefolosită la depunere | ✅ |
| `03-raport-adresa-gresita-ramane-in-uz.png` | Raportul ajunge la admin; adresa rămâne în uz până la decizia lui | ✅ |
| `04-admin-tablou-de-bord-contoare-adrese.png` | Tabloul de bord al adminului, cu contoarele pentru adrese | ✅ |
| `05-admin-coada-rapoarte.png` | Coada de rapoarte, cu tipul și motivul traduse | ✅ |
| `06-admin-lista-adrese-cu-sugestia-avocatului.png` | Lista adreselor, cu sugestia avocatului ca propusă | ✅ (vezi observația 6) |
| `07-admin-respinge-sugestia-avocatului.png` | Respingerea sugestiei | ✅ adresa devine „Respinsă” |
| `08-admin-adauga-adresa.png`, `09-admin-adresa-adaugata-ca-propusa.png` | Adăugarea unei adrese de către admin, ca propusă | ✅ |
| `10-admin-confirma-adresa.png` | Confirmarea, cu declarația adminului că a verificat adresa pe sursă | ✅ |
| `11-admin-scoate-adresa-din-uz.png` | Scoaterea din uz, cu motiv | ✅ |
| `12-admin-respinge-raportul-avocatului.png`, `13-admin-raport-decis.png` | Respingerea raportului: „Respins, adresa rămâne în uz” | ✅ |
| Verificare fără captură | Adminul primește notificări pentru sugestie și pentru raport | ✅ |

## 04-trimitere

| Captură | Ce arată | Rezultat |
|---|---|---|
| `01-fereastra-trimite-la-instanta.png` | Panoul de trimitere: mediu de test, instanța, adresa, expeditorul și copia, subiectul | ✅ |
| `02-declaratiile-avocatului-bifate.png` | Declarațiile avocatului, obligatorii | ✅ |
| `03-in-curs-de-transmitere-dosar-blocat.png`, `04-cardul-transmiterii-in-coada.png` | E-mail în așteptare; dosarul blocat, butoanele ascunse | ✅ |
| `05-antet-transmisa-instantei-fara-reincarcare.png` | Antetul trece singur în „Transmisă instanței” când serverul acceptă e-mailul, fără reîncărcarea paginii | ✅ |
| `06-cardul-transmiterii-acceptat-de-server.png` | „acceptat de serverul de e-mail”, copia `.eml`, data depunerii se declară de avocat | ✅ |
| `07-mailpit-emailul-catre-instanta.png`, `08-emailul-corp-destinatar-copie-anexe.png` | E-mailul: către adresa de simulare, avocatul în Cc și Reply-To, 3 anexe, formularea aprobată pentru taxă (pct. 6), datele împuternicirii | ✅ |
| `09-emailul-anteturi.png` | Antetele: Return-Path de tip VERP, Disposition-Notification-To, X-Lexrecovery-Ref | ✅ |
| `10-stabileste-data-depunerii.png`, `11-cerere-depusa-cu-data-declarata.png` | Avocatul declară data depunerii; dosarul arată „Cerere depusă” | ✅ |

## 05-esecuri-trimitere

| Captură | Ce arată | Rezultat |
|---|---|---|
| `01-refuz-temporar-reincercare-programata.png` | Refuz temporar 451: reîncercare automată programată | ✅ |
| `02-reincercari-epuizate-dupa-4-incercari.png` | După 4 încercări: „Transmiterea nu a reușit”; avocatul primește 2 notificări | ✅ |
| `03-retrimis-cu-acelasi-pachet-semnat-acceptat.png` | Retrimiterea cu același pachet semnat: acceptat | ✅ |
| `04-refuz-definitiv-550-adresa-scoasa-din-uz.png`, `05-pagina-adrese-adresa-invalida-fara-adresa-de-depunere.png` | Refuz 550 la destinatar (refuzul serverului nostru): adresa scoasă automat din uz, dosarul rămâne fără adresă de depunere | ✅ |
| `06-refuz-pentru-dimensiune-552.png` | Refuz 552 pentru dimensiune; un singur e-mail, deci o nouă împărțire nu ajută și cardul o spune | ✅ |
| `07-anexa-refuzata-optiuni-reluare-sau-inchidere.png` | 3 e-mailuri: primul acceptat, al doilea refuzat definitiv (554), al treilea în așteptare. Opțiuni: „Trimite părțile rămase” sau „Închide fără anexele rămase” | ✅ |
| `08-partile-ramase-retrimise-toate-acceptate.png` | Părțile rămase retrimise: toate 3 acceptate | ✅ |
| `17-inchis-fara-anexele-ramase.png` | Varianta „Închide fără anexele rămase”: al treilea e-mail anulat, anexele rămase se depun pe altă cale | ✅ |
| `09-cerere-nelivrata-in-timpul-anexelor-transmitere-anulata.png` | Cererea revine nelivrată cât anexele încă așteaptă, înainte de orice avansare a dosarului: transmiterea se anulează, e-mailurile rămase se anulează, dosarul revine în „cerere generată”, adresa raportată adminului | ✅ |
| `10-F1-cerere-nelivrata-dupa-data-depunerii-verificare-deschisa.png` | **F1**: aceeași nelivrare după ce avocatul a declarat data depunerii: se deschide o verificare, e-mailurile rămase se anulează, dosarul nu se mai blochează definitiv | ✅ |
| `11-F1-avocatul-confirma-verificarea-dosar-deblocat.png` | După „Am verificat”: transmiterea se închide și dosarul se deblochează | ✅ (vezi observația 1) |
| `12-rezultat-incert-avocatul-declara-din-copia-cc.png`, `13-copia-cc-declarata-cererea-considerata-trimisa.png` | Rezultat incert: avocatul declară dacă a primit copia CC; după „Am primit copia în CC”, cererea e considerată trimisă | ✅ ecranele și acțiunea. Starea „incert” a fost pusă direct în baza de date: întreruperea conexiunii după trimitere nu se poate simula din Mailpit; detectarea e acoperită de teste automate |
| `14-admin-lista-transmiterilor.png`, `15-admin-deblocare-transmitere-incerta.png`, `16-admin-dupa-deblocare.png` | Deblocarea din admin, cu motiv obligatoriu, înregistrată în jurnalul de audit; dosarul revine în „cerere generată” | ✅ (vezi observația 4) |

## 06-mesaje-primite

| Captură | Ce arată | Rezultat |
|---|---|---|
| `01-confirmare-automata-de-primire-data-propusa.png` | Confirmarea automată de primire, atribuită sigur transmiterii; înregistrarea se verifică pe portal | ✅ |
| `02-citire-raspuns-intarziere-anexa-nelivrata.png` | Pe același dosar: notificarea de afișare, răspunsul instanței, livrarea întârziată (fără efect) și nelivrarea anexei 2 (transmiterea devine „fără toate anexele”) | ✅ (vezi observația 2) |
| `03-mailpit-mesaje-simulate-si-redirectionarea-catre-avocat.png`, `04-raspunsul-instantei-redirectionat-avocatului.png` | Mesajele simulate și redirecționarea răspunsului către avocat, cu mesajul original atașat | ✅ |
| `05-incarcare-manuala-confirmare-eml.png`, `06-confirmare-eml-cu-data-dinainte-de-trimitere-ignorata.png` | Confirmare `.eml` încărcată manual, cu data dinaintea trimiterii: data e ignorată, corect | ✅ (vezi observația 3) |
| `07-confirmare-eml-cu-data-din-antet-propusa.png` | Confirmare `.eml` cu data după trimitere: „Data confirmării, preluată din antetul mesajului .eml: 24.10.2026 16:20” | ✅ |
| `08-notificari-avocat-confirmare-intarziata-si-celelalte.png` | Notificările avocatului, inclusiv „Fără confirmare de primire” (comanda `receipt-overdue`) | ✅ |
| `09-notificare-admin-casuta-necitita.png` | Notificarea adminului: căsuța de mesaje nu a mai fost citită (comanda `inbound-heartbeat-check`) | ✅ |
| `10-admin-lista-mesaje-primite.png`, `11-admin-detaliu-raspuns-fara-continutul-mesajului.png` | Mesajele primite în admin; adminul nu vede conținutul răspunsului instanței | ✅ (vezi observația 5) |

## 07-reparatii-observatii: observații găsite la test și reparate (24.10.2026)

| # | Observație | Reparație | Captură | Rezultat |
|---|---|---|---|---|
| 1 | După F1, cardul spunea „Cererea a fost trimisă...” și „Refuzul nu privește neapărat adresa instanței”, deși cererea revenise nelivrată cu adresa inexistentă | Titlu nou: „E-mailul cu cererea a fost returnat după avansarea dosarului; transmiterea s-a închis”. Pentru codurile 5.1.1-5.1.3 și 5.4.4 textul spune că raportul indică o adresă inexistentă, care se verifică cu registratura (sau că a fost raportată adminului, dacă s-a creat un raport) | `01-dupa-F1-titlu-cererea-returnata-cod-si-adresa-inexistenta.png` | ✅ |
| 2 | „Cod SMTP: - 5.1.1” | Se afișează doar codurile existente: „Cod SMTP: 5.1.1” | aceeași captură | ✅ |
| 3 | `.eml` cu data dinaintea trimiterii: textul vorbea de „PDF sau imagine” | Text propriu: data din antetul `.eml` nu corespunde transmiterii, deci nu a fost preluată | `02-eml-cu-data-dinainte-de-trimitere-text-corect.png` | ✅ |
| 4 | Pagina de deblocare din admin afișa valori netraduse | Starea, motivul, starea e-mailului, sursa și rezultatul încercărilor apar traduse | `03-admin-deblocare-stari-traduse.png` | ✅ |
| 5 | În lista de mesaje primite din admin, confirmarea încărcată de avocat apărea ca „Confirmare automată de primire” | Apare „Confirmare de primire” | `04-admin-mesaje-primite-confirmare-incarcata-de-avocat.png` | ✅ |
| 6 | Lista de adrese din admin afișa REGISTRY, VALIDATED, MANUAL | Scopul, statusul și sursa apar traduse („Registratură”, „Confirmată”, „Introdusă manual (admin)”). Datele în format englezesc rămân, ca în tot admin-ul | `05-admin-lista-adrese-traduse.png` | ✅ |
