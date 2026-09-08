<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function out(array $x,int $c=200):never{http_response_code($c);echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
  $admin=require_admin();
  if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();$in=input_json();$id=(int)($in['customer_id']??0);$amount=(int)($in['amount']??0);$note=trim((string)($in['note']??'Ручная корректировка'));
    if($id<1||$amount===0||abs($amount)>1000000)out(['ok'=>false,'error'=>'bad_input'],422);
    $pdo->beginTransaction();
    $s=$pdo->prepare('SELECT bonus_balance FROM customers WHERE id=? FOR UPDATE');$s->execute([$id]);$bal=$s->fetchColumn();if($bal===false)out(['ok'=>false,'error'=>'not_found'],404);
    $new=max(0,(int)$bal+$amount);$actual=$new-(int)$bal;
    $pdo->prepare('UPDATE customers SET bonus_balance=? WHERE id=?')->execute([$new,$id]);
    $pdo->prepare("INSERT INTO loyalty_transactions(customer_id,amount,kind,source_type,source_id,note) VALUES(?,?,'manual','admin',?,?)")->execute([$id,$actual,(string)($admin['id']??''),$note?:'Ручная корректировка']);
    $pdo->commit();audit($pdo,'customer_bonus_adjust','customer',(string)$id,['amount'=>$actual,'note'=>$note]);out(['ok'=>true,'bonus_balance'=>$new]);
  }
  $q=trim((string)($_GET['q']??''));$args=[];$where='1=1';if($q!==''){$where.=' AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)';$like='%'.$q.'%';$args=[$like,$like,$like];}
  $s=$pdo->prepare("SELECT id,name,email,phone,bonus_balance,created_at,(SELECT COUNT(*) FROM customer_favorites f WHERE f.customer_id=customers.id) favorites_count,(SELECT COUNT(*) FROM product_reviews r WHERE r.customer_id=customers.id) reviews_count,(SELECT COUNT(*) FROM orders o WHERE o.customer_id=customers.id) orders_count FROM customers WHERE $where ORDER BY id DESC LIMIT 200");$s->execute($args);out(['ok'=>true,'items'=>$s->fetchAll()]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());out(['ok'=>false,'error'=>'server_error'],500);}