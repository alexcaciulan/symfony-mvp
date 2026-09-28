# Plan final de implementare: somațiile noi ale avocatului (dobândă legală penalizatoare și accesorii contractuale)

Surse: `legala.docx` / `contractuala.docx` (textul extras în `legala.txt` / `contractuala.txt`), comparate cu versiunile anterioare `old_legala.txt` / `old_contractuala.txt` și cu codul de pe `lexrecovery`.

Notele roșii ale avocatului (culoare `EE0000`, verificat în XML-ul .docx) sunt exact două per document:
1. o linie cu coloanele tabelului de calcul, plasată fizic în secțiunea I, imediat sub `{{TABEL_DEBITE_RESTANTE}}` și `TOTAL DEBIT PRINCIPAL RESTANT`;
2. după fraza cu contul: „de pus condiție dacă există IBAN (preluat) în platformă”.

## 0. Deciziile pe care le luăm noi (fără avocat)

| # | Subiect | Decizie |
|---|---|---|
| D1 | Structura șablonului | O singură intrare, `templates/pdf/payment_notice.html.twig`, care include partiale. Partialele sunt comune doar acolo unde textul celor două variante e identic cuvânt cu cuvânt (antet, introducere până la temei, secțiunea I, ultimele trei paragrafe, semnătura). Temeiul de drept, rezumatul inițial, secțiunea II, secțiunea III (imputația) și somarea au ramificare internă după `isContractual`, pentru că diferă textual (vezi D29, D30). Textul fix trece prin translator, în chei noi `pdf.summons.v3.*`. Cheile vechi `pdf.summons.*` se șterg după ce testele trec. |
| D2 | Sursa textului | Textul RO se copiază din .txt, dar se **corectează artefactele de redactare**: `art. 3 alin. (2^1)` devine `art. 3 alin. (2¹)` (în .docx caracterul e literal `2^1`, fără superscript; versiunea veche avea `(2¹)`). Orice citare de alineat cu simbol special se verifică manual înainte să intre în chei. Separatoarele `–` / `|` din notele roșii nu ajung niciodată în PDF. |
| D3 | Notele roșii | Nu apar în PDF. Nota 2: propoziția de plată se afișează doar în `{% if creditor.iban %}`, iar fragmentul `, deschis la X` are condiția lui, pe `creditor.bankName`. Nota 1: vezi D4 și Î1 (blocantă). |
| D4 | Unde stă tabelul cu cele 7 coloane | Nota 1 e plasată în secțiunea I, dar conținutul ei („sold asupra căruia se calculează dobânda”, „dobândă calculată”, „penalități calculate”) descrie tabelul de calcul al accesoriilor, iar secțiunea II are propriul placeholder `TABEL_CALCUL_*`. **Implicit:** tabelul cu 7 coloane se randează la `TABEL_CALCUL_*` (secțiunea II), iar în secțiunea I rămâne tabelul simplu al debitelor (D8). Partialul tabelului de calcul e independent de poziție, ca mutarea lui în secțiunea I (dacă Î1 răspunde așa) să fie o schimbare de o linie în Twig. Î1 trebuie închisă înainte de demo-ul către avocat. |
| D5 | Tabelul de calcul, varianta legală | Coloane: Factură/document, Sold asupra căruia se calculează dobânda, Perioadă, Nr. zile, Rata BNR, Rata dobânzii legale penalizatoare, Dobândă calculată. Un rând pe fiecare `InterestPeriod`, pentru fiecare `ClaimItem` al debitorului destinatar. Se afișează **mereu**, și pentru 1 poziție. |
| D6 | Tabelul de calcul, varianta contractuală | Coloane: Factură, Sold, Scadență, Perioada de întârziere, Nr. zile, Rata contractuală, Penalități calculate. Un rând pe fiecare `ClaimItem` (calculatorul produce un singur `PenaltyPeriod`). |
| D7 | Afișarea perioadei (ambele tabele) | Ambele calculatoare lucrează pe interval semi-deschis: `InterestCalculatorService::buildPeriod` (`start = dueDate`, `days = diff(start, end)`) și `ContractualPenaltyCalculator::calculate` (`startDate = dueDate`, `days = diff(due, ref)`). Coloanele „Perioadă” și „Perioada de întârziere” afișează `start + 1 zi` până la `end`, astfel încât numărul de zile coincide cu intervalul inclusiv afișat. Coloana „Scadență” (contractual) afișează `dueDate` brut. **Nu** schimbăm numărarea zilelor. Separatorul „ - ” se pune în Twig. |
| D8 | Tabelul debitelor restante (`TABEL_DEBITE_RESTANTE`) | Coloane: Nr. crt, Document, Data emiterii, Scadență, Sumă (LEI), cu `signedAmountRon()` (notele de credit apar negative). Pentru pozițiile convertite din valută, nota per poziție rămâne ca azi, sub rând („X EUR × curs BNR Y din data Z”), fiindcă fiecare poziție are propriul `exchangeRate` / `exchangeRateDate`. Dosarele vechi fără `ClaimItem` primesc un rând sintetic din `invoiceNumber`, `invoiceDate`, `dueDate`, `amount`, cu nota FX la nivel de dosar (`originalCurrency`, `exchangeRate`, `exchangeRateDate`) dacă există. |
| D9 | Rotunjirea | Fiecare rând se afișează rotunjit onest la 2 zecimale (rândul rămâne egal cu `sold × rată × zile / 365`, respectiv `sold × rată/zi × zile`, recalculabil de debitor și instanță). TOTAL = `AggregatedAccessoryResult::total` (rotunjit o singură dată, pe sumă). **Nu** împingem diferența pe ultimul rând. Când suma rândurilor afișate diferă de TOTAL, sub tabel apare o notă scurtă: „Totalul este calculat pe valorile nerotunjite ale fiecărui interval.” (text nou, Î2). Pe calea veche fără poziții, `interestResult->total` / `penaltyResult->total` se rotunjesc în builder la 2 zecimale, ca `accessoryTotal` și `grandTotal` să nu mai fie float brut. |
| D10 | Concordanța cu cererea OP | Cererea OP **nu** preia totalul somației: `PaymentOrderRequestGeneratorService` recalculează cu agregatorul la `paymentNoticeDate`. Cifrele coincid doar dacă ambele folosesc aceeași dată stocată, deci D23 (reordonarea din controller) e obligatorie. Un test verifică egalitatea. |
| D11 | Eticheta accesoriilor (doar CONTRACTUAL) | Coloana nouă `legal_case.contractual_accessory_label` + enum `ContractualAccessoryLabel` (nullable, valorile nu se redenumesc niciodată, fiindcă o valoare orfană de enum crapă hidratarea). Forme de plural feminin care se acordă cu „calculate / care vor curge”: `PENALITATI_INTARZIERE` („penalități de întârziere”, implicit), `DOBANZI_PENALIZATOARE` („dobânzi penalizatoare contractuale”), `MAJORARI_INTARZIERE` („majorări de întârziere”). Pe linia TOTAL: `|upper`. **Pe ramura LEGAL eticheta nu se folosește deloc**: „dobânda legală penalizatoare” e text fix în toate locurile unde apare în `legala.txt`. |
| D12 | Titlul secțiunii II | Text fix, nederivat din etichetă: legal „II. CU PRIVIRE LA DOBÂNDA LEGALĂ PENALIZATOARE”, contractual „II. CU PRIVIRE LA DOBÂNDA PENALIZATOARE / PENALITĂȚILE CONTRACTUALE”. |
| D13 | `ARTICOL_CLAUZA_PENALITATI` | Coloană nouă `legal_case.penalty_clause_article` VARCHAR(100). Nu refolosim `contractReference` (vezi D14). Dacă lipsește: „clauza privind {label}”. |
| D14 | `contractReference` | Câmpul are azi trei roluri incompatibile: eticheta UI „Referință clauză contract”, promptul AI îl umple cu obiectul contractului, iar `ClaimItemFactory` îl folosește ca fallback pentru `causeReference` (cheia de grupare CPC art. 99). Decizie: în pasul 3 al wizard-ului câmpul `contractReference` se scoate din UI și e înlocuit de `penaltyClauseArticle`. Coloana rămâne, AI-ul continuă să o completeze, iar fallback-ul `causeReference` rămâne neatins. Nu se afișează în somație. |
| D15 | `TEXT_CLAUZA_CONTRACTUALA_PENALITATI` | Coloană nouă `legal_case.penalty_clause_text` LONGTEXT. Dacă lipsește, se înlocuiește „Potrivit {art} din {doc}, Părțile au convenit următoarele:” + citatul cu o singură frază care **nu** anticipează fraza următoare: „Părțile au convenit, potrivit {art} din {doc}, un mecanism de penalizare pentru întârzierea la plată.” Paragraful „Prin urmare, pentru neexecutarea la termen...” urmează neschimbat. |
| D16 | `MOD_CALCUL_CONFORM_CONTRACTULUI` | Fără câmp. Text derivat fix: „pentru fiecare zi de întârziere, asupra sumei rămase neachitate”. Descrie exact ce face `ContractualPenaltyCalculator`. |
| D17 | `RATA_CONTRACTUALA` | Din `contractualPenaltyRate` (`DECIMAL(5,3)`). Format cu până la 3 zecimale, zerourile finale eliminate, virgulă zecimală: 0.100 devine „0,1% pe zi de întârziere” în text și „0,1%/zi” în tabel; 0.015 devine „0,015%”. |
| D18 | `DATA_INCEPUT_CALCUL` | Derivat. O poziție: „data de {dueDate + 1 zi}”. Mai multe: „ziua imediat următoare scadenței fiecărei obligații de plată”. |
| D19 | `OBIECT_RAPORT_CONTRACTUAL` | `claimDescription` **nu** e potrivit: pentru mai multe poziții wizard-ul îl umple cu „Contravaloare N facturi neachitate: ...”, iar help-ul cere un paragraf întreg. Coloană nouă `legal_case.contract_object` VARCHAR(255), o sintagmă care se citește după „având ca obiect” (ex. „prestarea de servicii de transport”). Dacă lipsește: prima frază pierde „având ca obiect X”, a doua pierde „, respectiv X”. |
| D20 | `DOCUMENTE_CONTRACTUALE_RELEVANTE` | Derivat în PHP, în două forme gramaticale: genitiv după „în temeiul” („Contractului nr. 12 din 01.02.2024 și al facturilor fiscale emise”) și acuzativ după „rezultă din” („Contractul nr. 12 din 01.02.2024 și facturile fiscale emise”). Se aplică aceeași grupare ca cererea OP (`ClaimCauseGrouper::isSingleCause`): contractul se numește doar când există o singură cauză și `contractNumber` / `contractDate` sunt completate. Mai multe cauze sau contract necunoscut: „facturilor fiscale emise” / „facturile fiscale emise”. |
| D21 | `DOCUMENT_CONTRACTUAL` | Derivat, tot pe o singură cauză: „Contractul nr. X din data de Y”, „Contractul nr. X”, „Contractul încheiat la data de Y”. Mai multe cauze sau nimic: „contractul încheiat între părți”. |
| D22 | Rolul reprezentantului creditorului | Nu copiem „în calitate de Administrator”. Afișăm „legal reprezentată prin {Creditor::getLegalRepresentative()}”, fără rol. Dacă numele lipsește, clauza dispare (Î3). |
| D23 | Persoană fizică (creditor sau debitor) | Cerință de business, nu doar test: pentru PF **nu apare niciodată** „persoană juridică română”, „înregistrată la Registrul Comerțului”, „având CUI” sau „Sediul social”. Introducerea devine „Subscrisa, {nume}, cu domiciliul în {adresă}, ...”; în antete eticheta „Sediul social:” devine „Domiciliul:”. Nu afișăm CNP. Pentru PJ, liniile CUI, Nr. Reg. Com. și fragmentele corespunzătoare apar doar dacă au valoare. „În atenția” vine din `Debtor::getAdministrator()` și apare doar dacă există. |
| D24 | IBAN-ul | Clauzele `clause_iban` / `clause_bank` din paragraful „Subscrisa...” se **elimină**. Contul apare o singură dată, în propoziția de plată de după somare (D3). IBAN-ul vine din `PartyContactInfoTrait::getIban()`, banca din `Creditor::getBankName()`. |
| D25 | `NUMAR_SOMATIE` | Coloană nouă `legal_case.payment_notice_number` VARCHAR(50), input opțional la generare. Gol: linia devine „Data: {DATA_SOMATIE}”. Nu afișăm niciodată `caseNumber` (LR-unixtime) ca număr de registru. |
| D26 | Domiciliul procesual ales | `User::getProceduralAddress()`. Null: dispare „la adresa X”, fraza continuă cu persoana desemnată. Fără nume de avocat: paragraful dispare. „, telefon X” doar dacă `User::getPhone()` există. E-mail = `User::email`. Datele vin din `case.user` (avocatul dosarului), nu din sesiune. |
| D27 | Avocatul lipsește | Fără `user.fullName` dispar „prin X, în calitate de reprezentant convențional” și blocul „Prin: / X / Avocat”. Numărul de barou dispare (textul nou nu îl mai conține). |
| D28 | CPC | Art. 1.014 (temei, caracter cert/lichid/exigibil) și 1.015 (somația), exact ca în textul nou. `pdf.payment_order.subheading` (art. 1013-1024) nu se atinge aici; rămâne pentru pasul de renumerotare CPC deja deschis. |
| D29 | Temeiul și imputația diferă textual | Temei legal: „art. 1.535 din Codul civil”; contractual: „art. 1.270, art. 1.535 și, după caz, art. 1.538-1.539 din Codul civil”. Imputație legală: „ulterior asupra dobânzilor datorate” / „a dobânzilor datorate”; contractuală: „ulterior asupra dobânzilor și penalităților datorate” / „a accesoriilor contractuale datorate”. Partialul `_section_imputation` are două chei per frază. |
| D30 | Somarea diferă textual | Legal: „dobândă legală penalizatoare calculată” și „a dobânzii legale penalizatoare calculate” ca text fix. Contractual: `{label}` în liniile de sumă și „la care se adaugă”, dar „a accesoriilor contractuale calculate” fix în paragraful de sesizare a instanței. Aceeași regulă pentru rezumatul de sub „SOMAȚIE DE PLATĂ”. |
| D31 | „prin Contract” (contractual, secțiunea II final) | Afișăm „stabilite prin contract”, cu literă mică (textul nu definește termenul „Contract”). Semnalăm (Î5). |
| D32 | Costurile de recuperare (`legalCostsFixed`) | Urmăm textul nou: secțiunea iese din somație. Câmpurile entității rămân neatinse (Î6). |
| D33 | Salutul | Se păstrează „Stimate Domn / Stimată Doamnă,” (`pdf.summons.salutation_generic` devine cheie v3). Se elimină doar salutul personalizat cu numele administratorului. |
| D34 | Limba documentului | Somația e act juridic românesc: se randează **întotdeauna** în `ro`, indiferent de limba UI a avocatului, prin `LocaleSwitcher::runWithLocale('ro', ...)` în `PaymentNoticeGeneratorService`. Cheile EN există doar pentru lockstep. |
| D35 | Mai mulți debitori | Somația rămâne adresată primului debitor (Î7), dar pozițiile se filtrează la `item.debtor === null || item.debtor === destinatar`, iar debitul principal și accesoriile se calculează **pe pozițiile filtrate** (suma `signedAmountRon()`), nu pe `case.amount`. Altfel primului debitor i se cer facturile altuia. Pe calea fără poziții rămâne `case.amount`. |
| D36 | Condițiile de generare (`SummonsReadiness`) | Somația nu se generează dacă: (a) dosarul e CONTRACTUAL fără `contractualPenaltyRate` (azi agregatorul cade tăcut pe dobânda legală); (b) există poziții care contează, dar agregatorul întoarce `isEmpty()` (toate sărite sau note de credit), caz în care azi builder-ul recalculează tăcut pe `case.amount` / `case.dueDate`; (c) nu există poziții și lipsește `dueDate` sau `amount`, adică totalul ar veni din `calculatedInterest` stocat, fără bază (CPC 1.016); (d) o poziție a destinatarului nu e încă scadentă la data generării (calculatorul întoarce total 0 și `breakdown` gol, fără să o treacă în `skippedItemIds`; creanța nu e exigibilă în sensul art. 1.014). Un `breakdown` gol înseamnă „niciun rând”, nu un rând cu zero. |
| D37 | Plăți parțiale înregistrate | `ClaimItem::paidAmount` există și e „înregistrat, niciodată imputat”. Dacă vreo poziție a destinatarului are `hasUnimputedPayment()`, cardul afișează un avertisment vizibil înainte de generare („există plăți parțiale înregistrate care nu sunt reflectate în sold”). Nu blocăm până la Î4. „Sold” rămâne `amountRon` brut. |
| D38 | Răspunsul la blocare | Butonul nu se oferă când acțiunea e imposibilă: motivul apare în locul lui, în toate cele 4 puncte de intrare (D39). Dacă totuși vine un POST, controllerul răspunde prin Turbo Stream cu motivul, fără redirect. |
| D39 | Punctele de intrare ale generării | `_documents_generated_list.html.twig`, `_hero_actions.html.twig`, `_recommended_actions.html.twig` și agenda globală (`DeadlineRowActionResolver`, ruta `case_summons_generate`). Motivul de blocare ajunge în view-uri prin `OverviewContextBuilder::build` și în agendă prin resolver (acțiunea devine dezactivată cu motiv). Input-ul de număr stă doar în formularul din `_hero_actions`; celelalte puncte postează fără număr. |

