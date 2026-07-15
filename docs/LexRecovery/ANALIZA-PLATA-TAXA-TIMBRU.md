# ANALIZĂ: integrarea plății taxei judiciare de timbru

> Document de decizie (research + recomandare), NU implementare.
> Data: 2026-07-14. Input: discuție avocat Laurentiu Asaftei (13.07.2026), verificare juridică internă (avocat-senior), inventar cod branch `lexrecovery`.
> Output cerut: la ce pas integrăm plata taxei și sub ce formă.
> Revizuit 2026-07-14 de cei doi reviewers juridici (avocat-senior + legal-reviewer): verdict CONFORM CU OBSERVAȚII, zero blockere. Observațiile lor sunt integrate în text (CPC art. 197, art. 40 alin. 3, D4 legat de D1, D6 cheltuieli de judecată, capcanele UAT, GDPR, model de date extins).
> **IMPLEMENTAT 2026-07-14** (opțiunea C). Vezi §9 pentru ce s-a livrat și ce a rămas deschis.

---

## 1. Problema

Taxa judiciară de timbru pentru ordonanța de plată este de 200 lei fix (OUG 80/2013 art. 6 alin. 2). Astăzi platforma o **calculează și o afișează**, dar nu știe nimic despre **plata** ei. Cererea OP generată de noi scrie „Taxă timbru (anexată)”, ceea ce presupune o anexă pe care nimeni nu o cere și nimeni nu o verifică.

Consecința, formulată de avocat: dosarul poate ajunge la instanță netimbrat. Instanța ar trebui să pună în vedere achitarea în procedura de regularizare, dar dacă grefa omite, cererea se poate anula ca netimbrată la termen. Riscul cade pe avocatul care a folosit platforma noastră.

---

## 2. Cadrul legal (verificat)

| Aspect | Regulă | Temei |
|---|---|---|
| Cuantum OP | 200 lei fix, indiferent de valoarea creanței și de numărul de debitori | OUG 80/2013 art. 6 alin. 2 |
| Moment | Plată **anticipată** | OUG 80/2013 art. 33 alin. 1 |
| **Dovada se atașează cererii** | „Dacă cererea este supusă timbrării, **dovada achitării taxelor datorate se atașează cererii**. Netimbrarea sau timbrarea insuficientă atrage **anularea** cererii de chemare în judecată, în condițiile legii.” | **CPC art. 197** |
| Netimbrare, remediu | Reclamantul e înștiințat, are **10 zile** să timbreze și să transmită dovada, altfel cererea se anulează | OUG 80/2013 art. 33 alin. 2, care trimite la CPC art. 200 alin. 2 teza I |
| Cine plătește | **Debitorul taxei**, adică reclamantul (creditorul) | OUG 80/2013 art. 40 alin. 1 |
| Unde | Contul „Taxe judiciare de timbru și alte taxe de timbru” al **UAT-ului unde reclamantul are sediul social** (nu instanța, nu debitorul) | OUG 80/2013 art. 40 alin. 1 |
| Reclamant fără sediu în România | Contul UAT-ului unde se află **sediul instanței** | OUG 80/2013 art. 40 alin. 2 |
| **Ce constituie dovadă** | Ordinul de plată **semnat de debitor și vizat de instituția de credit**, extrasul de cont emis de banca plătitoare, sau orice alt document care atestă viramentul recunoscut de lege. Acestea **prezumă efectuarea plății, până la proba contrară**. | **OUG 80/2013 art. 40 alin. 3** |
| Plată în numerar la instanță | Posibilă la ghișeele deschise la sediile instanțelor | OUG 80/2013 art. 40 alin. 1^1 |
| Restituire | La cerere, în termen de **1 an**, dacă taxa nu era datorată | OUG 80/2013 art. 45 |

Patru observații care schimbă design-ul:

