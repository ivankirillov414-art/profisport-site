<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
require __DIR__.'/../server/migration.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false]);exit;}
$browserTrigger=(($_GET['browser']??'')==='1');
$got=(string)($_SERVER['HTTP_X_MIGRATION_TOKEN']??'');$expected=(string)($config['migration_tick_token']??'');
$tokenOk=$expected!==''&&$got!==''&&hash_equals($expected,$got);
if(!$tokenOk&&!$browserTrigger){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'unauthorized']);exit;}
// Browser trigger cannot schedule, edit or reveal a migration. It only advances a due job
// that the owner already confirmed with the current admin password.
@set_time_limit(55);
$result=migration_tick_once($pdo);
echo json_encode(['ok'=>true,'ran'=>(bool)($result['ran']??false)],JSON_UNESCAPED_SLASHES);
