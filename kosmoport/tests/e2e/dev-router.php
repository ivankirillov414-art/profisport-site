<?php
declare(strict_types=1);
// Test-server mount adapter only; delegates to real production PHP entry points.
$root = realpath(__DIR__ . '/../../public');
$base = rtrim(getenv('KCRM_QA_BASE_PATH') ?: '/kosmoport', '/');
$url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!is_string($url) || !str_starts_with($url, $base . '/')) { http_response_code(404); exit; }
$relative = rawurldecode(substr($url, strlen($base)));
if (str_contains($relative, "\0") || preg_match('~(^|[/\\\\])\.\.([/\\\\]|$)~', $relative)) { http_response_code(404); exit; }
$file = realpath($root . '/' . ltrim($relative, '/'));
if ($file && is_dir($file)) $file = realpath($file . '/index.php');
if (!$file || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) { http_response_code(404); exit; }
if (str_ends_with(strtolower($file), '.php')) {
    $_SERVER['SCRIPT_NAME'] = $base . '/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root)+1));
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    $_SERVER['SCRIPT_FILENAME'] = $file;
    require $file;
    exit;
}
$type = match(strtolower(pathinfo($file, PATHINFO_EXTENSION))) { 'js'=>'text/javascript; charset=utf-8','css'=>'text/css; charset=utf-8','svg'=>'image/svg+xml','png'=>'image/png','jpg','jpeg'=>'image/jpeg',default=>'application/octet-stream' };
header('Content-Type: ' . $type);
header('X-Content-Type-Options: nosniff');
readfile($file);
