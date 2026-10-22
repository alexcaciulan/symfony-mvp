# Plan: depunerea cererii de ordonanță de plată la instanță prin email (secțiunile 3b-3e)

Branch: `feature/trimitere-email-instanta`. Data: 2026-10-22. Revizia 2 (după verificarea pe cod și verificarea juridică din aceeași zi; vezi secțiunea 10).

Documentul este decision-first: deciziile de mai jos sunt obligatorii pentru implementare. Fiecare secțiune se implementează, se testează și se revizuiește separat, într-un singur ciclu, în ordinea: **3b, 3c1, 3c2, 3d1, 3d2, 3e**. Identificatorii de cod sunt în engleză. Textele pentru avocat trec prin translator (RO și EN în paralel, fără em-dash). Textul emailului către instanță trece tot prin translator, cu chei `court_email.*` randate cu locale `ro` forțat; copia EN există doar pentru mentenanță.

Citările legale marcate **[V]** nu sunt verificate pe sursă primară și se verifică pe legislatie.just.ro înainte de a intra în UI sau în textul emailului. Numerotarea OP folosită în plan este cea din cod (commit `d3b3c4d`): art. 1014 condiții, art. 1015 somația și termenul de 15 zile, art. 1017 conținutul și anexele cererii, art. 1022 ordonanța [V].

---

## 1. Scop și decizii

**Scop.** Platforma transmite cererea de emitere a ordonanței de plată la instanța competentă prin email, în numele avocatului: pachet semnat de avocat (semnătură electronică calificată aplicată local), email cu avocatul în CC, retry automat la erori temporare, confirmarea automată de primire a instanței păstrată ca dovadă a primirii mesajului și redirecționată avocatului. Doar avocații sunt utilizatori. Platforma transmite; avocatul semnează, alege și validează adresa instanței și declară data depunerii.

### 1.1. Răspunsurile avocatului (2026-10-22, obligatorii)

| Pct. | Răspuns | Efect în plan |
|---|---|---|
| 1 | Numărul dosarului nu vine pe email; se găsește pe portal.just.ro. | Nu extragem numărul din emailuri. După transmitere, `PortalCaseDiscoveryService` / `portal_case_match` propune numărul (dosarele în `CERERE_DEPUSA` fără număr sunt preluate de `LegalCaseRepository::findAwaitingPortalDiscovery`). |
| 3 | Expeditorul poate fi adresa platformei, avocatul obligatoriu în CC. Ulterior: redirecționarea automată a confirmării instanței către avocat. | From = căsuța platformei (citită de inbound, T8), CC = avocat (fără CC nu se trimite), redirecționare în 3e. |
| 4 | Dovada = confirmarea automată de primire a instanței (toate trimit; unele și confirmare de citire). | 3e: confirmarea de primire se păstrează ca dovadă a **primirii mesajului**; înregistrarea cererii se vede pe portal. Confirmarea de citire doar în istoric. |
| 9 | Contract + facturi + alte dovezi într-un singur PDF semnat o dată, plus cererea semnată, plus împuternicirea semnată (3 semnături). Opisul: implicit în PDF-ul cererii, comutabil. | 3c1: trei fișiere logice; `filing.opis_placement = merged|separate`, implicit `merged`. |
| 10 | Nu se atinge 10 MB; la refuz de dimensiune se trimite în mai multe emailuri. Compresia înainte de semnare. | 3c1: compresie și împărțire în volume înainte de semnare; 3c2: bugetul reverificat pe bytes semnați; 3d: trimitere în mai multe emailuri. |
| 11 | Adresele instanțelor sunt doar pe pagina fiecărei instanțe de pe portal.just.ro; colectare automată, avocatul doar validează. Import aprobat pe dev. | 3b: import ca propuneri; validare de către avocat (pagină dedicată) sau admin (EasyAdmin). |
| 12 | Trimiterea e blocată fără documentele obligatorii. | `FilingReadinessChecker` (din `b291f83`) reutilizat neschimbat ca poartă. |
| Semnături | Semnătură calificată aplicată local; platforma generează PDF-uri nesemnate, avocatul descarcă, semnează, încarcă; platforma verifică prezența semnăturii. Fără ZIP. | 3c1 și 3c2. „Fără ZIP” se interpretează în 1.4. |

### 1.2. Deciziile utilizatorului (2026-10-22, obligatorii)

| Temă | Decizie |
|---|---|
| Model email | Subiect `Cerere de emitere a ordonanței de plată: [Creditor] contra [Debitor]`. Corp: adresare către instanță; creditorul cu sediu și CUI „prin avocat”; lista celor 3 documente; solicitarea confirmării de primire și a comunicării numărului de dosar la adresa avocatului din CC; blocul de semnătură al avocatului; subsol „Mesaj transmis prin platforma LexRecovery în numele avocatului.” Reply-To = avocatul. |
| Erori temporare | Retry automat de 3 ori (5 min, 30 min, 2 h), apoi notificare avocat în aplicație și pe email. |
| Erori permanente | Fără retry, notificare imediată, adresa instanței marcată invalidă pentru verificare. |
| Erori de dimensiune | Fără retry orb, notificare și propunere de împărțire; verificarea dinainte de trimitere le previne. |
| Transport | Implementare completă acum cu transport simulat (Mailpit). Căsuța reală de trimitere (SPF/DKIM) și cea de primire se conectează la deploy. |
| Inbound | Abstracție cu implementare dev/test (API Mailpit, fixture-uri) și producție (IMAP; webhook la alegerea furnizorului), aleasă prin config. |
| Import adrese | Se construiește și se rulează pe dev; rezultatele rămân propuneri validate de avocat/admin. Politicos: rate limit, User-Agent identificabil, cache pentru paginile brute. |
| Tranziție | `depune_cerere` se aplică la trimiterea reușită (momentul exact: T14, de confirmat). |

### 1.3. Decizii tehnice

Alegeri de implementare. Cele marcate „confirmare” se confirmă cu utilizatorul înainte de secțiunea indicată, fără a bloca secțiunile anterioare.

| # | Decizie | Motiv | Confirmare |
|---|---|---|---|
| T1 | Mai multe adrese per instanță (`CourtEmailAddress`); coloana `Court.email` se elimină. | Tribunalele rutează pe secții. `Court.email` e goală (0/223) și nu e citită nicăieri. | nu |
| T2 | Fișierele pachetului (nesemnate și semnate) nu sunt rânduri `Document`. | Ar intra în opis, ZIP, tabul de documente și extracție. | nu |
| T3 | Merge PDF cu `qpdf` (adăugat în Dockerfile), compresie cu Ghostscript, imagini convertite cu ImageMagick + pagină dompdf, numerotarea paginilor anexelor prin overlay dompdf + `qpdf --overlay`. | `qpdf` e fără pierderi; FPDI gratuit nu citește PDF 1.5+. | nu |
| T4 | Verificarea semnăturii raportează „semnătură detectată”, niciodată „semnătură calificată validă”. Integritatea (digest CMS peste ByteRange) blochează; CN-ul semnatarului și valabilitatea certificatului la data semnării sunt doar avertismente. Fără validarea lanțului (LOTL). Avocatul e îndrumat spre un validator public (ex. DSS al Comisiei Europene). | Nu există validator PHP matur; instanța validează; răspunderea e a avocatului. | nu |
| T5 | Emailul către instanță se trimite sincron prin `TransportInterface` dedicat (`court_filing`) în handler-ul nostru Messenger, nu prin `MailerInterface`. Socket timeout SMTP 60 s. | `MailerInterface` trece prin `async` și pierde codul SMTP; timeout-ul trebuie să fie mult sub `redeliver_timeout`. | nu |
| T6 | Transport Messenger `court_filing` (`doctrine://default?auto_setup=0&queue_name=court_filing`, tabela `messenger_messages` existentă) cu retry `delay 300000, multiplier 6, max_delay 7200000, max_retries 3, jitter 0`. Worker propriu `messenger:consume court_filing` (proces separat de `async`). În test: `in-memory://` în `config/packages/test/messenger.yaml`. | Programul cerut; o extracție lungă pe `async` nu poate întârzia o trimitere sau provoca un redeliver fals. `SchemaValidateTest` rămâne verde. | nu |
| T7 | Redeliver al unei părți găsite în `SENDING` nu retrimite: partea devine `UNCERTAIN`, depunerea `NEEDS_REVIEW`. Rezolvarea o face **avocatul** („Am primit / Nu am primit copia în CC”); adminul are doar override. | O cerere dublă înseamnă două dosare și două taxe. Avocatul e singurul care vede CC-ul. | nu |
| T8 | From = `COURT_FILING_FROM`, căsuță **citită de inbound** (aceeași cu adresa inbound). Envelope sender VERP (`bounces+{token}@{domeniu}`) pentru DSN. `Disposition-Notification-To` = From. Reply-To = doar avocatul. Mesajele primite se redirecționează avocatului doar dacă avocatul nu e deja în To/Cc. | Auto-reply-urile instanțelor și grefierii răspund adesea la From; așa confirmarea ajunge în platformă fără a încălca Reply-To = avocat. | nu (înlocuiește întrebarea din revizia 1) |
| T9 | Tranziție nouă `anuleaza_depunere_nelivrata` (`CERERE_DEPUSA` -> `CERERE_GENERATA`), aplicată doar la un bounce permanent pentru adresa instanței pe partea 1 primit după acceptarea SMTP. | Un bounce târziu trebuie să anuleze starea „transmisă”. Numele evită confuzia cu retragerea cererii (art. 406 CPC [V]). | **da, înainte de 3e** |
| T10 | La transmitere `filedAt` rămâne **null**. `FilingSubmission` păstrează `sentAt` (UTC, afișat Europe/Bucharest) și `courtReceiptAt`. Data depunerii o declară avocatul prin acțiunea „Stabilește data depunerii” (disponibilă din `CERERE_DEPUSA` și după, auditată, `filingChannel = EMAIL`), cu propunerea din confirmare (3e). | Codul existent tratează deja `filedAt` ca declarația avocatului (`CasePortalController:119-124`). Data are efecte (întreruperea prescripției, art. 2537 pct. 2 CC [V]). | nu |
| T11 | Confirmarea instanței nu devine `Document`; se păstrează ca `InboundMail` (.eml integral + sha256) legat de depunere. | Aceeași logică ca T2. | nu |
| T12 | Adresele nominale (`prenume.nume@just.ro`) se marchează `PERSONAL`: niciodată adresă de depunere, ascunse avocatului, șterse la cerere. | GDPR art. 5 alin. 1 lit. c și art. 17 [V]. | nu |
| T13 | Trimitere reală doar cu opt-in explicit `COURT_FILING_LIVE=1`, absent implicit peste tot (inclusiv demo cu `DEMO_APP_ENV=prod`). Fără el: destinatarii `just.ro` sunt refuzați și `COURT_FILING_RECIPIENT_OVERRIDE` e obligatoriu, oricare ar fi `APP_ENV`. Cu el: override-ul setat este refuzat. Verificat la fiecare trimitere și de `app:filing:preflight` (deploy, healthcheck). | Adresele importate sunt reale; `APP_ENV` nu protejează demo-ul. | nu |
| T14 | `depune_cerere` se aplică când **partea 1** (cererea + împuternicirea) e acceptată SMTP, nu când toate părțile sunt acceptate. Cardul arată „anexe în curs”. Starea depunerii devine `PARTIALLY_SENT` până la ultima parte. | Cererea a ajuns la instanță; termenul de 6 luni (NCC 2540 [V]) se închide; o retrimitere integrală ar dubla cererea. Ajustează ușor decizia „la trimiterea reușită”. | **da, înainte de 3d1** |
| T15 | Validarea adresei de către avocat **promovează** adresa la `VALIDATED` (`validatedBy` = avocatul, `source` păstrat). Este un act separat de trimitere, cu propriul formular și bifa „Confirm că la această adresă instanța primește cereri introductive”. Trimiterea la o adresă `PROPOSED` e blocată. Validarea expiră după 180 de zile sau la setarea `missingSince`. | Pct. 11 („avocatul doar validează”); o bifă la trimitere nu e validare; organization tenancy e amânat. | **da, înainte de 3b** |
| T16 | Pentru debitor persoană fizică (PF, PFA, II), subiectul folosește denumirea/numele scurtat la inițiale + CUI unde există; pentru PJ rămâne denumirea completă. | Subiectul circulă necriptat prin intermediari (GDPR art. 5 alin. 1 lit. c, art. 32 [V]). Ajustează formatul decis. | **da, înainte de 3d1** |
| T17 | Pachete composer `zbateson/mail-mime-parser` (BSD-2) și `webklex/php-imap` (MIT). După `composer require`: `make restart`. | Parsare MIME robustă; IMAP fără ext-imap. | **da, înainte de 3e** |
| T18 | `CaseWorkflowService::apply($case, $transition, array $context = [])` transmite `actor` în contextul workflow; `CaseWorkflowSubscriber` preferă `$event->getContext()['actor']` față de `Security::getUser()`; `AuditLogService::log` primește `?User $actor`. | În worker nu există utilizator în sesiune; istoricul trebuie să aibă autor. | nu |
| T19 | „O singură depunere activă per dosar” impusă în DB: coloana `activeLock` (1 cât e activă, NULL la terminal) cu unique (`legal_case_id`, `active_lock`), plus `PESSIMISTIC_WRITE` pe `LegalCase` în `submit()`. La dublu clic se returnează depunerea existentă (Turbo Stream). Analog pentru build (unique `legal_case_id`+`version` + lock) și upload semnat (lock pe rândul părții). | Idempotență fără condiții de cursă. | nu |

