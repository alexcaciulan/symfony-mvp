# Verificare live: cardul „Fișiere de semnat” după machetele aprobate (25.10.2026)

Dosar clonă `TMP-LIVE-UXS` din dosarul 55 (șters la final, în ordinea FK; abonamentul 37 readus la `2026-10-21 00:00:00`). Dosarele 170-173 nu au fost atinse. Semnăturile sunt făcute în container cu certificatul de test (`TestPdfSigner`, nume „Test Avocat”), deci capturile nu conțin numele real al proprietarului. Starea „peste limita e-mailului” și volumele de anexe au fost puse direct în baza de date a clonei (limita reală a e-mailului nu se atinge cu fișierele de test); restul stărilor vin din încărcări reale prin interfață.

Macheta de referință: `docs/LexRecovery/mockups/v2/depunere/semnare/`.

| Captură | Machetă | Ce arată | Verificat în browser |
|---|---|---|---|
| `a-construit/a-construit-desktop.png`, `-telefon.png` | a | 3 fișiere nesemnate, o acțiune pe rând, „Descarcă toate (.zip)” în antet, fără selectorul nativ | coloane aliniate; la 390 px fără derulare orizontală (scrollWidth 390); butonul de rezervă ascuns de JS |
| `a2-incarcare/a2-tragere-desktop.png` | a2 | Fișier tras peste „Împuternicirea avocațială”: zona de plasare doar pe rândul acela | suprapunerea apare pe 1 din 3 rânduri |
| `a2-incarcare/a2-se-verifica-desktop.png` | a2 | Încărcare pornită la alegerea fișierului: „Se verifică”, iconul rotitor, pastila albastră | rândul se actualizează fără reîncărcare, apare toast-ul |
| `b-mixt/b-mixt-desktop.png` | b | Cerere cu semnătură detectată, împuternicire în așteptarea confirmării (rezumat într-un rând + „Detalii”), anexe nesemnate | fișierul tras a ajuns doar pe rândul împuternicirii |
| `b-mixt/b2-meniu-descarca-desktop.png` | b2 | Meniul „Descarcă” deschis din tastatură (Enter) | `aria-expanded=true`; săgeata jos și Home mută focusul; Escape închide și întoarce focusul pe buton |
| `c-confirmare/c-confirmare-desktop.png`, `-telefon.png` | c | Panoul de confirmare deschis implicit: toate observațiile cu text complet (nota cu cifre îngroșată, fundal mai închis), numele din certificat, ora încărcării, apoi butonul de confirmare și „Înlocuiește” | „Verifică și confirmă” doar deschide panoul și mută focusul pe rezumat; confirmarea schimbă pastila în „Semnătură detectată, document confirmat” |
| `c-confirmare/c2-balon-tastatura-desktop.png` | c2 | Explicația „De ce este necesară confirmarea”, deschisă din tastatură | se deschide la focus, se închide cu Escape; conturul de focus de 2 px pe eticheta de încărcare |
| `d-refuzuri/d-refuzuri-dupa-reincarcare-desktop.png`, `-telefon.png` | d | După reîncărcarea paginii: anexa refuzată ca „diferit de cel generat” (rândul rămâne nesemnat) și cererea cu înlocuire refuzată (pastila rămâne verde, „Fișierul semnat anterior rămâne în vigoare.”) | `role="alert"`, „Cum se rezolvă”, „Refuzat la …”, fără numele fișierului încărcat |
| `d-refuzuri/d2-peste-limita-email-desktop.png` | d | Fișier peste limita e-mailului: chenar galben cu „Reîmparte anexele” și „Încarcă din nou” | „Reîmparte anexele” a creat versiunea 2: cererea și împuternicirea și-au păstrat semnăturile, anexele noi nesemnate, refuzurile nu s-au copiat |
| `e-semnate/e-restrans-desktop.png`, `-telefon.png` | e | Card restrâns: rezumatul fără „gata de trimis”, „Descarcă toate (.zip)” vizibil, avertismentul de validitate vizibil fără deplierea listei | panoul de stare de deasupra păstrează singura acțiune „Verifică și trimite”; cardul nu o repetă |
| `e-semnate/e2-lista-afisata-desktop.png` | e2 | „Afișează fișierele (3)” deschis | rândurile au „Înlocuiește”, meniul „Descarcă” și observațiile |
| `f-volume/f-volume-desktop.png`, `-telefon.png` | f | Rând de grup „Anexele, în 2 volume.”, intervalele de pagini 1-11 și 12-20, „Copie comprimată” cu explicația deschisă din tastatură | linia de volume din „Detalii tehnice” rămâne |
| `stale/stale-desktop.png`, `-telefon.png` | (STALE) | „Neactualizat · v2”: fără .zip, fără încărcare, fără „Înlocuiește”, refuzul existent ascuns, doar descărcări | 0 formulare de încărcare, 0 alerte |
| `admin/admin-desktop.png` | (admin) | Adminul vede cardul doar pentru citire | 0 linkuri `/filing/package/`, 0 formulare, fără .zip, fără meniu; „Fișierele se descarcă doar de avocatul dosarului.” |
| `fara-js/fara-js-meniu-si-buton-desktop.png` | (fără JS) | JavaScript dezactivat: butonul „Trimite fișierul” vizibil, meniul „Descarcă” se deschide ca listă cu ambele linkuri | butonul de rezervă vizibil, 2 linkuri în meniu |
| `fara-js/fara-js-dupa-trimitere-desktop.png` | (fără JS) | După trimiterea clasică: redirect la `?tab=depunere`, rândul arată refuzul | URL final `/case/175?tab=depunere`, `role="alert"` pe rând |
| `dark/dark-refuz-volume-desktop.png` | (temă închisă) | Culorile noi în tema închisă: pastile, chenar de refuz, rând de grup | lizibil |

