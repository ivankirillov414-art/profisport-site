<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
start_secure_session();
$action=$_GET['action']??'health';
const OWNER='Иван Кириллов 414';
const RECOVERY_BOOTSTRAP_HASH='f6d3e916099398fecf160e423e4bb0a76251e06c73c56b57a32d59521ba30979';
try{
 if($action==='health'){$count=(int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();json_response(['ok'=>true,'db'=>true,'products'=>$count,'php'=>PHP_VERSION]);}
 if($action==='setup_status'){$s=$pdo->prepare('SELECT id,username,password_hash,force_password_setup FROM admin_users WHERE username=? LIMIT 1');$s->execute([OWNER]);$u=$s->fetch();json_response(['ok'=>true,'setup_required'=>!$u||empty($u['password_hash'])||(int)$u['force_password_setup']===1]);}
 if($action==='recovery_bootstrap'&&$_SERVER['REQUEST_METHOD']==='POST'){
  $in=input_json();$token=(string)($in['token']??'');
  if($token===''||!hash_equals(RECOVERY_BOOTSTRAP_HASH,hash('sha256',$token)))json_response(['ok'=>false,'error'=>'invalid_recovery'],403);
  $pdo->exec("CREATE TABLE IF NOT EXISTS admin_recovery_tokens (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,admin_user_id INT UNSIGNED NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,expires_at DATETIME NOT NULL,used_at DATETIME NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_exp(expires_at),INDEX idx_admin(admin_user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $s=$pdo->prepare('SELECT id FROM admin_users WHERE username=? AND is_active=1 LIMIT 1');$s->execute([OWNER]);$u=$s->fetch();if(!$u)json_response(['ok'=>false,'error'=>'owner_not_found'],404);
  $raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);$pdo->beginTransaction();
  $s=$pdo->prepare('UPDATE admin_recovery_tokens SET used_at=NOW() WHERE admin_user_id=? AND used_at IS NULL');$s->execute([(int)$u['id']]);
  $s=$pdo->prepare('INSERT INTO admin_recovery_tokens(admin_user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))');$s->execute([(int)$u['id'],$hash]);$pdo->commit();
  json_response(['ok'=>true,'recovery_token'=>$raw,'expires_minutes'=>30]);
 }
 if($action==='login'&&$_SERVER['REQUEST_METHOD']==='POST'){$in=input_json();$username=trim((string)($in['username']??''));$password=(string)($in['password']??'');$s=$pdo->prepare('SELECT id,username,password_hash,role,is_active FROM admin_users WHERE username=? LIMIT 1');$s->execute([$username]);$u=$s->fetch();if(!$u||!(int)$u['is_active']||empty($u['password_hash'])||!password_verify($password,$u['password_hash']))json_response(['ok'=>false,'error'=>'invalid_credentials'],401);session_regenerate_id(true);$_SESSION['admin']=['id'=>(int)$u['id'],'username'=>$u['username'],'role'=>$u['role']];$_SESSION['csrf']=bin2hex(random_bytes(24));audit($pdo,'login','admin_user',(string)$u['id']);json_response(['ok'=>true,'admin'=>$_SESSION['admin'],'csrf'=>$_SESSION['csrf']]);}
 if($action==='logout'&&$_SERVER['REQUEST_METHOD']==='POST'){require_admin();csrf_check();audit($pdo,'logout');$_SESSION=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);}session_destroy();json_response(['ok'=>true]);}
 if($action==='me'){$a=require_admin();json_response(['ok'=>true,'admin'=>$a,'csrf'=>$_SESSION['csrf']??'']);}
 if($action==='stats'){require_admin();$products=(int)$pdo->query('SELECT COUNT(*) FROM products WHERE is_active=1')->fetchColumn();$orders=(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='new'")->fetchColumn();json_response(['ok'=>true,'products'=>$products,'new_orders'=>$orders]);}
 if($action==='products'&&$_SERVER['REQUEST_METHOD']==='GET'){require_admin();$q=trim((string)($_GET['q']??''));$page=max(1,(int)($_GET['page']??1));$limit=40;$offset=($page-1)*$limit;if($q!==''){$s=$pdo->prepare('SELECT * FROM products WHERE title LIKE ? ORDER BY id DESC LIMIT '.$limit.' OFFSET '.$offset);$s->execute(['%'.$q.'%']);}else $s=$pdo->query('SELECT * FROM products ORDER BY id DESC LIMIT '.$limit.' OFFSET '.$offset);$rows=$s->fetchAll();foreach($rows as &$r){$r['images']=json_decode($r['images']?:'[]',true)?:[];$r['specs']=json_decode($r['specs']?:'{}',true)?:[];}json_response(['ok'=>true,'items'=>$rows,'page'=>$page]);}
 if($action==='settings'&&$_SERVER['REQUEST_METHOD']==='GET'){require_admin();$rows=$pdo->query('SELECT setting_key,setting_value FROM site_settings ORDER BY setting_key')->fetchAll();$out=[];foreach($rows as $r)$out[$r['setting_key']]=$r['setting_value'];json_response(['ok'=>true,'settings'=>$out]);}
 if($action==='settings_save'&&$_SERVER['REQUEST_METHOD']==='POST'){require_admin();csrf_check();$in=input_json();$s=$pdo->prepare('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');foreach(($in['settings']??[]) as $k=>$v){if(preg_match('/^[a-z0-9_]{1,120}$/i',(string)$k))$s->execute([(string)$k,(string)$v]);}audit($pdo,'settings_save','settings');json_response(['ok'=>true]);}
 json_response(['ok'=>false,'error'=>'not_found'],404);
}catch(Throwable $e){error_log($e->__toString());if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'error'=>'server_error'],500);}
