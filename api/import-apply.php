<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');@set_time_limit(0);
function out(array $x,int $code=200):never{http_response_code($code);echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function authImport(array $config):void{$g=(string)($_SERVER['HTTP_X_IMPORT_TOKEN']??'');$e=(string)($config['import_token']??'');if($e!==''&&$g!==''&&hash_equals($e,$g))return;require_admin();if($_SERVER['REQUEST_METHOD']==='POST')csrf_check();}
try{authImport($config);$cols=$pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_ASSOC);out(['ok'=>false,'error'=>'products_schema_probe','columns'=>$cols],422);}catch(Throwable $e){out(['ok'=>false,'error'=>$e->getMessage()],500);}
