# Somația de plată V2: ce a rămas neimplementat sau de clarificat

Stare la 2026-09-29. Fiecare punct a fost verificat în cod (analiză plus contestare independentă) pe branch-ul `feature/somatie-v2-followups`. Ce era necesar s-a rezolvat. Restul fie așteaptă un răspuns al avocatului, fie nu se poate produce azi.

## Rezolvat în runda 2026-09-29

- **Concordanța somație / cerere (fostul punct 7).** Testul `SummonsPaymentOrderConcordanceTest` confirmă că cererea cere exact principalul, accesoriile și totalul din somație, pe ambele ramuri (dobândă legală, penalitate contractuală), cu două facturi și o notă de credit. Testul pică dacă cererea ar calcula la data de azi în loc de data somației.
- **Numerotarea articolelor în cerere și opis.** Cererea citează acum „art. 1.014 și următoarele” și „art. 1.017”, iar opisul „CPC art. 1.017”, la fel ca somația. Intervalul „1013-1024” nu a fost înlocuit cu alt interval, pentru că al doilea capăt nu a fost confirmat. Mesajele din interfață trec de la 1013 la 1014 și de la „1016 alin. 2” la „1017 alin. 2” (alineatul e presupus neschimbat).
- **Suma golită în pasul „Creanță”.** Inputul primea un `value=""` înaintea valorii reale, iar browserul îl afișa pe primul. Defectul apărea și la prima afișare, și la scadență.
- **Creditorul ales din bibliotecă.** Datele creditorului ales ajung acum în pasul de confirmare, iar alegerea rămâne selectată la revenirea pe pas. Totodată, golirea selecției și completarea manuală a altui creditor nu mai leagă dosarul de creditorul ales anterior. Înainte, id-ul rămas în câmpul ascuns câștiga la salvare, iar somația și cererea ieșeau pe numele creditorului greșit.
- **Validarea IBAN.** Partea de cont acceptă 16 caractere alfanumerice (conturile de Trezorerie conțin litere), la fel ca validatorul Symfony pentru RO.

## Rămas, cu motivul

1. **Poarta de readiness înainte de generare (fostul punct 1).** Nu se poate ajunge azi în niciuna dintre stările descrise. Wizard-ul cere rata pe ramura contractuală din prima zi a acestui mod, scadența e obligatorie și nu poate fi în viitor, iar o poziție nescadentă dă eroare de admisibilitate. Dosarul nu se poate edita după creare (nici din administrare). În baza de dev nu există niciun dosar afectat. Devine necesar odată cu punctul 5: atunci constrângerile din `Step3ClaimData` se pun și în formularul de editare, iar `CaseSummonsController::generate` primește o gardă scurtă, cu răspuns prin `respond()` (Turbo Stream cu toast), după modelul din `CasePaymentOrderController`.
2. **Plățile parțiale (fostul punct 2).** Premisa era greșită: suma achitată pe factură nu se completează din niciun flux, deci somația nu poate ignora o plată înregistrată. Plățile menționate în documente sunt doar semnalate în wizard (scăzământ în descriere, plată în extras) și cer confirmare individuală. Decizia de fond rămâne întrebarea 4.
3. **Nota de credit și baza dobânzii (fostul punct 3).** Confirmat: principalul scade cu nota, dobânda se calculează pe facturile întregi. Corectarea cere o regulă juridică (dobânda pe partea stornată până la data notei sau deloc), deci așteaptă întrebarea 15. Somația și cererea rămân consecvente între ele.
4. **Mai mulți debitori (fostul punct 4).** Pozițiile nu sunt legate de un anumit debitor, deci nu e vorba de „facturile altuia”. Problema reală e că doar primul debitor primește somație. Așteaptă întrebarea 7.
5. **Editarea câmpurilor pe dosarele existente.** Lipsa e generală: după creare nu se poate edita nimic din dosar (nici suma, nici părțile). Un formular doar pentru clauză ar fi parțial și ar trebui refăcut după întrebările 8, 11 și 12. PDF-ul folosește formulările de rezervă, deci nu iese greșit.
6. **Numărul somației.** Așteaptă întrebarea 10. Dacă se mută la generare, câmpul trebuie pus în ambele formulare vizibile din pagina dosarului (butonul principal și lista de documente), nu doar într-unul. Altfel numărul s-ar pierde, pentru că somația nu se mai poate regenera după trimitere.
7. **Completarea automată din contract.** Așteaptă întrebarea 8 și rezolvarea secretului profesional (AI Vision nu a rulat în producție).
8. **Fraza „La aceste sume se adaugă dobânda…” la accesoriu 0.** Nu se schimbă. Fraza anunță dobânda care va curge de acum înainte, iar asta rămâne adevărat și când accesoriul calculat până la data somației e 0.
9. **Câmpurile noi în administrare.** Nu se adaugă: panoul e numai pentru citire pe dosare, deci nu aduce nimic documentului.

