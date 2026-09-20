<?php
declare(strict_types=1);
require __DIR__.'/private/core.php';
$error='';$done=false;
try {
    $config=cms_config();
    if(strlen($config['install_token']??'')<32){http_response_code(404);exit('Установка закрыта.');}
    try{if(cms_db()->query('SELECT COUNT(*) FROM ps_cms_users')->fetchColumn()>0){http_response_code(403);exit('CMS уже установлена. Войдите через главную страницу.');}}catch(PDOException $e){if(($e->errorInfo[1]??0)!==1146)throw $e;}
    cms_session();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))||!hash_equals($config['install_token'],(string)($_POST['token']??'')))throw new InvalidArgumentException('Неверный код установки или срок формы истёк.');
        cms_install((string)($_POST['username']??''),(string)($_POST['password']??''));cms_migrate();$done=true;
    }
}catch(InvalidArgumentException $e){$error=$e->getMessage();}
catch(Throwable $e){error_log('CMS install: '.$e->getMessage());$error='Проверьте конфигурацию CMS и доступ к базе данных.';}
function h(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Установка CMS</title><link rel="stylesheet" href="visual.css"><div class="login"><h1>Установка CMS</h1><?php if($done): ?><p>CMS установлена. Удалите install_token из конфигурации.</p><a href="index.html">Войти</a><?php else: ?><p><?=h($error)?></p><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf']??'')?>"><label>Код установки<input name="token" type="password" required autocomplete="off"></label><label>Логин владельца<input name="username" minlength="3" maxlength="100" required autocomplete="username"></label><label>Пароль владельца<input name="password" type="password" minlength="12" maxlength="72" required autocomplete="new-password"></label><button class="primary">Установить</button></form><?php endif; ?></div></html>
