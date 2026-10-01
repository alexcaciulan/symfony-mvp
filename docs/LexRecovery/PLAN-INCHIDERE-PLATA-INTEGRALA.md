# Plan final: închiderea dosarului la plata integrală înainte de cererea OP

Feedback avocat: „Ar trebui să pot marca plata amiabilă și înainte de generare cerere OP. Dacă plata este efectuată integral, dosarul se închide în etapa somației de plată cu status Finalizat.”

Varianta aprobată: închidere **doar la plata integrală**, **din AMIABIL și SOMATIE_TRIMISA**, cu statusul existent **INCHIS_SUCCES** („Închis cu succes”).

Afirmațiile disputate de reviewers au fost verificate în cod, în containerul `symfony-mvp-php`.

---

## 1. Rezumat și decizii

| # | Decizie | Justificare |
|---|---|---|
| D1 | **Tranziție dedicată `inchide_plata_integrala`**, cu `from: [AMIABIL, SOMATIE_TRIMISA]` și `to: INCHIS_SUCCES`. `inchide_succes` rămâne neatins. | `CloseReason::PARTIAL` se mapează pe `inchide_succes` (src/Enum/CloseReason.php:26-32). Dacă am extinde `from`, workflow-ul ar permite plata parțială din somație. Numele tranziției ajunge în CaseStatusHistory, în audit și în EmailNotificationSubscriber. Testele care interzic `inchide_succes` din AMIABIL rămân valabile (tests/Service/CaseWorkflowServiceTest.php, tests/Controller/Case/CaseTransitionControllerTest.php:822-836). |
| D2 | **Acțiune, formular și modal dedicate.** Nu refolosim `close()` și nici `CloseCaseType`. | `close()` are garda DEFINITIVA/EXECUTARE și răspunde cu redirect și la erori (CaseTransitionController.php:299-370, 552-562). `CloseCaseType` aduce PARTIAL și ABANDONED. |
| D3 | **Logica de business stă în serviciul nou `src/Service/Case/CaseFullPaymentClosureService.php`.** Serviciul face, într-o singură tranzacție: data plății, apply, **închiderea termenelor** și auditul. | Controller subțire. Închiderea termenelor direct în serviciu elimină dependența de ordinea listenerelor față de `CaseWorkflowSubscriber::onCompleted` și elimină catch-ul care ar înghiți erorile. Dacă ceva eșuează, tranzacția face rollback și dosarul nu rămâne închis cu termene deschise. |
| D4 | **Data plății e obligatorie și se persistă în coloana nouă `LegalCase.fullPaymentDate`** (`date_immutable`, nullable). | Se afișează în hero, pipeline și sidebar și va conta la raportări. |
| D5 | **Toate termenele necompletate ale dosarului se închid, cu audit**, inclusiv PRESCRIPTIE, RASPUNS_SOMATIE, DEPUNERE_CERERE și termenele OTHER (custom). | Fără pasul ăsta, `findIncomplete()` (src/Repository/LegalDeadlineRepository.php:54) continuă să alerteze în cron. Termenele OTHER se închid și ele: altfel cronul ar alerta pe un dosar read-only, unde avocatul nu le mai poate gestiona. `completedBy` = utilizatorul care închide, pentru că e o decizie a avocatului (vezi docblock-ul de la DeadlineService.php:540-546). |
| D6 | **Tranziția nu e oferită în admin change-status.** | Calea din admin (src/Controller/Admin/CaseStatusController.php:39) ar închide dosarul fără dată, bifă sau tranzacție. Cu D3, nici termenele nu s-ar închide. Așa se forțează trecerea prin serviciu. |
| D7 | **Fără filtru global pe statusurile terminale în `findIncomplete()`** în acest slice. | Ar schimba comportamentul pentru RESPINSA și pentru închiderile existente. Bug preexistent, vezi §6. |
| D8 | **Erorile se întorc prin Turbo Stream (toast) și modalul rămâne deschis. La succes: flash și redirect** la `case_overview`. | Respectă regula „erorile nu dau refresh” și evită capcana 422 + nonce CSP. Redirectul reîmprospătează toate regiunile. |
| D9 | **Butonul se randează doar dacă tranziția e posibilă**, adică în AMIABIL și SOMATIE_TRIMISA. În rest lipsește complet. | Regula „nu oferi acțiunea imposibilă”. |
| D10 | **Pipeline-ul arată stadiul din care s-a închis dosarul**, iar un status terminal nu se mai desenează „în mers”. RESPINSA primește o stare `failed` separată. | Azi INCHIS_SUCCES și RESPINSA sunt mapate fix pe stadiul 4 (`_pipeline.html.twig:9-24`). O regulă „terminal = done” ar desena o respingere ca „Definitivă” bifată verde. |
| D11 | **Bifa de confirmare cere stingerea întregii creanțe față de toți debitorii dosarului.** Când dosarul are mai mulți debitori, modalul arată explicit numărul lor. | Dosarul are până la 5 debitori. Închiderea e ireversibilă și stinge urmărirea prescripției pentru tot dosarul. Bifa trebuie să acopere situația nesolidarității, nu doar „debitorul a plătit”. Q7 rămâne deschisă, dar implementarea nu mai depinde de ea. |
| D12 | **Fără upload de dovadă a plății în acest slice.** Modalul spune explicit că dovada trebuie încărcată înainte de închidere. | Nu există un DocumentType potrivit: EXTRAS_CONT și DOVADA intră în opis/ZIP. UPLOAD e permis în AMIABIL și SOMATIE_TRIMISA, dar refuzat după închidere (EDITABLE_STATUSES). Q5 devine prioritară. |
| D13 | **Contorizarea abonamentului nu se schimbă.** | Din AMIABIL nu se consumă slot. Din SOMATIE_TRIMISA slotul e deja consumat. |
| D14 | **„Somat” înseamnă `paymentNoticeDate IS NOT NULL`**, nu `status <> AMIABIL`, în bibliotecile de creditori și debitori. | Un dosar închis din AMIABIL nu a somat pe nimeni. Altfel s-ar bloca greșit schimbarea CUI-ului debitorului (src/Service/Debtor/DebtorLibraryService.php:55-73) și creditorul ar fi marcat ca somat (src/Repository/CreditorRepository.php:116-127). |

