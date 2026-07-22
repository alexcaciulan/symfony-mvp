# Analiză: cum ajunge pachetul de documente la instanță

> Document de analiză și decizie, NU implementare. Data: 2026-07-17 (verificare adversarială: 2026-07-18).
>
> **Metodă.** Analiza a fost produsă printr-un workflow multi-agent: cercetare (5 lentile),
> dezvoltarea a 3 opțiuni, judecarea lor din 3 perspective, apoi **verificare adversarială** a
> celor mai importante 15 afirmații (5 agenți, fiecare încercând să refute un lot, cu regula
> "refutat implicit dacă nu se poate confirma din sursă").
>
> **Rezultatul verificării: 12 din 15 afirmații confirmate, 3 refutate.** Dintre cele 3 refutate,
> **două** au căzut doar fiindcă agentul nu a putut accesa sursa primară în sesiune (legislatie.just.ro
> inaccesibil, buget de căutare epuizat), nu pentru că s-ar fi găsit o eroare de fond, și privesc
> ambele contradicția deja cunoscută ÎCCJ 34/2017 vs 45/2020. **Una singură este substanțială**: un
> agent a accesat sursa primară și susține că **numerotarea CPC din cod este corectă**, contrazicând
> "bug-ul de citare" pe care o lentilă de cercetare îl afirmase. Vezi secțiunea 3 (subsecțiunea de
> citări CPC), acum marcată **DISPUTAT**, și secțiunea "Limite metodologice".
>
> Concluziile care susțin recomandarea (releul de email rejust, 0/223 adrese, semantica `depune_cerere`,
> gate-urile de generare, Legea 216/2025, art. 1015 pe somație) sunt toate în grupul **CONFIRMAT**.
> Recomandarea nu se schimbă. Etichetele `[CERT]` / `[PROBABIL]` / `[INCERT]` reprezintă încrederea
> agentului care a produs constatarea; unde verificarea a intervenit, am marcat explicit
> **CONFIRMAT** / **REFUTAT** / **DISPUTAT**.

## 1. Întrebarea și de ce contează acum

Platforma generează cererea de ordonanță de plată, opisul și somația, le împachetează într-un ZIP
(`src/Service/Document/CaseFilesPackager.php`) și îl oferă avocatului spre descărcare. Întrebarea:
**ce se întâmplă între descărcare și instanță, și cât din acest drum poate parcurge platforma?**

Contează acum din trei motive.

**Primul: produsul afirmă azi ceva neadevărat.** Tranziția `depune_cerere` se aplică la *generarea*
PDF-urilor, nu la depunere (`src/Controller/Case/CasePaymentOrderController.php:114`, în aceeași
tranzacție cu generatoarele, liniile 110-114). Statusul `CERERE_DEPUSA` înseamnă de fapt "cerere
generată". Avocatul poate genera și să nu depună niciodată, iar dosarul rămâne marcat "depus".
Emailul de notificare are aceeași problemă: `src/EventSubscriber/EmailNotificationSubscriber.php:41`
ascultă pe `entered.CERERE_DEPUSA` și îi scrie avocatului "cerere depusă" când s-a generat doar un PDF.

**Al doilea: după descărcare, platforma nu mai știe nimic.** Descărcarea ZIP-ului este complet
netrasată: fără audit log, fără schimbare de stare, fără timestamp
(`src/Controller/Case/CasePaymentOrderController.php:168-195`). Nu putem răspunde nici măcar la
întrebarea "a descărcat avocatul pachetul?". Nu există niciun câmp `filedAt` în `LegalCase`. Singurul
mecanism prin care platforma află că depunerea a avut loc este numărul ECRIS, introdus manual sau
descoperit prin portal, deci cu întârziere de zile.

**Al treilea: fereastra de reparare se închide la lansare.** Proiectul e pre-producție, fără date
reale. Separarea "generat" de "depus" costă azi zero. Peste șase luni, aceleași dosare devin ambigue:
nu vom ști niciodată care au fost efectiv depuse.

## 2. Ce impune legea

Toate afirmațiile de mai jos provin din faza de cercetare și **nu au fost verificate adversarial**.

### Canale de depunere valabile

`[CERT]` **Nu există obligație legală de depunere electronică** pentru avocați. Depunerea fizică
rămâne canalul implicit valid. Sistemul dosarului electronic / DEN este opțional.

`[CERT]` **CPC art. 199 alin. (1)** enumeră expres canalele, text verbatim: "Cererea de chemare în
judecată, depusă personal sau prin reprezentant, sosită prin poştă, curier, fax sau scanată şi
transmisă prin poştă electronică ori prin înscris în formă electronică, se înregistrează şi primeşte
dată certă prin aplicarea ştampilei de intrare." Deci emailul cu scan este un canal recunoscut.

`[CERT]` **CPC art. 148 alin. (2)**: "Cererile adresate, personal sau prin reprezentant, instanţelor
judecătoreşti pot fi formulate şi prin înscris în formă electronică, dacă sunt îndeplinite condiţiile
prevăzute de lege." Condițiile trimit la semnătura electronică (Legea 214/2024, succesoarea Legii
455/2001, citită prin Regulamentul UE 910/2014 eIDAS).

`[CERT]` **Tensiune reală între text și practică.** ROI-ul instanțelor (Hot. CSM 3243/2022) tratează
asimetric emailul: art. 94 alin. (1) enumeră pentru *actele de sesizare* doar "poștă, curier ori fax
sau în orice alt mod prevăzut de lege", fără mențiunea expresă a emailului, în timp ce art. 94
alin. (11) îl enumeră explicit pentru celelalte cereri, iar art. 129 alin. (1) pentru căile de atac.
Cererea de OP este act de sesizare. Practica CSM este mai permisivă decât textul.

### Data depunerii, pe canal

| Canal | Data depunerii | Temei |
|---|---|---|
| Registratură fizică | Data ștampilei de intrare | CPC art. 199 alin. (1) |
| Poștă recomandată / curierat specializat | **Data predării la poștă**, chiar dacă ajunge ulterior | CPC art. 183 alin. (1) |
| Fax / email | Data înregistrării la instanță, nu data trimiterii | CPC art. 183 alin. (3), CCR 605/2016 |
| Portal (rejust.ro) | Nereglementat distinct în CPC | `[INCERT]` |

