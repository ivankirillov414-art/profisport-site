<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function flow_out(array $x,int $status=200): never {http_response_code($status);echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function flow_auth(array $config): void {
  $got=(string)($_SERVER['HTTP_X_IMPORT_TOKEN']??'');$expected=(string)($config['import_token']??'');
  if($expected===''||$got===''||!hash_equals($expected,$got))flow_out(['ok'=>false,'error'=>'unauthorized'],401);
}
function flow_email(string $raw): string {
  $email=mb_strtolower(trim($raw));if(!preg_match('/^smoke-http-[a-z0-9._-]+@example\.invalid$/',$email))throw new InvalidArgumentException('invalid_smoke_identity');return $email;
}
try{
  if($_SERVER['REQUEST_METHOD']!=='POST')flow_out(['ok'=>false,'error'=>'method_not_allowed'],405);
  flow_auth($config);$action=(string)($_GET['action']??'');$in=input_json();

  if($action==='product'){
    $p=$pdo->query("SELECT id,name,price_rub,stock_qty FROM products WHERE is_active=1 AND COALESCE(stock_qty,0)>0 AND COALESCE(price_rub,0)>0 ORDER BY id LIMIT 1")->fetch();
    if(!$p)flow_out(['ok'=>false,'error'=>'no_sellable_product'],409);
    flow_out(['ok'=>true,'product'=>['id'=>(int)$p['id'],'name'=>(string)$p['name'],'price_rub'=>(int)$p['price_rub'],'stock_qty'=>(int)$p['stock_qty']]]);
  }

  if($action==='admin_status'){
    $email=flow_email((string)($in['email']??''));$number=trim((string)($in['order_number']??''));$status=(string)($in['status']??'');
    if(!in_array($status,['confirmed','processing','ready','completed','cancelled'],true)||!preg_match('/^PS-[A-Z0-9-]+$/',$number))flow_out(['ok'=>false,'error'=>'invalid_input'],422);
    $s=$pdo->prepare("SELECT o.id,o.status FROM orders o JOIN customers c ON c.id=o.customer_id WHERE o.order_number=? AND c.email=? AND o.comment='AUTOMATED_PRODUCTION_HTTP_SMOKE' LIMIT 1");$s->execute([$number,$email]);$row=$s->fetch();
    if(!$row)flow_out(['ok'=>false,'error'=>'smoke_order_not_found'],404);
    $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$status,(int)$row['id']]);
    flow_out(['ok'=>true,'from'=>$row['status'],'to'=>$status]);
  }

  if($action==='cleanup'){
    $email=flow_email((string)($in['email']??''));
    $pdo->beginTransaction();
    $s=$pdo->prepare('SELECT id FROM customers WHERE email=? LIMIT 1');$s->execute([$email]);$customerId=(int)($s->fetchColumn()?:0);
    if($customerId>0){
      $orders=$pdo->prepare("SELECT id FROM orders WHERE customer_id=? AND comment='AUTOMATED_PRODUCTION_HTTP_SMOKE'");$orders->execute([$customerId]);$ids=array_map('intval',$orders->fetchAll(PDO::FETCH_COLUMN));
      if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$pdo->prepare("DELETE FROM order_items WHERE order_id IN ($marks)")->execute($ids);$pdo->prepare("DELETE FROM orders WHERE id IN ($marks)")->execute($ids);}
      $pdo->prepare('DELETE FROM customer_favorites WHERE customer_id=?')->execute([$customerId]);
      $pdo->prepare('DELETE FROM product_reviews WHERE customer_id=?')->execute([$customerId]);
      $pdo->prepare('DELETE FROM loyalty_transactions WHERE customer_id=?')->execute([$customerId]);
      $pdo->prepare('DELETE FROM customers WHERE id=?')->execute([$customerId]);
    }
    $pdo->commit();
    auth_rate_clear($pdo,'customer_register','');auth_rate_clear($pdo,'customer_login',$email);
    flow_out(['ok'=>true,'cleaned'=>true]);
  }

  flow_out(['ok'=>false,'error'=>'not_found'],404);
}catch(InvalidArgumentException $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();flow_out(['ok'=>false,'error'=>$e->getMessage()],422);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());flow_out(['ok'=>false,'error'=>'server_error'],500);}