---

## 2. Modificări pe fișiere, în ordinea implementării

### 2.1 Documentarea deciziei (decision-first)
- `docs/LexRecovery/lexrecovery-symfony-flows.md:394`: înlocuiește linia cu:
  - `AMIABIL --> INCHIS_SUCCES : inchide_plata_integrala`
  - `SOMATIE_TRIMISA --> INCHIS_SUCCES : inchide_plata_integrala`
  
  Adaugă un paragraf cu deciziile D1-D14.
- `docs/LexRecovery/ANALIZA-FLUXURI-LEXRECOVERY.md:282-317`: adaugă tranziția în diagrama de stări și în tabelul de tranziții.

### 2.2 Workflow și enum
- `config/packages/workflow.yaml`, după blocul `inchide_fara_recuperare` (liniile 78-80):
  ```yaml
  # Debtor paid the full claim before the OP request was generated.
  inchide_plata_integrala:
      from: [AMIABIL, SOMATIE_TRIMISA]
      to: INCHIS_SUCCES
  ```
- `src/Enum/CaseTransition.php`: adaugă `case INCHIDE_PLATA_INTEGRALA = 'inchide_plata_integrala';`.
- Traduceri `enum.case_transition.inchide_plata_integrala`:
  - RO, lângă `translations/messages.ro.yaml:2239-2254`: „Închide: plată integrală”;
  - EN: „Close: paid in full”.

### 2.3 Entitate și migrare
- `src/Entity/LegalCase.php`: adaugă `#[ORM\Column(type: 'date_immutable', nullable: true)] private ?\DateTimeImmutable $fullPaymentDate = null;` cu getter și setter.
- Generează migrarea cu `docker exec symfony-mvp-php php bin/console doctrine:migrations:diff`.
- **Citește SQL-ul din container înainte de `migrate`** (memoria `ops_migrations_diff_stale_file_in_container`). Migrarea trebuie să conțină doar `ALTER TABLE legal_case ADD full_payment_date DATE DEFAULT NULL` și operația inversă în `down()`.
- Pe dev: `doctrine:migrations:migrate -n`, apoi `doctrine:schema:validate`.
- **Pe baza de test:** `docker exec symfony-mvp-php sh -c 'APP_ENV=test php bin/console doctrine:migrations:migrate -n && APP_ENV=test php bin/console doctrine:schema:validate'`. Entrypoint-ul migrează DB-ul de test doar la pornirea containerului (docker-entrypoint.sh:130-132).

### 2.4 Termene (DeadlineService și repository)
- `src/Repository/LegalDeadlineRepository.php`: metodă nouă `findIncompleteByCase(LegalCase $case): array`, cu `completedAt IS NULL`, ordonată ASC după `deadlineDate`.
- `src/Service/Deadline/DeadlineService.php`:
  - Extrage din `closeDeadline()` (liniile 549-576) un helper privat:
    ```php
    closeDeadlineEntity(LegalDeadline $deadline, string $action, string $reason, array $extraAuditData = [], ?User $user = null): void
    ```
    Helper-ul face, în ordine: verificarea de idempotență, `markCompleted($user)`, flush și audit `CATEGORY_DEADLINE_COMPLETED`. În payload, `type` se ia din `$deadline->getType()->value`.
  - `closeDeadline()` păstrează semnătura (cu `$reason` obligatoriu, deja existent) și deleagă la helper după `findOneByCaseAndType`. Comportamentul rămâne identic, deci testele existente trebuie să rămână verzi.
  - Metodă publică nouă `closeAllOpenOnFullPayment(LegalCase $case, User $user): int`:
    - iterează `findIncompleteByCase()` și cheamă helper-ul cu action `deadline_completed` și reason `case_closed_full_payment`;
    - închide toate tipurile, inclusiv OTHER și PRESCRIPTIE;
    - întoarce numărul de termene închise.
- **Fără listener nou** în `DeadlineCreationSubscriber`, conform D3 și D6.

### 2.5 Gărzi contra scrierii de termene pe un dosar terminal
- `src/Security/Voter/CaseVoter.php:100-103`: `canManageDeadlines()` devine `owner && !$case->getStatus()->isTerminal()`.
- Înainte de modificare, rulează un grep pe rutele cu `CASE_DEADLINE_MANAGE` din `src/Controller/Case/CaseDeadlineController.php`: 75, 147, 186, 238 (`add`), 268, 325, 391, 462, 522 și 564.
  - Rulează testele lor existente. Dacă vreun test cere gestionarea termenelor pe INCHIS_* (de exemplu `reopenExecutionPrescription` sau `setEnforcementRegistrationNumber` după `inchide_fara_recuperare`), garda nu se pune în voter.
  - În acel caz, revino la gărzi punctuale prin `respondDeadline` cu toast `case_overview.termene.flash_error_case_closed`, pe rutele `add` (238), `setPaymentNoticeCommunicationDate` (391) și `edit` (462).
