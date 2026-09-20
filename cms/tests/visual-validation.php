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
