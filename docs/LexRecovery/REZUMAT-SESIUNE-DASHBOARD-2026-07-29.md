# Rezumat sesiune: analiza dashboard-ului

Document însoțitor pentru `ANALIZA-DASHBOARD-2026-07-29.md`.
Stare: propunere neimplementată, cu o rezervă importantă descrisă la secțiunea 3.

---

## 1. Ce s-a produs

Doar documentație și mockup-uri. Niciun fișier de aplicație nu a fost modificat.

| Fișier | Stare | Conținut |
|---|---|---|
| `docs/LexRecovery/ANALIZA-DASHBOARD-2026-07-29.md` | nou, 522 linii | Diagnostic cu dovezi `fisier:linie`, cele trei viziuni evaluate, arhitectura de informație propusă, copy romanesc cu temeiuri legale, constatările verificării adversariale, plan pe 7 tranșe |
| `docs/LexRecovery/mockups/v3/02-dashboard/populated.html` | nou | Ziua normală de lucru |
| `docs/LexRecovery/mockups/v3/02-dashboard/empty.html` | nou | Cont nou plus primele zile de utilizare |
| `docs/LexRecovery/mockups/v3/02-dashboard/critic.html` | nou | Ziua critică, cu anexă randată la 390px |
| `docs/LexRecovery/mockups/v3/index.html` | nou | Galeria v3 |
| `docs/LexRecovery/mockups/index.html` | modificat | Intrare nouă către v3, lângă v1 și v2 |

Răsfoire locală:

```bash
php -S 127.0.0.1:8888 -t docs/LexRecovery/mockups
```

Apoi `http://localhost:8888/v3/`.

---

## 2. Diagnosticul dashboard-ului actual

Această secțiune rămâne validă indiferent de ce se decide despre redesign. Bug-urile sunt verificate în cod și există azi în producție.

| Constatare | Dovadă | Efect |
|---|---|---|
| Contul cu toate dosarele închise primește ramura populată | `has_cases` derivă din `findRecentByUser()`, care nu exclude statusurile terminale (`DashboardController.php:34`, `LegalCaseRepository.php:60-70`), în timp ce toate cele patru KPI le exclud | Ecran de zerouri, fără niciun CTA, pentru că butonul de dosar nou trăiește doar pe ramura goală |
| `KpiCard` și `HeroEmptyState` nu declară `{% props %}` | `KpiCard.html.twig:30-34`, `HeroEmptyState.html.twig:19-21`; contraexemplul corect la `StatusBadge.html.twig:7-10` | `label`, `value`, `hint`, `title` se scurg ca atribute HTML pe `div`; `title` produce tooltip nativ peste tot blocul |
| Cardul „Activitate portal.just.ro / În curând" promite o funcționalitate livrată de luni de zile | `templates/dashboard/index.html.twig:100-114`, text la `messages.ro.yaml:1628-1630` | Ocupă 50% din rândul doi cu copy fals |
| Restanțele sunt numărate de trei ori și listate de zero ori | lista filtrează `d.deadlineDate >= :today` (`LegalDeadlineRepository.php:146-167`) | Elementul cel mai urgent al paginii e un număr care nu se poate desface fără a părăsi pagina |
| „Dosare recente" înseamnă recent create, nu recent active | `findRecentByUser` sortează pe `createdAt DESC` | O ciornă abandonată ieri stă deasupra unui dosar cu termen de anulare peste patru zile |
| Lista de termene se trunchiază tăcut la 10 sub un subtitlu care anunță totalul | `DashboardController.php:26,30` vs `index.html.twig:94-98` | La 14 termene scrie „14 termene" și listează 10, fără link „vezi toate" |
| KPI „În recuperare" exclude tăcut dosarele non-RON | `LegalCaseRepository.php:225-245` vs `index.html.twig:148` | Totalul nu se potrivește cu lista de dedesubt, fără explicație |
| Rândurile de termen nu spun al cui dosar sunt | `DeadlineList.html.twig:53-59`; `findUpcomingByUser` nu joinează nimic | La 30 de dosare, rândul e neutilizabil |
| Nouă chei de traducere fără utilizare; `findByStatusGroupedByUrgency()` are docblock „Used by dashboard" și zero call-sites; `Skeleton.html.twig` are zero utilizări | `messages.ro.yaml:1606-1607,1617-1625`, `LegalCaseRepository.php:110` | Cod mort |
| `greeting_name` se pasează ca `%name%` într-o cheie care nu conține `%name%` în niciun locale | `index.html.twig:16-17` vs `messages.ro.yaml:1605` | Calcul inutil |
| Două `h1` pe starea goală; tabel fără `<caption>` și `scope="col"`; nicio regulă `:focus-visible` în `app.css`; animație infinită fără gardă `prefers-reduced-motion` | `index.html.twig:15,132-139`, `HeroEmptyState.html.twig:31`, `app.css:86-88` | Accesibilitate |
| Cele șase teste de dashboard verifică doar prezența unor stringuri, niciunul nu asertează o cifră | `tests/Controller/DashboardControllerTest.php:99-122` | Ar trece și dacă randarea e complet stricată |

