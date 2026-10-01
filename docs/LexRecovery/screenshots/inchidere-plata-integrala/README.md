# Închiderea dosarului la plată integrală: capturi

Capturi din mediul de dezvoltare (2026-10-01), branch `feature/inchidere-plata-integrala`. Planul: `../../PLAN-INCHIDERE-PLATA-INTEGRALA.md`.

| Fișier | Ce arată |
|---|---|
| `01-actiuni-recomandate-buton-activ.png` | Dosar în Amiabil: „Marchează plată amiabilă” e acum activ (înainte era dezactivat, „în curând”). |
| `02-modal-din-amiabil.png` | Modalul din Amiabil: data plății, suma încasată (opțional), mențiuni, avertismentele și bifa de confirmare. |
| `03-eroare-fara-bifa-modal-ramane-deschis.png` | Trimis fără bifă: apare un toast de eroare, pagina nu se reîncarcă, modalul rămâne deschis cu datele introduse. |
| `04-modal-din-somatie-trimisa.png` | Modalul din Somație trimisă: data somației ca reper și avertismentul despre accesoriile neachitate. |
| `05-dupa-inchidere-toast-succes.png` | După închidere: toast de succes, modalul se închide, pagina se actualizează pe loc. |
| `06-dosar-inchis-din-amiabil-hero-pipeline.png` | Dosar închis: „Închis cu succes”, chip „Plată integrală · data”, niciun termen activ, pipeline oprit la Amiabil, restul etapelor „Nu a fost necesar”. |
| `07-actiuni-recomandate-dosar-inchis.png` | Cardul de final în Acțiuni recomandate. |
| `08-tab-documente-nu-a-fost-necesar.png` | Tab Documente: somația, cererea și opisul apar „nu a fost necesar”; fără pachetul de depunere și fără încărcare de documente. |
| `09-tab-termene-inchise-fara-actiuni.png` | Tab Termene: toate termenele închise (inclusiv prescripția), fără butoane de adăugare sau ștergere. |
| `10-pipeline-dosar-inchis-din-somatie.png` | Dosar închis din Somație trimisă: pipeline oprit la stadiul 2. |
| `11-cerere-generata-fara-buton.png` | Dosar cu cererea OP generată: butonul nu mai apare (închiderea se face prin fluxul obișnuit). |
