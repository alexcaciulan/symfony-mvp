# Analiza dashboard-ului LexRecovery

Data: 29 iulie 2026
Stare: propunere de design, gata de decizie. Neimplementat.
Mockup-uri: `docs/LexRecovery/mockups/v3/02-dashboard/` (populated, empty, critic)

---

## 1. Rezumat executiv

Dashboard-ul actual nu deține niciun job propriu. Din cele șapte blocuri ale sale, patru sunt duplicate ale altor pagini: două KPI numără exact aceleași termene ca badge-ul din sidebar și ca pastilele din `/termene`, lista de termene este agenda fără consecință juridică și fără butoane, iar tabelul „Dosare recente” este `/cases` fără filtre și cu cel mai slab criteriu posibil de sortare. Al cincilea bloc, cardul „Activitate portal.just.ro / În curând”, ocupă jumătate din rândul doi cu un text fals: monitorizarea portalului este implementată de luni de zile.

Propunem înlocuirea lui cu o coadă de lucru: un rând per **dosar**, actul cel mai scump al dosarului, verbul și temeiul juridic, ordonate după cât costă întârzierea. Nu după dată, nu după data creării. Dashboard-ul numără exclusiv dosare, agenda numără exclusiv termene, deci cele două nu se mai pot contrazice pe același număr.

Avocatul câștigă un ecran pe care îl golește în loc de un raport pe care îl citește: dimineața vede care dosare cer un act de la el, în ce ordine, cu temeiul scris pe rând, și un buton care îl duce exact acolo unde se face actul. Câștigă și trei lucruri care azi nu au nicio suprafață: dosarele blocate pentru că lipsește o dată, starea abonamentului înainte de a începe munca (nu după ce a terminat wizardul), și dosarele care nu contribuie la totalul în lei.

---

## 2. Diagnostic al dashboard-ului actual

Controllerul are 39 de linii și trimite în template cinci lucruri: `recent_cases` (5), `has_cases`, `kpis`, `upcoming_deadlines` (7 zile, maxim 10).

### 2.1 Duplicare: aceleași cifre în patru locuri

| Informație | Locul 1 | Locul 2 | Locul 3 | Locul 4 |
|---|---|---|---|---|
| Termene restante | KPI roșu dashboard | badge sidebar | badge drawer mobil | pastilă `/termene` |
| Termene în 7 zile | KPI dashboard | `DeadlineList` dashboard | buckets azi/mâine/săptămână `/termene` | |
| Lista de dosare | tabel dashboard | tabelul Tabulator din `/cases` | | |

`countOverdueByUser()` doar deleagă la `countAgendaBuckets()['overdue']` (`src/Repository/LegalDeadlineRepository.php:179`), iar docblock-ul spune explicit că e același număr afișat în trei locuri. Al patrulea loc nu adaugă nimic.

### 2.2 Copy fals și cod mort

| Constatare | Dovadă |
|---|---|
| Cardul „Activitate portal.just.ro / În curând” ocupă 50% din rândul doi și promite o funcționalitate deja livrată | `templates/dashboard/index.html.twig:100-114`, textul la `translations/messages.ro.yaml:1628-1630`; monitorizarea există (`CourtPortalEvent`, subscriber de monitorizare, tabul Activitate Portal) |
| `greeting_name` se calculează și se pasează ca `%name%` într-o cheie care nu conține `%name%` în niciun locale | `templates/dashboard/index.html.twig:16-17` vs `translations/messages.ro.yaml:1605`, `translations/messages.en.yaml:1604` |
| Wrapper `<!--email_off-->` care protejează un email care nu se tipărește | `templates/dashboard/index.html.twig:17` |
| Nouă chei de traducere fără nicio utilizare, dintre care patru sunt un set paralel de statusuri care ar contrazice `StatusBadge` | `translations/messages.ro.yaml:1606-1607,1617-1625` |
| `findByStatusGroupedByUrgency()` are docblock „Used by dashboard” și zero call-sites | `src/Repository/LegalCaseRepository.php:110` |
| `Skeleton.html.twig` există și are zero utilizări în tot `templates/` | `templates/components/Skeleton.html.twig:1` |

### 2.3 Bug-uri de randare și de date

| Bug | Dovadă | Efect |
|---|---|---|
| `KpiCard` și `HeroEmptyState` nu declară `{% props %}`, deci scurg `label`, `value`, `hint`, `title` ca atribute HTML pe `div` | `templates/components/KpiCard.html.twig:30-34`, `templates/components/HeroEmptyState.html.twig:19-21`; contraexemplu corect la `templates/components/StatusBadge.html.twig:7-10` | `title` pe un div produce tooltip nativ peste tot blocul empty state |
| Contul cu toate dosarele închise primește ramura populată | `has_cases` derivă din `findRecentByUser()`, care NU exclude statusurile terminale (`src/Controller/DashboardController.php:34`, `src/Repository/LegalCaseRepository.php:60-70`), în timp ce toate cele patru KPI le exclud | Ecran de zerouri, listă goală, tabel cu dosare închise, fără niciun CTA (butonul de dosar nou trăiește doar pe ramura goală) |
| Lista de termene se trunchiază tăcut la 10 sub un subtitlu care anunță totalul | `src/Controller/DashboardController.php:26,30` vs `templates/dashboard/index.html.twig:94-98` | La 14 termene scrie „14 termene” și listează 10, fără link „vezi toate” |
| KPI „În recuperare” exclude tăcut dosarele non-RON, pe care tabelul de dedesubt le afișează în EUR | `src/Repository/LegalCaseRepository.php:225-245` vs `templates/dashboard/index.html.twig:148` | Portofoliu care nu se potrivește cu lista de dedesubt, fără explicație |
| Restanțele sunt numărate de 3 ori și listate de 0 ori | lista arată doar termene viitoare (`d.deadlineDate >= :today`, `src/Repository/LegalDeadlineRepository.php:146-167`) | Elementul cel mai urgent al paginii e un număr pe care nu îl poți desface fără să pleci |
| Rândurile de termen nu spun al cui dosar sunt | `templates/components/DeadlineList.html.twig:53-59`; `findUpcomingByUser` nu joinează nimic, spre deosebire de `findAgendaForUser` care face `addSelect` pe `lc`, `court`, `debtors` | La 30 de dosare, „Termen 15 zile, 12.08.2026” e neutilizabil |
| „Dosare recente” înseamnă recent create, nu recent active | `findRecentByUser` sortează pe `createdAt DESC` (`src/Repository/LegalCaseRepository.php:60-70`) | O ciornă abandonată ieri stă deasupra unui dosar cu termen de anulare peste 4 zile |
| Două ancore identice pe fiecare rând al tabelului | `templates/dashboard/index.html.twig:145,154` | 10 tab-stop-uri pentru 5 destinații |

### 2.4 Accesibilitate, mobil, contrast

