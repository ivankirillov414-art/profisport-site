<?php
// One-time bridge owned by the storefront, not a dependency of the CMS.
declare(strict_types=1);
require __DIR__.'/guard.php';
if(($_SESSION['admin']['role']??'')!=='owner'){http_response_code(403);exit('Настройка доступна владельцу магазина.');}
require __DIR__.'/../cms/private/core.php';
if(empty($_SESSION['cms_setup_csrf']))$_SESSION['cms_setup_csrf']=bin2hex(random_bytes(32));
$message='';$installed=false;
try{$installed=(bool)cms_db()->query('SELECT id FROM ps_cms_users WHERE id=1')->fetchColumn();}catch(Throwable $e){}
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals($_SESSION['cms_setup_csrf'],$_POST['csrf']??'')){http_response_code(403);exit('Обновите страницу.');}
    try{
        $username=trim((string)($_POST['username']??''));$password=(string)($_POST['password']??'');$confirm=(string)($_POST['confirm_password']??'');
        if($installed){
            if(strlen($username)<3||strlen($username)>100||strlen($password)<12||strlen($password)>72)throw new InvalidArgumentException('Логин: 3–100 символов. Новый пароль: 12–72 символа.');
            if(!hash_equals($password,$confirm))throw new InvalidArgumentException('Пароли не совпадают.');
            $s=cms_db()->prepare('UPDATE ps_cms_users SET username=?, password_hash=?, active=1 WHERE id=1');$s->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);
            if($s->rowCount()!==1)throw new RuntimeException('owner_missing');
            audit($pdo,'cms_owner_recovered','cms_user','1');$message='Доступ к ID Studio восстановлен. Войдите с новым логином и паролем.';
        }else {cms_install($username,$password);$installed=true;$message='CMS настроена. Войдите с новым логином и паролем.';}
    }
    catch(Throwable $e){error_log('CMS setup: '.$e->getMessage());$message=$e instanceof InvalidArgumentException?$e->getMessage():'Не удалось настроить CMS. Проверьте конфигурацию или наличие созданного владельца.';}
}
function cms_h(string $s):string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Подключение CMS</title><link rel="stylesheet" href="../cms/style.css"></head><body><main class="login"><h1>Отдельная CMS</h1><p><?=cms_h($message)?></p><?php if($installed):?><p>Войти в ID Studio не получается? Здесь владелец магазина может безопасно задать новые данные доступа. Содержимое, черновики и публикации не изменятся.</p><form method="post"><input type="hidden" name="csrf" value="<?=cms_h($_SESSION['cms_setup_csrf'])?>"><label>Логин ID Studio<input name="username" value="Иван Кириллов 414" minlength="3" maxlength="100" autocomplete="username" required></label><label>Новый пароль ID Studio<input name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label><label>Повторите новый пароль<input name="confirm_password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label><button>Восстановить доступ к ID Studio</button></form><p><a href="../cms/">Открыть CMS →</a></p><?php else:?><p>Создайте владельца CMS. Этот пароль используется отдельно от входа в магазин.</p><form method="post"><input type="hidden" name="csrf" value="<?=cms_h($_SESSION['cms_setup_csrf'])?>"><label>Логин<input name="username" minlength="3" maxlength="100" autocomplete="username" required></label><label>Новый пароль<input name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label><button>Создать CMS</button></form><?php endif;?></main></body></html>
