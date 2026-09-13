<?php
declare(strict_types=1);
define('PROFISPORT_SKIP_SCHEMA',true);
require __DIR__.'/../server/bootstrap.php';
require __DIR__.'/../server/source-photo-reconcile.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
@set_time_limit(0);

try{
    require_admin();
    $root=realpath(__DIR__.'/../import');
    if(!$root)throw new RuntimeException('import_missing');
    echo json_encode(spr_reconcile($pdo,$root),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $error){
    error_log($error->__toString());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'server_error','detail'=>$error->getMessage()],JSON_UNESCAPED_UNICODE);
}
