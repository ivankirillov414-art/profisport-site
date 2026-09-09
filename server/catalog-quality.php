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

function catalog_sanitize_specs(string $name,array $specs,int &$removed=0): array {
    $clean=[];
    foreach($specs as $key=>$value){
        $keyText=trim((string)$key);
        $valueText=trim((string)$value);
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
