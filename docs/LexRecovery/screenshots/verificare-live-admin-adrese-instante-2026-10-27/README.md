# Verificare live: paginile de acțiune din admin rămân în admin

Data: 27.10.2026, pe dev, cont de admin.

**Problema:** paginile de acțiune din admin (adăugarea unei adrese de e-mail la instanță, validare, respingere, schimbarea scopului, invalidare, deciziile pe rapoarte, deblocarea transmiterii, schimbarea statusului dosarului) se deschideau în layout-ul aplicației avocatului, cu meniul avocatului, și ieșeau din admin.

**Reparația:** cele 7 rute folosesc acum `#[AdminRoute]` din EasyAdmin, iar șabloanele lor moștenesc layout-ul de admin. Adresele și numele rutelor au rămas aceleași.

| Captură | Pagina | Rezultat |
|---|---|---|
| `01-court-3-email-add.png` | Adaugă o adresă de e-mail (din pagina instanței) | ✅ în admin, cu meniul admin |
| `02-court-email-1-purpose.png` | Schimbă scopul adresei | ✅ |
| `03-court-email-1-validate.png` | Validează adresa (cu declarația de verificare) | ✅ |
| `04-court-email-1-invalidate.png` | Marchează adresa ca invalidă | ✅ |
| `05-court-email-report-6-dismiss.png` | Respinge raportul unui avocat | ✅ |
| `06-filing-submission-33-override.png` | Deblocare transmitere | ✅ |
| `07-case-170-change-status.png` | Schimbă status dosar | ✅ |
| `08-adaugare-adresa-nominala-refuzata.png` | Adresă nominală refuzată: mesajul de eroare apare în admin | ✅ |
| `09-dupa-confirmare-inapoi-in-admin.png` | După confirmare: înapoi la detaliul adresei, cu mesajul de succes | ✅ |

## Adresa adăugată de admin intră direct confirmată

Decizie 27.10.2026: adminul care adaugă adresa este și cel care o verifică, deci adăugarea și confirmarea se fac într-un singur pas.

| Captură | Ce arată | Rezultat |
|---|---|---|
| `10-adaugare-cu-declaratie.png` | Formularul de adăugare: e-mail, scop, link-ul paginii unde a fost verificată adresa (opțional), bifa cu declarația, butonul „Adaugă și confirmă” | ✅ |
| `11-adresa-adaugata-direct-confirmata.png` | După adăugare: status „Confirmată”, confirmată de admin, link-ul sursei și declarația salvate | ✅ |
| (fără captură) | Fără bifă: „Confirmarea cere bifarea declarației.”, nu se salvează nimic. Link greșit: refuzat, nu se salvează nimic | ✅ |
| Jurnal de audit | `court_email_added` (cu link-ul sursei), apoi `court_email_validated` (cu textul declarației) | ✅ |

Declarația are o formulare nouă, valabilă pentru toate validările: „Confirm că am verificat adresa pe o sursă oficială a instanței...”. Înainte era „pe sursa indicată”, iar formularul nu cerea nicio sursă. Adresele de test folosite la verificare (#13, #14) au fost scoase din uz.
