<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function out(array $x,int $c=200):never{http_response_code($c);echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
$reasons=['profanity'=>'Нецензурная лексика','abuse'=>'Оскорбления или угрозы','spam'=>'Спам или реклама','irrelevant'=>'Не относится к товару или покупке','personal_data'=>'Чужие персональные данные','duplicate'=>'Повторный отзыв','other'=>'Другая причина'];
try{
  $admin=require_admin();
  if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();$in=input_json();$id=(int)($in['review_id']??0);$action=(string)($in['action']??'');
    if($id<1||!in_array($action,['approve','reject','edit'],true))out(['ok'=>false,'error'=>'bad_input'],422);
    $text=trim((string)($in['review_text']??''));
    if($action==='edit'&&(mb_strlen($text)<10||mb_strlen($text)>5000||!is_string($in['expected_text']??null)))out(['ok'=>false,'error'=>'invalid_review'],422);
    $reason=(string)($in['reason_code']??'');$note=trim((string)($in['reason_note']??''));
    if($action==='reject'&&(!isset($reasons[$reason])||mb_strlen($note)>500||($reason==='other'&&mb_strlen($note)<5)))out(['ok'=>false,'error'=>'rejection_reason_required'],422);
    $pdo->beginTransaction();
    $s=$pdo->prepare('SELECT id,customer_id,product_id,status,bonus_awarded,review_text FROM product_reviews WHERE id=? LIMIT 1 FOR UPDATE');$s->execute([$id]);$r=$s->fetch();if(!$r){$pdo->rollBack();out(['ok'=>false,'error'=>'not_found'],404);}
    $loyalty=['awarded'=>0,'reason'=>'not_applicable'];
    if($action==='edit'){
      if($in['expected_text']!==$r['review_text']){$pdo->rollBack();out(['ok'=>false,'error'=>'review_changed'],409);}
      if($text!==$r['review_text']){
        $pdo->prepare('INSERT INTO review_edit_history(review_id,before_text,after_text,edited_by) VALUES(?,?,?,?)')->execute([$id,$r['review_text'],$text,(int)$admin['id']]);
        $pdo->prepare('UPDATE product_reviews SET original_review_text=COALESCE(original_review_text,review_text),review_text=? WHERE id=?')->execute([$text,$id]);
      }
    }elseif($action==='approve'){
      if($r['status']==='rejected'){$pdo->rollBack();out(['ok'=>false,'error'=>'already_moderated'],409);}
      $pdo->prepare("UPDATE product_reviews SET status='approved' WHERE id=?")->execute([$id]);
      if((string)$r['status']!=='approved'&&(int)$r['customer_id']>0)$loyalty=loyalty_award_review($pdo,$id,(int)$r['customer_id']);
    }else{
      if($r['status']!=='pending'){$pdo->rollBack();out(['ok'=>false,'error'=>'already_moderated'],409);}
      $pdo->prepare("UPDATE product_reviews SET status='rejected',rejection_reason_code=?,rejection_note=?,rejected_at=NOW(),rejected_by=? WHERE id=?")->execute([$reason,$note,(int)$admin['id'],$id]);
    }
    $pdo->commit();audit($pdo,'review_'.$action,'review',(string)$id,['loyalty'=>$loyalty,'reason_code'=>$action==='reject'?$reason:null,'reason_note'=>$action==='reject'?$note:null]);out(['ok'=>true,'loyalty'=>$loyalty]);
  }
  $status=(string)($_GET['status']??'pending');if(!in_array($status,['pending','approved','rejected'],true))$status='pending';
  $count=$pdo->prepare('SELECT COUNT(*) FROM product_reviews WHERE status=?');$count->execute([$status]);$total=(int)$count->fetchColumn();$pages=max(1,(int)ceil($total/50));$page=min($pages,max(1,(int)($_GET['page']??1)));$offset=($page-1)*50;
  $s=$pdo->prepare('SELECT r.id,r.rating,r.review_text,r.original_review_text,r.status,r.bonus_awarded,r.created_at,r.rejection_reason_code,r.rejection_note,r.rejected_at,a.username rejected_by_name,c.name customer_name,c.email customer_email,p.name product_name FROM product_reviews r LEFT JOIN customers c ON c.id=r.customer_id LEFT JOIN products p ON p.id=r.product_id LEFT JOIN admin_users a ON a.id=r.rejected_by WHERE r.status=? ORDER BY r.id DESC LIMIT 50 OFFSET '.$offset);$s->execute([$status]);$items=$s->fetchAll();$history=[];
  if($items){
    $ids=array_column($items,'id');$h=$pdo->prepare('SELECT h.review_id,h.before_text,h.after_text,h.created_at,a.username editor FROM review_edit_history h LEFT JOIN admin_users a ON a.id=h.edited_by WHERE h.review_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY h.id DESC');$h->execute($ids);
    foreach($h->fetchAll() as $edit)$history[(int)$edit['review_id']][]=$edit;
  }
  foreach($items as &$item)$item['edit_history']=$history[(int)$item['id']]??[];unset($item);
  out(['ok'=>true,'items'=>$items,'status'=>$status,'total'=>$total,'page'=>$page,'pages'=>$pages,'rejection_reasons'=>$reasons,'loyalty'=>loyalty_program_status($pdo)]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());out(['ok'=>false,'error'=>'server_error'],500);}