Poșta recomandată este **singurul canal cu "efect de poștă"**, deci singurul pe care platforma îl
poate recomanda fără rezerve pentru o depunere aproape de un termen.

Pentru emailul trimis în **ultima zi a termenului, după programul instanței**, cercetarea a produs
două răspunsuri opuse. O lentilă citează ÎCCJ, Completul DCD, Decizia nr. 34/2017 (M. Of.
803/11.10.2017): actul e **tardiv**. Altă lentilă citează ÎCCJ, Decizia nr. 45/2020 (Complet DCD),
care ar fi **răsturnat** soluția, fiind pronunțată pe art. 182 și art. 183 astfel cum au fost
modificate prin Legea nr. 310/2018: actul **este în termen**.

`[VERIFICAT parțial 2026-07-18]` Verificarea adversarială a **confirmat pe sursă primară** existența
și conținutul Deciziei nr. 45/2020 (`legislatie.just.ro/Public/DetaliiDocument/207598`, ÎCCJ Completul
DCD, nr. 45 din 22.06.2020): actul trimis prin fax sau email în ultima zi, după ora închiderii, **este
socotit depus în termen**. Decizia nr. 34/2017 (soluția contrară) **nu a putut fi reconfirmată** din
sursă primară în sesiune (legislatie.just.ro inaccesibil, buget de căutare epuizat), deci rămâne pe
nivel de cunoștințe generale. Interpretarea mai bine probată este, așadar, cea din 45/2020: **email în
ultima zi = în termen**, fiindcă 34/2017 s-a pronunțat pe textul dinaintea modificării din 2018.
Consecință practică: politica de UI "nu depune electronic în ultima zi" rămâne sigură (prudentă sub
ambele), dar dacă se scrie text despre ultima zi, el trebuie ancorat pe 45/2020, nu pe 34/2017.

### Proba, și de ce contează pentru răspundere

`[CERT]` **Cea mai importantă constatare pentru profilul de răspundere al platformei.** CPC art. 183
alin. (3), astfel cum a fost modificat prin Legea 310/2018: proba datei depunerii prin email este
"menţiunea datei şi orei primirii faxului sau a e-mail-ului, astfel cum acestea sunt atestate de
către calculatorul sau faxul de primire **al instanţei**". Coroborat cu art. 199 alin. (1): data
certă se obține prin ștampila de intrare aplicată de instanță.

Deci: **proba prevăzută de lege se află exclusiv la instanță.** Un log SMTP al platformei este probă
de fapt, nu proba prevăzută de text. Platforma **nu poate produce mijlocul de probă pe care legea îl
cere**. Orice UI care promite "confirmare de primire" pe canalul email induce în eroare și transferă
platformei o răspundere pe care nu o poate proba. Se poate promite cel mult "dovadă de expediere".

### Cerințe de conținut și formă

> Notă de numerotare: articolele citate mai jos ca "art. 1017 alin. (2)" și "alin. (3)" apar sub
> **"art. 1016"** în forma republicată a CPC (vezi disputa de numerotare din secțiunea 3). *Regulile
> de fond sunt confirmate*; doar numărul articolului depinde de forma oficială autoritativă.

`[CONFIRMAT pe fond]` **Cuprinsul cererii, alineatul despre dovada comunicării**: "Dovada comunicării
somației prevăzute la art. 1015 alin. (1) se va atașa cererii **sub sancțiunea respingerii acesteia
ca inadmisibilă**." Sancțiune mai severă decât regularizarea din dreptul comun (CPC art. 200:
notificare, 10 zile, abia apoi anulare). Pentru acest element, instanța nu pare obligată să acorde
termen de regularizare.

`[CONFIRMAT pe fond]` **Numărul de exemplare**: "Cererea și actele anexate la aceasta se depun în
copie în atâtea exemplare câte părți sunt, plus unul pentru instanță." Depunerea electronică **nu**
elimină cerința: rejust avertizează expres că instanța poate cere tipărirea sau plata costului
tipăririi.

`[CERT]` **Somația nu poate fi comunicată prin email, niciodată.** CPC art. 1015 alin. (1) prevede
limitativ două canale: executor judecătoresc sau scrisoare recomandată cu conținut declarat și
confirmare de primire. Distincția email-DA-la-depunere / email-NU-la-somație este contraintuitivă și
merită explicată explicit avocatului în UI. Regula implementată azi în produs este corectă și nu
trebuie relaxată oricât s-ar digitaliza restul fluxului.

`[CERT]` **Taxa de timbru**: obligația de atașare a dovezii rămâne, în temeiul OUG 80/2013 art. 6
alin. (2) coroborat cu principiul din CPC art. 197.

### Modificări legislative recente, relevante

`[CERT]` **Legea 268/2024** (M. Of. 1089/31.10.2024, în vigoare 03.11.2024) a introdus art. 40
alin. (3) în OUG 80/2013: plata online a taxei, cu confirmare transmisă direct instanței, **prezumă
efectuarea plății până la proba contrară**. CSM recomandă folosirea exclusivă a acestei modalități.
Validează puternic decizia de produs pe taxa de timbru: ghidarea spre rejust.ro nu mai e doar
pragmatică, e canalul recunoscut expres de lege.

`[CERT]` **Legea 216/2025** (M. Of. 1151/11.12.2025, în vigoare 14.12.2025) a modificat CPC art. 150
alin. (2): copiile pot fi certificate "conform cu originalul" **și prin semnătură electronică** când
sunt transmise în format electronic.

`[CERT]` **Legea 57/2025** (M. Of. 433/12.05.2025) a ridicat pragul cererii cu valoare redusă de la
10.000 lei la **50.000 lei** (CPC art. 1026 alin. (1)). Strategic, nu tehnic: o parte semnificativă
din creanțele eligibile OP intră acum și pe procedura cu valoare redusă, tot scrisă, tot fără
prezență. Scopul produsului exclude explicit CVR. Merită o decizie conștientă, nu o omisiune.

## 3. Ce există azi în platformă

### Ce e solid

