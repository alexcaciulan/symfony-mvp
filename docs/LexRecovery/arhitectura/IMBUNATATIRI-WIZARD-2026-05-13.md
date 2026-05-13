# Îmbunătățiri arhitecturale wizard — roadmap post-MVP

**Data analizei**: 2026-05-13
**Context**: după livrarea Pas 3.4 (Turbo Frame + View Transitions), wizardul e fluid + reactiv. Întrebare arhitecturală deschisă: trebuie să trecem de la **session bag** la **DB drafts** pentru state management? Plus alte îmbunătățiri identificate în review-ul best-practices.
**Status**: roadmap propus, NU plan de execuție imediat. Decizia finală + prioritizarea ține de owner.
**Referințe**: `PLAN-DEZVOLTARE-LEXRECOVERY.md` (Pași 3.0-3.4 DONE), `analize-juridice/MULTI-DEBITOR-OP-2026-05-13.md` (lacune juridice), MEMORY.md (state Pas 3.4)

---

## TL;DR

Wizardul actual e la **~80-85% din best practices** pentru un wizard B2B legal. **Toate** îmbunătățirile identificate sunt **non-blocking pentru MVP** — pot fi livrate post primii utilizatori reali. Recomandarea: **NU refactoriza state management acum**; livrăm Faza 4-6 (overview dosar + termene + PDF somație + depunere) → cu produs funcțional end-to-end, apoi DB drafts ca prima îmbunătățire de UX.

**Top 3 îmbunătățiri în ordinea ROI**:
1. **DB drafts** (1-1.5 zile) — cross-device resumability, supraviețuiește logout
2. **Progressive disclosure PF/PJ** (2-3h) — hide câmpuri irelevante per persoană fizică/juridică
3. **Confirm modal Step 4** (1h) — preview rezumat înainte de submit final

Cuplate cu **lacunele juridice multi-debitor** (`analize-juridice/MULTI-DEBITOR-OP-2026-05-13.md`) — Pas 3.3.1 minim, în paralel cu următoarea iterație.

---

## 1. Arhitectura curentă — recapitulare

```
URL: /case/new/{documents,creditor,debtor,claim,confirmation}
Controller: 5 metode în CaseWizardController, GET = render, POST = save+next
State: $session->get('case_wizard_data') = [
    documentIds[], creditor: Step1CreditorData,
    debtors: Step2DebtorsData, claim: Step3ClaimData
]
Templates: wizard.html.twig (shell) + _stepN_*_content.twig (per pas)
            Wrap în <turbo-frame id="wizard-frame"> (Pas 3.4)
Reactivitate: Live Components Step 2+3 (Pas 3.3) + Stimulus controllers
```

**Stack pattern**: multi-page server-rendered + Turbo Frame swap + Live Components pentru micro-interacții. Standard B2B legal/financial (Stripe Connect, TurboTax, plaid IDV, eForm-uri gov.uk).

---

## 2. Audit best-practices

### ✅ Ce face LexRecovery CORECT

| Best practice | Status | Locație |
|---|---|---|
| Multi-page wizard server-rendered | ✅ | `CaseWizardController.php` |
| Validare incrementală per form | ✅ | DTOs cu `Assert\*` constraints |
| Validare cross-step la final | ✅ | `OpAdmissibilityValidator` (Pas 2.4) |
| Resumability F5 / browser back | ✅ | Session bag persistă |
| Stepper indicator | ✅ | `templates/components/Stepper.html.twig` (Pas 2.7) |
| URL canonical per pas | ✅ | `/case/new/{step-name}` semantic |
| CSRF protection per form | ✅ | Token-uri distincte per `Step*Type` |
| Rate limiting submit final | ✅ | `case_creation` 10/h (Pas 3.2) |
| AI prefill smart defaults | ✅ | Cascade 4 strategii (Pas 2.5) |
| Inline field validation | ✅ | `ValidCui`, `ValidCnp`, `ValidIban` |
| Turbo Frame fluidity | ✅ | Pas 3.4 |
| View Transitions API animation | ✅ | Pas 3.4 — `wizard-view-transition.js` |
| Scroll-to-top între pași | ✅ | Pas 3.4 |
| Friendly error messages | ✅ | i18n RO/EN cu descrieri clare |
| 422 status code pe form invalid | ✅ | Symfony 7 default |
| Symfony Form preservation pe invalid | ✅ | Nativ |
| Live Components pentru micro-interacții | ✅ | Pas 3.3 |
| Re-fetch entități la submit final | ✅ | `reuseOrCreateCreditor()` UNIQUE pe (user, cui) |

