<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
require __DIR__.'/../server/order-validation.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function order_result(array $row): never {json_response(['ok'=>true,'order_number'=>$row['order_number'],'total_rub'=>(int)$row['total_rub']]);}
function customer_id_from_session(): ?int {
  if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
  ini_set('session.use_strict_mode','1');
  session_name('PROFISPORT_CUSTOMER');
  session_set_cookie_params(['lifetime'=>60*60*24*30,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
  session_start();$id=(int)($_SESSION['customer_id']??0);session_write_close();return $id>0?$id:null;
}
try{
  if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
  $in=validate_order(input_json());
  $hash=hash('sha256',json_encode($in,JSON_UNESCAPED_UNICODE));
  $find=$pdo->prepare('SELECT order_number,total_rub,request_hash FROM orders WHERE request_key=?');
  $find->execute([$in['request_key']]);$existing=$find->fetch();
  if($existing){if(!hash_equals((string)$existing['request_hash'],$hash))json_response(['ok'=>false,'error'=>'request_conflict'],409);order_result($existing);}
  $customerId=customer_id_from_session();
  if($customerId){$c=$pdo->prepare('SELECT id FROM customers WHERE id=? AND is_active=1');$c->execute([$customerId]);if(!$c->fetchColumn())$customerId=null;}
  $pdo->beginTransaction();
  $groups=$in['groups'];$marks=implode(',',array_fill(0,count($groups),'?'));
  $s=$pdo->prepare("SELECT id,name,price_rub,stock_qty,stock_status,availability,is_active FROM products WHERE id IN ($marks) ORDER BY id FOR UPDATE");
  $s->execute(array_keys($groups));$calculated=order_lines($s->fetchAll(),$groups);
  // This is a request for manager confirmation; stock remains owned by the 1C import.
  $number='PS-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(5)));
  insert_order_row($pdo,'orders',[
    'customer_id'=>$customerId,'order_number'=>$number,'customer_name'=>$in['name'],'phone'=>$in['phone'],
    'email'=>$in['email']?:null,'delivery_method'=>$in['delivery'],'address'=>$in['address']?:null,
    'comment'=>$in['comment']?:null,'status'=>'new','total_rub'=>$calculated['total'],
    'request_key'=>$in['request_key'],'request_hash'=>$hash,
  ]);
  $orderId=(int)$pdo->lastInsertId();
  foreach($calculated['items'] as $x)insert_order_row($pdo,'order_items',[
    'order_id'=>$orderId,'product_id'=>$x['id'],'title'=>$x['title'],'price_rub'=>$x['price'],
    'quantity'=>$x['qty'],'line_total_rub'=>$x['line'],
  ]);
  $pdo->commit();order_result(['order_number'=>$number,'total_rub'=>$calculated['total']]);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>$e->getMessage()],422);
}catch(DomainException $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'error'=>$e->getMessage()],409);
}catch(Throwable $e){
  if($pdo->inTransaction())$pdo->rollBack();
  // A simultaneous retry can arrive before the first request commits.
  if($e instanceof PDOException&&$e->getCode()==='23000'&&isset($in,$hash)){
    $find->execute([$in['request_key']]);$existing=$find->fetch();
    if($existing&&hash_equals((string)$existing['request_hash'],$hash))order_result($existing);
  }
  error_log($e->__toString());json_response(['ok'=>false,'error'=>'server_error'],500);
}
