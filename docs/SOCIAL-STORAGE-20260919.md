# Social and Laravel storage fixes — 19 September 2026

## Mobile

- `src/backend/firestoreCompat.ts` sends `filter[]` / `or[]`, preserving all constraints in Laravel. The server also accepts repeated legacy keys.
- Admin screens and creator review use the Laravel session. `AppAccessContext` takes the administrative role from the Laravel user, not an unrelated profile row.
- `scripts/patch-modal.cjs` makes obsolete interaction handles optional for react-native-modal 14 rc on React Native 0.87. `patch-native-deps.cjs` also adapts the compressor to AGP 9 built-in Kotlin. React Native configuration runs these patches reproducibly.
- `src/media/optimizeUpload.ts` uses react-native-compressor 2.0.3 for native video/image and PCM/MP3 audio optimization. It measures the result and retains the smaller asset. Already encoded AAC/Opus and animated GIFs retain their original format.
- `src/chat/upload.ts` uses Laravel authentication, upload sessions, bounded native file transfers, and completion verification. No Firebase authentication or storage fallback remains in this path.
- Status, social, Cele Vibes, admin posts/banners, and admin films/music use the shared uploader. Social media preparation runs sequentially to limit memory use.
- Page follow state/counts come from the transactional API. Follow changes refresh recommendations. The creator-page bell now persists opt-in state; unfollow disables it.
- Friends discovery has pagination, country/place filters, mutual-friend filtering, region-first ordering, and a See all entry from the feed rail.

## Laravel endpoints

| Endpoint | Purpose |
| --- | --- |
| `POST /api/v1/auth/confirm-password` | Reauthenticate the current Laravel admin for protected actions |
| `PUT /api/v1/pages/{id}/follow` | Idempotent follow, SQL/compatibility mirrors and count in one transaction |
| `DELETE /api/v1/pages/{id}/follow` | Unfollow and disable page alerts |
| `PUT /api/v1/pages/{id}/notifications` | Save a follower's explicit notification preference |
| `GET /api/v1/friends/discover` | `page`, `limit`, `country`, `location`, `q`, `mutual` filters |
| `POST /api/v1/uploads/presign` | Authenticated upload session; local disk uses expiring signed URL |
| `PUT /api/v1/uploads/{id}/content` | Signed local storage transfer; 8 MiB chunks use `X-Upload-Offset` |
| `POST /api/v1/uploads/{id}/complete` | Verify actual file size before completion |

The VPS local disk stores media under Laravel's shared `storage/app/public/uploads/{user}/...`. Nginx serves `/storage/` as static content with `nosniff`, without script execution. Chunking stays below the existing proxy request limit. Maximum declared asset size is 1 GiB; media optimization occurs on the device first.

`SocialCounts` reconciles distinct legacy/SQL comments and mirrors counts into feed documents and SQL metrics. A repair pass updated 121 existing posts and 8 page follower counts.

`NotifyPageFollowers` queues first public page publications, checks current follow and explicit opt-in, creates a deterministic inbox record, and calls the existing notification service. The internal service route requires a private shared key and is blocked at the public Nginx route. Delivery retries reuse the notification ID; Android notification tags reduce duplicate display. FCM may still deliver at least once across a worker failure between sending and recording completion.

## Validation

- Live PostgreSQL rollback probes passed follow/unfollow idempotence, counts, bell persistence/rejection, page ownership checks, repeated filters, mixed SQL/legacy comments and deletion, location/mutual friend discovery, public profile field selection, and page-alert deduplication with mocked push transport.
- Public HTTPS probes passed Laravel admin authentication, upload session creation, chunk transfer, incomplete-file rejection, completion, public retrieval, and invalid-signature rejection. Synthetic accounts/files were removed.
- Follow-up HTTPS probes also passed password confirmation, wrong-password rejection, and status publication/readback directly in Laravel after deployment of timezone-aware storage timestamps.
- Notification inbox reads combine Laravel page alerts with the existing notification service; read/delete actions update both stores.
- Mobile uploader tests passed compression-before-transfer, measured reduction, chunk offsets, preserving AAC recordings, no completion after transfer failure, and Laravel-session enforcement without Firebase imports.
- Changed TypeScript syntax and PHP lint passed. The full repository still has pre-existing TypeScript errors.
- Final APK/AAB build, release signatures, matching bundled JavaScript, feature contents, and all 27 arm64 libraries' 16 KB alignment passed. A separate test-signed copy cold-launched on the connected phone and loaded the native compressor successfully. Real device media compression and push display remain untested end to end. See `RELEASE-1.0.13.md` for artifact hashes.

VPS backups: `/root/releases/social-fix-20260919/`. Google Play publication is deferred; deliver APK and AAB only.

For this Windows machine, Gradle's new dependency downloads require the antivirus inspection CA already trusted by Windows. A copy of the Java trust store with that CA is kept only in the local audit directory and passed explicitly to this build. TLS validation remains enabled; no system trust configuration was changed.

The reproducible native dependency patch uses AGP 9 built-in Kotlin and explicitly registers the compressor's generated Kotlin source directory. It also patches the modal dependency's obsolete InteractionManager handles for the current React Native runtime.
