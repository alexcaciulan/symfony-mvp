# Analiză: wizard-ul de creare dosar, ciorna și editarea dosarului după creare

Data: 2026-11-03. Ramura analizată: `lexrecovery`. Scop: să stabilim ce se salvează și când pe parcursul wizard-ului, dacă există o ciornă la care avocatul se poate întoarce, ce se mai poate modifica după ce dosarul a fost creat și cum ar trebui să arate editarea ca să aibă sens juridic și tehnic. Cazul concret care a pornit analiza: avocatul a uitat să adauge una sau mai multe facturi pe un dosar deja creat.

Documentul este o analiză cu recomandări și întrebări deschise. Planul de implementare detaliat se scrie după ce deciziile din secțiunea 7 sunt luate.

---

## 1. Cum este implementat wizard-ul

Un singur controller, `src/Controller/Case/CaseWizardController.php` (3.231 linii), cu rutele sub `/case/new`:

| Pas | Rută | Ce face |
|---|---|---|
| 0 | `/case/new/documents` | Încarcă documentele (max. batch, deduplicare, rate limit), declanșează extracția AI asincron (`ExtractDataMessage`), arată „date detectate” și conflictele între documente |
| 1 | `/case/new/creditor` | Formular creditor, prefill din extracție, potrivire cu biblioteca de creditori, sincronizare ANAF |
| 2 | `/case/new/debtor` | Formular debitori (multi), prefill, potrivire cu biblioteca de debitori, ANAF, BPI |
| 3 | `/case/new/claim` | Creanța: scalarii (sumă, monedă, scadență, tip relație, penalități, contract) și tabelul de poziții (facturi) cu confirmare/excludere pe rând |
| 4 | `/case/new/confirmation` | Recalculează totul (dobândă, taxă, instanță, admisibilitate), reverifică biblioteca și ANAF, cere confirmarea și persistă |

Rute auxiliare: `start` (reset), `skip`, `documents/{id}/retry`, `documents/{id}/remove` (cu preview al efectelor), `documents/{id}/type`, `debtor/pick`, `debtor/unpick`, `documents/status` (JSON).

Navigarea înapoi există: butonul „Înapoi” de pe fiecare pas și istoricul browser-ului (Turbo Frame cu `data-turbo-action="advance"`). Stepper-ul din antet este doar vizual, nu are link-uri.

Teste: 178 de teste în 10 fișiere acoperă wizard-ul (Step0, Step1To4, ClaimItemsFlow, conflicte, audit).

## 2. Cum se salvează datele la navigarea între pași

### 2.1 Mecanismul actual

Tot statul wizard-ului stă în **sesiunea PHP**, sub cheia `case_wizard_data`, ca un „bag” cu obiecte PHP serializate:

```
documentIds              list<int>          id-urile Document deja persistate în DB
creditor                 Step1CreditorData  DTO sau null
debtors                  Step2DebtorsData   DTO sau null
debtorBeforePick         Step2DebtorsData   backup la alegerea din bibliotecă
claim                    Step3ClaimData     DTO sau null
claimItems               list<ClaimItemRow> pozițiile (facturile) cu deciziile avocatului
claimItemsTableConfirmed bool
conflictResolutions      array<ConflictResolution>
conflictsAcknowledged    array
creditorLibraryRecheck / debtorLibraryRecheck  bool
```

Regula de salvare: un pas se scrie în bag **doar la POST valid** (formular valid, conflicte decise, bibliotecă împăcată), după care redirecționează la pasul următor. La GET, pasul se pre-completează din bag dacă există, altfel din agregarea extracției (`PrefillFromExtractionService::aggregate`). Deciziile pe conflicte se salvează și la POST invalid, ca avocatul să nu le refacă.

Excepția importantă: **documentele de la pasul 0 sunt persistate imediat în DB** (`Document` cu `legal_case_id = NULL`, fișierul în `cases/_pending/{userId}/{uuid}/`). Doar id-urile stau în sesiune.

