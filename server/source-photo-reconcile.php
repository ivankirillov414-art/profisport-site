<?php
declare(strict_types=1);

require_once __DIR__.'/source-photo-diagnostic.php';

function spr_photo_path(string $value): string {
    $value=trim(str_replace('\\','/',$value));
    if($value==='')return '';
    if(str_contains($value,'://')||str_starts_with($value,'//')){
        $host=strtolower((string)parse_url($value,PHP_URL_HOST));
        if($host!=='ferryffggg.infinityfreeapp.com')return 'external:'.$value;
        $parsed=parse_url($value,PHP_URL_PATH);
        if(!is_string($parsed))return 'invalid:'.$value;
        $value=$parsed;
    }
    $value=preg_replace('/[?#].*$/','',$value)??$value;
    $value=rawurldecode($value);
    if(str_contains($value,"\0")||in_array('..',explode('/',$value),true))return 'invalid:'.$value;
    $value=ltrim($value,'/');
    if(str_starts_with($value,'import/'))$value=substr($value,7);
    return mb_strtolower($value);
}

function spr_identity_key(string $value): string {
    return mb_strtolower(preg_replace('/\s+/u',' ',trim($value))??trim($value));
}

/** Possible duplicate identities are evidence for review, never an import match. */
function spr_identity_index(array $products): array {
    $index=['name'=>[],'sku'=>[],'source_hash'=>[]];
    foreach($products as $product){
        foreach(array_keys($index) as $field){
            $key=spr_identity_key((string)($product[$field]??''));
            if($key!=='')$index[$field][$key][]=$product;
        }
    }
    return $index;
}

function spr_identity_candidates(array $row,array $index): array {
    $keys=['name'=>(string)$row[7],'sku'=>(string)$row[5],'source_hash'=>hash('sha256','1c|'.trim((string)$row[4]))];
    $candidates=[];
    foreach($keys as $field=>$value){
        $key=spr_identity_key($value);
        if($key==='')continue;
        foreach($index[$field][$key]??[] as $product){
            $id=(string)$product['id'];
            if(!isset($candidates[$id]))$candidates[$id]=['product_id'=>$id,'source_id'=>$product['source_id'],'name'=>$product['name'],'reasons'=>[]];
            $candidates[$id]['reasons'][]=$field;
        }
    }
    return array_values($candidates);
}

