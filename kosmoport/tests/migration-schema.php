<?php
declare(strict_types=1);

/* Actual source-schema transfer test. This destroys ONLY the two explicitly
 * named loopback fixture schemas after --confirm-fixture-reset is supplied.
 * Coordinate with the web fixture before running: it uses the same databases.
 * No application bootstrap, live config, HTTP request or SQL from a ZIP is used.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/tools/backup-lib.php';
$options = getopt('', ['runtime-config:', 'confirm-fixture-reset', 'help']);
if (isset($options['help'])) {
    echo "Local isolated test only. Wait until both shared fixture databases are free.\n";
    echo "php tests/migration-schema.php --confirm-fixture-reset [--runtime-config=/private/local-runtime.private.json]\n";
    exit(0);
}
if (!array_key_exists('confirm-fixture-reset', $options)) {
    fwrite(STDERR, "Refused: requires --confirm-fixture-reset after the web fixture releases both databases.\n");
    exit(2);
}
$trustedRoot = dirname(__DIR__);
$runtimePath = $options['runtime-config'] ?? __DIR__ . '/migration-runtime/local-runtime.private.json';
$runtime = [];
$source = $target = null;
$fixtureRoot = null;
$schemasOwned = false;
$exitCode = 0;
$phase = 'dependency-checks';
$diagnostics = ['backup_lib_sha256' => hash_file('sha256', $trustedRoot . '/tools/backup-lib.php')];
$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $label);
    }
    $checks++;
};
$refuses = static function (callable $operation, string $label) use ($assert): void {
    try {
        $operation();
    } catch (Throwable) {
        $assert(true, $label);
        return;
    }
    $assert(false, $label);
};
$guardConnection = static function (PDO $db): void {
    $name = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if (!in_array($name, ['kcrm_migration_source_test', 'kcrm_migration_target_test'], true)) {
        throw new RuntimeException('Refused: database is not an exact disposable fixture schema.');
    }
};
$resetSchema = static function (PDO $db) use ($guardConnection): void {
    $guardConnection($db);
    // Names come only from this guarded, disposable schema, never an archive.
    $tables = $db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ($tables as [$name, $type]) {
            if (!preg_match('/\A[a-zA-Z0-9_]+\z/D', $name)) {
                throw new RuntimeException('Unexpected identifier in disposable fixture schema.');
            }
            $db->exec(($type === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . '`' . $name . '`');
        }
    } finally {
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    }
};
$snapshot = static function (PDO $db): array {
    $result = [];
    foreach (kcrm_transfer_tables($db) as $table) {
        $rows = array_map('kcrm_transfer_json', iterator_to_array(kcrm_transfer_encoded_rows($db, $table), false));
        sort($rows, SORT_STRING);
        $result[$table] = $rows;
    }
    return $result;
};
$mutateZip = static function (string $input, string $output, callable $change): void {
    if (!copy($input, $output)) {
        throw new RuntimeException('Could not copy fixture ZIP.');
    }
    $zip = new ZipArchive();
    if ($zip->open($output) !== true) {
        throw new RuntimeException('Could not open fixture ZIP.');
    }
    try {
        $change($zip);
    } finally {
        $zip->close();
    }
};

try {
    if (!is_file($runtimePath)) {
        throw new RuntimeException('Isolated local runtime config is missing.');
    }
    $runtime = json_decode(ltrim((string)file_get_contents($runtimePath), "\xEF\xBB\xBF"), true, 32, JSON_THROW_ON_ERROR);
    if (($runtime['host'] ?? '') !== '127.0.0.1'
        || ($runtime['database_source'] ?? '') !== 'kcrm_migration_source_test'
        || ($runtime['database_target'] ?? '') !== 'kcrm_migration_target_test') {
        throw new RuntimeException('Refused: only exact loopback fixture names are accepted.');
    }
    $port = filter_var($runtime['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($port === false || !is_string($runtime['user'] ?? null) || !is_string($runtime['password'] ?? null)) {
        throw new RuntimeException('Invalid isolated runtime connection fields.');
    }
    $migrationFiles = glob($trustedRoot . '/migrations/*.sql') ?: [];
    sort($migrationFiles, SORT_STRING);
    $migrationHashes = [];
    foreach ($migrationFiles as $file) {
        $migrationHashes[basename($file)] = hash_file('sha256', $file);
    }
    $diagnostics['migration_sha256'] = $migrationHashes;
    foreach (['001_core.sql', '010_workspace.sql', '020_documents.sql', '030_tickets.sql'] as $required) {
        $assert(in_array($required, array_map('basename', $migrationFiles), true), 'actual trusted migration present: ' . $required);
    }
    if (!is_file($trustedRoot . '/app/core/migrations.php') || !function_exists('kcrm_restore_prepare_schema')) {
        throw new RuntimeException('Dependency not ready: trusted Core SQL parser and kcrm_restore_prepare_schema(PDO, exact database, trusted source root) are required.');
    }
    $connect = static function (string $database) use ($runtime, $port): PDO {
        $db = new PDO('mysql:host=127.0.0.1;port=' . $port . ';dbname=' . $database . ';charset=utf8mb4', $runtime['user'], $runtime['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $db->exec("SET time_zone = '+00:00'");
        return $db;
    };
    $phase = 'connect-guarded-fixtures';
    $source = $connect($runtime['database_source']);
    $target = kcrm_transfer_connect(['database' => [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $port,
        'name' => $runtime['database_target'], 'username' => $runtime['user'],
        'password' => $runtime['password'], 'charset' => 'utf8mb4',
    ]]);
    $assert(!$source->getAttribute(PDO::ATTR_STRINGIFY_FETCHES), 'source PDO uses actual Core native numeric fetches');
    $assert((bool)$target->getAttribute(PDO::ATTR_STRINGIFY_FETCHES), 'target PDO uses the actual stringifying transfer connection');
    $guardConnection($source);
    $guardConnection($target);
    $schemasOwned = true;
    $phase = 'reset-disposable-schemas';
    $resetSchema($source);
    $resetSchema($target);
    // This production helper installs only source-controlled SQL using Core's
    // actual parser/journal. Do not replace it with a simplified test schema.
    $phase = 'prepare-actual-source-schema';
    kcrm_restore_prepare_schema($source, $runtime['database_source'], $trustedRoot);
    $diagnostics['source_inventory'] = kcrm_transfer_tables($source);
    $phase = 'prepare-actual-target-schema';
    kcrm_restore_prepare_schema($target, $runtime['database_target'], $trustedRoot);
    $diagnostics['target_inventory'] = kcrm_transfer_tables($target);
    $assert($diagnostics['source_inventory'] === $diagnostics['target_inventory'], 'actual source and target table inventories match exactly before data writes');
    $assert($source->query('SELECT version,checksum FROM kcrm_migrations ORDER BY version')->fetchAll(PDO::FETCH_KEY_PAIR) === $migrationHashes, 'source journal matches every captured actual SQL checksum');
    $assert($target->query('SELECT version,checksum FROM kcrm_migrations ORDER BY version')->fetchAll(PDO::FETCH_KEY_PAIR) === $migrationHashes, 'target journal matches the same captured actual SQL checksums');
    $assert((int)$source->query('SELECT COUNT(*) FROM kcrm_users')->fetchColumn() === 0, 'migrate-only creates no initial owner');
    $assert($target->query('SELECT id,owner_id,completed_at FROM kcrm_setup')->fetchAll(PDO::FETCH_NUM) === [['1', null, null]], 'actual target keeps the trusted pristine setup seed');
    $assert($target->query('SELECT id,touched FROM kcrm_ticket_lock')->fetchAll(PDO::FETCH_NUM) === [['1', '0']], 'actual target keeps the trusted pristine ticket lock seed');
    $assert(count(kcrm_transfer_migrations($source)) === count($migrationFiles), 'actual journal covers every trusted source migration');
    $target->exec("UPDATE kcrm_migrations SET applied_at='2025-01-01 00:00:00'");
    $targetMigrationJournal = kcrm_transfer_migrations($target);
    $assert($targetMigrationJournal !== kcrm_transfer_migrations($source), 'target migration timestamps differ without changing trusted migration identity');

    $phase = 'seed-actual-business-fixtures';
    $fixtureRoot = kcrm_transfer_temp_dir(__DIR__ . '/migration-runtime');
    $sourceStorage = $fixtureRoot . '/source-storage';
    $targetStorage = $fixtureRoot . '/target-storage';
    mkdir($sourceStorage);
    mkdir($targetStorage);
    mkdir($sourceStorage . '/documents');
    $guardBytes = "<?php\nhttp_response_code(404);\nexit;\n";
    file_put_contents($sourceStorage . '/documents/index.php', $guardBytes);
    file_put_contents($targetStorage . '/.htaccess', 'Deny from all');
    mkdir($sourceStorage . '/sessions');
    file_put_contents($sourceStorage . '/sessions/private-session', 'EXCLUDED-FIXTURE-SESSION');
    $actor = '10000000-0000-4000-8000-000000000001';
    $now = '2026-10-31 10:00:00';
    $source->prepare('INSERT INTO kcrm_users (id,name,email,password_hash,role,active,session_version,revision,created_at,updated_at) VALUES (?,?,?,?,?,1,1,1,?,?)')
        ->execute([$actor, 'Владелец фикстуры', 'schema-fixture@example.invalid', 'fixture-hash-not-a-real-password', 'owner', $now, $now]);
    $source->prepare('UPDATE kcrm_setup SET owner_id=?,completed_at=? WHERE id=1 AND owner_id IS NULL AND completed_at IS NULL')->execute([$actor, $now]);
    $source->exec('UPDATE kcrm_ticket_lock SET touched=7 WHERE id=1 AND touched=0');
    $document = str_repeat('ab', 16);
    $source->prepare('INSERT INTO kcrm_documents (id,title,category,current_version,created_by,created_by_name,updated_by,updated_by_name,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$document, 'Договор фикстуры', 'Договоры', 2, $actor, 'Владелец фикстуры', $actor, 'Владелец фикстуры', $now, $now]);
    $blobs = [];
    foreach ([1, 2] as $versionNo) {
        $versionId = str_repeat($versionNo === 1 ? 'cd' : 'ef', 16);
        $storageKey = 'objects/' . substr($document, 0, 2) . '/' . $document . '/' . $versionId . '.blob';
        $bytes = "%PDF-1.4\nКосмопорт fixture version " . $versionNo . "\n\x00\xff\n%%EOF\n";
        $path = $sourceStorage . '/documents/' . $storageKey;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $bytes);
        $source->prepare('INSERT INTO kcrm_document_versions (id,document_id,version_no,title,category,original_name,extension,mime,size_bytes,sha256,storage_key,uploaded_by,uploaded_by_name,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$versionId, $document, $versionNo, 'Договор фикстуры', 'Договоры', 'fixture-v' . $versionNo . '.pdf', 'pdf', 'application/pdf', strlen($bytes), hash('sha256', $bytes), $storageKey, $actor, 'Владелец фикстуры', $now]);
        $blobs[$storageKey] = $bytes;
    }
    $beforeTarget = $snapshot($target);
    $archive = $fixtureRoot . '/actual-schema.zip';
    $phase = 'backup-actual-source-schema';
    $manifest = kcrm_backup_create($source, $sourceStorage, $archive, ['application_version' => 'actual-source-schema-fixture']);
    $diagnostics['archive_inventory'] = array_keys($manifest['tables']);
    $assert(array_keys($manifest['tables']) === kcrm_transfer_tables($source), 'backup includes every table of the actual current schema');
    $assert($manifest['tables']['kcrm_document_versions']['rows'] === 2, 'both actual document version rows included');
    $assert(!isset($manifest['entries']['storage/documents/index.php']), 'exact fixed PHP storage guard is excluded');
    $assert(!isset($manifest['entries']['storage/sessions/private-session']), 'session state is excluded');
    $assert(kcrm_backup_verify($archive)['schema_version'] === $manifest['schema_version'], 'actual-schema archive verifies');

    $phase = 'missing-blob-and-document-metadata-refusals';
    $missingKey = array_key_last($blobs);
    unlink($sourceStorage . '/documents/' . $missingKey);
    $refuses(fn() => kcrm_backup_create($source, $sourceStorage, $fixtureRoot . '/missing-source-blob.zip'), 'backup creation rejects a missing actual document version blob');
    $assert($snapshot($target) === $beforeTarget, 'missing source blob does not change target rows');
    file_put_contents($sourceStorage . '/documents/' . $missingKey, $blobs[$missingKey]);
    file_put_contents($sourceStorage . '/documents/index.php', $guardBytes . '// not the fixed guard');
    $refuses(fn() => kcrm_backup_create($source, $sourceStorage, $fixtureRoot . '/modified-guard.zip'), 'only the exact trusted PHP guard is excluded');
    file_put_contents($sourceStorage . '/documents/index.php', $guardBytes);

    $missingArchive = $fixtureRoot . '/missing-archive-blob.zip';
    $mutateZip($archive, $missingArchive, static function (ZipArchive $zip) use ($missingKey): void {
        $manifest = json_decode($zip->getFromName('manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        $entry = 'storage/documents/' . $missingKey;
        $zip->deleteName($entry);
        unset($manifest['entries'][$entry]);
        $zip->addFromString('manifest.json', kcrm_transfer_json($manifest) . "\n");
    });
    $refuses(fn() => kcrm_backup_verify($missingArchive), 'verify rejects a missing referenced blob despite consistent ZIP entry inventory');
    $refuses(fn() => kcrm_backup_restore($target, $missingArchive, $targetStorage, $runtime['database_target']), 'restore rejects missing referenced blob before writes');
    $assert($snapshot($target) === $beforeTarget, 'missing archive blob leaves every target table unchanged');
    $assert(iterator_count(new FilesystemIterator($targetStorage, FilesystemIterator::SKIP_DOTS)) === 1, 'preflight refusal leaves only the original target protection stub');

    $tamperedBlobArchive = $fixtureRoot . '/tampered-referenced-blob.zip';
    $mutateZip($archive, $tamperedBlobArchive, static function (ZipArchive $zip) use ($missingKey): void {
        $manifest = json_decode($zip->getFromName('manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        $entry = 'storage/documents/' . $missingKey;
        $bytes = 'changed fixture blob with internally consistent manifest';
        $zip->addFromString($entry, $bytes);
        $manifest['entries'][$entry] = ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        $zip->addFromString('manifest.json', kcrm_transfer_json($manifest) . "\n");
    });
    $refuses(fn() => kcrm_backup_verify($tamperedBlobArchive), 'document row size/hash must match blob even when ZIP hashes are internally consistent');
    $refuses(fn() => kcrm_backup_restore($target, $tamperedBlobArchive, $targetStorage, $runtime['database_target']), 'inconsistent document blob is refused before target writes');
    $assert($snapshot($target) === $beforeTarget, 'tampered document blob leaves every target table unchanged');

    $tamperedSizeArchive = $fixtureRoot . '/tampered-version-size.zip';
    $mutateZip($archive, $tamperedSizeArchive, static function (ZipArchive $zip): void {
        $manifest = json_decode($zip->getFromName('manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        $entry = 'database/kcrm_document_versions.jsonl';
        $sizeIndex = array_search('size_bytes', array_column($manifest['tables']['kcrm_document_versions']['schema']['columns'], 'name'), true);
        if ($sizeIndex === false) {
            throw new RuntimeException('Actual version schema is missing size_bytes.');
        }
        $lines = explode("\n", rtrim($zip->getFromName($entry), "\n"));
        $row = json_decode($lines[0], true, 64, JSON_THROW_ON_ERROR);
        $row['cells'][$sizeIndex] = base64_encode((string)((int)base64_decode($row['cells'][$sizeIndex], true) + 1));
        $lines[0] = kcrm_transfer_json($row);
        $bytes = implode("\n", $lines) . "\n";
        $zip->addFromString($entry, $bytes);
        $manifest['entries'][$entry] = ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        $zip->addFromString('manifest.json', kcrm_transfer_json($manifest) . "\n");
    });
    $refuses(fn() => kcrm_backup_verify($tamperedSizeArchive), 'actual version size field must match the referenced blob');
    $refuses(fn() => kcrm_backup_restore($target, $tamperedSizeArchive, $targetStorage, $runtime['database_target']), 'inconsistent version size is refused before target writes');
    $assert($snapshot($target) === $beforeTarget, 'tampered version metadata leaves every target table unchanged');

    $phase = 'restore-valid-actual-schema';
    $assert(kcrm_transfer_tables($target, true) === $diagnostics['archive_inventory'], 'complete target/archive byte-sorted inventories match immediately before restore');
    $result = kcrm_backup_restore($target, $archive, $targetStorage, $runtime['database_target']);
    $assert($result['tables'] === count($manifest['tables']) && $result['files'] === 2, 'actual schema and both version files restored');
    $sourceRows = $snapshot($source);
    $targetRows = $snapshot($target);
    unset($sourceRows['kcrm_migrations'], $targetRows['kcrm_migrations']);
    $assert($sourceRows === $targetRows, 'all actual business rows restored exactly, including claimed source setup');
    $assert(kcrm_transfer_migrations($target) === $targetMigrationJournal, 'target retains its own trusted migration journal');
    foreach ($target->query('SELECT storage_key,size_bytes,sha256 FROM kcrm_document_versions ORDER BY version_no')->fetchAll() as $version) {
        $path = $targetStorage . '/documents/' . $version['storage_key'];
        $assert(is_file($path) && filesize($path) === (int)$version['size_bytes'] && hash_file('sha256', $path) === $version['sha256'], 'restored actual document version row matches its exact blob size/hash');
    }
    $assert(!is_file($targetStorage . '/documents/index.php'), 'excluded PHP guard is not transported as business data');
    $phase = 'backup-restored-schema-and-compare-identity';
    $restoredArchive = $fixtureRoot . '/restored-schema.zip';
    $restoredManifest = kcrm_backup_create($target, $targetStorage, $restoredArchive, ['application_version' => 'actual-source-schema-restored-fixture']);
    $assert($restoredManifest['schema_version'] === $manifest['schema_version'], 'backup-restore-backup retains the same schema identity despite different applied_at timestamps');
    $assert(kcrm_backup_verify($restoredArchive)['schema_version'] === $manifest['schema_version'], 'restored actual-schema backup verifies with the same schema version');
    $refuses(fn() => kcrm_backup_restore($target, $archive, $targetStorage, $runtime['database_target']), 'second restore refuses the now claimed/nonempty target');
    echo kcrm_transfer_json(['ok' => true, 'driver' => 'mysql', 'actual_source_schema' => true, 'native_source_pdo' => true, 'transfer_target_pdo' => true, 'trusted_prepare_helper' => true, 'migrations' => array_map('basename', $migrationFiles), 'tables' => count($manifest['tables']), 'document_versions' => 2, 'checks' => $checks, 'secrets_printed' => false, 'diagnostics' => $diagnostics]) . "\n";
} catch (Throwable $e) {
    $message = $e instanceof PDOException ? 'Isolated fixture database operation failed; private connection details suppressed.' : $e->getMessage();
    foreach (['password', 'user'] as $secret) {
        if (is_string($runtime[$secret] ?? null) && $runtime[$secret] !== '') {
            $message = str_replace($runtime[$secret], '[redacted]', $message);
        }
    }
    fwrite(STDERR, kcrm_transfer_json(['ok' => false, 'test' => 'migration-schema', 'phase' => $phase, 'error' => $message, 'diagnostics' => $diagnostics]) . "\n");
    $exitCode = 1;
} finally {
    if ($schemasOwned) {
        foreach ([$target, $source] as $fixtureDb) {
            if ($fixtureDb instanceof PDO) {
                try {
                    $resetSchema($fixtureDb);
                } catch (Throwable) {
                    fwrite(STDERR, "Isolated fixture schema cleanup failed; private connection details suppressed.\n");
                    $exitCode = 1;
                }
            }
        }
    }
    if (is_string($fixtureRoot)) {
        kcrm_transfer_remove_temp($fixtureRoot);
    }
}
exit($exitCode);
