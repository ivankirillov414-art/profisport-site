# Marketing workspace

An authenticated knowledge and work area for website/marketing staff. Existing CRM operations remain available under “Прежние разделы”; no old records, users, files, or ticket identifiers are deleted.

## Access

Only the `owner` role has default access. Existing admins, managers, cashiers, editors and readers are not automatically enrolled. The owner can select an existing active account as viewer or editor; this does not create accounts or send invitations. Private `excluded_names` additionally block matching people, including owner-role matches. Names in that exclusion list are not exposed in public scripts or API views.

The shared dispatcher enforces session, HTTPS in production, same-origin JSON and CSRF. `marketing/workspace`, `marketing/download`, `marketing/member-candidates`, `marketing/record`, `marketing/dataset-row`, `marketing/members` and `marketing/initialize` enforce workspace access again on the server. Membership changes and initialization require exact `owner`, not the implicit owner/admin expansion in the generic role helper.

## Storage and import

Everything operational lives in configured private storage, `marketing/`. No business data or artwork masters belong in source archives or public assets.

For a new workspace, the exact owner can upload a reviewed ZIP through the normal CRM session. `POST marketing/upload-bundle` accepts multipart `bundle` and `expected_revision`. It stages only `workspace.json` and the four named JPEG previews, at most 8 MiB compressed/expanded and 10 entries, with strict paths, types, JPEG dimensions, approved-image SHA256 pins and shared seed validation. ZIP entries must be files, without a directory wrapper, folder entries, README or EPS masters. The archive cannot grant membership. Preview integrity pins live in `approved-previews.php`; a trusted server owner can override the map through private config `marketing.approved_preview_sha256`, not through the upload or JSON seed.

After upload the UI shows counts for review; creation is a separate action. `POST marketing/initialize` with `{expected_revision:1,confirm:true}` validates the seed again and refuses to overwrite any existing `workspace.json`, including an empty one. Staging and initialization share the same bounded private lock. A nonce staging directory is validated completely before publication; the import marker is published last so initialization cannot observe half a bundle. Existing workspace, staged import or preview directory are never overwritten. Ordinary failure cleans only the attempt's own files; an interrupted process may leave an orphan preview directory requiring owner review rather than destructive automatic recovery.

Where normal private storage upload is available, the reviewed seed may alternatively be placed as **private** `marketing/import.json` plus lightweight JPEG previews in `marketing/previews/`. Never write over a live workspace to repeat an import.

The seed's private asset map controls downloads; arbitrary browser paths are never accepted. Preview and master availability are reported independently. A locally stored EPS master is not advertised as downloadable until actually present in protected storage. Do not raise the established upload limit or publish large EPS files to Git/free hosting merely to populate the library.

Workspace updates use an exclusive bounded file lock, a global expected revision and an atomic temporary-file replacement. Conflicts preserve the submitted form. Old records can be archived and restored. Address-source fields are retained; current work, assignee, next step, next date and verified visit status are saved separately. A “visited” mark requires a contact date. Minimal revision history is kept privately without note bodies or source contents; the central audit is also attempted.

## Measurement

Actual visitor counts stay null when unmeasured. Forecast rows show their explicit inputs and assumptions. Unique reach is not inferred from radio spots, and campaign audiences are not automatically summed. Provider offers are alternatives, not active bookings or paid spend. A2 print adaptation and physical print proof remain distinct states.

## Verification

Sanitized CI checks are `php tests/marketing-acl.php` (28 checks) and `php tests/marketing-bundle.php` (50 checks; ZIP extension required). The only image fixture is a tiny generated solid-colour JPEG with a fixture-specific private config integrity pin; no business contacts, private seed or artwork are in these tests. They cover authorization, source/list shape validation, nonfinite JSON numbers, hostile ZIP paths/types/limits, approved-image integrity, common lock, separate review/initialize and no overwrite.

Coordinator staging also includes `tests/marketing-service.php` outside the deployment source for the actual prepared seed, using isolated file storage and a stubbed identity. It changes no production accounts or records. Existing core authentication/CSRF was not replaced. Browser layout, positive native multipart upload and authenticated production acceptance must be verified separately by the coordinator.
