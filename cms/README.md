# ID CMS / ID Studio

Выпуск ID: 14 типов секций, поиск и категории, три композиции, сохранённые копии блоков в черновике сайта, медиатека с изоляцией списка по site_key, отмена/повтор до 60 состояний между сохранениями и проверка публикации. Исследование конкурентов и границы текущей реализации: `research.html`.

Новые медиа индексируются в `ps_cms_media` при загрузке. Старые файлы не мигрируются без достоверной принадлежности сайту; используемые изображения видны из текущего черновика. Публичные URL файлов доступны посетителям, список требует входа. Новые параметры сетки/видимости относятся к новым секциям. Ширина телефона — до 640 px; медиа до 5 МБ / 8000 px. Сохранённые блоки — копии, не синхронизированные экземпляры. Черновик сохраняется автоматически через 1,5 секунды после последней правки; Ctrl+S сохраняет явно. Автосохранение не публикует сайт, не сбрасывает отмену действий и не перерисовывает открытый макет. При ошибке сети или конфликте версий автоматические запросы останавливаются, правки остаются в открытом редакторе. Кнопка «Локальные копии» открывает сравнение с сервером: можно восстановить локальную копию, явно заменить изменившийся черновик или оставить серверную версию. Каждая последующая запись всё равно проверяет номер серверной версии. Несохранённые правки записываются в localStorage этого браузера, отдельно для пользователя, сайта и вкладки. После повторного входа предлагается восстановление; копия удаляется после подтверждённого сохранения или явного отказа от неё. Закрытие вкладки с несохранёнными правками вызывает предупреждение. Если браузер запрещает локальное хранение или исчерпана квота, редактор сообщает об этом; очистка данных браузера удаляет и локальные копии. Публичная демонстрация не сохраняется на сервере. Смена сайта сбрасывает локальную отмену. Публикация выпускает весь черновик выбранного сайта.

# Контент CMS 2 — standalone visual editor

PHP 8.1+ with PDO MySQL and mbstring, and MySQL/MariaDB (InnoDB), GrapesJS 0.23.6 OSS core (BSD-3-Clause, `vendor/LICENSE`). No subscription, Node process, storefront imports or product database access at runtime.

## What editors can do

Open `/cms/`: select a site and page, click existing text/images, drag or click library blocks, arrange sections, change colors and spacing, inspect desktop/tablet/mobile layouts. Save a draft or publish it. New pages get a working URL shown above the canvas, such as `/cms/site.php?site=profisport&page=about.html`. Add this URL to a button or existing link to make the page discoverable. Publication does not automatically modify site navigation. Version restore changes only the draft.

Supported blocks: hero, text, image, two columns, call to action, contacts (text/link), spacing. Contacts are not a form submission backend. Original catalog, cart and service forms retain their own DOM nodes and scripts. Existing sections can be hidden/reordered; their arbitrary HTML, scripts and database behavior are intentionally not editable. Header/menu fields remain accessible in “Все текстовые поля”. Static canvas does not run shop scripts; use “Предпросмотр” to test live interactions.

`demo.html` is a public sandbox using only original public content. Its saves are in browser memory, its uploads/publication are disabled, and it never reads real drafts.

## Product boundary

- `cms/` is the complete CMS application: its own login/session, API, project registry, documents, history, upload directory, install flow and release archive.
- `cms-client.js` is a copy of `cms/connector.js`, the storefront connector. The CMS never includes storefront PHP files and does not access products, 1C, orders, customer accounts or shop sessions.
- Each site has a unique `site_key`, separate draft/published document and history. The original owner (user ID 1) manages all sites and user access. Other accounts only see assigned sites: viewer reads drafts/history/media, editor saves/restores drafts and uploads images, publisher can additionally publish. These are application-level access boundaries, not separate infrastructure per customer.
- `.github/workflows/deploy-cms.yml` deploys only CMS files. The storefront workflow excludes `cms/**`. Media and private configuration are excluded from the portable release; uploads are never synchronized destructively.
- Source is still in the shared Git repository. Independent deployment does not imply a separate repository or separate physical database. Production initially preserves existing database credentials/tables to avoid data loss. Set `CMS_DB_HOST`, `CMS_DB_NAME`, `CMS_DB_USER`, `CMS_DB_PASSWORD` secrets after migrating **all** `ps_cms_*` tables to a separate database if desired. Merely changing credentials is not migration.

