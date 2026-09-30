<?php
declare(strict_types=1);
require __DIR__.'/../private/core.php';
$GLOBALS['cms_site_manifest']=['site'=>'collections-test','pages'=>[]];
function verify(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
function invalid(array $collections):void {try{cms_validate(['pages'=>[],'collections'=>$collections]);}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Invalid collection accepted');}
$fields=[];
foreach(['title'=>'string','body'=>'text','price'=>'number','active'=>'boolean','date'=>'date','photo'=>'image','link'=>'url'] as $key=>$type)$fields[]=['key'=>$key,'label'=>$key,'type'=>$type,'required'=>true];
$record=['id'=>'entry-one','slug'=>'first','status'=>'published','values'=>['title'=>'Первая запись','body'=>'Текст','price'=>0,'active'=>false,'date'=>'2024-02-29','photo'=>'/photo.png','link'=>'https://example.org']];
$collection=['id'=>'news','name'=>'Новости','titleField'=>'title','fields'=>$fields,'entries'=>[$record]];
$clean=cms_validate(['pages'=>[],'collections'=>[$collection]]);
verify($clean['collections'][0]['entries'][0]['values']===$record['values'],'Types were changed');
verify(cms_validate($clean)===$clean,'Normalization is not stable');
verify(cms_validate(['pages'=>[]])===['pages'=>[]],'Legacy documents changed');
verify(cms_public($clean)===[],'Private collection data leaked before page binding');
foreach(['price'=>'12','active'=>1,'date'=>'2025-02-29','photo'=>'javascript:alert(1)','link'=>'//evil.test','title'=>''] as $key=>$value){$bad=$collection;$bad['entries'][0]['values'][$key]=$value;invalid([$bad]);}
$bad=$collection;$bad['entries'][0]['values']['unknown']='x';invalid([$bad]);
$bad=$collection;$bad['entries'][]=$record;invalid([$bad]);
$bad=$collection;$bad['fields'][]=$fields[0];invalid([$bad]);
$bad=$collection;$bad['titleField']='price';invalid([$bad]);
$bad=$collection;$bad['entries'][0]['slug']='../private';invalid([$bad]);
invalid([$collection,$collection]);
$draft=$collection;$draft['entries'][0]['status']='draft';$draft['entries'][0]['values']=[];
verify(cms_collections_validate([$draft])[0]['entries'][0]['values']['title']===null,'Incomplete draft rejected');
$linked=$collection;$linked['id']='stories';$linked['fields'][]=['key'=>'source','label'=>'Источник','type'=>'reference','target'=>'news','required'=>true];$linked['entries'][0]['values']['source']='entry-one';
verify(count(cms_collections_validate([$collection,$linked]))===2,'Valid reference rejected');
invalid([$linked]);
$bad=$linked;$bad['entries'][0]['values']['source']='missing';invalid([$collection,$bad]);
invalid([$draft,$linked]);
$linked['entries'][0]['status']='draft';verify(count(cms_collections_validate([$draft,$linked]))===2,'Draft reference rejected');
$bad=$collection;$bad['entries']=array_fill(0,101,$record);invalid([$bad]);
echo "Collection schemas: typed values, drafts, required fields, references, deletion safety, URL validation and legacy compatibility passed\n";
