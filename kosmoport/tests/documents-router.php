<?php
declare(strict_types=1);

// Contract fixture only. Packaging must exclude tests/. This cannot run as a
// normal web endpoint or without the per-run loopback test key and temp root.
$root = getenv('KCRM_DOCUMENTS_TEST_ROOT');
$key = getenv('KCRM_DOCUMENTS_TEST_KEY');
if (PHP_SAPI !== 'cli-server' || !$root || !$key || !hash_equals($key, $_SERVER['HTTP_X_DOCUMENTS_TEST_KEY'] ?? '') || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}

final class DocumentsFixtureAuthException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}

function crm_config(?string $key = null): mixed
{
    $config = ['documents' => ['max_upload_bytes' => 2 * 1024 * 1024, 'allow_webroot_storage' => false]];
    return $key === null ? $config : ($config[$key] ?? null);
}

function crm_db(): PDO
{
    static $db;
    if (!$db) {
        $runtimeFile = getenv('KCRM_DOCUMENTS_MYSQL_RUNTIME');
        $database = getenv('KCRM_DOCUMENTS_MYSQL_DATABASE');
        if ($runtimeFile && $database) {
            $runtime = json_decode(ltrim((string)file_get_contents($runtimeFile), "\xEF\xBB\xBF"), true, flags: JSON_THROW_ON_ERROR);
            $runtimePath = str_replace('\\', '/', $runtime['runtime_path'] ?? '');
            if (($runtime['host'] ?? '') !== '127.0.0.1' || (!str_contains($runtimePath, '/tests/migration-runtime/') && !str_contains($runtimePath, '/tests/documents-security-runtime/')) || !preg_match('/\Akcrm_documents_test_[a-f0-9]{12}\z/D', $database)) {
                throw new RuntimeException('Not an isolated document database.');
            }
            $db = new PDO('mysql:host=127.0.0.1;port=' . (int)$runtime['port'] . ';dbname=' . $database . ';charset=utf8mb4', $runtime['user'], $runtime['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
            return $db;
        }
        $db = new PDO('sqlite:' . getenv('KCRM_DOCUMENTS_TEST_ROOT') . '/fixture.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
        // Execute the module's migration with only MySQL-specific type/index
        // syntax adapted for SQLite. This is NOT a MySQL/MariaDB engine test.
        $sql = file_get_contents(__DIR__ . '/../migrations/020_documents.sql');
        $sql = preg_replace('/CHARACTER SET ascii(?: COLLATE ascii_bin)?/i', '', $sql);
        $sql = preg_replace('/\bUNSIGNED\b/i', '', $sql);
        $sql = preg_replace('/^\s*KEY [a-z_]+ \([^\n]+\),?\r?\n/mi', '', $sql);
        $sql = preg_replace('/UNIQUE KEY [a-z_]+\s*\(/i', 'UNIQUE (', $sql);
        $sql = preg_replace('/\) ENGINE=InnoDB[^;]*;/i', ');', $sql);
        $sql = preg_replace('/,\s*\)/', ')', $sql);
        $db->exec($sql);
        $db->exec('CREATE TABLE IF NOT EXISTS documents_test_audit (id INTEGER PRIMARY KEY,action TEXT,entity_id TEXT,details TEXT,role TEXT)');
    }
    return $db;
}

function crm_current_user(): ?array
{
    $role = $_SERVER['HTTP_X_TEST_ROLE'] ?? 'anonymous';
    return $role === 'anonymous' ? null : ['id' => 'fixture-' . $role, 'name' => 'Тестовый сотрудник', 'email' => 'fixture@example.invalid', 'role' => $role];
}

function crm_require_roles(array $roles): array
{
    $user = crm_current_user();
    if ($user === null) {
        throw new DocumentsFixtureAuthException('unauthenticated', 'Войдите в систему.', 401);
    }
    if (!in_array($user['role'], array_merge($roles, ['owner', 'admin']), true)) {
        throw new DocumentsFixtureAuthException('forbidden', 'Нет доступа.', 403);
    }
    return $user;
}

function crm_input(): array
{
    return json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
}

function crm_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function crm_audit(string $action, string $entityType, ?string $entityId, array $details = []): void
{
    if (($_SERVER['HTTP_X_TEST_AUDIT_FAIL'] ?? '') === '1') {
        throw new RuntimeException('Fixture audit failure: never expose this detail.');
    }
    $statement = crm_db()->prepare('INSERT INTO documents_test_audit (action,entity_id,details,role) VALUES (?,?,?,?)');
    $statement->execute([$action, $entityId, json_encode($details, JSON_THROW_ON_ERROR), crm_current_user()['role'] ?? null]);
}

function crm_storage_path(string $relative = ''): string
{
    if (str_contains($relative, '..') || str_contains($relative, '\\') || str_starts_with($relative, '/')) {
        throw new RuntimeException('Unsafe fixture relative path.');
    }
    $storage = ($_SERVER['HTTP_X_TEST_STORAGE'] ?? '') === 'public' ? '/public/private' : '/storage';
    return getenv('KCRM_DOCUMENTS_TEST_ROOT') . $storage . ($relative !== '' ? '/' . $relative : '');
}

try {
    crm_db();
    $routes = require __DIR__ . '/../app/modules/documents.php';
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !hash_equals('fixture-csrf', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        crm_json(['ok' => false, 'error' => ['code' => 'csrf', 'message' => 'Недействительный CSRF.']], 403);
    }
    $route = $_SERVER['REQUEST_METHOD'] . ' ' . ($_GET['r'] ?? '');
    if ($route === 'GET _fixture/ping') {
        crm_json(['ok' => true, 'data' => ['fixture' => true]]);
    }
    if ($route === 'POST _fixture/local-file-upload') {
        // Ensure the production upload boundary rejects an ordinary local file.
        try {
            crm_documents_upload(['title' => 'Локальный файл'], ['file' => ['name' => 'local.txt', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK]]);
            crm_json(['ok' => false], 500);
        } catch (CrmDocumentException $error) {
            crm_json(['ok' => false, 'error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]], $error->status);
        }
    }
    if (!isset($routes[$route])) {
        crm_json(['ok' => false, 'error' => ['code' => 'not_found', 'message' => 'Не найдено.']], 404);
    }
    $routes[$route]();
} catch (DocumentsFixtureAuthException $error) {
    crm_json(['ok' => false, 'error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]], $error->status);
} catch (Throwable $error) {
    error_log((string)$error);
    crm_json(['ok' => false, 'error' => ['code' => 'documents_unavailable', 'message' => 'Не удалось выполнить операцию с документом.']], 500);
}
