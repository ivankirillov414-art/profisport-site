# Hosting workflow handoff

`deploy/github-workflow.yml` is an inert local template. The intended repository layout is the existing `ivankirillov414-art/profisport-site` repository with this application at `kosmoport/` and the template copied to `.github/workflows/deploy-kosmoport.yml`. No GitHub workflow, secret, FTP file, hosting configuration or existing storefront workflow was changed by this handoff. A GitHub run and live hosting verification have not been performed. Import only the reviewed application subtree and narrow workflow diff into the current parent repository; never publish the stale whole `id-studio` checkout. Parent root `.htaccess` and manual-only Pages workflow need no change. The temporary `deploy/CI_SOURCE_NOTES.md` and the local-only `tools/verify-stop-db.php` shutdown helper (which needs excluded test fixtures) are excluded from server source releases.

## Activation changes in the parent repository — proposal only

Read-only inspection of the local storefront checkout found `.github/workflows/deploy-infinityfree.yml` uploading the repository root to `/htdocs/`, excluding `cms/**` but not `kosmoport/**`. Before importing the application or enabling its workflow, make these small changes together:

```diff
 # .github/workflows/deploy-infinityfree.yml
-    paths-ignore: ['cms/**', '.github/workflows/deploy-cms.yml']
+    paths-ignore: ['cms/**', '.github/workflows/deploy-cms.yml', 'kosmoport/**', '.github/workflows/deploy-kosmoport.yml', '.github/workflows/deploy-infinityfree.yml']
 concurrency:
   group: infinityfree-deploy
-  cancel-in-progress: true
+  cancel-in-progress: false
 # In the existing root FTP action's exclude list:
+            kosmoport/**
```

Then add the separate Kosmoport workflow at `.github/workflows/deploy-kosmoport.yml`. The path ignore avoids unrelated storefront runs when only this application changes; the **FTP exclude is still necessary** for mixed commits and manual storefront runs. The existing root uploader must never upload or remove the Kosmoport subtree. Do not replace its entire exclusion list with the single new rule.

The new deploy job uses the exact existing `infinityfree-deploy` concurrency group and `cancel-in-progress: false`. Updating the old storefront flag is necessary: its current `true` can cancel an active deployment in the shared group. Local CMS and motion workflows currently use `independent-cms-deploy` and `profisport-motion-hotfix`, respectively. For whole-account FTP serialization, change their FTP-writing workflow/job groups to the same `infinityfree-deploy` and `cancel-in-progress: false` too. Otherwise the new group serializes only participating workflows; it is not a server-wide lock and does not cover manual FTP clients or another repository. These are recommendations for the owner, not edits made here.

Do not import generated state. The template rejects tracked live config, environment/private credential files, runtime directories, business storage and release archives before tests. It also rejects directory segments `fixtures.private`, `private`, `runtime`, `.runtime`, `*-runtime`, `uploads`, `attachments`, `backups`, `archives`, `exports`, `cache`, `__pycache__`, and `node_modules`; document/export/database/archive extensions are denied. SQL remains limited to trusted `migrations/` and the exact synthetic `tests/core-fixture.sql`. Public source images remain admissible. Parent-repository ignore rules should additionally cover at least:

```gitignore
kosmoport/config/config.php
kosmoport/config/*.local.php
kosmoport/**/.env*
kosmoport/**/*.private.json
kosmoport/**/*.private.php
kosmoport/tests/migration-runtime/
kosmoport/tests/e2e/.runtime/
kosmoport/tests/tickets-runtime/
kosmoport/tests/migration-web-runtime/
kosmoport/tests/migration-web-profile-runtime/
kosmoport/deploy/artifacts/
kosmoport/deploy/CI_SOURCE_NOTES.md
kosmoport/build/
kosmoport/**/*.zip
kosmoport/**/*.zip.sha256
kosmoport/storage/**
```

Only deliberately reviewed inert storage protection scaffolding may be tracked as an exception. The source packager generates fresh empty protected storage regardless. Never use a real database dump, corporate document, browser session, local runtime JSON or business backup as a source fixture or Actions artifact.

## Trigger, trust and artifact boundary

Pull requests, including forks, run tests and build a source-only ZIP. There is no `pull_request_target`, `workflow_run`, manual deployment input or cross-run artifact promotion. Deploy requires all of `push`, `refs/heads/main`, the exact original repository name and successful `test_package` plus `php84`. Checkout credentials are not persisted; workflow permissions are only `contents: read`.

Only the final guarded deploy step receives `FTP_PASS: ${{ secrets.FTP_PASSWORD }}`. Tests receive no repository secrets. Its constants are `FTP_HOST=ftpupload.net`, `FTP_USER=if0_42771076`; the release helper independently locks these values and `/htdocs/kosmoport/`. No root/CMS/Kosmosfera/ProfiSport fallback target is accepted.

