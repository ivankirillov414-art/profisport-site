<?php
declare(strict_types=1);
require __DIR__.'/../private/core.php';
$GLOBALS['cms_site_manifest']=['site'=>'collection-template-test','pages'=>[]];
function check(bool $value,string $message):void {if(!$value)throw new RuntimeException($message);}
function bad(array $collection):void {try{cms_validate(['pages'=>[],'collections'=>[$collection]]);}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Unsafe template accepted');}
$collection=['id'=>'news','name'=>'Private name','titleField'=>'title','fields'=>[['key'=>'title','label'=>'Title','type'=>'string','required'=>true],['key'=>'body','label'=>'Body','type'=>'text','required'=>false],['key'=>'image','label'=>'Image','type'=>'image','required'=>false]],'entries'=>[
 ['id'=>'first','slug'=>'launch','status'=>'published','values'=>['title'=>'Published title','body'=>'<script>untrusted</script>','image'=>'/photo.jpg']],
 ['id'=>'second','slug'=>'secret','status'=>'draft','values'=>['title'=>'Secret draft']]],
 'template'=>[['block'=>['id'=>'heading','type'=>'text','props'=>[]],'bindings'=>['title'=>'title','text'=>'body']]]];
$clean=cms_validate(['pages'=>[],'collections'=>[$collection]]);$pages=cms_public($clean);$key='c-4-news-launch.html';
check(count($pages)===1&&$pages[$key]['title']==='Published title','Record page missing');
check($pages[$key]['layout'][0]['props']['text']==='<script>untrusted</script>','Plain text content altered before escaping renderer');
check(!str_contains(cms_encode($pages),'Secret draft')&&!str_contains(cms_encode($pages),'Private name'),'Private metadata leaked');
check(cms_validate($clean)===$clean,'Template normalization not stable');
$broken=$collection;$broken['template'][0]['bindings']['image']='title';bad($broken);
$broken=$collection;$broken['template'][0]['bindings']['title']='missing';bad($broken);
$broken=$collection;$broken['template'][0]['bindings']['url']='image';bad($broken);
$broken=$collection;$broken['template'][0]['block']=['id'=>'global','type'=>'global','component'=>'missing'];bad($broken);
$broken=$collection;$broken['template'][]=$broken['template'][0];bad($broken);
$collision=$clean;$collision['pages'][$key]=['title'=>'Collision','fields'=>[],'blocks'=>[]];
try{cms_validate($collision);throw new RuntimeException('Page collision accepted');}catch(InvalidArgumentException $e){}
$a=['id'=>'one--two'];$b=['id'=>'one'];check(cms_collection_page_key($a,['slug'=>'three'])!==cms_collection_page_key($b,['slug'=>'two--three']),'Ambiguous address');
$broken=$collection;$broken['entries'][0]['values']['body']=str_repeat('x',12000);$broken['template']=array_map(fn($i)=>['block'=>['id'=>'large-'.$i,'type'=>'text','props'=>[]],'bindings'=>['title'=>'body','text'=>'body','text2'=>'body','alt'=>'body','label'=>'body']],range(1,20));bad($broken);
echo "Collection templates passed: safe bindings, generated addresses, draft privacy, page collisions and expansion limits\n";