**Constatare (3b).** `https://portal.just.ro/` a răspuns cu **403 Cloudflare** la o cerere automată și la un Playwright de pe același IP. Importul separă sursa paginilor de parsare și are fallback „pagini salvate din browser de un operator” și introducere manuală. User-Agent-ul conține doar adresa de contact a platformei (`contact@lexrecovery.ro`, căsuță reală, monitorizată).

### 1.4. Interpretarea „Fără ZIP”

„Fără ZIP” privește fluxul de semnare: pachetul de semnat se descarcă fișier cu fișier, nu ca arhivă. ZIP-ul existent (`CaseFilesPackager`) rămâne calea explicită „depunere pe alt canal” (REJUST, registratură, poștă, curier), împreună cu `confirmFiling` manual:
- pe overview e etichetat ca atare;
- descărcarea ZIP se auditează (`zip_package_downloaded`, din 3c1);
- „Trimite prin email” dispare după o depunere manuală confirmată; descărcarea ZIP ca pachet de depus dispare după o depunere `SENT` sau `PARTIALLY_SENT` (ZIP-ul rămâne accesibil ca arhivă a dosarului).

### 1.5. Poarta legală pentru trimiterea reală

Implementarea (cu transport simulat) nu este blocată. **Prima trimitere reală** (`COURT_FILING_LIVE=1`) este blocată până când:
1. Există nota „Temei legal pentru depunerea pe email” (anexă la acest document): textul art. 148, 149, 183, 194 CPC [V], cerința de semnătură electronică calificată (Legea 214/2024; Reg. UE 910/2014 art. 25 alin. 2 [V]), eventualele reguli CSM sau ale instanțelor privind canalul de depunere.
2. Avocatul a răspuns la întrebările 5 și 6 din secțiunea 6.
3. DPA-urile (secțiunea 5) sunt încheiate.

Per instanță, confirmarea că adresa primește cereri introductive face parte din actul de validare (T15), deci este blocantă la prima trimitere către acea instanță.

---

## 2. Arhitectura

### 2.1. Fluxul complet

```
[3b] CourtEmailAddress VALIDATED (neexpirată, implicită pentru instanță/secție)
          |
[3c] FilingPackage
     BUILT -> AWAITING_SIGNATURES -> SIGNED -> SENT
        \__ STALE (fingerprint diferit, calculat leneș; niciodată din SENT) -> rebuild (vechiul SUPERSEDED, fișierele păstrate)
     SENT -> SIGNED doar la anuleaza_depunere_nelivrata

[3d] FilingSubmission (deliveryStatus)
     QUEUED -> SENDING -> PARTIALLY_SENT -> SENT
                  |  \-> FAILED_TEMPORARY_EXHAUSTED | FAILED_PERMANENT | FAILED_SIZE | FAILED_INTERNAL
                  \-> NEEDS_REVIEW (parte UNCERTAIN)
     PARTIALLY_SENT + parte eșuată -> „Trimite părțile rămase” (aceeași depunere, același token și thread)
     QUEUED -> CANCELLED (înainte de prima parte acceptată)
     evidenceStatus [3e]: AWAITING_RECEIPT -> RECEIPT_RECEIVED | RECEIPT_MANUAL ; RECEIPT_OVERDUE (alertă)

     LegalCase: CERERE_GENERATA --depune_cerere (partea 1 acceptată, T14)--> CERERE_DEPUSA (filedAt rămâne null, T10)
                CERERE_DEPUSA --anuleaza_depunere_nelivrata (bounce permanent târziu pe partea 1, T9)--> CERERE_GENERATA
                CERERE_DEPUSA --(portal_case_match, existent)--> număr propus avocatului
```

### 2.2. Starea unei părți de email (`FilingEmailPart`)

```
PENDING -> SENDING -> ACCEPTED
             |  \-> RETRY_SCHEDULED -> SENDING ... (max 3 retry)
             |  \-> FAILED_TEMPORARY_EXHAUSTED | FAILED_PERMANENT | FAILED_SIZE | FAILED_INTERNAL
             \-> UNCERTAIN (redeliver găsit în SENDING) -> ACCEPTED | FAILED_INTERNAL (avocat: CC primit / neprimit)
PENDING -> CANCELLED (anulare sau inregistreaza_dosar pe alt canal)
```

Părțile se trimit în ordine: partea `n+1` se dispecerizează doar după ce `n` e `ACCEPTED`. Un eșec pe partea 1 oprește anexele. Părțile 2..n poartă `In-Reply-To`/`References` spre Message-ID-ul părții 1.

### 2.3. Componente

```
Admin ──> CourtEmailAddressCrud ─┐
Avocat ─> CourtAddressValidationController ─┴─> CourtEmailAddressService ─> CourtEmailAddress
portal.just.ro ─> CourtPageFetcher (HTTP | SavedHtml) ─> CourtContactPageParser ─> CourtEmailImporter

Avocat ──> CaseFilingController ──> FilingPackageBuilder ──> PdfToolbox (qpdf, gs, magick), PackageOpisRenderer
                                └─> SignedPartUploadService ──> UploadedPdfValidator, PdfSignatureInspector
                                └─> FilingSubmissionService ──> FilingSendGuard
                                                            └─> Messenger court_filing
                                                                 └─> SendFilingEmailPartHandler (re-rulează FilingSendGuard)
                                                                      └─> CourtFilingEmailFactory, RecipientOverrideGuard
                                                                      └─> TransportInterface (court_filing)
                                                                      └─> SmtpFailureClassifier
                                                                      └─> FilingCompletion ─> CaseWorkflowService (depune_cerere, context actor)
                                                                      └─> NotificationDispatcher, AuditLogService

Mailpit API | fixture | IMAP ──> InboundMailSourceInterface ──> InboundEmailIngestor
                                 └─> DsnParser, MdnParser, InboundMailClassifier, InboundAuthVerifier, InboundMailMatcher
                                 └─> FilingInboundApplier (receipt, bounce, MDN, reply, redirecționare)
```

---

## 3. Model de date

Toate entitățile noi vin cu migrare în secțiunea care le introduce. Enum-urile sunt backed string, cu `label()` prin chei de traducere.

### 3.1. Secțiunea 3b

**`Court`** (modificat): `portalSiteId` (int, nullable, unique); se elimină `email` (și câmpul din `CourtCrudController`).

**`CourtEmailAddress`** (nou)
| Câmp | Tip | Note |
|---|---|---|
| `court` | ManyToOne Court | not null |
| `email` | string(254) | lowercase; unique (`court_id`, `email`) |
| `purpose` | enum `CourtEmailPurpose` | `REGISTRY`, `SECTION_CIVIL`, `SECTION_PROFESSIONALS`, `OTHER`, `PERSONAL` |
| `sectionLabel` | string nullable | textul secției de pe pagină |
| `status` | enum `CourtEmailStatus` | `PROPOSED`, `VALIDATED`, `REJECTED`, `INVALID` |
| `filingDefault` | bool | cel mult unul `VALIDATED` per (instanță, scop) (impus în serviciu) |
| `source` | enum `CourtEmailSource` | `PORTAL_IMPORT`, `MANUAL` |
| `sourceUrl`, `context` | string / text | context ~200 caractere + cel mai apropiat titlu |
| `firstSeenAt`, `lastSeenAt`, `missingSince` | datetime_immutable | `missingSince` setat când adresa dispare de pe pagină |
| `validatedBy`, `validatedAt`, `validationStatement` | User, datetime, string | textul bifei confirmate la validare |
| `invalidatedAt`, `invalidReason` | datetime, string | |
| `invalidatedBySubmission` | ManyToOne FilingSubmission nullable | adăugat în migrarea 3d2 |
| `invalidatedByInboundMail` | ManyToOne InboundMail nullable | adăugat în migrarea 3e |

Amânate până la răspunsul la întrebarea 4: `maxMessageBytes` per adresă, `acceptsNewFilings` separat (acoperit de validare, T15). Snapshot-ul brut al paginii rămâne în cache-ul de fișiere, nu în entitate.