## 1. Felia 1: entități, enum, migrare

**Fișiere**
- `src/Enum/ContractualAccessoryLabel.php`: enum string cu `label()` (`enum.contractual_accessory_label.*`) și `phrase()` (`pdf.summons.v3.accessory_label.*`).
- `src/Entity/LegalCase.php`: `paymentNoticeNumber` (?string 50), `penaltyClauseArticle` (?string 100), `penaltyClauseText` (?text), `contractualAccessoryLabel` (?ContractualAccessoryLabel, `enumType`, nullable), `contractObject` (?string 255), cu getteri și setteri. Docblock-urile noi în engleză.
- `migrations/VersionYYYYMMDDHHMMSS.php`: 5 coloane NULL, generate cu `make migrate-diff`, verificate manual. Fără backfill: null înseamnă fallback.

**Teste**
- `tests/Enum/ContractualAccessoryLabelTest.php` (TestCase): fiecare caz are etichetă și frază; valorile sunt stabile.
- `tests/Entity/LegalCaseEntityTest.php`: round-trip pe cele 5 câmpuri noi.
- `php bin/console doctrine:schema:validate` trece.

**Verificare live:** `make migrate`, `make restart`, apoi overview-ul unui dosar existent se deschide fără eroare.

## 2. Felia 2: modelul de afișare (builder, tabele, fraze, readiness)

