# Detalii pe punctele lui Alex

**Pentru ședința cu termen 30.07.2026** · document scurt, de citit înainte de discuție.
Completează agenda din `AGENDA-SEDINTA-PARTENER-2026-07-28.md`, nu o înlocuiește.

---

## 1. Unde suntem

**Funcțional astăzi**, cap la cap, în mediul de dezvoltare și pe demo: cont și securitate,
wizard de dosar nou cu extracție automată, calcule (dobândă, taxă de timbru, instanță
competentă), verificare ANAF, arondare judecătorii pe 3184 de localități, somație, cerere de
ordonanță plus opis plus pachet ZIP, termene și alerte, notificări în aplicație, monitorizare
portal.just.ro, abonamente și plăți (sandbox), panou de administrare. Suita automată: 2478 de
teste, 0 eșecuri.

Notificările în aplicație sunt livrate integral: clopoțel cu contor, listă filtrabilă, marcare
ca citit, verificare periodică fără reîncărcarea paginii, curățare automată a celor vechi, plus
acoperirea fluxurilor care contează (schimbări de status pe dosar, termene, activitate pe portal,
eșecuri de plată, facturi emise, depășire de pachet). Au rămas două lucruri mici: notificarea la
schimbarea parolei și preferințele per utilizator (ce primește pe email și ce doar în aplicație).

**Ce lipsește pentru lansare** nu este funcționalitate, ci infrastructură și contracte:

| Lipsă | Natura ei |
| --- | --- |
| Instalare pe server de producție (domeniu, certificat, backup, cron-uri) | tehnic, 3 până la 5 zile |
| Plăți reale Netopia (contract, credențiale live, flag recurent aprobat) | contractual, depinde de firmă |
| Facturare fiscală reală (cont Oblio, date firmă, aviz consultant fiscal) | contractual |
| Termeni, politică de confidențialitate, acord de prelucrare a datelor | juridic, sarcină comună |
| Test manual complet pe un dosar real, împreună | comun |

Rezumat onest: aplicația e gata, firma și serverul nu.

---

## 2. Ce mai avem de făcut, în ordine

**A. Blocante de lansare** (fără ele nu se poate porni)

1. Documentele juridice ale platformei. Blochează și plățile, și înregistrarea utilizatorilor.
   Punctul critic: secretul profesional la trimiterea documentelor spre procesare AI.
2. Provisioning producție: server, domeniu, backup, cron zilnic (curs BNR, verificare termene,
   verificare portal), worker de mesaje, `trusted_proxies`.
