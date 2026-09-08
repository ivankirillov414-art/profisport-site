<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
try{
  if($_SERVER['REQUEST_METHOD']==='GET'){
    require_admin();$s=$pdo->query('SELECT id,request_number,name,phone,service_type,bike,problem,status,created_at FROM service_requests ORDER BY id DESC LIMIT 100');json_response(['ok'=>true,'items'=>$s->fetchAll()]);
  }
  if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
  $in=input_json();
  if(($_GET['action']??'')==='status'){
    require_admin();csrf_check();$id=(int)($in['id']??0);$status=$in['status']??'';
    if(!in_array($status,['new','contacted','completed','cancelled'],true)||$id<1)json_response(['ok'=>false,'error'=>'invalid_input'],422);
    $s=$pdo->prepare('UPDATE service_requests SET status=? WHERE id=?');$s->execute([$status,$id]);audit($pdo,'service_status','service_request',(string)$id,['status'=>$status]);json_response(['ok'=>true]);
  }
  $out=[];
  foreach(['name'=>200,'phone'=>40,'type'=>100,'bike'=>300,'problem'=>4000] as $key=>$limit){if(!is_string($in[$key]??''))json_response(['ok'=>false,'error'=>'invalid_input'],422);$out[$key]=trim($in[$key]??'');if(mb_strlen($out[$key])>$limit)json_response(['ok'=>false,'error'=>'invalid_input'],422);}
  $phone=preg_replace('/\D/','',$out['phone']);$key=$in['request_key']??'';
  if(mb_strlen($out['name'])<2||mb_strlen($out['problem'])<5||!preg_match('/^[78][0-9]{10}$/',$phone)||!is_string($key)||!preg_match('/^[a-f0-9]{64}$/',$key))json_response(['ok'=>false,'error'=>'invalid_input'],422);
  $hash=hash('sha256',json_encode($out,JSON_UNESCAPED_UNICODE));
  $number='SV-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(5)));
  $s=$pdo->prepare('INSERT INTO service_requests(request_number,name,phone,service_type,bike,problem,request_key,request_hash) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
  $s->execute([$number,$out['name'],'+7'.substr($phone,1),$out['type'],$out['bike'],$out['problem'],$key,$hash]);
  $s=$pdo->prepare('SELECT request_number,request_hash FROM service_requests WHERE request_key=?');$s->execute([$key]);$row=$s->fetch();
  if(!$row||!hash_equals($row['request_hash'],$hash))json_response(['ok'=>false,'error'=>'request_conflict'],409);
  json_response(['ok'=>true,'request_number'=>$row['request_number']]);
}catch(Throwable $e){error_log($e->__toString());json_response(['ok'=>false,'error'=>'server_error'],500);}
