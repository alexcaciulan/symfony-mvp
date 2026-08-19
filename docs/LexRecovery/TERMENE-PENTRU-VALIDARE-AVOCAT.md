# Termenele din procedura ordonanței de plată, pentru validare

> Platforma calculează singură termenele fiecărui dosar și trimite alerte pe email înainte de împlinirea lor. Documentul arată cum le calculează, ca să confirmi ce e corect și să corectezi ce nu e.
>
> La final ai 14 întrebări, toate cu răspuns da / nu / corecție scurtă, și un formular de răspuns pe care îl poți copia în email. Cele mai multe se răspund din practică. Două, întrebările 2 și 11, cer o verificare în textul legii.
>
> Trimiteri: CPC = Codul de procedură civilă, NCC = Codul civil.

**Cum citești**: textul descrie comportamentul de azi. Unde am nevoie de confirmarea ta apare o trimitere de tipul *(vezi întrebarea 4)*. Toate întrebările sunt strânse în lista de la final, nicăieri altundeva.

---

## 1. Regula de calcul (afectează toate termenele de mai jos)

Termenele se socotesc pe zile libere: nu se socotesc nici ziua de început, nici ziua de sfârșit (CPC art. 181 alin. 1 pct. 2). Dacă ultima zi cade în weekend sau într-o sărbătoare legală, termenul se prelungește până în prima zi lucrătoare (CPC art. 181 alin. 2).

Exemplu, somație comunicată **luni, 1 iunie 2026**:

| Termen | Ultima zi calculată de aplicație |
|---|---|
| 15 zile (plata la somație) | miercuri, **17 iunie 2026** |
| 10 zile (cererea în anulare) | vineri, **12 iunie 2026** |

**Acesta e punctul la care țin cel mai mult să mi-l confirmi**, pentru că de el atârnă toate celelalte termene. Cealaltă citire posibilă dă 16 iunie, adică 15 zile numărate direct de la comunicare *(vezi întrebarea 1)*.

De regula aceasta depinde și momentul în care se deblochează generarea cererii de ordonanță, adică protecția împotriva depunerii premature. O parte dintre termenele aflate acum în aplicație sunt calculate pe cealaltă variantă: le recalculez pe toate după ce îmi confirmi regula, nu înainte.

---

## 2. Toate termenele, pe o pagină

| Termen | Curge de la | Durată | Ce se întâmplă când se împlinește |
|---|---|---|---|
| Plata la somație, termenul debitorului | primirea somației | 15 zile (CPC art. 1015 alin. 1) | poți depune cererea de ordonanță |
| Depunerea cererii de ordonanță | primirea somației | 6 luni (NCC art. 2540) | somația nu mai întrerupe prescripția, deci creanța se poate prescrie |
| Timbrarea la regularizare | înștiințarea instanței | 10 zile (OUG 80/2013 art. 33 alin. 2) | instanța anulează cererea |
| Termen de judecată | data din citație | data e fixată de instanță | se judecă și în lipsă (CPC art. 223 alin. 1) |
| Cererea în anulare | comunicarea ordonanței | 10 zile (CPC art. 1024 alin. 1) | se pierde calea de atac, prin decădere (CPC art. 185 alin. 1) |
| Prescripția creanței | scadența fiecărei facturi | 3 ani (NCC art. 2517) | debitorul poate invoca prescripția, iar cererea se respinge |
| Prescripția executării | rămânerea definitivă | 3 ani (CPC art. 705 alin. 1) | ordonanța nu mai poate fi pusă în executare |
| Termen propriu, adăugat de tine | data aleasă de tine | cât alegi | doar un memento, fără efect juridic |

---

## 3. Ce trebuie să știi în plus

**Plata la somație.** Termenul apare la generarea somației, calculat provizoriu de la data documentului și marcat „dată estimată". După ce introduci data reală a comunicării, se recalculează de la primire. Cât timp curge, generarea cererii de ordonanță e blocată, ca să nu depui prematur *(vezi întrebarea 3)*.

