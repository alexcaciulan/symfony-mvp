# Analiză: pagina globală de Termene

Document de decizie, scris înainte de implementare. Toate afirmațiile despre cod sunt verificate în sursă și marcate cu `fișier:linie`. Ce nu am putut verifica este marcat explicit ca ipoteză sau ca decizie deschisă.

Stare la data analizei: nu există rută globală de termene, nici template, nici metodă de repository care să listeze termenele unui utilizator cu filtre și paginare. Intrarea „Termene” din sidebar este un `<span aria-disabled="true">` cu tooltip `layout.sidebar.coming_soon` (`templates/_sidebar.html.twig:70-75`).

---

## 1. Întrebarea de fond: are sens o pagină dedicată de Termene?

**Da, dar condiționat.** Condiția este una singură și e verificabilă: **fiecare rând trebuie să aibă un verb executabil pe loc**. Dacă rândul rămâne un link către dosar, pagina nu merită construită.

### De ce condiționat

Termenele apar azi în trei suprafețe, toate pasive:

| Suprafață | Ce acoperă | Ce nu poate |
| --- | --- | --- |
| KPI-uri dashboard (`src/Controller/DashboardController.php:26-30`) | două numere: `countUpcomingByUser(7)` și `countOverdueByUser()` | nu listează restanțele, doar le numără |
| Widget `DeadlineList` (`templates/components/DeadlineList.html.twig:1-71`) | maxim 10 termene la 7 zile, link către dosar | nicio acțiune pe rând, niciun orizont peste 7 zile |
| Tab „Termene” din dosar (`templates/case/overview/_tab_termene.html.twig:1-25`) | carduri complete, calendar 30 de zile, complete/edit/delete | un singur dosar |

Dacă pagina globală ar fi o a patra listă de citire, suprapunerea cu widgetul din dashboard este practic totală (aceleași date, aceeași acțiune finală: click către dosar). În acel scenariu decizia corectă este să extindem widgetul `DeadlineList` cu o fereastră configurabilă și un link „vezi toate”, și să lăsăm intrarea din sidebar dezactivată.

### Ce rămâne exclusiv paginii globale

Cinci lucruri pe care nici dashboardul, nici tabul din dosar nu le pot avea:

1. **Agregarea cross-dosar.** Un avocat cu 80 de dosare nu poate deschide 80 de taburi ca să afle ce are mâine.
2. **Restanțele ca listă.** Azi există doar ca număr (`countOverdueByUser`). Un număr nu se poate lucra.
3. **Orizontul peste 7 zile.** Widgetul se oprește la 7 zile; prescripțiile și timbrările la 20 de zile nu sunt vizibile nicăieri.
4. **Zona de blocaje: termene fatale care nu pot fi calculate.** Dosare în care lipsește faptul generator (somație generată fără `paymentNoticeCommunicationDate`, ordonanță comunicată fără `rulingCommunicationDate`, cerere depusă fără data înștiințării de timbrare). Codul deja loghează aceste situații (`src/EventSubscriber/DeadlineCreationSubscriber.php:59,96,182`), dar avocatul nu le vede nicăieri. O agendă, prin definiție, nu poate spune ce lipsește din ea.
5. **Acțiunea inline cross-dosar.** Bifă, amânare, setarea datei de comunicare, fără să intri în dosar.

### Riscul de suprapunere, tratat explicit

- **Față de dashboard:** dashboardul rămâne ecran de stare (KPI plus preview). Pagina Termene devine ecran de lucru. Ca să nu existe două adevăruri, KPI-urile „Termene urgente” și „Restante” devin link-uri către pagină cu filtrul preaplicat, iar definiția contoarelor se unifică într-o singură metodă de repository (vezi P1, secțiunea 7).
- **Față de tabul din dosar:** tabul răspunde la „ce termene are dosarul acesta” și rămâne așa. Pagina răspunde la „ce am eu de făcut azi, indiferent de dosar”. Suprapunerea legitimă este doar setul de acțiuni, care trebuie să lovească **aceleași rute** (`case_deadline_complete`, `_edit`, `_delete`, `_ruling_date`, `_summons_communication_date`), ca autorizarea prin `CaseVoter` și auditul să rămână într-un singur loc.
- **Față de `/notifications`:** regulă fermă. În Notificări intră **ce s-a întâmplat** (alertele 7/3/1/expirat sunt deja rânduri de notificare). În Termene intră **ce trebuie făcut**. Dacă pagina listează evenimente de alertă în loc de obiecte de lucru cu stare, avocatul are două inboxuri care spun același lucru și le ignoră pe amândouă.

### Metrica de control

Densitatea de acțiune: acțiuni pe vizită. Sub aproximativ 0,5 acțiuni pe vizită, pagina a devenit un meniu, iar decizia corectă este să o retragem și să extindem widgetul existent. Cine măsoară și când se ia decizia rămâne o întrebare deschisă (secțiunea 8).

---

## 2. Ce deservește pagina: jobs-to-be-done pe momente ale zilei

### 08:00 - 09:30, înainte de plecare la instanță

Fereastra reală este de 60 până la 90 de secunde.

1. **„Ce mă poate decădea azi?”** Nu „ce termene am”. Un TIMBRARE ratat înseamnă anularea cererii (OUG 80/2013 art. 33 alin. 2); un memento propriu nu înseamnă nimic. Pagina trebuie să deschidă direct pe Restante și Azi, fără nicio interacțiune.
2. **„Unde trebuie să fiu fizic azi?”** Termenele de tip JUDECATA trebuie să arate instanța și numărul de dosar de la instanță, nu numărul intern. Atenție: modelul **nu are oră** (`deadlineDate` este `DATE_IMMUTABLE`, `src/Entity/LegalDeadline.php:27-28`; `CourtPortalEvent.eventDate` este DATE). Pagina nu poate răspunde la „la ce oră”, deci trimite onest către portal.
3. **„Ce e blocat pe mine, nu pe instanță?”** Termenele RASPUNS_SOMATIE calculate estimativ, care așteaptă data reală a comunicării, sunt muncă a avocatului, nu așteptare.

### 12:00 - 15:00, între ședințe, pe telefon sau laptop în hol

1. **Amânarea.** După un termen de judecată, acțiunea dominantă nu este „completat”, ci „amânat la data X”. Azi asta cere două operațiuni în două ecrane: adaugi termen nou și marchezi vechiul completat. O singură acțiune este cel mai concret câștig al paginii.
2. **Am primit AR-ul.** Setarea datei reale de comunicare a somației. Fluxul există (`recalculatePaymentNoticeDeadline`, `src/Service/Deadline/DeadlineService.php:110-143`), dar e la trei clicuri într-un modal din pagina dosarului. Adus în față, deblochează admisibilitatea cererii de ordonanță (CPC art. 1015-1016), deci are valoare juridică, nu doar de confort.
3. **Captură rapidă.** Un termen aflat la ghișeu trebuie notat în 10 secunde, fără navigare în dosar.

### 17:00 - 19:00, închiderea zilei

1. **„Am rămas cu ceva nemarcat pe azi?”** Pagina trebuie să poată ajunge vizibil la zero pe blocul Azi. Fără o stare de zero credibilă, avocatul nu are cum să încheie ziua cu ecranul acesta.
2. **Pregătirea pentru mâine.** Ce trebuie printat sau depus.

### Vineri seara, revizia de risc

Termene depășite nemarcate, dosare fără dată de comunicare, prescripții sub 90 de zile. Acesta este singurul job care justifică un mod tabel cu filtre; restul se rezolvă în agendă.

---

## 3. Context tehnic actual

### 3.1 Modelul de date

`LegalDeadline` are exact 13 coloane persistate (`src/Entity/LegalDeadline.php:15-62`):

`id`, `legalCase` (ManyToOne, NOT NULL), `type` (`DeadlineType`, VARCHAR 30), `deadlineDate` (`DATE_IMMUTABLE`), `description` (VARCHAR 255, nullable), `priority` (`DeadlinePriority`, default MEDIUM), `completed` (bool), `completedAt`, `completedBy` (User, ON DELETE SET NULL), `alertSent7`, `alertSent3`, `alertSent1`, `alertSentExpired`, `createdAt`, `updatedAt`.

Metode derivate, fără coloană în DB (`:182-232`): `isOverdue()`, `getDaysRemaining()`, `markCompleted()` (idempotent, nu rescrie `completedAt` la a doua apelare), `resetAlertFlags()`.

**Ce NU există în model și deci nu se poate afișa fără migrare:**

| Lipsă | Consecință pentru pagină |
| --- | --- |
| câmp de proveniență (auto / portal / manual) | nu putem eticheta sursa termenului; deducția din tip e fragilă |
| responsabil (assignee) | nu există „termenul meu vs al colegului” (tenancy de cabinet e amânat) |
| oră | nu se poate afișa ora unei ședințe, nici detecta conflicte reale |
| stare intermediară dincolo de `completed` | „amânat” și „în așteptarea comunicării” nu pot fi stări reale |
| atașament sau dovadă | bifa de finalizare este o declarație, nu o probă |
| notă liberă peste 255 caractere | textele juridice lungi nu încap pe rând |

### 3.2 Cine creează termene

Patru surse, complet enumerate:

