# Source package module handoff

Implemented only `tools/package.php`, `deploy/*` and `tests/migration-package.php`. No live host, MySQL database, business storage, FastGo installation, Git worktree, publication or app-chat messages were changed. Backup/restore implementation remains owned by the root agent.

## Commands and prerequisites

```text
php tools/package.php --check
php tools/package.php --output build/kosmoport-source.zip
php deploy/verify-source-package.php build/kosmoport-source.zip
php tests/migration-package.php
```

PHP 8.2+ CLI is required. `--check` works without ZIP support. Actual archives require `ZipArchive`. This workstation's PHP 8.5.11 has `ext/php_zip.dll`, disabled by default. Verified DLL argument for PowerShell:

```powershell
$taskPhp = 'C:/Users/Izop300520251/Documents/ChatGPT/cfqn ghjabcgjhn/tools/php-runtime/php.exe'
& $taskPhp -d 'extension=C:/Users/Izop300520251/Documents/ChatGPT/cfqn ghjabcgjhn/tools/php-runtime/ext/php_zip.dll' tools/package.php --output build/kosmoport-source.zip
& $taskPhp -d 'extension=C:/Users/Izop300520251/Documents/ChatGPT/cfqn ghjabcgjhn/tools/php-runtime/ext/php_zip.dll' tests/migration-package.php
```

Existing ZIP or checksum sidecar files are not overwritten without `--force`. Output may be inside `build/`, which is outside the included trees. Output inside an included root/config/storage is refused before directories are created, except the explicitly excluded `deploy/artifacts/` release-handoff tree; this exception is covered by repeated-package fixtures. The builder first snapshots and checks source, creates a temporary ZIP, then promotes it and writes an atomic checksum sidecar. Limits are 32 MiB per file, 64 MiB total source, and 12,000 regular source entries.

Preflight requires nonempty `app/bootstrap.php`, `public/api/index.php`, `config/config.example.php`, at least one nonempty SQL migration, and `deploy/root.htaccess`. Missing files produce a nonzero exit and a clear required-file list. It never fills in missing application files or creates a partial release to hide an unfinished dependency.

## Package contents and security

The source allowlist is `app/`, `public/`, the exact `config/config.example.php`, `migrations/`, `bin/`, `tools/`, `docs/`, `deploy/`, and `integrations/`. Source extensions are allowlisted; SQL is accepted only under migrations; JSON is accepted as schemas, the public web manifest and the reviewed exact website source files `app/website/field-schema.json` / `default-content.json`. The exact `app/core/config.php` exception contains the programmatic loader required by bootstrap, not populated live configuration. All other `config.php` paths remain excluded. `bin/` is included for the documented CLI commands and receives independent deny rules. Unknown source types need deliberate review before extending this list; Python/PowerShell dev tools remain excluded.

Live `config.php`, environment files, credential/password/secret-named entries, private restore-target configurations, private-key material, business storage, uploads/attachments, exports, backups, archives, old/copy artifacts, databases, logs, dependency directories, Git/other hidden state, test trees and fixtures are excluded/refused. No source `storage/` content is read. The ZIP generates a denied `storage/` containing empty `documents/`, `backups/`, and `tmp/`; explicit storage directory modes are 0750, regular ZIP files 0644. Paths reject traversal, absolute/archive drive forms, backslashes, controls, reserved Windows names, trailing dots/spaces and case collisions.

Symlinks in traversed source/output paths are refused. PHP on this Windows runtime reports directory junctions as `is_link=false`, `lstat.mode=0`; the builder additionally detects this reparse case with `readlink()`. Ordinary Windows `readlink()` also returns canonical paths, so it is not used alone to classify a link. Output ancestors are checked too.

`CHECKSUMS.sha256` covers every regular ZIP member except itself, including generated protections and `SOURCE-PACKAGE.json`. A neighboring `.zip.sha256` checks the entire archive. A source package is not a business backup: it contains neither a database dump, real documents nor live connection settings.

## Deployment layout

Keep `app/`, `public/`, `config/`, `migrations/` and `storage/` as siblings. Prefer a project root outside the web document root with the document root pointed at `public/`. For InfinityFree's fixed `htdocs/`, use a new isolated `htdocs/kosmoport/` tree. Generated root rewrite rules route `/kosmoport/*` to `public/*`; if rewrite is unavailable, configure/use `/kosmoport/public/` explicitly. Never flatten `public/` and break PHP's relative bootstrap paths.

Every private top-level directory receives an independent Apache 2.4/2.2 deny `.htaccess`; these rules do not depend on rewriting. Both deny forms and the source layout are checked in fixtures. **Real HTTP denial remains unverified:** a host ignoring `.htaccess`/AllowOverride must fail the deployment acceptance checks before secrets or business documents are added. Actual configured-host checks must include private file paths and normalization/case variants. See `deploy/README.md` for the required 403/404 checks and InfinityFree browser-security limits. A PHP built-in server ignores Apache `.htaccess` and is not evidence that the shared-host protection works.

`deploy/maintenance.example.php` is disabled by default and is never copied into public by the packager. An operator may configure a temporary public loader with a trusted absolute private project path. It loads `app/bootstrap.php`, optionally defines `KCRM_BACKUP_TARGET_CONFIG` for a separate private PHP target configuration if that file exists, then loads `tools/backup-admin.php`. The root admin tool must enforce owner authentication and CSRF; the example adds no alternative authorization. An absent restore-target configuration leaves restore disabled. Delete any temporary loader after maintenance.

## Validation and limits

2026-10-31 local results: **101/101** checks passed with the matching ZIP DLL, **60/60** passed under minimal PHP without it, PHP lint passed for package/test/maintenance files, and a separately created native Windows source-root junction was explicitly refused. The ordinary `symlink()` fixture could not obtain Windows creation privileges and reports a skip. At the last integration preflight, `public/api/index.php` was still missing; the real-source package was correctly refused and no release ZIP was created.

Later integration revision: exact Core loader/public website JSON exceptions, private `bin/`, and the excluded `deploy/artifacts/` output tree are covered by **120/120 ZIP fixture checks** and **72/72 minimal-PHP checks**. Real-source preflight subsequently passed with all required files. `deploy/verify-source-package.php` performs a separate read-only actual-ZIP review of outer/member hashes, source exclusions, private rules, PHP syntax and simple static PHP require targets; dynamic config/module/maintenance loads remain explicitly listed for semantic review. The root supplied TOOLS-FINAL after reporting 42 actual-schema transfer checks with 5 migrations, 18 tables and 2 document versions. Final source snapshot target: `deploy/artifacts/kosmoport-crm-source-20261031.zip`; its external checksum and JSON review receipt belong beside the ZIP, outside the packaged source tree. Earlier partial preflight results are not a final release.

Source-package fixture checks cover missing dependencies, secret/business/artifact exclusions, required source retention, path/case rejection, private-key refusal, independent deny rules, disabled maintenance setup, output recursion/overwrite protection, actual ZIP reopening, complete member checksums, source-only metadata, empty storage and non-world-writable storage directory modes. The minimal-PHP run separately checks the clear missing-ZipArchive failure. A separate native Windows junction fixture exercises the platform-specific link refusal; PHP's ordinary `symlink()` fixture is skipped when Windows does not grant its creation privilege.

No target-server Apache, production login, live schema migration, database restore, real corporate-document recovery, printer/scanner or hosting deployment was performed by this module. Integrate the current application files, rerun `--check`, build the final source ZIP, and perform the host/restore acceptance checks in the root workflow.