- Decizia se ia pe baza rezultatului testelor și se notează în doc.
- UI-ul ascunde acțiunile respective pe un dosar terminal (vezi 2.10).

### 2.6 Serviciul de închidere
Fișier nou: `src/Service/Case/CaseFullPaymentClosureService.php`, cu metoda:
```php
close(LegalCase $case, User $user, \DateTimeImmutable $paymentDate, ?string $amountReceived, ?string $details): void
```

**Dependențe:** `EntityManagerInterface`, `CaseWorkflowService`, `DeadlineService`, `AuditLogService`. `PiiMasker` se apelează static, ca la CaseTransitionController.php:350, deci nu se injectează.

**Pași, în `wrapInTransaction`:**
1. Garda `workflowService->can($case, 'inchide_plata_integrala')`; altfel aruncă `NotEnabledTransitionException` sau `DomainException`.
2. `fromStatus = $case->getStatus()`, apoi `setFullPaymentDate($paymentDate)`.
3. `apply('inchide_plata_integrala')`, apoi flush.
4. `deadlineService->closeAllOpenOnFullPayment($case, $user)`.
5. Audit `case_closed` cu `CATEGORY_CASE_CLOSED`. `newData`:
   - `{caseNumber, reason: 'PAID', fromStatus, transition, paymentDate, amountReceived, debtorCount, closedDeadlines, details}`;
   - `details` trece prin mascarea CNP.
6. Flush.

**Docblock:** `src/Service/AuditLogService.php:72-73` (`CATEGORY_CASE_CLOSED`) adaugă „inchide_plata_integrala from AMIABIL or SOMATIE_TRIMISA”.

**Excluderea din admin:** în `src/Controller/Admin/CaseStatusController.php:39`, scoate `CaseTransition::INCHIDE_PLATA_INTEGRALA->value` din `$availableTransitions`. Comentariu: „Requires payment date and confirmation; only through the case overview”. Excluderea acoperă și lista afișată, și validarea POST.

### 2.7 Formular
Fișier nou: `src/Form/Case/FullPaymentClosureType.php`, cu `data_class` null și CSRF id `full_payment_closure`.

| Câmp | Tip | Constrângeri |
|---|---|---|
| `paymentDate` | `DateType` (`widget: single_text`, `input: datetime_immutable`) | `NotNull`, `LessThanOrEqual(value: 'today')` |
| `amountReceived` | `MoneyType` (RON, `input: string`), opțional | `PositiveOrZero` |
| `confirmFullPayment` | `CheckboxType` | `IsTrue` |
| `details` | `TextareaType`, opțional | `Length(max: 500)` |

- **Textul bifei:** „Confirm că întreaga creanță din acest dosar a fost achitată integral: principal, dobânzi sau penalități până la data plății și cheltuielile somației. Nu mai rămâne nimic de recuperat de la niciunul dintre debitorii dosarului.”
- **Help pe `details`:** „Nu introduce date personale inutile (CNP, IBAN).”
- Toate constrângerile folosesc argumente numite.
- Mesajele de validare stau în `translations/validators.{ro,en}.yaml`, conform memoriei de la fluxul de înregistrare: erorile constrângerilor se traduc din domeniul `validators`. `CloseCaseType` ține cheia în `messages` (messages.ro.yaml:1249); nu o atingem acum.
- Fără limită inferioară pentru dată (vezi „Corecturi respinse”). Modalul afișează data somației, dacă există, ca reper vizual.

### 2.8 Controller și rută
În `src/Controller/Case/CaseTransitionController.php` adaugă acțiunea `closeOnFullPayment(Request, int $id)`, cu ruta `POST /case/{id}/transition/full-payment` și numele `case_transition_full_payment`. Ordinea:
1. `find` și 404.
2. `denyAccessUnlessGranted(CaseVoter::TRANSITION)`.
3. `createForm` și `handleRequest`.
4. Dacă formularul nu e trimis sau e invalid, inclusiv la CSRF greșit: eroare cu primul mesaj din `$form->getErrors(true)`.
5. Dacă `!workflowService->can($case, 'inchide_plata_integrala')`: eroare `flash_error_wrong_status`.
6. Serviciul, cu `$this->getUser()`. Excepția `NotEnabledTransitionException` se prinde și se tratează ca la pasul 5.
7. Flash `case_overview.transition.flash_success_full_payment` și redirect la `case_overview`.

**Răspunsul de eroare:**
- Partial nou `templates/case/overview/_toast_turbo_stream.html.twig`: un singur `<turbo-stream action="append" target="toast-container">` cu toast-ul de eroare. Tiparul de markup se copiază din `_termene_actions_turbo_stream.html.twig`, fără regiuni și fără `close_modal_id`, ca modalul să rămână deschis.
- Helper privat `respondFullPaymentError(Request, LegalCase, string $message)`:
  - dacă `Accept` conține `text/vnd.turbo-stream.html`, răspunde cu stream-ul de mai sus, status 200;
  - altfel face flash error și redirect.
- Formularul din modal **nu** are `data-turbo-frame="_top"`, ca Turbo Drive să trimită `Accept` cu turbo-stream pe POST. La succes, Turbo urmează redirectul 303.
  - **Verificare live obligatorie:** dacă formularul e într-un frame, urmarea redirectului poate da „Content missing” (memoria revizie fluxuri 2026-08-14). În acest caz, adaugă `data-turbo-frame="_top"` și verifică explicit header-ul în Playwright.

`respondAfterTransition` rămâne neschimbat; se corectează doar docblock-ul învechit de la liniile 547-551.