### 3.2. Secțiunea 3c (3c1 + 3c2)

**`FilingPackage`**
| Câmp | Note |
|---|---|
| `legalCase`, `version` | unique (`legal_case_id`, `version`) |
| `status` enum `FilingPackageStatus` | `BUILT`, `AWAITING_SIGNATURES`, `SIGNED`, `STALE`, `SUPERSEDED`, `SENT` |
| `opisPlacement` enum | `MERGED`, `SEPARATE` (snapshot al setării la build) |
| `sourceFingerprint` | sha256 peste: sha256 calculat al bytes-ilor `CERERE_OP` (nu `contentHash`, pe care `AbstractPdfGenerator` nu îl actualizează), id-uri și sha256 ale documentelor sursă, seria/numărul/data împuternicirii, totalurile creanței și data de calcul a dobânzii, id instanță, setarea opisului, starea taxei de timbru + prezența dovezii |
| `includesStampDutyProof` | bool, același predicat ca șablonul cererii (`ACHITATA` și `hasStampDutyProof`) |
| `sizePlan` json | părți -> emailuri, bytes estimate encodate |
| `staleReason` | string nullable |
| `builtAt`, `builtBy`, `totalUnsignedBytes`, `totalSignedBytes` | |
| `previousVersion` | nullable; la re-împărțirea doar a anexelor, părțile cerere/împuternicire semnate se preiau |

**`FilingPackagePart`**
| Câmp | Note |
|---|---|
| `package`, `role` enum `FilingPartRole` | `PETITION`, `OPIS`, `ANNEX_BUNDLE`, `POWER_OF_ATTORNEY` |
| `volume`, `volumeCount` | pentru `ANNEX_BUNDLE` împărțit |
| `title`, `pageCount`, `annexPageFrom`, `annexPageTo`, `sourceDocumentIds` json | numerotare continuă a anexelor peste volume |
| `partToken` | scris în `/Info LexRecoveryPartId` al PDF-ului nesemnat |
| `unsignedPath`, `unsignedSha256`, `unsignedBytes`, `compressionApplied` | |
| `signedPath`, `signedSha256`, `signedBytes`, `signedUploadedAt`, `signedUploadedBy` | nullable (3c2) |
| `signatureStatus` enum | `MISSING`, `PRESENT_MATCHED`, `PRESENT_PROBABLE`, `MISMATCH`, `NOT_SIGNED`, `DAMAGED` |
| `probableMatchConfirmedBy`, `probableMatchConfirmedAt` | bifa obligatorie a avocatului pentru `PRESENT_PROBABLE` |
| `signatureReport` json | număr semnături, SubFilter, acoperire ByteRange, rezultat digest, CN și valabilitate certificat (doar avertismente) |

Fișiere: `{uploadsDir}/cases/{caseId}/filing/v{version}/unsigned/` și `.../signed/`, nume generate (`{role}-{volume}-{random}.pdf`), fără input de la utilizator. Un fișier semnat nu este rescris și nu este comprimat niciodată. Un pachet `SUPERSEDED` își păstrează fișierele.

**`AppSetting`**: `filing.opis_placement` (`merged`). Parametri în `config/packages/lexrecovery.yaml`: `filing.max_email_bytes` (9 500 000 encodat), `filing.signature_reserve_bytes` (300 000), `filing.signed_upload_max_bytes` (20 MB).

### 3.3. Secțiunea 3d (3d1 + 3d2)

**`FilingSubmission`**
| Câmp | Note |
|---|---|
| `legalCase`, `package` (SIGNED), `court`, `courtEmailAddress` | |
| `toAddress`, `fromAddress`, `ccAddress`, `replyToAddress`, `envelopeSender` | snapshot |
| `token` | aleator, 26 caractere, în Message-ID și VERP |
| `deliveryStatus` enum `FilingDeliveryStatus` | `QUEUED`, `SENDING`, `PARTIALLY_SENT`, `SENT`, `FAILED_TEMPORARY_EXHAUSTED`, `FAILED_PERMANENT`, `FAILED_SIZE`, `FAILED_INTERNAL`, `NEEDS_REVIEW`, `CANCELLED`, `UNDELIVERED` (după T9) |
| `evidenceStatus` enum `FilingEvidenceStatus` | `AWAITING_RECEIPT`, `RECEIPT_RECEIVED`, `RECEIPT_MANUAL`, `RECEIPT_OVERDUE` (3e) |
| `activeLock` | smallint nullable; unique (`legal_case_id`, `active_lock`) (T19) |
| `confirmations` json | textul exact al fiecărei bife confirmate, inclusiv „Cererea nu a fost depusă pe alt canal” când e cerută |
| `addressStateSnapshot` json | status, sursă, validatedBy/At ale adresei la trimitere |
| `initiatedBy`, `initiatedAt`, `initiatedIp`, `initiatedSessionHash`, `sentAt`, `courtReceiptAt`, `readReceiptAt` | |
| `templateVersion` | versiunea cheilor `court_email.*` |

**`FilingEmailPart`**
| Câmp | Note |
|---|---|
| `submission`, `sequence`, `total` | unique (`submission_id`, `sequence`) |
| `packageParts` | ManyToMany `FilingPackagePart` |
| `subject`, `messageId` (`<filing.{token}.{seq}@{domeniu}>`), `inReplyTo`, `verpToken` | scrise înainte de `send()` |
| `encodedBytes` | din mesajul serializat real, comparat cu bugetul imediat înainte de `send()` |
| `status` enum `FilingPartStatus`, `attemptCount`, `sentAt` | |
| `emlPath`, `emlSha256` | copia exactă, scrisă înainte de `send()` |
| `lastSmtpCode`, `lastEnhancedStatus`, `lastError` | |

**`FilingAttempt`**: `part`, `attemptNo` (unique cu `part`), `startedAt`, `finishedAt`, `outcome` (`ACCEPTED`, `TEMPORARY`, `PERMANENT`, `SIZE`, `INTERNAL`, `UNCERTAIN`), `smtpCode`, `enhancedStatus`, `errorClass`, `message` (max 500, fără date de debitor), `source` (`SMTP`, `ASYNC_BOUNCE`, `LAWYER_CC_CHECK`, `ADMIN_OVERRIDE`), `nextRetryAt`.

### 3.4. Secțiunea 3e

**`InboundMail`**
| Câmp | Note |
|---|---|
| `source`, `sourceId` | unique împreună |
| `messageId`, `inReplyTo`, `references`, `fromAddress`, `toAddress`, `ccAddresses`, `deliveredTo`, `subject`, `receivedAt` | |
| `rawPath` (`{uploadsDir}/inbound/{yyyy}/{mm}/{sha}.eml`), `rawSha256`, `rawBytes` | |
| `authVerdict` enum | `TRUSTED_PASS` (Authentication-Results al MTA-ului nostru, authserv-id din config, DMARC=pass pentru `just.ro`), `FAIL`, `ABSENT` |
| `classification` enum `InboundClassification` | `RECEIPT`, `READ_RECEIPT`, `BOUNCE_TEMPORARY`, `BOUNCE_PERMANENT`, `BOUNCE_SIZE`, `DELAYED`, `AUTO_REPLY_OTHER`, `HUMAN_REPLY`, `UNKNOWN` |
| `dsnStatus`, `dsnFinalRecipient` | |
| `matchedPart`, `matchedSubmission`, `matchMethod` (`VERP`, `IN_REPLY_TO`, `EMBEDDED_HEADERS`, `HEURISTIC`, `MANUAL`), `matchConfidence` (`CERTAIN`, `PROBABLE`) | |
| `reviewStatus` (`NONE`, `PENDING`, `RESOLVED`), `processedAt`, `forwardedAt`, `purgeAfter` | |

`FilingSubmission` primește `receiptInboundMail` și `readReceiptInboundMail` (FK). `InboundPollHeartbeat` (sau `AppSetting`): `lastSuccessfulPollAt`, `lastError`.

---

## 4. Secțiunile de implementare

### 4.1. Secțiunea 3b: adresele de email ale instanțelor

**Scop.** Model cu mai multe adrese per instanță, import ca propuneri de pe portal.just.ro, validare de către avocat (pagină dedicată, T15) și admin (EasyAdmin), marcaj de adresă invalidă, expirarea validării, rezolvarea adresei de depunere pentru un dosar.

**Pas 0 (spike, jumătate de zi).** Utilizatorul deschide manual, într-un browser normal, lista instanțelor și 3 pagini de contact (o judecătorie, un tribunal cu secții, Tribunalul București), le salvează ca HTML și notează schema URL, locul adreselor, sursa `portalSiteId` și eventualii termeni de utilizare ai portalului. Paginile devin fixture-uri. La 403 și în browser: cerere de whitelisting (CSM / just.ro), fetcher pe pagini salvate, introducere manuală. Nicio altă cerere externă fără aprobare.

**Fișiere**
- `src/Entity/CourtEmailAddress.php`, repository, enum-urile `CourtEmailPurpose`, `CourtEmailStatus`, `CourtEmailSource`; `src/Entity/Court.php`; migrare
- `src/Service/Court/Directory/CourtPageFetcherInterface.php`, `HttpCourtPageFetcher.php`, `SavedHtmlCourtPageFetcher.php`, `CourtSiteIdResolver.php`, `CourtContactPageParser.php`, `CourtEmailPurposeClassifier.php`, `CourtEmailImporter.php`
- `src/Service/Court/CourtEmailAddressService.php`, `CourtFilingAddressResolver.php`
- `src/Command/CourtDirectoryFetchCommand.php`, `CourtDirectoryImportCommand.php`
- `src/Controller/Admin/CourtEmailAddressCrudController.php`, `CourtCrudController.php`, `DashboardController.php`
- `src/Controller/Court/CourtAddressValidationController.php` + `templates/court/address/*` (pagina avocatului)
- `config/packages/framework.yaml` (scoped client), `rate_limiter.yaml` (`portal_court_pages`, `court_address_validation`, `when@test` `no_limit`)
- `tests/fixtures/court_pages/*.html`

