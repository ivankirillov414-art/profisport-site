<?php
// LOCAL HTTP TEST ROUTER ONLY; never deploy this file as a production entry point.
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$prefix = crm_config('base_path');
if (!is_string($path) || !str_starts_with($path, $prefix . '/')) { http_response_code(404); exit; }
$relative = substr($path, strlen($prefix));
if ($relative === '/api/index.php') { require KCRM_ROOT . '/public/api/index.php'; exit; }
if ($relative === '/setup.php') { require KCRM_ROOT . '/public/setup.php'; exit; }
http_response_code(404);
