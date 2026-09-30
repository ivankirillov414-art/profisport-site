<?php
declare(strict_types=1);
require __DIR__.'/private/core.php';
try {
    $action=$_GET['action']??'state';
    $method=$_SERVER['REQUEST_METHOD'];
    if($action==='public') {
        if($method!=='GET')cms_reply(['error'=>'method'],405);
        if(isset($_GET['site']))cms_select_site((string)$_GET['site']);
        header('Access-Control-Allow-Origin: *');
        $row=cms_document();if(!$row['published'])cms_reply(['published'=>false]);
        $data=json_decode($row['published'],true,512,JSON_THROW_ON_ERROR);
        cms_reply(['published'=>true,'revision'=>(int)$row['published_version'],'pages'=>cms_public($data)]);
    }
    cms_session();
    if($action==='session'&&$method==='GET')cms_reply(['csrf'=>$_SESSION['csrf'],'authenticated'=>!empty($_SESSION['user'])]);
    if($action==='login') {
        if($method!=='POST')cms_reply(['error'=>'method'],405);cms_csrf();
        $raw=file_get_contents('php://input',false,null,0,4097);if(strlen($raw)>4096)cms_reply(['error'=>'payload'],413);
        $input=json_decode($raw,true)??[];$db=cms_db();
        // A failed sign-in must not lock every CMS user behind the same office/mobile IP.
        $username=(string)($input['username']??'');
        $subject=hash('sha256',($_SERVER['REMOTE_ADDR']??'unknown').'\n'.$username);$now=time();
        $db->prepare('INSERT IGNORE INTO ps_cms_limits(subject,window_start) VALUES(?,?)')->execute([$subject,$now]);
        $db->beginTransaction();$s=$db->prepare('SELECT * FROM ps_cms_limits WHERE subject=? FOR UPDATE');$s->execute([$subject]);$limit=$s->fetch();
        $attempts=$now-(int)$limit['window_start']>=900?0:(int)$limit['attempts'];
        if($attempts>=8){$db->rollBack();cms_reply(['error'=>'Слишком много попыток. Повторите через 15 минут.'],429);}
        $db->prepare('UPDATE ps_cms_limits SET attempts=?,window_start=? WHERE subject=?')->execute([$attempts+1,$attempts===0?$now:$limit['window_start'],$subject]);$db->commit();
        $s=$db->prepare('SELECT * FROM ps_cms_users WHERE username=? AND active=1');$s->execute([$username]);$user=$s->fetch();
        $hash=$user['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        if(!password_verify((string)($input['password']??''),$hash)||!$user)cms_reply(['error'=>'Неверный логин или пароль.'],401);
        cms_migrate();
        $db->prepare('DELETE FROM ps_cms_limits WHERE subject=?')->execute([$subject]);session_regenerate_id(true);
        $_SESSION=['user'=>$user['id'],'seen'=>$now,'started'=>$now,'auth_version'=>(int)($user['auth_version']??1),'csrf'=>bin2hex(random_bytes(32))];cms_reply(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
    }
    $actor=cms_auth();
    if($action==='sites'&&$method==='GET')cms_reply(['items'=>cms_sites_for_user()]);
    if($action==='logout'&&$method==='POST'){cms_csrf();$_SESSION=[];session_destroy();cms_reply(['ok'=>true]);}
    if(in_array($action,['users','create-user','update-user','create-site'],true)&&!cms_owner())cms_reply(['error'=>'Только владелец может управлять пользователями и сайтами.'],403);
    if($action==='users'&&$method==='GET')cms_reply(['items'=>cms_users(),'sites'=>cms_sites_for_user()]);
    if(in_array($action,['create-user','update-user','create-site','change-password'],true)){
        if($method!=='POST')cms_reply(['error'=>'method'],405);cms_csrf();
        $raw=file_get_contents('php://input',false,null,0,65537);if(strlen($raw)>65536)cms_reply(['error'=>'payload'],413);
        $input=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($input))throw new InvalidArgumentException('Некорректные данные.');
        if($action==='create-site')cms_reply(cms_create_site($input,$actor));
        if($action==='change-password')cms_reply(cms_change_password($input,$actor));
        cms_reply(cms_manage_user($action,$input,$actor));
    }
    $sites=cms_sites_for_user();$default=in_array(cms_config()['site_key'],array_column($sites,'site_key'),true)?cms_config()['site_key']:($sites[0]['site_key']??'');$key=(string)($_GET['site']??$default);
    if($key==='')cms_reply(['error'=>'Владелец ещё не назначил вам доступ к сайту.','code'=>'no_sites'],403);
    cms_require_permission('read',$key);cms_select_site($key);$role=cms_role($key);
    $permission=match($action){'state','history','media'=>'read','save','restore','upload'=>'edit','publish'=>'publish',default=>null};
    if($permission===null)cms_reply(['error'=>'Неизвестное действие.'],422);cms_require_permission($permission,$key);
    if($action==='state'&&$method==='GET'){$row=cms_document();cms_reply(['user'=>$actor,'user_id'=>(int)$_SESSION['user'],'role'=>$role,'permissions'=>cms_permissions($role),'csrf'=>$_SESSION['csrf'],'manifest'=>cms_manifest(),'site_url'=>cms_site()['url'],'site'=>['key'=>cms_site_key(),'name'=>cms_site()['name']],'templates'=>cms_templates(),'draft'=>json_decode($row['draft'],true),'version'=>(int)$row['version'],'published_version'=>(int)$row['published_version']]);}
    if($action==='history'&&$method==='GET'){$s=cms_db()->prepare('SELECT id,actor,action,created_at FROM ps_cms_history WHERE site_key=? ORDER BY id DESC LIMIT 50');$s->execute([cms_site_key()]);cms_reply(['items'=>$s->fetchAll()]);}
    if($action==='media'&&$method==='GET') {
        $q=cms_db()->prepare('SELECT id,filename,name,width,height,bytes,created_at FROM ps_cms_media WHERE site_key=? ORDER BY id DESC LIMIT 500');$q->execute([cms_site_key()]);$items=$q->fetchAll();
        foreach($items as &$item)$item['url']=rtrim(cms_config()['media_url'],'/').'/'.$item['filename'];unset($item);
        cms_reply(['items'=>$items]);
    }
    if($method!=='POST')cms_reply(['error'=>'method'],405);cms_csrf();
    if($action==='upload') {
        $f=$_FILES['file']??null;if(!$f||$f['error']!==UPLOAD_ERR_OK||$f['size']>5*1024*1024)cms_reply(['error'=>'Выберите изображение до 5 МБ.'],422);
        $info=getimagesize($f['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$info['mime']??'']??null;
        if(!$ext||$info[0]>8000||$info[1]>8000)cms_reply(['error'=>'Допустимы JPG, PNG, WebP, до 8000 пикселей.'],422);
        $name=bin2hex(random_bytes(16)).'.'.$ext;if(!move_uploaded_file($f['tmp_name'],__DIR__.'/media/'.$name))throw new RuntimeException('Не удалось сохранить изображение.');
        $label=preg_replace('/[^\pL\pN ._-]/u','_',basename($f['name']))??'image';preg_match('/^.{0,100}/us',$label,$labelParts);$label=$labelParts[0]??'image';
        try {cms_db()->prepare('INSERT INTO ps_cms_media(site_key,filename,name,width,height,bytes) VALUES(?,?,?,?,?,?)')->execute([cms_site_key(),$name,$label,$info[0],$info[1],$f['size']]);}
        catch(Throwable $e){unlink(__DIR__.'/media/'.$name);throw $e;}
        cms_reply(['url'=>rtrim(cms_config()['media_url'],'/').'/'.$name]);
    }
    $raw=file_get_contents('php://input',false,null,0,1048577);if(strlen($raw)>1048576)cms_reply(['error'=>'Слишком большой документ.'],413);
    $input=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($input))throw new InvalidArgumentException('Некорректный документ.');
    cms_reply(cms_change($action,(int)($input['version']??0),$input['draft']??[],$actor,(int)($input['id']??0)));
}catch(InvalidArgumentException|JsonException $e){cms_reply(['error'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('CMS: '.$e->getMessage());cms_reply(['error'=>'CMS недоступна или ещё не настроена. Обратитесь к владельцу.'],503);}
