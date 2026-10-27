# Cardul „Fișiere de semnat”: propunere de reproiectare

Mockup-uri statice (Tailwind prin CDN) pentru cardul din fila „Depunere la instanță”. Cardul actual este în `templates/case/filing/_parts_to_sign.html.twig`. Paginile se generează din `kit.js` (aceeași structură ca `../shell.js`, plus comportament pe telefon); fiecare pagină conține doar datele ei în `window.PAGE`.

Navigare: `php -S 127.0.0.1:8888 -t docs/LexRecovery/mockups`, apoi http://localhost:8888/v2/depunere/semnare/

Capturi în `screenshots/`, câte una la 1440 px (`-desktop`) și la 390 px (`-telefon`).

| Ecran | Fișier | Ce arată |
|---|---|---|
| a | `a-pachet-construit.html` | 3 fișiere nesemnate, o acțiune pe rând, „Descarcă toate (.zip)” în antetul cardului |
| a2 | `a2-incarcare-in-curs.html` | Fișier tras peste rând (zonă de plasare) și verificare în curs |
| b | `b-mixt.html` | Cerere gata, împuternicire în așteptarea confirmării, anexe nesemnate; b2: meniul „Descarcă” deschis |
| c | `c-confirmare-deschisa.html` | Panoul de confirmare deschis; c2: explicația din iconul de informare, deschisă din tastatură |
| d | `d-incarcari-refuzate.html` | Fișier diferit de cel generat; fișier prea mare pentru e-mail, cu „Reîmparte anexele” |
| e | `e-toate-semnate.html` | Card restrâns la rezumat cu „Verifică și trimite”; e2: lista afișată, rânduri compacte |
| f | `f-anexe-in-volume.html` | Anexe în 3 volume (5 rânduri), copie comprimată, document neinclus |

## Anatomia rândului

Grilă cu trei coloane, identică pe toate rândurile:

1. **Iconul de stare** (36 px, stânga): document gri (nesemnat), bifă verde (semnătură detectată, cu sau fără confirmare), triunghi galben (confirmare necesară), cerc roșu cu x (încărcare refuzată), săgeți albastre rotitoare (verificare în curs). Starea nu se transmite doar prin culoare: iconul și textul pastilei o spun și ele.
2. **Titlul și datele fișierului**: titlul pe primul rând, dedesubt paginile, dimensiunea, intervalul din anexe și eticheta „Copie comprimată”. Fiecare bucată nu se rupe pe două rânduri (de exemplu „paginile 1-11” rămâne întreg).
3. **Acțiunile, aliniate la dreapta, pe două coloane cu lățime fixă**:
   - coloana acțiunii rândului (176 px): „Încarcă semnat”, „Verifică și confirmă”, „Încarcă din nou” sau „Înlocuiește” (discret, pentru fișierele gata). Când rândul nu are acțiune, coloana rămâne goală, ca „Descarcă” să stea pe aceeași verticală;
   - coloana „Descarcă” (132 px): buton simplu cât există doar fișierul nesemnat; meniu cu „Fișierul semnat” (cu data încărcării) și „Fișierul nesemnat” după încărcarea fișierului semnat.

Sub titlu, pe toată lățimea rândului (coloanele 2 și 3), stă **linia de stare**: pastila stării, numele din certificat („Certificat: Test Avocat”) și, dacă există, „N observații” ca buton care deschide lista. Dedesubt, la cerere, panoul rândului (observații, confirmare sau refuz).

Selectorul nativ „Choose File / no file selected” dispare: butonul „Încarcă semnat” este o etichetă care deschide selectorul de fișiere, iar alegerea fișierului pornește direct încărcarea (fără al doilea buton „Încarcă”). Rândul acceptă și fișierul tras peste el; în timpul tragerii, rândul arată „Eliberează fișierul pentru a-l încărca la „Împuternicirea avocațială””. Fără JavaScript, formularul afișează în continuare un buton de trimitere.

## Ce este restrâns și unde

