# PLAN DE DEZVOLTARE MVP — Pași progresivi pentru Claude Code

> Recuperare Creanțe — Cerere cu Valoare Redusă
> Februarie 2026 | v3 — optimizat pentru dezvoltare progresivă și sigură
> 18 pași | 9 faze | ~12-16 săptămâni

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
| 6 | Auth | Înregistrare simplă + Login + Verificare email | 2-3 ore | Pas 3, 4 | |
| 7 | Auth | Înregistrare multi-step + Validatori + Profil + Forgot password | 2-3 ore | Pas 6 | |
| 8 | Core | Wizard cerere pașii 1-4 (formulare, fără upload) | 4-6 ore | Pas 7 | |
| 9 | Core | Wizard cerere pașii 5-6 (upload dovezi + confirmare + calculator taxă) | 3-4 ore | Pas 8 | |
| 10 | Core | Workflow dosar + Voters + Dashboard creditor + Audit log | 3-4 ore | Pas 9 | |
| 11 | Core | Configurare Messenger worker + procesare async | 1-2 ore | Pas 10 | |
| 12 | Docs | Generare PDF (DomPDF) via Messenger | 2-3 ore | Pas 11 | |
| 13 | Docs | Upload documente (VichUploader + Flysystem) | 2-3 ore | Pas 10 | |
| 14 | Plăți | Integrare Netopia Payments + simulator local | 3-4 ore | Pas 12 | |
| 15 | Notif | Email tranzacțional + notificări in-app | 2-3 ore | Pas 10 | |
| 16 | Admin | EasyAdmin panel complet | 2-3 ore | Pas 10 | |
| 17 | Securitate | Hardening: CSP, rate limiting, GDPR | 2-3 ore | Pas 10 | |
| 18 | Deploy | CI/CD + Coolify + producție | 2-3 ore | Pas 17 | |

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

### PASUL 6 | Înregistrare simplă + Login + Verificare email | ~2-3 ore

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

### PASUL 7 | Înregistrare multi-step + Validatori custom + Profil + Forgot password | ~2-3 ore

Upgrade înregistrare la multi-step cu câmpuri per tip utilizator, validări avansate, pagina de profil și reset parolă.

**PROMPT:**
> Upgrade la înregistrare multi-step cu Turbo Frames (fără page reload): Pas 1 — selecție tip utilizator (PF/PJ/Avocat) cu carduri vizuale. Pas 2 — câmpuri diferite per tip: PF cere nume, prenume, CNP, adresă completă (stradă, număr, localitate, județ, cod poștal); PJ cere denumire firmă, CUI, adresă sediu, nume reprezentant; Avocat cere nume, prenume, număr legitimație barou, barou aparținător. Pas 3 — email + parolă + confirmare parolă + telefon + accept termeni. Creează validatori custom Symfony: CNP validator (13 cifre + checksum conform algoritmului oficial), CUI validator (format valid). Parolă: min 8 caractere, cel puțin o literă mare, o cifră. Implementează forgot password cu symfony/reset-password-bundle: link pe email, token 1 oră validitate, pagina resetare. Pagina profil (/profile): vizualizare și editare date personale, secțiune schimbare parolă (parolă curentă + nouă + confirmare). Toate cu Tailwind.

**VERIFICARE MANUALĂ:**
- [ ] Înregistrare multi-step: PF, PJ, Avocat — toate funcționează
- [ ] CNP invalid (checksum greșit) → eroare de validare
- [ ] CUI invalid → eroare de validare
- [ ] Forgot password: email în Mailpit, link funcționează, parola se schimbă
- [ ] Profil: editare date + schimbare parolă funcționează
- [ ] Navigare înapoi în wizard păstrează datele

**TESTE MINIME:**
- Unit test: CNP validator (valid, invalid, checksum greșit, lungime greșită)
- Unit test: CUI validator (valid, invalid)
- Test funcțional: wizard înregistrare complet (happy path per tip)
- Test funcțional: forgot password flow

