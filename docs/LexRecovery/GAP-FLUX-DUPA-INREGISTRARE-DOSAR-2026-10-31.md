# Fluxul după înregistrarea dosarului: ce există, ce lipsește

Data: 2026-10-31. Sursa: documentul avocatului „Dupa inregistrarea dosarului.docx” (12 pași în 4 etape, plus schema cu statusurile recomandate). Comparat cu codul de pe `lexrecovery` la `db82acb`. Branch-urile `feature/taxa-timbru-v3-clarificari` (`96c841f`) și `feature/executori-parteneri` (`f09be06`) nu sunt incluse decât acolo unde se spune explicit.

Legendă: ✅ există și se comportă ca în document · ⚠️ există parțial sau cu alt model · ❌ lipsește · ❓ de clarificat cu avocatul.

## 0. Preambul: identificarea dosarului pe portal și preluarea numărului

| Ce a scris avocatul | Stare | Ce face aplicația acum |
|---|---|---|
| Aplicația identifică dosarul pe portal.just.ro, îl asociază părților și preia numărul unic | ✅ | `PortalCaseDiscoveryService` caută zilnic (`app:portal-discover-cases`) dosarele depuse fără număr, după numele debitorului, la instanța din dosar; propunerea (`PortalCaseMatch`) apare pe pagina dosarului și avocatul o confirmă cu un click (portalul nu expune CUI, deci confirmarea umană e obligatorie). Confirmarea aplică `inregistreaza_dosar` și pornește monitorizarea. Există și căutarea manuală din tab-ul Portal. |

## Etapa I: monitorizarea judecății

| Pas | Ce a scris avocatul | Stare | Ce face aplicația acum |
|---|---|---|---|
| 1 | Primul termen detectat, asociat dosarului, avocatul notificat | ✅ | Ședința fără soluție de pe portal devine `HEARING_SCHEDULED`; se aplică automat `fixeaza_termen` (DOSAR_INREGISTRAT → TERMEN_FIXAT), se creează termenul JUDECATA cu data din portal, pleacă e-mail + notificare in-app („Termen fixat” și „Activitate pe portal”). |
| 2 | Monitorizarea termenelor, soluțiilor intermediare și amânărilor până la soluția pe fond | ⚠️ | Fiecare ședință nouă creează un termen JUDECATA nou, deci șirul de termene e urmărit. Două lipsuri: (a) o ședință cu soluție intermediară („Amână cauza”, „Amână pronunțarea”) e tratată ca `HEARING_COMPLETED` și declanșează notificarea „Hotărâre pe portal, necesită confirmare”, deși nu e nicio hotărâre; cardul de confirmare nu apare (heuristica nu găsește admite/respinge), dar notificarea de avertizare apare; (b) termenul JUDECATA al ședinței ținute nu se închide singur, rămâne pe avocat („Marchează ca făcut”). |
| 3 | Detectarea soluției și clasificarea: Soluționată / Respinsă / Închisă | ⚠️ | Soluția („Tip soluție” din portal) e clasificată de `RulingProposalResolver`: admite (inclusiv în parte) → propune `emite_ordonanta`; respinge sau anulează → propune `respinge`. Propunerea apare ca un card „Confirmă și aplică” + notificare; avocatul confirmă în modal (data pronunțării prepopulată, PDF-ul ordonanței opțional). Nu se aplică automat, decizie luată cu avocatul senior în mai: o emitere greșită ar porni termenul de 10 zile pe date eronate. **Soluționată** ✅ (ORDONANTA_EMISA). **Respinsă** ✅ (RESPINSA, motiv NO_PROOF / INADMISSIBLE; pe branch-ul taxă v3 și „anulată ca netimbrată” din DOSAR_INREGISTRAT). **Închisă** ❌: din DOSAR_INREGISTRAT și TERMEN_FIXAT nu există nicio ieșire de tip „închis”. `inchide_plata_integrala` merge doar din AMIABIL și SOMATIE_TRIMISA, `inchide_succes` / `inchide_fara_recuperare` doar din DEFINITIVA și EXECUTARE. Plata integrală în timpul judecății urmată de renunțare, perimarea sau o altă soluție de închidere nu au tranziție. |

## Etapa II: comunicarea și monitorizarea ordonanței

