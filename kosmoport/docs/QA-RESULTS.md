# Kosmoport CRM — final independent QA

Checked:2026-10-31T08:12:42.575Z. **Application PASS; release artifact FAIL.** No deployment/publication performed.

| Evidence | Actual result |
| --- | --- |
| Actual HTTP/API/MySQL, restore, Chromium | 59 PASS, 0 FAIL, 0 BLOCKED |
| UI-only503/401/offline/retry at390/1024/1366 | 3 PASS |
| PHP syntax on 8.5.11 | 53/53 PASS |
| Mutation-target/fixture safety guards | 2 PASS (node --test --test-isolation=none tests/e2e/harness.test.mjs) |
| Latest source package preflight | PASS, archive_created=false, current hash matches=true |
| Existing ZIP core freshness | FAIL: 9 stale of17 checked core files |

Fresh disposable database:`kcrmqa_20261031_7a19625387`; fixture:`20261031_7a19625387`. Genuine PHP entrypoints/modules, actual MariaDB SQL, Chromium390×844/1024×768/1366×768. Browser error injection is separately labelled and never counted as real API proof.

## Findings

**QA-004 CLOSED:** after the coordinator CSS fix, fresh actual390px partner edit/save/readback passes without page overflow. All1024/1366 partner edits also pass. The original cause was isolated by a browser-only variation: an absolute hidden Actions-header span used BODY as offsetParent; containing it within table-wrap changed document width426 to390, and removing the temporary style restored426. The final source fix was made by coordinator, not QA.

**QA-005/P1 OPEN:** existing source ZIP contains 9 stale files of17, missing=0. Rebuild after final source fixes, then repeat freshness. QA did not build or publish an archive. Stale: `app/core/auth.php`, `app/core/config.php`, `app/core/http.php`, `app/core/migrations.php`, `app/core/records.php`, `app/core/routes.php`, `public/setup.php`, `config/config.example.php`, `migrations/001_core.sql`.

Archive:`deploy/artifacts/kosmoport-crm-source-20261031.zip`; SHA256:`e2856fdbc71789473a1362b51eaa7e6e8aa624578125057fb5f316da21f5ddee`; report matches current ZIP=true.

## Exact source identity

All59 deployable source SHA256 values, integration start/end and final readback are in [QA-SOURCE-HASHES.json](QA-SOURCE-HASHES.json). Changed during this final run:[]. Changed since run end:[]. Package tool SHA256:`e194aaca432e1dc2fc7836bf9413f92e06eab85a147781b2e6435b08b3e6d4c9`; separately checked before/after with stable hash, current match=true. A passing packager check does not certify freshness of the existing ZIP. Earlier mixed-tool runs remain in the historical artifacts; they were not relabelled green.

## Actually executed

- Setup:key/CSRF/wrong key, array-valued CSRF controlled403, exactly one owner, repeat409. Login/logout/session and CSRF rotation; anonymous401, forged CSRF and foreign origin403.
- Partner/task creation and persistence, assigned user and due date, validation, stored markup escaped. Actual browser partner/task edit uses current expected revision and readback.
- Staff:owner/admin create and edit; blank replacement password preserves password/session; stale409; admin cannot create/edit owner; role/password/deactivation revoke old sessions; inactive listing; last-owner disable/demotion409. Actual browser staff create/edit/role change/deactivation/last-owner at all3 sizes.
- Real maintenance wrapper:owner200, anonymous401, admin/all other roles403, absent form CSRF403. No business backup downloaded by this access check.
- Coordinator security fixes:exact72-byte password works; valid prefix plus suffix and embedded NUL give generic401. Core protections unchanged.
- Tickets:canonical versioned import/replay/conflict rollback; check does not consume; mandatory manual adult-payment confirmation; admit/replay/duplicate/reversal/audit; cashier boundaries; two PHP workers racing to exactly one admission. Local-midnight UTC/Yekaterinburg statistics, week/month/all and distribution-point attribution. Fiscal payment remains explicitly unverified/false.
- Documents:genuine multipart PDF; immutable versions1/2, stale409; protected exact-byte download; anonymous/role/traversal/type/oversize refusals.
- Website:confirmed dates24.11.2026–18.01.2027, private draft isolation, stale save/publish409, invalid HTML422, actual published public HTML. Ordinary form fields and reviewed browser publication at all3 widths;409 retains entered text. Tested CRM sections contain no visible arrow glyphs.
- Full backup/restore:all18 actual application tables and both immutable blobs, trusted target schema, exact data hashes and authenticated restored downloads; corrupt/nonempty/wrong targets rejected before writes.
- UI-only faults:503/configuration/retry,401/invalid login, offline recovery and unlocked retry at all3 sizes.

## QA corrections, limits and lifecycle

Old CSRF after revocation correctly returns403; QA now obtains anonymous CSRF before retrying login. Excessive redundant role logins initially reached the unchanged30-per-IP limit; QA reuses independently authenticated role sessions. No rate-limit rows/config/guard were changed. Wrong201 upload and combined-source expectations are retained as historical QA corrections, not product bugs.

Local PHP8.5.11 / MariaDB11.4.13 / Node24 / Chromium. Exact PHP8.2, actual InfinityFree HTTPS/private-storage denial/extensions/limits, physical scanner/fiscal/paper/native-device execution are not certified here. No real records were used. Private configs/secrets/backups remain under ignored tests/e2e/.runtime; no secrets in this report. Local MariaDB remains available at127.0.0.1:58095 for integration; do not stop until contributors release it. PHP HTTP child servers are stopped after checks.

Commands:`node tools/verify-crm.mjs`,`node tools/verify-ui-errors.mjs`,`node tools/verify-php.mjs`,`php [explicit extensions] tools/verify-archive.php`,`node tools/verify-results.mjs`. Evidence under tests/e2e/artifacts:`integrated.json`,`integrated-source-hashes.json`,`final-results.json`,`ui-errors.json`,`php-lint.json`,`package-preflight.json`,`core-archive-freshness.json`,`mobile-layout-diagnostic.json`,`integrated-<width>-<section>.png`. Historical reports/screenshots must not be confused with the final fixture.
