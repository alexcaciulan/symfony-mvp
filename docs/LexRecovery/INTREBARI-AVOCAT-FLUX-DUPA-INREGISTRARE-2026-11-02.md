# Intrebari la documentul "Dupa inregistrarea dosarului"

Data: 2026-11-02

## Ce exista deja in aplicatie

| Pct. | Ce ai scris | Stare in aplicatie |
|------|-----------------------------------------|---------------------------------------------------------------|
| 1 | Primul termen detectat si notificat | Da. Sedinta de pe portal trece dosarul in "Termen fixat", creeaza termenul si trimite notificare. |
| 3 | Solutia: Solutionata / Respinsa | Da. Solutia e detectata si propusa, tu o confirmi dintr-un click. "Inchisa" lipseste (vezi intrebarea A). |
| 4 | Data comunicarii ordonantei | Da. Se cere la emitere, cu alerta si memento saptamanal cat timp lipseste. |
| 5 | Termenul de 10 zile pentru cererea in anulare | Da. Zile libere, prorogare pe zi lucratoare, alerte. Termenul de plata din ordonanta lipseste (vezi intrebarea B). |
| 6, 7 | Cererea in anulare detectata, status "In anulare" | Da. Calea de atac de pe portal trece dosarul automat in "In anulare". |
| 9 | "Definitiva" dedusa din comunicare | Da. Automat, dupa 10 zile libere, prorogare si un tampon de zile lucratoare. |
| 10, 12 | Trecerea la executare | Da. Din "Ordonanta emisa", "In anulare" sau "Definitiva", cu ordonanta in PDF si data cererii la executor. |

## Intrebari

**A. (pct. 3) Dosarul "Inchis" din judecata.** In schema ai scris ca inchiderea se face "prin functionalitatile deja existente". Azi aplicatia poate inchide dosarul doar inainte de depunere (plata integrala) sau dupa "Definitiva" / "Executare". Din "Dosar inregistrat" sau "Termen fixat" nu exista nicio iesire. Care sunt situatiile de inchidere in timpul judecatii: renuntare la judecata dupa plata debitorului, perimare, anulare ca netimbrata, altele? Ai nevoie de motive separate sau ajunge un singur status "Inchisa" cu motivul scris liber?

**B. (pct. 5) Termenul de plata din ordonanta.** Aplicatia nu retine termenul de plata stabilit de instanta (10-30 zile). Confirmi ca vrei un camp "termen de plata" completat la emiterea ordonantei, de la care aplicatia calculeaza data de la care poti porni executarea, cu alerta cand expira? Si acest termen curge tot de la comunicarea catre tine, sau de la comunicarea catre debitor?

**C. (pct. 5, pct. 9) Tamponul de zile lucratoare.** Ai scris 3 zile lucratoare. In iunie ai acceptat 5. Pastram 5 sau trecem la 3? Un tampon mai scurt inseamna mai multe dosare marcate "Definitiva" prematur, pentru ca termenul debitorului curge de la comunicarea catre el, pe care nu o cunosti.

**D. (pct. 8) Dupa cererea in anulare.** Vrei statusul "Solutionata in anulare" cu hotararea incarcata obligatoriu, apoi tu alegi rezultatul. Care sunt rezultatele posibile: respinsa (ordonanta ramane si devine definitiva), admisa in tot (ordonanta anulata, dosar respins), admisa in parte (ordonanta ramane pentru suma mentinuta)? La admiterea in parte, aplicatia trebuie sa modifice suma din dosar?

**E. (pct. 8) "Respinsa in anulare".** Cand ordonanta e anulata in tot, dosarul ajunge in acelasi loc ca o cerere respinsa de la inceput, sau vrei un status separat ca sa deosebesti cele doua situatii in liste si rapoarte?

**F. (pct. 9) Monitorizarea dupa "Definitiva".** Statusul e dedus din comunicarea catre tine, deci o cerere in anulare depusa tarziu de debitor poate aparea pe portal dupa ce aplicatia a marcat dosarul definitiv. Vrei ca aplicatia sa continue sa verifice portalul inca o perioada dupa "Definitiva" (de exemplu 30 de zile) si sa readuca dosarul in "In anulare" daca apare o cale de atac?

**G. (pct. 10) Executare pornita cat timp anularea e pe rol.** Daca ai pornit executarea in timpul cererii in anulare, aplicatia opreste azi monitorizarea portalului si nu mai afla solutia anularii. Confirmi ca trebuie sa continue monitorizarea pana la solutia cererii in anulare?

**H. (neacoperit in document) Plata debitorului dupa ordonanta.** Daca debitorul plateste dupa emiterea ordonantei, inainte de executare, cum inchizi dosarul? Azi aplicatia are "Inchis cu succes" doar din "Definitiva" sau "Executare". Vrei aceeasi inchidere si din "Ordonanta emisa" si "In anulare"?

**I. (pct. 6) Cererea in anulare a creditorului.** In schema scrii "formulata de debitor / creditor". Portalul arata calea de atac fara sa spuna cine a depus-o, iar aplicatia trece dosarul in "In anulare" oricum. Cand ordonanta e admisa doar in parte si tu formulezi cererea (art. 1024 alin. 2 CPC), vrei sa marchezi in aplicatie ca e cererea ta, cu un rezultat asteptat diferit (admiterea e favorabila), sau e suficient statusul "In anulare" fara distinctie?

**J. (pct. 2) Amanarile.** Cand instanta amana cauza, aplicatia iti arata azi o notificare "Hotarare, confirmare necesara", desi nu e hotarare. Vrem sa o tratam ca eveniment propriu "Termen amanat", cu noul termen creat automat si fara confirmare. Ai nevoie sa stii si motivul amanarii, cand portalul il arata?

**K. (pct. 11) Cum te intreaba aplicatia despre executare.** Cand ordonanta e executorie si termenul de plata a expirat, vrei o alerta in agenda si pe dosar ("Poti porni executarea"), un e-mail, sau ambele? Si cat timp dupa expirare sa insiste, daca nu raspunzi?