| Problemă | Dovadă |
|---|---|
| Două `h1` pe starea goală (pagina are unul, `HeroEmptyState` randează al doilea) | `templates/dashboard/index.html.twig:15` și `templates/components/HeroEmptyState.html.twig:31` |
| Tabel fără `<caption>` și fără `scope="col"` | `templates/dashboard/index.html.twig:132-139` |
| Animație infinită fără gardă `prefers-reduced-motion`, exact lângă cifra care trebuie citită | `assets/styles/app.css:86-88`; singura gardă din tot fișierul e la view transitions, linia 171 |
| Nicio regulă `:focus-visible` în tot `app.css`, deși două din patru KPI sunt linkuri fără afordanță vizuală | `templates/dashboard/index.html.twig:64,73` |
| CTA-ul principal nu are nume accesibil sub 640px (svg fără `aria-label`, etichetă în `hidden sm:inline`) | `templates/_topbar.html.twig:75-78`; dashboard-ul nu compensează cu buton propriu, spre deosebire de `/cases` (`templates/cases/index.html.twig:16-19`) |
| KPI-urile pe 2 coloane taie sumele mari sub `overflow-hidden` | `templates/dashboard/index.html.twig:56`, `templates/components/KpiCard.html.twig:33,42` |
| `text-slate-400` pe alb (circa 2.6:1) folosit pentru unitatea RON, adică informație esențială | `templates/components/KpiCard.html.twig:42` |

### 2.5 Teste care nu testează

Cele șase teste din `tests/Controller/DashboardControllerTest.php:99-122` verifică doar prezența unor stringuri în HTML. Niciunul nu asertează o cifră. `testDashboardRendersKpiCards` caută eticheta tradusă, care apare oricum de două ori din cauza scurgerii de atribute, deci ar trece și dacă randarea cardului e complet stricată. Smoke-ul de design (`tests/Smoke/Pas27DesignSystemSmokeTest.php:84-89`) asertează `str_contains($body, 'shadow-soft')`, clasă prezentă în layout indiferent de conținut: condiția e mereu adevărată.

---

## 3. Job-to-be-done și diviziunea muncii

**Job-ul dashboard-ului:** „Deschid laptopul la 9 dimineața. Spune-mi care dintre dosarele mele cer un act de la mine, în ce ordine, și du-mă direct acolo unde se face actul.”

Unitatea răspunsului este DOSARUL, nu termenul. Aceasta este decizia structurală care face pagina neduplicabilă.

| Pagină | Deține | Unitate | Ordine |
|---|---|---|---|
| `/dashboard` | dosarul care cere ceva | un rând per dosar, doar actul cel mai scump | gravitatea sancțiunii, niciodată cronologică |
| `/termene` | timpul | un rând per termen | grupat pe zile, orizont 30 de zile |
| `/cases` | populația | un rând per dosar, toate dosarele | filtrabil, sortabil |
| `/case/{id}` | actul individual | un dosar | taburi |
| `/notifications` | trecutul | un eveniment | cronologic descrescător |

**Testul de non-duplicare**, aplicabil oricărei adăugiri viitoare: dacă un element de pe dashboard poate fi obținut sortând sau filtrând `/termene` sau `/cases`, nu are ce căuta pe dashboard.

Coada trece testul din trei motive verificabile:

1. Deduplică pe dosar. Un dosar cu trei termene fatale apare de trei ori în agendă și o singură dată aici.
2. Conține rânduri care nu au niciun termen în spate: dosarele blocate pentru că lipsește o dată. Agenda nu le poate lista, pentru că nu există rând de termen.
3. Ordinea ei nu este niciodată cronologică.

**Ce NU face dashboard-ul, asumat:** nu răspunde la „cum stau în ansamblu pe etape”, nu listează termene individuale, nu afișează prescripții pe orizont lung (fiecare dosar produce una sau două, ar fi zgomot permanent), nu afișează nicio sumă recuperată.

---

## 4. Alternativele evaluate și de ce a câștigat una

Am construit și evaluat trei viziuni complete.

| | A. Triaj | B. Pipeline | C. Turn de control |
|---|---|---|---|
| Teza | coada de lucru a zilei, un act per dosar | extrasul de portofoliu: cât, unde s-a oprit, cât costă | jurnal cronologic: ce s-a schimbat de la ultima vizită |
| Elementul semnătură | coloana de sancțiune de 96px pe stânga fiecărui rând | banda de recuperare pe etape, proporțională cu sumele | rail vertical cu noduri și marcaj de vizită |
| Produce o decizie? | da, pe fiecare rând | nu, informează | o singură dată (confirmările din portal) |
| Cod nou | serviciu de asamblare, zero SQL nou | un agregat nou, ieftin | coloană nouă pe `User` + migrare + rută POST + 5 interogări noi |
| Interogări pe pagină | circa 12 | circa 8 | circa 14, față de 5 azi |
| Blocant | niciunul | `/cases` nu citește filtrele din query string | `Document` nu are timestamp de finalizare a extracției; `LegalDeadline` nu are proveniență |

**A câștigat viziunea Triaj.** Motivele, în ordine:

1. **Este singura care produce un ecran pe care avocatul îl golește.** B informează impecabil dar nu decide nimic: un avocat care vede „312.450 RON în lucru, 5 dosare în Somație trimisă” nu are ce face cu informația până nu deschide altă pagină. C povestește trecutul, iar `/notifications` acoperă parțial deja acel job.

2. **Stă aproape integral pe cod deja plătit de `/termene`.** `DeadlinePageViewBuilder::build()` întoarce grupurile, contoarele și blocajele; `findAgendaForUser` face `addSelect` cu `leftJoin` pe `lc`, `court` și `debtors`, deci randarea nu declanșează N+1; `blockedCasesQueryBuilder` fetch-joinează la fel. Singurul cod nou al MVP-ului este un serviciu de asamblare plus un COUNT trivial.

3. **B are un blocant extern verificat.** Fiecare segment al benzii promite „vezi dosarele din această etapă”, dar `/cases` nu citește azi filtrele din query string: niciun controller Stimulus nu face seed din `location.search` pentru filtre de tabel. Un element semnătură ale cărui linkuri aterizează în lista nefiltrată este mai rău decât absența lui.

4. **C cere migrare de schemă pentru un job secundar.** „Ce s-a schimbat” și „ce am de făcut” sunt două joburi, iar o pagină de start cu două joburi nu are niciunul.

**Ce s-a altoit din celelalte două:**

- Din B: fâșia de portofoliu ca ancoră de volum (mică, jos, nu ca titlu de ecran), disciplina de etichetare onestă a sumelor, și redenumirea paginii care rezolvă coliziunea de identitate cu `/cases`.
- Din C: separarea explicită a stărilor de pagină ca livrabile distincte, nu ca note de subsol. C are cea mai bună intuiție de produs din tot materialul: dacă pagina arată la fel de alarmant și când e liniște, avocatul nu o mai citește.
- Din B și C împreună: banda de cont condițională, prezentă în ambele, pe singurul teren complet curat din shell (`base.html.twig` nu are niciun banner de cont).