---

## Faza 4: Funcționalitate core

### PASUL 8 | Wizard cerere pașii 1-4 (formulare text) | ~4-6 ore

Prima parte a wizard-ului: selecție instanță, date reclamant, date pârât, descriere creanță. Fără upload, fără calculator taxă — doar formularele de bază cu salvare per pas.

**PROMPT:**
> Implementează wizard-ul de depunere cerere cu valoare redusă ca formular multi-step. Folosește un CaseCreateController cu sesiune sau salvare în DB la fiecare pas (LegalCase cu status 'draft'). Turbo Frames pentru navigare fără page reload. Sidebar cu progress indicator (pas curent evidențiat). Pașii: Pas 1 — Selecție instanță: dropdown județ, la schimbare județ se actualizează lista instanțelor (Stimulus controller cu fetch sau Turbo Frame). Dropdown instanțe din entitatea Court. Pas 2 — Date reclamant: precompletat din profilul utilizatorului logat, cu posibilitate de editare. Dacă PJ, apar câmpurile PJ. Pas 3 — Date pârât: formular pentru pârât (nume/denumire, CNP/CUI opțional, adresă completă, email, telefon). Buton 'Adaugă alt pârât' care afișează încă un set de câmpuri (max 3 pârâți, stocat ca JSON). Pas 4 — Creanța: sumă pretinsă (input numeric, max 10.000 lei conform OUG 80/2013), moneda RON, descrierea detaliată a obligației (textarea), baza legală (textarea), checkbox 'Solicit dobândă legală' cu câmp dată de la care se calculează. Validări pe fiecare pas (câmpuri obligatorii, sumă > 0 și <= 10000). Datele se salvează în LegalCase la fiecare pas — utilizatorul poate închide browserul și reveni. Pagina /dashboard/cases să listeze dosarele existente (draft-uri incluse) cu link de continuare.

**VERIFICARE MANUALĂ:**
- [ ] Parcurgi pașii 1-4 fără erori
- [ ] Schimbi județul → instanțele se actualizează
- [ ] Date reclamant precompletate din profil
- [ ] Adaugi 2-3 pârâți, datele se păstrează
- [ ] Navighezi înapoi — datele sunt acolo
- [ ] Închizi browser, revii — dosarul draft e în /dashboard/cases
- [ ] Sumă > 10000 lei → eroare validare

**TESTE MINIME:**
- Test funcțional: creare dosar draft, parcurgere pași 1-4
- Test funcțional: validare sumă invalidă (0, negativă, > 10000)
- Test unitar: factory LegalCase produce entitate validă

---

### PASUL 9 | Wizard cerere pașii 5-6 (upload + confirmare + calculator taxă) | ~3-4 ore

Completarea wizard-ului: upload dovezi, calculator taxă judiciară, pagina de confirmare, depunere finală.

**PROMPT:**
> Continuă wizard-ul cerere cu pașii 5 și 6. Pas 5 — Dovezi: secțiune cu checkbox-uri tip probă (înscrisuri, martori, expertiză judiciară, interogatoriu), pentru fiecare tip selectat apare un câmp descriere. Upload fișiere multiple: buton upload + drag&drop zone (Stimulus controller), max 10MB per fișier, doar PDF/JPG/PNG acceptate, preview thumbnail pentru imagini, listă fișiere uploadate cu buton de ștergere. Fișierele se salvează temporar (var/uploads/{case_uuid}/). Validare: cel puțin o dovadă selectată. Pas 6 — Confirmare și depunere: sumar complet pe o pagină (instanța, reclamant, pârât/pârâți, creanța, dovezile, fișierele uploadate). Calculator taxă judiciară OUG 80/2013: până la 2000 lei inclusiv = 50 lei taxă fixă, între 2001-10000 lei = 250 lei + 2% din suma care depășește 2000 lei. Afișare: taxă judiciară calculată + comision platformă 29.90 lei + total de plată. Checkbox 'Declar pe proprie răspundere că datele sunt corecte' + checkbox 'Accept termenii și condițiile' (obligatorii). Buton 'Depune cererea'. La depunere: LegalCase.status rămâne 'draft' → tranziția la 'pending_payment' se va face la Pasul 10 cu Workflow. Se creează entitatea Payment cu sumele calculate, status 'pending'. Creează TaxCalculatorService în src/Service/Case/ cu metoda calculate(float $amount): array care returnează taxa, comision și total.