1. `DeadlineCreationSubscriber` pe `workflow.entered.SOMATIE_TRIMISA`, `.ORDONANTA_EMISA`, `.DEFINITIVA`, `.EXECUTARE` și pe Doctrine `postPersist` pe `LegalCase` pentru prescripție per scadență (`src/EventSubscriber/DeadlineCreationSubscriber.php:43-192`).
2. `MonitoringEventApplier` pentru JUDECATA din portal (`src/Service/Portal/MonitoringEventApplier.php:117`).
3. `StampDutyService::recordCourtNotice` pentru TIMBRARE (`src/Service/StampDuty/StampDutyService.php:133`).
4. `CaseDeadlineController` pentru OTHER manual, CERERE_IN_ANULARE la introducerea datei comunicării hotărârii și recalculul RASPUNS_SOMATIE (`src/Controller/Case/CaseDeadlineController.php:106,157,208`).

Prioritatea **nu** este introdusă de utilizator: se setează automat din `DeadlineType::defaultPriority()` (`src/Enum/DeadlineType.php:31-43`, aplicată în `src/Service/Deadline/DeadlineService.php:393`). RASPUNS_SOMATIE și DEPUNERE_CERERE sunt HIGH; JUDECATA și OTHER sunt MEDIUM; CERERE_IN_ANULARE, TIMBRARE, PRESCRIPTIE, PRESCRIPTIE_EXECUTARE sunt CRITICAL. Consecință de design: **prioritatea nu aduce informație independentă față de tip**, deci nu poate fi axa principală de ordonare.

Formularele manuale cer doar data și descrierea; tipul e forțat pe OTHER în `createCustomDeadline()` (`src/Service/Deadline/DeadlineService.php:341-351`). `AddDeadlineType` impune dată în viitor, `EditDeadlineType` acceptă și date în trecut (corecție).

**`DeadlineType::DEPUNERE_CERERE` există în enum și are traduceri RO și EN, dar nu e creat de niciun serviciu** (`src/Enum/DeadlineType.php:10`, fără call-site de creare în `src/`). Este un tip mort.

### 3.3 Idempotență și erori tăcute

Idempotența este la nivel de query, nu de constrângere DB: `findOneByCaseAndType()` pentru termenele unice pe dosar, `findOneByCaseTypeAndDate()` pentru JUDECATA și PRESCRIPTIE (`src/Repository/LegalDeadlineRepository.php:53-83`). Tabela nu are niciun index UNIQUE, deci două rulări concurente pot duplica.

Toate excepțiile din crearea automată sunt prinse și doar logate, niciodată propagate, ca să nu rupă flush-ul sau tranziția (`src/EventSubscriber/DeadlineCreationSubscriber.php:72-77,105-110,158-165,186-191`). Un termen poate lipsi tăcut, iar UI-ul nu are cum să semnaleze asta astăzi. Aceasta este justificarea directă a zonei de blocaje din propunere.

### 3.4 Interogări existente și ce le lipsește

`src/Repository/LegalDeadlineRepository.php`:

- `findByCase(case)` (`:24`)
- `findIncomplete()` (`:41`), global, exclude soft-deleted, folosit de cron
- `findOneByCaseAndType()` (`:53`), `findOneByCaseTypeAndDate()` (`:71`)
- `findUpcomingByUser(user, days=30, limit=null)` (`:86`)
- `countUpcomingByUser(user, days=7)` (`:111`)
- `countOverdueByUser(user)` (`:132`)

**Defect de definiție confirmat.** `findUpcomingByUser` și `countUpcomingByUser` filtrează doar `d.deadlineDate <= :cutoff`, fără limită inferioară, deci includ și termenele deja expirate; în plus **nu** exclud dosarele în stare terminală. `countOverdueByUser` le exclude explicit (`lc.status NOT IN (:terminal)`, `:145`). Cele două KPI-uri de pe același ecran de dashboard folosesc definiții diferite și se suprapun. Pagina nouă face inconsistența vizibilă.

Nu există filtrare pe tip, prioritate, instanță, debitor sau interval, nici paginare, nici sortare configurabilă. Toate query-urile sortează fix `deadlineDate ASC`. Niciunul nu face fetch join, deci o listă globală ar genera N+1 la afișarea numărului de dosar, instanței și debitorului.

### 3.5 Indexuri

`legal_deadline` are exact două indexuri, ambele pe chei străine:

- `IDX_1D8A033382B4A9B (legal_case_id)` (`migrations/Version20260508102445.php:33`)
- `IDX_1D8A033385ECDE76 (completed_by_id)` (`migrations/Version20260515130649.php:32`)

Nu există index pe `deadline_date`, `completed` sau `type`. Orice filtru global pe interval de dată scanează tabela.

### 3.6 Alerte și cron

`DeadlineAlertService` rulează pe toate termenele necompletate (`findIncomplete`, nu per utilizator), cu praguri cumulative `<= 7`, `<= 3`, `<= 1` și `< 0` zile; cel mult o alertă per termen per rulare, dedup exclusiv prin flagurile booleene (`src/Service/Deadline/DeadlineAlertService.php:27-66`). `EmailNotificationSubscriber::onDeadlineAlert()` transformă evenimentul în `NotificationDispatch` de tip `DEADLINE_ALERT`, cu grup `prescriptie` sau `procedural` și variantă `error` când e expirat sau mai are cel mult o zi. Notificarea nu primește `dedupKey` și nu are legătură cu `LegalDeadline`: linkul duce la pagina dosarului.

Cronul `app:check-deadlines` rulează `processAlerts()` plus `CaseAutoFinalizer::process()`. **Nu e pornit din container**, ci înregistrat ca job extern la 07:00 (`docker-entrypoint.sh:143-146`). Consecință onestă pentru UI: dacă jobul nu rulează, flagurile rămân false, iar pagina devine mecanismul primar de avertizare, nu al doilea.

**Bug confirmat, de corectat odată cu pagina:** `recalculatePaymentNoticeDeadline()` mută data și șterge `description`, dar **nu apelează `resetAlertFlags()`** (`src/Service/Deadline/DeadlineService.php:110-143`), spre deosebire de editarea manuală (`src/Controller/Case/CaseDeadlineController.php:251-253`) și de recalculul TIMBRARE (`src/Service/Deadline/DeadlineService.php:180-182`). Mutarea termenului în viitor suprimă remindere deja marcate ca trimise.

### 3.7 Acțiuni existente și formatul răspunsului

Rute (`src/Controller/Case/CaseDeadlineController.php`): `case_deadline_complete` (`:43`), `case_deadline_add` (`:85`), `case_deadline_ruling_date` (`:115`), `case_deadline_summons_communication_date` (`:163`), `case_deadline_edit` (`:213`), `case_deadline_delete` (`:273`). Toate POST cu CSRF, voter `CASE_DEADLINE_MANAGE`, ownership-only, fără restricție de status. Fiecare operațiune scrie audit cu categorie dedicată (`CATEGORY_DEADLINE_CREATED/EDITED/COMPLETED/DELETED`).

**Corectură importantă față de presupunerea comună.** `complete()` **nu** randează `_termene_actions_turbo_stream.html.twig`, ci `case/overview/_deadline_card_turbo_stream.html.twig` (`:71`), care face replace pe `deadline-card-{id}` incluzând cardul mare cu gradient din dosar. Celelalte acțiuni trec prin `respondDeadline()` (`:308-332`) și țintesc `panel-termene`, `ruling-communication-alert`, `case-tabs-nav`, `case-kpi-grid`, `case-detalii-sidebar`.

Consecință: pe pagina globală, niciunul dintre aceste streamuri nu găsește țintele. Iar reutilizarea id-ului `deadline-card-{id}` ca scurtătură ar injecta cardul de dosar în lista densă. Ramura de context este obligatorie, nu opțională.

### 3.8 Infrastructura de listare reutilizabilă

Toate listele (Dosare, Creditori, Notificări, Facturi) folosesc `<twig:DataTable key="...">` peste Tabulator, cu filtre `search`, `enum` (Tom Select), `autocomplete` (remote, min 2 caractere) și `date_range`, sortare și paginare remote. Un tabel nou cere doar o clasă care implementează `TableDefinitionInterface` (`key`, `getColumns`, `getFilters`, `createScopedQueryBuilder` cu alias `t`, `serializeRow`).

**Limită confirmată în cod:** `TableDataService::applyCriteria()` construiește toate condițiile și ordonarea exclusiv pe prefixul `t.` (`src/Service/Table/TableDataService.php:55-110`). Filtrele pe instanță, creditor și status dosar trăiesc pe `legalCase`, deci cer suport pentru căi cu punct rezolvate pe aliasul deja unit. Este o extensie mică într-un motor generic, nu o rescriere, dar trebuie bugetată.

Alte limite: `selectableRows` e hardcodat `false` în `assets/controllers/tabulator_controller.js:64`; clasa `Filter` nu are niciun mecanism de persistare, deci „vederi salvate” este feature nou.

### 3.9 Sistem vizual

Tokeni: `--color-lex-navy #1E3A5F` plus variantele dark și light, trei umbre (`soft`, `card`, `hero`), Inter, dark mode pe clasa `.dark` (`assets/styles/app.css:32-57`). Utilitare proprii: `.tnum`, `.soft-pulse`, `.ring-fill`, `.nav-active`. Preline UI pentru modale, taburi și dropdownuri.

`DeadlinePriority::color()` întoarce exact tokeni din paleta `StatusBadge`: LOW slate, MEDIUM sky, HIGH amber, CRITICAL red (`src/Enum/DeadlinePriority.php:18-25`). Badge-ul de prioritate se poate face fără cod nou.

Shell autentificat real: `aside` fix `w-64` ascuns sub `lg`, apoi `div.lg:pl-64.min-h-screen.flex.flex-col.flex-1`, topbar sticky `h-14` cu backdrop-blur, `main.flex-1.px-4.lg:px-8.py-6`, body `bg-slate-50 dark:bg-slate-950`.

