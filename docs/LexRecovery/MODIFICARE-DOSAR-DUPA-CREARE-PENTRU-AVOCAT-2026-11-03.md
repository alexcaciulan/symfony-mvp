# Modificarea unui dosar dupa creare: propunere si intrebari

Data: 2026-11-03

## Cum e azi

Dupa ce ai confirmat ultimul pas al wizard-ului, dosarul e creat si nu mai poate fi modificat: nici creditorul, nici debitorul, nici facturile, nici suma, nici instanta. Daca incarci o factura ulterior din tab-ul Documente, ea ramane doar o anexa: nu intra in suma, nu apare in somatie si nici in cererea de ordonanta.

Pornind de la situatia in care ai uitat o factura pe un dosar deja creat, am analizat ce ar trebui sa se poata modifica si in ce conditii. Mai jos e propunerea, cu scenarii concrete, si cateva intrebari la care am nevoie de parerea ta.

## Ce propunem

Regula de baza: **un dosar are partile fixe (creditorul si debitorii); ce se poate modifica e creanta (facturile)**. Pentru orice altceva, dosarul se sterge si se face unul nou.

Toate modificarile de mai jos sunt permise **doar cat timp dosarul e in "Amiabil"**, adica pana la generarea somatiei. Dupa ce somatia a plecat, debitorul a primit o suma si un termen (cele 15 zile curg de la comunicare), iar cererea de ordonanta trebuie sa corespunda cu somatia. Ce se intampla dupa somatie e tocmai ce vrem sa stabilim impreuna (intrebarile A si B).

| Nr. | Propunere | Ce rezolva |
|-----|--------------------------------------------------|--------------------------------------------------------------|
| 1 | **Ciorna salvata automat.** Fiecare pas pe care l-ai completat in wizard ramane salvat. Daca inchizi aplicatia sau pleci la o sedinta, la intoarcere continui de unde ai ramas. Dosarele neterminate apar intr-o lista separata, cu "Continua" si "Renunta". | Azi, munca din wizard se pierde dupa 30 de minute de inactivitate, la inchiderea browserului sau la apasarea pe "Dosar nou". |
| 2 | **Stergerea dosarului in "Amiabil".** Cu confirmare. Nimic nu a plecat prin aplicatie catre debitor sau instanta. Dosarul dispare din liste, iar istoricul modificarilor si documentele se pastreaza pentru evidenta ta, pe o perioada limitata (vezi intrebarea J). | Ai gresit complet dosarul (alt creditor, alt debitor, alte documente). |
| 3 | **Adaugarea sau excluderea de facturi direct din pagina dosarului, in "Amiabil".** Pe cardul Creanta ai lista facturilor cu "Adauga factura" (dintr-un document incarcat, pe care aplicatia il citeste si iti precompleteaza datele, sau scris de mana) si "Exclude" / "Reinclude" pe fiecare factura. Tot acolo poti corecta textele creantei (obiectul, referinta contractului, clauza penala). Dupa fiecare actiune aplicatia recalculeaza suma, dobanda (pana la data crearii dosarului; la somatie se recalculeaza pana la data somatiei) si termenele de prescriptie, iti arata ce instanta ar rezulta (fara sa o schimbe fara confirmarea ta) si ce s-a schimbat. Nu treci din nou prin wizard. | Ai uitat o factura sau o factura s-a platit intre timp. |

## Scenarii

