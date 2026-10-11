<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
require_admin();
header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='GET')json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
try {
    $id=(int)($_GET['id']??0);
    if($id>0){
        $s=$pdo->prepare('SELECT id,name,last_name,phone FROM customers WHERE id=? AND is_active=1 LIMIT 1');
        $s->execute([$id]);$customer=$s->fetch();
        if(!$customer)json_response(['ok'=>false,'error'=>'not_found'],404);
        $tier=loyalty_purchase_tier($pdo,$id);
        $cfg=loyalty_config($pdo);
        $personal=loyalty_customer_discount($pdo,$id);
        $bp=(int)$tier['percent_bp'];$scope=null;
        if(!empty($cfg['enabled'])&&!empty($personal['enabled'])){
            if(empty($personal['group_key']))$bp=max($bp,(int)$personal['percent_bp']);
            else {
                foreach(($cfg['discount_groups']??[]) as $group){
                    if(($group['key']??'')===$personal['group_key']){$scope=['name'=>$group['name'],'percent'=>(int)$personal['percent_bp']/100];break;}
                }
            }
        }
        json_response(['ok'=>true,'customer'=>$customer,'percent'=>$bp/100,'scoped_discount'=>$scope]);
    }
    $raw=(string)($_GET['q']??'');
    if(strlen($raw)>64)json_response(['ok'=>false,'error'=>'invalid_phone'],422);
    $digits=preg_replace('/[^0-9]/','',$raw);
    if(strlen($digits)<3||strlen($digits)>11)json_response(['ok'=>true,'items'=>[]]);
    if(strlen($digits)===11&&($digits[0]==='8'||$digits[0]==='7'))$digits=substr($digits,1);
    // Legacy formatted phones and both Russian country prefixes are searchable.
    $phone="REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(',''),')',''),'.','')";
    $s=$pdo->prepare("SELECT id,name,last_name,phone FROM customers WHERE is_active=1 AND $phone LIKE ? ORDER BY last_name,name,id LIMIT 20");
    $s->execute(['%'.$digits.'%']);
    json_response(['ok'=>true,'items'=>$s->fetchAll()]);
} catch(Throwable $e){error_log('seller_discount: '.$e->getMessage());json_response(['ok'=>false,'error'=>'server_error'],500);}