Componenta `templates/components/DeadlineCard.html.twig` (paletă gray divergentă) nu are nicio referință în `templates/`. Se șterge odată cu pagina, ca să nu fie refolosită din reflex.

---

## 4. Context juridic

### 4.1 Cele opt tipuri, natura și consecința ratării

| Tip | Temei | Natură | Titular | Consecința ratării | Ireversibil |
| --- | --- | --- | --- | --- | --- |
| RASPUNS_SOMATIE | CPC art. 1015 alin. 1, 15 zile de la primire | termen de așteptare | debitorul | niciuna în sarcina creditorului; **riscul e invers**: depunerea prematură atrage respingerea cererii | nu |
| DEPUNERE_CERERE | fără temei în cod | reminder operațional | avocatul | niciuna | nu |
| JUDECATA | data fixată de instanță, preluată din portal | evidență | instanța | pierderea șansei de a răspunde apărărilor; procedura se judecă și în lipsă, iar neprezentarea niciunei părți poate atrage suspendarea (CPC art. 411 alin. 1 pct. 2). **Nu** există decădere din probe: sancțiunea din CPC art. 254 alin. 1 lovește nepropunerea probelor prin cerere sau întâmpinare, nu neprezentarea la termen | nu |
| CERERE_IN_ANULARE | CPC art. 1024 alin. 1, 10 zile de la comunicare | procedural, decădere (CPC art. 185 alin. 1) | **dublu**, vezi mai jos | decădere din calea de atac | **da** (repunere doar CPC art. 186) |
| TIMBRARE | OUG 80/2013 art. 33 alin. 2, 10 zile | procedural imperativ | avocatul | **anularea cererii prin încheiere** | **da**, cu remediu limitat (reexaminare în 15 zile) |
| PRESCRIPTIE | NCC art. 2517, 3 ani de la scadență | drept material | avocatul | stingerea dreptului material la acțiune | **da** |
| PRESCRIPTIE_EXECUTARE | CPC art. 706 alin. 1, 3 ani | drept material | avocatul | pierderea dreptului de a executa **și** a puterii executorii a titlului | **da** |
| OTHER | fără temei | organizare internă | avocatul | niciuna | nu |

Constantele sunt confirmate în cod: `PAYMENT_NOTICE_DAYS = 15`, `APPEAL_DAYS = 10`, `STAMP_DUTY_DAYS = 10`, `PRESCRIPTION_INTERVAL = '+3 years'`, `EXECUTION_PRESCRIPTION_INTERVAL = '+3 years'` (`src/Service/Deadline/DeadlineService.php:25-29`).

### 4.2 Ce trebuie separat vizual

**Prioritatea din enum nu poate fi criteriul de separare.** Ea este derivată mecanic din tip și nu exprimă natura juridică. Un memento personal căruia avocatul îi pune CRITICAL arată identic cu o timbrare la două zile.

Trei distincții obligatorii pe rând:

1. **Natura juridică**, ca badge: anularea cererii / decădere / stingerea dreptului / fără sancțiune / evidență. Este o proprietate a legii, nu a utilizatorului.
2. **Certitudinea datei**: CERT versus ESTIMAT. Se derivă azi din câmpuri reale, fără migrare: RASPUNS_SOMATIE este CERT doar când `paymentNoticeCommunicationDate` nu e null; PRESCRIPTIE_EXECUTARE este CERT doar când `rulingCommunicationDate` nu e null; restul sunt CERT.
3. **Titularul**: termen de ACȚIONAT versus termen de AȘTEPTAT. Aceeași culoare roșie pe ambele antrenează reflexul greșit, pentru că la termenele de așteptat acțiunea înainte de scadență **este** eroarea.

**CERERE_IN_ANULARE are două semnificații opuse pe care sistemul nu le distinge.** Când titularul e debitorul, expirarea este favorabilă clientului nostru (ordonanța rămâne definitivă) și termenul este de așteptat. Când cererea a fost admisă doar în parte, calea de atac aparține creditorului (CPC art. 1024 alin. 2), iar ratarea este ireversibilă în defavoarea clientului. În cod, tipul primește uniform CRITICAL.

**Termenul expirat al debitorului nu este o restanță a avocatului.** Expirarea lui RASPUNS_SOMATIE este evenimentul care deblochează depunerea cererii de ordonanță (CPC art. 1015-1016), deci nu intră nici în secțiunea roșie de restanțe, nici în contorul de restanțe, nici în badge-ul din sidebar. Mockup-ul îl scoate într-un bloc distinct, „Deblocate: se poate depune cererea de ordonanță”, cu acțiunea primară „Generează cererea de OP”. Aceeași regulă pentru cererea în anulare al cărei titular este debitorul.

**Prescripția nu se invocă din oficiu** (NCC art. 2512-2513), ci numai de cel interesat, prin întâmpinare sau cel mai târziu la primul termen. Afișajul nu trebuie să declare creanța pierdută, ci să semnaleze riscul și să lase decizia avocatului.

### 4.3 Capcane de afișare identificate în cod

| # | Defect | Locație | Efect pe pagină |
| --- | --- | --- | --- |
| 1 | `isOverdue()` compară `deadlineDate <= now`, iar `deadlineDate` este la 00:00, deci în chiar ziua împlinirii termenul apare deja ratat, deși CPC art. 182 alin. 1 îl duce până la ora 24:00 | `src/Entity/LegalDeadline.php:215-217` | avocatul poate crede că a pierdut un termen pe care îl mai are o zi întreagă |
| 2 | Calculul adună zile calendaristice direct (`+15`, `+10`) fără regula CPC art. 181 alin. 1 pct. 2 (nu se socotește nici ziua de început, nici ziua împlinirii), în timp ce derivarea definitivării folosește `+11` zile pentru același termen de 10 zile | `DeadlineService.php:71,97,152,176` vs `DeadlineCreationSubscriber.php:155` | aplicația se contrazice singură; la RASPUNS_SOMATIE, o zi în minus poate împinge la depunere prematură |
| 3 | JUDECATA trece prin `nextWorkingDay()` deși data ședinței e fixată de instanță și preluată din portal | `DeadlineService.php:318-335` | pagina poate afișa ședința în altă zi decât cea reală |
| 4 | PRESCRIPTIE_EXECUTARE se derivă din `rulingCommunicationDate + 11 zile`, iar când comunicarea lipsește se folosește **ziua curentă**, care e întotdeauna ulterioară momentului real | `DeadlineCreationSubscriber.php:148-160` | termen afișat **mai lung** decât cel real, adică siguranță falsă; cel mai periculos tip de eroare pe o pagină de termene |
| 5 | Disclaimerul juridic de la RASPUNS_SOMATIE trăiește în `description`, câmp refolosit pentru descrieri libere și pentru textul de prescripție, și e șters la recalculare | `DeadlineCreationSubscriber.php:35`, `DeadlineService.php:121,244` | avertismentul juridic poate dispărea singur |
| 6 | Prorogarea la prima zi lucrătoare se poate declanșa din zile nelucrătoare configurabile, fără explicație pe rând | `src/Service/Deadline/WorkingDayResolver.php:26-40,133-138` | avocatul nu poate verifica un calcul pentru care răspunde personal |
| 7 | Termenele de sursă legală se pot șterge | `CaseDeadlineController.php:273` | produsul facilitează pierderea evidenței |
| 8 | `markCompleted()` înregistrează cine și când, dar bifa este o declarație, nu o probă | `DeadlineService.php:353-379` | pentru termenele fatale, finalizarea nedovedită nu apără avocatul |
| 9 | Ora împlinirii se enunță simplificat ca „24:00 pentru orice act”, deși CPC art. 182 alin. 2 duce actul depus **la instanță** doar până la închiderea registraturii; numai ruta poștă sau curier rapid salvează ziua, prin CPC art. 183 alin. 1 | text de ecran | pe timbrare, unde dovada se depune la dosar, textul poate produce chiar anularea pe care pagina pretinde că o previne |
| 10 | Trimiterea pentru timbrare diverge: docblock-ul spune „CPC art. 200 alin. 2 teza I”, mockup-ul spunea „art. 200 alin. 3”, iar niciun template PDF sau fișier de traducere nu citează CPC art. 200 | `DeadlineService.php:166` | ecranul, codul și actul depus nu spun același lucru; numerotarea CPC este oricum marcată ca disputată în proiect |
| 11 | Alertele nu au moment: `LegalDeadline` reține patru booleeni (`alertSent7/3/1/Expired`), `Notification` are `createdAt` dar se leagă de `LegalCase`, nu de termen, iar dispecerizarea nu primește `dedupKey` | `src/Entity/LegalDeadline.php:47-56` | orice „trimisă la DD.MM.YYYY, ora HH:MM” pe ecran este inventat; se pot afișa doar punctele și datele programate, calculate din data termenului |

Prorogarea se aplică corect doar la RASPUNS_SOMATIE, CERERE_IN_ANULARE, TIMBRARE și JUDECATA. PRESCRIPTIE, PRESCRIPTIE_EXECUTARE și OTHER păstrează data exactă, pentru că sunt termene de drept material sau fără efect juridic. Excepția problematică este JUDECATA (defectul 3).

### 4.4 Lacună de fond: cele 6 luni din NCC art. 2540

Dacă somația nu este urmată de cererea de chemare în judecată în 6 luni, întreruperea prescripției se consideră că nu a avut loc (NCC art. 2540, la care trimite expres CPC art. 1015 alin. 2). Acest termen **nu există nicăieri în sistem**, deși a fost semnalat în analiza juridică. Este singurul candidat cu temei real pentru tipul mort DEPUNERE_CERERE.