Pachetul în sine e bine construit. `CaseFilesPackager.php:69-74` numerotează la rădăcină în ordinea
în care judecătorul citește: `01_cerere_ordonanta_plata`, `02_opis_documente`, `03_somatie_de_plata`,
`04_dovada_taxa_timbru`, apoi `dovada_comunicare/` și `anexe/`. Dovada taxei stă la rădăcină tocmai
pentru că CPC art. 197 cere atașarea la cererea însăși.

Opisul exclude corect OPIS și CERERE_OP din listă (`OpisGeneratorService.php:49-52`). Poarta de
*intrare* în depunere e bine gardată: status, instanță selectată, idempotență, expirarea termenului
de 15 zile, consimțământ explicit, gate pe taxa de timbru cu excepție de regularizare
(`CasePaymentOrderController.php:60-96`).

### Unde se oprește

| Constatare | Dovadă |
|---|---|
| `depune_cerere` se aplică la generare, nu la depunere | `CasePaymentOrderController.php:114` |
| Descărcarea ZIP e netrasată: fără audit, stare, timestamp | `CasePaymentOrderController.php:168-195` |
| Niciun câmp pentru data depunerii | `LegalCase.php`, grep `filedAt|submittedAt` → 0 rezultate |
| `Court.email` populat **0 din 223** (la fel address, phone) | `SELECT COUNT(email) FROM court` → 0 |
| `data/courts.json` nu are deloc cheia `email` | chei = name, county, type, coveredLocalities |
| Nicio infrastructură de email cu atașamente | grep `attachFromPath|->attach(|DataPart` → 0 |
| `NotificationDispatcher` poate trimite doar către un `User` | `NotificationDispatcher.php:46-47` |
| Packager sare **în tăcere** peste fișiere lipsă de pe disc | `CaseFilesPackager.php:77-80` |
| Mailer configurat doar pentru dev (Mailpit) | `.env:53` |
| Auto-descoperirea portal funcționează pe **2 instanțe reale din 223** | `portal_code` 3/223, una e `'test'` |

Ultimul rând merită subliniat: `portal_code` are valoarea literală `'test'` pe Judecătoria Câmpeni.
De curățat înainte de producție.

### Bug-uri descoperite incidental

`[DISPUTAT dupa verificare]` **Numerotarea articolelor CPC citate pe acte: nu se poate afirma că e
greșită.** O lentilă de cercetare a susținut că produsul citează greșit "art. 1016" pentru cuprinsul
cererii (ar trebui art. 1017, iar lit. f) nu există), pe forma consolidată de pe
`legislatie.just.ro/Public/DetaliiDocument/140271`. **Verificarea adversarială a refutat această
afirmație.** Un agent a accesat forma republicată de pe `legislatie.just.ro/Public/DetaliiDocumentAfis/140271`
(fetch 2026-07-18) și a găsit numerotarea **art. 1013-1024**, cu **art. 1016 = "Cuprinsul cererii"**,
art. 1015 = "Somația", art. 1013 = "Domeniul de aplicare". Sub această numerotare, **citarea din cod
este corectă**, iar `MEMORY.md` (care folosește "art. 1013-1024") este de asemenea corect.

Rezultatul net: **cele două forme oficiale ale CPC de pe legislatie.just.ro dau numerotări diferite
pentru același text**, cu un decalaj de o unitate, iar cercetarea și verificarea au ajuns la concluzii
opuse citând același număr de document (140271) pe căi diferite (`/DetaliiDocument/` vs
`/DetaliiDocumentAfis/`). **Verificarea nu a tranșat contradicția, ci a arătat că nu e sigur nici
măcar în ce direcție greșește cineva.**

**Consecință operațională: NU se modifică citările din cod până când avocatul nu confirmă care formă
este în vigoare.** A schimba art. 1016 în art. 1017 pe presupunerea "bug-ului" ar putea *introduce* o
eroare pe actele care ajung la judecător, dacă numerotarea 1013-1024 este cea autoritativă. Locuri
afectate (dacă se dovedește vreodată necesară o corecție): `translations/messages.ro.yaml` (în jurul
liniilor 1318 și 1334, `messages.en.yaml` 1317 și 1333, numere aproximative, de reconfirmat),
`OpisGeneratorService.php:14` și `:41`, `PaymentOrderRequestGeneratorService.php:15` și `:19`,
`templates/pdf/*.twig`. Vezi întrebarea 3 pentru avocat.

`[CONFIRMAT]` Numerotarea folosită pentru **somație (art. 1015)**, **cererea în anulare (art. 1024)**
și **creanța certă, lichidă, exigibilă (art. 1013/1014)** este consecventă cu forma republicată și nu
ridică probleme practice. Atenție la surse: legeaz.net redă text depășit pe alte articole.

`[CERT]` **Trei judecătorii suspendate marcate `active=1`**: Însurăței, Bocșa, Murgeni (anexa HG
1217/2023, M. Of. 1102/07.12.2023). Combinat cu golul cunoscut sat → comună, un debitor rural din
Brăila, Caraș-Severin sau Vaslui produce o listă de selecție manuală care conține o instanță
nefuncțională. Dacă avocatul o alege, dosarul merge la o instanță inexistentă operațional.

## 4. Ce s-a decis deja și ce s-a schimbat

Decizia oficială curentă, luată la pasul R9 (2026-06-30, cu mandat user din 2026-06-28), este
**NO-GO pentru integrarea automată cu registratura.rejust.ro**. Modelul de produs rezultat:
platforma pregătește pachetul, avocatul depune.

**Concluzia rămâne corectă. Cele două motive invocate în spike sunt însă factual false.**

| Afirmație în `SPIKE-DEPUNERE-ELECTRONICA-REJUST.md:21-26` | Verificare live 17.07.2026 |
|---|---|
| Depunerea cere "sesiunea autentificată a avocatului (cont propriu pe portal)" | **Fals.** Formularul de înregistrare dosar nou e complet anonim, fără cont, fără login |
| Depunerea cere "semnătura electronică calificată pe actele depuse" | **Fals.** CA Galați, autorul platformei, descrie serviciul ca acceptând acte "semnate olograf și scanate **ori** semnate în formă electronică". Alternative, nu cumulative |

