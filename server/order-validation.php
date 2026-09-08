<?php
declare(strict_types=1);

function validate_order(array $in): array {
    $out=[];
    foreach(['name'=>200,'phone'=>40,'email'=>200,'address'=>2000,'comment'=>4000] as $key=>$max){
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
    $ids=$in['items']??null;
    if(!is_array($ids)||!count($ids)||count($ids)>500)throw new InvalidArgumentException('invalid_items');
    $out['groups']=[];
    foreach($ids as $id){
        if((!is_int($id)&&!is_string($id))||!preg_match('/^[1-9][0-9]{0,9}$/',(string)$id))throw new InvalidArgumentException('invalid_items');
        $out['groups'][(int)$id]=($out['groups'][(int)$id]??0)+1;
    }
    ksort($out['groups']);
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
        $items[]=['id'=>(int)$p['id'],'title'=>(string)$p['name'],'price'=>$price,'qty'=>$qty,'line'=>$line];
    }
    return ['items'=>$items,'total'=>$total];
}