## Verificarea orchestratorului (25.10.2026, subfolderul `verificarea-mea/`)

Parcurs complet prin interfață, pe o clonă separată (ștearsă după test), cu fișiere semnate cu certificatul de test.

| Captură | Ce am verificat | Rezultat |
|---|---|---|
| `01-cerere-incarcata-prin-buton.png` | „Încarcă semnat” deschide alegerea fișierului; încărcarea pornește singură; cererea semnată peste original: „Semnătură detectată” | ✅ |
| `02-anexe-nesemnate-refuz-dupa-reincarcare.png` | Fișier nesemnat: refuz pe rând cu „Cum se rezolvă” și ora; rămâne după reîncărcarea paginii | ✅ |
| `03-imputernicire-semnatura-vizibila-confirmare-necesara.png` | Fișier rescris, cu semnătură vizibilă peste text (ca fișierul real al proprietarului): „Confirmarea documentului este necesară”, panoul cu observațiile deschis, butonul de confirmare la final | ✅ |
| (fără captură) | Confirmarea: rândul trece în „Semnătură detectată, document confirmat” | ✅ |
| (fără captură) | Anexele semnate încărcate cu „Încarcă din nou”: refuzul dispare | ✅ |
| `04-toate-semnate-card-restrans.png`, `05-lista-afisata.png` | Toate semnate: card restrâns, „Afișează fișierele (3)”, arhiva .zip, panoul de stare cu „Verifică și trimite” | ✅ |
| `06-meniu-descarca-deschis.png` | Meniul „Descarcă” cu fișierul semnat și cel nesemnat; Escape îl închide și readuce focusul | ✅ |
| (fără captură) | Toate descărcările răspund corect: arhiva .zip, fișierele semnate și nesemnate (200) | ✅ |
| `07-telefon-lista.png` | La 390 px nimic nu iese din ecran | ✅ |
| (fără captură) | „Verifică și trimite” duce la pagina „Verifică și trimite la instanță” | ✅ |
