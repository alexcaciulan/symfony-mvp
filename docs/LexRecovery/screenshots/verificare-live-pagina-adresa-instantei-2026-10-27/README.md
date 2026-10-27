# Verificare live: pagina „Adresa instanței”

Data: 27.10.2026, pe dev. Cele trei probleme semnalate și reparația lor.

| # | Problemă | Reparație | Captură | Rezultat |
|---|---|---|---|---|
| 1 | Conținutul ocupa doar partea stângă a paginii (`max-w-4xl`), cu spațiu gol în dreapta | Pagina folosește toată lățimea, ca restul paginilor dosarului | `02-cu-adrese-latime-completa.png` | ✅ |
| 2 | Fără adrese definite, „Sugerează o adresă” nu făcea nimic vizibil (formularul era deja pe ecran, deci nu avea unde derula) | Butonul aduce formularul în centru, pune cursorul în câmpul de e-mail și conturează formularul cu albastru o secundă și jumătate | `01-fara-adrese-sugereaza-focus.png` (dosar 91, Judecătoria Buftea) | ✅ |
| 3 | Dropdown-ul „Scopul adresei” era mai scund decât câmpul de e-mail și cu săgeata nativă a browserului | Același stil ca selecturile din wizard: aceeași înălțime (38px) cu câmpul, săgeată proprie; aplicat și dropdown-ului din „Raportează adresa ca greșită” | `01-...png`, `03-formular-raportare-dropdown.png` | ✅ |

## Bara de sus pe telefon

| Problemă | Reparație | Captură | Rezultat |
|---|---|---|---|
| Pe telefon (390px) bara de sus ieșea cu 26px din ecran: butonul de temă și „Dosar nou” erau tăiate, pe pagina dosarului și pe „Adresa instanței” | Pe telefon breadcrumb-ul păstrează doar pagina curentă și se scurtează cu „...” dacă nu încape; spațiile dintre butoane sunt mai mici. De la 640px în sus rămâne ca înainte | `04-telefon-bara-de-sus-adresa-instantei.png`, `05-telefon-bara-de-sus-dosar.png` | ✅ fără depășire pe 10 pagini (dashboard, dosare, dosar, depunere, adresa instanței, termene, notificări, profil, creditori, dosar nou) la 360, 390, 768 și 1440px |
