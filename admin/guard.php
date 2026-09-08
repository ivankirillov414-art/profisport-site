<?php
declare(strict_types=1);
require_once __DIR__.'/../server/bootstrap.php';
start_secure_session();
header('Cache-Control: no-store');
if(empty($_SESSION['admin'])){header('Location: login.php',true,303);exit;}
$s=$pdo->prepare('SELECT id FROM admin_users WHERE id=? AND is_active=1');
$s->execute([(int)$_SESSION['admin']['id']]);
if(!$s->fetchColumn()){$_SESSION=[];header('Location: login.php',true,303);exit;}
