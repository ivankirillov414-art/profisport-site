<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
@set_time_limit(0);

function out(array $x,int $code=200): never {
    http_response_code($code);
    echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function authAudit(array $config): void {
    $got=(string)($_SERVER['HTTP_X_IMPORT_TOKEN']??'');
    $expected=(string)($config['import_token']??'');
    if($expected!==''&&$got!==''&&hash_equals($expected,$got)) return;
    require_admin();
}
function utf8(string $s): string {
    if(str_starts_with($s,"\xEF\xBB\xBF")) return substr($s,3);
    if(str_starts_with($s,"\xFF\xFE")) return mb_convert_encoding(substr($s,2),'UTF-8','UTF-16LE');
    if(str_starts_with($s,"\xFE\xFF")) return mb_convert_encoding(substr($s,2),'UTF-8','UTF-16BE');
    return mb_check_encoding($s,'UTF-8')?$s:mb_convert_encoding($s,'UTF-8','Windows-1251');
}
function normHeader(string $s): string { return preg_replace('/[^a-zа-я0-9]+/u','',mb_strtolower(trim($s)))??''; }
function readCsv(string $path): array {
    $raw=file_get_contents($path);
    if($raw===false) return [];
    $raw=str_replace("\0",'',utf8($raw));
    $rows=[];
    foreach(preg_split('/\r\n|\n|\r/',$raw)?:[] as $line){
        if(trim($line)==='') continue;
        $r=str_getcsv($line,';','"','\\');
        if(count(array_filter($r,fn($v)=>trim((string)$v)!==''))) $rows[]=$r;
    }
    return $rows;
}
function findProductCsv(string $root): ?string {
    $found=[];
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach($it as $f){
        if(!$f->isFile()||strtolower($f->getExtension())!=='csv') continue;
        $n=mb_strtolower($f->getFilename());
        if(str_contains($n,'tovary')) $found[]=['p'=>$f->getPathname(),'s'=>$f->getSize(),'m'=>$f->getMTime()];
    }
    if(!$found) return null;
    usort($found,fn($a,$b)=>($b['s']<=>$a['s'])?:($b['m']<=>$a['m']));
    return $found[0]['p'];
}
function imageColumn(array $row): ?int {
    $aliases=['image','images','photo','photos','picture','pictures','картинка','изображение','фото','фотографии','файлфото'];
    foreach($row as $i=>$h){
        $n=normHeader((string)$h);
        foreach($aliases as $a) if($n===normHeader($a)) return (int)$i;
    }
    return null;
}
function refNames(string $cell): array {
    $out=[];
    foreach(preg_split('/[|,\r\n]+/u',$cell)?:[] as $x){
        $x=trim($x," \t\"'");
        if($x==='') continue;
        $out[]=mb_strtolower(basename(str_replace('\\','/',$x)));
    }
    return array_values(array_unique($out));
}
function normStem(string $name): string {
    $stem=pathinfo(mb_strtolower($name),PATHINFO_FILENAME);
    $stem=preg_replace('/(?:[-_ ](?:small|thumb|thumbnail|preview|mini|big|large|orig|original|\d+x\d+))+$/u','',$stem)??$stem;
    return preg_replace('/[^a-zа-я0-9]+/u','',$stem)??'';
}

try{
    authAudit($config);
    $root=realpath(__DIR__.'/../import');
    if(!$root) throw new RuntimeException('import_missing');

    $imageExt=['jpg','jpeg','png','webp','gif','avif'];
    $images=[];$byBase=[];$byStem=[];$duplicateBasenames=0;
    $other=[];
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach($it as $f){
        if(!$f->isFile()) continue;
        $ext=strtolower($f->getExtension());
        $rel=str_replace(DIRECTORY_SEPARATOR,'/',substr($f->getPathname(),strlen($root)+1));
        if(in_array($ext,$imageExt,true)){
            $images[]=$rel;
            $base=mb_strtolower($f->getFilename());
            if(isset($byBase[$base])) $duplicateBasenames++;
            $byBase[$base][]=$rel;
            $stem=normStem($base);
            if($stem!=='') $byStem[$stem][]=$rel;
        } elseif(in_array($ext,['csv','sql','xml','json'],true)) {
            $other[]=['path'=>$rel,'size'=>$f->getSize()];
        }
    }

    $db=[
        'products'=>(int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
        'active'=>(int)$pdo->query('SELECT COUNT(*) FROM products WHERE is_active=1')->fetchColumn(),
        'main_image_nonempty'=>(int)$pdo->query("SELECT COUNT(*) FROM products WHERE TRIM(COALESCE(main_image,''))<>''")->fetchColumn(),
        'images_nonempty'=>(int)$pdo->query("SELECT COUNT(*) FROM products WHERE images IS NOT NULL AND TRIM(images) NOT IN ('','[]','null')")->fetchColumn(),
        'no_photo_fields'=>(int)$pdo->query("SELECT COUNT(*) FROM products WHERE TRIM(COALESCE(main_image,''))='' AND (images IS NULL OR TRIM(images) IN ('','[]','null'))")->fetchColumn(),
    ];

    $csv=findProductCsv($root);$csvAudit=null;
    if($csv){
        $rows=readCsv($csv);$headerIndex=$rows?imageColumn($rows[0]):null;$hasHeader=$headerIndex!==null;
        $idx=$hasHeader?$headerIndex:9;
        $data=$rows;if($hasHeader) array_shift($data);
        $rowsWithRefs=0;$refs=0;$exact=0;$stemOnly=0;$missing=0;$multiExact=0;$unresolved=[];
        foreach($data as $row){
            $names=refNames((string)($row[$idx]??''));
            if(!$names) continue;
            $rowsWithRefs++;
            foreach($names as $name){
                $refs++;
                if(isset($byBase[$name])){
                    $exact++;
                    if(count($byBase[$name])>1) $multiExact++;
                    continue;
                }
                $stem=normStem($name);
                if($stem!==''&&isset($byStem[$stem])){$stemOnly++;continue;}
                $missing++;
                if(count($unresolved)<40) $unresolved[]=$name;
            }
        }
        $csvAudit=[
            'file'=>str_replace(DIRECTORY_SEPARATOR,'/',substr($csv,strlen($root)+1)),
            'rows'=>count($data),
            'image_column'=>$idx,
            'header_detected'=>$hasHeader,
            'rows_with_image_refs'=>$rowsWithRefs,
            'image_refs'=>$refs,
            'exact_filename_matches'=>$exact,
            'normalized_stem_matches'=>$stemOnly,
            'unresolved_refs'=>$missing,
            'ambiguous_exact_basenames'=>$multiExact,
            'unresolved_sample'=>$unresolved,
        ];
    }

    $last=null;
    try{
        $s=$pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key='last_1c_import' LIMIT 1");$s->execute();$v=$s->fetchColumn();
        if($v!==false) $last=json_decode((string)$v,true)?:$v;
    }catch(Throwable $ignored){}

    out([
        'ok'=>true,
        'mode'=>'read_only',
        'import_root'=>basename($root),
        'image_files'=>count($images),
        'unique_image_basenames'=>count($byBase),
        'duplicate_basename_collisions'=>$duplicateBasenames,
        'db'=>$db,
        'csv'=>$csvAudit,
        'last_1c_import'=>$last,
        'source_files'=>array_slice($other,0,80),
    ]);
}catch(Throwable $e){
    error_log($e->__toString());
    out(['ok'=>false,'error'=>$e->getMessage()],500);
}
