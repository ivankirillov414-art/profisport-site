# Standalone CMS

PHP 8.1+ / MySQL (InnoDB). No paid service, framework build, or connection to Supabase. The `cms/` directory is the standalone application; `cms-client.js` is the ProfiSport adapter. The core does not import any storefront PHP code or access catalog, order, customer, or 1C tables.

## Included

- Separate owner login and session; inactivity timeout, CSRF protection, login rate limiting.
- Five connected pages: home, shop, workshop, buyer information, service.
- Plain text, links, images, banner backgrounds; visibility and order of mapped top-level blocks.
- Draft, preview, explicit publish, revision history, restore into draft; concurrent-edit protection.
- JPG/PNG/WebP uploads with server-side validation; private API errors do not expose credentials.
- Public API serves published content only. The storefront keeps its original HTML if the API times out or has not been set up.

This is a content CMS. Product inventory, 1C, orders, and customers remain in the existing shop admin. It does not currently add arbitrary new page templates or edit product records. Dynamic catalog content is intentionally outside its editing map.

## Initial installation on the current host

1. Deploy this branch through the existing deployment workflow. It generates `cms/private/config.php` from the existing secret without committing credentials.
2. Sign in as the shop owner and open **Контент сайта** (`admin/cms-setup.php`). Create a separate CMS username and a password of at least 12 characters.
3. Setup creates only `ps_cms_*` tables and the initial draft. It does not change product data and does not publish anything automatically.
4. Open `/cms/`, sign in, edit, preview, save, and publish.

The first owner must be created by the user; no default password is shipped. Setup is permanently closed after the owner row exists. Subsequent CMS sign-in has no dependency on shop sign-in. Database users should be restricted to CMS tables or a separate database where the host permits this. The current deployment uses the existing database account with isolated table names.

## Separate hosting / migration

Copy `cms/`, media files, and a dump of all `ps_cms_*` tables. Set its own `private/config.php` using the example. Update `site_url`, `media_url`, and the API path in `cms-client.js`. For a different origin, keep a same-origin reverse proxy on the storefront for the public endpoint and update the preview origin allowlist deliberately. Current preview and session path are configured for `/cms/` on the same origin. This release is not configured for arbitrary cross-origin deployment.

For installation without ProfiSport, configure the database and invoke `cms_install()` from a private CLI or trusted provisioning process. Never expose an unauthenticated installer. Bindings are the connector-specific map in `private/bindings.json`, not hard-coded in the core.

## Backups / rollback

Back up `ps_cms_*` with the host's MySQL export and `cms/media/` with its file backup, alongside the private configuration. Revision history is not an off-server backup. Restore a content revision in the editor, then publish it. To disconnect the CMS, remove the `cms-client.js` script tag from the five connected pages; original HTML remains intact. Keep `cms/media/` excluded from destructive deploy synchronisation.

## Validation

Run `php cms/tests/validation.php`, `php cms/tests/integration.php` against a dedicated test database configured via `cms/private/config.php`, PHP lint, and Node syntax checks. Integration testing creates CMS tables in that explicitly configured database; never use production config for tests. CI uses a disposable MariaDB service. The browser smoke test must cover owner login, draft save, draft preview, published text, restore, image upload, and narrow-screen layout.

## Deployment status

Implementation and automated checks are separate from live installation. A successful repository push is not proof of deployment or owner setup. Verify the deployment job and complete the owner-creation flow before presenting CMS as active.
