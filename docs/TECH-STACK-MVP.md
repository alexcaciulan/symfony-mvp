# TECH STACK MVP — Recuperare Creanțe (Cerere Valoare Redusă)

> PHP 8.4 + Symfony 7 + MySQL 8 + Asset Mapper + Twig/Stimulus/Turbo
> Februarie 2026 | v2 — optimizat pentru MVP lean

---

## 1. Prezentare generală

Aplicație de recuperare creanțe prin procedura cererii cu valoare redusă (OUG 80/2013).
Principii: livrare rapidă (3-4 luni), expertiza echipei (PHP/Symfony), costuri minime, complexitate minimă.

| Layer | Tehnologie |
|-------|-----------|
| Backend | PHP 8.4 + Symfony 7.2 |
| Frontend | Twig + Symfony UX (Stimulus + Turbo) |
| CSS Framework | Tailwind CSS 3.4 (via symfonycasts/tailwind-bundle) |
| Asset pipeline | Asset Mapper + Importmap (zero Node.js) |
| Bază de date | MySQL 8.0 + Doctrine ORM 3 |
| Cache / Sessions | Symfony Cache filesystem (Redis post-MVP) |
| Queue / Jobs | Symfony Messenger + Doctrine transport |
| PDF Generation | DomPDF (dompdf/dompdf) |
| File Storage | Flysystem local (S3/B2 în producție) |
| Email | Symfony Mailer + Resend sau Postmark |
| Hosting | Hetzner Cloud (VPS) + Coolify (self-hosted PaaS) |
| CI/CD | GitHub Actions |
| Containerizare | Docker + Docker Compose |
| Monitoring | Sentry (erori) + Uptime Kuma (uptime) |

> Redis, JWT, OCR, 2FA, push notifications și Meilisearch sunt planificate post-MVP. Stack-ul permite adăugarea lor fără modificări de arhitectură.

---

## 2. Backend — PHP 8.4 + Symfony 7.2

Symfony 7.2 cu PHP 8.4: enums, fibers, typed properties, readonly classes, property hooks.

### 2.1 Symfony Bundles MVP

| Bundle / Component | Scop în aplicație |
|---|---|
| security-bundle | Autentificare, autorizare, roluri (ROLE_CREDITOR, ROLE_ADMIN) |
| symfony/form | Wizard-ul de depunere cerere (multi-step cu validare) |
| symfony/validator | Validare CNP, CUI, IBAN, sume, documente |
| symfony/workflow | State machine pentru statusul dosarului |
| symfony/messenger | Queue async: generare PDF, trimitere email (Doctrine transport) |
| symfony/mailer | Trimitere emailuri tranzacționale |
| symfony/notifier | Notificări multi-canal (email + in-app la MVP) |
| symfony/scheduler | Cron jobs: remindere plată, verificare status dosare |
| doctrine/orm | ORM + migrări bază de date |
| symfony/asset-mapper | Asset pipeline fără Node.js (JS, CSS, imagini) |
| symfony/ux-turbo | Turbo Frames/Streams — update-uri parțiale fără full page reload |
| ux-live-component | Componente reactive server-side (calculator taxă, preview cerere) |
| vich/uploader-bundle | Upload documente (contracte, facturi, dovezi) |
| league/flysystem-bundle | Abstractizare storage: local în dev, S3/B2 în producție |
| EasyAdmin 4 | Panou de administrare complet |
| dompdf/dompdf | Generare PDF Anexa 1 cerere cu valoare redusă |
| symfonycasts/tailwind-bundle | Compilare Tailwind CSS via CLI standalone (fără Node.js) |
| nelmio/security-bundle | Security headers: CSP, HSTS, X-Frame-Options |
| symfony/rate-limiter | Rate limiting: login 5/min, depunere cerere 10/oră |

### 2.2 Ce NU includem la MVP (și de ce)

