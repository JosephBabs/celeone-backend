# VPS Firebase synchronization

The `celeone-firebase-sync.service` systemd service reads Firebase Auth accounts and the Firestore root collections `users`, `user_data`, `status`, and `posts` from `celeone-e5843`. It imports new or changed records into PostgreSQL, then waits 60 seconds before the next cycle. Firestore is read-only; the exporter paginates the source collections and the importer skips unchanged records and newer VPS documents. It does not propagate source deletions or recursively synchronize subcollections.

The service-account credential is stored at `/etc/celeone/firebase-service-account.json` (root:www-data, mode 640), outside application releases. Never package it in a release archive. The runtime dependency `google-auth-library` is included in `package.json` and `package-lock.json`.

Exports are written atomically to the configured `FIREBASE_SYNC_FILE` and `FIREBASE_AUTH_FILE` paths. The source-count report is `storage/app/firebase-targeted.ndjson.report.json`. Account password hashes are excluded. PostgreSQL document, snapshot, and ledger changes commit together for each document; failed exports never trigger an import of stale files.

After a code deployment, install the checked-in unit if necessary and restart the process to load updated PHP code:

```sh
sudo install -m 644 deploy/systemd/celeone-firebase-sync.service /etc/systemd/system/celeone-firebase-sync.service
sudo systemctl daemon-reload
sudo systemctl enable celeone-firebase-sync
sudo systemctl restart celeone-firebase-sync
sudo systemctl status celeone-firebase-sync --no-pager
sudo journalctl -u celeone-firebase-sync -n 30 --no-pager
```

A successful cycle logs account creation/update/skip counts, document import/skip counts, and `Firebase sync completed at ...`. An unchanged cycle should report zero imported documents and skip the source records. The unit automatically restarts on failure and starts after a VPS reboot.

Deployment verification on 2026-09-18 fetched 270 Auth accounts, 100 user documents, 252 user profiles, 106 statuses, and 120 posts. The first account import created eight missing accounts. A subsequent unchanged cycle skipped all 270 accounts and 578 Firestore documents. The pre-fix database and code backups are under `/root/releases/firebase-sync-fix/`.

Regression tests use an isolated in-memory SQLite database:

```sh
php vendor/bin/phpunit tests/Feature/FirebaseIncrementalImportTest.php
```
