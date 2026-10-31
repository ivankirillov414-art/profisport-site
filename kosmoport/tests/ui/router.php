<?php
// Development-only static/fixture router. Never included in the public hosting package.
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/kosmoport/crm/' || $path === '/kosmoport/crm/index.php') {
    $page = file_get_contents($root . '/public/crm/index.php');
    $page = preg_replace('/^<\?php.*?\?>/s', '', $page);
    $page = str_replace('<body>', '<body><div style="position:sticky;top:0;z-index:100;background:#fff2c4;color:#513b00;padding:8px;text-align:center">Локальный тестовый стенд · все записи вымышлены · данные не отправляются на сервер</div>', $page);
    $page = str_replace('<script type="module"', '<script src="../assets/fixture.js"></script><script type="module"', $page);
    header('Content-Type: text/html; charset=utf-8'); echo $page; return true;
}
if ($path === '/kosmoport/assets/fixture.js') {
    header('Content-Type: application/javascript; charset=utf-8'); readfile(__DIR__ . '/fixture.js'); return true;
}
if (preg_match('~^/kosmoport/assets/(crm[\w.-]*\.(css|js))$~', $path, $match)) {
    $file = $root . '/public/assets/' . $match[1];
    if (is_file($file)) { header('Content-Type: ' . ($match[2] === 'css' ? 'text/css' : 'application/javascript') . '; charset=utf-8'); readfile($file); return true; }
}
http_response_code(404); echo 'Local fixture: route not found';return true;
