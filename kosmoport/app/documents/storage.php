<?php
declare(strict_types=1);

require_once __DIR__ . '/validation.php';

function crm_documents_options(): array
{
    $options = crm_config('documents');
    return is_array($options) ? $options : [];
}

function crm_documents_max_bytes(): int
{
    $value = crm_documents_options()['max_upload_bytes'] ?? (10 * 1024 * 1024);
    if (!is_int($value) || $value < 1 || $value > 20 * 1024 * 1024) {
        crm_documents_error('storage_configuration', 'Проверьте настройку размера загружаемых документов.', 503);
    }
    return $value;
}

function crm_documents_within(string $path, string $root): bool
{
    $path = str_replace('\\', '/', $path);
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if (PHP_OS_FAMILY === 'Windows') {
        $path = strtolower($path);
        $root = strtolower($root);
    }
    return $path === $root || str_starts_with($path, $root . '/');
}

function crm_documents_directory(string $path): void
{
    if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path))) {
        crm_documents_error('storage_unavailable', 'Защищённое хранилище документов недоступно.', 503);
    }
}

function crm_documents_guard_file(string $path, string $contents): void
{
    if (is_link($path)) {
        crm_documents_error('storage_configuration', 'Проверьте защиту хранилища документов.', 503);
    }
    if (is_file($path)) {
        if (@file_get_contents($path) !== $contents) {
            crm_documents_error('storage_configuration', 'Проверьте защиту хранилища документов.', 503);
        }
        return;
    }
    $handle = @fopen($path, 'xb');
    if ($handle === false) {
        // A parallel request may have completed the same guard first.
        if (@file_get_contents($path) === $contents) {
            return;
        }
        crm_documents_error('storage_unavailable', 'Не удалось защитить хранилище документов.', 503);
    }
    try {
        if (fwrite($handle, $contents) !== strlen($contents)) {
            crm_documents_error('storage_unavailable', 'Не удалось защитить хранилище документов.', 503);
        }
        @chmod($path, 0600);
    } finally {
        fclose($handle);
    }
}

function crm_documents_storage_root(): string
{
    $base = crm_storage_path('');
    crm_documents_directory($base);
    $root = crm_storage_path('documents');
    crm_documents_directory($root);
    $canonicalBase = realpath($base);
    $canonicalRoot = realpath($root);
    if ($canonicalBase === false || $canonicalRoot === false || !crm_documents_within($canonicalRoot, $canonicalBase) || $canonicalRoot === $canonicalBase) {
        crm_documents_error('storage_configuration', 'Некорректное расположение хранилища документов.', 503);
    }
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $webroot = $documentRoot !== '' ? realpath($documentRoot) : realpath(__DIR__ . '/../../public');
    if ($webroot === false && PHP_SAPI !== 'cli') {
        crm_documents_error('storage_configuration', 'Не удалось проверить закрытость хранилища документов.', 503);
    }
    // The opt-in is a deployment assertion, not automatic proof. The hosting owner
    // must test HTTP denial on the real host before enabling the shared-host fallback.
    $options = crm_documents_options();
    $verifiedCorePolicy = crm_config('storage.allow_webroot_storage') === true && crm_config('storage.http_denial_verified') === true;
    // Core's explicitly verified shared-host policy is sufficient when there is
    // no document-specific override. An explicit false remains a stricter opt-out.
    $allowWebroot = array_key_exists('allow_webroot_storage', $options) ? $options['allow_webroot_storage'] === true : $verifiedCorePolicy;
    if (is_string($webroot) && crm_documents_within($canonicalRoot, $webroot) && !$allowWebroot) {
        crm_documents_error('storage_configuration', 'Документы должны храниться вне публичной папки сайта.', 503);
    }
    crm_documents_guard_file($canonicalRoot . '/.htaccess', "Require all denied\nOptions -Indexes\n");
    crm_documents_guard_file($canonicalRoot . '/index.php', "<?php\nhttp_response_code(404);\nexit;\n");
    return $canonicalRoot;
}

function crm_documents_object_path(string $root, string $key, bool $createDirectories = false): string
{
    if (!preg_match('~\Aobjects/([a-f0-9]{2})/([a-f0-9]{32})/([a-f0-9]{32})\.blob\z~D', $key, $parts) || !str_starts_with($parts[2], $parts[1])) {
        crm_documents_error('storage_integrity', 'Нарушена целостность записи документа.', 409);
    }
    $segments = ['objects', $parts[1], $parts[2]];
    $directory = $root;
    foreach ($segments as $segment) {
        $directory .= '/' . $segment;
        if (is_link($directory)) {
            crm_documents_error('storage_integrity', 'Небезопасное расположение файла документа.', 409);
        }
        if ($createDirectories) {
            crm_documents_directory($directory);
        }
        $real = realpath($directory);
        if ($real === false || !crm_documents_within($real, $root)) {
            crm_documents_error('file_unavailable', 'Файл документа временно недоступен.', 503);
        }
    }
    $path = $directory . '/' . $parts[3] . '.blob';
    if (is_link($path)) {
        crm_documents_error('storage_integrity', 'Небезопасная ссылка на файл документа.', 409);
    }
    return $path;
}

function crm_documents_store(string $source, string $documentId, string $versionId, array $metadata): array
{
    $root = crm_documents_storage_root();
    $key = 'objects/' . substr($documentId, 0, 2) . '/' . $documentId . '/' . $versionId . '.blob';
    $path = crm_documents_object_path($root, $key, true);
    // Exclusive creation prevents overwriting any existing immutable version.
    $target = @fopen($path, 'xb');
    if ($target === false) {
        crm_documents_error('storage_unavailable', 'Не удалось сохранить новую версию документа.', 503);
    }
    $input = @fopen($source, 'rb');
    $complete = false;
    try {
        if ($input === false) {
            crm_documents_error('invalid_upload', 'Не удалось прочитать загруженный файл.');
        }
        $copied = stream_copy_to_stream($input, $target, $metadata['size_bytes'] + 1);
        if ($copied !== $metadata['size_bytes'] || !fflush($target)) {
            crm_documents_error('storage_unavailable', 'Не удалось полностью сохранить документ.', 503);
        }
        @chmod($path, 0600);
        if (!hash_equals($metadata['sha256'], (string)hash_file('sha256', $path))) {
            crm_documents_error('storage_integrity', 'Файл изменился во время загрузки.', 409);
        }
        $complete = true;
        return ['key' => $key, 'path' => $path];
    } finally {
        if (is_resource($input)) {
            fclose($input);
        }
        fclose($target);
        if (!$complete) {
            @unlink($path);
        }
    }
}
