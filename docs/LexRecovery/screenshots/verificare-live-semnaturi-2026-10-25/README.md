# Verificare live: recunoașterea fișierelor semnate (2026-10-25)

Toate fișierele sunt sintetice. Cererea generată a unei clone temporare a dosarului 55 (TMP-LIVE-SEMN-1025, ștearsă după verificare) a fost modificată în containerul PHP cu `tests/Support/TestPdfVariants.php`, apoi semnată cu cheia de test (`TestPdfSigner`, certificat „Test Avocat”). Fișierul semnat real din dosarul 170 a fost citit doar pe loc, fără capturi.

| Captură | Fișier încărcat | Rezultat |
|---|---|---|
| `01-ghostscript-identificator-lipsa.png` | cerere re-distilată cu Ghostscript (pdfwrite), apoi semnată | recunoscut prin text, confirmare necesară; nota „Identificatorul intern al documentului lipsește din fișier” |
| `02-text-adaugat-pagina-1.png` | text „Semnat electronic de TEST SIGNER” desenat în conținutul paginii 1, apoi semnată | recunoscut prin text; nota „Text suplimentar în pagina 1 (5 cuvinte în plus...)” |
| `03-cuvant-schimbat-refuzat.png` | un cuvânt schimbat în pagina 1 („Live” în „Lime”) | refuzat: fișierul diferă de cel generat |
| `04-pagina-adaugata-la-final.png` | o pagină „Pagina de semnaturi” adăugată incremental, semnătura pe ultima pagină | recunoscut, confirmare necesară; nota „O pagină adăugată după ultima pagină generată” deasupra butonului de confirmare |
| `05-pagina-adaugata-375px.png` | același fișier, la 375 px | notele se rup pe rânduri, fără depășire în card |
| `06-semnatura-marca-temporala-dss.png` | semnătură, marcă temporală a documentului și date de validare (DSS) după ea | semnătură detectată, cu cele două avertismente neutre |
| `07-format-sha1-depasit.png` | semnătură adbe.pkcs7.sha1 | semnătură detectată, cu avertismentul despre formatul depășit |

După confirmarea fișierului cu pagina adăugată, partea a trecut la „document confirmat de avocat”.

Observații:
- La 375 px pagina dosarului are o depășire orizontală care vine din antet (butonul de acțiune) și din bara de file. Ea există și pe dosarul 170, înainte de această modificare; cardul fișierelor nu depășește.
- Dosarul 170 afișează aceleași informații ca înainte (rândul salvat nu are note de potrivire).
