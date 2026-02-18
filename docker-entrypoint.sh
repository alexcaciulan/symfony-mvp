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

# Run migrations
echo "Running migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

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

# Execute the main command
exec "$@"
