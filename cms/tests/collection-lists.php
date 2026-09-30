<?php
declare(strict_types=1);
require __DIR__.'/../private/core.php';
$GLOBALS['cms_site_manifest']=['site'=>'list-test','pages'=>[]];
function yes(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function rejects(array $draft):void{try{cms_validate($draft);}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Invalid list accepted');}
$c=['id'=>'products','name'=>'Private collection name','titleField'=>'title','fields'=>[['key'=>'title','label'=>'Title','type'=>'string','required'=>true],['key'=>'price','label'=>'Price','type'=>'number','required'=>false],['key'=>'category','label'=>'Category','type'=>'string','required'=>false]],'entries'=>[]];
foreach([10,2,null] as $i=>$value)$c['entries'][]=['id'=>'item-'.$i,'slug'=>'item-'.$i,'status'=>'published','values'=>['title'=>'Item '.$i,'price'=>$value,'category'=>'A']];
$c['entries'][]=['id'=>'private','slug'=>'private','status'=>'draft','values'=>['title'=>'SECRET']];
$list=['id'=>'list-one','type'=>'collection','collection'=>'products','mapping'=>['title'=>'title','text'=>'price'],'query'=>['pageSize'=>2,'sortField'=>'price','direction'=>'asc','filterField'=>'category'],'props'=>['title'=>'Products']];
$draft=['pages'=>['index.html'=>['title'=>'Index','fields'=>[],'blocks'=>[],'layout'=>[$list]]],'collections'=>[$c]];
$clean=cms_validate($draft);$public=cms_public($clean);$rendered=$public['index.html']['layout'][0];
yes(array_column($rendered['items'],'text')===['2','10',''],'Numeric sort or null order incorrect');
yes($rendered['filterLabel']==='Category'&&$rendered['pageSize']===2,'List settings lost');
yes(!str_contains(cms_encode($public),'SECRET')&&!str_contains(cms_encode($public),'Private collection name'),'Private data leaked');
yes($rendered['items'][0]['recordPage']==='','Link created without a template');
$draft['collections'][0]['template']=[['block'=>['id'=>'detail','type'=>'text','props'=>[]],'bindings'=>['title'=>'title']]];
$rendered=cms_public(cms_validate($draft))['index.html']['layout'][0];yes($rendered['items'][0]['recordPage']==='c-8-products-item-1.html','Record page link missing');
$bad=$draft;unset($bad['collections']);rejects($bad);
$bad=$draft;$bad['pages']['index.html']['layout'][0]['mapping']['image']='title';rejects($bad);
$bad=$draft;$bad['pages']['index.html']['layout'][0]['mapping']['text']='missing';rejects($bad);
$bad=$draft;$bad['pages']['index.html']['layout'][0]['query']['sortField']='missing';rejects($bad);
$bad=$draft;$bad['pages']['index.html']['layout'][0]['query']['pageSize']=25;rejects($bad);
$draft['pages']['index.html']['layout'][0]['query']['direction']='desc';$items=cms_public(cms_validate($draft))['index.html']['layout'][0]['items'];yes(array_column($items,'text')===['10','2',''],'Descending sort/null placement incorrect');
echo "Collection list validation passed: mappings, numeric sorting, null order, privacy, safe record links and missing dependencies\n";
