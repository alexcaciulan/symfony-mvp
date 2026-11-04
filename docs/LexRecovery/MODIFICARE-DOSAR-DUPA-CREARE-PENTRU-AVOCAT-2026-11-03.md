# Modificarea unui dosar dupa creare: ce s-a construit si intrebari

Data: 2026-11-03, actualizat 2026-11-04 (cu capturi din aplicatie)

## Cum era pana acum

Dupa ce confirmai ultimul pas al wizard-ului, dosarul era creat si nu mai putea fi modificat: nici creditorul, nici debitorul, nici facturile, nici suma, nici instanta. Daca incarcai o factura ulterior din tab-ul Documente, ea ramanea doar o anexa: nu intra in suma, nu aparea in somatie si nici in cererea de ordonanta. Munca din wizard se pierdea dupa 30 de minute de inactivitate sau la inchiderea browserului.

Pornind de la situatia in care ai uitat o factura pe un dosar deja creat, am stabilit ce se poate modifica si in ce conditii, am construit partea aceasta si am verificat-o in aplicatie. Mai jos e ce ai acum, cu scenarii concrete si capturi, si cateva intrebari la care am nevoie de parerea ta.

## Regula de baza

**Un dosar are partile fixe (creditorul si debitorii); ce se poate modifica e creanta (facturile).** Pentru orice altceva, dosarul se sterge si se face unul nou.

Toate modificarile sunt permise **doar cat timp dosarul e in "Amiabil"**, adica pana la generarea somatiei. Dupa ce somatia a plecat, debitorul a primit o suma si un termen (cele 15 zile curg de la comunicare), iar cererea de ordonanta nu poate cere mai mult decat s-a somat (mai putin se poate). Ce se intampla dupa somatie e tocmai ce vrem sa stabilim impreuna (intrebarile A si B de mai jos).

## Ce ai acum in aplicatie

| Nr. | Ce s-a construit | Ce rezolva |
|--------|--------------------------------------|------------------------------------------------------|
| 1 | **Ciorna salvata automat.** Fiecare pas completat in wizard ramane salvat. Daca inchizi aplicatia sau pleci la o sedinta, la intoarcere continui de unde ai ramas, chiar de pe alt calculator. Dosarele neterminate apar intr-o banda separata pe prima pagina si in lista de dosare, cu "Continua" si "Renunta". Documentele deja citite nu se citesc din nou. Ciornele neatinse 30 de zile se sterg singure. | Munca din wizard nu se mai pierde. |
| 2 | **Stergerea dosarului in "Amiabil".** Din meniul dosarului, cu confirmare si motiv optional. Dosarul dispare din liste, din agenda de termene si din biblioteci. Istoricul modificarilor se pastreaza pentru evidenta ta. | Ai gresit complet dosarul (alt creditor, alt debitor, alte documente). |
| 3 | **Adaugarea sau excluderea de facturi direct din pagina dosarului, in "Amiabil".** Pe cardul Creanta ai lista facturilor cu "Adauga factura" (dintr-un document incarcat, pe care aplicatia il citeste si iti precompleteaza datele, sau scris de mana) si "Exclude" / "Reinclude" pe fiecare factura, cu motivul excluderii. Tot acolo poti corecta textele creantei (obiectul, referinta contractului, clauza penala, numarul somatiei). Dupa fiecare actiune aplicatia recalculeaza suma, dobanda sau penalitatile si termenele de prescriptie, iti arata ce instanta ar rezulta (fara sa o schimbe fara confirmarea ta) si ce s-a schimbat. Nu treci din nou prin wizard. | Ai uitat o factura, o factura s-a platit intre timp sau ai de corectat un text. |

## Cum arata in aplicatie

Capturile sunt facute pe un dosar de test, cu date fictive.

**Cardul Creanta in "Amiabil"**, cu butoanele "Modifica detaliile creantei" si "Adauga factura" si cu "Exclude" pe fiecare factura:

![Cardul Creanta](capturi/editare-dosar/01-card-creanta-amiabil.png)

