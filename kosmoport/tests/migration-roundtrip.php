<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/tools/backup-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$options = getopt('', ['runtime-config:']);
$runtimePath = $options['runtime-config'] ?? __DIR__ . '/migration-runtime/local-runtime.private.json';
if (!is_file($runtimePath)) {
    fwrite(STDERR, "Need isolated local MariaDB runtime config; no production database is accepted.\n");
    exit(2);
}
$runtime = json_decode(file_get_contents($runtimePath), true, 32, JSON_THROW_ON_ERROR);
if (($runtime['host'] ?? '') !== '127.0.0.1'
    || ($runtime['database_source'] ?? '') !== 'kcrm_migration_source_test'
    || ($runtime['database_target'] ?? '') !== 'kcrm_migration_target_test') {
    throw new RuntimeException('This test only accepts the dedicated loopback fixture database names.');
}
$connect = static function (string $name) use ($runtime): PDO {
    $db = new PDO('mysql:host=127.0.0.1;port=' . (int)$runtime['port'] . ';dbname=' . $name . ';charset=utf8mb4', $runtime['user'], $runtime['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true,
    ]);
    $db->exec("SET time_zone = '+00:00'");
    return $db;
};
$source = $connect($runtime['database_source']);
$target = $connect($runtime['database_target']);
$root = kcrm_transfer_temp_dir(__DIR__ . '/migration-runtime');
$sourceStorage = $root . '/source-storage';
$targetStorage = $root . '/target-storage';
mkdir($sourceStorage);
mkdir($targetStorage);
mkdir($sourceStorage . '/documents');
mkdir($sourceStorage . '/documents/demo');
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
    } catch (Throwable $e) {
        $assert(true, $label);
        return;
    }
    $assert(false, $label);
};
$dropFixture = static function (PDO $db): void {
    // Only explicitly named disposable databases passed the guard above.
    foreach (['ps_cms_preserve_test', 'kcrm_versions', 'kcrm_documents', 'kcrm_users', 'kcrm_migrations'] as $table) {
        $db->exec('DROP TABLE IF EXISTS ' . chr(96) . $table . chr(96));
    }
};
$installFixture = static function (PDO $db): void {
    $db->exec("CREATE TABLE kcrm_migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at VARCHAR(30) NOT NULL) ENGINE=InnoDB");
    $db->exec("CREATE TABLE kcrm_users (id VARCHAR(64) PRIMARY KEY, name VARCHAR(100) NOT NULL, password_hash VARCHAR(200) NOT NULL) ENGINE=InnoDB");
    $db->exec("CREATE TABLE kcrm_documents (id VARCHAR(64) PRIMARY KEY, title VARCHAR(200) NOT NULL, author VARCHAR(64) NOT NULL, note TEXT NULL, FOREIGN KEY (author) REFERENCES kcrm_users(id)) ENGINE=InnoDB");
    $db->exec("CREATE TABLE kcrm_versions (id INT PRIMARY KEY, document_id VARCHAR(64) NOT NULL, path VARCHAR(200) NOT NULL, bytes BLOB NOT NULL, FOREIGN KEY (document_id) REFERENCES kcrm_documents(id)) ENGINE=InnoDB");
    $db->prepare('INSERT INTO kcrm_migrations VALUES (?, ?, ?)')->execute(['001_fixture', str_repeat('a', 64), gmdate('c')]);
};
$zipMutation = static function (string $sourceZip, string $destination, callable $change): void {
    copy($sourceZip, $destination);
    $zip = new ZipArchive();
    if ($zip->open($destination) !== true) {
        throw new RuntimeException('Fixture ZIP could not open.');
    }
    try {
        $change($zip);
    } finally {
        $zip->close();
    }
};
$rebuildManifest = static function (ZipArchive $zip, string $entry, string $bytes, ?callable $modify = null): void {
    $manifest = json_decode($zip->getFromName('manifest.json'), true, 64, JSON_THROW_ON_ERROR);
    $zip->addFromString($entry, $bytes);
    $manifest['entries'][$entry] = ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    if ($modify !== null) {
        $manifest = $modify($manifest);
    }
    $zip->addFromString('manifest.json', kcrm_transfer_json($manifest) . "\n");
};
try {
    $dropFixture($source);
    $dropFixture($target);
    $installFixture($source);
    $installFixture($target);
    $source->prepare('INSERT INTO kcrm_users VALUES (?, ?, ?)')->execute(['owner', 'Владелец 🚀', 'fixture-hash-only']);
    $source->prepare('INSERT INTO kcrm_documents VALUES (?, ?, ?, ?)')->execute(['doc1', 'Договор Космопорт', 'owner', null]);
    $binary = "\0\xFF\x80\r\n";
    $source->prepare('INSERT INTO kcrm_versions VALUES (?, ?, ?, ?)')->execute([1, 'doc1', 'documents/demo/v1.pdf', $binary]);
    $source->prepare('INSERT INTO kcrm_versions VALUES (?, ?, ?, ?)')->execute([2, 'doc1', 'documents/demo/v2.pdf', $binary . 'v2']);
    file_put_contents($sourceStorage . '/documents/demo/v1.pdf', "%PDF-1.4\nfixture-v1\n");
    file_put_contents($sourceStorage . '/documents/demo/v2.pdf', "%PDF-1.4\nfixture-v2\n");
    file_put_contents($sourceStorage . '/.htaccess', 'Deny from all');
    mkdir($sourceStorage . '/sessions');
    file_put_contents($sourceStorage . '/sessions/private-session', 'NOT-BUSINESS-DATA');
    $archive = $root . '/backup.zip';
    $manifest = kcrm_backup_create($source, $sourceStorage, $archive, ['application_version' => 'fixture']);
    $assert(is_file($archive), 'backup created');
    $assert(count($manifest['tables']) === 4 && count($manifest['entries']) === 6, 'all tables and both document versions');
    $assert(!isset($manifest['entries']['storage/sessions/private-session']), 'sessions excluded');
    $assert(kcrm_backup_verify($archive)['schema_version'] === $manifest['schema_version'], 'verify roundtrip manifest');
    $result = kcrm_backup_restore($target, $archive, $targetStorage, $runtime['database_target']);
    $assert($result['files'] === 2 && $result['tables'] === 4, 'real MySQL restore completed');
    foreach (['kcrm_users', 'kcrm_documents', 'kcrm_versions'] as $table) {
        $assert($source->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_NUM) === $target->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_NUM), $table . ' exact SQL rows');
    }
    $assert(hash_file('sha256', $sourceStorage . '/documents/demo/v1.pdf') === hash_file('sha256', $targetStorage . '/documents/demo/v1.pdf'), 'v1 file hash');
    $assert(hash_file('sha256', $sourceStorage . '/documents/demo/v2.pdf') === hash_file('sha256', $targetStorage . '/documents/demo/v2.pdf'), 'v2 file hash');
    $refuses(fn() => kcrm_backup_restore($target, $archive, $targetStorage, $runtime['database_target']), 'nonempty target refused');
    $refuses(fn() => kcrm_backup_restore($source, $archive, $sourceStorage, $runtime['database_source']), 'source database refused');
    $refuses(fn() => kcrm_backup_restore($target, $archive, $targetStorage, 'typo_target_test'), 'wrong explicit target refused');
    $refuses(fn() => kcrm_backup_create($source, $sourceStorage, $archive), 'existing backup never overwritten');
    foreach (['../escape.txt', '/absolute.txt', 'storage/a/../../escape.txt', 'storage\\escape.txt', 'C:/escape.txt', 'storage/a./doc.pdf', 'storage/CON.pdf'] as $index => $unsafe) {
        $bad = $root . '/path-' . $index . '.zip';
        $zipMutation($archive, $bad, static fn(ZipArchive $zip) => $zip->addFromString($unsafe, 'bad'));
        $refuses(fn() => kcrm_backup_verify($bad), 'ZIP unsafe path ' . $index);
    }
    $bad = $root . '/case-duplicate.zip';
    $zipMutation($archive, $bad, static fn(ZipArchive $zip) => $zip->addFromString('MANIFEST.JSON', '{}'));
    $refuses(fn() => kcrm_backup_verify($bad), 'case duplicate refused');
    $bad = $root . '/symlink.zip';
    $zipMutation($archive, $bad, static function (ZipArchive $zip): void {
        $name = 'storage/documents/demo/v1.pdf';
        $index = $zip->locateName($name);
        $zip->setExternalAttributesIndex($index, ZipArchive::OPSYS_UNIX, 0120777 << 16);
    });
    $refuses(fn() => kcrm_backup_verify($bad), 'symlink metadata refused');
    $bad = $root . '/hash.zip';
    $zipMutation($archive, $bad, static fn(ZipArchive $zip) => $zip->addFromString('storage/documents/demo/v1.pdf', 'corrupted'));
    $refuses(fn() => kcrm_backup_verify($bad), 'hash mismatch refused');
    $bad = $root . '/base64.zip';
    $zipMutation($archive, $bad, static fn(ZipArchive $zip) => $rebuildManifest($zip, 'database/kcrm_users.jsonl', kcrm_transfer_json(['cells' => ['%%%', null, null]]) . "\n"));
    $refuses(fn() => kcrm_backup_verify($bad), 'noncanonical row encoding refused even with valid hash');
    $bad = $root . '/foreign.zip';
    $zipMutation($archive, $bad, static function (ZipArchive $zip) use ($rebuildManifest): void {
        $rebuildManifest($zip, 'database/ps_cms_users.jsonl', '', static function (array $manifest): array {
            $manifest['tables']['ps_cms_users'] = $manifest['tables']['kcrm_users'];
            $manifest['tables']['ps_cms_users']['entry'] = 'database/ps_cms_users.jsonl';
            $manifest['tables']['ps_cms_users']['rows'] = 0;
            return $manifest;
        });
    });
    $refuses(fn() => kcrm_backup_verify($bad), 'foreign table in archive refused');
    foreach (['storage/documents/.htaccess', 'storage/documents/.user.ini', 'storage/documents/tool.php'] as $i => $entry) {
        $bad = $root . '/server-control-' . $i . '.zip';
        $zipMutation($archive, $bad, static fn(ZipArchive $zip) => $rebuildManifest($zip, $entry, 'unsafe'));
        $refuses(fn() => kcrm_backup_verify($bad), 'server control or executable document refused');
    }
    $refuses(fn() => kcrm_backup_verify($archive, ['limits' => ['expanded_bytes' => 10]]), 'expanded size limit enforced');
    $refuses(fn() => kcrm_backup_verify($archive, ['limits' => ['archive_bytes' => 10]]), 'archive size limit enforced');
    $refuses(fn() => kcrm_backup_verify($archive, ['limits' => ['entries' => 2]]), 'entry count limit enforced');
    // Reset only our fixture target, preserving source and the valid backup.
    $dropFixture($target);
    $installFixture($target);
    $target->exec('CREATE TABLE ps_cms_preserve_test (id INT PRIMARY KEY, marker VARCHAR(40)) ENGINE=InnoDB');
    $target->exec("INSERT INTO ps_cms_preserve_test VALUES (1,'must survive')");
    $emptyStorage = $root . '/empty-storage';
    mkdir($emptyStorage);
    $refuses(fn() => kcrm_backup_restore($target, $archive, $emptyStorage, $runtime['database_target']), 'foreign target DB tables refused');
    $assert($target->query('SELECT marker FROM ps_cms_preserve_test')->fetchColumn() === 'must survive', 'foreign records unchanged');
    $assert((int)$target->query('SELECT COUNT(*) FROM kcrm_users')->fetchColumn() === 0, 'foreign-target refusal writes no CRM rows');
    $target->exec('DROP TABLE ps_cms_preserve_test');
    $target->exec('CREATE TRIGGER kcrm_fixture_trigger BEFORE INSERT ON kcrm_users FOR EACH ROW SET NEW.name=NEW.name');
    $refuses(fn() => kcrm_backup_restore($target, $archive, $emptyStorage, $runtime['database_target']), 'target trigger refused before writes');
    $assert((int)$target->query('SELECT COUNT(*) FROM kcrm_users')->fetchColumn() === 0, 'trigger refusal writes no rows');
    $target->exec('DROP TRIGGER kcrm_fixture_trigger');
    $target->exec('ALTER TABLE kcrm_documents DROP FOREIGN KEY kcrm_documents_ibfk_1');
    $target->exec('ALTER TABLE kcrm_documents ADD CONSTRAINT kcrm_docs_changed FOREIGN KEY(author) REFERENCES kcrm_users(id) ON DELETE CASCADE');
    $refuses(fn() => kcrm_backup_restore($target, $archive, $emptyStorage, $runtime['database_target']), 'changed FK actions refused');
    $dropFixture($target);
    $installFixture($target);
    $target->exec('ALTER TABLE kcrm_documents ADD COLUMN extra VARCHAR(20)');
    $refuses(fn() => kcrm_backup_restore($target, $archive, $emptyStorage, $runtime['database_target']), 'schema mismatch refused');
    $dropFixture($target);
    $installFixture($target);
    $bad = $root . '/fk-failure.zip';
    $zipMutation($archive, $bad, static function (ZipArchive $zip) use ($rebuildManifest): void {
        $bytes = kcrm_transfer_json(['cells' => array_map('base64_encode', ['doc1', 'Договор', 'missing-owner']) + [3 => null]]) . "\n";
        $rebuildManifest($zip, 'database/kcrm_documents.jsonl', $bytes);
    });
    $refuses(fn() => kcrm_backup_restore($target, $bad, $emptyStorage, $runtime['database_target']), 'SQL FK error aborts restore');
    $assert((int)$target->query('SELECT COUNT(*) FROM kcrm_users')->fetchColumn() === 0, 'inserts before FK failure rolled back');
    $assert(iterator_count(new FilesystemIterator($emptyStorage, FilesystemIterator::SKIP_DOTS)) === 0, 'restore lock/files removed on failure');
    // A file-system failure after SQL insert must roll back those rows.
    $bad = $root . '/collision.zip';
    $zipMutation($archive, $bad, static function (ZipArchive $zip) use ($rebuildManifest): void {
        $rebuildManifest($zip, 'storage/documents/demo/v1.pdf/child.pdf', 'collision');
    });
    $refuses(fn() => kcrm_backup_restore($target, $bad, $emptyStorage, $runtime['database_target']), 'file-directory collision aborts restore');
    $assert((int)$target->query('SELECT COUNT(*) FROM kcrm_users')->fetchColumn() === 0, 'filesystem failure rolls back SQL');
    $assert(iterator_count(new FilesystemIterator($emptyStorage, FilesystemIterator::SKIP_DOTS)) === 0, 'filesystem failure removes only created files and dirs');
    $result = ['ok' => true, 'driver' => 'mysql', 'server_version' => $source->getAttribute(PDO::ATTR_SERVER_VERSION),
        'php_version' => PHP_VERSION, 'checks' => $checks, 'real_roundtrip' => true,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'library_sha256' => hash_file('sha256', dirname(__DIR__) . '/tools/backup-lib.php')];
    file_put_contents(__DIR__ . '/migration-roundtrip-results.json', kcrm_transfer_json($result) . "\n");
    echo kcrm_transfer_json($result) . "\n";
} finally {
    $dropFixture($target);
    $dropFixture($source);
    kcrm_transfer_remove_temp($root);
}