**(a) „Dovada se atașează cererii” este literă de lege, nu bună practică.** CPC art. 197 o spune explicit. Intuiția avocatului („dovada plății să ajungă la instanță împreună cu celelalte documente”) nu e o preferință de proces: e chiar norma. Asta transformă includerea dovezii în pachetul de depunere dintr-un nice-to-have într-o cerință.

**(b) Omisiunea grefei nu ne salvează.** Timbrajul este chestiune de ordine publică: excepția netimbrării este absolută și poate fi invocată din oficiu în orice stadiu al procedurii, inclusiv la soluționarea cererii sau la emiterea ordonanței, chiar în lipsa unui termen de judecată clasic (în OP, dacă debitorul nu formulează întâmpinare, instanța poate soluționa fără dezbateri ample). Deci nu putem construi fluxul pe premisa „dacă grefierul nu zice nimic, e în regulă”.

**(c) Plătitorul trebuie să fie identificabil ca reclamant.** Art. 40 alin. 3 vorbește de „ordinul de plată **semnat de debitor**”, iar debitorul taxei este reclamantul. Legea nu interzice ca transferul să pornească din contul avocatului, dar dacă dovada nu îl identifică pe reclamant ca debitor al taxei, prezumția de plată din art. 40 alin. 3 devine discutabilă. Analiza INM (Solomon C.M.) tratează plata ajunsă în contul altei UAT ca **taxă neefectuată**, cu risc de anulare. Riscul cel mai subtil nu e *dacă* s-a plătit, ci *cine* a plătit și *în ce cont*.

**(d) Taxa se recuperează de la debitor doar dacă o cerem.** Vezi §6, D6: cererea OP pe care o generăm azi nu solicită cheltuielile de judecată.

**(e) Există deja o soluție instituțională pentru exact acest caz.** Portalul CSM `registratura.rejust.ro` a introdus „plățile pentru terți”: avocatul completează formularul, generează un **link de plată** și îl trimite clientului (e-mail, SMS), iar clientul achită el însuși. Portalul plătește prin ghiseul.ro, generează dovada, iar dovada **pleacă automat la instanță împreună cu cererea și înscrisurile**. Dovada generată de portal se încadrează în „orice alt document care atestă viramentul recunoscut de lege” (art. 40 alin. 3), deci beneficiază de aceeași prezumție de plată ca și chitanța de la primărie (interpretare, nu citat de lege). Acesta este precedentul pe care merită să-l urmăm, nu să-l reinventăm.

---

## 3. Ce există azi în aplicație

| Există | Nu există |
|---|---|
| `StampDutyCalculator` (constantă 200 RON, `config/services.yaml:11`) | Orice noțiune de „taxă achitată” |
| `LegalCase.stampDuty` (scris o singură dată, la finalul wizardului, `CaseWizardController.php:621`) | Câmp de status al plății, dată, plătitor, referință chitanță |
| Afișare în Step 3 live, Step 4, KPI overview, PDF cerere OP | `DocumentType` pentru dovada de plată (cel mai apropiat: `DOVADA` generic) |
| `CaseFilesPackager` (ZIP: cerere OP + opis + somație + anexe) | Dovada în ZIP și în opis |
| Tranziția `depune_cerere` | Orice guard pe workflow (fișierul `workflow.yaml` nu are niciun `guard:`) |
| `Court.email` (câmp populat, dar **nefolosit nicăieri în cod**) | Trimitere electronică către instanță (spike rejust: NO-GO, nu are API, cere semnătura calificată a avocatului) |

**Defectul semantic care trebuie decis odată cu asta:** tranziția `depune_cerere` se aplică azi în `CasePaymentOrderController::generate` (`:106`), adică în momentul în care avocatul apasă „Generează cerere OP”. Statusul devine `CERERE_DEPUSA` deși nimic nu a fost depus: avocatul abia urmează să descarce ZIP-ul și să meargă la instanță. Cererea avocatului („condiționăm trimiterea documentelor de încărcarea dovezii”) nu are unde să se agațe curat, fiindcă momentul „trimit la instanță” nu e modelat separat de „generez documentele”.

---

## 4. Opțiuni de arhitectură a plății

