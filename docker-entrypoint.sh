#!/bin/sh
set -e

# Install composer dependencies if vendor doesn't exist
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "Installing composer dependencies..."
    composer install --no-interaction --optimize-autoloader
    echo "Composer installation completed!"
else
    echo "Composer dependencies already installed"
fi

# Verify Symfony console is available
if [ ! -f "bin/console" ]; then
    echo "ERROR: bin/console not found!"
    exit 1
fi

# Wait for database to be ready using a simpler method first
echo "Waiting for database to be ready..."
max_attempts=30
attempt=0

# First, wait for MySQL port to be open
until nc -z database 3306 2>/dev/null; do
    attempt=$((attempt + 1))
    if [ $attempt -gt $max_attempts ]; then
        echo "Database port 3306 is not reachable after $max_attempts attempts"
        exit 1
    fi
    echo "Waiting for database port (attempt $attempt/$max_attempts)..."
    sleep 2
done

echo "Database port is open, waiting for MySQL to be ready..."
sleep 5

# Now try Doctrine connection
attempt=0
until php bin/console dbal:run-sql "SELECT 1" > /dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ $attempt -gt 10 ]; then
        echo "Database connection failed after $attempt attempts"
        echo "DATABASE_URL: $DATABASE_URL"
        echo "Trying to debug..."
        php bin/console dbal:run-sql "SELECT 1" || true
        exit 1
    fi
    echo "Database not ready, retrying (attempt $attempt/10)..."
    sleep 2
done

echo "Database is up!"

# Wait for Mercure hub (best-effort — don't fail boot if unavailable, just warn)
echo "Waiting for Mercure hub..."
mercure_attempts=0
until wget -q --spider "http://mercure/.well-known/mercure?topic=ping" 2>/dev/null; do
    mercure_attempts=$((mercure_attempts + 1))
    if [ $mercure_attempts -gt 30 ]; then
        echo "WARNING: Mercure hub not reachable after 30 attempts — push notifications will not work."
        break
    fi
    sleep 1
done
if [ $mercure_attempts -le 30 ]; then
    echo "Mercure hub is up!"
fi

# Run migrations
echo "Running migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Load baseline fixtures (InterestRateConfig + Plan)
echo "Loading baseline fixtures..."
php bin/console doctrine:fixtures:load --no-interaction --append --group=baseline

# Import courts data
echo "Importing courts..."
php bin/console app:import-courts --no-interaction

# Create test users + demo cases (only in dev)
if [ "$APP_ENV" = "dev" ]; then
    echo "Creating test users..."
    php bin/console app:create-test-users --no-interaction

    echo "Seeding demo cases..."
    php bin/console app:seed-demo-cases --no-interaction

    # Dense BNR exchange rates for the current year so the FX freshness guard
    # accepts recent invoice dates in dev. Best-effort: offline start still works
    # (the sparse baseline fixtures remain as anchors).
    echo "Importing BNR exchange rates (current year, best-effort)..."
    php bin/console app:import-exchange-rates --year="$(date +%Y)" 2>/dev/null || echo "  BNR rate import skipped (offline or unavailable)"

    # Run migrations on test database for PHPUnit
    echo "Running test database migrations..."
    APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration 2>/dev/null || true

    echo "Loading baseline fixtures on test DB..."
    APP_ENV=test php bin/console doctrine:fixtures:load --no-interaction --append --group=baseline 2>/dev/null || true
fi

# Clear cache
echo "Clearing cache..."
php bin/console cache:clear --no-warmup
php bin/console cache:warmup

# Install assets
echo "Installing assets..."
php bin/console assets:install public
php bin/console importmap:install

# Build Tailwind CSS
echo "Building Tailwind CSS..."
php bin/console tailwind:build

# Set correct permissions
chown -R www-data:www-data /var/www/html/var

echo "Application is ready!"

# NOTE: cron jobs are NOT started here. `app:import-exchange-rates` (06:00),
# `app:check-deadlines` (07:00) and `app:portal-check-all` (08:00) are registered
# as scheduled jobs in Coolify. The Messenger worker (compose `worker`) consumes
# the `CheckCasePortalMessage` items dispatched by `app:portal-check-all`.

# Execute the main command
exec "$@"
