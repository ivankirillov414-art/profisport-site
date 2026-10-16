<?php
declare(strict_types=1);
require __DIR__.'/server/seo.php';
try {
 $pdo=seo_database();$where=seo_active_where();$category=is_string($_GET['category']??null)?trim($_GET['category']):'';
 $page=filter_var($_GET['page']??'1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$page||strlen($category)>2000){http_response_code(404);header('X-Robots-Tag: noindex');exit;}
 $title=$category!==''?$category:'Каталог спортивных товаров';$heading=$title.' в Оренбурге';
 $body='<nav aria-label="Хлебные крошки"><a href="index.html">Главная</a> / <a href="catalog.php">Каталог</a></nav><h1>'.seo_e($heading).'</h1><p>Актуальный ассортимент ПрофиСпорт. Выберите раздел или откройте карточку товара, чтобы посмотреть характеристики и цену.</p><a href="index.html#catalogProducts">Подбор и фильтры каталога</a>';
 if($category===''){$body.='<section><h2>Разделы каталога</h2><ul class="seoCategories">';foreach($pdo->query("SELECT category_path, COUNT(*) AS n FROM products WHERE $where AND category_path<>'' GROUP BY category_path ORDER BY category_path") as $row){$body.='<li><a href="catalog.php?category='.rawurlencode($row['category_path']).'">'.seo_e($row['category_path']).'</a> <small>('.(int)$row['n'].')</small></li>';}$body.='</ul></section>';}
 $filter=$where.($category!==''?' AND category_path=?':'');$args=$category!==''?[$category]:[];$stmt=$pdo->prepare("SELECT COUNT(*) FROM products WHERE $filter");$stmt->execute($args);$count=(int)$stmt->fetchColumn();$pages=max(1,(int)ceil($count/32));
 if($page>$pages||($category!==''&&$count===0)){http_response_code(404);header('X-Robots-Tag: noindex');echo seo_shell('<h1>Раздел не найден</h1><a href="catalog.php">Все категории</a>');exit;}
 $stmt=$pdo->prepare("SELECT id,name,short_description FROM products WHERE $filter ORDER BY sort_order ASC,id ASC LIMIT 32 OFFSET ".(($page-1)*32));$stmt->execute($args);
 $body.='<section><h2>Товары</h2><ul class="seoProducts">';foreach($stmt as $p){$body.='<li><h3><a href="product.html?id='.(int)$p['id'].'">'.seo_e($p['name']).'</a></h3><p>'.seo_e(seo_text((string)$p['short_description'],130)).'</p></li>';}$body.='</ul></section><nav aria-label="Страницы каталога">';
 $query=$category!==''?'category='.rawurlencode($category).'&':'';
 if($page>1)$body.='<a rel="prev" href="catalog.php?'.seo_e($query).'page='.($page-1).'">← Предыдущая</a> ';$body.=' Страница '.$page.' из '.$pages.' ';
 if($page<$pages)$body.='<a rel="next" href="catalog.php?'.seo_e($query).'page='.($page+1).'">Следующая →</a>';$body.='</nav>';
 $path='catalog.php'.($category!==''||$page>1?'?'.($category!==''?'category='.rawurlencode($category):'').($page>1?($category!==''?'&':'').'page='.$page:''):'');
 header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-cache');echo seo_head(seo_shell($body),$heading.($page>1?' — страница '.$page:'').' | ПрофиСпорт','Каталог ПрофиСпорт: '.seo_text($title,110).'. Спортивные товары в наличии, характеристики и самовывоз в Оренбурге.',$path);
}catch(Throwable $e){error_log('SEO catalog: '.$e->getMessage());http_response_code(503);header('X-Robots-Tag: noindex');header('Retry-After: 300');echo seo_shell('<h1>Каталог временно недоступен</h1><p>Попробуйте позже.</p>');}
