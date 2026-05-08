# LexRecovery – Cum Funcționează Aplicația
### Ghid vizual pentru avocați

---

## Ce face aplicația, pe scurt

> LexRecovery automatizează tot procesul de recuperare a creanțelor prin procedura **Ordonanței de Plată** – de la primul contact cu debitorul până la executarea silită. Avocatul introduce datele o singură dată, iar aplicația se ocupă de documente, termene și monitorizare.

---

## Fluxul Complet al unui Dosar

```mermaid
flowchart TD
    START(["📁 Dosar nou\nClient vine cu o creanță neîncasată"])

    START --> PAS1

    subgraph PAS1["📋  PASUL 1 – Înregistrezi dosarul"]
        direction LR
        A1["Introduci datele clientului\n(creditorul)"] 
        A2["Introduci datele debitorului\n(cel care datorează)"]
        A3["Introduci suma datorată\nși data scadenței"]
        A1 --> A2 --> A3
    end

    PAS1 --> AUTO1{{"⚡ Aplicația calculează automat:\n✔ Dobânda legală acumulată\n✔ Suma totală la zi\n✔ Taxa de timbru\n✔ Instanța competentă"}}

    AUTO1 --> PAS2

    subgraph PAS2["✉️  PASUL 2 – Somația de plată"]
        direction LR
        B1["Apeși un buton\n→ Somația e gata în secunde"]
        B2["Descarci documentul\npre-completat și semnat"]
        B3["Trimiți somația\nla debitor"]
        B1 --> B2 --> B3
    end

    PAS2 --> AUTO2{{"⚡ Aplicația:\n✔ Numără automat cele 30 de zile\n✔ Te alertează cu 7, 3 și 1 zi înainte de expirare\n✔ Îți amintește dacă nu ai acționat"}}

    AUTO2 --> DECIZIE1{A plătit\ndebitorul?}

    DECIZIE1 -->|"✅ DA – a plătit"| SUCCES_AMIABIL(["🎉 Dosar închis\nRecuperare amiabilă"])

    DECIZIE1 -->|"❌ NU – nu a plătit"| PAS3

    subgraph PAS3["⚖️  PASUL 3 – Cererea de Ordonanță de Plată"]
        direction LR
        C1["Apeși un buton\n→ Cererea completă e gata"]
        C2["Primești un ZIP cu:\n• Cererea de chemare\n• Somația trimisă\n• Contractul / facturile\n• Opisul documentelor\n• Calculul dobânzilor"]
        C3["Depui dosarul\nla instanță"]
        C1 --> C2 --> C3
    end

    PAS3 --> PAS4

    subgraph PAS4["🔍  PASUL 4 – Monitorizare instanță"]
        direction LR
        D1["Introduci numărul\nde dosar din instanță"]
        D2["Aplicația verifică zilnic\nautomatizat portal.just.ro"]
        D3["Primești notificare\npe email când apare\norice mișcare în dosar"]
        D1 --> D2 --> D3
    end

    PAS4 --> DECIZIE2{Ce decide\ninstanța?}

    DECIZIE2 -->|"✅ Ordonanță emisă"| PAS5
    DECIZIE2 -->|"⚠️ Contestație depusă\nde debitor"| CONTESTATIE["📋 Aplicația te alertează\nîți calculează termenul\nde răspuns automat"]
    CONTESTATIE --> DECIZIE3{Rezultat\ncontestație}
    DECIZIE3 -->|"Contestație respinsă"| PAS5
    DECIZIE3 -->|"Contestație admisă"| RESPINS(["📁 Dosar închis\nCale alternativă necesară"])
    DECIZIE2 -->|"❌ Cerere respinsă"| RESPINS

    subgraph PAS5["🏛️  PASUL 5 – Ordonanța devine definitivă"]
        direction LR
        E1["10 zile de la comunicare\nfără contestație"]
        E2["Aplicația numără zilele\nși te alertează la expirare"]
        E3["Ordonanța devine\nTITLU EXECUTORIU"]
        E1 --> E2 --> E3
    end

    PAS5 --> PAS6

    subgraph PAS6["⚡  PASUL 6 – Executarea silită"]
        direction LR
        F1["Selectezi executorul\njudecătoresc partener"]
        F2["Aplicația generează\npachetul complet:\n• Cerere executare\n• Calculul actualizat\n• Titlul executoriu\n• Toate actele dosarului"]
        F3["Trimiți pachetul\nla executor"]
        F4["Monitorizezi\nîncasările în aplicație"]
        F1 --> F2 --> F3 --> F4
    end

    PAS6 --> DECIZIE4{Rezultat\nexecutare}
    DECIZIE4 -->|"✅ Recuperat integral"| SUCCES(["🎉 Dosar închis\nRecuperare integrală"])
    DECIZIE4 -->|"Recuperat parțial"| PARTIAL(["📁 Dosar parțial\nExecutare continuă"])
    DECIZIE4 -->|"Debitor fără bunuri"| INSOLVABIL(["📁 Dosar suspendat\nReluare la apariția\nunor bunuri noi"])

    style START fill:#3b82f6,color:#fff,stroke:#2563eb
    style SUCCES_AMIABIL fill:#22c55e,color:#fff,stroke:#16a34a
    style SUCCES fill:#22c55e,color:#fff,stroke:#16a34a
    style PARTIAL fill:#f59e0b,color:#fff,stroke:#d97706
    style INSOLVABIL fill:#94a3b8,color:#fff,stroke:#64748b
    style RESPINS fill:#ef4444,color:#fff,stroke:#dc2626
    style AUTO1 fill:#fef9c3,stroke:#eab308,color:#713f12
    style AUTO2 fill:#fef9c3,stroke:#eab308,color:#713f12
```

