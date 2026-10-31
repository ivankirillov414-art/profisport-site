<?php
declare(strict_types=1);
ini_set('display_errors', '0'); ini_set('log_errors', '1');
require_once dirname(__DIR__) . '/app/bootstrap.php';
$error = ''; $done = false; $status = 200; $csrf = '';
try {
    if (crm_config('environment') === 'production' && !crm_is_https()) crm_fail('HTTPS_REQUIRED', 'Откройте установку через HTTPS.', 403);
    header('Cache-Control: no-store'); header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer');
    $csrf = crm_csrf_token();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') {
        crm_validate_body_size();
        $providedCsrf = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        crm_check_csrf(is_string($providedCsrf) ? $providedCsrf : '');
        $input = str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') ? crm_input() : $_POST;
        $user = crm_install_owner($input); $done = true;
        if (str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) crm_json(['ok' => true, 'data' => ['user' => $user, 'installed' => true]], 201);
    } elseif ($method !== 'GET') crm_fail('METHOD_NOT_ALLOWED', 'Метод запроса не поддерживается.', 405);
} catch (Throwable $exception) {
    if (str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) crm_error_response($exception);
    $status = $exception instanceof CrmHttpException ? $exception->status : 500;
    $error = $exception instanceof CrmHttpException ? $exception->getMessage() : 'Не удалось завершить установку. Проверьте приватную конфигурацию и журнал сервера.';
    if (!$exception instanceof CrmHttpException) error_log('Kosmoport setup [' . get_class($exception) . ']');
}
http_response_code($status);
header('Content-Type: text/html; charset=utf-8');
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Первоначальная настройка CRM</title>
<style>body{font:17px/1.5 system-ui;background:#f4f6f8;color:#172435;margin:0;padding:24px}main{max-width:560px;margin:32px auto;background:white;padding:28px;border-radius:16px}label{display:block;margin:18px 0}input,button{box-sizing:border-box;font:inherit;padding:12px;width:100%;border:1px solid #9faebd;border-radius:8px}button{background:#174e82;color:white;cursor:pointer}.error{color:#94222a}a{color:#174e82}</style>
<main><h1>Первоначальная настройка CRM</h1>
<?php if ($done): ?><p>Владелец создан. Повторная установка закрыта.</p><p>Удалите ключ установки из приватного конфига и <a href="<?= $escape((crm_config('base_path') ?: '') . '/crm/') ?>">войдите в CRM</a>.</p>
<?php else: ?><p>Эта форма не регистрирует посетителей. Нужен приватный одноразовый ключ настройки, выданный администратором.</p>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<?php if ($csrf !== ''): ?><form method="post" autocomplete="off"><input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<label>Одноразовый ключ настройки<input name="setup_key" type="password" required autocomplete="off"></label>
<label>Имя владельца<input name="name" maxlength="200" required autocomplete="name"></label>
<label>Электронная почта<input name="email" type="email" maxlength="254" required autocomplete="username"></label>
<label>Новый пароль<input name="password" type="password" minlength="12" required autocomplete="new-password"></label>
<button type="submit">Создать владельца</button></form><?php endif; ?>
<?php endif; ?></main></html>
