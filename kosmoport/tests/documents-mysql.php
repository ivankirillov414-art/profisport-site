<?php
declare(strict_types=1);

// Isolated loopback test database only. Never accepts SQL, arbitrary database
// names or credentials from HTTP. Private runtime credentials are not printed.
function documents_test_mysql(array $arguments): array
{
    [$runtimeFile, $database, $mode] = $arguments;
    if (!preg_match('/\Akcrm_documents_test_[a-f0-9]{12}\z/D', $database)) {
        throw new RuntimeException('Invalid isolated document database name.');
    }
    $runtime = json_decode(ltrim((string)file_get_contents($runtimeFile), "\xEF\xBB\xBF"), true, flags: JSON_THROW_ON_ERROR);
    $runtimePath = str_replace('\\', '/', $runtime['runtime_path'] ?? '');
    if (($runtime['host'] ?? '') !== '127.0.0.1' || (!str_contains($runtimePath, '/tests/migration-runtime/') && !str_contains($runtimePath, '/tests/documents-security-runtime/'))) {
        throw new RuntimeException('Not an isolated loopback runtime.');
    }
    $port = filter_var($runtime['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($port === false) {
        throw new RuntimeException('Invalid loopback port.');
    }
    $db = new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4', $runtime['user'], $runtime['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    if ($mode === 'init') {
        $db->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $db->exec('USE `' . $database . '`');
        $migration = file_get_contents(__DIR__ . '/../migrations/020_documents.sql');
        foreach (explode(';', $migration) as $statement) {
            if (trim($statement) !== '') {
                $db->exec($statement);
            }
        }
        $db->exec('CREATE TABLE documents_test_audit (id BIGINT PRIMARY KEY AUTO_INCREMENT,action TEXT,entity_id TEXT,details TEXT,role TEXT) ENGINE=InnoDB');
        return ['status' => 'ready', 'server' => $db->query('SELECT VERSION()')->fetchColumn(), 'database' => $database];
    }
    if ($mode === 'drop') {
        $db->exec('DROP DATABASE `' . $database . '`');
        return ['status' => 'dropped'];
    }
    $db->exec('USE `' . $database . '`');
    if ($mode === 'audit') {
        return ['items' => $db->query('SELECT action,entity_id,details,role FROM documents_test_audit ORDER BY id')->fetchAll(PDO::FETCH_NUM)];
    }
    throw new RuntimeException('Unknown fixture operation.');
}

if (PHP_SAPI === 'cli') {
    try {
        if ($argc !== 4) {
            throw new RuntimeException('Expected private runtime, isolated database and operation.');
        }
        echo json_encode(documents_test_mysql(array_slice($argv, 1)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "\n";
    } catch (Throwable) {
        fwrite(STDERR, "Document MySQL fixture failed; credentials were not printed.\n");
        exit(1);
    }
}
