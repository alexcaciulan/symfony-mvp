# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Framework and Environment

- **Symfony 7.3** (PHP >= 8.2, Docker uses 8.4)
- **Database**: MySQL 8.0 (default in Docker), managed via Doctrine ORM
- **Admin UI**: EasyAdmin 4.27 at `/admin` route
- **Frontend**: Stimulus + Asset Mapper + Importmap (no heavy bundler)
- **Email verification**: SymfonyCasts verify-email-bundle

## Essential Commands

### Docker Setup (Recommended)

**Quick Start:**
```bash
make setup                     # Build, start containers, install dependencies, run migrations
```

**Available services:**
- Application: http://localhost:8080
- Mailpit UI (email testing): http://localhost:8025
- MySQL: localhost:3307 (mapped to avoid conflict with local MySQL)

**Common Docker commands:**
```bash
make up                        # Start all containers
make down                      # Stop all containers
make logs                      # Show all container logs
make shell                     # Access PHP container shell
make composer                  # Run composer install
make migrate                   # Run database migrations
make test                      # Run PHPUnit tests
make cache-clear               # Clear Symfony cache
```

**Manual Docker commands:**
```bash
docker compose up -d           # Start containers in background
docker compose down            # Stop containers
docker compose exec php sh     # Access PHP container
docker compose exec php php bin/console <command>  # Run Symfony commands
```

### Local Development (Without Docker)
```bash
symfony server:start           # Preferred (requires Symfony CLI)
php -S 127.0.0.1:8000 -t public # Fallback
```

### Dependencies and Setup
```bash
# With Docker:
make composer                  # or: docker compose exec php composer install

# Without Docker:
composer install               # Installs deps + runs auto-scripts (cache:clear, assets:install, importmap:install)
php bin/console cache:clear    # Clear cache manually when changing services/config
```

### Database and Migrations
```bash
# With Docker:
make migrate                   # Run migrations
make migrate-diff              # Generate migration from entity changes

# Without Docker:
php bin/console doctrine:migrations:migrate                    # Run all pending migrations
php bin/console doctrine:migrations:diff                       # Generate migration from entity changes
php bin/console make:migration                                 # Alternative to diff
php bin/console doctrine:schema:validate                       # Verify schema matches entities
```

### Testing
```bash
# With Docker:
make test                      # Run all tests

# Without Docker:
./bin/phpunit                  # Run all tests
vendor/bin/phpunit             # Alternative
./bin/phpunit tests/path/to/SpecificTest.php  # Run single test
```

**Test configuration strict rules** (in `phpunit.dist.xml`):
- `failOnDeprecation="true"` - Tests fail on any deprecation
- `failOnNotice="true"` - Tests fail on notices
- `failOnWarning="true"` - Tests fail on warnings
- `APP_ENV=test` is automatically set for tests

### Console and Code Generation
```bash
php bin/console                               # List all available commands
php bin/console make:controller               # Generate new controller
php bin/console make:entity                   # Create/update entity
php bin/console make:form                     # Generate form type
php bin/console make:crud                     # Generate CRUD controller
```

## Architecture Overview

### Service Configuration
- **Autowiring enabled**: All services in `src/` are auto-wired and auto-configured by default
- **Constructor injection**: Preferred pattern for dependencies
- **No manual service registration** required unless custom configuration is needed
- Configured in `config/services.yaml` with `_defaults: { autowire: true, autoconfigure: true }`

### Directory Structure and Responsibilities
```
src/
├── Controller/           # HTTP request handlers, extend AbstractController
│   ├── Admin/           # EasyAdmin CRUD controllers
│   │   ├── DashboardController.php  # EasyAdmin dashboard at /admin
│   │   └── UserCrudController.php   # User management CRUD
│   ├── RegistrationController.php
│   └── SecurityController.php
├── Entity/              # Doctrine ORM entities (DB models)
│   └── User.php         # Main user entity with email verification
├── Form/                # Form types (implement buildForm())
│   └── RegistrationFormType.php
├── Repository/          # Data access layer, extend ServiceEntityRepository
│   └── UserRepository.php
├── Security/            # Security helpers
│   └── EmailVerifier.php  # Email verification using symfonycasts/verify-email-bundle
└── Kernel.php           # Application kernel

config/
├── packages/            # Bundle-specific configuration
│   └── security.yaml    # Security: form_login, /admin requires IS_AUTHENTICATED_FULLY
├── routes/              # Route definitions
│   └── easyadmin.yaml   # EasyAdmin routes (auto-discovered)
└── services.yaml        # Service container configuration

migrations/              # Doctrine database migrations (versioned)
templates/               # Twig templates
tests/                   # PHPUnit tests (bootstrap: tests/bootstrap.php)
assets/                  # Frontend Stimulus controllers and styles
public/                  # Web root (index.php entry point)
```

