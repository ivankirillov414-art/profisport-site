<?php
declare(strict_types=1);

require_once __DIR__.'/source-photo-diagnostic.php';

function spr_photo_path(string $value): string {
    $value=trim(rawurldecode(str_replace('\\','/',$value)));
    if($value==='')return '';
    if(str_contains($value,'://')){
        $parsed=parse_url($value,PHP_URL_PATH);
        if(!is_string($parsed))return '';
        $value=rawurldecode($parsed);
    }
    $value=preg_replace('/[?#].*$/','',$value)??$value;
    $value=ltrim($value,'/');
    if(str_starts_with($value,'import/'))$value=substr($value,7);
    return mb_strtolower($value);
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

    $dbRows=$pdo->query('SELECT id,source_id,name,main_image,images,is_active FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
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
    ];
    foreach($dbRows as $product){
        if((int)($product['is_active']??0)===1)$stats['db_active_products']++;
        if(trim((string)($product['source_id']??''))!=='')$stats['db_products_with_source_id']++;
    }
    $examples=['wrong'=>[],'empty'=>[],'secondary'=>[],'missing_product'=>[],'ambiguous_db'=>[]];

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
            if(count($examples['missing_product'])<12)$examples['missing_product'][]=['source_id'=>$sourceId,'name'=>$name,'expected'=>array_values($expected)];
            continue;
        }
        if(count($matches)!==1){
            $stats['ambiguous_db_products']++;
            if(count($examples['ambiguous_db'])<12)$examples['ambiguous_db'][]=['source_id'=>$sourceId,'name'=>$name,'db_ids'=>array_map(fn($p)=>(int)$p['id'],$matches)];
            continue;
        }

        $stats['matched_products']++;$product=$matches[0];
        $main=spr_photo_path((string)($product['main_image']??''));
        $gallery=json_decode((string)($product['images']??''),true);
        if(!is_array($gallery))$gallery=[];
        $galleryPaths=[];
        foreach($gallery as $photo){$path=spr_photo_path((string)$photo);if($path!=='')$galleryPaths[$path]=true;}
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

    return ['ok'=>true,'kind'=>'diafan_mysql_reconciliation','mode'=>'read_only','mysql_links_checked'=>true,
        'source_file'=>$selected['relative'],'stats'=>$stats,
        'duplicate_source_ids'=>count(array_filter($sourceIds,fn($count)=>$count>1)),
        'duplicate_db_source_ids'=>count(array_filter($dbBySource,fn($items)=>count($items)>1)),
        'examples'=>$examples,'generated_at'=>date('c')];
}