**Ce s-a respins din toate trei:** bara de stare cu contoare de TERMENE, în ambele forme propuse. Ar fi a patra afișare a aceluiași număr. Dashboard-ul numără exclusiv dosare, sidebarul păstrează semnalul de termene restante. Cele două vocabulare nu se pot contrazice pentru că nu împart niciun substantiv.

---

## 5. Arhitectura de informație propusă

Ordinea de sus în jos este ordinea în care un avocat pierde bani.

**Grid:** o singură coloană pe toată pagina, ritm vertical `space-y-6`. Fără rail lateral. Motivul este de conținut, nu de gust: un rail transformă pagina într-un tablou de bord pe care îl citești, iar noi vrem o listă pe care o golești.

**Densitate:** coada este singurul element dens (rânduri de trei niveluri). Banda de cont, rigla, portofoliul și piciorul sunt toate elemente de o linie. Pagina scade de la circa două ecrane într-o zi critică la sub un ecran într-o zi liniștită, iar acea scădere este ea însăși mesajul.

---

### 5.1 Banda de cont (condițională)

**Prioritate:** MVP (gradul ambru) + FAZA_2 (gradul roșu)

**Scop.** Răspunde la „pot lucra azi?” înainte ca avocatul să înceapă. Cotă epuizată sau autorizare de plată expirată înseamnă că tranziția `trimite_somatie` va fi refuzată la finalul wizardului. Azi avocatul află asta abia după ce a completat tot dosarul, iar singurul indiciu permanent din shell este un micro-text de 10px trunchiat în switcherul din sidebar (`templates/_sidebar.html.twig:25-32`).

**Sursă de date.** Gradul ambru: funcția Twig existentă `current_subscription()`, apoi `getCasesConsumed()` vs `getPlan()->getIncludedCases()` și `getRecurringTokenExpiresAt()`. Zero query nou.
Gradul roșu: **query nou**. `findCurrentForUser` filtrează `status IN (ACTIVE, TRIAL, CANCELED)`, deci nu poate întoarce niciodată PAST_DUE sau SUSPENDED. Cere `findLatestForUser`, indiferent de status și de `currentPeriodEnd`.

**Stare goală.** Nu se randează deloc. Nu există variantă verde „totul e în regulă”: o bandă permanentă devine invizibilă în trei zile. Absența ei este mesajul.

**Copy.**
- Cotă: „Ai consumat 50 din 50 de dosare incluse în planul Pro. Următorul dosar activat se facturează separat.” Link: „Vezi planul”.
- Fără abonament: „Nu ai un abonament activ. Poți pregăti dosare, dar trimiterea somației cere un plan.” Link: „Vezi planurile”.
- Plată neconfirmată: „Plata abonamentului nu a fost confirmată. Perioada facturată s-a încheiat pe 24.07.2026, iar dosarele noi nu mai pot fi activate până la reautorizare.” Link: „Actualizează cardul”.
- Autorizare care expiră: „Autorizarea de plată salvată expiră pe 31.08.2026. Până la reînnoire, plata automată nu se poate face.”

---

### 5.2 Antetul cu verdictul zilei

**Prioritate:** MVP

**Scop.** În două secunde: ziua e critică sau liniștită, câte dosare cer un act, cât principal stă pe dosarele cu termen fatal. Înlocuiește `h1`-ul actual „Dosarele mele”, care se ciocnea de identitatea lui `/cases` („Dosare”, subtitlu „Toate dosarele tale, cu filtrare după status, căutare și sortare”). Două pagini din meniu nu mai pot pretinde amândouă că sunt lista completă de dosare.

**Sursă de date.** Derivat integral în PHP din coada deja asamblată. Numărul de dosare = rânduri după deduplicare. Sumele se grupează pe monedă și niciodată nu se adună între monede. `LegalCase::getAmount()` este `?string` nullable: dosarele fără sumă se exclud din total și se numără separat pe ecran. „Următoarea dată urmărită” vine din `findUpcomingByUser`, cu excluderea explicită a tipurilor nesancționate.

**Stare goală.** Cont nou: antetul nu se randează, pagina devine ecranul de pornire.

**Copy.**
- Zi critică: „3 dosare sunt pe termen fatal.” Subtitlu: „Unul are termenul depășit de 4 zile, două expiră până vineri. Alte 11 dosare cer un act sau așteaptă o dată de la tine. Principal pretins pe cele 3 dosare cu termen fatal: 218.900,00 RON.”
- Zi normală: „11 dosare cer un act.” Subtitlu: „Două au termen fatal în următoarele două zile și două așteaptă o dată de la tine. Principal pretins pe cele cinci dosare cu termen fatal sau cu dată lipsă: 251.800,00 RON și 5.000,00 EUR, afișate separat pentru că monedele nu se adună. Este principalul pretins, fără dobândă și fără taxa de timbru.”
- Zi liniștită: „Niciun termen nu se împlinește astăzi.” Subtitlu: „Următoarea dată urmărită este 08.08.2026, estimată.”
- Toate dosarele închise: „Toate dosarele sunt închise.” Subtitlu: „Nu ai nimic în lucru.”

CTA „Dosar nou” cu eticheta vizibilă la orice lățime, nu ascunsă în `hidden sm:inline`. Un singur `h1` în document, în toate stările.

---

### 5.3 Rigla de cost (șase benzi, contoare pe dosare)

**Prioritate:** MVP (contoare + ancoră de scroll), FAZA_2 (filtrare reală)

**Scop.** Spune din ce este făcută coada de dedesubt. Nu duplică pastilele din `/termene`, pentru că numără DOSARE, nu termene. Este singura suprafață din produs care poate rosti „trei dosare sunt pe termen fatal”, propoziție pe care nici agenda (care numără termene) nici `/cases` (care nu are noțiunea de consecință) nu o pot spune.

**Sursă de date.** Numărate în PHP pe coada deja asamblată, **niciodată printr-un SELECT separat**. Aceasta este regula care previne bug-ul actual (subtitlul anunță 14, lista arată 10).

| Bandă | Definiție | Culoare |
|---|---|---|
| Termen fatal | termen ireversibil cu dată certă, depășit sau sub 3 zile | roșu |
| Dată lipsă | o dată lipsă oprește calculul unui termen fatal | rose |
| Restant | depășit, dar fără sancțiune procesuală | ambru |
| Azi și mâine | grupurile `KEY_TODAY` și `KEY_TOMORROW` | ardezie |
| Fatal în 30 de zile | termen ireversibil cert, încă în orizont | sky |
| Termen debitor | termenul debitorului s-a împlinit | indigo |

A șasea bandă („Fatal în 30 de zile”) este obligatorie: fără ea, un termen de timbrare cert scadent peste 6 zile ar apărea în coadă fără bandă, iar rigla nu ar mai fi rezumatul cozii.

**Stare goală.** Pastilele cu 0 rămân pe ecran, dezactivate (`opacity-50`, `aria-disabled`, necliclabile), cu cifra în slate, niciodată în roșu. Avocatul trebuie să vadă că sistemul a verificat, nu că s-a stricat.