| Nr. | Situatia | Ce se intampla in aplicatie (propus) |
|-----|------------------------------------------------|--------------------------------------------------------------------------|
| S1 | Ai uitat o factura. Somatia nu a fost inca generata. | Pe cardul Creanta apesi "Adauga factura", incarci factura sau completezi datele, confirmi. Aplicatia recalculeaza suma, dobanda, instanta competenta si termenele de prescriptie si pastreaza istoricul modificarii. |
| S2 | Ai uitat o factura. Somatia a plecat deja. | Blocat pentru moment. Vezi intrebarea A. |
| S3 | O factura s-a platit integral intre timp. Somatia nu a plecat. | Daca s-a platit la timp, o excluzi: suma si dobanda se recalculeaza, factura ramane vizibila ca exclusa si o poti reinclude. Daca s-a platit cu intarziere, ai dreptul la dobanda si penalitati de la scadenta pana la plata, deci excluderea le-ar pierde. Vezi intrebarea G. |
| S4 | O factura s-a platit intre timp. Somatia a plecat deja. | Blocat pentru moment. Vezi intrebarea B. Plata integrala a creantei inchide dosarul ca si azi. |
| S5 | Adaugi o factura emisa de alta firma sau catre alt debitor decat cei din dosar, sau o factura care exista deja in dosar. | Aplicatia o refuza si iti spune de ce. Facturile altor parti merg intr-un dosar nou. O factura de la acelasi debitor, dar din alt contract, nu e refuzata: primesti un avertisment (vezi intrebarea F). |
| S6 | Ai gresit creditorul sau debitorul. Somatia nu a plecat. | Stergi dosarul si faci altul. Partile nu se schimba pe un dosar existent. |
| S7 | Ai inceput un dosar si ai plecat inainte de ultimul pas. | Dosarul neterminat te asteapta in lista de ciorne. Continui de unde ai ramas, cu documentele deja citite. |
| S8 | Factura adaugata duce creanta peste 200.000 lei sau vine din alt contract, iar instanta competenta se schimba. | Aplicatia iti arata la confirmare ca instanta s-a schimbat si ce instanta propune. Vezi intrebarea C. |
| S9 | Vrei doar sa corectezi obiectul creantei, referinta contractului sau clauza penala. | Pe cardul Creanta, "Modifica detaliile creantei". Sumele nu se modifica, dar referinta contractului poate schimba instanta propusa; iti aratam asta la confirmare. |
| S10 | Cererea de ordonanta a fost deja depusa la instanta. | Nimic nu se mai modifica in aplicatie. Vezi intrebarea D. |

## Intrebari

**A. Factura uitata dupa somatie.** Ce faci in practica? Trimiti o somatie noua pentru toata creanta (si termenul de 15 zile curge din nou), trimiti o somatie doar pentru factura noua si o pastrezi pe prima, sau deschizi un dosar nou doar pentru factura uitata? Ne intereseaza ce faci de obicei, ca aplicatia sa urmeze acelasi drum.

**B. Factura platita dupa somatie.** Daca debitorul plateste o parte din facturi dupa somatie, cererea de ordonanta se face pe suma ramasa fara o somatie noua? Sau si aici trimiti o somatie noua pentru suma ramasa?

**C. Schimbarea instantei.** Cand o factura adaugata schimba instanta competenta, e suficient sa te avertizam si sa confirmi, sau vrei sa poti pastra instanta aleasa initial chiar daca aplicatia propune alta?

**D. Dupa depunerea cererii.** Confirmi ca, odata depusa cererea la instanta, nu mai modifici nimic in aplicatie, iar o eventuala completare a cererii o faci direct la instanta? Documentele generate (cererea, opisul) raman consultabile si neschimbate in aplicatie.

**F. Factura din alt contract, acelasi debitor.** O treci in acelasi dosar (o singura somatie, o singura cerere) sau faci dosar separat? Ce faci daca, din cauza ei, instanta competenta s-ar schimba sau ar rezulta instante diferite pentru cele doua contracte?

**G. Factura platita cu intarziere, inainte de somatie.** Ce faci in practica cu dobanda si penalitatile de la scadenta pana la plata: le ceri in continuare sau renunti la ele? Ai prefera sa inregistrezi plata pe factura, cu data ei, in loc sa o excluzi din dosar?

**J. Dosarul sters.** Cat timp vrei sa ramana istoricul si documentele unui dosar sters in evidenta ta? Ai obligatii de pastrare din statutul profesiei sau din contractul cu clientul de care trebuie sa tinem cont?

**M. Aceeasi factura in doua dosare.** Daca aceeasi factura apare in doua dosare ale tale (de exemplu la doi debitori raspunzatori), vrei un avertisment sau aplicatia trebuie sa refuze?

**E. Alte situatii.** Ai intalnit si alte cazuri in care ai avut nevoie sa modifici un dosar dupa ce l-ai creat (alt reprezentant al creditorului, alt cont bancar, alt termen de plata contractual, debitor intrat in insolventa)? Spune-ne ce s-a intamplat concret, ca sa vedem daca intra in aceasta propunere sau ramane pentru mai tarziu.
