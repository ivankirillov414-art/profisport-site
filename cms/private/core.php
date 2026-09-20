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
function cms_site_key(): string {
    $key=$GLOBALS['cms_site_key']??cms_config()['site_key'];
    if(!is_string($key)||!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$key))throw new InvalidArgumentException('Неверный идентификатор сайта.');
    return $key;
}
function cms_manifest(): array {
    if(isset($GLOBALS['cms_site_manifest']))return $GLOBALS['cms_site_manifest'];
    return json_decode(file_get_contents(__DIR__.'/bindings.json'),true,512,JSON_THROW_ON_ERROR);
}
function cms_site(): array {
    return $GLOBALS['cms_site']??['site_key'=>cms_config()['site_key'],'name'=>'ProfiSport','url'=>cms_config()['site_url']];
}
function cms_migrate(): void {
    $db=cms_db();
    $db->exec("CREATE TABLE IF NOT EXISTS ps_cms_sites (site_key VARCHAR(64) PRIMARY KEY, name VARCHAR(160) NOT NULL, url VARCHAR(2048) NOT NULL, manifest LONGTEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS ps_cms_media (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, site_key VARCHAR(64) NOT NULL, filename VARCHAR(80) NOT NULL, name VARCHAR(200) NOT NULL, width INT NOT NULL, height INT NOT NULL, bytes INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(site_key,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $original=json_decode(file_get_contents(__DIR__.'/bindings.json'),true,512,JSON_THROW_ON_ERROR);
    $db->prepare('INSERT IGNORE INTO ps_cms_sites(site_key,name,url,manifest) VALUES(?,?,?,?)')->execute([cms_config()['site_key'],cms_config()['site_name']??'ProfiSport',cms_config()['site_url'],cms_encode($original)]);
}
function cms_select_site(string $key): void {
    $GLOBALS['cms_site_key']=$key;cms_site_key();
    $s=cms_db()->prepare('SELECT * FROM ps_cms_sites WHERE site_key=?');$s->execute([$key]);$site=$s->fetch();
    if(!$site)throw new InvalidArgumentException('Сайт не найден.');
    $GLOBALS['cms_site']=$site;
    $GLOBALS['cms_site_manifest']=json_decode($site['manifest'],true,512,JSON_THROW_ON_ERROR);
}
function cms_create_site(array $input,string $actor): array {
    $key=$input['key']??'';$name=trim($input['name']??'');$url=$input['url']??'';
    if(!preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/D',$key)||strlen($name)<1||strlen($name)>160||!str_starts_with($url,'https://')||!cms_url($url)||strlen($url)>2048)throw new InvalidArgumentException('Укажите название, код латиницей и HTTPS-адрес сайта.');
    $manifest=['site'=>$key,'pages'=>[]];$draft=['pages'=>['index.html'=>['title'=>'Главная','fields'=>[], 'blocks'=>[], 'layout'=>[]]]];
    $db=cms_db();$db->beginTransaction();
    try {
        $s=$db->prepare('SELECT site_key FROM ps_cms_sites WHERE site_key=?');$s->execute([$key]);if($s->fetch())throw new InvalidArgumentException('Этот код сайта уже занят.');
        $db->prepare('INSERT INTO ps_cms_sites(site_key,name,url,manifest) VALUES(?,?,?,?)')->execute([$key,$name,rtrim($url,'/').'/',cms_encode($manifest)]);
        $db->prepare('INSERT INTO ps_cms_documents(site_key,draft) VALUES(?,?)')->execute([$key,cms_encode($draft)]);
        $db->prepare('INSERT INTO ps_cms_history(site_key,actor,action,content) VALUES(?,?,?,?)')->execute([$key,$actor,'install',cms_encode($draft)]);
        $db->commit();return ['ok'=>true,'site_key'=>$key];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function cms_initial(): array {
    $data=['pages'=>[]];
    foreach(cms_manifest()['pages'] as $key=>$page) {
        $data['pages'][$key]=['fields'=>[], 'blocks'=>[]];
        foreach($page['fields'] as $field) $data['pages'][$key]['fields'][$field['id']]=$field['value'];
        foreach($page['blocks'] as $block) $data['pages'][$key]['blocks'][]=['id'=>$block['id'],'visible'=>$block['visible']];
    }
    if(!$data['pages'])$data['pages']['index.html']=['title'=>'Главная','fields'=>[],'blocks'=>[],'layout'=>[]];
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
    session_set_cookie_params(['lifetime'=>0,'path'=>rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/cms/api.php')),'/').'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);session_start();
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
    if(!is_array($input['pages']??null)||count($input['pages'])>100||array_diff(array_keys($manifest['pages']),array_keys($input['pages'])))throw new InvalidArgumentException('Отсутствуют страницы сайта или превышен лимит 100 страниц.');
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
    foreach($input['pages'] as $key=>$incoming) {
        if(!is_string($key)||!preg_match('/^[a-z0-9][a-z0-9-]{0,90}\.html$/D',$key))throw new InvalidArgumentException('Адрес страницы: латинские буквы, цифры и дефисы.');
        if(!isset($manifest['pages'][$key])) {
            if(!is_string($incoming['title']??null)||strlen(trim($incoming['title']))<1||strlen($incoming['title'])>200)throw new InvalidArgumentException('Укажите название страницы.');
            $clean['pages'][$key]=['title'=>$incoming['title'],'fields'=>[], 'blocks'=>[]];
        }
        if(isset($incoming['layout']))$clean['pages'][$key]['layout']=cms_layout($incoming['layout'],$key);
    }
    if(isset($input['library'])) {
        if(!is_array($input['library'])||count($input['library'])>50)throw new InvalidArgumentException('Допускается до 50 сохранённых блоков.');
        $clean['library']=[];
        foreach($input['library'] as $item) {
            if(!is_string($item['name']??null)||strlen($item['name'])>200||trim($item['name'])==='')throw new InvalidArgumentException('Укажите название сохранённого блока.');
            $clean['library'][]=['name'=>$item['name'],'block'=>cms_layout([$item['block']??[]],'__pattern__')[0]];
        }
    }
    return $clean;
}
function cms_document(bool $lock=false): array {
    $s=cms_db()->prepare('SELECT * FROM ps_cms_documents WHERE site_key=?'.($lock?' FOR UPDATE':''));$s->execute([cms_site_key()]);
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
            $s=$db->prepare('SELECT content FROM ps_cms_history WHERE site_key=? AND id=?');$s->execute([cms_site_key(),$restore]);$old=$s->fetchColumn();if(!$old)throw new InvalidArgumentException('Версия не найдена.');$draft=cms_encode(cms_validate(json_decode($old,true,512,JSON_THROW_ON_ERROR)));
        } else throw new InvalidArgumentException('Неизвестное действие.');
        $db->prepare('INSERT INTO ps_cms_history(site_key,actor,action,content) VALUES(?,?,?,?)')->execute([cms_site_key(),$actor,$action,$draft]);
        $db->prepare('UPDATE ps_cms_documents SET draft=?,published=?,version=version+1,published_version=? WHERE site_key=?')->execute([$draft,$published,$pubversion,cms_site_key()]);
        $db->commit();return ['ok'=>true,'version'=>$version+1,'published_version'=>$pubversion,'draft'=>json_decode($draft,true)];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function cms_layout(array $layout,string $page): array {
    if(count($layout)>100)throw new InvalidArgumentException('На странице допускается до 100 блоков.');
    $templates=cms_templates();$known=array_column($templates[$page]['sections']??[],'id');$seen=[];$out=[];
    foreach($layout as $block) {
        $id=$block['id']??'';$type=$block['type']??'';
        if(!is_string($id)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$id)||isset($seen[$id]))throw new InvalidArgumentException('Неверный или повторяющийся блок.');
        $seen[$id]=true;
        if($type==='existing') {
            if(!in_array($id,$known,true)||!is_bool($block['visible']??null))throw new InvalidArgumentException('Неизвестный блок сайта.');
            $out[]=['id'=>$id,'type'=>'existing','visible'=>$block['visible']];continue;
        }
        if(!in_array($type,['hero','text','image','columns','cta','contacts','spacer','gallery','cards','faq','pricing','testimonials','metrics','split'],true))throw new InvalidArgumentException('Неизвестный тип блока.');
        $props=[];
        foreach(['title','text','text2','image','alt','label','url','background','color','align','space','mobileSpace','visibility','columns','radius','accent'] as $key) {
            $v=$block['props'][$key]??'';
            if(!is_string($v)||strlen($v)>12000)throw new InvalidArgumentException('Неверные свойства блока.');
            if(in_array($key,['image','url'],true)&&!cms_url($v,$key==='image'))throw new InvalidArgumentException('Небезопасная ссылка.');
            if(in_array($key,['background','color','accent'],true)&&$v!==''&&!preg_match('/^#[0-9a-f]{6}$/iD',$v))throw new InvalidArgumentException('Неверный цвет.');
            if($key==='align'&&!in_array($v,['','left','center','right'],true))throw new InvalidArgumentException('Неверное выравнивание.');
            if(in_array($key,['space','mobileSpace'],true)&&!in_array($v,['','24','48','80','120'],true))throw new InvalidArgumentException('Неверный отступ.');
            if($key==='visibility'&&!in_array($v,['','all','desktop','mobile'],true))throw new InvalidArgumentException('Неверная видимость.');
            if($key==='columns'&&!in_array($v,['','2','3','4'],true))throw new InvalidArgumentException('Неверная сетка.');
            if($key==='radius'&&!in_array($v,['','0','8','16','24'],true))throw new InvalidArgumentException('Неверное скругление.');
            $props[$key]=$v;
        }
        if(isset($block['props']['items'])) {
            if(!is_array($block['props']['items'])||count($block['props']['items'])>24)throw new InvalidArgumentException('До 24 элементов в блоке.');
            $props['items']=[];
            foreach($block['props']['items'] as $item) {
                if(!is_array($item))throw new InvalidArgumentException('Некорректный элемент.');
                $row=[];foreach(['title','text','image','alt','label','url'] as $key) {
                    $v=$item[$key]??'';if(!is_string($v)||strlen($v)>12000)throw new InvalidArgumentException('Некорректный элемент.');
                    if(in_array($key,['image','url'],true)&&!cms_url($v,$key==='image'))throw new InvalidArgumentException('Небезопасная ссылка элемента.');
                    $row[$key]=$v;
                }
                $props['items'][]=$row;
            }
        }
        $out[]=['id'=>$id,'type'=>$type,'props'=>$props];
    }
    foreach($known as $id)if(!isset($seen[$id]))throw new InvalidArgumentException('Системные блоки можно скрыть, но нельзя удалить.');
    return $out;
}
function cms_templates(): array {
    if(cms_manifest()['site']!=='profisport')return [];
    $path=__DIR__.'/templates.json';return is_file($path)?json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR):[];
}
function cms_public(array $data): array {
    $manifest=cms_manifest();$templates=cms_templates();$pages=[];
    foreach($data['pages'] as $key=>$draft) {
        $page=$manifest['pages'][$key]??['fields'=>[],'blocks'=>[]];$fields=[];$blocks=[];
        foreach($page['fields'] as $f){$v=$draft['fields'][$f['id']]??$f['value'];if($v!==$f['value'])$fields[]=['selector'=>$f['selector'],'kind'=>$f['kind'],'value'=>$v];}
        foreach($draft['blocks'] as $b)foreach($page['blocks'] as $original)if($b['id']===$original['id'])$blocks[]=['selector'=>$original['selector'],'visible'=>$b['visible'],'changed'=>$b['visible']!==$original['visible']];
        $pages[$key]=['title'=>$draft['title']??$page['title']??'','fields'=>$fields,'blocks'=>$blocks];
        if(isset($draft['layout'])) {
            $pages[$key]['layout']=$draft['layout'];
            $pages[$key]['sections']=array_map(fn($s)=>['id'=>$s['id'],'selector'=>$s['selector']],$templates[$key]['sections']??[]);
        }
    }
    return $pages;
}