**Copy.** Nota obligatorie de sub riglă: „Numărăm dosare, nu termene: un dosar apare o singură dată, în banda cea mai scumpă. Badge-ul «Termene» din meniu numără termene (9 restante pe 6 dosare). Banda «Dată lipsă» adună termenele fatale care nu pot fi calculate până nu introduci data de la care curg.”

Nota trebuie să conțină explicit ambele numere, altfel contradicția cu badge-ul din sidebar rămâne deschisă pentru un utilizator care scanează.

---

### 5.4 Confirmă soluția instanței

**Prioritate:** FAZA_2

**Scop.** Portalul a întors o soluție și platforma NU aplică tranziția singură, pentru că un `emite_ordonanta` greșit pornește termenul fatal de 10 zile pe date eronate (CPC art. 1024 alin. 1). Actul e rar, are miza maximă și azi trăiește exclusiv în overview-ul fiecărui dosar, deci un avocat cu 30 de dosare nu află că are unul decât dacă intră din întâmplare pe dosarul potrivit. Este singurul element care are voie să sară peste ordinea cozii.

**Sursă de date.** `RulingProposalResolver::actionableProposal()` există și e apelat azi dintr-un singur loc. NU se poate exprima ca un singur query: primește lista de evenimente și interoghează workflow-ul. Implementare: query nou de shortlist pe `CourtPortalEvent` scoped pe utilizator (`findUnnotified` este global și nu se poate refolosi), apoi rezolvare în PHP pe mulțimea mică.

**Stare goală.** Nu se randează deloc. Nu lasă gaură în pagină.

**Copy.** Titlu: „Instanța a dat o soluție. Confirmă tu ce s-a întâmplat.” Rând: „4521/302/2026 · Judecătoria Sectorului 6 București · Beta Solutions S.R.L. Portalul scrie: «Admite cererea. Somează pe debitor să plătească suma de 152.000 lei.» Pronunțat 24.07.2026 · Propunere: marchează ordonanța ca emisă.” Buton: „Confirmă în dosar”. Notă permanentă: „Nimic nu se aplică automat. Cele 10 zile pentru cererea în anulare curg de la înmânarea sau comunicarea ordonanței (CPC art. 1024 alin. 1), deci data o confirmi tu în dosar.”

Textul brut din portal se randează escapat, nu se stochează într-un loc nou și nu intră în corpul notificărilor sau al email-urilor.

---

### 5.5 Coada de acțiuni

**Prioritate:** MVP

**Scop.** Corpul paginii și singurul loc unde se lucrează. Un rând per dosar, actul cel mai scump al dosarului, verbul și temeiul juridic. Avocatul coboară lista de sus în jos și o golește.

**Sursă de date.** Serviciu nou de **asamblare**, fără niciun SQL nou. Intrări: grupurile agendei din `DeadlinePageViewBuilder` și `DeadlineBlockageFinder::find()`. Serviciul face trei lucruri: deduplică pe dosar, atribuie banda, sortează.

Atenție la costul real: `DeadlinePageViewBuilder::build()` nu este „un apel”, execută 10 interogări, dintre care `countAgendaBuckets`, prescripțiile pe orizont lung și `warmDebtors` sunt risipite pe dashboard. Se extrage o variantă care construiește doar grupurile și blocajele.

**Elementul semnătură.** Coloana de gutter fixă de 96px pe stânga fiecărui rând, care tipărește **tipul termenului** în cuvinte: „Timbrare”, „Prescripție”, „Întrerupere 6 luni”, „Anulare cerere”, „Termen debitor”, „Judecată”, „Evidență”, plus „Dată lipsă” pentru rândurile de blocaj. Scanând coloana de sus în jos citești un registru procedural, nu un calendar.

**Anatomia rândului.** Bandă verticală de 3px pe stânga în culoarea benzii. Linia 1: numele debitorului plus `StatusBadge`-ul dosarului. Linia 2: actul plus temeiul. Linia 3: meta cu separator middot: scadența, zilele rămase (roșu bold sub 3 zile), instanța, principalul, numărul de dosar. Dacă dosarul mai are termene deschise, un link discret „+2 termene” către `/case/{id}?tab=termene` (ruta acceptă deja parametrul).

**Lista închisă de câmpuri pe rând (GDPR):** debitor, status, act, temei, scadență, zile rămase, instanță, principal, număr dosar. Nu se adaugă CNP, adresă, CUI sau date de contact. Serviciul de asamblare citește exclusiv interogări scoped pe utilizator, ca să nu ocolească tăcut `CaseVoter`.

**Reguli de deduplicare și ordonare.** Comparator pe două chei, nu pe `severityRank` singur: întâi proximitatea sancțiunii (depășit, sub 3 zile, în orizont), abia apoi gravitatea, cum face agenda per bucket. Blocajul are prioritate peste orice rând de agendă al aceluiași dosar: blocajul este cauza, termenul estimat este efectul.

**Ce NU intră în coadă.** `CERERE_IN_ANULARE` este exclus: pe dosarul creditorului cele 10 zile sunt fereastra debitorului, pe care avocatul o așteaptă să se împlinească pentru ca ordonanța să devină definitivă. Un verb la imperativ acolo ar sugera un act pe care creditorul nu îl are decât în ipoteza CPC art. 1024 alin. 2. La fel, `PRESCRIPTIE_EXECUTARE` (3 ani, CPC art. 705 alin. 1) rămâne exclusiv în `/termene`, decizie asumată.

**Stare goală.** Zi liniștită cu dosare active: cardul rămâne și afișează o singură linie centrată, plus link către agendă. Niciodată un card gol. „Nu e nimic scadent. Următorul termen cade pe 12.08.2026.” Cont nou: cardul nu se randează.

**Trunchiere.** Plafon 12 rânduri, apoi subsol care **declară** trunchierea: „Încă 3 dosare cer un act: 2 cu termenul debitorului împlinit și 1 cu termen mâine. Vezi agenda completă.”

**Copy pentru verbe** (chei noi, distincte de `deadlines.action.*`):

