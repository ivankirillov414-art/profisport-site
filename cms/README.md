# ID CMS / ID Studio

Выпуск ID: 14 типов секций, поиск и категории, три композиции, сохранённые копии блоков в черновике сайта, медиатека с изоляцией списка по site_key, отмена/повтор до 60 состояний между сохранениями и проверка публикации. Исследование конкурентов и границы текущей реализации: `research.html`.

Новые медиа индексируются в `ps_cms_media` при загрузке. Старые файлы не мигрируются без достоверной принадлежности сайту; используемые изображения видны из текущего черновика. Публичные URL файлов доступны посетителям, список требует входа. Новые параметры сетки/видимости относятся к новым секциям. Ширина телефона — до 640 px; медиа до 5 МБ / 8000 px. Сохранённые блоки — копии, не синхронизированные экземпляры. Черновик сохраняется автоматически через 1,5 секунды после последней правки; Ctrl+S сохраняет явно. Автосохранение не публикует сайт, не сбрасывает отмену действий и не перерисовывает открытый макет. При ошибке сети или конфликте версий автоматические запросы останавливаются, правки остаются в открытом редакторе. После устранения ошибки сохраните вручную; конфликт нельзя обходить перезаписью чужой версии. Закрытие вкладки с несохранёнными правками вызывает предупреждение. Публичная демонстрация не сохраняется на сервере. Смена сайта сбрасывает локальную отмену. Публикация выпускает весь черновик выбранного сайта.

# Контент CMS 2 — standalone visual editor

PHP 8.1+ and MySQL/MariaDB (InnoDB), GrapesJS 0.23.6 OSS core (BSD-3-Clause, `vendor/LICENSE`). No subscription, Node process, storefront imports or product database access at runtime.

## What editors can do

Open `/cms/`: select a site and page, click existing text/images, drag or click library blocks, arrange sections, change colors and spacing, inspect desktop/tablet/mobile layouts. Save a draft or publish it. New pages get a working URL shown above the canvas, such as `/cms/site.php?site=profisport&page=about.html`. Add this URL to a button or existing link to make the page discoverable. Publication does not automatically modify site navigation. Version restore changes only the draft.

Supported blocks: hero, text, image, two columns, call to action, contacts (text/link), spacing. Contacts are not a form submission backend. Original catalog, cart and service forms retain their own DOM nodes and scripts. Existing sections can be hidden/reordered; their arbitrary HTML, scripts and database behavior are intentionally not editable. Header/menu fields remain accessible in “Все текстовые поля”. Static canvas does not run shop scripts; use “Предпросмотр” to test live interactions.

`demo.html` is a public sandbox using only original public content. Its saves are in browser memory, its uploads/publication are disabled, and it never reads real drafts.

## Product boundary

- `cms/` is the complete CMS application: its own login/session, API, project registry, documents, history, upload directory, install flow and release archive.
- `cms-client.js` is a copy of `cms/connector.js`, the storefront connector. The CMS never includes storefront PHP files and does not access products, 1C, orders, customer accounts or shop sessions.
- Each site has a unique `site_key`, separate draft/published document and history. The current CMS owner can manage all sites; this release is not a multi-tenant SaaS with independent customer accounts.
- `.github/workflows/deploy-cms.yml` deploys only CMS files. The storefront workflow excludes `cms/**`. Media and private configuration are excluded from the portable release; uploads are never synchronized destructively.
- Source is still in the shared Git repository. Independent deployment does not imply a separate repository or separate physical database. Production initially preserves existing database credentials/tables to avoid data loss. Set `CMS_DB_HOST`, `CMS_DB_NAME`, `CMS_DB_USER`, `CMS_DB_PASSWORD` secrets after migrating **all** `ps_cms_*` tables to a separate database if desired. Merely changing credentials is not migration.

## Upgrade from v1

No owner recreation, password reset or content replacement. After owner login the API creates `ps_cms_sites` if missing and registers the original project with `INSERT IGNORE`. Original documents and history remain untouched. Legacy documents stay valid without `layout`; visual layout is added on first visual save. Keep field IDs and binding defaults stable. The visual connector snapshot lives in `private/templates.json` and is separate from stored drafts.

## Independent install

1. Download the `content-cms-standalone` release artifact or run `bash cms/tools/package.sh /absolute/new/output-directory`. Unzip onto a PHP/MySQL host in any directory or domain. The portable build starts with a generic site and contains no ProfiSport bindings, demo content, credentials or media.
2. Copy `private/config.example.php` to `private/config.php`; set your own DB credentials, initial site key/name/URL and **absolute HTTPS** `media_url`.
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

Implemented: debounced draft autosave in both editors, sequential revision handling, preservation of edits made during requests, undo preservation, and pause on save errors/conflicts. Manual saves and site changes wait for active autosaves. Demo remains memory-only.

Verified locally: `node cms/tests/autosave.js`, `node cms/tests/blocks.js`, JS syntax checks, and `node cms/tests/autosave-browser.cjs` with Playwright 1.62.1 / Chromium. The browser suite runs actual editor scripts against an isolated in-memory API; it is not evidence of PHP/MySQL or production verification. Playwright must be available through Node module resolution; install its Chromium browser before running the browser suite.

Still required: recovery after a closed/crashed session and explicit conflict recovery; site-scoped user roles and publishing permissions; dynamic collections; synchronized global components. The existing research roadmap also describes SEO/sitemap, design controls, portability and operational verification; confirm their intended release scope against the original project discussion before claiming completion. Full database integration, browser acceptance, release packaging and deployment remain to be verified for the final release.
