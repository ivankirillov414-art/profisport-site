<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
header('Referrer-Policy: no-referrer');header('Cache-Control: no-store');
const BOOT_HASH='f6d3e916099398fecf160e423e4bb0a76251e06c73c56b57a32d59521ba30979';
$boot=(string)($_GET['key']??'');
if($boot===''||!hash_equals(BOOT_HASH,hash('sha256',$boot))){http_response_code(403);exit('Недействительная ссылка восстановления.');}
$pdo->exec("CREATE TABLE IF NOT EXISTS admin_recovery_tokens (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,admin_user_id INT UNSIGNED NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,expires_at DATETIME NOT NULL,used_at DATETIME NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_exp(expires_at),INDEX idx_admin(admin_user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->beginTransaction();
try{
 $s=$pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key='owner_recovery_bootstrap_used' LIMIT 1 FOR UPDATE");$s->execute();if($s->fetchColumn()){ $pdo->rollBack();http_response_code(410);exit('Эта ссылка восстановления уже использована.');}
 $s=$pdo->prepare('SELECT id FROM admin_users WHERE username=? AND is_active=1 LIMIT 1 FOR UPDATE');$s->execute(['Иван Кириллов 414']);$u=$s->fetch();if(!$u)throw new RuntimeException('owner_missing');
 $raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);
 $s=$pdo->prepare('UPDATE admin_recovery_tokens SET used_at=NOW() WHERE admin_user_id=? AND used_at IS NULL');$s->execute([(int)$u['id']]);
 $s=$pdo->prepare('INSERT INTO admin_recovery_tokens(admin_user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))');$s->execute([(int)$u['id'],$hash]);
 $s=$pdo->prepare("INSERT INTO site_settings(setting_key,setting_value) VALUES('owner_recovery_bootstrap_used',NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");$s->execute();$pdo->commit();
 header('Location: ./recovery.php?token='.rawurlencode($raw),true,303);exit;
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());http_response_code(500);exit('Не удалось запустить восстановление.');}