3. Backfill cursuri BNR 2023-2026 (altfel facturile în EUR afișează „curs indisponibil").
4. Netopia live plus Oblio live, în ordinea asta, cu o tranzacție reală de valoare mică.
5. Test manual comun pe un dosar adevărat.

**B. Decizii care blochează cod** (detaliate în agendă, pct. 3): termenul de 6 luni din
art. 2540, titularul cererii în anulare, cine depune la instanță, cont individual versus cont
de cabinet, modelul de preț.

**C. Îmbunătățiri analizate, gata de pornit** (fiecare are documentul de analiză scris)

| Îmbunătățire | Efort | Când |
| --- | --- | --- |
| Refacerea dashboard-ului ca listă de lucru pe dosare | mediu | înainte de demonstrațiile din piață |
| Export termene în calendar (.ics, Google, Outlook) | mic | efect bun la demonstrații |
| Preferințe de notificare per utilizator plus alerta la schimbarea parolei | mic | restul din notificări, se poate face oricând |
| Cont de cabinet cu mai mulți utilizatori | mare | decizia se ia acum, execuția înainte de date reale |
| Autentificare în doi pași | mediu | argument de vânzare pentru cabinete mari |

Ordinea propusă: A, apoi B pe măsură ce vin răspunsurile, apoi C în ordinea de mai sus.

---

## 3. Lansare extracție documente multiple v2

**Stare: implementat și integrat.** Avocatul încarcă mai multe facturi și contracte deodată;
AI-ul clasifică fiecare document, extrage o poziție de creanță per factură (scadență proprie,
dobândă proprie), agregă coerent și, când documentele se contrazic, cere avocatului să aleagă
într-un card de neconcordanțe în loc să decidă tăcut.

Trei probleme reale rezolvate cu ocazia asta:

- dobândă greșită la mai multe facturi cu scadențe diferite (viciu atacabil în opoziție);
- date fabricate prin combinarea a două documente (nume din contract, cod fiscal din factură);
- lipsa unei porți pe acordul de procesare AI: până la corecția din 21.07 un cont nou trimitea
  primul PDF, cu CNP în clar, fără acord înregistrat. Acum acordul e condiție tehnică.

**Ce mai trebuie înainte de a-l numi „lansat"**

1. Acordul de prelucrare cu utilizatorul avocat plus temeiul transferului spre procesatorul AI.
   Acesta e singurul blocant de fond și e la pct. 3.2 din agendă.
2. Testarea ta pe documente reale, anonimizate: 5 până la 10 dosare cu structuri diferite
   (facturi multiple, contract cadru, storno, plăți parțiale).
3. Confirmarea costului per dosar și includerea lui în preț.

---

## 4. Pagina termene

**Stare: implementată și integrată** pe branch-ul de lucru. Are agendă (restanțe, azi, mâine,
săptămâna), zona de blocaje pe dosar (termen care nu se poate calcula pentru că lipsește o dată),
filtre și acțiuni direct din listă.

Cu ocazia implementării au ieșit două corecții juridice cu efect real:

- **Calculul greșea sistematic cu o zi.** CPC art. 181 alin. 1 pct. 2 folosește sistemul zilelor
  libere. Consecința gravă nu era afișajul, ci poarta care împiedică depunerea prematură: se
  putea genera cererea cu o zi înainte ca termenul debitorului să expire, adică respingere ca
  prematură. Corectat într-un singur loc, cu teste.
- **Termenul de 6 luni din art. 2540 lipsea complet.** E cel mai periculos termen din aplicație:
  depășirea lui șterge retroactiv întreruperea prescripției și creanța se poate pierde integral.
  Acum e urmărit, ancorat pe data comunicării somației.

**Ce rămâne, și are nevoie de tine**

1. Confirmarea că cererea de ordonanță satisface „chemarea în judecată" din art. 2540.
2. Cererea în anulare cu titulari diferiți pe admiterea parțială: urmărim titularul sau doar
   avertizăm. Până la răspuns nu închidem automat termenul, ca să nu ascundem termenul propriu
   al clientului nostru.

Amânate prin decizie, nu sunt datorie: vederea Registru, export CSV, feed .ics, coloana de
proveniență, operații în masă.

---

## 5. Acțiuni ulterioare, cu termene propuse

Datele sunt propuneri, se fixează în ședință. Tot ce ține de „live" depinde de datele de firmă.

| Ce | Cine | Termen propus | Depinde de |
| --- | --- | --- | --- |
| Documentație pe fluxurile rămase (termene, extracție v2) pentru validarea ta | Alex | 07.08 | nimic |
| Răspuns la cele 2 întrebări pe termene | Laurențiu | 07.08 | nimic |
| Termeni, confidențialitate, acord de prelucrare | comun | 14.08 | decizia de la pct. 3.2 |
| Finalizare aplicație v1 (blocante A, mai puțin cele contractuale) | Alex | 18.08 | răspunsurile de la pct. 2B |
| Colectare feedback v1 de la tine, într-un document unic | Laurențiu | 18.08 | cont de test |
| Implementarea feedbackului v1 | Alex | 25.08 | volumul feedbackului |
| Date firmă, contract Netopia, cont Oblio | Laurențiu | 25.08 | firma |
| Deploy pe server live | Alex | 31.08 | documentele juridice plus datele de firmă |
| Prima tranzacție reală de test | comun | după deploy | Netopia live |

Regula de prioritate propusă: eroare juridică se rezolvă în aceeași săptămână, înaintea oricărei
funcționalități noi.

---

## 6. De decis în ședință, pe scurt

1. Confirmi cele două întrebări pe termene, chiar și provizoriu?
2. Documentele juridice: le scrii, adaptăm un șablon, sau externalizăm?
3. Data la care îmi dai datele de firmă (blochează plăți, facturare, deploy).
4. Câte dosare de test parcurgi și până când.
5. Data următoarei ședințe.
