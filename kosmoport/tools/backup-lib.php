<?php
declare(strict_types=1);

/* Source-controlled transport. It does not bootstrap the application or connect
 * to a database until explicitly called. ZIP data is never executed as SQL/PHP. */
const KCRM_BACKUP_FORMAT = 'kosmoport-crm-backup';
const KCRM_BACKUP_VERSION = 1;

function kcrm_transfer_fail(string $message): never
{
    throw new RuntimeException($message);
}

function kcrm_transfer_json(mixed $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function kcrm_transfer_limits(array $overrides = []): array
{
    return array_replace([
        'archive_bytes' => 256 * 1024 * 1024,
        'expanded_bytes' => 512 * 1024 * 1024,
        'entry_bytes' => 128 * 1024 * 1024,
        'entries' => 10000,
        'manifest_bytes' => 4 * 1024 * 1024,
        'row_bytes' => 16 * 1024 * 1024,
    ], $overrides);
}

function kcrm_transfer_safe_name(string $name): string
{
    if ($name === '' || strlen($name) > 1024 || str_contains($name, '\\')
        || preg_match('/[\x00-\x1f\x7f:<>|?*]/', $name) || str_starts_with($name, '/')) {
        kcrm_transfer_fail('Небезопасное имя файла в архиве.');
    }
    foreach (explode('/', $name) as $part) {
        if ($part === '' || $part === '.' || $part === '..' || trim($part) !== $part
            || str_ends_with($part, '.') || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\.|$)/i', $part)) {
            kcrm_transfer_fail('Недопустимый сегмент пути в архиве.');
        }
    }
    return $name;
}

