<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate');

$pdo->exec("CREATE TABLE IF NOT EXISTS admin_recovery_tokens (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,admin_user_id INT UNSIGNED NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,expires_at DATETIME NOT NULL,used_at DATETIME NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_exp(expires_at),INDEX idx_admin(admin_user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$msg='';$ok=false;$token=(string)($_GET['token']??$_POST['token']??'');
if($_SERVER['REQUEST_METHOD']==='POST'){
 $p1=(string)($_POST['password']??'');$p2=(string)($_POST['confirm']??'');
 if($token===''||strlen($token)>200)$msg='Ссылка сброса недействительна.';
 elseif(mb_strlen($p1)<10)$msg='Пароль должен содержать минимум 10 символов.';
 elseif($p1!==$p2)$msg='Пароли не совпадают.';
 else{
  $hash=hash('sha256',$token);
  $pdo->beginTransaction();
  try{
   $s=$pdo->prepare("SELECT t.id token_id,u.id user_id FROM admin_recovery_tokens t JOIN admin_users u ON u.id=t.admin_user_id WHERE t.token_hash=? AND t.used_at IS NULL AND t.expires_at>NOW() AND u.username=? AND u.is_active=1 LIMIT 1 FOR UPDATE");
   $s->execute([$hash,'Иван Кириллов 414']);$r=$s->fetch();
   if(!$r){$pdo->rollBack();$msg='Ссылка сброса недействительна или уже использована.';}
   else{
    $s=$pdo->prepare('UPDATE admin_users SET password_hash=?,force_password_setup=0 WHERE id=?');$s->execute([password_hash($p1,PASSWORD_DEFAULT),$r['user_id']]);
    $s=$pdo->prepare('UPDATE admin_recovery_tokens SET used_at=NOW() WHERE admin_user_id=? AND used_at IS NULL');$s->execute([$r['user_id']]);
    $pdo->commit();$_SESSION=[];session_regenerate_id(true);$ok=true;$msg='Пароль изменён. Теперь войди с новым паролем.';
   }
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());$msg='Не удалось изменить пароль.';}
 }
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>ПрофиСпорт — новый пароль</title><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:18px;background:linear-gradient(145deg,#0c121c,#121b29 55%,#0b111a);font:16px system-ui,-apple-system,sans-serif;color:#fff}.c{width:min(460px,100%);padding:26px;border:1px solid #ffffff18;border-radius:28px;background:#ffffff0c;box-shadow:0 28px 80px #0008}.brand{font-size:22px;font-weight:850}.m{display:inline-grid;place-items:center;width:38px;height:38px;margin-right:10px;border-radius:11px;background:#f2c94c;color:#151515}h1{font-size:31px;margin:28px 0 8px}p{color:#aab3c1}input{width:100%;height:58px;margin:8px 0;padding:0 17px;border:1px solid #ffffff22;border-radius:16px;background:#ffffff0d;color:#fff;font:inherit}button,a{display:block;width:100%;margin-top:12px;padding:17px;border:0;border-radius:16px;background:#f2c94c;color:#151515;text-align:center;text-decoration:none;font-weight:850;font:inherit}.err{color:#ff8b83}.ok{color:#78dc91}</style></head><body><section class="c"><div class="brand"><span class="m">П</span>ПрофиСпорт</div><h1>Новый пароль</h1><?php if($msg):?><p class="<?=$ok?'ok':'err'?>"><?=htmlspecialchars($msg,ENT_QUOTES,'UTF-8')?></p><?php endif;?><?php if($ok):?><a href="./">Перейти ко входу</a><?php else:?><p>Задай новый пароль для владельца «Иван Кириллов 414».</p><form method="post" autocomplete="off"><input type="hidden" name="token" value="<?=htmlspecialchars($token,ENT_QUOTES,'UTF-8')?>"><input type="password" name="password" autocomplete="new-password" minlength="10" required placeholder="Новый пароль, минимум 10 символов"><input type="password" name="confirm" autocomplete="new-password" minlength="10" required placeholder="Повтори новый пароль"><button type="submit">Сохранить новый пароль</button></form><?php endif;?></section></body></html>