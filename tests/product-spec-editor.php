<?php
declare(strict_types=1);
require __DIR__.'/../server/product-spec-editor.php';
function check(bool $condition):void{if(!$condition)throw new RuntimeException('spec editor regression');}
$rows=json_decode(product_specs_json([['name'=>' Материал ','value'=>' Алюминий '],['name'=>'Колёса','value'=>'27,5"']]),true);
check($rows[0]===['name'=>'Материал','value'=>'Алюминий']);
check($rows[1]['value']==='27,5"');
check(product_specs_json([])==='[]');
foreach([null,['Материал'=>'Сталь'],[['name'=>'','value'=>'x']],[['name'=>'x','value'=>'']],[['name'=>'x','value'=>[]]],[['name'=>'Материал','value'=>'x'],['name'=>'материал','value'=>'y']],array_fill(0,101,['name'=>'x','value'=>'y']),[['name'=>str_repeat('я',121),'value'=>'x']],[['name'=>'x','value'=>str_repeat('я',2001)]]] as $invalid){
    try{product_specs_json($invalid);throw new RuntimeException('invalid specs accepted');}
    catch(InvalidArgumentException $e){}
}
echo "Product spec editor validation passed\n";