Consecința directă pentru afișaj: cât timp comunicarea somației întrerupe prescripția, un rând PRESCRIPTIE calculat mecanic ca scadență plus 3 ani **nu poate fi marcat CERT** pe un dosar cu somație comunicată. În mockup, aceste rânduri sunt ESTIMAT, cu linia 3 care spune de la ce dată curge din nou termenul și până când se menține întreruperea, iar zona de blocaje primește un rând dedicat pentru dosarele cu somație comunicată și fără cerere depusă la 6 luni.

### 4.5 Ce trebuie afișat lângă un termen ca să fie utilizabil juridic

În ordinea deciziei reale a avocatului:

1. **Ce trebuie făcut**, formulat ca act, nu ca etichetă de sistem („Timbrare 200 lei”, nu „TIMBRARE”).
2. Data limită cu ziua săptămânii și zilele rămase.
3. **Partea adversă** (denumire plus CUI scurt). Avocatul își ține dosarele minte după parte, nu după număr. Niciodată `personalId` (CNP).
4. Numărul de dosar în format portal (`courtCaseNumber`), cu marcaj explicit când e doar numărul intern al platformei.
5. Instanța.
6. **Principalul în RON.** Verificat în cod: `LegalCase::setAmount()` primește `principalRon` din `ClaimTotalsService::recalculate()` (`src/Service/Case/ClaimTotalsService.php:85,128`), deci `amount` **este** principalul, nu totalul cu dobânzi. Corect de afișat lângă termen, pentru că pragul de competență se raportează la principal (CPC art. 98 alin. 2).
7. **Baza de calcul**, formulată verificabil: „10 zile de la comunicarea din 12.03.2026”. Fără ea, avocatul deschide oricum dosarul, deci pagina nu economisește nimic.
8. **Temeiul**, citat identic cu documentele generate. O divergență între ecran și actul depus este în sine un risc.
9. **Consecința ratării**, text scurt și fix per tip. Este singura informație care permite prioritizarea corectă între două termene care cad în aceeași zi.

---

## 5. Cele trei arhetipuri evaluate

### 5.1 Agendă / inbox de triaj

Grupare temporală (Restante, Azi, Mâine, Restul săptămânii, Următoarele 30 de zile), rânduri dense cu acțiune inline.

Puncte tari: zero configurare, se deschide util din prima; scalează de la 5 la 500 de termene; permite acțiune inline; oferă o stare de zero care funcționează ca dovadă de diligență.

Puncte slabe: nu răspunde la planificarea lunii; trebuie apărat de prescripțiile la 3 ani, care altfel curg la coada listei; gruparea pe dată contrazice parțial cerința de separare pe natură juridică.

### 5.2 Calendar lunar plus panou de zi

Grilă de zile cu cipuri, panou lateral pentru ziua selectată.

Puncte tari: spațialitate (se vede aglomerarea unei săptămâni); familiar.

Puncte slabe, decisive: **modelul nu are oră** pe niciun câmp de dată, deci calendarul promite o agendă orară pe care nu o poate onora și nu poate detecta conflictul real de două ședințe în aceeași zi la instanțe diferite. Restanțele stau structural în afara ecranului (dispar la navigarea în luna următoare). Prescripțiile la 3 ani sunt invizibile până în luna în care e prea târziu. Densitatea este mai mică, nu mai mare: o celulă la 1280px încape aproximativ 3 cipuri, restul devine „+7”. Nu poate afișa ce nu are dată, adică exact cazul cel mai periculos. Costul de implementare este cel mai mare, iar conceptul are nevoie oricum de un mod listă obligatoriu.

### 5.3 Tabel dens de control al riscului

Tabulator cu sortare implicită pe gravitatea consecinței, filtre bogate, export.

Puncte tari: infrastructura există aproape integral; excelent pentru interogări ad-hoc; sortarea pe gravitate se obține gratuit printr-un `CASE WHEN ... END AS HIDDEN riskRank` în query builder.

Puncte slabe: nu are ritm zilnic (se deschide identic azi și mâine); nu are noțiunea de „am terminat cu ziua de azi”; acțiunea contextuală dominantă după o ședință („amânat la data X”) încape prost într-un rând de tabel; cere extinderea `TableDataService` pentru căi cu punct.

### 5.4 Tabel de comparație

| Criteriu | Agendă | Calendar | Tabel dens |
| --- | --- | --- | --- |
| Răspunde la „ce mă poate decădea azi” | **da**, structural | parțial | parțial (prin sortare) |
| Acțiune inline pe rând | **da**, natural | da, în panou | dificil |
| Restanțe vizibile mereu | **da**, secțiune proprie | nu, cer un raft lipit | da, prin filtru |
| Termene fără dată (blocaje) | da, secțiune | **nu**, prin definiție | da, registru secundar |
| Prescripții pe orizont lung | da, rail separat | **nu**, invizibile | da, prin filtru |
| Interogare ad-hoc pe portofoliu | nu | nu | **da** |
| Stare de zero la final de zi | **da** | slabă | slabă |
| Densitate informațională | bună | **slabă** | foarte bună |
| Respectă lipsa orei din model | da | **nu**, promite ce nu poate | da |
| Cost de implementare | mediu | **mare** | mic plus extindere motor |
| Suprapunere cu ce există | mică | mare (Outlook) | medie (dashboard) |

### 5.5 Decizie

**Agenda de triaj câștigă**, cu două grefe din tabelul dens (ordonarea pe gravitatea consecinței în interiorul grupurilor temporale, plus modul Registru construit pe `TableDefinitionInterface` ca vedere secundară) și o grefă din calendar (feed `.ics`). **Calendarul lunar se respinge integral**, nici măcar ca mod secundar: are nevoie de trei proteze (raft de restanțe fixat, raft de orizont lung, mod listă obligatoriu) care reconstruiesc agenda peste el.

De ce hibrid și nu agendă pură: sortarea cronologică pură în interiorul unui grup este exact ce face Outlook, iar un memento OTHER ar sta deasupra unei timbrări fatale. De ce nu structurare primară pe natura juridică (cele trei secțiuni cerute de analiza juridică): o prescripție la 2 ani ar sta deasupra unei timbrări la 2 zile, ceea ce e greșit pentru jobul de dimineață. **Compromisul: data grupează, gravitatea ordonează, natura juridică se citește din badge.** Sortarea pură pe gravitate rămâne disponibilă în Registru.

---

## 6. Propunerea reținută: „Agenda de termene”

**Teza.** Un singur ecran de lucru care răspunde la „ce fac azi, în toate dosarele”, grupat temporal, unde fiecare rând are o acțiune executabilă pe loc și afișează consecința juridică a ratării, ca avocatul să nu mai deschidă dosarul ca să afle ce are de făcut.

### ZONA 0, shell

Identic cu Dosare și Facturi, fără invenții: `aside` fix `w-64` ascuns sub `lg`, `div.lg:pl-64.min-h-screen.flex.flex-col.flex-1`, header sticky `h-14` cu backdrop-blur, `main.flex-1.px-4.lg:px-8.py-6`, body `bg-slate-50 dark:bg-slate-950`.

În sidebar, span-ul `aria-disabled` de la `_sidebar.html.twig:70-75` devine link cu patternul `nav-active` plus bara verticală `w-0.5` la stânga. Badge numeric la dreapta etichetei, alimentat **exclusiv din contorul de restanțe** (roșu `bg-red-100 text-red-700`), nu din cel de 7 zile, ca numărul să aibă o singură semantică și să scadă vizibil când avocatul lucrează.

Aceeași intrare se adaugă în drawerul mobil din `templates/_topbar.html.twig:100-120`, care azi conține doar Dashboard, Setări și Admin.

Breadcrumbul din topbar afișează „Termene” ca element curent, prin `block breadcrumb`.

### ZONA 1, antet de pagină

`div flex md:flex-row md:items-end md:justify-between gap-3`.

Stânga: `h1` „Termene” `text-2xl md:text-3xl font-bold tracking-tight`, sub el `p.text-sm.text-slate-600` cu fereastra declarată explicit. Text exact: „Termenele din toate dosarele tale active, până la 24.08.2026. Alertele pe email rulează zilnic la 07:00.”

Dreapta, `self-start`, în ordine: (a) comutator segmentat de două poziții „Agendă | Registru”, `rounded-lg border border-slate-200`, poziția activă `bg-white shadow-soft text-lex-navy`, Agendă implicit; (b) buton secundar „Adaugă termen”; (c) dropdown Preline „Export” cu „CSV (filtrul curent)” și „Abonare calendar (.ics)”.

Fără CTA gradient în antet: gradientul rămâne rezervat `HeroEmptyState`.

### ZONA 2, bara de risc (`id="deadline-riskbar"`)

Un singur rând, `flex flex-wrap gap-2`, card `rounded-2xl border shadow-soft bg-white dark:bg-slate-900`, `px-4 py-3`. Patru pastile-contor care sunt în același timp filtre comutabile (`button`, `aria-pressed`, cumulabile, al doilea click le scoate). Nu `KpiCard`, ca să nu consume 140px pe verticală înainte de conținut. Fiecare pastilă: cifra în `.tnum text-lg font-bold`, eticheta dedesubt `text-[11px] uppercase tracking-wide`.

1. **RESTANTE**, `bg-red-50 text-red-700 ring-1 ring-red-200`, activ `ring-2`. Definiție: `completed = false` și `deadline_date < CURDATE()` și dosar nesoft-deleted și status dosar netermina.
2. **EXPIRĂ AZI**, contur roșu pe fundal alb. Sub cifră, `text-[10px] slate-500`: „până la ora 24:00”. Definiție: `deadline_date = CURDATE()`.
3. **FATALE ÎN 30 DE ZILE**, `bg-amber-50 text-amber-700`. Numai TIMBRARE, CERERE_IN_ANULARE, PRESCRIPTIE, PRESCRIPTIE_EXECUTARE.
4. **BLOCATE PE DATE LIPSĂ**, `bg-slate-100 text-slate-700`. Contor de **dosare**, nu de termene (vezi Zona 3).