**Fișiere**
- `src/DTO/Summons/PrincipalRow.php`: `documentLabel`, `issueDate`, `dueDate`, `amount`, plus metadatele FX ale poziției (`currency`, `originalAmount`, `exchangeRate`, `exchangeRateDate`).
- `src/DTO/Summons/LegalInterestRow.php`: `documentLabel`, `balance`, `periodStart` (deja +1 zi), `periodEnd`, `days`, `nbrRate`, `applicableRate`, `interest` (rotunjit la afișare).
- `src/DTO/Summons/ContractualPenaltyRow.php`: `documentLabel`, `balance`, `dueDate`, `periodStart` (deja +1 zi), `periodEnd`, `days`, `dailyRate`, `penalty`.
- `src/Service/Document/Summons/SummonsTableBuilder.php` (pur, fără DI): rânduri din pozițiile filtrate (D35) și `AggregatedAccessoryResult` (`interestByItemId` / `penaltyByItemId`); rândul sintetic pentru dosarele vechi. Aplică D7 (ambele tabele) și D9 (inclusiv rotunjirea căii vechi). Pozițiile din `skippedItemIds` și cele cu `breakdown` gol nu apar în tabelul de calcul. Expune `roundingNoteNeeded`.
- `src/Service/Document/Summons/SummonsPhraseComposer.php` (pur): `documentsGenitive()`, `documentsAccusative()`, `contractDocument()` (cu `isSingleCause` primit ca parametru), `calculationStart()`, `ratePhrase()` / `rateCell()` (D17), `clauseArticle()`. Întoarce chei de traducere și parametri, fără text RO în PHP.
- `src/Service/Document/Summons/SummonsReadiness.php`: `check(LegalCase): ?string` (cheia motivului de blocare, D36) și `warnings(LegalCase): list<string>` (D37). Refolosește agregatorul, nu duplică regulile.
- `src/Service/Document/SummonsContextBuilder.php`: adaugă `principalRows`, `principalTotal`, `legalInterestRows`, `contractualPenaltyRows`, `isContractual`, `accessoryLabel` (setat **doar** pe CONTRACTUAL, implicit `PENALITATI_INTARZIERE`; null pe LEGAL), `recipientDebtor`, frazele compuse, `roundingNoteNeeded`. Scoate `interestResult` / `penaltyResult` din context după ce șablonul nu le mai folosește. `refDate` rămâne `paymentNoticeDate` sau azi.

