#!/bin/sh
set -e

cd /var/www/html

if [ ! -f .env ]; then
  cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
  php artisan key:generate --force
fi

DB_PATH="${DB_DATABASE:-/data/database.sqlite}"
mkdir -p "$(dirname "$DB_PATH")" storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
touch "$DB_PATH"

php artisan migrate --force
php artisan db:seed --force

role="${1:-app}"

if [ "$role" = "queue" ]; then
  echo "Starting queue worker..."
  exec php artisan queue:work --sleep=1 --tries=3 --timeout=300
fi

echo "Starting HTTP server on :8000..."
exec php artisan serve --host=0.0.0.0 --port=8000
