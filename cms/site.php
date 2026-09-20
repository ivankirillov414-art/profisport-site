<?php
declare(strict_types=1);
require __DIR__.'/private/core.php';
$site=(string)($_GET['site']??'');$page=(string)($_GET['page']??'index.html');
if(!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$site)||!preg_match('/^[a-z0-9][a-z0-9-]{0,90}\.html$/D',$page)){http_response_code(404);exit('Страница не найдена.');}
$preview=($_GET['cms-preview']??'')==='1';$title='Предпросмотр';$pages=[];
try {
    cms_select_site($site);$row=cms_document();$data=json_decode($row['published']??'{}',true);
    if(!$preview&&!isset($data['pages'][$page])){http_response_code(404);exit('Страница ещё не опубликована.');}
    $title=$data['pages'][$page]['title']??cms_site()['name'];
    if(!$preview)$pages=cms_public($data??['pages'=>[]]);
}catch(Throwable $e){if(!$preview){http_response_code(503);exit('Сайт временно недоступен.');}}
function h(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($title)?></title><link rel="stylesheet" href="site.css"></head><body><nav class="cms-site-nav"><a href="<?=h(cms_site()['url']??'/')?>"><?=h(cms_site()['name']??'Сайт')?></a><?php foreach($pages as $key=>$p): ?><a href="site.php?site=<?=h($site)?>&amp;page=<?=h($key)?>"><?=h($p['title']?:$key)?></a><?php endforeach; ?></nav><main data-cms-root></main><script src="connector.js" data-cms-site="<?=h($site)?>" data-cms-page="<?=h($page)?>" defer></script></body></html>