**Teste**
- `tests/Service/Document/Summons/SummonsTableBuilderTest.php` (TestCase):
  - `testLegalRowsSplitAtEveryBnrRateChange`
  - `testLegalRowsRenderForASingleClaimItem`
  - `testLegalRowsRenderOneBlockPerClaimItem`
  - `testLegalPeriodStartIsDisplayedAsTheDayAfterDueDate`
  - `testContractualDelayPeriodStartsTheDayAfterDueDateWhileDueDateColumnStaysRaw`
  - `testEveryDisplayedRowEqualsBalanceTimesRateTimesDays`
  - `testRoundingNoteAppearsOnlyWhenRowsDoNotAddUpToTotal`
  - `testLegacySingleSumTotalIsRoundedToTwoDecimals`
  - `testCreditNoteAppearsNegativeInPrincipalRowsAndNotInAccessoryRows`
  - `testSkippedItemIsListedAsPrincipalButHasNoAccessoryRow`
  - `testItemWithEmptyBreakdownYieldsNoAccessoryRow`
  - `testLegacyCaseWithoutClaimItemsYieldsOneSyntheticRow`
  - `testItemsOfAnotherDebtorAreExcluded`
  - `testContractualRowCarriesDueDateAndDailyRate`
- `tests/Service/Document/Summons/SummonsPhraseComposerTest.php` (TestCase): cele două forme gramaticale ale documentelor; combinațiile contractului (număr/dată/niciuna); o cauză vs. mai multe cauze; data de început pentru 1 și N poziții; formatul ratei (0.100, 0.015, 1.000).
- `tests/Service/Document/Summons/SummonsReadinessTest.php` (TestCase): CONTRACTUAL fără rată blocat; toate pozițiile sărite sau note de credit blocat; dosar fără poziții și fără `dueDate` blocat; poziție nescadentă blocată; dosar LEGAL valid trece; plată parțială produce avertisment, nu blocare.
- `tests/Service/Document/SummonsContextBuilderTest.php` (rămâne TestCase cu repository anonim): se rescriu cele 4 teste care citesc `interestResult` / `penaltyResult` pe noile chei; se adaugă `testMultiItemLegalCaseExposesPerPeriodRows`, `testContractualCaseExposesAccessoryLabelDefault`, `testLegalCaseExposesNoAccessoryLabel`.

