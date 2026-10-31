<?php
declare(strict_types=1);
// Opt-in local QA fixture. Never accepts a production host/database configuration.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/e2e/db-admin.php';
require_once dirname(__DIR__) . '/app/bootstrap.php';
$checks = 0; $created = []; $server = null; $private = null;
$check = static function (bool $condition, string $message) use (&$checks): void { if (!$condition) throw new RuntimeException($message); $checks++; };
$reject = static function (callable $call, string $code) use ($check): void { try { $call(); } catch (CrmHttpException $e) { $check($e->errorCode === $code, 'unexpected error code'); return; } throw new RuntimeException('missing expected rejection'); };
$run = gmdate('YmdHis') . '_' . bin2hex(random_bytes(5));
$prefix = 'kcrmqa_core_' . $run;
try {
    $credentials = qa_server_config(); $server = qa_connect();
    $create = static function (string $suffix) use ($server, &$created, $prefix): PDO {
        $name = qa_database_name($prefix . '_' . $suffix);
        $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created[] = $name; return qa_connect($name);
    };
    $source = $create('source');
    $first = crm_run_migrations($source); $again = crm_run_migrations($source);
    $check($first['driver'] === 'mysql' && count($first['applied']) >= 2, 'real MySQL migrations');
    $check($again['applied'] === [], 'migrations replay is a no-op');
    foreach (['kcrm_users','kcrm_setup','kcrm_audit','kcrm_partners','kcrm_contacts','kcrm_tasks'] as $table) $check((int)$source->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() === 0, 'migrate-only leaves all business tables empty');
    $private = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcrm-core-mysql-' . bin2hex(random_bytes(8)); mkdir($private, 0700);
    $key = bin2hex(random_bytes(32)); $password = bin2hex(random_bytes(16));
    $config = ['environment'=>'test','base_path'=>'/core-fixture','storage_path'=>$private.'/storage','app_key'=>bin2hex(random_bytes(32)),'setup_key_hash'=>hash('sha256',$key),'database'=>['driver'=>'mysql','host'=>'127.0.0.1','port'=>(int)$credentials['port'],'name'=>$created[0],'username'=>$credentials['username'],'password'=>$credentials['password'],'charset'=>'utf8mb4']];
    file_put_contents($private . '/config.php', '<?php return ' . var_export($config,true) . ';');
    putenv('KCRM_CONFIG=' . $private . '/config.php'); $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $owner = crm_install_owner(['setup_key'=>$key,'name'=>'Изолированный владелец','email'=>'core-owner@example.invalid','password'=>$password]);
    $check($owner['role'] === 'owner' && crm_is_installed(), 'real MySQL initial owner');
    $reject(static fn() => crm_install_owner(['setup_key'=>$key,'name'=>'Повтор','email'=>'other@example.invalid','password'=>$password]), 'ALREADY_INSTALLED');
    $login = crm_auth_login(['email'=>'core-owner@example.invalid','password'=>$password]);
    $check($login['user']['id'] === $owner['id'], 'MySQL authenticated session');
    $partner = crm_partner_save(['name'=>'Юникод: Космопорт 🚀','contact_name'=>'Контакт','phone'=>'+70000000000','notes'=>"Строка 1\nСтрока 2"]);
    $saved = crm_partner_save(['id'=>$partner['id'],'expected_revision'=>1,'status'=>'agreed']);
    $check($saved['revision'] === 2 && $saved['phone'] === $partner['phone'] && $saved['notes'] === $partner['notes'], 'prepared UTF-8 and partial update');
    $reject(static fn() => crm_partner_save(['id'=>$partner['id'],'expected_revision'=>1,'status'=>'archived']), 'REVISION_CONFLICT');
    $contact = crm_contact_save(['partner_id'=>$partner['id'],'name'=>'Иван','email'=>'contact@example.invalid']);
    $check($contact['partner_id'] === $partner['id'], 'MySQL foreign-key contact');
    $manager = crm_user_save(['name'=>'Менеджер','email'=>'core-manager@example.invalid','role'=>'manager','password'=>$password]);
    $check(!isset($manager['password_hash'], $manager['session_version']), 'user fields safe');
    $task = crm_task_save(['title'=>'Подтвердить партнёра','assignee_id'=>$manager['id'],'partner_id'=>$partner['id'],'due_date'=>'2026-10-31']);
    $done = crm_task_save(['id'=>$task['id'],'expected_revision'=>1,'status'=>'done']);
    $check($done['status'] === 'done' && str_ends_with($done['completed_at'],'Z'), 'MySQL task completion UTC');
    $check(crm_dashboard()['counts']['partners'] === 1, 'real dashboard counts');
    $before = (int)$source->query('SELECT COUNT(*) FROM kcrm_audit')->fetchColumn();
    try { crm_transaction(static function (): void { crm_partner_save(['name'=>'Rollback fixture']); throw new RuntimeException('deliberate test rollback'); }); } catch (RuntimeException) {}
    $check((int)$source->query("SELECT COUNT(*) FROM kcrm_partners WHERE name='Rollback fixture'")->fetchColumn() === 0, 'business rollback');
    $check((int)$source->query('SELECT COUNT(*) FROM kcrm_audit')->fetchColumn() === $before, 'audit rolls back with business write');
    $reject(static fn() => crm_user_save(['id'=>$owner['id'],'expected_revision'=>1,'active'=>false]), 'LAST_OWNER');
    $target = $create('restore_target'); $migrated = crm_run_migrations($target);
    $check(count($migrated['applied']) >= 2, 'explicit separate-PDO restore target migrated');
    foreach (['kcrm_users','kcrm_setup','kcrm_audit','kcrm_partners','kcrm_contacts','kcrm_tasks'] as $table) $check((int)$target->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() === 0, 'restore target business tables remain empty');
    $schemaSeeds = [];
    foreach ($target->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) if ($table !== 'kcrm_migrations') {
        $check(preg_match('/^kcrm_[a-z0-9_]+$/D',$table) === 1,'trusted fixture table');
        if ($table === 'kcrm_ticket_lock') {
            $rows = $target->query('SELECT id,touched FROM kcrm_ticket_lock')->fetchAll(PDO::FETCH_ASSOC);
            $check(count($rows) === 1 && (int)$rows[0]['id'] === 1 && (int)$rows[0]['touched'] === 0,'known ticket schema lock seed only');
            $schemaSeeds[] = $table; continue;
        }
        $check((int)$target->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === 0,'all installed module business tables start empty');
    }
    $check((string)crm_db()->query('SELECT DATABASE()')->fetchColumn() === $created[0], 'explicit target does not switch source PDO');
    $check((int)$source->query('SELECT COUNT(*) FROM kcrm_users')->fetchColumn() === 2, 'source users untouched by target migrations');
    $version = $source->query('SELECT VERSION()')->fetchColumn();
    $bad = $source->prepare('UPDATE kcrm_migrations SET checksum=? WHERE version=?'); $bad->execute([str_repeat('0',64),'001_core.sql']);
    $reject(static fn() => crm_run_migrations($source), 'MIGRATION_CHANGED');
    $result = ['ok'=>true,'checks'=>$checks,'database_engine'=>$version,'mysql_migrations_verified'=>true,'core_migrate_only_empty'=>true,'separate_target_pdo_verified'=>true,'module_schema_seed_tables'=>$schemaSeeds,'business_data'=>'disposable fixtures only'];
} catch (Throwable $error) { $result = ['ok'=>false,'error'=>get_class($error),'message'=>$error instanceof RuntimeException && !$error instanceof PDOException ? $error->getMessage() : 'Local MySQL test failed; inspect private local configuration.']; }
finally {
    if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION=[]; session_destroy(); }
    if ($server) foreach (array_reverse($created) as $name) { if (!str_starts_with($name, $prefix . '_')) throw new LogicException('Unsafe QA cleanup'); $server->exec('DROP DATABASE `' . qa_database_name($name) . '`'); }
    if ($private !== null) {
        foreach (glob($private . '/storage/sessions/sess_*') ?: [] as $session) unlink($session);
        if (is_dir($private . '/storage/sessions')) rmdir($private . '/storage/sessions');
        if (is_dir($private . '/storage')) rmdir($private . '/storage');
        if (is_file($private . '/config.php')) unlink($private . '/config.php');
        if (is_dir($private)) rmdir($private);
    }
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