| Situație | Copy |
|---|---|
| Timbrare | „Timbrează și trimite instanței dovada plății. 10 zile de la primirea comunicării instanței (OUG 80/2013 art. 33 alin. 2).” Notă: „Cererea de acordare a facilităților la plata taxei se depune în 5 zile de la aceeași comunicare.” |
| Timbrare depășită | „Termenul de timbrare a trecut. Dacă instanța a anulat cererea, încheierea se atacă cu cerere de reexaminare în 15 zile de la comunicare (CPC art. 200 alin. 4).” Notă: „Anularea pentru netimbrare nu are autoritate de lucru judecat asupra fondului: cererea se poate reintroduce cu taxa achitată.” |
| Termen debitor împlinit | „Generează cererea de OP. Cele 15 zile din somație s-au împlinit la 24.07.2026 (CPC art. 1015 alin. 1).” Notă: „Împlinirea termenului este o condiție necesară, nu suficientă. Verifică BPI și celelalte condiții de admisibilitate înainte de depunere.” |
| Întrerupere 6 luni | „Generează cererea de OP. Somația a fost comunicată la 31.01.2026, iar cererea nu este depusă (CPC art. 1015 alin. 2, NCC art. 2540).” Notă: „Dacă cererea nu se depune până la 31.07.2026, întreruperea produsă de somație se desființează retroactiv și prescripția se socotește necurmată.” |
| Dată lipsă, somație | „Completează data primirii somației de către debitor. Somația a fost generată, dar data nu este introdusă.” Notă: „Termenul de 15 zile rămâne estimat, iar cererea depusă pe baza lui se poate respinge ca prematură (CPC art. 1015 alin. 1, art. 1016). Termenul legal curge de la primirea somației, indiferent de ce este introdus în aplicație. Blocat este calculul, nu termenul.” |
| Dată lipsă, ordonanță | „Completează data înmânării sau comunicării ordonanței. Fără ea, cele 10 zile ale cererii în anulare nu pot fi calculate, iar dosarul nu poate fi marcat definitiv (CPC art. 1024 alin. 1).” |
| Prescripție | „Prescripția dreptului material la acțiune se împlinește la 18.08.2026 (NCC art. 2500 alin. 1). Instanța nu o poate invoca din oficiu (NCC art. 2512 alin. 2), dar debitorul poate. Somația comunicată întrerupe cursul.” |
| Evidență | „Atașează la dosar dovada de comunicare primită de la instanță. Termen intern setat de tine, fără sancțiune procedurală.” Notă pe buton: „Nu constituie dovada că actul a fost făcut.” |

**Disclaimer permanent** sub cardul cozii: „Ordonarea și încadrarea termenelor sunt orientative. Verificarea termenului și decizia procedurală rămân ale avocatului.”

---

### 5.6 Fâșia de portofoliu

**Prioritate:** MVP

**Scop.** Ancora de volum, singura metrică de bani rămasă pe pagină, afișată mic și jos. Este și locul unde se plătește datoria de onestitate a KPI-ului actual: `sumActiveAmountByUser` filtrează deliberat `currency = 'RON'` (decizie corectă și documentată), dar UI-ul actual tace despre asta sub eticheta „În recuperare”.

**Sursă de date.** `countActiveByUser()` și `sumActiveAmountByUser()` există. Piesă nouă: un COUNT definit ca „dosare active care nu contribuie la total”, adică `currency <> 'RON' OR amount IS NULL`. Inversarea simplă a filtrului de monedă ar rata dosarele fără sumă. Circa 8 linii.

**Layout.** Nu este card, este fâșie cu `border-t`, trei valori inline. Valoarea nu are `overflow-hidden` și primește `break-words`: cardul actual taie sumele mari la 360px, iar o sumă trunchiată e mai rea decât una greșită, pentru că nu se vede că e trunchiată.

**Stare goală.** Nu se randează la zero dosare active. Nu afișează „0 dosare active, 0,00 RON”.

**Copy.** „47 dosare active · 1.284.600,00 RON principal în lucru · 3 dosare active nu intră în total: 2 în valută și 1 fără sumă completată.” Link: „Vezi toate dosarele”.

Eticheta spune „principal în lucru”, niciodată „în recuperare” și niciodată „recuperat”.

---

### 5.7 Piciorul de navigație

**Prioritate:** MVP

**Scop.** Închide pagina trimițând explicit în cele trei pagini care dețin subiectele atinse doar în trecere. Declară vizibil că dashboard-ul nu încearcă să fie și agendă, și registru, și jurnal.

**Sursă de date.** Rute existente plus funcțiile Twig globale `overdue_deadlines_count()` și `unread_notifications_count()`. Prima memoizează pe request; celelalte două extensii nu memoizează și sunt deja apelate în topbar și sidebar, deci trebuie memoizate înainte de a adăuga al doilea call-site.

**Stare goală.** Nu se randează pe contul nou.

**Copy.** „Agenda completă pe 30 de zile (9 restante)” · „Toate dosarele” · „Notificări (3)”. Contorul apare în paranteză doar când e mai mare decât zero.

---

### 5.8 Secțiuni FAZA_2 și BACKLOG

| Secțiune | Prioritate | Sursă | Notă |
|---|---|---|---|
| Confirmări de soluție din portal | FAZA_2 | query nou de shortlist + `RulingProposalResolver` | vezi 5.4 |
| Confirmare pentru dosarele marcate automat definitive | FAZA_2 | `CaseAutoFinalizer` | tamponul de 5 zile lucrătoare e conservator, nu exact juridic; avocatul confirmă înainte de a porni executarea |
| Dosare fără mișcare | FAZA_2 | query nou peste `activeByUserQueryBuilder` | restrâns la statusurile cu `isActiveOnPortal() === false`, altfel cronul de portal atinge `updatedAt` |
| Monitorizare portal oprită automat | FAZA_2 | inversarea condițiilor din `findActiveForMonitoring()` | după 5 interogări eșuate dosarul devine orb fără ca avocatul să observe |
| Filtrarea cozii prin riglă | FAZA_2 | mecanismul `DeadlineAgendaFilter` | filtrul se aplică în SQL, niciodată în browser; contoarele rămân nefiltrate |
| Acțiune în loc din coadă | FAZA_2 | `CONTEXT_DASHBOARD` + template Turbo Stream propriu | vezi 7.3 |
| Banda de recuperare pe etape | BACKLOG | agregat nou `GROUP BY status` | precondiție blocantă: `/cases` nu citește filtrele din query string |
| Vechime în etapa curentă | BACKLOG | `CaseStatusHistoryRepository` (azi gol) | trei capcane: AMIABIL nu scrie rând, etapa curentă nu apare, tranzițiile automate au `createdBy NULL` |
| Documente orfane și extracții eșuate | BACKLOG | două query-uri simple | blocant de produs: nu există nicio destinație pentru documentele orfane |
| Rată de succes | BACKLOG | `GROUP BY status` pe terminale | zgomot sub 10 dosare finalizate |
| Flux cronologic cu marcaj de vizită | BACKLOG | coloană nouă pe `User` + 5 interogări noi | locul lui nu e dashboard-ul, ci `/notifications` |

---

## 6. Ce eliminăm din dashboard-ul actual

