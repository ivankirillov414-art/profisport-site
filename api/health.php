<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';

header('Cache-Control: no-store');
try {
    $pdo->query('SELECT 1')->fetchColumn();
    $active = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE is_active=1')->fetchColumn();
    json_response([
        'ok' => true,
        'database' => true,
        'catalog_active' => $active,
        'service' => 'profisport-store',
        'time' => gmdate('c'),
    ]);
} catch (Throwable $e) {
    error_log($e->__toString());
    json_response([
        'ok' => false,
        'database' => false,
        'service' => 'profisport-store',
    ], 503);
}