Dosarul (`LegalCase`, `LegalCaseDebtor`, `ClaimItem`, creditorul nou dacă e cazul) se creează **o singură dată, la confirmarea pasului 4**, într-o tranzacție (`persistWizard`), cu status inițial `AMIABIL`, după care bag-ul se golește.

### 2.2 Ce se întâmplă cu ciorna

Nu există o entitate de ciornă. „Ciorna” este bag-ul din sesiune, deci:

| Eveniment | Efect asupra wizard-ului în curs |
|---|---|
| Reload, navigare în altă pagină, revenire pe un pas | Datele rămân (sesiunea e aceeași) |
| 30 de minute fără activitate (`gc_maxlifetime: 1800`) | Sesiunea expiră, bag-ul se pierde |
| Închiderea browserului (`cookie_lifetime: 0`) | Cookie-ul de sesiune dispare, bag-ul se pierde |
| Logout / login pe alt dispozitiv | Sesiune nouă, bag gol |
| Click pe „Dosar nou” din topbar | `start` **resetează explicit** bag-ul (comentariu în cod: „Hard reset on every Dosar nou click”) |
| Deploy / restart container (sesiuni pe fișiere, `session.save_handler = files`) | În Docker sesiunile stau în container; la recreare se pierd |

În toate cazurile de pierdere, **documentele rămân în DB și pe disc ca orfane** (fără dosar). Comentariul din `start()` spune că o comandă `app:cleanup-pending-uploads` ar trebui să le curețe; comanda **nu există** în `src/Command/`. Orfanele se acumulează și costă extracții AI deja plătite.

Concluzie: nu se poate relua procesul mai târziu. Dacă avocatul încarcă 8 documente, completează creditorul și debitorii și pleacă la o ședință, la întoarcere reia de la zero (și plătește din nou extracția la reîncărcare, fiindcă deduplicarea e doar în interiorul bag-ului curent).

### 2.3 Este implementarea recomandată?

Pentru un wizard de 5 pași care creează o entitate complexă, sesiunea este abordarea standard Symfony și a fost alegerea corectă pentru MVP: fără schemă, fără dosare pe jumătate completate în listă, tranzacție unică la final. Are însă trei slăbiciuni care au devenit vizibile:

1. **Fragilitate**: obiecte PHP serializate în sesiune; orice schimbare în DTO-uri (adăugare de câmpuri, redenumire) poate invalida bag-urile vii. `loadBag()` se apără cu `instanceof` și aruncă ce nu mai recunoaște, deci eșecul e „tăcut”: avocatul pierde pasul fără explicație.
2. **Lipsa reluării**, descrisă mai sus. Analiza de fluxuri inițială (`ANALIZA-FLUXURI-LEXRECOVERY.md`, §12.2 „Form draft autosave”) prevedea un controller Stimulus `autosave` cu endpoint `/dosar/nou/draft`; nu a fost implementat.
3. **Producție**: cu sesiuni pe fișiere, orice a doua replică PHP sau un restart la deploy rupe wizard-urile în curs. Independent de ciornă, în producție sesiunile trebuie mutate în DB (`PdoSessionHandler`) sau Redis.

Alternativele:

| Opțiune | Descriere | Pro | Contra |
|---|---|---|---|
| A. Sesiune (actual) + întărire | Rămâne bag-ul; se adaugă handler de sesiune în DB pentru prod, curățarea orfanelor | Zero schimbări în wizard | Nu rezolvă reluarea, nici fragilitatea |
| B. `LegalCase` creat devreme, cu status `CIORNA` | Dosarul se persistă de la pasul 0/1 și se completează progresiv | Reluare de oriunde; documentele au dosar din start | Foarte invaziv: 13 statusuri, workflow, subscriberi (termene la persist), voter, listări, rapoarte, abonament, toate presupun că un dosar salvat e complet. Alocă număr de dosar pentru ceva ce poate fi abandonat |
| C. Entitate `CaseDraft` separată | Bag-ul se salvează ca JSON într-o tabelă proprie (user, payload, documentIds, pasul curent, updatedAt); sesiunea ține doar id-ul ciornei | Reluare de pe orice dispozitiv; listă „Ciorne” pe dashboard; documentele orfane au proprietar (curățare simplă); `LegalCase` rămâne neatins; schimbarea în controller e localizată în `loadBag`/`saveBag` | Necesită (de)serializare JSON a DTO-urilor (enum-uri, date) și o migrare; o ciornă abandonată trebuie expirată |

