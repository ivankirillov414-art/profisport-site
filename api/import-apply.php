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
function authImport(array $config): void { $g=(string)($_SERVER['HTTP_X_IMPORT_TOKEN']??''); $e=(string)($config['import_token']??''); if($e!==''&&$g!==''&&hash_equals($e,$g))return; require_admin(); if($_SERVER['REQUEST_METHOD']==='POST')csrf_check(); }
function findFiles(string $root): array { $p=[];$c=[]; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)); foreach($it as $f){ if(!$f->isFile()||strtolower($f->getExtension())!=='csv')continue; $n=mb_strtolower($f->getFilename()); $x=['p'=>$f->getPathname(),'s'=>$f->getSize(),'m'=>$f->getMTime()]; if(str_contains($n,'tovary'))$p[]=$x; elseif(str_contains($n,'categor'))$c[]=$x; } $pick=function(array $a):?string{ if(!$a)return null; usort($a,fn($x,$y)=>($y['s']<=>$x['s'])?:($y['m']<=>$x['m'])); return $a[0]['p']; }; return[$pick($p),$pick($c)]; }
function imageIndex(string $root,int $offset): array { $cache=__DIR__.'/../uploads/1c-image-index.json'; if($offset>0&&is_file($cache)){ $j=json_decode((string)file_get_contents($cache),true); if(is_array($j)&&isset($j['map']))return[$j['map'],(int)($j['count']??count($j['map']))]; } $map=[];$count=0; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)); foreach($it as $f){ if(!$f->isFile())continue; $e=strtolower($f->getExtension()); if(!in_array($e,['jpg','jpeg','png','webp','gif','avif'],true))continue; $count++; $map[mb_strtolower($f->getFilename())]=str_replace(DIRECTORY_SEPARATOR,'/',substr($f->getPathname(),strlen($root)+1)); } if(!is_dir(dirname($cache)))@mkdir(dirname($cache),0755,true); @file_put_contents($cache,json_encode(['count'=>$count,'map'=>$map],JSON_UNESCAPED_SLASHES)); return[$map,$count]; }
function photoUrls(string $cell,array $idx): array { $out=[]; foreach(preg_split('/[|,\r\n]+/u',$cell)?:[] as $x){ $x=mb_strtolower(basename(str_replace('\\','/',trim($x," \t\"'")))); if($x!==''&&isset($idx[$x]))$out[]='/import/'.$idx[$x]; } return array_values(array_unique($out)); }
function money(string $x): float { $x=str_replace(',','.',preg_replace('/[^0-9,.-]/u','',$x)??''); return is_numeric($x)?(float)$x:0.0; }
function slugify(string $s,string $fallback): string { $s=mb_strtolower(trim($s)); $s=preg_replace('/[^a-zа-я0-9]+/u','-',$s)??''; $s=trim($s,'-'); return $s!==''?$s:$fallback; }
try{
 authImport($config); if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('post_required');
 $root=realpath(__DIR__.'/../import'); if(!$root)throw new RuntimeException('import_missing'); [$pf,$cf]=findFiles($root); if(!$pf||!$cf)throw new RuntimeException('1c_csv_missing');
 $rows=csvRows($pf); $catsRaw=csvRows($cf); $total=count($rows); $offset=max(0,(int)($_GET['offset']??0)); $limit=min(1000,max(100,(int)($_GET['limit']??500))); $slice=array_slice($rows,$offset,$limit);
 $cats=[]; foreach($catsRaw as $r){ $id=trim((string)($r[0]??'')); $name=trim((string)($r[1]??'')); if($id===''||$name==='')continue; $cats[$id]=['name'=>$name,'parent'=>trim((string)($r[2]??''))]; }
 $catPath=function(string $id)use(&$cats):string{ $parts=[];$seen=[]; while($id!==''&&isset($cats[$id])&&!isset($seen[$id])){ $seen[$id]=1; array_unshift($parts,$cats[$id]['name']); $id=$cats[$id]['parent']; } return implode(' / ',$parts); };
 [$imgIndex,$imageFiles]=imageIndex($root,$offset);
 $bySource=$pdo->prepare('SELECT * FROM products WHERE source_id=? LIMIT 1');
 $byHash=$pdo->prepare('SELECT * FROM products WHERE source_hash=? LIMIT 1');
 $byName=$pdo->prepare('SELECT * FROM products WHERE name=? ORDER BY is_active DESC,id DESC LIMIT 1');
 $ins=$pdo->prepare('INSERT INTO products(source_id,source_hash,name,slug,sku,price,price_rub,stock_status,availability,category_path,main_image,images,is_active,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,1,NOW())');
 $upd=$pdo->prepare('UPDATE products SET source_id=?,source_hash=?,name=?,slug=?,sku=?,price=?,price_rub=?,category_path=?,main_image=?,images=?,is_active=1,updated_at=NOW() WHERE id=?');
 $created=0;$updated=0;$rowsWithPhoto=0;$photosLinked=0;
 $pdo->beginTransaction();
 foreach($slice as $r){
   $sourceId=trim((string)($r[4]??'')); $sku=trim((string)($r[5]??'')); $name=trim((string)($r[7]??'')); if($name==='')$name=trim((string)($r[6]??'')); if($name==='')continue;
   $hash=hash('sha256','1c|'.($sourceId!==''?$sourceId:json_encode($r,JSON_UNESCAPED_UNICODE))); $price=money((string)($r[10]??'')); $priceRub=(int)round($price); $category=$catPath(trim((string)($r[3]??''))); $urls=photoUrls((string)($r[9]??''),$imgIndex); if($urls){$rowsWithPhoto++;$photosLinked+=count($urls);} 
   $existing=false; if($sourceId!==''){ $bySource->execute([$sourceId]); $existing=$bySource->fetch(); } if(!$existing){$byHash->execute([$hash]);$existing=$byHash->fetch();} if(!$existing){$byName->execute([$name]);$existing=$byName->fetch();}
   $existingImages=[]; if($existing&&!empty($existing['images']))$existingImages=json_decode((string)$existing['images'],true)?:[]; $finalImages=$urls?:$existingImages; $main=$finalImages[0]??($existing['main_image']??null); $slug=$existing['slug']??slugify($name,'product-'.$sourceId);
   if($existing){$upd->execute([$sourceId?:($existing['source_id']??null),$hash,$name,$slug,$sku?:($existing['sku']??null),$price,$priceRub,$category?:($existing['category_path']??''),$main,json_encode($finalImages,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$existing['id']]);$updated++;}
   else{$ins->execute([$sourceId?:null,$hash,$name,$slug,$sku?:null,$price,$priceRub,'unknown','unknown',$category,$main,json_encode($finalImages,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$created++;}
 }
 $pdo->commit(); $next=$offset+count($slice); $done=$next>=$total;
 if($done){ $categoryPayload=[]; foreach($cats as $id=>$c)$categoryPayload[]=['id'=>$id,'name'=>$c['name'],'parent'=>$c['parent'],'path'=>$catPath($id)]; $meta=['file'=>basename($pf),'rows'=>$total,'category_rows'=>count($cats),'image_files'=>$imageFiles,'finished_at'=>date('c')]; $s=$pdo->prepare("INSERT INTO site_settings(setting_key,setting_value) VALUES('last_1c_import',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"); $s->execute([json_encode($meta,JSON_UNESCAPED_UNICODE)]); $s=$pdo->prepare("INSERT INTO site_settings(setting_key,setting_value) VALUES('1c_categories',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"); $s->execute([json_encode($categoryPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]); audit($pdo,'import_1c_complete','products',null,$meta); }
 out(['ok'=>true,'source'=>basename($pf),'category_source'=>basename($cf),'offset'=>$offset,'processed'=>count($slice),'total_rows'=>$total,'created'=>$created,'updated'=>$updated,'rows_with_photo'=>$rowsWithPhoto,'photos_linked'=>$photosLinked,'category_rows'=>count($cats),'image_files'=>$imageFiles,'next_offset'=>$next,'done'=>$done]);
}catch(Throwable $e){ if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack(); error_log($e->__toString()); out(['ok'=>false,'error'=>$e->getMessage()],500); }
