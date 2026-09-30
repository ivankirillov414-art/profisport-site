<?php
declare(strict_types=1);
require __DIR__.'/../private/core.php';
$GLOBALS['cms_site_manifest']=['site'=>'seo-test','pages'=>[]];$GLOBALS['cms_site_key']='seo-test';$GLOBALS['cms_site']=['site_key'=>'seo-test','name'=>'SEO','url'=>'https://external.example/'];
$_SERVER['HTTP_HOST']='localhost:8123';$_SERVER['SCRIPT_NAME']='/cms/site.php';
function ok(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function rejects(array $seo):void{try{cms_seo_validate($seo);}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Unsafe SEO accepted');}
$seo=cms_seo_validate(['title'=>'Title <script>','description'=>'Text & details','image'=>'/cms/media/image.png','noindex'=>false]);
$draft=cms_validate(['pages'=>['index.html'=>['title'=>'Page title','fields'=>[],'blocks'=>[],'seo'=>$seo]]]);$pages=cms_public($draft);ok($pages['index.html']['seo']===$seo,'SEO lost in public document');
$meta=cms_seo_metadata('index.html',$pages['index.html']);ok($meta['title']==='Title <script>','Title changed before HTML escaping');ok($meta['image']==='http://localhost:8123/cms/media/image.png','Root image URL incorrect');
$xml=cms_sitemap($pages);ok(str_contains($xml,'&amp;page=index.html'),'XML URL not escaped');ok(!str_contains($xml,'<script>'),'Metadata leaked into XML');
$pages['index.html']['seo']['noindex']=true;ok(!str_contains(cms_sitemap($pages),'<loc>'),'Noindex page leaked into sitemap');
$pages['index.html']['seo']['noindex']=false;$pages['index.html']['seo']['canonical']='https://other.example/page';ok(!str_contains(cms_sitemap($pages),'<loc>'),'External canonical leaked into sitemap');
foreach([['canonical'=>'javascript:bad'],['canonical'=>'https://example.org/a#section'],['canonical'=>'https://user:pass@example.org/a'],['image'=>'//evil.example/x'],['noindex'=>'false'],['title'=>str_repeat('x',601)]] as $bad)rejects($bad);
$fields=['title'=>['type'=>'string'],'body'=>['type'=>'text'],'picture'=>['type'=>'image'],'number'=>['type'=>'number']];
ok(cms_collection_seo_validate(['titleField'=>'title','descriptionField'=>'body','imageField'=>'picture'],$fields)['imageField']==='picture','Collection bindings failed');
try{cms_collection_seo_validate(['imageField'=>'body'],$fields);throw new RuntimeException('Wrong SEO type accepted');}catch(InvalidArgumentException $e){}
echo "SEO validation passed: metadata preservation, absolute URLs, XML escaping, noindex, external canonical exclusion and typed bindings\n";