**Verificare live:** nimic vizibil încă. `make test` verde.

## 3. Felia 3: șablonul Twig și traducerile PDF

**Fișiere**
- `templates/pdf/payment_notice.html.twig`: rescris după D1.
- Partiale noi în `templates/pdf/summons/`:
  - `_parties_header.html.twig` (CĂTRE / DE LA, PJ vs. PF după D23)
  - `_intro.html.twig` (Subscrisa fără IBAN, domiciliul procesual ales, temeiul ramificat D29)
  - `_composition.html.twig` (lista „compus din”, ramificată D30, folosită la început și la somare)
  - `_section_principal.html.twig` (tabelul debitelor, nota FX per poziție, art. 1.270, art. 1.014)
  - `_calculation_table.html.twig` (tabelul cu 7 coloane, legal sau contractual; poziționare după D4)
  - `_section_legal_interest.html.twig`
  - `_section_contractual.html.twig` (clauza sau fallback D15, eticheta D11, titlul fix D12)
  - `_section_imputation.html.twig` (ramificat D29)
  - `_closing.html.twig` (VĂ SOMĂM, propoziția de plată condiționată, sesizarea instanței ramificată, art. 1.015, semnătura)
- Ce se elimină: caseref, salutul personalizat, IBAN-ul din intro (D24), numărul de barou, vechiul tabel de sume, „În fapt”, blocul de costuri (D32), deadline box, footer-ul platformei, `claim_object_label`.
- `src/Service/Document/PaymentNoticeGeneratorService.php`: randare în `ro` (D34).
- `translations/messages.ro.yaml` și `messages.en.yaml`: `pdf.summons.v3.*`, RO după D2 (inclusiv `(2¹)`), EN echivalent, chei în lockstep, fără em-dash. Articolele cu punct: 1.014, 1.015, 1.270, 1.507, 1.535, 1.538, 1.539. `php bin/console lint:yaml translations/`.

