# Verificare live: tab-ul „Depunere la instanță” mereu vizibil

Data: 25.10.2026, pe dev (`lexrecovery` + modificări necomise). Tab-ul apare pe orice dosar. Când dosarul nu are nimic de depus prin e-mail, conținutul explică de ce.

| Captură | Dosar | Situație | Ce arată | Rezultat |
|---|---|---|---|---|
| `01-inainte-de-cerere-pasii.png` | 93 | Somație trimisă | „Depunerea devine disponibilă după generarea cererii”, cei 4 pași (Pachet, Semnare, Trimitere, Data depunerii), nota despre alte canale | ✅ fără punct pe tab |
| `02-plata-integrala.png` | 66 | Închis cu plată integrală | „Plata integrală a fost înregistrată”, cu data plății | ✅ |
| `03-depusa-pe-alt-canal.png` | 32 | Definitivă, fără transmitere din platformă | „Cererea nu a fost transmisă prin platformă” | ✅ |
| `04-dosar-finalizat.png` | 33 | Respinsă | „Dosarul este finalizat” | ✅ |
| (fără captură) | 170 | Cerere generată, fișiere de semnat | Clic pe tab: fluxul complet de depunere, neschimbat, punct albastru | ✅ |
