# Corecție număr dosar portal (după activare) — AMÂNAT

> Status: **AMÂNAT** (decizie 2026-06-27)
> Context: ridicat în timpul livrării auto-descoperirii dosarului pe portal.just.ro
> (`PortalCaseMatcher` + `CasePortalController::discover`). Feature-ul de descoperire
> rămâne livrat; doar **corecția numărului după ce există activitate** se amână.

## 1. Problema

După activarea monitorizării, avocatul poate reapăsa „Caută dosarul pe portal" și alege
**alt dosar** din sugestii (sau introduce manual alt număr). Întrebarea: ce se întâmplă cu
activitatea deja înregistrată pe dosarul setat inițial?

Caz tipic care provoacă eroarea: același debitor apare pe mai multe dosare (la testul real,
`RĂDULESCU ALEXANDER TUDOR` apărea și pe `2001/300/2026` cu creditorul nostru, și pe
`20252/300/2025` cu alt creditor). Avocatul poate confirma din greșeală dosarul vecin.

## 2. Comportamentul actual (gap)

`CasePortalController::activate` doar suprascrie `courtCaseNumber` și menține
`portalMonitoringActive = true`. **Nu atinge** nimic din activitatea veche:

- `CourtPortalEvent`-urile dosarului greșit rămân în timeline, amestecate cu cele noi;
- deduplicarea (`CourtPortalEventRepository::eventExists` pe `case + tip + dată`) poate
  **bloca** importul unor evenimente reale ale dosarului nou dacă coincid ca tip/dată;
- termenele și notificările auto-generate din evenimentele vechi rămân active pe date greșite.

Rezultat: activitate „fantomă" de la dosarul greșit, fără nicio curățare.

## 3. Regula de produs propusă (pentru când se reia)

Pragul **nu** este „a fost setat numărul o dată?", ci **„a apărut deja activitate reală de
la portal?"** (există cel puțin un `CourtPortalEvent` real):

- **Activ, fără evenimente portal** (doar s-a activat): schimbarea e o **corecție de setup**,
  inofensivă. Se permite liber (descoperirea + inputul manual rămân disponibile).
- **Activ, cu evenimente portal**: numărul devine „purtător de adevăr juridic" (toate
  evenimentele, termenele, eventualele tranziții atârnă de el). Schimbarea **nu** mai e o
  operațiune banală: se ascunde descoperirea și se oferă separat o acțiune **explicită și
  discretă** „Corectează numărul de dosar", cu avertisment + curățare + audit.

Motivul pentru care nu alegem nici „lock total", nici „liber mereu":
- lock total = avocatul nu mai poate repara o alegere greșită;
- liber mereu = amesteci istoricul a două dosare și strici integritatea.

## 4. Implicațiile acțiunii de corecție (de ce e netrivială)

| Strat afectat | Implicație | Reversibil? |
|---|---|---|
| `CourtPortalEvent` (timeline) | Aparțin dosarului greșit, trebuie șterse. Pierzi istoricul importat. | Da (re-scan le reface pentru dosarul corect) |
| Notificări in-app + email | Deja trimise avocatului. In-app se pot șterge; **email-urile nu se pot retrage**. | Parțial |
| Termene auto (`LegalDeadline` sursă portal) | Create din ședințele dosarului greșit. Dacă avocatul și-a planificat agenda sau a atins termenul, ștergerea e sensibilă. | Riscant (vezi secțiunea 6) |
| Status workflow (`TERMEN_FIXAT` etc.) | Monitorizarea poate să fi mutat statusul automat (`fixeaza_termen`). O mașină de stări **nu se poate derula înapoi în siguranță**. | **Nu automat** |
| `CaseStatusHistory` / `AuditLog` | Append-only prin design (trasabilitate). **Nu se șterg**, se adaugă o intrare de corecție. | Nu (intenționat) |

## 5. Riscul real, juridic

Cel mai periculos nu e ce ștergi, ci ce **nu s-a urmărit**: cât timp monitorizai dosarul
greșit, termenele dosarului corect nu au fost calculate, inclusiv cel critic de 10 zile pentru
cererea în anulare (CPC art. 1024). După corecție, re-scan-ul trebuie să reconstruiască
termenele dosarului corect, iar avocatul trebuie avertizat explicit că a existat o fereastră în
care monitorizarea era pe alt dosar.

## 6. Blocant tehnic: lipsa provenienței pe termene

`LegalDeadline` **nu are câmp de proveniență**. Câmpuri existente: `type`, `deadlineDate`,
`priority`, `completed` / `completedBy`, flag-uri alerte, `createdAt` / `updatedAt`. Nu există
`source` / `createdBy` / legătură către `CourtPortalEvent`.

Consecință: nu putem distinge un termen creat de monitorizare de unul pus manual de avocat.
Singurul indiciu e `type = JUDECATA`, dar avocatul poate crea și el manual un astfel de termen,
deci ștergerea pe baza tipului ar putea șterge un termen pus de avocat. Asta e exact lucrul
periculos.