## De clarificat cu avocatul

1. **Unde stă tabelul de calcul.** Nota cu coloanele e pusă sub tabelul facturilor, dar descrie calculul dobânzii. Acum tabelul de calcul e în secțiunea II, iar în secțiunea I e un tabel simplu cu facturile. Corect, sau preferă un singur tabel combinat în secțiunea I?
2. **Rotunjirea.** Fiecare rând e rotunjit la bani, iar totalul e calculat pe valorile exacte, deci poate diferi cu câțiva bani de suma rândurilor. Sub tabel apare atunci nota „Totalul este calculat pe valorile nerotunjite ale fiecărui interval.” O acceptă, sau preferă totalul ca sumă a rândurilor rotunjite?
3. **Rolul reprezentantului creditorului.** „În calitate de Administrator” era scris fix în text și l-am scos. Rămâne fără rol, sau adăugăm un câmp „calitatea reprezentantului”?
4. **Plățile parțiale.** Somația trebuie blocată până la imputare, generată pe soldul brut cu avertisment doar pentru avocat, sau generată pe suma rămasă?
5. **„Stabilite prin Contract”.** Textul folosește „Contract” cu majusculă fără să-l definească. Acceptă „contract”, cu literă mică?
6. **Onorariul.** Secțiunea de cheltuieli de recuperare (onorariul fix și cel de succes) nu mai apare în textul nou. A fost intenționat?
7. **Mai mulți debitori.** Câte o somație separată pentru fiecare debitor, cu facturile lui, sau una singură, adresată tuturor, în solidar?
8. **Extragerea automată a clauzei.** Completează clauza manual, sau vrea extragere automată din contract, cu verificare ulterioară?
9. **Art. 1.522 Cod civil** (punerea în întârziere) nu mai apare în temeiul de drept. A fost intenționat?
10. **Numărul somației.** Este numărul din registrul de ieșire al cabinetului, introdus manual?
11. **Denumirea accesoriilor.** Lista „penalități de întârziere / dobânzi penalizatoare contractuale / majorări de întârziere” acoperă practica, sau mai trebuie variante?
12. **Tipuri de rată contractuală.** Acum e suportată doar o rată procentuală pe zi. Are contracte cu rată lunară sau anuală, ori cu plafon („fără a depăși valoarea debitului”)?
13. **Creditor persoană fizică.** Textul spune „Subscrisa, [nume] … (denumită în continuare „Creditoarea”)”, formulă potrivită pentru o firmă. Pentru o persoană fizică ar fi „Subsemnatul/Subsemnata … Creditorul/Creditoarea”. Cum vrea formularea? CNP-ul trebuie să apară?
14. **Creanțe în valută.** Sub fiecare factură convertită apare nota „X EUR × curs BNR Y din data Z”. O păstrăm?
15. **Nota de credit.** Trebuie să reducă suma pe care se calculează dobânda pentru factura pe care o corectează?
16. **Ziua schimbării ratei BNR.** În ziua în care intră în vigoare o nouă rată, calculul folosește încă rata veche. E corect juridic?
17. **Restul numerotării din Titlul IX.** Cuprinsul ediției curente a codului arată Titlul IX între art. 1.014 și 1.025, deci pare deplasat în întregime cu o unitate. Răspunsul anterior spunea însă că art. 1015 și 1024 rămân. Care sunt, în ediția folosită, articolele pentru comunicarea somației, dovada comunicării (azi citată „art. 1017 alin. 2”) și cererea în anulare (azi „art. 1024”)? Până la răspuns, actele tipărite citează doar art. 1.014, 1.015 și 1.017.

## Inconsecvențe cunoscute între documente

- **Mai mulți debitori.** Totalurile somației și ale cererii coincid (aceleași poziții, același calcul, confirmat prin test). Diferă doar destinatarul: somația merge la primul debitor, cererea îi cuprinde pe toți. Ține de întrebarea 7.
- **Alte articole din procedură.** Trimiterile la art. 1020-1022 apar doar în interfață, nu în actele depuse. Deplasarea cu o unitate nu a fost confirmată de avocat, deci nu s-au schimbat.

## Wizard

- **Creditorul se suprascrie după CUI.** Nu se schimbă. Același CUI înseamnă aceeași persoană juridică, iar constrângerea UNIQUE(user, cui) e voită. Actele deja emise sunt PDF-uri fixe, iar denumirea nouă e cea corectă pentru actele viitoare. Un IBAN lăsat gol înseamnă „nu știu”, nu „șterge”.

## Date de test rămase în mediul de dezvoltare

- **Dosare:** patru dosare noi (50–53), toate în starea „Somație trimisă”. Dosarul 53 are două poziții adăugate direct în baza de date.
- **Abonament:** abonamentul avocatului de test e prelungit până la 21.10.2026.
- **Creditor suprascris:** creditorul dosarului 50 a primit numele „SC Fara Cont Test SRL”, din cauza suprascrierii după CUI.
