# Protected document service

Entry point: `app/modules/documents.php`, loaded by the common authenticated API dispatcher. See `handoffs/documents.md` for the schema, role matrix, API fields, validation profile and verification receipt.

The service requires PHP 8.2+, PDO MySQL, fileinfo, DOM and ZipArchive. It adds no package dependency. Core owns the session, CSRF, configuration, audit helper and safe global error handler.

Private configuration can optionally contain:

```php
'documents' => [
    'max_upload_bytes' => 10 * 1024 * 1024,
    'allow_webroot_storage' => false,
],
```

Keep the document override false when storage is outside the document root. For a shared-host fallback, omit that document-specific override to inherit the core's explicitly verified `storage.allow_webroot_storage` AND `storage.http_denial_verified` policy; an explicit false remains a stricter opt-out. Core must also accept the root deny guard. Guard-file presence alone is not evidence of HTTP denial, and enabling any flag requires a real-host check.

Backups must preserve both `kcrm_documents` / `kcrm_document_versions` and every immutable object under `crm_storage_path('documents')`. The public API has no direct object URL, rename, overwrite or delete path. Download always checks permissions and the stored size/hash.

Office formats are restricted to macro-free, bounded OOXML packages. PDF object streams, encryption, scripts and active attachments are refused by the restricted PDF profile; save such files as ordinary PDF without object streams. Plain-text formats require UTF-8. This is format validation, not antivirus scanning.
