# Câmpuri populate în somația de plată

Inventar al datelor dinamice injectate în `templates/pdf/payment_notice.html.twig`.
Tot ce nu apare aici este text fix din traduceri (`pdf.summons.*` în `translations/messages.ro.yaml`).

Lanțul de generare:
`PaymentNoticeGeneratorService` → `AbstractPdfGenerator::templateContext()` → `SummonsContextBuilder::build()` → template.

## Sursa contextului

| Origine | Chei furnizate |
|---|---|
| `AbstractPdfGenerator:37` | `case`, `user` (avocatul logat), `today` |
| `SummonsContextBuilder:80` | `penaltyType`, `principal`, `currency`, `interestResult`, `penaltyResult`, `claimItems`, `claimItemAccessories`, `claimDescription`, `accessoryTotal`, `grandTotal`, `refDate`, `invoiceDate`, `originalAmount`, `originalCurrency`, `exchangeRate`, `exchangeRateDate` |

## Inventar pe secțiuni

| # | Secțiune (linie în template) | Câmp în document | Sursă | Opțional | Observații |
|---|---|---|---|---|---|
| 1 | Referință dosar (47) | Număr dosar | `case.caseNumber` | nu | |
| 2 | Antet „Către" (54-60) | Denumire debitor | `debtor.name` | nu | Doar primul debitor: `case.debtors\|first` |
| 2 | Antet „Către" (54-60) | Adresă debitor | `debtor.address` | nu | |
| 2 | Antet „Către" (54-60) | Administrator | `debtor.administrator` | da | |
| 2 | Antet „De la" (64-69) | Denumire creditor | `creditor.name` | nu | |
| 2 | Antet „De la" (64-69) | Adresă creditor | `creditor.address` | nu | |
| 3 | Subiect (77) | Număr dosar | `case.caseNumber` | nu | |
| 3 | Subiect (77) | Data somației | `refDate` | nu | `case.paymentNoticeDate` dacă există, altfel data curentă |
| 4 | Formulă de adresare (81-85) | Nume administrator | `debtor.administrator` | da | Fără el se folosește formula generică |
| 5 | Identificare creditor (88-106) | Denumire + adresă | `creditor.name`, `creditor.address` | nu | Lead-ul frazei |
| 5 | Identificare creditor (88-106) | Număr ONRC | `creditor.onrcNumber` | da | Clauză |
| 5 | Identificare creditor (88-106) | CUI | `creditor.cui` | da | Clauză |
| 5 | Identificare creditor (88-106) | IBAN | `creditor.iban` | da | Clauză |
| 5 | Identificare creditor (88-106) | Bancă | `creditor.bankName` | da | Se lipește de clauza IBAN |
| 5 | Identificare creditor (88-106) | Reprezentant legal | `creditor.legalRepresentative` | da | Clauză |
| 5 | Identificare creditor (88-106) | Avocat | `user.fullName` | da | Reprezentare convențională |
| 5 | Identificare creditor (88-106) | Număr barou | `user.barNumber` | da | Fără el se folosește varianta scurtă a clauzei |
| 6 | Domiciliu ales, art. 158 CPC (110) | Avocat | `user.fullName` | da | Blocul dispare fără `user.fullName` |
| 7 | Total introductiv (121) | Total general | `grandTotal` | nu | `principal + accessoryTotal` |
| 7 | Total introductiv (121) | Monedă | `currency` | nu | Default `RON` |
| 8 | Tabel sume (123-146) | Debit principal | `principal` | nu | `case.amount` |
| 8 | Tabel sume (123-146) | Accesoriu | `accessoryTotal` | da | Rândul apare doar dacă > 0; eticheta comută după `penaltyType` |
| 8 | Tabel sume (123-146) | Total | `grandTotal` | nu | |
| 9 | În fapt (150-153) | Creditor, debitor | `creditor.name`, `debtor.name` | nu | |
| 9 | În fapt (155) | Obiectul creanței | `claimDescription` | da | `case.claimDescription` |
| 9 | În fapt (157-162) | Identificare debitor | `debtor.name`, `debtor.address` | nu | |
| 9 | În fapt (157-162) | ONRC, CUI debitor | `debtor.onrcNumber`, `debtor.cui` | da | Clauze |
| 10 | Debit principal (166) | Sumă + monedă | `principal`, `currency` | nu | |
| 10 | Conversie FX (167-175) | Sumă originală | `originalAmount` | da | Blocul apare doar dacă `originalCurrency` există |
| 10 | Conversie FX (167-175) | Valută originală | `originalCurrency` | da | |
| 10 | Conversie FX (167-175) | Curs BNR | `exchangeRate` | da | 4 zecimale |
| 10 | Conversie FX (167-175) | Data cursului | `exchangeRateDate` | da | |
| 11 | Tabel poziții (179-216) | Număr document | `item.documentNumber` | da | Tabelul apare doar cu `claimItems\|length > 1` |
| 11 | Tabel poziții (179-216) | Data documentului | `item.documentDate` | da | |
| 11 | Tabel poziții (179-216) | Scadență | `item.dueDate` | da | |
| 11 | Tabel poziții (179-216) | Sumă în RON | `item.signedAmountRon` | nu | Semnată: notele de credit intră negativ |
| 11 | Tabel poziții (179-216) | FX per poziție | `item.amount`, `item.currency`, `item.exchangeRate`, `item.exchangeRateDate` | da | Doar dacă poziția nu e în RON |
| 11 | Tabel poziții (179-216) | Accesoriu per poziție | `claimItemAccessories.forItem(item.id)` | da | Fără rezultat se afișează „indisponibil" sau „—" |
| 11 | Tabel poziții (210-214) | Rând total | `principal`, `accessoryTotal` | nu | |
| 12 | Factură, o singură poziție (217-220) | Număr factură | `case.invoiceNumber` | da | Ramura alternativă la tabelul de poziții |
| 12 | Factură, o singură poziție (217-220) | Data facturii | `case.invoiceDate` | da | |
| 13 | Contract (221-224) | Număr contract | `case.contractNumber` | da | Se afișează în ambele ramuri |
| 13 | Contract (221-224) | Data contractului | `case.contractDate` | da | |
| 14 | Accesorii contractuale (227-240) | Referință contract | `case.contractReference` | da | Default textual dacă lipsește |
| 14 | Accesorii contractuale (227-240) | Sumă de bază | `principal`, `currency` | nu | |
| 14 | Accesorii contractuale (227-240) | Rată zilnică | `penaltyResult.breakdown\|first.dailyRate` | da | 3 zecimale |
| 14 | Accesorii contractuale (227-240) | Număr zile | `penaltyResult.breakdown\|first.days` | da | |
| 14 | Accesorii contractuale (227-240) | Total penalitate | `penaltyResult.total` | da | |
| 15 | Dobândă legală (242-270) | Perioadă | `p.startDate`, `p.endDate` | da | Un rând per perioadă din `interestResult.breakdown` |
| 15 | Dobândă legală (242-270) | Zile | `p.days` | da | |
| 15 | Dobândă legală (242-270) | Rată aplicabilă | `p.applicableRate` | da | |
| 15 | Dobândă legală (242-270) | Dobândă pe perioadă | `p.periodInterest` | da | |
| 15 | Dobândă legală (242-270) | Total dobândă | `interestResult.total` | da | |
| 16 | Cheltuieli (274-279) | Onorariu fix | `case.legalCostsFixed` | da | Blocul apare doar dacă e setat |
| 16 | Cheltuieli (274-279) | Monedă onorariu | `case.legalCostsCurrency` | da | Default `EUR` |
| 16 | Cheltuieli (274-279) | Onorariu de succes | `case.legalCostsSuccessPercent` | da | |
| 17 | Somare finală (285-289) | Termen de plată | text fix | nu | 15 zile, CPC art. 1015 alin. 1, nu se calculează |
| 17 | Somare finală (285-289) | Total de plată | `grandTotal`, `currency` | nu | |
| 18 | Semnătură (295-305) | Data emiterii | `refDate` | nu | |
| 18 | Semnătură (295-305) | Nume avocat | `user.fullName` | da | |
| 18 | Semnătură (295-305) | Număr barou | `user.barNumber` | da | |

## Comportamente de reținut

| Comportament | Unde | Efect |
|---|---|---|
| Un singur debitor în document | `payment_notice.html.twig:3` (`case.debtors\|first`) | Dosarele cu mai mulți debitori (până la 5) produc o somație adresată unuia singur |
| Data somației îngheață calculul | `SummonsContextBuilder:226` | Cu `paymentNoticeDate` setată, regenerarea dă aceleași cifre ca originalul trimis |
| Degradare grațioasă, fără excepție | `SummonsContextBuilder:210-224` | Fără scadență sau fără rată contractuală, defalcarea dispare și `accessoryTotal` cade pe `case.calculatedInterest`; documentul se generează, dar fără justificarea pe perioade cerută de CPC art. 1016 alin. 1 lit. c |
| Ramificare pe tipul de penalitate | `SummonsContextBuilder:46, 68-74` | Default `LEGAL_PENALIZATOARE`; `CONTRACTUAL` schimbă atât blocul de accesorii, cât și eticheta din tabelul de sume |
| Accesoriu per poziție | `SummonsContextBuilder:56-66` | Cu o singură poziție se păstrează formatul vechi, ca dosarele deja depuse să se printeze identic |
