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

$ordered=catalog_image_candidates(['main_image'=>' /import/images/skis.jpg ', 'images'=>'["/import/images/pads.jpg","/import/images/skis.jpg","/import/images/detail.jpg",null,{}]']);
q_assert($ordered===['/import/images/skis.jpg','/import/images/pads.jpg','/import/images/detail.jpg'],'main photo must precede stale gallery entry, preserving valid detail photos');
q_assert(catalog_image_candidates(['main_image'=>'/import/images/skis.jpg','images'=>'not json'])===['/import/images/skis.jpg'],'broken gallery JSON must not hide the main photo');
q_assert(catalog_image_candidates(['main_image'=>null,'images'=>'["/import/images/detail.jpg"]'])===['/import/images/detail.jpg'],'missing main must preserve the gallery fallback');
q_assert(catalog_image_candidates(['images'=>'"not a gallery"'])===[],'non-array gallery must stay empty');

require __DIR__.'/../server/import-safety.php';
q_assert(import_scalar_price('1 530,00')===1530.0,'ordinary source prices must remain supported');
q_assert(import_scalar_price('0')===0.0,'explicit zero differs from an unparsed price');
q_assert(import_scalar_price('3650.00&1&89=233|3650.00&1&89=234')===null,'variant price must never be converted to zero');
q_assert(import_scalar_price('3650&1&89=233')===null,'integer variant price must not concatenate into a huge price');
foreach(['','-1','NaN','1.2.3','100000000'] as $price)q_assert(import_scalar_price($price)===null,'invalid price must be skipped');
$existing=['id'=>80,'source_id'=>'32236'];
q_assert(import_identity_decision('32033',[],[],[$existing])['status']==='conflict','same-name glove must not overwrite a different source code');
q_assert(import_identity_decision('32236',[$existing],[],[])['status']==='update','an exact source code must keep its existing card');
q_assert(import_identity_decision('32033',[],[],[])['status']==='create','an unoccupied new identity may be created');
q_assert(import_identity_decision('',[],[],[])['status']==='conflict','missing source identity must not create a product');
q_assert(import_identity_decision('32033',[],[$existing],[])['status']==='conflict','stale hash must not reassign another source code');
q_assert(import_identity_decision('32236',[$existing,$existing],[],[])['status']==='conflict','duplicate database identities must not pick an arbitrary card');
q_assert(import_identity_decision('32236',[$existing],[['id'=>81,'source_id'=>'32236']],[])['status']==='conflict','source and hash pointing to different cards must be skipped');

echo "Catalog quality and import identity/price checks passed.\n";