### Opțiunea A: colectăm noi cei 200 lei prin Netopia și îi virăm la UAT
**Respinsă.** Dublu risc, ambele în afara zonei noastre de competență:
- **Statutul profesiei de avocat** (fonduri ale clientului, avansuri pentru cheltuieli, cont fiduciar distinct). Există o hotărâre interpretativă recentă a Consiliului UNBR (nr. 325/18.06.2026) exact pe acest articol, pe care nu am putut-o citi (acces blocat).
- **Reglementarea serviciilor de plată** (PSD2 / Legea 209/2019): colectarea de fonduri de la plătitor și transferul către un beneficiar terț se apropie de prestarea de servicii de plată, care cere autorizare BNR.

Chiar dacă ambele s-ar rezolva, câștigul de UX nu justifică efortul: taxa oricum trebuie să ajungă în contul UAT-ului corect, pe numele reclamantului, iar noi am introduce un intermediar în plus exact pe veriga unde greșeala costă anularea cererii.

### Opțiunea B: mapăm conturile de trezorerie și afișăm IBAN-ul de plată
**Fezabilă tehnic, dar periculoasă ca produs.** Conturile par să aibă structură previzibilă (`RO` + cifră de control + `TREZ` + codul de trezorerie al UAT-ului + codul de venit bugetar `21070203` + `XXXXX`), deci ar fi derivabile algoritmic pentru cele ~3.200 de UAT-uri. Formula e observată din exemple publicate de instanțe și primării, **nu confirmată de o sursă oficială**. Problema nu e derivarea, ci **verificarea**: nu există un registru oficial consolidat, iar o singură mapare greșită înseamnă bani viraţi în contul altei UAT, adică taxă neachitată în sensul legii și cerere anulabilă. Ne-am asuma un risc juridic care astăzi e al avocatului și pe care nu îl putem acoperi la scară.

Dacă totuși vrem să ajutăm cu datele de plată (avocatul a cerut explicit: „un ajutor ar însemna să indicăm noi datele de plată”), varianta responsabilă este: afișăm **UAT-ul corect** (dedus din sediul social al creditorului, pe care îl avem deja din ANAF) și **denumirea exactă a contului**, plus link către site-ul primăriei respective, fără să afirmăm noi IBAN-ul. Adică rezolvăm partea grea (care primărie?) și nu ne asumăm partea riscantă (ce IBAN?).

### Opțiunea C: ghidăm plata către canalul oficial și cerem dovada în platformă
**Recomandată.** Platforma nu atinge banii. Face trei lucruri:
1. **Spune clar cine, cât, unde**: 200 lei, plătibili de creditorul X, la UAT-ul sediului său social (calculat de noi), în contul „Taxe judiciare de timbru și alte taxe de timbru”.
2. **Trimite spre canalul care rezolvă corect maparea**: `registratura.rejust.ro` (plata cu cardul prin ghiseul.ro, cu opțiunea „plata pentru terți” prin care achită chiar clientul, iar dovada ajunge la instanță odată cu cererea) sau ghiseul.ro direct. Când dosarul are deja număr, link direct către formularul de plată într-un dosar existent, util fix pentru cazul regularizării.
3. **Cere dovada înapoi în platformă** ca document, o include în ZIP și în opis, și condiționează pasul de depunere de existența ei.

---

## 5. Recomandare: la ce pas și sub ce formă

### Poziția în flux
Plata **nu depinde de cererea OP**. Cuantumul e fix, iar UAT-ul depinde de sediul creditorului, nu de instanță și nu de documente. Deci taxa poate fi achitată oricând după crearea dosarului. Asta ne dă libertate: nu trebuie să o înghesuim într-un pas anume al wizardului.

Propunerea concretă:

**Wizard: nimic nou.** Rămâne doar afișarea existentă („+ taxă timbru 200 RON”). Wizardul creează dosarul, nu îl depune. A cere plata acolo ar bloca un lucru care abia peste zile devine necesar (somația are termen de 15 zile înainte să existe cerere OP).

