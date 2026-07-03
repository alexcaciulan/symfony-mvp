# SPIKE: Depunere electronică + acces la dosarul electronic (registratura.rejust.ro)

> Pas R9 din `PLAN-REVIZIE-WORKFLOW-AVOCAT-2026-06.md`. Document de fezabilitate (research), NU implementare. Output: recomandare GO/NO-GO + pasul viitor recomandat.
> Data: 2026-06-30.

## Obiectiv

Avocatul a cerut „depunerea prin platformă" la `registratura.rejust.ro`. Clarificare ulterioară (user, 2026-06-30): scopul real nu e doar depunerea, ci faptul că aceasta **acordă concomitent avocatului acces la dosarul electronic** (consultarea online a actelor, termenelor și hotărârilor). Întrebarea de fezabilitate: poate platforma LexRecovery automatiza acest lucru (API rejust.ro), sau rămânem pe un flux de email?

## Ce este registratura.rejust.ro

Portal oficial al CSM (Consiliul Superior al Magistraturii), interconectat cu sistemele instanțelor, care permite justițiabililor și profesioniștilor:
- înregistrarea unui dosar nou (cerere de chemare în judecată);
- declararea unei căi de atac într-un dosar;
- **depunerea de acte/documente într-un dosar existent**;
- plata taxei judiciare de timbru cu cardul;
- eliberarea de certificate de grefă / copii certificate ale hotărârilor.

## Constatări

### 1. API public: NU există (documentat)
`registratura.rejust.ro` este un **portal web destinat utilizării manuale** de către justițiabil/avocat. Nu există documentație publică a unui API programatic. Depunerea presupune:
- **sesiunea autentificată a avocatului** (cont propriu pe portal);
- **semnătura electronică calificată** pe actele depuse (eIDAS / Legea 455/2001 privind semnătura electronică).

Consecință: o platformă terță (LexRecovery) **nu poate depune în numele avocatului** fără credențialele și certificatul calificat ale acestuia. Automatizarea ar însemna fie stocarea/manipularea certificatului avocatului (inacceptabil ca risc), fie un API de delegare care nu există.

### 2. Operabilitate limitată
Suportul digital variază mult între instanțe; multe instanțe nu au încă logistica pentru dosare electronice pe platforma CSM, ceea ce limitează acoperirea reală a serviciilor.

### 3. Accesul la dosarul electronic: procedură reală
Accesul la „dosarul electronic" (consultare online) se obține astfel (confirmat din surse instanțe + portal.just.ro):
- avocatul/partea depune o **„Cerere de acces la dosarul electronic"** (formular standard PDF/DOC publicat pe site-ul fiecărei instanțe), la registratură **sau trimisă electronic / prin email**, însoțită de copie act de identitate / dovada calității;
- după aprobare, instanța **trimite prin email un cod de acces**, cu care se consultă online toate actele, termenele și hotărârile din dosar;
- dacă instanța nu are sistemul implementat, rămâne doar monitorizarea prin `portal.just.ro` (deja integrată în LexRecovery).

Cererea de acces se poate face și ulterior, de către avocat, indicând email + telefon; după introducerea datelor în sistem, instanța notifică prin email.

## Recomandare: NO-GO pentru integrare automată rejust.ro (MVP)

Integrarea programatică directă cu `registratura.rejust.ro` **nu este fezabilă pentru MVP**: nu există API, iar depunerea necesită sesiunea + semnătura calificată a avocatului (bariere tehnice + de răspundere pe care o platformă terță nu le poate prelua sigur). Avocatul folosește rejust.ro manual, cu pachetul ZIP deja generat de platformă (cerere OP + opis + somație + dovada comunicării).

## Path recomandat (fallback validat cu user): email „Cerere de acces la dosarul electronic"

Întrucât scopul real e accesul la dosarul electronic, fluxul realist și automatizabil este pe email:

1. Avocatul depune cererea OP (manual, pe rejust.ro sau fizic) și revine în platformă cu numărul de dosar; SAU platforma trage numărul de dosar de pe `portal.just.ro` (auto-discovery existent, `PortalCaseMatcher`).
2. După ce numărul de dosar e cunoscut, platforma **generează o „Cerere de acces la dosarul electronic"** (PDF, prefilat cu numărul de dosar, datele avocatului și ale dosarului).
3. Platforma **o trimite prin email la instanță** (`Court.email`, câmp existent pe entitatea `Court`; infrastructură de email prin `NotificationDispatcher`).
4. Instanța returnează codul de acces pe email-ul avocatului (în afara platformei).

**Acesta NU e implementat în R9** (R9 = spike + copy). E un pas viitor separat, cu pre-condiții:
- model de „Cerere de acces la dosarul electronic" validat juridic (variază per instanță; un model generic + posibilitate de override);
- `Court.email` populat per instanță (de verificat acoperirea în `app:import-courts`);
- atașarea identității/calității avocatului (copie act + împuternicire), pe care avocatul o furnizează o singură dată;
- gestionarea răspunsului instanței rămâne manuală (codul vine pe email-ul avocatului).

## Impact R9 asupra copy-ului

Textele care afirmau categoric „depunere fizică la registratură / prin curier" au fost reformulate neutru (depunere la instanța competentă, cu confirmare de primire), ca să nu contrazică direcția de produs până la decizia GO/NO-GO pe fluxul de email. ZIP-ul de depunere include deja dovada comunicării (`CaseFilesPackager`, folder `dovada_comunicare/`).

## Surse
- registratura.rejust.ro, depunere acte într-un dosar existent: https://registratura.rejust.ro/depunere-acte-si-documente-intr-un-dosar-existent
- registratura.rejust.ro (portal): https://registratura.rejust.ro/
- Model „Cerere de acces la dosarul electronic" (exemplu instanță): https://portal.just.ro/301/Documents/cerere_acces%20dosar%20electronic.pdf
- Accesarea dosarului electronic (procedură): https://sfat-avocat.ro/accesarea-dosarului-electronic-la-judecatorie
- Context lansare + limitări CSM (presă juridică): juridice.ro / avocatnet.ro (articole 2023 despre registratura.rejust.ro)
