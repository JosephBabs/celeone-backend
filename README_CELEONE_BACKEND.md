# CeleOne Laravel backend

This project is the PostgreSQL/API/admin backend for the CeleOne mobile app.

## Data migration contract

The mobile app currently uses Firebase project `celeone-e5843`. The schema inventory is in `config/firebase_collections.php`.

Export Firebase documents as newline-delimited JSON with this shape:

```json
{"collectionPath":"users","documentId":"firebaseUid","parentDocumentPath":null,"data":{"email":"..."},"createTime":null,"updateTime":null}
```

Place the file at `storage/app/firebase-export.ndjson`, then run:

```bash
php artisan migrate
php artisan firebase:import
php artisan storage:link
```

To create that export directly from the Firebase CLI session:

```powershell
firebase login --reauth
$env:NODE_TLS_REJECT_UNAUTHORIZED = '0' # only if local Avast TLS inspection blocks Node
node tools/firebase-export.mjs celeone-e5843 storage/app/firebase-export.ndjson
php artisan firebase:import
```

The export script is read-only against Firebase and writes only the local NDJSON file.

Known users, pages, and posts are normalized into relational tables. Every other document is retained in `firebase_documents.payload` so no legacy collection is discarded.

## API

- `POST /api/auth/google` validates a native Google ID token and returns a Sanctum token.
- `GET /api/posts` uses cursor pagination.
- `POST /api/media` stores uploads under `storage/app/public/uploads`.
- `/api/admin/*` exposes the protected management API for users, posts, dashboard counts, and schema inventory.

Set `GOOGLE_CLIENT_ID_MOBILE` and PostgreSQL credentials in `.env` before running the API.

This workstation's PostgreSQL 18 instance is configured on port `5433`. pgAdmin is available at `http://127.0.0.1:5052`.