**Adauga factura, din document.** Incarci factura, aplicatia o citeste si iti arata datele citite, cu verificarea emitentului si a destinatarului fata de partile dosarului:

![Adauga factura din document](capturi/editare-dosar/02-adauga-factura-din-document.png)

![Factura citita si precompletata](capturi/editare-dosar/16-factura-citita-precompletata.png)

**Adauga factura, scrisa de mana**, cand nu ai documentul la indemana:

![Adauga factura manual](capturi/editare-dosar/03-adauga-factura-manual.png)

**Dupa adaugare: ce s-a schimbat si propunerea de instanta.** Capitalul (fara dobanzi si penalitati) a trecut de 200.000 lei, deci regulile indica tribunalul. Instanta ramane cea veche pana apesi "Schimba instanta":

![Ce s-a schimbat si propunerea de instanta](capturi/editare-dosar/04-ce-s-a-schimbat-si-propunere-instanta.png)

![Instanta schimbata dupa confirmare](capturi/editare-dosar/05-instanta-schimbata-dupa-confirmare.png)

**Exclude o factura, cu motiv.** Factura ramane in dosar ca exclusa si o poti reinclude oricand:

![Exclude factura cu motiv](capturi/editare-dosar/06-exclude-factura-cu-motiv.png)

![Factura exclusa si Reinclude](capturi/editare-dosar/07-factura-exclusa-si-reinclude.png)

**Modifica detaliile creantei** (textele, nu sumele):

![Modifica detaliile creantei](capturi/editare-dosar/08-modifica-detaliile-creantei.png)

**Ce refuza aplicatia si ce doar semnaleaza:**

- o factura care exista deja in dosar e refuzata (captura 09);
- o factura emisa de alta firma sau catre alt debitor e refuzata; fisierul ramane in Documente si il poti sterge de acolo (captura 15);
- o factura din alt contract, acelasi debitor, nu e refuzata: primesti un avertisment si confirmi ca ramane in dosar (captura 10, vezi intrebarea F);
- o factura cu scadenta mai veche de 3 ani e primita, dar iti semnalam ca prescriptia pare implinita (captura 11);
- o factura in valuta e convertita la cursul BNR din data emiterii (captura 12);
- o factura cu scadenta in viitor se salveaza, dar somatia nu se poate trimite pana la implinirea ei (captura 13).

![Factura dublura refuzata](capturi/editare-dosar/09-factura-dublura-refuzata.png)

![Factura din alt contract, avertisment](capturi/editare-dosar/10-factura-din-alt-contract-avertisment.png)

![Factura prescrisa](capturi/editare-dosar/11-factura-prescrisa.png)

![Factura in valuta](capturi/editare-dosar/12-factura-in-valuta.png)

![Somatia blocata de o scadenta viitoare](capturi/editare-dosar/13-somatie-blocata-scadenta-viitoare.png)

![Factura altor parti, refuzata](capturi/editare-dosar/15-factura-alte-parti-refuzata.png)

**Dupa somatie, cardul e blocat.** Daca ai pagina deschisa in doua ferestre si somatia pleaca dintr-una, cealalta iti spune ca dosarul a trecut de etapa amiabila:

![Editare refuzata dupa somatie](capturi/editare-dosar/18-editare-refuzata-somatia-a-plecat.png)

**Dosarele neterminate**, reluate de pe alt calculator:

![Dosare neterminate](capturi/editare-dosar/19-dosare-neterminate-alt-browser.png)

**Stergerea dosarului**, cu confirmare:

![Sterge dosarul](capturi/editare-dosar/21-sterge-dosar-confirmare.png)

## Scenarii

