# Cèlè One production backend overlay

This archive is an overlay for the complete Laravel project. The uploaded source did not include `composer.json`, `artisan`, `bootstrap/`, `public/`, `vendor/`, or tests, and both `storage/app/firebase-export.ndjson` and the Firebase Auth export were absent/empty. Merge this tree into the complete application before deployment.

## Required Composer packages

Install compatible current versions of `laravel/sanctum`, `predis/predis` (or phpredis), and the production storage driver. The code uses Laravel HTTP, Redis, queues, PostgreSQL JSONB, Sanctum and session authentication.

## Deployment order

1. Back up PostgreSQL, Firebase exports, Firebase Auth export, storage and `.env`.
2. Merge this overlay and configure `.env.production.example` values.
3. Run `composer install --no-dev --optimize-autoloader` and `npm ci`.
4. Run `php artisan optimize:clear`, then `php artisan migrate --force`.
5. Export Firebase Auth to `storage/app/firebase-auth.ndjson` and Firestore to `storage/app/firebase-export.ndjson`.
6. Run `php artisan celeone:import-firebase-auth`.
7. Run `php artisan firebase:import` and `php artisan celeone:migration-status`.
8. Confirm imported admin users have `admin` or `super_admin`; they can sign into `/admin/login` with the same Firebase email/password while Firebase Auth compatibility remains enabled.
9. Run `npm run socket:start` and `php artisan celeone:outbox:publish`; then install the supplied systemd units.
10. Install the Nginx configuration, certificates, reload Nginx, and verify `/api/v1`, `/admin`, `/socket`, and socket health.

Do not decommission Firebase until source/import counts and checksums reconcile and the new mobile release has passed canary rollout.