| Componentă | Motiv excludere | Când se adaugă |
|---|---|---|
| Redis | Doctrine transport + filesystem cache suficiente la volum mic | Când ai 500+ utilizatori activi |
| Lexik JWT | Nu există client mobil; webhooks au autentificare proprie | Când adaugi API mobilă |
| scheb/2fa | Nice-to-have, nu blocker pentru lansare | Sprint 2-3 post-lansare |
| Google Vision OCR | Verificarea documentelor se face manual de admin | Când volumul justifică |
| Gotenberg | DomPDF suficient pentru formular juridic structurat | Dacă ai nevoie de HTML complex în PDF |
| Meilisearch | MySQL FULLTEXT suficient la volum mic | Când ai 1000+ dosare |
| Push / SMS | Email + notificări in-app acoperă nevoile MVP | Post-lansare, pe baza feedback-ului |

### 2.3 Structură proiect (Hibrid)

Structura standard Symfony cu subdirectoare pe domenii în Controller/ și Service/. Compatibilitate 100% cu Maker Bundle, Doctrine și autowiring.

```
src/
├── Controller/
│   ├── Case/           — CaseCreateController, CaseDashboardController, CaseDetailController
│   ├── Payment/        — PaymentController, PaymentWebhookController
│   ├── User/           — RegistrationController, ProfileController, LoginController
│   ├── Document/       — DocumentUploadController, DocumentDownloadController
│   └── Admin/          — EasyAdmin dashboard și CRUD controllers
├── Entity/             — flat: User, LegalCase, CaseStatusHistory, Document, Payment, Court, Notification, AuditLog
├── Repository/         — flat: UserRepository, LegalCaseRepository, etc.
├── Service/
│   ├── Case/           — CaseSubmissionService, TaxCalculatorService, CaseWorkflowService
│   ├── Payment/        — PaymentProcessorService, NetopiaService, InvoiceService
│   ├── Document/       — PdfGeneratorService, DocumentStorageService
│   ├── Notification/   — EmailNotificationService
│   └── Court/          — CourtLookupService, CompetentaTeritorialaService
├── Form/               — CaseWizardType, RegistrationType, etc.
├── EventSubscriber/    — Listeners pentru workflow transitions și audit log
├── MessageHandler/     — Handlere async Messenger (GeneratePdf, SendEmail)
├── Security/           — Voters (CaseVoter, DocumentVoter)
└── Twig/               — Twig Extensions și Components
```

---

## 3. Frontend — Twig + Asset Mapper

Asset Mapper e direcția oficială Symfony (Webpack Encore e în maintenance mode). Zero Node.js.

### 3.1 Arhitectură frontend

| Tehnologie | Rol | Unde se folosește |
|---|---|---|
| Twig | Template engine | Toate paginile, layout-uri, componente |
| Asset Mapper | Asset pipeline | Servire JS, CSS, imagini fără build step Node.js |
| Importmap | JS module loading | Import JS direct în browser, fără bundler |
| Turbo Drive | Navigație SPA-like | Toate link-urile — zero config |
| Turbo Frames | Update-uri parțiale | Wizard cerere (fiecare pas = un frame), tabel dosare |
| Turbo Streams | Real-time updates | Notificări live, status dosar |
| Stimulus | JS interactiv | Toggle-uri, dropdowns, calculator taxă, upload preview |
| Live Components | Reactive server-side | Formularul de cerere, preview PDF în timp real |
| Tailwind CSS | Utility-first CSS | Tot styling-ul, responsive design |

### 3.2 Asset pipeline — fără Node.js

Întregul frontend se gestionează exclusiv cu PHP:

- **Asset Mapper** — detectează și servește automat fișierele din assets/
- **Importmap** — `php bin/console importmap:require pachet` — înlocuiește npm install
- **Tailwind Bundle** — `php bin/console tailwind:build --watch` — compilează Tailwind via binary standalone
- **Stimulus** — auto-loading controllere din assets/controllers/ via Symfony UX
- **În producție** — `php bin/console asset-map:compile` — generează assets versionați

Beneficii: Dockerfile fără Node.js, CI/CD mai rapid, o singură tehnologie (PHP).

### 3.3 Responsive

Tailwind CSS breakpoint-uri standard (sm, md, lg, xl). Wizard: single-column pe mobile, multi-column pe desktop cu sidebar de progres. manifest.json minimal pentru iconiță home screen.

---