---

## Ce Face Aplicația în Locul Tău

```mermaid
mindmap
  root(("🤖 LexRecovery\nFace automat"))
    Documente
      Somație de plată pre-completată
      Cerere Ordonanță de Plată completă
      Opis documente
      Dosar executare silită
      ZIP cu tot pachetul pentru instanță
    Calcule
      Dobânda legală acumulată zi de zi
      Taxa de timbru exact
      Instanța competentă
      Suma totală actualizată live
    Termene
      Numără cele 30 zile somație
      Numără 10 zile contestație
      Alertează cu 7 zile înainte
      Alertează cu 3 zile înainte
      Alertează cu 1 zi înainte
      Calculează termenul de prescripție
    Monitorizare
      Verifică zilnic portal.just.ro
      Detectează termene noi fixate
      Detectează ordonanța emisă
      Trimite email imediat la orice schimbare
```

---

## Câte Dosare Poți Gestiona Simultan

```mermaid
graph LR
    subgraph FARA["❌ Fără aplicație"]
        direction TB
        F1["5-10 dosare active\ngreu de urmărit manual\nrisc pierdere termene"]
    end

    subgraph CU["✅ Cu LexRecovery"]
        direction TB
        C1["50-100+ dosare active\ntotul monitorizat automat\nzero termen pierdut"]
    end

    FARA -->|"Productivitate\n10x"| CU
```

---

## Dashboard – Ce Vei Vedea la Prima Autentificare

```mermaid
graph TD
    DASH["🖥️ Dashboard Principal"]

    DASH --> W1["📊 Dosare Active\nstatus fiecăruia dintr-o privire"]
    DASH --> W2["⏰ Termene Această Săptămână\nce trebuie făcut azi, mâine, poimâine"]
    DASH --> W3["🔔 Alerte Urgente\ncritice în roșu, importante în portocaliu"]
    DASH --> W4["💰 Sume în Recuperare\ntotal portofoliu la zi"]

    W2 --> T1["🔴 Expiră AZI: Dosar X vs Y – somație"]
    W2 --> T2["🟠 Mâine: Dosar A vs B – termen instanță"]
    W2 --> T3["🟡 Peste 3 zile: Dosar C vs D – contestație"]
    W2 --> T4["🟢 Peste 7 zile: Dosar E vs F – prescripție exec."]
```

---

## Siguranța Datelor

```mermaid
graph LR
    S1["🔒 Acces doar\ncu parolă"] 
    S2["👥 Datele cabinetului\ntău sunt separate\nde alte cabinete"]
    S3["💾 Backup automat\nzilnic"]
    S4["🇷🇴 Server în\nEuropa\n(GDPR compliant)"]
    S5["📄 Toate documentele\ngenerate sunt\nstocate securizat"]

    S1 --- S2 --- S3 --- S4 --- S5
```

---

## Timp Estimat per Dosar

| Activitate | Fără aplicație | Cu LexRecovery |
|---|---|---|
| Redactare somație | 30-45 min | **2 minute** (download PDF) |
| Redactare cerere OP + opis | 2-3 ore | **5 minute** (download ZIP) |
| Calcul dobânzi + taxă timbru | 15-20 min | **Instant** (automat) |
| Verificare portal.just.ro | Zilnic, manual | **Automat** (email la schimbare) |
| Urmărire termene | Agendă manuală | **Automat** (email cu 7/3/1 zile) |
| Dosar executare silită | 1-2 ore | **10 minute** |

---

> **Concluzie:** LexRecovery nu înlocuiește avocatul – judecata juridică, strategia și relația cu clientul rămân ale tale. Aplicația elimină munca repetitivă și administrativă, astfel încât tu să te poți concentra pe dosarele care necesită cu adevărat expertiza ta.