**Servicii**
- `HttpCourtPageFetcher`: scoped HttpClient `https://portal.just.ro`, User-Agent `LexRecoveryCourtDirectory/1.0 (+https://lexrecovery.ro/bot; contact@lexrecovery.ro)`, timeout 20 s, cel mult o cerere la 5 s, respectă `robots.txt`, cache `var/court_pages/{siteId}/{Ymd}.html` + sha256, fără re-descărcare sub 30 de zile (`--refresh`), oprire la primul 403/429.
- `SavedHtmlCourtPageFetcher`: citește paginile salvate de operator în același director.
- `CourtSiteIdResolver`: `portalCode` / nume -> `portalSiteId`, reutilizând `CourtNameIndex`.
- `CourtContactPageParser` (pur): HTML -> `list<EmailCandidate>`; `mailto:`, entități, `[at]`, `(at)`, ` at `, adrese despărțite de taguri.
- `CourtEmailPurposeClassifier` (pur): `registratur` -> `REGISTRY`; `secția a II-a`, `profesioniști` -> `SECTION_PROFESSIONALS`; `civil` -> `SECTION_CIVIL`; `prenume.nume@just.ro` -> `PERSONAL`; restul `OTHER`.
- `CourtEmailImporter`: upsert idempotent, inserează `PROPOSED`, actualizează `lastSeenAt`, setează `missingSince`; nu modifică rânduri `VALIDATED`/`REJECTED`/`INVALID` (doar `missingSince`, care expiră validarea).
- `CourtEmailAddressService`: `validate(address, user, statement)`, `reject`, `setFilingDefault`, `markInvalid`, `reopen`, `reportWrong`, `addManual`, `erasePersonal`; audit la fiecare acțiune. `PERSONAL` nu poate fi validată pentru depunere.
- `CourtFilingAddressResolver::resolve(LegalCase): CourtFilingAddressResult`: judecătorie -> `VALIDATED` + `filingDefault` neexpirată; tribunal -> `SECTION_PROFESSIONALS`/`SECTION_CIVIL` validată după natura părților, altfel null cu `TRIBUNAL_SECTION_UNKNOWN` (fără excepții, fără fallback pe registratură); validare expirată -> null cu `VALIDATION_EXPIRED`; `INVALID`, `PROPOSED`, `PERSONAL` nu sunt returnate niciodată.

**Comenzi** (output în engleză): `app:court-directory:fetch [--court=] [--limit=] [--refresh] [--from-cache-only] [--resolve-site-ids]`; `app:court-directory:import [--court=] [--dry-run]` cu raport de acoperire (instanțe cu propuneri, fără propuneri, blocate, adrese dispărute, tribunale fără secție).

**UI**
- Pagina avocatului „Adresa instanței” (link din cardul de depunere al dosarului): adresele propuse pentru instanța dosarului (fără `PERSONAL`), sursa și contextul, formular de validare cu bifa T15, adăugare manuală din lista proprie, „Raportează adresă greșită”. Turbo Stream, CSRF, limiter.
- EasyAdmin: filtre status/scop/județ/tip, acțiuni Validează (aceeași bifă), Respinge, Implicit, Invalidă, Redeschide, Șterge personală; dashboard „instanțe fără adresă validată”, „adrese de verificat”, „validări expirate”; listă read-only în `CourtCrudController`.

**Traduceri.** `court_email.purpose.*`, `court_email.status.*`, `filing.recipient.*`, `court_address.validation.*`, RO și EN.

**Teste**
- TestCase: parser pe fixture-urile din Pas 0 (judecătorie, tribunal cu secții, obfuscare, personală, duplicate); classifier.
- KernelTestCase: importer (idempotență, `missingSince`, nu suprascrie `VALIDATED`); resolver (judecătorie, tribunal cu/fără secție, invalidă, propusă, personală, expirată); service (un singur implicit, audit, `PERSONAL` nu se validează).
- `HttpCourtPageFetcher` cu `MockHttpClient`: User-Agent, cache hit, oprire la 403.
- CommandTester pe ambele comenzi cu fetcher fals.
- WebTestCase: pagina avocatului (voter pe dosar, CSRF, validare cu bifă, fără bifă refuzat, adresa altei instanțe refuzată); acțiunile EasyAdmin.
- Niciun test nu face apel de rețea.

**DONE**
- Migrare aplicată, `doctrine:schema:validate` curat.
- Parser și importer verzi pe cel puțin 3 pagini reale (fixture-uri).
- Importul a rulat pe dev pe paginile care au putut fi descărcate sau salvate; raportul de acoperire e copiat în acest document. Introducerea manuală din listele avocatului contează la acoperire. 223 de pagini salvate manual **nu** sunt condiție.
- Un avocat validează o adresă din pagina proprie, iar resolverul o returnează pentru dosarul lui.

**Verificare live**
- `fetch --limit=3`, `import --dry-run`, apoi import real; verifică `var/court_pages` și ritmul în log.
- Playwright cu avocatul dev: validează adresa instanțelor a 3 dosare `CERERE_GENERATA`; screenshot.

**Riscuri**: 403 Cloudflare; structuri diferite de pagini; Tribunalul București fără adresă (manual); secție greșită = pierdere tăcută (resolver null); adrese personale (ascunse, ștergere la cerere); pagini schimbate (`missingSince` expiră validarea).

### 4.2. Secțiunea 3c1: asamblarea și descărcarea pachetului

**Scop.** Construirea fișierelor de semnat (cererea existentă copiată ca atare + opis în mod pachet, anexele într-un PDF numerotat, împuternicirea), compresie și împărțire înainte de semnare, descărcare nesemnată individuală, status și învechire calculată leneș, auditarea descărcării ZIP. Independentă de 3b.

**Fișiere**
- `Dockerfile` (`qpdf` pe linia `apk`, dev și prod)
- `src/Entity/FilingPackage.php`, `FilingPackagePart.php`, repository-uri, enum-urile `FilingPackageStatus`, `FilingPartRole`, `SignatureStatus`, `OpisPlacement`, migrare
- `src/Service/Pdf/ProcessSandbox.php` (director temporar sub `var/`, timeout, limite de memorie), `QpdfRunner.php`, `GhostscriptCompressor.php`, `ImageToPdfConverter.php`, `PdfPageNumberOverlay.php`
- `src/Service/Filing/FilingPackageBuilder.php`, `FilingSizePlanner.php`, `PackageOpisRenderer.php`, `FilingPackageStalenessChecker.php`, `FilingPackageFingerprint.php`
- `src/Service/Document/OpisGeneratorService.php` (context reutilizabil pentru modul pachet, fără scriere prin `AbstractPdfGenerator`), `templates/pdf/document_index_package.html.twig`, `templates/pdf/annex_cover.html.twig`
- `src/Controller/Case/CaseFilingController.php`, `templates/case/filing/_package_card.html.twig`, stream-uri Turbo
- `src/Controller/Case/CasePaymentOrderController.php` (audit `zip_package_downloaded`, eticheta „depunere pe alt canal”)
- `config/packages/lexrecovery.yaml`, `rate_limiter.yaml` (`filing_package_build` + `when@test`)

**Servicii**
- `QpdfRunner` (prin `ProcessSandbox`): merge, compactare fără pierderi, `--json`, `/Info LexRecoveryPartId`, `--overlay`; refuză PDF-uri criptate, cu XFA sau JavaScript.
- `GhostscriptCompressor`: `gs -dSAFER -sDEVICE=pdfwrite -dPDFSETTINGS=/ebook -dColorImageResolution=150 -dGrayImageResolution=150 -dMonoImageResolution=300 -dDetectDuplicateImages=true`; păstrează rezultatul doar dacă e mai mic, are același număr de pagini și același text per pagină.
- `ImageToPdfConverter`: ImageMagick `-resize 2000x2000> -quality 80 -strip`, apoi pagină A4 dompdf.
- `PackageOpisRenderer`: opis în mod pachet, randat în directorul pachetului (nu atinge rândul `OPIS` și nici opisul din ZIP): cele 3 fișiere fizice, fiecare document cu intervalul de pagini din numerotarea continuă a anexelor, împuternicirea ca fișier separat. **Nu** menționează volumele sau împărțirea pe emailuri.
- `FilingPackageBuilder::build(LegalCase, User)` (lock pe dosar, T19):
  1. `FilingReadinessChecker` și aceleași gărzi ca `CasePaymentOrderController::generate` (inclusiv dovada comunicării); blocantele opresc build-ul. Cere un `CERERE_OP` existent.
  2. Copiază bytes-ii `CERERE_OP` existenți ca atare (regula „o cerere per dosar” rămâne). Regenerează doar opisul, prin `PackageOpisRenderer`.
  3. `MERGED`: cerere + opis într-un PDF; `SEPARATE`: opisul e fișier separat (a patra semnătură).
  4. Anexe în ordinea din `CaseFilesPackager`: somația, dovada comunicării, contract, facturi, alte dovezi; plus `DOVADA_TAXA_TIMBRU` **doar** când `stampDutyStatus = ACHITATA` și dovada există (același predicat ca șablonul cererii, extras într-o metodă comună); exclusă pentru `AMANATA_REGULARIZARE`. Exclude cererea, opisul, împuternicirea, tipurile auto-generate. Imaginile se convertesc. Fiecare volum are o pagină de gardă (în afara numerotării) cu mențiunea de copii certificate „conform cu originalul” (art. 150 CPC [V], textul final la avocat, întrebarea 7), semnată odată cu volumul. Paginile anexelor primesc numerotare continuă prin overlay.
  5. Împuternicirea ca fișier separat.
  6. `FilingSizePlanner` (pur): buget = `max_email_bytes / 1.37 - signature_reserve_bytes` per email; emailul 1 = cererea + împuternicirea (+ anexe dacă încap); compresie doar la depășire, **înainte** de semnare; altfel volume.
  7. Fingerprint, pachetul anterior `SUPERSEDED` (fișierele rămân), audit `CATEGORY_FILING_PACKAGE`.
  8. `rebuildAnnexVolumes(package, smallerBudget)`: versiune nouă care preia părțile cerere/împuternicire semnate și refac doar volumele de anexe (opisul neschimbat, deci cererea nu se re-semnează). Folosit în 3d2 la `FAILED_SIZE` și în 3c2 la upload peste buget.
- `FilingPackageStalenessChecker` (leneș, fără listener Doctrine): recalculează fingerprint-ul la afișarea overview-ului, la upload și la trimitere; mută în `STALE` doar din `BUILT`/`AWAITING_SIGNATURES`/`SIGNED`, niciodată din `SENT`; ignoră schimbările cât timp o depunere ține `activeLock`.
- Dobânda: cererea afișează explicit data de calcul. Dacă textul actual nu o afișează, se adaugă în acest pas. Modalul din 3d1 avertizează dacă data de calcul e mai veche de 30 de zile.

**UI.** Card „Pachet de depunere” pe overview, lângă cardul ZIP (redenumit „Pachet pentru depunere pe alt canal”): generare cu avertismentele readiness; rând per fișier (descărcare nesemnată, dimensiune, pagini); „se vor trimite N emailuri”; banner volume; banner învechire cu motiv și regenerare; instrucțiunea „semnătura se aplică ultima; fișierul semnat nu se mai modifică”; nota „comprimarea o face platforma, local; încărcarea documentelor pe servicii externe de compresie poate încălca secretul profesional (Legea 51/1995 art. 11 [V]) și GDPR”. Turbo Stream inclusiv la erori; `CaseVoter`, CSRF, limiter. Descărcările doar prin voter.

