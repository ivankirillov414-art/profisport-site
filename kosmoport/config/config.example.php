<?php
declare(strict_types=1);

// Copy to PRIVATE config/config.php, or set KCRM_CONFIG to an absolute private path.
// Never deploy populated config or storage as public assets or commit credentials.
return [
    'environment' => 'production',
    'app_name' => 'Космопорт CRM',
    'base_path' => '/kosmoport',
    'timezone' => 'Asia/Yekaterinburg',
    'storage_path' => dirname(__DIR__) . '/storage',
    'app_key' => '', // Generate with: php bin/core-key.php
    'setup_key_hash' => '', // SHA-256 printed by core-key.php. Empty disables installation.
    'storage' => [
        // Enable BOTH only after actual HTTP denial is verified on the target host.
        // Storage under a web root also needs a deny-all .htaccess at its root.
        'allow_webroot_storage' => false, 'http_denial_verified' => false,
    ],
    'http' => ['max_json_bytes' => 8388608, 'max_multipart_bytes' => 25165824],
    'website' => [
        'public_url' => '', // HTTPS URL of the exhibition, no trailing slash.
        'requests_enabled' => false, // Enable after migration 050, privacy and staff acceptance.
        'organizer_name' => '', // Verified legal name of the personal-data operator.
        'privacy_url' => '', // Published HTTPS policy approved by the organizer.
        'contact_email' => '',
        'contact_phone' => '',
    ],
    'payments' => [
        // Inventory for owner setup. Fields alone do not enable/verify payments.
        'provider' => '', // yookassa, tbank, alfabank, hosted_link; leave empty until selected.
        'mode' => 'disabled', // disabled, test, live.
        'merchant_status' => 'not_started', // Declared setup status, NOT provider verification.
        'merchant_id' => '', 'secret_key' => '', // ONLY in PRIVATE config; never commit real values.
        'return_url' => '', 'webhook_url' => '', 'offer_url' => '',
        'fiscalization' => '', // provider_receipts or own_online_register after selection.
    ],
    'database' => [
        'driver' => 'mysql', 'host' => 'YOUR_MYSQL_HOST', 'port' => 3306,
        'name' => 'YOUR_DATABASE_NAME', 'username' => 'YOUR_DATABASE_USER',
        'password' => '', 'charset' => 'utf8mb4',
    ],
    'session' => [
        'name' => 'KCRMSESSID', 'secure' => 'auto', 'same_site' => 'Lax',
        'idle_seconds' => 1800, 'absolute_seconds' => 43200,
    ],
];