## Upgrade from v1

No owner recreation, password reset or content replacement. After owner login the API creates `ps_cms_sites` if missing and registers the original project with `INSERT IGNORE`. Original documents and history remain untouched. Legacy documents stay valid without `layout`; visual layout is added on first visual save. Keep field IDs and binding defaults stable. The visual connector snapshot lives in `private/templates.json` and is separate from stored drafts.

## Independent install

1. Download the `id-studio-standalone` artifact from a successful CMS checks run, or run `python3 cms/tools/package.py /absolute/new/output-directory` (the Bash wrapper remains supported). Unzip onto a PHP/MySQL host in any directory or domain. The portable build starts with a generic site and contains no ProfiSport bindings, demo content, credentials or media. The archive has a SHA-256 sidecar and an internal `release-manifest.json` with hashes of its files.
2. Copy `private/config.example.php` to `private/config.php`; set your own DB credentials, initial site key/name/URL, **absolute HTTPS** `media_url`, and `public_url` to the directory hosting this CMS (for example `https://example.com/cms/`).
3. Generate a random installation token (`php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`) and place it in `install_token` in private config.
4. Open `install.php`, provide the token and choose owner credentials. Installation locks once the first owner exists. Remove `install_token`. No shop login is involved.
5. Log in, create pages/blocks, save, publish. Uploads need a writable `media/` folder. Protect `private/` and executable uploads with the included Apache rules or equivalent rules on a different server.

## Connect another site

Create it in **Сайты → Подключить новый сайт**. Either use its generated `site.php` pages directly, or embed this script on a page with `<main data-cms-root></main>`:

```html
<script src="https://cms.example.com/connector.js"
 data-cms-endpoint="https://cms.example.com/api.php"
 data-cms-site="my-site" data-cms-page="index.html" defer></script>
```

Only published content is public; the public endpoint permits anonymous cross-origin reading. Private API uses the CMS's own origin/session/CSRF checks. Configure the connected site's CSP to permit the CMS script, API, uploaded images and inline block styles. Preview messages require the exact configured CMS origin and opener; editor validates the exact site origin. Arbitrary existing websites require a reviewed connector map/snapshot to preserve functional DOM; adding their URL alone does not automatically import a website.

## Backups and rollback

Back up all `ps_cms_*` tables, `media/`, private config and connector snapshots before migration. History is not an off-host backup. Restore a revision and publish to roll back content. Removing the connector leaves original storefront HTML functional. For full rollback restore the corresponding files/database backup together.

## Verification

`cms-tests.yml` runs PHP/JS checks, hostile recipe/URL validation, legacy compatibility, MariaDB install/save/publish/restore, site isolation and authenticated HTTP contracts. Deployment verifies transferred runtime file bytes via FTPS. A successful CI run is not proof that an owner browser login or production write was tested. Never use a production database for tests (`CMS_TEST=1` and `_test` suffix are mandatory).

Build connector after edits: `cat cms/blocks.js cms/tools/adapter.js > cms/connector.js && cp cms/connector.js cms-client.js`. Snapshot development: install the pinned dev dependency in `cms/tools/`, then run `node cms/tools/build-templates.mjs` from repository root. Inspect binding/selector changes before regenerating; stored field IDs must not change.

## Completion work — 2026-09-30

Source recovered from `ivankirillov414-art/profisport-site`, base `ce57d44`, branch `codex/id-studio-completion`. This is an ongoing release, not a completed production delivery.

Implemented: debounced draft autosave in both editors, sequential revision handling, preservation of edits made during requests, undo preservation, and pause on save errors/conflicts. Manual saves and site changes wait for active autosaves. Demo remains memory-only. Added browser-local session recovery, explicit comparison/selection of conflicting drafts, JSON download, and separate backups for each user/site/tab. A failed local write cannot delete the previous recovery copy before the server confirms saving.