Motivul **real și suficient** pentru NO-GO: nu există API public și niciun temei contractual pentru
automatizare. Endpoint-urile interne (`/api/courts?requestedService=file_court_case`) răspund 401
chiar și cu token-ul propriu al aplicației, sunt nedocumentate, stau în spatele Cloudflare, iar
automatizarea ar însemna scraping pe un portal CSM. **NO-GO-ul stă în picioare, dar pe alt picior.**
Spike-ul trebuie corectat: prima persoană care verifică demontează argumentul și, cu el, încrederea
în decizie.

### Descoperirea cea mai importantă a analizei

`[CERT]` **registratura.rejust.ro este, tehnic, un releu de email.** Portalul trimite pe adresa de
email înregistrată a instanței formularul completat plus atașamentele. CSM afirmă explicit: "Mesajul
e-mail trimis prin intermediul portalului are aceeași valoare juridică ca mesajul e-mail trimis de
utilizator direct instanței."

Consecință: **canalul cel mai bun e deja construit de CSM, gratuit.** Portalul își alege singur
instanța din dropdown-ul propriu, deci rezolvă și problema rutării pe secție la cele 42 de tribunale,
pe care un singur câmp `Court.email` nu o poate acoperi structural. Ghidarea spre rejust ocolește
complet blocantul de 0/223 adrese.

`[CERT]` rejust **nu este obligatoriu**: CSM doar îndeamnă și indică el însuși drept alternative
"e-mail, fax, poștă sau servicii de curierat". Adoptarea e reală: contorul public afișa 72.815.361,61
lei taxe achitate prin portal la 17.07.2026. Portalul permite și **plata de către un terț** (avocatul
completează, clientul plătește prin link), ceea ce se potrivește exact cu modelul de utilizare.

### Ce s-a schimbat în bine față de spike

`[CERT]` **Cererea de acces la dosarul electronic se poate depune "odată cu acțiunea introductivă"**,
nu doar ulterior. Îmbunătățește direct fluxul propus în spike: în loc de secvențial (depunere, apoi
aflăm numărul de dosar, apoi trimitem cererea de acces), platforma poate include cererea de acces
**chiar în pachetul de depunere**. Elimină un pas și dependența de auto-descoperire. Pentru cererile
depuse începând cu 01.01.2026, accesul se realizează prin platforma națională DEN.

`[CERT]` **DEN nu este canal de depunere.** Înregistrarea cere ca utilizatorul să fie *deja* parte
într-un dosar existent. Elimină DEN din discuția "cum ajunge pachetul la instanță".

### Contradicții de documentație de reparat

- `ANALIZA-FLUXURI-LEXRECOVERY.md:269, :307, :162` afirmă categoric "depunere fizică" (v1.1 din
  2026-05-09, precede R9, neactualizat).
- `ANALIZA-PLATA-TAXA-TIMBRU.md:57` descrie `Court.email` drept "câmp populat, dar nefolosit".
  Partea "nefolosit" e corectă; "populat" e fals (0/223).
- `memory/project_lexrecovery_pas_5_2.md:71, :108` consemnează drept livrată formularea "fizic la
  registratură sau prin curier", exact textul pe care R9 l-a eliminat.
- `PLAN-REVIZIE-WORKFLOW-AVOCAT-2026-06.md:344, :346` marchează R9 drept "necomis", deși a fost comis
  în `62d59bc` pe 2026-07-03.

Copy-ul din UI este curat și consecvent. Problema e strict în docs și memorii.

## 5. Opțiunile analizate

Fiecare opțiune a fost dezvoltată de un agent, apoi judecată independent de trei agenți (juridic,
tehnic, produs). Scorurile sunt medii pe cele trei lentile. **Niciuna nu a fost găsită blocată
juridic.**

| Opțiune | Juridic | Tehnic | Produs | Media | Verdict |
|---|---|---|---|---|---|
| **Status quo îmbunătățit** (ZIP + checklist + confirmare depunere) | 8.0 | 7.5 | 6.0 | **7.2** | Recomandat |
| Semnătură în platformă + rejust ghidat | 4.0 | 6.5 | 4.0 | **4.0 - 5.5** | Respins (jumătate se extrage) |
| Email asistat către registratură | 3.0 | 4.0 | 3.0 | **3.3** | Respins |

Opțiunea din mijloc a primit medii diferite între rulări (4.0, apoi 5.5), variație normală a
agenților-judecători. Ordonarea și decalajul rămân stabile: opțiunea recomandată domină clar, iar
emailul asistat rămâne ultimul.

Decalajul e mare și consecvent: **cu cât platforma preia mai mult din drumul până la instanță, cu
atât scorul scade pe toate cele trei lentile simultan.**

### Opțiunea A: status quo îmbunătățit (7.2)

Platforma nu transmite nimic. Rămâne generator de pachet și registru de evidență. Se adaugă: un
moment explicit "am depus" cu dată și canal, un checklist per canal, și numărul de exemplare calculat.

Valoarea reală, onest: **nu adaugă capabilitate de transmitere, ci repară o minciună existentă.**
Nu poate exista fără D3 (separarea `CERERE_GENERATA` de `CERERE_DEPUSA`): nu ai unde agăța
confirmarea dacă statusul afirmă deja depunerea.

**Efort: 4,5-5,5 zile dev + 0,5 zi validare avocat (blocantă, în față).**

Limita cea mai serioasă, de spus în față: `[CERT]` **rejust impune 3 sloturi de upload, maximum 11 MB
per fișier și 13 MB total.** Pachetul nostru e un ZIP unic cu subfoldere și N fișiere, nemărginit
(`Step0DocumentsType.php:32-33` permite 10 fișiere × 10M doar din surse; packager-ul nu verifică
nicio dimensiune). **ZIP-ul nu se mapează pe canalul pe care îl recomandăm.** Fără comasarea anexelor
într-un PDF unic, checklist-ul pentru rejust îi cere avocatului să dezarhiveze și să recompună manual
în 3 sloturi. Extensia cu cel mai bun raport valoare/efort: un PDF unic de anexe comasate (+1,5-2
zile, necesită FPDI, DomPDF nu comasează). `[INCERT]` Prioritatea depinde de un test de 10 minute:
acceptă rejust fișiere `.zip`?