**VERIFICARE MANUALĂ:**
- [ ] Upload fișiere: drag&drop și click funcționează
- [ ] Validare tip fișier (upload .exe → eroare)
- [ ] Validare dimensiune (> 10MB → eroare)
- [ ] Sumarul afișează toate datele corect
- [ ] Calculator taxă: 1000 lei → 50 lei taxă; 5000 lei → 310 lei taxă (250 + 2% din 3000)
- [ ] La depunere: LegalCase + Payment create în DB

**TESTE MINIME:**
- Unit test: TaxCalculatorService — toate intervalele (500 lei, 2000 lei, 2001 lei, 5000 lei, 10000 lei)
- Unit test: TaxCalculatorService — sumă invalidă (0, negativă, > 10000) aruncă excepție
- Test funcțional: upload fișier valid + invalid

---

### PASUL 10 | Workflow dosar + Voters + Dashboard creditor + Audit log | ~3-4 ore

State machine, autorizare per dosar (voters de la început), dashboard-ul creditorului și audit log.

**PROMPT:**
> Configurează Symfony Workflow component pentru LegalCase. Definește workflow-ul 'legal_case' cu statusuri și tranziții: draft → pending_payment (tranziție: 'submit'), pending_payment → paid (tranziție: 'confirm_payment'), paid → submitted_to_court (tranziție: 'submit_to_court'), submitted_to_court → under_review (tranziție: 'mark_received'), under_review → additional_info_requested (tranziție: 'request_info'), additional_info_requested → under_review (tranziție: 'provide_info'), under_review → resolved_accepted (tranziție: 'accept'), under_review → resolved_rejected (tranziție: 'reject'), resolved_accepted → enforcement (tranziție: 'enforce'). Configurează în config/packages/workflow.yaml. Creează CaseWorkflowService în src/Service/Case/ care wrappează Workflow component cu metode semantice. Creează EventSubscriber pe workflow events care: la fiecare tranziție creează CaseStatusHistory entry, la fiecare tranziție creează AuditLog entry (user, IP, acțiune, status vechi/nou). Implementează Security Voters: CaseVoter — doar proprietarul (legalCase.user) sau ROLE_ADMIN poate VIEW/EDIT/DELETE un dosar; DocumentVoter — doar proprietarul dosarului poate download/upload. Aplică voters în controllere cu $this->denyAccessUnlessGranted(). Dashboard creditor (/dashboard): tabel dosare ale userului curent (număr dosar, pârât principal, sumă, status ca badge colorat, dată creare, buton acțiuni). Filtre: status (dropdown), perioadă (date range), sumă (min/max). Paginare. Pagina detalii dosar (/dashboard/cases/{id}): toate datele cererii, timeline vizuală cu tranzițiile din CaseStatusHistory, lista documente, butoane acțiuni condiționate de status (ex: 'Plătește' vizibil doar pe pending_payment), secțiune 'Istoric activitate' cu AuditLog. Turbo Frames pentru filtre și paginare în dashboard. Actualizează wizard-ul (Pasul 9): la depunere, aplică tranziția 'submit' (draft → pending_payment).

**VERIFICARE MANUALĂ:**
- [ ] Workflow: doar tranzițiile definite sunt permise
- [ ] Voter: user A nu poate vedea dosarul user B (403)
- [ ] Voter: admin poate vedea orice dosar
- [ ] Dashboard: afișează doar dosarele utilizatorului logat
- [ ] Filtrele funcționează (status, sumă)
- [ ] Pagina detalii: timeline corect, date complete
- [ ] AuditLog se populează automat la tranziție
- [ ] Secțiunea 'Istoric activitate' afișează cronologic

