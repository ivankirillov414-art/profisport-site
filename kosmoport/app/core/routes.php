<?php
declare(strict_types=1);

function crm_core_routes(): array
{
    return [
        'GET health' => static function (): array {
            try { $ready = crm_is_installed(); } catch (Throwable) { $ready = false; }
            return ['version' => KCRM_VERSION, 'ready' => $ready];
        },
        'GET auth/me' => static fn(): array => ['user' => crm_current_user(), 'csrf' => crm_csrf_token()],
        'POST auth/login' => static fn(): array => crm_auth_login(crm_input()),
        'POST auth/logout' => static fn(): array => crm_auth_logout(),
        'GET dashboard' => static fn(): array => crm_dashboard(),
        'GET partners' => static fn(): array => crm_partners_list(),
        'POST partners' => static fn(): array => crm_partner_save(crm_input()),
        'GET contacts' => static fn(): array => crm_contacts_list(),
        'POST contacts' => static fn(): array => crm_contact_save(crm_input()),
        'GET tasks' => static fn(): array => crm_tasks_list(),
        'POST tasks' => static fn(): array => crm_task_save(crm_input()),
        'GET users' => static fn(): array => crm_users_list(),
        'POST users' => static fn(): array => crm_user_save(crm_input()),
        'GET audit' => static fn(): array => crm_audit_list(),
    ];
}

function crm_dispatch(): never
{
    header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin'); header('X-Frame-Options: DENY');
    $route = $_GET['r'] ?? '';
    if (!is_string($route) || !preg_match('~^[a-z0-9][a-z0-9_/-]{0,100}$~D', $route) || str_contains($route, '//')) crm_fail('ROUTE_NOT_FOUND', 'Раздел API не найден.', 404);
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['GET','POST','PATCH','DELETE'], true)) crm_fail('METHOD_NOT_ALLOWED', 'Метод запроса не поддерживается.', 405);
    if ($route !== 'health' && crm_config('environment') === 'production' && !crm_is_https()) crm_fail('HTTPS_REQUIRED', 'Для работы CRM требуется защищённое соединение HTTPS.', 403);
    if ($method !== 'GET') { crm_validate_body_size(); crm_check_csrf(); }
    $routes = crm_core_routes();
    foreach (glob(KCRM_ROOT . '/app/modules/*.php') ?: [] as $file) {
        $module = require $file;
        if (!is_array($module)) throw new LogicException('Module must return a route map');
        foreach ($module as $key => $handler) {
            if (!is_string($key) || !preg_match('~^(GET|POST|PATCH|DELETE) [a-z0-9][a-z0-9_/-]{0,100}$~D', $key) || !$handler instanceof Closure || isset($routes[$key])) throw new LogicException('Invalid or colliding module route');
            $routes[$key] = $handler;
        }
    }
    $key = $method . ' ' . $route;
    if (!isset($routes[$key])) {
        foreach (array_keys($routes) as $candidate) if (substr($candidate, strpos($candidate, ' ') + 1) === $route) crm_fail('METHOD_NOT_ALLOWED', 'Метод запроса не поддерживается.', 405);
        crm_fail('ROUTE_NOT_FOUND', 'Раздел API не найден.', 404);
    }
    $result = $routes[$key]();
    if (!is_array($result)) throw new LogicException('Route must send a response or return array data');
    crm_json(['ok' => true, 'data' => $result]);
}
