<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';

function customer_session(): void {
  if(session_status()===PHP_SESSION_ACTIVE)return;
  ini_set('session.use_strict_mode','1');
  ini_set('session.gc_maxlifetime',(string)(60*60*24*30));
  session_name('PROFISPORT_CUSTOMER');
  session_set_cookie_params(['lifetime'=>60*60*24*30,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
  session_start();
}
function customer_me(PDO $pdo): ?array {
  customer_session();
  $id=(int)($_SESSION['customer_id']??0);
  if($id<1)return null;
  $s=$pdo->prepare('SELECT id,name,email,phone,bonus_balance FROM customers WHERE id=? AND is_active=1 LIMIT 1');
  $s->execute([$id]);
  $u=$s->fetch();
  return $u?:null;
}
function customer_require(PDO $pdo): array { $u=customer_me($pdo); if(!$u)json_response(['ok'=>false,'error'=>'unauthorized'],401); return $u; }
function customer_csrf(): string { customer_session(); if(empty($_SESSION['customer_csrf']))$_SESSION['customer_csrf']=bin2hex(random_bytes(24)); return (string)$_SESSION['customer_csrf']; }
function customer_csrf_check(): void { customer_session(); $t=$_SERVER['HTTP_X_CSRF_TOKEN']??''; if(!$t||empty($_SESSION['customer_csrf'])||!hash_equals((string)$_SESSION['customer_csrf'],$t))json_response(['ok'=>false,'error'=>'csrf'],403); }
function customer_payload(PDO $pdo,array $u): array {
  $f=$pdo->prepare('SELECT product_id FROM customer_favorites WHERE customer_id=? ORDER BY created_at DESC');$f->execute([(int)$u['id']]);
  $history=$pdo->prepare('SELECT amount,kind,note,created_at FROM loyalty_transactions WHERE customer_id=? ORDER BY id DESC LIMIT 20');$history->execute([(int)$u['id']]);
  $orders=$pdo->prepare('SELECT order_number,status,total_rub,created_at FROM orders WHERE customer_id=? ORDER BY id DESC LIMIT 50');$orders->execute([(int)$u['id']]);
  $orderRows=$orders->fetchAll();foreach($orderRows as &$order)$order['total_rub']=(float)$order['total_rub'];unset($order);
  return ['ok'=>true,'customer'=>$u,'favorites'=>array_map('strval',array_column($f->fetchAll(),'product_id')),'loyalty'=>$history->fetchAll(),'orders'=>$orderRows,'csrf'=>customer_csrf()];
}

$action=(string)($_GET['action']??'me');
try{
  if($action==='me'){
    $u=customer_me($pdo);
    if(!$u)json_response(['ok'=>true,'customer'=>null,'csrf'=>customer_csrf()]);
    json_response(customer_payload($pdo,$u));
  }
  if($action==='register'&&$_SERVER['REQUEST_METHOD']==='POST'){
    customer_session(); $in=input_json();
    $name=trim((string)($in['name']??''));$email=mb_strtolower(trim((string)($in['email']??'')));$phone=trim((string)($in['phone']??''));$password=(string)($in['password']??'');
    if(mb_strlen($name)<2||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<8)json_response(['ok'=>false,'error'=>'invalid_input'],422);
    $s=$pdo->prepare('SELECT id FROM customers WHERE email=? LIMIT 1');$s->execute([$email]);if($s->fetch())json_response(['ok'=>false,'error'=>'email_exists'],409);
    $s=$pdo->prepare('INSERT INTO customers(name,email,phone,password_hash) VALUES(?,?,?,?)');$s->execute([$name,$email,$phone?:null,password_hash($password,PASSWORD_DEFAULT)]);
    $_SESSION['customer_id']=(int)$pdo->lastInsertId();$_SESSION['customer_csrf']=bin2hex(random_bytes(24));session_regenerate_id(true);
    json_response(['ok'=>true,'customer'=>customer_me($pdo),'csrf'=>customer_csrf()]);
  }
  if($action==='login'&&$_SERVER['REQUEST_METHOD']==='POST'){
    customer_session();$in=input_json();$email=mb_strtolower(trim((string)($in['email']??'')));$password=(string)($in['password']??'');
    $s=$pdo->prepare('SELECT id,password_hash,is_active FROM customers WHERE email=? LIMIT 1');$s->execute([$email]);$u=$s->fetch();
    if(!$u||!(int)$u['is_active']||!password_verify($password,(string)$u['password_hash']))json_response(['ok'=>false,'error'=>'invalid_credentials'],401);
    session_regenerate_id(true);$_SESSION['customer_id']=(int)$u['id'];$_SESSION['customer_csrf']=bin2hex(random_bytes(24));
    json_response(['ok'=>true,'customer'=>customer_me($pdo),'csrf'=>customer_csrf()]);
  }
  if($action==='logout'&&$_SERVER['REQUEST_METHOD']==='POST'){
    customer_require($pdo);customer_csrf_check();$_SESSION=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);}session_destroy();json_response(['ok'=>true]);
  }
  if($action==='favorite'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $u=customer_require($pdo);customer_csrf_check();$in=input_json();$pid=(int)($in['product_id']??0);if($pid<1)json_response(['ok'=>false,'error'=>'bad_product'],422);
    $s=$pdo->prepare('SELECT 1 FROM customer_favorites WHERE customer_id=? AND product_id=?');$s->execute([(int)$u['id'],$pid]);$exists=(bool)$s->fetchColumn();
    if($exists){$d=$pdo->prepare('DELETE FROM customer_favorites WHERE customer_id=? AND product_id=?');$d->execute([(int)$u['id'],$pid]);$active=false;}else{$i=$pdo->prepare('INSERT IGNORE INTO customer_favorites(customer_id,product_id) VALUES(?,?)');$i->execute([(int)$u['id'],$pid]);$active=true;}
    json_response(['ok'=>true,'active'=>$active]);
  }
  if($action==='favorites_merge'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $u=customer_require($pdo);customer_csrf_check();$in=input_json();$raw=is_array($in['product_ids']??null)?$in['product_ids']:[];
    $ids=[];foreach(array_slice($raw,0,300) as $v){$id=(int)$v;if($id>0)$ids[$id]=true;}
    if(!$ids)json_response(['ok'=>true,'merged'=>0]);
    $marks=implode(',',array_fill(0,count($ids),'?'));$s=$pdo->prepare("SELECT id FROM products WHERE id IN ($marks) AND is_active=1");$s->execute(array_keys($ids));$valid=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
    $ins=$pdo->prepare('INSERT IGNORE INTO customer_favorites(customer_id,product_id) VALUES(?,?)');$merged=0;foreach($valid as $pid){$ins->execute([(int)$u['id'],$pid]);$merged+=$ins->rowCount();}
    json_response(['ok'=>true,'merged'=>$merged]);
  }
  if($action==='order'&&$_SERVER['REQUEST_METHOD']==='GET'){
    $u=customer_require($pdo);$number=trim((string)($_GET['number']??''));if($number==='')json_response(['ok'=>false,'error'=>'bad_order'],422);
    $s=$pdo->prepare('SELECT id,order_number,status,total_rub,delivery_method,address,comment,created_at FROM orders WHERE customer_id=? AND order_number=? LIMIT 1');$s->execute([(int)$u['id'],$number]);$order=$s->fetch();if(!$order)json_response(['ok'=>false,'error'=>'not_found'],404);
    $items=$pdo->prepare('SELECT product_id,title,price_rub,quantity,line_total_rub FROM order_items WHERE order_id=? ORDER BY id');$items->execute([(int)$order['id']]);
    unset($order['id']);json_response(['ok'=>true,'order'=>$order,'items'=>$items->fetchAll()]);
  }
  if($action==='reviews'&&$_SERVER['REQUEST_METHOD']==='GET'){
    $pid=(int)($_GET['product_id']??0);if($pid<1)json_response(['ok'=>false,'error'=>'bad_product'],422);
    $s=$pdo->prepare("SELECT r.id,r.rating,r.review_text,r.created_at,c.name FROM product_reviews r LEFT JOIN customers c ON c.id=r.customer_id WHERE r.product_id=? AND r.status='approved' ORDER BY r.id DESC LIMIT 50");$s->execute([$pid]);$rows=$s->fetchAll();
    $avg=0;if($rows)$avg=array_sum(array_map(fn($r)=>(int)$r['rating'],$rows))/count($rows);
    json_response(['ok'=>true,'count'=>count($rows),'average'=>round($avg,1),'items'=>$rows]);
  }
  if($action==='review_submit'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $u=customer_require($pdo);customer_csrf_check();$in=input_json();$pid=(int)($in['product_id']??0);$rating=(int)($in['rating']??0);$text=trim((string)($in['text']??''));
    if($pid<1||$rating<1||$rating>5||mb_strlen($text)<10)json_response(['ok'=>false,'error'=>'invalid_review'],422);
    $s=$pdo->prepare('INSERT INTO product_reviews(customer_id,product_id,rating,review_text,status) VALUES(?,?,?,?,\'pending\')');$s->execute([(int)$u['id'],$pid,$rating,$text]);
    json_response(['ok'=>true,'status'=>'pending']);
  }
  json_response(['ok'=>false,'error'=>'not_found'],404);
}catch(Throwable $e){error_log($e->__toString());json_response(['ok'=>false,'error'=>'server_error'],500);}
