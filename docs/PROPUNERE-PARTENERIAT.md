# RecuperareCreanțe — Analiză aport și propunere parteneriat

## 1. Situația actuală

Platforma **RecuperareCreanțe** este o aplicație web funcțională (MVP) care digitalizează procedura de recuperare a creanțelor cu valoare redusă (OUG 80/2013). Aplicația permite creditorilor să depună cereri online, să plătească taxele judiciare digital și să urmărească dosarele în timp real.

**Stadiu actual:** MVP funcțional, testat, pregătit pentru lansare. Rămân 2 pași tehnici (securitate avansată + deploy în producție).

---

## 2. Aportul tehnic — Dezvoltator (Alex)

### 2.1 Ce s-a construit concret

| Categorie | Detalii cantitative |
|-----------|-------------------|
| **Entități de date** | 9 entități (User, LegalCase, Court, Document, Payment, CaseStatusHistory, AuditLog, Notification, ResetPasswordRequest) |
| **Controllere** | 11 controllere cu ~30 rute (wizard, plăți, documente, admin, auth, profil) |
| **Formulare** | 11 form types (wizard 6 pași + auth + profil) |
| **Templates** | 30+ template-uri Twig (UI complet: homepage, wizard, dashboard, admin, auth) |
| **Servicii** | 3 servicii business (calcul taxe, generare PDF, workflow management) |
| **Teste automate** | 97 teste în 13 fișiere (controllers, services, voters, commands) |
| **Traduceri** | ~200 chei de traducere în 2 limbi (RO + EN) |
| **Componente JS** | 5 controllere Stimulus (dropdown cascade, câmpuri condiționale, colecții dinamice) |
| **Comenzi CLI** | 2 comenzi (import instanțe, creare utilizatori test) |
| **Migrări DB** | 6 migrări de bază de date |

### 2.2 Complexitate tehnică

Aplicația nu este un simplu site web. Include:

- **State machine** cu 9 statusuri și 9 tranziții (Symfony Workflow) pentru ciclul de viață al unui dosar
- **Generare PDF** conformă cu formularul oficial Anexa 1 (art. 1028 CPC) — DomPDF cu template dedicat
- **Sistem de autorizare granulară** — Symfony Voter care verifică proprietatea dosarului + status curent
- **Calcul automat taxe judiciare** conform OUG 80/2013 (formule progresive pe tranșe)
- **Upload/download documente** cu validare tip, dimensiune, protecție CSRF
- **Audit logging** complet pe toate acțiunile din sistem
- **Admin panel** complet (EasyAdmin) cu dashboard statistici, management dosare, schimbare statusuri
- **Protecție brute-force** pe login (rate limiting)
- **Sistem forgot/reset password** cu token-uri securizate
- **Infrastructură Docker** completă (4 containere: PHP-FPM, Nginx, MySQL, Mailpit)
- **Internaționalzare** completă (RO/EN cu switch de limbă)

### 2.3 Stack tehnic

| Componentă | Tehnologie |
|-----------|-----------|
| Backend | PHP 8.4 + Symfony 7.3 |
| Bază de date | MySQL 8.0 + Doctrine ORM |
| Frontend | Tailwind CSS v4 + Stimulus + Turbo |
| Admin | EasyAdmin 4.29 |
| PDF | DomPDF 3.x |
| Containerizare | Docker Compose (4 servicii) |
| Teste | PHPUnit 12.x (configurare strictă) |

### 2.4 Ore investite

**Estimare: 100-200 ore** de muncă directă, incluzând:
- Cercetare și planificare arhitectură
- Configurare proiect și infrastructură Docker
- Dezvoltare backend (controllere, entități, servicii, workflow)
- Dezvoltare frontend (templates, Tailwind, Stimulus controllers)
- Sistem de autentificare complet
- Wizard cerere 6 pași cu validări complexe
- Generare PDF (Anexa 1 oficială)
- Admin panel cu statistici și management dosare
- Scriere teste (97 teste)
- Debugging, optimizare, refactoring
- Documentație tehnică

### 2.5 Valoare de piață a muncii prestate

| Scenariu | Tarif orar | Ore | Valoare totală |
|----------|-----------|-----|---------------|
| Junior developer RO | 25 EUR/h | 150h | 3.750 EUR |
| Mid developer RO | 40 EUR/h | 150h | 6.000 EUR |
| Senior developer RO | 60 EUR/h | 150h | 9.000 EUR |
| Freelancer extern | 80 EUR/h | 150h | 12.000 EUR |
| Agenție software | 100+ EUR/h | 150h | 15.000+ EUR |

