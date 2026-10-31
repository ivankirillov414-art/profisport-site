<?php
declare(strict_types=1);

function crm_row_view(array $row): array
{
    unset($row['password_hash'], $row['session_version']);
    foreach (['created_at', 'updated_at', 'completed_at'] as $key) if (array_key_exists($key, $row)) $row[$key] = crm_iso($row[$key]);
    if (isset($row['revision'])) $row['revision'] = (int)$row['revision'];
    if (isset($row['active'])) $row['active'] = (bool)$row['active'];
    return $row;
}

function crm_page(): array
{
    $integer = static function (mixed $value, int $default, int $max): int {
        if ($value === null) return $default;
        if (!is_string($value) || !preg_match('/^\d{1,6}$/D', $value) || (int)$value > $max) crm_fail('INVALID_PAGE', 'Проверьте параметры списка');
        return (int)$value;
    };
    return [max(1, $integer($_GET['limit'] ?? null, 50, 100)), $integer($_GET['offset'] ?? null, 0, 100000)];
}

function crm_find(string $table, string $id, bool $lock = false): array
{
    if (!in_array($table, ['kcrm_users', 'kcrm_partners', 'kcrm_contacts', 'kcrm_tasks'], true)) throw new LogicException('Unrecognised core table');
    $row = crm_query('SELECT * FROM ' . $table . ' WHERE id=?' . ($lock ? crm_lock_suffix() : ''), [$id])->fetch();
    if (!$row) crm_fail('NOT_FOUND', 'Запись не найдена.', 404);
    return $row;
}

function crm_store_record(string $table, array $fields, array $input, array $actor, string $entity): array
{
    $allowed = [
        'kcrm_partners' => ['name','kind','status','contact_name','phone','email','website','address','notes'],
        'kcrm_contacts' => ['partner_id','name','role_title','phone','email','notes'],
        'kcrm_tasks' => ['title','description','status','priority','assignee_id','partner_id','due_date','completed_at'],
    ];
    if (!isset($allowed[$table]) || array_diff(array_keys($fields), $allowed[$table])) throw new LogicException('Unrecognised core fields');
    return crm_transaction(function () use ($table, $fields, $input, $actor, $entity): array {
        $id = crm_identifier($input['id'] ?? null, true); $now = crm_now();
        if ($id !== null) {
            $old = crm_find($table, $id, true);
            $expected = $input['expected_revision'] ?? $input['revision'] ?? null;
            if (!is_int($expected) || $expected < 1) crm_fail('REVISION_REQUIRED', 'Обновите карточку перед сохранением. Нужна версия записи.', 422);
            if ((int)$old['revision'] !== $expected) crm_fail('REVISION_CONFLICT', 'Запись уже изменена другим сотрудником. Обновите карточку.', 409);
            $columns = array_map(static fn(string $column): string => $column . '=?', array_keys($fields));
            $changed = crm_query('UPDATE ' . $table . ' SET ' . implode(',', $columns) . ',revision=revision+1,updated_at=? WHERE id=? AND revision=?', [...array_values($fields), $now, $id, $expected])->rowCount();
            if ($changed !== 1) crm_fail('REVISION_CONFLICT', 'Запись уже изменена другим сотрудником.', 409);
            $action = 'update';
        } else {
            $id = crm_uuid(); $values = ['id' => $id, ...$fields, 'created_by' => $actor['id'], 'revision' => 1, 'created_at' => $now, 'updated_at' => $now];
            crm_query('INSERT INTO ' . $table . ' (' . implode(',', array_keys($values)) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')', array_values($values)); $action = 'create';
        }
        crm_audit($entity . '.' . $action, $entity, $id, ['fields' => array_keys($fields)]);
        return crm_row_view(crm_find($table, $id));
    });
}

