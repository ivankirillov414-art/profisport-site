# Website enquiries and hosted checkout

The public site supports an enquiry about a family visit or a group visit. This is separate from a paid order, a reservation and an admission. All intake remains disabled in existing installations until explicitly configured.

## Enable enquiries

1. Back up the existing database and verify a restore on a separate installation.
2. Check the pending migration list and checksums. Run `php bin/core-migrate.php` using the private production configuration during the installation or maintenance window. Migration `050_website_requests.sql` only creates `kcrm_visit_requests`. It does not alter existing business tables. Ordinary website GET requests never run migrations.
3. Publish the actual organizer's privacy policy over HTTPS and set the optional `website` private configuration:

```php
'website' => [
    'public_url' => 'https://YOUR-CONFIRMED-DOMAIN',
    'requests_enabled' => false,
    'organizer_name' => 'THE ACTUAL ORGANIZER',
    'privacy_url' => 'https://YOUR-CONFIRMED-DOMAIN/privacy',
],
```

4. Confirm the organizer, contacts, policy and access roles, then change `requests_enabled` to `true`. Both a valid HTTPS policy URL and a nonempty organizer are required, and the table must exist. Missing settings, a missing migration or database unavailability keep the forms closed.
5. On an isolated acceptance environment, exercise the real form and check that the enquiry appears in CRM's **Заявки сайта**. Owner, admin and manager can read/change status. The editor can read but cannot change status. Cashier and anonymous users cannot read contacts. No email/SMS is sent automatically.

Requests use same-origin cookies, CSRF and Origin checks, explicit consent, bounded fields, valid event dates, integer guest counts, a honeypot and a five-new-requests-per-IP-hash limit per fifteen minutes. The server does not store the raw IP address. A repeated request ID in the same browser session returns the original enquiry, with `paid=false` and `reserved=false`.

Private identity, storage and DB credentials stay outside the release ZIP. The frontend sends no contact data to an analytics service. Local `kosmoport:funnel` events only identify the step; UTM source/medium/campaign are saved with an enquiry. No external analytics service is configured by this release.

## Organizer phones

Public content and the website editor expose `contact.phone` (primary) and `contact.phone_secondary` (additional), each as a separate bounded string. The primary call action uses the first callable number; the other number has its own link. The confirmed defaults are the mobile `+7 (992) 080-02-28` and local Orenburg `58-00-22`, linked separately as `tel:+79920800228` and `tel:+73532580022`. No messenger accounts or corporate telecom contract are implied.

Legacy saved content without `phone_secondary` receives only an empty additional field when validated; its existing primary number and other contact values remain unchanged. Explicit empty overrides are preserved. Editing/saving/publishing both phone fields uses the existing website revision and role checks; no schema migration or production data rewrite is needed. Ambiguous combined phone strings are displayed without a generated dial link rather than concatenated into a new number. The before-release public content readback had revision 0 and empty contacts, so the packaged defaults apply on that installation.

## Hosted payment link

The website editor already exposes `tickets.price`, `tickets.sales_open` and `tickets.checkout_url`. `public/checkout.php` reads the published configuration on every request. It only redirects with HTTP 303 when sales are enabled, a numeric price exists and the destination is an absolute HTTPS URL without embedded credentials. Otherwise it returns HTTP 503 and a clear closed-sales page. It never marks a payment or issues a ticket.

Use a merchant-controlled hosted payment page only after the price, product, organizer, sale/refund terms and fiscalization have been approved. The current link adapter does not transfer the selected quantity/date to a payment provider. The visitor must confirm those final parameters on the provider page. This limitation is visible beside the button.

Automatic ticket sales require the selected provider's order/payment API, server-side prices, verified payment notifications or API status readback, idempotent ticket issuing, receipt/fiscalization setup, refund handling and delivery tests. A success URL or browser return is never proof of payment. Provider credentials must remain in private configuration.

## Deployment and rollback

The source package is portable: PHP 8.2+, PDO MySQL, MySQL/MariaDB and the existing CRM extensions are sufficient. On 1gb use the `public` directory as the website document root and keep its siblings `app`, `config`, `storage`, `migrations`, `bin` and `tools` private. Where a shared host forces an in-webroot layout, perform the actual host HTTP-denial gates already described in INSTALL/HOSTING before activating document storage.

On existing InfinityFree the adapter preserves populated configuration and storage; absent `website` settings mean enquiries remain closed. This release does not run an automatic production migration and does not modify payments, admissions or FastGo systems.

For a rollback, disable `requests_enabled`, preserve the new request table and backup, and restore the preceding application bytes. Do not drop a table containing enquiries. A schema rollback is unnecessary because the new table is additive. The ordinary CLI migration runner checks previously applied file checksums and does not reapply them.

## Evidence and acceptance

Run `tests/website.php` and `tests/website-funnel.php` for the content and security contract. The new migration has also been exercised on a new local MariaDB 11.4.13 installation, including an upgrade from the preceding migration set and a no-op replay. Separate actual HTTP tests covered persistence, session/CSRF/Origin, deduplication, role access, status conflicts, rate limits and hosted redirect without accepting money.

These tests do not prove deployment, target PHP support, live private-path denial, visual responsiveness or payment-provider operation. Those require a fresh release-specific public readback, browser acceptance and, after provider selection, a merchant test environment.
