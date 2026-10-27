# Verificare live: feature/taxa-timbru-v3-completari (commit 3b90af4)

Data: 24.10.2026. Testat pe dosare clonă `TMP-LIVE-*` (din dosarul 55), pe codul curent al branch-ului `feature/trimitere-email-instanta`, care conține `3b90af4` neschimbat pentru aceste funcționalități. Clonele au fost șterse după test.

## 1. Alerta „taxa de timbru figurează neachitată”

Alerta a fost declanșată doar pentru clone (fără `app:check-deadlines`, care ar fi atins și alte dosare de pe dev).

| Clonă | Situație | Alertă așteptată | Rezultat |
|---|---|---|---|
| TTA | Dosar înregistrat, fără termen de timbrare | Da | ✅ primită |
| TTB | Dosar înregistrat, termen de timbrare închis | Da (înainte: nu) | ✅ primită |
| TTC | Dosar înregistrat, termen de timbrare expirat | Da (înainte: nu) | ✅ primită |
| TTD | Dosar înregistrat, termen de timbrare activ (10.11.2026) | Nu | ✅ nu a primit |
| TTE | Ordonanță emisă | Da (înainte: nu) | ✅ primită |
| TTF | În anulare | Da (înainte: nu) | ✅ primită |
| TTG | Definitivă | Nu | ✅ nu a primit |

| Captură | Ce arată |
|---|---|
| `01-alerta-mailpit-lista.png` | Cele 5 e-mailuri de alertă, câte unul pentru fiecare clonă care trebuia alertată |
| `02-alerta-email-continut.png` | Textul nou al e-mailului: taxa „figurează neachitată”, sancțiunea din art. 33 alin. 2, ce se face dacă taxa a fost deja plătită. Înainte: „Dosarul are număr, taxa de timbru se achită acum” |
| `03-alerta-notificari-aplicatie.png` | Cele 5 notificări în aplicație: „Taxa de timbru neachitată: ...”. Înainte: „Achită taxa de timbru: ...” |
| `04-alerta-notificare-text.png` | Textul notificării: „...dacă a fost deja achitată, plata se înregistrează în dosar”. Înainte: „...nu aștepta înștiințarea de regularizare” |

## 2. Plata prin registratura electronică

| Captură | Ce arată | Rezultat |
|---|---|---|
| `05-card-taxa-neachitata-inainte.png` | Cardul taxei înainte de plată (clona TTE, ordonanță emisă) | ✅ |
| `06-modal-registratura-confirmare-optionala.png` | Fereastra „Confirmă plata prin registratură”: textul nu mai spune că instanța a primit confirmarea; câmp nou „Confirmarea plății (opțional)” | ✅ |
| `07-card-platit-registratura-fara-fisier.png` | Plată declarată fără fișier: „Plată individualizată pe dosar prin registratura electronică... Confirmarea plății nu este atașată.” și butonul nou „Atașează confirmarea”. Înainte: „Confirmarea a fost transmisă instanței pe acest canal” | ✅ |
| `08-modal-atasare-confirmare-ulterior.png` | „Atașează confirmarea” deschide formularul de dovadă a plății | ✅ (vezi observațiile) |
| `09-card-dupa-atasare-confirmare.png` | După atașare: „Vezi dovada” | ✅ |
| `10-modal-registratura-cu-fisier.png` | Fereastra registraturii cu fișierul atașat direct (clona TTF, în anulare) | ✅ |
| `11-mesaj-dupa-confirmare-cu-fisier.png` | Mesajul „Taxa de timbru a fost marcată ca achitată, iar confirmarea a fost atașată la dosar.” | ✅ |
| `12-card-platit-registratura-cu-fisier.png` | Cardul: achitată, „Vezi dovada” | ✅ |
| `13-fisier-tip-gresit-respins.png` | Fișier .txt respins: „Tip de fișier invalid. Doar PDF, JPG și PNG sunt acceptate.”; taxa rămâne neachitată | ✅ |

