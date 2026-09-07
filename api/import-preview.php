<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
try{
 require_admin();
 $dir=realpath(__DIR__.'/../import'); $name=basename((string)($_GET['file']??'')); $path=$dir?$dir.DIRECTORY_SEPARATOR.$name:'';
 if(!$dir||!$name||!is_file($path)||strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='csv'){http_response_code(404);echo json_encode(['error'=>'csv_not_found']);exit;}
 $raw=file_get_contents($path, false, null, 0, 65536); if($raw===false)throw new RuntimeException('read_failed');
 if(str_starts_with($raw,"\xEF\xBB\xBF"))$raw=substr($raw,3); $encoding=mb_detect_encoding($raw,['UTF-8','Windows-1251','CP1251'],true)?:'UTF-8'; if($encoding!=='UTF-8')$raw=mb_convert_encoding($raw,'UTF-8',$encoding);
 $first=preg_split('/\R/u',$raw,2)[0]??''; $counts=[';'=>substr_count($first,';'),','=>substr_count($first,','),"\t"=>substr_count($first,"\t")]; arsort($counts); $delimiter=(string)array_key_first($counts);
 $tmp=fopen('php://temp','r+');fwrite($tmp,$raw);rewind($tmp);$headers=fgetcsv($tmp,0,$delimiter)?:[];if(isset($headers[0]))$headers[0]=preg_replace('/^\xEF\xBB\xBF/','',$headers[0]);
 $norm=fn($s)=>preg_replace('/[^a-zа-я0-9]+/u','',mb_strtolower(trim((string)$s)));
 $aliases=['external_id'=>['id','guid','uuid','код','кодтовара','ид'],'sku'=>['sku','артикул','арт'],'barcode'=>['barcode','штрихкод','штрихкодтовара'],'title'=>['name','title','наименование','название','товар'],'category'=>['category','категория','группа'],'price'=>['price','цена','розничнаяцена','ценарозница'],'stock'=>['stock','остаток','количество','остатоксклад'],'unit'=>['unit','единица','едизм'],'brand'=>['brand','бренд','производитель'],'model'=>['model','модель'],'description'=>['description','описание']];
 $mapping=[];foreach($headers as $i=>$h){$n=$norm($h);foreach($aliases as $field=>$vals){foreach($vals as $a){if($n===$norm($a)){$mapping[$field]=['index'=>$i,'column'=>$h];break 2;}}}}
 $rows=[];while(($r=fgetcsv($tmp,0,$delimiter))!==false&&count($rows)<20){if(count(array_filter($r,fn($v)=>trim((string)$v)!=='')))$rows[]=$r;}fclose($tmp);
 echo json_encode(['file'=>$name,'encoding'=>$encoding,'delimiter'=>$delimiter==="\t"?'TAB':$delimiter,'headers'=>$headers,'mapping'=>$mapping,'preview'=>$rows],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){error_log($e->__toString());http_response_code(500);echo json_encode(['error'=>'server_error']);}
