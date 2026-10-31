<?php
declare(strict_types=1);

require_once __DIR__ . '/storage.php';

function crm_documents_require(string $operation): array
{
    $roles = ['owner', 'admin', 'document_manager', 'manager'];
    if ($operation !== 'upload') {
        $roles[] = 'reader';
    }
    $user = crm_current_user();
    if ($user === null || !in_array($user['role'] ?? '', $roles, true)) {
        try {
            crm_audit('documents.access_denied', 'document', null, ['operation' => $operation, 'authenticated' => $user !== null]);
        } catch (Throwable) {
            // A logging failure must never grant access to an unauthorized caller.
        }
        crm_require_roles($roles);
        crm_documents_error($user === null ? 'unauthenticated' : 'forbidden', $user === null ? 'Войдите в систему.' : 'Нет доступа к документам.', $user === null ? 401 : 403);
    }
    return crm_require_roles($roles);
}

function crm_documents_iso(string $timestamp): string
{
    return (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

function crm_documents_select(): string
{
    return 'SELECT d.*, v.original_name, v.extension, v.mime, v.size_bytes, v.sha256, v.uploaded_by_name AS author FROM kcrm_documents d JOIN kcrm_document_versions v ON v.document_id=d.id AND v.version_no=d.current_version';
}

function crm_documents_item(array $row): array
{
    return [
        'id' => $row['id'], 'title' => $row['title'], 'category' => $row['category'],
        'current_version' => (int)$row['current_version'], 'version_count' => (int)$row['current_version'],
        'filename' => $row['original_name'], 'extension' => $row['extension'], 'mime' => $row['mime'],
        'size_bytes' => (int)$row['size_bytes'], 'sha256' => $row['sha256'], 'author' => $row['author'], 'author_name' => $row['author'],
        'created_by' => $row['created_by'], 'updated_by' => $row['updated_by'],
        'created_at' => crm_documents_iso($row['created_at']), 'updated_at' => crm_documents_iso($row['updated_at']),
    ];
}

function crm_documents_version_item(array $row): array
{
    return [
        'id' => $row['id'], 'document_id' => $row['document_id'], 'version' => (int)$row['version_no'],
        'title' => $row['title'], 'category' => $row['category'], 'filename' => $row['original_name'],
        'extension' => $row['extension'], 'mime' => $row['mime'], 'size_bytes' => (int)$row['size_bytes'],
        'sha256' => $row['sha256'], 'author' => $row['uploaded_by_name'], 'author_name' => $row['uploaded_by_name'], 'uploaded_by' => $row['uploaded_by'],
        'created_at' => crm_documents_iso($row['created_at']),
    ];
}

function crm_documents_find(PDO $db, string $id): array
{
    $query = $db->prepare(crm_documents_select() . ' WHERE d.id=:id');
    $query->execute(['id' => $id]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        crm_documents_error('document_not_found', 'Документ не найден.', 404);
    }
    return crm_documents_item($row);
}

function crm_documents_list(array $query): array
{
    crm_documents_require('list');
    $search = crm_documents_text($query['q'] ?? '', 200, 'Поиск', true);
    $category = crm_documents_text($query['category'] ?? '', 80, 'Категория', true);
    $limit = crm_documents_number($query['limit'] ?? 25, 1, 100, 'Размер страницы');
    $offset = crm_documents_number($query['offset'] ?? 0, 0, 1000000, 'Смещение страницы');
    $where = [];
    $parameters = [];
    if ($search !== '') {
        $like = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $where[] = "(d.title LIKE :q1 ESCAPE '!' OR v.original_name LIKE :q2 ESCAPE '!' OR d.category LIKE :q3 ESCAPE '!' OR v.uploaded_by_name LIKE :q4 ESCAPE '!')";
        $parameters += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
    }
    if ($category !== '') {
        $where[] = 'd.category=:category';
        $parameters['category'] = $category;
    }
    $suffix = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $db = crm_db();
    $sql = crm_documents_select() . $suffix . ' ORDER BY d.updated_at DESC,d.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    $items = array_map('crm_documents_item', $statement->fetchAll(PDO::FETCH_ASSOC));
    $count = $db->prepare('SELECT COUNT(*) FROM kcrm_documents d JOIN kcrm_document_versions v ON v.document_id=d.id AND v.version_no=d.current_version' . $suffix);
    $count->execute($parameters);
    $categories = $db->query('SELECT category AS name,COUNT(*) AS count FROM kcrm_documents GROUP BY category ORDER BY category')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($categories as &$item) {
        $item['count'] = (int)$item['count'];
    }
    return ['items' => $items, 'total' => (int)$count->fetchColumn(), 'limit' => $limit, 'offset' => $offset, 'categories' => $categories];
}

function crm_documents_versions(array $query): array
{
    crm_documents_require('history');
    $id = crm_documents_id($query['id'] ?? null);
    $db = crm_db();
    $document = crm_documents_find($db, $id);
    $statement = $db->prepare('SELECT * FROM kcrm_document_versions WHERE document_id=:id ORDER BY version_no DESC');
    $statement->execute(['id' => $id]);
    return ['document' => $document, 'items' => array_map('crm_documents_version_item', $statement->fetchAll(PDO::FETCH_ASSOC))];
}

function crm_documents_upload(array $form, array $files): array
{
    $user = crm_documents_require('upload');
    $maxBytes = crm_documents_max_bytes();
    $file = $files['file'] ?? null;
    if (!is_array($file) || is_array($file['error'] ?? null) || !is_int($file['error'] ?? null)) {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) {
            crm_documents_error('file_too_large', 'Файл превышает допустимый размер.', 413);
        }
        crm_documents_error('file_required', 'Выберите файл документа.');
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        crm_documents_error('file_too_large', 'Файл превышает допустимый размер.', 413);
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        crm_documents_error('invalid_upload', 'Загрузка файла не завершена. Выберите файл повторно.');
    }
    // PHP normalizes filename to a basename. Inspect the original full_path as
    // well, so directory-upload and traversal names are explicitly refused.
    if (isset($file['full_path']) && (!is_string($file['full_path']) || $file['full_path'] !== ($file['name'] ?? null))) {
        crm_documents_error('unsafe_filename', 'Загружайте отдельный файл без пути к папке.');
    }
    $metadata = crm_documents_validate_file($file['tmp_name'], $file['name'] ?? null, $maxBytes);
    $documentId = isset($form['document_id']) && $form['document_id'] !== '' ? crm_documents_id($form['document_id']) : null;
    $expected = isset($form['expected_version']) ? crm_documents_number($form['expected_version'], 1, 2147483647, 'Ожидаемая версия') : null;
    if ($documentId === null && $expected !== null) {
        crm_documents_error('invalid_version', 'Ожидаемая версия допустима только для существующего документа.');
    }
    $title = array_key_exists('title', $form) ? crm_documents_text($form['title'], 240, 'Название') : null;
    $category = array_key_exists('category', $form) ? crm_documents_text($form['category'], 80, 'Категория') : null;
    if ($documentId === null && $title === null) {
        crm_documents_error('title_required', 'Укажите название документа.');
    }
    $actorId = crm_documents_text((string)($user['id'] ?? ''), 64, 'Сотрудник');
    $actorName = crm_documents_text($user['name'] ?? '', 200, 'Имя сотрудника');
    $db = crm_db();
    $created = $documentId === null;
    $documentId ??= bin2hex(random_bytes(16));
    $versionId = bin2hex(random_bytes(16));
    $now = gmdate('Y-m-d H:i:s');
    $stored = null;
    $db->beginTransaction();
    try {
        $version = 1;
        if (!$created) {
            $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $statement = $db->prepare('SELECT * FROM kcrm_documents WHERE id=:id' . $lock);
            $statement->execute(['id' => $documentId]);
            $previous = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($previous)) {
                crm_documents_error('document_not_found', 'Документ не найден.', 404);
            }
            if ($expected !== null && $expected !== (int)$previous['current_version']) {
                crm_documents_error('version_conflict', 'Документ уже обновлён другим сотрудником. Обновите историю версий.', 409);
            }
            $current = crm_documents_find($db, $documentId);
            if (hash_equals($current['sha256'], $metadata['sha256'])) {
                crm_documents_error('duplicate_version', 'Этот файл уже является текущей версией документа.', 409);
            }
            $version = (int)$previous['current_version'] + 1;
            if ($version > 2147483647) {
                crm_documents_error('version_limit', 'Достигнут предел числа версий документа.', 409);
            }
            $title ??= $previous['title'];
            $category ??= $previous['category'];
        } else {
            $category ??= 'Общие';
            $statement = $db->prepare('INSERT INTO kcrm_documents (id,title,category,current_version,created_by,created_by_name,updated_by,updated_by_name,created_at,updated_at) VALUES (:id,:title,:category,1,:created_by,:created_by_name,:updated_by,:updated_by_name,:created_at,:updated_at)');
            $statement->execute(['id' => $documentId, 'title' => $title, 'category' => $category, 'created_by' => $actorId, 'created_by_name' => $actorName, 'updated_by' => $actorId, 'updated_by_name' => $actorName, 'created_at' => $now, 'updated_at' => $now]);
        }
        $stored = crm_documents_store($file['tmp_name'], $documentId, $versionId, $metadata);
        $statement = $db->prepare('INSERT INTO kcrm_document_versions (id,document_id,version_no,title,category,original_name,extension,mime,size_bytes,sha256,storage_key,uploaded_by,uploaded_by_name,created_at) VALUES (:id,:document_id,:version_no,:title,:category,:original_name,:extension,:mime,:size_bytes,:sha256,:storage_key,:uploaded_by,:uploaded_by_name,:created_at)');
        $statement->execute(['id' => $versionId, 'document_id' => $documentId, 'version_no' => $version, 'title' => $title, 'category' => $category, 'original_name' => $metadata['filename'], 'extension' => $metadata['extension'], 'mime' => $metadata['mime'], 'size_bytes' => $metadata['size_bytes'], 'sha256' => $metadata['sha256'], 'storage_key' => $stored['key'], 'uploaded_by' => $actorId, 'uploaded_by_name' => $actorName, 'created_at' => $now]);
        if (!$created) {
            $statement = $db->prepare('UPDATE kcrm_documents SET title=:title,category=:category,current_version=:version,updated_by=:actor,updated_by_name=:author,updated_at=:updated WHERE id=:id');
            $statement->execute(['title' => $title, 'category' => $category, 'version' => $version, 'actor' => $actorId, 'author' => $actorName, 'updated' => $now, 'id' => $documentId]);
        }
        crm_audit('documents.upload', 'document', $documentId, ['version' => $version, 'version_id' => $versionId, 'sha256' => $metadata['sha256'], 'size_bytes' => $metadata['size_bytes'], 'extension' => $metadata['extension'], 'new_document' => $created]);
        $document = crm_documents_find($db, $documentId);
        $statement = $db->prepare('SELECT * FROM kcrm_document_versions WHERE id=:id');
        $statement->execute(['id' => $versionId]);
        $versionItem = crm_documents_version_item($statement->fetch(PDO::FETCH_ASSOC));
        $db->commit();
        return ['document' => $document, 'version' => $versionItem];
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if (is_array($stored)) {
            @unlink($stored['path']);
        }
        if ($error instanceof PDOException && (string)$error->getCode() === '23000') {
            crm_documents_error('version_conflict', 'Документ изменился во время загрузки. Обновите историю версий.', 409);
        }
        throw $error;
    }
}

