<?php
declare(strict_types=1);
function seo_config(): array {
    $file=__DIR__.'/config.php'; $c=is_file($file)?require $file:[];
    return seo_settings($c);
}
function seo_settings(array $c): array {
    $base=rtrim((string)($c['seo_public_url']??''),'/');
    if($base!=='' && (!filter_var($base,FILTER_VALIDATE_URL)||parse_url($base,PHP_URL_SCHEME)!=='https'||parse_url($base,PHP_URL_USER)!==null||parse_url($base,PHP_URL_QUERY)!==null||parse_url($base,PHP_URL_FRAGMENT)!==null||!in_array(parse_url($base,PHP_URL_PATH),[null,'','/'],true)))throw new RuntimeException('seo_public_url must be an HTTPS origin');
    return ['base'=>$base,'index'=>($c['seo_indexing']??false)===true && $base!==''];
}
function seo_e(string $s): string {return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function seo_text(string $s,int $max=180): string {$s=trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($s),ENT_QUOTES|ENT_HTML5,'UTF-8'))??'');return mb_strlen($s)>$max?mb_substr($s,0,$max-1).'…':$s;}
function seo_json(array $data): string {return '<script type="application/ld+json">'.json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).'</script>';}
function seo_pages(): array {return [
 'index.html'=>['Велосипеды и спортивные товары в Оренбурге — ПрофиСпорт','Велосипеды, самокаты, ролики, лыжи и спортивная экипировка. Подбор техники и веломастерская ПрофиСпорт в Оренбурге.'],
 'shop.html'=>['Магазин спортивных товаров в Оренбурге — ПрофиСпорт','Каталог велосипедов, самокатов и спортивного снаряжения. Выбор товаров, подбор велосипеда и магазины ПрофиСпорт в Оренбурге.'],
 'workshop.html'=>['Веломастерская в Оренбурге — ПрофиСпорт','Ремонт и обслуживание велосипедов в Оренбурге. Услуги мастерской, подготовка к сезону и контакты ПрофиСпорт на проспекте Победы, 79.'],
 'service.html'=>['Ремонт и обслуживание велосипедов в Оренбурге — ПрофиСпорт','Диагностика, настройка и ремонт велосипедов в ПрофиСпорт. Посмотрите прайс и порядок обращения в мастерскую на проспекте Победы, 79.'],
 'buyer-info.html'=>['Оплата, доставка и возврат товаров — ПрофиСпорт, Оренбург','Информация покупателям ПрофиСпорт: оплата, доставка, гарантия, возврат товаров и адреса магазинов в Оренбурге.'],
 ];}
function seo_head(string $html,string $title,string $description,string $path,bool $private=false,array $schema=[],?array $settings=null): string {
    $c=$settings??seo_config();$html=preg_replace_callback('~<title>.*?</title>~is',fn()=>'<title>'.seo_e($title).'</title>',$html,1);
    $html=preg_replace('~<meta\b[^>]*name=["\x27](?:description|robots)["\x27][^>]*>~i','',$html);
    $html=preg_replace('~<link\b[^>]*rel=["\x27]canonical["\x27][^>]*>~i','',$html);
    $tags='<meta name="description" content="'.seo_e($description).'"><meta name="robots" content="'.(!$c['index']||$private?'noindex, follow':'index, follow, max-image-preview:large').'">';
    $tags.='<meta property="og:locale" content="ru_RU"><meta property="og:site_name" content="ПрофиСпорт"><meta property="og:type" content="website"><meta property="og:title" content="'.seo_e($title).'"><meta property="og:description" content="'.seo_e($description).'">';
    if($c['base']!==''){$url=$c['base'].'/'.$path;$tags.='<link rel="canonical" href="'.seo_e($url).'"><meta property="og:url" content="'.seo_e($url).'">';}
    if($schema)$tags.=seo_json($schema);
    return str_replace('</head>',$tags.'</head>',$html);
}
function seo_database(): PDO {
    $c=require __DIR__.'/config.php';return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$c['db_host'],(int)($c['db_port']??3306),$c['db_name']),$c['db_user'],$c['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function seo_active_where(): string {return "is_active=1 AND COALESCE(stock_qty,0)>0 AND COALESCE(stock_status,'unknown')<>'out_of_stock' AND COALESCE(availability,'unknown')<>'out_of_stock' AND COALESCE(price_rub,0)>0";}
function seo_shell(string $body): string {return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ПрофиСпорт</title><link rel="stylesheet" href="seo-catalog.css?v=1"></head><body><header><a href="index.html">ПрофиСпорт</a><nav><a href="catalog.php">Каталог</a><a href="shop.html">Магазин</a><a href="workshop.html">Мастерская</a></nav></header><main>'.$body.'</main><footer><a href="buyer-info.html">Оплата, доставка и возврат</a> · <a href="index.html#storeLocation">Магазины в Оренбурге</a></footer></body></html>';}
