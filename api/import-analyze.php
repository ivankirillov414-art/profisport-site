<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function norm(string $s): string { return preg_replace('/[^a-zа-я0-9]+/u','',mb_strtolower(trim($s))) ?? ''; }
function utf8(string $s): string { $e=mb_detect_encoding($s,['UTF-8','Windows-1251','CP1251'],true)?:'UTF-8'; return $e==='UTF-8'?$s:mb_convert_encoding($s,'UTF-8',$e); }
function aliases(): array { return [
 'external_id'=>['id','guid','uuid','код','кодтовара','ид','идентификатор'],
 'sku'=>['sku','артикул','арт','vendorcode'], 'barcode'=>['barcode','штрихкод','штрихкодтовара','ean'],
 'title'=>['name','title','наименование','название','товар'], 'category'=>['category','категория','группа'],
 'price'=>['price','цена','розничнаяцена','ценарозница'], 'stock'=>['stock','остаток','количество','остатоксклад'],
 'image'=>['image','images','photo','photos','picture','pictures','картинка','картинки','изображение','изображения','фото','фотографии','файлфото','имяфайла']
 ]; }
function mapHeaders(array $headers): array { $out=[];$aa=aliases(); foreach($headers as $i=>$h){$n=norm((string)$h);foreach($aa as $field=>$vals){foreach($vals as $a){if($n===norm($a)){$out[$field]=['index'=>$i,'column'=>(string)$h];break 2;}}}} return $out; }
function csvRows(string $path,int $limit=50000): array { $raw=file_get_contents($path); if($raw===false)throw new RuntimeException('read_failed'); if(str_starts_with($raw,"\xEF\xBB\xBF"))$raw=substr($raw,3); $raw=utf8($raw); $first=preg_split('/\R/u',$raw,2)[0]??''; $counts=[';'=>substr_count($first,';'),','=>substr_count($first,','),"\t"=>substr_count($first,"\t")]; arsort($counts);$d=(string)array_key_first($counts);$fh=fopen('php://temp','r+');fwrite($fh,$raw);rewind($fh);$headers=fgetcsv($fh,0,$d)?:[];$rows=[];while(($r=fgetcsv($fh,0,$d))!==false&&count($rows)<$limit){if(count(array_filter($r,fn($v)=>trim((string)$v)!=='')))$rows[]=$r;}fclose($fh);return [$headers,$rows]; }
function xlsxRows(string $path,int $limit=50000): array { if(!class_exists('ZipArchive'))throw new RuntimeException('zip_unavailable');$z=new ZipArchive();if($z->open($path)!==true)throw new RuntimeException('xlsx_open_failed');$shared=[];$sx=$z->getFromName('xl/sharedStrings.xml');if($sx){$xml=@simplexml_load_string($sx);if($xml){foreach($xml->si as $si){$texts=[];if(isset($si->t))$texts[]=(string)$si->t;foreach($si->r as $r)$texts[]=(string)$r->t;$shared[]=implode('',$texts);}}}$sheet=$z->getFromName('xl/worksheets/sheet1.xml');$z->close();if(!$sheet)throw new RuntimeException('sheet1_missing');$xml=@simplexml_load_string($sheet);if(!$xml)throw new RuntimeException('sheet_parse_failed');$all=[];foreach($xml->sheetData->row as $row){$vals=[];$max=-1;foreach($row->c as $c){$ref=(string)$c['r'];preg_match('/([A-Z]+)/',$ref,$m);$letters=$m[1]??'A';$idx=0;for($i=0;$i<strlen($letters);$i++)$idx=$idx*26+(ord($letters[$i])-64);$idx--;$t=(string)$c['t'];$v=(string)$c->v;$val=$t==='s'?(string)($shared[(int)$v]??''):$v;$vals[$idx]=$val;$max=max($max,$idx);}if($max>=0){$dense=[];for($i=0;$i<=$max;$i++)$dense[]=$vals[$i]??'';$all[]=$dense;}if(count($all)>$limit+1)break;}if(!$all)return [[],[]];$headers=array_shift($all);return [$headers,$all]; }
function refsFromCell(string $v): array { $parts=preg_split('/[;,|\r\n]+/u',$v)?:[];$out=[];foreach($parts as $p){$p=trim($p," \t\n\r\0\x0B\"'");if($p==='')continue;$p=basename(str_replace('\\','/',$p));$out[]=$p;}return array_values(array_unique($out)); }

