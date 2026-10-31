# Verificare live: clarificările avocatului la taxa de timbru (2026-10-31)

Decizia: `docs/LexRecovery/DECIZIE-TAXA-TIMBRU-CLARIFICARI-AVOCAT-2026-10-31.md`. Dosare temporare clonate din dosarul 84 al contului `avocat@test.com`, șterse după verificare:

- TX1 `TMP-LIVE-TX1-NUMAR`: dosar înregistrat, număr 777/211/2026 cunoscut de o zi, taxă neachitată.
- TX2 `TMP-LIVE-TX2-PROPUS`: cerere depusă, portalul a propus 778/211/2026, neconfirmat, taxă amânată.
- TX3 `TMP-LIVE-TX3-EXPIRAT`: dosar înregistrat, număr cunoscut de 20 de zile, termen de timbrare expirat de 3 zile, taxă neachitată.

| Captură | Ce arată |
|---|---|
| 01 | Alerta din antetul dosarului TX1 cu linia „Plătește până la 04.11.2026 (cel mult 5 zile de la alocarea numărului de dosar)”. |
| 02 | Cardul taxei pe TX1 cu aceeași linie deasupra butoanelor. |
| 03 | După apăsarea linkului spre registratură și revenirea în pagină, cardul arată promptul „Ai plătit pe registratură? Confirmă plata acum”. |
| 04 | Butonul din prompt deschide dialogul existent „Confirmă plata prin registratură”. |
| 05 | Alerta pe TX2: numărul propus de portal, cu termenul de plată calculat de la propunere. |
| 06 | Cardul pe TX3: fereastra de 5 zile depășită, textul roșu „Cele 5 zile ... au trecut la 16.10.2026”. |
| 07 | Zona de blocaje din agendă: „Termen expirat” (TX3, link „Deschide dosarul”), „De plătit acum” (TX1, TX2 și dosarul 84 cu propunere, link „Plătește taxa”), „Se așteaptă numărul” (dosarele 172 și 173, link „Caută numărul de dosar”). |
| 08 | E-mailul alertei zilnice pentru TX1 (număr confirmat), cu „adică până la 04.11.2026”. |
| 09 | E-mailul alertei pentru TX2 (număr propus): confirmă dosarul, apoi plătește. |

Selecția alertei verificată separat, fără `app:check-deadlines`: TX1 și TX2 intră în alertă cu cheie zilnică (fereastră deschisă), TX3 nu intră deloc (termen de timbrare expirat).

## A doua trecere (după review), clone noi TX1, TX2, TX4

| Captură | Ce arată |
|---|---|
| 10 | Dialogul de confirmare deschis din prompt, completat (data plății, referința). |
| 11 | Cardul după trimiterea confirmării din prompt: „Achitată”, plată prin registratură, alerta din antet a dispărut. |
| 12 | Tab-ul Portal pe TX2, propunerea 778/211/2026 (făcută cu două zile înainte) înainte de confirmare. |
| 13 | După „Activează monitorizarea”: dosar înregistrat, iar „Plătește până la 03.11.2026” a rămas calculat de la propunere, nu de la confirmare. |
| 14, 15, 16 | Interfața în engleză: cardul cu fereastra depășită, cardul cu termenul și promptul, zona de blocaje. |
| 17 | Dialogul de respingere deschis din „Dosar înregistrat”, cu motivul nou „Anulată pentru netimbrare”. |
| 18 | TX4 marcat „Respinsă”. |

Gol găsit și închis în această trecere: tranziția de respingere era permisă doar din „Termen fixat”, iar dialogul avea motivele scrise fix în șablon; acum respingerea se poate marca și din „Dosar înregistrat” (anularea în camera de consiliu nu așteaptă un termen), iar lista motivelor vine din enum. Apelul extern spre portal.just.ro din activare a fost izolat în container pe durata verificării.
