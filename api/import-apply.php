<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
@set_time_limit(0);

function jexit(array $x,int $code=200): never { http_response_code($code); echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function norm(string $s): string { return preg_replace('/[^a-zа-я0-9]+/u','',mb_strtolower(trim($s)))??''; }
function toUtf8(string $s): string { $e=mb_detect_encoding($s,['UTF-8','Windows-1251','CP1251'],true)?:'UTF-8'; return $e==='UTF-8'?$s:mb_convert_encoding($s,'UTF-8',$e); }
function readCsv(string $path): array {
  $raw=file_get_contents($path); if($raw===false) throw new RuntimeException('read_failed');
  if(str_starts_with($raw,"\xEF\xBB\xBF")) $raw=substr($raw,3); $raw=toUtf8($raw);
  $first=preg_split('/\R/u',$raw,2)[0]??''; $c=[';'=>substr_count($first,';'),','=>substr_count($first,','),"\t"=>substr_count($first,"\t")]; arsort($c); $d=(string)array_key_first($c);
  $f=fopen('php://temp','r+'); fwrite($f,$raw); rewind($f); $h=fgetcsv($f,0,$d)?:[]; $rows=[];
  while(($r=fgetcsv($f,0,$d))!==false){ if(count(array_filter($r,fn($v)=>trim((string)$v)!==''))) $rows[]=$r; }
  fclose($f); return [$h,$rows];
}
function findCol(array $h,array $aliases): ?int { foreach($h as $i=>$v){$n=norm((string)$v);foreach($aliases as $a)if($n===norm($a))return $i;} return null; }
function v(array $r,?int $i): string { return $i===null?'':trim((string)($r[$i]??'')); }
function rub(string $x): int { $x=preg_replace('/[^0-9,.-]/u','',$x)??''; $x=str_replace(',','.',$x); return is_numeric($x)?(int)round((float)$x):0; }
function inferTitle(array $h,array $rows): ?int {
  $best=null;$bestScore=-1.0;$sample=array_slice($rows,0,250);
  foreach($h as $i=>$unused){$text=0;$nonempty=0;$unique=[];foreach($sample as $r){$x=trim((string)($r[$i]??''));if($x==='')continue;$nonempty++;$unique[$x]=1;if(mb_strlen($x)>=3&&!is_numeric(str_replace([',','.',' '],'',$x)))$text++;}if(!$nonempty)continue;$score=($text/$nonempty)+(count($unique)/$nonempty)*0.25;if($score>$bestScore){$bestScore=$score;$best=$i;}}
  return $best;
}
function authImport(array $config): void {
  $supplied=(string)($_SERVER['HTTP_X_IMPORT_TOKEN']??''); $expected=(string)($config['import_token']??'');
  if($expected!==''&&$supplied!==''&&hash_equals($expected,$supplied)) return;
  require_admin(); if($_SERVER['REQUEST_METHOD']==='POST') csrf_check();
}

try {
  authImport($config);
  if($_SERVER['REQUEST_METHOD']!=='POST') throw new RuntimeException('post_required');
  $root=realpath(__DIR__.'/../import'); if(!$root) throw new RuntimeException('import_missing');
  $productFile=null;$categoryFile=null;$imageMap=[];$imageFiles=0;
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
  foreach($it as $f){
    if(!$f->isFile())continue; $name=mb_strtolower($f->getFilename());$ext=mb_strtolower($f->getExtension());
    if($ext==='csv'&&str_contains($name,'tovary'))$productFile=$f->getPathname();
    elseif($ext==='csv'&&(str_contains($name,'categor')||str_contains($name,'category'))) $categoryFile=$f->getPathname();
    elseif(in_array($ext,['jpg','jpeg','png','webp','gif','avif'],true)){
      $imageFiles++;$base=mb_strtolower($f->getFilename());$stem=mb_strtolower(pathinfo($base,PATHINFO_FILENAME));$rel=str_replace(DIRECTORY_SEPARATOR,'/',substr($f->getPathname(),strlen($root)+1));$imageMap[$base]=$rel;$imageMap[$stem]=$rel;
    }
  }
  if(!$productFile) throw new RuntimeException('products_csv_missing');
  [$h,$rows]=readCsv($productFile); $total=count($rows);
  $offset=max(0,(int)($_GET['offset']??0));$limit=min(1000,max(100,(int)($_GET['limit']??500)));$slice=array_slice($rows,$offset,$limit);

  $id=findCol($h,['id','guid','uuid','код','код товара','ид','идентификатор','id товара','id элемента']);
  $sku=findCol($h,['sku','артикул','арт','vendorcode','article']);$barcode=findCol($h,['barcode','штрихкод','штрих код','ean']);
  $title=findCol($h,['name','title','наименование','название','товар','название товара']); if($title===null)$title=inferTitle($h,$rows);
  $price=findCol($h,['price','цена','розничная цена','цена продажи','price_rub']);
  $cat=findCol($h,['category','категория','группа','cat_id','category_id','id категории','раздел']);
  if($title===null) throw new RuntimeException('title_column_missing');

  $categories=[];
  if($categoryFile){
    [$ch,$cr]=readCsv($categoryFile);$cid=findCol($ch,['id','код','category_id','id категории']);$cn=findCol($ch,['name','title','наименование','название','категория']);$cp=findCol($ch,['parent_id','родитель','parent','id родителя']);
    if($cn===null)$cn=inferTitle($ch,$cr);
    foreach($cr as $r){$key=v($r,$cid);$name=v($r,$cn);if($name==='')continue;if($key==='')$key=$name;$categories[$key]=['name'=>$name,'parent'=>v($r,$cp)];}
  }
  $catPath=function(string $key)use(&$categories):string{if($key===''||!isset($categories[$key]))return $key;$parts=[];$seen=[];while($key!==''&&isset($categories[$key])&&!isset($seen[$key])){$seen[$key]=1;array_unshift($parts,$categories[$key]['name']);$key=$categories[$key]['parent'];}return implode(' / ',$parts);};

  if($offset===0){$pdo->exec('UPDATE products SET is_active=0');}
  $sel=$pdo->prepare('SELECT id FROM products WHERE source_hash=? LIMIT 1');
  $ins=$pdo->prepare('INSERT INTO products(source_hash,title,price_rub,availability,category_path,images,is_active) VALUES(?,?,?,?,?,?,1)');
  $upd=$pdo->prepare('UPDATE products SET title=?,price_rub=?,availability=?,category_path=?,images=?,is_active=1,updated_at=CURRENT_TIMESTAMP WHERE id=?');
  $created=0;$updated=0;$withImages=0;$linkedImages=0;
  $pdo->beginTransaction();
  foreach($slice as $r){
    $t=v($r,$title); if($t==='')continue;$ident='';foreach([$id,$sku,$barcode] as $ci){$z=v($r,$ci);if($z!==''){$ident=$z;break;}}
    $sourceHash=hash('sha256','1c|'.($ident!==''?$ident:json_encode($r,JSON_UNESCAPED_UNICODE)));
    $p=rub(v($r,$price));$category=$catPath(v($r,$cat));
    $matches=[];$tokens=[];
    foreach($r as $cell){foreach(preg_split('/[;,|\r\n\s]+/u',(string)$cell)?:[] as $part){$part=trim($part," \t\n\r\0\x0B\"'");if($part==='')continue;$base=mb_strtolower(basename(str_replace('\\','/',$part)));$stem=mb_strtolower(pathinfo($base,PATHINFO_FILENAME));if(isset($imageMap[$base]))$matches[]=$imageMap[$base];elseif(isset($imageMap[$stem]))$matches[]=$imageMap[$stem];if(mb_strlen($stem)>=6)$tokens[$stem]=1;}}
    foreach([$id,$sku,$barcode] as $ci){$z=mb_strtolower(v($r,$ci));if($z!==''&&mb_strlen($z)>=4){if(isset($imageMap[$z]))$matches[]=$imageMap[$z];foreach(['-1','_1','-01','_01'] as $suf)if(isset($imageMap[$z.$suf]))$matches[]=$imageMap[$z.$suf];}}
    $matches=array_values(array_unique($matches));if($matches){$withImages++;$linkedImages+=count($matches);} $images=json_encode(array_map(fn($x)=>'/import/'.$x,$matches),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $sel->execute([$sourceHash]);$pid=$sel->fetchColumn();
    if($pid){$upd->execute([$t,$p,'available',$category,$images,(int)$pid]);$updated++;}
    else{$ins->execute([$sourceHash,$t,$p,'available',$category,$images]);$created++;}
  }
  $pdo->commit();
  $next=$offset+count($slice);$done=$next>=$total;
  if($done){$s=$pdo->prepare("INSERT INTO site_settings(setting_key,setting_value) VALUES('last_1c_import',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");$s->execute([json_encode(['file'=>basename($productFile),'rows'=>$total,'finished_at'=>date('c'),'image_files'=>$imageFiles,'category_rows'=>count($categories)],JSON_UNESCAPED_UNICODE)]);audit($pdo,'import_1c_complete','products',null,['rows'=>$total]);}
  jexit(['ok'=>true,'source'=>basename($productFile),'category_source'=>$categoryFile?basename($categoryFile):null,'headers'=>$offset===0?$h:null,'offset'=>$offset,'processed'=>count($slice),'total_rows'=>$total,'created'=>$created,'updated'=>$updated,'rows_with_images'=>$withImages,'linked_images'=>$linkedImages,'image_files'=>$imageFiles,'category_rows'=>count($categories),'next_offset'=>$next,'done'=>$done]);
} catch(Throwable $e){ if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());jexit(['ok'=>false,'error'=>$e->getMessage()],500); }