**Recomandare: C.** Este singura care rezolvă reluarea fără să atingă semantica dosarului, și păstrează 178 de teste existente aproape neschimbate (bag-ul rămâne aceeași structură în memorie). Opțiunea B ar fi justificată doar dacă am vrea ca ciorna să apară ca „dosar” în toate listele, ceea ce avocatul nu a cerut. Opțiunea A (handler de sesiune în DB) rămâne necesară pentru producție oricum și poate merge în Faza 9.1 (deploy).

Un câștig colateral al opțiunii C: „Dosar nou” nu mai șterge munca; întreabă „Continuă ciorna din 3 nov. (4 documente, creditor completat) sau începe un dosar nou?”.

## 3. Ce se poate edita astăzi după creare

După pasul 4 nu există nicio cale de a modifica părțile sau creanța. Codul o spune explicit în `CaseSummonsController`: „Claim data is frozen once the case is saved (stamp duty, court and admissibility were derived from it), so the way out is the wizard”, adică un dosar nou.

| Informație | Editabilă de avocat? | Unde | Observații |
|---|---|---|---|
| Documente sursă (adăugare) | Da, în `AMIABIL`, `SOMATIE_TRIMISA`, `CERERE_GENERATA`, `CERERE_DEPUSA` (`CASE_UPLOAD`) | Tab Documente, modal upload | **Nu se extrage nimic** din documentul nou și **nu se creează poziții**; fișierul e doar anexă (comentariul din template: „documents added after case creation are never extracted”). Plafon 10 anexe |
| Documente sursă (ștergere) | Da, aceleași statusuri | Tab Documente | Exceptate: generate, dovada taxei, tipurile „probă” (`isDeletable`) |
| Împuternicire avocațială (serie, număr, dată) | Da | Tab Documente | |
| Creditor (datele companiei) | Indirect, prin biblioteca de creditori | `/creditors/{id}/edit` | `LegalCase.creditor` e referință directă la entitatea din bibliotecă, nu snapshot: editarea în bibliotecă se vede pe toate dosarele; CUI-ul e blocat după somație |
| Debitor (datele companiei) | Indirect, prin biblioteca de debitori | `/debtors/{id}/edit` | Același model de referință; `DebtorVoter::EDIT` blochează după somație |
| Creditorul ales / lista debitorilor | **Nu** | | |
| Creanța: sumă, monedă, scadență, poziții (facturi), excluderi, confirmări | **Nu** | | Nici adminul: `ClaimItemCrudController` și `LegalCaseCrudController` au `EDIT` dezactivat |
| Tip relație, temei, penalități contractuale, referințe contract, obiectul creanței | **Nu** | | |
| Instanța | **Nu** | | Adresa de e-mail a instanței da (`CaseCourtAddressController`) |
| Taxă de timbru (dovadă, amânare, regularizare) | Da, oricând (`STAMP_DUTY_MANAGE`) | Card taxă | Valoarea e fixă (200 lei, OUG 80/2013 art. 6 alin. 2), nu depinde de sumă |
| Termene | Da, oricând (`DEADLINE_MANAGE`) | Tab Termene | |
| Număr dosar instanță, termen de judecată, hotărâri, închidere | Da, prin tranziții | Modalele din overview | |
| Ștergerea dosarului | **Nu** | | `deletedAt` există pe entitate, nicio rută nu îl setează |

Voter-ul definește `CASE_EDIT` (permis în `AMIABIL`, `SOMATIE_TRIMISA`, `CERERE_GENERATA`, `CERERE_DEPUSA`), dar **niciun controller nu îl folosește**. Planul inițial îl prevedea (PLAN-DEZVOLTARE §4.0, „CASE_EDIT permis doar dacă status în AMIABIL, SOMATIE_TRIMISA, CERERE_DEPUSA”), editarea propriu-zisă nu a fost însă livrată.

