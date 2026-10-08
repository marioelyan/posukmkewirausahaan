#!/usr/bin/env bash
# Devcontainer post-create: install dependencies and prepare the app for local dev.
set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> composer install"
composer install --no-interaction --prefer-dist

echo "==> preparing .env"
if [ ! -f .env ]; then
  cp .env.example .env
fi

set_env() { # key value
  if grep -q "^${1}=" .env; then
    sed -i "s|^${1}=.*|${1}=${2}|" .env
  else
    printf '%s=%s\n' "${1}" "${2}" >> .env
  fi
}
set_env DB_CONNECTION mysql
set_env DB_HOST db
set_env DB_PORT 3306
set_env DB_DATABASE point_of_sales
set_env DB_USERNAME pos
set_env DB_PASSWORD secret
set_env PUPPETEER_SKIP_DOWNLOAD true

php artisan key:generate --force >/dev/null 2>&1 || true

echo "==> waiting for MySQL (db:3306)"
for _ in $(seq 1 60); do
  if php -r 'exit(@fsockopen("db", 3306) ? 0 : 1);'; then
    break
  fi
  sleep 2
done

echo "==> migrate + seed"
php artisan migrate --seed --force

echo "==> storage link"
php artisan storage:link >/dev/null 2>&1 || true

echo "==> npm install (skip Puppeteer Chromium download)"
PUPPETEER_SKIP_DOWNLOAD=true npm install

echo
echo "Setup selesai. Jalankan aplikasi dengan:"
echo "  composer run dev      # Laravel :8000 + queue + logs + Vite :5173"
echo "Lalu buka tab Ports -> 8000 (pertama kali diarahkan ke /setup)."
