<?php
declare(strict_types=1);
require_once __DIR__.'/import-safety.php';
require_once __DIR__.'/import-single-csv.php';

/** Versioned, idempotent recovery of options skipped from the owner's current export. */
function catalog_repair_source_options(PDO $pdo): array {
    $key='catalog_source_options_v1';$locked=false;
    try{
        $get=$pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key=? LIMIT 1');
        $get->execute(['current_1c_snapshot']);$snapshot=(string)($get->fetchColumn()?:'');
        if($snapshot==='')return ['state'=>'no_current_export'];
        $get->execute([$key]);$saved=json_decode((string)($get->fetchColumn()?:''),true);
        if(($saved['snapshot']??'')===$snapshot&&($saved['state']??'')==='done')return $saved;
        $locked=(int)$pdo->query("SELECT GET_LOCK('profisport_1c_import',0)")->fetchColumn()===1;
        if(!$locked)return ['state'=>'running'];
        $root=realpath(__DIR__.'/../import');if(!$root)return ['state'=>'no_source'];
        $products=[];$categories=[];
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $f){
            if(!$f->isFile()||strtolower($f->getExtension())!=='csv'||str_contains(str_replace('\\','/',$f->getPathname()),'/.staging/'))continue;
            $entry=['path'=>$f->getPathname(),'mtime'=>single_csv_source_mtime($f->getPathname()),'size'=>$f->getSize()];
            $n=mb_strtolower($f->getFilename());if(str_contains($n,'tovary'))$products[]=$entry;elseif(str_contains($n,'categor'))$categories[]=$entry;
        }
        $pick=static function(array $files):?string{usort($files,static fn($a,$b)=>($b['mtime']<=>$a['mtime'])?:($b['size']<=>$a['size']));return $files[0]['path']??null;};
        $pf=$pick($products);$cf=$pick($categories);
        if(!$pf||!hash_equals($snapshot,single_csv_snapshot($pf,$cf)))return ['state'=>'source_not_current'];
        $rows=single_csv_rows($pf);$map=['category'=>3,'source_id'=>4,'sku'=>5,'name'=>7,'image'=>9,'price'=>10];$stock=11;
        if(!isset($rows[0][4])||trim((string)$rows[0][2])!=='Kod_'.trim((string)$rows[0][4]))return ['state'=>'not_legacy_export'];
        $counts=[];$recover=[];
        foreach($rows as $r){$id=trim((string)($r[4]??''));$counts[$id]=($counts[$id]??0)+1;if(str_contains((string)($r[10]??''),'&'))$recover[$id]=true;}
        foreach($counts as $id=>$n)if($id!==''&&$n>1)$recover[$id]=true;
        $normalized=import_normalize_rows($rows,$map,$stock);
        $eligible=array_values(array_filter($normalized,static fn($r)=>isset($recover[explode('~',trim((string)($r[4]??'')),2)[0]])));
        $progress=(($saved['snapshot']??'')===$snapshot)?$saved:[];
        $offset=(int)($progress['offset']??0);
        $normalizedCounts=[];foreach($normalized as $r){$id=trim((string)($r[4]??''));$normalizedCounts[$id]=($normalizedCounts[$id]??0)+1;}
        $imageIndex=json_decode((string)@file_get_contents(__DIR__.'/../uploads/1c-image-index.json'),true)['map']??[];
        $cats=[];foreach($cf?single_csv_rows($cf):[] as $r)if(isset($r[0],$r[1]))$cats[trim((string)$r[0])]=['name'=>trim((string)$r[1]),'parent'=>trim((string)($r[2]??''))];
        $path=static function(string $id)use($cats):string{$parts=[];$seen=[];while(isset($cats[$id])&&!isset($seen[$id])){$seen[$id]=true;array_unshift($parts,$cats[$id]['name']);$id=$cats[$id]['parent'];}return implode(' / ',$parts);};
        $find=$pdo->prepare('SELECT * FROM products WHERE source_id=? LIMIT 2');
        $insert=$pdo->prepare('INSERT INTO products(source_id,source_hash,catalog_snapshot,name,slug,sku,price,price_rub,stock_qty,stock_status,availability,category_path,main_image,images,is_active,short_description,specs) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $update=$pdo->prepare('UPDATE products SET catalog_snapshot=?,name=?,price=?,price_rub=?,stock_qty=?,stock_status=?,availability=?,is_active=?,updated_at=NOW() WHERE id=?');
        $created=(int)($progress['created']??0);$restored=(int)($progress['restored']??0);$skipped=(int)($progress['skipped']??0);$start=microtime(true);$pdo->beginTransaction();
        foreach(array_slice($eligible,$offset,100) as $r){
            $id=trim((string)($r[4]??''));$parent=explode('~',$id,2)[0];if(!isset($recover[$parent]))continue;
            if(($normalizedCounts[$id]??0)!==1){$skipped++;continue;}
            $price=import_scalar_price((string)($r[10]??''));$rawQty=trim((string)($r[11]??''));
            if($price===null||$price<=0||!preg_match('/^\d+(?:[.,]0+)?$/D',$rawQty)||(int)$rawQty<=0){$skipped++;continue;}
            if(microtime(true)-$start>35)throw new RuntimeException('source_options_time_budget');
            $find->execute([$id]);$matches=$find->fetchAll();if(count($matches)>1){$skipped++;continue;}
            $name=mb_substr(trim((string)($r[7]??$r[6]??'')),0,500);$qty=(int)$rawQty;
            if($matches){$ex=$matches[0];$update->execute([$snapshot,$name,$price,(int)round($price),$qty,'in_stock','in_stock',1,$ex['id']]);$restored++;continue;}
            $find->execute([$parent]);$parentRow=$find->fetch()?:[];
            $images=[];foreach(preg_split('/[|;,]/',(string)($r[9]??''))?:[] as $photo){
                $photo=trim($photo);$relative=$imageIndex[mb_strtolower(rawurldecode(basename($photo)))]??'';
                if($relative!==''&&is_file($root.'/'.$relative))$images[]='/import/'.implode('/',array_map('rawurlencode',explode('/',$relative)));
            }
            if(!$images){$images=json_decode((string)($parentRow['images']??'[]'),true)?:[];if(!$images&&!empty($parentRow['main_image']))$images[]=$parentRow['main_image'];}
            $specs=str_contains($id,'~')?[['name'=>'Размер / вариант','value'=>trim(explode(' — ',$name,2)[1]??'')]]:[];
            $insert->execute([$id,hash('sha256','1c|'.$id),$snapshot,$name,'source-option-'.substr(hash('sha256',$id),0,24),trim((string)($r[5]??'')),$price,(int)round($price),$qty,'in_stock','in_stock',$path(trim((string)($r[3]??'')))?:($parentRow['category_path']??''),$images[0]??null,json_encode($images,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),1,mb_substr(html_entity_decode(strip_tags((string)($r[8]??'')),ENT_QUOTES,'UTF-8'),0,10000),json_encode($specs,JSON_UNESCAPED_UNICODE)]);
            $created++;
        }
        $next=min(count($eligible),$offset+100);
        $result=['state'=>$next>=count($eligible)?'done':'pending','offset'=>$next,'total'=>count($eligible),'snapshot'=>$snapshot,'source_products'=>count($recover),'created'=>$created,'restored'=>$restored,'skipped'=>$skipped];
        $save=$pdo->prepare('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        $save->execute([$key,json_encode($result,JSON_UNESCAPED_UNICODE)]);$pdo->commit();return $result;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());return ['state'=>'failed'];}
    finally{if($locked)$pdo->query("SELECT RELEASE_LOCK('profisport_source_options_v1')");}
}