La dreapta, `ml-auto`: comutator discret „Arată finalizate” și link `text-xs` „Filtre” care descoperă al doilea rând cu filtrele opționale.

Toate patru cifrele vin dintr-un singur SELECT cu `CASE WHEN`, randat server-side, deci banda e completă înainte de orice fetch.

**Regulă critică.** Pagina **nu** folosește `LegalDeadline::isOverdue()`. Gruparea temporală se face în SQL, pe `deadline_date` față de `CURDATE()`. Un termen cu data de azi apare în „Azi”, niciodată în „Restante”. Asta rezolvă structural defectul 1 din secțiunea 4.3, fără să atingem entitatea și fără să schimbăm comportamentul tabului din dosar.

### ZONA 3, „Blocaje: termene fatale care nu pot fi calculate”

Aceasta este zona care justifică pagina, deci stă în corpul paginii, imediat sub bara de risc, pe toată lățimea, nu într-un rail lateral.

Card `rounded-2xl border-l-2 border-l-amber-400 border border-slate-200 shadow-soft`. Antet cu titlul, contorul și linia explicativă `text-xs slate-500`: „În aceste dosare lipsește faptul generator, deci termenul nu există în agendă.” Se randează doar dacă are conținut; colapsabil, **implicit închis**, cu buton „Deschide” în antet. Zona este cea care justifică pagina, dar nu este ce citește avocatul în fiecare dimineață: desfășurată, ocupa circa 340px și împingea agenda sub prima ecranare. Antetul, contorul și pastila „BLOCAJE PE DOSAR” din bara de risc rămân vizibile, deci blocajele nu devin invizibile, doar tăcute.

Trei cazuri, toate derivabile din câmpuri reale de pe `LegalCase`, fără migrare:

| Caz | Condiție | Acțiune inline |
| --- | --- | --- |
| (a) somație generată, comunicare neînregistrată | `paymentNoticeDate` setat și `paymentNoticeCommunicationDate` null | „Setează data comunicării somației”, POST pe `case_deadline_summons_communication_date` |
| (b) ordonanță comunicată fără dată | ordonanță emisă și `rulingCommunicationDate` null, deci CERERE_IN_ANULARE (10 zile, CPC art. 1024 alin. 1) nu există | „Setează data comunicării hotărârii”, POST pe `case_deadline_ruling_date` |
| (c) cerere depusă fără înștiințare de timbrare | dosar depus, fără termen TIMBRARE | „Setează data înștiințării”, prin fluxul `StampDutyService::recordCourtNotice` |

Rândul: numărul de dosar, debitorul, ce termen lipsește scris ca act („Cererea în anulare, 10 zile”), consecința („dacă nu introduci data, termenul nu apare nicăieri”), plus butonul primar. Layout `divide-y`, maxim 5 rânduri plus „vezi toate (N)” care comută pe Registru cu filtrul preaplicat.

### ZONA 4, corpul agendei

Grilă `xl:grid-cols-[minmax(0,1fr)_20rem] gap-6`. Sub `xl`, railul coboară sub agendă.

Coloana principală conține cinci secțiuni, fiecare un card `rounded-2xl border border-slate-200 dark:border-slate-800 shadow-soft`, cu antet sticky `top-14` în interiorul coloanei. **Fără gradient, fără blur decorativ, fără `animate-pulse` pe rânduri**: grafica din `_deadline_card.html.twig` e proiectată pentru 3 carduri, nu pentru 40 de rânduri.

1. **„Restante”**, singura cu accent roșu (`border-l-2 border-l-red-500`). Antet: „Restante (7)” plus `text-xs slate-500` „cea mai veche: 41 de zile”. Mereu deschisă. Când e goală: un singur rând `bg-emerald-50 text-emerald-800 text-sm` „Nicio restanță”, iar secțiunea se colapsează. Starea de zero trebuie să fie vizibilă, nu absentă.
2. **„Azi, marți 25 iulie”.** Sub titlu, o linie fixă `text-xs slate-500`, text exact: „Termenele se împlinesc la ora 24:00. Actul depus azi la poștă sau curier este în termen (CPC art. 182).”
3. **„Mâine”.**
4. **„Restul săptămânii”** (până duminică).
5. **„Următoarele 30 de zile”**, colapsată implicit peste 10 rânduri, cu contorul în antet.

Fereastra agendei se oprește la azi plus 30 de zile. Ce e peste nu apare în coloana principală și nu intră în contoare.

**Ordonarea în interiorul fiecărei secțiuni** nu este alfabetică și nici pe prioritatea din enum, ci pe gravitatea consecinței. Criteriul de gravitate este explicit **cât remediu mai rămâne după ratare**, aceeași axă cu coloana „Ireversibil” din secțiunea 4.1, nu o listă de tipuri stabilită separat. Ordinea fixă care rezultă, exact cea produsă de `DeadlineConsequence::severityRank()` (`src/Enum/DeadlineConsequence.php`), este:

1. **PRESCRIPTIE** și **PRESCRIPTIE_EXECUTARE** (stingerea dreptului, NCC art. 2517 și CPC art. 706 alin. 1): niciun remediu.
2. **CERERE_IN_ANULARE** (decădere, CPC art. 1024 alin. 1 raportat la art. 185 alin. 1): repunerea în termen există, dar numai pentru motive mai presus de voința părții (CPC art. 186).
3. **TIMBRARE** (anularea cererii, OUG 80/2013 art. 33 alin. 2 și CPC art. 197): încheierea de anulare nu are autoritate de lucru judecat, deci cererea se poate redepune. Atenție, OUG 80/2013 art. 39 **nu** este remediul împotriva anulării: acel articol dă cerere de reexaminare împotriva **cuantumului** taxei, în 3 zile de la comunicarea sumei datorate, deci înainte ca anularea să fi fost dispusă. Temeiul acestei trepte rămâne exclusiv lipsa autorității de lucru judecat, de confirmat cu avocatul (secțiunea 8.2).
4. **JUDECATA**: cauza se judecă și în lipsă, se pierde doar șansa de a răspunde.
5. **DEPUNERE_CERERE** și **OTHER**: evidență proprie, fără temei și fără sancțiune.
6. **RASPUNS_SOMATIE**: termen al debitorului (CPC art. 1015 alin. 1). Expirarea lui nu costă nimic creditorului, ci deblochează depunerea cererii de ordonanță, deci stă ultimul în ordinea de gravitate, coerent cu secțiunea 4.2 („termenul expirat al debitorului nu este o restanță a avocatului”). Vizibilitatea lui nu vine din poziția în listă, ci din blocul distinct „Deblocate” și din badge-ul propriu.

Motivul pentru care axa este consecința, nu altceva: `DeadlinePriority` e derivată mecanic din tip, deci nu aduce informație independentă, iar în interiorul unei zile nu există ordine cronologică pentru că `deadlineDate` nu are oră. Singura ordonare onestă este cea juridică. O a doua listă de tipuri, scrisă direct în specificația de UI, ar fi intrat în contradicție cu clasificarea din care ecranul își ia badge-urile, așa că ordinea de mai sus se citește dintr-o singură sursă: maparea tip la consecință din `DeadlineConsequenceResolver`. Ierarhia rămâne de confirmat cu avocatul (secțiunea 8.2, punctul 17).

### Anatomia rândului (exact)

Wrapper cu `id="deadline-row-{id}"`, `py-3 px-4`, `divide-y` între rânduri, `hover:bg-slate-50`.

