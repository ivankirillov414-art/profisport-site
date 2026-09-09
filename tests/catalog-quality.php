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

echo "Catalog quality checks passed.\n";
