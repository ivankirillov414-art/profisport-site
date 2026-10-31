<?php
declare(strict_types=1);

const KCRM_ROLES = ['owner', 'admin', 'manager', 'cashier', 'editor', 'document_manager', 'reader'];
// Random, discarded plaintext; no seeded account or usable installation password.
const KCRM_DUMMY_HASH = '$2y$12$gWGAtl6Szf7AmMjTKXC0cuX5F4h42Iwq7F/jnPLbDUpJR78qYymxG';

function crm_password(mixed $value): string
{
    if (!is_string($value) || strlen($value) > 72 || preg_match('//u', $value) !== 1 || preg_match_all('/./us', $value) < 12 || str_contains($value, "\0")) crm_fail('PASSWORD_INVALID', 'Пароль: не менее 12 символов и не более 72 байт UTF-8.');
    return $value;
}

function crm_user_view(array $row): array
{ return array_intersect_key($row, array_flip(['id', 'name', 'email', 'role'])); }

function crm_rate_bucket(string $purpose, string $value): string
{ return hash_hmac('sha256', $purpose . ':' . $value, crm_config('app_key')); }

function crm_rate_consume(string $bucket, int $limit): void
{
    $blocked = crm_transaction(function () use ($bucket, $limit): bool {
        $sql = crm_db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INSERT IGNORE INTO kcrm_auth_rate_limits (bucket,window_started_at,attempts,blocked_until,updated_at) VALUES (?,?,0,NULL,?)'
            : 'INSERT INTO kcrm_auth_rate_limits (bucket,window_started_at,attempts,blocked_until,updated_at) VALUES (?,?,0,NULL,?) ON CONFLICT(bucket) DO NOTHING';
        crm_query($sql, [$bucket, crm_now(), crm_now()]);
        $row = crm_query('SELECT * FROM kcrm_auth_rate_limits WHERE bucket=?' . crm_lock_suffix(), [$bucket])->fetch();
        $now = time();
        if ($row['blocked_until'] && strtotime($row['blocked_until'] . ' UTC') > $now) return true;
        $newWindow = strtotime($row['window_started_at'] . ' UTC') <= $now - 900;
        $attempts = ($newWindow ? 0 : (int)$row['attempts']) + 1;
        $until = $attempts > $limit ? gmdate('Y-m-d H:i:s', $now + 900) : null;
        crm_query('UPDATE kcrm_auth_rate_limits SET window_started_at=?,attempts=?,blocked_until=?,updated_at=? WHERE bucket=?', [$newWindow ? crm_now() : $row['window_started_at'], $attempts, $until, crm_now(), $bucket]);
        return $until !== null;
    });
    if ($blocked) { header('Retry-After: 900'); crm_fail('RATE_LIMITED', 'Слишком много попыток. Повторите вход через 15 минут.', 429); }
}