### 2.9 Notificări
`src/EventSubscriber/EmailNotificationSubscriber.php:124-128` (`onInchisSucces`):
- Dacă `$event->getTransition()?->getName() === 'inchide_plata_integrala'`, folosește place-key-ul `inchis_plata_integrala`, după modelul de la liniile 71 și 85.
- Cele cinci chei construite la liniile 289-318 (title, message, subject, heading, body) se adaugă în RO și EN:
  - RO: „Dosarul %case% a fost închis: creanța a fost achitată integral înainte de cererea de ordonanță.”
  - EN: „Case %case% was closed: the claim was paid in full before the payment order request.”

### 2.10 Contextul overview și templates

**`src/Service/Case/OverviewContextBuilder.php`**
- Termenul activ: dacă `$case->getStatus()->isTerminal()`, nu mai apelează `pickActiveDeadline()` și pune `null`. E o modificare reală la apelant; funcția de la 124-137 nu primește dosarul.
- `can_close_full_payment` = `workflowService->can($case, 'inchide_plata_integrala')`.
- `closed_from_status`: `oldStatus` din prima intrare `statusHistory` cu `newStatus` INCHIS_* sau RESPINSA. Istoricul e ordonat DESC (LegalCase.php:382), iar statusurile terminale nu au ieșiri, deci există o singură astfel de intrare. Pentru alte statusuri sau fără istoric: `null`.
- `debtor_count` = `count($case->getDebtors())`.
- Toate cheile se pun exclusiv în `build()`, iar template-urile folosesc `|default`. Motiv: `_detalii_sidebar` și `_pipeline` se re-randează și din stream-urile din CaseSummonsController:168, CasePaymentOrderController:218, CasePortalController:289, CaseStampDutyController:305 și CaseDeadlineController:634. Verifică faptul că toate folosesc `build()`.

**`templates/case/overview/_recommended_actions.html.twig`**
- Liniile 73-85: butonul activ, cu `data-hs-overlay="#hs-modal-full-payment"`, apare doar dacă `can_close_full_payment`.
- Linia 5: `summons_done` devine `case.paymentNoticeDate is not null`. `summons_date|date` se afișează doar dacă valoarea nu e null; azi un null ar da data curentă.
- Pe INCHIS_SUCCES cu `fullPaymentDate`: card final „Dosar închis: plată integrală la dd.mm.yyyy”. Fără dată (date vechi): textul fără dată.

**Fișier nou `templates/case/overview/_modal_full_payment.html.twig`**, id `hs-modal-full-payment`, pe scheletul `_modal_set_summons_communication_date.html.twig`. Conține:
- form spre `case_transition_full_payment`;
- input `date` cu `max` = azi și data somației ca reper;
- suma încasată (opțional), bifa, textarea;
- dacă `debtor_count > 1`: avertisment „Dosarul are %n% debitori. Închide doar dacă nu mai ai nimic de recuperat de la niciunul.”;
- pe SOMATIE_TRIMISA: „Dacă dobânzile, penalitățile sau cheltuielile nu au fost achitate, nu închide dosarul: ele pot fi cerute prin ordonanța de plată.”;
- „Încarcă dovada plății în tab-ul Documente înainte de închidere. După închidere dosarul devine doar pentru citire.”;
- avertismentul de ireversibilitate: „Dosarul se închide definitiv cu statusul Închis cu succes. Toate termenele deschise, inclusiv prescripția, se închid.”;
- buton verde.

Modalul se include în `templates/case/overview.html.twig` după `_modal_close_case` (linia 65), condiționat de `can_close_full_payment`.

**`templates/case/overview/_pipeline.html.twig`**
- Dacă statusul e terminal și `closed_from_status` există, `current_stage` = `stage_map[closed_from_status]`. Fallback, când `closed_from_status` e null: stadiul 4.
- Stări:
  - stadiile `<= current_stage`: `done`;
  - pe INCHIS_SUCCES și INCHIS_FARA_RECUPERARE, stadiile ulterioare: `skipped` (gri, „nu a mai fost necesar”);
  - pe RESPINSA, stadiul curent: `failed` (roșu, fără bifă).
- Niciun status terminal nu mai primește puls sau „în mers”.
- Antet terminal: `case_overview.pipeline.closed_at_stage` („Închis la stadiul %current% din 5: %label%”). Bara de progres pe terminal: 100%, neutră.
- În bucla `history_dates` (liniile 39-45), ignoră `newStatus` INCHIS_* și RESPINSA.
- Eticheta stadiului de închidere: „Închis: plată integrală · dd.mm.yyyy”, dacă `fullPaymentDate` e setat.

**Alte template-uri**
- `templates/case/overview/_zip_package_card.html.twig`, ramura `else` (60-66): CTA-ul „Generează pachet” apare doar dacă statusul nu e terminal. Ramura de download (`elseif payment_order_doc`) rămâne neatinsă, iar comportamentul din AMIABIL, SOMATIE_TRIMISA, CERERE_GENERATA și CERERE_DEPUSA nu se schimbă.
- `templates/case/overview/_tab_documente.html.twig:17-19`: ascunde `_communication_warning` pe status terminal.
- `templates/case/overview/_documents_generated_list.html.twig`: pe status terminal fără document, rândurile Somație (else de la 56-66) și Cerere OP afișează „Nu a fost necesar” în loc de „lipsă”.
- Tab-ul Termene și blocul Somație: butoanele care deschid modalul de dată a comunicării, termen custom și editare termen se ascund pe status terminal.
- `_hero.html.twig`: chip „Plată integrală · dd.mm.yyyy” lângă badge, dacă `fullPaymentDate` e setat.
- Opțional: `templates/components/PipelineStatus.html.twig` e mort (singura referință e un comentariu). Rămâne neatins în acest slice și se notează în doc.

