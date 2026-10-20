#!/usr/bin/env bash
set -euo pipefail

TARGET="${1:?previous release directory is required}"
BASE=/var/www/celeone-backend
if [[ ! -d "$TARGET" ]]; then
  echo "Release not found: $TARGET" >&2
  exit 2
fi

CURRENT="/var/www/celeone-backend-rollback-$(date +%Y%m%d%H%M%S)"
mv "$BASE" "$CURRENT"
ln -s "$TARGET" "$BASE"
systemctl daemon-reload
systemctl restart celeone-socket.service celeone-worker.service celeone-outbox.service
systemctl reload nginx
echo "Rolled back to $TARGET"