function kcrm_transfer_identifier(string $name, bool $table = false): string
{
    if (!preg_match($table ? '/^kcrm_[a-z0-9_]+$/D' : '/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
        kcrm_transfer_fail('Недопустимое имя таблицы или столбца.');
    }
    return chr(96) . $name . chr(96);
}

function kcrm_transfer_document_name(string $relative): void
{
    kcrm_transfer_safe_name('storage/' . $relative);
    foreach (explode('/', $relative) as $part) {
        if (in_array(strtolower($part), ['.htaccess', '.user.ini', 'web.config', 'php.ini'], true)) {
            kcrm_transfer_fail('Служебные настройки веб-сервера в документах запрещены.');
        }
    }
    if (preg_match('/\.(php[0-9]?|phtml|phar|cgi|pl|exe|bat|cmd|ps1|sh)$/i', $relative)) {
        kcrm_transfer_fail('Исполняемый файл в бизнес-хранилище запрещён.');
    }
}

function kcrm_transfer_driver(PDO $db): string
{
    $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (!in_array($driver, ['mysql', 'sqlite'], true)) {
        kcrm_transfer_fail('Поддерживается MySQL/MariaDB; SQLite разрешён для локальных тестов.');
    }
    return $driver;
}

function kcrm_transfer_database_name(PDO $db): string
{
    if (kcrm_transfer_driver($db) === 'mysql') {
        $name = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    } else {
        $list = $db->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
        $name = basename((string)($list[0]['file'] ?? ''));
    }
    if ($name === '') {
        kcrm_transfer_fail('Нужна явно выбранная именованная база данных.');
    }
    return $name;
}

function kcrm_transfer_tables(PDO $db, bool $all = false): array
{
    if (kcrm_transfer_driver($db) === 'mysql') {
        if ((int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND LEFT(EVENT_OBJECT_TABLE,5)='kcrm_'")->fetchColumn() > 0) {
            kcrm_transfer_fail('Триггеры в схеме CRM не поддерживаются переносом.');
        }
        $rows = $db->query("SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_NUM);
    } else {
        $rows = $db->query("SELECT name, type, 'SQLite' FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_NUM);
    }
    $tables = [];
    foreach ($rows as [$name, $type, $engine]) {
        if (!$all && !str_starts_with($name, 'kcrm_')) {
            continue;
        }
        if ($all && !str_starts_with($name, 'kcrm_')) {
            kcrm_transfer_fail('Целевая база содержит посторонние таблицы. Используйте отдельную базу.');
        }
        kcrm_transfer_identifier($name, true);
        if (!in_array(strtoupper((string)$type), ['BASE TABLE', 'TABLE'], true)) {
            kcrm_transfer_fail('Резервирование представлений не поддерживается.');
        }
        if (kcrm_transfer_driver($db) === 'mysql' && strtoupper((string)$engine) !== 'INNODB') {
            kcrm_transfer_fail('Для согласованной копии все таблицы CRM должны использовать InnoDB.');
        }
        $tables[] = $name;
    }
    if (!$tables || !in_array('kcrm_migrations', $tables, true)) {
        kcrm_transfer_fail('Не найдена установленная схема CRM с kcrm_migrations.');
    }
    sort($tables, SORT_STRING); // Canonical order is independent of server collation.
    return $tables;
}

function kcrm_transfer_schema(PDO $db, string $table): array
{
    $quoted = kcrm_transfer_identifier($table, true);
    $columns = [];
    if (kcrm_transfer_driver($db) === 'mysql') {
        foreach ($db->query('SHOW FULL COLUMNS FROM ' . $quoted)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (str_contains(strtolower((string)$row['Extra']), 'generated')) {
                kcrm_transfer_fail('Вычисляемые столбцы требуют отдельной версии формата.');
            }
            $columns[] = [
                'name' => $row['Field'], 'type' => preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower($row['Type'])),
                'nullable' => $row['Null'] === 'YES', 'default' => $row['Default'] === null ? null : (string)$row['Default'],
                'key' => $row['Key'], 'extra' => strtolower($row['Extra']),
                'collation' => $row['Collation'],
            ];
        }
        $indexes = [];
        foreach ($db->query('SHOW INDEX FROM ' . $quoted)->fetchAll(PDO::FETCH_ASSOC) as $index) {
            $indexes[] = [
                'name' => $index['Key_name'], 'sequence' => (int)$index['Seq_in_index'],
                'column' => $index['Column_name'], 'unique' => !(bool)$index['Non_unique'],
                'prefix' => $index['Sub_part'] === null ? null : (int)$index['Sub_part'], 'collation' => $index['Collation'],
                'type' => $index['Index_type'],
            ];
        }
        usort($indexes, static fn(array $a, array $b): int => [$a['name'], $a['sequence']] <=> [$b['name'], $b['sequence']]);
        // Separate filtered metadata reads avoid an expensive information_schema
        // JOIN on shared MySQL/MariaDB servers.
        $stmt = $db->prepare("SELECT CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,REFERENCED_TABLE_SCHEMA,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION");
        $stmt->execute([$table]);
        $keyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rules = [];
        if ($keyRows) {
            $ruleStatement = $db->prepare("SELECT CONSTRAINT_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=?");
            $ruleStatement->execute([$table]);
            foreach ($ruleStatement->fetchAll(PDO::FETCH_ASSOC) as $rule) {
                $rules[$rule['CONSTRAINT_NAME']] = $rule;
            }
        }
        $foreignKeys = [];
        $databaseName = kcrm_transfer_database_name($db);
        foreach ($keyRows as $key) {
            if ($key['REFERENCED_TABLE_SCHEMA'] !== $databaseName) {
                kcrm_transfer_fail('Внешний ключ за пределы отдельной базы CRM запрещён.');
            }
            unset($key['REFERENCED_TABLE_SCHEMA']);
            $key['ORDINAL_POSITION'] = (int)$key['ORDINAL_POSITION'];
            if (!isset($rules[$key['CONSTRAINT_NAME']])) {
                kcrm_transfer_fail('Не удалось прочитать ограничения внешнего ключа.');
            }
            foreach (['UPDATE_RULE', 'DELETE_RULE'] as $field) {
                $key[$field] = $rules[$key['CONSTRAINT_NAME']][$field] === 'NO ACTION' ? 'RESTRICT' : $rules[$key['CONSTRAINT_NAME']][$field];
            }
            $foreignKeys[] = $key;
        }
        $dependencies = array_values(array_unique(array_column($foreignKeys, 'REFERENCED_TABLE_NAME')));
        sort($dependencies, SORT_STRING);
    } else {
        foreach ($db->query('PRAGMA table_info(' . $quoted . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[] = [
                'name' => $row['name'], 'type' => strtolower($row['type']),
                'nullable' => !(bool)$row['notnull'], 'default' => $row['dflt_value'],
                'key' => $row['pk'] ? 'PRI' : '', 'extra' => '', 'collation' => null,
            ];
        }
        $dependencies = array_values(array_unique(array_column($db->query('PRAGMA foreign_key_list(' . $quoted . ')')->fetchAll(PDO::FETCH_ASSOC), 'table')));
        sort($dependencies, SORT_STRING);
        $indexes = $db->query('PRAGMA index_list(' . $quoted . ')')->fetchAll(PDO::FETCH_ASSOC);
        $foreignKeys = $db->query('PRAGMA foreign_key_list(' . $quoted . ')')->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($columns as $column) {
        kcrm_transfer_identifier($column['name']);
    }
    return ['columns' => $columns, 'dependencies' => $dependencies, 'indexes' => $indexes, 'foreign_keys' => $foreignKeys];
}

function kcrm_transfer_encoded_rows(PDO $db, string $table): Generator
{
    $mysql = kcrm_transfer_driver($db) === 'mysql';
    $bufferedAttribute = $mysql ? (defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') : constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) : 0;
    $buffered = $mysql ? $db->getAttribute($bufferedAttribute) : null;
    $stmt = null;
    try {
        if ($mysql) {
            $db->setAttribute($bufferedAttribute, false);
        }
        $stmt = $db->query('SELECT * FROM ' . kcrm_transfer_identifier($table, true));
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            yield array_map(static fn($value) => $value === null ? null : base64_encode((string)$value), $row);
        }
    } finally {
        $stmt?->closeCursor();
        if ($mysql) {
            $db->setAttribute($bufferedAttribute, $buffered);
        }
    }
}

function kcrm_transfer_migrations(PDO $db): array
{
    $rows = iterator_to_array(kcrm_transfer_encoded_rows($db, 'kcrm_migrations'), false);
    usort($rows, static fn(array $a, array $b): int => strcmp(kcrm_transfer_json($a), kcrm_transfer_json($b)));
    return $rows;
}

function kcrm_transfer_migration_identity(array $rows, array $columns): array
{
    $identity = [];
    foreach ($rows as $row) {
        $item = [];
        foreach ($columns as $i => $column) {
            if (!in_array($column, ['applied_at', 'created_at', 'executed_at'], true)) {
                $item[$column] = $row[$i];
            }
        }
        if (!$item) {
            kcrm_transfer_fail('В журнале миграций отсутствует идентичность версии.');
        }
        $identity[] = $item;
    }
    usort($identity, static fn(array $a, array $b): int => strcmp(kcrm_transfer_json($a), kcrm_transfer_json($b)));
    return $identity;
}

function kcrm_transfer_temp_dir(?string $parent = null): string
{
    $parent ??= sys_get_temp_dir();
    $root = rtrim($parent, '/\\') . DIRECTORY_SEPARATOR . 'kcrm-transfer-' . bin2hex(random_bytes(12));
    if (!mkdir($root, 0700)) {
        kcrm_transfer_fail('Не удалось создать временный каталог.');
    }
    return $root;
}

function kcrm_transfer_remove_temp(string $dir): void
{
    // Only a directory made by our random private staging helper is eligible.
    if (!preg_match('/^kcrm-transfer-[0-9a-f]{24}$/D', basename($dir)) || is_link($dir) || !is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

function kcrm_transfer_zip(): ZipArchive
{
    if (!class_exists('ZipArchive')) {
        kcrm_transfer_fail('На PHP требуется расширение zip (ZipArchive).');
    }
    return new ZipArchive();
}

function kcrm_transfer_storage_files(string $storage): array
{
    if (!is_dir($storage) || is_link($storage)) {
        kcrm_transfer_fail('Приватный каталог хранения не найден или является ссылкой.');
    }
    $files = [];
    $root = realpath($storage);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $canonicalFile = realpath($file->getPathname());
        $normalize = static fn(string $p): string => PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $p)) : $p;
        if ($file->isLink() || $canonicalFile === false
            || $normalize($canonicalFile) !== $normalize($file->getPathname())
            || !str_starts_with($normalize($canonicalFile), $normalize($root) . '/')) {
            kcrm_transfer_fail('Символические ссылки в хранилище запрещены.');
        }
        if (!$file->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        // Runtime state and backup archives never belong in the business bundle.
        if (preg_match('~^(tmp|temp|cache|sessions|logs|backups)(/|$)~i', $relative)
            || in_array(basename($relative), ['.htaccess', 'index.html', '.gitkeep'], true)) {
            continue;
        }
        if ($relative === 'documents/index.php' && file_get_contents($file->getPathname()) === "<?php\nhttp_response_code(404);\nexit;\n") {
            continue; // A fixed application-generated HTTP denial guard, never business data.
        }
        kcrm_transfer_document_name($relative);
        $files[$relative] = $file->getPathname();
        if (count($files) > 10000) {
            kcrm_transfer_fail('Количество файлов хранилища превышает лимит копирования.');
        }
    }
    ksort($files, SORT_STRING);
    return $files;
}

function kcrm_transfer_write_all($stream, string $bytes): void
{
    $length = strlen($bytes);
    for ($offset = 0; $offset < $length;) {
        $written = fwrite($stream, substr($bytes, $offset));
        if ($written === false || $written === 0) {
            kcrm_transfer_fail('Не удалось записать резервную копию; проверьте свободное место.');
        }
        $offset += $written;
    }
}

function kcrm_backup_create(PDO $db, string $storage, string $output, array $options = []): array
{
    if (file_exists($output) || is_link($output)) {
        kcrm_transfer_fail('Выходной файл уже существует. Выберите новое имя.');
    }
    $outputParent = realpath(dirname($output));
    $storageRoot = realpath($storage);
    $normalize = static fn(string $p): string => PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $p)) : $p;
    if ($outputParent === false || $storageRoot === false
        || $normalize($outputParent) === $normalize($storageRoot)
        || str_starts_with($normalize($outputParent), $normalize($storageRoot) . '/')) {
        kcrm_transfer_fail('Архив должен сохраняться вне исходного бизнес-хранилища.');
    }
    $limits = kcrm_transfer_limits($options['limits'] ?? []);
    $tables = kcrm_transfer_tables($db);
    $staging = kcrm_transfer_temp_dir($options['temp_dir'] ?? null);
    $partial = $output . '.' . bin2hex(random_bytes(6)) . '.partial';
    $zip = kcrm_transfer_zip();
    $opened = false;
    $ownTransaction = false;
    try {
        if ($db->inTransaction()) {
            kcrm_transfer_fail('Копирование нельзя запускать внутри другой транзакции.');
        }
        if (kcrm_transfer_driver($db) === 'mysql') {
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $db->beginTransaction();
        $ownTransaction = true;
        $migrations = kcrm_transfer_migrations($db);
        $manifest = [
            'format' => KCRM_BACKUP_FORMAT, 'format_version' => KCRM_BACKUP_VERSION,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'application_version' => (string)($options['application_version'] ?? 'unknown'),
            'source_database' => kcrm_transfer_database_name($db), 'driver' => kcrm_transfer_driver($db),
            'schema_version' => hash('sha256', kcrm_transfer_json(kcrm_transfer_migration_identity($migrations, array_column(kcrm_transfer_schema($db, 'kcrm_migrations')['columns'], 'name')))),
            'migrations' => $migrations, 'tables' => [], 'entries' => [],
        ];
        if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            kcrm_transfer_fail('Не удалось создать ZIP.');
        }
        $opened = true;
        $expanded = 0;
        $stagedFiles = [];
        foreach ($tables as $table) {
            $entry = 'database/' . $table . '.jsonl';
            $path = $staging . DIRECTORY_SEPARATOR . $table . '.jsonl';
            $out = fopen($path, 'xb');
            if ($out === false) {
                kcrm_transfer_fail('Не удалось открыть временный файл.');
            }
            $rowCount = 0;
            try {
                foreach (kcrm_transfer_encoded_rows($db, $table) as $cells) {
                    $line = kcrm_transfer_json(['cells' => $cells]) . "\n";
                    if (strlen($line) > $limits['row_bytes']) {
                        kcrm_transfer_fail('Строка БД превышает лимит копирования.');
                    }
                    kcrm_transfer_write_all($out, $line);
                    $rowCount++;
                    if (ftell($out) > $limits['entry_bytes']) {
                        kcrm_transfer_fail('Таблица превышает лимит копирования.');
                    }
                }
            } finally {
                fclose($out);
            }
            $size = filesize($path);
            $expanded += $size;
            if ($expanded > $limits['expanded_bytes'] || count($manifest['entries']) + 2 > $limits['entries']) {
                kcrm_transfer_fail('Превышен лимит резервной копии.');
            }
            $manifest['entries'][$entry] = ['bytes' => $size, 'sha256' => hash_file('sha256', $path)];
            $manifest['tables'][$table] = ['entry' => $entry, 'rows' => $rowCount, 'schema' => kcrm_transfer_schema($db, $table)];
            $stagedFiles[$entry] = $path;
            if (!$zip->addFile($path, $entry)) {
                kcrm_transfer_fail('Не удалось добавить таблицу в ZIP.');
            }
        }
        // Document version files are immutable. Copy them to private staging
        // while the database snapshot is open; reject a changing/disappearing file.
        foreach (kcrm_transfer_storage_files($storage) as $relative => $source) {
            $entry = 'storage/' . $relative;
            $size = filesize($source);
            $expanded += $size;
            if ($size > $limits['entry_bytes'] || $expanded > $limits['expanded_bytes']
                || count($manifest['entries']) + 2 > $limits['entries']) {
                kcrm_transfer_fail('Превышен лимит резервной копии.');
            }
            $copy = $staging . DIRECTORY_SEPARATOR . 'document-' . hash('sha256', $relative);
            $before = hash_file('sha256', $source);
            if (!copy($source, $copy) || !hash_equals($before, hash_file('sha256', $copy))
                || !hash_equals($before, hash_file('sha256', $source))) {
                kcrm_transfer_fail('Документ изменился во время копирования. Повторите в режиме обслуживания.');
            }
            $manifest['entries'][$entry] = ['bytes' => $size, 'sha256' => $before];
            $stagedFiles[$entry] = $copy;
            if (!$zip->addFile($copy, $entry)) {
                kcrm_transfer_fail('Не удалось добавить документ в ZIP.');
            }
        }
        if ($expanded > $limits['expanded_bytes'] || count($manifest['entries']) + 1 > $limits['entries']) {
            kcrm_transfer_fail('Превышен лимит резервной копии.');
        }
        kcrm_backup_check_document_refs($manifest, $stagedFiles, $limits);
        $json = kcrm_transfer_json($manifest) . "\n";
        if (strlen($json) > $limits['manifest_bytes'] || $expanded + strlen($json) > $limits['expanded_bytes']
            || !$zip->addFromString('manifest.json', $json)) {
            kcrm_transfer_fail('Манифест резервной копии слишком велик.');
        }
        if (!$zip->close()) {
            kcrm_transfer_fail('ZIP не закрыт; копия не завершена.');
        }
        $opened = false;
        $db->commit();
        $ownTransaction = false;
        if (filesize($partial) > $limits['archive_bytes']) {
            kcrm_transfer_fail('ZIP превышает лимит загрузки/восстановления.');
        }
        @chmod($partial, 0600);
        // Publish without Unix rename's overwrite race. Hard-link is atomic on
        // the same volume; shared hosts can fall back to exclusive creation.
        $linked = function_exists('link') && @link($partial, $output);
        if (!$linked) {
            $final = @fopen($output, 'xb');
            if ($final === false) {
                kcrm_transfer_fail('Не удалось сохранить копию без перезаписи существующего файла.');
            }
            $input = fopen($partial, 'rb');
            $complete = false;
            try {
                if ($input === false || stream_copy_to_stream($input, $final) !== filesize($partial) || !fflush($final)) {
                    kcrm_transfer_fail('Ошибка записи окончательной копии.');
                }
                $complete = true;
            } finally {
                if (is_resource($input)) {
                    fclose($input);
                }
                fclose($final);
                if (!$complete) {
                    @unlink($output);
                }
            }
        }
        @chmod($output, 0600);
        return $manifest;
    } finally {
        if ($opened) {
            $zip->close();
        }
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        @unlink($partial);
        kcrm_transfer_remove_temp($staging);
    }
}

function kcrm_backup_stage(string $archive, array $options = []): array
{
    $limits = kcrm_transfer_limits($options['limits'] ?? []);
    if (!is_file($archive) || is_link($archive) || filesize($archive) > $limits['archive_bytes']) {
        kcrm_transfer_fail('Архив не найден или превышает лимит.');
    }
    $zip = kcrm_transfer_zip();
    if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
        kcrm_transfer_fail('Некорректный ZIP.');
    }
    $stage = kcrm_transfer_temp_dir($options['temp_dir'] ?? null);
    try {
        if ($zip->numFiles < 2 || $zip->numFiles > $limits['entries']) {
            kcrm_transfer_fail('Недопустимое количество файлов в ZIP.');
        }
        $names = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                kcrm_transfer_fail('Повреждённый каталог ZIP.');
            }
            $name = kcrm_transfer_safe_name($stat['name']);
            $folded = strtolower($name);
            if (isset($names[$folded])) {
                kcrm_transfer_fail('Повторяющийся путь в ZIP.');
            }
            $opsys = $attr = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $attr);
            if (($opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000)
                || ($stat['encryption_method'] ?? 0) !== 0) {
                kcrm_transfer_fail('Ссылки и зашифрованные ZIP-записи запрещены.');
            }
            $limit = $name === 'manifest.json' ? $limits['manifest_bytes'] : $limits['entry_bytes'];
            if ($stat['size'] > $limit || ($total += $stat['size']) > $limits['expanded_bytes']) {
                kcrm_transfer_fail('Превышен лимит распакованного архива.');
            }
            $names[$folded] = ['name' => $name, 'size' => $stat['size']];
        }
        if (!isset($names['manifest.json'])) {
            kcrm_transfer_fail('Манифест отсутствует.');
        }
        $raw = $zip->getFromName('manifest.json', $limits['manifest_bytes'] + 1);
        $manifest = json_decode($raw === false ? '' : $raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== KCRM_BACKUP_FORMAT
            || ($manifest['format_version'] ?? null) !== KCRM_BACKUP_VERSION
            || !is_array($manifest['entries'] ?? null) || !is_array($manifest['tables'] ?? null)
            || !is_array($manifest['migrations'] ?? null) || !is_string($manifest['source_database'] ?? null)
            || !is_string($manifest['schema_version'] ?? null)
            || !isset($manifest['tables']['kcrm_migrations'])) {
            kcrm_transfer_fail('Неизвестный формат или повреждённый манифест.');
        }
        if (count($names) !== count($manifest['entries']) + 1) {
            kcrm_transfer_fail('В ZIP есть посторонние или пропущенные файлы.');
        }
        $files = [];
        foreach ($manifest['entries'] as $name => $metadata) {
            kcrm_transfer_safe_name($name);
            if (!preg_match('~^(database/kcrm_[a-z0-9_]+\.jsonl|storage/.+)$~D', $name)
                || !is_array($metadata) || !is_int($metadata['bytes'] ?? null) || $metadata['bytes'] < 0
                || !preg_match('/^[a-f0-9]{64}$/D', (string)($metadata['sha256'] ?? ''))
                || ($names[strtolower($name)]['name'] ?? null) !== $name
                || $names[strtolower($name)]['size'] !== $metadata['bytes']) {
                kcrm_transfer_fail('Повреждённый список файлов.');
            }
            if (str_starts_with($name, 'storage/')) {
                kcrm_transfer_document_name(substr($name, 8));
                $segments = explode('/', $name);
                array_pop($segments);
                while (count($segments) > 1) {
                    if (isset($manifest['entries'][implode('/', $segments)])) {
                        kcrm_transfer_fail('Один путь в архиве одновременно является файлом и каталогом.');
                    }
                    array_pop($segments);
                }
            }
            $input = $zip->getStream($name);
            $path = $stage . DIRECTORY_SEPARATOR . hash('sha256', $name);
            $out = fopen($path, 'xb');
            if ($input === false || $out === false) {
                kcrm_transfer_fail('Не удалось открыть содержимое ZIP.');
            }
            $hash = hash_init('sha256');
            $bytes = 0;
            try {
                while (!feof($input)) {
                    $chunk = fread($input, 65536);
                    if ($chunk === false) {
                        kcrm_transfer_fail('Ошибка чтения ZIP.');
                    }
                    $bytes += strlen($chunk);
                    if ($bytes > $metadata['bytes']) {
                        kcrm_transfer_fail('Фактический размер записи превышает заявленный.');
                    }
                    hash_update($hash, $chunk);
                    kcrm_transfer_write_all($out, $chunk);
                }
            } finally {
                fclose($input);
                fclose($out);
            }
            if ($bytes !== $metadata['bytes'] || !hash_equals($metadata['sha256'], hash_final($hash))) {
                kcrm_transfer_fail('Контрольная сумма не совпадает.');
            }
            $files[$name] = $path;
        }
        foreach ($manifest['tables'] as $table => $info) {
            kcrm_transfer_identifier($table, true);
            if (!is_array($info) || ($info['entry'] ?? null) !== 'database/' . $table . '.jsonl'
                || !isset($files[$info['entry']]) || !is_int($info['rows'] ?? null) || $info['rows'] < 0
                || !is_array($info['schema']['columns'] ?? null) || !$info['schema']['columns']
                || !is_array($info['schema']['dependencies'] ?? null)) {
                kcrm_transfer_fail('Некорректная схема таблицы в копии.');
            }
            $namesSeen = [];
            foreach ($info['schema']['columns'] as $column) {
                $columnName = $column['name'] ?? '';
                kcrm_transfer_identifier($columnName);
                if (isset($namesSeen[$columnName])) {
                    kcrm_transfer_fail('Повтор столбца в манифесте.');
                }
                $namesSeen[$columnName] = true;
            }
            foreach ($info['schema']['dependencies'] as $dependency) {
                if (!is_string($dependency) || !isset($manifest['tables'][$dependency])) {
                    kcrm_transfer_fail('Ссылка за пределы схемы CRM.');
                }
            }
            $count = 0;
            foreach (kcrm_backup_read_rows($files[$info['entry']], count($namesSeen), $limits) as $unused) {
                $count++;
            }
            if ($count !== $info['rows']) {
                kcrm_transfer_fail('Количество строк не совпадает с манифестом.');
            }
        }
        foreach ($files as $name => $unused) {
            if (str_starts_with($name, 'database/') && !isset($manifest['tables'][substr($name, 9, -6)])) {
                kcrm_transfer_fail('В архиве есть незаявленная таблица.');
            }
        }
        // The migration table must agree with the independently declared schema version.
        $actualMigrations = iterator_to_array(kcrm_backup_read_rows($files['database/kcrm_migrations.jsonl'], count($manifest['tables']['kcrm_migrations']['schema']['columns']), $limits), false);
        $actualMigrations = array_map(static fn(array $row): array => array_map(static fn($v) => $v === null ? null : base64_encode($v), $row), $actualMigrations);
        usort($actualMigrations, static fn(array $a, array $b): int => strcmp(kcrm_transfer_json($a), kcrm_transfer_json($b)));
        if ($actualMigrations !== $manifest['migrations']) {
            kcrm_transfer_fail('Версия схемы не соответствует таблице миграций.');
        }
        $identity = kcrm_transfer_migration_identity($actualMigrations, array_column($manifest['tables']['kcrm_migrations']['schema']['columns'], 'name'));
        if (!hash_equals(hash('sha256', kcrm_transfer_json($identity)), $manifest['schema_version'])) {
            kcrm_transfer_fail('Идентичность версии схемы не совпадает с журналом миграций.');
        }
        kcrm_backup_check_document_refs($manifest, $files, $limits);
        return ['manifest' => $manifest, 'files' => $files, 'stage' => $stage];
    } catch (Throwable $e) {
        kcrm_transfer_remove_temp($stage);
        throw $e;
    } finally {
        $zip->close();
    }
}

