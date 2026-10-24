# Verificare live: împuternicirea avocațială

Data: 24.10.2026. Testat pe 6 dosare clonă `TMP-LIVE-I*` (din dosarul 55, în „somație trimisă”, somație generată la 29.09.2026), pe branch-ul `feature/trimitere-email-instanta`, care conține commit-ul `b291f83` (împuternicirea) și ajustările ulterioare. Clonele au fost șterse după test.

## 1. Verificări la generarea cererii

| Captură | Situație | Ce arată | Rezultat |
|---|---|---|---|
| `01-fara-imputernicire-cererea-blocata.png` | Dosar fără împuternicire | „Lipsește împuternicirea avocațială”, butonul „Încarcă împuternicirea”, butonul de generare dezactivat | ✅ |
| `02-fara-imputernicire-pachetul-zip-blocat.png` | Același dosar, „Generează & descarcă ZIP” | ZIP-ul trece prin aceeași fereastră de generare, deci e blocat la fel | ✅ |
| `03-imputernicire-fara-data-eticheta-si-buton.png` | Împuternicire încărcată fără dată (dosar vechi) | Eticheta „Data împuternicirii lipsește” în lista de documente | ✅ |
| `04-imputernicire-fara-data-cererea-blocata.png` | Același dosar | „Împuternicirea nu are data completată. Data împuternicirii apare în opis...”, cu butonul „Completează datele împuternicirii” | ✅ |
| `08-fara-inscris-creanta-cererea-blocata.png` | Dosar fără factură sau alt înscris al creanței | „Lipsește înscrisul din care rezultă creanța”, cu butonul „Încarcă factura”, generare dezactivată | ✅ |
| `09-al-doilea-exemplar-fara-data-avertisment.png` | Două exemplare, unul fără dată | Doar avertisment: „Un alt exemplar al împuternicirii nu are data completată. Opisul îl listează fără dată.”; generarea rămâne posibilă | ✅ |

## 2. Completarea și modificarea datelor

| Captură | Ce arată | Rezultat |
|---|---|---|
| `05-completare-date-imputernicire.png` | Fereastra „Datele împuternicirii” deschisă din fereastra de generare: seria C, nr. 777, data 05.10.2026 | ✅ |
| `06-date-salvate-avertisment-dupa-somatie.png` | „Datele împuternicirii au fost salvate.” și avertismentul: data (05.10.2026) e ulterioară generării somației (29.09.2026), se verifică existența mandatului la data semnării | ✅ (vezi observația 1) |
| `07-dupa-completare-avertisment-mandat-in-generare.png` | Fereastra de generare: nicio blocare, avertismentul despre mandat cu butonul „Modifică datele împuternicirii” | ✅ |
| Jurnal de audit | `document_power_of_attorney_update` cu valorile vechi și noi (seria null → C, B → D) | ✅ |

## 3. Încărcarea unei împuterniciri noi

| Captură | Ce arată | Rezultat |
|---|---|---|
| `10-incarcare-imputernicire-cu-serie-numar-data.png` | Formularul de încărcare deschis din „Încarcă împuternicirea”: tipul e preselectat, apar câmpurile Seria, Numărul, Data | ✅ |
| `11-incarcare-fara-data-refuzata.png` | Fără dată: „Data împuternicirii este obligatorie.” | ✅ |
| `12-imputernicire-incarcata-cu-date-in-lista.png` | Încărcată cu data 10.01.2026 (înainte de somație, fără avertisment): în listă „seria BU nr. 445566 din 10.01.2026” | ✅ |

## 4. Opisul

| Captură | Ce arată | Rezultat |
|---|---|---|
| `13-opis-randul-imputernicirii.png` | Opisul generat: „Împuternicire avocațială seria B nr. 123456 din 05.01.2026” | ✅ |
| `14-modificare-date-dupa-generarea-cererii.png` | Cererea e generată; datele se pot încă modifica (seria D, nr. 999). Mesaj: „Opisul se regenerează cu noile date la descărcarea pachetului.” | ✅ |
| `15-opis-regenerat-cu-datele-noi.png` | Opisul din ZIP-ul descărcat: „Împuternicire avocațială seria D nr. 999 din 05.01.2026” | ✅ |

## 5. Blocarea după depunere

| Captură | Ce arată | Rezultat |
|---|---|---|
| `16-dupa-depunere-datele-nu-se-mai-modifica.png` | Dosarul în „cerere depusă”: butonul de modificare a dispărut, rândul arată datele finale | ✅ |
| Cerere directă la server | O modificare trimisă direct e refuzată: „Datele împuternicirii nu se mai modifică după depunerea cererii sau după închiderea dosarului.” | ✅ |

## Observații găsite la test și reparate (24.10.2026)

| # | Observație | Reparație | Captură | Rezultat |
|---|---|---|---|---|
| 1 | După modificarea datelor, avertismentul începea cu „Împuternicirea a fost încărcată...” | La modificare apare un text propriu: „Data împuternicirii (05.10.2026) este ulterioară datei generării somației (29.09.2026)...”. La încărcare textul rămâne cel vechi | `18-fix-mesaj-modificare-fara-incarcata.png` | ✅ |
| 2 | Cardul „Pachet pentru depunere pe alt canal” lista „Contract + factură” și nu lista împuternicirea | Lista arată „Împuternicirea avocațială” și „Înscrisurile din care rezultă creanța”, fiecare bifată sau marcată cu „!” după aceeași verificare ca generarea cererii | `17-fix-card-zip-lista-imputernicire-si-inscrisuri.png` (ambele lipsă), `19-fix-card-zip-dupa-completarea-imputernicirii.png` (împuternicirea bifată după completarea datei) | ✅ |