**Teste** (`tests/Service/Document/PaymentNoticeGeneratorServiceTest.php`, KernelTestCase)
- Se rescriu: `testRenderHtmlContainsAllStructuralSections` (I/II/III, VĂ SOMĂM, art. 1.015), `testLegalPenaltyRendersInterestBreakdownTable` (cele 7 antete legale), `testContractualPenaltyRendersDailyRateLine` devine `testContractualPenaltyRendersSevenColumnTable`, `testRenderHtmlShowsClaimObjectWhenDescriptionSet` devine `testRenderHtmlShowsContractObjectWhenSet`.
- Se inversează: `testRenderHtmlKeepsTheBarRollNumberOnTheSummons` devine `testSummonsNoLongerPrintsBarRollNumber`; `testContractualPenaltyRendersLegalCostsSection` devine `testSummonsOmitsLegalCostsSection`.
- Se adaugă:
  - `testPaymentSentenceIsOmittedWithoutIban`
  - `testPaymentSentenceOmitsBankWhenBankNameMissing`
  - `testIbanAppearsOnlyInThePaymentSentence`
  - `testRedLawyerNotesNeverReachThePdf` („de pus conditie”, liniile de coloane în proză)
  - `testNoUnrenderedPlaceholderOrEmptyNumberLine` (fără `{{`, `%`, „Nr.  /”, `2^1`)
  - `testCitesParagraphTwoSuperscriptOne`
  - `testCreditorRoleIsNeverHardcodedAsAdministrator`
  - `testPfCreditorNeverClaimsLegalEntityStatus` (fără „persoană juridică”, „Registrul Comerțului”, „Sediul social”)
  - `testPfDebtorHeaderUsesDomicileLabel`
  - `testMissingProceduralAddressDropsAddressFragment`
  - `testMissingPhoneDropsPhoneFragment`
  - `testContractualFallbackSentenceWhenClauseTextMissing`
  - `testAccessoryLabelIsUppercasedOnTotalLine`
  - `testLegalSummonsNeverMentionsContractualAccessoryLabel`
  - `testSectionTwoHeadingIsFixedForEveryAccessoryLabel`
  - `testImputationWordingDiffersBetweenLegalAndContractual`
  - `testCitesArticle1014NotArticle1013`
  - `testMultiItemLegalCaseListsEveryPeriodOfEveryInvoice`
  - `testFxNoteIsPerConvertedItem`
  - `testSummonsIsRenderedInRomanianEvenForEnglishUser`
  - `testRenderedHtmlContainsNoEmDash`

**Verificare live (Playwright, `avocat@test.com`)**
1. Dosar LEGAL cu 2 facturi, una traversând o schimbare de rată BNR, creditor cu IBAN și bancă: generezi și deschizi PDF-ul. Ambele facturi au rânduri separate pe intervale BNR, fiecare rând se verifică de mână, TOTAL e corect, propoziția de plată apare o singură dată.
2. Același dosar cu IBAN golit: propoziția de plată lipsește.
3. Dosar CONTRACTUAL cu 1 factură și rată 0,1: tabel cu 7 coloane, „Perioada de întârziere” începe a doua zi după „Scadență”, eticheta implicită apare peste tot, o dată cu majuscule; titlul secțiunii II e cel fix.
4. Dosar vechi fără ClaimItems: rândul sintetic.
5. Avocat cu UI în EN: PDF-ul e în română.

## 4. Felia 4: generarea (număr, blocare, dată)

**Fișiere**
- `src/Controller/Case/CaseSummonsController.php`:
  - ordinea: CSRF, status, debitor, **`SummonsReadiness`**, apoi rate limiter, apoi paywall (o încercare blocată nu consumă nici token, nici slot);
  - la blocare răspunde prin Turbo Stream cu motivul (D38);
  - citește `payment_notice_number` opțional (trim, max 50, constrângere `Length` cu argumente numite);
  - în tranzacție: `setPaymentNoticeNumber`, **`setPaymentNoticeDate(new \DateTime())` înainte de `generate()`** (schimbare reală: azi PDF-ul folosește „azi”, iar cererea OP data stocată), apoi `generate()` și tranziția;
  - audit `newData` primește și `paymentNoticeNumber`.
- `src/Service/Case/OverviewContextBuilder.php`: expune `summonsBlockReason` și `summonsWarnings`.
- `templates/case/overview/_hero_actions.html.twig`: input opțional „Nr. înregistrare somație”; buton ascuns și motiv afișat la blocare; avertismentul D37.
- `templates/case/overview/_documents_generated_list.html.twig`, `_recommended_actions.html.twig`: aceeași poartă, fără input.
- `src/Service/Deadline/DeadlineRowActionResolver.php`: acțiunea de generare devine dezactivată, cu motiv, când readiness blochează.
- `translations/messages.{ro,en}.yaml`: `case_overview.summons.notice_number_label` / `_help`, `blocked_no_rate`, `blocked_no_computable_row`, `blocked_no_due_date`, `blocked_not_yet_due`, `warning_unimputed_payment`.