function kcrm_backup_read_rows(string $path, int $columns, array $limits): Generator
{
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        kcrm_transfer_fail('Не удалось прочитать таблицу.');
    }
    try {
        while (($line = fgets($stream, $limits['row_bytes'] + 1)) !== false) {
            if (strlen($line) > $limits['row_bytes'] || !str_ends_with($line, "\n")) {
                kcrm_transfer_fail('Строка таблицы превышает лимит или повреждена.');
            }
            $row = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($row) || array_keys($row) !== ['cells'] || !is_array($row['cells'])
                || !array_is_list($row['cells']) || count($row['cells']) !== $columns) {
                kcrm_transfer_fail('Неверный формат строки таблицы.');
            }
            $cells = [];
            foreach ($row['cells'] as $cell) {
                if ($cell === null) {
                    $cells[] = null;
                } elseif (!is_string($cell) || ($decoded = base64_decode($cell, true)) === false || base64_encode($decoded) !== $cell) {
                    kcrm_transfer_fail('Неверное кодирование значения таблицы.');
                } else {
                    $cells[] = $decoded;
                }
            }
            yield $cells;
        }
    } finally {
        fclose($stream);
    }
}

function kcrm_backup_verify(string $archive, array $options = []): array
{
    $verified = kcrm_backup_stage($archive, $options);
    try {
        return $verified['manifest'];
    } finally {
        kcrm_transfer_remove_temp($verified['stage']);
    }
}