Verified locally: `node cms/tests/autosave.js`, `node cms/tests/recovery.js`, `node cms/tests/blocks.js`, JS syntax checks, and `node cms/tests/autosave-browser.cjs` with Playwright 1.62.1 / Chromium. The browser suite runs actual editor scripts against an isolated in-memory API; it is not evidence of PHP/MySQL or production verification. Playwright must be available through Node module resolution; install its Chromium browser before running the browser suite.

Dynamic collections, templates and public lists are implemented and locally verified. The existing research roadmap also describes SEO/sitemap, design controls, portability and operational verification; confirm their intended release scope against the original project discussion before claiming completion. The final release still needs end-to-end acceptance for the remaining features, release packaging and deployment verification.

Recovery verification: browser reload with an unsaved draft; explicit replacement against a newer server revision; selecting the server copy without writing; simultaneous local-storage quota failure and server conflict; removal only after acknowledged save. Recovery dialog visually inspected at 390 px. The visual editor header now wraps at narrow widths; it was rechecked at 390 px after adding account controls. Remaining screens still need the final responsive audit. Browser autosave/recovery suite is included in CI, but its remote run has not yet been verified.

## Users and permissions

Open **Пользователи** as the owner to create an account, assign one role per site, change access, reset a password, or disable an account. No grant means no access to private site data. Existing owner credentials, drafts, publications and history are preserved during migration. The owner cannot be disabled or demoted through this screen. Every account can change its own password using its current password. A user with no assigned sites sees a dedicated screen with refresh, password and logout actions. An old URL for an inaccessible site offers links only to sites currently assigned to that user. The storefront owner-recovery bridge also increments the CMS session version; deploying the updated bridge is part of delivery.

All private site endpoints enforce permissions on the server. Changing an account's access/active state or resetting its password invalidates existing sessions. Self-service password change retains the requesting session and invalidates other sessions. Administrative changes are audited in `ps_cms_access_log` without passwords or password hashes. `ps_cms_memberships` stores site access; the existing users table gains auto-increment IDs and an `auth_version` column. Deployment requires CREATE and ALTER permissions for this additive migration; back up CMS tables first.

Local verification (2026-09-30): PHP 8.4.26 and MariaDB 10.11.16 on disposable `idstudio_roles_test` and `idstudio_final_roles_test` databases, bound to 127.0.0.1:33307; PHP test server at localhost:8123. `validation.php`, `visual-validation.php`, `integration.php`, `multisite.php`, `http_contract.py`, `http_roles.py`, and `access-audit.php` passed. `roles-browser.cjs` exercised the actual PHP/API/database through Chromium: account creation, scoped login, saving a page as editor, editor/viewer/publisher changes, session revocation, password change no-sites logout, navigation from an inaccessible site URL, read-only fields on a second site, and the empty-fields screen for a generic site. Autosave/recovery browser regressions also passed with permission-aware state fixtures. These suites are wired into CI; remote CI and production have not been verified yet.

Test runtimes are portable under `%LOCALAPPDATA%/id-studio-test-runtime`; local connection settings remain in ignored `cms/private/config.php`. Optional `db_port` defaults to 3306. Do not run database test suites against a live database. `integration.php` requires a fresh disposable database, followed by `multisite.php`, `http_contract.py`, `http_roles.py`, browser roles, and `access-audit.php` in that order, with `CMS_TEST=1`. The browser suites require Playwright 1.62.1 and installed Chromium.

## Общие блоки

В визуальном редакторе откройте **Общие блоки**, выберите созданную секцию и задайте название. Полученный блок можно вставлять на разные страницы того же сайта. **Изменить общий блок** меняет все связанные экземпляры. Обычные правки на холсте становятся местными исключениями; в **Связь с общим блоком** их можно сбросить или отвязать экземпляр. Список элементов карточек/галереи заменяется целиком при местном редактировании. «Мои блоки» по-прежнему сохраняет независимые копии.

