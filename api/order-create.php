<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $x,int $code=200): never { http_response_code($code); echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function customer_id_from_session(): ?int {
  if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
  session_name('PROFISPORT_CUSTOMER');
  session_set_cookie_params(['lifetime'=>60*60*24*30,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
  @session_start();
  $id=(int)($_SESSION['customer_id']??0);
  session_write_close();
  return $id>0?$id:null;
}
try{
  if($_SERVER['REQUEST_METHOD']!=='POST')out(['ok'=>false,'error'=>'method_not_allowed'],405);
  $in=input_json();
  $name=trim((string)($in['name']??''));$phone=trim((string)($in['phone']??''));$email=trim((string)($in['email']??''));$delivery=trim((string)($in['delivery']??'pickup'));$address=trim((string)($in['address']??''));$comment=trim((string)($in['comment']??''));
  $ids=$in['items']??[];if(!is_array($ids))$ids=[];$ids=array_values(array_filter(array_map('intval',$ids),fn($v)=>$v>0));
  if(mb_strlen($name)<2||strlen(preg_replace('/\D+/','',$phone)??'')<10||!$ids)out(['ok'=>false,'error'=>'invalid_input'],422);
  if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))out(['ok'=>false,'error'=>'bad_email'],422);
  $groups=[];foreach($ids as $id)$groups[$id]=($groups[$id]??0)+1;
  $placeholders=implode(',',array_fill(0,count($groups),'?'));
  $s=$pdo->prepare("SELECT id,name,price_rub,stock_qty,stock_status,availability,is_active FROM products WHERE id IN ($placeholders)");$s->execute(array_keys($groups));$rows=$s->fetchAll();
  if(count($rows)!==count($groups))out(['ok'=>false,'error'=>'product_missing'],409);
  $items=[];$total=0;
  foreach($rows as $p){
    if(!(int)$p['is_active']||$p['stock_status']==='out_of_stock'||$p['availability']==='out_of_stock')out(['ok'=>false,'error'=>'out_of_stock','product_id'=>(int)$p['id']],409);
    $qty=$groups[(int)$p['id']];$price=(int)$p['price_rub'];$line=$price*$qty;$total+=$line;
    $items[]=['id'=>(int)$p['id'],'title'=>(string)$p['name'],'price'=>$price,'qty'=>$qty,'line'=>$line];
  }
  $customerId=customer_id_from_session();
  $number='PS-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));
  $pdo->beginTransaction();
  $o=$pdo->prepare('INSERT INTO orders(customer_id,order_number,customer_name,phone,email,delivery_method,address,comment,status,total_rub) VALUES(?,?,?,?,?,?,?,?,\'new\',?)');
  $o->execute([$customerId,$number,$name,$phone,$email?:null,$delivery?:'pickup',$address?:null,$comment?:null,$total]);
  $orderId=(int)$pdo->lastInsertId();
  $i=$pdo->prepare('INSERT INTO order_items(order_id,product_id,title,price_rub,quantity,line_total_rub) VALUES(?,?,?,?,?,?)');
  foreach($items as $x)$i->execute([$orderId,$x['id'],$x['title'],$x['price'],$x['qty'],$x['line']]);
  $pdo->commit();
  out(['ok'=>true,'order_number'=>$number,'total_rub'=>$total]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());out(['ok'=>false,'error'=>'server_error'],500);}