**Traduceri.** `filing.package.*`, `pdf.opis_package.*`, `pdf.annex_cover.*`, RO și EN.

**Teste**
- TestCase: `FilingSizePlanner` (exact la buget, anexă uriașă, împărțire forțată); `FilingPackageFingerprint`.
- KernelTestCase (sărite dacă lipsesc binarele): `QpdfRunner`, `GhostscriptCompressor`, `ImageToPdfConverter`, overlay; builder (ordine, `MERGED`/`SEPARATE`, imagine, dovada taxei inclusă la `ACHITATA` cu dovadă și exclusă la `AMANATA_REGULARIZARE`, `CERERE_OP` copiat byte cu byte și rândul `OPIS` neatins, opis fără volume, blocante readiness, volume, `rebuildAnnexVolumes` păstrează cererea); staleness leneș (data împuternicirii sau anexă nouă -> `STALE`; `SENT` nu devine `STALE`).
- WebTestCase: voter, CSRF, audit `zip_package_downloaded`, dublu clic pe build -> un singur pachet.

**DONE**: pe un dosar dev `CERERE_GENERATA`, build-ul produce 3 PDF-uri în buget, cu opisul în mod pachet; editarea împuternicirii mută pachetul în `STALE` la următoarea afișare; ZIP-ul funcționează ca înainte și se auditează.

**Verificare live**: Playwright pe o clonă `TMP-LIVE-*`: build, descărcare, inspecția vizuală a opisului și a numerotării; set de anexe de 15 MB comprimat sau împărțit.

**Riscuri**: Ghostscript degradează scanări (prag, „doar dacă e mai mic”, verificare text); PDF-uri criptate sau corupte (refuz); volumele contrazic „un PDF semnat o dată” (excepție acceptată prin pct. 10); dobânda înghețată în PDF (data de calcul explicită, avertisment).

### 4.3. Secțiunea 3c2: încărcarea semnată și inspecția

**Scop.** Încărcarea versiunilor semnate, validarea securizată a fișierului, verificarea prezenței și integrității semnăturii, potrivirea cu fișierul generat, reverificarea bugetului pe bytes semnați, trecerea pachetului în `SIGNED`. Depinde de 3c1.

**Fișiere**: `src/Service/Pdf/UploadedPdfValidator.php`, `PdfSignatureInspector.php`, `PdfTextFingerprinter.php`; `src/Service/Filing/SignedPartUploadService.php`; rute în `CaseFilingController`; `rate_limiter.yaml` (`filing_signed_upload` + `when@test`); `tests/fixtures/filing/` + scriptul generator de PDF semnat cu certificat self-signed.

**Servicii**
- `UploadedPdfValidator` (reutilizat în 3e pentru confirmările manuale): magic bytes `%PDF-` și MIME prin finfo (nu extensia), max 20 MB, refuz criptat/XFA/JavaScript, procesare în `ProcessSandbox` cu `gs -dSAFER`, timeout și limite, nume stocat generat.
- `PdfSignatureInspector`: primar `qpdf --json` (câmpuri `/Sig` cu valoare); fallback `/ByteRange` + `/Contents`; verifică a=0, ultima semnătură acoperă fișierul, golul egal cu `/Contents`, `SubFilter` `adbe.pkcs7.detached` / `ETSI.CAdES.detached`; verifică digest-ul CMS peste ByteRange fără lanț (`openssl cms -verify -noverify -binary`); digest greșit -> `DAMAGED` (refuzat). CN-ul și valabilitatea certificatului la data semnării sunt doar avertismente (CN diferit de `User::getFullName()`, certificat expirat).
- `SignedPartUploadService` (lock pe rândul părții, o încărcare la un moment dat): validează, inspectează, potrivește cu nesemnatul: prefix identic -> `PRESENT_MATCHED`; `LexRecoveryPartId` + pagini + text per pagină -> `PRESENT_PROBABLE`, acceptat doar cu bifa avocatului „Confirm că fișierul semnat este documentul generat”; altfel `MISMATCH` refuzat; fără semnătură `NOT_SIGNED` refuzat. Reverifică bugetul pe bytes semnați reali: depășire -> refuz cu propunerea `rebuildAnnexVolumes` (doar volumele afectate, re-semnare doar a lor). Platforma nu comprimă niciodată un fișier semnat. Pachetul devine `SIGNED` când toate părțile sunt `PRESENT_*` confirmate.

**UI.** Pe rândul fiecărui fișier: încărcare semnată, chip („semnătură detectată”, „semnătură detectată, document recunoscut prin text, confirmare necesară”, „fișier diferit de cel generat”, „fără semnătură”, „semnătură deteriorată”), avertismentele de certificat, textul permanent „Prezența unei semnături nu garantează validitatea calificată; verificarea revine avocatului și instanței” cu link spre validatorul public. Răspunderea avocatului pentru semnătură e afirmată în UI.

**Traduceri.** `filing.signature.*`, `filing.upload.*`, RO și EN.

**Teste**
- TestCase: inspector pe fixture-uri (nesemnat, incremental, cu rescriere, două semnături, ByteRange incomplet, digest alterat, criptat); validator (extensie falsă, JavaScript, peste limită).
- KernelTestCase: upload matched, probable fără bifă refuzat, probable cu bifă acceptat, mismatch, nesemnat, deteriorat, peste buget -> propunere rebuild; două upload-uri concurente pe aceeași parte -> unul singur aplicat.
- WebTestCase: voter, CSRF, a patra semnătură cerută în `SEPARATE`, descărcarea semnatului doar pentru avocatul dosarului.

**DONE**: fixture-urile semnate duc pachetul în `SIGNED`; nesemnatele, alteratele și cele cu JavaScript sunt refuzate; un upload peste buget propune re-împărțirea doar a anexelor.

**Verificare live**: Playwright pe `TMP-LIVE-*`: semnare cu fixture sau cu un instrument QES real dacă e disponibil; notează nivelul de potrivire și avertismentele produse de instrumentul real al avocatului.

**Riscuri**: instrumente care rescriu fișierul (fallback pe text cu confirmare); LTV mărește fișierul (rezervă + reverificare la upload); suprafața de atac a PDF-urilor încărcate (sandbox, refuzuri).

### 4.4. Secțiunea 3d1: trimiterea, calea fericită

**Scop.** Modelul de date al depunerii (complet, inclusiv stările folosite în 3d2), mesajul, garda (la `submit()` și în handler), trimiterea asincronă în ordine a uneia sau mai multor părți cu threading, `depune_cerere` cu actor (T14, T18), interblocările cu portalul, cu depunerea manuală și cu ZIP-ul, idempotența, modalul și cardul de status, notificarea `FILING_SENT`, acțiunea „Stabilește data depunerii”. Depinde de 3b și 3c2.

**Fișiere**
- `src/Entity/FilingSubmission.php`, `FilingEmailPart.php`, `FilingAttempt.php`, repository-uri, enum-uri, migrare
- `src/Message/SendFilingEmailPartMessage.php`, `src/MessageHandler/SendFilingEmailPartHandler.php`
- `src/Service/Filing/CourtFilingEmailFactory.php`, `FilingSendGuard.php`, `FilingSubmissionService.php`, `FilingCompletion.php`, `RecipientOverrideGuard.php`, `FilingDateService.php`, `FilingLockQuery.php`
- `src/Command/FilingPreflightCommand.php`
- `src/Service/Case/CaseWorkflowService.php`, `src/EventSubscriber/CaseWorkflowSubscriber.php`, `src/Service/AuditLogService.php` (T18)
- `src/Security/Voter/CaseVoter.php` (fără upload/ștergere/editarea împuternicirii cât o depunere ține `activeLock`)
- `src/Controller/Case/CaseTransitionController.php` (`confirmFiling` refuzat cu depunere activă sau `PARTIALLY_SENT`), `CasePortalController.php` (`inregistreaza_dosar` cu depunere activă anulează părțile `PENDING`/`RETRY_SCHEDULED` și marchează depunerea pentru review)
- `src/Controller/Case/CaseFilingController.php` (modal, trimitere, anulare, `.eml`, data depunerii), `src/Controller/Admin/FilingSubmissionCrudController.php` (doar metadate)
- `translations/messages.*.yaml` (`court_email.*`, `filing.send.*`, `filing.status.*`, `notification.filing_sent`)
- `config/packages/messenger.yaml`, `test/messenger.yaml`, `mailer.yaml`, `rate_limiter.yaml` (`filing_submission` per utilizator per zi, `filing_court_cap` global per instanță, `when@test`)
- `.env`, `.env.test`, `tests/bootstrap.php` (unset `COURT_FILING_MAILER_DSN`, `INBOUND_MAIL_DSN`), `compose.yaml` (worker `court_filing` separat), configurația demo
- Docblock-uri care devin false: `CaseStatus::CERERE_GENERATA` / `CERERE_DEPUSA`, `FilingChannel` („the platform files nothing”), clasa `CasePaymentOrderController`, comentariul `depune_cerere` din `workflow.yaml`
- `src/Enum/NotificationType.php`, `src/EventSubscriber/EmailNotificationSubscriber.php`

**Configurare**
- `framework.mailer.transports`: `main`, `court_filing: '%env(COURT_FILING_MAILER_DSN)%'` (dev `smtp://mailer:1025`, test `null://null`), socket timeout 60 s; TLS obligatoriu în prod (opțiunea `require_tls` a transportului ESMTP, verificată pe versiunea instalată), eșecul TLS = eroare temporară.
- Env: `COURT_FILING_MAILER_DSN`, `COURT_FILING_FROM`, `COURT_FILING_BOUNCE_DOMAIN`, `COURT_FILING_RECIPIENT_OVERRIDE`, `COURT_FILING_LIVE` (T13).
- Messenger: T6. Worker: `messenger:consume court_filing` ca proces separat (compose, demo, supervisor prod).

**Mesajul** (`CourtFilingEmailFactory`, chei `court_email.*` cu locale `ro` forțat, `templateVersion` în snapshot)
- Subiect: `Cerere de emitere a ordonanței de plată: {Creditor} contra {Debitor}` (debitor PF conform T16), plus ` (partea {k} din {n})`.
- Corp text/plain UTF-8: adresare; creditorul cu sediu și CUI „prin avocat {nume}”; lista documentelor (și volumelor); la împărțire, în emailul 1 mențiunea că anexele urmează; fraza despre taxa de timbru condiționată de aceeași regulă ca pachetul (varianta „achitată, dovada în anexe” sau „se va achita la indicarea instanței”, texte finale la avocat, întrebarea 3); solicitarea confirmării de primire și a comunicării numărului de dosar la adresa avocatului din CC; blocul de semnătură (nume, barou dacă există în profil, seria/numărul/data împuternicirii, telefon, email); subsolul decis, completat cu „Actele sunt semnate electronic de avocat; acest email asigură doar transmiterea.” Emailul nu afirmă înregistrarea, plata neatestată sau validitatea calificată a semnăturilor.
- Antete: From `"Av. {Nume} prin LexRecovery" <COURT_FILING_FROM>`, To adresa validată (sau override), Cc avocatul (fără CC nu se construiește), Reply-To avocatul, Message-ID propriu, `In-Reply-To`/`References` pentru părțile 2..n, `X-LexRecovery-Ref`, `Disposition-Notification-To` = From.
- Envelope: `bounces+{verpToken}@{COURT_FILING_BOUNCE_DOMAIN}`, destinatari [instanța, avocatul].
- Atașamente: fișierele semnate, nume stabile (`01-cerere-si-opis.pdf`, `02-anexe-vol1.pdf`, `03-imputernicire.pdf`).
- Verificare că cererea indică emailul avocatului ca adresă de comunicare, niciodată adresa platformei (art. 194 lit. a CPC [V]).