| Pas | Ce a scris avocatul | Stare | Ce face aplicația acum |
|---|---|---|---|
| 4 | Înregistrarea datei comunicării ordonanței către avocatul creditorului | ✅ / ❓ | Există modalul „Data comunicării ordonanței”, alerta amber pe dosar, blocajul RULING_COMMUNICATION_MISSING în agendă și memento săptămânal prin e-mail. Semantica textelor e însă amestecată: eticheta câmpului spune „recepție la debitor”, hint-ul trimite la procesul-verbal al executorului sau recipisa Poștei, iar nota de definitivare spune „data către debitor nu ne e cunoscută, doar cea către avocat”. Codul folosește o singură dată. Avocatul confirmă acum (pasul 9) că e data comunicării către avocat. Textele trebuie aliniate. |
| 5 | Termenul cererii în anulare (10 zile de la comunicare) + termenul de plată din ordonanță (10-30 zile) + 3 zile lucrătoare tampon | ⚠️ | **10 zile** ✅: termenul CERERE_IN_ANULARE se creează la salvarea datei, pe zile libere (N+1) cu prorogare la prima zi lucrătoare, prioritate critică, alerte 7/3/1. **Tamponul**: implementat 5 zile lucrătoare (`app.legal_deadlines.auto_final_buffer_days`), avocatul scrie acum 3; în iunie acceptase 5 și spusese că poate crește la 10. ❓ 3 sau 5. **Termenul de plată stabilit prin ordonanță** ❌: aplicația nu reține termenul de plată acordat de instanță (CPC art. 1022 alin. 3) și nu are un termen pentru el. Există doar o fereastră informativă de 40 de zile de la comunicare (`recommendedExecutionDate`), vizibilă numai în modalul de executare și numai în DEFINITIVA. |
| 6 | Verificarea existenței cererii în anulare (debitor sau creditor) | ✅ / ⚠️ | Căile de atac din portal (`caiAtac`) devin `APPEAL_FILED` și aplică automat `formuleaza_cerere_anulare` (doar din ORDONANTA_EMISA, protectiv față de definitivare). Lipsă: nu există buton manual „cerere în anulare depusă”; dacă avocatul află din comunicarea instanței înaintea portalului, sau dacă el însuși depune cererea (Î6 din august: „întrebăm avocatul dacă depune sau nu”), nu poate marca. Există doar „Nu depun cerere în anulare” (închide termenul propriu). |

## Etapa III: ramificații după verificarea căii de atac

| Pas | Ce a scris avocatul | Stare | Ce face aplicația acum |
|---|---|---|---|
| 7 | Cerere în anulare identificată → status „În anulare” | ✅ | IN_ANULARE („Cerere în anulare depusă”), e-mail la intrare, monitorizarea continuă, executarea rămâne posibilă cu avertismentul despre cauțiune (CPC art. 1024). |
| 8 | Monitorizarea soluționării: status „Soluționată în anulare” fără a echivala cu respingerea; ulterior, încărcarea hotărârii și decizia favorabil (→ executare) / nefavorabil (→ „Respinsă în anulare”) | ⚠️ | Detectarea există: soluția din IN_ANULARE e propusă avocatului (admite / anulează → `admite_cerere_anulare` → RESPINSA; respinge → `respinge_cerere_anulare` → DEFINITIVA) și se aplică doar la confirmare. Diferențe față de model: (a) nu există statusul intermediar „Soluționată în anulare”, soluția se traduce direct în rezultat final; (b) nu se cere încărcarea hotărârii din cererea în anulare; (c) admiterea în parte (ordonanța menținută parțial) e sugerată ca „admite” → RESPINSA, adică exact echivalarea cu respingerea pe care avocatul o refuză; (d) „Respinsă în anulare” nu e status propriu, dosarul ajunge în RESPINSA la fel ca o cerere introductivă respinsă (diferența e doar în e-mail și audit); (e) dacă executarea a pornit înainte de soluție (IN_ANULARE → EXECUTARE), monitorizarea portal se oprește automat (`CaseWorkflowSubscriber`, EXECUTARE nu e în `isActiveOnPortal`), deci soluția cererii în anulare nu mai e detectată și trebuie marcată manual din butoanele din EXECUTARE. Ce există deja pentru după: `annulmentRulingCommunicationDate` (data comunicării hotărârii din anulare), blocaj dacă lipsește, ancoră pentru prescripția executării. |
| 9 | Fără cerere în anulare după termen + 3 zile lucrătoare → „Definitivă”, dedusă exclusiv din comunicarea către avocat | ✅ | `CaseAutoFinalizer` rulează zilnic în `app:check-deadlines`: comunicare + 10 zile libere + prorogare + tampon în zile lucrătoare → `marcheaza_definitiva`; fără data comunicării nu finalizează și alertează. Deducerea se face deja exclusiv din data introdusă de avocat. Diferența e doar tamponul (5 vs 3, vezi pasul 5). După DEFINITIVA monitorizarea portal se oprește, deci o cerere în anulare tardivă cu repunere în termen nu ar mai fi detectată; acceptat în iunie. |

## Etapa IV: tranziția către executarea silită

