<?php
declare(strict_types=1);

function validate_order(array $in): array {
    $out=[];
    foreach(['name'=>200,'phone'=>40,'email'=>200,'address'=>2000,'comment'=>4000,'pickup_store'=>180] as $key=>$max){
        if(isset($in[$key])&&!is_string($in[$key]))throw new InvalidArgumentException('invalid_input');
        $out[$key]=trim($in[$key]??'');
        if(mb_strlen($out[$key])>$max)throw new InvalidArgumentException('invalid_input');
    }
    $phone=preg_replace('/\D/','',$out['phone']);
    if(mb_strlen($out['name'])<2||!preg_match('/^[78][0-9]{10}$/',$phone))throw new InvalidArgumentException('invalid_input');
    $out['phone']='+7'.substr($phone,1);
    if($out['email']!==''&&!filter_var($out['email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('bad_email');
    $out['delivery']=$in['delivery']??'pickup';
    if(!in_array($out['delivery'],['pickup','orenburg_delivery'],true))throw new InvalidArgumentException('invalid_delivery');
    if($out['delivery']==='orenburg_delivery'&&mb_strlen($out['address'])<5)throw new InvalidArgumentException('address_required');
    $pickupStores=['Проспект Победы, 79','Проспект Победы, 118 строение 2'];
    if($out['delivery']==='pickup'){
        if($out['pickup_store']==='')$out['pickup_store']=$pickupStores[0];
        if(!in_array($out['pickup_store'],$pickupStores,true))throw new InvalidArgumentException('invalid_pickup_store');
        $out['address']='';
    }else{
        $out['pickup_store']='';
    }
    $ids=$in['items']??null;
    if(!is_array($ids)||!count($ids)||count($ids)>500)throw new InvalidArgumentException('invalid_items');
    $out['groups']=[];
    foreach($ids as $id){
        if((!is_int($id)&&!is_string($id))||!preg_match('/^[1-9][0-9]{0,9}$/',(string)$id))throw new InvalidArgumentException('invalid_items');
        $out['groups'][(int)$id]=($out['groups'][(int)$id]??0)+1;
    }
    ksort($out['groups']);
    $bonus=$in['bonus_spend']??0;
    if((!is_int($bonus)&&!is_string($bonus))||!preg_match('/^[0-9]{1,7}$/',(string)$bonus))throw new InvalidArgumentException('invalid_bonus_spend');
    $out['bonus_spend']=(int)$bonus;
    if($out['bonus_spend']<0||$out['bonus_spend']>1000000)throw new InvalidArgumentException('invalid_bonus_spend');
    $out['request_key']=$in['request_key']??'';
    if(!is_string($out['request_key'])||!preg_match('/^[a-f0-9]{64}$/',$out['request_key']))throw new InvalidArgumentException('invalid_request_key');
    return $out;
}

function order_lines(array $rows,array $groups): array {
    if(count($rows)!==count($groups))throw new DomainException('product_missing');
    $items=[];$total=0;
    foreach($rows as $p){
        if(!(int)$p['is_active']||$p['stock_status']==='out_of_stock'||$p['availability']==='out_of_stock')throw new DomainException('out_of_stock');
        $qty=$groups[(int)$p['id']];
        if($p['stock_qty']!==null&&$qty>(int)$p['stock_qty'])throw new DomainException('insufficient_stock');
        $price=(int)$p['price_rub'];
        if($price<=0)throw new DomainException('price_unavailable');
        $line=$price*$qty;$total+=$line;
        if($total>2147483647)throw new DomainException('order_too_large');
        $items[]=['id'=>(int)$p['id'],'title'=>(string)$p['name'],'price'=>$price,'qty'=>$qty,'line'=>$line,'category_path'=>(string)($p['category_path']??'')];
    }
    return ['items'=>$items,'total'=>$total];
}
