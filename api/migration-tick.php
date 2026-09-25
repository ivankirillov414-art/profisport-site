<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
require __DIR__.'/../server/migration.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false]);exit;}
$got=(string)($_SERVER['HTTP_X_MIGRATION_TOKEN']??'');$expected=(string)($config['migration_tick_token']??'');
if($expected===''||$got===''||!hash_equals($expected,$got)){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'unauthorized']);exit;}
@set_time_limit(55);
$result=migration_tick_once($pdo);
echo json_encode(['ok'=>true,'ran'=>(bool)($result['ran']??false)],JSON_UNESCAPED_SLASHES);