**Teste**
- `tests/Controller/Case/CaseSummonsControllerTest.php` (WebTestCase): fixture-ul din `setUp` primește `dueDate` (azi are doar `amount = 3000`, deci readiness ar bloca `testGenerateSummonsHappyPath*`, variantele Turbo Stream, cele din agendă și `testCasePageStatesTheSummonsIsInServiceUntilTheDateIsRecorded`). Teste noi:
  - `testGenerateStoresPaymentNoticeNumber`
  - `testGenerateWithoutNumberPrintsDateOnly`
  - `testContractualCaseWithoutRateCannotGenerate` (niciun token de rate limit, niciun slot, nicio tranziție)
  - `testBlockedGenerationRespondsWithTurboStreamNotRedirect`
  - `testNoticeDateInPdfEqualsStoredPaymentNoticeDate`
  - `testSummonsAccessoryTotalEqualsPaymentOrderAccessoryTotal`
  - `testAuditLogRecordsPaymentNoticeNumber`
- Fixture-urile din `tests/Controller/DeadlineAgendaActionsTest.php`, `DeadlineAgendaDialogsTest.php`, `tests/Controller/Case/CaseDeadlineControllerTest.php` și `CaseDeadlineTabRegressionTest.php` se verifică și primesc `dueDate` unde generează somația.
- `tests/Service/Deadline/DeadlineRowActionResolverTest.php`: `testSummonsActionIsDisabledWithReasonWhenNotReady`.

**Verificare live:** generezi cu „868”, PDF-ul arată „Nr. 868 / {data}”; fără număr apare doar data; dosar CONTRACTUAL fără rată: niciun buton activ în cele 3 locuri din overview și în agendă, motivul vizibil.

## 5. Felia 5: câmpurile noi în wizard și editarea după creare

**Fișiere, wizard**
- `src/DTO/Wizard/Step3ClaimData.php`: `penaltyClauseArticle` (`Length(max: 100)`), `penaltyClauseText` (`Length(max: 10000)`), `contractualAccessoryLabel` (enum), validate doar pe CONTRACTUAL prin tiparul existent `Assert\When(expression: 'this.penaltyType === enum(...CONTRACTUAL)')`; `contractObject` (`Length(max: 255)`) pe ambele ramuri.
- `src/Form/Wizard/Step3ClaimType.php`, `src/Twig/Components/Step3ClaimLiveComponent.php`, `templates/components/Step3ClaimLiveComponent.html.twig`: blocul contractual primește articolul, textarea pentru clauză și select pentru denumire; `contractReference` iese din UI (D14); câmp nou „Obiectul contractului” cu help care arată că se citește după „având ca obiect”. Clauza goală: avertisment soft.
- `src/Controller/Case/CaseWizardController.php`: `applyAccessoryFields` persistă câmpurile noi (punctul existent, apelat din ambele fluxuri de creare), cu `StringCapper` pe lungimi.

**Fișiere, editare după creare** (altfel dosarele CONTRACTUAL existente rămân blocate pe fallback)
- `src/Controller/Case/CaseSummonsDetailsController.php` (sau o acțiune pe controllerul existent de overview): GET modal + POST, CSRF, `CaseVoter::EDIT`, permis doar în AMIABIL.
- `src/Form/Case/SummonsDetailsType.php`: `contractObject`, `penaltyClauseArticle`, `penaltyClauseText`, `contractualAccessoryLabel`, `contractualPenaltyRate` (ultimele trei doar pe CONTRACTUAL).
- `templates/case/overview/_summons_details_modal.html.twig`, link „Completează detaliile somației” lângă butonul de generare; când motivul de blocare e `blocked_no_rate`, motivul trimite direct în modal. Răspuns prin Turbo Stream, erorile de validare rămân în modal.
- `src/Controller/Admin/LegalCaseCrudController.php`: câmpurile noi, pentru suport.
- Traduceri wizard + modal + `enum.contractual_accessory_label.*`, RO/EN în lockstep.

**Teste**
- `tests/DTO/Wizard/Step3ClaimDataTest.php` (existent, extins): constrângerile pe CONTRACTUAL, nicio validare a clauzei pe LEGAL.
- `tests/Controller/Case/CaseWizardControllerStep1To4Test.php`: `testStep3PersistsPenaltyClauseFields`, `testStep3PersistsContractObject`.
- `tests/Controller/Case/CaseSummonsDetailsControllerTest.php` (WebTestCase): `testLawyerCanFillClauseAfterCaseCreation`, `testDetailsCannotBeEditedAfterSummonsWasSent`, `testOtherUserCannotEditDetails`, `testInvalidInputStaysInModalWithoutRedirect`.

**Verificare live:** wizard pe CONTRACTUAL cu „art. 7.2”, clauză și „majorări de întârziere”, generezi: PDF-ul are „Potrivit art. 7.2 din Contractul nr. ..., Părțile au convenit următoarele:” + citat și „majorări de întârziere” peste tot. Apoi un dosar CONTRACTUAL vechi: modalul completează clauza, generarea preia textul.

## 6. Felia 6 (după Î8): extracția AI a clauzei și a obiectului

Se adaugă `penaltyClauseArticle`, `penaltyClauseText`, `contractualAccessoryLabel` și `contractObject` în: `ClaimExtraction`, `ContractPrompt`, `SharedPromptFragments` (promptul `contractReference` se corectează: obiectul merge în `contractObject`), `InvoicePrompt` (referința la `contractReference`), `AiVisionExtractionStrategy`, `CoherentAggregator`, `ConflictResolutionService`, `PrefillFromExtractionService` (allowlist-ul de câmpuri și construirea DTO-ului), `FieldGroup`. Fallback-ul `causeReference` din `ClaimItemFactory` rămâne neatins.

