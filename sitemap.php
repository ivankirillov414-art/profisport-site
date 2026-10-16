<?php
declare(strict_types=1);
require __DIR__.'/server/seo.php';
try {
 $c=seo_config();if(!$c['index']){http_response_code(503);header('X-Robots-Tag: noindex');header('Retry-After: 86400');exit('Sitemap is disabled until production launch.');}
 $pdo=seo_database();$urls=[];foreach(array_keys(seo_pages()) as $path)$urls[]=$c['base'].'/'.$path;$urls[]=$c['base'].'/catalog.php';
 foreach($pdo->query('SELECT id,category_path FROM products WHERE '.seo_active_where().' ORDER BY id') as $p){$urls[]=$c['base'].'/product.html?id='.(int)$p['id'];if($p['category_path']!=='')$urls[]=$c['base'].'/catalog.php?category='.rawurlencode($p['category_path']);}
 $urls=array_values(array_unique($urls));if(count($urls)>50000)throw new RuntimeException('Split sitemap before exceeding 50,000 URLs');
 $xml='<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';foreach($urls as $url)$xml.='<url><loc>'.htmlspecialchars($url,ENT_XML1|ENT_QUOTES,'UTF-8').'</loc></url>';$xml.='</urlset>';
 header('Content-Type: application/xml; charset=utf-8');header('Cache-Control: no-cache');echo $xml;
}catch(Throwable $e){error_log('SEO sitemap: '.$e->getMessage());http_response_code(503);header('Retry-After: 300');echo 'Sitemap temporarily unavailable.';}