## 4. Bază de date — MySQL 8.0

### 4.1 Entități principale

| Entitate | Descriere | Relații cheie |
|---|---|---|
| User | Utilizator (PF/PJ/Avocat/Admin) | 1:N Cases, 1:N Payments |
| LegalCase | Dosarul / cererea cu valoare redusă | N:1 User, 1:N Documents |
| CaseStatusHistory | Istoricul tranzițiilor de status | N:1 LegalCase |
| Document | Fișiere uploadate + PDF-uri generate | N:1 LegalCase |
| Payment | Tranzacție (taxă + comision) | N:1 LegalCase, N:1 User |
| Court | Instanță judecată (judecătorie, tribunal) | 1:N LegalCases |
| Notification | Notificări trimise/pending | N:1 User, N:1 LegalCase |
| AuditLog | Audit trail (toate acțiunile pe dosar) | N:1 User, N:1 LegalCase |

### 4.2 Considerații tehnice

- **UUID v7 ca PK** — sortabile cronologic, sigure ca exposed IDs
- **Soft deletes** — pe User și LegalCase (cerință legală — nu se șterg dosare)
- **JSON columns** — MySQL 8 suportă nativ JSON; util pentru datele variabile ale formularului
- **Full-text search** — MySQL FULLTEXT index pe descrierea creanței (suficient la MVP)
- **Migrări** — Doctrine Migrations, rulate automat în CI/CD

---

## 5. Queue-uri și Cache

La MVP: Doctrine ca transport pentru Messenger și filesystem pentru cache/sesiuni. Migrare la Redis = schimbare de .env.

### 5.1 Symfony Messenger (Doctrine transport)

Messenger folosește tabela `messenger_messages` din MySQL. Zero infrastructură adițională.

| Job | Trigger | Prioritate |
|---|---|---|
| GeneratePdfMessage | După completare cerere | Înaltă |
| SendEmailMessage | Confirmare, status update | Înaltă |
| ProcessPaymentWebhook | Callback de la Netopia | Critică |
| CheckCaseStatusMessage | Scheduler (zilnic) | Scăzută |
| SendReminderMessage | Scheduler (reminder plată) | Medie |

### 5.2 Migrare la Redis (post-MVP)

O singură schimbare în .env: `MESSENGER_TRANSPORT_DSN=redis://localhost:6379/messages`. Similar pentru sesiuni și cache. Nu necesită schimbări de cod.

---

## 6. Infrastructură și Hosting

### 6.1 Hetzner Cloud

| Resursă | Specificație MVP | Cost estimat |
|---|---|---|
| VPS Principal | CPX31: 4 vCPU, 8 GB RAM, 160 GB NVMe | ~14 EUR/lună |
| Backup | Snapshot automat zilnic | ~2.80 EUR/lună |
| SSL | Let's Encrypt (gratis via Coolify) | 0 EUR |
| **TOTAL MVP** | | **~17 EUR/lună** |

### 6.2 Coolify — Self-hosted PaaS

Deploy automat din GitHub, SSL automat, management Docker containers, monitoring de bază și logs. Elimină configurarea manuală a Nginx, SSL, Docker Compose în producție.

### 6.3 Docker Compose (development)

4 containere — `make setup` pornește totul, fără dependențe externe:

- **php** — PHP 8.4-FPM container cu toate extensiile necesare
- **nginx** — Web server, servește aplicația pe localhost:8080
- **mysql** — MySQL 8.0 cu volume persistent (port 3307 local)
- **mailpit** — Fake SMTP server (vizualizare emailuri pe localhost:8025)

Aplicația: http://localhost:8080 | Mailpit: http://localhost:8025

> **Alternativă locală (fără Docker):** `symfony serve` (Symfony CLI) — HTTPS automat, dar necesită PHP + MySQL instalate local.

---

## 7. Securitate

