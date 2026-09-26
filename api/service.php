<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
try{
  if($_SERVER['REQUEST_METHOD']==='GET'){
    require_admin();$s=$pdo->query('SELECT sr.id,sr.request_number,sr.customer_id,sr.vehicle_id,sr.name,sr.phone,sr.service_type,sr.bike,sr.problem,sr.status,sr.source,sr.created_at,v.title vehicle_title FROM service_requests sr LEFT JOIN customer_vehicles v ON v.id=sr.vehicle_id ORDER BY sr.id DESC LIMIT 100');$items=$s->fetchAll();
    $h=$pdo->prepare('SELECT status,source,created_at FROM service_request_status_history WHERE service_request_id=? ORDER BY id');
    foreach($items as &$item){ensure_service_request_history($pdo,(int)$item['id']);$h->execute([(int)$item['id']]);$item['history']=$h->fetchAll();}unset($item);
    json_response(['ok'=>true,'items'=>$items]);
  }
  if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
  $in=input_json();
  if(($_GET['action']??'')==='status'){
    $admin=require_admin();csrf_check();$id=(int)($in['id']??0);$status=$in['status']??'';
    if(!in_array($status,['new','contacted','accepted','diagnostics','repair','ready','completed','cancelled'],true)||$id<1)json_response(['ok'=>false,'error'=>'invalid_input'],422);
    $pdo->beginTransaction();$q=$pdo->prepare('SELECT status FROM service_requests WHERE id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetchColumn();if($old===false){$pdo->rollBack();json_response(['ok'=>false,'error'=>'not_found'],404);}
    ensure_service_request_history($pdo,$id);
    if((string)$old!==$status){$s=$pdo->prepare('UPDATE service_requests SET status=? WHERE id=?');$s->execute([$status,$id]);record_service_request_status($pdo,$id,$status,'admin',(int)$admin['id']);}
    $pdo->commit();audit($pdo,'service_status','service_request',(string)$id,['from'=>$old,'to'=>$status]);json_response(['ok'=>true]);
  }
  auth_rate_check($pdo,'service_request','',10,3600);auth_rate_failure($pdo,'service_request','',10,3600,3600);
  $out=[];
  foreach(['name'=>200,'phone'=>40,'type'=>100,'bike'=>300,'problem'=>4000] as $key=>$limit){if(!is_string($in[$key]??''))json_response(['ok'=>false,'error'=>'invalid_input'],422);$out[$key]=trim($in[$key]??'');if(mb_strlen($out[$key])>$limit)json_response(['ok'=>false,'error'=>'invalid_input'],422);}
  $phone=preg_replace('/\D/','',$out['phone']);$key=$in['request_key']??'';
  if(mb_strlen($out['name'])<2||mb_strlen($out['problem'])<5||!preg_match('/^[78][0-9]{10}$/',$phone)||!is_string($key)||!preg_match('/^[a-f0-9]{64}$/',$key))json_response(['ok'=>false,'error'=>'invalid_input'],422);
  $hash=hash('sha256',json_encode($out,JSON_UNESCAPED_UNICODE));
  $number='SV-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(5)));
  $s=$pdo->prepare("INSERT INTO service_requests(source,request_number,name,phone,service_type,bike,problem,request_key,request_hash) VALUES('public',?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
  $s->execute([$number,$out['name'],'+7'.substr($phone,1),$out['type'],$out['bike'],$out['problem'],$key,$hash]);
  $s=$pdo->prepare('SELECT id,request_number,request_hash FROM service_requests WHERE request_key=?');$s->execute([$key]);$row=$s->fetch();
  if(!$row||!hash_equals($row['request_hash'],$hash))json_response(['ok'=>false,'error'=>'request_conflict'],409);
  ensure_service_request_history($pdo,(int)$row['id']);
  json_response(['ok'=>true,'request_number'=>$row['request_number']]);
}catch(Throwable $e){error_log($e->__toString());json_response(['ok'=>false,'error'=>'server_error'],500);}