---

## 3. Rezerva asupra propunerii

Observația utilizatorului la final: mockup-ul v3 seamănă în mare parte cu pagina `/termene`. Verificarea a confirmat-o, iar două din cele trei argumente de non-duplicare din analiză nu rezistă.

**Suprapunerea de formă:**

| Element | `/termene` (implementat) | Dashboard v3 (propus) |
|---|---|---|
| Riglă de contoare colorate sus | 4 benzi | 6 benzi |
| Anatomia rândului | bandă 3px stânga, titlu plus badge, linie meta cu middot, buton pe dreapta | identică, plus gutter de tip în stânga |
| Verbe pe buton | „Generează cererea de OP", „Deschide dosarul", „Marchează finalizat" | „Generează cererea de OP", „Deschide dosarul", „Marchează timbrat" |
| Grupare cu antet | „Restante (5)", „Deblocate (1)", „Azi, marți 28" | în `critic.html`, coada e grupată pe benzi cu antet |

**Argumente infirmate:**

1. „Coada conține rânduri fără niciun termen în spate, pe care agenda nu le poate lista." Fals. `templates/deadlines/_blockages.html.twig` listează exact acele dosare, iar docblock-ul spune textual: *„The agenda can only list what exists; this zone is what is missing from it, which is why the page needs it at all."*
2. „Dashboard-ul numără dosare, agenda numără termene." Parțial fals. Agenda are deja contorul „blocaje pe dosar" cu subtitlul literal „dosare, nu termene", și are deja grupul „Deblocate: se poate depune cererea de ordonanță", care este banda „Termen debitor împlinit" din dashboard.

Rămâne valid un singur argument: deduplicarea pe dosar. Diferența dintre cele două pagini se reduce astfel la un mod de grupare.

În plus, dashboard-ul v3 este `/termene` cu mai puțin: fără filtre, fără registru, fără export, fără adăugare de termen, fără rail de prescripții.

Cauza ratării: verificarea adversarială a fost cerută pe non-duplicare **de conținut**, iar agenții au livrat argumente de conținut subtile, în timp ce forma a ieșit aproape identică. Întrebarea „de ce nu e asta deja pagina de termene?" nu a fost pusă explicit niciunui verificator.

---

## 4. Ce rămâne de decis

Patru direcții, neagreate încă:

1. **Contopire în `/termene`.** Dashboard-ul dispare ca pagină separată, agenda devine pagina de start redenumită „Panou de lucru" și primește doar câștigurile reale ale analizei: banda de cont, antetul cu verdict, fâșia de portofoliu onestă, comutator „grupează pe dosar". Cel mai puțin cod nou, elimină duplicarea definitiv.
2. **Redistribuirea rolurilor.** Dashboard preia triajul, `/termene` se retrage la registru: filtre, export, adăugare, prescripții, calendar. Costă demontarea unei pagini abia comise.
3. **Dashboard face altceva.** Devine portofoliu plus semnale, adică viziunile B și C respinse de arbitraj. Zero suprapunere, dar nu produce decizii directe.
4. **Doar reparații.** Se execută numai tranșa 0 din plan: cardul fals, cheile moarte, scurgerea de props, `has_cases`, sortarea pe `createdAt`. Decizia de design rămâne deschisă.

---

## 5. Ce rămâne folosibil din analiză indiferent de direcție

- Diagnosticul de la secțiunea 2 de mai sus, integral.
- Banda de cont condițională, pe teren curat: `base.html.twig` nu are niciun banner de cont.
- Antetul cu verdict calculat în locul unei etichete fixe.
- Onestitatea sumelor: `sumActiveAmountByUser` filtrează deliberat `currency = 'RON'`, iar UI-ul tace despre asta sub eticheta „În recuperare". Eticheta corectă este „principal în lucru", iar dosarele excluse se numără pe ecran. Definiția corectă a excluderii este `currency <> 'RON' OR amount IS NULL`, pentru că `getAmount()` este `?string` nullable.
- Corecțiile juridice de copy din secțiunea 7.1 a analizei, în special: cuvântul „Ireparabil" este fals pentru netimbrare (reexaminare în 15 zile, CPC art. 200 alin. 4) și pentru decădere (repunere în termen, CPC art. 186); „Poți depune" este o concluzie de admisibilitate pe care expirarea celor 15 zile nu o susține singură, pentru că `OpAdmissibilityValidator` blochează pe insolvență, BPI vechi și radiere; „Fără ea nu curg cele 15 zile" este neadevărat, pentru că termenul curge de drept de la primirea somației.
- Constrângerea tehnică verificată: `AgendaResponseFactory::isAgendaRequest()` decide după câmpul ascuns `_context=agenda`, deci orice buton POST tras dintr-o pagină nouă fără acel câmp scoate avocatul din listă.
- `NotificationExtension` și `SubscriptionExtension` nu memoizează și sunt deja apelate în topbar și sidebar. Trebuie memoizate pe modelul `DeadlineExtension` înainte de a adăuga un al doilea call-site.
