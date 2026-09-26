<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();

function out(array $x,int $c=200):never{http_response_code($c);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function csv_safe(mixed $value): string { $value=(string)($value??'');return preg_match('/^[\x00-\x20]*[=+\-@]/u',$value)?"'".$value:$value; }
function customer_rows(PDO $pdo,string $q='',int $limit=200): array {
  $args=[];$where='1=1';
  if($q!==''){$where.=' AND (name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?)';$like='%'.$q.'%';$args=[$like,$like,$like,$like];}
  $limit=max(1,min(10000,$limit));
  $sql="SELECT id,name,last_name,email,phone,birth_date,preferred_store,registration_source,qr_registered_at,bonus_balance,created_at,(password_hash IS NOT NULL AND password_hash<>'') has_account,(SELECT COUNT(*) FROM customer_favorites f WHERE f.customer_id=customers.id) favorites_count,(SELECT COUNT(*) FROM product_reviews r WHERE r.customer_id=customers.id) reviews_count,(SELECT COUNT(*) FROM orders o WHERE o.customer_id=customers.id) orders_count FROM customers WHERE $where ORDER BY id DESC LIMIT $limit";
  $s=$pdo->prepare($sql);$s->execute($args);return $s->fetchAll();
}
function customer_detail(PDO $pdo,int $id): ?array {
  if($id<1)return null;
  $s=$pdo->prepare("SELECT id,name,last_name,email,phone,birth_date,preferred_store,registration_source,qr_registered_at,consent_at,bonus_balance,is_active,created_at,updated_at,(password_hash IS NOT NULL AND password_hash<>'') has_account,
    (SELECT COUNT(*) FROM customer_favorites f WHERE f.customer_id=customers.id) favorites_count,
    (SELECT COUNT(*) FROM product_reviews r WHERE r.customer_id=customers.id) reviews_count,
    (SELECT COUNT(*) FROM orders o WHERE o.customer_id=customers.id) orders_count,
    (SELECT COUNT(*) FROM service_requests sr WHERE sr.customer_id=customers.id) service_count
    FROM customers WHERE id=? LIMIT 1");
  $s->execute([$id]);$customer=$s->fetch();if(!$customer)return null;

  $orders=$pdo->prepare('SELECT id,order_number,status,total_rub,delivery_method,pickup_store,address,comment,bonus_earned,created_at,updated_at FROM orders WHERE customer_id=? ORDER BY id DESC LIMIT 100');
  $orders->execute([$id]);$orderRows=$orders->fetchAll();
  $items=$pdo->prepare('SELECT product_id,title,price_rub,quantity,line_total_rub FROM order_items WHERE order_id=? ORDER BY id');
  $history=$pdo->prepare('SELECT status,source,created_at FROM order_status_history WHERE order_id=? ORDER BY id');
  foreach($orderRows as &$order){
    $items->execute([(int)$order['id']]);$order['items']=$items->fetchAll();
    $history->execute([(int)$order['id']]);$order['history']=$history->fetchAll();
  }unset($order);

  $favorites=$pdo->prepare('SELECT cf.product_id,cf.created_at saved_at,p.name,p.price_rub,p.stock_qty,p.is_active FROM customer_favorites cf LEFT JOIN products p ON p.id=cf.product_id WHERE cf.customer_id=? ORDER BY cf.created_at DESC LIMIT 200');
  $favorites->execute([$id]);

  $reviews=$pdo->prepare('SELECT r.id,r.product_id,r.rating,r.review_text,r.status,r.bonus_awarded,r.created_at,r.updated_at,p.name product_name FROM product_reviews r LEFT JOIN products p ON p.id=r.product_id WHERE r.customer_id=? ORDER BY r.id DESC LIMIT 100');
  $reviews->execute([$id]);

  $loyalty=$pdo->prepare('SELECT id,amount,kind,source_type,source_id,note,order_id,expires_at,status,created_at FROM loyalty_transactions WHERE customer_id=? ORDER BY id DESC LIMIT 100');
  $loyalty->execute([$id]);

  $audit=$pdo->prepare("SELECT a.id,a.action,a.payload,a.created_at,u.username admin_username FROM audit_log a LEFT JOIN admin_users u ON u.id=a.admin_user_id WHERE a.entity_type='customer' AND a.entity_id=? ORDER BY a.id DESC LIMIT 50");
  $audit->execute([(string)$id]);

  return [
    'customer'=>$customer,
    'orders'=>$orderRows,
    'favorites'=>$favorites->fetchAll(),
    'reviews'=>$reviews->fetchAll(),
    'loyalty'=>$loyalty->fetchAll(),
    'vehicles'=>customer_vehicle_rows($pdo,$id),
    'service_requests'=>customer_service_rows($pdo,$id),
    'audit'=>$audit->fetchAll(),
    'loyalty_program'=>loyalty_program_status($pdo),
  ];
}

try{
  $admin=require_admin();
  if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();$in=input_json();$id=(int)($in['customer_id']??0);$amount=(int)($in['amount']??0);$note=trim((string)($in['note']??'Ручная корректировка'));
    if($id<1||$amount===0||abs($amount)>1000000)out(['ok'=>false,'error'=>'bad_input'],422);
    $result=loyalty_manual_adjustment($pdo,$id,$amount,$note?:'Ручная корректировка',(int)($admin['id']??0));
    audit($pdo,'customer_bonus_adjust','customer',(string)$id,['amount'=>$result['amount'],'note'=>$note,'program_enabled'=>loyalty_program_enabled($pdo)]);
    out(['ok'=>true,'bonus_balance'=>$result['balance'],'actual_amount'=>$result['amount'],'loyalty'=>loyalty_program_status($pdo)]);
  }
  if($_SERVER['REQUEST_METHOD']!=='GET')out(['ok'=>false,'error'=>'method_not_allowed'],405);
  $id=(int)($_GET['id']??0);
  if($id>0){
    $detail=customer_detail($pdo,$id);if(!$detail)out(['ok'=>false,'error'=>'not_found'],404);
    out(['ok'=>true]+$detail);
  }
  $q=trim((string)($_GET['q']??''));
  if(($_GET['export']??'')==='csv'){
    $rows=customer_rows($pdo,$q,10000);header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="profisport-clients-'.date('Y-m-d').'.csv"');header('Cache-Control: no-store');echo "\xEF\xBB\xBF";$stream=fopen('php://output','wb');fputcsv($stream,['ID','Имя','Фамилия','Телефон','Email','Дата рождения','Источник','Есть кабинет','Дата QR-регистрации','Дата добавления'],';');foreach($rows as $row)fputcsv($stream,array_map('csv_safe',[$row['id'],$row['name'],$row['last_name'],$row['phone'],$row['email'],$row['birth_date'],$row['registration_source'],(int)$row['has_account']?'да':'нет',$row['qr_registered_at'],$row['created_at']]));fclose($stream);exit;
  }
  $items=customer_rows($pdo,$q);$total=(int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();$qr=(int)$pdo->query('SELECT COUNT(*) FROM customers WHERE qr_registered_at IS NOT NULL')->fetchColumn();out(['ok'=>true,'items'=>$items,'total'=>$total,'qr_total'=>$qr,'loyalty'=>loyalty_program_status($pdo)]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());out(['ok'=>false,'error'=>'server_error'],500);}
