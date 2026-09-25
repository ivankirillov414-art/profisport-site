# ProfiSport — production readiness

This file is a technical checklist for moving the store from the temporary InfinityFree environment to a normal PHP/MySQL host without changing store logic.

## Runtime requirements

- PHP 8.1+ with PDO MySQL, mbstring, JSON and sessions.
- MySQL 8.0+ or compatible MariaDB.
- HTTPS enabled before customer/admin sessions are used.
- Writable directories only where imports/uploads require them; application PHP source should remain read-only to the web process where possible.
- `server/config.php` must be created from `server/config.example.php` on the destination and must not be committed.
- Database and application timezone: Asia/Yekaterinburg / +05:00 unless the business requirements change.

## Pre-migration checks

1. `Store checks` GitHub Action must pass for both fresh and legacy schemas.
2. `Catalog quality audit` must complete successfully.
3. `Autonomous photo autofill` must remain conservative: no SKU/article matching, no candidate below the configured confidence threshold.
4. Verify `api/health.php` returns `ok: true` and a plausible active catalog count on the destination host.
5. Verify the automatic 1C watcher on the current host: a changed server-side export must become `current_1c_snapshot`; an unchanged snapshot must be a no-op.
6. Test customer registration/login, favorites, checkout, order creation, admin status updates and customer order history.
7. Verify product prices and stock are read server-side during checkout; browser values must never be trusted.

## Data migration

- Export/import the MySQL database with UTF-8 (`utf8mb4`).
- Preserve product IDs so favorites, reviews and order item references remain valid.
- Preserve `source_id` / `source_hash` so the next 1C import updates existing rows rather than creating duplicates.
- Copy required import/upload image directories. The built-in migration engine includes runtime files, `/import` and `/uploads` while excluding Git/test-only material.
- Take a database backup immediately before DNS cutover.

## Security

- Generate a new strong admin password and import token for production.
- Do not reuse temporary hosting/database credentials.
- Keep `server/config.php` outside version control and protected by the server configuration.
- HTTPS is mandatory because admin/customer cookies are `Secure` and `HttpOnly`.
- Keep CSRF checks enabled for customer and admin writes.
- Do not expose database/admin credentials to browser JavaScript.

## Automatic hosting migration

The owner can open **Admin → Перенос сайта**, enter the new host's FTPS and MySQL credentials, run a preflight, choose a delay, and confirm the migration with the current admin password. Credentials are AES-256-GCM encrypted on the source server, never returned to the browser after scheduling, and are erased from the migration record after a successful transfer.

The destination database must be empty. The worker copies schema/data in resumable chunks, copies runtime files over FTPS, writes the destination server config, verifies table row counts, and optionally checks the destination HTTPS health endpoint. Failed jobs can be retried from their saved cursor. DNS switching remains a separate registrar action.

## Cutover

1. Deploy the exact tested `main` commit.
2. Configure DB credentials and import token.
3. Import DB and required image files.
4. Open `api/health.php` and verify health.
5. Smoke-test catalog, product page, checkout, profile and admin orders.
6. Point the final domain/DNS to the new host.
7. Only after the final domain is known, add canonical URLs, robots/sitemap host URLs and production analytics.
8. Keep the old host available briefly for rollback, but do not accept parallel orders on two databases.

## Catalog source policy

- The current 1C/MySQL export is the only storefront catalog source of truth.
- The server checks automatically for a changed 1C snapshot; unchanged snapshots are no-ops.
- Zero-stock products and products absent from the current snapshot are hidden.
- Product photos come only from the current source data. Missing photos are intentionally left missing.
- The old `velo56-parser` pipeline and fallback photo research are retired and must not be reintroduced as storefront fallbacks.

## Backups and monitoring

- Daily automated database backup, with at least one off-host copy.
- Regular backup of uploaded/imported images that are not reproduced from the repository.
- Monitor `api/health.php` and HTTP 5xx errors.
- Retain application/PHP error logs without exposing them publicly.
- Before major catalog/import changes, take an extra database snapshot.

## Deliberately not hard-coded yet

The repository must not invent business rules that have not been approved. In particular, production work should not hard-code arbitrary bonus accrual rates, warranty promises, assembly/first-service promises, payment methods, return rules, or final-domain SEO URLs until those rules are explicitly confirmed.