The deploy job downloads this same run's exact source artifact by commit-named artifact name and verifies its hash against the successful producer job's SHA256 output. It also independently reruns the member/layout/PHP-source verifier before the FTP secret is available. The test job uploads only the ZIP and its outer checksum. After a successful guarded deployment, a separate allowlisted receipt artifact retains package/remote file hashes, measured image metadata, managed source/canary path metadata, marker identity, preservation flags and the explicit `browser_privacy_verified=false`. The raw report stays in runner temp and is not uploaded; the sanitizer does not retain probe bodies, private configuration contents, runtime directories, credentials or business backups. There is no deployment/readback artifact on a PR.

## Concrete CI commands and fixture configuration

The template selects PHP 8.2 with `pdo_mysql`, `pdo_sqlite`, `zip`, `fileinfo`, `mbstring` and `dom`, and an isolated `mariadb:10.11` service on runner loopback. The public service-bootstrap password is disposable fixture material, not a hosting credential. A new test account receives a randomly generated masked password; its connection data is written with mode 0600 to job-local runtime files and exported only within the test job. Both exact migration schemas are created in that fresh service. The core test gets `KCRM_QA_DB_FILE`; document/migration fixtures get the generated `tests/migration-runtime/local-runtime.private.json` with the expected loopback names/path guard.

A separate unprivileged PHP 8.4 lane checks PHP syntax, isolated SQLite ticket/website modules and source-package fixtures. It neither starts another shared database nor uploads a competing artifact. The single tested artifact is produced by PHP 8.2; deployment waits for both compatibility lanes. The whole MySQL/HTTP suite is not duplicated or claimed for PHP 8.4.

