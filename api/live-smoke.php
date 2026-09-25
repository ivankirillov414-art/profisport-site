<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function smoke_out(array $x,int $status=200): never {http_response_code($status);echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function smoke_auth(array $config): void {
  $got=(string)($_SERVER['HTTP_X_IMPORT_TOKEN']??'');$expected=(string)($config['import_token']??'');
  if($expected===''||$got===''||!hash_equals($expected,$got))smoke_out(['ok'=>false,'error'=>'unauthorized'],401);
}
try{
  if($_SERVER['REQUEST_METHOD']!=='POST')smoke_out(['ok'=>false,'error'=>'method_not_allowed'],405);
  smoke_auth($config);
  $p=$pdo->query("SELECT id,name,price_rub,stock_qty FROM products WHERE is_active=1 AND COALESCE(stock_qty,0)>0 AND COALESCE(price_rub,0)>0 ORDER BY id LIMIT 1")->fetch();
  if(!$p)throw new RuntimeException('no_sellable_product');
  $nonce=bin2hex(random_bytes(6));$email='smoke-'.$nonce.'@example.invalid';$phone='+7999'.str_pad((string)random_int(0,9999999),7,'0',STR_PAD_LEFT);$orderNo='PS-SMOKE-'.strtoupper($nonce);
  $pdo->beginTransaction();
  $s=$pdo->prepare("INSERT INTO customers(name,last_name,email,phone,birth_date,password_hash,registration_source,consent_at,is_active) VALUES('Smoke','Test',?,?, '1990-01-01',?,'smoke',NOW(),1)");
  $s->execute([$email,$phone,password_hash(bin2hex(random_bytes(12)),PASSWORD_DEFAULT)]);$customerId=(int)$pdo->lastInsertId();
  $pdo->prepare('INSERT INTO customer_favorites(customer_id,product_id) VALUES(?,?)')->execute([$customerId,(int)$p['id']]);
  insert_order_row($pdo,'orders',[
    'customer_id'=>$customerId,'order_number'=>$orderNo,'customer_name'=>'Smoke Test','phone'=>$phone,'email'=>$email,
    'delivery_method'=>'pickup','address'=>null,'comment'=>'AUTOMATED_PRODUCTION_SMOKE','status'=>'new','total_rub'=>(int)$p['price_rub'],
    'request_key'=>hash('sha256','smoke-request-'.$nonce),'request_hash'=>hash('sha256','smoke-hash-'.$nonce)
  ]);
  $orderId=(int)$pdo->lastInsertId();
  insert_order_row($pdo,'order_items',[
    'order_id'=>$orderId,'product_id'=>(int)$p['id'],'title'=>(string)$p['name'],'price_rub'=>(int)$p['price_rub'],'quantity'=>1,'line_total_rub'=>(int)$p['price_rub']
  ]);
  foreach(['confirmed','processing','ready','completed'] as $status)$pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$status,$orderId]);
  $fav=$pdo->prepare('SELECT COUNT(*) FROM customer_favorites WHERE customer_id=? AND product_id=?');$fav->execute([$customerId,(int)$p['id']]);
  $order=$pdo->prepare('SELECT status,total_rub FROM orders WHERE id=? AND customer_id=?');$order->execute([$orderId,$customerId]);$orderRow=$order->fetch();
  $items=$pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id=?');$items->execute([$orderId]);
  $checks=[
    'catalog_product'=>(int)$p['id']>0,
    'customer_created'=>$customerId>0,
    'favorite_saved'=>(int)$fav->fetchColumn()===1,
    'order_created'=>$orderId>0,
    'order_item_saved'=>(int)$items->fetchColumn()===1,
    'admin_status_cycle'=>($orderRow['status']??'')==='completed',
    'profile_order_visible'=>is_array($orderRow)&&isset($orderRow['total_rub'])
  ];
  if(in_array(false,$checks,true))throw new RuntimeException('smoke_check_failed');
  $pdo->rollBack();
  smoke_out(['ok'=>true,'rolled_back'=>true,'checks'=>$checks,'product_id'=>(int)$p['id']]);
}catch(Throwable $e){
  if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
  error_log($e->__toString());smoke_out(['ok'=>false,'error'=>'production_smoke_failed'],500);
}