## 4. Ce depinde de creanță (de ce editarea nu e o simplă actualizare)

La confirmare, din creanță se derivă și se stochează pe dosar:

| Derivat | Unde se calculează | Se recalculează ulterior? |
|---|---|---|
| `amount`, `currency`, `dueDate`, `invoiceNumber`, `invoiceDate` (scalarii dosarului) | `ClaimTotalsService::recalculate` din poziții | Da, serviciul e reutilizabil |
| Conversia valutară (sumă, curs, dată curs) | `resolveConversion` în wizard, pe poziție în `ClaimItem` | Parțial; cursul pe poziție nouă trebuie rezolvat la data potrivită |
| `calculatedInterest` (accesoriul total) | Wizard la creare, `CaseAccessoryService::refreshStoredTotal` la somație | Da, serviciul există |
| Instanța competentă (`court`) | `CompetentCourtResolver::resolveForItems` (prag 200.000 lei, grupare pe cauză, CPC art. 99) | **Nu**; o factură în plus poate muta competența de la judecătorie la tribunal sau poate schimba gruparea pe cauze |
| Taxa de timbru | Fixă | Nu e nevoie |
| Admisibilitate (erori blocante, avertismente) | `OpAdmissibilityValidator` + `AccessoryComputabilityChecker` | Da, serviciile sunt pure |
| Termene de prescripție (unul per scadență distinctă) | `DeadlineCreationSubscriber` la persist, `createPrescriptionDeadlines` idempotent | Adăugarea: da (idempotent). Eliminarea unei facturi lasă un termen orfan |
| Audit `wizard_submit` (câmpuri auto/manual, poziții, conflicte) | `persistWizard` | O editare are nevoie de propriul audit cu diferența |
| Biblioteci (creditor/debitor creat sau actualizat) | `reuseOrCreateCreditor`, `buildDebtorLink` | Da |

După `trimite_somatie` se adaugă efecte **externe**: somația comunicată debitorului enunță suma și accesoriile (CPC art. 1015), termenul de 15 zile curge de la comunicare, slotul de abonament s-a consumat, iar cererea OP generată ulterior preia tabelul de poziții. O creanță modificată după somație face somația să nu mai corespundă cu cererea.

## 5. Cazul concret: factura uitată

Astăzi avocatul are două variante, ambele proaste:

1. Încarcă factura pe dosar ca anexă (Tab Documente). Suma, dobânda, somația și cererea **nu o includ**. Documentul apare în opis, dar creanța cerută rămâne cea veche.
2. Creează un dosar nou cu toate facturile (recomandarea implicită din cod). Pierde ce a completat, iar dacă a trimis deja somația pentru primul dosar, are două dosare pentru același debitor și aceeași relație contractuală.

Ce ar trebui să se întâmple, pe statusuri:

| Status | Comportament dorit |
|---|---|
| `AMIABIL` (nimic nu a plecat) | Redeschiderea completă a dosarului: încărcarea facturii trece prin extracție, deduplicare față de pozițiile existente, apare ca rând nou în tabelul de la pasul 3; la confirmare se recalculează totul (totaluri, accesoriu, instanță, admisibilitate, termene) și se scrie audit cu diferența |
| `SOMATIE_TRIMISA` | Două posibilități juridice, de decis cu avocatul (secțiunea 7, Î1): (a) se permite și se cere o **nouă somație** care să acopere și factura nouă (termenul de 15 zile curge din nou pentru întreaga creanță, dosarul se întoarce logic la „somație de trimis”), sau (b) se blochează, factura merge într-un dosar nou |
| `CERERE_GENERATA` (pachetul există, n-a plecat) | Ca la somație, plus regenerarea cererii și a opisului |
| `CERERE_DEPUSA` și după | Blocat în aplicație. Completarea cererii la instanță (CPC art. 204) e un act procedural pe care avocatul îl face în afara platformei; în aplicație rămâne dosar nou |

