<?php
declare(strict_types=1);
require __DIR__.'/../server/order-validation.php';
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);}
function fails(callable $f,string $error):void{try{$f();}catch(InvalidArgumentException|DomainException $e){check($e->getMessage()===$error,'wrong error');return;}throw new RuntimeException('expected '.$error);}
$input=['name'=>'Тестовый покупатель','phone'=>'8 (999) 123-45-67','items'=>[1,'1'],'request_key'=>str_repeat('a',64)];
$v=validate_order($input);check($v['phone']==='+79991234567','normalized phone');check($v['groups']===[1=>2],'quantities');
fails(fn()=>validate_order(array_replace($input,['items'=>['1 OR 1=1']])),'invalid_items');
fails(fn()=>validate_order(array_replace($input,['items'=>[0]])),'invalid_items');
fails(fn()=>validate_order(array_replace($input,['delivery'=>'free'])),'invalid_delivery');
fails(fn()=>validate_order(array_replace($input,['delivery'=>'orenburg_delivery'])),'address_required');
fails(fn()=>validate_order(array_replace($input,['request_key'=>''])),'invalid_request_key');
$row=['id'=>1,'name'=>'Мяч','is_active'=>1,'stock_status'=>'in_stock','availability'=>'in_stock','stock_qty'=>2,'price_rub'=>150];
check(order_lines([$row],[1=>2])['total']===300,'server price');
fails(fn()=>order_lines([$row],[1=>3]),'insufficient_stock');
fails(fn()=>order_lines([array_replace($row,['price_rub'=>0])],[1=>1]),'price_unavailable');
fails(fn()=>order_lines([array_replace($row,['is_active'=>0])],[1=>1]),'out_of_stock');
fails(fn()=>order_lines([],[1=>1]),'product_missing');
echo "Order validation checks passed\n";
