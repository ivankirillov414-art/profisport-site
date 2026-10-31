<?php
declare(strict_types=1);

function crm_split_sql(string $sql): array
{
    $out = []; $buffer = ''; $quote = null; $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i]; $next = $sql[$i + 1] ?? '';
        if ($quote !== null) {
            $buffer .= $char;
            if ($char === '\\' && $quote !== '`' && $i + 1 < $length) $buffer .= $sql[++$i];
            elseif ($char === $quote) { if ($next === $quote) $buffer .= $sql[++$i]; else $quote = null; }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') { $quote = $char; $buffer .= $char; continue; }
        if (($char === '-' && $next === '-' && ctype_space($sql[$i + 2] ?? ' ')) || $char === '#') { while ($i < $length && $sql[$i] !== "\n") $i++; $buffer .= "\n"; continue; }
        if ($char === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2); if ($end === false) crm_fail('MIGRATION_INVALID', 'Незакрытый комментарий SQL.', 500);
            if (($sql[$i + 2] ?? '') === '!') crm_fail('MIGRATION_INVALID', 'Исполняемые комментарии SQL не поддерживаются.', 500);
            $i = $end + 1; $buffer .= ' '; continue;
        }
        if ($char === ';') { if (trim($buffer) !== '') $out[] = trim($buffer); $buffer = ''; } else $buffer .= $char;
    }
    if ($quote !== null) crm_fail('MIGRATION_INVALID', 'Незакрытая строка SQL.', 500);
    if (trim($buffer) !== '') $out[] = trim($buffer);
    return $out;
}

function crm_run_migrations(?PDO $target = null): array
{
    $db = $target ?? crm_db();
    $run = static function (string $sql, array $values = []) use ($db): PDOStatement { $statement = $db->prepare($sql); $statement->execute($values); return $statement; };
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        // Explicit fixture schema only. Production MySQL migrations are never translated or marked applied.
        if (crm_config('environment') !== 'test') crm_fail('TEST_DRIVER_FORBIDDEN', 'Тестовая схема запрещена.', 503);
        $fixture = KCRM_ROOT . '/tests/core-fixture.sql';
        if (!is_file($fixture)) crm_fail('TEST_SCHEMA_MISSING', 'Отсутствует явно тестовая схема.', 503);
        foreach (crm_split_sql(file_get_contents($fixture)) as $statement) $db->exec($statement);
        return ['driver' => 'sqlite_test', 'fixture_only' => true, 'applied' => ['core-fixture.sql']];
    }
    $lock = 'kcrm_' . substr(hash('sha256', (string)$db->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
    if ((int)$run('SELECT GET_LOCK(?, 10)', [$lock])->fetchColumn() !== 1) crm_fail('MIGRATION_BUSY', 'Обновление структуры уже выполняется.', 503);
    $applied = [];
    try {
        $db->exec('CREATE TABLE IF NOT EXISTS kcrm_migrations (version VARCHAR(190) NOT NULL PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $files = glob(KCRM_ROOT . '/migrations/[0-9]*.sql') ?: []; sort($files, SORT_STRING);
        foreach ($files as $file) {
            $name = basename($file); $sql = file_get_contents($file); $checksum = hash('sha256', $sql);
            $old = $run('SELECT checksum FROM kcrm_migrations WHERE version=?', [$name])->fetchColumn();
            if ($old !== false) { if (!hash_equals($old, $checksum)) crm_fail('MIGRATION_CHANGED', 'Изменена уже применённая миграция: ' . $name, 409); continue; }
            if (preg_match('/^\s*DELIMITER\b/im', $sql)) crm_fail('MIGRATION_INVALID', 'DELIMITER не поддерживается мигратором.', 500);
            foreach (crm_split_sql($sql) as $statement) $db->exec($statement);
            $run('INSERT INTO kcrm_migrations (version,checksum,applied_at) VALUES (?,?,?)', [$name, $checksum, crm_now()]); $applied[] = $name;
        }
    } finally { $run('SELECT RELEASE_LOCK(?)', [$lock]); }
    return ['driver' => 'mysql', 'applied' => $applied];
}