**Observație:** Un MVP de complexitate similară comandat la o agenție ar costa **10.000-20.000 EUR** și ar dura 2-4 luni cu o echipă de 2-3 persoane.

---

## 3. Aportul non-tehnic — Partener (Avocat)

### 3.1 Contribuție concretă până acum

| Contribuție | Status |
|------------|--------|
| Ideea de business | Da — a propus conceptul |
| Investiție financiară | Nu |
| Documente juridice pentru aplicație | Nu |
| Clienți sau contracte aduse | Nu |
| Timp investit în dezvoltare | Nu |
| Consultanță juridică activă | Nu (formularul oficial e public) |

### 3.2 Ce ar putea aduce în viitor

| Potențial | Valoare reală | Observații |
|-----------|--------------|-----------|
| Consultanță juridică | Medie | Verificare conformitate procedurală, actualizări legislative |
| Rețea de avocați | Limitată | Aplicația e B2C (retail), nu B2B; avocații nu sunt publicul țintă principal |
| Credibilitate juridică | Medie | Unui brand juridic îi conferă încredere prezența unui avocat |
| Relații cu instanțe | Scăzută | Instanțele nu decid dacă un creditor folosește platforma |
| Dezvoltare business | Depinde | Doar dacă se implică activ în operațiuni, parteneriate, vânzări |

### 3.3 Observație importantă

**O idee fără execuție are valoare limitată.** În industria tech, ideile sunt abundente — ceea ce contează este execuția. MVP-ul funcțional există exclusiv datorită muncii de dezvoltare. Fără dezvoltator, ideea rămâne o idee. Fără ideea aceasta specifică, dezvoltatorul poate construi orice altă platformă.

---

## 4. Responsabilități viitoare

### 4.1 Ce face dezvoltatorul (Alex) — continuu

| Responsabilitate | Frecvență | Înlocuibil? |
|-----------------|-----------|-------------|
| Dezvoltare funcționalități noi | Săptămânal | Greu (cost ridicat) |
| Mentenanță și bug fixing | Continuu | Greu |
| Securitate și actualizări | Lunar | Greu |
| DevOps: deploy, monitoring, backup | Continuu | Greu |
| Integrare plăți reale (Netopia) | One-time | Greu |
| Scalare infrastructură | La nevoie | Greu |
| Buget marketing (împărțit 50/50) | Lunar | Investiție financiară comună |
| Hosting și servicii (împărțit 50/50) | Lunar | ~50-100 EUR/lună total |

### 4.2 Ce ar face partenerul (Avocat) — potențial

| Responsabilitate | Frecvență | Înlocuibil? |
|-----------------|-----------|-------------|
| Verificare conformitate juridică | Ocazional | Mediu (alt avocat) |
| Actualizări legislative (OUG, CPC) | Rar | Mediu |
| Dezvoltare parteneriate | La nevoie | Mediu |
| Suport clienți pe aspecte juridice | La nevoie | Mediu |

### 4.3 Analiza dependenței

- **Fără dezvoltator:** Proiectul se oprește. Nu există produs, nu există venituri.
- **Fără partenerul avocat:** Proiectul continuă. Conformitatea juridică se verifică cu orice avocat. Formularul Anexa 1 este public.

---

## 5. Propunere împărțire părți sociale

### 5.1 Factori de evaluare

| Factor | Dezvoltator (Alex) | Partener (Avocat) |
|--------|-------------------|-------------------|
| Execuție tehnică (MVP construit) | 100% | 0% |
| Investiție timp (100-200h) | 100% | 0% |
| Investiție financiară curentă | 0% | 0% |
| Investiție financiară viitoare (marketing, hosting) | 50% | 50% |
| Idee de business | Contribuție parțială (rafinare) | Contribuție inițială |
| Expertiză juridică | 0% | 100% |
| Mentenanță și dezvoltare viitoare | 100% | 0% |
| Risc asumat (timp + bani) | Ridicat | Scăzut |

### 5.2 Propunere recomandată

