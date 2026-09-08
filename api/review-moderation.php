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
    csrf_check();$in=input_json();$id=(int)($in['review_id']??0);$action=(string)($in['action']??'');$bonus=max(0,min(100000,(int)($in['bonus']??0)));
    if($id<1||!in_array($action,['approve','reject'],true))out(['ok'=>false,'error'=>'bad_input'],422);
    $s=$pdo->prepare('SELECT id,customer_id,product_id,status,bonus_awarded FROM product_reviews WHERE id=? LIMIT 1');$s->execute([$id]);$r=$s->fetch();if(!$r)out(['ok'=>false,'error'=>'not_found'],404);
    $pdo->beginTransaction();
    if($action==='approve'){
      $pdo->prepare("UPDATE product_reviews SET status='approved' WHERE id=?")->execute([$id]);
      if($bonus>0&&(int)$r['customer_id']>0&&!(int)$r['bonus_awarded']){
        $pdo->prepare('UPDATE customers SET bonus_balance=bonus_balance+? WHERE id=?')->execute([$bonus,(int)$r['customer_id']]);
        $pdo->prepare("INSERT INTO loyalty_transactions(customer_id,amount,kind,source_type,source_id,note) VALUES(?,?,'review_bonus','review',?,'Бонус за опубликованный отзыв')")->execute([(int)$r['customer_id'],$bonus,(string)$id]);
        $pdo->prepare('UPDATE product_reviews SET bonus_awarded=1 WHERE id=?')->execute([$id]);
      }
    }else $pdo->prepare("UPDATE product_reviews SET status='rejected' WHERE id=?")->execute([$id]);
    $pdo->commit();audit($pdo,'review_'.$action,'review',(string)$id,['bonus'=>$bonus]);out(['ok'=>true]);
  }
  $status=(string)($_GET['status']??'pending');if(!in_array($status,['pending','approved','rejected'],true))$status='pending';
  $s=$pdo->prepare('SELECT r.id,r.rating,r.review_text,r.status,r.bonus_awarded,r.created_at,c.name customer_name,c.email,p.name product_name FROM product_reviews r LEFT JOIN customers c ON c.id=r.customer_id LEFT JOIN products p ON p.id=r.product_id WHERE r.status=? ORDER BY r.id DESC LIMIT 100');$s->execute([$status]);out(['ok'=>true,'items'=>$s->fetchAll(),'status'=>$status]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());out(['ok'=>false,'error'=>'server_error'],500);}