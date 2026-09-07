<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
start_secure_session();
$action = $_GET['action'] ?? 'health';

const SETUP_TOKEN_HASH = '80bd038679da7c8b134685ffa8c54c96043f9868e3e0e33b60ca075d7a416b8b';
const SETUP_TOKEN_EXPIRES = 1788980399;

try {
    if ($action === 'health') {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
        json_response(['ok'=>true,'db'=>true,'products'=>$count,'php'=>PHP_VERSION]);
    }

    if ($action === 'setup_status') {
        $s=$pdo->prepare('SELECT id,username,password_hash,force_password_setup FROM admin_users WHERE username=? LIMIT 1');
        $s->execute(['Иван Кириллов 414']); $u=$s->fetch();
        json_response(['ok'=>true,'setup_required'=>!$u || empty($u['password_hash']) || (int)$u['force_password_setup']===1]);
    }

    if ($action === 'setup' && $_SERVER['REQUEST_METHOD']==='POST') {
        $in=input_json();
        $token=(string)($in['setup_token']??'');
        $new=(string)($in['new_password']??'');
        if (time() > SETUP_TOKEN_EXPIRES) json_response(['ok'=>false,'error'=>'setup_token_expired'],403);
        $tokenHash=hash('sha256',$token);
        if ($token==='' || !hash_equals(SETUP_TOKEN_HASH,$tokenHash)) json_response(['ok'=>false,'error'=>'setup_token'],403);
        if (mb_strlen($new)<10) json_response(['ok'=>false,'error'=>'password_too_short'],422);
        $s=$pdo->prepare('SELECT id,password_hash,force_password_setup FROM admin_users WHERE username=? LIMIT 1 FOR UPDATE');
        $pdo->beginTransaction(); $s->execute(['Иван Кириллов 414']); $u=$s->fetch();
        if (!$u || (!empty($u['password_hash']) && (int)$u['force_password_setup']===0)) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'already_configured'],409); }
        $h=password_hash($new,PASSWORD_DEFAULT);
        $s=$pdo->prepare('UPDATE admin_users SET password_hash=?,force_password_setup=0 WHERE id=?'); $s->execute([$h,$u['id']]);
        $pdo->commit(); audit($pdo,'admin_setup','admin_user',(string)$u['id']);
        json_response(['ok'=>true]);
    }

    if ($action === 'login' && $_SERVER['REQUEST_METHOD']==='POST') {
        $in=input_json(); $username=trim((string)($in['username']??'')); $password=(string)($in['password']??'');
        $s=$pdo->prepare('SELECT id,username,password_hash,role,is_active,force_password_setup FROM admin_users WHERE username=? LIMIT 1'); $s->execute([$username]); $u=$s->fetch();
        if (!$u || !(int)$u['is_active'] || empty($u['password_hash']) || !password_verify($password,$u['password_hash'])) json_response(['ok'=>false,'error'=>'invalid_credentials'],401);
        session_regenerate_id(true); $_SESSION['admin']=['id'=>(int)$u['id'],'username'=>$u['username'],'role'=>$u['role']]; $_SESSION['csrf']=bin2hex(random_bytes(24));
        audit($pdo,'login','admin_user',(string)$u['id']);
        json_response(['ok'=>true,'admin'=>$_SESSION['admin'],'csrf'=>$_SESSION['csrf']]);
    }

    if ($action === 'logout' && $_SERVER['REQUEST_METHOD']==='POST') {
        require_admin(); csrf_check(); audit($pdo,'logout'); $_SESSION=[]; session_destroy(); json_response(['ok'=>true]);
    }

    if ($action === 'me') {
        $a=require_admin(); json_response(['ok'=>true,'admin'=>$a,'csrf'=>$_SESSION['csrf']??'']);
    }

    if ($action === 'stats') {
        require_admin();
        $products=(int)$pdo->query('SELECT COUNT(*) FROM products WHERE is_active=1')->fetchColumn();
        $orders=(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='new'")->fetchColumn();
        json_response(['ok'=>true,'products'=>$products,'new_orders'=>$orders]);
    }

    if ($action === 'products' && $_SERVER['REQUEST_METHOD']==='GET') {
        require_admin(); $q=trim((string)($_GET['q']??'')); $page=max(1,(int)($_GET['page']??1)); $limit=40; $offset=($page-1)*$limit;
        if ($q!=='') { $s=$pdo->prepare('SELECT * FROM products WHERE title LIKE ? ORDER BY id DESC LIMIT '.$limit.' OFFSET '.$offset); $s->execute(['%'.$q.'%']); }
        else { $s=$pdo->query('SELECT * FROM products ORDER BY id DESC LIMIT '.$limit.' OFFSET '.$offset); }
        $rows=$s->fetchAll(); foreach($rows as &$r){$r['images']=json_decode($r['images']?:'[]',true)?:[];$r['specs']=json_decode($r['specs']?:'{}',true)?:[];}
        json_response(['ok'=>true,'items'=>$rows,'page'=>$page]);
    }

    if ($action === 'product_save' && $_SERVER['REQUEST_METHOD']==='POST') {
        $a=require_admin(); csrf_check(); $in=input_json(); $id=(int)($in['id']??0);
        if ($id<1) json_response(['ok'=>false,'error'=>'bad_id'],422);
        $s=$pdo->prepare('UPDATE products SET title=?,price_rub=?,old_price_rub=?,availability=?,category_path=?,description=?,is_active=? WHERE id=?');
        $s->execute([trim((string)$in['title']),max(0,(int)$in['price_rub']),($in['old_price_rub']===''||$in['old_price_rub']===null)?null:max(0,(int)$in['old_price_rub']),trim((string)($in['availability']??'unknown')),trim((string)($in['category_path']??'')),(string)($in['description']??''),!empty($in['is_active'])?1:0,$id]);
        audit($pdo,'product_save','product',(string)$id,['title'=>$in['title']??'','price_rub'=>$in['price_rub']??0]);
        json_response(['ok'=>true]);
    }

    if ($action === 'settings' && $_SERVER['REQUEST_METHOD']==='GET') {
        require_admin(); $rows=$pdo->query('SELECT setting_key,setting_value FROM site_settings ORDER BY setting_key')->fetchAll(); $out=[]; foreach($rows as $r)$out[$r['setting_key']]=$r['setting_value']; json_response(['ok'=>true,'settings'=>$out]);
    }

    if ($action === 'settings_save' && $_SERVER['REQUEST_METHOD']==='POST') {
        require_admin(); csrf_check(); $in=input_json(); $s=$pdo->prepare('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        foreach(($in['settings']??[]) as $k=>$v){ if(!preg_match('/^[a-z0-9_]{1,120}$/i',(string)$k))continue; $s->execute([(string)$k,(string)$v]); }
        audit($pdo,'settings_save','settings'); json_response(['ok'=>true]);
    }

    if ($action === 'import_batch' && $_SERVER['REQUEST_METHOD']==='POST') {
        require_admin(); csrf_check(); $in=input_json(); $items=$in['items']??[]; if(!is_array($items)||count($items)>100)json_response(['ok'=>false,'error'=>'bad_batch'],422);
        $s=$pdo->prepare('INSERT INTO products(source_hash,external_url,title,price_rub,old_price_rub,availability,category_path,specs,description,images,is_active) VALUES(?,?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE external_url=VALUES(external_url),title=VALUES(title),price_rub=VALUES(price_rub),old_price_rub=VALUES(old_price_rub),availability=VALUES(availability),category_path=VALUES(category_path),specs=VALUES(specs),description=VALUES(description),images=VALUES(images),is_active=1');
        $n=0; foreach($items as $p){$url=(string)($p['url']??'');$title=trim((string)($p['title']??''));if($title==='')continue;$hash=hash('sha256',$url!==''?$url:$title);$s->execute([$hash,$url,$title,max(0,(int)($p['price_rub']??0)),isset($p['old_price_rub'])?(int)$p['old_price_rub']:null,(string)($p['availability']??'unknown'),is_array($p['category_path']??null)?implode(' > ',$p['category_path']):(string)($p['category_path']??''),json_encode($p['specs']??[],JSON_UNESCAPED_UNICODE),$p['description']??'',json_encode($p['images']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$n++;}
        audit($pdo,'import_batch','product',null,['count'=>$n]); json_response(['ok'=>true,'imported'=>$n]);
    }

    json_response(['ok'=>false,'error'=>'not_found'],404);
} catch (Throwable $e) {
    error_log($e->__toString());
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok'=>false,'error'=>'server_error'],500);
}
