<?php
declare(strict_types=1);
require __DIR__.'/../server/bicycle-fit.php';
function fit_check(bool $condition):void{if(!$condition)throw new RuntimeException('bicycle fit regression');}
$p=['name'=>'Велосипед Test','source_id'=>'a','sku'=>'1'];
$fit=bicycle_fit_validate(['min'=>150,'max'=>170,'frame'=>' M ','note'=>' Замер ']);
$fit['identity']=bicycle_fit_identity($p);
fit_check(bicycle_fit_current($p,$fit)===$fit);
fit_check(bicycle_fit_current(array_merge($p,['sku'=>'2']),$fit)===null);
$specs=['Рост райдера'=>'180–200 см','Размер рамы'=>'L','Материал'=>'Алюминий'];
$applied=bicycle_fit_specs($specs,$fit);
fit_check(bicycle_fit_range($applied)==='150–170 см');
fit_check(count(array_filter($applied,fn($r)=>$r['name']==='Размер рамы'&&$r['value']==='M'))===1);
fit_check(count(array_filter($applied,fn($r)=>$r['name']==='Материал'))===1);
fit_check(bicycle_fit_specs($specs,null)===$specs);
fit_check(bicycle_fit_range(['Рекомендуемый рост'=>'170'])==='');
foreach([['min'=>180,'max'=>150],['min'=>150,'max'=>150],['min'=>79,'max'=>150],['min'=>150,'max'=>221],['min'=>'150','max'=>170],['min'=>150,'max'=>170,'frame'=>[]]] as $invalid){try{bicycle_fit_validate($invalid);throw new RuntimeException('invalid fit accepted');}catch(InvalidArgumentException $e){}}
echo "Bicycle fit override and validation passed\n";