| Informație | Azi | Propunere |
|---|---|---|
| „Prezența unei semnături nu garantează validitatea calificată…” | Repetat sub fiecare rând semnat | O singură dată, în subsolul cardului, cu icon de informare, vizibil cât timp există cel puțin o semnătură detectată; nu se poate închide |
| Avertismentele semnăturii (`cn_differs`, `cert_not_valid_at_indicated_date`, `timestamp_appended`, `trailing_update`, `textless_pages`, `legacy_sub_filter`) | Rânduri galbene cu icon, unul sub altul | Buton „N observații” pe linia de stare; textele complete, fiecare cu iconul lui, se deschid sub rând |
| Notele comparării (`text_added`, `text_added_with_digits`, `pages_appended`, `part_id_missing`) | La fel | Pe rândul care cere confirmare: rezumat într-un rând („3 observații: text adăugat în pagina 4, o pagină adăugată la final, nume diferit în certificat.”) și „Detalii”. Nota cu cifre are fundal mai închis și text îngroșat |
| Butonul de confirmare | Lipit de rând, cu eticheta lungă | În panoul „Detalii”, după observații, numele din certificat și numele fișierului; butonul din coloana de acțiuni („Verifică și confirmă”) doar deschide panoul, deci confirmarea se face după citire |
| Numele din certificat | În propoziția de stare | Pe linia de stare, separat; în panoul de confirmare, ca rând „Numele din certificat” |
| Refuz (fișier diferit, nesemnat, deteriorat, format neacceptat) | Mesaj flash, sus | Chenar roșu pe rândul fișierului: ce s-a întâmplat, „Cum se rezolvă”, numele fișierului refuzat și ora |
| Depășirea limitei e-mailului | Chenar galben pe rând | Chenar galben pe rând, text mai scurt, „Reîmparte anexele” ca singura acțiune a rândului |
| „Copie comprimată” | Text în linia de date | Etichetă cu explicație la cerere (compresie locală, fără servicii externe) |
| Banner volume anexe | Separat de listă | Rând de grup în listă, deasupra volumelor |
| Documente neincluse, „semnături detectate pe toate fișierele…”, „Fișierele se descarcă doar de avocatul dosarului” | Subsolul cardului | Rămân în subsol; propoziția pentru pachetul semnat devine rezumatul cardului restrâns |
| Note juridice (DSS, răspundere, pagina de gardă, compresie, fișiere nesemnate) | Card „Note juridice” | Neschimbate, în cardul pliat din coloana laterală |

Cardul restrâns (toate fișierele gata) păstrează accesul la tot: „Afișează fișierele (3)” deschide rândurile compacte, cu „Înlocuiește”, meniul „Descarcă” și observațiile fiecărui fișier.

Pachetul neactualizat (STALE) păstrează eticheta galbenă „Neactualizat, versiunea N” în antet, iar încărcările se închid (coloana de acțiuni rămâne doar cu „Descarcă”), ca acum.

## Explicație la cerere sau panou pliat

- **Explicație la cerere** (balon mic, iconul „i”) doar pentru definiții scurte, care nu condiționează o acțiune: de ce este necesară confirmarea, ce înseamnă „Copie comprimată”.
- **Panou pliat** pentru orice text care trebuie citit înainte de o decizie: observațiile, confirmarea, refuzurile. Textul acestora nu stă niciodată doar într-un balon.
- Avertismentul despre validitate nu stă într-un balon: rămâne text vizibil, o singură dată pe card.

## Accesibilitate

- Toate comenzile sunt butoane sau linkuri reale; ordinea tastaturii urmează ordinea vizuală pe rând (titlu, acțiunea rândului, „Descarcă”, observații).
- Butoanele care deschid ceva au `aria-expanded` și `aria-controls`; „Verifică și confirmă” și „Detalii” controlează același panou și își sincronizează starea.
- Meniul „Descarcă”: `aria-haspopup="menu"`, săgeți sus/jos între opțiuni, Escape închide și întoarce focusul pe buton.
- Balonul de explicație apare la hover, la focus din tastatură și la atingere; textul este legat prin `aria-describedby`, deci cititorul de ecran îl citește fără hover; Escape îl închide.
- Inputul de fișier este ascuns vizual, nu eliminat; eticheta lui primește conturul de focus (`:has(input:focus-visible)`) și are `aria-label` cu numele documentului („Încarcă fișierul semnat: Anexe”).
- Refuzurile au `role="alert"`, deci sunt anunțate la reîncărcarea rândului.
- Contrast: textele de stare pe fundal galben folosesc amber-900/950, pe roșu red-800/900, pe verde emerald-800, toate peste 4.5:1. Conturul de focus este de 2 px, navy.
- Animația de verificare se oprește cu `prefers-reduced-motion`.

## Telefon (390 px)

- Bara laterală dispare (buton de meniu în bara de sus), filele se derulează pe orizontală, etapele arată doar cercul și numele.
- Pe rând: iconul și titlul sus, linia de stare dedesubt, apoi acțiunile pe un rând de două butoane egale, pe toată lățimea; panourile ocupă toată lățimea rândului.
- Butonul de confirmare se întinde pe toată lățimea și se rupe pe două rânduri.
- Meniul „Descarcă” și balonul de explicație se aliniază la marginea din stânga, ca să nu iasă din ecran.
- „Descarcă toate (.zip)” trece sub textul introductiv.

## Texte propuse

Textele existente rămân valabile; propunerile de mai jos scurtează sau adaugă. Ar fi chei noi în `filing.signature.*` și `filing.upload.*`, cu echivalent EN.

