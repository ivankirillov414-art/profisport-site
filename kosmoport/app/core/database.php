<?php
declare(strict_types=1);

function crm_db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $db = crm_config('database');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if (($db['driver'] ?? '') === 'sqlite_test') {
        if (crm_config('environment') !== 'test' || ($db['allow_test_driver'] ?? false) !== true) crm_fail('TEST_DRIVER_FORBIDDEN', 'Тестовая база запрещена в рабочем режиме.', 503);
        $path = $db['path'] ?? '';
        if (!is_string($path) || $path === '') crm_fail('NOT_CONFIGURED', 'Не настроена тестовая база.', 503);
        $pdo = new PDO('sqlite:' . $path, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys=ON'); $pdo->exec('PRAGMA busy_timeout=5000');
    } elseif (($db['driver'] ?? '') === 'mysql') {
        foreach (['host', 'name', 'username'] as $key) if (!is_string($db[$key] ?? null) || $db[$key] === '' || preg_match('/[;\x00-\x1f]/', $db[$key])) crm_fail('NOT_CONFIGURED', 'Не настроено подключение к рабочей базе.', 503);
        if (($db['charset'] ?? '') !== 'utf8mb4' || !is_numeric($db['port']) || (int)$db['port'] < 1 || (int)$db['port'] > 65535) crm_fail('NOT_CONFIGURED', 'Проверьте параметры рабочей базы.', 503);
        $pdo = new PDO('mysql:host=' . $db['host'] . ';port=' . (int)$db['port'] . ';dbname=' . $db['name'] . ';charset=utf8mb4', $db['username'], $db['password'], $options);
        $pdo->exec("SET time_zone = '+00:00'");
    } else crm_fail('NOT_CONFIGURED', 'Неизвестный драйвер базы данных.', 503);
    return $pdo;
}

function crm_query(string $sql, array $values = []): PDOStatement
{ $statement = crm_db()->prepare($sql); $statement->execute($values); return $statement; }

function crm_transaction(callable $callback): mixed
{
    $db = crm_db();
    if ($db->inTransaction()) return $callback($db);
    $db->beginTransaction();
    try { $result = $callback($db); $db->commit(); return $result; }
    catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

function crm_lock_suffix(): string { return crm_db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }

function crm_audit(string $action, string $entityType, ?string $entityId, array $details = []): void
{
    $redact = static function (mixed $value, int $depth = 0) use (&$redact): mixed {
        if ($depth > 8) return '[depth limit]';
        if (!is_array($value)) return is_string($value) ? substr($value, 0, 4000) : $value;
        $out = []; foreach ($value as $key => $item) $out[$key] = preg_match('/password|secret|token|csrf|authorization|cookie|key_hash/i', (string)$key) ? '[redacted]' : $redact($item, $depth + 1);
        return $out;
    };
    $actorId = $GLOBALS['kcrm_authenticated_actor'] ?? (crm_current_user()['id'] ?? null);
    crm_query('INSERT INTO kcrm_audit (id,actor_id,action,entity_type,entity_id,details,created_at) VALUES (?,?,?,?,?,?,?)', [crm_uuid(), $actorId, crm_text($action, 'Действие', 100, true), crm_text($entityType, 'Тип записи', 64, true), $entityId, json_encode($redact($details), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), crm_now()]);
}