### 2.11 Biblioteci creditori și debitori (D14)
- `src/Repository/CreditorRepository.php:116-127`: în `hasSummonedCase`, `lc.status <> :amicable` devine `lc.paymentNoticeDate IS NOT NULL`. Docblock-ul se actualizează.
- `src/Service/Debtor/DebtorLibraryService.php:64-73`: în `hasSummonedCase`, condiția devine `$link->getLegalCase()->getPaymentNoticeDate() !== null`.
- **Atenție:** pentru dosarele existente trecute de somație, verifică în DB-ul de dev că `paymentNoticeDate` e setat:
  ```sql
  SELECT status, COUNT(*) FROM legal_case WHERE status <> 'AMIABIL' AND payment_notice_date IS NULL GROUP BY status
  ```
  Dacă există rânduri (seed, date vechi), se păstrează și condiția veche pentru statusurile non-terminale:
  ```sql
  (paymentNoticeDate IS NOT NULL OR status NOT IN (AMIABIL, INCHIS_SUCCES))
  ```
  Decizia se ia pe date și se notează.

### 2.12 Traduceri (RO și EN în paralel, fără em-dash)
**Domeniul `messages`:**
- `case_overview.modal.full_payment_{title, intro, field_date_label, field_date_reference, field_amount_label, field_confirm_label, field_details_label, field_details_help, warning_irreversible, warning_multi_debtor, warning_accessories, warning_proof, cancel, submit}`
- `case_overview.transition.flash_success_full_payment`
- `case_overview.termene.flash_error_case_closed`
- `case_overview.pipeline.{closed_at_stage, stage_skipped_label, stage_failed_label, closed_full_payment_label}`
- `case_overview.recommended_actions.case_closed_full_payment` și varianta `_no_date`
- `case_overview.documents.not_needed`
- `notification.case_status.inchis_plata_integrala.{title,message}`
- `email.case_status.inchis_plata_integrala.{subject,heading,body}`

**Domeniul `validators`:** `full_payment.date_required`, `full_payment.date_future`, `full_payment.confirm_required`, `full_payment.details_max`, `full_payment.amount_positive`.

**Ce se șterge și ce rămâne:**
- Șterge `tooltip.coming_soon_mark_paid` (messages.ro.yaml:865 și messages.en.yaml:865), după un grep care confirmă că nu mai e folosit.
- Păstrează `recommended_actions.mark_paid` („Marchează plată amiabilă”).

Rulează `php bin/console lint:yaml translations/` și un grep `—` pe diff.

### 2.13 Build și reload
- `make tailwind` pentru clasele stărilor `skipped` și `failed`.
- `docker exec symfony-mvp-php sh -c 'php bin/console cache:clear && kill -USR2 1'` (memoria `ops_php_fpm_reload_without_restart`).
- Repornește worker-ul Messenger (`docker compose restart worker`), care ține containerul DI vechi (memoria `ops_worker_cache_race_stuck_mail`).

### 2.14 Seed demo
Nu se modifică. `tests/Command/SeedDemoCasesCommandTest.php` cere exact 5 dosare.

---

## 3. Efecte laterale și tratarea lor

| Efect | Tratare |
|---|---|
| Termene și cron | Toate termenele deschise se închid în tranzacția serviciului (2.4, 2.6). Gărzile din 2.5 împiedică recrearea lor. Termenul activ e null pe dosarele terminale. |
| Notificări | `entered.INCHIS_SUCCES` declanșează deja notificarea in-app și email-ul; noua tranziție folosește texte dedicate (2.9). Worker-ul se repornește (2.13). |
| Monitorizare portal | Fără acțiune: AMIABIL și SOMATIE_TRIMISA nu sunt monitorizate. |
| Istoric și audit | `CaseWorkflowSubscriber::onCompleted` scrie CaseStatusHistory, persistat la flush-ul din serviciu. Symfony desparte tranziția cu mai multe `from`, deci `oldStatus` e corect. Serviciul scrie `case_closed` cu `fromStatus` explicit. |
| Blocaje somație și BlockedCaseAlert | Ies automat, pentru că filtrează pe `IN (AMIABIL, SOMATIE_TRIMISA)`. |
| Agendă, dashboard, KPI, tabel dosare, EasyAdmin | Exclud deja terminalele (`isTerminal()`, `CaseStatus::cases()`). Verificat: LegalCaseRepository:96-240, LegalCaseTableDefinition:49-51, LegalCaseCrudController:126,153. |
| Biblioteci creditori și debitori | Corectat prin D14 (2.11). |
| Abonament | Fără cod nou. Factura `case_extra` pending: Q8. |
| Admin change-status | Tranziția e exclusă (D6). Celelalte tranziții rămân neschimbate. |
| Voter | După închidere, EDIT și UPLOAD sunt refuzate, iar DEADLINE_MANAGE e refuzat de garda din 2.5. |
| Statistici | INCHIS_SUCCES include de acum și închideri înainte de instanță; se disting prin `fullPaymentDate` sau prin `fromStatus` din audit. |

---

## 4. Teste

**TestCase / Enum**
- `tests/Enum/CaseTransitionTest.php:12`: numărul de tranziții trece de la 15 la 16; aserție `from('inchide_plata_integrala')`.
- `EnumLabelKeysExistTest` acoperă doar `enum.case_transition.*`.

