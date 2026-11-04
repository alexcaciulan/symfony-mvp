# Ce informații ale dosarului mai merită să fie editabile

Data: 2026-11-04. Ramura: `lexrecovery` la `5181000`. Punct de pornire: după livrarea editării creanței (ciornă, ștergere, card Creanță), ce alte câmpuri ale dosarului se schimbă în practică și ar merita o cale de modificare din pagina dosarului, fără a trece prin wizard.

Metoda: inventarul câmpurilor din `LegalCase`, `LegalCaseDebtor`, `Creditor` și `Debtor`, confruntat cu rutele de acțiune existente (`debug:router`), apoi fiecare câmp evaluat după trei criterii: cât de des se schimbă după creare, ce acte sau calcule depind de el (ce se strică dacă se schimbă) și cât costă să fie editabil în siguranță.

## 1. Ce se poate modifica deja, și de unde

| Zonă | Câmpuri | De unde | Condiție |
|---|---|---|---|
| Creanța (pozițiile) | facturile, excluderea cu motiv, reincluderea | cardul Creanță | doar `AMIABIL` |
| Creanța (textele) | `claimDescription`, `contractReference`, `penaltyClauseArticle`, `penaltyClauseText`, `paymentNoticeNumber` | „Modifică detaliile creanței” | doar `AMIABIL` |
| Instanța | `court` | propunerea de pe card, cu „Schimbă instanța” | doar `AMIABIL`, doar instanța pe care o propun regulile |
| Somația | `paymentNoticeCommunicationDate`, `paymentNoticeCommunicationMethod` | „Corectează data” din tab-ul Documente | după generarea somației |
| Termene | `LegalDeadline` (adăugare, editare, completare, ștergere), `rulingCommunicationDate`, `annulmentRulingCommunicationDate`, `enforcementRegistrationNumber` | tab-ul Termene | în funcție de etapă |
| Taxa de timbru | `stampDutyStatus` și câmpurile dovezii | cardul taxei | de la cererea OP încolo |
| Depunere | `courtCaseNumber`, `filedAt`, `filingChannel`, `filingReference` | fluxul de depunere | după generarea cererii |
| Împuternicirea | seria, numărul, data | tab-ul Documente | oricând |
| Părțile | toate câmpurile creditorului și debitorului | bibliotecile de creditori și debitori (`/creditors/{id}/edit`, `/debtors/{id}/edit`) | o firmă deja somată nu se mai actualizează din wizard; biblioteca o permite, cu notă în dosare |
| Status | tranzițiile de workflow | butoanele de acțiune | conform workflow-ului |

Concluzie: tot ce ține de etapele de după somație are deja câte o cale proprie. Golul rămas e în parametrii creanței care nu sunt poziții.

## 2. Ce nu se poate modifica după creare

| Câmp | Se scrie la | Cine depinde de el | Cât de des se schimbă în practică |
|---|---|---|---|
| `penaltyType` (dobândă legală / penalitate contractuală) | pasul 3 | accesoriul pe fiecare poziție, somația, cererea OP | rar, dar grav: avocatul descoperă clauza penală după ce a creat dosarul pe dobândă legală, sau invers |
| `contractualPenaltyRate`, `contractualPenaltyCapPercent`, `contractualAccessoryLabel` | pasul 3 | la fel | odată cu cel de mai sus |
| `accessoryCutoffDate` | pasul 3 | accesoriul, somația | rar (plată intermediară, înțelegere cu debitorul) |
| `contractNumber`, `contractDate` | pasul 3 | somația (referința), rata dobânzii legale (4 pp înainte de 05.04.2013), instanța (cauza) | ocazional: contractul se identifică abia după creare |
| `contractObject` | pasul 3 | somația („având ca obiect”) | ocazional |
| `legalCostsFixed`, `legalCostsCurrency`, `legalCostsSuccessPercent` | pasul 3 | doar cererea OP (CC art. 1531) | des: onorariul se negociază sau se corectează până la depunere |
| `relationshipType` | pasul 3 | rata dobânzii legale, admisibilitatea | niciodată în MVP (doar comercial e activ) |
| `notes` | nicăieri din UI | nimic | ar fi folosit des dacă ar exista |
| `amount`, `currency`, `dueDate`, `invoiceNumber`, `invoiceDate`, urma valutară, `calculatedInterest`, `stampDuty` | derivate din poziții și din servicii | sunt rezultat, nu intrare | nu se editează niciodată direct |
| `paymentNoticeDate`, `status`, `caseNumber`, `createdAt`, `opGenerationConsent`, `debitAcknowledgedStatus` | fluxurile lor | acte generate, workflow | nu se editează direct |

