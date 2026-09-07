<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function n(string $s): string{return preg_replace('/[^a-zа-я0-9]+/u','',mb_strtolower(trim($s)))??'';}
function enc(string $s): string{$e=mb_detect_encoding($s,['UTF-8','Windows-1251','CP1251'],true)?:'UTF-8';return $e==='UTF-8'?$s:mb_convert_encoding($s,'UTF-8',$e);}
function csv_data(string $p): array{$raw=file_get_contents($p);if($raw===false)throw new RuntimeException('read_failed');$raw=enc($raw);$first=preg_split('/\R/u',$raw,2)[0]??'';$cc=[';'=>substr_count($first,';'),','=>substr_count($first,','),"\t"=>substr_count($first,"\t")];arsort($cc);$d=(string)array_key_first($cc);$f=fopen('php://temp','r+');fwrite($f,$raw);rewind($f);$h=fgetcsv($f,0,$d)?:[];$r=[];while(($x=fgetcsv($f,0,$d))!==false){if(array_filter($x,fn($v)=>trim((string)$v)!==''))$r[]=$x;}fclose($f);return[$h,$r];}
function col(array $h,array $names): ?int{foreach($h as $i=>$v){$x=n((string)$v);foreach($names as $a)if($x===n($a))return $i;}return null;}
function val(array $r,?int $i): string{return $i===null?'':trim((string)($r[$i]??''));}
function money(string $v): float{$v=str_replace([' ','\xc2\xa0',','],['','','.'],$v);return is_numeric($v)?(float)$v:0.0;}
try{
 require_admin();
 if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('post_required');
 $root=realpath(__DIR__.'/../import');if(!$root)throw new RuntimeException('import_missing');
 $product=null;$category=null;$images=[];
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
 foreach($it as $f){if(!$f->isFile())continue;$name=mb_strtolower($f->getFilename());$ext=mb_strtolower($f->getExtension());if($ext==='csv'&&str_contains($name,'tovary'))$product=$f->getPathname();elseif($ext==='csv'&&str_contains($name,'categor'))$category=$f->getPathname();elseif(in_array($ext,['jpg','jpeg','png','webp'],true)){$base=mb_strtolower($f->getFilename());$stem=mb_strtolower(pathinfo($base,PATHINFO_FILENAME));$rel=str_replace(DIRECTORY_SEPARATOR,'/',substr($f->getPathname(),strlen($root)+1));$images[$base]=$rel;$images[$stem]=$rel;}}
 if(!$product)throw new RuntimeException('products_csv_missing');
 [$h,$rows]=csv_data($product);
 $id=col($h,['id','guid','uuid','код','код товара','ид','идентификатор']);$sku=col($h,['sku','артикул','арт','vendorcode']);$barcode=col($h,['barcode','штрихкод','ean']);$title=col($h,['name','title','наименование','название','товар']);$price=col($h,['price','цена','розничная цена']);$cat=col($h,['category','категория','группа','cat_id','category_id']);
 if($title===null)throw new RuntimeException('title_column_missing');
 $imgCols=[];foreach($h as $i=>$x)if(preg_match('/(image|photo|picture|картин|изображ|фото)/u',n((string)$x)))$imgCols[]=$i;
 $pdo->beginTransaction();$created=0;$updated=0;$linked=0;
 foreach($rows as $r){$ext=val($r,$id);$s=val($r,$sku);$b=val($r,$barcode);$t=val($r,$title);if($t==='')continue;$p=money(val($r,$price));$cid=null;
  $found=null;foreach([['external_id',$ext],['sku',$s],['barcode',$b]] as [$field,$v]){if($v==='')continue;try{$q=$pdo->prepare("SELECT id FROM products WHERE $field=? LIMIT 1");$q->execute([$v]);$found=$q->fetchColumn();if($found)break;}catch(Throwable $e){}}
  if($found){$q=$pdo->prepare('UPDATE products SET title=?,price=?,updated_at=NOW() WHERE id=?');$q->execute([$t,$p,$found]);$pid=(int)$found;$updated++;}
  else{$q=$pdo->prepare('INSERT INTO products(title,price,sku,barcode,external_id,created_at,updated_at) VALUES(?,?,?,?,?,NOW(),NOW())');$q->execute([$t,$p,$s?:null,$b?:null,$ext?:null]);$pid=(int)$pdo->lastInsertId();$created++;}
  $refs=[];foreach($imgCols as $ci){foreach(preg_split('/[;,|\r\n]+/u',val($r,$ci))?:[] as $x){$x=basename(str_replace('\\','/',trim($x," \t\n\r\0\x0B\"'")));if($x!=='')$refs[]=$x;}}
  foreach(array_unique($refs) as $x){$k=mb_strtolower($x);$st=mb_strtolower(pathinfo($k,PATHINFO_FILENAME));$rel=$images[$k]??$images[$st]??null;if(!$rel)continue;try{$q=$pdo->prepare('INSERT IGNORE INTO product_images(product_id,url,sort_order) VALUES(?,?,?)');$q->execute([$pid,'/import/'.$rel,$linked]);$linked+=(int)$q->rowCount();}catch(Throwable $e){}}
 }
 $pdo->commit();
 echo json_encode(['ok'=>true,'source'=>basename($product),'rows'=>count($rows),'created'=>$created,'updated'=>$updated,'images_linked'=>$linked,'categories_file'=>$category?basename($category):null],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