function crm_documents_prepare_download(array $query): array
{
    crm_documents_require('download');
    $id = crm_documents_id($query['id'] ?? null);
    $version = isset($query['version']) ? crm_documents_number($query['version'], 1, 2147483647, 'Версия') : null;
    $db = crm_db();
    $sql = 'SELECT v.* FROM kcrm_document_versions v JOIN kcrm_documents d ON d.id=v.document_id WHERE d.id=:id AND v.version_no=' . ($version === null ? 'd.current_version' : ':version');
    $statement = $db->prepare($sql);
    $statement->execute($version === null ? ['id' => $id] : ['id' => $id, 'version' => $version]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        crm_documents_error('document_not_found', 'Документ или версия не найдены.', 404);
    }
    $root = crm_documents_storage_root();
    $path = crm_documents_object_path($root, $row['storage_key']);
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        crm_audit('documents.file_unavailable', 'document', $id, ['version' => (int)$row['version_no']]);
        crm_documents_error('file_unavailable', 'Файл документа временно недоступен.', 503);
    }
    try {
        $stat = fstat($handle);
        $hash = hash_init('sha256');
        $read = hash_update_stream($hash, $handle);
        if ($stat === false || $stat['size'] !== (int)$row['size_bytes'] || $read !== (int)$row['size_bytes'] || !hash_equals($row['sha256'], hash_final($hash)) || !rewind($handle)) {
            crm_audit('documents.integrity_failed', 'document', $id, ['version' => (int)$row['version_no']]);
            crm_documents_error('file_integrity', 'Файл не прошёл проверку целостности. Обратитесь к администратору.', 409);
        }
        $name = crm_documents_filename($row['original_name']);
        if ($name['mime'] !== $row['mime'] || $name['extension'] !== $row['extension']) {
            crm_documents_error('file_integrity', 'Тип сохранённого документа не прошёл проверку.', 409);
        }
        crm_audit('documents.download', 'document', $id, ['version' => (int)$row['version_no'], 'version_id' => $row['id'], 'sha256' => $row['sha256'], 'size_bytes' => (int)$row['size_bytes']]);
        return ['handle' => $handle, 'metadata' => crm_documents_version_item($row)];
    } catch (Throwable $error) {
        fclose($handle);
        throw $error;
    }
}

function crm_documents_download(array $query): never
{
    $download = crm_documents_prepare_download($query);
    $metadata = $download['metadata'];
    header('Content-Type: ' . $metadata['mime'] . (in_array($metadata['extension'], ['txt', 'csv'], true) ? '; charset=utf-8' : ''));
    header('Content-Disposition: attachment; filename="document-v' . $metadata['version'] . '.' . $metadata['extension'] . '"; filename*=UTF-8\'\'' . rawurlencode($metadata['filename']));
    header('Content-Length: ' . $metadata['size_bytes']);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: sandbox; default-src 'none'");
    header('X-Frame-Options: DENY');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    try {
        while (!feof($download['handle']) && !connection_aborted()) {
            $chunk = fread($download['handle'], 65536);
            if ($chunk === false) {
                break;
            }
            echo $chunk;
        }
    } finally {
        fclose($download['handle']);
    }
    exit;
}