## 3. Recomandare

### Prioritatea 1: parametrii accesoriilor, în același dialog „Modifică detaliile creanței”, doar în `AMIABIL`

Câmpuri: `penaltyType`, `contractualPenaltyRate`, `contractualPenaltyCapPercent`, `contractualAccessoryLabel`, `accessoryCutoffDate`, `contractNumber`, `contractDate`, `contractObject`.

De ce merită: sunt exact câmpurile pe care `LegalCaseClaimFieldsMapper::applyAccessoryFields` le scrie deja din wizard, iar `ClaimRecalculator` reface deja accesoriul, propunerea de instanță, termenele și auditul după orice schimbare pe creanță. Lipsesc doar câmpurile din formular, validarea de la pasul 3 (rata obligatorie la penalitate contractuală, plafonul între 0 și 100) și un rând în panoul „Ce s-a schimbat” pentru accesorii. Decizia de a bloca totul după somație rămâne neatinsă, pentru că accesoriul recalculat ar contrazice somația.

Risc: schimbarea modului de accesorii schimbă suma din somație; e acoperit de regula existentă (somația generată se marchează depășită). Pentru `contractDate` înainte de 05.04.2013 se schimbă rata dobânzii legale; recalculatorul o aplică deja.

Efort: circa o zi, cu teste pe editor și pe controller și verificare live.

### Prioritatea 2: cheltuielile de recuperare, până la generarea cererii OP

Câmpuri: `legalCostsFixed`, `legalCostsCurrency`, `legalCostsSuccessPercent`.

De ce merită: nu intră în somație și nu schimbă instanța; apar doar în cererea OP, deci pot fi corectate și după somație, până când cererea e generată. Un dialog mic, separat de creanță, cu propria poartă: permis în `AMIABIL` și `SOMATIE_TRIMISA`, blocat din `CERERE_GENERATA`. Dacă avocatul vrea să le schimbe după generare, regenerează cererea (același mecanism de act depășit).

Efort: o jumătate de zi.

### Prioritatea 3: note interne pe dosar

Câmpul `notes` există în entitate, dar nu apare nicăieri în interfață. Un câmp de text liber pe pagina dosarului, editabil în orice etapă, fără efect legal, cu audit. Util pentru ce nu are loc în aplicație: discuții cu clientul, promisiuni de plată, numere de telefon ale contabilului debitorului.

Efort: o jumătate de zi.

### Nu recomand acum

- **Părțile din dosar**: rămân fixe, după decizia luată. Corecțiile de date (adresă, administrator, IBAN, bancă) se fac din biblioteci și se propagă în dosarele care nu au somație trimisă. Singurul plus ar fi un link „Modifică în bibliotecă” pe cardurile Creditor și Debitor din pagina dosarului, cu nota că o firmă somată nu se mai actualizează.
- **Instanța aleasă liber** (alternative teritoriale, CPC art. 107 și 113): depinde de răspunsul avocatului la întrebarea C. Până atunci rămâne doar propunerea regulilor.
- **`relationshipType`**: doar comercial e activ în MVP.
- **Câmpurile derivate** (sume, scadență, urmă valutară, taxă, accesoriu stocat): sunt rezultate ale pozițiilor și ale serviciilor comune; a le edita direct ar reintroduce exact dublarea pe care am scos-o.

## 4. Întrebări pentru avocat, de adăugat la lista A-M

- **N.** Ți s-a întâmplat să descoperi clauza penală (sau să renunți la ea) după ce ai creat dosarul? Cât de des se schimbă modul de calcul al accesoriilor înainte de somație?
- **O.** Onorariul din cererea OP: îl știi de la început sau se stabilește până la depunere? Ar trebui să poată fi corectat după somație?
- **P.** Ai nevoie de un loc pentru note interne pe dosar (discuții cu clientul, promisiuni de plată), care nu apar în niciun act?

## 5. Ordine propusă

1. Prioritatea 1 (accesorii și contract) împreună cu răspunsul la N, pentru că refolosește integral ce există.
2. Prioritatea 3 (note), cea mai ieftină și fără risc legal.
3. Prioritatea 2 (onorariu), după răspunsul la O, ca să fixăm corect etapa până la care e permis.
