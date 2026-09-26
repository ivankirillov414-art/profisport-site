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
    csrf_check();$in=input_json();$id=(int)($in['review_id']??0);$action=(string)($in['action']??'');
    if($id<1||!in_array($action,['approve','reject'],true))out(['ok'=>false,'error'=>'bad_input'],422);
    $pdo->beginTransaction();
    $s=$pdo->prepare('SELECT id,customer_id,product_id,status,bonus_awarded FROM product_reviews WHERE id=? LIMIT 1 FOR UPDATE');$s->execute([$id]);$r=$s->fetch();if(!$r){$pdo->rollBack();out(['ok'=>false,'error'=>'not_found'],404);}
    $loyalty=['awarded'=>0,'reason'=>'not_applicable'];
    if($action==='approve'){
      $pdo->prepare("UPDATE product_reviews SET status='approved' WHERE id=?")->execute([$id]);
      if((string)$r['status']!=='approved'&&(int)$r['customer_id']>0)$loyalty=loyalty_award_review($pdo,$id,(int)$r['customer_id']);
    }else $pdo->prepare("UPDATE product_reviews SET status='rejected' WHERE id=?")->execute([$id]);
    $pdo->commit();audit($pdo,'review_'.$action,'review',(string)$id,['loyalty'=>$loyalty]);out(['ok'=>true,'loyalty'=>$loyalty]);
  }
  $status=(string)($_GET['status']??'pending');if(!in_array($status,['pending','approved','rejected'],true))$status='pending';
  $s=$pdo->prepare('SELECT r.id,r.rating,r.review_text,r.status,r.bonus_awarded,r.created_at,c.name customer_name,c.email,p.name product_name FROM product_reviews r LEFT JOIN customers c ON c.id=r.customer_id LEFT JOIN products p ON p.id=r.product_id WHERE r.status=? ORDER BY r.id DESC LIMIT 100');$s->execute([$status]);out(['ok'=>true,'items'=>$s->fetchAll(),'status'=>$status,'loyalty'=>loyalty_program_status($pdo)]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());out(['ok'=>false,'error'=>'server_error'],500);}