function kcrm_backup_check_document_refs(array $manifest, array $files, array $limits): void
{
    if (!isset($manifest['tables']['kcrm_document_versions'])) {
        return;
    }
    $info = $manifest['tables']['kcrm_document_versions'];
    $columns = array_column($info['schema']['columns'], 'name');
    foreach (['storage_key', 'size_bytes', 'sha256'] as $required) {
        if (!in_array($required, $columns, true)) {
            kcrm_transfer_fail('Неизвестная схема версий документов.');
        }
    }
    foreach (kcrm_backup_read_rows($files[$info['entry']], count($columns), $limits) as $cells) {
        $row = array_combine($columns, $cells);
        $key = $row['storage_key'];
        if (!is_string($key) || !preg_match('~^objects/[a-f0-9]{2}/[a-f0-9]{32}/[a-f0-9]{32}\.blob$~D', $key)) {
            kcrm_transfer_fail('Недопустимый ключ версии документа.');
        }
        $name = 'storage/documents/' . $key;
        $metadata = $manifest['entries'][$name] ?? null;
        if (!is_string($row['size_bytes']) || !ctype_digit($row['size_bytes'])
            || !is_string($row['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $row['sha256'])
            || !is_array($metadata) || !isset($files[$name]) || (int)$row['size_bytes'] !== $metadata['bytes']
            || !hash_equals($row['sha256'], $metadata['sha256'])) {
            kcrm_transfer_fail('В копии отсутствует или повреждена версия документа из БД.');
        }
    }
}

function kcrm_restore_order(array $tables): array
{
    $result = [];
    $pending = $tables;
    while ($pending) {
        $progress = false;
        foreach ($pending as $table => $info) {
            $dependencies = array_diff($info['schema']['dependencies'], [$table]);
            if (!array_diff($dependencies, $result)) {
                $result[] = $table;
                unset($pending[$table]);
                $progress = true;
            }
        }
        if (!$progress) {
            kcrm_transfer_fail('Циклические внешние ключи требуют отдельной процедуры восстановления.');
        }
    }
    return $result;
}

function kcrm_transfer_pristine_setup(PDO $db): bool
{
    $rows = $db->query('SELECT id,owner_id,completed_at FROM kcrm_setup')->fetchAll(PDO::FETCH_NUM);
    return count($rows) === 1 && (string)$rows[0][0] === '1' && $rows[0][1] === null && $rows[0][2] === null;
}

function kcrm_transfer_pristine_ticket_lock(PDO $db): bool
{
    $rows = $db->query('SELECT id,touched FROM kcrm_ticket_lock')->fetchAll(PDO::FETCH_NUM);
    return count($rows) === 1 && (string)$rows[0][0] === '1' && (string)$rows[0][1] === '0';
}

function kcrm_transfer_target_empty(PDO $target, array $tables): void
{
    foreach ($tables as $table) {
        if ($table === 'kcrm_migrations' || (int)$target->query('SELECT COUNT(*) FROM ' . kcrm_transfer_identifier($table, true))->fetchColumn() === 0) {
            continue;
        }
        if (($table === 'kcrm_setup' && kcrm_transfer_pristine_setup($target))
            || ($table === 'kcrm_ticket_lock' && kcrm_transfer_pristine_ticket_lock($target))) {
            continue;
        }
        kcrm_transfer_fail('Целевая база содержит рабочие записи. Перезапись запрещена.');
    }
}

function kcrm_restore_prepare_schema(PDO $target, string $expectedDatabase, ?string $trustedRoot = null): array
{
    if (kcrm_transfer_driver($target) !== 'mysql' || $expectedDatabase === ''
        || !hash_equals($expectedDatabase, kcrm_transfer_database_name($target)) || $target->inTransaction()) {
        kcrm_transfer_fail('Для подготовки нужна явно указанная новая база MySQL.');
    }
    $trustedRoot ??= dirname(__DIR__);
    $parser = $trustedRoot . '/app/core/migrations.php';
    if (!is_file($parser) || is_link($parser)) {
        kcrm_transfer_fail('Отсутствует доверенный SQL-парсер ядра.');
    }
    require_once $parser;
    $lockName = 'kcrm-restore-' . substr(hash('sha256', $expectedDatabase), 0, 48);
    $lock = $target->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        kcrm_transfer_fail('Целевая база занята подготовкой или восстановлением.');
    }
    try {
        if ((int)$target->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() !== 0) {
            kcrm_transfer_fail('Подготовка допускается только в базе совсем без таблиц. Перезапись запрещена.');
        }
        $files = glob($trustedRoot . '/migrations/[0-9]*.sql') ?: [];
        sort($files, SORT_STRING);
        if (!$files) {
            kcrm_transfer_fail('Не найдены миграции установленной версии.');
        }
        $ready = [];
        foreach ($files as $file) {
            if (is_link($file) || !preg_match('/^[0-9]{3}_[a-z0-9_]+\.sql$/D', basename($file))) {
                kcrm_transfer_fail('Недопустимый файл доверенной миграции.');
            }
            $sql = file_get_contents($file);
            if ($sql === false || preg_match('/^\s*DELIMITER\b/im', $sql)) {
                kcrm_transfer_fail('Неподдерживаемый файл миграции.');
            }
            $statements = crm_split_sql($sql);
            // This preparation path only installs reviewed declarative tables
            // and the exact inert setup seed. It never runs arbitrary data SQL.
            foreach ($statements as $statement) {
                if (!preg_match('/^(CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|ALTER\s+TABLE)\s+' . chr(96) . '?kcrm_[a-z0-9_]+' . chr(96) . '?(?:\s|\()/i', $statement)
                    && !preg_match('/^INSERT\s+IGNORE\s+INTO\s+kcrm_setup\s*\(\s*id\s*,\s*owner_id\s*,\s*completed_at\s*\)\s*VALUES\s*\(\s*1\s*,\s*NULL\s*,\s*NULL\s*\)$/iD', $statement)
                    && !preg_match('/^INSERT\s+IGNORE\s+INTO\s+kcrm_ticket_lock\s*\(\s*id\s*,\s*touched\s*\)\s*VALUES\s*\(\s*1\s*,\s*0\s*\)$/iD', $statement)) {
                    kcrm_transfer_fail('Миграция требует отдельной проверки перед подготовкой новой базы.');
                }
                if (preg_match('/\b(TRIGGER|PROCEDURE|FUNCTION|OUTFILE|INFILE|LOAD_FILE)\b/i', $statement)) {
                    kcrm_transfer_fail('Исполняемые объекты в миграциях переноса запрещены.');
                }
            }
            $ready[] = ['name' => basename($file), 'checksum' => hash('sha256', $sql), 'statements' => $statements];
        }
        // DDL may commit implicitly. Failure leaves only this new disposable
        // target for inspection; no attempt is made to drop partially made tables.
        $target->exec('CREATE TABLE IF NOT EXISTS kcrm_migrations (version VARCHAR(190) NOT NULL PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $journal = $target->prepare('INSERT INTO kcrm_migrations (version,checksum,applied_at) VALUES (?,?,?)');
        foreach ($ready as $migration) {
            foreach ($migration['statements'] as $statement) {
                $target->exec($statement);
            }
            $journal->execute([$migration['name'], $migration['checksum'], gmdate('Y-m-d H:i:s')]);
        }
        return ['database' => $expectedDatabase, 'applied' => array_column($ready, 'name'), 'owner_created' => false];
    } finally {
        $release = $target->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}

function kcrm_restore_preflight(PDO $target, array $manifest, string $expectedDatabase): array
{
    $actual = kcrm_transfer_database_name($target);
    if ($expectedDatabase === '' || !hash_equals($expectedDatabase, $actual)
        || strcasecmp($expectedDatabase, $manifest['source_database']) === 0) {
        kcrm_transfer_fail('Укажите отдельную целевую базу с другим именем и подтвердите её точное имя.');
    }
    if (kcrm_transfer_driver($target) !== $manifest['driver']) {
        kcrm_transfer_fail('Драйвер базы не соответствует копии.');
    }
    $targetTables = kcrm_transfer_tables($target, true);
    $backupTables = array_keys($manifest['tables']);
    sort($backupTables, SORT_STRING);
    if ($targetTables !== $backupTables) {
        kcrm_transfer_fail('Состав таблиц не совпадает. Установите точную версию схемы из доверенного исходного пакета.');
    }
    foreach ($targetTables as $table) {
        if (kcrm_transfer_schema($target, $table) !== $manifest['tables'][$table]['schema']) {
            kcrm_transfer_fail('Структура таблицы отличается: ' . $table);
        }
    }
    kcrm_transfer_target_empty($target, $targetTables);
    if (kcrm_transfer_migrations($target) !== $manifest['migrations']) {
        // Migration timestamps differ between installations. Compare migration identity
        // excluding only known timestamp columns, keeping names/checksums/versions exact.
        $columns = array_column($manifest['tables']['kcrm_migrations']['schema']['columns'], 'name');
        if (kcrm_transfer_migration_identity(kcrm_transfer_migrations($target), $columns) !== kcrm_transfer_migration_identity($manifest['migrations'], $columns)) {
            kcrm_transfer_fail('Версии/контрольные суммы миграций не совпадают.');
        }
    }
    return kcrm_restore_order($manifest['tables']);
}

function kcrm_backup_restore(PDO $target, string $archive, string $targetStorage, string $expectedDatabase, array $options = []): array
{
    $verified = kcrm_backup_stage($archive, $options); // All bytes/rows checked before any writes.
    $manifest = $verified['manifest'];
    $createdFiles = [];
    $createdDirs = [];
    $ownTransaction = false;
    $committed = false;
    $commitAttempted = false;
    $databaseLock = null;
    try {
        if ($target->inTransaction()) {
            kcrm_transfer_fail('Восстановление нельзя запускать внутри другой транзакции.');
        }
        if (kcrm_transfer_driver($target) === 'mysql') {
            $databaseLock = 'kcrm-restore-' . substr(hash('sha256', kcrm_transfer_database_name($target)), 0, 48);
            $lockStatement = $target->prepare('SELECT GET_LOCK(?, 0)');
            $lockStatement->execute([$databaseLock]);
            if ((int)$lockStatement->fetchColumn() !== 1) {
                $databaseLock = null;
                kcrm_transfer_fail('Эта целевая база уже занята другим восстановлением.');
            }
        }
        $order = kcrm_restore_preflight($target, $manifest, $expectedDatabase);
        if (!is_dir($targetStorage) || is_link($targetStorage)) {
            kcrm_transfer_fail('Заранее создайте отдельный пустой приватный каталог для целевых документов.');
        }
        $targetStorage = realpath($targetStorage);
        foreach (new FilesystemIterator($targetStorage, FilesystemIterator::SKIP_DOTS) as $file) {
            if (!in_array($file->getFilename(), ['.htaccess', 'index.html', '.gitkeep'], true) || !$file->isFile() || $file->isLink()) {
                kcrm_transfer_fail('Целевое хранилище непустое. Выберите новый каталог.');
            }
        }
        $lockPath = $targetStorage . DIRECTORY_SEPARATOR . '.restore-lock';
        $lock = @fopen($lockPath, 'xb');
        if ($lock === false) {
            kcrm_transfer_fail('Целевое хранилище занято восстановлением.');
        }
        $createdFiles[] = $lockPath;
        try {
            kcrm_transfer_write_all($lock, gmdate('c'));
        } finally {
            fclose($lock);
        }
        $target->beginTransaction();
        $ownTransaction = true;
        // Recheck inside the transaction to catch concurrent writes before the first insert.
        kcrm_transfer_target_empty($target, $order);
        foreach ($order as $table) {
            if ($table === 'kcrm_migrations') {
                continue; // Keep the target installation's trusted migration journal.
            }
            $info = $manifest['tables'][$table];
            $columns = array_column($info['schema']['columns'], 'name');
            if ($table === 'kcrm_setup' && kcrm_transfer_pristine_setup($target)) {
                $rows = iterator_to_array(kcrm_backup_read_rows($verified['files'][$info['entry']], count($columns), kcrm_transfer_limits($options['limits'] ?? [])), false);
                if ($columns !== ['id', 'owner_id', 'completed_at'] || count($rows) !== 1 || $rows[0][0] !== '1') {
                    kcrm_transfer_fail('Неизвестное состояние первоначальной настройки в копии.');
                }
                $setup = $target->prepare('UPDATE kcrm_setup SET owner_id=?,completed_at=? WHERE id=1 AND owner_id IS NULL AND completed_at IS NULL');
                $setup->execute([$rows[0][1], $rows[0][2]]);
                $actual = $target->query('SELECT id,owner_id,completed_at FROM kcrm_setup')->fetchAll(PDO::FETCH_NUM);
                $actual = array_map(static fn(array $r): array => array_map(static fn($v) => $v === null ? null : (string)$v, $r), $actual);
                if ($actual !== $rows) {
                    kcrm_transfer_fail('Целевая первоначальная настройка была занята другим процессом.');
                }
                continue; // Only this exact inert row from trusted 001_core.sql is updated.
            }
            if ($table === 'kcrm_ticket_lock' && kcrm_transfer_pristine_ticket_lock($target)) {
                $rows = iterator_to_array(kcrm_backup_read_rows($verified['files'][$info['entry']], count($columns), kcrm_transfer_limits($options['limits'] ?? [])), false);
                if ($columns !== ['id', 'touched'] || count($rows) !== 1 || $rows[0][0] !== '1'
                    || !is_string($rows[0][1]) || !ctype_digit($rows[0][1]) || (int)$rows[0][1] > 2147483647) {
                    kcrm_transfer_fail('Неизвестное состояние технической блокировки билетов в копии.');
                }
                $ticketLock = $target->prepare('UPDATE kcrm_ticket_lock SET touched=? WHERE id=1 AND touched=0');
                $ticketLock->execute([$rows[0][1]]);
                if ((string)$target->query('SELECT touched FROM kcrm_ticket_lock WHERE id=1')->fetchColumn() !== $rows[0][1]) {
                    kcrm_transfer_fail('Целевая блокировка билетов уже использована.');
                }
                continue; // Only the inert seed of trusted 030_tickets.sql.
            }
            $sql = 'INSERT INTO ' . kcrm_transfer_identifier($table, true) . ' ('
                . implode(',', array_map('kcrm_transfer_identifier', $columns)) . ') VALUES ('
                . implode(',', array_fill(0, count($columns), '?')) . ')';
            $stmt = $target->prepare($sql);
            foreach (kcrm_backup_read_rows($verified['files'][$info['entry']], count($columns), kcrm_transfer_limits($options['limits'] ?? [])) as $row) {
                foreach ($row as $i => $value) {
                    $stmt->bindValue($i + 1, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                }
                $stmt->execute();
            }
        }
        foreach ($verified['files'] as $name => $source) {
            if (!str_starts_with($name, 'storage/')) {
                continue;
            }
            $relative = substr($name, 8);
            $parts = explode('/', $relative);
            $filename = array_pop($parts);
            $dir = $targetStorage;
            foreach ($parts as $part) {
                $dir .= DIRECTORY_SEPARATOR . $part;
                if (is_link($dir)) {
                    kcrm_transfer_fail('Обнаружена ссылка в целевом хранилище.');
                }
                if (!is_dir($dir)) {
                    if (!mkdir($dir, 0700)) {
                        kcrm_transfer_fail('Не удалось создать каталог документа.');
                    }
                    $createdDirs[] = $dir;
                }
            }
            $path = $dir . DIRECTORY_SEPARATOR . $filename;
            $out = @fopen($path, 'xb');
            if ($out === false) {
                kcrm_transfer_fail('Документ уже существует или недоступен для записи.');
            }
            $createdFiles[] = $path;
            $input = fopen($source, 'rb');
            try {
                if ($input === false || stream_copy_to_stream($input, $out) !== filesize($source)) {
                    kcrm_transfer_fail('Не удалось перенести документ.');
                }
            } finally {
                if (is_resource($input)) {
                    fclose($input);
                }
                fclose($out);
            }
            @chmod($path, 0600);
            if (!hash_equals($manifest['entries'][$name]['sha256'], hash_file('sha256', $path))) {
                kcrm_transfer_fail('Ошибка контрольной суммы после записи документа.');
            }
        }
        $commitAttempted = true;
        $target->commit();
        $ownTransaction = false;
        $committed = true;
        return ['database' => $expectedDatabase, 'tables' => count($order), 'files' => count(array_filter(array_keys($verified['files']), static fn(string $n): bool => str_starts_with($n, 'storage/'))), 'schema_version' => $manifest['schema_version']];
    } finally {
        try {
            if ($ownTransaction && $target->inTransaction()) {
                $target->rollBack();
            }
        } catch (Throwable) {
            // Preserve the operation's original exception. Disconnection rolls
            // back an uncommitted InnoDB transaction; cleanup must still run.
        } finally {
            // A lost connection during COMMIT has an uncertain outcome. Retain
            // the new documents and lock for manual target inspection in that case.
            if (!$committed && !$commitAttempted) {
                foreach (array_reverse($createdFiles) as $file) {
                    @unlink($file);
                }
                foreach (array_reverse($createdDirs) as $dir) {
                    @rmdir($dir);
                }
            } elseif ($committed) {
                @unlink($lockPath);
            }
            kcrm_transfer_remove_temp($verified['stage']);
            if ($databaseLock !== null) {
                try {
                    $release = $target->prepare('SELECT RELEASE_LOCK(?)');
                    $release->execute([$databaseLock]);
                } catch (Throwable) {
                    // A disconnected server already releases connection locks.
                }
            }
        }
    }
}

function kcrm_transfer_load_config(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        kcrm_transfer_fail('Укажите существующий приватный конфигурационный файл.');
    }
    $config = (static fn(string $p) => require $p)($path);
    if (!is_array($config)) {
        kcrm_transfer_fail('Конфигурация должна возвращать массив PHP.');
    }
    return $config;
}

function kcrm_transfer_connect(array $config): PDO
{
    $settings = $config['db'] ?? $config['database'] ?? null;
    if (!is_array($settings)) {
        kcrm_transfer_fail('В конфигурации отсутствует массив db.');
    }
    $dsn = $settings['dsn'] ?? null;
    if ($dsn === null) {
        $host = (string)($settings['host'] ?? '');
        $name = (string)($settings['name'] ?? $settings['database'] ?? '');
        $port = (int)($settings['port'] ?? 3306);
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/D', $host) || !preg_match('/^[a-zA-Z0-9_]+$/D', $name) || $port < 1 || $port > 65535) {
            kcrm_transfer_fail('Недопустимые параметры базы данных.');
        }
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
    }
    if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) {
        kcrm_transfer_fail('Рабочий конфиг должен использовать PDO MySQL.');
    }
    $db = new PDO($dsn, (string)($settings['user'] ?? $settings['username'] ?? ''), (string)($settings['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => true,
    ]);
    $db->exec("SET time_zone = '+00:00'");
    return $db;
}

function kcrm_transfer_config_storage(array $config): string
{
    $storage = $config['storage_path'] ?? $config['storage_dir'] ?? $config['storage']['path'] ?? null;
    if (!is_string($storage) || $storage === '') {
        kcrm_transfer_fail('В конфиге не указан абсолютный storage_path.');
    }
    return $storage;
}