Определения, связи и исключения входят в единый черновик, автосохранение, отмену, восстановление сессии и историю. Изменения становятся публичными только после публикации. Публичный API раскрывает ссылки в обычные безопасные секции: обновлять внешний коннектор не требуется. Неиспользуемые определения и их названия не выдаются посетителям. Допускается до 50 определений; вложенные ссылки и преобразование исходных секций магазина запрещены. Нельзя удалить определение, пока используются его экземпляры. Размер раскрытых секций ограничен 1 МБ на страницу и 4 МБ на сайт.

Проверено локально: `components.js` и `components.php` (разрешение ссылок, исключения, валидация и ограничения размера); `components-browser.cjs` с настоящими PHP/MariaDB и Chromium (создание, две страницы, предпросмотр, местные исключения, перезагрузка, публикация, публичные страницы, сброс, отвязка и восстановление истории). Панель общих блоков осмотрена на ширине 390 px. Тесты добавлены в CI; удалённый запуск и production ещё не проверены.

## Collections

The server now preserves and validates `collections` in the existing atomic draft/history document. Each collection defines an ID, name, string title field, typed fields, and entries with stable IDs, unique slugs, draft/published eligibility, and values. Supported types are string, text, number, boolean, date, image, URL, and a reference to a record in another collection in the same site. Required fields apply when an entry is eligible for publication; incomplete drafts are allowed. References cannot point to missing collections/records, and a publishable record cannot reference a draft record. Deleting referenced content is rejected. Limits: 20 collections, 20 fields each, 100 records per collection, 500 records total, and 700 KB of collection JSON within the API's 1 MB document limit.

The visual editor now has **Коллекции** for creating collections, configuring fields, searching/editing records, and selecting publication eligibility. Existing field keys/types are stable; removing a field explicitly removes its values. A server validation request checks the complete collection set before applying a form, without advancing the document revision. Accepted changes join normal autosave, local recovery and undo. Validation requires editing permission and CSRF; viewers cannot use the management UI. Collection management is currently available in the authenticated visual editor, not the public demo or fields-only editor.

**Шаблон страниц** configures shared sections for record pages. Add a section or copy one from the current page, bind text/image/link properties to collection fields, adjust constant values/colors/spacing, reorder sections, and preview an eligible record. Existing copied item lists remain static. A binding to an image or URL requires that field type; references display the linked record's title without recursive expansion. Saving a template participates in normal draft versioning. Record URLs are listed under **Адреса страниц после публикации** in the records panel. The stable address uses the collection ID and entry slug; changing a slug changes its URL. There are no automatic redirects.

Only records marked for publication with a nonempty template generate public pages, and only from the published site snapshot. Public output consists of rendered block recipes; collection schemas, private names and draft records remain private. Generated pages are excluded from the automatic top navigation. Ordinary page/record URL collisions are rejected. Templates allow 20 sections; expanded public content is limited to 1 MB per page and 4 MB per site.

Choose **Коллекции → Добавить список на страницу** to map card titles, descriptions, images and image descriptions; select initial ordering, a visitor filter, 1–24 records per page, columns and colors. Open that section to reconfigure or remove the list. The list displays eligible records even without a detail template; links appear only when a detail template exists. Records remain the source of card content. Referenced collections/fields cannot be removed while a list uses them. Lists are not converted to shared blocks or copied into record templates.

Visitors can search displayed titles/descriptions, filter by the selected field, change title ordering, and page through results. The published snapshot contains up to 100 mapped records per list; filtering/pagination are performed in the browser rather than fetching private collection schemas. Initial number ordering is numeric, empty values follow populated values, and equal values use record IDs for deterministic ordering. Public record links resolve against the CMS endpoint, including on an embedded site with a different origin. Deploy the updated `connector.js` and, for the existing storefront, `cms-client.js` with this release. Older connectors do not implement interactive collection lists.