**TESTE MINIME:**
- Unit test: CaseWorkflowService — tranziții permise și interzise
- Unit test: CaseVoter — proprietar vs. alt user vs. admin
- Test funcțional: dashboard afișează doar dosarele proprii
- Test funcțional: acces dosar străin → 403

---

### PASUL 11 | Configurare Messenger worker + procesare async | ~1-2 ore

Configurarea completă a Symfony Messenger pentru procesare asincronă: routing, worker în Docker, retry, failed transport.

**PROMPT:**
> Configurează Symfony Messenger complet pentru procesare asincronă. În config/packages/messenger.yaml: definește transport 'async' cu Doctrine (doctrine://default), transport 'failed' (doctrine://default?queue_name=failed). Routing: trimite toate mesajele din App\Message\ la transportul 'async'. Creează structura de mesaje: src/Message/GeneratePdfMessage.php (legalCaseId), src/Message/SendEmailMessage.php (recipientEmail, templateName, context array), src/MessageHandler/ cu handlere goale (implementarea reală vine la pașii următori) care doar loghează mesajul primit. Adaugă un proces Messenger worker în Docker: în compose.yaml, un serviciu 'messenger-worker' (sau supervisor în containerul php) care rulează `php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M` cu restart automat. Adaugă comenzi utile în Makefile: `make messenger` (consume), `make messenger-failed` (retry failed), `make messenger-stop` (stop workers). Verifică că un mesaj dispatched ajunge la handler.

**VERIFICARE MANUALĂ:**
- [ ] Worker-ul pornește și consumă mesaje
- [ ] `make messenger` funcționează
- [ ] Dispatch un mesaj de test → handler-ul îl primește (verifică logs)
- [ ] Worker-ul se restartează automat după crash

**TESTE MINIME:**
- Test unitar: mesajele se serializează/deserializează corect
- Test: dispatch mesaj → handler primit (integration test)

---

## Faza 5: Documente

### PASUL 12 | Generare PDF (DomPDF) via Messenger | ~2-3 ore

Generarea automată a cererii în format PDF conform Anexa 1, procesată async prin Messenger.

**PROMPT:**
> Instalează dompdf/dompdf. Creează PdfGeneratorService în src/Service/Document/ cu metoda generateCasePdf(LegalCase $case): string (returnează calea fișierului). Template-ul PDF este un template Twig (templates/pdf/cerere_valoare_redusa.html.twig) care reproduce Anexa 1 (OMJ 359/C/2013) cu: antetul "CERERE CU VALOARE REDUSĂ", secțiunile: instanța competentă, datele reclamantului, datele pârâtului/pârâților, obiectul cererii și valoarea, descrierea creanței, baza legală, probele propuse, data și semnătura. Toate datele precompletate din LegalCase. CSS inline pentru layout-ul PDF (margini, fonturi, borduri — compatibil DomPDF). Implementează GeneratePdfMessageHandler: primește GeneratePdfMessage, apelează PdfGeneratorService, salvează PDF-ul în var/uploads/{case_uuid}/cerere_{caseNumber}.pdf, creează Document entity cu tipul 'cerere_pdf', loghează în AuditLog. Trigger: după tranziția 'confirm_payment' (paid), dispatch GeneratePdfMessage. PDF-ul trebuie să fie descărcabil din pagina detalii dosar.

**VERIFICARE MANUALĂ:**
- [ ] Simulează tranziția confirm_payment → PDF generat async
- [ ] PDF-ul conține datele dosarului (deschide-l și verifică)
- [ ] Document entity creat în DB cu tip 'cerere_pdf'
- [ ] PDF descărcabil din pagina detalii dosar
- [ ] AuditLog înregistrează generarea

**TESTE MINIME:**
- Unit test: PdfGeneratorService generează PDF valid (non-empty file)
- Unit test: PDF conține datele dosarului (grep text în output)
- Test: handler procesează mesajul și creează Document entity

---

### PASUL 13 | Upload documente (VichUploader + Flysystem) | ~2-3 ore

Sistem de upload, stocare și management documente per dosar.

**PROMPT:**
> Instalează și configurează vich/uploader-bundle și league/flysystem-bundle. Flysystem: adapter local (var/uploads/) în dev, pregătit pentru S3 prin schimbare config (config/packages/flysystem.yaml). VichUploader: mapping pentru Document entity, stocare organizată pe dosar (uploads/{case_uuid}/). Validare la upload: max 10MB, doar PDF/JPG/PNG/DOC/DOCX, maxim 20 fișiere per dosar. Pagina documente integrată în detalii dosar: secțiune 'Documente' cu lista fișierelor (icon per tip: PDF roșu, imagine verde, Word albastru), dimensiune formatată, dată upload. Buton upload (+ drag&drop zone cu Stimulus controller), upload fără page reload (Turbo Frame). Buton download per fișier. Buton ștergere per fișier (cu confirmare). Verificare voter: doar proprietarul dosarului poate upload/download/delete. AuditLog: loghează upload-ul, download-ul și ștergerea fiecărui document.

**VERIFICARE MANUALĂ:**
- [ ] Upload fișier: click și drag&drop funcționează
- [ ] Validări: fișier prea mare → eroare, tip invalid → eroare
- [ ] Lista documente afișează fișierele cu icon per tip
- [ ] Download funcționează
- [ ] Ștergere cu confirmare funcționează
- [ ] Alt user nu poate accesa documentele (403)
- [ ] AuditLog înregistrează acțiunile

**TESTE MINIME:**
- Test funcțional: upload fișier valid
- Test funcțional: upload fișier invalid (dimensiune, tip)
- Test funcțional: download fișier propriu vs. fișier străin (403)

---

## Faza 6: Plăți

### PASUL 14 | Integrare Netopia Payments + simulator local | ~3-4 ore

Procesarea plăților cu Netopia (hosted payment page) și un simulator complet pentru development.

**PROMPT:**
> Creează src/Service/Payment/NetopiaService.php cu: metoda initiatePayment(Payment $payment): string (returnează URL redirect Netopia), metoda processIpn(Request $request): PaymentResult (procesare webhook IPN), metoda verifySignature(Request $request): bool. Flux utilizator: din pagina detalii dosar (status pending_payment), pagina de plată (/payment/{case_id}) afișează: rezumat (taxa judiciară + comision platformă + total), buton 'Plătește cu cardul'. Click → redirect Netopia hosted page. Webhook IPN: PaymentController procesează callback-ul async via Messenger (ProcessPaymentWebhookMessage + handler). La plată confirmată: Payment.status = completed, aplică tranziția Workflow 'confirm_payment' (pending_payment → paid), dispatch GeneratePdfMessage. La plată eșuată: Payment.status = failed, dosarul rămâne pending_payment. Pagini: succes (/payment/success/{case_id}) cu link către dosar, eroare (/payment/error/{case_id}) cu buton retry. ÎN DEVELOPMENT: creează PaymentSimulatorController (/dev/payment-simulator/{case_id}) care simulează: buton 'Simulează plată reușită' → trimite IPN success, buton 'Simulează plată eșuată' → trimite IPN fail. Acest controller e disponibil doar în env=dev. Credențiale Netopia în .env: NETOPIA_SIGNATURE=test, NETOPIA_API_KEY=test, NETOPIA_SANDBOX=true. AuditLog pe fiecare acțiune de plată.

**VERIFICARE MANUALĂ:**
- [ ] Depune cerere → pagina plată afișează sumele corecte
- [ ] Simulator: plată reușită → dosar trece în 'paid', PDF se generează
- [ ] Simulator: plată eșuată → dosar rămâne 'pending_payment'
- [ ] Payment entity actualizat corect
- [ ] Pagini succes/eroare afișează informația corectă
- [ ] AuditLog înregistrează plata

**TESTE MINIME:**
- Unit test: NetopiaService.initiatePayment generează URL valid
- Unit test: procesare IPN success + fail
- Test funcțional: flux complet simulator (happy path)

---

## Faza 7: Notificări

### PASUL 15 | Email tranzacțional + notificări in-app | ~2-3 ore

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

### PASUL 16 | EasyAdmin panel complet | ~2-3 ore

Panoul de administrare cu CRUD-uri pe toate entitățile.

**PROMPT:**
> Extinde EasyAdmin 4 existent (dashboard + UserCrudController). Dashboard (/admin): statistici rapide ca widgets (total utilizatori activi, total dosare, dosare active, venituri luna curentă din plăți completed), mini-grafic sau tabel dosare pe lună (ultimele 6 luni). CRUD-uri noi: LegalCaseCrudController (listă cu coloane: caseNumber, reclamant, pârât, sumă, status badge, instanță, dată; filtre: status, dată, sumă min/max, instanță; pagina detail cu toate datele; acțiune custom 'Schimbă status' care deschide un form modal cu select status + motiv text — folosește Workflow valid transitions), PaymentCrudController (listă tranzacții cu filtre status/dată, detail, export CSV), CourtCrudController (listă, editare, adăugare instanță nouă), NotificationCrudController (listă, filtru citit/necitit, buton retrimitere email), AuditLogCrudController (readonly — doar list + detail, filtre: utilizator, acțiune, entitate, dată). Extinde UserCrudController: adaugă câmpurile noi (tip, CNP, CUI, telefon), acțiune blocare/deblocare (setează un câmp 'blocked' pe User), vizualizare dosarele userului. Acces restricționat: tot /admin necesită ROLE_ADMIN. Customizare: culorile aplicației pe dashboard.

**VERIFICARE MANUALĂ:**
- [ ] Dashboard afișează statisticile corecte
- [ ] Toate CRUD-urile funcționează (list, detail, edit, delete)
- [ ] Filtrele pe dosare funcționează
- [ ] Schimbare status dosar din admin respectă Workflow (doar tranziții valide)
- [ ] AuditLog readonly, cu filtre funcționale
- [ ] Export CSV plăți funcționează
- [ ] User fără ROLE_ADMIN → redirect login sau 403

**TESTE MINIME:**
- Test funcțional: acces /admin cu ROLE_ADMIN → 200
- Test funcțional: acces /admin cu ROLE_USER → 403 sau redirect

---

## Faza 9: Securitate și Deploy

### PASUL 17 | Security hardening + GDPR | ~2-3 ore

Headers de securitate, rate limiting și conformitate GDPR.

**PROMPT:**
> Instalează și configurează nelmio/security-bundle cu headers: Content-Security-Policy (self + inline styles pentru Tailwind + Google Fonts), Strict-Transport-Security (max-age=31536000), X-Frame-Options (DENY), X-Content-Type-Options (nosniff), Referrer-Policy (strict-origin-when-cross-origin). Instalează și configurează symfony/rate-limiter: login max 5 încercări/minut per IP (cu mesaj "Prea multe încercări. Reîncearcă în X secunde"), înregistrare max 3/oră per IP, depunere cerere max 10/oră per user. Afișează mesaje de eroare prietenoase la rate limit exceeded. GDPR — pagina 'Datele mele' (/profile/my-data): secțiune informativă (ce date colectăm, de ce, baza legală), buton 'Exportă datele mele' care generează JSON cu: datele personale, dosarele cu statusuri, plățile, notificările; download automat. Buton 'Șterge contul meu' cu confirmare (tastează "STERGE"): aplică soft delete pe User, anonimizează datele personale (email → deleted_{uuid}@anonimizat.ro, CNP/CUI → null, nume → "Utilizator șters"), păstrează dosarele 7 ani (cerință legală), logout automat. Logare în AuditLog: login reușit/eșuat, schimbare parolă, export date, ștergere cont.

**VERIFICARE MANUALĂ:**
- [ ] Security headers prezente (browser DevTools → Network → Response Headers)
- [ ] Rate limiter: 6 login-uri rapide → blocat cu mesaj prietenos
- [ ] Pagina 'Datele mele' afișează informația corect
- [ ] Export JSON conține toate datele
- [ ] Ștergere cont: anonimizare + soft delete + logout
- [ ] Dosarele rămân în DB după ștergere cont

**TESTE MINIME:**
- Test funcțional: rate limiter blochează după limită
- Test funcțional: export date returnează JSON valid
- Test funcțional: ștergere cont anonimizează datele
- Test: security headers prezente în response

---

### PASUL 18 | CI/CD + Deploy producție | ~2-3 ore

Pipeline GitHub Actions și deploy pe Hetzner via Coolify.

**PROMPT:**
> Configurează CI/CD complet. GitHub Actions (.github/workflows/ci.yml): job 'test' (on push/PR): services MySQL 8.0, PHP 8.4 setup, composer install, php bin/console tailwind:build --minify, php bin/console asset-map:compile, php bin/console doctrine:migrations:migrate --no-interaction, ./bin/phpunit --testdox. Job 'quality' (on push/PR, paralel cu test): PHPStan nivel 6 (phpstan.neon cu paths src/ tests/), PHP-CS-Fixer --dry-run --diff. IMPORTANT: fără npm/Node.js în CI — totul e PHP. Job 'deploy' (on merge to main, după test+quality): build Docker image multi-stage, push GitHub Container Registry, trigger Coolify deploy webhook (URL din secret). Dockerfile producție: FROM php:8.4-fpm-alpine, instalare extensii (pdo_mysql, intl, gd, zip, opcache), COPY composer files + composer install --no-dev --optimize-autoloader, COPY source, RUN tailwind:build --minify + asset-map:compile, OPcache settings optimale. docker-compose.prod.yml referință: app + nginx + mysql cu variabile din .env.prod, volume persistente var/uploads/ + var/log/, restart always, healthchecks. Script deploy.sh: wait for db, doctrine:migrations:migrate --no-interaction, cache:clear, cache:warmup, app:import-courts.

**VERIFICARE MANUALĂ:**
- [ ] Push pe branch → GitHub Actions rulează test + quality
- [ ] Teste trec verde în CI
- [ ] PHPStan fără erori
- [ ] Docker build local: `docker build -f Dockerfile.prod .` funcționează
- [ ] Dockerfile NU conține Node.js
- [ ] `deploy.sh` rulează migrări + cache clear

**TESTE MINIME:** CI-ul însuși este testul — verifică că toate testele existente trec.

---

## După MVP — Ce urmează

Feature-uri planificate post-lansare, în ordinea priorității:

1. **Integrare Netopia reală:** Credențiale reale, cont business, PCI compliance, testare end-to-end
2. **2FA (TOTP):** `composer require scheb/2fa-totp-bundle` — QR code pentru Google Authenticator
3. **Redis:** Migrare Messenger + sesiuni + cache (schimbare .env, adăugare container)
4. **SMS notificări:** symfony/notifier + Vonage bridge pentru acțiuni urgente
5. **Portal instanțe:** Integrare registratura.rejust.ro pentru depunere electronică
6. **Comunicare pârât:** Pârâtul primește cererea și răspunde online
7. **Push notifications:** FCM + Service Worker PWA
8. **Executare silită:** Flux post-hotărâre cu cerere executare
9. **Meilisearch:** Căutare full-text avansată în dosare (înlocuiește MySQL FULLTEXT)
10. **Semnătură electronică:** Integrare furnizori semnătură calificată
11. **Encryption at rest:** AES-256 pe documente sensibile (custom Doctrine type)
12. **API mobilă:** API Platform bundle + aplicație React Native/Flutter

---

> **Fiecare pas construit pe cel anterior. Nu sări pași. Commit după fiecare reușită. Teste la fiecare pas.**