Regula corectă de ștergere (când va exista proveniența): dispar **doar** termenele care
îndeplinesc *toate*:
1. au sursă portal,
2. nu au fost atinse de avocat (nu sunt `completed`, needitate manual).

Termenele manuale sau deja bifate rămân, niciodată șterse în tăcere.

**Precondiție obligatorie**: adăugarea unui câmp de proveniență pe `LegalDeadline`, ideal o
legătură nullable `sourceEvent -> CourtPortalEvent` (sau enum `source: MANUAL | PORTAL`).
Beneficii dincolo de corecție:
- ștergere precisă, prin FK, doar a termenelor portalului neatinse;
- în UI: „termen creat automat din portal" vs „adăugat manual";
- reconciliere curată la re-scan.

Fără acest câmp, varianta sigură ar fi să **nu** ștergem termenele automat, ci doar să
**avertizăm** avocatul să le verifice.

## 7. A doua capcană tehnică: race async

Dacă un worker de monitorizare (`CheckCasePortalMessageHandler`) este în zbor pentru numărul
vechi când se face corecția, poate scrie evenimente **după** curățare. Guard necesar: workerul
citește `courtCaseNumber`-ul curent și abandonează dacă s-a schimbat între timp.

## 8. Scope sigur recomandat (când se reia)

Acțiunea de corecție ar trebui să facă **doar** stratul de date, NU rollback de status:

1. șterge `CourtPortalEvent` + notificările in-app + termenele cu sursă portal **neatinse manual**;
2. `lastPortalCheckAt = null` -> re-scan complet pentru numărul nou;
3. intrare de audit `portal_case_number_corrected` (vechi -> nou); istoricul rămâne intact;
4. **NU** atinge statusul automat; afișează un avertisment că monitorizarea l-a putut muta și că
   avocatul trebuie să confirme stadiul corect manual.

Adică: corecția repară datele și repornește monitorizarea curat, dar lasă deliberat în seama
avocatului singurul lucru care nu se poate automatiza fără risc (readucerea statusului). Asta
ține feature-ul onest și evită un rollback „magic" care ar ascunde un termen pierdut.

## 9. Cum monitorizarea creează termene azi (context relevant)

Pentru a înțelege ce e de curățat, comportamentul actual al `MonitoringEventApplier`:

| Eveniment portal | Termen auto? | Tranziție status auto? |
|---|---|---|
| `HEARING_SCHEDULED` (termen judecată fixat) | Da: termen `JUDECATA` cu data ședinței | `fixeaza_termen` (dacă starea permite) |
| `APPEAL_FILED` (cale de atac) | Nu | `formuleaza_cerere_anulare` (protectiv) |
| `HEARING_COMPLETED` / `RULING_ISSUED` (soluție) | Nu | Doar propunere logată (`portal_transition_proposed`) |
| `CASE_INFO_UPDATE` | Nu | Doar informativ |

Deci termenele auto apar **doar la `HEARING_SCHEDULED`** (cu dedup
`LegalDeadlineRepository::findOneByCaseTypeAndDate`). Soluțiile/hotărârile nu mișcă nimic
automat. Termenul critic de 10 zile (cererea în anulare) nu vine din monitorizare; atârnă de
`rulingCommunicationDate`, setat în alt flux.

Implicație: termenele auto sunt un risc de curățat **doar dacă** dosarul greșit avusese un
`HEARING_SCHEDULED`. Altfel singurele artefacte de curățat sunt evenimentele + notificările.

## 10. Checklist pentru reluare

- [ ] Câmp proveniență pe `LegalDeadline` (`sourceEvent -> CourtPortalEvent` sau enum `source`) + migrare.
- [ ] `MonitoringEventApplier` / `DeadlineService::createHearingDeadline` setează sursa la creare.
- [ ] Acțiune controller separată „corectează numărul" (gated: doar owner, doar dacă există activitate).
- [ ] Curățare: `CourtPortalEvent` + notificări in-app + termene sursă portal neatinse; `lastPortalCheckAt = null`.
- [ ] Audit `portal_case_number_corrected` (old -> new), istoric append-only intact.
- [ ] Guard race async în `CheckCasePortalMessageHandler` (verifică `courtCaseNumber` curent).
- [ ] Avertisment UI: status posibil mutat de portal, necesită confirmare manuală; „vei pierde N evenimente / M termene".
- [ ] UI: ascunde descoperirea când există activitate; afișează corecția discret.

## 11. Riscul de a NU face nimic (între timp)

Cât timp feature-ul e amânat, comportamentul actual (secțiunea 2) rămâne: dacă avocatul schimbă
numărul după ce există activitate, istoricul se amestecă fără avertisment. Mitigare minimă
opțională, dacă se dorește înainte de feature-ul complet: un simplu avertisment text în UI la
schimbarea numărului unui dosar care are deja `CourtPortalEvent`, fără nicio ștergere automată.
