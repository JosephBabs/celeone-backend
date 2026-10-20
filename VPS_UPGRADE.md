  # VPS release upgrades

  The VPS runs the Laravel backend natively under PHP-FPM/Nginx and systemd. Releases are versioned and switched atomically; the PostgreSQL database is not replaced during an application upgrade.

  Create a release archive locally without secrets, `vendor`, `node_modules`, database dumps, or Firebase exports:

  ```powershell
  tar -czf CeleOne_backend_2026.09.19.1.tar.gz `
    --exclude=.git --exclude=vendor --exclude=node_modules `
    --exclude='storage/app/*.ndjson' --exclude='storage/app/*.dump' `
    --exclude='storage/app/*.sql*' --exclude='storage/logs/*' `
    -C D:\CeleOne_v1_backend .
  ```

  Upload and activate a release:

  ```powershell
  scp CeleOne_backend_2026.09.19.1.tar.gz root@169.58.111.25:/root/releases/
  ssh root@169.58.111.25 "sudo /usr/local/sbin/celeone-upgrade /root/releases/CeleOne_backend_2026.09.19.1.tar.gz 2026.09.19.1"
  ```

  The command copies the production `.env` and public uploads, installs dependencies, runs migrations, rebuilds Laravel caches, switches the release, restarts the socket/queue/outbox services, and validates their status.

  Rollback:

  ```bash
  ls -dt /var/www/celeone-backend-releases/previous-* | head
  sudo /usr/local/sbin/celeone-rollback /var/www/celeone-backend-releases/previous-YYYYMMDDHHMMSS
  ```
set SSH=root@169.58.111.25
set LARAVEL=/var/www/celeone-api
set REMOTEZIP=/root/celeone_google_auth_logout_fix_bundle.zip