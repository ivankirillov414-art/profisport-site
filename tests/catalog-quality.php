<?php
declare(strict_types=1);
require __DIR__.'/../server/catalog-quality.php';

function q_assert(bool $ok,string $message): void {
    if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}
}

$removed=0;
$specs=catalog_sanitize_specs('Палки лыжные алюминиевые TREK Snowline 6008',[
    'Ростовка рамы'=>'Пластик',
    'Материал'=>'Алюминий',
],$removed);
q_assert($removed===1,'impossible frame-size material must be removed');
q_assert(!array_key_exists('Ростовка рамы',$specs),'bad frame-size spec must disappear');
q_assert(($specs['Материал']??'')==='Алюминий','valid specs must remain');

$removed=0;
$bike=catalog_sanitize_specs('Велосипед STELS Navigator',[
    'Ростовка рамы'=>'18',
],$removed);
q_assert($removed===0,'bicycle frame size must remain');
q_assert(($bike['Ростовка рамы']??'')==='18','bicycle frame size was removed');

q_assert(catalog_sanitize_old_price(50000,55000)===55000,'valid old price must remain');
q_assert(catalog_sanitize_old_price(50000,49000)===null,'reversed old price must be removed');
q_assert(catalog_sanitize_old_price(0,55000)===null,'old price must be removed when current price is missing');
q_assert(catalog_sanitize_old_price_float(50000.0,55000.0)===55000.0,'valid float old price must remain');
q_assert(catalog_sanitize_old_price_float(50000.0,50000.0)===null,'equal float old price must be removed');

$inferred=false;
q_assert(catalog_resolve_brand('Велосипед STELS Navigator','Forward',[],$inferred)==='Forward','existing brand must win');
q_assert($inferred===false,'existing brand must not be marked inferred');

$inferred=false;
q_assert(catalog_resolve_brand('Товар без бренда','',['Бренд'=>'Fischer'],$inferred)==='Fischer','spec brand must be used');
q_assert($inferred===false,'spec brand must not be marked inferred');

$inferred=false;
q_assert(catalog_resolve_brand('Велосипед 24 STELS Turbo 470 MD','',[],$inferred)==='STELS','STELS title brand must be inferred');
q_assert($inferred===true,'title brand must be marked inferred');

$inferred=false;
q_assert(catalog_resolve_brand('Самокат трюковой Provokator 47 версия 2','',[],$inferred)==='Provokator','Provokator title brand must be inferred');
q_assert($inferred===true,'Provokator must be marked inferred');

$inferred=false;
q_assert(catalog_resolve_brand('Велосумка под раму BA01024 RUSH HOUR','',[],$inferred)==='Rush Hour','multi-word brand must be inferred');

foreach([
    'Ботинки лыжные NNN Comfort one size',
    'Мазь скольжения PURE ONE WET',
    'Спица STD 14 BLACK STAINLESS',
    'Эспандер FIT кистевой 20 кг',
] as $name){
    $inferred=false;
    q_assert(catalog_resolve_brand($name,'',[],$inferred)==='',"noise token inferred as brand: $name");
    q_assert($inferred===false,"noise token marked inferred: $name");
}

echo "Catalog quality checks passed.\n";
