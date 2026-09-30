<?php
declare(strict_types=1);

function cms_access_migrate(): void {
    $db=cms_db();
    $column=$db->query("SHOW COLUMNS FROM ps_cms_users LIKE 'id'")->fetch();
    if(!str_contains($column['Extra'],'auto_increment'))$db->exec('ALTER TABLE ps_cms_users MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT');
    if(!$db->query("SHOW COLUMNS FROM ps_cms_users LIKE 'auth_version'")->fetch()){
        try{$db->exec('ALTER TABLE ps_cms_users ADD auth_version INT UNSIGNED NOT NULL DEFAULT 1');}
        catch(PDOException $e){if(($e->errorInfo[1]??0)!==1060)throw $e;}
    }
    $db->exec("CREATE TABLE IF NOT EXISTS ps_cms_memberships (user_id INT UNSIGNED NOT NULL, site_key VARCHAR(64) NOT NULL, role VARCHAR(20) NOT NULL, PRIMARY KEY(user_id,site_key), FOREIGN KEY(user_id) REFERENCES ps_cms_users(id), FOREIGN KEY(site_key) REFERENCES ps_cms_sites(site_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS ps_cms_access_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, actor VARCHAR(100) NOT NULL, target_id INT UNSIGNED NOT NULL, action VARCHAR(30) NOT NULL, details LONGTEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function cms_owner(): bool {return (int)($_SESSION['user']??0)===1;}
function cms_permissions(string $role): array {
    return ['read'=>in_array($role,['owner','viewer','editor','publisher'],true),
        'edit'=>in_array($role,['owner','editor','publisher'],true),
        'publish'=>in_array($role,['owner','publisher'],true),'manage'=>$role==='owner'];
}
function cms_role(string $site): string {
    if(cms_owner())return 'owner';
    $s=cms_db()->prepare('SELECT role FROM ps_cms_memberships WHERE user_id=? AND site_key=?');$s->execute([$_SESSION['user'],$site]);
    return $s->fetchColumn()?:'';
}
function cms_require_permission(string $permission,?string $site=null): void {
    $role=cms_role($site??cms_site_key());
    if(!(cms_permissions($role)[$permission]??false))cms_reply(['error'=>'Недостаточно прав для этого действия.','code'=>'forbidden'],403);
}
function cms_sites_for_user(): array {
    if(cms_owner())return cms_db()->query("SELECT site_key,name,url,'owner' AS role FROM ps_cms_sites ORDER BY created_at,site_key")->fetchAll();
    $s=cms_db()->prepare('SELECT s.site_key,s.name,s.url,m.role FROM ps_cms_sites s JOIN ps_cms_memberships m ON m.site_key=s.site_key WHERE m.user_id=? ORDER BY s.created_at,s.site_key');$s->execute([$_SESSION['user']]);return $s->fetchAll();
}
function cms_users(): array {
    $users=cms_db()->query('SELECT id,username,active FROM ps_cms_users ORDER BY id')->fetchAll();
    $s=cms_db()->query('SELECT user_id,site_key,role FROM ps_cms_memberships ORDER BY site_key');$members=[];
    foreach($s as $row)$members[(int)$row['user_id']][]=['site_key'=>$row['site_key'],'role'=>$row['role']];
    foreach($users as &$user){$user['id']=(int)$user['id'];$user['active']=(bool)$user['active'];$user['owner']=$user['id']===1;$user['memberships']=$members[$user['id']]??[];}unset($user);
    return $users;
}
function cms_password_valid(mixed $password): void {
    if(!is_string($password)||strlen($password)<12||strlen($password)>72)throw new InvalidArgumentException('Пароль: от 12 до 72 байт.');
}
function cms_memberships_validate(mixed $input): array {
    if(!is_array($input)||!array_is_list($input)||count($input)>100)throw new InvalidArgumentException('Некорректный список сайтов.');
    $out=[];$sites=array_column(cms_sites_for_user(),'site_key');
    foreach($input as $item){
        if(!is_array($item)||!is_string($item['site_key']??null)||!in_array($item['site_key'],$sites,true)||isset($out[$item['site_key']])||!in_array($item['role']??null,['viewer','editor','publisher'],true))throw new InvalidArgumentException('Укажите существующий сайт и роль без повторов.');
        $out[$item['site_key']]=$item['role'];
    }return $out;
}
function cms_manage_user(string $action,array $input,string $actor): array {
    if(!cms_owner())cms_reply(['error'=>'Только владелец может управлять пользователями.'],403);
    $create=$action==='create-user';$id=$create?0:filter_var($input['id']??null,FILTER_VALIDATE_INT);
    if(!$create&&(!$id||$id<=1))throw new InvalidArgumentException('Учётную запись владельца нельзя изменить здесь.');
    $username=$input['username']??null;$password=$input['password']??'';
    if($create&&(!is_string($username)||strlen($username)<3||strlen($username)>100||trim($username)!==$username||preg_match('/[\x00-\x1f\x7f]/',$username)))throw new InvalidArgumentException('Логин: 3–100 байт, без пробелов по краям и управляющих символов.');
    if($create||$password!=='')cms_password_valid($password);
    $memberships=cms_memberships_validate($input['memberships']??null);
    $active=$input['active']??true;if(!is_bool($active))throw new InvalidArgumentException('Некорректное состояние учётной записи.');
    $db=cms_db();$db->beginTransaction();
    try{
        if($create){$s=$db->prepare('INSERT INTO ps_cms_users(username,password_hash,active) VALUES(?,?,?)');$s->execute([$username,password_hash($password,PASSWORD_DEFAULT),(int)$active]);$id=(int)$db->lastInsertId();}
        else{
            $s=$db->prepare('SELECT id FROM ps_cms_users WHERE id=? FOR UPDATE');$s->execute([$id]);if(!$s->fetch())throw new InvalidArgumentException('Пользователь не найден.');
            $db->prepare('UPDATE ps_cms_users SET active=?,auth_version=auth_version+1 WHERE id=?')->execute([(int)$active,$id]);
            if($password!=='')$db->prepare('UPDATE ps_cms_users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
            $db->prepare('DELETE FROM ps_cms_memberships WHERE user_id=?')->execute([$id]);
        }
        $s=$db->prepare('INSERT INTO ps_cms_memberships(user_id,site_key,role) VALUES(?,?,?)');foreach($memberships as $site=>$role)$s->execute([$id,$site,$role]);
        $db->prepare('INSERT INTO ps_cms_access_log(actor,target_id,action,details) VALUES(?,?,?,?)')->execute([$actor,$id,$action,cms_encode(['active'=>$active,'memberships'=>$memberships,'password_changed'=>$password!==''])]);
        $db->commit();return ['ok'=>true,'id'=>$id];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof PDOException&&($e->errorInfo[1]??0)===1062)throw new InvalidArgumentException('Этот логин уже занят.');throw $e;}
}
function cms_change_password(array $input,string $actor): array {
    cms_password_valid($input['password']??null);$old=$input['current_password']??null;
    if(!is_string($old)||strlen($old)>72)throw new InvalidArgumentException('Укажите текущий пароль.');
    $db=cms_db();$db->beginTransaction();
    try{
        $s=$db->prepare('SELECT password_hash,auth_version FROM ps_cms_users WHERE id=? FOR UPDATE');$s->execute([$_SESSION['user']]);$user=$s->fetch();
        if(!$user||!password_verify($old,$user['password_hash']))throw new InvalidArgumentException('Текущий пароль неверен.');
        $db->prepare('UPDATE ps_cms_users SET password_hash=?,auth_version=auth_version+1 WHERE id=?')->execute([password_hash($input['password'],PASSWORD_DEFAULT),$_SESSION['user']]);
        $db->prepare('INSERT INTO ps_cms_access_log(actor,target_id,action,details) VALUES(?,?,?,?)')->execute([$actor,$_SESSION['user'],'change-password','{}']);
        $db->commit();$_SESSION['auth_version']=(int)$user['auth_version']+1;session_regenerate_id(true);return ['ok'=>true];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