**Test nou de traduceri** (`tests/Translation/FullPaymentTranslationKeysTest.php`, KernelTestCase)
- Verifică în RO și EN cele 5 chei `notification.*` și `email.*` pentru `inchis_plata_integrala`, cheile `validators` `full_payment.*` și cheile de modal.
- Verifică și că nicio valoare nu conține `—`.

**KernelTestCase / Workflow** (`tests/Service/CaseWorkflowServiceTest.php`)
- `testInitialMarkingIsAmiabil` (69-74): adaugă `sort()` și așteaptă `['inchide_plata_integrala', 'trimite_somatie']`. Fără sort, ordinea din YAML ar fi inversă.
- **Test nou** `testGetAvailableTransitionsFromSomatieTrimisa`, cu `sort()`, așteaptă `['genereaza_cerere', 'inchide_plata_integrala']`.
- `validTransitionsProvider`: două intrări noi (AMIABIL și SOMATIE_TRIMISA spre INCHIS_SUCCES).
- `testFullPaymentClosureIsNotEnabledFromLaterStates`: data provider pe CERERE_GENERATA, CERERE_DEPUSA, DOSAR_INREGISTRAT și statusurile ulterioare, inclusiv terminalele.
- `testInchideSuccesStaysDisabledFromAmiabilAndSomatie`.

**KernelTestCase / Serviciu și termene**
- `tests/Service/Case/CaseFullPaymentClosureServiceTest.php`:
  - `testCloseSetsPaymentDateStatusAndAudit`
  - `testCloseFromSomatieTrimisaRecordsFromStatus`
  - `testCloseClosesAllOpenDeadlinesIncludingCustomAndPrescription`
  - `testCloseRecordsStatusHistoryAfterFlush`
  - `testCloseFromCerereGenerataThrowsAndChangesNothing`
  - `testCloseRollsBackWhenDeadlineClosingFails`: un mock pe DeadlineService aruncă excepția; statusul rămâne AMIABIL.
- `tests/Service/Deadline/DeadlineServiceTest.php`:
  - `testCloseAllOpenOnFullPaymentClosesEveryOpenDeadline` (PRESCRIPTIE, RASPUNS_SOMATIE, DEPUNERE_CERERE, două OTHER; audit cu reason și `completedBy`);
  - `testCloseAllOpenOnFullPaymentIsIdempotent`;
  - testele existente pe `closeDeadline` rămân verzi după extragerea helper-ului.
- `tests/Repository/LegalDeadlineRepositoryTest.php`: `testFindIncompleteByCaseReturnsOnlyOpen`.
- `tests/Service/Deadline/DeadlineAlertServiceTest.php`: `testCaseClosedOnFullPaymentProducesNoAlerts`.
- `tests/EventSubscriber/EmailNotificationSubscriberTest.php`: `testFullPaymentClosureUsesDedicatedNotificationKeys`.
- `EmailNotificationSubscriberIntegrationTest`: `testFullPaymentClosurePersistsSingleNotification`.
- Bibliotecă:
  - `tests/Repository/CreditorRepositoryTest.php`: `testCaseClosedFromAmiabilDoesNotCountAsSummoned`;
  - `tests/Service/Debtor/DebtorLibraryServiceTest.php`: `testCuiChangeAllowedWhenOnlyCaseClosedFromAmiabil`;
  - testele existente pe dosarele somate rămân verzi.

**KernelTestCase / Formular** (`tests/Form/Case/FullPaymentClosureTypeTest.php`, director nou)
- `testPaymentDateIsRequired`
- `testFuturePaymentDateIsRejected`
- `testFullPaymentConfirmationIsRequired`
- `testDetailsMax500`
- `testNegativeAmountIsRejected`

**WebTestCase / Controller** (`tests/Controller/Case/CaseTransitionControllerTest.php`)
- `testFullPaymentFromAmiabilClosesCaseAsInchisSucces`: verifică statusul, `fullPaymentDate`, un singur audit `case_closed` (transition, reason PAID, fromStatus AMIABIL, paymentDate), CaseStatusHistory și termenele închise.
- `testFullPaymentFromSomatieTrimisaClosesCase`
- `testFullPaymentRejectedFromOtherStatuses`: data provider pe CERERE_GENERATA, DEFINITIVA și INCHIS_SUCCES; fără schimbare de status și fără audit.
- `testFullPaymentWithoutConfirmationKeepsStatus`
- `testFullPaymentRejectsFutureDate`
- `testFullPaymentErrorReturnsTurboStreamToastWithoutRedirect`: cu `HTTP_ACCEPT: text/vnd.turbo-stream.html`; așteaptă 200, content-type turbo-stream, fără `Location` și fără `hs-modal-full-payment` închis.
- `testFullPaymentErrorWithoutTurboRedirectsWithFlash`
- `testFullPaymentCsrfMissingRejected`
- `voterDenialCases` (878): intrarea `full-payment`, cu 403 cross-user.
- `testFullPaymentRedirectsWithSingleFlash`
- CSRF-ul se citește cu `csrfForForm` din HTML-ul overview-ului randat în AMIABIL și SOMATIE_TRIMISA.

**WebTestCase / Admin**
- `testAdminChangeStatusDoesNotOfferFullPaymentTransition`: GET fără opțiune; POST cu tranziția respinsă.

**WebTestCase / Termene** (`tests/Controller/Case/CaseDeadlineControllerTest.php`)
- `testSummonsCommunicationDateRejectedOnClosedCase`
- `testAddDeadlineRejectedOnClosedCase`
- `testEditDeadlineRejectedOnClosedCase`
- Testele existente pe INCHIS_* (dacă există) arată dacă garda poate sta în voter (2.5).

