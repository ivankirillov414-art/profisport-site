<?php
declare(strict_types=1);
require __DIR__.'/../server/legal-documents.php';
function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$s=legal_defaults();check(count($s['documents'])===14,'complete document set');check(legal_public_documents($s)===[],'drafts private by default');
$payload=['revision'=>0,'action'=>'save','slug'=>'reviews','title'=>'Отзывы','body'=>'Это новый черновик документа, который не должен сразу появиться на сайте.'];
$s=legal_apply($s,$payload,1);check(legal_public_documents($s)===[],'draft save not published');
try{legal_apply($s,$payload,1);throw new Exception('accepted stale write');}catch(RuntimeException $e){check($e->getMessage()==='conflict','conflict guard');}
try{legal_apply($s,array_replace($payload,['revision'=>1,'action'=>'publish']),1);throw new Exception('published incomplete seller');}catch(InvalidArgumentException $e){check(str_contains($e->getMessage(),'Заполните'),'seller validation');}
$seller=array_fill_keys(array_keys(legal_fields()),'Заполнено');$seller['seller_type']='ООО';$seller['inn']='1234567890';$seller['ogrn']='1234567890123';$seller['email']='legal@example.test';
$s=legal_apply($s,['revision'=>1,'action'=>'seller','seller'=>$seller],1);
$s=legal_apply($s,array_replace($payload,['revision'=>2,'action'=>'publish','body'=>'Оператор {{seller_name}}. Текст условий опубликован и не меняется при сохранении черновика.']),1);
$published=legal_public_documents($s)['reviews'];check(!str_contains($published['body'],'{{'),'seller expanded');check(!isset($published['by']),'admin identity not public');
$s=legal_apply($s,array_replace($payload,['revision'=>3,'body'=>'Другой черновик текста. Покупатели продолжают видеть прежнюю опубликованную редакцию.']),1);
check(legal_public_documents($s)['reviews']===$published,'published snapshot preserved');
$s=legal_apply($s,array_replace($payload,['revision'=>4,'action'=>'publish']),1);check(count($s['documents']['reviews']['history'])===1,'publication history');
$s=legal_apply($s,['revision'=>5,'action'=>'restore','slug'=>'reviews','version'=>3],1);check(legal_public_documents($s)['reviews']['version']===5,'restore only draft');
$s=legal_apply($s,['revision'=>6,'action'=>'unpublish','slug'=>'reviews'],1);check(legal_public_documents($s)===[],'unpublished documents private');
$doc=['body'=>'Условия {{seller_name}}. [ЗАПОЛНИТЬ: сведения]'];check(count(legal_publication_errors($doc,$seller))>0,'placeholder publication blocked');
$seller['seller_type']='ИП';$seller['registration']='';check(count(legal_publication_errors(['body'=>str_repeat('Текст ',20)],$seller))>=3,'IP registration and number lengths');
echo "PASS: drafts, seller substitution, publication validation, immutable public snapshots, revisions, restore and removal\n";