| Element eliminat | Motiv |
|---|---|
| Cele patru carduri `KpiCard` | Două numără exact ce numără badge-ul din sidebar și pastilele din `/termene`. „Dosare active” e o cifră fără decizie atașată. „În recuperare” excludea tăcut dosarele non-RON. |
| Componenta `DeadlineList` de pe dashboard | Agenda fără consecință juridică, fără marcaj de estimare, fără debitor, fără instanță, fără butoane, trunchiată tăcut la 10. Rândurile ei nu spuneau nici măcar al cui dosar sunt. |
| Cardul „Activitate portal.just.ro / În curând” | Ocupa 50% din rândul doi și copy-ul mințea. Ce supraviețuiește din el este singura felie care cere ceva de la avocat: confirmarea soluției, în FAZA_2. |
| Tabelul „Dosare recente” | 42 de linii scrise manual care dublează `/cases`, cu două ancore identice pe rând, fără caption și fără scope, sortat pe `createdAt DESC`. Nu îl înlocuim cu un tabel sortat mai bine: „cere ceva de la mine” bate „e nou” la orice criteriu. |
| Cele trei carduri „cum funcționează” de pe starea goală | `/termene` are un ecran gol mai bun juridic. Nu vrem două onboarding-uri concurente. |
| `greeting_name` și wrapperul `<!--email_off-->` | Cod mort: construiesc un `%name%` pe care niciun locale nu îl folosește. |
| Cele nouă chei de traducere nefolosite | Inclusiv setul paralel `status_*` care ar contrazice `StatusBadge`. Se șterg în același commit cu template-ul. |
| `dashboard.cases.kpi.*` și `dashboard.cases.how.*` | Devin orfane odată cu eliminarea `KpiCard` și a celor trei carduri. |
| Bara de contoare de termene, în orice formă | Ar fi a patra afișare a aceluiași număr. Sidebarul o are deja. |
| Orice sumă „recuperată” sau „sub risc” | Nu există câmp de sumă încasată în model. `LegalCase::amount` e principalul pretins, nu bani intrați. |
| Trendul „+5 vs luna trecută” din mockup-ul v2 | Nu e alimentat de nimic și ar fi o cifră de vanitate pe un ecran de triaj. |

Redenumire: prima intrare din sidebar, `h1`-ul și breadcrumb-ul devin „Panou de lucru”. Numele „Dosare” rămâne al paginii care chiar e lista completă.

---

## 7. Constatările verificării adversariale

Propunerea a fost verificată de două ori, o dată pe date și cod, o dată pe conținut juridic și GDPR. Ambele au produs blocante reale. Toate au fost corectate în propunerea de mai sus și în mockup-uri.

### 7.1 Blocante juridice

| Constatare | Corecție aplicată |
|---|---|
| Cuvântul „Ireparabil” (bandă, gutter, `h1`) este fals pentru două din cele trei consecințe: anularea pentru netimbrare se atacă cu reexaminare în 15 zile (CPC art. 200 alin. 4) și nu are autoritate de lucru judecat asupra fondului; decăderea admite repunerea în termen (CPC art. 186). Aplicația spune deja corect ambele lucruri în copy-ul de confirmare, deci dashboard-ul ar fi contrazis produsul. | Cuvântul a fost eliminat din tot ecranul. Banda se numește „Termen fatal”. Rândurile al căror termen s-a împlinit numesc remediul rămas, nu declară pierderea. |
| Banda verde „Poți depune” era o concluzie de admisibilitate luată doar din expirarea celor 15 zile, ignorând tot ce `OpAdmissibilityValidator` blochează la depunere: insolvență, verificare BPI mai veche de 7 zile, radiere, exigibilitate. | Eticheta devine „Termen debitor împlinit”, culoarea nu mai e verde, iar nota spune: „Împlinirea termenului este o condiție necesară, nu suficientă. Verifică BPI și celelalte condiții de admisibilitate înainte de depunere.” |
| „STINGEREA DREPTULUI” în gutter: prescripția stinge dreptul material la acțiune (NCC art. 2500 alin. 1), nu dreptul, iar instanța nu o aplică din oficiu (NCC art. 2512 alin. 2). În plus același cuvânt s-ar fi tipărit pe termenul de 6 luni (NCC art. 2540), unde nu se stinge nimic. | Gutterul tipărește **tipul termenului**, nu consecința comprimată într-un verdict. |
| `CERERE_IN_ANULARE` cu verb la imperativ pe dosarul creditorului: pe acel dosar cele 10 zile sunt fereastra debitorului, pe care avocatul o așteaptă să se împlinească. | Exclus din coadă. Rămâne în `/termene`, unde contextul dosarului e vizibil. |
| „Timbrează cererea. 10 zile de la notificare” avea trei defecte: „notificare” se confundă cu somația, termenul curge de la primirea comunicării instanței, obligația include transmiterea dovezii, iar aceeași comunicare deschide și termenul de 5 zile pentru facilități. | Copy corectat integral, plus nota despre cele 5 zile. |
| „Fără ea nu curg cele 15 zile” afirmă un neadevăr: termenul curge de drept de la primirea somației, indiferent ce e introdus în aplicație. | „Blocat este calculul, nu termenul.” Reluată formularea existentă pentru blocaje. |
| Suma „sub risc” în subtitlul `h1` este o predicție juridică cuantificată pe principalul pretins. Ratarea timbrării nu pune principalul în risc, lipsa datei de comunicare nu pune nimic în risc, iar prescripția operează doar dacă debitorul o invocă. | Cifra a fost reetichetată strict descriptiv: „Principal pretins pe cele 3 dosare cu termen fatal”. Cuvintele „sub risc”, „expunere” și „pierdere” nu apar nicăieri. |
| Cumulat, pagina aluneca spre a părea că platforma ia decizia procedurală. | Disclaimer permanent sub coadă. Regulă tare: orice buton care închide un termen păstrează neschimbat dialogul de confirmare existent. Nimic nu se închide cu un singur clic din coadă. |
| GDPR: numele debitorului persoană fizică apare pe ecranul cu cea mai mare probabilitate de a fi proiectat sau lăsat deschis, iar lista de câmpuri nu era închisă. | Lista de câmpuri pe rând este acum închisă și scrisă în specificație. Textul de portal se randează escapat. |

### 7.2 Blocante de date

| Constatare | Corecție aplicată |
|---|---|
| `findCurrentForUser` filtrează `status IN (ACTIVE, TRIAL, CANCELED)`, deci gradul roșu al benzii de cont (PAST_DUE, SUSPENDED) era cod mort. Un avocat în dunning ar fi primit mesajul „Nu ai un abonament activ”, care e fals. | Banda de cont în MVP se restrânge la cotă epuizată, autorizare expirată și lipsă de abonament. Gradul roșu cere `findLatestForUser`, marcat FAZA_2. |
| Copy-ul benzii cerea „data eșecului de plată” și un termen de grație. `Subscription` nu are aceste câmpuri, iar `SubscriptionRenewalService` doar flip-uiește statusul. | Copy rescris pe singura ancoră reală: `currentPeriodEnd`. |
| `getRecurringTokenExpiresAt()` este expirarea tokenului recurent, nu a cardului. | Formularea vorbește despre autorizare, nu despre card. |
| Taxonomia de cinci benzi nu acoperea un termen fatal cert la 4 până la 30 de zile, exact ce livrează agenda pe 30 de zile. | S-a adăugat a șasea bandă, „Fatal în 30 de zile”. |
| Deduplicarea pe `severityRank` singur inversa urgența (o prescripție la 20 de zile urca peste o timbrare scadentă mâine) și distrugea întotdeauna banda debitorului, pentru că `NO_SANCTION` are rank 0. | Comparator pe două chei: întâi proximitatea sancțiunii, apoi gravitatea, ca în agendă. Blocajul are prioritate peste orice rând de agendă al aceluiași dosar. |
| Vocabularul gutterului lipsea două cazuri din enum și conținea un cuvânt („BLOCAJ”) care nu e o consecință. | Gutterul folosește tipul termenului; „Dată lipsă” e etichetă de categorie, aplicabilă doar rândurilor de blocaj. |
| `LegalCase::getAmount()` este `?string` nullable: dosarele fără sumă contribuiau tăcut cu zero. | Se exclud din total și se numără separat pe ecran, la fel ca cele în valută. |
| `upcomingQueryBuilder` nu exclude termenele nesancționate ale debitorului, deci „următorul termen” putea fi termenul debitorului prezentat ca termen propriu. | Filtrare explicită pe tipurile nesancționate în apelul din antet. |
| COUNT-ul de dosare excluse prin simpla inversare a monedei ar fi ratat dosarele cu `amount NULL`. | Definiție corectă: `currency <> 'RON' OR amount IS NULL`. |
| `NotificationExtension` și `SubscriptionExtension` nu memoizează și sunt deja apelate în topbar și sidebar. | Se memoizează pe modelul `DeadlineExtension` înainte de a adăuga al doilea call-site. |

