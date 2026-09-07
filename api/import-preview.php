<?php
require __DIR__.'/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['admin_id'])) { http_response_code(401); echo json_encode(['error'=>'auth']); exit; }
$dir=realpath(__DIR__.'/../import');
$name=basename((string)($_GET['file']??''));
$path=$dir ? $dir.DIRECTORY_SEPARATOR.$name : '';
if(!$dir || !$name || !is_file($path) || strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='csv'){http_response_code(404);echo json_encode(['error'=>'csv_not_found']);exit;}
$fh=fopen($path,'rb'); if(!$fh){http_response_code(500);echo json_encode(['error'=>'open_failed']);exit;}
$sample=fread($fh,65536); rewind($fh);
if(str_starts_with($sample,"\xEF\xBB\xBF")) $sample=substr($sample,3);
if(!mb_check_encoding($sample,'UTF-8')) $sample=mb_convert_encoding($sample,'UTF-8','Windows-1251,UTF-8');
$first=strtok($sample,"\r\n"); $delims=[';'=>substr_count($first,';'),','=>substr_count($first,','),'\t'=>substr_count($first,"\t")]; arsort($delims); $delimiter=array_key_first($delims); if($delimiter==='\t')$delimiter="\t";
$headers=fgetcsv($fh,0,$delimiter); if(isset($headers[0]))$headers[0]=preg_replace('/^\xEF\xBB\xBF/','',$headers[0]);
$norm=function($s){$s=mb_strtolower(trim((string)$s));return preg_replace('/[^a-zа-я0-9]+/u','',$s);};
$aliases=['external_id'=>['id','guid','uuid','код','кодтовара','ид'], 'sku'=>['sku','артикул','арт'], 'barcode'=>['barcode','штрихкод','штрихкодтовара'], 'title'=>['name','title','наименование','название','товар'], 'category'=>['category','категория','группа'], 'price'=>['price','цена','розничнаяцена','ценарозница'], 'stock'=>['stock','остаток','количество','остатоксклад'], 'unit'=>['unit','единица','едизм'], 'brand'=>['brand','бренд','производитель'], 'model'=>['model','модель'], 'description'=>['description','описание']];
$mapping=[]; foreach($headers?:[] as $i=>$h){$n=$norm($h);foreach($aliases as $field=>$vals){foreach($vals as $a){if($n===$norm($a)){ $mapping[$field]=['index'=>$i,'column'=>$h]; break 2;}}}}
$rows=[];$count=0;while(($r=fgetcsv($fh,0,$delimiter))!==false && $count<20){if(count(array_filter($r,fn($v)=>trim((string)$v)!==''))===0)continue;$rows[]=$r;$count++;} fclose($fh);
echo json_encode(['file'=>$name,'delimiter'=>$delimiter==='\t'?'TAB':$delimiter,'headers'=>$headers,'mapping'=>$mapping,'preview'=>$rows],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