**Servicii**
- `FilingSendGuard` (blocante și avertismente): dosar în `CERERE_GENERATA` și `CaseVoter::TRANSITION`; utilizator verificat (`User::isVerified`); abonament activ; limiter per utilizator și plafon per instanță; `FilingReadinessChecker` fără blocante; dovada comunicării și termenul de 15 zile împlinit (art. 1015 [V]); pachet `SIGNED`, neînvechit; `CompetentCourtResolver` re-rulat, egal cu `case.court` și cu instanța adresei; adresă rezolvată (3b) `VALIDATED`, neexpirată, ne-`INVALID`; nicio depunere activă; niciun `PortalCaseMatch` `PROPOSED`/`ACCEPTED` și niciun `courtCaseNumber`; nicio depunere manuală confirmată; bytes semnați în buget. Avertismente: CN diferit de numele avocatului, data de calcul a dobânzii veche.
- `FilingSubmissionService::submit(...)`: `PESSIMISTIC_WRITE` pe `LegalCase`, garda, persistă depunerea (`activeLock = 1`) și părțile (bin-packing), audit `CATEGORY_FILING_SUBMISSION` cu IP, hash de sesiune, `templateVersion` și textul bifelor; dispecerizează partea 1. Dublu clic: returnează depunerea existentă.
- `SendFilingEmailPartHandler`: lock pe parte; `ACCEPTED` -> ack; `SENDING` -> `UNCERTAIN` (T7, rezolvarea în 3d2); re-rulează `FilingSendGuard` (fără limiter) și la eșec depunerea `NEEDS_REVIEW` fără trimitere; `SENDING`, flush; construiește mesajul, `RecipientOverrideGuard`, scrie `.eml`, Message-ID și `encodedBytes` reale, compară cu bugetul; `send()`; `FilingAttempt`; succes -> `ACCEPTED`, dispecerizează partea următoare; partea 1 -> `FilingCompletion::onPetitionAccepted`; ultima -> `SENT`, `activeLock = NULL`, pachetul `SENT`.
- `FilingCompletion::onPetitionAccepted` (tranzacție): `filingChannel = EMAIL`, `filingReference` = Message-ID partea 1, `filedAt` neatins (T10), `depune_cerere` cu `context['actor'] = initiatedBy` (doar din `CERERE_GENERATA`; altfel review), audit, notificare `FILING_SENT`, publicare pe `caseStatusTopic` (model `ExtractionMercurePublisher`).
- `EmailNotificationSubscriber`: fără al doilea email la intrarea în `CERERE_DEPUSA` când canalul e `EMAIL`.
- `FilingDateService::setFromLawyer(case, date, source)`: din `CERERE_DEPUSA` și după, auditat, `filingChannel = EMAIL`; termenele derivate din data depunerii se recalculează aici, nu la transmitere.
- `RecipientOverrideGuard` + `app:filing:preflight`: T13.

**UI**
- Modal „Trimite la instanță”: instanța și motivul competenței cu linia „Instanța a fost determinată automat; verificarea competenței aparține avocatului”; adresa (status, sursă, validată de, data); From/CC/Reply-To; previzualizarea subiectului și a corpului; atașamentele cu dimensiuni; numărul de emailuri; avertismentele de bifat; bifa „Am verificat documentele și adresa instanței”; bifa „Cererea nu a fost depusă pe alt canal” obligatorie dacă `CERERE_GENERATA` precede deploy-ul sau există audit `zip_package_downloaded`.
- Card de status: „Transmisă instanței, fără confirmare de primire” (niciodată „depusă” până când avocatul stabilește data), „anexe în curs” la `PARTIALLY_SENT`, cronologie per parte și încercare, `.eml`, „Anulează” doar înainte de prima parte acceptată, „Stabilește data depunerii”.
- EasyAdmin `FilingSubmissionCrudController`: read-only, doar metadate (status, hash, mărimi, coduri SMTP); fără descărcare de `.eml` sau atașamente.

**Teste**
- TestCase: factory (antete, CC obligatoriu, Reply-To, VERP, Message-ID, threading, subiect k din n, subiect PF, fraza taxei pe cele două stări, locale ro forțat); bin-packing; `RecipientOverrideGuard` (fără `COURT_FILING_LIVE` refuză `just.ro` în orice `APP_ENV`; cu el refuză override setat).
- KernelTestCase: test că `court_filing` se rezolvă la `null://` în test; transport fals cu succes: o parte și trei părți în ordine, partea 1 -> `depune_cerere` cu actor în `CaseStatusHistory::createdBy`, `filedAt` null, canal `EMAIL`, `.eml` scris înainte de `send()`; garda în handler eșuată -> `NEEDS_REVIEW`, nicio trimitere; `inregistreaza_dosar` cu depunere activă -> părțile rămase anulate; tranzițiile existente din `CaseWorkflowSubscriber` neschimbate (fără context, actorul din sesiune).
- WebTestCase: fiecare gardă (fără pachet semnat, adresă propusă, expirată, neverificat, `PortalCaseMatch`, depunere manuală, instanță diferită), bifa „alt canal”, CSRF, voter, dublu submit -> o singură depunere, upload blocat cât e activă, `confirmFiling` refuzat, „Stabilește data depunerii”.
- MailerAssertions în test.

**DONE**: o trimitere pe un dosar dev ajunge în Mailpit cu From, To (override), Cc, Reply-To, Message-ID, `Disposition-Notification-To`, Return-Path corecte și atașamente cu hash egal cu părțile semnate; o împărțire forțată produce 2 emailuri în ordine, cu threading; dosarul ajunge în `CERERE_DEPUSA` cu `filedAt` null și e preluat de descoperirea pe portal; `app:filing:preflight` trece pe dev și pică cu `COURT_FILING_LIVE=1` + override.

**Verificare live**: trimitere pe `TMP-LIVE-*`, inspecție antete brute și dimensiune encodată în Mailpit; Playwright pe modal, card, notificare; `make restart` și reload worker după modificări PHP.

**Riscuri**: acceptarea SMTP nu e depunere (etichete, `filedAt` null); worker cu DI învechit (reload); DMARC între From și domeniul VERP (deploy); domeniul platformei trimite la instanțe fără legătură verificată cu un avocat real (numărul din Tablou scos în `d3b3c4d`; atenuare: utilizator verificat, abonament, limite, avertisment CN, audit).

### 4.5. Secțiunea 3d2: eșecuri, retry și reluare

**Scop.** Clasificarea eșecurilor SMTP și interne, programul de retry (5 min, 30 min, 2 h), epuizarea, `UNCERTAIN` rezolvat de avocat, `PARTIALLY_SENT` și „Trimite părțile rămase”, retrimiterea după corectarea adresei, `FAILED_SIZE` cu re-împărțirea anexelor, invalidarea adresei, notificările de eșec. Depinde de 3d1.

**Fișiere**: `src/Service/Filing/SmtpFailureClassifier.php`, `FilingResumeService.php`, `UncertainPartResolver.php`; `src/EventSubscriber/FilingSendFailureSubscriber.php` (`WorkerMessageFailedEvent`); migrare (FK `CourtEmailAddress.invalidatedBySubmission`); `NotificationType`; traduceri `filing.failure.*`, `notification.filing_*`.

**Servicii**
- `SmtpFailureClassifier` (pur): 4xx, erori de conexiune, eșec TLS = temporar (partea `RETRY_SCHEDULED`, rethrow); `5.1.x`, 550/551/553 = permanent (`UnrecoverableMessageHandlingException`, adresa `INVALID` legată de depunere); 552, `5.3.4`, `5.2.3` = dimensiune (unrecoverable, `FAILED_SIZE`); 5xx necunoscut = permanent. Erori non-SMTP: fișier semnat lipsă sau hash diferit = `FAILED_INTERNAL` unrecoverable, notificare, fără a consuma retry-urile; DB indisponibil = temporar.
- `FilingSendFailureSubscriber`: la `!willRetry()`, partea `FAILED_TEMPORARY_EXHAUSTED`, depunerea `FAILED_TEMPORARY_EXHAUSTED` sau `PARTIALLY_SENT` (dacă partea 1 e acceptată), notificare.
- Stări după eșec: nimic acceptat -> depunere terminală, `activeLock = NULL`, pachetul rămâne `SIGNED` (semnăturile nu depind de adresă); o nouă depunere e permisă doar după starea terminală; pachetul trece în `SENT` doar când cel puțin o parte e acceptată. Partea 1 acceptată și o anexă eșuată -> `PARTIALLY_SENT` (rămâne activă).
- `FilingResumeService::resumeRemaining(submission, user)`: aceeași depunere, același token și thread, trimite doar părțile ne-`ACCEPTED`, cere confirmare nouă (audit). `confirmFiling` manual rămâne refuzat. La `FAILED_SIZE` pe o anexă: `rebuildAnnexVolumes` (3c1), re-semnarea doar a volumelor noi, apoi reluarea.
- `UncertainPartResolver`: avocatul declară „Am primit copia în CC” -> `ACCEPTED` și calea de completare; „Nu am primit” -> `FAILED_INTERNAL`, reluare permisă. Override admin cu motiv obligatoriu, auditat. Acoperă și crash-ul între flush `SENDING` și `send()`.
- Notificări nesuprimabile: `FILING_FAILED_TEMPORARY`, `FILING_FAILED_PERMANENT`, `FILING_FAILED_SIZE`, `FILING_FAILED_INTERNAL`, `FILING_NEEDS_REVIEW`, `FILING_PARTIALLY_SENT`; dedup `filing:{submissionId}:{event}`; în aplicație și pe email.

**UI.** Pe card: motivul eșecului în limbaj simplu, codul SMTP, următorul retry; „Retrimite” (depunere nouă, doar după terminal fără nimic acceptat, cu confirmare nouă); „Trimite părțile rămase” la `PARTIALLY_SENT`; „Am primit / Nu am primit copia în CC” la `UNCERTAIN`; link la pagina de adresă după `FAILED_PERMANENT`; propunerea de re-împărțire la `FAILED_SIZE`.

