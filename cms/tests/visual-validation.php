<?php
declare(strict_types=1);
require __DIR__.'/../private/core.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rejects(callable $fn):void{try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Unsafe document accepted');}
$d=cms_initial();$d['pages']['index.html']['layout']=array_map(fn($s)=>['id'=>$s['id'],'type'=>'existing','visible'=>true],cms_templates()['index.html']['sections']);
$d['pages']['offers.html']=['title'=>'Акции','fields'=>[],'blocks'=>[],'layout'=>[['id'=>'new-1','type'=>'hero','props'=>['title'=>'Новая акция','background'=>'#ffffff']]]];
$clean=cms_validate($d);check($clean['pages']['offers.html']['layout'][0]['props']['title']==='Новая акция','New page lost');
$bad=$d;$bad['pages']['offers.html']['layout'][0]['props']['image']='javascript:alert(1)';rejects(fn()=>cms_validate($bad));
$bad=$d;$bad['pages']['offers.html']['layout'][0]['props']['background']='red;background:url(https://evil.test)';rejects(fn()=>cms_validate($bad));
$bad=$d;$bad['pages']['offers.html']['layout'][0]['type']='script';rejects(fn()=>cms_validate($bad));
$bad=$d;$bad['pages']['index.html']['layout'][]=$bad['pages']['index.html']['layout'][0];rejects(fn()=>cms_validate($bad));
$bad=$d;array_pop($bad['pages']['index.html']['layout']);rejects(fn()=>cms_validate($bad));
$bad=$d;$bad['pages']['../private.html']=$bad['pages']['offers.html'];rejects(fn()=>cms_validate($bad));
check(cms_validate(cms_initial())===cms_initial(),'Legacy drafts must remain valid without migration');
$public=cms_public($clean);check(count($public['offers.html']['layout'])===1,'Public new page lost');
echo "Visual CMS validation passed\n";
$extended=$d;
$extended['pages']['offers.html']['layout'][]=['id'=>'gallery-test','type'=>'gallery','props'=>['visibility'=>'mobile','mobileSpace'=>'24','columns'=>'3','items'=>[['title'=>'Photo','image'=>'https://example.com/a.webp','alt'=>'Bike']]]];
$extended['library']=[['name'=>'Saved gallery','block'=>$extended['pages']['offers.html']['layout'][1]]];
$valid=cms_validate($extended);check($valid['library'][0]['block']['props']['items'][0]['alt']==='Bike','Saved block data lost');
check(!isset(cms_public($valid)['library']),'Private presets leaked');
$bad=$extended;$bad['pages']['offers.html']['layout'][1]['props']['items'][0]['image']='javascript:alert(1)';rejects(fn()=>cms_validate($bad));
$bad=$extended;$bad['pages']['offers.html']['layout'][1]['props']['visibility']='mobile;display:none';rejects(fn()=>cms_validate($bad));
$bad=$extended;$bad['library'][0]['block']=['id'=>'section0','type'=>'existing','visible'=>true];rejects(fn()=>cms_validate($bad));
$bad=$extended;$bad['pages']['offers.html']['layout'][1]['props']['items']=array_fill(0,25,[]);rejects(fn()=>cms_validate($bad));
echo "ID nested blocks and private presets passed\n";
