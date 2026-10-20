#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${1:-/var/www/celeone-api}"
FIX_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WEB_CLIENT_ID="${GOOGLE_WEB_CLIENT_ID:-275960060318-t160nsbie7s4oinm9ok7efpp050iifq1.apps.googleusercontent.com}"

if [[ ! -f "$APP_DIR/artisan" ]]; then
  echo "Laravel artisan not found at: $APP_DIR"
  echo "Usage: bash DEPLOY_BACKEND.sh /actual/path/to/laravel"
  exit 1
fi

cd "$APP_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="storage/app/deploy-backups/google-auth-$STAMP"
mkdir -p "$BACKUP/Auth" "$BACKUP/routes" "$BACKUP/config"
cp -a app/Http/Controllers/Auth/GoogleController.php "$BACKUP/Auth/" 2>/dev/null || true
cp -a routes/api.php "$BACKUP/routes/api.php"
cp -a config/services.php "$BACKUP/config/services.php"

cp -a "$FIX_DIR/app/Http/Controllers/Auth/GoogleController.php" app/Http/Controllers/Auth/GoogleController.php
cp -a "$FIX_DIR/routes/api.php" routes/api.php
cp -a "$FIX_DIR/config/services.php" config/services.php

if ! composer show google/apiclient >/dev/null 2>&1; then
  composer require google/apiclient:^2.19 --no-interaction --prefer-dist
fi

if grep -q '^GOOGLE_WEB_CLIENT_ID=' .env; then
  sed -i "s#^GOOGLE_WEB_CLIENT_ID=.*#GOOGLE_WEB_CLIENT_ID=$WEB_CLIENT_ID#" .env
else
  printf '\nGOOGLE_WEB_CLIENT_ID=%s\n' "$WEB_CLIENT_ID" >> .env
fi

php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan route:list --path=api/v1/auth | grep -E 'auth/google|auth/logout|auth/login' || true

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

echo
echo "Backend files deployed. Backup: $APP_DIR/$BACKUP"
echo "Now reload your installed PHP-FPM service and nginx."
echo "Example: sudo systemctl reload php8.3-fpm && sudo nginx -t && sudo systemctl reload nginx"