**Teste**
- TestCase: classifier; `MultiplierRetryStrategy::getWaitingTime` = 300 s, 1800 s, 7200 s.
- KernelTestCase cu `Worker` real și `TransportInterface` fals care eșuează: verifică `DelayStamp` (300 000, 1 800 000, 7 200 000) și `RedeliveryStamp` pe plicurile de retry (`InMemoryTransport` ignoră `DelayStamp`); 421/450 -> retry, a 4-a eroare -> exhausted + notificare; 550 -> adresa `INVALID`, fără retry; 552 -> `FAILED_SIZE`; fișier lipsă -> `FAILED_INTERNAL` fără retry; redeliver cât primul handler rulează (lock ținut) -> nicio trimitere a doua; `SENDING` găsit -> `UNCERTAIN`; partea 2 eșuată -> `PARTIALLY_SENT`, reluarea trimite doar partea 2 cu același token; retrimitere după adresă corectată reutilizează pachetul `SIGNED`.
- WebTestCase: acțiunile de pe card, voter, CSRF.

**DONE**: fiecare clasă de eșec respectă politica; nicio cale nu produce două trimiteri ale părții 1; reluarea trimite doar părțile lipsă.

**Verificare live**: Mailpit chaos (`--enable-chaos`, `PUT /api/v1/chaos`): 451 la RCPT cu delay-uri scurtate prin env în dev, urmărind programul și notificările; 550; 552. Chaos acoperă doar erorile SMTP sincrone; bounce-urile asincrone vin doar din simulatorul din 3e. Oprirea worker-ului în timpul trimiterii -> `UNCERTAIN`, rezolvat din card.

**Riscuri**: trimitere lentă confundată cu crash (timeout 60 s, worker separat, lock); dublură după crash (`UNCERTAIN`); limite de dimensiune necunoscute per instanță (întrebarea 4).

### 4.6. Secțiunea 3e: procesarea mesajelor primite

**Scop.** Preluarea mesajelor din căsuța From/inbound și de la adresele VERP prin abstracție, clasificare, verificarea autenticității, potrivire cu partea sau depunerea, confirmarea de primire ca dovadă a primirii (fără efect automat asupra `filedAt` sau numărului), redirecționarea către avocat, bounce-urile reintroduse în politica 3d2, `anuleaza_depunere_nelivrata` cu redeschiderea termenului, heartbeat, alertă de confirmare neprimită, încărcare manuală, retenție. Depinde de 3d2 și de T9, T17.

**Fișiere**
- `src/Entity/InboundMail.php`, repository, enum-uri, migrare (FK pe `FilingSubmission` și `CourtEmailAddress`)
- `src/Service/Inbound/InboundMailSourceInterface.php`, `RawInboundMail.php`, `MailpitInboundSource.php`, `FixtureInboundSource.php`, `ImapInboundSource.php`, `InboundMailSourceResolver.php`
- `src/Service/Inbound/InboundEmailIngestor.php`, `DsnParser.php`, `MdnParser.php`, `InboundMailClassifier.php`, `InboundAuthVerifier.php`, `InboundMailMatcher.php`, `FilingInboundApplier.php`, `InboundForwarder.php`, `ReceiptOverdueChecker.php`
- `src/Message/ProcessInboundMailMessage.php` + handler
- `src/Command/FilingPollInboundCommand.php`, `FilingSimulateCourtReplyCommand.php` (refuză fără override, ca T13), `FilingReceiptOverdueCommand.php`, `InboundPurgeCommand.php`, `InboundHeartbeatCheckCommand.php`
- `src/Service/Deadline/DeadlineService.php` (`reopenFilingDeadline()`), listener pe `entered.CERERE_GENERATA` care, venind din `anuleaza_depunere_nelivrata`, nu rulează `onCerereGenerata` (`EmailNotificationSubscriber:43`)
- `config/packages/workflow.yaml`, `src/Enum/CaseTransition.php`
- `src/Controller/Admin/InboundMailCrudController.php`, rute în `CaseFilingController` (încărcare manuală, `.eml` pentru avocat)
- `tests/fixtures/inbound/*.eml`, `Makefile` (`make filing-poll`)
- Fără `InboundEmailWebhookController`: se adaugă la alegerea furnizorului.

**Abstracție** (`INBOUND_MAIL_DSN`, model `PaymentGatewayResolver`): `mailpit://mailer:8025?to={COURT_FILING_FROM}` (dev; caută după From-ul platformei și adresele VERP; ack prin tag `processed`; tolerează baza goală); `fixture://tests/fixtures/inbound` (test); `imaps://...` (prod, folder `Processed`).

**Pipeline**
- `app:filing:poll-inbound` (cron 5 min, Faza 9.1): `fetch()`, stocare raw + sha256 (idempotent pe `(source, sourceId)` și Message-ID + sha256), dispecerizare `ProcessInboundMailMessage`, `acknowledge()`, actualizare heartbeat.
- `DsnParser` (RFC 3464, fallback non-RFC), `MdnParser` (RFC 8098), `InboundMailClassifier` (DSN, MDN, auto-reply, uman, necunoscut).
- `InboundAuthVerifier`: ia în considerare doar `Authentication-Results` adăugat de MTA-ul nostru (authserv-id din config); DMARC=pass pentru `just.ro` -> `TRUSTED_PASS`. Antetele de autentificare ale altora și domeniul expeditorului nu dovedesc nimic.
- `InboundMailMatcher`: `CERTAIN` doar prin VERP sau `In-Reply-To`/`References` pe Message-ID-ul nostru **și** `TRUSTED_PASS` (pentru confirmări); `X-LexRecovery-Ref` în originalul atașat pentru DSN; euristica (domeniu, subiect, fereastră 14 zile) dă cel mult `PROBABLE` și merge mereu la review.

**Efecte (`FilingInboundApplier`)**
- **Confirmare de primire** `CERTAIN`: `evidenceStatus = RECEIPT_RECEIVED`, `courtReceiptAt`; avocatul primește redirecționarea (originalul ca `message/rfc822`) doar dacă nu e deja în To/Cc, plus notificare; cardul propune data pentru „Stabilește data depunerii”; `filedAt` și numărul nu se schimbă automat. UI: „confirmare automată de primire de la instanță”, cu nota că înregistrarea se vede pe portal.just.ro. `PROBABLE` -> review de către avocat.
- **Confirmare de citire**: `readReceiptAt`, linie în cronologie.
- **Bounce permanent pentru instanță**: partea 1 -> depunerea `UNDELIVERED`, adresa `INVALID`, `anuleaza_depunere_nelivrata` dacă dosarul e în `CERERE_DEPUSA` (curăță `filingChannel` și `filingReference`, `filedAt` dacă fusese setat, intrare în istoric, `reopenFilingDeadline()`, notificare, fără email `cerere_depusa` repetat), pachetul `SENT` -> `SIGNED`; dosar avansat -> review. Pe o anexă -> `PARTIALLY_SENT` (3d2).
- **Bounce temporar**: `DelayStamp` din programul 3d2 cât `attemptCount < 3`, altfel exhausted; doar dacă a eșuat exclusiv instanța.
- **Bounce de dimensiune**: `FAILED_SIZE`, propunere de re-împărțire.
- **Bounce doar pentru CC**: notificare, depunerea neafectată.
- **Răspuns uman sau orice alt mesaj al instanței**: redirecționare imediată, notificare cu prioritate maximă, mențiunea „Adresa platformei nu este adresă de comunicare procedurală; răspundeți instanței de pe adresa proprie.”
- **Nepotrivit**: coada admin (doar metadate; accesul admin la conținut cere motiv și e auditat).
- `ReceiptOverdueChecker` (zilnic): `SENT` fără confirmare după o zi lucrătoare -> `RECEIPT_OVERDUE`, notificare cu link la încărcarea manuală.
- `InboundHeartbeatCheckCommand`: alertă admin dacă ultimul poll reușit e mai vechi de 2 ore.
- Încărcare manuală a confirmării: `.eml`, PDF, imagine (fără `.msg`), validare ca în 3c2, `matchMethod = MANUAL`, `evidenceStatus = RECEIPT_MANUAL`.
- `InboundPurgeCommand`: `UNKNOWN` și spam șterse după 30 de zile; restul conform regulii de retenție (întrebarea 8).

**Notificări noi:** `FILING_RECEIPT_RECEIVED`, `FILING_RECEIPT_OVERDUE`, `FILING_BOUNCED_PERMANENT`, `FILING_COURT_MESSAGE`.

**Simulator dev.** `app:filing:simulate-court-reply --submission=ID --kind=receipt|read|bounce-temporary|bounce-permanent|bounce-size|reply` trimite mesaje RFC 3464/8098 realiste prin SMTP în Mailpit, cu `In-Reply-To` și un `Authentication-Results` cu authserv-id-ul de dev.

**UI.** Cronologie: badge confirmare, `.eml` (doar avocatul dosarului), MDN, bounce cu diagnostic, propunerea de dată; încărcare manuală; EasyAdmin `InboundMailCrudController` cu metadate, filtre și „Leagă de depunere”; HTML-ul primit nu se randează niciodată.

**Traduceri.** `filing.inbound.*`, `notification.filing_receipt_*`, `notification.filing_court_message`, etichete clasificare, RO și EN.

**Teste**
- Corpus `.eml`: NDR Exchange 5.1.1, Postfix 5.1.1, 552 / 5.3.4, 4.4.7, DSN delayed, MDN, auto-reply Outlook, confirmare tip just.ro, auto-reply în română, răspuns uman, bounce non-RFC, spam, confirmare cu `Authentication-Results` falsificat (authserv-id străin). Se adaugă 2-3 confirmări reale de la avocat când există.
- TestCase: parsere, clasificator, `InboundAuthVerifier`, matcher (fiecare metodă și niciuna).
- KernelTestCase: ingestor idempotent; applier pe fiecare clasă (stări, adresă invalidă, tranziția T9 cu `reopenFilingDeadline` și fără email repetat, redirecționare doar când avocatul lipsește din To/Cc); end-to-end cu `FixtureInboundSource`: trimitere prin transportul fals, ingestie confirmare, `RECEIPT_RECEIVED`, redirecționare; `MailpitInboundSource` doar cu `MockHttpClient` pe JSON înregistrat; purge; heartbeat.
- WebTestCase: încărcare manuală (validări, `.msg` refuzat), acțiunea admin, accesul la `.eml` doar pentru avocatul dosarului.

**DONE**: pe dev, trimitere + `simulate-court-reply --kind=receipt` + `poll-inbound` -> cardul arată confirmarea, avocatul primește redirecționarea în Mailpit; `bounce-permanent` invalidează adresa, aplică T9 și redeschide termenul; `bounce-size` nu reîncearcă; nepotrivitele ajung în coadă; poll-ul de două ori nu dublează; adaptorul se schimbă prin env.

