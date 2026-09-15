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
  $s=$pdo->prepare('SELECT id,name,last_name,email,phone,birth_date,bonus_balance FROM customers WHERE id=? AND is_active=1 LIMIT 1');
  $s->execute([$id]);
  $u=$s->fetch();
  return $u?:null;
}
function customer_require(PDO $pdo): array { $u=customer_me($pdo); if(!$u)json_response(['ok'=>false,'error'=>'unauthorized'],401); return $u; }
function customer_csrf(): string { customer_session(); if(empty($_SESSION['customer_csrf']))$_SESSION['customer_csrf']=bin2hex(random_bytes(24)); return (string)$_SESSION['customer_csrf']; }
function customer_csrf_check(): void { customer_session(); $t=$_SERVER['HTTP_X_CSRF_TOKEN']??''; if(!$t||empty($_SESSION['customer_csrf'])||!hash_equals((string)$_SESSION['customer_csrf'],$t))json_response(['ok'=>false,'error'=>'csrf'],403); }
function customer_phone(string $raw): string {
  $digits=preg_replace('/\D+/','',$raw)??'';
  if(strlen($digits)===10)$digits='7'.$digits;
  if(strlen($digits)===11&&$digits[0]==='8')$digits='7'.substr($digits,1);
  return strlen($digits)===11&&$digits[0]==='7'?'+'.$digits:'';
}
function customer_birth_date(string $raw): string {
  $date=DateTimeImmutable::createFromFormat('!Y-m-d',$raw);$errors=DateTimeImmutable::getLastErrors();
  if(!$date||($errors&&($errors['warning_count']||$errors['error_count'])))return '';
  $today=new DateTimeImmutable('today');$oldest=$today->modify('-120 years');
  return $date<=$today&&$date>=$oldest?$date->format('Y-m-d'):'';
}
function customer_valid_name(string $value): bool { $n=mb_strlen($value);return $n>=2&&$n<=120&&!preg_match('/[<>]/u',$value); }
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
    $name=trim((string)($in['name']??''));$lastName=trim((string)($in['last_name']??''));$email=mb_strtolower(trim((string)($in['email']??'')));$phone=customer_phone((string)($in['phone']??''));$birthDate=customer_birth_date((string)($in['birth_date']??''));$password=(string)($in['password']??'');$consent=($in['consent']??false)===true||($in['consent']??'')==='on';
    if(!customer_valid_name($name)||!customer_valid_name($lastName)||mb_strlen($email)>200||!filter_var($email,FILTER_VALIDATE_EMAIL)||$phone===''||$birthDate===''||strlen($password)<8||strlen($password)>72||!$consent)json_response(['ok'=>false,'error'=>'invalid_input'],422);
    $s=$pdo->prepare('SELECT id,phone,birth_date,password_hash FROM customers WHERE email=? LIMIT 1');$s->execute([$email]);$existing=$s->fetch();
    if($existing){
      if(!empty($existing['password_hash']))json_response(['ok'=>false,'error'=>'email_exists'],409);
      if(customer_phone((string)($existing['phone']??''))!==$phone||(string)($existing['birth_date']??'')!==$birthDate)json_response(['ok'=>false,'error'=>'profile_mismatch'],409);
      $pdo->prepare("UPDATE customers SET name=?,last_name=?,password_hash=?,registration_source='qr_and_account',is_active=1 WHERE id=?")->execute([$name,$lastName,password_hash($password,PASSWORD_DEFAULT),(int)$existing['id']]);
      $_SESSION['customer_id']=(int)$existing['id'];
    }else{
      $s=$pdo->prepare("INSERT INTO customers(name,last_name,email,phone,birth_date,password_hash,registration_source,consent_at) VALUES(?,?,?,?,?,?,'website',NOW())");$s->execute([$name,$lastName,$email,$phone,$birthDate,password_hash($password,PASSWORD_DEFAULT)]);
      $_SESSION['customer_id']=(int)$pdo->lastInsertId();
    }
    $_SESSION['customer_csrf']=bin2hex(random_bytes(24));session_regenerate_id(true);
    json_response(['ok'=>true,'customer'=>customer_me($pdo),'csrf'=>customer_csrf()]);
  }
  if($action==='qr_register'&&$_SERVER['REQUEST_METHOD']==='POST'){
    customer_csrf_check();$in=input_json();
    if(!empty($in['company']))json_response(['ok'=>true]);
    $now=time();$attempts=is_array($_SESSION['qr_attempts']??null)?$_SESSION['qr_attempts']:[];$attempts=array_values(array_filter($attempts,fn($t)=>is_int($t)&&$t>$now-3600));if(count($attempts)>=5)json_response(['ok'=>false,'error'=>'rate_limited'],429);$attempts[]=$now;$_SESSION['qr_attempts']=$attempts;
    $name=trim((string)($in['name']??''));$lastName=trim((string)($in['last_name']??''));$email=mb_strtolower(trim((string)($in['email']??'')));$phone=customer_phone((string)($in['phone']??''));$birthDate=customer_birth_date((string)($in['birth_date']??''));$consent=($in['consent']??false)===true;
    if(!customer_valid_name($name)||!customer_valid_name($lastName)||!filter_var($email,FILTER_VALIDATE_EMAIL)||$phone===''||$birthDate===''||!$consent)json_response(['ok'=>false,'error'=>'invalid_input'],422);
    $s=$pdo->prepare('SELECT id,password_hash FROM customers WHERE email=? OR phone=? ORDER BY (email=?) DESC LIMIT 1');$s->execute([$email,$phone,$email]);$existing=$s->fetch();
    if($existing){
      if(empty($existing['password_hash']))$pdo->prepare("UPDATE customers SET name=?,last_name=?,email=?,phone=?,birth_date=?,registration_source='store_qr',consent_at=NOW(),qr_registered_at=NOW(),is_active=1 WHERE id=?")->execute([$name,$lastName,$email,$phone,$birthDate,(int)$existing['id']]);
      else $pdo->prepare("UPDATE customers SET last_name=COALESCE(NULLIF(last_name,''),?),birth_date=COALESCE(birth_date,?),consent_at=NOW(),qr_registered_at=NOW() WHERE id=?")->execute([$lastName,$birthDate,(int)$existing['id']]);
      $id=(int)$existing['id'];
    }else{
      $s=$pdo->prepare("INSERT INTO customers(name,last_name,email,phone,birth_date,password_hash,registration_source,consent_at,qr_registered_at) VALUES(?,?,?,?,?,NULL,'store_qr',NOW(),NOW())");$s->execute([$name,$lastName,$email,$phone,$birthDate]);$id=(int)$pdo->lastInsertId();
    }
    json_response(['ok'=>true,'customer_id'=>$id]);
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
    $product=$pdo->prepare('SELECT id FROM products WHERE id=? AND is_active=1');$product->execute([$pid]);if(!$product->fetchColumn())json_response(['ok'=>false,'error'=>'bad_product'],404);
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
    if($pid<1||$rating<1||$rating>5||mb_strlen($text)<10||mb_strlen($text)>5000)json_response(['ok'=>false,'error'=>'invalid_review'],422);
    $product=$pdo->prepare('SELECT id FROM products WHERE id=? AND is_active=1');$product->execute([$pid]);if(!$product->fetchColumn())json_response(['ok'=>false,'error'=>'bad_product'],404);
    $s=$pdo->prepare('INSERT INTO product_reviews(customer_id,product_id,rating,review_text,status) VALUES(?,?,?,?,\'pending\')');$s->execute([(int)$u['id'],$pid,$rating,$text]);
    json_response(['ok'=>true,'status'=>'pending']);
  }
  json_response(['ok'=>false,'error'=>'not_found'],404);
}catch(Throwable $e){error_log($e->__toString());json_response(['ok'=>false,'error'=>'server_error'],500);}
