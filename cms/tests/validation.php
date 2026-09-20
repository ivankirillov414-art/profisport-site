<?php
declare(strict_types=1);
require __DIR__.'/../private/core.php';
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$initial=cms_initial();check(cms_validate($initial)===$initial,'Initial document must validate');
foreach(['javascript:alert(1)','data:text/html,test','//evil.example','https://good.example\\@evil.example','java\nscript:alert(1)','../private/config.php'] as $v)check(!cms_url($v),'Unsafe URL accepted: '.$v);
foreach(['https://example.com/image.webp','/cms/media/image.jpg','assets/image.png','#catalog','tel:+73532960486'] as $v)check(cms_url($v),'Safe URL rejected: '.$v);
check(!cms_url('tel:+73532960486',true),'Image cannot use telephone protocol');
$bad=$initial;unset($bad['pages']['index.html']['fields']['f1']);
try{cms_validate($bad);throw new RuntimeException('Missing field accepted');}catch(InvalidArgumentException $e){}
$bad=$initial;$bad['pages']['index.html']['blocks'][]=$bad['pages']['index.html']['blocks'][0];
try{cms_validate($bad);throw new RuntimeException('Duplicate block accepted');}catch(InvalidArgumentException $e){}
$changed=$initial;$changed['pages']['index.html']['fields']['f1']='<script>alert(1)</script>';check(cms_validate($changed)===$changed,'Text is stored literally; adapter must use textContent');
echo "CMS validation passed\n";