**Overview dosar: card „Taxa de timbru”, vizibil din statusul `SOMATIE_TRIMISA`.** Acolo e locul firesc: avocatul e deja în dosar, somația a plecat, urmează pachetul pentru instanță. Cardul conține cuantumul, UAT-ul de plată dedus din sediul creditorului, denumirea contului, butonul „Plătește pe registratura.rejust.ro” și butonul „Încarcă dovada”.

**Gate: la generarea pachetului pentru instanță.** Aici răspundem cererii avocatului („condiționăm trimiterea documentelor de încărcarea dovezii”). Concret, `CasePaymentOrderController::generate` primește o precondiție în plus, alături de cele existente (termen expirat, status debit, consimțământ): **există un document `DOVADA_TAXA_TIMBRU`**. Fără el, nu se generează cererea OP și nu se aplică `depune_cerere`.

Cu o supapă, pentru că legea o permite: opțiunea explicită **„Depun fără dovadă, voi timbra la regularizare”**, cu avertisment despre termenul de 10 zile și sancțiunea anulării, bifă distinctă și înregistrare în audit. Motivul: art. 33 alin. 2 chiar permite timbrarea ulterioară, unii avocați lucrează așa deliberat, iar un blocaj absolut i-ar împinge să ocolească platforma. Diferența față de azi e că alegerea devine **conștientă, documentată și auditată**, nu o scăpare.

**Supapa vine la pachet cu termenul, nu separat.** Ambii reviewers au semnalat același lucru: dacă oferim supapa fără să urmărim termenul de 10 zile, mutăm riscul din „avocatul nu știe” în „avocatul știe, dar noi îl lăsăm singur exact pe veriga care duce la anulare”. Deci bifarea supapei creează obligatoriu un `LegalDeadline` de timbrare, cu notificare. Termenul curge de la comunicarea instanței (pe care nu o cunoaștem automat), așa că modelăm un termen de urmărire cu dată completată de avocat la primirea citației, nu un termen calculat de noi.

**ZIP și opis: dovada intră în pachet.** `CaseFilesPackager` primește `04_dovada_taxa_timbru`, iar `OpisGeneratorService` o listează. Nu doar pentru că a cerut-o avocatul, ci pentru că **CPC art. 197 impune ca dovada să fie atașată cererii**.

**După înregistrarea dosarului:** dacă dosarul a fost depus fără dovadă, cardul rămâne activ, cu link direct către plata în dosar existent pe rejust.ro (acum avem `courtCaseNumber`), iar termenul de timbrare devine vizibil în lista de termene a dosarului.

### Model de date propus
- `DocumentType::DOVADA_TAXA_TIMBRU` (nou, în `uploadableTypes()`, nu auto-generat).
- Pe `LegalCase`:
  - `stampDutyStatus` (enum: `NEACHITATA` / `ACHITATA` / `AMANATA_REGULARIZARE`; prevăd de la început și `RESTITUITA` ca valoare rezervată, ca să nu fie nevoie de migrare de date când apare fluxul art. 45)
  - `stampDutyPaidAt`, `stampDutyPaidAmount` (suma **efectiv achitată**, distinctă de `stampDuty`, care e valoarea *calculată* la crearea dosarului, posibil cu luni înainte de plată)
  - `stampDutyPayerName` (cine figurează ca plătitor pe dovadă, pentru avertismentul de la §2c)
  - `stampDutyUat` (**snapshot** al UAT-ului pe care i l-am indicat avocatului la momentul plății; fără el nu putem reconstitui retroactiv ce sfat i-am dat, iar exact contul UAT e obiectul disputei tipice din analiza INM)
  - `stampDutyPaymentReference` (număr OP / referință ghiseul.ro, pentru corelarea cu dovada încărcată)
  - `stampDutyLawVersion` (câmpul deja identificat ca amânat în plan, `PLAN-DEZVOLTARE:806`, se adaugă acum fiindcă în sfârșit are sens: justifică retroactiv temeiul sumei)
