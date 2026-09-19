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
  $sql="SELECT id,name,last_name,email,phone,birth_date,registration_source,qr_registered_at,bonus_balance,created_at,(password_hash IS NOT NULL AND password_hash<>'') has_account,(SELECT COUNT(*) FROM customer_favorites f WHERE f.customer_id=customers.id) favorites_count,(SELECT COUNT(*) FROM product_reviews r WHERE r.customer_id=customers.id) reviews_count,(SELECT COUNT(*) FROM orders o WHERE o.customer_id=customers.id) orders_count FROM customers WHERE $where ORDER BY id DESC LIMIT $limit";
  $s=$pdo->prepare($sql);$s->execute($args);return $s->fetchAll();
}

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
  $q=trim((string)($_GET['q']??''));
  if(($_GET['export']??'')==='csv'){
    $rows=customer_rows($pdo,$q,10000);header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="profisport-clients-'.date('Y-m-d').'.csv"');header('Cache-Control: no-store');echo "\xEF\xBB\xBF";$stream=fopen('php://output','wb');fputcsv($stream,['ID','Имя','Фамилия','Телефон','Email','Дата рождения','Источник','Есть кабинет','Дата QR-регистрации','Дата добавления'],';');foreach($rows as $row)fputcsv($stream,array_map('csv_safe',[$row['id'],$row['name'],$row['last_name'],$row['phone'],$row['email'],$row['birth_date'],$row['registration_source'],(int)$row['has_account']?'да':'нет',$row['qr_registered_at'],$row['created_at']]));fclose($stream);exit;
  }
  $items=customer_rows($pdo,$q);$total=(int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();$qr=(int)$pdo->query('SELECT COUNT(*) FROM customers WHERE qr_registered_at IS NOT NULL')->fetchColumn();out(['ok'=>true,'items'=>$items,'total'=>$total,'qr_total'=>$qr]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());out(['ok'=>false,'error'=>'server_error'],500);}
