<?php
declare(strict_types=1);
require_once __DIR__.'/../server/product-photos.php';
function check_photo(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
$p=['id'=>12,'source_id'=>'A','name'=>'Тест','main_image'=>'/import/first.png','images'=>'["/import/second.png","/import/first.png"]'];
check_photo(pph_view($p,null)['images']===['/import/first.png','/import/second.png'],'Source main image is first and not duplicated');
$o=['enabled'=>1,'source_identity'=>'A','gallery'=>'["/uploads/product-photos/12/'.str_repeat('a',64).'.png"]','revision'=>4];
check_photo(pph_view($p,$o)['manual']===true,'Override enabled');
$p['main_image']='/import/new-1c.png';$p['images']='[]';
check_photo(pph_view($p,$o)['images'][0]!==$p['main_image'],'New source does not replace manual images');
$o['enabled']=0;check_photo(pph_view($p,$o)['images']===['/import/new-1c.png'],'Reset reveals latest source');
$o['enabled']=1;$o['source_identity']='OLD';check_photo(pph_view($p,$o)['manual']===false,'Reused product id must not inherit old override');
$o['source_identity']='A';$o['gallery']='[]';check_photo(pph_view($p,$o)['images']===[],'Explicitly empty manual gallery stays empty');
check_photo(pph_urls(['images'=>['javascript:alert(1)','data:text/html,test','//evil.test/test','/import/valid image.jpg']])===['/import/valid image.jpg'],'Unsafe URL schemes rejected, legitimate spaces preserved');
check_photo(pph_photo_path('/uploads/product-photos/12/'.str_repeat('a',64).'.png')!==null,'Generated upload path allowed');
check_photo(pph_photo_path('/uploads/product-photos/12/../config.php')===null,'Traversal rejected');
check_photo(pph_ini_bytes('5M')===5242880,'Hosting limits parsed');
echo "PASS: product photo override domain tests\n";