- Nicio entitate `Payment` nouă. `Invoice` rămâne strict pentru abonamentul SaaS. Taxa de timbru nu este o plată către noi și nu trebuie să atingă modelul de billing.

### Determinarea UAT-ului: trei capcane
1. **Sediul se poate muta.** UAT-ul relevant e cel de la **momentul plății**, nu cel din onboarding-ul dosarului. Deci resincronizăm ANAF chiar înainte de a afișa UAT-ul, și arătăm data ultimei sincronizări.
2. **ANAF nu e sursa primară pentru „sediul social”.** ANAF dă domiciliul fiscal, sediul social e concept de drept societar (ONRC). De regulă coincid, dar e o aproximare pe care o marcăm explicit în UI, nu o ascundem.
3. **Cesiunea de creanță.** Dacă reclamantul e cesionarul, UAT-ul se calculează pe sediul **cesionarului** (creditorul din dosar), nu al cedentului din contractul-sursă. Modelul actual e deja corect aici, atâta timp cât `Creditor` de pe dosar e cel care stă în cerere.

Sugestia de UAT rămâne **orientativă**, cu formulare care trimite verificarea finală la portal. Altfel am submina exact motivul pentru care am respins opțiunea B: dacă indicăm greșit UAT-ul, riscul e același ca la un IBAN greșit, chiar dacă nu am scris niciun IBAN.

### GDPR
Dovada de plată conține date bancare ale creditorului (IBAN, uneori un extras de cont cu tranzacții străine de speță), iar `stampDutyPayerName` poate fi numele unei persoane fizice. Tratamentul: **același pipeline ca la `CONTRACT` / `FACTURA`** (upload prin `DocumentUploadService`, stocare sub `var/uploads/cases/{id}/`, acces prin `CaseVoter`), fără extracție AI pe acest tip de document (nu avem ce extrage și nu vrem să trimitem extrase de cont către un LLM). `stampDutyPayerName` se scrie în audit ca atare (nume, nu date bancare). Retenția urmează politica generală a documentelor dosarului.

### Ce NU facem
- Nu colectăm cei 200 lei (opțiunea A).
- Nu afirmăm IBAN-uri de trezorerie pe care nu le putem garanta.
- Nu depunem noi la instanță: spike-ul rejust rămâne NO-GO (fără API, cu semnătură calificată obligatorie). Platforma pregătește, avocatul depune.

---

## 6. Riscuri și puncte de decis

| # | Punct | Recomandarea mea |
|---|---|---|
| D1 | Gate dur (fără dovadă nu se generează pachetul) sau gate cu supapă documentată? | Cu supapă, auditată. Legea permite timbrarea la regularizare; blocajul absolut împinge avocatul în afara platformei. |
| D2 | Afișăm IBAN-ul UAT-ului sau doar numele UAT-ului plus link la primărie? | Doar UAT + denumirea contului + link. IBAN-ul greșit costă anularea cererii. Reevaluăm dacă găsim o sursă oficială consolidată. |
| D3 | Corectăm semantica lui `depune_cerere` (azi se aplică la generarea PDF-ului, nu la depunere)? | Da, dar ca decizie separată. Afectează statusuri existente și e mai mare decât taxa de timbru. |
| D4 | Modelăm termenul de 10 zile pentru regularizare? | **Da, în aceeași livrare cu D1** (ambii reviewers au insistat). Supapa fără termen mută riscul, nu îl reduce. |
| D5 | Avertizăm când plătitorul de pe dovadă nu e creditorul? | Da, avertisment soft la upload, ancorat în art. 40 alin. 3 („ordinul de plată semnat de debitor”). Nu îl putem verifica automat. |
| **D6** | **Cererea OP nu solicită taxa ca cheltuială de judecată. O reparăm?** | **Da, și e independent de fluxul de plată.** Vezi mai jos: e cel mai concret câștig pentru client din toată analiza. |