function crm_auth_login(array $input): array
{
    if (!crm_is_installed()) crm_fail('NOT_INSTALLED', 'Владелец CRM ещё не создан. Обратитесь к администратору.', 503);
    $email = crm_email($input['email'] ?? '', true);
    $password = $input['password'] ?? '';
    if (!is_string($password) || strlen($password) > 72 || str_contains($password, "\0") || preg_match('//u', $password) !== 1) crm_fail('LOGIN_FAILED', 'Неверная почта или пароль.', 401);
    crm_rate_consume(crm_rate_bucket('login-ip', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')), 30);
    $accountBucket = crm_rate_bucket('login-account', $email);
    crm_rate_consume($accountBucket, 8);
    $row = crm_query('SELECT * FROM kcrm_users WHERE email=?', [$email])->fetch();
    $valid = password_verify($password, $row['password_hash'] ?? KCRM_DUMMY_HASH);
    if (!$valid || !$row || !(bool)$row['active'] || !in_array($row['role'], KCRM_ROLES, true)) {
        crm_audit('auth.login_failed', 'auth', null, ['account_fingerprint' => $accountBucket]);
        crm_fail('LOGIN_FAILED', 'Неверная почта или пароль.', 401);
    }
    crm_session(); session_regenerate_id(true);
    $_SESSION = ['user_id' => $row['id'], 'session_version' => (int)$row['session_version'], 'authenticated_at' => time(), 'last_seen' => time(), 'csrf' => bin2hex(random_bytes(32))];
    crm_query('DELETE FROM kcrm_auth_rate_limits WHERE bucket=?', [$accountBucket]);
    if (password_needs_rehash($row['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) crm_query('UPDATE kcrm_users SET password_hash=? WHERE id=? AND password_hash=?', [password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $row['id'], $row['password_hash']]);
    $user = crm_current_user();
    if (!$user) crm_fail('LOGIN_FAILED', 'Неверная почта или пароль.', 401);
    crm_audit('auth.login', 'user', $row['id']);
    return ['user' => $user, 'csrf' => crm_csrf_token()];
}

function crm_auth_logout(): array
{
    if ($user = crm_current_user()) crm_audit('auth.logout', 'user', $user['id']);
    crm_session(); $_SESSION = []; session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32)); $_SESSION['last_seen'] = time();
    return ['user' => null, 'csrf' => $_SESSION['csrf']];
}

function crm_assert_setup_key(mixed $token): void
{
    $expected = crm_config('setup_key_hash');
    if (!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/Di', $expected)) crm_fail('SETUP_DISABLED', 'Первоначальная установка отключена. Обратитесь к администратору.', 403);
    if (!is_string($token) || strlen($token) < 32 || strlen($token) > 256 || !hash_equals(strtolower($expected), hash('sha256', $token))) crm_fail('SETUP_KEY_INVALID', 'Неверный ключ первоначальной установки.', 403);
}

function crm_is_installed(): bool
{
    try { return (bool)crm_query('SELECT completed_at FROM kcrm_setup WHERE id=1')->fetchColumn(); }
    catch (PDOException $e) {
        if (in_array((string)$e->getCode(), ['42S02', 'HY000'], true) && (str_contains($e->getMessage(), 'no such table') || str_contains($e->getMessage(), "doesn't exist"))) return false;
        throw $e;
    }
}

function crm_install_owner(array $input): array
{
    crm_assert_setup_key($input['setup_key'] ?? null);
    if (crm_is_installed()) crm_fail('ALREADY_INSTALLED', 'Владелец уже создан. Повторная установка запрещена.', 409);
    $name = crm_text($input['name'] ?? '', 'Имя владельца', 200, true);
    $email = crm_email($input['email'] ?? '', true);
    $hash = password_hash(crm_password($input['password'] ?? ''), PASSWORD_BCRYPT, ['cost' => 12]);
    crm_run_migrations();
    return crm_transaction(function () use ($name, $email, $hash): array {
        $insert = crm_db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE INTO kcrm_setup (id,owner_id,completed_at) VALUES (1,NULL,NULL)' : 'INSERT INTO kcrm_setup (id,owner_id,completed_at) VALUES (1,NULL,NULL) ON CONFLICT(id) DO NOTHING';
        crm_query($insert);
        $state = crm_query('SELECT * FROM kcrm_setup WHERE id=1' . crm_lock_suffix())->fetch();
        if (!$state || $state['completed_at'] !== null || (int)crm_query('SELECT COUNT(*) FROM kcrm_users')->fetchColumn() !== 0) crm_fail('ALREADY_INSTALLED', 'Владелец уже создан. Повторная установка запрещена.', 409);
        $id = crm_uuid(); $now = crm_now();
        crm_query('INSERT INTO kcrm_users (id,name,email,password_hash,role,active,session_version,revision,created_at,updated_at) VALUES (?,?,?,?,?,1,1,1,?,?)', [$id, $name, $email, $hash, 'owner', $now, $now]);
        crm_query('UPDATE kcrm_setup SET owner_id=?,completed_at=? WHERE id=1 AND completed_at IS NULL', [$id, $now]);
        crm_audit('setup.owner_created', 'user', $id, ['role' => 'owner']);
        return ['id' => $id, 'name' => $name, 'email' => $email, 'role' => 'owner'];
    });
}
