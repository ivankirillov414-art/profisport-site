<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
require __DIR__.'/../server/bicycle-fit.php';
$admin=require_admin();
const BICYCLE_FIT_FIELDS='id,source_id,sku,name,category_path,specs,main_image,images,stock_qty,price_rub,updated_at';
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $settings=bicycle_fit_settings($pdo);$items=[];$pending=0;$total=0;
        $page=max(1,(int)($_GET['page']??1));$mode=($_GET['mode']??'pending')==='all'?'all':'pending';
        $q=mb_strtolower(mb_substr(trim((string)($_GET['q']??'')),0,200),'UTF-8');
        $rows=$pdo->query('SELECT '.BICYCLE_FIT_FIELDS.' FROM products WHERE is_active=1 AND COALESCE(stock_qty,0)>0 ORDER BY id DESC');
        while($p=$rows->fetch()){
            if(!bicycle_fit_is_bicycle($p))continue;
            $stored=$settings[(int)$p['id']]??null;$fit=bicycle_fit_current($p,$stored);
            $specs=bicycle_fit_specs(json_decode((string)$p['specs'],true)?:[],$fit);$range=bicycle_fit_range($specs);
            if($range==='')$pending++;
            if($mode==='pending'&&$range!=='')continue;
            if($q!==''&&!str_contains(mb_strtolower($p['name'].' '.$p['sku'],'UTF-8'),$q))continue;
            $total++;
            if($total<=($page-1)*24||$total>$page*24)continue;
            $items[]=['id'=>(int)$p['id'],'name'=>$p['name'],'sku'=>$p['sku'],'price'=>$p['price_rub'],'image'=>customer_vehicle_image($p),'stock'=>$p['stock_qty'],'specs'=>product_specs_rows($specs),'fit'=>$fit,'range'=>$range,'version'=>bicycle_fit_version($p,$stored)];
        }
        json_response(['ok'=>true,'items'=>$items,'pending'=>$pending,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/24)),'csrf'=>$_SESSION['csrf']??'']);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
    csrf_check();$input=input_json();$id=(int)($input['id']??0);
    try{$fit=bicycle_fit_validate($input);}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>'invalid_input','message'=>$e->getMessage()],422);}
    $pdo->beginTransaction();
    $s=$pdo->prepare('SELECT '.BICYCLE_FIT_FIELDS.' FROM products WHERE id=? AND is_active=1 AND COALESCE(stock_qty,0)>0 FOR UPDATE');$s->execute([$id]);$p=$s->fetch();
    if(!$p||!bicycle_fit_is_bicycle($p)){$pdo->rollBack();json_response(['ok'=>false,'error'=>'not_found'],404);}
    $s=$pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key=? FOR UPDATE');$s->execute([BICYCLE_FIT_PREFIX.$id]);$raw=$s->fetchColumn();$stored=$raw?json_decode((string)$raw,true):null;
    if(!is_string($input['version']??null)||!hash_equals(bicycle_fit_version($p,$stored),$input['version'])){$pdo->rollBack();json_response(['ok'=>false,'error'=>'product_changed'],409);}
    $fit+=['identity'=>bicycle_fit_identity($p),'admin_id'=>(int)$admin['id'],'verified_at'=>gmdate('c')];
    $s=$pdo->prepare('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$s->execute([BICYCLE_FIT_PREFIX.$id,json_encode($fit,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    audit($pdo,'bicycle_fit_confirm','product',(string)$id,['min'=>$fit['min'],'max'=>$fit['max'],'frame'=>$fit['frame']]);
    $pdo->commit();json_response(['ok'=>true]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());json_response(['ok'=>false,'error'=>'server_error'],500);}
