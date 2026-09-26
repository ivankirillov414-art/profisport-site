<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
$admin=require_admin();
try{
  $statuses=['new','confirmed','processing','ready','completed','cancelled'];
  if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();$in=input_json();$id=(int)($in['id']??0);$status=$in['status']??'';
    if($id<1||!in_array($status,$statuses,true))json_response(['ok'=>false,'error'=>'invalid_input'],422);
    $pdo->beginTransaction();$s=$pdo->prepare('SELECT status,created_at,updated_at FROM orders WHERE id=? FOR UPDATE');$s->execute([$id]);$orderRow=$s->fetch();
    if(!$orderRow){$pdo->rollBack();json_response(['ok'=>false,'error'=>'not_found'],404);}
    $old=(string)$orderRow['status'];
    if($old!==($in['previous_status']??null)){$pdo->rollBack();json_response(['ok'=>false,'error'=>'order_changed'],409);}
    if($status!==$old){
      $hc=$pdo->prepare('SELECT COUNT(*) FROM order_status_history WHERE order_id=?');$hc->execute([$id]);
      if((int)$hc->fetchColumn()===0){
        record_order_status($pdo,$id,'new',null,'legacy',(string)$orderRow['created_at']);
        if($old!=='new')record_order_status($pdo,$id,$old,null,'legacy_current',(string)($orderRow['updated_at']?:$orderRow['created_at']));
      }
      $s=$pdo->prepare('UPDATE orders SET status=? WHERE id=?');$s->execute([$status,$id]);
      record_order_status($pdo,$id,$status,(int)$admin['id'],'admin');
      loyalty_handle_order_status_change($pdo,$id,$old,$status,(int)$admin['id']);
      customer_vehicle_sync_order($pdo,$id);
    }
    audit($pdo,'order_status','order',(string)$id,['from'=>$old,'to'=>$status]);$pdo->commit();
    json_response(['ok'=>true]);
  }
  if($_SERVER['REQUEST_METHOD']!=='GET')json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
  $id=(int)($_GET['id']??0);
  if($id>0){
    $s=$pdo->prepare('SELECT id,order_number,customer_name,phone,email,delivery_method,pickup_store,address,comment,status,total_rub,created_at,updated_at FROM orders WHERE id=?');$s->execute([$id]);$order=$s->fetch();
    if(!$order)json_response(['ok'=>false,'error'=>'not_found'],404);
    $s=$pdo->prepare('SELECT product_id,title,price_rub,quantity,line_total_rub FROM order_items WHERE order_id=? ORDER BY id');$s->execute([$id]);$items=$s->fetchAll();
    $h=$pdo->prepare('SELECT status,source,created_at FROM order_status_history WHERE order_id=? ORDER BY id');$h->execute([$id]);
    json_response(['ok'=>true,'order'=>$order,'items'=>$items,'history'=>$h->fetchAll()]);
  }
  $status=(string)($_GET['status']??'');$q=mb_substr(trim((string)($_GET['q']??'')),0,200);$page=max(1,min(100000,(int)($_GET['page']??1)));
  $where=[];$args=[];
  if($status!==''){if(!in_array($status,$statuses,true))json_response(['ok'=>false,'error'=>'invalid_status'],422);$where[]='status=?';$args[]=$status;}
  if($q!==''){$where[]='(order_number LIKE ? OR customer_name LIKE ? OR phone LIKE ?)';array_push($args,'%'.$q.'%','%'.$q.'%','%'.$q.'%');}
  $clause=$where?' WHERE '.implode(' AND ',$where):'';
  $s=$pdo->prepare('SELECT COUNT(*) FROM orders'.$clause);$s->execute($args);$total=(int)$s->fetchColumn();
  $s=$pdo->prepare('SELECT id,order_number,customer_name,phone,status,total_rub,created_at FROM orders'.$clause.' ORDER BY id DESC LIMIT 30 OFFSET '.(($page-1)*30));$s->execute($args);
  json_response(['ok'=>true,'items'=>$s->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/30))]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());json_response(['ok'=>false,'error'=>'server_error'],500);}
