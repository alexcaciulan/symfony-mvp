# PLAN DE DEZVOLTARE MVP — Pași progresivi pentru Claude Code

> Recuperare Creanțe — Cerere cu Valoare Redusă
> Februarie 2026 | v4 — actualizat cu statusul curent al implementării
> 18 pași | 9 faze | 14 din 18 pași finalizați (complet sau parțial)

---

## Hartă de dezvoltare

Fiecare pas este un prompt pe care îl dai lui Claude Code. După fiecare pas, verifici manual, faci git commit, apoi treci la următorul. Pașii sunt în ordine strictă.

| Pas | Faza | Descriere | Durată est. | Depinde de | Status |
|-----|------|-----------|-------------|------------|--------|
| 1 | Setup | Proiect Symfony + Docker Compose | — | — | ✅ DONE |
| 2 | Setup | Tailwind Bundle + verificare compilare | 1 oră | Pas 1 | ✅ DONE |
| 3 | Setup | Layout responsive + homepage | 1-2 ore | Pas 2 | ✅ DONE |
| 4 | DB | Entități Doctrine + migrări | 2-3 ore | Pas 1 | ✅ DONE |
| 5 | DB | Date de referință (instanțe) + useri de test | 1-2 ore | Pas 4 | ✅ DONE |
| 6 | Auth | Înregistrare simplă + Login + Verificare email | 2-3 ore | Pas 3, 4 | ⏭️ AMÂNAT |
| 7 | Auth | Înregistrare multi-step + Validatori + Profil + Forgot password | 2-3 ore | Pas 6 | ✅ DONE (parțial) |
| 8 | Core | Wizard cerere pașii 1-4 (formulare, fără upload) | 4-6 ore | Pas 5 | ✅ DONE |
| 9 | Core | Wizard cerere pașii 5-6 (probe + confirmare + calculator taxă) | 3-4 ore | Pas 8 | ✅ DONE |
| 10 | Core | Workflow dosar + Voters + Dashboard creditor + Audit log | 3-4 ore | Pas 9 | ✅ DONE (parțial) |
| 11 | Core | Configurare Messenger worker + procesare async | 1-2 ore | Pas 10 | ⏭️ SKIP (absorbit în Pas 12/15) |
| 12 | Docs | Generare PDF (DomPDF) via Messenger | 2-3 ore | Pas 11 | ✅ DONE (sync, fără Messenger) |
| 13 | Docs | Upload documente (simplificat, fără VichUploader/Flysystem) | 2-3 ore | Pas 10 | ✅ DONE |
| 14 | Plăți | Integrare Netopia Payments + simulator local | 3-4 ore | Pas 12 | ✅ DONE (simplificat) |
| 15 | Notif | Email tranzacțional + notificări in-app | 2-3 ore | Pas 10 | ⏭️ AMÂNAT |
| 16 | Admin | EasyAdmin panel complet | 2-3 ore | Pas 10 | ✅ DONE (simplificat) |
| 17 | Securitate | Rate limiting pe endpoint-uri critice | 2-3 ore | Pas 10 | ✅ DONE (parțial — rate limiting) |
| 18 | Deploy | CI/CD + Coolify + producție + Security headers | 2-3 ore | Pas 17 | |

> **Teste:** se scriu incremental la fiecare pas (nu un pas separat la final). Fiecare pas include o secțiune "Teste minime" cu ce trebuie acoperit.

> **Pași paraleli:** 12-13, 14-15-16-17 pot fi dezvoltați în paralel (ramuri Git separate) după ce Pas 11 e complet.

---

## Reguli pentru lucrul cu Claude Code

- **Un pas = un prompt.** Nu combina mai mulți pași într-un singur prompt.
- **Verifică după fiecare pas.** Rulează aplicația, testează manual, apoi treci mai departe.
- **Commit după fiecare pas.** `git commit` după fiecare pas reușit. Poți reveni dacă ceva se strică.
- **Dă context.** La începutul promptului, menționează ce s-a făcut deja.
- **Atașează documente relevante.** Tech stack-ul și fluxurile de business ca context.
- **Cere explicații când nu înțelegi.** Înainte să treci mai departe.
- **Teste la fiecare pas.** Scrie cel puțin teste unitare pentru logica de business și teste funcționale pentru controller-e.

---

## Faza 1: Setup proiect

### PASUL 1 | Proiect Symfony + Docker Compose | ✅ DONE

Deja implementat. Docker Compose cu 4 containere (php, nginx, mysql, mailpit), Symfony 7.3, PHP 8.4, Asset Mapper, Doctrine, Messenger cu Doctrine transport, EasyAdmin de bază, autentificare de bază (login/register/email verification).