### ⚠️ / ❌ Ce lipsește (gap analysis)

| Best practice | Status | Cost fix |
|---|---|---|
| Cross-device resumability | ❌ | DB drafts: 1-1.5 zile |
| Supraviețuire logout/cookie expire | ❌ | DB drafts: ↑ |
| Auto-save indicator vizual | ❌ | DB drafts: ↑ |
| Progressive disclosure PF/PJ | ❌ | 2-3h Stimulus |
| Confirm modal Step 4 | ❌ | 1h Preline modal |
| Multi-debitor temei coparticipare | ❌ | 1-2h (lacună juridică A din `MULTI-DEBITOR-OP-2026-05-13.md`) |
| Multi-debitor warning art. 59 CPC | ❌ | 30min UI (lacună juridică B) |
| Wizard analytics (abandonment per pas) | ❌ | 2-4h (necesită eveniment tracking) |
| Email reminder draft abandonat | ❌ | Necesită DB drafts + cron + mailer template |
| Mobile-first responsive optimizat | ⚠️ | Există grid responsive, NU testat extensiv pe mobil |
| A11y stepper aria-labels complete | ⚠️ | Audit + refactor: 1-2h |
| Save & continue later button explicit | ❌ | Necesită DB drafts |
| Multi-user collaboration (review draft) | ❌ | Post-MVP advanced |

---

## 3. Recomandare #1 — DB drafts în loc de session bag

### Problema

Session bag funcționează DAR are limitări concrete:

1. **Pierdere totală la logout** — avocatul completase 3 pași într-un dosar complex (BPI verificare, ANAF check pe 3 debitori), trebuie să iasă urgent, revine peste 4 ore → wizardul e gol
2. **Cross-device 0** — avocatul începe pe desktop la birou (acces ANAF rapid), vrea să continue pe iPad acasă pentru review → imposibil cu session
3. **Cookie expire** — sesiune Symfony default ~120 min (variabil), browser închis = pierdut
4. **Browser crash / power loss** — chiar și extensiile dezvoltatorului care reload context distrug session
5. **NU există „dosarele mele în lucru"** — dashboard arată doar dosare submitted; drafturile sunt invizibile

### Soluția — DB draft entity

```
src/Entity/LegalCaseDraft.php (nou)
├── id: Uuid v7 (visible în URL, NU autoincrement)
├── user_id: FK → User
├── status: enum DraftStatus (DRAFT | SUBMITTED | ABANDONED)
├── created_at, updated_at, last_accessed_at: DateTimeImmutable
├── snapshot: JSON column conținând:
│   ├── documentIds: int[]
│   ├── creditor: Step1CreditorData serialized
│   ├── debtors: Step2DebtorsData serialized
│   └── claim: Step3ClaimData serialized
└── current_step: int (0..4) — pentru shortcut „continuă unde ai rămas"

URL: /case/new/{draftUuid}/{step-name}
    ↑ UUID în URL = shareable, opaque (nu CNP/CUI vizibil), cross-device

Flow nou:
1. User click „Dosar nou" pe dashboard
   → POST /case/new → create LegalCaseDraft cu UUID
   → redirect /case/new/{uuid}/documents
2. Fiecare submit per pas → UPDATE snapshot JSON + current_step
3. Dashboard listează drafturi cu status=DRAFT (user.legalCaseDrafts)
4. Step 4 submit success → status=SUBMITTED + persistă LegalCase + cleanup draft (opțional)
5. Cleanup job: cron șterge drafturi cu status=DRAFT + last_accessed_at < -30 zile (GDPR data minimization)
```