Judecătorul de produs (scor 6, cel mai sever) reproșează că opțiunea "cheltuiește jumătate din buget
pe ceremonie și exclude exact lucrul pentru care avocatul ar plăti", dar confirmă că minciuna există
și trebuie reparată.

**Regulă de design nenegociabilă:** `filedAt` este **autodeclarat** și nu alimentează niciun calcul
de termen, nicio tranziție automată, niciun mesaj de tip "prescripția s-a întrerupt la data X".
`[CERT]` Întreruperea prescripției (Cod civil art. 2537 pct. 2 și art. 2539) operează prin cererea
efectiv depusă, nu prin bifa noastră. Dacă regula se încalcă, opțiunea își pierde principalul avantaj
și devine **mai riscantă decât ruta de email**, fiindcă produce afirmații juridice pe date
neverificate, cu aparență de certitudine.

### Opțiunea B: semnătură calificată în platformă + rejust ghidat (4.0)

**Sunt două produse sub un titlu care îl scoate în față pe cel greșit.**

Jumătatea "rejust ghidat" e ieftină, utilă, fără dependențe externe. **De făcut** (e inclusă în
opțiunea A).

Jumătatea "semnătură în platformă" **rezolvă o problemă care nu există** pe canalul ales: rejust
acceptă expres scan olograf. Construim un pod peste un râu care nu e acolo.

Limita tehnică dură: **semnarea locală (token USB/smartcard) nu e implementabilă ca funcție în
platformă.** Cheia privată nu părăsește tokenul prin design, asta îl face QSCD. PHP de pe server nu
ajunge la ea; browserul nu ajunge la ea (WebCrypto nu expune PKCS#11, applet-urile Java sunt moarte,
WebAuthn nu e API de semnare de documente). Ar trebui o aplicație nativă plus o extensie de browser
cu native messaging, semnate și întreținute pe Windows și macOS. **Acesta e un produs separat.**

Rămâne remote signing (CSC API), care cere contract cu un QTSP, plus SetaPDF-Signer (comercial, 600
EUR/server, licență legată de MAC/IP). Incompatibilitate structurală: **nu se poate semna PAdES un
ZIP**; semnătura se aplică per PDF, iar fuziunea în 3 sloturi distruge semnăturile individuale.

Judecătorul de produs (scor 3): "propunerea se demontează singură. Beneficiul central declarat este
că avocatul nu iese din platformă, dar pașii 3-5 ai propriului flux îl scot din platformă."

Alternativa onestă din aceeași familie, dacă se vrea totuși semnătură: **"bring your own signed PDF"**.
Avocatul semnează în unealta lui, încarcă înapoi, platforma parsează dicționarul de semnătură și
verifică lanțul față de EU Trusted List. Circa 80% din valoare, 15% din cost, zero dependență de QTSP.

### Opțiunea C: email asistat (3.3)

Denumirea corectă nu e "depunere prin platformă", ci **"expediere asistată"**. Trei afirmații din
formularea inițială nu rezistă:

1. **"Din numele avocatului" nu e realizabil.** Nu putem pune `From: avocat@...` fără să spargem
   SPF/DKIM/DMARC. Realist: `From: depuneri@lexrecovery.ro`, display name "Av. X, prin LexRecovery",
   `Reply-To` avocatul.
2. **"Cu confirmare de primire" nu produce probă.** MDN e ignorat de majoritatea serverelor
   instituționale; DSN cu `NOTIFY=SUCCESS` e rar suportat. `[CERT]` Proba legală stă exclusiv la
   instanță (CPC art. 183 alin. (3)). Putem proba doar **expedierea**.
3. **Platforma nu poate face semnarea** (vezi opțiunea B).

**Riscul dominant nu e bounce-ul, ci eșecul tăcut.** `[CERT]` Cele 42 de tribunale rutează pe
**secție**, nu pe instanță: Tribunalul Bacău are `tr-bacau-reg1@just.ro` (Secția I Civilă și Penală)
și `tr-bacau-reg2@just.ro` (Secția a II-a Civilă). Un email pe secția greșită **nu produce bounce**.
Ajunge la o cutie reală, e citit de o persoană reală, și moare acolo. Nu îl putem detecta automat,
niciodată.

`[CERT]` **Adresele nu sunt standardizate.** Ipoteza unui tipar `tribunalul.X@just.ro` este falsă:
prefixe `jud-`, `jd-`, `tr-`, `ca-`, `cabc-`, `judecatoria.`; sufixe `-reg`, `-registratura`,
`-dosare`, `-gref`, niciunul; `jud-roman.reg@just.ro` cu punct; `jud-bc-registratura@just.ro` cu
abreviere; Judecătoria Tg. Mureș are **două adrese concurente**. `[CERT]` **Nu există sursă unică
descărcabilă**: data.gov.ro dă count 0 pentru instanțe judecătorești; portal.just.ro/SitePages/instante.aspx
întoarce 200 și zero emailuri. Tribunalul București **nu publică nicio adresă**. Unele instanțe
publică adrese nominale de grefier (`paula.lovin@just.ro`), care se învechesc și ridică o problemă
de date personale.

**Efort: 3-5 săptămâni**, dominat de date și validare externă, nu de cod: 8-11 zile cod, dar **5-10
zile-om de colectare manuală** pentru 223 de instanțe plus secțiile celor 42 de tribunale (realist
260-280 de rânduri), cu proces de întreținere recurent.

Judecătorul juridic (scor 3): "din momentul în care platforma construiește și trimite ea însăși actul
de sesizare, se transformă dintr-un generator de documente într-un participant activ la actul de
procedură, ceea ce schimbă calitativ profilul de răspundere". Riscul e **structural neeliminabil prin
inginerie sau prin disclaimer contractual**.

## 6. Recomandare

**Se implementează opțiunea A (status quo îmbunătățit), cu ghidare spre rejust.ro ca fiind canalul
recomandat. Nu se construiește expediere prin email. Nu se construiește semnătură calificată în
platformă.**

