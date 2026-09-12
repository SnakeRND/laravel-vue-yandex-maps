#!/bin/sh
set -e

cd /var/www/html

if [ ! -f .env ]; then
  if [ "${APP_ENV:-local}" = "production" ] && [ -f .env.production.example ]; then
    cp .env.production.example .env
  else
    cp .env.example .env
  fi
fi

if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
  php artisan key:generate --force
fi

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

echo "Waiting for PostgreSQL..."
i=0
until php -r '
$dsn = sprintf(
  "pgsql:host=%s;port=%s;dbname=%s",
  getenv("DB_HOST") ?: "db",
  getenv("DB_PORT") ?: "5432",
  getenv("DB_DATABASE") ?: "maps_reviews"
);
try {
  new PDO($dsn, getenv("DB_USERNAME") ?: "maps", getenv("DB_PASSWORD") ?: "secret");
  exit(0);
} catch (Throwable $e) {
  exit(1);
}
'; do
  i=$((i + 1))
  if [ "$i" -gt 60 ]; then
    echo "PostgreSQL is not available"
    exit 1
  fi
  sleep 1
done

php artisan migrate --force
php artisan db:seed --force

if [ "${APP_ENV:-local}" = "production" ]; then
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
fi

role="${1:-app}"

if [ "$role" = "queue" ]; then
  echo "Starting queue worker..."
  exec php artisan queue:work --sleep=1 --tries=3 --timeout=1800
fi

echo "Starting HTTP server on :8000 (APP_ENV=${APP_ENV:-local})..."
exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
