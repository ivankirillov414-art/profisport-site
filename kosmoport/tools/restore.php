<?php
declare(strict_types=1);
require_once __DIR__ . '/backup-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$args = getopt('', ['config:', 'input:', 'target-db:', 'confirm-empty-target:', 'help']);
if (isset($args['help'])) {
    echo "Local CLI only. Target must be a separately named, empty, pre-migrated database.\n";
    echo "php tools/restore.php --config=/private/target.php --input=/private/backup.zip --target-db=NEW_DB --confirm-empty-target=NEW_DB\n";
    echo "There is no overwrite, truncate, schema-import or drop option.\n";
    exit(0);
}
try {
    foreach (['config', 'input', 'target-db', 'confirm-empty-target'] as $key) {
        if (!is_string($args[$key] ?? null) || $args[$key] === '') {
            kcrm_transfer_fail('Нужны --config, --input, --target-db и --confirm-empty-target.');
        }
    }
    if (!hash_equals($args['target-db'], $args['confirm-empty-target'])) {
        kcrm_transfer_fail('Подтверждение имени целевой базы не совпадает.');
    }
    $config = kcrm_transfer_load_config($args['config']);
    $result = kcrm_backup_restore(kcrm_transfer_connect($config), $args['input'], kcrm_transfer_config_storage($config), $args['target-db']);
    echo kcrm_transfer_json(['ok' => true, 'operation' => 'restore', 'result' => $result]) . "\n";
} catch (Throwable $e) {
    $message = $e instanceof PDOException ? 'Восстановление остановлено при обращении к базе. Проверьте пустую целевую схему и ограничения.' : $e->getMessage();
    fwrite(STDERR, kcrm_transfer_json(['ok' => false, 'error' => $message]) . "\n");
    exit(1);
}
