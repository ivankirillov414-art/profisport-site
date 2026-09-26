<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
function out(array $x,int $c=200):never{http_response_code($c);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function vehicle_spec_admin_summary(PDO $pdo): array {
    $scan=vehicle_spec_registry_scan_catalog($pdo,5000);
    $vehiclesTotal=(int)$pdo->query("SELECT COUNT(*) FROM customer_vehicles WHERE is_active=1 AND vehicle_type='bicycle'")->fetchColumn();
    $vehiclesVerified=(int)$pdo->query("SELECT COUNT(DISTINCT v.id) FROM customer_vehicles v JOIN vehicle_components c ON c.vehicle_id=v.id AND c.is_active=1 AND c.source_profile_key IS NOT NULL WHERE v.is_active=1 AND v.vehicle_type='bicycle'")->fetchColumn();
    return [
        'registry_profiles'=>count(vehicle_spec_registry_profiles()),
        'catalog_bicycles'=>$scan['total'],
        'catalog_matched'=>$scan['matched'],
        'catalog_unmatched'=>$scan['unmatched'],
        'customer_bicycles'=>$vehiclesTotal,
        'customer_bicycles_verified'=>$vehiclesVerified,
        'customer_bicycles_unverified'=>max(0,$vehiclesTotal-$vehiclesVerified),
        'matched'=>vehicle_spec_registry_queue($pdo,'matched',300),
        'unmatched'=>vehicle_spec_registry_queue($pdo,'unmatched',300),
    ];
}
try{
    $admin=require_admin();
    if($_SERVER['REQUEST_METHOD']==='GET')out(['ok'=>true,'summary'=>vehicle_spec_admin_summary($pdo),'csrf'=>$_SESSION['csrf']??'']);
    if($_SERVER['REQUEST_METHOD']!=='POST')out(['ok'=>false,'error'=>'method_not_allowed'],405);
    if(($admin['role']??'')!=='owner')out(['ok'=>false,'error'=>'forbidden'],403);
    csrf_check();$in=input_json();$action=(string)($in['action']??'');
    if($action==='rescan'){
        $scan=vehicle_spec_registry_scan_catalog($pdo,5000);
        audit($pdo,'vehicle_spec_registry_scan','vehicle_spec_registry','catalog',['matched'=>$scan['matched'],'unmatched'=>$scan['unmatched']]);
        out(['ok'=>true,'scan'=>$scan,'summary'=>vehicle_spec_admin_summary($pdo)]);
    }
    if($action==='apply_registry'){
        $scan=vehicle_spec_registry_scan_catalog($pdo,5000);
        $applied=vehicle_spec_registry_apply_all($pdo,10000);
        audit($pdo,'vehicle_spec_registry_apply','vehicle_spec_registry','catalog',['scan'=>['matched'=>$scan['matched'],'unmatched'=>$scan['unmatched']], 'applied'=>$applied]);
        out(['ok'=>true,'scan'=>$scan,'applied'=>$applied,'summary'=>vehicle_spec_admin_summary($pdo)]);
    }
    out(['ok'=>false,'error'=>'bad_action'],422);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());out(['ok'=>false,'error'=>'server_error'],500);}