**Verificare live (Playwright)**: fiecare tip simulat în cronologie și în centrul de notificări; restart Mailpit și poll pe baza goală. Bounce-urile asincrone vin doar din simulator.

**Riscuri**: confirmări fără antete de threading (review); confirmări falsificate (doar MTA-ul nostru e de încredere, fără efect automat); bounce târziu după avansarea dosarului (review); ESP care suprascrie Return-Path (webhook la deploy); comunicări procedurale pe adresa platformei (redirecționare imediată, heartbeat).

---

## 5. Ce rămâne pentru deploy

- **Domeniu și căsuță**: furnizor SMTP care permite `MAIL FROM` propriu (VERP) sau webhook de bounce; căsuța `COURT_FILING_FROM` citită prin IMAP; furnizor în SEE sau garanții art. 44-46 GDPR [V].
- **SPF, DKIM, DMARC** pentru From și domeniul de bounce, aliniere verificată (`p=none` la început); TLS obligatoriu la transport, MTA-STS unde e posibil; MTA-ul de primire adaugă `Authentication-Results` cu authserv-id configurat.
- **Secrete** în Coolify: DSN-uri, credențiale IMAP.
- **Gărzi**: `COURT_FILING_LIVE=1` doar în prod, după poarta 1.5; override nesetat în prod; `app:filing:preflight` la deploy și în healthcheck.
- **Worker**: `messenger:consume court_filing` separat de `async`, reload după fiecare deploy.
- **Cron** (Faza 9.1): `poll-inbound` la 5 min, `receipt-overdue` zilnic, `inbound-heartbeat-check` la 30 min, `inbound-purge` zilnic, re-rulare periodică a importului de adrese.
- **Docker prod**: `qpdf` în imagine; versiunea Mailpit fixată.
- **GDPR și termeni**: DPA cu fiecare avocat/cabinet (art. 28) și cu furnizorii SMTP/IMAP/hosting; registrul art. 30 pentru „depunere prin email” și „director adrese instanțe”; termeni de utilizare cu împărțirea răspunderii (avocatul: conținut, semnare, alegerea și validarea adresei; platforma: funcționare, logare fidelă, retry conform politicii; fără promisiunea „depunere garantată”; limitările nu exclud culpa gravă sau intenția, art. 1355 alin. 2 CC [V]); date sintetice în dev și demo.
- **Opțional**: marcaj temporal calificat (eIDAS art. 41-42 [V]) pe sha256-ul `.eml` trimis.
- **Prima trimitere reală**: după poarta 1.5, către o instanță cu adresa validată, cu avocatul urmărind confirmarea.

---

## 6. Întrebări deschise pentru avocat

1. **Plasarea opisului (pct. 9)**: în PDF-ul cererii (implicit) sau separat, cu semnătură proprie?
2. **Contractul (pct. 12)**: blocant (`CONTRACT_BLOCKS_FILING`) sau doar avertisment când creanța rezultă din facturi acceptate?
3. **Textul emailului**: aprobarea corpului final, cu două variante pentru taxa de timbru: amânată („Taxa judiciară de timbru se va achita la indicarea instanței.”) și deja achitată („Dovada achitării taxei judiciare de timbru este inclusă în anexe.”), plus mențiunea că anexele urmează în emailuri separate.
4. **Registratura**: instanțele-țintă înregistrează o cerere nouă primită pe email și netimbrată? Confirmarea automată de primire e suficientă sau trebuie și confirmarea înregistrării? Au limită de dimensiune per căsuță?
5. **Admisibilitatea canalului**: acceptă fiecare instanță-țintă depunerea cererii pe email și pe ce adresă (art. 148-149 CPC [V])?
6. **Semnături**: cele 3 semnături (cerere, anexe, împuternicire) sunt suficiente? Împuternicirea necesită și semnătura clientului?
7. **Mențiunea „conform cu originalul”** pe pagina de gardă a volumelor de anexe: textul exact (art. 150 CPC [V]).
8. **Retenție**: termenul de arhivare al avocatului pentru `.eml`-uri, confirmări și pachetul semnat (minimum până la prescripția executării, art. 706 CPC [V]).

## 7. Confirmări cerute utilizatorului

| Decizie | Înainte de |
|---|---|
| T15: validarea avocatului promovează adresa; trimiterea la `PROPOSED` blocată; expirare 180 de zile | 3b |
| T14: `depune_cerere` la acceptarea părții 1, `PARTIALLY_SENT` | 3d1 |
| T16: subiect cu debitor PF scurtat | 3d1 |
| T9: tranziția `anuleaza_depunere_nelivrata` | 3e |
| T17: pachetele composer | 3e |

---

## 8. Riscuri

| Risc | Impact | Atenuare |
|---|---|---|
| Instanța nu acceptă cereri introductive pe email | Cerere neînregistrată | Poarta 1.5, validarea per adresă (T15), întrebările 4-5 |
| Portalul blochează importul (403) | 3b fără date | Pagini salvate, whitelisting, introducere manuală |
| Secție greșită la tribunal | Pierdere tăcută | Resolver null fără secție validată |
| Instanță necompetentă | Declinare (art. 129-133 CPC [V]), întârziere | Resolver re-rulat, linia de competență în modal, răspunderea avocatului |
| Depunere dublă după crash sau după eșec parțial | Două dosare, două taxe | `UNCERTAIN` rezolvat de avocat, `PARTIALLY_SENT` + reluare, `activeLock` |
| Acceptare SMTP confundată cu depunerea | Avocat indus în eroare | „Transmisă, fără confirmare”, `filedAt` stabilit de avocat |
| Comunicări procedurale pe adresa platformei | Citație ratată | Redirecționare imediată, prioritate maximă, heartbeat poll |
| Bounce permanent după `depune_cerere` | Stare falsă | `anuleaza_depunere_nelivrata`, `reopenFilingDeadline` |
| Dobânda înghețată în PDF-ul semnat | Sumă depășită la trimitere | Data de calcul explicită, avertisment la 30 de zile |
| Fișier semnat diferit de cel generat | Alt document trimis | Potrivire prefix / id + text cu confirmare, digest CMS |
| Semnătura prezentată ca „validă calificat” | Afirmație neîntemeiată | Doar „semnătură detectată”, text de răspundere |
| Dimensiune peste limita instanței | Respingere | Buget encodat, reverificare pe bytes semnați, re-împărțire doar a anexelor |
| Confirmări falsificate | Dovadă falsă | Doar `Authentication-Results` al MTA-ului nostru, fără efect automat |
| Trimitere reală din dev sau demo | Email real la instanță | `COURT_FILING_LIVE` opt-in, override obligatoriu altfel |
| Acces admin la conținut protejat | Secret profesional | Admin doar metadate; acces la conținut motivat și auditat |
| Date personale în subiect, pagini, `.eml` | GDPR | T16, `PERSONAL` ascunse, retenție, purge, date sintetice în dev |
| Cont fără legătură verificată cu un avocat real | Domeniul platformei trimite abuziv | Utilizator verificat, abonament, limite, avertisment CN, audit |
| Citări legale neverificate | Text greșit, respingere | [V] verificate pe legislatie.just.ro înainte de UI și email |

---

## 9. Note de implementare comune

- După orice modificare PHP/config/traduceri în Docker: `make restart` (și reload worker). După `composer require`: `make restart`.
- `php bin/console lint:yaml translations/` după fiecare editare a traducerilor; cheile RO/EN în paralel.
- Verificare live Playwright pe clone `TMP-LIVE-*` la fiecare secțiune.
- Nicio cerere externă (portal, SMTP real) fără aprobare explicită.

---

## 10. Decizii de revizie

Revizia 2 aplică critica tehnică și critica juridică din 2026-10-22. Acceptate integral: tehnic 1-10, 12-28, 30, 32-39; juridic 1, 4-15, 18-28. Acceptate parțial sau respinse:

- **Tehnic 11 (`PARTIALLY_SENT`, tranziția la partea 1)**: acceptat, dar ca decizie de confirmat (T14), pentru că ajustează decizia utilizatorului.
- **Tehnic 29 și juridic 11 (cine validează)**: combinate. Validarea avocatului promovează adresa (tehnic 29), dar printr-un act separat de trimitere, iar trimiterea la `PROPOSED` e blocată (juridic 11). De confirmat (T15).
- **Tehnic 31 vs juridic 1 (`acceptsNewFilings`)**: câmpul separat se amână (tehnic 31); condiția blocantă cerută de juridic 1 se obține prin bifa din actul de validare (T15). Nu se introduce un câmp cu trei stări.
- **Juridic 2 (`filedAt` provizoriu)**: acceptat mai strict decât s-a cerut: `filedAt` nu se setează deloc la transmitere, conform principiului deja existent în `CasePortalController` („`filedAt` is the lawyer's declaration”). T10 din revizia 1 se înlocuiește.
- **Juridic 3 (stare nouă `TRANSMISA_FARA_CONFIRMARE`)**: respinsă ca loc nou în workflow. Ar atinge cele 9 locuri, enum-urile, descoperirea pe portal (`findAwaitingPortalDiscovery` pe `CERERE_DEPUSA`) și toate testele de workflow. Același efect se obține cu `filedAt` null + `evidenceStatus` + etichetele „transmisă, fără confirmare”.
- **Juridic 16 (dobânda: `STALE` dacă data build-ului diferă de data trimiterii)**: respins în forma cerută, pentru că ar învechi orice pachet după o zi și ar cere re-semnare zilnică. Acceptată alternativa propusă de aceeași critică: data de calcul explicită în cerere, inclusă în fingerprint, plus avertisment în modal după 30 de zile.
- **Juridic 17 (excluderea dovezii taxei)**: înlocuit de tehnic 1. Dovada se include când taxa e `ACHITATA` și dovada există, pentru că șablonul cererii afirmă atunci „conform dovezii anexate”; se exclude la `AMANATA_REGULARIZARE`.
- **Tehnic 32 vs juridic 10 (CN și certificatul)**: CN-ul și valabilitatea certificatului rămân, dar doar ca avertismente (tehnic 21/32); se adaugă verificarea integrității digest-ului CMS, care blochează (juridic 10). Fără validarea lanțului.
- **Juridic 4 (marcaj temporal calificat)**: acceptat ca opțiune de deploy, nu în secțiunile 3b-3e (necesită un furnizor extern).
- **Juridic 20 (`REQUIRETLS`)**: acceptat ca TLS obligatoriu la transport și MTA-STS; extensia SMTP `REQUIRETLS` (RFC 8689) depinde de furnizor și se evaluează la deploy.
- **Juridic 25 (termeni de utilizare)**: acceptat ca livrabil de deploy (secțiunea 5), nu ca cod.
