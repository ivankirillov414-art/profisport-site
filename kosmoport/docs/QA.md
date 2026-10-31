# QA of the integrated Kosmoport CRM

Only local disposable data is used. No real scanner, payment/fiscal system, printer, production login or InfinityFree protection claim follows from these tests.

## Safety and commands

- `powershell -File tools/verify-environment.ps1` probes PHP without modifying its global configuration. Override `KCRM_QA_PHP` for another runtime.
- `node --test tests/e2e/harness.test.mjs` verifies test-target safety guards.
- In a sandbox that blocks Node worker spawning, use `node --test --test-isolation=none tests/e2e/harness.test.mjs` instead; these execute the same guard assertions.
- `node tools/verify-local-db.mjs` starts/reuses the checksum-verified portable MariaDB in the ignored local runtime. It binds only loopback and installs no service. Connection credentials stay in `.runtime/local-db.json`.
- Set `NODE_PATH` to the available Playwright installation, then `node tools/verify-ui-errors.mjs` runs separately labelled UI fault-injection cases; `node tools/verify-crm.mjs` creates a fresh database/config, starts actual PHP, runs real API/data/document/website/backup tests and real browser sections, and stops its PHP processes. Each section reports independently so a partner form defect does not hide the ticket results.
- `node tools/verify-php.mjs` records syntax results and source SHA256 values; it does not claim PHP-version/host compatibility beyond the runtime actually used.
- The integration client refuses non-loopback URLs, implicit ports, URL credentials and fixtures without the `KCRM_QA_DISPOSABLE_V1` marker. All databases must be named `kcrmqa_*`.
- `tests/e2e/.runtime/` contains local runtimes, credentials and disposable databases; never deploy or package it. `tests/e2e/artifacts/` contains fixture-only screenshots/results.
- The test router mounts actual application files at `/kosmoport/`. It does not replace business API handlers.

## Acceptance evidence to collect

1. One-time-key setup: wrong key, CSRF, actual owner creation, setup closure/reuse rejection.
2. Anonymous session, login/logout/session rotation, rejected CSRF, server role boundaries.
3. Partner and task creation/readback, input errors and escaped content.
4. Tickets: check does not consume; manual adult-payment confirmation is required; admission, idempotent retry, duplicate attempt, authorized reversal and statistics grouped at Asia/Yekaterinburg day boundaries. Distribution source must survive import/export.
5. Documents: genuine validated upload, immutable new version, authorized binary download, denied anonymous/unauthorized access and traversal/type errors.
6. Website: draft is not public, expected-revision conflict, authorized publish, published actual content.
7. Backup and restore: full database/document data into a separate empty disposable target, checksum/content comparison, refusal of nonempty/wrong targets and corrupt archives.
8. Browser at 390/1024/1366 px: readable forms/actions, no document-level horizontal overflow; loading, empty, unauthorized, network/API error and retry states. Real browser-to-API-to-database evidence is separate from UI error-injection fixtures.

Statuses: **PASS** actually executed; **FAIL** reproduced defect; **BLOCKED** required implementation/environment unavailable; **NOT RUN** outside this pass. Missing modules and fake backend responses cannot count as integrated PASS.

Current execution results and exact defect reproduction steps live in `handoffs/qa.md` and generated fixture-only reports.

## Local runtime lifecycle and evidence

The official MariaDB11.4.13 Windows ZIP used for QA has SHA256 `d62986d433eeebfde218560b276103831604a61e929e87f1a17f5aebd80257e2`. ZIP installation without a service follows the [MariaDB instructions](https://mariadb.com/docs/server/server-management/install-and-upgrade-mariadb/installing-mariadb/binary-packages/installing-mariadb-windows-zip-packages). The binary, databases and credentials are local test tooling, not application dependencies or release assets.

PHP is loaded with its own `ext` directory and explicit `pdo_mysql,pdo_sqlite,fileinfo,openssl,mbstring,zip`; global PHP configuration is unchanged. The current executed runtime is PHP8.5.11 / MariaDB11.4.13. An actual PHP8.2/shared-host runtime, InfinityFree browser verification and production private-storage denial remain separate checks.

The local server may be reused by the coordinator during integration. After **all contributors stop using it**, `tools/verify-stop-db.php` may be run with PDO MySQL enabled: it checks the loopback marker, exact QA-owned datadir and database allowlist before issuing a graceful shutdown; no files/databases are deleted. Do not stop another contributor's server or delete an unverified computed path.

Reports: `tests/e2e/artifacts/integrated.json`, `ui-errors.json`, `php-lint.json`; screenshots `integrated-<width>-<section>.png`. Private fixture configs/credentials and backup ZIPs stay under `.runtime/runs/<id>/`. Historical failed runs are retained with numbered report names. A corrected test expectation is distinguished from a product fix in the handoff.

## Final executed snapshot

Final results: [QA-RESULTS.md](QA-RESULTS.md), exact source identities: [QA-SOURCE-HASHES.json](QA-SOURCE-HASHES.json). The final fresh run has59/59 integrated PASS; existing release ZIP remains stale. Staff management, actual owner-only maintenance wrapper and coordinator security fixes are included. Existing bundled Playwright is loaded automatically; KCRM_QA_PLAYWRIGHT can override its path. Use node tools/verify-results.mjs only after a completed integration report and current archive/preflight reports. Historical error screenshots are not the final fixture.