| Nr. | Situatia | Ce se intampla in aplicatie |
|--------|------------------------------------|--------------------------------------------------------|
| S1 | Ai uitat o factura. Somatia nu a fost inca generata. | Pe cardul Creanta apesi "Adauga factura", incarci factura sau completezi datele, confirmi. Aplicatia recalculeaza suma, dobanda sau penalitatile, instanta competenta (cu confirmarea ta) si termenele de prescriptie si pastreaza istoricul modificarii. |
| S2 | Ai uitat o factura. Somatia a plecat deja. | Blocat pentru moment. Vezi intrebarea A. |
| S3 | O factura s-a platit integral intre timp. Somatia nu a plecat. | Daca s-a platit la timp, o excluzi cu motivul "Platita integral": suma si dobanda se recalculeaza, factura ramane vizibila ca exclusa si o poti reinclude. Daca s-a platit cu intarziere, ai dreptul la accesorii de la scadenta pana la plata (penalitatile din contract sau, intre profesionisti, dobanda legala penalizatoare), deci excluderea le-ar pierde; aplicatia te avertizeaza in dialogul de excludere. Vezi intrebarea G. |
| S4 | O factura s-a platit intre timp. Somatia a plecat deja. | Blocat pentru moment. Vezi intrebarea B. Plata integrala a creantei (capital si accesorii) inchide dosarul ca si azi; daca raman accesorii neincasate, decizi tu daca il inchizi. |
| S5 | Adaugi o factura emisa de alta firma sau catre alt debitor decat cei din dosar, sau o factura care exista deja in dosar. | Aplicatia o refuza si iti spune de ce. Facturile altor parti merg intr-un dosar nou. O factura de la acelasi debitor, dar din alt contract, nu e refuzata: primesti un avertisment si confirmi (vezi intrebarea F). |
| S6 | Ai gresit creditorul sau debitorul. Somatia nu a plecat. | Stergi dosarul si faci altul. Partile nu se schimba pe un dosar existent. |
| S7 | Ai inceput un dosar si ai plecat inainte de ultimul pas. | Dosarul neterminat te asteapta in banda "Dosare neterminate". Continui de unde ai ramas, cu documentele deja citite, chiar de pe alt calculator. |
| S8 | Factura adaugata duce creanta peste 200.000 lei sau vine din alt contract, iar instanta competenta se schimba. | Aplicatia iti arata la confirmare ca instanta s-ar schimba si ce instanta propune. Instanta ramane cea aleasa pana apesi "Schimba instanta". Vezi intrebarea C. |
| S9 | Vrei doar sa corectezi obiectul creantei, referinta contractului, clauza penala sau numarul somatiei. | Pe cardul Creanta, "Modifica detaliile creantei". Sumele nu se modifica, dar referinta contractului poate schimba instanta propusa; iti aratam asta la confirmare. |
| S10 | Cererea de ordonanta a fost deja depusa la instanta. | Nimic nu se mai modifica in aplicatie. Vezi intrebarea D. |
| S11 | Ai generat somatia, dar nu ai trimis-o inca, si modifici creanta. | Modificarea e permisa cat timp dosarul e in "Amiabil". Somatia generata e marcata ca depasita si trebuie generata din nou inainte de trimitere; aplicatia te previne in fiecare dialog. |

## Intrebari

**A. Factura uitata dupa somatie.** Ce faci in practica? Trimiti o somatie noua pentru toata creanta (si termenul de 15 zile curge din nou), trimiti o somatie doar pentru factura noua si o pastrezi pe prima, sau deschizi un dosar nou doar pentru factura uitata? Ne intereseaza ce faci de obicei, ca aplicatia sa urmeze acelasi drum.

**B. Factura platita dupa somatie.** Daca debitorul plateste o parte din facturi dupa somatie, cererea de ordonanta se face pe suma ramasa fara o somatie noua? Sau si aici trimiti o somatie noua pentru suma ramasa?

**C. Schimbarea instantei.** Cand o factura adaugata schimba instanta competenta, aplicatia te avertizeaza si pastreaza instanta aleasa pana confirmi. Gradul instantei (judecatorie sau tribunal) rezulta din valoarea capitalului si nu se alege. Vrei sa poti alege intre instantele teritoriale alternative permise de lege (de exemplu locul executarii contractului), sau e suficienta cea propusa dupa sediul debitorului?

**D. Dupa depunerea cererii.** Confirmi ca, odata depusa cererea la instanta, nu mai modifici nimic in aplicatie, iar o eventuala modificare a cererii (CPC art. 204) o faci direct la instanta, in limitele procedurii speciale si fara a depasi suma pentru care a fost comunicata somatia? Documentele generate (cererea, opisul) raman consultabile si neschimbate in aplicatie.

