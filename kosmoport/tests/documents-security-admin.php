<?php
declare(strict_types=1);

// CLI-only administration of newly created, name-scoped loopback QA databases.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    if ($argc !== 4) throw new RuntimeException('Arguments required.');
    [$runtimeFile, $database, $mode] = array_slice($argv, 1);
    if (!preg_match('/\Akcrm_documents_security_[a-f0-9]{12}\z/D', $database)) throw new RuntimeException('Unsafe QA database name.');
    $runtime = json_decode(ltrim((string)file_get_contents($runtimeFile), "\xEF\xBB\xBF"), true, flags: JSON_THROW_ON_ERROR);
    $runtimePath = str_replace('\\', '/', $runtime['runtime_path'] ?? '');
    if (($runtime['host'] ?? '') !== '127.0.0.1' || (!str_contains($runtimePath, '/tests/migration-runtime/') && !str_contains($runtimePath, '/tests/documents-security-runtime/'))) throw new RuntimeException('Not the isolated loopback runtime.');
    $port = filter_var($runtime['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($port === false) throw new RuntimeException('Invalid loopback port.');
    $db = new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4', $runtime['user'], $runtime['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if ($mode === 'create') {
        $db->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $result = ['created' => true, 'server' => $db->query('SELECT VERSION()')->fetchColumn()];
    } elseif ($mode === 'drop') {
        $db->exec('DROP DATABASE `' . $database . '`');
        $result = ['dropped' => true];
    } else {
        $db->exec('USE `' . $database . '`');
        if ($mode === 'reset-rates') {
            $db->exec('DELETE FROM kcrm_auth_rate_limits');
            $result = ['reset' => true];
        } elseif ($mode === 'stats') {
            $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            $result = ['tables' => $tables, 'database_engine' => $db->query('SELECT VERSION()')->fetchColumn(), 'counts' => [], 'migrations' => [], 'owners_hashed' => true, 'audits' => []];
            foreach (['kcrm_users','kcrm_setup','kcrm_documents','kcrm_document_versions','kcrm_audit'] as $table) {
                $result['counts'][$table] = in_array($table, $tables, true) ? (int)$db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() : 0;
            }
            if (in_array('kcrm_users', $tables, true)) {
                foreach ($db->query("SELECT password_hash FROM kcrm_users WHERE role='owner'")->fetchAll(PDO::FETCH_COLUMN) as $hash) {
                    $result['owners_hashed'] = $result['owners_hashed'] && str_starts_with($hash, '$2y$') && strlen($hash) === 60;
                }
            }
            if (in_array('kcrm_migrations', $tables, true)) $result['migrations'] = $db->query('SELECT version FROM kcrm_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('kcrm_audit', $tables, true)) $result['audits'] = $db->query('SELECT action,actor_id,entity_type,entity_id FROM kcrm_audit ORDER BY created_at,id')->fetchAll();
        } else throw new RuntimeException('Unknown QA operation.');
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable) {
    fwrite(STDERR, "Isolated document security database operation failed; no credentials printed.\n");
    exit(1);
}