**WebTestCase / Overview** (`tests/Controller/Case/CaseOverviewControllerTest.php`)
- Butonul și modalul de plată integrală:
  - `testFullPaymentButtonAndModalPresentOnAmiabil`
  - `testFullPaymentButtonAndModalPresentOnSomatieTrimisa`
  - `testFullPaymentButtonAbsentOnCerereGenerataAndTerminal`
  - `testFullPaymentModalShowsMultiDebtorWarning`
- Pipeline:
  - `testPipelineForCaseClosedFromSomatieShowsSkippedStages`: verifică că nu apare „în mers” și nici „Stadiu 5 din 5”;
  - `testPipelineTerminalStageIsNotActive`
  - `testPipelineRespinsaShowsFailedNotDone`
  - `testPipelineClosedWithoutHistoryFallsBackToStage5`, pe tiparul de la linia 400, unde statusul e setat fără istoric.
- Alte regiuni pe dosarul închis:
  - `testZipGenerateCtaAbsentOnClosedCase`
  - `testZipDownloadStillShownOnCerereGenerata`
  - `testActiveDeadlineHiddenOnClosedCase`
  - `testGeneratedDocumentsShowNotNeededOnClosedCase`
- Stream după somație:
  - `testSummonsGenerationStreamContainsFullPaymentButton`, cu `Accept` turbo-stream.
- Teste existente care trebuie să rămână verzi: `testModalCloseCaseReasonSelectHas4Options` (906) și `testCloseRejectsWrongStatus` (822).

**Rulare:** `docker exec symfony-mvp-php ./bin/phpunit`. Suita are `failOnDeprecation`, `failOnNotice` și `failOnWarning`.

---

## 5. Verificare live în browser (Playwright)

1. Login ca `avocat@test.com` (memoria `ops_dev_test_user_password`).
2. Pe un dosar AMIABIL:
   - butonul e activ și modalul se deschide;
   - fără bifă: apare toast-ul de eroare, pagina nu se reîncarcă, modalul rămâne deschis cu datele completate (verifică în Network header-ul `Accept` turbo-stream);
   - cu o dată din viitor: toast de eroare;
   - trimitere validă: flash afișat o singură dată, badge „Închis cu succes” fără puls, pipeline oprit la Amiabil cu stadiile ulterioare „nu a mai fost necesar”, card final în sidebar, fără termen activ, fără CTA „Generează pachet”, rândurile Somație și Cerere cu „Nu a fost necesar”;
   - fără „Content missing”.
3. Pe un dosar SOMATIE_TRIMISA cu data comunicării setată și cu un termen custom:
   - închide dosarul;
   - în tab-ul Termene, toate termenele apar închise;
   - în Audit apar `case_closed` și `deadline_completed`;
   - acțiunile de termen lipsesc;
   - în clopoțel apare notificarea dedicată, iar în Mailpit (localhost:8025) apare email-ul.
4. Pe un dosar cu doi debitori: apare avertismentul de debitori multipli.
5. Pe un dosar CERERE_GENERATA: butonul lipsește, iar CTA-ul ZIP sau link-ul de download e neschimbat.
6. Pe un dosar DEFINITIVA: „Închide dosar” funcționează ca înainte, iar pipeline-ul arată stadiul 5 „done” fără „în mers”. Pe RESPINSA: stadiul e roșu, fără bifă.
7. În admin, la change-status pe un dosar AMIABIL: tranziția nu apare.
8. Debitorul unui dosar închis din AMIABIL: schimbarea CUI-ului din bibliotecă nu e refuzată.
9. `app:check-deadlines`: dosarele închise nu generează alerte.
10. În consola browserului: zero erori CSP.

---

## 6. Riscuri și ce NU facem acum

**Riscuri**
- **Ireversibilitate.** INCHIS_SUCCES nu are ieșiri, iar termenele se închid. Atenuare:
  - bifa formulată pe stingerea întregii creanțe față de toți debitorii;
  - avertismente (debitori multipli, accesorii, dovadă);
  - audit complet, cu `fromStatus`, sumă și termenele închise, pentru o reparare manuală.
- **„Integral” e definit provizoriu** în textul bifei. Avocatul îl confirmă la Q4; schimbarea e doar de text.
- **Migrare.** Risc de fișier stale în container, plus migrarea DB-ului de test (2.3).
- **Garda din voter (2.5)** poate atinge fluxuri post-închidere existente. Decizia se ia pe baza testelor.
- **Pipeline.** Regula terminală schimbă afișarea și pentru DEFINITIVA, EXECUTARE și RESPINSA. E intenționat și se verifică live.
- **D14** depinde de existența `paymentNoticeDate` pe dosarele vechi trecute de somație. Se verifică în date (2.11).
- **Ruta de submit Turbo** (frame sau `_top`) se confirmă live (2.8).

**Ce NU facem acum**
- Nu redenumim INCHIS_SUCCES în „Finalizat”.
- Nu tratăm plata parțială: nici PARTIAL, nici entitate Payment, nici imputație.
- Nu permitem închiderea din CERERE_GENERATA.
- Nu adăugăm upload de dovadă și nici un DocumentType nou.
- Nu adăugăm filtru global pe terminale în `findIncomplete()`. Bug preexistent de semnalat separat: PRESCRIPTIE și PRESCRIPTIE_EXECUTARE rămân deschise după închiderile din DEFINITIVA/EXECUTARE și pe RESPINSA.
- Nu adăugăm redeschiderea dosarului.
- Nu adăugăm intrare în dropdown-ul din hero și nici scenariu în seed.
- Nu atingem refactorul lui `close()` și nici domeniul de traducere al `CloseCaseType`.
- Nu ștergem componenta `PipelineStatus` (cod mort, notat).
- Commit doar după confirmarea explicită a userului, apoi `make demo-sync` și `demo.sh restart php worker`.

