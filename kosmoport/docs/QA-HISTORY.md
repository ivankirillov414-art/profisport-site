# Independent QA — in progress

Date: 2026-10-31. Work is confined to `tests/e2e/`, `tools/verify*`, `docs/QA*`, and this handoff. No production, deployment, scanner, fiscal register, printing or real business-data operations.

## Environment actually checked

- Specified PHP exists: PHP 8.5.11 NTS x64.
- Its default configuration has PDO but no database drivers loaded. Running with `-n`, its `ext` directory and explicit `pdo_mysql`, `pdo_sqlite`, `fileinfo`, `openssl`, `mbstring`, `zip` extensions successfully reports both `mysql` and `sqlite` PDO drivers and all four required libraries. No global PHP configuration was changed.
- No MySQL/MariaDB server was found on PATH or in the checked standard installation folders. QA is provisioning a **portable loopback-only MariaDB 11.4.13** under `tests/e2e/.runtime/`; official SHA256 manifest has been downloaded, ZIP download is in progress. This is not ready for other roles yet; connection instructions will follow here.
- Existing PHP SQLite test support can be used by module owners independently. It does not prove MySQL migration compatibility.

## Harness implementation

- `tests/e2e/harness.mjs`: real HTTP sessions, cookies and CSRF state, strict same-origin JSON/binary handling, per-case pass/fail/blocked report. Mutation target must be HTTP loopback with an explicit port. No production override.
- Fixture marker requires `environment=test` and a database named `kcrmqa_*`.
- `tests/e2e/dev-router.php`: test-only `/kosmoport/` mount adapter invoking the actual PHP entry points; no fake backend or business routes.
- `tests/e2e/harness.test.mjs`: verifies the safety guard rejects production URLs and business database names.
- `.runtime/` and test artifacts are ignored inside the QA-owned directory. They must never enter source/FTP packages.

## Current integration state / dependencies

Application modules and schemas are still being created. Only the contract and early CRM UI were available at the first check. Missing modules are not counted as passing user flows. QA will run the real HTTP, document, ticket, website and backup scenarios as their implementations arrive.

Core: please document the final setup form field names, supported role names and the CLI/migration entry point in your existing handoff. Packaging: please document the programmatic disposable-target backup/restore entry points. No cross-owned files have been changed by QA.

## Results

Environment probe: PASS (explicit extension loading). Other results will be appended after execution. No integrated acceptance claim yet.

## Portable MySQL-compatible test server ready

MariaDB11.4.13 is running at127.0.0.1:58095 without a Windows service; archive SHA256 d62986d433eeebfde218560b276103831604a61e929e87f1a17f5aebd80257e2 verified. Private connection file: tests/e2e/.runtime/local-db.json (do not print/copy to source package). Other roles may create only their own kcrmqa_* database on this disposable server; never modify another role's database. Use tests/e2e/db-admin.php create kcrmqa_<unique> with PDO MySQL enabled; it refuses an existing database. QA will keep its integration database separate.

Actual checks now: two harness safety tests PASS using node --test --test-isolation=none; PHP router lint PASS. Default node test-worker spawn was rejected by the sandbox (EPERM); same tests passed without worker isolation. No integrated application routes are counted as tested yet.

## Executed UI fault-injection pass

Three Chromium viewport cases PASS:390×844,1024×768,1366×768. Real public CRM assets through the prefixed PHP server; API503/configuration,401/invalid login,and offline failure were explicitly injected. Verified configuration-error screen→retry→login, readable error, enabled retry and preserved email, no horizontal page overflow, no pageerror. Screenshots:tests/e2e/artifacts/ui-errors-{390,1024,1366}.png; JSON report ui-errors.json. The390px screenshot was visually inspected. This is NOT real auth/API acceptance. First QA draft incorrectly expected the login heading during503; corrected to the actual «CRM недоступна→Повторить подключение» flow, then all three passed. No production/UI source change by QA.

21 PHP source files passed syntax checks on PHP8.5.11 at the recorded hash snapshot. New modules continue to appear; final lint/acceptance will be rerun after integration. Exact PHP8.2/InfinityFree behaviour is not yet verified.

Real integrated HTTP pass has now started on an isolated fresh kcrmqa_* database with actual setup/API entry points and production module code. Results pending; no claim of acceptance yet.

## First real integrated MySQL/HTTP run — executed