**Timbrarea.** OUG 80/2013 art. 33 alin. 2 spune „în cel mult 10 zile de la primirea comunicării instanței". 10 zile e deci plafonul legal, nu o practică: instanța nu poate acorda mai mult, dar poate acorda mai puțin *(vezi întrebarea 5)*.

**Termenul de judecată** vine de pe portalul instanțelor și se salvează exact cum e publicat *(vezi întrebarea 4)*.

**Cererea în anulare** se calculează exclusiv de la data comunicării ordonanței, pe care o introduci tu. Aplicația refuză deliberat să folosească data pronunțării *(vezi întrebarea 9)*.

**Prescripția creanței.** Se creează câte un termen pentru fiecare scadență din dosar. Pozițiile excluse de tine și notele de credit nu generează termen. Facturile adăugate ulterior pe un dosar existent nu generează azi termen *(vezi întrebarea 7)*. După comunicarea somației, data afișată nu mai corespunde realității, pentru că prescripția a fost întreruptă *(vezi întrebarea 13)*.

**Prescripția executării** se socotește de la ziua următoare expirării termenului de cerere în anulare, pentru că aceea e ziua în care ordonanța rămâne definitivă *(vezi întrebarea 8)*. Prorogarea la prima zi lucrătoare nu se aplică azi niciunuia dintre cele două termene de prescripție *(vezi întrebarea 10)*.

---

## 4. Ce fac când lipsește data de plecare

Regula pe care am ales-o: mai bine o alertă prea devreme decât un termen care pare mai lung decât este. Un termen afișat cu o dată optimistă liniștește fără temei. De aceea, când lipsește data de plecare, aplicația fie afișează termenul marcat „estimat", fie nu îl afișează deloc, iar dosarul apare pe o listă de dosare blocate, cu indicația datei pe care trebuie să o completezi.

| Situația | Ce lipsește | Ce termen nu se poate calcula | Ce vezi |
|---|---|---|---|
| Somație generată, comunicare neconfirmată | data primirii somației | plata la somație, și cele 6 luni | termenul de 15 zile apare marcat „dată estimată", cele 6 luni nu apar deloc |
| Ordonanță emisă | data comunicării ordonanței | cererea în anulare | termenul nu apare și nu primești nicio alertă pe el |
| Cerere depusă, taxa amânată la regularizare | data înștiințării instanței | timbrarea | termenul nu apare și nu primești nicio alertă pe el |
| Dosar definitiv sau în executare | data comunicării ordonanței | prescripția executării | termenul nu apare și nu primești nicio alertă pe el |

În prima situație afișez un termen estimat, în celelalte trei nu afișez nimic *(vezi întrebarea 6)*.

---

## 5. Întrebările

