# Android 1.0.13 (version code 16)

Package: `com.celeoneapp`. Release artifacts use the existing Cele One upload signing key.

## Changes

- Five-second video-frame thumbnails for new social videos and Cele Vibes, with a short-clip fallback.
- Android thumbnail extraction and cached covers for older videos without thumbnails.
- Static feed/reel previews; hover or explicit touch interaction starts playback. Swiping to another reel leaves it paused.
- Verified page badges appear inline immediately after page names in posts, profiles, comments, and search.
- Includes the previously deployed communications, comments, feed diversity, caching, and recommendation changes.

## Build

From `android`:

```powershell
.\gradlew.bat :app:assembleRelease :app:bundleRelease --max-workers=2 '-Dorg.gradle.jvmargs=-Xmx2g -XX:MaxMetaspaceSize=768m -Dfile.encoding=UTF-8'
```

Outputs:

- APK: `android/app/build/outputs/apk/release/app-release.apk`
- AAB: `android/app/build/outputs/bundle/release/app-release.aab`

Native thumbnail compilation and changed TSX syntax checks passed. The publisher's mocked inspect, duplicate-version rejection, validation-only, commit, and mismatched-bundle cases also passed without contacting Google. Existing project-wide TypeScript errors and the previously documented Firebase App Check configuration issue remain separate limitations.

## Publishing

Google Play publishing is deferred at the user's request. Deliver signed APK and AAB builds only.

## September 19 fixes

- Laravel-only authentication and chunked VPS storage for social/status/reel/admin media uploads.
- Real native image, video and audio optimization before transfer, with measured output sizes.
- Admin role derived from Laravel; modal compatibility with React Native 0.87.
- Transactional page follows, persisted opt-in notifications, authoritative counts and feed refresh.
- Comment recount across imported and relational records; preservation of repeated compatibility filters.
- Paginated friend discovery with region priority, country/place filters and friends of friends.

## Final artifacts and validation

Final Gradle assembleRelease and bundleRelease succeeded (1569 tasks). APK and AAB have identical Hermes bundles and the expected Laravel upload, friend discovery, feed, thumbnail, and compressor features. All 27 arm64 libraries pass 16 KB ELF alignment; APK ZIP alignment passes as well.

| Artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| APK | 123755255 | `dd8d978c19dd63ab5c81fe8e56d868d48a809b01f1350e1fe349ec6449d1732b` |
| AAB | 80014470 | `7b8bc636b6cdfb33eb97b2ada8d9bb801345f9b0b000dd4135092ad310b58ee6` |

Both signatures verify with Cele One certificate SHA-256 `08f12d4829a0cc8e4af4cc169582f03ca183a10ba02ffc9215a85fc646e93c4e`. JDK 25 reports self-signed certificate and JAR streaming-order warnings for the Gradle-produced AAB; JarFile signature verification passes.

A separate debug-signed test copy updated the connected TECNO phone without clearing app data. Version 1.0.13 / code 16 cold-launched successfully, remained running, and loaded the native compressor successfully. Production artifacts retain their release signature. Actual media compression on-device, real push display, and a two-phone voice call still require end-to-end validation; no real-user publication or notification was sent during probes.