### 7.3 Costul real, corectat

Afirmația „zero query nou” era adevărată despre SQL-ul scris și falsă despre SQL-ul executat.

| | Azi | Propunere MVP |
|---|---|---|
| Interogări pe pagină | circa 5 | circa 12 |

`DeadlinePageViewBuilder::build()` execută 10 interogări: agenda (3), blocajele (4), `countAgendaBuckets` (1), prescripțiile pe orizont lung plus `warmDebtors` (2). Ultimele trei sunt risipite pe dashboard, care nu afișează nici contoarele de termene, nici prescripțiile. Se extrage o variantă care le sare, ceea ce coboară la 7 interogări pentru coadă.

Plafonul de 12 rânduri limitează randarea, nu costul: nicio interogare din lanț nu are LIMIT, iar regula „contoarele riglei se numără în PHP pe coada asamblată” face hidratarea completă obligatorie. Aceasta este o alegere conștientă: preferăm cifre care nu pot diverge de listă. Peste un prag de volum se trece pe randare progresivă cu `Skeleton`, **nu pe cache**: o coadă de triaj cache-uită ar afișa un act deja făcut.

O afirmație din propunerea inițială a fost respinsă ca falsă: mitigarea prin „restrângerea orizontului la 2 zile, parametrul există deja” nu e posibilă. `HORIZON_DAYS` este constantă de clasă și `buildAgenda()` nu primește niciun orizont.

### 7.4 Butoanele: constrângere verificată

`AgendaResponseFactory::isAgendaRequest()` decide după câmpul ascuns `_context=agenda`. Un POST tras din dashboard fără acel câmp primește răspunsul paginii de dosar și scoate avocatul din coadă.

În plus, `DeadlineRowActionResolver` rezolvă „ce poate apăsa”, nu „ce act juridic urmează”: butonul primar e POST pentru majoritatea tipurilor, iar etichetele existente înseamnă „închide termenul”, nu „fă actul”. A eticheta „Timbrează cererea” un buton care închide un termen ar transforma o închidere de evidență într-o afirmație că plata s-a făcut.

Decizie pentru MVP: verbele din coadă navighează spre locul unde se face actul, iar copy-ul folosește chei noi, distincte de `deadlines.action.*`. Butoanele care închid termene păstrează neschimbate notele existente („Nu constituie dovada că actul a fost făcut”). Acțiunea în loc, cu `CONTEXT_DASHBOARD` și template propriu de Turbo Stream, este FAZA_2.

---

## 8. Riscuri, limite și întrebări deschise pentru avocat

### 8.1 Riscuri asumate

| Risc | Mitigare |
|---|---|
| Cifrele dashboard-ului (dosare) diferă de badge-ul din sidebar (termene). Un avocat cu badge 9 și bandă „Restant 2” poate crede că ceva s-a stricat. | Nota de sub riglă scrie ambele numere explicit. Fiecare bandă își scrie numele în cuvinte. Alternativa, să numărăm termene, reintroduce exact duplicarea pe care designul o elimină. |
| Dashboard-ul devine cea mai scumpă pagină din aplicație. | Se extrage o variantă redusă a builderului. Se cere un profil pe un cont cu 200 de dosare înainte de livrare. |
| Un avocat cu 3 dosare pierde ecranul de „cum stau în ansamblu”. | Cine vrea starea contului merge în `/cases`. Un cabinet cu 3 dosare nu are nevoie de dashboard, unul cu 30 nu poate lucra fără triaj. |
| Avocatul obișnuit cu tabelul de dosare recente va reclama absența lui. | Tabelul nu răspundea la nicio întrebare: nici „ce cere ceva de la mine”, nici „unde e dosarul X”. |
| Coada poate deveni lungă pe conturi mari. | Plafon 12 rânduri cu trunchiere declarată explicit, plus filtrarea pe bandă în FAZA_2. |

### 8.2 Limite ale modelului, nedepășibile fără câmpuri noi

- Nu există niciun câmp de sumă încasată. `LegalCase::amount` este principalul pretins la depunere; singura sumă real încasată din model este `ClaimItem::paidAmount`, care reprezintă plăți parțiale ale debitorului constatate din documente, nu recuperări prin procedură.
- `Subscription` nu are data eșecului de plată și nici termen de grație.
- `Document` nu are timestamp de finalizare a extracției.
- `LegalDeadline` nu are proveniență, deci nu se poate scrie „termen creat automat” pentru niciun rând.
- `CaseStatusHistory` nu scrie rând pentru etapa AMIABIL și nu are rând pentru etapa curentă.

### 8.3 Întrebări deschise pentru avocat

1. **Termenul de 6 luni (NCC art. 2540).** Îl tratăm ca termen fatal cu bandă roșie, alături de timbrare? Consecința (desființarea retroactivă a întreruperii) nu stinge dreptul, dar poate readuce o creanță în prescripție. Este încadrarea corectă?
2. **Cererea în anulare pe dosarul creditorului.** Am scos-o complet din coadă. Există situații în care creditorul are efectiv de făcut ceva în acele 10 zile, în afara ipotezei CPC art. 1024 alin. 2?
3. **Pragul „termen fatal acum”.** L-am fixat la „depășit sau sub 3 zile”. Este suficient pentru timbrare, unde termenul e de 10 zile și implică o deplasare la UAT plus transmiterea dovezii?
4. **Termenul de 5 zile pentru facilități la plata taxei.** Îl afișăm doar ca notă pe rândul de timbrare, sau merită termen propriu în agendă?
5. **Auto-finalizarea (`CaseAutoFinalizer`).** Tamponul de 5 zile lucrătoare peste cele 10 zile este conservator, dar platforma nu cunoaște data comunicării către debitor. Este acceptabil ca dosarul să fie prezentat ca definitiv fără confirmarea avocatului, sau confirmarea devine obligatorie (FAZA_2)?
6. **Prescripția executării (CPC art. 705 alin. 1).** Am exclus-o de pe dashboard împreună cu celelalte prescripții. Fiind cea mai scurtă și integral în mâna avocatului, merită excepție?
7. **Banda „Termen debitor împlinit”.** Copy-ul actual spune „condiție necesară, nu suficientă”. Este suficient de clar, sau trebuie enumerate explicit verificările (BPI, radiere, exigibilitate)?
8. **Ordinea benzilor.** Am pus „Dată lipsă” imediat sub „Termen fatal”, înaintea restanțelor reparabile. Un dosar blocat pe o dată este mai urgent decât un termen restant fără sancțiune?