Report:tests/e2e/artifacts/integrated-first.json; disposable DB kcrmqa_20261031_0bf6600abd.16PASS,2FAIL,1BLOCKED at this snapshot. PASS:real key+CSRF setup and single owner;login/session+CSRF rotation;setup reuse rejected;anonymous401;forged/missingCSRF+foreignOrigin403;real role accounts;partner/task readback;role denials;versioned ticket import/repeat/conflict rollback;check→manual confirmation→admit→same-request replay→duplicate409→reversal/audit;two actual PHP processes raced to one admission(200/409);Asia/Yekaterinburg local-midnight boundaries2in/2out andperiods;website draft isolation/stale409/actual publicHTML publication;logout invalidation.

### OPEN QA-001(P1): cashier role cannot perform ticket work

Repro:run node tools/verify-crm.mjs with the local QA runtime;owner creates an active user role=cashier through POST users,imports the generated valid fixture ticket,logs in as that cashier,then POST tickets/check with that valid code returns403 FORBIDDEN(expected200).Current app/core/auth.php KCRM_ROLES permitscashier but notoperator;app/modules/tickets.php permitsoperator/manager forcheck/admit/statistics,so there is no usable non-manager ticket operator account among the actual allowed core roles.Owner/manager checks work.Rights must be aligned by the module/core owners;do not broaden import/reversal/document/audit rights to fix it.No production code changed by QA.

The other initial FAIL was a QA expectation error:documents/upload correctly returned201 Created while the first harness expected200.Corrected the harness to201;version/access cases rerun on a fresh database.This is not a product defect.Initial UI/payload mismatch suspicion was checked against the concurrently updated UI:canAdmit now acceptsstatus=valid and the admission_id is normalized,so no defect is asserted there.

## Integration findings update

QA-001 CLOSED in the next real run:ticket module now usescashier/manager;cashier check+admit PASS,import/reverse remain403;no broader rights granted by QA. All document multipart/version/download/access/type/size cases now PASS (201 Created is expected).

Restore initially rejected the identical18-table sets with «Состав таблиц не совпадает».Current transfer code now canonicalizes table order with SORT_STRING;direct diagnostic confirms equal arrays.Fresh run advanced past that guard.Next refusal «Заранее создайте...пустой приватный каталог» is a QA setup omission,not a product bug:target storage must be precreated by the operator per docs.Corrected target fixture to create a new empty private directory;rerun pending.

### OPEN QA-003(P1): existing partner cannot be edited in the real browser

Repro in current real run:kcrmqa_20261031_380ff08f92;390px and1024px.Login as the fixture owner→Партнёры→Изменить existing QA partner→change contact→Сохранить.Dialog stays open with «Обновите карточку перед сохранением.Нужна версия записи».POST partners omits expected_revision/revision,while GET partners returns revision=1 and core requires numeric expected_revision for updates.In public/assets/crm.js editPartner payload only addsid;editTask has the same missing field by source inspection (task UI step not reached yet).Owner must attach loaded revision to edit payloads,retaining409 conflict behaviour.Do not remove the core revision guard.Screenshots/text:tests/e2e/artifacts/integrated-{390,1024}-failure.*.QA has not edited application files.Browser harness is being split into independent section checks so this defect will not hide ticket/document/website results.

## UI source changed during a QA run — fresh stable run required

The later browser run spans two source versions:crm.js/CRM forms were actively updated during390→1024→1366.Original390 partner/task422 is real for the old payload;the new implementation usesrecordPayload/expected_revision and1366 taskedit already passed.A transient renderSettings-not-defined state was observed while the same file was being written;this is not recorded as a stable final product defect.Browser source-hash guard has been added to prevent accepting mixed-version runs.The new website editor has normal input fields plus collapsed JSON;QA now exercises actual brand fields(or legacy JSON where present),not a hidden textarea.Selected old report retained as integrated-ui-transition.json.Fresh full run starts after these files are present;no application source changed by QA.

## Current integration status — source editing detected again

Actual backend/API and full backup/restore checks are now passing, including18-table content hashes and exact v1/v2 downloads after restore,corruption rejection before writes,and nonempty/wrong target refusal.New source recordPayload sends expected_revision;task edit passed in the actual updated UI.The next partner-case timeout was a QA locator error after a successful200/save:the contact text sharesa td with phone/email,so exact getByText did not match.Corrected it to the actual contact cell;not a product defect.

The UI hash guard then detected crm-forms.js andcrm.css changes during the test and correctly marked remaining1366 cases BLOCKED,notaccepted.New report retained as integrated-sixth-ui-changed.json.A fresh quiet source pass is required;no current app bug is inferred from mixed-version output.Updated UI normal website brand fields/conflict/publish have been adapted in QA.

Source packaging preflight currently refuses the public installation manifest because it does not yet contain only a valid64-lowercase-hex setup_key_hash.This is a release-configuration dependency;QA did not write/replace any release key or foreign file.Production/InfinityFree still not accessed.
