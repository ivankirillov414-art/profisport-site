<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
require __DIR__.'/../server/migration.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false]);exit;}
$browserTrigger=(($_GET['browser']??'')==='1');
if($browserTrigger){
  $secFetchSite=strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE']??''));
  $origin=(string)($_SERVER['HTTP_ORIGIN']??'');
  $referer=(string)($_SERVER['HTTP_REFERER']??'');
  $host=preg_replace('/:\d+$/','',(string)($_SERVER['HTTP_HOST']??''));
  $sameHost=function(string $url)use($host):bool{
    if($url===''||$host==='')return false;
    $urlHost=(string)(parse_url($url,PHP_URL_HOST)??'');
    return $urlHost!==''&&strcasecmp($urlHost,$host)===0;
  };
  $trustedBrowserContext=$secFetchSite==='same-origin'||$sameHost($origin)||$sameHost($referer);
  if(!$trustedBrowserContext){
    http_response_code(403);echo json_encode(['ok'=>false,'error'=>'browser_context_required']);exit;
  }
  if($secFetchSite!==''&&$secFetchSite!=='same-origin'){
    http_response_code(403);echo json_encode(['ok'=>false,'error'=>'cross_site']);exit;
  }
}
$got=(string)($_SERVER['HTTP_X_MIGRATION_TOKEN']??'');$expected=(string)($config['migration_tick_token']??'');
$tokenOk=$expected!==''&&$got!==''&&hash_equals($expected,$got);
if(!$tokenOk&&!$browserTrigger){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'unauthorized']);exit;}
// Browser trigger cannot schedule, edit or reveal a migration. It only advances a due job
// that the owner already confirmed with the current admin password.
@set_time_limit(55);
$result=migration_tick_once($pdo);
echo json_encode(['ok'=>true,'ran'=>(bool)($result['ran']??false)],JSON_UNESCAPED_SLASHES);