### D6: gap-ul descoperit de reviewers
`templates/pdf/payment_order_request.html.twig:144-148` afișează taxa ca rând separat, **după** „TOTAL SOLICITAT”, deci în afara sumei cerute. Iar temeiul juridic din cerere (`translations/messages.ro.yaml:1208`) citează doar art. 1014-1024 CPC și prescripția, **fără niciun petitum de obligare a debitorului la cheltuieli de judecată** și fără art. 453 CPC.

Consecința: creditorul câștigă procesul și **rămâne cu cei 200 lei pierduți**, pentru că instanța nu acordă cheltuieli de judecată din oficiu, ci doar dacă i se cer. Fix: petitum explicit („solicit obligarea debitorului la plata cheltuielilor de judecată, inclusiv taxa judiciară de timbru de 200 lei”) plus temeiul art. 453 CPC (aplicabil OP prin completare, art. 1013 alin. 2 CPC). În plan există deja acest item, amânat post-MVP sub eticheta „M2 cheltuieli de judecată” (`PLAN-DEZVOLTARE:2077`). Recomand deprioritizarea lui din „post-MVP” în „odată cu taxa de timbru”: e absurd să cerem avocatului dovada plății și să nu cerem instanței banii înapoi.

**Rămâne de verificat înainte de implementare:** conținutul HCU UNBR nr. 325/18.06.2026 (interpretativă, fonduri ale clientului). Relevant doar dacă cineva reia discuția despre opțiunea A. Pentru opțiunea C nu e blocant, fiindcă banii nu trec prin noi.

**Out of scope, mențiune pentru a preveni confuzia:** cererea în anulare (CPC art. 1024) se timbrează și ea, dar e formulată de **debitor**, nu de clientul nostru. Platforma servește creditorul, deci nu calculăm și nu urmărim acea taxă. Cuantumul ei e oricum incert (cei doi reviewers au dat răspunsuri diferite: 200 lei prin art. 6 alin. 2, respectiv 100 lei prin analogie cu art. 24), semn că nu e o zonă în care vrem să afirmăm ceva fără o verificare dedicată.

---

## 7. Efort estimat (opțiunea C)

| Livrabil | Estimare |
|---|---|
| `DocumentType::DOVADA_TAXA_TIMBRU` + câmpuri `LegalCase` + migrare + enum status | 0,5 zi |
| Serviciu de rezolvare UAT plată din sediul creditorului (reutilizează `LocalityNormalizer`) + excepția art. 40 alin. 2 + refresh ANAF | 0,75 zi |
| Card „Taxa de timbru” pe overview (stare, date de plată, deep-link rejust, upload) | 1 zi |
| Gate în `CasePaymentOrderController::generate` + supapa „amân la regularizare” + audit | 0,5 zi |
| Termen de timbrare (`LegalDeadline`) creat la bifarea supapei + notificare (D4) | 0,5 zi |
| ZIP `04_dovada_taxa_timbru` + opis | 0,25 zi |
| Petitum cheltuieli de judecată în cererea OP, art. 453 CPC (D6) | 0,5 zi |
| i18n RO/EN + teste | 1 zi |
| **Total** | **~5 zile** |

---

## 9. Ce s-a livrat (2026-07-14)

Opțiunea C, integral, plus D6. Suita: 1367 teste, cu 23 noi; erorile rămase sunt baseline preexistent, identic cu HEAD.

