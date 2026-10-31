<?php
declare(strict_types=1);
require_once __DIR__ . '/backup-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$args = getopt('', ['config:', 'output:', 'verify:', 'help']);
if (isset($args['help'])) {
    echo "Local CLI only. On shared hosting use the temporary authenticated browser helper.\n";
    echo "php tools/backup.php --config=/private/config.php --output=/private/new-backup.zip\n";
    echo "php tools/backup.php --verify=/private/backup.zip\n";
    exit(0);
}
try {
    if (isset($args['verify'])) {
        $manifest = kcrm_backup_verify((string)$args['verify']);
        echo kcrm_transfer_json(['ok' => true, 'operation' => 'verify', 'tables' => count($manifest['tables']), 'entries' => count($manifest['entries']), 'schema_version' => $manifest['schema_version']]) . "\n";
    } else {
        if (!is_string($args['config'] ?? null) || !is_string($args['output'] ?? null)) {
            kcrm_transfer_fail('Нужны --config и --output либо --verify.');
        }
        $config = kcrm_transfer_load_config($args['config']);
        $manifest = kcrm_backup_create(kcrm_transfer_connect($config), kcrm_transfer_config_storage($config), $args['output'], [
            'application_version' => (string)($config['version'] ?? 'unknown'),
        ]);
        echo kcrm_transfer_json(['ok' => true, 'operation' => 'backup', 'tables' => count($manifest['tables']), 'entries' => count($manifest['entries']), 'sha256' => hash_file('sha256', $args['output']), 'schema_version' => $manifest['schema_version']]) . "\n";
    }
} catch (Throwable $e) {
    $message = $e instanceof PDOException ? 'Ошибка доступа к базе. Проверьте приватный конфиг, версию схемы и права.' : $e->getMessage();
    fwrite(STDERR, kcrm_transfer_json(['ok' => false, 'error' => $message]) . "\n");
    exit(1);
}
