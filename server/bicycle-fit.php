<?php
declare(strict_types=1);
require_once __DIR__.'/product-spec-editor.php';
const BICYCLE_FIT_PREFIX='bicycle_fit:';
function bicycle_fit_is_bicycle(array $product): bool {
    $name=mb_strtolower(trim((string)$product['name']),'UTF-8');
    return (bool)preg_match('/^(?:(?:детский|детские|горный|горные|городской|дорожный|шоссейный|подростковый|женский|мужской|складной|спортивный)\s+)*(?:электро)?велосипед(?:\s|$)|^(?:electric\s+)?bicycle(?:\s|$)|^bmx(?:\s|$)/u',$name);
}
function bicycle_fit_identity(array $product): string {
    return hash('sha256',json_encode([(string)($product['source_id']??''),(string)($product['sku']??''),(string)$product['name']],JSON_UNESCAPED_UNICODE));
}
function bicycle_fit_settings(PDO $pdo): array {
    $out=[];
    foreach($pdo->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key LIKE 'bicycle_fit:%'")->fetchAll() as $row){
        $value=json_decode((string)$row['setting_value'],true);
        if(is_array($value))$out[(int)substr($row['setting_key'],strlen(BICYCLE_FIT_PREFIX))]=$value;
    }
    return $out;
}
function bicycle_fit_current(array $product,?array $fit): ?array {
    return $fit&&hash_equals(bicycle_fit_identity($product),(string)($fit['identity']??''))?$fit:null;
}
function bicycle_fit_version(array $product,?array $fit): string {
    return hash('sha256',json_encode([$product,$fit],JSON_UNESCAPED_UNICODE));
}
function bicycle_fit_specs(array $specs,?array $fit): array {
    if(!$fit)return $specs;
    $rows=product_specs_rows($specs);$out=[];
    foreach($rows as $row){
        $key=mb_strtolower(trim($row['name']),'UTF-8');
        if(in_array($key,['рекомендуемый рост','рост велосипедиста','рост райдера','рост человека','примечание по ростовке'],true))continue;
        if($fit['frame']!==''&&in_array($key,['размер рамы','ростовка рамы','рама размер'],true))continue;
        $out[]=$row;
    }
    $out[]=['name'=>'Рекомендуемый рост','value'=>$fit['min'].'–'.$fit['max'].' см'];
    if($fit['frame']!=='')$out[]=['name'=>'Размер рамы','value'=>$fit['frame']];
    if($fit['note']!=='')$out[]=['name'=>'Примечание по ростовке','value'=>$fit['note']];
    return $out;
}
function bicycle_fit_range(array $specs): string {
    foreach(product_specs_rows($specs) as $row){
        if(!in_array(mb_strtolower(trim($row['name']),'UTF-8'),['рекомендуемый рост','рост велосипедиста','рост райдера','рост человека'],true))continue;
        preg_match_all('/\d+(?:[.,]\d+)?/',$row['value'],$matches);
        $numbers=array_map(fn($n)=>(float)str_replace(',','.',$n),$matches[0]);
        if(count($numbers)===2&&$numbers[0]>=80&&$numbers[1]<=220&&$numbers[0]<$numbers[1])return $row['value'];
    }
    return '';
}
function bicycle_fit_validate(array $input): array {
    $min=$input['min']??null;$max=$input['max']??null;$frame=$input['frame']??'';$note=$input['note']??'';
    if(!is_int($min)||!is_int($max)||$min<80||$max>220||$min>=$max||!is_string($frame)||!is_string($note)||mb_strlen($frame)>100||mb_strlen($note)>1000)
        throw new InvalidArgumentException('Укажите рост от 80 до 220 см: нижняя граница должна быть меньше верхней. Размер рамы — до 100 символов, пояснение — до 1000.');
    return ['min'=>$min,'max'=>$max,'frame'=>trim($frame),'note'=>trim($note)];
}
