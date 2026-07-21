# Stack demo izolat + tunel Cloudflare cu URL stabil

Scop: avocatul testează aplicația pe branch-ul `lexrecovery` printr-un link
public constant, în timp ce tu lucrezi în paralel pe alte branch-uri, cu alte
migrări și alt cod, fără să strici demo-ul.

Totul rulează de pe Mac-ul local. Nu e nevoie de server: `cloudflared` deschide
o conexiune de ieșire spre Cloudflare, iar Cloudflare rutează traficul public
înapoi prin ea. Condiția e ca Mac-ul să fie pornit, treaz (scriptul pornește
`caffeinate`) și conectat la internet.

## Cum e izolat

| | Dev (munca ta) | Demo (avocatul) |
|---|---|---|
| Cod | `~/Downloads/myprojects/symfony-mvp`, orice branch | `~/Downloads/myprojects/symfony-mvp-demo`, git worktree detached pe `lexrecovery` |
| Proiect compose | `symfony-mvp` | `lexdemo` |
| Containere | `symfony-mvp-*` | `lexdemo-*` |
| Bază de date | volum `symfony-mvp_database_data` | volum `lexdemo_database_data` |
| Vendor | volum propriu | volum propriu |
| Porturi | 8080 / 8025 / 3307 / 3000 | 8090 / 8035 / 3317 |
| Mercure | container separat pe :3000 | proxy same-origin prin nginx, pe `/.well-known/mercure` |

Cele două stack-uri nu împart nimic: nici fișiere, nici schema bazei de date,
nici vendor. Un `doctrine:migrations:diff` sau un `composer require` pe branch-ul
tău de lucru nu atinge demo-ul.

Worktree-ul demo este intenționat **detached**: rămâne fix pe commit-ul pe care
l-ai pus, chiar dacă tu comiți mai departe pe `lexrecovery`. Îl muți explicit cu
`make demo-sync`.

## Configurare inițială

```bash
cp .env.demo.dist .env.demo     # completează hostname-urile publice
make demo-setup                 # creează worktree, build, composer, migrări, seed
```

`make demo-setup` durează câteva minute prima dată (imagine + `composer install`
în volum nou). La final aplicația demo răspunde pe http://localhost:8090, iar
Mailpit-ul ei pe http://localhost:8035.

## Tunel quick (fără domeniu, modul implicit)

Cât timp `DEMO_TUNNEL_NAME` este gol în `.env.demo`, `make demo-expose` pornește
un quick tunnel Cloudflare:

```bash
make demo-up
make demo-expose
```

Scriptul citește URL-ul `*.trycloudflare.com` din output-ul `cloudflared`, îl
scrie în `.env.demo` și recreează containerele `php`, `worker` și `mercure`, ca
`APP_BASE_URL`, `MERCURE_PUBLIC_URL` și `cors_origins` să corespundă adresei
publice. Fără pasul acesta, link-urile din emailuri ar duce spre `localhost`, iar
fluxul de extracție nu ar mai primi actualizări live.

Link-ul apare în terminal, într-un chenar. Se schimbă la fiecare repornire a
tunelului, deci trebuie retrimis avocatului.

Cu `DEMO_EXPOSE_MAIL=true` se deschide un al doilea tunel spre Mailpit, ca
testerul să poată deschide emailurile de confirmare cont și de resetare parolă.
Mailpit arată **toate** mesajele aplicației, inclusiv link-uri care permit
preluarea oricărui cont, deci UI-ul lui e protejat cu basic auth din
`DEMO_MAIL_AUTH` (`user:parolă`). Ambele valori se dau avocatului odată cu
link-ul.

## Tunel cu domeniu propriu (URL stabil)

Precondiție: un domeniu adăugat în contul Cloudflare (nameserverele domeniului
mutate la Cloudflare, plan Free e suficient). Cloudflare Registrar vinde `.com`
și zona se configurează automat, dar nu vinde `.ro`: pentru `.ro` cumperi de la
un registrar acreditat ROTLD, apoi în Cloudflare faci **Add a site** și muți
nameserverele la cele indicate acolo.

```bash
brew install cloudflared

# 1. Autentificare: se deschide browserul, alegi zona (domeniul).
#    Salvează un certificat în ~/.cloudflared/cert.pem
cloudflared tunnel login

# 2. Creează tunelul (o singură dată). Salvează credențialele
#    în ~/.cloudflared/<UUID>.json
cloudflared tunnel create lexdemo

# 3. Rutează subdomeniile spre tunel (creează automat recordurile DNS CNAME)
cloudflared tunnel route dns lexdemo demo.domeniul-tau.ro
cloudflared tunnel route dns lexdemo mail.demo.domeniul-tau.ro
```

Apoi în `.env.demo`:

```
DEMO_PUBLIC_URL=https://demo.domeniul-tau.ro
DEMO_MAIL_HOST=mail.demo.domeniul-tau.ro
DEMO_TUNNEL_NAME=lexdemo
```

`DEMO_PUBLIC_URL` ajunge în `APP_BASE_URL`, `DEFAULT_URI`, `MERCURE_PUBLIC_URL`
și în `cors_origins` al hub-ului Mercure, deci după ce îl schimbi trebuie
repornit stack-ul demo:

```bash
make demo-down && make demo-up
make demo-expose        # pornește tunelul, link-ul rămâne același la restart
```

Certificatul HTTPS îl emite Cloudflare automat pentru subdomeniu, nu trebuie
nimic local.

### Protejarea demo-ului

Aplicația e publică pe internet cât timp rulează tunelul. Opțional, în
dashboard-ul Cloudflare (Zero Trust > Access > Applications) poți adăuga o
politică de tip one-time PIN pe adresa de email a avocatului, așa încât doar el
să poată deschide link-ul. Mailpit-ul, în special, expune tot ce trimite
aplicația și merită fie o politică Access, fie lăsat doar local
(`DEMO_MAIL_HOST=` gol).

## Operare zilnică

```bash
make demo-sync          # demo-ul sare pe vârful curent al lui lexrecovery + migrări + reload
make demo-logs          # logurile stack-ului demo
make demo-up / down     # pornire / oprire
make demo-db-copy       # copiază o dată baza de date de dev peste cea demo
./scripts/demo.sh exec php php bin/console <comandă>
```

`make demo-sync` rulează pe worktree-ul demo: `checkout --detach lexrecovery`,
`composer install`, migrări, build Tailwind, `cache:clear` și restart la `php` +
`worker` (OPcache nu vede fișierele noi fără restart).

Atenție: worktree-ul demo vede doar ce e **comis** pe `lexrecovery`. Modificările
necomise din checkout-ul tău principal nu ajung în demo.

## Note

- `APP_ENV` pentru demo se controlează cu `DEMO_APP_ENV` din `.env.demo`. Pe
  `dev` apare bara de debug Symfony și profilerul este accesibil public. Pentru
  o demonstrație către un client extern, `prod` e varianta corectă.
- Cele două stack-uri rulează simultan: MySQL, PHP-FPM, worker și Mercure de
  două ori. Contează în RAM pe un Mac cu 16 GB; dacă e strâmt, oprește stack-ul
  de dev cât timp nu lucrezi.
- `make expose` (quick tunnel, URL aleatoriu) rămâne pentru stack-ul de dev;
  `make demo-expose` e pentru stack-ul demo cu URL fix.