**F. Factura din alt contract, acelasi debitor.** O treci in acelasi dosar (o singura somatie, o singura cerere) sau faci dosar separat? Ce faci daca, din cauza ei, instanta competenta s-ar schimba sau ar rezulta instante diferite pentru cele doua contracte? Textul pe care il arata aplicatia spune ca "cumulul pretentiilor si competenta instantei pentru fiecare se apreciaza potrivit CPC art. 99 si 218": te rog confirma regula si trimiterile la articole.

**G. Factura platita cu intarziere, inainte de somatie.** Ce faci in practica cu accesoriile de la scadenta pana la plata (penalitatile din contract sau, intre profesionisti, dobanda legala penalizatoare): le ceri in continuare sau renunti la ele? Cum imputezi plata: intai pe cheltuieli si accesorii, apoi pe capital (C. civ. art. 1506)? Ai prefera sa inregistrezi plata pe factura, cu data ei, in loc sa o excluzi din dosar?

**H. Facturi in alta moneda.** Azi aplicatia converteste la cursul BNR din data emiterii facturii. Legea pare sa indice cursul de la scadenta sau de la plata pentru suma datorata (C. civ. art. 1578), iar pentru valoarea cererii si taxa de timbru cursul de la data depunerii. Ce curs aplici in practica pentru fiecare scop: suma ceruta, dobanda, taxa?

**I. Note de credit si storno.** O nota de credit pe o factura din dosar o tratezi ca o reducere a facturii, ca pozitie separata sau excluzi factura?

**J. Dosarul sters.** Cat timp vrei sa ramana istoricul si documentele unui dosar sters in evidenta ta? Ai obligatii de pastrare din statutul profesiei sau din contractul cu clientul de care trebuie sa tinem cont? Si GDPR cere o durata justificata pentru datele debitorilor din dosarele sterse.

**K. Factura fara scadenta.** Cand factura nu are termen de plata, aplici 30 de zile de la primirea facturii de catre debitor (Legea 72/2013 art. 3, doar intre profesionisti) sau termenul din contract? Aplicatia nu stie data primirii, doar data emiterii: ce data folosesti in practica? Pentru un debitor care nu e profesionist, cum stabilesti scadenta?

**L. Dobanda inainte de somatie.** In pagina dosarului, dobanda e calculata pana la data crearii dosarului si se recalculeaza la data somatiei. Preferi sa o vezi estimata pana la zi?

**M. Aceeasi factura in doua dosare.** Daca aceeasi factura apare in doua dosare ale tale (de exemplu la doi debitori raspunzatori), vrei un avertisment sau aplicatia trebuie sa refuze?

**N. Clauza penala descoperita dupa creare.** Ti s-a intamplat sa descoperi clauza penala din contract (sau sa renunti la ea) dupa ce ai creat dosarul pe dobanda legala? Cat de des se schimba modul de calcul al accesoriilor, rata sau plafonul inainte de somatie? Daca se intampla, le-am face modificabile din acelasi dialog cu detaliile creantei, doar in "Amiabil".

**O. Onorariul din cererea de ordonanta.** Cheltuielile de recuperare (onorariul fix sau de succes) le stii de la crearea dosarului sau se stabilesc pana la depunere? Ar trebui sa poata fi corectate si dupa somatie, pana la generarea cererii? Nu intra in somatie, apar doar in cerere.

**P. Note interne pe dosar.** Ai nevoie de un loc in dosar pentru note care nu apar in niciun act (discutii cu clientul, promisiuni de plata, contactul contabilului debitorului)?

**E. Alte situatii.** Ai intalnit si alte cazuri in care ai avut nevoie sa modifici un dosar dupa ce l-ai creat (alt reprezentant al creditorului, alt cont bancar, alt termen de plata contractual, debitor intrat in insolventa)? Spune-ne ce s-a intamplat concret, ca sa vedem daca intra in aceasta propunere sau ramane pentru mai tarziu.
