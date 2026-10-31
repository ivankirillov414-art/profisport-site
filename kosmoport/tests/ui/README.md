# CRM UI checks

No dependencies, production credentials or real records are needed.

```powershell
node tests/ui/client.test.mjs
node tests/ui/forms.test.mjs
node tests/ui/backend-payload.test.mjs 'C:/Users/Izop300520251/Documents/ChatGPT/cfqn ghjabcgjhn/tools/php-runtime/php.exe'
node --check public/assets/crm.js
node --check public/assets/crm-api.js
node --check public/assets/crm-utils.js
node --check public/assets/crm-forms.js
& 'C:/Users/Izop300520251/Documents/ChatGPT/cfqn ghjabcgjhn/tools/php-runtime/php.exe' -l public/crm/index.php
```

## Isolated browser fixture

The fixture is outside `public/` and must not be included in a hosting archive. It overrides fetch only on this test page; all non-test requests are rejected. The banner explicitly labels every record as synthetic.

```powershell
& 'C:/Users/Izop300520251/Documents/ChatGPT/cfqn ghjabcgjhn/tools/php-runtime/php.exe' -S 127.0.0.1:8769 tests/ui/router.php
```

In another terminal: `node tests/ui/static-smoke.mjs`.

Open `http://127.0.0.1:8769/kosmoport/crm/`. Use a made-up syntactically valid address such as `test@example.invalid` and a test password; no credentials are transmitted to an external server.

Manual cases for the coordinator/QA:

1. Login; six tabs appear. The malicious markup in the synthetic task title is displayed as text, never rendered as an image.
2. Partners: empty state, create form, search and edit. Tasks: create/status/due date/assignee.
3. Tickets: check `TEST-001`. Check alone does not admit. The confirmation button remains disabled until adult payment is manually confirmed. After admission, repeat check shows a used ticket. Owner may reverse with a reason. Unknown codes never show the admission form.
4. Statistics remains separate from scan. Scan composition is left input plus two metrics on the right on a laptop; stacks on small screens.
5. Documents: search/category, versions, protected download, upload/new-version form.
6. Website: brand, dates, venue, address, price and contacts use ordinary fields; the JSON section contains remaining content. Invalid JSON is rejected locally. First valid save deliberately returns 409; ordinary inputs and JSON stay unchanged, publish is disabled, local JSON download and server reload are offered. Reload has an explicit confirmation. After reloading and editing, save works. Publish requires its own reviewed-content checkbox. The preview renders only text, never content as executable HTML.
7. Check at 1366, 1024 and 390 px: navigation scrolls independently on mobile, labels/inputs remain usable, tables have their own horizontal scroll, modal remains within the viewport.
8. Owner/admin see Settings; other roles do not. Owner has a plain maintenance.php link. Editing an employee with a blank password must omit password from POST. Real backend protections are checked by backend-payload.test.mjs, not assumed from the fixture.

The real core HTTP test uses actual production PHP core code, the UI's payload builders, a fresh temporary SQLite TEST fixture and synthetic accounts. It binds only loopback, prints no credentials, stops its child PHP process and deletes its own temporary config/database/storage. It does not use the shared QA MySQL database or existing CMS credentials.

The fixture proves no production persistence, live authentication, file validation, real scanner/cashier operation, or exact public-site rendering. Those need the real backend and separate verification.