`collections.php` tests schema/type/link validation and legacy compatibility; `collection-templates.php` tests safe field bindings, draft privacy, address collisions and expansion limits. `collections-http.py` verifies real PHP/MariaDB persistence, revision conflicts, rejected writes, atomic history restoration, public privacy and site isolation. `collections-browser.cjs` verifies real schema/record forms, zero-valued numbers, incomplete drafts, required-field errors, reload, search, editing, rejected deletion of a referenced record, viewer denial, template preview, publication and public record pages. It also checks HTTP 404 before publication and for draft record URLs. The record list was visually checked at 390 px. These tests passed locally and are included in CI.

`collection-lists.php` and `collections.js` verify query/mapping validation, type-aware ordering, privacy, client recipe roundtrip and escaped HTML. `collection-lists-browser.cjs` exercises the real PHP API, editor insertion/configuration/removal, reload, dependency protection, publication, public search/filter/sort/pagination and links from a second origin. Chromium's loopback-network permission is granted only to the local embedding test origin. The public list was visually inspected at 390 px; editor autosave/recovery and collection-template regressions also passed. Remote CI, the final release package and production remain unverified.

## SEO and sitemap

**SEO страницы** sets a search title, description, canonical URL, sharing image and noindex for the selected page. Empty titles use the page name. Empty canonical values use the generated page URL, or the connected site's original URL for a manifest page. Canonical overrides must be absolute HTTPS URLs without credentials or fragments. **Шаблон страниц → SEO страниц записей** maps title/description/image fields for collection record pages and optionally excludes those pages from indexing. These settings participate in normal drafts, history and publication.

`site.php` emits title, description, canonical, robots and Open Graph metadata in the server HTML. Preview mode exposes no private metadata and sends `X-Robots-Tag: noindex, nofollow`. `sitemap.php?site=SITE_KEY` emits XML for the published snapshot; unpublished and noindex pages are excluded, as are canonical URLs outside the CMS directory. No invented modification timestamps are emitted. Configure `public_url` explicitly in production, particularly behind a reverse proxy; without it the direct request scheme/host/directory is used, and forwarded headers are not trusted.

An external website must integrate the published page's `seo` object into its own server-rendered head. The JavaScript connector does not rewrite the external site's source HTML or provide server-side SEO there. Likewise, submit an external site's own sitemap for URLs outside the CMS directory. The CMS does not edit an existing robots.txt or automatically submit sitemaps to search engines. Canonical and sitemap behavior follows the [Google Search Central sitemap guidance](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap).

Local verification: `seo.php` checks validation, absolute URLs, XML escaping, noindex, external canonical exclusion and typed collection bindings. `seo-browser.cjs` exercises the actual form/API/database and checks raw HTML responses without executing JavaScript: published metadata, draft isolation, noindex exclusion, preview privacy, collection record metadata and reload. Both passed and are included in CI; production SEO remains unverified.

## Release packaging and clean installation

`tools/package.py` builds on Windows and Linux without an external zip utility. The `--demo` mode collects the current HTML's script/style dependencies and vendor assets; the preview workflow now uses this instead of an outdated file list. `tests/package.py` verifies both outputs, all editor dependencies, hashes, generic bindings, absence of local configuration/uploads, and refusal to overwrite an existing build.

Clean installation was verified from the extracted `ID-Studio-2026-09-30-rc2.zip` against a new disposable `idstudio_portable_release2_test` database: one-time installation, installation lock, independent owner login, generic editor without storefront bindings/templates, section creation, autosave, server SEO, publication, sitemap and fields-mode navigation. `portable-browser.cjs` and the guarded fixture helper reproduce this in CI on a separate fresh database. This is evidence of a local portable installation, not of production deployment.

The clean-install check uncovered a login startup race: before JavaScript loaded, native form submission could put credentials in a GET URL. Both login forms now specify POST and keep their button disabled until session/CSRF initialization succeeds. `login-startup-browser.cjs` deliberately delays scripts and the session response, then verifies the disabled state, POST request, CSRF token and absence of credentials in URLs for both editors. Only disposable test credentials were used during this verification.