0. **Bandă verticală de 3px** la stânga, culoarea strict din `DeadlinePriority::color()` (slate / sky / amber / red), singurul semnal cromatic. Nu inventăm o a doua axă cromatică: același termen trebuie să arate identic aici și în tabul dosarului.
1. **Fără checkbox.** Închiderea termenului se face printr-un buton etichetat, nu printr-o bifă. O bifă pe un termen procedural se citește ca „actul a fost făcut", ceea ce este fals: `markCompleted()` scoate termenul din agendă, îl scade din contoare și oprește alertele, fără să dovedească nimic. Butonul spune exact ce se întâmplă.
   - Când actul se face chiar aici, butonul primar ESTE închiderea („Marchează finalizat", „Marchează timbrat") și nu mai există un al doilea control.
   - Când actul se face în altă parte („Generează cererea de OP", „Deschide Portal", „Trimite somația"), închiderea devine un buton secundar conturat, mereu în aceeași poziție, la stânga celui primar. Este legitim: avocatul poate depune prin rejust, în afara platformei, și apoi să închidă termenul.
   - **Pe PRESCRIPTIE și PRESCRIPTIE_EXECUTARE eticheta nu este „finalizat"**, ci „Nu mai urmări". Prescripția este termen de drept substanțial: curge indiferent ce apasă avocatul, iar singurul efect real al butonului este tăcerea alertelor, exact pe tipul de termen unde tăcerea costă creanța. Eticheta rămâne de validat cu avocatul (secțiunea 8).
   - **Termenele fatale deja ratate nu primesc niciun buton de închidere.** Rândul rămâne estompat, iar linia 2 spune în cuvinte „consecința s-a putut produce". Singura acțiune este „Deschide dosarul".
2. **Linia 1:** tipul scris ca ACT („Timbrare 200 lei”, „Cerere în anulare”, „Termen de judecată”, „Răspuns somație, termen al debitorului”). Lângă el, **un singur badge**: natura juridică (Anularea cererii / Decădere / Stingerea dreptului / Fără sancțiune / Evidență / Deblochează depunerea).
3. **Linia 2**, `text-xs slate-500`, clasa `.tnum`, separatori „·”: data `12.03.2026 (joi)` · „în 3 zile” sau „expiră azi” sau „depășit de 4 zile” · `courtCaseNumber` sau, când e null, `caseNumber` cu eticheta explicită „nr. intern” · instanța (`Court.name`) · debitorul principal cu CUI scurt (niciodată `personalId`) · principalul în RON (`LegalCase.amount`, verificat ca fiind `principalRon`).
4. **La dreapta:** un buton primar contextual și un meniu kebab Preline.

**Două linii, un badge.** Rândul răspunde la „ce act, până când, în ce dosar, pe ce sumă” și la nimic altceva. Baza de calcul, temeiul legal, explicația consecinței, marcajul CERT și istoricul alertelor trăiesc toate în panoul de detaliu, la un click distanță. Tipărite pe rând costau patru linii fiecare, iar la 40 de rânduri ecranul devenea necitibil, ceea ce anulează scopul unui ecran de triaj.

Singura excepție este data nesigură: incertitudinea schimbă modul în care se citește data însăși, deci rămâne sub dată, ca marcaj scurt („dată estimată”, „întreruperea prescripției se menține condiționat”), cu fraza juridică integrală în `title`. Nu se întoarce niciodată în badge: un al doilea badge pe rând reintroduce exact aglomerarea eliminată aici.

Pe mobil, butonul primar coboară pe propria linie, sub cele două linii de text.

### ZONA 5, rail dreapta 20rem, sticky `top-20`

**RAIL 1, „Prescripții, orizont lung”.** Exclus din contoarele de triaj. PRESCRIPTIE și PRESCRIPTIE_EXECUTARE cu peste 30 de zile rămase, sortate crescător după zile rămase, maxim 5 rânduri plus „vezi toate” care comută pe Registru cu filtrul de tip preaplicat. Sub 90 de zile, rândul primește chenar ambră. Sub 30 de zile, termenul migrează automat în coloana principală, după aceeași regulă unică de fereastră. Justificare: fiecare dosar contribuie permanent cu 1 până la 2 termene deschise la orizont de 3 ani, deci în agendă ar fi zgomot permanent.

**Rail-ul are o singură intrare.** Nota despre alerte a coborât sub agendă, ca text de subsol `text-[11px]`: se citește o dată, nu în fiecare zi, iar în rail concura vizual cu prescripțiile. Legenda consecinței și legenda punctelor de alertă au fost eliminate complet: un badge care are nevoie de legendă permanentă este un badge greșit, iar consecința se citește deja din textul lui.

### ZONA 6, modul „Registru” (vedere secundară)

O singură clasă `LegalDeadlineTableDefinition` care implementează `TableDefinitionInterface`, randată prin componenta existentă `<twig:DataTable>`.

Sortare implicită pe gravitate, obținută în `createScopedQueryBuilder` printr-un `CASE WHEN ... END AS HIDDEN riskRank`: 0 restanțe fatale, 1 fatale în cel mult 7 zile, 2 restanțe nefatale, 3 fatale în cel mult 30 de zile, 4 restul necompletate, 5 orizont lung, 6 finalizate; tie-break pe `deadlineDate ASC`. Motorul suprascrie ordinea doar când utilizatorul apasă un antet.

Coloane: semafor de consecință (formatter `status_badge`), DATA cu ziua săptămânii, REST (zile rămase, negativ pentru restanțe), ACT DE FĂCUT plus badge CERT/ESTIMAT plus baza de calcul pe linia a doua, DOSAR, PARTE ADVERSĂ, INSTANȚĂ, PRINCIPAL, STADIU (badge `CaseStatus`), ALERTE, acțiuni.

Filtre: `search`, tip (enum multiplu, **fără DEPUNERE_CERERE**, care nu e creat de niciun serviciu și ar fi mereu gol), instanță și creditor prin autocomplete pe rutele existente, `date_range` pe `deadlineDate`, comutator „Ascunde orizontul lung (peste 180 de zile)” activ implicit.

Sub `lg`, comutatorul redirecționează spre Agendă: Tabulator la `fitColumns` cu 10 coloane e ilizibil pe 375px.

### Acțiuni disponibile

Toate lovesc rutele existente, ca autorizarea prin `CaseVoter::CASE_DEADLINE_MANAGE` și auditul să rămână într-un singur loc.

1. **Bifă de finalizare inline.** POST pe `case_deadline_complete` cu token `complete_deadline_{id}`, prin controllerul Stimulus `optimistic-action`. Necesită ramura nouă de stream (P2).
2. **„Amână la data...”.** Popover cu input dată plus trei scurtături (mâine, peste 7 zile, prima zi lucrătoare de luni), POST pe `case_deadline_edit`. `EditDeadlineType` acceptă date în trecut, iar schimbarea datei apelează `resetAlertFlags()`. Este acțiunea dominantă după un termen de judecată, iar azi cere două operațiuni în două ecrane.
3. **„Setează data comunicării somației”.** Pe `case_deadline_summons_communication_date`, din rândurile RASPUNS_SOMATIE marcate ESTIMAT și din Zona 3. Badge-ul trece din ESTIMAT în CERT. Se corectează odată cu pagina lipsa apelului `resetAlertFlags()` (secțiunea 3.6).
4. **„Setează data comunicării hotărârii”.** Pe `case_deadline_ruling_date`, din Zona 3. Este acțiunea care creează efectiv termenul de 10 zile; fără ea, termenul fatal nu există în listă.
5. **„Adaugă termen”** din antet: modal Preline cu selector de dosar. `AddDeadlineType` nu are azi câmp de dosar (dosarul vine din URL), deci se adaugă un câmp cu autocomplete pe dosarele active ale utilizatorului. Tipul rămâne forțat pe OTHER.
6. **Editare** dată și descriere prin modalul Preline partajat, populat din `data-*` ale rândului (pattern `deadline-edit` existent).
7. **Ștergere: doar pentru tipul OTHER.** Pentru termenele de sursă legală, meniul kebab afișează în loc un text explicativ, nu o acțiune: „Termen legal. Data se schimbă introducând data faptului generator, nu ștergând termenul.” Modelul nu are stare „inaplicabil cu motiv”, deci nu simulăm una.
8. **Confirmare cu conținut juridic** la finalizarea termenelor fatale, nu „Ești sigur?”. Pentru TIMBRARE, dialogul arată `stampDutyStatus` real al dosarului și avertizează când se bifează finalizat un termen al cărui dosar are taxa NEACHITATA, pentru că bifa este o declarație, nu o dovadă.
9. **Navigare secundară.** Numărul de dosar e link către `case_overview`; pentru rândurile JUDECATA linkul duce în tabul Portal, cu nota „ora ședinței se vede pe portal.just.ro”.
10. **Dashboard.** KPI-urile „Termene urgente” și „Restante” devin link-uri către agendă cu filtrul preaplicat.

### Stări

**Goală, trei registre distincte, fără al patrulea stil:**

- (a) utilizator fără niciun dosar: `HeroEmptyState` pe toată pagina, CTA gradient „Dosar nou”;
- (b) dosare există dar zero termene în 30 de zile: bara de risc colapsează pe un rând verde-neutru „Registru curat” plus, dacă există, „Următorul termen: 4 august 2026, Timbrare, dosar 1234/300/2026” cu link, iar corpul afișează un card `border-dashed` centrat;
- (c) secțiune goală într-o pagină plină: un singur rând `text-xs slate-400` („Nimic azi”), fără card, ca să nu se piardă densitatea.

**Încărcare.** Prima randare este server-side completă (agendă plus contoare într-un singur request), deci fără spinner de pagină. Secțiunile colapsate se încarcă lazy în `turbo-frame`, cu 3 rânduri schelet pe înălțime fixă, ca să nu sară layoutul. Registrul are `dataLoader: false`, deci patru rânduri schelet sub antet, nu overlay.

**Eroare.** Un `turbo-frame` căzut afișează în locul secțiunii un card cu bordură roșie, textul scurt al erorii și buton „Reîncearcă”, fără să afecteze restul paginii. O acțiune inline eșuată (CSRF expirat, dosar șters între timp) revine la starea anterioară și ridică un toast `error` în containerul fix `#toasts`, cu motivul concret, nu cu „a apărut o eroare”.

**Portofoliu mare.** Fiecare secțiune afișează primele 25 de rânduri plus „Arată încă N”, paginat prin `turbo-frame`, fără infinite scroll (împiedică revenirea la același loc). Peste 200 de termene în fereastră, sub bara de risc apare o linie discretă: „Ai 240 de termene în 30 de zile. Registrul permite filtrare pe tip, instanță și creditor.” Antetele de secțiune rămân sticky în interiorul coloanei.

**Mobil (sub `lg`).** Sidebarul rămâne drawer. Bara de risc devine sticky sub header, cu patru pastile care derulează orizontal. Rândul devine card cu două linii (linia 1: tipul plus zilele rămase la `text-base`; linia 2: dosar și debitor la `text-xs`), cu checkbox de 44px la stânga. Tap pe card expandează inline linia 3 și afișează butonul primar full-width, fără navigare în dosar. Kebabul devine bottom sheet. Acțiunea „Setează data comunicării” rămâne disponibilă pe mobil, pentru că exact ea se face în hol, între ședințe. Export, coloane și modul Registru se ascund.

### Texte

Toate în `translations/` sub namespace nou `deadlines.*`, RO și EN în lockstep, refolosind `enum.deadline_type` și `enum.deadline_priority`. Componenta neutilizată `templates/components/DeadlineCard.html.twig` se șterge odată cu pagina.

---

## 7. Ce trebuie adăugat în backend

Legendă efort: S sub o zi, M una până la două zile, L peste două zile.

### 7.1 Precondiții blocante (fără ele pagina nu se livrează)

| # | Ce | De ce | Efort |
| --- | --- | --- | --- |
| **P1** | **Unificarea definiției contoarelor.** O singură metodă de agregare (un SELECT cu `CASE WHEN`) consumată și de dashboard. Definiție unică: `completed = false`, dosar nesoft-deleted, status dosar netermina. | Azi `countUpcomingByUser` (`:111-124`) nu are limită inferioară pe dată și nu exclude statusurile terminale, iar `countOverdueByUser` (`:132-155`) le exclude. Cele două numere de pe dashboard se suprapun și se contrazic. Pagina nouă face inconsistența vizibilă pe același ecran. | M |
| **P2** | **Ramură nouă de Turbo Stream pentru context global.** Câmp ascuns `_context=agenda` în toate formularele paginii; ramură în `complete()` și în `respondDeadline()` care randează un stream cu trei ținte: `deadline-row-{id}`, `deadline-riskbar`, `toasts`. | `complete()` randează `_deadline_card_turbo_stream.html.twig` (`:71`), care înlocuiește `deadline-card-{id}` cu cardul mare din dosar; celelalte acțiuni țintesc `panel-termene`, `case-kpi-grid`, `case-detalii-sidebar`, inexistente pe pagina nouă. Fără ramură, fiecare bifă aruncă avocatul din agendă în dosar și triajul moare. | M |
| **P3** | **Igiena datelor.** (a) închiderea CERERE_IN_ANULARE la intrarea dosarului în IN_ANULARE; (b) corectarea derivării PRESCRIPTIE_EXECUTARE care folosește ziua curentă când lipsește comunicarea; (c) decizie pe DEPUNERE_CERERE (temei NCC art. 2540 sau eliminare). | Toate trei devin vizibile simultan pe zeci de rânduri. Primul lucru pe care avocatul l-ar vedea în „Restante” ar fi un termen care nu mai are sens. | M |
| **P4** | **Migrare de index.** `(completed, deadline_date)` și `(legal_case_id, completed, deadline_date)` pe `legal_deadline`. | Tabela are azi doar indexuri pe chei străine (`Version20260508102445.php:33`, `Version20260515130649.php:32`). Orice filtru pe interval scanează tabela. | S |

### 7.2 Obligatoriu pentru MVP

| Ce | Detaliu | Efort |
| --- | --- | --- |
| Metode noi de repository | `findAgendaForUser(user, from, to, criterii)` cu fetch join pe `legalCase`, `court` și `debtors`; `countAgendaBuckets(user)` (un singur SELECT agregat pentru cele patru pastile); `findLongHorizonPrescriptions(user, limit)`; `findBlockedCases(user)` pentru Zona 3. Niciuna nu există azi și nicio metodă actuală nu face fetch join. | L |
| Rută și controller | `GET /termene` (`app_deadlines`), server-side complet la prima randare; `GET /termene/sectiune/{cheie}` pentru turbo-frame lazy. | M |
| Template-uri | pagina, secțiunea, rândul, cele trei registre de stare goală, streamul de context. | L |
| Derivarea CERT / ESTIMAT | server-side, din `paymentNoticeCommunicationDate` și `rulingCommunicationDate` de pe `LegalCase`, fără migrare. | S |
| Maparea natură juridică și consecință | tabelă fixă tip către etichetă, în PHP (nu în DB), traduceri sub `deadlines.*`. | S |
| Fix `resetAlertFlags()` în `recalculatePaymentNoticeDeadline()` | `DeadlineService.php:110-143`. | S |
| Sidebar și drawer mobil | span dezactivat devine link cu badge de restanțe; intrare nouă în `_topbar.html.twig`. | S |
| Link-uri din dashboard | KPI-urile devin link-uri cu filtru preaplicat. | S |
| Formular „Adaugă termen” cu selector de dosar | câmp nou în `AddDeadlineType` sau formular dedicat; tipul rămâne OTHER. | M |
| Teste | trei niveluri: unit pe maparea consecință și pe derivarea CERT/ESTIMAT, `KernelTestCase` pe metodele noi de repository (inclusiv cazul dosarului terminal și al termenului de azi), `WebTestCase` pe rută, pe acțiunile inline și pe streamul de context. | M |

### 7.3 Ulterior (v1.1 și v2)

| Ce | De ce nu în MVP | Efort |
| --- | --- | --- |
| Modul Registru (`LegalDeadlineTableDefinition`) | cere extinderea `TableDataService` pentru căi cu punct (`t.legalCase.court`), pentru că azi filtrează și sortează exclusiv pe `t.<camp>` (`TableDataService.php:55-110`). Fără ea, filtrele pe instanță, creditor și status dosar nu pot exista. | M plus M pentru motor |
| Export CSV pe filtrul curent | cere `queryAll()` fără paginare în `TableDataService` (seam-ul e deja anticipat în comentariul de la `:50`). UTF-8 cu BOM, separator punct și virgulă, fără CNP, plafonat la 5000 de rânduri, prin `StreamedResponse`. | M |
| Feed `.ics` cu token | evenimente `DTSTART;VALUE=DATE` (modelul nu are oră), prefix „ESTIMAT” în `SUMMARY`. Este răspunsul onest la „am deja Outlook”: nu concurăm cu calendarul lui, îi trimitem termenele în el. | M |
| Sertar lateral de detaliu | pentru textele juridice lungi (disclaimerul de la somație, explicația prorogării, istoricul de recalculare din audit). Fără el, rândul ori se trunchiază, ori pierde densitate. | M |
| Coloană `source` pe `LegalDeadline` | ar permite etichetarea provenienței (automat / portal / manual). Este exact blocantul deja notat pentru corecția numărului de dosar din portal. În MVP **nu afirmăm sursa deloc**, în loc să o ghicim. | M plus migrare |
| Operații în masă | `selectableRows` e hardcodat `false` (`tabulator_controller.js:64`); cer endpoint nou, voter per rând, audit individual și raportare parțială. Completarea în masă pe termene fatale este inerent periculoasă. | L |
| Vederi salvate definite de utilizator | clasa `Filter` nu are persistare; cer o entitate nouă. În MVP, preseturi fixe plus ultimul filtru în `localStorage`. | M |
| Ora pe JUDECATA, stare intermediară („amânat”) | cer migrare și decizie de model. Amânarea rămâne o schimbare de dată, cu istoricul în audit log, și nu pretindem altceva pe ecran. | L |

---

## 8. Riscuri și decizii deschise

### 8.1 Blocante de lansare, de decis cu avocatul

1. **Regula de calcul pe zile.** Codul adună zile calendaristice direct (`+15`, `+10`) fără CPC art. 181 alin. 1 pct. 2, în timp ce derivarea definitivării folosește `+11` zile pentru același termen de 10 zile. Aplicația se contrazice singură. La termenele proprii, o zi în minus este conservator; la RASPUNS_SOMATIE (termen al debitorului) afișarea cu o zi mai devreme poate împinge la depunerea prematură a cererii de ordonanță, care se respinge. **Nu se schimbă fără validare juridică.**
2. **Derivarea PRESCRIPTIE_EXECUTARE** care folosește ziua curentă când lipsește `rulingCommunicationDate` și produce un termen mai lung decât cel real. Până la corecție, aceste termene se afișează obligatoriu ca ESTIMAT.
3. **Închiderea termenelor CERERE_IN_ANULARE** la intrarea dosarului în IN_ANULARE. Azi rămân active la nesfârșit.

### 8.2 De decis cu avocatul, fără să blocheze structura paginii

4. **Prorogarea aplicată datei de judecată.** `DeadlineService` trece JUDECATA prin `nextWorkingDay()`, deși data ședinței e fixată de instanță și preluată din portal. Recomandarea mea este eliminarea prorogării pentru JUDECATA, dar este o schimbare de comportament juridic.
5. **Dubla semnificație a CERERE_IN_ANULARE.** Termen de așteptat când titularul e debitorul, termen de acționat când calea de atac aparține creditorului (cerere admisă în parte). Codul nu distinge, iar culoarea roșie uniformă induce reflexul greșit. Distincția cere fie un câmp nou, fie o derivare din statusul dosarului care trebuie validată juridic.
6. **Termenul de 6 luni din NCC art. 2540.** Nu există nicăieri în sistem și este singurul candidat cu temei real pentru DEPUNERE_CERERE. Dacă nu se adaugă, tipul se scoate din enum și din filtre.
7. **Disclaimerul juridic de la RASPUNS_SOMATIE**, care trăiește în `description` și e șters la recalculare. În MVP nu depindem de el (CERT/ESTIMAT se derivă din câmpurile de comunicare de pe `LegalCase`), dar rămâne o fragilitate de model.
8. **Numerotarea CPC.** Pagina trebuie să citeze exact aceleași articole ca documentele generate (art. 1015 pentru somație, art. 1024 pentru cererea în anulare). O divergență între ecran și actul depus este în sine un risc.
13. **Trimiterea pentru timbrare.** Până la validare, ecranul afișează doar „OUG 80/2013 art. 33 alin. 2”, fără trimiterea la CPC art. 200 și fără afirmația „citat identic în cererea generată”, care azi nu este adevărată. După validare, aceeași formă se folosește în ecran, în docblock-ul `DeadlineService::createStampDutyDeadline` și în textele generate.
14. **Prorogarea prescripției.** `DeadlineService::createPrescriptionDeadlines` nu aplică prorogare, cu motivare explicită în docblock: termenul este de drept substanțial, deci CPC art. 181 nu îi este temei. Eventuala prorogare s-ar sprijini pe **NCC art. 2554**, „Prorogarea termenului", nu pe art. 2556, care este de fapt prezumția efectuării în termen a actelor depuse la poștă, adică analogul substanțial al CPC art. 183. Până la decizie, ecranul afișează data exactă, fără notă de prorogare, ca să corespundă cu ce produce codul. Dacă decizia este pentru prorogare, se modifică simultan serviciul, docblock-ul și mockup-ul.
15. **Titularul cererii în anulare, ca dată afișată.** Chipul „titular: creditorul / debitorul” nu se derivă azi din model. În mockup este marcat explicit ca element care cere fie un câmp nou pe termen, fie o derivare validată din stadiul dosarului, iar presetul de filtrare „Ale debitorului” a fost scos din MVP. Pentru calea creditorului, temeiul citat este CPC art. 1024 alin. 2 raportat la alin. 1 și la art. 1021, tot sub rezerva validării.
16. **Momentul trimiterii alertelor.** Ca să se poată afișa „trimisă la DD.MM.YYYY, ora HH:MM”, este nevoie fie de patru coloane de timestamp pe `LegalDeadline`, fie de `dedupKey` plus legătură către termen pe `Notification` (recomandat, refolosește infrastructura existentă). Pentru bannerul de portal indisponibil sunt necesare `lastPortalCheckAt` și `lastPortalSuccessAt` pe `LegalCase`: azi există doar `portalConsecutiveFailures`, fără marcă de timp.
17. **Ierarhia de gravitate din interiorul unei zile.** Ordinea folosită de agendă (secțiunea 6, ZONA 4) este cea din `DeadlineConsequence::severityRank()` și se sprijină pe cât remediu mai rămâne după ratare: stingerea dreptului, decăderea, anularea cererii, apoi termenele fără efect de stingere. Consecința practică pe care avocatul trebuie să o confirme este că RASPUNS_SOMATIE apare ultimul, sub JUDECATA și sub mementourile OTHER, pentru că este termenul debitorului. Dacă avocatul consideră că trebuie să stea mai sus, se modifică simultan `severityRank()`, testele care fixează rangurile și această secțiune.

### 8.3 De decis de produs

9. **Sursa badge-ului numeric din sidebar: decis.** Badge-ul afișează contorul de restanțe, în roșu, în toate ecranele (Termene, Dosar, Dashboard, meniul mobil). Când nu există restanțe, badge-ul nu se randează. Mockup-urile v2 au fost aliniate.
10. **Se schimbă și dashboardul odată cu pagina.** Unificarea definiției contoarelor va schimba cifrele afișate azi în KPI-ul „Termene urgente” (va exclude restanțele și dosarele terminale). Este corect, dar utilizatorii existenți vor vedea numărul scăzând fără explicație.
11. **Pragul de renunțare.** Metrica de control este densitatea de acțiune (acțiuni pe vizită). Sub aproximativ 0,5, pagina a devenit un meniu, iar decizia corectă este să extindem widgetul `DeadlineList`. Cine măsoară și când se ia decizia rămâne de stabilit.
12. **Sertarul lateral de detaliu** intră în v1 sau v1.1. Fără el, textele juridice lungi nu au unde să încapă și rămân doar în dosar.

### 8.4 Risc rezolvat prin verificare

`LegalCase.amount` **este** principalul în RON: `ClaimTotalsService::recalculate()` îi atribuie `principalRon` (`src/Service/Case/ClaimTotalsService.php:85,128`). Coloana se poate afișa etichetată ca principal, cu trimitere la CPC art. 98 alin. 2. Atenție la nota din entitate: câmpul este denormalizat din pozițiile `ClaimItem` și nu se atribuie direct (`src/Entity/LegalCase.php:55-63`).

### 8.5 Riscuri asumate ale arhetipului

- **Gruparea temporală contrazice parțial cerința juridică de separare pe natura termenului.** Un memento OTHER și o timbrare fatală apar în același bloc „Azi”. Compensăm prin badge de natură juridică, linia de consecință și scoaterea prescripțiilor pe orizont lung în rail, dar ierarhia principală rămâne data. Sortarea pură pe gravitate rămâne disponibilă în Registru.
- **Pierdem planificarea lunii.** Compensarea onestă este feedul `.ics`, nu un calendar propriu.
- **Pierdem interogarea liberă pe portofoliu.** De aceea Registrul există, dar pornește deliberat pe locul doi.
- **Pagina face vizibile defecte existente și nu le poate ascunde.** Igiena lor este precondiție de lansare, nu polish ulterior.

---

## 9. Legătura cu mockup-urile

Mockupul există la `docs/LexRecovery/mockups/v2/05-termene/`, în două fișiere:

- `index.html`, ecranul plin (blocaje, restanțe, deblocate, azi, mâine, restul săptămânii, 30 de zile, rail de orizont lung) plus vederea Registru și panoul de detaliu;
- `stari.html`, cele șase stări care nu încap în ecranul principal (cont nou, registru curat, portofoliu mare, portal indisponibil, vedere mobilă, detaliu și proveniență).

**Schelet obligatoriu**, identic cu celelalte pagini v2, altfel comparația directă cu dashboard și dosar nu funcționează:

- Tailwind v3 Play CDN cu `tailwind.config` inline: `darkMode: 'class'`, culorile `lex-navy`, `lex-navy-dark`, `lex-navy-light`, `boxShadow` `soft`/`card`/`hero`, `fontFamily` Inter, plugin `addVariant` pentru `hs-tab-active`, `hs-dropdown-open`, `hs-accordion-active`, `hs-overlay-open`, `hs-overlay-backdrop-open`;
- `<style>` inline cu `.tnum`, `.soft-pulse`, `.ring-fill`, `.nav-active`;
- body `bg-slate-50 dark:bg-slate-950`, `aside` fix `w-64`, `div.lg:pl-64`, header sticky `h-14`, `main.flex-1.px-4.lg:px-8.py-6`.

**Reguli specifice pentru acest mockup:**

1. Sidebarul marchează „Termene” ca **activ** (`nav-active`, text `lex-navy`, bară verticală) și păstrează ordinea reală a meniului: Dashboard, Dosare, Termene, Notificări, secțiunea Bibliotecă (Creditori, Debitori), secțiunea Cabinet (Abonament, Facturi, Setări).
2. Topbarul reflectă **producția**, nu mockup-urile vechi: breadcrumb, clopoțel de notificări cu badge, comutator dark, comutator RO/EN, buton „Dosar nou”. Fără câmpul de căutare globală cu `⌘K` și fără butonul Help, care nu există în aplicație.
3. Breadcrumbul afișează „Termene” ca element curent în `ol` de `text-xs`, nu ca titlu duplicat.
4. Toate textele se scriu în **română finală, cu diacritice**, ca să se poată extrage direct în chei `deadlines.*`.
5. Culorile de prioritate sunt exact `DeadlinePriority::color()` (slate / sky / amber / red), aceleași ca în `_deadline_card.html.twig` și `DeadlineList.html.twig`. Fără tokeni noi. Concret: banda de 3 px din stânga rândului codifică **prioritatea** (RASPUNS_SOMATIE HIGH deci ambru, JUDECATA și OTHER MEDIUM deci albastru, TIMBRARE / CERERE_IN_ANULARE / PRESCRIPTIE / PRESCRIPTIE_EXECUTARE CRITICAL deci roșu), iar **consecința** este purtată exclusiv de badge. Cele două semnale nu se amestecă.
7. **Acțiunea primară pe rând, regulă unică:** acțiunea primară este actul de făcut („Marchează timbrat”, „Marchează finalizat”, „Deschide Portal”, „Setează data comunicării”, „Generează cererea de OP”, „Trimite somația”). „Amână la data...” apare numai pe JUDECATA și pe mementourile proprii (OTHER), și numai din meniul kebab. Pe termenele legale (TIMBRARE, CERERE_IN_ANULARE, PRESCRIPTIE, PRESCRIPTIE_EXECUTARE) amânarea nu se oferă deloc: data se schimbă corectând faptul generator, iar termenul se recalculează prin `DeadlineService`. Stil: navy plin pentru acțiunea primară, roșu plin doar când termenul este fatal și mai sunt cel mult 3 zile, conturat pentru acțiuni neutre.
8. **Termenul fatal deja ratat** primește un tratament propriu: rând estompat, eticheta „Consecința s-a putut produce”, fără bifă (bifa ar sugera că actul mai poate fi făcut) și cu acțiunea primară „Deschide dosarul”.
9. **PRESCRIPTIE_EXECUTARE nu apare în coloana principală.** Termenul se creează doar la intrarea în DEFINITIVA sau EXECUTARE și cade la 3 ani de la data la care hotărârea a devenit executorie, deci pe dosarele din 2026 este în 2029. Locul lui este railul de orizont lung.
10. **Pe telefon rămâne doar Agenda.** Comutatorul Registru și grupul de export se ascund sub `lg`, ca utilizatorul să nu ajungă într-un tabel de 11 coloane pe 320 px.
6. Nu se importă cardul mare cu gradient, blur și `animate-pulse` din tabul dosarului.

Navigare locală pentru review:

```bash
php -S 127.0.0.1:8888 -t docs/LexRecovery/mockups
# http://localhost:8888/v2/05-termene/index.html
# http://localhost:8888/v2/05-termene/stari.html
```