### Câmpuri encriptate la rest

`snapshot` poate conține CNP / CUI / IBAN — date personale GDPR-sensitive. Soluții:

- **A** (minimal): rely pe MySQL encryption at rest (deja deployment Hetzner cu disk encryption) + ne-loggable în queries verbose
- **B** (defensive): aplicarea explicită a unei Doctrine Type cu encryption layer (gen `doctrine-encrypt-bundle`) — overhead minim, audit clean
- **C** (paranoid): split JSON în câmpuri cu nivel diferit de sensibilitate (CNP în coloană dedicată encrypted, restul JSON normal)

**Recomandare**: **A pentru MVP**, migrare la **B** când avocații-utilizatori produc volum (>100 drafturi simultane în DB). C e overkill pentru contextul nostru.

### Effort estimat

| Componentă | Effort |
|---|---|
| Entity `LegalCaseDraft` + migrare DB | 1h |
| Repository + voter (DraftVoter EDIT scoped la owner) | 1h |
| `CaseWizardController` refactor (snapshot load/save per pas) | 3-4h |
| Dashboard secțiune „Drafturi în lucru" | 1-2h |
| Cleanup command + cron schedule | 1h |
| `LegalCaseDraftFactory` + tests unitare | 1-2h |
| Migrare data existentă (drop session-bag pe deploy — incompatibil) | 30min |
| Tests integration WebTestCase | 2-3h |
| **TOTAL** | **~12h = 1.5-2 zile** |

### Beneficii concrete

1. **Cross-device** — UUID în URL, avocat partajează cu sine între dispozitive
2. **Supraviețuiește logout / browser crash / power loss**
3. **Dashboard „Continuă dosar"** — recovery natural a draftului
4. **„Salvat acum 5s"** indicator vizual (auto-save toast la fiecare submit pas)
5. **Share cu coleg pentru review** — pre-submit collaboration
6. **Wizard analytics nativ** — `current_step` în DB → SELECT GROUP BY abandonment rate per pas
7. **Email reminder** — cron caută drafturi >24h fără update → trimite reminder cu link
8. **Audit trail GDPR** — `updated_at` timestamp arată exact când user-ul a interactionat cu datele

### Riscuri

- **Migrare incompatibilă** — wizardurile in-flight la deploy-ul migrarii pierd starea (rar — utilizatorii completează în <1h). Mitigare: deploy weekend când traficul e zero
- **DB load** — auto-save la fiecare submit pas = +5 UPDATE-uri per wizard completat. Negligibil la scara LexRecovery
- **JSON snapshot schema drift** — dacă schimb Step1CreditorData structura, drafturile vechi devin incompatibile. Mitigare: versionare schema (`snapshot_version: int`) + migrare lazy la load

---

## 4. Recomandare #2 — Progressive disclosure PF/PJ

### Problema

Step 1 (creditor) și Step 2 (debitor) afișează **toate** câmpurile indiferent de tipul de persoană selectat (PF vs PJ). Asta înseamnă:

- Pentru **persoana fizică**, user-ul vede câmpuri irelevante: `CUI`, `nrRegCom`, `denumire societate`, `administrator`
- Pentru **persoana juridică**, user-ul vede câmpuri irelevante: `CNP`, `nume + prenume` (deși există un singur câmp `name` actualmente)
- Câmpurile irelevante ajung valide cu valori goale → utilizatorul nu știe care e cerut

### Soluția

Stimulus controller `person-type-toggle_controller.js` care:

1. Listen pe schimbarea radio-button `personType`
2. La PF → hide `.field--pj-only` (`cui`, `onrcNumber`, `administrator`)
3. La PJ → hide `.field--pf-only` (`personalId`)
4. Animație simplă cu `transition: opacity 200ms`

Pentru robustețe **server-side** (cineva trimite POST cu DevTools), `Step1CreditorType` + `Step2DebtorEntryType` adaugă listener `FormEvents::PRE_SET_DATA`:

```php
$builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
    $data = $event->getData();
    if (($data['personType'] ?? null) === 'PF') {
        // Curăță câmpurile PJ-only — sunt ignorate, NU eroare
        $data['cui'] = null;
        $data['onrcNumber'] = null;
        $data['administrator'] = null;
    }
    $event->setData($data);
});
```

### Effort estimat

| Componentă | Effort |
|---|---|
| Stimulus controller `person-type-toggle_controller.js` | 30min |
| Update `_step1_creditor_content.html.twig` cu clase `.field--pj-only` / `.field--pf-only` | 30min |
| Update `_step2_debtor_content.html.twig` idem | 30min |
| PRE_SUBMIT listener pe Step1CreditorType + Step2DebtorEntryType | 1h |
| Tests Form pentru PRE_SUBMIT (1 caz per tip) | 30min |
| **TOTAL** | **~3h** |

### Beneficii

- UX mai curat — utilizatorul vede DOAR câmpurile relevante
- Mai puține erori de validare (CUI invalid pentru PF dispare)
- Pattern reutilizabil pentru viitor (ex: alte enumuri condiționale)

---

## 5. Recomandare #3 — Confirm modal Step 4

### Problema

Step 4 prezintă summary cu 4 card-uri (Creditor + Debitor + Claim + Documente) + checkbox-uri de acord, apoi buton „Salvează dosar" mare verde. Click pe buton = persistă imediat în DB + redirect la dashboard.

Risc: **submit accidental** — user-ul click „Continuă" din obișnuință, sau dublu-click, sau modifică ceva → submit fără confirmare. Drepturile produse (LegalCase + Debtor[] + Document attachments + AuditLog) sunt non-trivial de reversed.

### Soluția

Preline modal `hs-overlay` care apare la click „Salvează dosar":

```
┌─────────────────────────────────────────────┐
│  Confirmă crearea dosarului                 │
│                                             │
│  Creditor: ACME Lawyers SRL                 │
│  Debitor: SC Datornic SRL (CUI 14186770)   │
│  Sumă: 47.500,00 RON + dobândă 1.245,00   │
│  Taxă timbru: 200,00 RON                    │
│                                             │
│  După confirmare, dosarul va fi creat și    │
│  primit număr unic.                         │
│                                             │
│  [Anulează]            [Confirmă crearea]   │
└─────────────────────────────────────────────┘
```

Buton „Confirmă crearea" în modal = submit real al formularului.

### Effort estimat

| Componentă | Effort |
|---|---|
| Markup Preline modal în `_step4_confirmation_content.html.twig` | 30min |
| Wire data-attribute trigger + form submit handler | 30min |
| Tests: confirm modal apare + submit funcționează prin modal | 30min |
| **TOTAL** | **~1.5h** |

### Beneficii

- Previne submit accidental (dosar persistat + audit log + rate limit consumat)
- Recap final = double-check vizual ultim
- Pattern standard SaaS (Stripe „Create payment", GitHub „Delete repository")

---

## 6. Mitigări temporare pentru session bag (zero effort architecture)

Dacă **NU** facem DB drafts încă, putem reduce 80% din anxietatea user-ului cu 30min effort:

### 6.1. Extinde session lifetime la 24h

`config/packages/framework.yaml`:
```yaml
framework:
    session:
        cookie_lifetime: 86400        # 24h în loc de „browser session"
        gc_maxlifetime: 86400         # garbage collect peste 24h, nu peste 2h
```

