<?php
declare(strict_types=1);

// Local transport fixture only. No application bootstrap, production config or endpoints.
const WEB_FIXTURE_ROOT = __DIR__ . '/migration-web-runtime';
const WEB_FIXTURE_RUNTIME = __DIR__ . '/migration-runtime/local-runtime.private.json';

function web_fixture_runtime(): array
{
    $runtime = json_decode(ltrim((string)file_get_contents(WEB_FIXTURE_RUNTIME), "\xEF\xBB\xBF"), true, 32, JSON_THROW_ON_ERROR);
    if (($runtime['host'] ?? '') !== '127.0.0.1'
        || ($runtime['database_source'] ?? '') !== 'kcrm_migration_source_test'
        || ($runtime['database_target'] ?? '') !== 'kcrm_migration_target_test'
        || !str_contains((string)($runtime['runtime_path'] ?? ''), 'migration-runtime')) {
        throw new RuntimeException('Only the isolated loopback runtime is allowed.');
    }
    return $runtime;
}

function web_fixture_connect(string $which): PDO
{
    $runtime = web_fixture_runtime();
    if (!in_array($which, ['source', 'target'], true)) {
        throw new RuntimeException('Unknown disposable database.');
    }
    $db = new PDO('mysql:host=127.0.0.1;port=' . (int)$runtime['port'] . ';dbname=' . $runtime['database_' . $which] . ';charset=utf8mb4', $runtime['user'], $runtime['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => true,
    ]);
    $db->exec("SET time_zone = '+00:00'");
    return $db;
}

