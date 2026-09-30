<?php
declare(strict_types=1);
require __DIR__.'/../private/core.php';
$GLOBALS['cms_site_manifest']=['site'=>'components-test','pages'=>[]];
function ok(bool $value,string $message):void {if(!$value)throw new RuntimeException($message);}
function reject(array $draft):void {try{cms_validate($draft);}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Invalid shared component accepted');}
$block=['id'=>'original','type'=>'text','props'=>['title'=>'Shared title','text'=>'Shared text']];
$ref=['id'=>'instance-a','type'=>'global','component'=>'shared-one','overrides'=>[]];
$page=['title'=>'Page','fields'=>[],'blocks'=>[],'layout'=>[$ref]];
$draft=['components'=>[['id'=>'shared-one','name'=>'Private component label','block'=>$block]],'pages'=>['index.html'=>$page,'about.html'=>$page]];
$draft['pages']['about.html']['layout'][0]['overrides']=['title'=>'Local title'];
$clean=cms_validate($draft);$public=cms_public($clean);
ok($public['index.html']['layout'][0]['props']['title']==='Shared title','Shared instance resolution');
ok($public['about.html']['layout'][0]['props']['title']==='Local title','Instance exception');
ok(!str_contains(cms_encode($public),'Private component label'),'Definition metadata leaked');
$clean['components'][0]['block']['props']['title']='Updated everywhere';$clean=cms_validate($clean);$public=cms_public($clean);
ok($public['index.html']['layout'][0]['props']['title']==='Updated everywhere','Shared update');
ok($public['about.html']['layout'][0]['props']['title']==='Local title','Exception lost');
$bad=$draft;unset($bad['components']);reject($bad);
$bad=$draft;$bad['components'][]=$bad['components'][0];reject($bad);
$bad=$draft;$bad['components'][0]['block']=$ref;reject($bad);
$bad=$draft;$bad['components'][0]['block']=['id'=>'original','type'=>'existing','visible'=>true];reject($bad);
$bad=$draft;$bad['pages']['index.html']['layout'][0]['overrides']=['image'=>'javascript:alert(1)'];reject($bad);
$bad=$draft;$bad['pages']['index.html']['layout'][0]['overrides']=['anything'=>'value'];reject($bad);
$bad=$draft;$bad['pages']['index.html']['layout'][0]['component']='another-site-component';reject($bad);
$bad=$draft;$bad['library']=[['name'=>'Unsafe reference','block'=>$ref]];reject($bad);
$bad=$draft;$bad['components']=array_fill(0,51,$draft['components'][0]);reject($bad);
$bad=$draft;$bad['components'][0]['block']['props']['text']=str_repeat('x',12000);
$bad['pages']['index.html']['layout']=array_map(fn($i)=>['id'=>'r-'.$i,'type'=>'global','component'=>'shared-one'],range(1,100));reject($bad);
$bad['pages']['index.html']['layout']=array_slice($bad['pages']['index.html']['layout'],0,75);
$bad['pages']=array_fill_keys(['one.html','two.html','three.html','four.html','five.html'],$bad['pages']['index.html']);reject($bad);
$clean['pages']['about.html']['layout'][0]=cms_component_render($clean['pages']['about.html']['layout'][0],$clean['components']);
$clean['pages']['index.html']['layout']=[];unset($clean['components']);$detached=cms_validate($clean);
ok($detached['pages']['about.html']['layout'][0]['props']['title']==='Local title','Detach preserves appearance');
echo "Shared components: resolution, overrides, detach, private metadata, missing/cyclic references and hostile props passed\n";