**Cost**: 5min. **Beneficiu**: dacă închizi laptopul accidental și revii a doua zi, wizardul te așteaptă.

### 6.2. Auto-save toast flash pe GET render

În `CaseWizardController::creditor()` / `debtor()` / `claim()`:
```php
if (!empty($bag['creditor']) || !empty($bag['debtors']) || !empty($bag['claim'])) {
    $this->addFlash('info', 'wizard.flash.progress_saved');
}
```

Cu i18n: `"Progresul tău e salvat — poți reveni oricând."`

**Cost**: 15min. **Beneficiu**: feedback vizual continuu — user-ul știe că nu pierde lucrul.

### 6.3. Banner pe dashboard „Dosar în lucru"

Dashboard verifică dacă session bag conține date și afișează:
```
┌─────────────────────────────────────────────────┐
│ 🔄 Ai un dosar în lucru                         │
│ Pasul 2 din 4 · Debitor                         │
│ [Continuă →]                                    │
└─────────────────────────────────────────────────┘
```

**Cost**: 30min (DashboardController + partial template). **Beneficiu**: recovery natural când user-ul nu-și amintește că are wizard pe parcurs.

### Trade-off vs DB drafts

| Caracteristică | Session bag + mitigări | DB drafts |
|---|---|---|
| Effort | 30 min | 12h |
| Pierdere la logout | ❌ Da (chiar și cu 24h cookie) | ✅ Nu |
| Cross-device | ❌ Nu | ✅ Da |
| Share cu coleg | ❌ Nu | ✅ Da |
| Dashboard drafturi | ⚠️ Doar pentru session activ | ✅ Toate drafturile |
| Email reminder | ❌ Nu | ✅ Da |
| Wizard analytics | ❌ Nu | ✅ Da |
| GDPR audit trail | ⚠️ Limitat | ✅ Complete |

Mitigările cresc UX-ul de la 80% → 85% cu 30min. DB drafts de la 85% → 95% cu 12h.

---

## 7. Improvements post-MVP advance

Listate pentru completitudine, **NU recomandate pentru next 2 sprints**:

- **Optimistic UI submit Step 4** — preview „Dosarul tău se creează..." cu animation, în paralel cu POST → dacă fail, rollback vizual. Effort: 2-3h.
- **Wizard analytics** — track abandonment rate per pas (Step 1: 80% → Step 2: 65% → ...). Necesită eveniment tracking (PostHog, Plausible, custom). Effort: 4-8h.
- **Email reminder draft abandonat** — la 24h-72h-7zile după last_accessed_at fără submit, email cu deep link. Effort: 2-3h (cu DB drafts existente).
- **Multi-user collaboration** — avocat senior review draft → comment threads. Effort: săptămâni. Post-MVP advanced.
- **Browser tab persistence indicator** — vizibil în tab title „⚠️ Dosar nesalvat" când user-ul are wizard în progres. Effort: 1h Stimulus.
- **Keyboard shortcuts** — Ctrl+S = save current step, Ctrl+→ = next step. Effort: 2h.

---

## 8. Roadmap propus