---

## 9. Plan de implementare pe tranșe

### Tranșa 0: curățenie și datorii tehnice

**Efort: 1 zi. Query-uri noi: niciunul.**

Precondiție pentru tot restul. Livrată separat, se poate comite independent.

- `KpiCard` și `HeroEmptyState` își declară `{% props %}` (bug real, verificat prin randare).
- `HeroEmptyState` coboară titlul la `h2`.
- Regulă globală `:focus-visible` în `app.css`.
- Gardă `prefers-reduced-motion` pe `.soft-pulse`.
- Memoizare pe request în `NotificationExtension` și `SubscriptionExtension`.
- Ramificarea stărilor se mută de pe `has_cases` pe `countActiveByUser() > 0`.
- Ștergerea celor nouă chei de traducere moarte și a codului mort `greeting_name`.

### Tranșa 1: coada de acțiuni (MVP)

**Efort: 4 până la 5 zile. Query-uri noi: unul, COUNT trivial.**

- Serviciu nou de asamblare: deduplicare pe dosar, atribuire de bandă, comparator pe două chei.
- Variantă redusă a builderului de agendă, care sare `countAgendaBuckets`, prescripțiile pe orizont lung și `warmDebtors`.
- Filtru pe tipurile nesancționate în `findUpcomingByUser` pentru „următoarea dată urmărită”.
- COUNT nou: `currency <> 'RON' OR amount IS NULL` pe dosare active.
- Template complet: antet, riglă (contoare + ancoră), coadă, portofoliu, picior.
- Namespace nou de traduceri `dashboard.work.*`, RO și EN în lockstep, plus `lint:yaml`.
- Teste care asertează **valori**, nu prezența unor stringuri: contorul fiecărei benzi vs numărul de rânduri, deduplicarea pe dosar, excluderea dosarelor fără sumă din total.
- Verificare live în browser pe cele trei stări.

### Tranșa 2: stările de pagină

**Efort: 1 până la 2 zile. Query-uri noi: niciunul.**

- Cont nou: coloană centrată, un singur `h1`, registrul celor șase termene urmărite.
- Cont cu toate dosarele închise: a treia ramură, dedicată.
- Zi liniștită: rigla cu șase pastile la 0, dezactivate; coada cu o singură linie și link către agendă.
- Zi critică: accentuarea unei singure benzi, nu a tuturor.

### Tranșa 3: banda de cont

**Efort: 1 zi (gradul ambru), plus 0,5 zile pentru gradul roșu. Query-uri noi: unul (`findLatestForUser`).**

- MVP: cotă epuizată, autorizare care expiră, lipsă de abonament. Fără query nou.
- FAZA_2: `SubscriptionRepository::findLatestForUser()` pentru PAST_DUE și SUSPENDED.

### Tranșa 4: confirmările de soluție din portal

**Efort: 2 până la 3 zile. Query-uri noi: unul complex (shortlist pe `CourtPortalEvent` scoped pe utilizator).**

- Serviciu `PendingRulingProposalFinder`, cap la 5 dosare.
- Rezolvare în PHP cu `RulingProposalResolver::actionableProposal()`.
- Card cu ring violet, între riglă și coadă.
- Randare escapată a textului din portal.

### Tranșa 5: igiena de sub coadă

**Efort: 2 zile. Query-uri noi: două simple.**

- Dosare fără mișcare, restrâns la statusurile cu `isActiveOnPortal() === false`.
- Monitorizare portal oprită automat, prin inversarea condițiilor din `findActiveForMonitoring()`.

### Tranșa 6: filtrare și acțiune în loc

**Efort: 3 până la 4 zile. Query-uri noi: niciunul, dar modificare de contract.**

- Filtrarea cozii prin riglă, în SQL, cu `aria-pressed` și URL copiabil.
- `CONTEXT_DASHBOARD` în `AgendaResponseFactory`, template propriu de Turbo Stream, ramură de redirect.
- Butoanele care cer date pe care coada nu le colectează rămân linkuri în dosar, ca pe agendă.

### Rezumat

| Tranșă | Efort | Query-uri noi | Prioritate |
|---|---|---|---|
| 0. Curățenie | 1 zi | 0 | precondiție |
| 1. Coada | 4-5 zile | 1 simplu | MVP |
| 2. Stările | 1-2 zile | 0 | MVP |
| 3. Banda de cont | 1 + 0,5 zile | 1 simplu | MVP + FAZA_2 |
| 4. Confirmări portal | 2-3 zile | 1 complex | FAZA_2 |
| 5. Igiena | 2 zile | 2 simple | FAZA_2 |
| 6. Filtrare și acțiune | 3-4 zile | 0 | FAZA_2 |

MVP livrabil: tranșele 0, 1, 2 și partea ambră din 3, adică 7 până la 9 zile.

---

## 10. Mockup-uri

Servite local cu `php -S 127.0.0.1:8888 -t docs/LexRecovery/mockups`, apoi `http://localhost:8888/v3/`.

| Mockup | Fișier | Ce arată |
|---|---|---|
| Panou populat | [`mockups/v3/02-dashboard/populated.html`](mockups/v3/02-dashboard/populated.html) | Ziua normală: 11 dosare cer un act, cele șase benzi ale riglei, opt rânduri de coadă cu gutterul de tip de termen, fâșia de portofoliu, piciorul, plus tabelul complet de note de implementare cu sursa fiecărei secțiuni. |
| Stări inițiale | [`mockups/v3/02-dashboard/empty.html`](mockups/v3/02-dashboard/empty.html) | Două stări pe același ecran: contul nou, cu registrul celor șase termene urmărite și temeiul fiecăruia; și primele zile, cu două dosare tinere, rigla aproape goală și panoul de orientare care dispare după al treilea dosar. |
| Zi critică | [`mockups/v3/02-dashboard/critic.html`](mockups/v3/02-dashboard/critic.html) | Gradarea de alertă prin saturația suprafeței, banda de cont roșie, blocul de confirmări din portal, coada grupată pe benzi cu antet propriu, plus anexa cu zona critică randată la 390px. |

Index-ul galeriei: [`mockups/v3/index.html`](mockups/v3/index.html), legat din [`mockups/index.html`](mockups/index.html).