function web_fixture_drop(PDO $db): void
{
    $allowed = ['kcrm_versions', 'kcrm_documents', 'kcrm_users', 'kcrm_migrations'];
    $actual = $db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    if (array_diff($actual, $allowed)) {
        throw new RuntimeException('Unexpected tables: disposable fixture ownership guard failed.');
    }
    foreach ($allowed as $table) {
        $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
}

function web_fixture_install(PDO $db): void
{
    $db->exec('CREATE TABLE kcrm_migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at VARCHAR(30) NOT NULL) ENGINE=InnoDB');
    $db->exec('CREATE TABLE kcrm_users (id VARCHAR(64) PRIMARY KEY, name VARCHAR(100) NOT NULL, password_hash VARCHAR(200) NOT NULL) ENGINE=InnoDB');
    $db->exec('CREATE TABLE kcrm_documents (id VARCHAR(64) PRIMARY KEY, title VARCHAR(200) NOT NULL, author VARCHAR(64) NOT NULL, note TEXT NULL, FOREIGN KEY(author) REFERENCES kcrm_users(id)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE kcrm_versions (id INT PRIMARY KEY, document_id VARCHAR(64) NOT NULL, path VARCHAR(200) NOT NULL, bytes BLOB NOT NULL, FOREIGN KEY(document_id) REFERENCES kcrm_documents(id)) ENGINE=InnoDB');
    $db->prepare('INSERT INTO kcrm_migrations VALUES (?, ?, ?)')->execute(['001_web_fixture', str_repeat('b', 64), '2026-10-31T00:00:00Z']);
}

function web_fixture_state(): array
{
    $state = [];
    foreach (['source', 'target'] as $which) {
        $db = web_fixture_connect($which);
        $rows = [];
        $counts = [];
        foreach (['kcrm_users', 'kcrm_documents', 'kcrm_versions'] as $table) {
            $data = $db->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetchAll(PDO::FETCH_NUM);
            $counts[$table] = count($data);
            $rows[$table] = array_map(static fn(array $row): array => array_map(static fn($v) => $v === null ? null : base64_encode((string)$v), $row), $data);
        }
        $files = [];
        $storage = WEB_FIXTURE_ROOT . '/' . $which . '-storage';
        if (is_dir($storage)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $name = str_replace('\\', '/', substr($file->getPathname(), strlen($storage) + 1));
                    $files[$name] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        ksort($files);
        $state[$which] = ['counts' => $counts, 'rows_sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)), 'file_count' => count($files), 'files' => $files];
    }
    return $state;
}

function web_fixture_remove_runtime(): void
{
    // Verify the exact resolved target before any recursive deletion.
    if (!is_dir(WEB_FIXTURE_ROOT)) {
        return;
    }
    $root = realpath(WEB_FIXTURE_ROOT);
    if ($root === false || is_link(WEB_FIXTURE_ROOT) || basename($root) !== 'migration-web-runtime'
        || realpath(dirname($root)) !== realpath(__DIR__)) {
        throw new RuntimeException('Unsafe runtime cleanup target.');
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        if ($file->isDir() && !$file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($root);
}

if (PHP_SAPI === 'cli') {
    if (defined('KCRM_WEB_FIXTURE_LIBRARY')) {
        return;
    }
    $action = $argv[1] ?? '';
    if ($action === 'setup') {
        if (is_dir(WEB_FIXTURE_ROOT)) {
            throw new RuntimeException('Refusing to reuse an existing HTTP fixture runtime.');
        }
        mkdir(WEB_FIXTURE_ROOT, 0700);
        foreach (['source-storage', 'target-storage', 'sessions', 'source-storage/documents'] as $path) {
            mkdir(WEB_FIXTURE_ROOT . '/' . $path, 0700);
        }
        $source = web_fixture_connect('source');
        $target = web_fixture_connect('target');
        web_fixture_drop($source);
        web_fixture_drop($target);
        web_fixture_install($source);
        web_fixture_install($target);
        $source->prepare('INSERT INTO kcrm_users VALUES (?, ?, ?)')->execute(['web-owner', 'Тестовый владелец 🚀', 'fake-fixture-hash']);
        $source->prepare('INSERT INTO kcrm_documents VALUES (?, ?, ?, ?)')->execute(['web-document', 'Тестовый договор', 'web-owner', null]);
        foreach ([1, 2] as $version) {
            $path = 'documents/version-' . $version . '.pdf';
            $source->prepare('INSERT INTO kcrm_versions VALUES (?, ?, ?, ?)')->execute([$version, 'web-document', $path, "\0\xFF\x80\r\nfixture-" . $version]);
            file_put_contents(WEB_FIXTURE_ROOT . '/source-storage/' . $path, "%PDF-1.4\nlocal-http-fixture-version-" . $version . "\n");
        }
        file_put_contents(WEB_FIXTURE_ROOT . '/fixture.private.json', json_encode(['token' => bin2hex(random_bytes(32))], JSON_THROW_ON_ERROR));
        $targetLoader = '<?php $r=json_decode(ltrim(file_get_contents(' . var_export(WEB_FIXTURE_RUNTIME, true) . '),"\\xEF\\xBB\\xBF"),true,32,JSON_THROW_ON_ERROR); return ["db"=>["host"=>"127.0.0.1","port"=>$r["port"],"name"=>$r["database_target"],"user"=>$r["user"],"password"=>$r["password"]],"storage_path"=>' . var_export(WEB_FIXTURE_ROOT . '/target-storage', true) . '];';
        file_put_contents(WEB_FIXTURE_ROOT . '/target.private.php', $targetLoader);
        echo json_encode(['ok' => true, 'state' => web_fixture_state()], JSON_THROW_ON_ERROR), PHP_EOL;
    } elseif ($action === 'state') {
        echo json_encode(web_fixture_state(), JSON_THROW_ON_ERROR), PHP_EOL;
    } elseif ($action === 'cleanup') {
        web_fixture_drop(web_fixture_connect('target'));
        web_fixture_drop(web_fixture_connect('source'));
        web_fixture_remove_runtime();
        echo "{\"ok\":true,\"fake_tables_removed\":true,\"runtime_removed\":true}\n";
    } else {
        throw new RuntimeException('Unknown CLI fixture action.');
    }
    exit;
}

// The fixture refuses all non-loopback requests and binds its server only to loopback.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
session_save_path(WEB_FIXTURE_ROOT . '/sessions');
session_name('KCRM_MIGRATION_HTTP_FIXTURE');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'path' => '/']);
session_start();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/fixture/session') {
    $secret = json_decode((string)file_get_contents(WEB_FIXTURE_ROOT . '/fixture.private.json'), true, 8, JSON_THROW_ON_ERROR);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($secret['token'], (string)($_SERVER['HTTP_X_FIXTURE_TOKEN'] ?? ''))) {
        http_response_code(403);
        exit;
    }
    $input = json_decode((string)file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
    $role = $input['role'] ?? null;
    if (!in_array($role, ['owner', 'admin', 'staff', null], true)) {
        http_response_code(422);
        exit;
    }
    session_regenerate_id(true);
    $_SESSION = $role === null ? [] : ['fixture_user' => ['id' => 'fixture-' . $role, 'name' => 'Fixture', 'email' => 'fixture@example.invalid', 'role' => $role]];
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true, 'role' => $role], JSON_THROW_ON_ERROR);
    exit;
}
if ($path === '/fixture/state') {
    $secret = json_decode((string)file_get_contents(WEB_FIXTURE_ROOT . '/fixture.private.json'), true, 8, JSON_THROW_ON_ERROR);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !hash_equals($secret['token'], (string)($_SERVER['HTTP_X_FIXTURE_TOKEN'] ?? ''))) {
        http_response_code(403);
        exit;
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(web_fixture_state(), JSON_THROW_ON_ERROR);
    exit;
}
if ($path !== '/backup') {
    http_response_code(404);
    exit;
}

function crm_current_user(): ?array { return $_SESSION['fixture_user'] ?? null; }
function crm_require_roles(array $roles): array
{
    $user = crm_current_user();
    if ($user === null) {
        http_response_code(401);
        exit('Fixture authentication required.');
    }
    // Frozen contract: core owner/admin override; backup helper must still reject admin.
    if (!in_array($user['role'], ['owner', 'admin'], true) && !in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('Fixture role denied.');
    }
    return $user;
}
function crm_config(?string $key = null): mixed { return $key === 'version' ? 'local-http-fixture' : []; }
function crm_db(): PDO { static $db; return $db ??= web_fixture_connect('source'); }
function crm_storage_path(string $relative = ''): string
{
    if ($relative !== '' && (!preg_match('~^[a-zA-Z0-9_./-]+$~D', $relative) || str_contains($relative, '..'))) {
        throw new RuntimeException('Unsafe fixture storage path.');
    }
    return WEB_FIXTURE_ROOT . '/source-storage' . ($relative === '' ? '' : '/' . $relative);
}
function crm_audit(string $action, string $entityType, ?string $entityId, array $details = []): void
{
    file_put_contents(WEB_FIXTURE_ROOT . '/audit.jsonl', json_encode(['action' => $action, 'entity' => $entityType, 'details' => $details], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
}
define('KCRM_BACKUP_TARGET_CONFIG', WEB_FIXTURE_ROOT . '/target.private.php');
require dirname(__DIR__) . '/tools/backup-admin.php';
