<?php
declare(strict_types=1);

function crm_config(?string $key = null): mixed
{
    static $config;
    if ($config === null) {
        $file = getenv('KCRM_CONFIG') ?: KCRM_ROOT . '/config/config.php';
        if (!is_file($file)) crm_fail('NOT_CONFIGURED', 'CRM ещё не настроена. Обратитесь к администратору.', 503);
        $loaded = require $file;
        if (!is_array($loaded)) crm_fail('NOT_CONFIGURED', 'Конфигурация CRM недоступна.', 503);
        $defaults = ['environment' => 'production', 'app_name' => 'Космопорт CRM', 'base_path' => '/kosmoport', 'timezone' => 'Asia/Yekaterinburg', 'storage_path' => KCRM_ROOT . '/storage', 'app_key' => '', 'setup_key_hash' => '', 'database' => ['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306, 'name' => '', 'username' => '', 'password' => '', 'charset' => 'utf8mb4'], 'session' => ['name' => 'KCRMSESSID', 'secure' => 'auto', 'same_site' => 'Lax', 'idle_seconds' => 1800, 'absolute_seconds' => 43200]];
        $candidate = array_replace_recursive($defaults, $loaded);
        $base = rtrim((string)$candidate['base_path'], '/');
        if ($base !== '' && !preg_match('~^/[A-Za-z0-9_/-]+$~D', $base) || str_contains($base, '..') || str_contains($base, '//')) crm_fail('NOT_CONFIGURED', 'Проверьте путь CRM в конфигурации.', 503);
        $candidate['base_path'] = $base;
        if (!is_string($candidate['app_key']) || strlen($candidate['app_key']) < 32) crm_fail('NOT_CONFIGURED', 'Не задан приватный ключ приложения.', 503);
        try { new DateTimeZone((string)$candidate['timezone']); } catch (Throwable) { crm_fail('NOT_CONFIGURED', 'Проверьте часовой пояс CRM.', 503); }
        $config = $candidate;
        date_default_timezone_set('UTC');
    }
    if ($key === null) return $config;
    $value = $config;
    foreach (explode('.', $key) as $part) { if (!is_array($value) || !array_key_exists($part, $value)) return null; $value = $value[$part]; }
    return $value;
}

function crm_storage_path(string $relative = ''): string
{
    $root = rtrim((string)crm_config('storage_path'), '/\\');
    if ($root === '' || !(str_starts_with($root, '/') || preg_match('~^[A-Za-z]:[/\\\\]~', $root))) crm_fail('NOT_CONFIGURED', 'Нужен абсолютный путь приватного хранилища.', 503);
    $relative = str_replace('\\', '/', $relative);
    if (str_contains($relative, "\0") || str_contains($relative, ':') || str_starts_with($relative, '/') || preg_match('~(^|/)\.{1,2}(/|$)~', $relative)) crm_fail('INVALID_PATH', 'Недопустимый путь к файлу');
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) crm_fail('STORAGE_UNAVAILABLE', 'Приватное хранилище недоступно.', 503);
    $canonical = realpath($root);
    if ($canonical === false) crm_fail('STORAGE_UNAVAILABLE', 'Приватное хранилище недоступно.', 503);
    $compare = static fn(string $path): string => PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $path)) : $path;
    $publicRoots = [realpath(KCRM_ROOT . '/public')];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) $publicRoots[] = realpath($_SERVER['DOCUMENT_ROOT']);
    foreach ($publicRoots as $public) if ($public !== false && ($compare($canonical) === $compare($public) || str_starts_with($compare($canonical), $compare($public) . '/'))) {
        $guard = $canonical . '/.htaccess';
        $explicitlyVerified = crm_config('storage.allow_webroot_storage') === true && crm_config('storage.http_denial_verified') === true;
        if (!$explicitlyVerified || !is_file($guard) || !preg_match('/Require\s+all\s+denied|Deny\s+from\s+all/i', (string)file_get_contents($guard))) crm_fail('UNSAFE_STORAGE', 'Хранилище должно быть вне публичного каталога. Для общего хостинга требуется проверенная блокировка HTTP-доступа.', 503);
    }
    $candidate = $canonical;
    foreach (explode('/', $relative) as $part) {
        if ($part === '') continue;
        $candidate .= DIRECTORY_SEPARATOR . $part;
        if (is_link($candidate)) crm_fail('INVALID_PATH', 'Ссылки в хранилище не допускаются');
        if (file_exists($candidate)) {
            $resolved = realpath($candidate);
            if ($resolved === false || !str_starts_with($compare($resolved) . '/', $compare($canonical) . '/')) crm_fail('INVALID_PATH', 'Недопустимый путь к файлу');
        }
    }
    return $candidate;
}