| Pas | Ce a scris avocatul | Stare | Ce face aplicația acum |
|---|---|---|---|
| 10 | Verificarea posibilității executării: caracter executoriu, expirarea termenului de plată, suspendare | ⚠️ | Caracter executoriu ✅: `trece_la_executare` din ORDONANTA_EMISA (cere data comunicării), IN_ANULARE și DEFINITIVA; cere PDF-ul ordonanței în dosar și data cererii la executor; numărul de înregistrare al executorului se poate completa ulterior. Expirarea termenului de plată ❌: nu e modelat (vezi pasul 5), există doar fereastra informativă de 40 de zile. Suspendarea ❌: nu e modelată, apare doar în textul de avertisment; avocatul spune că nu o dezvoltăm acum. |
| 11 | Aplicația întreabă avocatul dacă pornește executarea, inclusiv înainte de definitivare, cu alertele stabilite | ⚠️ | Există CTA „Începe executarea silită” în acțiunile recomandate (ORDONANTA_EMISA cu dată de comunicare, IN_ANULARE, DEFINITIVA) și e-mailul „titlu executoriu obținut” la DEFINITIVA. Aplicația nu întreabă însă activ la momentul îndeplinirii condițiilor: nu există notificare, alertă în agendă sau blocaj „termenul de plată a expirat, poți trece la executare”. |
| 12 | Transfer către modulul de executare; dacă nu optează, dosarul rămâne disponibil | ⚠️ | EXECUTARE e un status cu data și numărul cererii la executor, termenul PRESCRIPTIE_EXECUTARE, blocajul „număr de înregistrare lipsă” și închiderea (plătit, parțial, abandonat, insolvabil). Nu există un flux distinct de executare (Faza 6, post-MVP). Executorii parteneri de pe `feature/executori-parteneri` acoperă comunicarea somației, nu executarea silită, și nu sunt legați de EXECUTARE. „Rămâne disponibil” ✅: dosarul stă în DEFINITIVA până la decizie sau închidere. |

## Statusurile recomandate de avocat față de cele existente

| Avocat | Aplicație | Observație |
|---|---|---|
| Dosar înregistrat | DOSAR_INREGISTRAT „Dosar înregistrat” | identic |
| Termen fixat / În judecată | TERMEN_FIXAT „Termen fixat” | identic |
| Soluționată | ORDONANTA_EMISA „Ordonanță emisă” | același sens, altă etichetă |
| Respinsă | RESPINSA „Respinsă” | identic pentru cererea introductivă |
| În anulare | IN_ANULARE „Cerere în anulare depusă” | același sens |
| Soluționată în anulare | lipsește | azi: respingerea cererii în anulare → DEFINITIVA direct |
| Respinsă în anulare | lipsește | azi: admiterea cererii în anulare → RESPINSA, același status ca respingerea cererii introductive |
| Definitivă | DEFINITIVA „Definitivă” | identic |

## Ce nu rulează fără cron

Pașii 0, 1, 2, 3, 6 și 9 depind de trei comenzi programate: `app:check-deadlines` (07:00), `app:portal-check-all` (08:00), `app:portal-discover-cases` (09:00). Nu rulează nicăieri în afară de producție (Coolify, Faza 9.1, neterminată). Pe dev și pe demo se întâmplă doar la „Verifică acum” din tab-ul Portal sau din comenzi lansate manual. Pentru ca avocatul să vadă fluxul end-to-end pe demo, stack-ul demo are nevoie de cron-uri (sau de rulare manuală zilnică).

## Decizii de luat (user, apoi avocat)

1. **„Închisă” din judecată**: ce soluții închid dosarul din DOSAR_INREGISTRAT / TERMEN_FIXAT (plată integrală + renunțare la judecată → INCHIS_SUCCES; perimare, anulare pe alte motive → RESPINSA sau INCHIS_FARA_RECUPERARE?) și dacă avocatul le marchează manual din propunerea de pe portal.
2. **Termenul de plată din ordonanță**: câmp „termen de plată acordat (zile, 10-30)” la confirmarea emiterii; termen nou calculat de la comunicare, cu 3 zile lucrătoare tampon; alertă „poți trece la executare” la expirare. Înlocuiește sau completează fereastra informativă de 40 de zile?
3. **Tamponul definitivării**: 3 sau 5 zile lucrătoare (un parametru de configurare).
4. **Modelul după cererea în anulare**: status intermediar „Soluționată în anulare” (soluție detectată, hotărâre de încărcat), încărcarea hotărârii obligatorie, decizia avocatului favorabil (→ DEFINITIVA, continuă spre executare) / nefavorabil (→ status nou „Respinsă în anulare” sau RESPINSA cu motiv), tratarea admiterii în parte (ordonanța rămâne pentru restul).
5. **Monitorizarea în EXECUTARE** cât timp cererea în anulare e pe rol (azi se oprește) și, eventual, după DEFINITIVA o perioadă scurtă.
6. **Textele datei comunicării**: o singură dată, cea către avocat, în etichetă, hint și nota de definitivare.
7. **Amânările**: eveniment propriu („Amânare”), fără notificarea de hotărâre, și închiderea automată a termenului de judecată trecut când apare ședința următoare sau soluția.
8. **Marcare manuală** „cerere în anulare depusă” (de debitor sau de creditor), pe lângă detectarea din portal.
9. **Cron pe demo** pentru monitorizare și termene, ca avocatul să vadă pașii automați fără intervenție.
