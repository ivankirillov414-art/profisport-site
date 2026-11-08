<?php
declare(strict_types=1);
$root=dirname(__DIR__);
try { if ((getenv('KCRM_CONFIG') || is_file($root.'/config/config.php')) && is_file($root.'/app/bootstrap.php')) require_once $root.'/app/bootstrap.php'; } catch (Throwable) {}
require_once $root.'/app/website/content.php';
require_once $root.'/app/website/funnel.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') {http_response_code(405);header('Allow: GET');exit;}
$target=kp_checkout_target(kp_read_published()['content']);
if ($target!==null) {header('Location: '.$target,true,303);exit;}
http_response_code(503);header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Продажа билетов — Космопорт</title><link rel="stylesheet" href="site/styles.css"><body><main class="container section"><h1>Продажи пока закрыты</h1><p>Платёжная страница ещё не подключена. Деньги не списаны, билет не оформлен.</p><p><a class="button" href="./#tickets">Вернуться к билетам</a></p></main></body></html>
