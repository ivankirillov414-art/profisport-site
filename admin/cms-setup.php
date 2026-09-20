<?php
// One-time bridge owned by the storefront, not a dependency of the CMS.
declare(strict_types=1);
require __DIR__.'/guard.php';
if(($_SESSION['admin']['role']??'')!=='owner'){http_response_code(403);exit('Настройка доступна владельцу магазина.');}
require __DIR__.'/../cms/private/core.php';
if(empty($_SESSION['cms_setup_csrf']))$_SESSION['cms_setup_csrf']=bin2hex(random_bytes(32));
$message='';$installed=false;
try{$installed=(bool)cms_db()->query('SELECT id FROM ps_cms_users WHERE id=1')->fetchColumn();}catch(Throwable $e){}
if($_SERVER['REQUEST_METHOD']==='POST'&&!$installed){
    if(!hash_equals($_SESSION['cms_setup_csrf'],$_POST['csrf']??'')){http_response_code(403);exit('Обновите страницу.');}
    try{cms_install(trim((string)($_POST['username']??'')),(string)($_POST['password']??''));$installed=true;$message='CMS настроена. Войдите с новым логином и паролем.';}
    catch(Throwable $e){error_log('CMS setup: '.$e->getMessage());$message=$e instanceof InvalidArgumentException?$e->getMessage():'Не удалось настроить CMS. Проверьте конфигурацию или наличие созданного владельца.';}
}
function cms_h(string $s):string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Подключение CMS</title><link rel="stylesheet" href="../cms/style.css"></head><body><main class="login"><h1>Отдельная CMS</h1><p><?=cms_h($message)?></p><?php if($installed):?><p>Настройка завершена. CMS использует отдельную учётную запись.</p><a href="../cms/">Открыть CMS →</a><?php else:?><p>Создайте владельца CMS. Этот пароль используется отдельно от входа в магазин.</p><form method="post"><input type="hidden" name="csrf" value="<?=cms_h($_SESSION['cms_setup_csrf'])?>"><label>Логин<input name="username" minlength="3" maxlength="100" autocomplete="username" required></label><label>Новый пароль<input name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label><button>Создать CMS</button></form><?php endif;?></main></body></html>