## 6. Direcții de implementare (pentru planul următor)

Decizii 2026-11-03, după reanalizarea cu delimitarea agreată:

- **Părțile sunt fixe; se editează doar creanța; doar în `AMIABIL`.** Alt creditor, alt debitor sau alte documente înseamnă ștergerea dosarului și unul nou.
- **Fără redeschiderea wizard-ului.** Wizard-ul construiește un dosar din documente cu părți necunoscute, conflicte între documente și potriviri în biblioteci; pentru o factură adăugată sau exclusă pe un dosar cu părți fixe, acel aparat e balast. Ce contează este recalculul, iar acela este un serviciu, nu un ecran. Wizard-ul rămâne neatins (3.231 linii, 178 teste).
- **Ciorna se salvează automat, fără buton, și nu este un status de dosar.**

### 6.1 Ciorna (`CaseDraft`)

Entitate pe utilizator cu bag-ul salvat serializat (același conținut pe care `saveBag` îl pune azi în sesiune) plus coloane denormalizate pentru listă: pasul atins, numărul de documente, numele creditorului, `updatedAt`. `loadBag`/`saveBag` citesc și scriu în tabelă; sesiunea ține doar id-ul ciornei. Listă „Dosare neterminate” pe dashboard și deasupra listei de dosare, cu „Continuă” și „Renunță”. „Dosar nou” arată ciornele existente înainte să pornească una nouă. Comandă de expirare (propus 30 de zile fără activitate) care șterge și documentele ciornei. Documentele orfane de azi (fără dosar și fără ciornă) se curăță cu aceeași comandă. Sesiunile în DB (`PdoSessionHandler`) rămân un punct de deploy (Faza 9.1), nu de produs.

### 6.2 Ștergerea dosarului în `AMIABIL`

Rută `POST case/{id}/delete`, voter `CASE_EDIT` plus status `AMIABIL`, confirmare, `deletedAt` (există pe entitate, nefolosit), audit. Nimic nu a plecat spre exterior și slotul de abonament nu s-a consumat.

### 6.3 Recalculul creanței (nucleul)

Serviciu `ClaimRecalculator` (nume orientativ) compus din serviciile existente: `ClaimTotalsService::recalculate`, `CaseAccessoryService::refreshStoredTotal`, `CompetentCourtResolver::resolveForItems` (cu semnal „instanța s-a schimbat” și ce instanță propune; nu se schimbă automat fără confirmare), `DeadlineService::createPrescriptionDeadlines` plus anularea termenelor de prescripție rămase fără poziție, `OpAdmissibilityValidator::validateClaimRows`, `AccessoryComputabilityChecker`. Scrie un audit `claim_edited` cu `before`/`after`. Un singur loc, testabil direct, refolosibil dacă editarea de după somație se cere vreodată.

### 6.4 Modificarea facturilor din pagina dosarului

Pe cardul Creanță din overview, doar când voter-ul `CASE_EDIT` și statusul `AMIABIL` permit:

- Tabelul pozițiilor cu „Adaugă factură” și „Exclude” / „Reinclude” pe fiecare rând (excluderea păstrează rândul, ca în wizard).
- Adăugarea dintr-un document: documentul încărcat ca `FACTURA` trece prin extracția existentă (`ExtractDataMessage`, azi nedeclanșată după creare) și precompletează formularul; sau manual (număr, dată, scadență, sumă, monedă). Deduplicare pe `dedupKey` (`ClaimItemRepository::findOneByDedupKey`), refuz când emitentul sau destinatarul detectat diferă de părți, conversie valutară cu `CurrencyConverter`.
- Modal separat „Modifică detaliile creanței” pentru textele fără efect pe sume: obiectul creanței, referința contractului, articolul și textul clauzei, numărul somației anterioare.
- Răspuns Turbo Stream care reîmprospătează cardul și arată ce s-a schimbat (sumă, accesoriu, instanță, termene).
- Pierdere asumată: mecanismul de conflicte între documente nu se aplică; avocatul confirmă o factură cu datele în față.

### 6.5 Ordinea și estimarea

