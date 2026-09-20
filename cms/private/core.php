<?php
declare(strict_types=1);
// Standalone core: never imports the storefront, its session, or its data tables.
function cms_config(): array {
    static $config;
    if ($config === null) {
        if (!is_file(__DIR__.'/config.php')) throw new RuntimeException('CMS ещё не настроена.');
        $config = require __DIR__.'/config.php';
    }
    return $config;
}
function cms_db(): PDO {
    static $db;
    if (!$db) {
        $c = cms_config();
        $db = new PDO('mysql:host='.$c['db_host'].';dbname='.$c['db_name'].';charset=utf8mb4', $c['db_user'], $c['db_pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    }
    return $db;
}
function cms_manifest(): array { return json_decode(file_get_contents(__DIR__.'/bindings.json'),true,512,JSON_THROW_ON_ERROR); }
function cms_initial(): array {
    $data=['pages'=>[]];
    foreach(cms_manifest()['pages'] as $key=>$page) {
        $data['pages'][$key]=['fields'=>[], 'blocks'=>[]];
        foreach($page['fields'] as $field) $data['pages'][$key]['fields'][$field['id']]=$field['value'];
        foreach($page['blocks'] as $block) $data['pages'][$key]['blocks'][]=['id'=>$block['id'],'visible'=>$block['visible']];
    }
    return $data;
}
function cms_install(string $username,string $password): void {
    if(strlen($password)<12 || strlen($password)>72 || strlen($username)<3 || strlen($username)>100) throw new InvalidArgumentException('Логин: 3–100 символов. Пароль: 12–72 байта.');
    $db=cms_db();
    $db->exec("CREATE TABLE IF NOT EXISTS ps_cms_users (id INT UNSIGNED PRIMARY KEY, username VARCHAR(100) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS ps_cms_documents (site_key VARCHAR(64) PRIMARY KEY, draft LONGTEXT NOT NULL, published LONGTEXT NULL, version INT NOT NULL DEFAULT 1, published_version INT NOT NULL DEFAULT 0, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS ps_cms_history (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, site_key VARCHAR(64) NOT NULL, actor VARCHAR(100) NOT NULL, action VARCHAR(30) NOT NULL, content LONGTEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(site_key,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS ps_cms_limits (subject CHAR(64) PRIMARY KEY, attempts INT NOT NULL DEFAULT 0, window_start BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->beginTransaction();
    try {
        // Fixed primary key makes initial owner creation atomic, including simultaneous requests.
        $db->prepare('INSERT INTO ps_cms_users(id,username,password_hash) VALUES(1,?,?)')->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);
        $db->prepare('INSERT INTO ps_cms_documents(site_key,draft) VALUES(?,?)')->execute([cms_config()['site_key'],cms_encode(cms_initial())]);
        $db->prepare('INSERT INTO ps_cms_history(site_key,actor,action,content) VALUES(?,?,?,?)')->execute([cms_config()['site_key'],$username,'install',cms_encode(cms_initial())]);
        $db->commit();
    } catch(Throwable $e) { $db->rollBack(); throw $e; }
}
function cms_encode(array $data): string { return json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
function cms_reply(array $data,int $status=200): never {
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');echo cms_encode($data);exit;
}
function cms_session(): void {
    if(session_status()===PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode','1');
    session_name('INDEPENDENT_CMS');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/cms/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);session_start();
    if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
}
function cms_auth(): string {
    cms_session();
    if(empty($_SESSION['user']) || time()-($_SESSION['seen']??0)>1800 || time()-($_SESSION['started']??0)>28800) cms_reply(['error'=>'Войдите в CMS.'],401);
    $s=cms_db()->prepare('SELECT username FROM ps_cms_users WHERE id=? AND active=1');$s->execute([$_SESSION['user']]);
    $username=$s->fetchColumn();if(!$username)cms_reply(['error'=>'Доступ закрыт.'],401);
    $_SESSION['seen']=time();return $username;
}
function cms_csrf(): void {
    cms_session();
    if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))cms_reply(['error'=>'Обновите страницу и повторите действие.'],403);
}
function cms_url(string $value,bool $image=false): bool {
    if($value==='') return true;
    if(preg_match('/[\x00-\x20\x7f\\\\]/',$value) || str_starts_with($value,'//'))return false;
    if(preg_match('~^https://[^/]+~i',$value)) return filter_var($value,FILTER_VALIDATE_URL)!==false;
    if(!$image && preg_match('~^(mailto:[^\s]+@[^\s]+|tel:\+?[0-9()-]+|#[a-zA-Z0-9_-]+)$~',$value)) return true;
    return !preg_match('~^[a-z][a-z0-9+.-]*:~i',$value) && !str_contains($value,'..') && !str_contains($value,':');
}
function cms_validate(array $input): array {
    $clean=['pages'=>[]];$manifest=cms_manifest();
    if(array_keys($input['pages']??[])!==array_keys($manifest['pages']))throw new InvalidArgumentException('Состав страниц изменился. Обновите редактор.');
    foreach($manifest['pages'] as $key=>$page) {
        $incoming=$input['pages'][$key];$fields=[];
        foreach($page['fields'] as $f) {
            $v=$incoming['fields'][$f['id']]??null;
            if(!is_string($v)||strlen($v)>12000)throw new InvalidArgumentException('Поле отсутствует или слишком длинное.');
            if(in_array($f['kind'],['image','background','link'],true)&&!cms_url($v,$f['kind']!=='link'))throw new InvalidArgumentException('Укажите безопасную ссылку: HTTPS или путь внутри сайта.');
            $fields[$f['id']]=$v;
        }
        $blocks=$incoming['blocks']??[];$ids=array_column($page['blocks'],'id');$got=[];
        foreach($blocks as $b){if(!is_array($b)||!in_array($b['id']??null,$ids,true)||!is_bool($b['visible']??null))throw new InvalidArgumentException('Неверный блок.');$got[]=$b['id'];}
        sort($got);sort($ids);if($got!==$ids)throw new InvalidArgumentException('Состав блоков изменился.');
        $clean['pages'][$key]=['fields'=>$fields,'blocks'=>array_map(fn($b)=>['id'=>$b['id'],'visible'=>$b['visible']],$blocks)];
    }
    return $clean;
}
function cms_document(bool $lock=false): array {
    $s=cms_db()->prepare('SELECT * FROM ps_cms_documents WHERE site_key=?'.($lock?' FOR UPDATE':''));$s->execute([cms_config()['site_key']]);
    $row=$s->fetch();if(!$row)throw new RuntimeException('CMS не инициализирована.');return $row;
}
function cms_change(string $action,int $version,array $input,string $actor,int $restore=0): array {
    $db=cms_db();$db->beginTransaction();
    try {
        $row=cms_document(true);
        if((int)$row['version']!==$version){$db->rollBack();cms_reply(['error'=>'Кто-то уже сохранил изменения. Обновите страницу перед редактированием.'],409);}
        $draft=$row['draft'];$published=$row['published'];$pubversion=(int)$row['published_version'];
        if($action==='save')$draft=cms_encode(cms_validate($input));
        elseif($action==='publish'){cms_validate(json_decode($draft,true,512,JSON_THROW_ON_ERROR));$published=$draft;$pubversion++;}
        elseif($action==='restore'){
            $s=$db->prepare('SELECT content FROM ps_cms_history WHERE site_key=? AND id=?');$s->execute([cms_config()['site_key'],$restore]);$old=$s->fetchColumn();if(!$old)throw new InvalidArgumentException('Версия не найдена.');$draft=cms_encode(cms_validate(json_decode($old,true,512,JSON_THROW_ON_ERROR)));
        } else throw new InvalidArgumentException('Неизвестное действие.');
        $db->prepare('INSERT INTO ps_cms_history(site_key,actor,action,content) VALUES(?,?,?,?)')->execute([cms_config()['site_key'],$actor,$action,$draft]);
        $db->prepare('UPDATE ps_cms_documents SET draft=?,published=?,version=version+1,published_version=? WHERE site_key=?')->execute([$draft,$published,$pubversion,cms_config()['site_key']]);
        $db->commit();return ['ok'=>true,'version'=>$version+1,'published_version'=>$pubversion,'draft'=>json_decode($draft,true)];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
