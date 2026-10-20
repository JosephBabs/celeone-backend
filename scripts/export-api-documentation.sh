#!/usr/bin/env bash
# Run from the authoritative backend checkout after composer install.
set -euo pipefail
source_dir="$(pwd -P)"
test -f "$source_dir/artisan"
work_dir="$(mktemp -d /tmp/celeone-api-docs.XXXXXXXX)"
chmod 700 "$work_dir"
trap 'rm -rf -- "$work_dir"' EXIT
for entry in app bootstrap config database routes resources artisan composer.json composer.lock; do cp -a "$source_dir/$entry" "$work_dir/"; done
find "$work_dir/bootstrap/cache" -maxdepth 1 -type f -name '*.php' -delete
if [ -f "$source_dir/.env" ]; then cp "$source_dir/.env" "$work_dir/.env"; chmod 600 "$work_dir/.env"; fi
cp -a "$source_dir/vendor" "$work_dir/vendor"
mkdir -p "$work_dir/public" "$work_dir/storage/logs" "$work_dir/storage/framework/cache/data" "$work_dir/storage/framework/sessions" "$work_dir/storage/framework/views"
php "$source_dir/scripts/format-documentation-source.php" "$work_dir"
cd "$work_dir"
# Schema introspection only. PostgreSQL rejects writes from this process.
PGOPTIONS='-c default_transaction_read_only=on' php artisan scramble:export --path=public/raw-openapi.json > export.log 2>&1 || true
test -s public/raw-openapi.json
php artisan route:list --json > routes.json
python3 "$source_dir/scripts/complete-openapi.py" public/raw-openapi.json routes.json public/openapi.json
cp public/openapi.json "$source_dir/public/openapi.json.next"
mv "$source_dir/public/openapi.json.next" "$source_dir/public/openapi.json"

