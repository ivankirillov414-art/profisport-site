<?php
declare(strict_types=1);

/** File-only diagnostics for the observed 41-column DIAFAN/1C export. */
function spd_read_csv(string $file): array {
    $raw=file_get_contents($file);
    if($raw===false)throw new RuntimeException('source_read_failed');
    if(str_starts_with($raw,"\xFF\xFE"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
    elseif(str_starts_with($raw,"\xFE\xFF"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16BE');
    elseif(!mb_check_encoding($raw,'UTF-8'))$raw=mb_convert_encoding($raw,'UTF-8','Windows-1251');
    $raw=preg_replace('/^\xEF\xBB\xBF/','',$raw)??$raw;
    $raw=str_replace(["\r\n","\r"],"\n",$raw);
    $stream=fopen('php://temp','w+');
    if(!$stream)throw new RuntimeException('source_stream_failed');
    fwrite($stream,$raw);rewind($stream);$rows=[];
    while(($row=fgetcsv($stream,0,';','"',''))!==false){
        if(array_filter($row,fn($x)=>trim((string)$x)!==''))$rows[]=$row;
    }
    fclose($stream);return $rows;
}

function spd_legacy_row(array $row): bool {
    return count($row)===41 && preg_match('/^\d+$/',trim((string)$row[4]))===1
        && trim((string)$row[2])==='Kod_'.trim((string)$row[4]);
}

function spd_reference(string $reference,array $paths,array $basenames): array {
    $reference=trim($reference," \t\n\r\"'");
    $path=rawurldecode(str_replace('\\','/',$reference));
    // Only names and local paths are authoritative in this source format.
    if(str_contains($path,'://')||str_contains($path,"\0")||in_array('..',explode('/',$path),true))return ['status'=>'invalid','paths'=>[]];
    if(str_starts_with($path,'/import/'))$path=substr($path,8);
    elseif(str_starts_with($path,'import/'))$path=substr($path,7);
    elseif(str_starts_with($path,'/'))return ['status'=>'invalid','paths'=>[]];
    if(strtolower(basename($path))==='default-no-image.png')return ['status'=>'placeholder','paths'=>[]];
    $key=mb_strtolower($path);
    if(str_contains($path,'/'))$matches=isset($paths[$key])?[$paths[$key]]:[];
    else $matches=$basenames[$key]??[];
    return ['status'=>count($matches)===1?'exact':(count($matches)>1?'ambiguous':'missing'),'paths'=>$matches];
}

function spd_report(string $root): ?array {
    $images=[];$paths=[];$sources=[];$imageCount=0;
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach($iterator as $file){
        if(!$file->isFile()||$file->isLink())continue;
        $extension=strtolower($file->getExtension());
        $relative=str_replace(DIRECTORY_SEPARATOR,'/',substr($file->getPathname(),strlen($root)+1));
        if(in_array($extension,['jpg','jpeg','png','webp','gif','avif'],true)){
            $imageCount++;$paths[mb_strtolower($relative)]=$relative;
            $images[mb_strtolower($file->getFilename())][]=$relative;
        }elseif($extension==='csv'&&str_contains(mb_strtolower($file->getFilename()),'tovary')){
            $sources[]=['file'=>$file->getPathname(),'relative'=>$relative,'size'=>$file->getSize()];
        }
    }
    if(!$sources)return null;
    usort($sources,fn($a,$b)=>($b['size']<=>$a['size'])?:strcmp($a['relative'],$b['relative']));
    $selected=$sources[0];$rows=spd_read_csv($selected['file']);
    if(!$rows||!spd_legacy_row($rows[0]))return null;
    $stats=['valid_rows'=>0,'malformed_rows'=>0,'rows_with_exact_photo'=>0,'rows_with_ambiguous_photo'=>0,
        'rows_with_missing_photo'=>0,'rows_with_placeholder'=>0,'rows_without_photo_reference'=>0];
    $refs=['exact'=>0,'ambiguous'=>0,'missing'=>0,'placeholder'=>0,'invalid'=>0];
    $examples=[];$ids=[];$referenced=[];$malformed=[];
    foreach($rows as $index=>$row){
        if(!spd_legacy_row($row)){
            $stats['malformed_rows']++;
            if(count($malformed)<10)$malformed[]=['record'=>$index+1,'fields'=>count($row)];
            continue;
        }
        $stats['valid_rows']++;$id=trim((string)$row[4]);$ids[$id]=($ids[$id]??0)+1;
        $cell=trim((string)$row[9]);$states=[];$matches=[];
        if($cell==='')$stats['rows_without_photo_reference']++;
        foreach(array_unique(preg_split('/[|,\r\n]+/u',$cell)?:[]) as $ref){
            if(trim($ref)==='')continue;
            $result=spd_reference($ref,$paths,$images);$state=$result['status'];$refs[$state]++;$states[$state]=true;
            foreach($result['paths'] as $path){$matches[]=$path;if($state==='exact')$referenced[$path]=true;}
        }
        if(isset($states['exact']))$stats['rows_with_exact_photo']++;
        if(isset($states['ambiguous']))$stats['rows_with_ambiguous_photo']++;
        if(isset($states['placeholder']))$stats['rows_with_placeholder']++;
        if(isset($states['missing'])||isset($states['invalid']))$stats['rows_with_missing_photo']++;
        if((isset($states['ambiguous'])||isset($states['missing'])||isset($states['invalid']))&&count($examples)<24){
            $examples[]=['source_id'=>$id,'name'=>(string)$row[7],'statuses'=>array_keys($states),'paths'=>array_values(array_unique($matches))];
        }
    }
    return ['ok'=>true,'kind'=>'diafan_1c_source','mode'=>'read_only','mysql_links_checked'=>false,
        'file'=>$selected['relative'],'source_files_found'=>count($sources),'rows'=>count($rows),
        'image_files'=>$imageCount,'unique_image_names'=>count($images),
        'duplicate_image_names'=>count(array_filter($images,fn($v)=>count($v)>1)),
        'unique_referenced_files'=>count($referenced),'stats'=>$stats,'references'=>$refs,
        'duplicate_source_ids'=>array_filter($ids,fn($v)=>$v>1),'malformed_examples'=>$malformed,'examples'=>$examples];
}