| Livrat | Unde |
|---|---|
| Stare plată pe dosar (status, dată, sumă achitată, plătitor, snapshot UAT, referință, versiune lege) | `LegalCase`, migrare `Version20260714185807` (cu backfill: dosarele deja depuse nu apar retroactiv ca netimbrate) |
| Primăria de plată din sediul creditorului, fără să inventeze una când nu poate | `StampDutyUatResolver`, `StampDutyPaymentTarget` |
| Sediu structurat pe creditor + lookup ANAF în Step 1 (același controller Stimulus ca la debitor, redenumit `party-anaf-lookup`) | `Creditor`, `Step1CreditorType`, `CaseWizardController` |
| Card taxă, upload dovadă, amânare, data comunicării instanței | `CaseStampDutyController`, `StampDutyService`, `_stamp_duty_card` + 3 modale |
| Gate la generarea pachetului, cu supapă auditată | `CasePaymentOrderController::generate()`, `StampDutyStatus::allowsFiling()` |
| Termen de timbrare 10 zile, prorogat la zi lucrătoare | `DeadlineService::createStampDutyDeadline()`, `DeadlineType::TIMBRARE` (CRITICAL) |
| Dovada în pachet (`04_dovada_taxa_timbru`) și în opis | `CaseFilesPackager`, `OpisGeneratorService` |
| Petitum cheltuieli de judecată (art. 453 CPC) în cererea OP | `payment_order_request.html.twig` |

Două defecte prinse de reviewers, ambele în documentul care ajunge la judecător, ambele reparate:
- petitum-ul afirma „achitată conform dovezii anexate” **și** când avocatul alesese timbrarea la regularizare, deci mințea instanța exact în scenariul pe care fluxul îl tratează onest, contrazis chiar de ZIP-ul propriu (dovada lipsea). Textul urmează acum starea reală.
- `art. 1013 alin. 2 CPC` fusese citat pentru „completare cu dreptul comun”, dar alineatul e despre excluderea creanțelor din insolvență. Citare eliminată; art. 453 se aplică fără ea.

## 10. Rămas deschis

- **Onorariul în petitum se cere fără dovadă atașată.** Art. 452 CPC cere dovada întinderii cheltuielilor. Textul spune acum „urmând a fi dovedit cu înscrisurile depuse la dosar”, ceea ce e corect procedural, dar dacă avocatul nu depune chitanța, instanța nu acordă onorariul. De discutat dacă vrem un gate și aici, ca la taxă.
- **Onorariul e exprimat în EUR** (`legalCostsCurrency` implicit EUR) alături de un principal în RON, într-un act depus la instanță. Un petitum mixt poate complica executarea. Conversia la RON pe cursul BNR există deja pentru creanță și s-ar putea refolosi.
- **Sediul creditorului nu se resincronizează cu ANAF înainte de a afișa primăria.** Dacă sediul s-a mutat între crearea dosarului și plată, indicăm o primărie depășită. `Creditor.anafCheckedAt` se persistă deja, dar nu e afișat nicăieri.
- **`StampDutyPaymentTarget.courtName`** se calculează pentru cazul art. 40 alin. 2 (creditor fără sediu în România, taxa merge la primăria instanței), dar cardul încă nu îl afișează.
- **Banner persistent** cât timp dosarul e `AMANATA_REGULARIZARE` și încă nu există termen de timbrare, ca avocatul să nu uite să introducă data înștiințării.

## 8. Surse

- OUG 80/2013, art. 6 alin. 2, art. 33, art. 40 (alin. 1, 1^1, 2, 3), art. 45: https://legislatie.just.ro/Public/DetaliiDocument/149314
- CPC art. 197 (timbrarea cererii, dovada se atașează cererii): https://lege5.ro/Gratuit/gmzdmobzgq/art-197-timbrarea-cererii-codul-de-procedura-civila
- Consecințele plății taxei în contul altei UAT (INM, Solomon C.M.): https://inm-lex.ro/wp-content/uploads/2021/12/Solomon-CM-Consecintele-platii-TJT-in-contul-altei-UAT-decat-cea-stabilita-potrivit-art.-40-din-OUG-nr.-80_2013.pdf
- registratura.rejust.ro, plata taxei într-un dosar existent: https://registratura.rejust.ro/plata-taxei-judiciare-de-timbru-intr-un-dosar-existent
- Plăți pentru terți pe registratura.rejust.ro (JURIDICE.ro): https://www.juridice.ro/698201/portalul-registratura-rejust-ro-introduce-platile-pentru-terti.html
- Spike intern depunere electronică: `docs/LexRecovery/SPIKE-DEPUNERE-ELECTRONICA-REJUST.md`
