<?php
declare(strict_types=1);

/** Canonical specs use the name/value format already supported by the catalogue. */
function product_specs_json(mixed $input): string {
    if(!is_array($input)||!array_is_list($input)||count($input)>100)
        throw new InvalidArgumentException('Допустимо до 100 характеристик.');
    $out=[];$seen=[];
    foreach($input as $row){
        if(!is_array($row)||!is_string($row['name']??null)||!is_string($row['value']??null))
            throw new InvalidArgumentException('Укажите название и значение характеристики.');
        $name=trim($row['name']);$value=trim($row['value']);
        if($name===''||$value===''||mb_strlen($name)>120||mb_strlen($value)>2000)
            throw new InvalidArgumentException('Название: до 120 символов; значение: до 2000 символов. Оба поля обязательны.');
        $key=mb_strtolower($name,'UTF-8');
        if(isset($seen[$key]))throw new InvalidArgumentException('Названия характеристик не должны повторяться.');
        $seen[$key]=true;$out[]=['name'=>$name,'value'=>$value];
    }
    return json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