The PHP 8.2 test/package job and the deployment job install `Pillow==12.3.0` into a job-temporary virtual environment, outside the checkout. This is the Pillow version verified in the supplied local runtime; its [public PyPI release](https://pypi.org/project/pillow/12.3.0/) was independently confirmed, including Python 3.12 support. It is not a claim that it is the newest release. The test job needs it for synthetic image fixtures; the deployment job needs it to compare measured WebP readback when the host changes compressed image bytes. The separate PHP 8.4 lane needs no Python image library. No dependency is installed on the hosting account.

All database commands are sequential because the migration tests share their two disposable fixture schemas:

```text
KCRM_CONFIG="$KCRM_CORE_UNIT_CONFIG" php tests/core-unit.php
php tests/core-mysql.php
python3 tests/documents-http.py --php "$(command -v php)" --mysql-runtime "$KCRM_CI_RUNTIME"
php tests/migration-roundtrip.php --runtime-config "$KCRM_CI_RUNTIME"
php tests/migration-schema.php --runtime-config "$KCRM_CI_RUNTIME" --confirm-fixture-reset
```

Additional source/module checks are PHP lint, Python compilation of the exact FTP helper (cache outside the checkout), CRM JavaScript syntax, `node tests/ui/client.test.mjs`, `php tests/tickets-service.php`, `php tests/tickets-routes.php`, `php tests/website.php` and `php tests/migration-package.php`. Before packaging and before any real FTP step, `KCRM_QA_PHP="$(command -v php)" python3 tests/migration-ftp.py` runs the offline in-memory FTPS safety suite. Its trusted-loader test reads `KCRM_QA_PHP` (the current fixture does not use `PHP_BINARY`); it blocks socket creation and uses synthetic state. Packaging uses:

```text
php tools/package.php --check
php tools/package.php --output "$RUNNER_TEMP/kosmoport-bundle/source.zip"
php deploy/verify-source-package.php "$RUNNER_TEMP/kosmoport-bundle/source.zip"
```

`core-mysql.php` exercises actual migrations/CRUD/auth helpers with its own temporary databases. Document HTTP tests use real multipart requests and the MySQL document fixture; they do not certify core production sessions. Ticket service and website module checks use isolated SQLite adapters. The schema-transfer test uses the current trusted MySQL migrations and both real document versions. Results are whatever the future run reports: no CI PASS is asserted by this template.

The existing `core-http.py`, `tickets-http.py`, E2E runtime and local DB launcher assume a `<php>/ext`/Windows runtime layout; they are not silently claimed as portable Linux CI coverage here. Their Windows/local results remain separate. There is no real scanner, fiscal register, production owner login, physical printer, InfinityFree browser challenge completion or host upload-limit test in this job. A successful CI job is not those proofs.

## Release helper and public installation manifest

The confirmed command, run from the checked-out `kosmoport/` root, is:

```text
python3 deploy/ftp-release.py --package <source.zip> --expected-sha256 <producer-sha256> --apply
```

Its confirmed environment is `FTP_HOST`, `FTP_USER`, `FTP_PASS`. Defaults for reviewed extra inputs are `--config-template config/infinityfree.example.php` and `--install-public deploy/install-public.json`. The parser accepts these two exact hash-only schemas; the coordinator owns which valid shape is present and the workflow never rewrites it. The versioned form is:

```json
{"format":"kosmoport-install-public-v1","setup_key_sha256":"<64 lowercase hexadecimal characters>","base_path":"/kosmoport","contains_secret":false}
```

The placeholder is a hash, not a token. The other accepted shape is exactly `{"setup_key_hash":"<64 lowercase hexadecimal characters>"}`; the coordinator's latest temporary handoff says the current file uses this one-field form. Both shapes reject duplicate/unknown fields, raw secret fields and invalid values. The raw high-entropy setup token is kept separately and is never generated/printed in CI, committed, bundled or uploaded as an artifact. `config/infinityfree.example.php` reads only existing private CMS `db_*` connection settings on the host; it contains no populated credentials, does not query CMS accounts and does not copy an old user's password/hash. The app key is independently generated on first deployment; the first CRM owner uses ordinary `setup.php`, a new setup key and a new password. The normal portable `config/config.example.php` remains available for a new host.

The source allowlist admits only the exact hosting template, exact deployment Python/YAML sources and this hash-only manifest; it does not admit arbitrary deployment JSON/Python/YAML or private populated `config.php`. Invalid manifest JSON, extra password/token fields and non-SHA256 values refuse the package. The FTP helper owns the final config construction, remote preflight, `/htdocs/kosmoport/` path restrictions, private-file preservation, rollback/readback and TLS verification. This workflow does not substitute a generic root FTP action for those checks.

The authorized release workflow runs real Chromium after the FTPS readback. `tools/probe-host.mjs` navigates the public website, checks the fresh public control by response-body SHA256, and observes exactly four required private GET denials without redirects or host challenge responses. Only those observations produce the private receipt passed to the separate `--privacy-proof --confirm-browser-checks` activation mode. The raw receipt is not uploaded as an artifact. A successful FTPS readback alone does not enable document storage.

The initial hosting adapter keeps all three gates false: `storage.allow_webroot_storage`, `storage.http_denial_verified`, and `documents.allow_webroot_storage`. A new deployment nonce invalidates a prior runtime opt-in on every deployment, even if guard files did not change. The helper binds proof to the verified `https://ferryffggg.infinityfreeapp.com` origin, path, instance, nonce, package and all ten guards, with fresh actual private canaries and a public control. After successful activation, `tools/verify-host-public.mjs` checks the real website, anonymous API session/CSRF, CRM login and independent setup form. The authorized legacy redirect is then enabled through its standalone mode and verified in Chromium. The workflow publishes only nonsensitive guard/readiness results. It does not create the first owner or certify an authenticated production flow. Shared Actions concurrency is not a remote lease and makes no full server-side compare-and-swap guarantee.

Release report fields `deployment_nonce`, `origin` and `verified_at` identify the delivery marker/readback. Their presence is not independent browser privacy evidence and never enables the three flags. Standalone redirect/rollback modes do not redeploy the package or renew its nonce/proof.

Check the real GitHub conclusion, artifact/remote hashes and authenticated browser behavior after an authorized activation. InfinityFree may return its browser challenge to external HTTP clients; such a response does not prove either private-path denial or working CRM login. Do not weaken TLS, expose temporary credentials or bypass the host challenge to obtain a green run.

## Action pins and local validation scope

Checkout and upload-artifact pins match the inspected local storefront workflows. Additional pins were read from their maintainers' signed release/commit pages: [setup-php 2.40.0](https://github.com/shivammathur/setup-php/commit/eb7c497e18156a6bbabfef1d3a82760b9eda3962), [download-artifact v4.3.0](https://github.com/actions/download-artifact/commit/d3f86a106a0bac45b974a628896c90dbdf5c8093). These immutable pins are deliberate; updating a runner/action version is a separate reviewed change.

Local review covers YAML/embedded-code structure, deployment guards, source-package fixture behavior and the confirmed uploader interface. It does not execute GitHub Actions, start a service container or contact the FTP server. The existing local storefront snapshot may differ from current GitHub; re-read the destination files before applying the proposed diff.

2026-10-31 local results: the updated source-package suite passed **148 ZIP checks** and **96 checks without the ZIP extension** on PHP 8.5.11; package/verifier/test PHP lint passed. The exact tracked-file guard admitted eight reviewed source examples and refused 26 private/runtime/document/dump/temporary-note cases. Embedded Python blocks were syntax checked and the embedded PHP block linted through stdin; trigger/repository/secret/concurrency/action-pin/opt-in-flag structure and the single source artifact producer plus both PHP job success gates were checked. Real-source preflight accepted the coordinator's current hash-only manifest without printing its fields or token material; fixtures cover both supported schemas. A full YAML parser, `actionlint` and Bash linter were unavailable in the supplied local runtime, so this is a structural/manual YAML review, not an executed GitHub validation or PHP 8.2/8.4 runner test. The first authorized GitHub run still has to establish those results.
