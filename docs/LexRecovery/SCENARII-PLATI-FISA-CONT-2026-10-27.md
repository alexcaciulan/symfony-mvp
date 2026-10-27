# Cum trateaza platforma platile din fisa de cont si din extras

Pentru discutia despre punctul 4 din tabelul de status (platile din fisa de cont si din extrasul de cont). Toate exemplele sunt verificate in platforma.

## Regula generala

- Se cere doar restul neachitat al fiecarei facturi.
- Dobanda sau penalitatile curg pe rest, de la scadenta facturii pana la data somatiei.
- Pentru partea platita nu se cer penalitati pentru intarziere.
- Plafonul de 10% se calculeaza din valoarea facturii.
- Pe fiecare factura apar totalul, plata si restul cerut, la fel in somatie si in cererea de ordonanta.

## Scenarii

| Nr. | Ce arata documentul | Ce face platforma | Exemplu |
|---|---|---|---|
| 1 | Fisa de cont arata restul de plata pe fiecare factura | Ia restul din fisa. Este alocarea facuta de contabilitatea creditorului, deci are prioritate fata de orice calcul din plati | Fisa Dosarului 3: factura 23381 de 33.666,00 lei, rest 456,60 lei. Se cer 456,60 lei, cu dobanda pe 456,60 lei de la scadenta |
| 2 | Fisa arata o factura achitata integral (rest 0) | Factura iese din cerere, cu motivul afisat. Nu se cer penalitati pentru intarziere | Factura 23382 cu rest 0 |
| 3 | Fisa arata o factura neachitata deloc (restul egal cu totalul) | Se cere totalul facturii, fara nicio mentiune de plata | Facturile 23383, 23585 din Dosarul 3 |
| 4 | Extrasul arata o plata pentru o singura factura | Plata se scade din factura respectiva | Factura de 10.000 lei, plata 4.000 lei: se cer 6.000 lei, cu dobanda pe 6.000 lei de la scadenta |
| 5 | O plata pentru mai multe facturi, toate in dosar, fara suma pe fiecare | Plata se aloca de la cea mai veche scadenta (la aceeasi scadenta, de la cea mai veche factura) | Exemplul tau: 20.000 + 30.000 + 50.000 lei, toate scadente la 01.03.2026, plata 60.000 lei la 15.03.2026. Primele doua ies din cerere, din a treia se cer 40.000 lei |
| 6 | O plata pentru mai multe facturi, dintre care unele nu sunt in dosar | Plata nu se imparte, pentru ca nu se stie cat a mers pe facturile lipsa. Se cere totalul facturii din dosar, iar pe pozitie apare un avertisment ca plata trebuie verificata | Plata de 3.000 lei pentru facturile RO-200 (in dosar) si XX-999 (lipsa) |
| 7 | O plata care nu numeste nicio factura | Nu se scade din nicio factura. Apare un avertisment ca exista o plata nelegata de nicio pozitie | Incasare fara mentiune de factura |
| 8 | Factura in valuta, platita partial | Restul se cere in lei, la cursul BNR din data facturii, iar documentele arata restul si in valuta | Factura de 10.000 EUR, plata 4.000 EUR: se cer 6.000 EUR × 4,9760 = 29.856,00 lei |

## Ce poate face avocatul

- Sa corecteze suma facturii. Daca fisa arata restul, restul ramane cel din fisa.
- Sa excluda sau sa reincluda orice factura.
- Sa verifice in fisa platile marcate cu avertisment (scenariile 6 si 7).

## De discutat

1. Scenariul 6: e in regula ca platforma sa ceara totalul si sa lase verificarea pe seama avocatului?
2. Scenariul 7: o plata fara factura trebuie alocata de la cea mai veche scadenta, ca in scenariul 5, sau ramane la verificarea avocatului?
3. Exista situatii din practica pe care nu le acopera tabelul de mai sus?