| Pas | Mărime |
|---|---|
| 1. `CaseDraft` + listă + curățare | 2 zile |
| 2. Ștergerea dosarului în `AMIABIL` | 0,5 zile |
| 3. `ClaimRecalculator` cu teste pe serviciu | 1 zi |
| 4. Cardul Creanță: adăugare, excludere, detalii | 3 zile |
| 5. Verificare live (S1, S3, S5, S7, S8, S9) | 1 zi |

Total aproximativ 7,5 zile; pașii 3-4 primesc plan dedicat (`PLAN-EDITARE-DOSAR.md`). Nu intră: editarea după somație, schimbarea părților, redeschiderea wizard-ului.

## 7. Întrebări deschise

Decizii luate la 2026-11-03: editarea doar din `AMIABIL` pentru moment (Î1, Î2 și Î4 se amână până la discuția cu avocatul); ciorna se salvează automat, fără buton, și nu apare în lista de dosare ca status, ci într-o bandă „Dosare neterminate” (Î5 închisă: intră prima).

Pentru avocat (amânate, nu blochează planul):

- **Î1. Factură adăugată după somație.** Se acceptă în același dosar cu o nouă somație pentru întreaga creanță (termenul de 15 zile curge din nou), sau factura uitată merge într-un dosar nou? Există vreo situație în care avocatul ar vrea să trimită o somație doar pentru factura nouă, păstrând-o pe prima?
- **Î2. Eliminarea unei facturi după somație** (de exemplu plătită între timp sau greșit atribuită). E aceeași regulă ca la adăugare, sau o retragere parțială se poate face fără somație nouă?
- **Î3. Schimbarea instanței competente** ca efect al editării (pragul de 200.000 lei sau cauză diferită). Avocatul vrea să fie doar avertizat și să confirme, sau aplicația schimbă instanța automat?
- **Î4. După depunere** (`CERERE_DEPUSA` și mai departe), confirmăm că aplicația nu permite nicio modificare a creanței și că o completare a cererii (CPC art. 204) se face în afara platformei?

Pentru produs:

- **Î5.** Ciorna persistată (`CaseDraft`) intră înaintea editării sau după? Recomandarea e înainte: e independentă, mică și repară pierderea de muncă raportabilă deja.
- **Î6.** Schimbarea creditorului sau a listei de debitori pe un dosar existent intră în editare, sau rămâne „dosar nou”? Recomandare: creditorul rămâne fix (dosarul e al lui); debitorii editabili doar în `AMIABIL`.
- **Î7.** Documentele încărcate după creare prin Tab Documente: rămân simple anexe (comportamentul actual) sau orice factură încărcată acolo deschide automat editarea? Recomandare: rămân anexe, cu un hint „Pentru a include factura în creanță, modifică dosarul”, ca să nu amestecăm două fluxuri.

## 8. Referințe în cod

- `src/Controller/Case/CaseWizardController.php`: `start()` 165, `documents()` 182, `creditor()` 687, `debtor()` 892, `claim()` 1220, `confirmation()` 1517, `persistWizard()` 1802, `emptyBag()` 2537, `loadBag()` 3084, `saveBag()` 3159
- `src/Service/Case/ClaimItemFactory.php`: `collectRows()`, `materialize()`; `ClaimTotalsService::recalculate()`; `CaseAccessoryService::refreshStoredTotal()`
- `src/Service/Court/CompetentCourtResolver.php`: `resolveForItems()`, prag 200.000 lei
- `src/Service/Deadline/DeadlineService.php`: `createPrescriptionDeadlines()` 802
- `src/Security/Voter/CaseVoter.php`: `CASE_EDIT` definit, nefolosit
- `src/Controller/Case/DocumentController.php`: `upload()` 74 (fără extracție), `delete()` 186
- `config/packages/framework.yaml`: sesiune, `gc_maxlifetime: 1800`, `cookie_lifetime: 0`
- `docs/LexRecovery/ANALIZA-FLUXURI-LEXRECOVERY.md` §12.2: „Form draft autosave” prevăzut, nelivrat
