<?php
declare(strict_types=1);
require __DIR__.'/server/seo.php';
header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-cache');
try{$c=seo_config();}catch(Throwable $e){http_response_code(503);exit;}
if(!$c['index']){echo "User-agent: *\nDisallow: /\n";exit;}
echo "User-agent: *\nDisallow: /admin/\nDisallow: /cms/\nDisallow: /server/\nDisallow: /import/\nDisallow: /tests/\nDisallow: /docs/\nSitemap: ".$c['base']."/sitemap.xml\n";
