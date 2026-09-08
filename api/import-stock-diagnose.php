<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
@set_time_limit(0);
function out(array $x,int $code=200): never { http_response_code($code); echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function utf8(string $s): string { if(str_starts_with($s,"\xEF\xBB\xBF"))return substr($s,3); if(str_starts_with($s,"\xFF\xFE"))return mb_convert_encoding(substr($s,2),'UTF-8','UTF-16LE'); if(str_starts_with($s,"\xFE\xFF"))return mb_convert_encoding(substr($s,2),'UTF-8','UTF-16BE'); return mb_check_encoding($s,'UTF-8')?$s:mb_convert_encoding($s,'UTF-8','Windows-1251'); }
function csvRows(string $p): array { $raw=file_get_contents($p); if($raw===false)throw new RuntimeException('read_failed'); $raw=str_replace("\0",'',utf8($raw)); $rows=[]; foreach(preg_split('/\r\n|\n|\r/',$raw)?:[] as $ln){ if(trim($ln)==='')continue; $r=str_getcsv($ln,';','"','\\'); if(count(array_filter($r,fn($v)=>trim((string)$v)!=='')))$rows[]=$r; } return $rows; }
function authDiag(array $config): void { $g=(string)($_SERVER['HTTP_X_IMPORT_TOKEN']??''); $e=(string)($config['import_token']??''); if($e!==''&&$g!==''&&hash_equals($e,$g))return; require_admin(); }
function findProductFile(string $root): ?string { $c=[]; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)); foreach($it as $f){ if(!$f->isFile()||strtolower($f->getExtension())!=='csv')continue; $n=mb_strtolower($f->getFilename()); if(!str_contains($n,'tovary'))continue; $c[]=['p'=>$f->getPathname(),'s'=>$f->getSize(),'m'=>$f->getMTime()]; } if(!$c)return null; usort($c,fn($a,$b)=>($b['s']<=>$a['s'])?:($b['m']<=>$a['m'])); return $c[0]['p']; }
try{
  authDiag($config);
  $root=realpath(__DIR__.'/../import'); if(!$root)throw new RuntimeException('import_missing');
  $pf=findProductFile($root); if(!$pf)throw new RuntimeException('product_csv_missing');
  $rows=csvRows($pf); if(!$rows)throw new RuntimeException('empty_csv');
  $sample=array_slice($rows,0,min(5000,count($rows))); $maxCols=0; foreach($sample as $r)$maxCols=max($maxCols,count($r));
  $columns=[];
  for($i=0;$i<$maxCols;$i++){
    $nonblank=$numeric=$zero=$ones=$positive=$negative=$decimal=0; $min=null;$max=null;$freq=[];
    foreach($sample as $r){
      $raw=trim((string)($r[$i]??'')); if($raw==='')continue; $nonblank++;
      $clean=str_replace(["\xc2\xa0",' ', ','],['','','.'],$raw);
      if(!preg_match('/^-?\d+(?:\.\d+)?$/u',$clean))continue;
      $numeric++; $v=(float)$clean; if(floor($v)!=$v)$decimal++; if($v===0.0)$zero++; if($v===1.0)$ones++; if($v>0)$positive++; if($v<0)$negative++; $min=$min===null?$v:min($min,$v); $max=$max===null?$v:max($max,$v); $k=(string)$v; if(count($freq)<50||isset($freq[$k]))$freq[$k]=($freq[$k]??0)+1;
    }
    arsort($freq); $top=[]; foreach(array_slice($freq,0,12,true) as $v=>$n)$top[]=['v'=>$v,'n'=>$n];
    $columns[]=['index'=>$i,'column'=>$i+1,'nonblank'=>$nonblank,'numeric'=>$numeric,'numeric_ratio'=>$nonblank?round($numeric/$nonblank,4):0,'zero'=>$zero,'ones'=>$ones,'positive'=>$positive,'negative'=>$negative,'decimal'=>$decimal,'min'=>$min,'max'=>$max,'unique_seen'=>count($freq),'top_values'=>$top];
  }
  out(['ok'=>true,'file'=>basename($pf),'rows'=>count($rows),'sample_rows'=>count($sample),'columns'=>$columns]);
}catch(Throwable $e){error_log($e->__toString());out(['ok'=>false,'error'=>$e->getMessage()],500);}