### Authentication and Security
- **User provider**: Entity-based, using `App\Entity\User` with email as identifier
- **Login**: Form-based authentication at `/login` (`app_login` route)
- **Logout**: Configured at `/logout` (`app_logout` route)
- **Admin access**: `/admin` requires `IS_AUTHENTICATED_FULLY` role
- **Password hashing**: Auto-configured for `PasswordAuthenticatedUserInterface`
- **Email verification**: Handled via `src/Security/EmailVerifier.php` helper

### Frontend Architecture
- **Stimulus controllers**: Small JavaScript controllers in `assets/controllers/`
- **Import mapping**: Configured in `importmap.php` and `assets/controllers.json`
- **No bundler**: Uses Symfony Asset Mapper for modern JS without webpack/vite
- **Entry point**: `assets/app.js` loads Stimulus bootstrap
- After asset changes, run `php bin/console importmap:install` and `php bin/console assets:install`

### EasyAdmin Integration
- Dashboard configured in `src/Controller/Admin/DashboardController.php`
- Admin CRUD controllers in `src/Controller/Admin/` extend `AbstractCrudController`
- Route defined in `config/routes/easyadmin.yaml`
- Access at `/admin` (requires authentication)

## Code Patterns and Conventions

### Controller Pattern
```php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class ExampleController extends AbstractController
{
    public function __construct(
        private ExampleRepository $repository, // Constructor injection
    ) {}

    public function index(): Response
    {
        return $this->render('example/index.html.twig', [
            'data' => $this->repository->findAll(),
        ]);
    }
}
```

### Entity Changes Workflow
1. Modify entity class in `src/Entity/`
2. Generate migration: `php bin/console doctrine:migrations:diff`
3. Review generated migration in `migrations/`
4. Run migration: `php bin/console doctrine:migrations:migrate`
5. Commit both entity changes AND migration file

### Adding a New Service
1. Create class under `src/` (any subdirectory)
2. That's it - autowiring handles the rest
3. Inject via constructor where needed
4. Run `php bin/console cache:clear` if DI cache becomes stale

### Repository Pattern
- Extend `ServiceEntityRepository`
- Custom query methods go in repository classes
- Inject repositories via constructor, not entity manager directly

### Form Pattern
- Form types in `src/Form/` implement `buildForm()` method
- Used by controllers: `$form = $this->createForm(FormType::class, $entity)`
- Handle submission: `$form->handleRequest($request)` then `$form->isSubmitted() && $form->isValid()`

## Before Editing Code

1. **Read related files first**: Controller + Form + Entity for the feature area
2. **Entity changes**: Always generate a migration after modifying entities
3. **Run tests locally**: `./bin/phpunit` to reproduce failures before changing behavior
4. **Cache clear**: Run `php bin/console cache:clear` after config/service changes
5. **Asset changes**: Ensure `importmap:install` and `assets:install` work after template/asset changes

## Docker Architecture

The application runs in a multi-container Docker setup:

### Services
- **php**: PHP 8.4-FPM container running the Symfony application
- **nginx**: Web server serving on port 8080
- **database**: MySQL 8.0 database
- **mailer**: Mailpit for email testing (SMTP on 1025, UI on 8025)

### Key Files
- `Dockerfile`: PHP container configuration with all required extensions
- `compose.yaml`: Main Docker Compose configuration
- `compose.override.yaml`: Development-specific overrides (port mappings)
- `docker-entrypoint.sh`: Initialization script (waits for DB, runs migrations, clears cache)
- `docker/nginx/default.conf`: Nginx configuration for Symfony
- `Makefile`: Convenient commands for Docker operations

### Docker Volumes
- Application code is mounted as a volume for live editing
- `vendor` directory uses a named volume for better performance
- `database_data` persists MySQL data

### Environment Variables
The `compose.yaml` configures environment variables for the PHP container:
- `DATABASE_URL`: Auto-configured to use the `database` service
- `MAILER_DSN`: Auto-configured to use the `mailer` service
- Variables can be overridden via `.env.local`

## Environment Configuration

- `.env`: Default environment variables for Docker (committed)
- `.env.local`: Local overrides (not committed, create manually)
- `.env.test`: Test environment defaults (committed)
- **Database**: MySQL 8.0 on port 3306 (credentials in compose.yaml)
- **Messenger**: Uses Doctrine transport by default
- **Mailer**: Mailpit SMTP server for development
