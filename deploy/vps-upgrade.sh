#!/usr/bin/env bash
set -euo pipefail

# Usage: sudo /usr/local/sbin/celeone-upgrade /root/releases/celeone-backend.tar.gz 2026.09.17.1
ARCHIVE="${1:?archive path is required}"
VERSION="${2:?release version is required}"
if [[ ! "$VERSION" =~ ^[0-9A-Za-z._-]+$ ]]; then
  echo "Invalid release version: $VERSION" >&2
  exit 2
fi
if [[ ! -f "$ARCHIVE" ]]; then
  echo "Archive not found: $ARCHIVE" >&2
  exit 2
fi

BASE=/var/www/celeone-backend
RELEASES=/var/www/celeone-backend-releases
STAGE="$RELEASES/$VERSION"
PREVIOUS="$RELEASES/previous-$(date +%Y%m%d%H%M%S)"

install -d -m 755 "$RELEASES"
if [[ -e "$STAGE" ]]; then
  echo "Release already exists: $STAGE" >&2
  exit 3
fi
install -d -o www-data -g www-data "$STAGE"
tar -xzf "$ARCHIVE" -C "$STAGE"

if [[ -f "$BASE/.env" ]]; then
  install -m 640 -o www-data -g www-data "$BASE/.env" "$STAGE/.env"
fi
if [[ -d "$BASE/storage/app/public" ]]; then
  mkdir -p "$STAGE/storage/app"
  cp -a "$BASE/storage/app/public" "$STAGE/storage/app/"
fi
mkdir -p "$STAGE/bootstrap/cache" "$STAGE/storage/framework/cache" "$STAGE/storage/framework/sessions" "$STAGE/storage/framework/views" "$STAGE/storage/logs"
chown -R www-data:www-data "$STAGE"

cd "$STAGE"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci --omit=dev
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan about >/dev/null
chown -R www-data:www-data "$STAGE"

if [[ -L "$BASE" || -d "$BASE" ]]; then
  mv "$BASE" "$PREVIOUS"
fi
ln -s "$STAGE" "$BASE"
printf '%s\n' "$VERSION" > "$BASE/VERSION"
chown -h www-data:www-data "$BASE"

systemctl daemon-reload
install -m 644 "$BASE/deploy/systemd/celeone-firebase-sync.service" /etc/systemd/system/celeone-firebase-sync.service
systemctl daemon-reload
systemctl restart celeone-socket.service celeone-worker.service celeone-outbox.service
systemctl enable --now celeone-firebase-sync.service
systemctl restart celeone-firebase-sync.service
systemctl reload nginx
systemctl is-active --quiet celeone-socket.service
systemctl is-active --quiet celeone-worker.service
systemctl is-active --quiet celeone-outbox.service
echo "CeleOne release $VERSION is active"
echo "Previous release: $PREVIOUS"
