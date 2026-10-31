<?php
declare(strict_types=1);
require_once __DIR__ . '/backup-lib.php';
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$args = getopt('', ['config:', 'target-db:', 'confirm-empty-target:', 'help']);
if (isset($args['help'])) {
    echo "Local CLI. Install trusted source migrations into a new database with ZERO tables; no owner is created.\n";
    echo "php tools/restore-schema.php --config=/private/target.php --target-db=NEW_DB --confirm-empty-target=NEW_DB\n";
    exit(0);
}
try {
    foreach (['config', 'target-db', 'confirm-empty-target'] as $key) {
        if (!is_string($args[$key] ?? null) || $args[$key] === '') {
            kcrm_transfer_fail('Нужны --config, --target-db и --confirm-empty-target.');
        }
    }
    if (!hash_equals($args['target-db'], $args['confirm-empty-target'])) {
        kcrm_transfer_fail('Подтверждение имени целевой базы не совпадает.');
    }
    $result = kcrm_restore_prepare_schema(kcrm_transfer_connect(kcrm_transfer_load_config($args['config'])), $args['target-db']);
    echo kcrm_transfer_json(['ok' => true, 'operation' => 'prepare-empty-target', 'result' => $result]) . "\n";
} catch (Throwable $e) {
    $message = $e instanceof PDOException ? 'Подготовка целевой схемы остановлена. Проверьте только новую базу; DDL мог примениться частично.' : $e->getMessage();
    fwrite(STDERR, kcrm_transfer_json(['ok' => false, 'error' => $message]) . "\n");
    exit(1);
}