try{
 require_admin();
 $root=realpath(__DIR__.'/../import'); if(!$root)throw new RuntimeException('import_missing');
 $images=[];$tables=[];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
 foreach($it as $f){if(!$f->isFile())continue;$ext=strtolower($f->getExtension());$rel=str_replace(DIRECTORY_SEPARATOR,'/',substr($f->getPathname(),strlen($root)+1));if(in_array($ext,['jpg','jpeg','png','webp','gif','avif'],true)){$base=$f->getFilename();$stem=pathinfo($base,PATHINFO_FILENAME);$images[mb_strtolower($base)]=$rel;$images[mb_strtolower($stem)]=$rel;}elseif(in_array($ext,['csv','xlsx'],true))$tables[]=['path'=>$f->getPathname(),'relative'=>$rel,'ext'=>$ext];}
 $report=[];$totalRows=0;$totalDirect=0;$totalIdentifier=0;$samples=[];
 foreach($tables as $t){[$headers,$rows]=$t['ext']==='csv'?csvRows($t['path']):xlsxRows($t['path']);$map=mapHeaders($headers);$imageCols=[];foreach($headers as $i=>$h){$n=norm((string)$h);if(preg_match('/(image|photo|picture|картин|изображ|фото|файл)/u',$n))$imageCols[]=$i;}if(isset($map['image']))$imageCols[]=$map['image']['index'];$imageCols=array_values(array_unique($imageCols));$direct=0;$identifier=0;$rowSamples=[];
  foreach($rows as $r){$totalRows++;$matched=[];foreach($imageCols as $ci){foreach(refsFromCell((string)($r[$ci]??'')) as $ref){$k=mb_strtolower($ref);$stem=mb_strtolower(pathinfo($ref,PATHINFO_FILENAME));if(isset($images[$k]))$matched[]=$images[$k];elseif(isset($images[$stem]))$matched[]=$images[$stem];}}
   if($matched){$direct++;$totalDirect++;}else{foreach(['external_id','sku','barcode'] as $key){if(!isset($map[$key]))continue;$id=trim((string)($r[$map[$key]['index']]??''));if($id==='')continue;$needle=mb_strtolower($id);foreach($images as $ik=>$path){if(strlen($needle)>=6&&str_contains($ik,$needle)){$matched[]=$path;break 2;}}}if($matched){$identifier++;$totalIdentifier++;}}
   if($matched&&count($rowSamples)<8){$rowSamples[]=['title'=>isset($map['title'])?(string)($r[$map['title']['index']]??''):'','sku'=>isset($map['sku'])?(string)($r[$map['sku']['index']]??''):'','images'=>array_values(array_unique($matched))];}
  }
  $report[]=['file'=>$t['relative'],'rows'=>count($rows),'headers'=>$headers,'mapping'=>$map,'image_columns'=>array_map(fn($i)=>$headers[$i]??('col_'.$i),$imageCols),'direct_matches'=>$direct,'identifier_matches'=>$identifier,'samples'=>$rowSamples];$samples=array_merge($samples,$rowSamples);
 }
 echo json_encode(['ok'=>true,'tables'=>$report,'image_files'=>(int)(count($images)/2),'rows'=>$totalRows,'direct_matches'=>$totalDirect,'identifier_matches'=>$totalIdentifier,'matched_rows'=>$totalDirect+$totalIdentifier,'samples'=>array_slice($samples,0,12)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){error_log($e->__toString());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'server_error','detail'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