function crm_partners_list(): array
{
    crm_require_roles(KCRM_ROLES); [$limit, $offset] = crm_page(); $where = []; $values = [];
    $q = crm_text($_GET['q'] ?? $_GET['search'] ?? '', 'Поиск', 150);
    if ($q !== '') { $where[] = '(name LIKE ? OR contact_name LIKE ? OR phone LIKE ? OR email LIKE ?)'; $values = array_fill(0, 4, '%' . $q . '%'); }
    if (isset($_GET['status']) && $_GET['status'] !== '') { $where[] = 'status=?'; $values[] = crm_text($_GET['status'], 'Статус', 32); }
    $filter = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $total = (int)crm_query('SELECT COUNT(*) FROM kcrm_partners' . $filter, $values)->fetchColumn();
    $rows = crm_query('SELECT * FROM kcrm_partners' . $filter . ' ORDER BY updated_at DESC,id LIMIT ' . $limit . ' OFFSET ' . $offset, $values)->fetchAll();
    return ['items' => array_map('crm_row_view', $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}

function crm_partner_save(array $input): array
{
    $actor = crm_require_roles(['manager']);
    if (!empty($input['id'])) $input = array_replace(array_intersect_key(crm_find('kcrm_partners', crm_identifier($input['id'])), array_flip(['name','kind','status','contact_name','phone','email','website','address','notes'])), $input);
    $fields = ['name' => crm_text($input['name'] ?? '', 'Организация', 200, true), 'kind' => crm_text($input['kind'] ?? 'organisation', 'Тип партнёра', 80, true), 'status' => crm_choice($input['status'] ?? 'new', ['new','active','contacted','negotiation','negotiating','agreed','confirmed','paused','declined','archived'], 'Статус'), 'contact_name' => crm_text($input['contact_name'] ?? '', 'Контактное лицо', 200), 'phone' => crm_text($input['phone'] ?? '', 'Телефон', 80), 'email' => crm_email($input['email'] ?? ''), 'website' => crm_text($input['website'] ?? '', 'Сайт', 500), 'address' => crm_text($input['address'] ?? '', 'Адрес', 500), 'notes' => crm_text($input['notes'] ?? '', 'Заметки', 10000)];
    if ($fields['website'] !== '' && (!filter_var($fields['website'], FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($fields['website'], PHP_URL_SCHEME) ?: ''), ['http', 'https'], true))) crm_fail('INVALID_WEBSITE', 'Сайт должен начинаться с http:// или https://');
    return crm_store_record('kcrm_partners', $fields, $input, $actor, 'partner');
}

function crm_contacts_list(): array
{
    crm_require_roles(KCRM_ROLES); [$limit, $offset] = crm_page(); $where = []; $values = [];
    if (isset($_GET['partner_id'])) { $where[] = 'c.partner_id=?'; $values[] = crm_identifier($_GET['partner_id']); }
    $q = crm_text($_GET['q'] ?? '', 'Поиск', 150);
    if ($q !== '') { $where[] = '(c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)'; array_push($values, ...array_fill(0, 3, '%' . $q . '%')); }
    $filter = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $total = (int)crm_query('SELECT COUNT(*) FROM kcrm_contacts c' . $filter, $values)->fetchColumn();
    $rows = crm_query('SELECT c.*,p.name AS partner_name FROM kcrm_contacts c JOIN kcrm_partners p ON p.id=c.partner_id' . $filter . ' ORDER BY c.updated_at DESC,c.id LIMIT ' . $limit . ' OFFSET ' . $offset, $values)->fetchAll();
    return ['items' => array_map('crm_row_view', $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}

function crm_contact_save(array $input): array
{
    $actor = crm_require_roles(['manager']);
    if (!empty($input['id'])) $input = array_replace(array_intersect_key(crm_find('kcrm_contacts', crm_identifier($input['id'])), array_flip(['partner_id','name','role_title','phone','email','notes'])), $input);
    $partner = crm_identifier($input['partner_id'] ?? null); crm_find('kcrm_partners', $partner);
    $fields = ['partner_id' => $partner, 'name' => crm_text($input['name'] ?? '', 'Контактное лицо', 200, true), 'role_title' => crm_text($input['role_title'] ?? '', 'Должность', 150), 'phone' => crm_text($input['phone'] ?? '', 'Телефон', 80), 'email' => crm_email($input['email'] ?? ''), 'notes' => crm_text($input['notes'] ?? '', 'Заметки', 10000)];
    return crm_store_record('kcrm_contacts', $fields, $input, $actor, 'contact');
}

function crm_tasks_list(): array
{
    crm_require_roles(KCRM_ROLES); [$limit, $offset] = crm_page(); $where = []; $values = [];
    foreach (['status', 'assignee_id', 'partner_id'] as $key) if (isset($_GET[$key]) && $_GET[$key] !== '') { $where[] = 't.' . $key . '=?'; $values[] = $key === 'status' ? crm_choice($_GET[$key], ['todo','in_progress','blocked','done','cancelled'], 'Статус') : crm_identifier($_GET[$key]); }
    $q = crm_text($_GET['q'] ?? '', 'Поиск', 150);
    if ($q !== '') { $where[] = '(t.title LIKE ? OR t.description LIKE ?)'; array_push($values, '%' . $q . '%', '%' . $q . '%'); }
    $filter = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $total = (int)crm_query('SELECT COUNT(*) FROM kcrm_tasks t' . $filter, $values)->fetchColumn();
    $rows = crm_query('SELECT t.*,u.name AS assignee_name,p.name AS partner_name FROM kcrm_tasks t LEFT JOIN kcrm_users u ON u.id=t.assignee_id LEFT JOIN kcrm_partners p ON p.id=t.partner_id' . $filter . ' ORDER BY t.updated_at DESC,t.id LIMIT ' . $limit . ' OFFSET ' . $offset, $values)->fetchAll();
    return ['items' => array_map('crm_row_view', $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}

function crm_task_save(array $input): array
{
    $actor = crm_require_roles(['manager','cashier','editor','document_manager']);
    return crm_transaction(function () use ($input, $actor): array {
        $old = !empty($input['id']) ? crm_find('kcrm_tasks', crm_identifier($input['id']), true) : null;
        $input = array_replace(array_intersect_key($old ?? [], array_flip(['title','description','status','priority','assignee_id','partner_id','due_date'])), $input);
        $manager = in_array($actor['role'], ['owner','admin','manager'], true);
        if (!$manager && $old && $old['created_by'] !== $actor['id'] && $old['assignee_id'] !== $actor['id']) crm_fail('FORBIDDEN', 'Можно изменять только свои задачи.', 403);
        $assignee = crm_identifier($input['assignee_id'] ?? null, true);
        if ($assignee !== null) { $target = crm_find('kcrm_users', $assignee); if (!(bool)$target['active']) crm_fail('ASSIGNEE_INACTIVE', 'Сотрудник отключён. Выберите другого исполнителя.'); }
        if (!$manager && $assignee !== null && $assignee !== $actor['id'] && $assignee !== ($old['assignee_id'] ?? null)) crm_fail('FORBIDDEN', 'Назначать задачи другим сотрудникам может менеджер.', 403);
        $partner = crm_identifier($input['partner_id'] ?? null, true); if ($partner !== null) crm_find('kcrm_partners', $partner);
        $status = crm_choice($input['status'] ?? 'todo', ['todo','in_progress','blocked','done','cancelled'], 'Статус');
        $fields = ['title' => crm_text($input['title'] ?? '', 'Задача', 240, true), 'description' => crm_text($input['description'] ?? '', 'Описание', 10000), 'status' => $status, 'priority' => crm_choice($input['priority'] ?? 'normal', ['low','normal','high','urgent'], 'Приоритет'), 'assignee_id' => $assignee, 'partner_id' => $partner, 'due_date' => crm_date($input['due_date'] ?? null), 'completed_at' => $status === 'done' ? ($old['completed_at'] ?? crm_now()) : null];
        return crm_store_record('kcrm_tasks', $fields, $input, $actor, 'task');
    });
}

function crm_users_list(): array
{
    $actor = crm_require_roles(KCRM_ROLES); [$limit, $offset] = crm_page();
    $admin = in_array($actor['role'], ['owner','admin'], true);
    $filter = $admin && ($_GET['include_inactive'] ?? '') === '1' ? '' : ' WHERE active=1';
    $columns = $admin ? 'id,name,email,role,active,revision,created_at,updated_at' : 'id,name,role';
    return ['items' => array_map('crm_row_view', crm_query('SELECT ' . $columns . ' FROM kcrm_users' . $filter . ' ORDER BY name,id LIMIT ' . $limit . ' OFFSET ' . $offset)->fetchAll()), 'total' => (int)crm_query('SELECT COUNT(*) FROM kcrm_users' . $filter)->fetchColumn(), 'limit' => $limit, 'offset' => $offset];
}

function crm_user_save(array $input): array
{
    $actor = crm_require_roles([]);
    return crm_transaction(function () use ($input, $actor): array {
        // Serialise owner mutations to prevent two requests disabling the last owners.
        crm_query('SELECT id FROM kcrm_setup WHERE id=1' . crm_lock_suffix())->fetch();
        $id = crm_identifier($input['id'] ?? null, true); $old = $id ? crm_find('kcrm_users', $id, true) : null;
        $role = crm_choice($input['role'] ?? ($old['role'] ?? 'reader'), KCRM_ROLES, 'Роль');
        if ($actor['role'] !== 'owner' && ($role === 'owner' || ($old['role'] ?? '') === 'owner')) crm_fail('FORBIDDEN', 'Учётными записями владельцев управляет владелец.', 403);
        $name = crm_text($input['name'] ?? ($old['name'] ?? ''), 'Имя', 200, true); $email = crm_email($input['email'] ?? ($old['email'] ?? ''), true);
        $active = array_key_exists('active', $input) ? crm_boolean($input['active']) : (bool)($old['active'] ?? true);
        $password = $input['password'] ?? null;
        $hash = $password === null || $password === '' ? ($old['password_hash'] ?? null) : password_hash(crm_password($password), PASSWORD_BCRYPT, ['cost' => 12]);
        if (!$hash) crm_fail('PASSWORD_REQUIRED', 'Задайте сотруднику пароль.');
        if ($old && $old['role'] === 'owner' && (bool)$old['active'] && (!$active || $role !== 'owner') && (int)crm_query("SELECT COUNT(*) FROM kcrm_users WHERE role='owner' AND active=1")->fetchColumn() <= 1) crm_fail('LAST_OWNER', 'Нельзя отключить последнего владельца.', 409);
        if (crm_query('SELECT id FROM kcrm_users WHERE email=? AND id<>?', [$email, $id ?? ''])->fetchColumn()) crm_fail('EMAIL_EXISTS', 'Такая почта уже используется.', 409);
        $now = crm_now();
        if ($old) {
            $expected = $input['expected_revision'] ?? $input['revision'] ?? null;
            if (!is_int($expected) || $expected !== (int)$old['revision']) crm_fail('REVISION_CONFLICT', 'Учётная запись уже изменена. Обновите список.', 409);
            $revoke = $hash !== $old['password_hash'] || $role !== $old['role'] || $active !== (bool)$old['active'];
            crm_query('UPDATE kcrm_users SET name=?,email=?,password_hash=?,role=?,active=?,session_version=session_version+?,revision=revision+1,updated_at=? WHERE id=? AND revision=?', [$name,$email,$hash,$role,(int)$active,(int)$revoke,$now,$id,$expected]);
        } else {
            $id = crm_uuid(); crm_query('INSERT INTO kcrm_users (id,name,email,password_hash,role,active,session_version,revision,created_at,updated_at) VALUES (?,?,?,?,?,?,1,1,?,?)', [$id,$name,$email,$hash,$role,(int)$active,$now,$now]);
        }
        crm_audit('user.' . ($old ? 'update' : 'create'), 'user', $id, ['role' => $role, 'active' => $active, 'credentials_changed' => $password !== null && $password !== '']);
        return crm_row_view(crm_find('kcrm_users', $id));
    });
}

function crm_dashboard(): array
{
    $actor = crm_require_roles(KCRM_ROLES); $today = (new DateTimeImmutable('now', new DateTimeZone(crm_config('timezone'))))->format('Y-m-d');
    $counts = ['partners' => (int)crm_query("SELECT COUNT(*) FROM kcrm_partners WHERE status<>'archived'")->fetchColumn(), 'open_tasks' => (int)crm_query("SELECT COUNT(*) FROM kcrm_tasks WHERE status NOT IN ('done','cancelled')")->fetchColumn(), 'overdue_tasks' => (int)crm_query("SELECT COUNT(*) FROM kcrm_tasks WHERE status NOT IN ('done','cancelled') AND due_date<?", [$today])->fetchColumn(), 'documents' => null];
    try { if (in_array($actor['role'], ['owner','admin','manager','document_manager','reader'], true)) $counts['documents'] = (int)crm_query('SELECT COUNT(*) FROM kcrm_documents')->fetchColumn(); }
    catch (PDOException $error) { if (!str_contains($error->getMessage(), 'no such table') && !str_contains($error->getMessage(), "doesn't exist")) throw $error; }
    $tasks = crm_query('SELECT t.*,u.name AS assignee_name FROM kcrm_tasks t LEFT JOIN kcrm_users u ON u.id=t.assignee_id ORDER BY t.updated_at DESC,t.id LIMIT 8')->fetchAll();
    return ['counts' => $counts, 'recent_tasks' => array_map('crm_row_view', $tasks), 'timezone' => crm_config('timezone'), 'generated_at' => crm_iso(crm_now())];
}

function crm_audit_list(): array
{
    crm_require_roles([]); [$limit, $offset] = crm_page(); $where = []; $values = [];
    foreach (['action','entity_type','entity_id'] as $key) if (isset($_GET[$key]) && $_GET[$key] !== '') { $where[] = 'a.' . $key . '=?'; $values[] = crm_text($_GET[$key], 'Фильтр', 100); }
    $filter = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $rows = crm_query('SELECT a.*,u.name AS actor_name FROM kcrm_audit a LEFT JOIN kcrm_users u ON u.id=a.actor_id' . $filter . ' ORDER BY a.created_at DESC,a.id LIMIT ' . $limit . ' OFFSET ' . $offset, $values)->fetchAll();
    foreach ($rows as &$row) { $row = crm_row_view($row); $row['details'] = json_decode($row['details'], true, 32, JSON_THROW_ON_ERROR); } unset($row);
    return ['items' => $rows, 'total' => (int)crm_query('SELECT COUNT(*) FROM kcrm_audit a' . $filter, $values)->fetchColumn(), 'limit' => $limit, 'offset' => $offset];
}
