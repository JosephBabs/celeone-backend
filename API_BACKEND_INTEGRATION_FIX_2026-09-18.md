# Cèlè One — API / Backend / Social integration fix
Date: 2026-09-18

## Fixed root causes

### Firestore compatibility bridge
- Fixed PostgreSQL write lookup using `collection_path` / `document_id` instead of non-existent camelCase columns.
- Added Firestore transforms: `increment`, `arrayUnion`, `arrayRemove`, `serverTimestamp`, `deleteField`.
- Added dotted-path updates such as `metrics.comments` and `metrics.reactions`.
- Added query operators used by the mobile app: `array-contains`, `array-contains-any`, `in`, `not-in`, OR filters, booleans, document IDs.
- Fixed compatibility pagination offset.
- Reduced compatibility polling for chat/live/comment paths and added Firestore-like snapshot metadata/docChanges.

This directly affects Status, Social posts, Page posts, friends, groups, admin updates, metrics and old Firebase-imported data.

### Posts / comments / reactions
- Dedicated comment API supports SQL posts and imported compatibility posts.
- Social comments/replies now use `/posts/{id}/comments` as source of truth.
- Comment edit/delete/reaction endpoints support imported comments too.
- Post reactions now use `/posts/{id}/reaction`; server returns authoritative counters.
- Page-as-actor reactions are supported, including unreact.
- Post API can show/update/delete imported posts and mirrors SQL posts into the compatibility feed.

### Admin mobile
- Firebase token exchange now preserves/synchronizes `admin` / `super_admin` from existing user data/custom claims.
- API errors expose actual backend validation/authorization messages.
- Generic admin resource updates now persist correctly because the compatibility write layer is fixed.

### Upload API
- Unified mobile/backend field names for presign.
- Added compatible `upload_url`, `id/upload_id`, completion payloads.
- Added missing GET `/uploads/url` and DELETE `/uploads` routes used by storageCompat.

### Social live
- Starting a live session now creates both the domain SQL session and the PostgreSQL compatibility mirror consumed by the Social discovery UI.
- Ending a live updates the mirror status to `ended`.
- Host identity/playback metadata is preserved.
- Live config lookup supports `social_config/live` and legacy `social_configs/live`.
- Existing live comments/viewers/reactions now benefit from fixed transforms and faster polling.

### Messaging
- Direct chat provisioning aligns the same `dm_<uids>` public ID across local SQLite, XMPP, REST API and socket backend.
- Backend direct-room creation accepts Firebase UID as well as numeric user IDs.
- `client_id` is an opaque idempotency string, no longer incorrectly restricted to RFC UUID.
- Chat V2 sends XMPP realtime first; Firestore/API persistence runs in parallel instead of blocking the realtime packet.
- REST message persistence is added as a durable parallel mirror.
- Socket messages use public conversation IDs, include recipient IDs and emit directly to `private-user.<id>` rooms.
- Redis outbox message events are converted to `msg:new` with a short dedupe window.

## Deployment notes
1. Deploy the backend code and run the application's normal Laravel deployment sequence (`composer install` if needed, migrations, route/config cache refresh).
2. Restart the socket service after replacing `socket/server.mjs`.
3. Deploy/rebuild the mobile app with the mobile ZIP changes.
4. Existing imported posts/statuses/pages remain supported; no forced content rewrite is required by this patch.

## Validation performed
- PHP syntax lint: 83 backend PHP files, no syntax errors.
- Socket server: `node --check`, passed.
- Modified mobile TS/TSX files: 0 TypeScript transpile diagnostics.
- Full runtime integration tests were not possible from the supplied archives because the backend has no `vendor/` directory and the mobile export has no root `package.json`/native build project.