function spr_reconcile(PDO $pdo,string $root): array {
    $paths=[];$basenames=[];$sources=[];
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach($iterator as $file){
        if(!$file->isFile()||$file->isLink())continue;
        $extension=strtolower($file->getExtension());
        $relative=str_replace(DIRECTORY_SEPARATOR,'/',substr($file->getPathname(),strlen($root)+1));
        if(in_array($extension,['jpg','jpeg','png','webp','gif','avif'],true)){
            $paths[mb_strtolower($relative)]=$relative;
            $basenames[mb_strtolower($file->getFilename())][]=$relative;
        }elseif($extension==='csv'&&str_contains(mb_strtolower($file->getFilename()),'tovary')){
            $sources[]=['file'=>$file->getPathname(),'relative'=>$relative,'size'=>$file->getSize()];
        }
    }
    if(!$sources)throw new RuntimeException('product_source_missing');
    usort($sources,fn($a,$b)=>($b['size']<=>$a['size'])?:strcmp($a['relative'],$b['relative']));
    $selected=$sources[0];$rows=spd_read_csv($selected['file']);

    $sourceIds=[];
    foreach($rows as $row){
        if(!spd_legacy_row($row))continue;
        $id=trim((string)$row[4]);$sourceIds[$id]=($sourceIds[$id]??0)+1;
    }

    $dbRows=$pdo->query('SELECT id,source_id,source_hash,sku,name,main_image,images,is_active FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $identityIndex=spr_identity_index($dbRows);
    $dbBySource=[];
    foreach($dbRows as $product){
        $id=trim((string)($product['source_id']??''));
        if($id!=='')$dbBySource[$id][]=$product;
    }

    $stats=[
        'source_valid_rows'=>0,'source_exact_unique_rows'=>0,'source_duplicate_id_rows'=>0,
        'db_products'=>count($dbRows),'db_active_products'=>0,'db_products_with_source_id'=>0,
        'matched_products'=>0,'missing_products'=>0,'ambiguous_db_products'=>0,
        'correct_main_photo'=>0,'native_photo_only_secondary'=>0,'empty_photo_fields'=>0,
        'wrong_or_unlinked_photo'=>0,'repairable_existing_products'=>0,
        'missing_code_with_identity_candidates'=>0,'missing_code_without_identity_candidates'=>0,
        'gallery_first_differs_from_main'=>0,'galleries_with_unverified_photos'=>0,
        'source_name_differs'=>0,'db_products_outside_exact_audit'=>0,
    ];
    foreach($dbRows as $product){
        if((int)($product['is_active']??0)===1)$stats['db_active_products']++;
        if(trim((string)($product['source_id']??''))!=='')$stats['db_products_with_source_id']++;
    }
    $examples=['wrong'=>[],'empty'=>[],'secondary'=>[],'missing_product'=>[],'ambiguous_db'=>[],'gallery'=>[],'name_difference'=>[]];

    foreach($rows as $row){
        if(!spd_legacy_row($row))continue;
        $stats['source_valid_rows']++;
        $sourceId=trim((string)$row[4]);$name=trim((string)$row[7]);
        if(($sourceIds[$sourceId]??0)!==1){$stats['source_duplicate_id_rows']++;continue;}

        $expected=[];$unsafe=false;$hasReference=false;
        foreach(array_unique(preg_split('/[|,\r\n]+/u',trim((string)$row[9]))?:[]) as $reference){
            if(trim($reference)==='')continue;
            $hasReference=true;$match=spd_reference($reference,$paths,$basenames);
            if($match['status']!=='exact'){$unsafe=true;continue;}
            foreach($match['paths'] as $path)$expected[spr_photo_path((string)$path)]=(string)$path;
        }
        if(!$hasReference||$unsafe||!$expected)continue;
        $stats['source_exact_unique_rows']++;

        $matches=$dbBySource[$sourceId]??[];
        if(!$matches){
            $stats['missing_products']++;
            $candidates=spr_identity_candidates($row,$identityIndex);
            $stats[$candidates?'missing_code_with_identity_candidates':'missing_code_without_identity_candidates']++;
            if(count($examples['missing_product'])<100)$examples['missing_product'][]=[
                'source_id'=>$sourceId,'name'=>$name,'sku'=>(string)$row[5],'expected'=>array_values($expected),
                'source_price'=>(string)$row[10],'source_stock'=>(string)$row[11],
                'identity_candidate_count'=>count($candidates),'identity_candidates'=>array_slice($candidates,0,10)];
            continue;
        }
        if(count($matches)!==1){
            $stats['ambiguous_db_products']++;
            if(count($examples['ambiguous_db'])<12)$examples['ambiguous_db'][]=['source_id'=>$sourceId,'name'=>$name,'db_ids'=>array_map(fn($p)=>(int)$p['id'],$matches)];
            continue;
        }

        $stats['matched_products']++;$product=$matches[0];
        if(spr_identity_key((string)$product['name'])!==spr_identity_key($name)){
            $stats['source_name_differs']++;
            if(count($examples['name_difference'])<20)$examples['name_difference'][]=['product_id'=>$product['id'],'source_id'=>$sourceId,'name'=>$product['name'],'source_name'=>$name];
        }
        $main=spr_photo_path((string)($product['main_image']??''));
        $gallery=json_decode((string)($product['images']??''),true);
        if(!is_array($gallery))$gallery=[];
        $galleryPaths=[];
        foreach($gallery as $photo){if(!is_string($photo))continue;$path=spr_photo_path($photo);if($path!=='')$galleryPaths[$path]=true;}
        $unverified=array_keys(array_diff_key($galleryPaths,$expected));
        $first=array_key_first($galleryPaths);
        if($main!==''&&$first!==null&&$first!==$main)$stats['gallery_first_differs_from_main']++;
        if($unverified){
            $stats['galleries_with_unverified_photos']++;
            if(count($examples['gallery'])<20)$examples['gallery'][]=['product_id'=>$product['id'],'source_id'=>$sourceId,'name'=>$product['name'],'main'=>$product['main_image'],'unverified'=>$unverified,'expected'=>array_values($expected)];
        }
        if($main!==''&&isset($expected[$main])){$stats['correct_main_photo']++;continue;}

        $stats['repairable_existing_products']++;
        $sample=['product_id'=>(int)$product['id'],'source_id'=>$sourceId,'name'=>(string)$product['name'],
            'current'=>(string)($product['main_image']??''),'expected'=>array_values($expected)];
        if(array_intersect_key($expected,$galleryPaths)){
            $stats['native_photo_only_secondary']++;
            if(count($examples['secondary'])<12)$examples['secondary'][]=$sample;
        }elseif($main===''&&!$galleryPaths){
            $stats['empty_photo_fields']++;
            if(count($examples['empty'])<12)$examples['empty'][]=$sample;
        }else{
            $stats['wrong_or_unlinked_photo']++;
            if(count($examples['wrong'])<20)$examples['wrong'][]=$sample;
        }
    }

    $stats['db_products_outside_exact_audit']=$stats['db_products']-$stats['matched_products'];
    return ['ok'=>true,'kind'=>'diafan_mysql_reconciliation','mode'=>'read_only','mysql_links_checked'=>true,
        'report_version'=>2,'scope'=>'unique_source_id_and_exact_source_files','file_contents_checked'=>false,
        'source_file'=>$selected['relative'],'stats'=>$stats,
        'duplicate_source_ids'=>count(array_filter($sourceIds,fn($count)=>$count>1)),
        'duplicate_db_source_ids'=>count(array_filter($dbBySource,fn($items)=>count($items)>1)),
        'examples'=>$examples,'generated_at'=>date('c')];
}
