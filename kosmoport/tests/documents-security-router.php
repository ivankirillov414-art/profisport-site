<?php
declare(strict_types=1);

// An access key protects only this disposable test listener. It NEVER grants
// an application role or substitutes for real core session/CSRF checks.
$key = getenv('KCRM_DOCUMENTS_SECURITY_KEY');
if (PHP_SAPI !== 'cli-server' || !$key || !hash_equals($key, $_SERVER['HTTP_X_DOCUMENTS_SECURITY_KEY'] ?? '') || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404); exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$prefix = crm_config('base_path');
if ($path === $prefix . '/api/index.php') { require KCRM_ROOT . '/public/api/index.php'; exit; }
if ($path === $prefix . '/setup.php') { require KCRM_ROOT . '/public/setup.php'; exit; }
http_response_code(404);
