<?php
declare(strict_types=1);

/** Unknown DIAFAN variant prices must not silently become zero. */
function import_scalar_price(string $raw): ?float {
    $value=str_replace(["\xc2\xa0",' '],'',trim($raw));
    $value=str_replace(',','.',$value);
    if(preg_match('/^\d+(?:\.\d{1,2})?$/D',$value)!==1)return null;
    $price=(float)$value;
    return is_finite($price)&&$price<=99999999.99?$price:null;
}

/** An equal name is only a conflict signal, never an identity match. */
function import_identity_decision(string $sourceId,array $bySource,array $byHash,array $byName): array {
    if($sourceId==='')return ['status'=>'conflict'];
    if(count($bySource)>1||count($byHash)>1)return ['status'=>'conflict'];
    $source=$bySource[0]??null;$hash=$byHash[0]??null;
    if($source&&$hash&&(int)$source['id']!==(int)$hash['id'])return ['status'=>'conflict'];
    $existing=$source??$hash;
    if($existing){
        if(trim((string)($existing['source_id']??''))!==$sourceId)return ['status'=>'conflict'];
        return ['status'=>'update','product'=>$existing];
    }
    return ['status'=>$byName?'conflict':'create'];
}