**Ce există:**
- Docker: php (8.4-FPM) + nginx (port 8080) + mysql (port 3307) + mailpit (port 8025)
- Symfony cu Asset Mapper + Importmap + Stimulus + Turbo
- Doctrine ORM + Migrations + Messenger (Doctrine transport)
- Security bundle cu form login + email verification
- EasyAdmin dashboard + User CRUD
- Entity: User cu email/password/roles/isVerified
- `make setup` pornește totul

---

### PASUL 2 | Tailwind Bundle + verificare compilare | ✅ DONE

Instalare Tailwind CSS fără Node.js. Doar configurare, fără layout — verificăm că totul compilează corect.

**PROMPT:**
> Instalează și configurează symfonycasts/tailwind-bundle pentru Tailwind CSS (compilare via Tailwind CLI standalone, fără Node.js). Rulează `php bin/console tailwind:init` pentru a genera tailwind.config.js și fișierul CSS inițial. Configurează tailwind.config.js să scaneze templates/ și assets/ pentru clase. Adaugă directiva @tailwind în fișierul CSS principal. Verifică că `php bin/console tailwind:build` compilează fără erori. Adaugă comanda `tailwind:build` în docker-entrypoint.sh pentru development. NU modifica layout-ul sau template-urile existente încă — doar instalare și verificare.

**VERIFICARE MANUALĂ:**
- [ ] `php bin/console tailwind:build` compilează fără erori
- [ ] Fișierul CSS compilat există și conține clase Tailwind
- [ ] Aplicația încarcă CSS-ul fără erori în browser (verifică Network tab)

**TESTE MINIME:** Nu e cazul — pas de configurare.

---

### PASUL 3 | Layout responsive + homepage | ✅ DONE

Layout-ul master Twig cu Tailwind și o homepage funcțională.

