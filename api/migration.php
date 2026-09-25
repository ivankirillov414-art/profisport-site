<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
require __DIR__.'/../server/migration.php';
start_secure_session();
migration_ensure_schema($pdo);

function migration_public_row(array $r): array {
  $progress=json_decode((string)($r['progress_json']??''),true);if(!is_array($progress))$progress=[];
  return [
    'id'=>(int)$r['id'],
    'status'=>(string)$r['status'],
    'due_at'=>(string)$r['due_at'],
    'phase'=>(string)$r['phase'],
    'progress'=>$progress,
    'error'=>(string)($r['error_text']??''),
    'created_at'=>(string)$r['created_at'],
    'completed_at'=>$r['completed_at']?:null
  ];
}

try{
  $admin=migration_owner($pdo);
  $action=(string)($_GET['action']??'status');

  if($action==='status'&&$_SERVER['REQUEST_METHOD']==='GET'){
    $rows=$pdo->query("SELECT id,status,due_at,phase,progress_json,error_text,created_at,completed_at FROM site_migrations ORDER BY id DESC LIMIT 10")->fetchAll();
    json_response(['ok'=>true,'items'=>array_map('migration_public_row',$rows),'server'=>[
      'php'=>PHP_VERSION,
      'pdo_mysql'=>extension_loaded('pdo_mysql'),
      'openssl'=>function_exists('openssl_encrypt'),
      'ftps'=>function_exists('ftp_ssl_connect'),
      'zip'=>class_exists('ZipArchive')
    ]]);
  }

  if($action==='preflight'&&$_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();$in=input_json();$cfg=migration_config($in);$result=migration_preflight($cfg,true);
    audit($pdo,'migration_preflight','site_migration',null,['target_url'=>$cfg['target_url'],'db_host'=>$cfg['db_host'],'ftp_host'=>$cfg['ftp_host']]);
    json_response(['ok'=>true,'checks'=>$result]);
  }

  if($action==='schedule'&&$_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();$in=input_json();migration_verify_password($pdo,(int)$admin['id'],(string)($in['current_password']??''));
    $cfg=migration_config($in);$checks=migration_preflight($cfg,true);
    $delay=max(10,min(10080,(int)($in['delay_minutes']??60)));
    $active=(int)$pdo->query("SELECT COUNT(*) FROM site_migrations WHERE status IN ('scheduled','running')")->fetchColumn();
    if($active>0)json_response(['ok'=>false,'error'=>'migration_already_active'],409);
    $due=(new DateTimeImmutable())->modify('+'.$delay.' minutes')->format('Y-m-d H:i:s');
    $s=$pdo->prepare("INSERT INTO site_migrations(status,due_at,phase,cursor_json,config_cipher,progress_json,created_by) VALUES('scheduled',?,'db_schema','{}',?,'{}',?)");
    $s->execute([$due,migration_encrypt($cfg),(int)$admin['id']]);$id=(int)$pdo->lastInsertId();
    audit($pdo,'migration_scheduled','site_migration',(string)$id,['due_at'=>$due,'delay_minutes'=>$delay,'target_url'=>$cfg['target_url'],'checks'=>$checks]);
    json_response(['ok'=>true,'id'=>$id,'due_at'=>$due,'checks'=>$checks]);
  }

  if($action==='retry'&&$_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();$in=input_json();migration_verify_password($pdo,(int)$admin['id'],(string)($in['current_password']??''));
    $id=(int)($in['id']??0);if($id<1)json_response(['ok'=>false,'error'=>'invalid_id'],422);
    $q=$pdo->prepare("SELECT phase FROM site_migrations WHERE id=? AND status='failed' AND config_cipher IS NOT NULL LIMIT 1");$q->execute([$id]);$failedPhase=(string)($q->fetchColumn()?:'');if($failedPhase==='')json_response(['ok'=>false,'error'=>'cannot_retry'],409);
    if($failedPhase==='verify')$s=$pdo->prepare("UPDATE site_migrations SET status='scheduled',due_at=NOW(),phase='db_data',cursor_json='{\"table\":0,\"offset\":0}',error_text=NULL WHERE id=?");
    else $s=$pdo->prepare("UPDATE site_migrations SET status='scheduled',due_at=NOW(),error_text=NULL WHERE id=?");
    $s->execute([$id]);
    audit($pdo,'migration_retry','site_migration',(string)$id);json_response(['ok'=>true]);
  }

  if($action==='cancel'&&$_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();$in=input_json();migration_verify_password($pdo,(int)$admin['id'],(string)($in['current_password']??''));
    $id=(int)($in['id']??0);if($id<1)json_response(['ok'=>false,'error'=>'invalid_id'],422);
    $s=$pdo->prepare("UPDATE site_migrations SET status='cancelled',config_cipher=NULL,error_text=NULL WHERE id=? AND status='scheduled'");$s->execute([$id]);
    if(!$s->rowCount())json_response(['ok'=>false,'error'=>'cannot_cancel'],409);
    audit($pdo,'migration_cancelled','site_migration',(string)$id);json_response(['ok'=>true]);
  }

  json_response(['ok'=>false,'error'=>'not_found'],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>$e->getMessage()],422);
}catch(RuntimeException $e){error_log($e->__toString());json_response(['ok'=>false,'error'=>$e->getMessage()],409);
}catch(Throwable $e){error_log($e->__toString());json_response(['ok'=>false,'error'=>'server_error'],500);}