Argumentul principal, într-o frază: **canalul de transmitere cel mai bun există deja, e construit de
CSM, e gratuit, are aceeași valoare juridică ca emailul direct, își rutează singur instanța pe secție,
și nu ne cere să deținem nicio adresă.** Orice lucru pe care l-am construi noi ar fi o versiune
inferioară a lui, cu 0/223 adrese de colectat și cu răspundere procedurală în plus.

Argumentul secundar: **platforma nu poate produce proba pe care legea o cere** (CPC art. 183
alin. (3): atestarea calculatorului instanței). Orice canal pe care l-am opera ne-ar pune în poziția
de a promite ceva ce nu putem dovedi.

De ce **formularul rejust** și nu emailul direct la registratură, deși juridic sunt echivalente (rejust
e tehnic tot un email trimis pe adresa instanței): la emailul direct, avocatul trebuie să găsească
singur adresa corectă, iar la cele 42 de tribunale rutarea se face pe secție (pentru OP, Secția a II-a
Civilă / litigii cu profesioniști). Un email pe secția greșită nu produce bounce, ajunge la o cutie
reală și moare tăcut acolo, imposibil de detectat. Formularul rejust elimină complet acest risc,
fiindcă instanța și rutarea le rezolvă portalul din dropdown-ul propriu. Emailul direct rămâne
menționat în checklist ca alternativă legitimă (CSM însuși îl indică), dar platforma nu furnizează
adresa: nu o avem (0/223) și nu o putem deriva.

Se implementează, în ordine:

1. **D3: separarea `CERERE_GENERATA` de `CERERE_DEPUSA`** (loc nou în workflow, tranziție
   `genereaza_cerere`, `depune_cerere` mutat pe confirmarea reală, `inregistreaza_dosar` acceptând
   **ambele** stări ca `from`, pentru cazul în care portalul descoperă dosarul înaintea confirmării).
   Plus `filedAt`, `filingChannel`, `filingReference` pe `LegalCase`. **Fereastra se închide la
   lansare.**
2. **Audit log pe descărcarea ZIP** (circa 5 linii, primul semnal că avocatul are pachetul).
3. **Hardening `CaseFilesPackager`**: eroare tare pe documentele obligatorii lipsă de pe disc, în loc
   de `continue` tăcut. Riscul e real: respingere ca inadmisibilă pentru lipsa dovezii comunicării.
4. **Confirmarea de depunere** (modal cu dată, canal, referință opțională).
5. **Checklist per canal** + raportarea dimensiunii ZIP cu avertisment peste 13 MB + `CopiesCalculator`
   (numărul de exemplare din cuprinsul cererii), cu formula **vizibilă** în UI, nu doar rezultatul.

**Ce NU se face fără confirmarea avocatului** (schimbare față de versiunea inițială a acestui
document): **nu se ating citările de articole CPC din cod.** Versiunea inițială recomanda un "fix"
al numerotării (art. 1016 în art. 1017) ca prioritate. Verificarea adversarială a refutat premisa:
forma republicată a CPC dă art. 1016 = "Cuprinsul cererii", deci codul poate fi deja corect. A face
"fixul" pe presupunere ar risca să *introducă* o eroare pe acte care ajung la judecător. Se așteaptă
răspunsul la întrebarea 3 (care formă este în vigoare) înainte de orice atingere a citărilor.

**Nu se face**: expediere prin email, semnătură calificată în platformă, integrare rejust, colectarea
celor 223 de adrese. Ușa rămâne deschisă: opțiunea A nu construiește nimic care ar trebui demontat.

**Pistă neacoperită, de menționat pentru corectitudine**: NO-GO-ul e fundamentat exclusiv pe
rejust.ro. `ANALIZA-JURIDICA-PROCEDURA-OP-2026-05-08.md:504-514` (m4) recomandă integrare post-MVP cu
**SmartGate / portal.just.ro**, un canal diferit, niciodată evaluat. Dacă cineva vrea vreodată să
răstoarne decizia, acolo e unghiul.

## 7. Ce rămâne în sarcina avocatului

Explicit, pentru copy și pentru limitarea răspunderii:

- **Depunerea însăși**, pe canalul ales. Platforma nu transmite nimic.
- **Semnarea** cererii, olograf sau electronic, în unealta proprie.
- **Recompunerea pachetului** în cele 3 sloturi rejust, dacă alege acest canal.
- **Numărul de exemplare** și tipărirea lor (CPC art. 1017 alin. (3)). Depunerea electronică nu
  scutește de multiplicare.
- **Comunicarea somației** prin executor sau scrisoare recomandată cu conținut declarat. Niciodată
  email.
- **Confirmarea depunerii** în platformă. Nu există remediu tehnic dacă nu o face: dosarul rămâne în
  `CERERE_GENERATA`. Atenuare parțială: `inregistreaza_dosar` acceptă ambele stări, deci introducerea
  numărului de dosar sare peste confirmarea uitată.
- **Verificarea adresei registraturii**, dacă alege emailul direct. Nu o furnizăm: nu o avem și nu o
  putem deriva.

Ce **nu** promite platforma, și trebuie spus în UI și în termenii de serviciu: livrare, confirmare de
primire, dovadă a datei depunerii. `filedAt` este o declarație a avocatului, nu o constatare.

## 8. Întrebări deschise pentru avocatul consultant

1. **Formula exemplarelor.** Textul (cuprinsul cererii de OP) cere "atâtea exemplare câte părți sunt,
   plus unul pentru instanță". Literal, formula numără și creditorul (creditori + debitori + 1);
   practica uzuală pare să calculeze debitori + 1, fiindcă reclamantul nu își comunică cererea sieși.
   Care e formula corectă în practica registraturilor? (Recomandarea internă: varianta literală,
   fiindcă supra-copierea costă hârtie iar sub-copierea atrage regularizare.)
2. **ÎCCJ 34/2017 vs 45/2020.** Emailul trimis în ultima zi a termenului, după programul instanței,
   este în termen sau tardiv? Verificarea a confirmat 45/2020 pe sursă primară (email = în termen),
   dar 34/2017 nu a putut fi reconfirmată. Confirmi că 45/2020 este soluția în vigoare? Blochează
   orice text din UI despre ultima zi.
