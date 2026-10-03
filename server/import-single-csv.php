<?php
declare(strict_types=1);

/** Read one CSV independently of its filename. Preserve quoted multiline cells. */
function single_csv_rows(string $path): array {
    $raw=file_get_contents($path);
    if($raw===false)throw new RuntimeException('Не удалось прочитать CSV.');
    if(str_starts_with($raw,"\xFF\xFE"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
    elseif(str_starts_with($raw,"\xFE\xFF"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16BE');
    elseif(!mb_check_encoding($raw,'UTF-8'))$raw=mb_convert_encoding($raw,'UTF-8','Windows-1251');
    $raw=preg_replace('/^\xEF\xBB\xBF/','',$raw)??$raw;
    $first=explode("\n",str_replace("\r","\n",$raw),2)[0];
    $counts=[];foreach([';',',',"\t"] as $delimiter)$counts[$delimiter]=count(str_getcsv($first,$delimiter,'"',''));
    arsort($counts);$delimiter=(string)array_key_first($counts);
    $stream=fopen('php://temp','w+');if(!$stream)throw new RuntimeException('Не удалось прочитать CSV.');
    fwrite($stream,$raw);rewind($stream);$rows=[];
    while(($row=fgetcsv($stream,0,$delimiter,'"',''))!==false){if(array_filter($row,fn($v)=>trim((string)$v)!==''))$rows[]=$row;}
    fclose($stream);
    // Keep the existing fixed-width DIAFAN recovery for old unescaped exports.
    if($delimiter===';'){
        $score=fn(array $items):int=>count(array_filter($items,fn($r)=>count($r)===41&&preg_match('/^\d+$/',trim((string)($r[4]??'')))===1&&trim((string)($r[2]??''))==='Kod_'.trim((string)$r[4])));
        $physical=[];foreach(preg_split('/\r\n|\n|\r/',$raw)?:[] as $line){if(trim($line)!=='')$physical[]=str_getcsv($line,';','"','\\');}
        if($score($physical)>$score($rows))$rows=$physical;
    }
    return $rows;
}

function single_csv_snapshot(string $products,?string $categories): string {
    $a=hash_file('sha256',$products);$b=$categories!==null?hash_file('sha256',$categories):'single-csv';
    if($a===false||$b===false)throw new RuntimeException('Не удалось проверить файлы выгрузки.');
    // A new admin batch may contain changed photos but identical CSV bytes.
    $directory=str_replace('\\','/',dirname($products));
    $batch=preg_match('~/manual/[^/]+$~',$directory)?"\n".basename($directory):'';
    return hash('sha256',$a."\n".$b.$batch);
}

/** Text categories come from the CSV; numeric IDs need an existing mapping. */
function single_csv_category(string $value,callable $resolveId): string {
    $value=trim($value);if($value==='')return '';
    $mapped=$resolveId($value);if($mapped!=='')return $mapped;
    if(preg_match('/^\d+$|^[a-f0-9]{8}-[a-f0-9-]{27,}$/i',$value))return '';
    return mb_substr($value,0,500,'UTF-8');
}

/** Refresh supplied images, preserving all existing images not replaced by name. */
function single_csv_merge_photos(array $incoming,array $previous,string $main): array {
    $valid=fn($v)=>is_string($v)&&trim($v)!=='';
    $incoming=array_values(array_unique(array_filter($incoming,$valid)));
    $previous=array_values(array_unique(array_filter(array_merge($previous,$main!==''?[$main]:[]),$valid)));
    if(!$incoming)return ['images'=>$previous,'main'=>$main!==''?$main:($previous[0]??null)];
    $name=fn(string $v)=>mb_strtolower(rawurldecode(basename((string)(parse_url($v,PHP_URL_PATH)?:$v))),'UTF-8');
    $replaced=array_fill_keys(array_map($name,$incoming),true);
    $previous=array_values(array_filter($previous,fn($v)=>!isset($replaced[$name($v)])));
    return ['images'=>array_values(array_unique(array_merge($incoming,$previous))),'main'=>$incoming[0]];
}

/** Same-second uploads must not select an older, larger CSV by file size. */
function single_csv_source_mtime(string $path): float {
    $mtime=(float)filemtime($path);
    $dir=str_replace('\\','/',dirname($path));
    if(!preg_match('~/manual/[^/]+$~',$dir))return $mtime;
    $marker=$dir.'/.upload.json';
    if(!is_file($marker))return $mtime;
    $data=json_decode((string)file_get_contents($marker),true);
    $completed=$data['completed_at']??0;
    return is_numeric($completed)?max($mtime,(float)$completed):$mtime;
}