1. **Zilele libere.** Pe o somație comunicată luni, 1 iunie 2026, debitorul mai poate plăti valabil miercuri, 17 iunie 2026? *(Prioritate absolută: răspunsul schimbă toate termenele și decide dacă recalculez termenele deja existente.)*
2. **Cele 6 luni.** NCC art. 2540 cere ca punerea în întârziere să fie urmată de „chemarea în judecată" în 6 luni. Ce moment oprește curgerea celor 6 luni: **depunerea cererii de ordonanță la instanță** (moment care coincide cu înregistrarea și îi dă dată certă, CPC art. 199 alin. 1), sau **prima zi de judecată efectivă**?
3. **Blocarea generării.** Țin generarea cererii de ordonanță blocată până la expirarea celor 15 zile de la primirea somației, fără posibilitatea de a trece peste blocaj. Da sau nu?
4. **Termenul de judecată.** Salvez data din citație exact cum e publicată, fără să o mut în prima zi lucrătoare, chiar dacă apare într-o zi nelucrătoare. Da sau nu?
5. **Timbrarea.** Adaug un câmp în care să tastezi termenul indicat de instanță, pentru cazurile în care acesta e mai scurt de 10 zile. Da sau nu?
6. **Termenul estimat.** Afișez cele 15 zile calculate de la data somației, marcate vizibil „dată estimată", până completezi data reală a comunicării. Da sau nu?
7. **Facturi adăugate ulterior.** Când adaugi facturi noi pe un dosar existent, creez și pentru ele termen de prescripție de 3 ani. Da sau nu?
8. **Prescripția executării.** Cei 3 ani curg de la rămânerea definitivă a ordonanței (CPC art. 705 alin. 2), iar eu socotesc că ordonanța rămâne definitivă în prima zi după expirarea termenului de cerere în anulare. Da sau nu?
9. **Închiderea termenului de cerere în anulare.** Aceeași cale de atac aparține și creditorului (CPC art. 1024 alin. 2), iar cele două ferestre curg independent, din același eveniment, comunicarea ordonanței. Azi termenul rămâne deschis până îl închizi tu. Când o cerere în anulare este depusă, închid **doar fereastra părții care a depus-o**, sau **fereastra ambelor părți**? Dacă răspunsul e „doar a părții care a depus", adaug o întrebare la înregistrare, ca să rețin cine a formulat cererea.
10. **Prorogarea prescripției.** Termenele procedurale se prelungesc la prima zi lucrătoare (CPC art. 181 alin. 2), iar NCC art. 2554 pare să prevadă aceeași prelungire pentru prescripție. Aplic prorogarea și celor două termene de prescripție (3 ani creanța, 3 ani executarea). Da sau nu?
11. **Numerotarea articolelor din somație și din cerere.** Somația trimisă debitorului spune „În temeiul dispozițiilor art. 1013 și următoarele din Codul de procedură civilă", iar cererea depusă la instanță spune „Formulată în temeiul art. 1013-1024". Aici am nevoie de tine ca să tranșezi, pentru că verificările mele s-au contrazicut de două ori. O verificare a găsit procedura la art. 1013-1024, cu cuprinsul cererii la art. 1016, deci exact cum scrie azi în documente. Alta a găsit-o la art. 1014-1025, cu cuprinsul la art. 1017. Nu schimb nimic până nu îmi spui tu, pentru că textul acesta ajunge pe acte care se depun la instanță. **Care numerotare e în vigoare azi: art. 1013-1024 sau art. 1014-1025?**
12. **Ritmul alertelor.** Azi alertele pleacă pe email cu 7, 3 și 1 zi înainte, plus una în ziua expirării. Pe prescripție și pe cererea în anulare adaug alerte suplimentare cu 30 și 14 zile înainte. Da sau nu?
13. **Prescripția întreruptă.** După comunicarea somației, data de prescripție afișată nu mai e cea reală. Azi rămâne afișată, marcată ca nesigură. Preferi să rămână așa, sau să dispară din listă până marchezi cererea de ordonanță ca depusă?
14. **Eticheta unui buton.** Când vrei ca un termen de prescripție să nu mai apară în listă, apeși un buton. Îl numesc „Nu mai urmări" sau „Prescripție întreruptă"?

---

## Formular de răspuns

| Întrebarea | Răspunsul tău |
|---|---|
| 1. | |
| 2. | |
| 3. | |
| 4. | |
| 5. | |
| 6. | |
| 7. | |
| 8. | |
| 9. | |
| 10. | |
| 11. | |
| 12. | |
| 13. | |
| 14. | |

<sub>Am verificat textele CPC art. 181, 185, 199, 223, 705, 1015, 1024, NCC art. 2517, 2540, 2554 și OUG 80/2013 art. 33, verbatim, la mai multe surse publice consolidate independente. Excepție: numerotarea de la întrebarea 11, unde două verificări succesive au dat rezultate opuse, motiv pentru care ți-o supun fără o propunere din partea mea.</sub>
