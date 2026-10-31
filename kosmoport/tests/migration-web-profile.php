<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('KCRM_WEB_FIXTURE_LIBRARY', true);
require __DIR__ . '/migration-web-fixture.php';
require dirname(__DIR__) . '/tools/backup-lib.php';

final class WebMigrationProfile
{
    public static bool $enabled = false;
    public static string $phase = '';
    public static array $sql = [];
    public static function record(string $method, string $sql, int $start): void
    {
        if (self::$enabled) {
            self::$sql[] = ['phase' => self::$phase, 'method' => $method,
                'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                'elapsed_ms' => round((hrtime(true) - $start) / 1e6, 3)];
        }
    }
}
final class WebMigrationStatement extends PDOStatement
{
    protected function __construct() {}
    public function execute(?array $params = null): bool
    {
        $start = hrtime(true);
        try { return parent::execute($params); }
        finally { WebMigrationProfile::record('execute', $this->queryString, $start); }
    }
}
final class WebMigrationPDO extends PDO
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $start = hrtime(true);
        try { return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs); }
        finally { WebMigrationProfile::record('query', $query, $start); }
    }
}
$runtime = web_fixture_runtime();
$connect = static function (string $which) use ($runtime): WebMigrationPDO {
    $db = new WebMigrationPDO('mysql:host=127.0.0.1;port=' . (int)$runtime['port'] . ';dbname=' . $runtime['database_' . $which] . ';charset=utf8mb4', $runtime['user'], $runtime['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true,
        PDO::ATTR_STATEMENT_CLASS => [WebMigrationStatement::class, []],
    ]);
    $db->exec("SET time_zone = '+00:00'");
    return $db;
};
$operations = [];
$measure = static function (string $label, callable $fn) use (&$operations): mixed {
    $start = hrtime(true);
    WebMigrationProfile::$phase = $label;
    WebMigrationProfile::$enabled = true;
    try { return $fn(); }
    finally {
        WebMigrationProfile::$enabled = false;
        $operations[] = ['operation' => $label, 'elapsed_ms' => round((hrtime(true) - $start) / 1e6, 3)];
    }
};
$source = $connect('source');
$target = $connect('target');
$parent = __DIR__ . '/migration-web-profile-runtime';
$stage = null;
$error = null;
$clean = false;
try {
    if (file_exists($parent)) { throw new RuntimeException('Refusing an existing profiler runtime directory.'); }
    mkdir($parent, 0700);
    $stage = kcrm_transfer_temp_dir($parent);
    mkdir($stage . '/storage', 0700);
    mkdir($stage . '/storage/documents', 0700);
    web_fixture_drop($source);
    web_fixture_drop($target);
    web_fixture_install($source);
    web_fixture_install($target);
    $source->prepare('INSERT INTO kcrm_users VALUES (?, ?, ?)')->execute(['profile-owner', 'Profiler fixture', 'fake-profile-hash']);
    $source->prepare('INSERT INTO kcrm_documents VALUES (?, ?, ?, ?)')->execute(['profile-doc', 'Profiler fixture document', 'profile-owner', null]);
    foreach ([1, 2] as $i) {
        $source->prepare('INSERT INTO kcrm_versions VALUES (?, ?, ?, ?)')->execute([$i, 'profile-doc', 'documents/' . $i . '.pdf', "\0\xFFfixture-" . $i]);
        file_put_contents($stage . '/storage/documents/' . $i . '.pdf', "%PDF-1.4\nfixture-" . $i . "\n");
    }
    $measure('SELECT 1', static fn() => $source->query('SELECT 1')->fetchColumn());
    $measure('GET_LOCK/RELEASE_LOCK', static function () use ($target): void {
        if ((int)$target->query("SELECT GET_LOCK('kcrm-web-migration-profile-isolated',0)")->fetchColumn() !== 1) { throw new RuntimeException('Profile advisory lock unavailable.'); }
        $target->query("SELECT RELEASE_LOCK('kcrm-web-migration-profile-isolated')")->fetchColumn();
    });
    foreach (['kcrm_migrations', 'kcrm_users', 'kcrm_documents', 'kcrm_versions'] as $table) {
        $measure('COUNT ' . $table, static fn() => $source->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn());
        $measure('schema ' . $table, static fn() => kcrm_transfer_schema($source, $table));
    }
    $archive = $stage . '/profile.zip';
    $measure('backup_create including schema + ZIP write', static fn() => kcrm_backup_create($source, $stage . '/storage', $archive, ['temp_dir' => $parent]));
    $measure('ZIP stage + manifest/hash/row verification', static function () use ($archive, $parent): void {
        $verified = kcrm_backup_stage($archive, ['temp_dir' => $parent]);
        kcrm_transfer_remove_temp($verified['stage']);
    });
    $measure('two document file copies + SHA256', static function () use ($stage): void {
        foreach ([1, 2] as $i) {
            $sourceFile = $stage . '/storage/documents/' . $i . '.pdf';
            $copy = $stage . '/copy-' . $i . '.pdf';
            if (!copy($sourceFile, $copy) || hash_file('sha256', $sourceFile) !== hash_file('sha256', $copy)) { throw new RuntimeException('Profile file copy mismatch.'); }
        }
    });
} catch (Throwable $e) {
    $error = $e->getMessage();
} finally {
    WebMigrationProfile::$enabled = false;
    try {
        web_fixture_drop($target);
        web_fixture_drop($source);
        if ($stage !== null) { kcrm_transfer_remove_temp($stage); }
        $resolved = realpath($parent);
        if ($resolved !== false && !is_link($parent) && basename($resolved) === 'migration-web-profile-runtime' && realpath(dirname($resolved)) === realpath(__DIR__)) {
            rmdir($resolved);
        }
        $clean = true;
    } catch (Throwable $e) {
        $error ??= 'Profiler cleanup failed: ' . $e->getMessage();
    }
    $slowest = WebMigrationProfile::$sql;
    usort($slowest, static fn(array $a, array $b): int => $b['elapsed_ms'] <=> $a['elapsed_ms']);
    $result = ['ok' => $error === null && $clean, 'scope' => 'isolated local profiler; SQL values and credentials are not recorded',
        'checked_at_utc' => gmdate('c'), 'server' => $source->getAttribute(PDO::ATTR_SERVER_VERSION), 'port' => $runtime['port'],
        'operations' => $operations, 'slowest_sql' => array_slice($slowest, 0, 10), 'all_sql' => WebMigrationProfile::$sql,
        'fake_records_and_files_removed' => $clean, 'error' => $error];
    file_put_contents(__DIR__ . '/migration-web-profile-results.json', json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo json_encode(['ok' => $result['ok'], 'operations' => $operations, 'slowest_sql' => array_slice($slowest, 0, 3), 'cleanup' => $clean, 'error' => $error], JSON_THROW_ON_ERROR), PHP_EOL;
}
exit($error === null && $clean ? 0 : 1);