3. **Numerotarea articolelor CPC (blocant pentru orice atingere a citărilor din cod).** Care formă a
   Codului este autoritativă: cea în care cuprinsul cererii de OP este **art. 1016** (forma
   republicată, `/DetaliiDocumentAfis/140271`, pe care codul o folosește azi) sau cea în care este
   **art. 1017** (forma consolidată, `/DetaliiDocument/140271`)? Cercetarea și verificarea au ajuns
   la concluzii opuse citând același document. Până la răspuns, **nu modificăm nicio citare de articol
   în cod sau în copy**, ca să nu introducem o eroare pe acte care ajung la judecător.
4. **Testul de 10 minute pe rejust**: acceptă portalul un fișier `.zip`? Determină dacă checklist-ul
   e utilizabil ca atare sau are nevoie de comasarea anexelor în PDF (+1,5-2 zile).
5. **Validarea checklist-ului per canal**, frază cu frază. Nu scriem copy juridic nevalidat pentru un
   public de avocați. **Blocant.**
6. **Confirmi direcția generală**: platforma pregătește, avocatul depune, cu rejust ca recomandare
   implicită? (Aceasta e întrebarea 6 din `MODIFICARI-REVIZIE-AVOCAT-PENTRU-VALIDARE-2026-06.md:148`,
   rămasă fără răspuns consemnat din 2026-06.)
7. **Cererea de acces la dosarul electronic** depusă odată cu acțiunea introductivă: e practică
   acceptată? Merită inclusă în pachet?
8. **Certificarea "conform cu originalul" prin semnătură electronică** (Legea 216/2025, CPC art. 150
   alin. (2), în vigoare de 7 luni): o acceptă registraturile în practică?
9. **Legea 57/2025** a ridicat pragul CVR la 50.000 lei. Rămânem strict pe ordonanța de plată sau
   recunoaștem că piața-țintă are acum o alternativă procedurală mai largă? Decizie de scop, nu
   tehnică.
10. **Semnătura**: presupunem că avocatul-utilizator are certificat calificat? (`[PROBABIL]` UNBR nu
    emite certificate proprii; se cumpără comercial; e-UNBR e proiect fără calendar public la mai 2026.)

## 9. Limite metodologice și incertitudini

### Cum a decurs verificarea

Verificarea adversarială **a rulat** pe 2026-07-18, în formă compresată: cele mai importante 15
afirmații marcate `CERT` (selectate prin scor pe cuvinte-cheie, ca să acopere exact constatările
load-bearing), împărțite în 5 loturi, câte un agent `avocat-senior` per lot, fiecare încercând să
refute din sursa primară, cu regula "refutat implicit dacă nu se poate confirma".

Rezultat: **12 confirmate, 3 refutate.** Distincția care contează pentru cum citești restul
documentului:

- **2 din cele 3 refutări sunt tehnice, nu de fond.** Agenții nu au putut accesa legislatie.just.ro
  și au epuizat bugetul de căutare, deci au aplicat regula implicită (refutat = neconfirmat). Ambele
  privesc contradicția ÎCCJ 34/2017 vs 45/2020, deja tratată ca deschisă. Partea utilă: un alt agent
  **a confirmat 45/2020 pe sursă primară** (vezi secțiunea 2).
- **1 refutare este substanțială și a schimbat documentul**: numerotarea CPC. Agentul a accesat forma
  republicată și a arătat că "bug-ul de citare" nu poate fi afirmat, fiindcă sub acea formă codul e
  corect (art. 1016 = cuprinsul cererii). Am retras recomandarea de "fix" și am marcat subiectul
  DISPUTAT (secțiunea 3, întrebarea 3). Aceasta e valoarea concretă a fazei de verificare: a oprit o
  modificare de cod care ar fi putut introduce o eroare.

Notă de proces: o rulare anterioară a raportat fals "9 afirmații refutate", pentru că logica
scriptului trata un agent mort ca pe o refutare reușită. Bug-ul a fost reparat înainte de rularea de
verificare finală.

**Ce nu blochează decizia**: recomandarea se sprijină pe constatări structurale (0/223 adrese, absența
API-ului, proba care stă la instanță, releul de email al CSM), toate în grupul **CONFIRMAT**, verificate
direct în cod și în surse primare. **Ce blochează implementarea**: citările de articole CPC (întrebarea
3, blocant strict), plus întrebările 1 și 8.

### Incertitudini specifice

- `[DISPUTAT]` **Numerotarea Titlului IX.** Cele două forme oficiale de pe legislatie.just.ro dau
  numerotări cu decalaj de o unitate: forma republicată (`/DetaliiDocumentAfis/140271`) dă art.
  1013-1024 cu cuprinsul cererii la **art. 1016**; o lentilă a susținut că forma consolidată
  (`/DetaliiDocument/140271`) dă art. 1014-1025 cu cuprinsul la **art. 1017**. Verificarea a înclinat
  spre forma republicată (deci codul corect), dar nu a tranșat definitiv. **Nu se modifică nicio
  citare până la confirmarea avocatului (întrebarea 3).**
- `[INCERT]` **Dacă rejust acceptă `.zip`.** Input-urile de fișier nu au atribut `accept`, validarea
  e server-side. Dat fiind că grefa tipărește atașamentele, ZIP-ul e probabil nepotrivit.
- `[INCERT]` **Limitele de atașament pe cutiile @just.ro.** Nicio specificație publicată. Probabil
  variază per instanță.
- `[INCERT]` **Dimensiunea unui pachet real.** ZIP-ul observat în `var/uploads` are 117 KB, dar e
  dosar demo, nereprezentativ pentru scanuri. Pragul de 13 MB nu poate fi calibrat fără date reale.
- `[INCERT]` **Acoperirea reală a rejust.** CSM afirmă acoperire națională, dar dropdown-ul se
  populează din `/api/courts`, care răspunde 401. Nu am putut enumera independent.
- `[INCERT]` **Stadiul DEN**: harta oficială "Stadiul înrolării instanțelor" era încă etichetată
  "iulie 2025" la 17.07.2026, deși site-ul afișează versiune 2.2.43 și copyright 2026.
