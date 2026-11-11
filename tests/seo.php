<?php
declare(strict_types=1);
require __DIR__.'/../server/seo.php';
function check(bool $yes,string $message): void {if(!$yes)throw new RuntimeException($message);}
check(seo_settings([])===['base'=>'','index'=>false],'No hostname inference or automatic indexing');
check(!seo_settings(['seo_indexing'=>true])['index'],'Missing production origin must block indexing');
foreach(['http://example.test','https://example.test/path','https://user@example.test','https://example.test/?x=1','https://example.test/#x','javascript:alert(1)'] as $bad){try{seo_settings(['seo_public_url'=>$bad]);throw new LogicException('accepted unsafe origin');}catch(RuntimeException $e){}}
$settings=seo_settings(['seo_public_url'=>'https://example.test/','seo_indexing'=>true]);
$html=seo_head('<html><head><title>Old</title><meta name="description" content="Old"><link rel="canonical" href="https://wrong.test/"></head></html>','Цена $199 <script>','"Описание"','catalog.php?category=x&page=2',false,['@context'=>'https://schema.org','name'=>'</script><script>alert(1)</script>'],$settings);
check(str_contains($html,'Цена $199 &lt;script&gt;'),'Title must preserve dollars and escape tags');
check(substr_count($html,'name="description"')===1,'Exactly one description');
check(str_contains($html,'https://example.test/catalog.php?category=x&amp;page=2'),'Canonical retains actual pagination');
check(!str_contains($html,'wrong.test'),'Old canonical removed');
check(!str_contains($html,'</script><script>alert'),'JSON-LD script injection must be escaped');
check(str_contains(seo_head('<head><title>x</title></head>','x','d','index.html',true,[],$settings),'noindex, follow'),'Private filters not indexable');
check(seo_text('<b>Тест</b> &amp; товар')==='Тест & товар','Plain, readable descriptions');
check(str_contains(seo_active_where(),'stock_qty,0)>0'),'No unavailable inventory in sitemap or listings');
echo "SEO metadata, launch gating, escaping and canonical tests passed.\n";
