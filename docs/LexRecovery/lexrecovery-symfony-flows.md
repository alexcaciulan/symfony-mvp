# LexRecovery – Diagrame Fluxuri Aplicație (Symfony Stack)

> **Document pentru Claude Code**
> Acest fișier descrie arhitectura completă, fluxurile utilizator, logica de business și structura tehnică a aplicației LexRecovery – platformă SaaS pentru automatizarea recuperării creanțelor prin procedura Ordonanței de Plată.
>
> **Stack:** PHP 8.4 + Symfony 7.2 + Twig + Stimulus + Turbo + MySQL 8.0 + Doctrine ORM 3 + DomPDF + Flysystem + Symfony Messenger + symfony/panther

---

## Cuprins

1. [Arhitectura Generală](#1-arhitectura-generală)
2. [Fluxul Principal al Utilizatorului](#2-fluxul-principal-al-utilizatorului)
3. [Modulul Auth & Onboarding](#3-modulul-auth--onboarding)
4. [Modulul Gestiune Dosare](#4-modulul-gestiune-dosare)
5. [Modulul Generare Documente PDF](#5-modulul-generare-documente-pdf)
6. [Modulul Pipeline & Statusuri](#6-modulul-pipeline--statusuri)
7. [Modulul Termene & Reminder-uri](#7-modulul-termene--reminder-uri)
8. [Modulul Monitorizare portal.just.ro](#8-modulul-monitorizare-portaljustro)
9. [Logica de Business – Calcule Automate](#9-logica-de-business--calcule-automate)
10. [Entități Doctrine ORM](#10-entități-doctrine-orm)
11. [Arhitectura Controllers & Routes](#11-arhitectura-controllers--routes)
12. [Structura Proiect Symfony](#12-structura-proiect-symfony)
13. [Symfony Messenger – Jobs & Queues](#13-symfony-messenger--jobs--queues)
14. [Frontend – Stimulus Controllers](#14-frontend--stimulus-controllers)
15. [Docker & Deployment](#15-docker--deployment)

---

## 1. Arhitectura Generală

```mermaid
graph TB
    subgraph CLIENT["🖥️ Client (Browser)"]
        TWIG["Twig Templates\n+ Stimulus Controllers\n+ Turbo Frames/Streams"]
    end

    subgraph SYMFONY["⚙️ Symfony 7.2 Application (VPS Hetzner)"]
        CTRL["Controllers\n(HTTP Layer)"]
        SVC["Services\n(Business Logic)"]
        MESSENGER["Symfony Messenger\n(Async Jobs)"]
        WORKER["Messenger Worker\n(php bin/console\nmessenger:consume)"]
        CONSOLE["Console Commands\n(php bin/console app:*)"]
    end

    subgraph SERVICES["🔧 Services Interne"]
        DOCGEN["DocumentService\n(DomPDF + Twig)"]
        CALCULE["CalculeService\n(dobânzi, timbru)"]
        SCRAPER["PortalScraperService\n(symfony/panther)"]
        EMAILSVC["EmailService\n(Symfony Mailer)"]
        TERMSVC["TermeneService\n(calcul automate)"]
    end

    subgraph STORAGE["🗄️ Persistență"]
        MYSQL["MySQL 8.0\n(Doctrine ORM 3)"]
        FILES["Flysystem\n(local → S3/B2 producție)"]
        CACHE["Symfony Cache\n(filesystem → Redis)"]
    end

    subgraph EXTERNAL["🌐 Servicii Externe"]
        PORTAL["portal.just.ro\n(scraping via panther)"]
        RESEND["Resend / Postmark\n(email)"]
        ONRC["ONRC API\n(verificare firme)"]
    end

    subgraph INFRA["🚀 Infrastructură"]
        COOLIFY["Coolify\n(self-hosted PaaS)"]
        DOCKER["Docker + Compose"]
        GITHUB["GitHub Actions\n(CI/CD)"]
        CRON["VPS Cron\n(declanșează comenzi)"]
    end

    TWIG -->|"HTTP Request"| CTRL
    CTRL --> SVC
    SVC --> DOCGEN
    SVC --> CALCULE
    SVC --> TERMSVC
    CTRL --> MESSENGER
    MESSENGER --> WORKER
    WORKER --> SCRAPER
    WORKER --> EMAILSVC
    WORKER --> CONSOLE
    CRON -->|"*/  * * * *"| CONSOLE
    DOCGEN --> FILES
    DOCGEN --> MYSQL
    SVC --> MYSQL
    SVC --> CACHE
    SCRAPER --> PORTAL
    EMAILSVC --> RESEND
    SVC -.->|"V2"| ONRC
    COOLIFY --> DOCKER
    GITHUB --> COOLIFY
```

---

## 2. Fluxul Principal al Utilizatorului

```mermaid
journey
    title Fluxul complet al unui dosar de recuperare creanță
    section Onboarding
      Register cabinet: 5: Avocat
      Configurare profil: 3: Avocat
      Primul dosar ghidat: 5: Avocat, App
    section Faza Amiabilă
      Creare dosar (wizard 4 pași): 5: Avocat, App
      Generare somație PDF: 5: App
      Download + trimitere somație: 3: Avocat
      Monitorizare termen 30 zile: 5: App
    section Faza Judiciară
      Generare cerere OP PDF: 5: App
      Calcul taxă timbru automat: 5: App
      Depunere la instanță: 3: Avocat
      Introducere nr. dosar instanță: 4: Avocat
      Monitorizare portal.just.ro: 5: App
      Primire alertă ordonanță emisă: 5: App
    section Executare
      Generare dosar executare PDF: 4: App
      Trimitere la executor: 3: Avocat
      Urmărire recuperare sume: 4: App
```

---

## 3. Modulul Auth & Onboarding

```mermaid
flowchart TD
    START([Utilizator accesează app]) --> CHECK{Sesiune activă?\nsymfony/security}

    CHECK -->|Da| DASHBOARD[Dashboard Principal]
    CHECK -->|Nu| AUTH_PAGE["/login sau /register"]

    AUTH_PAGE --> LOGIN_FORM["Formular Login\n(LoginFormAuthenticator)\n- Email\n- Parolă\n- Remember me"]

    AUTH_PAGE --> REGISTER_FORM["Formular Register\n(RegistrationFormType)\n- Email\n- Parolă (RepeatedType)\n- Nume complet\n- Denumire cabinet\n- Barou"]

    REGISTER_FORM --> VALIDATE{Validare\nSymfony Constraints}
    VALIDATE -->|Invalid| REGISTER_FORM
    VALIDATE -->|Valid| CREATE_USER["UserService::register()\n1. Creare Cabinet entity\n2. Creare User entity\n   (password hash via\n   UserPasswordHasherInterface)\n3. Asociere User→Cabinet\n4. Persist + Flush (Doctrine)"]

    CREATE_USER --> SEND_VERIFY["EmailService::sendVerification()\n(Symfony Mailer + Resend)\nToken stocat în DB\nExpiră în 24h"]

    SEND_VERIFY --> VERIFY_PAGE["/verify-email/{token}"]
    VERIFY_PAGE --> CONFIRM{Token valid\nși neexpirat?}
    CONFIRM -->|Da| ACTIVATE["User::isVerified = true\nAuto-login via Security"]
    CONFIRM -->|Nu| ERROR_TOKEN["Flash message eroare\nButon retrimite email"]

    ACTIVATE --> ONBOARDING["Onboarding Wizard\n(sesiune: onboarding_step)\n/onboarding"]

    ONBOARDING --> OB1["Step 1: Date cabinet\n(CUI, adresă, telefon, logo)\nCabinetType form"]
    OB1 --> OB2["Step 2: Date avocat\n(nr. barou, specialitate)\nAvocatProfileType form"]
    OB2 --> OB3["Step 3: Rata BNR curentă\n(configurabilă manual)\nSetariType form"]
    OB3 --> OB4["Step 4: Tutorial interactiv\nTurbo Frame cu ghid pas-cu-pas"]
    OB4 --> MARK_DONE["Cabinet::onboardingDone = true"]
    MARK_DONE --> DASHBOARD

    LOGIN_FORM --> AUTHENTICATOR["LoginFormAuthenticator\nverific email + parolă\nGenerez session token"]
    AUTHENTICATOR -->|Succes| DASHBOARD
    AUTHENTICATOR -->|Eșec| LOGIN_ERR["Flash: Credențiale invalide"]

    style DASHBOARD fill:#22c55e,color:#fff
    style START fill:#3b82f6,color:#fff
```

---

## 4. Modulul Gestiune Dosare

### 4.1 Creare Dosar – Wizard Multi-Step

```mermaid
flowchart TD
    BTN_NOU["GET /dosare/nou"] --> WIZARD["DosarWizardController\nSession: dosar_wizard_data\nTurbo Frame navigation"]

    WIZARD --> STEP1["STEP 1 – Creditor\nGET /dosare/nou/creditor"]
    STEP1 --> S1_EXIST{Există client\nîn sistem?}
    S1_EXIST -->|Da| S1_SELECT["Autocomplete search\n(Stimulus + /api/clienti/search)\nSelectează și continuă"]
    S1_EXIST -->|Nu| S1_CREATE["ClientType form:\n- tip: PF / PJ (Choice)\n- nume (Text)\n- cnp_cui (Text + validare)\n- adresa (Textarea)\n- email (Email)\n- telefon (Text)\n- reprezentant_legal (Text, PJ only)\n- cont_bancar_iban (Text)"]
    S1_SELECT --> S1_SAVE["Session: wizard['creditor_id']"]
    S1_CREATE --> S1_SAVE

    S1_SAVE --> STEP2["STEP 2 – Debitor\nGET /dosare/nou/debitor"]
    STEP2 --> S2_EXIST{Există debitor\nîn sistem?}
    S2_EXIST -->|Da| S2_SELECT["Selectează din listă"]
    S2_EXIST -->|Nu| S2_CREATE["DebitorType form:\n- tip: PF / PJ\n- nume, cnp_cui, adresa\n- email, telefon\n- administrator (PJ)"]
    S2_SELECT --> S2_SAVE
    S2_CREATE --> S2_SAVE
    S2_SAVE --> ONRC_CHECK

    ONRC_CHECK["OnrcService::verify(cui)\n(dacă tip = PJ)"] --> ONRC_RESULT{Status firmă}
    ONRC_RESULT -->|"Activă"| S2_OK["✅ Badge: Firmă activă"]
    ONRC_RESULT -->|"Radiată / Insolvență"| S2_WARN["⚠️ Flash warning afișat\nAvocat poate continua"]
    ONRC_RESULT -->|"API down"| S2_SKIP["Skip silențios"]
    S2_OK --> STEP3
    S2_WARN --> STEP3
    S2_SKIP --> STEP3

    STEP3["STEP 3 – Date Creanță\nGET /dosare/nou/creanta"] --> S3_FORM["CreantaType form:\n- suma_principala (Money)\n- data_scadenta (DatePicker)\n- temei_juridic (Text)\n- tip_raport: comercial/civil (Choice)\n- dobanda_contract_procent (Number, optional)\n- penalitati_procent_zi (Number, optional)\n- descriere (Textarea)\n\nCALCULAT LIVE via Stimulus:\n→ Dobândă acumulată\n→ Total creanță la zi\n→ Taxă timbru\n→ Instanță competentă"]

    S3_FORM --> STEP4["STEP 4 – Documente\nGET /dosare/nou/documente"]
    STEP4 --> S4_UPLOAD["VichyUploader / Flysystem:\n- contract (obligatoriu)\n- facturi (multiple)\n- corespondență\n- alte înscrisuri\nFormat: PDF, JPG, PNG\nMax: 10MB/fișier"]

    S4_UPLOAD --> REVIEW["REVIEW – Sumar\nGET /dosare/nou/review\n\nTurbo Frame cu toate datele\nCalcule finale afișate"]

    REVIEW --> CONFIRM{"POST /dosare/nou/confirm\nCSRF token validat"}
    CONFIRM -->|Da| CREATE_DOSAR["DosarService::create()\n1. Persist Client (dacă nou)\n2. Persist Debitor (dacă nou)\n3. Persist Dosar entity\n4. Move uploaded files\n   via Flysystem\n5. Persist Documente\n6. Calcul și persist Termene auto\n7. Dispatch DosarCreatedEvent\n8. Flush()"]
    CONFIRM -->|Înapoi| WIZARD

    CREATE_DOSAR --> TERMENE_AUTO["TermeneService::generateForDosar()\nTermene create automat:\n1. PRESCRIPTIE: scadenta + 3 ani\n2. REMINDER_SOMATIE: azi + 7 zile\n   (prompt avocat să trimită somația)"]

    TERMENE_AUTO --> REDIRECT["RedirectResponse\n→ /dosare/{id}\nFlash: 'Dosar creat cu succes'"]

    style REDIRECT fill:#22c55e,color:#fff
    style BTN_NOU fill:#3b82f6,color:#fff
```

### 4.2 Pagina Dosar – Tab Layout

```mermaid
flowchart TD
    DOSAR_PAGE["GET /dosare/{id}\nDosarController::show()"] --> SECURITY["Security check:\nVoter: DosarVoter::VIEW\n(dosar aparține cabinetului?)"]

    SECURITY -->|Acces permis| LAYOUT["Layout dosar\nTurbo Drive navigation"]
    SECURITY -->|Acces refuzat| 403[403 Access Denied]

    LAYOUT --> HEADER["Header fix:\n[Creditor] ←→ [Debitor]\nSumă live | Status badge colorat\nButoane acțiuni rapide contextuale"]

    LAYOUT --> TABS["Tabs (Turbo Frames)\n/dosare/{id}#tab-{name}"]

    TABS --> TAB_OVERVIEW["📊 Tab: OVERVIEW\n/dosare/{id}/overview\n\n- Pipeline status vizual (5 etape)\n- Calcule actualizate la zi\n- Timeline activitate recentă\n- Acțiuni disponibile contextual\n  (în funcție de status curent)"]

    TABS --> TAB_DOCS["📄 Tab: DOCUMENTE\n/dosare/{id}/documente\n\n- Lista documente uploadate\n- Lista documente generate de app\n- Butoane generare PDF contextuale\n- Download individual\n- Buton 'Descarcă tot (ZIP)'\n- Ștergere document"]

    TABS --> TAB_TERMENE["📅 Tab: TERMENE\n/dosare/{id}/termene\n\n- Lista termene active (sortate)\n- Badge priority: CRITICAL/HIGH/MED\n- Status: activ / expirat / rezolvat\n- Buton adaugă termen custom\n- Toggle rezolvat (AJAX via Turbo)"]

    TABS --> TAB_PORTAL["🌐 Tab: PORTAL JUST\n/dosare/{id}/portal\n\n- Status ultima verificare + timestamp\n- Câmp editabil nr. dosar instanță\n- Istoricul modificărilor detectate\n- Badge 'schimbare' pe intrările noi\n- Buton 'Verifică acum' (async job)"]

    TABS --> TAB_ACTIVITATE["📝 Tab: ACTIVITATE\n/dosare/{id}/activitate\n\n- Log audit complet (cine, ce, când)\n- Câmp comentariu intern\n- Atașare fișiere la comentariu"]
```

---

## 5. Modulul Generare Documente PDF

```mermaid
flowchart TD
    TRIGGER["POST /dosare/{id}/documente/genereaza\n{tip: 'somatie'|'cerere_op'|'opis'}"] --> AUTH_CHECK["Security: DosarVoter::EDIT"]

    AUTH_CHECK --> DISPATCH["DocumentService::generate(\n  dosar: Dosar,\n  tip: DocumentTip\n)"]

    DISPATCH --> COLLECT["Colectare date din Doctrine:\ndosar→creditor\ndosar→debitor\ndosar→creanta\nCalculeService::getTotalLaZi(dosar)\nCalculeService::getTaxaTimbru(dosar)\nCalculeService::getInstanta(dosar)"]

    COLLECT --> SELECT_TPL{Selectare\ntemplate Twig}

    SELECT_TPL -->|"SOMATIE"| TPL_SOM["templates/pdf/somatie.html.twig\n\nDate injectate:\n- creditor.nume, adresa, cnpCui\n- debitor.nume, adresa, cnpCui\n- creanta.sumaInitiala\n- creanta.dataScadenta\n- dobandaLegala (calculată)\n- totalCreanta\n- termenDePlata (15 sau 30 zile)\n- dataAzi"]

    SELECT_TPL -->|"CERERE_OP"| TPL_COP["templates/pdf/cerere_op.html.twig\n\nDate adiționale:\n- instanta.denumire, adresa\n- taxaTimbru\n- listaAnexe (documente din dosar)\n- nrSomatie (dacă există)\n- dataDepuneriSomatie"]

    SELECT_TPL -->|"OPIS"| TPL_OPIS["templates/pdf/opis.html.twig\n\nLista completă documente:\n- fiecare document cu nr. crt.\n- tip, denumire, nr. file\n- semnătură avocat"]

    TPL_SOM --> RENDER_HTML["Twig::render(template, data)\n→ HTML string"]
    TPL_COP --> RENDER_HTML
    TPL_OPIS --> RENDER_HTML

    RENDER_HTML --> DOMPDF["Dompdf::loadHtml(html)\nDompdf::setPaper('A4', 'portrait')\nDompdf::render()\n$pdfContent = dompdf->output()"]

    DOMPDF --> SAVE_FILE["Flysystem::write(\n  'dosare/{dosarId}/{tip}_{timestamp}.pdf',\n  $pdfContent\n)"]

    SAVE_FILE --> PERSIST_DB["Doctrine persist Document:\n- dosar: $dosar\n- tip: DocumentTip::SOMATIE\n- numeFisier: 'somatie_20240115.pdf'\n- cale: 'dosare/{id}/...'\n- generat: true\n- generatLa: now()\nflush()"]

    PERSIST_DB --> RESPONSE["JsonResponse sau\nTurboStream response:\n- Actualizează lista documente\n- Afișează buton Download\n- Flash success message"]

    RESPONSE --> DOWNLOAD["GET /documente/{id}/download\nFlysystem::readStream(cale)\nBinaryFileResponse cu\nContent-Disposition: attachment"]

    subgraph ZIP_BUNDLE["Descărcare ZIP complet"]
        ZIP_BTN["GET /dosare/{id}/documente/zip"] --> ZIP_CREATE["ZipArchive PHP\nAdaugă toate documentele\ndin Flysystem"]
        ZIP_CREATE --> ZIP_RESPONSE["StreamedResponse\nContent-Type: application/zip\nContent-Disposition: attachment;\nfilename='dosar_{id}_complet.zip'"]
    end

    subgraph CSS_PDF["CSS pentru DomPDF\ntemplates/pdf/_base.html.twig"]
        CSS_RULES["@page { size: A4; margin: 2cm; }\nbody { font-family: DejaVu Sans; }\n.header { border-bottom: 2px solid #000; }\n.section-title { font-weight: bold; }\n.signature-block { margin-top: 3cm; }\n\nATENȚIE: DomPDF suportă\nCSS limitat – fără Flexbox/Grid\nFolosește tabele pentru layout"]
    end

    style RESPONSE fill:#22c55e,color:#fff
    style ZIP_RESPONSE fill:#22c55e,color:#fff
```

---

## 6. Modulul Pipeline & Statusuri

```mermaid
stateDiagram-v2
    [*] --> AMIABIL : DosarService::create()

    AMIABIL --> SOMATIE_TRIMISA : POST /dosare/{id}/status\naction=marcare_somatie_trimisa\n(avocat confirmă trimiterea)
    note right of AMIABIL
        Acțiuni disponibile:
        [Generează Somație PDF]
        [Editează date dosar]

        Termene auto create:
        - PRESCRIPTIE (scadenta + 3 ani)
        - REMINDER_SOMATIE (azi + 7 zile)
    end note

    SOMATIE_TRIMISA --> AMIABIL : Plată primită\n→ INCHIS_SUCCES
    SOMATIE_TRIMISA --> CERERE_DEPUSA : Termen expirat\navocat depune cerere
    note right of SOMATIE_TRIMISA
        Acțiuni disponibile:
        [Generează Cerere OP PDF]
        [Descarcă ZIP complet]
        [Marchează plată primită]

        Termen auto creat:
        - SCADENTA_SOMATIE
          (data_trimitere + 30 zile)

        App monitorizează și
        alertează la expirare
    end note

    CERERE_DEPUSA --> DOSAR_INREGISTRAT : Avocat introduce\nnr. dosar instanță\nvia form inline
    note right of CERERE_DEPUSA
        Acțiuni disponibile:
        [Introdu nr. dosar instanță]
        [Upload chitanță taxă timbru]

        La introducere nr. dosar:
        → App pornește monitorizarea
          portal.just.ro automat
        → Dispatch PortalCheckMessage
    end note

    DOSAR_INREGISTRAT --> TERMEN_FIXAT : PortalScraperService\ndetectează termen\nde judecată
    note right of DOSAR_INREGISTRAT
        Job async zilnic:
        PortalCheckMessage
        dispatched via Messenger

        La detectare schimbare:
        → Email notificare avocat
        → Termen creat automat
        → Status actualizat
    end note

    TERMEN_FIXAT --> ORDONANTA_EMISA : Portal detectează\nsoluție favorabilă
    TERMEN_FIXAT --> RESPINSA : Portal detectează\nrespingere
    note right of TERMEN_FIXAT
        Acțiuni disponibile:
        [Vezi termen în calendar]
        [Adaugă notițe]

        App monitorizează
        zilnic portal.just.ro
    end note

    ORDONANTA_EMISA --> DEFINITIVA : 10 zile fără\ncontestație\n(TermeneService calculează)
    ORDONANTA_EMISA --> CONTESTATA : Avocat marchează\ncontestație depusă
    note right of ORDONANTA_EMISA
        Termen auto creat:
        - TERMEN_CONTESTATIE
          (data_comunicare + 10 zile)

        Acțiuni disponibile:
        [Marchează data comunicare]
        [Marchează contestație]
    end note

    CONTESTATA --> DEFINITIVA : Contestație respinsă\n(avocat marchează)
    CONTESTATA --> RESPINSA : Contestație admisă

    DEFINITIVA --> EXECUTARE : POST status\naction=pornire_executare
    note right of DEFINITIVA
        Termen auto creat:
        - PRESCRIPTIE_EXEC
          (data_definitiva + 3 ani)

        Acțiuni disponibile:
        [Generează dosar executare]
        [Selectează executor]
    end note

    EXECUTARE --> INCHIS_SUCCES : Sumă recuperată\nintegral
    EXECUTARE --> INCHIS_PARTIAL : Recuperare parțială
    EXECUTARE --> INCHIS_INSOLVABIL : Debitor fără bunuri

    RESPINSA --> [*]
    INCHIS_SUCCES --> [*]
    INCHIS_PARTIAL --> [*]
    INCHIS_INSOLVABIL --> [*]
    AMIABIL --> INCHIS_SUCCES : Plată amiabilă
```

### Implementare tranziție status

```php
// src/Service/DosarService.php
public function tranzitioneazaStatus(Dosar $dosar, DosarStatus $nouStatus): void
{
    $statusPrecedent = $dosar->getStatus();

    // Validare tranziție permisă
    if (!$this->isTransitionAllowed($statusPrecedent, $nouStatus)) {
        throw new InvalidStatusTransitionException(...);
    }

    $dosar->setStatus($nouStatus);
    $dosar->setDataSchimbareStatus(new \DateTimeImmutable());

    // Acțiuni post-tranziție
    match ($nouStatus) {
        DosarStatus::SOMATIE_TRIMISA => $this->onSomatieTrimisa($dosar),
        DosarStatus::CERERE_DEPUSA   => $this->onCerereDepusa($dosar),
        DosarStatus::DOSAR_INREGISTRAT => $this->onDosarInregistrat($dosar),
        DosarStatus::ORDONANTA_EMISA => $this->onOrdonantaEmisa($dosar),
        DosarStatus::DEFINITIVA      => $this->onDefinitiva($dosar),
        default => null,
    };

    $this->entityManager->flush();
    $this->eventDispatcher->dispatch(new DosarStatusChangedEvent($dosar, $statusPrecedent));
}

private function onSomatieTrimisa(Dosar $dosar): void
{
    $this->termeneService->createTermen(
        dosar: $dosar,
        tip: TermenTip::SCADENTA_SOMATIE,
        data: new \DateTimeImmutable('+30 days'),
        priority: TermenPriority::HIGH
    );
}

private function onDosarInregistrat(Dosar $dosar): void
{
    // Pornește monitorizarea portal.just.ro
    $this->messageBus->dispatch(new PortalCheckMessage($dosar->getId()));
}

private function onOrdonantaEmisa(Dosar $dosar): void
{
    $this->termeneService->createTermen(
        dosar: $dosar,
        tip: TermenTip::TERMEN_CONTESTATIE,
        data: new \DateTimeImmutable('+10 days'),
        priority: TermenPriority::CRITICAL
    );
}
```

---

## 7. Modulul Termene & Reminder-uri

```mermaid
flowchart TD
    subgraph SURSE["Surse creare termene"]
        T_AUTO["Termene automate\nTermeneService\n(la tranziții status)"]
        T_PORTAL["Termene din portal\nPortalScraperService\n(la detectare termen instanță)"]
        T_MANUAL["Termene manuale\nAvocat via form\nPOST /termene/create"]
    end

    T_AUTO --> TDB[("MySQL\ntabela: termen")]
    T_PORTAL --> TDB
    T_MANUAL --> TDB

    subgraph TERMENE_GENERATE["Termene auto generate de app"]
        TA1["PRESCRIPTIE\n= data_scadenta + 3 ani\npriority: CRITICAL\nla: creare dosar"]
        TA2["REMINDER_SOMATIE\n= azi + 7 zile\npriority: MEDIUM\nla: creare dosar\n(prompt să trimită somația)"]
        TA3["SCADENTA_SOMATIE\n= data_trimitere + 30 zile\npriority: HIGH\nla: SOMATIE_TRIMISA"]
        TA4["TERMEN_CONTESTATIE\n= data_comunicare + 10 zile\npriority: CRITICAL\nla: ORDONANTA_EMISA"]
        TA5["PRESCRIPTIE_EXEC\n= data_definitiva + 3 ani\npriority: MEDIUM\nla: DEFINITIVA"]
    end

    subgraph CRON_CMD["⏰ Cron VPS – zilnic 07:00"]
        CRON["php bin/console\napp:check-termene"] --> QUERY["SELECT t FROM Termen t\nWHERE t.rezolvat = false\nAND t.data <= :limitaSapteZile\nORDER BY t.data ASC"]

        QUERY --> LOOP{Iterare\nTermene}

        LOOP --> CHECK7{"data <= azi + 7 zile\nAND alertat7 = false?"}
        CHECK7 -->|Da| SEND7["EmailService::sendReminderTermen(\n  avocat, termen, '7 zile'\n)\ntermen.alertat7 = true"]

        CHECK7 -->|Nu| CHECK3{"data <= azi + 3 zile\nAND alertat3 = false?"}
        CHECK3 -->|Da| SEND3["Email reminder 3 zile\ntermen.alertat3 = true"]

        CHECK3 -->|Nu| CHECK1{"data <= azi + 1 zi\nAND alertat1 = false?"}
        CHECK1 -->|Da| SEND1["Email 'MÂINE termen!'\ntermen.alertat1 = true"]

        CHECK1 -->|Nu| CHECK_EXP{"data < azi\nAND alertat_expirat = false?"}
        CHECK_EXP -->|Da| SEND_EXP["Email 'TERMEN EXPIRAT'\ntermen.alertatExpirat = true"]

        SEND7 --> LOOP
        SEND3 --> LOOP
        SEND1 --> LOOP
        SEND_EXP --> LOOP
        LOOP --> FLUSH["entityManager→flush()\nLog în monolog"]
    end

    TDB --> CRON_CMD

    subgraph EMAIL_TERMEN["Template Email Termen\ntemplates/email/reminder_termen.html.twig"]
        EMAIL["Subiect: ⚠️ Termen în X zile – {{ dosar.creditor.nume }} vs {{ dosar.debitor.nume }}\n\n- Tip termen: {{ termen.tip.label }}\n- Data: {{ termen.data|date('d.m.Y') }}\n- Dosar: {{ dosar.creditor.nume }} vs {{ dosar.debitor.nume }}\n- Sumă creanță: {{ calcule.total|number_format(2) }} RON\n- {{ actiueSugerata }}\n- [Deschide dosarul →] url('/dosare/' ~ dosar.id)"]
    end

    SEND7 --> EMAIL_TERMEN
    SEND3 --> EMAIL_TERMEN
    SEND1 --> EMAIL_TERMEN

    subgraph CALENDAR_VIEW["/calendar – CalendarController"]
        CAL_LUNA["Vedere lunară\n(Stimulus controller: calendar)\nDate via AJAX:\nGET /api/termene?luna=X&an=Y"]
        CAL_LISTA["Vedere listă\n/calendar/lista\nFiltru: această săptămână /\nlunar / toate"]
        CAL_LUNA --> CLICK_EVENT["Click termen\n→ Turbo Frame\n→ Detalii dosar inline"]

        LEGEND_CRIT["🔴 CRITICAL"]
        LEGEND_HIGH["🟠 HIGH"]
        LEGEND_MED["🟡 MEDIUM"]
        LEGEND_LOW["🟢 LOW / CUSTOM"]
    end

    TDB --> CALENDAR_VIEW

    style FLUSH fill:#22c55e,color:#fff
```

---

## 8. Modulul Monitorizare portal.just.ro

```mermaid
flowchart TD
    subgraph TRIGGER["Declanșare verificare"]
        CRON_CMD2["⏰ Cron VPS – zilnic 08:00\nphp bin/console app:check-portal"] --> DISPATCH_ALL["Dispatch PortalCheckMessage\npentru fiecare dosar activ\ncu nr_dosar_instanta NOT NULL\n(status: CERERE_DEPUSA → EXECUTARE)"]

        MANUAL_BTN["POST /dosare/{id}/portal/check\n(buton manual în UI)"] --> DISPATCH_ONE["Dispatch PortalCheckMessage\npentru dosarul curent\nsync: true (rezultat imediat)"]
    end

    DISPATCH_ALL --> MESSAGE_BUS["Symfony Messenger Bus"]
    DISPATCH_ONE --> MESSAGE_BUS

    MESSAGE_BUS --> HANDLER["PortalCheckMessageHandler\n::__invoke(PortalCheckMessage)"]

    HANDLER --> LOAD_DOSAR["Doctrine: find Dosar by id\nVerifică nr_dosar_instanta exists"]

    LOAD_DOSAR --> PANTHER["PortalScraperService\n::scrape(nrDosar, codInstanta)\n\nPantherClient::createChromeClient()\n$client→request('GET', $url)\n$crawler→filter('.date-dosar')"]

    subgraph PANTHER_DETAIL["symfony/panther – Detalii implementare"]
        P1["PantherClient::createChromeClient(\n  null, null,\n  ['--headless', '--no-sandbox',\n   '--disable-dev-shm-usage']\n)"]
        P2["URL construit:\nhttps://portal.just.ro/SitePages/dosar.aspx\n?id_dosar={nrDosar}&instanta={cod}"]
        P3["Date extrase cu CSS selectors:\n- .nr-dosar → număr dosar\n- .stadiu → status curent\n- .termene-viitoare li → termene\n- .solutie → soluție pronunțată\n- .data-pronuntare → data soluției"]
        P4["Rate limiting:\nsleep(rand(2, 5))\nîntre request-uri consecutive"]
    end

    PANTHER --> EXTRACT["PortalData DTO:\n{\n  nrDosar: string\n  status: string\n  termeneViitoare: TermenData[]\n  solutie: string|null\n  dataSolutie: DateTime|null\n  rawContent: string\n}"]

    EXTRACT --> LOAD_PREV["Doctrine: ultimul ActivitatePortal\npentru dosarul curent\n(pentru comparație)"]

    LOAD_PREV --> COMPARE{"rawContent\ndiferit față de\nultima verificare?"}

    COMPARE -->|"Nu – same content"| LOG_NO_CHANGE["Persist ActivitatePortal:\n- schimbareDetectata: false\n- dataVerificare: now()\nflush()"]

    COMPARE -->|"Da – schimbare!"| ANALYZE["PortalAnalyzerService\n::analyze(prev, current)"]

    ANALYZE --> DETECT_TERMEN{"Termen nou\nde judecată detectat?"}
    ANALYZE --> DETECT_SOLUTIE{"Soluție\npronunțată?"}
    ANALYZE --> DETECT_OTHER{"Altă\nmodificare?"}

    DETECT_TERMEN -->|Da| CREATE_TERMEN_P["TermeneService::createTermen(\n  tip: TERMEN_INSTANTA,\n  data: data extrasă\n)"]

    DETECT_SOLUTIE -->|"Ordonanță emisă"| UPD_OP["DosarService::tranzitioneaza(\n  ORDONANTA_EMISA\n)\n→ Creează termen contestație"]

    DETECT_SOLUTIE -->|"Respinsă"| UPD_R["DosarService::tranzitioneaza(\n  RESPINSA\n)"]

    CREATE_TERMEN_P --> SAVE_CHANGE
    UPD_OP --> SAVE_CHANGE
    UPD_R --> SAVE_CHANGE
    DETECT_OTHER -->|Da| SAVE_CHANGE

    SAVE_CHANGE["Persist ActivitatePortal:\n- schimbareDetectata: true\n- continutExtras: new\n- continutAnterior: prev\n- tipSchimbare: detectat\n- dataVerificare: now()"]

    SAVE_CHANGE --> NOTIFY_EMAIL["EmailService::\nsendPortalChangeNotification(\n  avocat, dosar, schimbare\n)"]

    NOTIFY_EMAIL --> FLUSH2["flush() + Log"]

    subgraph ERROR_HANDLING["Gestionare Erori în Handler"]
        ERR_TIMEOUT["PantherException\n(timeout)"] --> RETRY_LOGIC["Messenger retry:\nmax 3 retries\ndelay: 5min, 15min, 1h\n(configurare messenger.yaml)"]
        ERR_STRUCTURE["CSS selector\nnu găsește elementul"] --> LOG_WARN["monolog warning:\n'Portal HTML structure changed'\nAlertă pe email admin"]
        ERR_DOSAR_404["Dosar negăsit\npe portal"] --> MARK_NOT_FOUND["ActivitatePortal\nstatus: NOT_FOUND\nAvocat notificat"]
    end

    style FLUSH2 fill:#22c55e,color:#fff
    style LOG_NO_CHANGE fill:#86efac,color:#000
```

---

## 9. Logica de Business – Calcule Automate

```mermaid
flowchart TD
    subgraph CALC_DOBANDA["CalculeService::getDobandaLegala()"]
        D_INPUT["Input:\n- sumaInitiala: float\n- dataScadenta: DateTimeInterface\n- dataCalcul: DateTimeInterface (default: azi)\n- tipRaport: 'comercial'|'civil'\n- dobandaContract: float|null"]

        D_INPUT --> D_CHECK{dobandaContract\nnot null?}

        D_CHECK -->|Da| D_CONTRACT["Dobândă contractuală:\n\n$zile = dataCalcul - dataScadenta (zile)\n$dobanda = suma × (dobandaContract/100)\n           × $zile / 365"]

        D_CHECK -->|Nu| D_GET_BNR["ConfigRata::getLatest(cabinetId)\n→ rata_bnr_procent (din DB)\n  (configurată manual în setări)"]

        D_GET_BNR --> D_LEGALA["Dobândă legală (OG 13/2011):\n\nCOMERCIAL: rata_bnr + 8%\nCIVIL:      rata_bnr + 4%\n\n$rataTotala = rata_bnr + puncte\n$zile = dataCalcul - dataScadenta\n$dobanda = suma × ($rataTotala/100)\n           × $zile / 365"]

        D_CONTRACT --> D_RESULT["return DobandaResult:\n- suma: float\n- rata: float\n- zile: int\n- formula: string (pentru afișare)"]
        D_LEGALA --> D_RESULT
    end

    subgraph CALC_TOTAL["CalculeService::getTotalLaZi()"]
        TOT["$dobanda = getDobandaLegala(dosar)\n$penalitati = getPenalitati(dosar)\n\nreturn TotalResult:\n- sumaInitiala: float\n- dobanda: float\n- penalitati: float\n- total: float (suma tuturor)\n- dataCalcul: DateTimeImmutable"]
    end

    subgraph CALC_TIMBRU["CalculeService::getTaxaTimbru()"]
        T_INPUT["Input: $totalCreanta (float)"]
        T_CALC["Legea 80/2013, art. 6:\n\n$taxa = $totalCreanta * 0.02;\n$taxa = max($taxa, 200.0);\n\n// Exemple:\n// 5.000 RON  → max(100, 200) = 200 RON\n// 15.000 RON → max(300, 200) = 300 RON\n// 80.000 RON → max(1600, 200) = 1600 RON\n\nreturn round($taxa, 2)"]
        T_INPUT --> T_CALC
    end

    subgraph CALC_INSTANTA["CalculeService::getInstantaCompetenta()"]
        I_INPUT["Input:\n- $totalCreanta: float\n- $judetDebitor: string"]

        I_INPUT --> I_SUMA{"totalCreanta\n<= 200.000 RON?"}

        I_SUMA -->|Da| I_JUDEC["Judecătorie\n\nInstantaService::findJudecatorie(\n  $judetDebitor\n)\n→ denumire, adresă, cod portal"]

        I_SUMA -->|Nu| I_TRIB["Tribunal\n\nInstantaService::findTribunal(\n  $judetDebitor\n)\n→ denumire, adresă, cod portal"]

        I_JUDEC --> I_RESULT["return InstantaResult:\n- tip: 'JUDECATORIE'|'TRIBUNAL'\n- denumire: string\n- adresa: string\n- codPortal: string\n- contTimbru: string (IBAN virament)"]
        I_TRIB --> I_RESULT
    end

    subgraph INSTANTELE_DB["InstantaService – Date statice (hardcoded sau DB)"]
        INST_DATA["Array asociativ județ → instanță:\n[\n  'Cluj'    => ['judecatorie' => 'Judecătoria Cluj-Napoca', ...],\n  'Ilfov'   => ['judecatorie' => 'Judecătoria Cornetu', ...],\n  'Sector1' => ['judecatorie' => 'Judecătoria Sector 1', ...],\n  ...\n]"]
    end
```

---

## 10. Entități Doctrine ORM

```php
// src/Entity/Cabinet.php
#[ORM\Entity(repositoryClass: CabinetRepository::class)]
class Cabinet
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private int $id;

    #[ORM\Column(length: 255)]
    private string $nume;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $cui = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adresa = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telefon = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $emailContact = null;

    #[ORM\Column(default: false)]
    private bool $onboardingDone = false;

    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'cabinet')]
    private Collection $avocati;

    #[ORM\OneToMany(targetEntity: Client::class, mappedBy: 'cabinet')]
    private Collection $clienti;

    #[ORM\OneToMany(targetEntity: Debitor::class, mappedBy: 'cabinet')]
    private Collection $debitori;

    #[ORM\OneToOne(targetEntity: ConfigRata::class, mappedBy: 'cabinet', cascade: ['persist'])]
    private ?ConfigRata $configRata = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
}

// src/Entity/User.php (Avocat)
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[UniqueEntity(fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private int $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column]
    private array $roles = ['ROLE_USER'];

    #[ORM\Column]
    private string $password;

    #[ORM\Column(length: 255)]
    private string $numeComplet;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $nrBarou = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $baroul = null;

    #[ORM\Column(default: false)]
    private bool $isVerified = false;

    #[ORM\ManyToOne(inversedBy: 'avocati')]
    #[ORM\JoinColumn(nullable: false)]
    private Cabinet $cabinet;

    #[ORM\OneToMany(targetEntity: Dosar::class, mappedBy: 'avocat')]
    private Collection $dosare;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
}

// src/Entity/Client.php
#[ORM\Entity(repositoryClass: ClientRepository::class)]
class Client
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private int $id;

    #[ORM\Column(enumType: TipPersoana::class)]
    private TipPersoana $tip; // PF | PJ

    #[ORM\Column(length: 255)]
    private string $nume;

    #[ORM\Column(length: 20)]
    private string $cnpCui;

    #[ORM\Column(type: Types::TEXT)]
    private string $adresa;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telefon = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reprezentantLegal = null;

    #[ORM\Column(length: 34, nullable: true)] // IBAN max 34 chars
    private ?string $contBancarIban = null;

    #[ORM\ManyToOne(inversedBy: 'clienti')]
    private Cabinet $cabinet;

    #[ORM\OneToMany(targetEntity: Dosar::class, mappedBy: 'creditor')]
    private Collection $dosareCreditor;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
}

// src/Entity/Debitor.php
#[ORM\Entity(repositoryClass: DebitorRepository::class)]
class Debitor
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private int $id;

    #[ORM\Column(enumType: TipPersoana::class)]
    private TipPersoana $tip;

    #[ORM\Column(length: 255)]
    private string $nume;

    #[ORM\Column(length: 20)]
    private string $cnpCui;

    #[ORM\Column(type: Types::TEXT)]
    private string $adresa;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telefon = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $administrator = null;

    #[ORM\Column(enumType: StatusOnrc::class, nullable: true)]
    private ?StatusOnrc $statusOnrc = null; // ACTIV|RADIAT|INSOLVENT|NECUNOSCUT

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dataVerificareOnrc = null;

    #[ORM\ManyToOne(inversedBy: 'debitori')]
    private Cabinet $cabinet;

    #[ORM\OneToMany(targetEntity: Dosar::class, mappedBy: 'debitor')]
    private Collection $dosare;
}

// src/Entity/Dosar.php
#[ORM\Entity(repositoryClass: DosarRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Dosar
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private int $id;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $numarIntern = null;

    #[ORM\ManyToOne(inversedBy: 'dosareCreditor')]
    #[ORM\JoinColumn(nullable: false)]
    private Client $creditor;

    #[ORM\ManyToOne(inversedBy: 'dosare')]
    #[ORM\JoinColumn(nullable: false)]
    private Debitor $debitor;

    #[ORM\ManyToOne(inversedBy: 'dosare')]
    #[ORM\JoinColumn(nullable: false)]
    private User $avocat;

    // Date creanță
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $sumaInitiala;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dataScadenta;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $dobandaContractProcent = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, nullable: true)]
    private ?string $penalitatiProcentZi = null;

    #[ORM\Column(length: 500)]
    private string $temeiJuridic;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descriere = null;

    #[ORM\Column(enumType: TipRaport::class)]
    private TipRaport $tipRaport; // COMERCIAL | CIVIL

    // Status & portal
    #[ORM\Column(enumType: DosarStatus::class)]
    private DosarStatus $status = DosarStatus::AMIABIL;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dataSchimbareStatus = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $nrDosarInstanta = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $instantaDenumire = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $instantaCod = null;

    // Relații
    #[ORM\OneToMany(targetEntity: Termen::class, mappedBy: 'dosar', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['data' => 'ASC'])]
    private Collection $termene;

    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'dosar', cascade: ['persist', 'remove'])]
    private Collection $documente;

    #[ORM\OneToMany(targetEntity: ActivitatePortal::class, mappedBy: 'dosar', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['dataVerificare' => 'DESC'])]
    private Collection $activitatePortal;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

// src/Entity/Termen.php
#[ORM\Entity(repositoryClass: TermenRepository::class)]
class Termen
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private int $id;

    #[ORM\ManyToOne(inversedBy: 'termene')]
    #[ORM\JoinColumn(nullable: false)]
    private Dosar $dosar;

    #[ORM\Column(enumType: TermenTip::class)]
    private TermenTip $tip;
    // PRESCRIPTIE | SCADENTA_SOMATIE | TERMEN_INSTANTA |
    // TERMEN_CONTESTATIE | PRESCRIPTIE_EXEC | CUSTOM | REMINDER_SOMATIE

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $data;

    #[ORM\Column(length: 500)]
    private string $descriere;

    #[ORM\Column(enumType: TermenPriority::class)]
    private TermenPriority $priority; // CRITICAL | HIGH | MEDIUM | LOW

    #[ORM\Column(default: false)]
    private bool $alertat7 = false;

    #[ORM\Column(default: false)]
    private bool $alertat3 = false;

    #[ORM\Column(default: false)]
    private bool $alertat1 = false;

    #[ORM\Column(default: false)]
    private bool $alertatExpirat = false;

    #[ORM\Column(default: false)]
    private bool $rezolvat = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rezolvatLa = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
}

// src/Entity/Document.php
#[ORM\Entity(repositoryClass: DocumentRepository::class)]
class Document
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private int $id;

    #[ORM\ManyToOne(inversedBy: 'documente')]
    #[ORM\JoinColumn(nullable: false)]
    private Dosar $dosar;

    #[ORM\Column(enumType: DocumentTip::class)]
    private DocumentTip $tip;
    // SOMATIE | CERERE_OP | OPIS | CONTRACT | FACTURA |
    // HOTARARE | CHITANTA_TIMBRU | ALTELE

    #[ORM\Column(length: 255)]
    private string $numeFisier;

    #[ORM\Column(length: 500)]
    private string $caleStorage; // path în Flysystem

    #[ORM\Column(nullable: true)]
    private ?int $sizeBytes = null;

    #[ORM\Column(default: false)]
    private bool $generatDeApp = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
}

// src/Entity/ActivitatePortal.php
#[ORM\Entity(repositoryClass: ActivitatePortalRepository::class)]
class ActivitatePortal
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private int $id;

    #[ORM\ManyToOne(inversedBy: 'activitatePortal')]
    #[ORM\JoinColumn(nullable: false)]
    private Dosar $dosar;

    #[ORM\Column]
    private \DateTimeImmutable $dataVerificare;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $continutExtras = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $continutAnterior = null;

    #[ORM\Column(default: false)]
    private bool $schimbareDetectata = false;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $tipSchimbare = null;

    #[ORM\Column(default: false)]
    private bool $notificareTrimisa = false;
}

// src/Entity/ConfigRata.php
#[ORM\Entity]
class ConfigRata
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private int $id;

    #[ORM\OneToOne(inversedBy: 'configRata')]
    private Cabinet $cabinet;

    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 2)]
    private string $rataBnrProcent; // ex: 6.50

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $valabilaDeLa;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;
}
```

---

## 11. Arhitectura Controllers & Routes

```mermaid
graph LR
    subgraph SECURITY_CTRL["SecurityController"]
        SC1["GET /login"]
        SC2["POST /login → LoginFormAuthenticator"]
        SC3["GET /logout"]
        SC4["GET /register"]
        SC5["POST /register"]
        SC6["GET /verify-email/{token}"]
        SC7["GET /onboarding/{step}"]
        SC8["POST /onboarding/{step}"]
    end

    subgraph DASHBOARD_CTRL["DashboardController"]
        DC1["GET / → dashboard\n(termene săptămână,\ndosare active,\nstatistici)"]
    end

    subgraph DOSAR_CTRL["DosarController"]
        D1["GET /dosare\n(lista + filtre + search)"]
        D2["GET /dosare/nou/{step}\n(wizard steps: creditor,\ndebitor, creanta, doc, review)"]
        D3["POST /dosare/nou/{step}"]
        D4["POST /dosare/nou/confirm"]
        D5["GET /dosare/{id}\n(overview tab)"]
        D6["GET /dosare/{id}/documente"]
        D7["GET /dosare/{id}/termene"]
        D8["GET /dosare/{id}/portal"]
        D9["GET /dosare/{id}/activitate"]
        D10["POST /dosare/{id}/status\n(tranzitie status)"]
        D11["POST /dosare/{id}/nr-dosar\n(update nr instanta)"]
    end

    subgraph DOC_CTRL["DocumentController"]
        DC2["POST /documente/genereaza\n{dosarId, tip}"]
        DC3["GET /documente/{id}/download"]
        DC4["POST /documente/upload\n{dosarId, tip, file}"]
        DC5["DELETE /documente/{id}"]
        DC6["GET /dosare/{id}/documente/zip"]
    end

    subgraph TERMEN_CTRL["TermenController"]
        T1["POST /termene\n(creare manual)"]
        T2["PATCH /termene/{id}/rezolva"]
        T3["DELETE /termene/{id}"]
        T4["GET /calendar\n(toate termenele)"]
        T5["GET /api/termene\n(JSON pentru Stimulus calendar)"]
    end

    subgraph PORTAL_CTRL["PortalController"]
        P1["POST /dosare/{id}/portal/check\n(verificare manuală async)"]
        P2["GET /dosare/{id}/portal/activitate\n(Turbo Frame refresh)"]
    end

    subgraph CLIENT_CTRL["ClientController"]
        CL1["GET /clienti"]
        CL2["GET/POST /clienti/nou"]
        CL3["GET/POST /clienti/{id}/editeaza"]
        CL4["GET /api/clienti/search?q=\n(JSON autocomplete)"]
    end

    subgraph DEBITOR_CTRL["DebitorController"]
        DB1["GET /debitori"]
        DB2["GET/POST /debitori/nou"]
        DB3["GET/POST /debitori/{id}/editeaza"]
        DB4["GET /api/debitori/search?q="]
        DB5["GET /api/debitori/{id}/verify-onrc"]
    end

    subgraph SETARI_CTRL["SetariController"]
        S1["GET/POST /setari\n(cabinet, avocat, rata BNR)"]
    end
```

---

## 12. Structura Proiect Symfony

```
lexrecovery/
├── config/
│   ├── packages/
│   │   ├── doctrine.yaml
│   │   ├── messenger.yaml          # Transport + routing messages
│   │   ├── mailer.yaml             # Resend DSN
│   │   ├── security.yaml           # Firewall + authenticator
│   │   ├── twig.yaml
│   │   └── tailwind.yaml           # symfonycasts/tailwind-bundle
│   ├── routes.yaml
│   └── services.yaml
│
├── src/
│   ├── Controller/
│   │   ├── SecurityController.php
│   │   ├── DashboardController.php
│   │   ├── DosarController.php
│   │   ├── DocumentController.php
│   │   ├── TermenController.php
│   │   ├── PortalController.php
│   │   ├── ClientController.php
│   │   ├── DebitorController.php
│   │   └── SetariController.php
│   │
│   ├── Entity/
│   │   ├── Cabinet.php
│   │   ├── User.php
│   │   ├── Client.php
│   │   ├── Debitor.php
│   │   ├── Dosar.php
│   │   ├── Termen.php
│   │   ├── Document.php
│   │   ├── ActivitatePortal.php
│   │   └── ConfigRata.php
│   │
│   ├── Enum/
│   │   ├── TipPersoana.php         # PF | PJ
│   │   ├── DosarStatus.php         # AMIABIL | SOMATIE_TRIMISA | ...
│   │   ├── DocumentTip.php         # SOMATIE | CERERE_OP | ...
│   │   ├── TermenTip.php           # PRESCRIPTIE | SCADENTA_SOMATIE | ...
│   │   ├── TermenPriority.php      # CRITICAL | HIGH | MEDIUM | LOW
│   │   ├── TipRaport.php           # COMERCIAL | CIVIL
│   │   └── StatusOnrc.php          # ACTIV | RADIAT | INSOLVENT | ...
│   │
│   ├── Form/
│   │   ├── LoginFormType.php
│   │   ├── RegistrationFormType.php
│   │   ├── ClientType.php
│   │   ├── DebitorType.php
│   │   ├── CreantaType.php
│   │   ├── TermenType.php
│   │   └── SetariType.php
│   │
│   ├── Service/
│   │   ├── DosarService.php        # Logică principală dosare
│   │   ├── CalculeService.php      # Dobânzi, timbru, instanță
│   │   ├── DocumentService.php     # Generare PDF (DomPDF + Twig)
│   │   ├── TermeneService.php      # Creare/calcul termene automate
│   │   ├── PortalScraperService.php # symfony/panther scraping
│   │   ├── PortalAnalyzerService.php # Analiză schimbări detectate
│   │   ├── EmailService.php        # Template-uri email
│   │   ├── InstantaService.php     # Instanțe competente
│   │   └── OnrcService.php         # Verificare firme ONRC
│   │
│   ├── Message/                    # Symfony Messenger Messages
│   │   ├── PortalCheckMessage.php
│   │   ├── SendReminderEmailMessage.php
│   │   └── GenerateDocumentMessage.php
│   │
│   ├── MessageHandler/
│   │   ├── PortalCheckMessageHandler.php
│   │   ├── SendReminderEmailMessageHandler.php
│   │   └── GenerateDocumentMessageHandler.php
│   │
│   ├── Command/                    # Console Commands
│   │   ├── CheckTermeneCommand.php  # app:check-termene
│   │   └── CheckPortalCommand.php   # app:check-portal
│   │
│   ├── EventListener/
│   │   ├── DosarStatusChangedListener.php
│   │   └── DosarCreatedListener.php
│   │
│   ├── Security/
│   │   ├── LoginFormAuthenticator.php
│   │   ├── DosarVoter.php          # Can user access this dosar?
│   │   └── EmailVerifier.php
│   │
│   ├── Repository/
│   │   ├── DosarRepository.php
│   │   ├── TermenRepository.php
│   │   ├── ClientRepository.php
│   │   └── ...
│   │
│   └── DTO/
│       ├── PortalData.php          # Date extrase din portal
│       ├── DobandaResult.php
│       ├── TotalResult.php
│       └── InstantaResult.php
│
├── templates/
│   ├── base.html.twig              # Layout principal
│   ├── security/
│   │   ├── login.html.twig
│   │   └── register.html.twig
│   ├── dashboard/
│   │   └── index.html.twig
│   ├── dosar/
│   │   ├── index.html.twig         # Lista dosare
│   │   ├── show.html.twig          # Pagina dosar (tabs)
│   │   ├── wizard/
│   │   │   ├── creditor.html.twig
│   │   │   ├── debitor.html.twig
│   │   │   ├── creanta.html.twig
│   │   │   ├── documente.html.twig
│   │   │   └── review.html.twig
│   │   └── _partials/
│   │       ├── pipeline.html.twig
│   │       ├── termene_tab.html.twig
│   │       ├── documente_tab.html.twig
│   │       └── portal_tab.html.twig
│   ├── pdf/                        # Template-uri DomPDF
│   │   ├── _base.html.twig         # Layout comun PDF
│   │   ├── somatie.html.twig
│   │   ├── cerere_op.html.twig
│   │   └── opis.html.twig
│   ├── email/                      # Template-uri email
│   │   ├── _base.html.twig
│   │   ├── verify_email.html.twig
│   │   ├── reminder_termen.html.twig
│   │   └── portal_schimbare.html.twig
│   └── calendar/
│       └── index.html.twig
│
├── assets/
│   ├── app.js                      # Entry point Importmap
│   ├── controllers/                # Stimulus Controllers
│   │   ├── calcule_controller.js   # Live calcul dobânzi
│   │   ├── calendar_controller.js  # Calendar interactiv
│   │   ├── autocomplete_controller.js # Search client/debitor
│   │   ├── upload_controller.js    # Drag & drop upload
│   │   └── pipeline_controller.js  # Status pipeline visual
│   └── styles/
│       └── app.css                 # Tailwind directives
│
├── migrations/
│   └── ...
│
├── docker/
│   ├── php/
│   │   ├── Dockerfile
│   │   └── php.ini
│   ├── nginx/
│   │   └── nginx.conf
│   └── mysql/
│       └── my.cnf
│
├── docker-compose.yml
├── docker-compose.prod.yml
├── .env
├── .env.local                      # Local overrides (gitignored)
└── composer.json
```

---

## 13. Symfony Messenger – Jobs & Queues

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                # DSN: doctrine://default (MySQL transport)
                # Sau: redis://localhost:6379/messages (producție)
                options:
                    use_notify: true
                    check_delayed_until: 60
                retry_strategy:
                    max_retries: 3
                    delay: 1000      # 1 sec
                    multiplier: 5    # 1s, 5s, 25s
                    max_delay: 300000 # 5 min max

            sync:
                dsn: 'sync://'

        routing:
            App\Message\PortalCheckMessage: async
            App\Message\SendReminderEmailMessage: async
            App\Message\GenerateDocumentMessage: async
```

```yaml
# Cron jobs pe VPS (crontab -e)
# Zilnic 07:00 - verificare termene și trimitere reminder-uri
0 7 * * * cd /var/www/lexrecovery && php bin/console app:check-termene >> /var/log/lexrecovery/termene.log 2>&1

# Zilnic 08:00 - verificare portal.just.ro pentru dosare active
0 8 * * * cd /var/www/lexrecovery && php bin/console app:check-portal >> /var/log/lexrecovery/portal.log 2>&1

# Messenger worker (gestionat de Supervisor sau systemd)
# /etc/supervisor/conf.d/lexrecovery-worker.conf
[program:lexrecovery-messenger]
command=php /var/www/lexrecovery/bin/console messenger:consume async --time-limit=3600
autostart=true
autorestart=true
numprocs=2
```

---

## 14. Frontend – Stimulus Controllers

```javascript
// assets/controllers/calcule_controller.js
// Calculează live dobânzi + timbru când utilizatorul completează formularul

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['suma', 'scadenta', 'tipRaport', 'dobandaContract',
                      'rezultatDobanda', 'rezultatTotal', 'rezultatTimbru', 'rezultatInstanta'];
    static values = { rataBnr: Number }  // injectat din Twig: data-calcule-rata-bnr-value="{{ rata_bnr }}"

    connect() {
        this.calculeaza();
    }

    calculeaza() {
        const suma = parseFloat(this.sumaTarget.value) || 0;
        const scadenta = new Date(this.scadentaTarget.value);
        const azi = new Date();
        const tipRaport = this.tipRaportTarget.value;
        const dobandaContract = parseFloat(this.dobandaContractTarget.value) || null;

        if (!suma || isNaN(scadenta)) return;

        const zile = Math.max(0, Math.floor((azi - scadenta) / (1000 * 60 * 60 * 24)));

        let rata;
        if (dobandaContract) {
            rata = dobandaContract;
        } else {
            const puncte = tipRaport === 'comercial' ? 8 : 4;
            rata = this.rataBnrValue + puncte;
        }

        const dobanda = suma * (rata / 100) * zile / 365;
        const total = suma + dobanda;
        const timbru = Math.max(total * 0.02, 200);

        this.rezultatDobandaTarget.textContent = this.formatRON(dobanda);
        this.rezultatTotalTarget.textContent = this.formatRON(total);
        this.rezultatTimbruTarget.textContent = this.formatRON(timbru);
    }

    formatRON(val) {
        return new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2 }).format(val) + ' RON';
    }
}
```

```javascript
// assets/controllers/autocomplete_controller.js
// Search client/debitor în wizard

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'results', 'hiddenId'];
    static values = { url: String }

    async search() {
        const q = this.inputTarget.value;
        if (q.length < 2) return;

        const resp = await fetch(`${this.urlValue}?q=${encodeURIComponent(q)}`);
        const data = await resp.json();

        this.resultsTarget.innerHTML = data.map(item =>
            `<li data-id="${item.id}" data-action="click->autocomplete#select">
                ${item.nume} – ${item.cnpCui}
             </li>`
        ).join('');
    }

    select(event) {
        const li = event.currentTarget;
        this.inputTarget.value = li.textContent.trim();
        this.hiddenIdTarget.value = li.dataset.id;
        this.resultsTarget.innerHTML = '';
    }
}
```

---

## 15. Docker & Deployment

```yaml
# docker-compose.yml (development)
services:
  php:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    volumes:
      - .:/var/www/html
      - /var/www/html/vendor
    environment:
      - APP_ENV=dev
    depends_on:
      - mysql
      - chrome

  nginx:
    image: nginx:alpine
    ports:
      - "8080:80"
    volumes:
      - .:/var/www/html
      - ./docker/nginx/nginx.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - php

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: secret
      MYSQL_DATABASE: lexrecovery
      MYSQL_USER: lexrecovery
      MYSQL_PASSWORD: secret
    volumes:
      - mysql_data:/var/lib/mysql
    ports:
      - "3306:3306"

  chrome:
    image: zenika/alpine-chrome:latest
    # Necesar pentru symfony/panther (scraping portal.just.ro)
    cap_add:
      - SYS_ADMIN
    command: --headless --no-sandbox --remote-debugging-port=9222
    ports:
      - "9222:9222"

  messenger-worker:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    command: php bin/console messenger:consume async --time-limit=3600 -vv
    restart: unless-stopped
    depends_on:
      - mysql
    volumes:
      - .:/var/www/html

volumes:
  mysql_data:
```

```yaml
# docker-compose.prod.yml (producție – Coolify pe Hetzner)
services:
  php:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
      target: prod
    environment:
      - APP_ENV=prod
      - APP_SECRET=${APP_SECRET}
      - DATABASE_URL=${DATABASE_URL}
      - MAILER_DSN=${MAILER_DSN}
      - MESSENGER_TRANSPORT_DSN=${MESSENGER_TRANSPORT_DSN}
    restart: unless-stopped

  messenger-worker:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
      target: prod
    command: php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
    restart: unless-stopped
    deploy:
      replicas: 2

  # Coolify gestionează nginx + SSL (Let's Encrypt) automat
```

```yaml
# .github/workflows/deploy.yml
name: Deploy to Production

on:
  push:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: composer install --no-dev --optimize-autoloader
      - run: php bin/phpunit

  deploy:
    needs: test
    runs-on: ubuntu-latest
    steps:
      - name: Deploy via Coolify webhook
        run: |
          curl -X POST "${{ secrets.COOLIFY_WEBHOOK_URL }}" \
          -H "Authorization: Bearer ${{ secrets.COOLIFY_TOKEN }}"
```

---

## Variabile de Mediu

```env
# .env (valori default, se suprascriu în .env.local)
APP_ENV=dev
APP_SECRET=change_me_in_production

# Baza de date
DATABASE_URL="mysql://lexrecovery:secret@mysql:3306/lexrecovery?serverVersion=8.0"

# Messenger (dev: doctrine, prod: redis sau doctrine)
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0

# Email (Resend)
MAILER_DSN=resend+api://RE_XXXXXXXX@default

# Flysystem (local dev, S3/B2 în producție)
STORAGE_DRIVER=local
STORAGE_LOCAL_PATH=%kernel.project_dir%/var/storage

# Panther (Chrome pentru scraping)
PANTHER_CHROME_ARGUMENTS='--headless --no-sandbox --disable-dev-shm-usage'
PANTHER_CHROME_DRIVER_BINARY=/usr/bin/chromedriver

# App
APP_BASE_URL=https://lexrecovery.ro
ADMIN_EMAIL=admin@lexrecovery.ro
```

---

## Dependențe Composer principale

```json
{
    "require": {
        "php": ">=8.4",
        "symfony/framework-bundle": "7.2.*",
        "symfony/orm-pack": "^2.0",
        "symfony/security-bundle": "7.2.*",
        "symfony/mailer": "7.2.*",
        "symfony/messenger": "7.2.*",
        "symfony/twig-bundle": "7.2.*",
        "symfony/asset-mapper": "7.2.*",
        "symfony/ux-turbo": "^2.0",
        "symfony/ux-stimulus-bundle": "^2.0",
        "symfony/panther": "^2.1",
        "dompdf/dompdf": "^2.0",
        "league/flysystem-bundle": "^3.0",
        "symfonycasts/tailwind-bundle": "^0.6",
        "resend/resend-php": "^0.2",
        "doctrine/doctrine-migrations-bundle": "^3.0"
    },
    "require-dev": {
        "symfony/maker-bundle": "^1.0",
        "symfony/debug-bundle": "7.2.*",
        "phpunit/phpunit": "^11.0"
    }
}
```

---

## Note pentru Claude Code

### Ordinea de implementare (strictă)
1. Docker setup + MySQL + migrare inițială
2. Entități Doctrine + migrări
3. Auth complet (register, login, verify email, onboarding)
4. CRUD Client + Debitor (cu autocomplete JSON)
5. Wizard creare Dosar (session-based, 4 steps)
6. `CalculeService` – logica dobânzi, timbru, instanță
7. Template-uri PDF Twig + `DocumentService` (DomPDF)
8. Pipeline statusuri + `DosarService::tranzitioneazaStatus()`
9. `TermeneService` + `CheckTermeneCommand` + email remindere
10. `PortalScraperService` (symfony/panther) + `CheckPortalCommand`
11. Stimulus controllers (calcule live, autocomplete, calendar)
12. UI Polish (Tailwind, Turbo Frames pentru tabs)

### Convenții de cod
- **Enums PHP 8.1** pentru toate constantele (DosarStatus, DocumentTip, etc.)
- **DTOs imutabile** pentru rezultatele serviciilor (readonly properties)
- **Voters Symfony** pentru autorizare granulară (nu `isGranted` direct în controller)
- **Repository methods expresive**: `findActiveByCabinet()`, `findUpcomingTermene()`
- **Flash messages** pentru toate acțiunile utilizatorului
- **Turbo Streams** pentru actualizări parțiale (fără reload pagină)
- **CSRF protection** pe toate formularele POST

### Atenție DomPDF
- Nu suportă Flexbox / CSS Grid → folosește `<table>` pentru layout în template-uri PDF
- Font implicit: serif → include DejaVu Sans pentru caractere românești (ș, ț, ă, â, î)
- Path fonturi: `$dompdf->getOptions()->setChroot(...)` configurat corect