```
Sprint actual (Pas 3.x finalizare)
├── Pas 3.3.1 (1-2h)
│   ├── Lacuna B din `MULTI-DEBITOR-OP-2026-05-13.md`: warning amber count ≥ 2
│   └── Mitigări session bag punctele 6.1 + 6.2 + 6.3
└── Verificare git commit + merge

Sprint Faza 4 (1-2 săptămâni)
├── Pas 4.0 — Pagina overview dosar (3-4 zile)
├── Pas 4.1 — DeadlineService (0.5 zi)
├── Pas 4.2 — Subscribers workflow (0.5 zi)
└── Pas 4.3 — UI tab Termene (0.5 zi)

Sprint Faza 5-6 (1-2 săptămâni)
├── Faza 5 — Generare PDF (somație + cerere OP)
└── Faza 6 — Generare ZIP + depunere portal

MVP COMPLETE — produs funcțional end-to-end

Sprint îmbunătățiri UX (post primii 5-10 utilizatori beta)
├── Pas 7.x — DB drafts migration (1.5-2 zile) — recomandarea #1
├── Pas 7.y — Progressive disclosure PF/PJ (3h) — recomandarea #2
├── Pas 7.z — Confirm modal Step 4 (1.5h) — recomandarea #3
├── Pas 7.w — Lacunele juridice multi-debitor C + D (vezi MULTI-DEBITOR-OP-2026-05-13.md)
└── Mobile responsive optimization + A11y audit
```

**Principiu**: livrăm valoare end-to-end (cerere OP completă către instanță) ÎNAINTE să optimizăm experiența wizardului. Wizard fluid + complet funcțional > wizard cu DB drafts dar fără output PDF.

---

## 9. Decizie pentru owner

Trei opțiuni de proceed acum:

### Opțiunea A — Conservator (recomandat)
- Aplicăm **mitigările temporare** (6.1 + 6.2 + 6.3) — 30min
- Aplicăm **Lacuna B juridică** multi-debitor warning — 30min
- Sărim direct la **Faza 4** (overview dosar)
- DB drafts + alte îmbunătățiri → după MVP funcțional

### Opțiunea B — Investiție early în UX
- Aplicăm **DB drafts migration** acum — 1.5-2 zile
- Apoi Faza 4
- Pierdere de 2 zile pe Faza 4, dar arhitectura mai solidă pentru viitor

### Opțiunea C — Concomitent
- Sprint paralel: dev1 face DB drafts + dev2 face Faza 4
- Necesită 2 oameni — overhead coordonare. Probabil neaplicabil în context one-dev.

**Recomandare**: **Opțiunea A**. Argumentele:
1. Session bag funcționează — nu blocăm MVP
2. Faza 4-6 livrează valoarea principală (PDF + portal) — fără ele, produsul e doar formular
3. Feedback real de la avocați-utilizatori va prioritiza corect (auzim „am pierdut dosar la logout" → urgent; nu auzim → confirm session bag e suficient)
4. DB drafts făcut post-utilizatori = mai precis cu requirements (poate cere features pe care nu le-am anticipat — gen status custom „aproape gata", „așteaptă review")

---

## 10. Cross-references

- `PLAN-DEZVOLTARE-LEXRECOVERY.md` — sursa de adevăr pentru pași, status DONE/TODO
- `analize-juridice/MULTI-DEBITOR-OP-2026-05-13.md` — lacunele juridice ce trebuie adresate cuplat cu Recomandarea #2 (progressive disclosure va trebui combinat cu validarea temeiului coparticipării)
- `ANALIZA-FLUXURI-LEXRECOVERY.md` — fluxurile complete, inclusiv wizard
- `PLAN-PAS-4-0-OVERVIEW-DOSAR.md` — pasul critic imediat după 3.x
- `MEMORY.md` — index proiect cu state-ul actual

---

## 11. Concluzie

Wizardul actual e **arhitectural corect** pentru un produs B2B legal în faza MVP. Toate îmbunătățirile identificate (DB drafts + progressive disclosure + confirm modal + lacunele juridice multi-debitor) sunt **clasice „make it right" post „make it work"**.

**Prioritatea acum**: livrăm produs funcțional end-to-end (cerere OP creată + somație PDF + depunere portal). Wizardul actual e suficient pentru primii utilizatori beta. Improvements arhitecturale după ce produsul livrează valoare.

**Single-line take-home**: arhitectura curentă e la 80%, fix-urile triviale o aduc la 85%, DB drafts o aduc la 95% — DAR niciuna nu e prerequisite pentru MVP. Faza 4-6 sunt prerequisite.