| Asociat | Părți sociale | Justificare |
|---------|--------------|-------------|
| **Dezvoltator (Alex)** | **70-75%** | A construit MVP-ul singur (100-200h), asigură mentenanță, dezvoltare și operare continuă. Este singurul indispensabil. Costurile post-lansare se împart 50/50. |
| **Partener (Avocat)** | **25-30%** | A propus ideea inițială, poate aduce consultanță juridică și credibilitate. Contribuie 50/50 la costuri post-lansare. Contribuția viitoare depinde de implicarea activă. |

### 5.3 De ce nu 50/50?

Un split egal ar fi echitabil doar dacă:
- Ambii parteneri ar fi investit timp comparabil (nu e cazul: 150h vs 0h)
- Ambii ar fi investit bani comparabili (post-lansare da, dar pre-lansare: 150h muncă neplătită vs 0h)
- Ambii ar avea roluri indispensabile pe termen lung (dezvoltatorul e indispensabil; consultanța juridică se externalizează ușor)
- Ideea ar fi brevetabilă sau unică (procedura OUG 80/2013 este publică, oricine poate construi o platformă similară)

### 5.4 Mecanism de protecție: Vesting

Se recomandă un **vesting schedule** pentru ambii asociați:

| Parametru | Detalii |
|-----------|---------|
| **Perioadă vesting** | 4 ani |
| **Cliff** | 1 an (dacă un asociat pleacă în primul an, pierde tot) |
| **Vesting lunar** | După cliff, părțile sociale se câștigă lunar (1/48 pe lună) |
| **Accelerare** | La exit/vânzare, toate părțile devin imediat vested |

**De ce contează:** Dacă partenerul avocat nu se implică activ după lansare (nu aduce clienți, nu oferă consultanță, nu contribuie la dezvoltare business), acesta nu ar trebui să dețină 25-30% pentru o simplă idee inițială.

---

## 6. Recomandări structurale

### 6.1 Contract de asociere — puncte esențiale

- **Roluri clare:** CTO/dezvoltator vs. consilier juridic/business development
- **Responsabilități concrete** cu KPIs măsurabili (nu "ajută când poate")
- **Investiții financiare:** costuri post-lansare împărțite 50/50 (hosting, marketing, domeniu, certificări)
- **Clauze de exit:** ce se întâmplă dacă un asociat vrea să plece
- **Proprietatea intelectuală:** codul sursă rămâne în companie, nu la dezvoltator individual
- **Clauză de non-compete:** ambii asociați se angajează să nu creeze platforme concurente
- **Dilution:** ce se întâmplă la o rundă de investiții sau la aducerea unui nou asociat

### 6.2 Condiții pentru partenerul avocat

Pentru a justifica 25-30%, partenerul ar trebui să se angajeze la:
- Verificare juridică trimestrială (conformitate, actualizări legislative)
- Minim 5-10 ore/lună implicate în proiect (consultanță, business development)
- Participare la decizii strategice
- Dezvoltarea de parteneriate cu birouri de avocatură (canal de distribuție)
- Contribuție la conținut juridic (articole, FAQ, ghiduri pentru utilizatori)

### 6.3 Scenarii alternative

| Scenariu | Împărțire | Când se aplică |
|----------|-----------|---------------|
| Partener activ (10+ ore/lună, aduce clienți) | 70% / 30% | Implicare constantă verificabilă |
| Partener semi-activ (2-5 ore/lună, consultanță) | 75% / 25% | Consultanță ocazională |
| Partener pasiv (doar ideea, fără implicare) | 80-85% / 15-20% | Nicio implicare după lansare |

---

## 7. Rezumat

| Aspect | Dezvoltator (Alex) | Partener (Avocat) |
|--------|-------------------|-------------------|
| **Ce a făcut** | MVP complet: 97 teste, 9 entități, 11 controllere, wizard 6 pași, PDF, admin, auth, Docker | A propus ideea |
| **Ce va face** | Dezvoltare, mentenanță, DevOps, marketing (buget propriu), hosting | Consultanță juridică, potențial business dev |
| **Ore investite** | 100-200h (valoare 6.000-15.000 EUR) | ~0h |
| **Bani investiți** | 0 EUR (post-lansare: 50/50 cu partenerul) | 0 EUR (post-lansare: 50/50 cu Alex) |
| **Indispensabil?** | Da — fără el nu există produs | Nu — consultanța se externalizează |
| **Propunere %** | **70-75%** | **25-30%** |

---

*Document generat pe baza analizei tehnice a codului sursă al platformei RecuperareCreanțe — Februarie 2026*
