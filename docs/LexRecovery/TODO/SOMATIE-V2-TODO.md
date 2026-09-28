# Somația de plată V2: ce a rămas neimplementat sau de clarificat

Stare la 2026-09-28, după commit-ul `6b245e3` pe `feature/somatie-avocat-v2`.

## Neimplementat, în ordinea importanței

1. **Blocarea generării când datele nu permit o somație corectă.** Butonul „Trimite somația” ar trebui ascuns, cu motivul afișat în locul lui, în toate cele 4 locuri de unde se poate genera: butonul principal din pagina dosarului, lista de documente, acțiunile recomandate și agenda de termene. Cazurile de blocat:
   - dosar marcat „penalitate contractuală”, dar fără rată. Azi somația iese cu dobândă legală etichetată drept penalitate contractuală și fără tabel de calcul. E singurul caz care produce un document juridic greșit, deci are prioritate. Wizard-ul nu mai permite un dosar nou așa, dar pot exista dosare vechi;
   - nicio factură nu are scadență sau sumă, deci nu se poate calcula nimic;
   - o factură nu e încă scadentă la data generării, deci creanța nu e exigibilă.

   Dacă cererea de generare ajunge totuși la server, răspunsul trebuie să arate motivul pe loc, fără reîncărcarea paginii.

2. **Plățile parțiale nu sunt scăzute.** Aplicația permite înregistrarea unei plăți parțiale pe o factură, dar somația cere tot soldul inițial, deci o sumă mai mare decât cea datorată. Până la decizia avocatului, minimul ar fi un avertisment vizibil înainte de generare: „există plăți parțiale înregistrate care nu sunt reflectate în sold”.

3. **Nota de credit nu reduce baza de calcul a dobânzii.** Principalul scade corect cu valoarea notei, dar dobânda se calculează pe facturile întregi. Exemplu: facturi de 10.000 + 6.000 și o notă de credit de 1.500 pe prima factură dau un principal de 14.500, dar dobânda se calculează pe 16.000.

4. **Dosarele cu mai mulți debitori.** Somația e adresată doar primului debitor, dar cuprinde facturile tuturor. Primul debitor ar primi astfel și facturile altuia.

5. **Editarea câmpurilor noi pe dosarele deja create.** Obiectul contractului, articolul și textul clauzei, denumirea accesoriilor, numărul somației și rata se pot completa doar în wizard, la crearea dosarului. Dosarele existente nu le pot primi, așa că somația lor folosește formulările generice de rezervă.

6. **Numărul somației se cere prea devreme.** Acum se introduce în wizard, deși numărul din registrul de ieșire al cabinetului se atribuie de obicei la emitere. Ar fi mai firesc un câmp opțional lângă butonul de generare.

7. **Concordanța dintre somație și cererea de ordonanță.** Data somației se salvează acum înainte de generarea PDF-ului, ca ambele documente să calculeze dobânda până la aceeași dată. Lipsește un test care să confirme că sumele din cele două documente coincid.

8. **Completarea automată din contract.** Articolul și textul clauzei penale, obiectul contractului și denumirea accesoriilor ar putea fi extrase de AI din contractul încărcat. Asta presupune trimiterea contractului la un serviciu AI, deci acordul clientului și respectarea secretului profesional.

9. **Mărunțișuri.**
   - Fraza „La aceste sume se adaugă dobânda…” apare și când accesoriul e 0, adică scadența e chiar în ziua generării.
   - Câmpurile noi lipsesc din interfața de administrare.

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

## Inconsecvențe cunoscute între documente

- **Numerotarea articolelor.** Cererea de ordonanță de plată citează încă „art. 1013–1024 CPC”, în timp ce somația folosește numerotarea confirmată de avocat (art. 1.014 pentru creanța certă, lichidă și exigibilă, art. 1.015 pentru somație). Renumerotarea trebuie aplicată și în cerere.
- **Mai mulți debitori.** Pe astfel de dosare, somația și cererea de ordonanță pot avea totaluri diferite până se decide cum se tratează acești debitori.

## Probleme existente în wizard, fără legătură cu somația

1. **Suma se golește.** În pasul „Creanță”, câmpul sumei se golește vizual după modificarea altui câmp, deși aplicația reține valoarea. Comportamentul exista dinainte.
2. **Creditorul se suprascrie după CUI.** Un creditor nou cu același CUI ca unul existent îl suprascrie (se schimbă numele și pe dosarele vechi), iar un IBAN lăsat gol nu șterge IBAN-ul salvat anterior.
3. **Biblioteca de creditori.** Selectarea unui creditor din bibliotecă nu completează formularul.
4. **Validarea IBAN** respinge litere în partea de cont, deși unele IBAN-uri românești reale le conțin.

## Date de test rămase în mediul de dezvoltare

- **Dosare:** patru dosare noi (50–53), toate în starea „Somație trimisă”. Dosarul 53 are două poziții adăugate direct în baza de date.
- **Abonament:** abonamentul avocatului de test e prelungit până la 21.10.2026.
- **Creditor suprascris:** creditorul dosarului 50 a primit numele „SC Fara Cont Test SRL”, din cauza suprascrierii după CUI.
