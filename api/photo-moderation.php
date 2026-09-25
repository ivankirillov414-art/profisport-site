<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
require_admin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(410);
echo json_encode([
  'ok'=>false,
  'error'=>'photo_moderation_disabled',
  'source_policy'=>'current_1c_mysql_only',
  'message'=>'Ручная подмена, загрузка и интернет-поиск фотографий отключены.'
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