---

## 7. Întrebări pentru avocat

**Amânate prin varianta aprobată**
1. Redenumirea „Închis cu succes” în „Finalizat”: doar pentru închiderea la somație sau pentru toate închiderile cu succes?
2. Plata parțială în faza somației: dosarul continuă cu cererea OP pentru rest, se închide cu un status separat, sau ambele variante?
3. Închiderea din CERERE_GENERATA (cererea generată, dar nedepusă) la plata integrală?

**Descoperite în analiză (Q4, Q5 și Q7 sunt prioritare)**

4. Textul bifei definește „integral” ca principal, dobânzi sau penalități până la data plății și cheltuielile somației. E corect? Suma încasată trebuie să devină obligatorie?
5. Dovada plății: obligatorie, opțională sau deloc? Ca tip separat de document, exclus din opis/ZIP?
6. Termenele închise la plată (inclusiv prescripția) trebuie să apară în tab-ul Termene cu mențiunea „închis: debit achitat”?
7. La mai mulți debitori: bifa cere stingerea față de toți. Este nevoie de închidere per debitor (de exemplu, unul plătește, iar dosarul continuă contra celorlalți dacă nu sunt solidari)? Se leagă de întrebarea 7 din SOMATIE-V2. De reverificat art. 1443 și următoarele NCC pe legislatie.just.ro.
8. Factura de depășire (`case_extra`) a unui dosar închis la somație rămâne datorată? (Întrebare de produs.)
9. Este nevoie de o cale de redeschidere (plată returnată, CEC refuzat), în afară de intervenția administratorului?
10. Eticheta acțiunii: „Marchează plată amiabilă” sau „Debitorul a achitat integral”?
11. Data plății poate fi anterioară somației sau scadenței? O validăm sau doar o afișăm ca reper?

---

## Ce s-a schimbat față de draft

- **Termene:**
  - închiderea lor s-a mutat din listener în serviciu, în aceeași tranzacție, cu rollback la eroare (D3);
  - helper nou `closeDeadlineEntity` și query nou `findIncompleteByCase`; draftul presupunea că `closeDeadline()` poate itera, deși caută un singur termen după tip;
  - se închid și termenele OTHER, iar `completedBy` = utilizatorul care închide.
- **Admin:** tranziția e exclusă din admin change-status (D6).
- **Bifa:** reformulată pentru toți debitorii și pentru accesorii plus cheltuieli. Au apărut avertismentele pentru debitori multipli, pentru accesorii în SOMATIE_TRIMISA și pentru dovadă, plus câmpul opțional cu suma încasată.
- **Gărzi pe termene:** extinse la toate rutele de scriere (preferabil în voter, decizie pe baza testelor). Numele corect al acțiunii este `add`, nu `createCustomDeadline`.
- **Teste de workflow:** `sort()` în `testInitialMarkingIsAmiabil`; testul pentru SOMATIE_TRIMISA e nou, nu o modificare.
- **Bibliotecile de creditori și debitori:** „somat” se definește pe `paymentNoticeDate` (D14).
- **Pipeline:** antet și bară pentru terminal, starea `failed` pentru RESPINSA, fallback fără istoric.
- **Termenul activ:** modificare reală la apelant, nu o „plasă de siguranță” deja existentă.
- **Turbo Stream de eroare:** partial dedicat `_toast_turbo_stream.html.twig`; verificarea header-ului `Accept` e explicită.
- **Notificări:** test dedicat pentru cheile `notification.*` și `email.*`.
- **Alte template-uri:** tab-ul Documente afișează „Nu a fost necesar”. CTA-ul ZIP se condiționează pe „non-terminal”, nu pe „doar SOMATIE_TRIMISA”, ca să nu dispară în alte statusuri.
- **Mediu și dependențe:** migrarea pe DB-ul de test; repornirea worker-ului; `PiiMasker` static, nu injectat.

## Corecturi respinse și de ce

- **Validare de limită inferioară pentru data plății** (juridic și completitudine): respinsă ca regulă dură. O plată anterioară somației sau chiar creării dosarului este plauzibilă, iar avertismentul „non-blocant” ar cere JS sau un flux în doi pași, disproporționat pentru slice. Data somației se afișează ca reper, iar întrebarea a devenit Q11.
- **Închidere per debitor** (juridic, varianta b): respinsă pentru acest slice. Cere un model nou de stare per debitor. Bifa pe „toți debitorii” și avertismentul acoperă riscul, iar întrebarea rămâne Q7.
- **Listener pe `completed.inchide_plata_integrala` păstrat pentru calea admin** (tehnic): inutil, pentru că tranziția e scoasă din admin.
- **Mutarea mesajelor `CloseCaseType` în `validators`** (tehnic, nota 2): în afara scopului. Formularul nou urmează convenția corectă (`validators`), iar cel vechi rămâne neatins.
- **Restrângerea CTA-ului ZIP doar la SOMATIE_TRIMISA** (din draft): înlocuită cu „non-terminal”, ca să nu schimbe comportamentul existent în AMIABIL.
- **Citarea art. 2540 NCC** (juridic, notă): nu apare în cod și nici în textele UI din plan, deci nu cere nicio acțiune; s-a scos din justificări.