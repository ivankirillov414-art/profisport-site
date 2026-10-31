<?php
declare(strict_types=1);

function crm_is_https(): bool
{ return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443; }

function crm_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $config = crm_config('session');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{2,40}$/D', (string)$config['name'])) crm_fail('NOT_CONFIGURED', 'Проверьте настройки сессии.', 503);
        $secure = $config['secure'] === 'auto' ? crm_is_https() : $config['secure'] === true;
        if (crm_is_https()) $secure = true;
        $sameSite = in_array($config['same_site'], ['Lax', 'Strict'], true) ? $config['same_site'] : 'Lax';
        $dir = crm_storage_path('sessions');
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) crm_fail('SESSION_UNAVAILABLE', 'Не удалось открыть защищённую сессию.', 503);
        ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1'); ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string)max(300, (int)$config['absolute_seconds']));
        session_name($config['name']); session_save_path($dir);
        session_set_cookie_params(['lifetime' => 0, 'path' => (crm_config('base_path') ?: '') . '/', 'secure' => $secure, 'httponly' => true, 'samesite' => $sameSite]);
        if (!session_start()) crm_fail('SESSION_UNAVAILABLE', 'Не удалось открыть защищённую сессию.', 503);
    }
    $now = time();
    if (isset($_SESSION['user_id']) && ($now - (int)($_SESSION['last_seen'] ?? 0) > max(60, (int)crm_config('session.idle_seconds')) || $now - (int)($_SESSION['authenticated_at'] ?? 0) > max(300, (int)crm_config('session.absolute_seconds')))) {
        $_SESSION = []; session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = $now;
    if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function crm_csrf_token(): string { crm_session(); return $_SESSION['csrf']; }

function crm_check_csrf(?string $provided = null): void
{
    crm_session();
    $provided ??= $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($provided) || strlen($provided) !== 64 || !hash_equals($_SESSION['csrf'], $provided)) crm_fail('CSRF_INVALID', 'Сессия обновилась. Обновите страницу и повторите действие.', 403);
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        $expected = (crm_is_https() ? 'https://' : 'http://') . strtolower($_SERVER['HTTP_HOST'] ?? '');
        if (!hash_equals($expected, strtolower(rtrim((string)$_SERVER['HTTP_ORIGIN'], '/')))) crm_fail('ORIGIN_FORBIDDEN', 'Запрос с другого сайта запрещён.', 403);
    }
}

function crm_current_user(): ?array
{
    crm_session();
    if (!isset($_SESSION['user_id'])) return null;
    $row = crm_query('SELECT id,name,email,role,active,session_version FROM kcrm_users WHERE id=?', [$_SESSION['user_id']])->fetch();
    if (!$row || !(bool)$row['active'] || (int)$row['session_version'] !== (int)($_SESSION['session_version'] ?? 0)) {
        $_SESSION = []; session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(32)); return null;
    }
    $GLOBALS['kcrm_authenticated_actor'] = $row['id'];
    return array_intersect_key($row, array_flip(['id', 'name', 'email', 'role']));
}

function crm_require_roles(array $roles): array
{
    $user = crm_current_user();
    if (!$user) crm_fail('AUTH_REQUIRED', 'Войдите в CRM.', 401);
    if (!in_array($user['role'], ['owner', 'admin'], true) && !in_array($user['role'], $roles, true)) crm_fail('FORBIDDEN', 'Недостаточно прав для этого действия.', 403);
    return $user;
}
