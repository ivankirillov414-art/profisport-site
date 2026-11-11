<?php
declare(strict_types=1);
require __DIR__.'/seo.php';
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$id){http_response_code(404);header('X-Robots-Tag: noindex');echo seo_shell('<h1>Товар не найден</h1><a href="catalog.php">Открыть каталог</a>');exit;}
try {
    if(!is_file(__DIR__.'/config.php'))throw new RuntimeException('No configuration');
    // Reuse the public price/discount/stock/photo/specification rules, without HTTP requests.
    $saved=$_GET;$_GET=['id'=>(string)$id];ob_start();
    try{require __DIR__.'/../api/catalog.php';$json=ob_get_contents();}finally{ob_end_clean();$_GET=$saved;}
    $data=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(empty($data['ok']))throw new RuntimeException('Catalog unavailable');
    header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-cache');
    $p=$data['items'][0]??null;
    if(!$p){http_response_code(404);header('X-Robots-Tag: noindex');echo seo_shell('<h1>Товар недоступен в каталоге</h1><p>Посмотрите актуальный ассортимент или уточните наличие в магазине.</p><a href="catalog.php">Открыть каталог</a>');exit;}
    http_response_code(200);$inStock=($p['availability']??'')==='in_stock'||($p['stock_status']??'')==='in_stock';$name=(string)$p['name'];$description=seo_text((string)($p['description']??''))?:$name.' в ПрофиСпорт, Оренбург. Характеристики, актуальная цена и самовывоз из магазина.';
    $body='<nav aria-label="Хлебные крошки"><a href="index.html">Главная</a> / <a href="catalog.php">Каталог</a> / '.seo_e($name).'</nav><h1>'.seo_e($name).'</h1><p>'.number_format((float)$p['price_rub'],0,',',' ').' ₽ · '.($inStock?'В наличии':'Наличие уточняется').'</p><p>'.seo_e($description).'</p><dl>';
    foreach(($p['specs']??[]) as $key=>$value)if(is_scalar($value))$body.='<dt>'.seo_e((string)$key).'</dt><dd>'.seo_e((string)$value).'</dd>';
    $body.='</dl><a href="index.html#storeLocation">Адреса магазинов и контакты</a>';
    $html=file_get_contents(__DIR__.'/../product.html');$html=str_replace('<p>Загрузка товара…</p>',$body,$html);
    $c=seo_config();$schema=['@context'=>'https://schema.org','@type'=>'Product','name'=>$name,'description'=>$description];
    if(!empty($p['sku']))$schema['sku']=$p['sku'];if(!empty($p['brand']))$schema['brand']=['@type'=>'Brand','name'=>$p['brand']];
    // Only real source photos; do not pass placeholder graphics as product images.
    $images=[];foreach($p['images']??[] as $image){if(preg_match('~^https?://~i',$image))$images[]=$image;elseif($c['base']!==''&&!str_starts_with($image,'//'))$images[]=$c['base'].'/'.ltrim($image,'/');}if($images)$schema['image']=$images;
    if($c['base']!=='')$schema['offers']=['@type'=>'Offer','url'=>$c['base'].'/product.html?id='.$id,'price'=>(string)$p['price_rub'],'priceCurrency'=>'RUB'];
    if($inStock&&isset($schema['offers']))$schema['offers']['availability']='https://schema.org/InStock';
    echo seo_head($html,$name.' — купить в Оренбурге | ПрофиСпорт',$description,'product.html?id='.$id,false,$schema);
}catch(Throwable $e){error_log('SEO product: '.$e->getMessage());http_response_code(503);header('Content-Type: text/html; charset=utf-8');header('X-Robots-Tag: noindex');header('Retry-After: 300');echo seo_shell('<h1>Каталог временно недоступен</h1><p>Попробуйте открыть страницу позже.</p>');}
