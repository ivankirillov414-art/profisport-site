<?php
declare(strict_types=1);
require __DIR__.'/private/core.php';
$site=(string)($_GET['site']??'');$page=(string)($_GET['page']??'index.html');
if(!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$site)||!preg_match('/^[a-z0-9][a-z0-9-]{0,90}\.html$/D',$page)){http_response_code(404);exit('Страница не найдена.');}
$preview=($_GET['cms-preview']??'')==='1';$title='Предпросмотр';$pages=[];$seo=['title'=>$title,'description'=>'','canonical'=>'','image'=>'','noindex'=>true];
try {
    cms_select_site($site);$row=cms_document();$data=json_decode($row['published']??'{}',true);
    if(!$preview)$pages=cms_public($data??['pages'=>[]]);
    if(!$preview&&!isset($pages[$page])){http_response_code(404);exit('Страница ещё не опубликована.');}
    $title=$pages[$page]['title']??cms_site()['name'];
    $seo=$preview?['title'=>'Предпросмотр','description'=>'','canonical'=>'','image'=>'','noindex'=>true]:cms_seo_metadata($page,$pages[$page]);$title=$seo['title'];
}catch(Throwable $e){if(!$preview){http_response_code(503);exit('Сайт временно недоступен.');}}
function h(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
if($preview)header('X-Robots-Tag: noindex, nofollow');
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($title)?></title><meta name="description" content="<?=h($seo['description']??'')?>"><meta name="robots" content="<?=!empty($seo['noindex'])?'noindex, nofollow':'index, follow'?>"><?php if(!empty($seo['canonical'])): ?><link rel="canonical" href="<?=h($seo['canonical'])?>"><meta property="og:url" content="<?=h($seo['canonical'])?>"><?php endif; ?><meta property="og:type" content="website"><meta property="og:title" content="<?=h($title)?>"><meta property="og:description" content="<?=h($seo['description']??'')?>"><?php if(!empty($seo['image'])): ?><meta property="og:image" content="<?=h($seo['image'])?>"><?php endif; ?><link rel="stylesheet" href="site.css"></head><body><nav class="cms-site-nav"><a href="<?=h(cms_site()['url']??'/')?>"><?=h(cms_site()['name']??'Сайт')?></a><?php foreach($pages as $key=>$p): if(!empty($p['collection']))continue; ?><a href="site.php?site=<?=h($site)?>&amp;page=<?=h($key)?>"><?=h($p['title']?:$key)?></a><?php endforeach; ?></nav><main data-cms-root></main><script src="connector.js" data-cms-site="<?=h($site)?>" data-cms-page="<?=h($page)?>" defer></script></body></html>