| Aspect | Implementare | Bundle/Tool |
|---|---|---|
| Autentificare | Email + parolă (2FA post-MVP) | security-bundle |
| Autorizare | Voters Symfony (per-dosar, per-acțiune) | security-bundle Voters |
| CSRF | Token CSRF pe toate formularele | Symfony Form (built-in) |
| Rate Limiting | Login: 5/min, depunere cerere: 10/oră | symfony/rate-limiter |
| Criptare | TLS 1.3 în transit | Caddy auto-TLS (prod) |
| Password hash | bcrypt / Argon2id | Symfony PasswordHasher |
| Audit trail | Log complet pe fiecare acțiune pe dosar | EventSubscriber custom |
| GDPR | Consimțământ, export, ștergere date | Custom + EasyAdmin CRUD |
| Headers | CSP, HSTS, X-Frame-Options | nelmio/security-bundle |

---

## 8. Plăți — Netopia Payments

Hosted payment page (redirect) — cel mai simplu și sigur (Netopia gestionează PCI compliance).

1. Utilizatorul completează cererea → se calculează taxa judiciară + comision platformă
2. Redirect către Netopia hosted payment page
3. Webhook IPN procesat async via Messenger (ProcessPaymentWebhookMessage)
4. Dosarul tranziționează automat: pending_payment → paid

În development: PaymentSimulatorController simulează callback-urile Netopia.

---

## 9. CI/CD și Monitoring

### 9.1 GitHub Actions

- **On push/PR:** PHPStan → PHPUnit → PHP-CS-Fixer → Tailwind build → Asset Mapper compile
- **On merge to main:** Build Docker image → Push registry → Deploy via Coolify webhook

Fără npm/Node.js în CI — totul se compilează cu PHP.

### 9.2 Testing

- **PHPUnit** — unit tests (validators, calculatoare taxă, workflow transitions)
- **WebTestCase** — functional tests (controllere, formulare)
- **Foundry** — factories pentru fixtures în teste (dev dependency)
- **PHPStan nivel 6+** — analiză statică strictă

### 9.3 Monitoring

- **Sentry** — error tracking + performance monitoring (plan gratuit la MVP)
- **Uptime Kuma** — self-hosted, monitorizează uptime + SSL expiry
- **Symfony Profiler** — debug bar în development
- **Monolog** — logging structurat, trimis în fișier + Sentry

---

## 10. Servicii externe

| Serviciu | Provider | Scop | Cost MVP |
|---|---|---|---|
| Email | Resend / Postmark | Confirmări, notificări | Gratis (3K/lună) |
| Plăți | Netopia | Procesare card | 1.5% + 0.25 RON/trx |
| Errors | Sentry | Erori + performance | Gratis (5K ev/lună) |
| ONRC | openapi.ro | Verificare CUI/PJ | ~10 EUR/lună |

---

## 11. Sumar costuri lunare MVP

| Categorie | Cost estimat |
|---|---|
| Hetzner VPS (CPX31) | ~14 EUR |
| Backup + Snapshots | ~3 EUR |
| Domeniu (.ro) | ~2 EUR/lună (amortizat) |
| ONRC API (openapi.ro) | ~10 EUR |
| Email, Sentry, storage | 0 EUR (free tiers) |
| **TOTAL LUNAR** | **~29 EUR/lună** |

---

## 12. Scalare post-MVP

- **Redis:** schimbare .env pentru Messenger + sesiuni + cache
- **2FA:** `composer require scheb/2fa-totp-bundle`
- **JWT API:** `composer require lexik/jwt-authentication-bundle`
- **Vertical:** upgrade VPS instant (CPX31 → CPX41/51)
- **Horizontal:** separă MySQL pe server dedicat, adaugă app server + load balancer
- **Search:** Meilisearch dedicat când ai 1000+ dosare
- **CDN:** Cloudflare (free tier) pentru assets și protecție DDoS
- **SMS/Push:** symfony/notifier + Vonage bridge + FCM

---

## 13. Prioritizare MVP

| Nivel | Se implementează | NU se implementează încă |
|---|---|---|
| P0 | Entități + migrări, Wizard cerere, Generare PDF, Plăți Netopia | OCR, 2FA, JWT, SMS |
| P1 | Workflow dosare, Email notificări, EasyAdmin CRUD, Audit log | Push notifications, PWA complet |
| P2 | Tailwind styling, Upload documente, Notificări in-app | Full-text search, Encryption at rest |
