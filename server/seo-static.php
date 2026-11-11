<?php
declare(strict_types=1);
require __DIR__.'/seo.php';
$path=basename((string)parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH));if($path==='')$path='index.html';
$pages=seo_pages();if(!isset($pages[$path])){http_response_code(404);exit;}
[$title,$description]=$pages[$path];$html=file_get_contents(__DIR__.'/../'.$path);
$c=seo_config();$schema=[];
if($path==='index.html'&&$c['base']!==''){$schema=['@context'=>'https://schema.org','@type'=>'SportingGoodsStore','name'=>'ПрофиСпорт','url'=>$c['base'].'/index.html','address'=>['@type'=>'PostalAddress','addressLocality'=>'Оренбург','streetAddress'=>'проспект Победы, 79','addressCountry'=>'RU'],'department'=>[['@type'=>'SportingGoodsStore','name'=>'ПрофиСпорт','address'=>['@type'=>'PostalAddress','addressLocality'=>'Оренбург','streetAddress'=>'проспект Победы, 118, строение 2','addressCountry'=>'RU']]]];}
header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-cache');
// Search and picker combinations are useful to people but are not landing pages.
$private=(bool)array_intersect(array_keys($_GET),['cat','q','search','min','max','height','register']);
echo seo_head($html,$title,$description,$path,$private,$schema);