Teste pe 3 niveluri: unit pe parser, integrare pe prefill, adversarial pe răspuns AI malformat sau clauză trunchiată.

**Verificare live:** încarci un contract cu clauză penală; wizard-ul vine precompletat cu articol, text, denumire și obiect.

## 7. Felia 7: curățenie și regresie

- Se șterg cheile vechi `pdf.summons.*` nefolosite (grep în `templates/` și `src/`).
- `./bin/phpunit` complet (strict pe deprecation, notice, warning), `lint:yaml translations/`, `doctrine:schema:validate`, `make restart`.
- Grep: nicio liniuță de tip em-dash (U+2014) în cheile noi și în partialele noi; niciun `2^1`.
- Verificare live finală: somația pe un dosar de fiecare tip, citită cap-coadă față de `legala.txt` / `contractuala.txt`, paragraf cu paragraf, cu abaterile intenționate (D2, D9, D15, D22, D23, D31) bifate explicit.

## 8. Întrebări închise pentru avocat

1. **Î1 (blocantă pentru demo) Poziția tabelului cu 7 coloane.** Nota cu coloanele e plasată sub tabelul debitelor din secțiunea I. Am înțeles că descrie tabelul de calcul al dobânzii / penalităților din secțiunea II, iar în secțiunea I rămâne un tabel simplu (Nr. crt, Document, Data emiterii, Scadență, Sumă). Corect (a), sau doriți un singur tabel combinat în secțiunea I (b)? *(implicit: a)*
2. **Î2 Rotunjirea.** Fiecare rând al tabelului e rotunjit la bani, iar totalul e calculat pe valori nerotunjite, deci poate diferi de suma rândurilor cu câțiva bani. Acceptați nota „Totalul este calculat pe valorile nerotunjite ale fiecărui interval.” (Da / Nu, preferați rotunjirea pe fiecare rând și totalul ca sumă a rândurilor)
3. **Î3 Rolul reprezentantului creditorului.** Textul are „în calitate de Administrator” fix. Preferați (a) fără rol („legal reprezentată prin X”) sau (b) un câmp „Calitate reprezentant” per creditor? *(implicit: a)*
4. **Î4 Plăți parțiale.** Platforma poate înregistra plăți parțiale pe o factură, dar nu le impută. Când există o astfel de plată, somația trebuie (a) blocată până la imputare, (b) generată pe soldul brut, cu avertisment doar pentru avocat, sau (c) generată pe suma rămasă (factură minus plată)? *(implicit azi: b)*
5. **Î5 „stabilite prin Contract”** (contractual, finalul secțiunii II). Acceptați „stabilite prin contract”, cu literă mică? (Da/Nu)
6. **Î6 Costurile de recuperare** (onorariu fix și de succes) nu mai apar în somație. Intenționat? (Da / Nu, păstrați ca secțiune opțională)
7. **Î7 Mai mulți debitori.** O somație separată pentru fiecare debitor, cu facturile lui (a), sau una singură adresată tuturor, solidar (b)? *(implicit: prima somație, doar către primul debitor, cu facturile lui)*
8. **Î8 Textul clauzei penale și obiectul contractului.** Le completați manual (a) sau doriți extragerea automată din contract, cu verificare ulterioară (b)? (b implică trimiterea contractului la AI: consimțământ GDPR și secret profesional)
9. **Î9 Art. 1.522 Cod civil** (punerea în întârziere) nu mai apare în temei. Omisiune voită? (Da/Nu)
10. **Î10 Numărul somației.** E numărul din registrul de ieșire al cabinetului, introdus manual la generare? (Da/Nu)
11. **Î11 Denumirea accesoriilor.** Lista „penalități de întârziere / dobânzi penalizatoare contractuale / majorări de întârziere” acoperă practica? (Da / Nu, adăugați: ...)
12. **Î12 Rata contractuală** e suportată doar ca procent pe zi. Aveți contracte cu rată lunară sau anuală, ori cu plafon („fără a depăși debitul”) care trebuie suportate acum? (Da/Nu)
13. **Î13 Creditor persoană fizică.** Pentru PF folosim „Subscrisa, {nume}, cu domiciliul în {adresă}” și „Domiciliul:” în antet, fără CNP. Corect? (Da / Nu, CNP-ul trebuie să apară)
14. **Î14 Creanțe în valută.** Păstrăm sub fiecare factură convertită nota „X EUR × curs BNR Y din data Z”? (Da/Nu)

## 9. Riscuri reziduale, nerezolvate aici

- La o schimbare de rată BNR, ziua `validFrom` se calculează cu rata veche (interval `(start, end]`). Chestiune juridică separată.
- `pdf.payment_order.subheading` citează încă art. 1013-1024; renumerotarea CPC rămâne în pasul dedicat.
- Pe dosarele cu mai mulți debitori, somația (filtrată pe destinatar, D35) și cererea OP (toate pozițiile) pot avea totaluri diferite până la răspunsul la Î7.
- `contractReference` rămâne în DB cu sens ambiguu; iese doar din UI (D14). Retragerea completă cere o decizie separată asupra fallback-ului `causeReference`.
