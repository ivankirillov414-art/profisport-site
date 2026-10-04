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


/** DIAFAN serializes each stocked price option as price&quantity&parameter=value. */
function import_price_options(string $raw): ?array {
    if(!str_contains($raw,'&'))return null;
    $options=[];$seen=[];
    foreach(explode('|',$raw) as $part){
        $bits=explode('&',trim($part));if(count($bits)<3)return null;
        $price=import_scalar_price(array_shift($bits));$quantity=array_shift($bits);
        if($price===null||!preg_match('/^\d+$/D',$quantity))return null;
        $params=[];
        foreach($bits as $bit){if(!preg_match('/^(\d+)=([^&|=]+)$/D',$bit,$m)||isset($params[$m[1]]))return null;$params[$m[1]]=$m[2];}
        ksort($params);$key=http_build_query($params);
        if(isset($seen[$key]))return null;$seen[$key]=true;
        $options[]=['price'=>$price,'quantity'=>(int)$quantity,'params'=>$params,'key'=>$key];
    }
    return $options&&count($options)<=100?$options:null;
}

/** Expand real options into separately priced, separately stocked sellable items. */
function import_normalize_rows(array $rows,array $map,int $stockIndex): array {
    $groups=[];foreach($rows as $i=>$row){$id=trim((string)($row[$map['source_id']]??''));if($id!=='')$groups[$id][]=$i;}
    $discard=[];
    foreach($groups as $ids){
        if(count($ids)<2)continue;
        // Repeated copies of one identity may refresh price and stock later in the export.
        // Different products sharing an ID remain conflicts, never merged by title alone.
        $base=$rows[$ids[0]];unset($base[$map['price']],$base[$stockIndex]);$same=true;
        foreach($ids as $i){$candidate=$rows[$i];unset($candidate[$map['price']],$candidate[$stockIndex]);if($candidate!==$base){$same=false;break;}}
        if($same){foreach(array_slice($ids,0,-1) as $i)$discard[$i]=true;continue;}
        $identity=static fn(array $row):array=>array_map(static fn($key)=>trim((string)($row[$map[$key]]??'')),['category','sku','name','image']);
        $baseIdentity=$identity($rows[$ids[0]]);
        foreach($ids as $i)if($identity($rows[$i])!==$baseIdentity){$same=false;continue 2;}
        $seenRows=[];
        foreach($ids as $i){
            $copy=$rows[$i];unset($copy[$map['price']],$copy[$stockIndex]);
            $signature=substr(hash('sha256',json_encode($copy,JSON_UNESCAPED_UNICODE)),0,16);
            if(isset($seenRows[$signature]))$discard[$seenRows[$signature]]=true;
            $seenRows[$signature]=$i;
            $labels=array_filter(array_map('trim',array_slice($rows[$i],29)));
            $label=$labels?implode(' / ',$labels):'Вариант '.(count($seenRows));
            $rows[$i][$map['source_id']].='~'.$signature;
            $rows[$i][$map['name']]=mb_substr(trim((string)$rows[$i][$map['name']]),0,400).' — '.mb_substr($label,0,90);
        }
    }
    $out=[];
    foreach($rows as $i=>$row){
        if(isset($discard[$i]))continue;
        $options=import_price_options((string)($row[$map['price']]??''));
        if(!$options){$out[]=$row;continue;}
        $total=trim((string)($row[$stockIndex]??''));
        if(!preg_match('/^\d+(?:[.,]0+)?$/D',$total)||array_sum(array_column($options,'quantity'))!==(int)$total){$out[]=$row;continue;}
        $labels=[];
        $attributeKeys=array_keys($options[0]['params']);
        if(count($attributeKeys)===1){
            $key=$attributeKeys[0];$values=array_column(array_column($options,'params'),$key);
            // Legacy export column 30 contains the display labels for DIAFAN size parameter 89.
            if((string)$key==='89'){$candidate=array_map('trim',explode('|',(string)($row[29]??'')));if(count($candidate)===count($values)&&count(array_filter($candidate,'strlen'))===count($values))$labels=array_combine($values,$candidate);}
        }
        foreach($options as $j=>$option){
            $child=$row;$parent=trim((string)($row[$map['source_id']]??''));
            if($parent==='')continue;
            $child[$map['source_id']]=$parent.'~'.substr(hash('sha256',$option['key']),0,16);
            $label=count($option['params'])===1?($labels[(string)reset($option['params'])]??''): '';
            if($label==='')$label='Вариант '.($j+1);
            $child[$map['name']]=mb_substr(trim((string)($row[$map['name']]??'')),0,440).' — '.$label;
            $child[$map['price']]=(string)$option['price'];$child[$stockIndex]=(string)$option['quantity'];
            $out[]=$child;
        }
    }
    return $out;
}
