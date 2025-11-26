# Docker Setup pentru Symfony MVP

Această aplicație rulează complet în containere Docker pentru un environment de dezvoltare consistent.

## Servicii Disponibile

- **Application (Nginx + PHP-FPM)**: http://localhost:8080
- **Mailpit UI** (pentru testarea email-urilor): http://localhost:8025
- **MySQL Database**: localhost:3307 (mapped to port 3307 to avoid conflict with local MySQL)

## Quick Start

```bash
# Setup complet (build, start, install dependencies, run migrations)
make setup

# Accesează aplicația la http://localhost:8080
```

## Comenzi Comune

### Gestionare Containere

```bash
make up              # Pornește toate containerele
make down            # Oprește toate containerele
make restart         # Restart toate containerele
make logs            # Vezi loguri de la toate containerele
make logs-php        # Vezi doar loguri PHP
make logs-nginx      # Vezi doar loguri Nginx
make logs-db         # Vezi doar loguri MySQL
```

### Development

```bash
make shell           # Intră în containerul PHP (shell interactiv)
make composer        # Rulează composer install
make composer-update # Rulează composer update
make test            # Rulează PHPUnit tests
make cache-clear     # Șterge cache-ul Symfony
```

### Database

```bash
make migrate         # Rulează migrările database
make migrate-diff    # Generează migrare din modificările entităților
make shell-db        # Intră în MySQL shell
```

### Cleanup

```bash
make clean           # Oprește containerele și șterge volumele
```

## Structura Docker

### Containere

1. **symfony-mvp-php**:
   - PHP 8.4-FPM Alpine
   - Toate extensiile necesare (pdo_mysql, intl, zip, opcache, apcu)
   - Composer instalat
   - Rulează `docker-entrypoint.sh` la pornire (instalează dependencies, migrări, cache)

2. **symfony-mvp-nginx**:
   - Nginx Alpine
   - Configurare optimizată pentru Symfony
   - Servește aplicația pe portul 8080

3. **symfony-mvp-db**:
   - MySQL 8.0
   - Database: `symfony_mvp`
   - User: `app` / Password: `app_password`
   - Root password: `root_password`

4. **symfony-mvp-mailpit**:
   - Mailpit pentru capturarea email-urilor
   - SMTP port: 1025
   - Web UI: http://localhost:8025

### Fișiere Importante

- `Dockerfile`: Configurarea containerului PHP
- `compose.yaml`: Configurarea principală Docker Compose
- `compose.override.yaml`: Override-uri pentru development (port mappings)
- `docker-entrypoint.sh`: Script de inițializare (așteaptă DB, rulează migrări)
- `docker/nginx/default.conf`: Configurare Nginx
- `Makefile`: Comenzi convenabile

### Volume

- **Cod aplicație**: mounted ca volum pentru live editing
- **vendor**: volum named pentru performance mai bună
- **database_data**: persistă datele MySQL

## Variabile de Environment

Configurate în `compose.yaml` și `.env`:

- `APP_ENV`: `dev` (default)
- `DATABASE_URL`: Auto-configurat să folosească serviciul `database`
- `MAILER_DSN`: Auto-configurat să folosească serviciul `mailer`

Poți override aceste valori în `.env.local` (care nu este committat).

## Comenzi Docker Manuale

Dacă preferi să nu folosești Makefile:

```bash
# Start
docker compose up -d

# Stop
docker compose down

# Logs
docker compose logs -f

# Shell în PHP container
docker compose exec php sh

# Rulează comenzi Symfony
docker compose exec php php bin/console <command>

# Rulează Composer
docker compose exec php composer install

# Rulează tests
docker compose exec php ./bin/phpunit
```

## Troubleshooting

### Containerele nu pornesc

```bash
# Verifică statusul
docker compose ps

# Verifică logurile
docker compose logs
```

### Database connection errors

```bash
# Verifică că database container rulează
docker compose ps database

# Verifică logurile database
docker compose logs database

# Restart database container
docker compose restart database
```

### Permission errors

```bash
# Setează permisiunile corecte pentru var/
docker compose exec php chown -R www-data:www-data /var/www/html/var
```

### Rebuild după modificări în Dockerfile

```bash
# Rebuild PHP container
docker compose build php

# Sau rebuild toate
docker compose build

# Apoi restart
docker compose up -d
```

## Development Workflow

1. **Pornește containerele**: `make up`
2. **Fă modificări în cod** - schimbările sunt live datorită volumelor mounted
3. **Rulează comenzi Symfony**: `docker compose exec php php bin/console <command>`
4. **Vezi loguri**: `make logs`
5. **Rulează teste**: `make test`
6. **Oprește containerele**: `make down`

## Production Notes

Pentru production, ar trebui să:

1. Folosești un `Dockerfile` separat de production (fără volumele mounted)
2. Optimizezi Composer cu `--no-dev --optimize-autoloader`
3. Setezi `APP_ENV=prod`
4. Folosești secrets pentru passwords în loc de variabile de environment
5. Configurezi un reverse proxy (nginx) cu SSL
6. Folosești un managed database service