# Răspuns la observațiile dvs. pe „Determinarea instanței competente"

Stimate domnule avocat,

Am analizat toate completările dumneavoastră (textul cu albastru) din documentul *Determinarea instanței competente* și le-am verificat juridic. Vă confirmăm că **observația principală era corectă** și am modificat aplicația în consecință. Mai jos găsiți, punct cu punct, ce am schimbat.

---

## 1. Corecția principală: pragul judecătorie/tribunal se calculează pe principal (implementat)

**Observația dvs.:** valoarea de 200.000 RON care decide între judecătorie și tribunal trebuie raportată **doar la principal**, nu la totalul cu dobânzi și penalități, conform **CPC art. 98 alin. 2** („nu se vor avea în vedere accesoriile [...], indiferent de data scadenței").

**Confirmare:** corectă. Aplicația calcula greșit pragul pe valoarea totală.

**Ce am modificat:**
- Aplicația compară acum **principalul** cu pragul de 200.000 RON:
  - principal ≤ 200.000 RON → **judecătorie**;
  - principal > 200.000 RON → **tribunal**.
- Dobânda acumulată și penalitățile contractuale **nu mai influențează** alegerea instanței. Ele rămân afișate și se cer în continuare în petit, dar nu mai mută dosarul la tribunal.
- Mesajul afișat avocatului în aplicație a fost corectat (nu mai spune „valoare totală cu accesorii", ci precizează că accesoriile sunt excluse, art. 98 alin. 2).

**Exemple recalculate (așa cum ați indicat):**

| Principal | Dobândă | Penalități | Instanța (acum corect) |
|-----------|---------|------------|------------------------|
| 180.000   | 25.000  | 0          | Judecătorie (înainte: greșit „Tribunal") |
| 195.000   | 0       | 10.000     | Judecătorie (înainte: greșit „Tribunal") |
| 200.000   | 1 RON   | 0          | Judecătorie (înainte: greșit „Tribunal") |
| 201.000   | 0       | 0          | Tribunal |

---

## 2. Observații confirmate și documentate

| Observația dvs. | Statut |
|-----------------|--------|
| Competența teritorială poate fi modificată prin contract (ex. la sediul reclamantului) | Corect (CPC art. 126). Notat în document; rămâne verificare manuală a avocatului. |
| Competența alternativă (art. 113) poate fi mai favorabilă creditorului; manual sau prin analiză AI a contractului | Corect. Notat ca opțiune viitoare; rămâne decizie manuală a avocatului. |
| Bucureștiul are și un Tribunal Militar, dar pentru noi contează un singur tribunal | Corect. Tribunalul militar nu are competență în ordonanța de plată; va fi exclus din datele aplicației. |
| Nu pot exista mai multe judecătorii competente pentru același domiciliu, doar una | Corect. Cazul „mai multe" rămâne doar ca verificare de siguranță pentru erori de date. |
| Schimbarea sediului după depunere nu schimbă instanța (perpetuatio fori) | Corect (CPC art. 106). Confirmat în document. |

---

## 3. Observații preluate pentru o etapă viitoare (necesită dezvoltare separată)

- **Identificarea județului din numărul de la Registrul Comerțului** (ex. J40 = București), când datele ANAF nu conțin județul. Util ca sugestie, dar va fi însoțit de un avertisment, deoarece numărul „J" reflectă județul de înregistrare, nu neapărat sediul actual.
- **Verificarea atentă a adresei la București (pe sectoare).** Vom întări validarea, având în vedere riscul semnalat de dumneavoastră (o selecție greșită de sector duce la declinarea competenței după luni de așteptare).

---

## 4. Observații marcate „OK" de dumneavoastră

Punctele pe care le-ați confirmat ca fiind corecte (raza teritorială pe localitate, tribunalul pe județ, normalizarea localităților, granița exactă a pragului, exemplele finale) **au rămas neschimbate**.

---

**Pe scurt:** observația juridică esențială (pragul pe principal) a fost validată și implementată, exemplele au fost recalculate, iar restul completărilor au fost fie confirmate și documentate, fie programate pentru o etapă următoare. Modificările au fost verificate atât juridic, cât și tehnic, înainte de a fi integrate.

Cu stimă,
Echipa LexRecovery
