<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try{
 $admin=require_admin();csrf_check();
 if(($admin['username']??'')!=='Иван Кириллов 414')json_response(['ok'=>false,'error'=>'forbidden'],403);
 $pdo->exec("CREATE TABLE IF NOT EXISTS admin_recovery_tokens (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,admin_user_id INT UNSIGNED NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,expires_at DATETIME NOT NULL,used_at DATETIME NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_exp(expires_at),INDEX idx_admin(admin_user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);
 $pdo->beginTransaction();
 $s=$pdo->prepare('UPDATE admin_recovery_tokens SET used_at=NOW() WHERE admin_user_id=? AND used_at IS NULL');$s->execute([(int)$admin['id']]);
 $s=$pdo->prepare('INSERT INTO admin_recovery_tokens(admin_user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))');$s->execute([(int)$admin['id'],$hash]);
 $pdo->commit();audit($pdo,'password_recovery_issued','admin_user',(string)$admin['id']);
 json_response(['ok'=>true,'path'=>'../admin/recovery.php?token='.rawurlencode($token),'expires_minutes'=>30]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());json_response(['ok'=>false,'error'=>'server_error'],500);}
