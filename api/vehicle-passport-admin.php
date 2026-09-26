<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
function out(array $x,int $c=200):never{http_response_code($c);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
  $admin=require_admin();$vehicleId=(int)($_GET['vehicle_id']??0);
  if($_SERVER['REQUEST_METHOD']==='GET'){
    if($vehicleId<1)out(['ok'=>false,'error'=>'bad_vehicle'],422);
    $passport=vehicle_passport_payload($pdo,$vehicleId,true);if(!$passport)out(['ok'=>false,'error'=>'not_found'],404);
    out(['ok'=>true,'passport'=>$passport,'csrf'=>$_SESSION['csrf']??'']);
  }
  if($_SERVER['REQUEST_METHOD']!=='POST')out(['ok'=>false,'error'=>'method_not_allowed'],405);
  csrf_check();$in=input_json();$action=(string)($in['action']??'');$vehicleId=(int)($in['vehicle_id']??$vehicleId);
  if($vehicleId<1)out(['ok'=>false,'error'=>'bad_vehicle'],422);
  $exists=$pdo->prepare('SELECT id FROM customer_vehicles WHERE id=? AND is_active=1 LIMIT 1');$exists->execute([$vehicleId]);if(!$exists->fetchColumn())out(['ok'=>false,'error'=>'not_found'],404);
  if($action==='save_vehicle'){
    $serial=mb_substr(trim((string)($in['serial_number']??'')),0,180);
    $odo=$in['odometer_km']??null;if($odo!==null&&$odo!==''&&!is_numeric($odo))out(['ok'=>false,'error'=>'bad_odometer'],422);
    $odo=$odo===null||$odo===''?null:max(0,(float)$odo);
    $s=$pdo->prepare('UPDATE customer_vehicles SET serial_number=?,odometer_km=?,odometer_updated_at=IF(? IS NULL,odometer_updated_at,NOW()) WHERE id=?');
    $s->execute([$serial?:null,$odo,$odo,$vehicleId]);audit($pdo,'vehicle_profile_update','customer_vehicle',(string)$vehicleId,['serial_number_set'=>$serial!=='','odometer_km'=>$odo]);
    out(['ok'=>true,'passport'=>vehicle_passport_payload($pdo,$vehicleId,true)]);
  }
  if($action==='save_component'){
    $component=vehicle_passport_save_component($pdo,$vehicleId,is_array($in['component']??null)?$in['component']:[],(int)$admin['id']);
    out(['ok'=>true,'component'=>$component,'passport'=>vehicle_passport_payload($pdo,$vehicleId,true)]);
  }
  if($action==='record_event'){
    $componentId=(int)($in['component_id']??0);if($componentId<1)out(['ok'=>false,'error'=>'bad_component'],422);
    $q=$pdo->prepare('SELECT id FROM vehicle_components WHERE id=? AND vehicle_id=? LIMIT 1');$q->execute([$componentId,$vehicleId]);if(!$q->fetchColumn())out(['ok'=>false,'error'=>'bad_component'],404);
    $eventId=vehicle_passport_record_event($pdo,$componentId,is_array($in['event']??null)?$in['event']:[],(int)$admin['id']);
    out(['ok'=>true,'event_id'=>$eventId,'passport'=>vehicle_passport_payload($pdo,$vehicleId,true)]);
  }
  if($action==='remove_component'){
    if(($admin['role']??'')!=='owner')out(['ok'=>false,'error'=>'forbidden'],403);
    $componentId=(int)($in['component_id']??0);$s=$pdo->prepare('UPDATE vehicle_components SET is_active=0 WHERE id=? AND vehicle_id=?');$s->execute([$componentId,$vehicleId]);
    audit($pdo,'vehicle_component_remove','vehicle_component',(string)$componentId,['vehicle_id'=>$vehicleId]);out(['ok'=>true,'passport'=>vehicle_passport_payload($pdo,$vehicleId,true)]);
  }
  out(['ok'=>false,'error'=>'bad_action'],422);
}catch(InvalidArgumentException $e){out(['ok'=>false,'error'=>$e->getMessage()],422);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());out(['ok'=>false,'error'=>'server_error'],500);}