| Unde | Azi | Propus |
|---|---|---|
| `filing.panel.parts.intro` | Se descarcă, se semnează local cu semnătura calificată și se încarcă înapoi. PDF, cel mult 20 MB. | Se descarcă, se semnează local cu semnătura calificată și se încarcă înapoi, cu butonul rândului sau trăgând fișierul peste rând. PDF, cel mult 20 MB. |
| `filing.signature.chip.MISSING` | nesemnat | Nesemnat |
| `filing.signature.chip.PRESENT_MATCHED` | semnătură detectată | Semnătură detectată |
| `filing.signature.chip.PRESENT_PROBABLE_unconfirmed` | semnătură detectată, document recunoscut prin text, confirmare necesară | Confirmarea documentului este necesară (explicația „recunoscut după text” trece în balonul „i”) |
| `filing.signature.chip.PRESENT_PROBABLE_confirmed` | semnătură detectată, document confirmat de avocat | Semnătură detectată, document confirmat |
| `filing.signature.signer` | Numele din certificat: %name% | Certificat: %name% (pe linia de stare); în panou rămâne „Numele din certificat” |
| `filing.signature.notice.validity` | …validitatea calificată; verificarea revine avocatului și instanței. | …validitatea calificată. Verificarea revine avocatului și instanței. |
| `filing.package.download_unsigned` / `filing.upload.download_signed` | Descarcă (nesemnat) / Descarcă (semnat) | Buton „Descarcă” cu opțiunile „Fișierul semnat” (încărcat la %date%) și „Fișierul nesemnat” (generat de platformă) |
| `filing.upload.button` | Încarcă fișierul semnat | Încarcă semnat (eticheta accesibilă păstrează textul complet și numele documentului) |
| `filing.upload.replace` | Înlocuiește fișierul semnat | Înlocuiește |
| `filing.upload.over_budget.rebuild_button` | Reface volumele de anexe | Reîmparte anexele (același verb ca în panoul de stare) |
| `filing.upload.over_budget.text` | Fișierul semnat este mai mare decât spațiul rămas… | Titlu „Fișierul semnat nu încape în e-mailul lui”, apoi: Depășește cu aproximativ %excess% MB spațiul rămas și nu a fost păstrat. Anexele se pot reîmpărți în volume mai mici; volumele noi se semnează din nou, iar cererea și împuternicirea își păstrează semnăturile. |
| `filing.signature.refused.mismatch` | Fișierul diferă de cel generat de platformă. Pentru depunerea prin e-mail, fișierul trebuie generat și semnat din nou; … | Titlu „Fișierul încărcat diferă de cel generat de platformă”, text „Fișierul nu a fost acceptat. Pentru depunerea prin e-mail se folosește fișierul generat de platformă, semnat fără alte modificări.”, apoi „Cum se rezolvă: se descarcă din nou fișierul nesemnat, se semnează fără editare, conversie sau tipărire în PDF și se încarcă. Depunerea pe altă cale rămâne disponibilă.” |
| nou: `filing.signature.awaiting_explain` | | Programul de semnare a rescris fișierul, deci documentul a fost recunoscut după text, nu după amprenta fișierului generat. |
| nou: `filing.signature.compare_title` / `compare_intro` | | Ce s-a constatat la comparare / Textul paginilor generate se regăsește în fișierul semnat. Diferențele de mai jos ajung la instanță așa cum sunt. |
| nou: `filing.signature.observations` | | {1} 1 observație\|[0,Inf[ %count% observații |
| nou: `filing.signature.short.*` (rezumatul într-un rând) | | nume diferit în certificat; certificat în afara valabilității; marcă temporală adăugată; date de validare adăugate; pagini fără text; format de semnătură depășit; text adăugat în pagina %page%; text cu cifre în pagina %page%; o pagină adăugată la final / %count% pagini adăugate la final; identificator intern lipsă |
| nou: `filing.upload.refused_status` | | Încărcare refuzată (pastila rândului), „Rândul rămâne nesemnat.” |
| nou: `filing.upload.uploading` | | Se încarcă și se verifică semnătura / Se verifică |
| nou: `filing.upload.drop_here` | | Eliberează fișierul pentru a-l încărca la „%title%” |
| nou: `filing.package.compressed_explain` | | Anexele au fost comprimate local de platformă, ca să încapă în limita e-mailului. Nu se folosesc servicii externe de compresie. |
| nou: `filing.panel.parts.show_files` / `signed_summary` | | Afișează fișierele (%count%) / Semnături detectate pe toate fișierele. Restul cerințelor se verifică înainte de trimitere. |
| nou: `filing.package.download_all` | | Descarcă toate (.zip) |

## Întrebări deschise

1. Refuzul trebuie să rămână pe rând și după reîncărcarea paginii (necesită păstrarea ultimului refuz pe `FilingPackagePart`) sau este suficient pe răspunsul imediat după încărcare?
2. La refuzul „fișier diferit”, remedierea corectă este „descarcă din nou și semnează” sau „reconstruiește pachetul”? Textul actual („trebuie generat și semnat din nou”) permite ambele citiri.
3. Avertismentul despre validitate o singură dată pe card este acceptabil juridic, în locul repetării sub fiecare semnătură? (Propunerea îl ține vizibil și neînchis, nu într-un balon.)
4. „Descarcă toate (.zip)” apare atât în antetul cardului, cât și ca acțiune secundară a panoului de stare; păstrăm doar una?
5. Tragerea fișierului se face pe rând (alegere sigură a documentului) sau acceptăm și plasarea oriunde pe card, cu potrivire după identificatorul intern (care poate lipsi, vezi `part_id_missing`)?
