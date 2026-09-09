<?php
declare(strict_types=1);

function catalog_text_norm(string $value): string {
    $value=mb_strtolower(trim($value),'UTF-8');
    $value=str_replace('ё','е',$value);
    $value=preg_replace('/[^a-zа-я0-9]+/ui',' ',$value)??$value;
    return trim(preg_replace('/\s+/u',' ',$value)??$value);
}

function catalog_is_bicycle_like(string $name): bool {
    $name=catalog_text_norm($name);
    return preg_match('/^(?:электровелосипед|велосипед|bmx)(?:\s|$)/ui',$name)===1;
}

function catalog_spec_value(array $specs,array $keys): string {
    $wanted=array_map('catalog_text_norm',$keys);
    foreach($specs as $key=>$value){
        if(is_array($value)){
            $candidateKey=(string)($value['name']??$value['key']??$value['title']??'');
            $candidateValue=$value['value']??'';
        }else{
            $candidateKey=(string)$key;
            $candidateValue=$value;
        }
        if(!in_array(catalog_text_norm($candidateKey),$wanted,true))continue;
        if(is_scalar($candidateValue)){
            $result=trim((string)$candidateValue);
            if($result!=='')return $result;
        }
    }
    return '';
}

function catalog_resolve_brand(string $name,?string $brand,array $specs,bool &$inferred=false): string {
    $inferred=false;
    $existing=trim((string)$brand);
    if($existing!=='')return $existing;

    $fromSpecs=catalog_spec_value($specs,['Бренд','Производитель']);
    if($fromSpecs!=='')return $fromSpecs;

    // Conservative, view-only fallback. Aliases come from repeated catalog title
    // evidence and intentionally exclude model/standard tokens such as NNN, ONE,
    // PRO, STD, FIT and colour words. Longer phrases go first.
    $aliases=[
        'RUSH HOUR'=>'Rush Hour',
        'VINCA SPORT'=>'Vinca Sport',
        'CN SPOKE'=>'CN Spoke',
        'X-TREME'=>'X-Treme',
        'TECHTEAM'=>'TechTeam',
        'MAXISCOO'=>'Maxiscoo',
        'PROVOKATOR'=>'Provokator',
        'NORDSKI'=>'Nordski',
        'SHIMANO'=>'Shimano',
        'STARFIT'=>'Starfit',
        'FISCHER'=>'Fischer',
        'ATOMIC'=>'Atomic',
        'BRADOS'=>'Brados',
        'DEUTER'=>'Deuter',
        'SIMPLA'=>'Simpla',
        'ASPECT'=>'Aspect',
        'BOYBO'=>'BoyBo',
        'KENDA'=>'Kenda',
        'KENLI'=>'Kenli',
        'MAXXIS'=>'Maxxis',
        'ROCKET'=>'Rocket',
        'SIGMA'=>'Sigma',
        'SPINE'=>'SPINE',
        'STELS'=>'STELS',
        'TREK'=>'TREK',
        'VARMA'=>'VARMA',
        'WANDA'=>'Wanda',
        'WELT'=>'Welt',
    ];
    $hay=' '.catalog_text_norm($name).' ';
    foreach($aliases as $alias=>$canonical){
        $needle=catalog_text_norm($alias);
        if($needle!==''&&str_contains($hay,' '.$needle.' ')){
            $inferred=true;
            return $canonical;
        }
    }
    return '';
}

function catalog_sanitize_specs(string $name,array $specs,int &$removed=0): array {
    $clean=[];
    foreach($specs as $key=>$value){
        if(is_array($value)){
            // Preserve list-shaped source specs untouched; their semantic lookup
            // is handled by catalog_spec_value().
            $clean[$key]=$value;
            continue;
        }
        $keyText=trim((string)$key);
        $valueText=is_scalar($value)?trim((string)$value):'';
        if($keyText===''||$valueText==='')continue;

        $keyNorm=catalog_text_norm($keyText);
        $valueNorm=catalog_text_norm($valueText);

        // A source-data defect was found on ski poles: bicycle-only field
        // "Ростовка рамы" contained material values such as "Пластик".
        // Drop only the clearly impossible combination; preserve legitimate
        // bicycle/frame dimensions and every other source characteristic.
        if(
            $keyNorm==='ростовка рамы' &&
            !catalog_is_bicycle_like($name) &&
            preg_match('/^(?:пластик|сталь|алюминий|алюминий сплав|карбон|углепластик|композит)$/ui',$valueNorm)
        ){
            $removed++;
            continue;
        }

        $clean[$keyText]=$value;
    }
    return $clean;
}

function catalog_sanitize_old_price(int $priceRub,?int $oldPriceRub): ?int {
    if($priceRub<=0||$oldPriceRub===null||$oldPriceRub<=$priceRub)return null;
    return $oldPriceRub;
}

function catalog_sanitize_old_price_float(float $price,?float $oldPrice): ?float {
    if($price<=0||$oldPrice===null||$oldPrice<=$price)return null;
    return $oldPrice;
}
