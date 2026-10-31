<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { if (!$condition) throw new RuntimeException($message); $checks++; };
$reject = static function (callable $call, string $code) use ($assert): void { try { $call(); } catch (CrmHttpException $error) { $assert($error->errorCode === $code, 'wrong error code'); return; } throw new RuntimeException('expected rejection: ' . $code); };
$assert(crm_config('database.driver') === 'sqlite_test', 'explicit fixture driver');
$assert(crm_config('not.present') === null, 'missing config');
$assert(crm_config('timezone') === 'Asia/Yekaterinburg', 'configured timezone');
$assert(preg_match('/^[a-f0-9-]{36}$/', crm_uuid()) === 1, 'UUID');
$assert(crm_iso('2026-10-31 10:05:00') === '2026-10-31T10:05:00Z', 'UTC');
$assert(crm_date('2026-10-31') === '2026-10-31', 'date');
$reject(static fn() => crm_date('2026-02-30'), 'INVALID_DATE');
$reject(static fn() => crm_date(['date']), 'INVALID_DATE');
$reject(static fn() => crm_text(['name'], 'Имя'), 'INVALID_FIELD');
$reject(static fn() => crm_email('not-mail'), 'INVALID_EMAIL');
$reject(static fn() => crm_boolean('true'), 'INVALID_FIELD');
$reject(static fn() => crm_identifier('x OR 1=1'), 'INVALID_ID');
$reject(static fn() => crm_password('short'), 'PASSWORD_INVALID');
$reject(static fn() => crm_password(str_repeat('x', 73)), 'PASSWORD_INVALID');
foreach (['../secret','a/../b','C:\\secret','/etc/passwd','\\\\server\\share','a\\..\\b',"a\0b"] as $path) $reject(static fn() => crm_storage_path($path), 'INVALID_PATH');
$assert(str_ends_with(crm_storage_path('documents/version.bin'), 'version.bin'), 'private storage');
$statements = crm_split_sql("-- comment\nCREATE TABLE example (name TEXT DEFAULT 'a;b'); /* comment */ INSERT INTO example VALUES ('it''s; valid');");
$assert(count($statements) === 2, 'quoted/comment SQL splitting');
$reject(static fn() => crm_split_sql("SELECT 'unfinished"), 'MIGRATION_INVALID');
$reject(static fn() => crm_split_sql('/*! SQL */'), 'MIGRATION_INVALID');
foreach (glob(KCRM_ROOT . '/migrations/0[01]*.sql') as $file) {
    $sql = file_get_contents($file); $parts = crm_split_sql($sql);
    $assert(count($parts) > 0 && !str_contains($sql, 'sqlite'), 'production MySQL migrations');
    foreach ($parts as $statement) $assert(preg_match('/^(CREATE TABLE IF NOT EXISTS kcrm_|INSERT IGNORE INTO kcrm_)/', $statement) === 1, 'fixed table prefixes');
}
echo json_encode(['ok' => true, 'checks' => $checks], JSON_THROW_ON_ERROR) . PHP_EOL;