- `[INCERT]` **Practica reală majoritară a avocaților** pentru cererea de OP: nu s-a găsit sondaj,
  studiu sau fir de forum relevant și recent. Orice afirmație ar fi speculație.
- `[INCERT]` **Temeiul formal al suspendării** judecătoriilor Însurăței, Bocșa, Murgeni. Faptul că
  sunt fără circumscripție proprie în anexa HG 1217/2023 e cert; actul care declară expres
  suspendarea nu a fost găsit.
- `[INCERT]` **Legea 214/2024** (succesoarea Legii 455/2001): conținutul se bazează pe rezumate de
  presă juridică, nu pe textul integral la sursă primară.
- **Nu s-au scrapat toate cele 223 de instanțe**, ci un eșantion de circa 13 plus lista completă a
  circumscripției CA Bacău. Concluzia privind lipsa standardizării e solidă (contraexemplele infirmă
  orice tipar), dar procentul de acoperire scrapabilă nu e cuantificat.
- **Nu s-a testat nimic runtime.** Toate afirmațiile despre cod provin din citire statică și din
  interogări SQL.
- **Sursele juridice gratuite trebuie folosite cu prudență**: legeaz.net redă încă textul *anterior*
  al art. 183, fără fax/email. Cel puțin o sursă consultată reproduce text abrogat.

## 10. Surse

### Legislație

- Cod de procedură civilă, formă consolidată: https://legislatie.just.ro/Public/DetaliiDocument/140271
  (art. 148, 150, 183, 199, 200, 1014-1025). Atenție: `/DetaliiDocumentAfis/140271` este forma de bază
  cu numerotare veche.
- CPC art. 199: https://legeaz.net/noul-cod-de-procedura-civila/art-199
- CPC art. 183, formă modificată prin Legea 310/2018:
  https://coduri.juridice.ro/codul-de-procedura-civila/index.php/2019/09/25/art-183-actele-depuse-la-posta-servicii-specializate-de-curierat-unitati-militare-sau-locuri-de-detinere/
- Legea 268/2024 (art. 40 alin. (3) OUG 80/2013), M. Of. 1089/31.10.2024:
  https://legislatie.just.ro/Public/DetaliiDocumentAfis/290171
- Legea 216/2025 (CPC art. 150 alin. (2)), M. Of. 1151/11.12.2025:
  https://www.avocatnet.ro/articol_69352/
- Legea 57/2025 (prag CVR 50.000 lei), M. Of. 433/12.05.2025:
  https://legislatie.just.ro/public/DetaliiDocument/297925
- ÎCCJ, Decizia nr. 45/2020 (Complet DCD): https://legislatie.just.ro/Public/DetaliiDocument/207598
- ÎCCJ, Decizia nr. 34/2017, M. Of. 803/11.10.2017 (soluție contrară, pe textul nemodificat)
- CCR, Decizia nr. 605/2016, M. Of. 03.01.2017:
  https://www.clujust.ro/ccr-trimiterea-actelor-de-procedura-prin-e-mail-e-asimilata-depunerii-personale-la-instanta/
- Hot. CSM 3243/2022 (ROI instanțe), M. Of. 1245 și 1245 bis/27.12.2022, art. 94 alin. (1) și (11),
  art. 129 alin. (1)
- HG 1217/2023, M. Of. 1102/07.12.2023 (arondare judecătorii)

### Surse verificate live la 17.07.2026

- registratura.rejust.ro, formular înregistrare dosar nou (randat în browser, fără autentificare):
  https://registratura.rejust.ro/inregistreaza-un-dosar-nou-pe-rolul-instantei-de-judecata
  Reconfirmat live la 2026-07-20, formularul neschimbat. Captură:
  `docs/LexRecovery/rejust-inregistrare-dosar-nou-2026-07-20.png`. Vizibile pe formular: selector
  instanță (rutare făcută de portal), cele 3 opțiuni de taxă de timbru inclusiv plata de către terț
  cu împuternicire, limita scrisă explicit "maximum 11 MB fiecare, totalul sub 13 MB", exact 3 sloturi
  de upload, contorul de taxe achitate (72.884.020,93 lei), parteneri CA Galați + ADR + Ghișeul.ro + APERO.
- FAQ CSM: https://registratura.rejust.ro/faq
- Instrucțiuni CSM republicate de Tribunalul Alba: https://tribunalulalba.ro/tbab/portal-rejust-instructiuni/
- Curtea de Apel Galați (autorul platformei rejust): https://cagl.ro/
- Instrucțiuni comunicare electronică, CA Bacău (PDF oficial):
  https://portal.just.ro/321/Documents/Dosar%20electronic/Instructiuni-comunicare-electronica.pdf
- DEN: https://den.just.ro/informatii + Manual oficial DEN (portal.just.ro/62)
- data.gov.ro CKAN API: `package_search?q=instante+judecatoresti` → count 0
- Instrucțiuni Dosar Electronic Judecătoria Sector 2, actualizare 16.04.2026: portal.just.ro/300
- Plăți pentru terți pe rejust: https://www.juridice.ro/698201/
- Certificate calificate pentru avocați (ofertă barou): https://www.baroul-bucuresti.ro/stire/comunicat-...-trans-sped
- Q&A Congresul Avocaților 2026 (e-UNBR): https://www.juridice.ro/823749/
- Practică pe canalul somației (spețe 2016-2021): https://www.juridice.ro/680192/

### Documente interne

- `docs/LexRecovery/SPIKE-DEPUNERE-ELECTRONICA-REJUST.md` (R9, 2026-06-30, comis în `62d59bc`)
- `docs/LexRecovery/ANALIZA-PLATA-TAXA-TIMBRU.md` (decizia D3, linia 140)
- `docs/LexRecovery/ANALIZA-JURIDICA-PROCEDURA-OP-2026-05-08.md` (m4, pista SmartGate, liniile 504-514)
- `docs/LexRecovery/ANALIZA-FLUXURI-LEXRECOVERY.md` (v1.1, conține afirmații depășite)
- `docs/LexRecovery/MODIFICARI-REVIZIE-AVOCAT-PENTRU-VALIDARE-2026-06.md` (întrebarea 6, linia 148)