**PROMPT:**
> Creează layout-ul master Twig (base.html.twig) responsive cu Tailwind CSS. Structură: header cu logo text "RecuperăriCreanțe" și navigație (Home, Depune cerere, Dosarele mele — vizibile doar autentificat; Login/Înregistrare — vizibile neautentificat; link Admin — vizibil ROLE_ADMIN), sidebar pentru dashboard (ascuns pe mobile, toggle cu Stimulus controller), zonă de conținut principal, footer minimal. Font: DM Sans (Google Fonts, încărcat via CDN). Schema de culori: albastru închis (#1E3A5F) primar, albastru (#2563EB) accent, gri deschis (#F9FAFB) fundal. Creează homepage cu: hero section (titlu, descriere scurtă, buton CTA "Depune cerere"), secțiune "Cum funcționează" (3 pași vizuali), secțiune beneficii. Adaptează paginile existente (login, register) să folosească noul layout. Verifică pe mobile și desktop.

**VERIFICARE MANUALĂ:**
- [ ] Homepage se afișează cu stiluri Tailwind corecte
- [ ] Layout responsive: testează mobile view (Chrome DevTools)
- [ ] Sidebar toggle funcționează pe mobile (Stimulus)
- [ ] Turbo Drive funcționează (navigația nu reîncarcă toată pagina)
- [ ] Paginile login/register folosesc noul layout

**TESTE MINIME:** Un test funcțional că homepage-ul răspunde 200.

---

## Faza 2: Bază de date

### PASUL 4 | Entități Doctrine + migrări | ✅ DONE

Entitățile aplicației cu relații, auto-increment int IDs și soft deletes.

**Ce s-a implementat:**
- Extindere User cu: type (PHP enum PF/PJ/AVOCAT/ADMIN), CNP, CUI, companyName, barNumber, phone, adresă completă (street, streetNumber, block, staircase, apartment, city, county, postalCode), deletedAt
- 6 PHP enums: UserType, CourtType, DocumentType, PaymentType, PaymentStatus, NotificationChannel
- 7 entități noi: Court, LegalCase (cu toate câmpurile wizard 6 pași), CaseStatusHistory, Document, Payment, Notification, AuditLog
- 7 repositories cu query methods utile
- O singură migrare pentru toate schimbările
- **Decizie**: INT auto-increment pe toate entitățile (nu UUID v7, pentru ușurința debugging-ului)

---

### PASUL 5 | Date de referință (instanțe) + useri de test | ✅ DONE

Instanțele din România ca import din JSON și useri de test simpli (fără Foundry).

**PROMPT:**
> Creează două lucruri separate: (1) Un command Symfony `app:import-courts` care importă lista completă de judecătorii și tribunale din România dintr-un fișier JSON inclus în proiect (data/courts.json). Include toate judecătoriile (~180) și tribunalele (~42) cu: nume oficial, județ, tip (judecatorie/tribunal). Command-ul e idempotent (rulat de mai multe ori nu duplică). Adaugă apelul în docker-entrypoint.sh după migrări. (2) Un command Symfony `app:create-test-users` care creează 5 useri de test: admin@test.com cu ROLE_ADMIN, creditor-pf@test.com PF, creditor-pj@test.com PJ, avocat@test.com AVOCAT, user@test.com doar ROLE_USER. Parola "password" pentru toți, toți verificați. Command-ul e idempotent.

**Ce s-a amânat pentru pași ulteriori:**
- ~~Fixtures cu zenstruck/foundry~~ — nu e necesar încă, userii de test se creează cu command simplu
- ~~Dosare de test (draft, pending_payment, paid)~~ — se vor crea manual sau automat după implementarea wizard-ului (Pașii 8-9)
- ~~Documente și plăți asociate~~ — depind de funcționalități neimplementate încă

**VERIFICARE MANUALĂ:**
- [ ] `php bin/console app:import-courts` importă instanțele fără erori
- [ ] Rulat a doua oară nu duplică
- [ ] `php bin/console app:create-test-users` creează userii de test
- [ ] Rulat a doua oară nu duplică
- [ ] Login cu admin@test.com / password funcționează

**TESTE MINIME:** Test funcțional că `app:import-courts` importă instanțe și că `app:create-test-users` creează useri.

---

## Faza 3: Autentificare

### PASUL 6 | Înregistrare simplă + Login + Verificare email | ⏭️ AMÂNAT

> **AMÂNAT** — Auth-ul de bază (login/register/email verification) funcționează deja. Upgrade-ul formularului de înregistrare și pagina de profil se vor face după wizard (Pașii 8-9). Wizard-ul nu depinde de aceste îmbunătățiri.

Upgrade-ul sistemului de auth existent: formular de înregistrare cu selecție tip utilizator, login, verificare email. Încă fără multi-step sau validatori complecși.

**PROMPT:**
> Extinde sistemul de autentificare existent. Modifică formularul de înregistrare: adaugă selecție tip utilizator (Persoană fizică, Persoană juridică, Avocat) ca prim câmp, apoi email + parolă + confirmare parolă + nume + prenume + telefon. Configurează rolurile: la înregistrare se atribuie automat ROLE_USER + ROLE_CREDITOR. ROLE_ADMIN se atribuie doar manual. Verifică că email verification (symfonycasts/verify-email-bundle) funcționează cu noile câmpuri. Redirect post-login: dacă user are dosare, redirect la /dashboard, altfel la homepage. Redirect neautentificat: orice rută protejată duce la /login. Adaugă pagina /dashboard (gol deocamdată, cu layout-ul Tailwind, mesaj "Dashboard-ul tău — în curând"). Emailurile se trimit prin Mailpit. Toate paginile folosesc layout-ul Tailwind creat la Pasul 3.

**VERIFICARE MANUALĂ:**
- [ ] Creezi cont nou cu tip PF, primești email în Mailpit
- [ ] După verificare email, contul funcționează
- [ ] Login/logout funcționează
- [ ] Redirect la /dashboard după login
- [ ] Neautentificat pe /dashboard → redirect /login

**TESTE MINIME:**
- Test funcțional: înregistrare valid + invalid (email duplicat, parolă scurtă)
- Test funcțional: login valid + invalid
- Test funcțional: acces /dashboard neautentificat → redirect 302

---

### PASUL 7 | Înregistrare multi-step + Validatori custom + Profil + Forgot password | ✅ DONE (parțial)

Upgrade pagina de profil, forgot password și login rate limiting. Înregistrare multi-step și validatori CNP/CUI rămân amânate.

**Ce s-a implementat:**
- `ResetPasswordController` — forgot password complet: request (email) → check email → reset (parolă nouă + confirmare)
- `symfony/reset-password-bundle` instalat + `ResetPasswordRequest` entity + migrare
- `ResetPasswordService` — serviciu extras (SOLID) pentru logica forgot password
- Template-uri email forgot password (Twig, branded)
- `ProfileController` — vizualizare profil + editare (prenume, nume, telefon) + schimbare parolă
- Login rate limiting: `login_throttling: max_attempts: 5, interval: '1 minute'` în `security.yaml`
- Traduceri RO + EN complete (reset_password.*, profile.*)
- Teste funcționale: forgot password flow, profil editare, schimbare parolă

**Ce s-a amânat (post-MVP):**
- Înregistrare multi-step (PF/PJ/Avocat cu câmpuri diferite per tip)
- Validatori custom CNP (checksum) și CUI (format)
- Parolă complexă (literă mare + cifră) — momentan min 6 caractere
- Selecție tip utilizator la înregistrare

---

## Faza 4: Funcționalitate core

### PASUL 8 | Wizard cerere pașii 1-4 (formulare text) | ✅ DONE

Prima parte a wizard-ului: selecție instanță, date reclamant, date pârât, descriere creanță. PRG (Post-Redirect-Get) cu Turbo Drive pentru navigare SPA-like.

**Ce s-a implementat:**
- `CaseWizardController` cu rute `/case/new`, `/case/{id}/step/{step}`, `/case/courts-by-county/{county}`
- DTOs: `Step2ClaimantData`, `Step3DefendantsData`, `Step3DefendantEntry` cu validări Symfony
- FormTypes: `Step1CourtType`, `Step2ClaimantType`, `Step3DefendantType`, `Step4ClaimType`
- Stimulus controllers: `court_selector` (cascadare județ→instanță), `conditional_fields` (PF/PJ, avocat, dobândă), `collection` (add/remove pârâți)
- Templates: `wizard.html.twig`, `_stepper.html.twig`, `_step1-4_content.html.twig`, `_defendant_fields.html.twig`
- `DashboardController` cu `/dashboard/cases` — tabel dosare cu badge-uri status
- Traduceri RO + EN complete + validări
- Navigare `base.html.twig` actualizată cu "Depune cerere" + "Dosarele mele"
- 15 teste funcționale (toate trec)

**Decizie**: PRG + Turbo Drive (nu Turbo Frames) — mai simplu, fiecare pas e un page load cu cache Turbo.

---

### PASUL 9 | Wizard cerere pașii 5-6 (probe + confirmare + calculator taxă) | ✅ DONE

Completarea wizard-ului: probe/martori, calculator taxă judiciară, pagina de confirmare, depunere finală. **Upload fișiere amânat la Pasul 13 (Flysystem).**

**Ce s-a implementat:**
- `TaxCalculatorService` — calculator taxă OUG 80/2013 (≤2000 RON = 50; 2001-10000 = 250 + 2%(sumă-2000); platformFee = 29.90)
- DTOs: `Step5EvidenceData`, `Step5WitnessEntry` cu validări (max 5 martori)
- FormTypes: `WitnessEntryType`, `Step5EvidenceType` (CollectionType cu prototip), `Step6ConfirmationType` (2 checkboxuri IsTrue)
- Controller actualizat: steps 5-6 în `createStepForm()` / `saveStepData()` + `calculateAndSaveFees()` + `submitCase()`
- La depunere: creează 2 entități `Payment` (TAXA_JUDICIARA + COMISION_PLATFORMA), status→`pending_payment`, `submittedAt` setat
- Templates: `_step5_content.html.twig` (probe + martori dinamici), `_step6_content.html.twig` (sumar complet + taxe + confirmare), `_witness_fields.html.twig`
- Dashboard actualizat cu badge `pending_payment` (portocaliu)
- Flash messages traduse corect (fix `|trans` în `base.html.twig`)
- 8 unit tests TaxCalculator + 7 teste funcționale noi (total 38 teste, toate trec)

**Ce s-a amânat:**
- Upload fișiere → Pasul 13 (VichUploader + Flysystem)

---

### PASUL 10 | Workflow dosar + Voters + Dashboard creditor + Audit log | ✅ DONE (parțial)

State machine, autorizare per dosar (voters), audit log. Implementare simplificată — funcționalități core fără dashboard avansat.

**Ce s-a implementat:**
- `symfony/workflow` instalat + `config/packages/workflow.yaml` — state_machine `legal_case` cu 9 statusuri și 9 tranziții
- `CaseWorkflowService` — wrappează Workflow component (apply, can, getAvailableTransitions)
- `CaseWorkflowSubscriber` — EventSubscriber pe `workflow.legal_case.completed`, creează automat `CaseStatusHistory` + `AuditLog` la fiecare tranziție
- `CaseVoter` — CASE_VIEW (proprietar sau admin), CASE_EDIT (proprietar + draft sau admin)
- Controller actualizat: `submitCase()` folosește `workflowService->apply()` în loc de `setStatus()` manual; `loadAndAuthorize()` folosește `denyAccessUnlessGranted('CASE_EDIT')` în loc de verificări manuale; `view()` folosește `denyAccessUnlessGranted('CASE_VIEW')`
- Timeline vizuală pe pagina `case/view.html.twig` cu `CaseStatusHistory`
- Badge-uri colorate pe dashboard + view pentru toate cele 9 statusuri (cu traduceri `case_status.*`)
- Traduceri RO + EN complete pentru statusuri
- 7 unit tests CaseWorkflowService + 6 unit tests CaseVoter + teste funcționale actualizate (total 53 teste, toate trec)

**Ce s-a amânat (se va implementa în pași dedicați sau viitoare iterații):**
- Filtre dashboard (status dropdown, perioadă, sumă min/max) — viitor pas dedicat
- Paginare dosare în dashboard — viitor pas dedicat
- Turbo Frames pe dashboard — viitor pas dedicat
- DocumentVoter — vine cu Pas 13 (upload documente)
- Pagina detalii dosar avansată (butoane acțiuni per status, secțiune documente, audit log complet) — viitor pas dedicat
- Acțiune admin "Schimbă status" cu modal — vine cu Pas 16 (EasyAdmin complet)

**VERIFICARE MANUALĂ:**
- [x] Workflow: doar tranzițiile definite sunt permise
- [x] Voter: user A nu poate vedea dosarul user B (403)
- [x] Voter: admin poate vedea orice dosar
- [x] AuditLog se populează automat la tranziție
- [x] CaseStatusHistory se populează automat la tranziție
- [x] Timeline vizuală pe pagina detalii dosar

---

### PASUL 11 | Configurare Messenger worker + procesare async | ⏭️ SKIP

> **SKIP** — Absorbit în Pas 12 (PDF sync) și Pas 15 (Notificări). Messenger este deja configurat (transport async Doctrine + failed). Worker Docker și routing se adaugă când apare nevoia reală de procesare async (email, PDF mare, etc.).

---

## Faza 5: Documente

### PASUL 12 | Generare PDF (DomPDF) | ✅ DONE (sync, fără Messenger)

Generare PDF "Cerere cu Valoare Redusă" (Anexa 1) precompletat cu datele dosarului, sincron la depunere.

**Ce s-a implementat:**
- `dompdf/dompdf` instalat
- `PdfGeneratorService` — generează PDF din template Twig, salvează pe disk, creează entitate `Document`
- Template `templates/pdf/cerere_valoare_redusa.html.twig` — Anexa 1 cu 5 secțiuni (instanță, părți, cerere, detalii, semnătură), font DejaVu Sans (diacritice), CSS inline, format A4
- `DocumentController` — rută download cu verificare `CASE_VIEW` (voter)
- Generare automată la submit wizard (draft→pending_payment) — `submitCase()` apelează sync `generateCasePdf()`
- Secțiune "Documente" pe pagina detalii dosar cu link download
- Stocare: `var/uploads/cases/{id}/cerere_{id}.pdf`
- 1 unit test PdfGeneratorService + 3 teste DocumentController + asertări Document în teste existente (total 57 teste)

**Ce s-a amânat:**
- Procesare async via Messenger — PDF se generează sync (~200ms), se migra trivial
- Worker Docker Messenger — nu e necesar fără async
- Regenerare PDF (buton "Regenerează")
- AuditLog la generare PDF — se adaugă când e nevoie

---

### PASUL 13 | Upload documente (simplificat) | ✅ DONE

Upload, stocare și management documente per dosar — simplificat fără VichUploader/Flysystem.

**Ce s-a implementat:**
- `DocumentUploadType` — FormType cu file (FileType, max 10MB, PDF/JPG/PNG) + documentType (ChoiceType: DOVADA, CONTRACT, FACTURA, ALT_DOCUMENT)
- `DocumentController` extins cu rute upload (POST) și delete (POST cu CSRF)
- `CaseVoter` extins cu `CASE_UPLOAD` — proprietar + status draft/pending_payment, sau admin
- Template `_documents_section.html.twig` — listă documente cu icon per tip (PDF=roșu, imagine=verde), upload form inline, butoane download + delete
- AuditLog la upload (document_upload) și delete (document_delete)
- Protecție: CERERE_PDF nu poate fi șters
- Fișiere salvate cu UUID v4 ca nume, în `var/uploads/cases/{caseId}/`
- Traduceri RO + EN complete
- 4 teste voter (CASE_UPLOAD) + 6 teste controller (upload valid, upload alt user, upload max files, delete propriu, delete CERERE_PDF, delete alt user) — total 67 teste

**Ce s-a amânat:**
- VichUploader — upload manual cu `UploadedFile->move()` e suficient
- Flysystem / S3 — local storage, migrare ulterioară (un singur service de schimbat)
- Drag & drop — polish UI
- Turbo Frames — PRG standard
- DOC/DOCX support — doar PDF/JPG/PNG
- Upload multiplu — un fișier pe request
- Preview imagine (thumbnail-uri)

---

## Faza 6: Plăți

### PASUL 14 | Plăți cu simulator (simplificat) | ✅ DONE

Simulator de plată pentru MVP — fără integrare reală Netopia.

**Ce s-a implementat:**
- `PaymentController` — pagină plată GET `/case/{id}/payment` (rezumat taxe, notă simulator, buton "Plătește") + POST `/case/{id}/payment/process` (marchează Payment entities COMPLETED, aplică workflow `confirm_payment`, creează AuditLog)
- Template `payment.html.twig` — pagină Tailwind cu rezumat taxe, notă informativă simulator, formular CSRF + buton verde
- Buton "Plătește acum" pe pagina detalii dosar (vizibil doar pentru `pending_payment`)
- Link "Plătește" în tabel dosare dashboard (între "Continuă" și "Vezi")
- Redirect wizard submit → pagina plată (în loc de dashboard)
- Simulare: `paymentMethod = 'simulator'`, `externalReference = 'SIM-{timestamp}'`
- Verificări: CSRF, CASE_VIEW voter, status pending_payment (altfel redirect cu flash)
- AuditLog: action `payment_completed` cu detalii plăți
- Traduceri RO + EN complete (payment.*)
- 8 teste funcționale (afișare taxe, auth, non-owner 403, only pending_payment, payments COMPLETED, workflow transition, AuditLog, invalid CSRF)

**Ce s-a amânat (post-MVP):**
- Integrare Netopia reală (webhook IPN, redirect flow, certificat SSL merchant)
- Pagină checkout cu formular card / 3D Secure
- Facturi/chitanțe PDF automate
- Refund flow (status REFUNDED)
- Retry pe plăți eșuate
- Email notificare plată
- PaymentCrudController EasyAdmin
- NetopiaService (initiatePayment, processIpn, verifySignature)

---

## Faza 7: Notificări

### PASUL 15 | Email tranzacțional + notificări in-app | ⏭️ AMÂNAT

> **AMÂNAT** — Pasul conține două funcționalități distincte care nu sunt necesare la MVP:
> - **Email-uri tranzacționale** (confirmare plată, schimbare status) — se adaugă natural la integrarea Netopia reală (post-MVP). Email-ul de verificare cont funcționează deja.
> - **Notificări in-app** (bell icon, dropdown, polling, pagina /notifications) — efort mare pentru valoare mică la MVP. Utilizatorul vede statusul dosarului direct în dashboard. Devin utile când platforma are mulți useri activi.
>
> **Când se implementează:** email-urile tranzacționale se adaugă ca subscriber simplu la integrarea Netopia. Notificările in-app se implementează post-lansare, pe baza feedback-ului utilizatorilor.

Email-uri pe acțiuni cheie și sistem de notificări în dashboard.

**PROMPT:**
> Implementează SendEmailMessageHandler: procesează SendEmailMessage async, trimite email via Symfony Mailer. Creează template-uri email HTML (Twig, în templates/email/) cu design consistent (logo, culori brand, footer): confirmare_inregistrare.html.twig, confirmare_plata.html.twig, schimbare_status.html.twig, reminder_plata.html.twig. Creează EmailNotificationService în src/Service/Notification/ care construiește și dispatch-uiește SendEmailMessage. Integrează: la înregistrare → email confirmare (deja existent, verifică că folosește template-ul nou), la plată confirmată → email confirmare plată, la schimbare status dosar → email notificare. Configurare: Mailpit în dev (deja), Resend în prod via .env (MAILER_DSN=resend+api://KEY@default). Notificări in-app: bell icon în header cu counter badge (număr necitite), click → dropdown cu ultimele 10 notificări (titlu, mesaj scurt, timp relativ, punct colorat necitit), link 'Vezi toate' → pagina /notifications cu toate notificările paginate. Click pe notificare: marchează citită + redirect la linkResursa. Buton 'Marchează toate ca citite'. Stimulus controller care face polling la 30 secunde pentru actualizare badge fără page reload. EventSubscriber pe workflow transitions care creează automat Notification entity (in_app) la fiecare schimbare de status.

**VERIFICARE MANUALĂ:**
- [ ] Email-uri apar în Mailpit la înregistrare, plată, schimbare status
- [ ] Template-urile email arată bine (HTML formatat)
- [ ] Bell icon: counter corect de necitite
- [ ] Dropdown: notificări recente afișate
- [ ] Click notificare → citită + redirect
- [ ] Polling actualizează badge-ul automat

**TESTE MINIME:**
- Test: EventSubscriber creează Notification la tranziție workflow
- Test funcțional: pagina /notifications răspunde 200
- Test: mark as read funcționează

---

## Faza 8: Administrare

### PASUL 16 | EasyAdmin panel complet (simplificat) | ✅ DONE

Panoul de administrare cu CRUD-uri pe entitățile esențiale + schimbare status dosar via Workflow.

**Ce s-a implementat:**
- `LegalCaseCrudController` — listă dosare (id, creditor, reclamant, pârât, instanță, sumă, status badge, dată), filtre (status, instanță, sumă, dată), detalii complet (toate câmpurile), readonly (fără new/edit/delete), acțiune custom "Schimbă status" cu Workflow valid transitions
- `CaseStatusController` — pagină separată `/admin/case/{id}/change-status` cu formular (select tranziție, motiv opțional, CSRF), aplică Workflow, creează AuditLog (admin_status_change)
- `CourtCrudController` — CRUD complet instanțe (nume, județ, tip, adresă, email, telefon, activ), filtre (județ, tip, activ), searchable (nume, județ)
- `AuditLogCrudController` — readonly (fără new/edit/delete), coloane (user, acțiune, tip entitate, ID, data), filtre (user, acțiune, tip entitate, dată), detalii cu JSON date vechi/noi
- Dashboard extins: secțiune Utilizatori (4 cards: total, verificați, neverificați, admini) + secțiune Dosare (8 cards: total, ciornă, așteptare plată, plătit, la instanță, în analiză, admise, respinse) + secțiune Financiar (venituri luna curentă)
- Meniu extins: Dashboard, Dosare, Utilizatori, Instanțe, Jurnal audit
- Entități: `__toString()` pe User și LegalCase, getters `claimantName` și `firstDefendantName` pe LegalCase, getters `oldDataJson`/`newDataJson` pe AuditLog
- 10 teste funcționale (dashboard acces admin/user/anonim, stats, meniu, change status GET/POST valid/invalid tranziție/CSRF, forbidden non-admin)

**Ce s-a amânat:**
- PaymentCrudController + export CSV — depinde de Pas 14 (Netopia), fără plăți reale nu are sens
- NotificationCrudController — depinde de Pas 15 (notificări)
- Blocare/deblocare user — necesită câmp `blocked` + migrare + verificare login
- Mini-grafic dosare pe lună — nice-to-have, nu MVP
- Extindere UserCrudController cu câmpuri noi (CNP, CUI, telefon) — îmbunătățire ulterioară
- Modal EasyAdmin pentru schimbare status — pagină separată e suficientă

**VERIFICARE MANUALĂ:**
- [x] Dashboard afișează statisticile corecte (utilizatori + dosare + venituri)
- [x] LegalCase CRUD: list + detail + filtre funcționează
- [x] Schimbare status dosar din admin respectă Workflow (doar tranziții valide)
- [x] AuditLog readonly, cu filtre funcționale
- [x] CourtCrud: list + edit + add funcționează
- [x] User fără ROLE_ADMIN → 403
- [x] Anonim → redirect login

---

## Faza 9: Securitate și Deploy

### PASUL 17 | Rate limiting pe endpoint-uri critice | ✅ DONE (parțial — rate limiting)

Rate limiting pe endpoint-urile critice. Security headers și GDPR au fost separate (headers → Pas 18/Nginx, GDPR → post-MVP).

**Ce s-a implementat:**
- `config/packages/rate_limiter.yaml` — 4 rate limiters cu sliding_window policy:
  - `registration`: 3/oră per IP
  - `forgot_password`: 3/oră per IP
  - `case_creation`: 10/oră per user
  - `document_upload`: 20/oră per user
- `RegistrationController` — `RateLimiterFactory $registrationLimiter` pe POST, keyed on IP
- `ResetPasswordController` — `RateLimiterFactory $forgotPasswordLimiter` pe POST, keyed on IP
- `CaseWizardController` — `RateLimiterFactory $caseCreationLimiter` pe POST, keyed on user
- `DocumentController` — `RateLimiterFactory $documentUploadLimiter` pe POST, keyed on user
- Overrides test env: `no_limit` policy pe toate 4 limiters (evită interferențe cu teste)
- Traduceri RO + EN: `rate_limit.*` (mesaje prietenoase)
- 4 teste KernelTestCase: verificare configurare fabrici limiter
- Total: 229 teste (toate trec)

**Ce s-a amânat:**
- **Security headers (CSP, HSTS, X-Frame-Options)** — se configurează la deploy în Nginx (Pas 18), nu nelmio/security-bundle
- **GDPR (export date, ștergere cont, anonimizare)** — mutat la post-MVP (vezi secțiunea "După MVP")

**VERIFICARE MANUALĂ:**
- [x] Rate limiter blochează după limita configurată (testat manual)
- [x] Mesaje flash prietenoase la limită atinsă
- [x] Teste trec cu `no_limit` policy în env test
- [x] 229 teste trec

---

### PASUL 18 | CI/CD + Deploy producție + Security headers | ~2-3 ore

Pipeline GitHub Actions, deploy pe Hetzner via Coolify, și security headers în Nginx.

**PROMPT:**
> Configurează CI/CD complet. GitHub Actions (.github/workflows/ci.yml): job 'test' (on push/PR): services MySQL 8.0, PHP 8.4 setup, composer install, php bin/console tailwind:build --minify, php bin/console asset-map:compile, php bin/console doctrine:migrations:migrate --no-interaction, ./bin/phpunit --testdox. Job 'quality' (on push/PR, paralel cu test): PHPStan nivel 6 (phpstan.neon cu paths src/ tests/), PHP-CS-Fixer --dry-run --diff. IMPORTANT: fără npm/Node.js în CI — totul e PHP. Job 'deploy' (on merge to main, după test+quality): build Docker image multi-stage, push GitHub Container Registry, trigger Coolify deploy webhook (URL din secret). Dockerfile producție: FROM php:8.4-fpm-alpine, instalare extensii (pdo_mysql, intl, gd, zip, opcache), COPY composer files + composer install --no-dev --optimize-autoloader, COPY source, RUN tailwind:build --minify + asset-map:compile, OPcache settings optimale. docker-compose.prod.yml referință: app + nginx + mysql cu variabile din .env.prod, volume persistente var/uploads/ + var/log/, restart always, healthchecks. Script deploy.sh: wait for db, doctrine:migrations:migrate --no-interaction, cache:clear, cache:warmup, app:import-courts. Security headers în nginx.conf producție: Content-Security-Policy (self + inline styles + Google Fonts), Strict-Transport-Security (max-age=31536000), X-Frame-Options (DENY), X-Content-Type-Options (nosniff), Referrer-Policy (strict-origin-when-cross-origin).

**VERIFICARE MANUALĂ:**
- [ ] Push pe branch → GitHub Actions rulează test + quality
- [ ] Teste trec verde în CI
- [ ] PHPStan fără erori
- [ ] Docker build local: `docker build -f Dockerfile.prod .` funcționează
- [ ] Dockerfile NU conține Node.js
- [ ] `deploy.sh` rulează migrări + cache clear

**TESTE MINIME:** CI-ul însuși este testul — verifică că toate testele existente trec.

---

## Lucru suplimentar (neplanificat inițial)

### Refactorizare SOLID

Extragere logică de business din controllere în servicii dedicate, conform principiului Single Responsibility:

- `RegistrationService` — logică înregistrare + verificare email (extras din `RegistrationController`)
- `ResetPasswordService` — logică forgot password (extras din `ResetPasswordController`)
- `CaseSubmissionService` — logică submit dosar + generare PDF + creare plăți (extras din `CaseWizardController`)
- `DocumentUploadService` — logică upload + delete documente (extras din `DocumentController`)
- `FlashType` enum — constante pentru tipuri flash messages (success, error, warning, info)
- `AuditAction` enum — constante pentru acțiuni audit log
- 24 teste noi pentru serviciile extrase

### Test coverage extins

De la ~67 teste la **229 teste** — 15 fișiere de test noi:
- Teste unitare: servicii, enum-uri, entități
- Teste funcționale: controllere, securitate, autorizare
- Teste KernelTestCase: configurare rate limiters

---

## După MVP — Ce urmează

Feature-uri planificate post-lansare, în ordinea priorității:

1. **GDPR — Conformitate date personale:** Pagina "Datele mele" (/profile/my-data) cu: export date personale (JSON), ștergere cont cu anonimizare (email→deleted_uuid@anonimizat.ro, CNP/CUI→null, nume→"Utilizator șters"), păstrare dosare 7 ani (cerință legală), logout automat, AuditLog la export/ștergere
2. **Integrare Netopia reală:** Credențiale reale, cont business, PCI compliance, testare end-to-end
3. **Email tranzacțional + notificări in-app:** Confirmare plată, schimbare status, bell icon cu polling (fostul Pas 15)
4. **Înregistrare multi-step + validatori CNP/CUI:** Formular diferit per tip utilizator PF/PJ/Avocat (restul din Pas 6+7)
5. **2FA (TOTP):** `composer require scheb/2fa-totp-bundle` — QR code pentru Google Authenticator
6. **Redis:** Migrare Messenger + sesiuni + cache (schimbare .env, adăugare container)
7. **SMS notificări:** symfony/notifier + Vonage bridge pentru acțiuni urgente
8. **Portal instanțe:** Integrare registratura.rejust.ro pentru depunere electronică
9. **Comunicare pârât:** Pârâtul primește cererea și răspunde online
10. **Push notifications:** FCM + Service Worker PWA
11. **Executare silită:** Flux post-hotărâre cu cerere executare
12. **Meilisearch:** Căutare full-text avansată în dosare (înlocuiește MySQL FULLTEXT)
13. **Semnătură electronică:** Integrare furnizori semnătură calificată
14. **Encryption at rest:** AES-256 pe documente sensibile (custom Doctrine type)
15. **API mobilă:** API Platform bundle + aplicație React Native/Flutter

---

> **Fiecare pas construit pe cel anterior. Nu sări pași. Commit după fiecare reușită. Teste la fiecare pas.**