## 3. Textul din cererea de ordonanță (cheltuieli de judecată)

Două clone în „somație trimisă”, cu taxa achitată, cererea generată din interfață.

| Captură | Situație | Text în cerere | Rezultat |
|---|---|---|---|
| `14-cerere-op-taxa-registratura-fara-fisier.png` | Achitată, fără dovadă atașată | „achitată prin registratura electronică a instanțelor, prin plată individualizată pe numărul de dosar”. Înainte: „...confirmarea plății fiind transmisă instanței pe această cale” | ✅ |
| `15-cerere-op-taxa-conform-dovezii-anexate.png` | Achitată, cu dovadă atașată | „achitată conform dovezii anexate” | ✅ |

## Observații găsite la test și reparate (24.10.2026)

| # | Observație | Reparație | Captură | Rezultat |
|---|---|---|---|---|
| 1 | „Atașează confirmarea” deschidea formularul general de dovadă: cerea din nou data plății și vorbea despre chitanță sau ghiseul.ro | Fereastră dedicată „Atașează confirmarea plății prin registratură”: doar fișierul; arată data declarată; data și referința plății rămân neschimbate | `16-fix-card-registratura-fara-fisier.png`, `17-fix-modal-atasare-confirmare-registratura.png`, `18-fix-mesaj-confirmare-atasata.png` | ✅ data 23.10.2026 și referința GH-4711 au rămas cele declarate |
| 2 | După atașare, cardul nu mai spunea că plata s-a făcut prin registratură | Dosarul reține acum că plata s-a făcut prin registratură (câmp nou, completat și pentru dosarele existente din jurnalul de audit). Cardul arată „Plată individualizată pe dosar prin registratura electronică... Confirmarea plății este atașată.” plus „Vezi dovada” | `19-fix-card-registratura-cu-confirmare-atasata.png` | ✅ |

## Modificări după răspunsurile avocatului (24.10.2026)

| Pct. | Răspunsul avocatului | Modificare | Captură | Rezultat |
|---|---|---|---|---|
| 12 | Fără contract încărcat nu există ce trimite; relația contractuală se prezumă în formă simplificată, pe baza facturilor | Avertismentul „Contractul nu este încărcat...” a fost scos din fereastra de generare a cererii și din pachete | `20-cerere-fara-contract-fara-avertisment.png` | ✅ dosar doar cu facturi: niciun avertisment, cererea se generează |
| 6 | Formularea propusă e în regulă, dar nu e obligatorie | Cererea (taxă amânată) și e-mailul către instanță: „va fi achitată prin registratura electronică după comunicarea numărului unic de dosar, dovada urmând a fi depusă la dosar”. Înainte: „urmând a fi achitată potrivit art. 33 alin. 2...” în cerere și „se va achita la indicarea instanței” în e-mail | `21-cerere-taxa-amanata-formularea-avocatului.png` | ✅ în cerere; e-mailul verificat prin test automat |
| 13 | Indiferent cine plătește, registratura trimite automat dovada la dosarul instanței; adresa de e-mail din formularul de plată primește o confirmare | Cererea: „...dovada plății fiind transmisă de registratură la dosarul instanței”. Fereastra registraturii, cardul și fereastra de atașare spun că dovada ajunge automat la dosar; fișierul devine „Confirmarea primită pe e-mail (opțional)”, pentru evidența dosarului | `22-cerere-taxa-registratura-dovada-transmisa-automat.png`, `23-modal-registratura-dovada-ajunge-automat.png`, `24-card-registratura-dovada-transmisa-automat.png`, `25-modal-atasare-confirmare-email.png` | ✅ |
| 9 | Opisul poate fi în același PDF cu cererea sau la sfârșitul ei | Fără modificare: opisul era deja implicit în PDF-ul cererii (3 semnături) | n/a | ✅ |
