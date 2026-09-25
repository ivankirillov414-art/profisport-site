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

function customer_order_image(array $row): ?string {
  $decoded=json_decode((string)($row['images']??''),true);if(!is_array($decoded))$decoded=[];
  $urls=array_values(array_unique(array_filter(array_merge([(string)($row['main_image']??'')],array_map('strval',$decoded)),fn($v)=>trim((string)$v)!=='')));
  foreach($urls as $url){
    $url=trim((string)$url);if($url==='')continue;
    if(str_starts_with($url,'/import/'))return 'api/product-image.php?p='.rawurlencode(substr($url,8));
    if(str_starts_with($url,'import/'))return 'api/product-image.php?p='.rawurlencode(substr($url,7));
    if(preg_match('~^https?://~i',$url))return $url;
    if(str_starts_with($url,'api/'))return $url;
  }
  return null;
}
function customer_order_history(PDO $pdo,array $order): array {
  $s=$pdo->prepare('SELECT status,source,created_at FROM order_status_history WHERE order_id=? ORDER BY id');$s->execute([(int)$order['id']]);$rows=$s->fetchAll();
  if($rows){$complete=true;foreach($rows as $row)if(str_starts_with((string)($row['source']??''),'legacy')){$complete=false;break;}return ['complete'=>$complete,'items'=>$rows];}
  $items=[['status'=>'new','source'=>'legacy','created_at'=>(string)$order['created_at']]];
  if((string)$order['status']!=='new')$items[]=['status'=>(string)$order['status'],'source'=>'legacy_current','created_at'=>(string)($order['updated_at']?:$order['created_at'])];
  return ['complete'=>false,'items'=>$items];
}
function customer_favorite_details(PDO $pdo,int $customerId): array {
  $s=$pdo->prepare('SELECT cf.product_id,cf.created_at AS saved_at,p.name,p.price_rub,p.old_price_rub,p.stock_qty,p.stock_status,p.availability,p.is_active,p.main_image,p.images FROM customer_favorites cf LEFT JOIN products p ON p.id=cf.product_id WHERE cf.customer_id=? ORDER BY cf.created_at DESC,cf.product_id DESC');
  $s->execute([$customerId]);$items=[];
  foreach($s->fetchAll() as $row){
    $exists=$row['name']!==null;
    $qty=$row['stock_qty']!==null?(int)$row['stock_qty']:null;
    $price=(int)($row['price_rub']??0);$old=$row['old_price_rub']!==null?(int)$row['old_price_rub']:null;
    $available=$exists&&(int)($row['is_active']??0)===1&&$qty!==null&&$qty>0&&($row['stock_status']??'')!=='out_of_stock'&&($row['availability']??'')!=='out_of_stock'&&$price>0;
    $items[]=[
      'product_id'=>(int)$row['product_id'],
      'name'=>$exists?(string)$row['name']:'Товар больше недоступен',
      'price_rub'=>$price,
      'old_price_rub'=>$old!==null&&$old>$price?$old:null,
      'stock_qty'=>$qty,
      'available'=>$available,
      'exists'=>$exists,
      'image'=>$exists?customer_order_image($row):null,
      'saved_at'=>(string)$row['saved_at'],
      'url'=>$exists?'product.html?id='.(int)$row['product_id']:null
    ];
  }
  return $items;
}
function customer_review_details(PDO $pdo,int $customerId): array {
  $s=$pdo->prepare("SELECT r.id,r.product_id,r.rating,r.review_text,r.status,r.created_at,r.updated_at,p.name,p.main_image,p.images,EXISTS(SELECT 1 FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.customer_id=r.customer_id AND oi.product_id=r.product_id AND o.status='completed') AS verified_purchase FROM product_reviews r LEFT JOIN products p ON p.id=r.product_id WHERE r.customer_id=? ORDER BY r.id DESC");
  $s->execute([$customerId]);$items=[];
  foreach($s->fetchAll() as $row){
    $items[]=[
      'id'=>(int)$row['id'],
      'product_id'=>(int)$row['product_id'],
      'name'=>$row['name']!==null?(string)$row['name']:'Товар больше недоступен',
      'rating'=>(int)$row['rating'],
      'text'=>(string)$row['review_text'],
      'status'=>(string)$row['status'],
      'verified_purchase'=>(bool)$row['verified_purchase'],
      'image'=>$row['name']!==null?customer_order_image($row):null,
      'created_at'=>(string)$row['created_at'],
      'updated_at'=>(string)$row['updated_at'],
      'url'=>$row['name']!==null?'product.html?id='.(int)$row['product_id']:null
    ];
  }
  return $items;
}
function customer_review_eligible(PDO $pdo,int $customerId): array {
  $s=$pdo->prepare("SELECT oi.product_id,MAX(o.id) AS last_order_id,MAX(o.created_at) AS purchased_at,SUBSTRING_INDEX(GROUP_CONCAT(o.order_number ORDER BY o.id DESC SEPARATOR ','),',',1) AS order_number,SUBSTRING_INDEX(GROUP_CONCAT(oi.title ORDER BY o.id DESC SEPARATOR '|||'),'|||',1) AS purchased_title,p.name,p.main_image,p.images FROM orders o JOIN order_items oi ON oi.order_id=o.id LEFT JOIN product_reviews r ON r.customer_id=o.customer_id AND r.product_id=oi.product_id LEFT JOIN products p ON p.id=oi.product_id WHERE o.customer_id=? AND o.status='completed' AND oi.product_id IS NOT NULL AND r.id IS NULL GROUP BY oi.product_id,p.name,p.main_image,p.images ORDER BY last_order_id DESC LIMIT 100");
  $s->execute([$customerId]);$items=[];
  foreach($s->fetchAll() as $row){
    $items[]=[
      'product_id'=>(int)$row['product_id'],
      'name'=>$row['name']!==null?(string)$row['name']:(string)$row['purchased_title'],
      'image'=>$row['name']!==null?customer_order_image($row):null,
      'order_number'=>(string)$row['order_number'],
      'purchased_at'=>(string)$row['purchased_at'],
      'url'=>$row['name']!==null?'product.html?id='.(int)$row['product_id']:null
    ];
  }
  return $items;
}
function customer_payload(PDO $pdo,array $u): array {
  $f=$pdo->prepare('SELECT product_id FROM customer_favorites WHERE customer_id=? ORDER BY created_at DESC');$f->execute([(int)$u['id']]);
  $history=$pdo->prepare('SELECT amount,kind,note,created_at FROM loyalty_transactions WHERE customer_id=? ORDER BY id DESC LIMIT 20');$history->execute([(int)$u['id']]);
  $orders=$pdo->prepare('SELECT order_number,status,total_rub,delivery_method,pickup_store,address,created_at FROM orders WHERE customer_id=? ORDER BY id DESC LIMIT 50');$orders->execute([(int)$u['id']]);
  $orderRows=$orders->fetchAll();foreach($orderRows as &$order)$order['total_rub']=(float)$order['total_rub'];unset($order);
  $reviews=$pdo->prepare('SELECT COUNT(*) FROM product_reviews WHERE customer_id=?');$reviews->execute([(int)$u['id']]);$reviewsCount=(int)$reviews->fetchColumn();
  $favoriteIds=array_map('strval',array_column($f->fetchAll(),'product_id'));
  return ['ok'=>true,'customer'=>$u,'favorites'=>$favoriteIds,'favorite_details'=>customer_favorite_details($pdo,(int)$u['id']),'loyalty'=>$history->fetchAll(),'orders'=>$orderRows,'reviews_count'=>$reviewsCount,'review_details'=>customer_review_details($pdo,(int)$u['id']),'review_eligible'=>customer_review_eligible($pdo,(int)$u['id']),'csrf'=>customer_csrf()];
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
    auth_rate_check($pdo,'customer_register','',5,3600);
    if(!customer_valid_name($name)||!customer_valid_name($lastName)||mb_strlen($email)>200||!filter_var($email,FILTER_VALIDATE_EMAIL)||$phone===''||$birthDate===''||strlen($password)<10||strlen($password)>72||!$consent)json_response(['ok'=>false,'error'=>'invalid_input'],422);
    $s=$pdo->prepare('SELECT id,phone,birth_date,password_hash FROM customers WHERE email=? LIMIT 1');$s->execute([$email]);$existing=$s->fetch();
    if($existing){
      if(!empty($existing['password_hash'])){auth_rate_failure($pdo,'customer_register','',5,3600,3600);json_response(['ok'=>false,'error'=>'email_exists'],409);}
      if(customer_phone((string)($existing['phone']??''))!==$phone||(string)($existing['birth_date']??'')!==$birthDate){auth_rate_failure($pdo,'customer_register','',5,3600,3600);json_response(['ok'=>false,'error'=>'profile_mismatch'],409);}
      $pdo->prepare("UPDATE customers SET name=?,last_name=?,password_hash=?,registration_source='qr_and_account',is_active=1 WHERE id=?")->execute([$name,$lastName,password_hash($password,PASSWORD_DEFAULT),(int)$existing['id']]);
      $_SESSION['customer_id']=(int)$existing['id'];
    }else{
      $s=$pdo->prepare("INSERT INTO customers(name,last_name,email,phone,birth_date,password_hash,registration_source,consent_at) VALUES(?,?,?,?,?,?,'website',NOW())");$s->execute([$name,$lastName,$email,$phone,$birthDate,password_hash($password,PASSWORD_DEFAULT)]);
      $_SESSION['customer_id']=(int)$pdo->lastInsertId();
    }
    auth_rate_clear($pdo,'customer_register','');$_SESSION['customer_csrf']=bin2hex(random_bytes(24));session_regenerate_id(true);
    json_response(['ok'=>true,'customer'=>customer_me($pdo),'csrf'=>customer_csrf()]);
  }
  if($action==='qr_register'&&$_SERVER['REQUEST_METHOD']==='POST'){
    customer_csrf_check();$in=input_json();
    if(!empty($in['company']))json_response(['ok'=>true]);
    auth_rate_check($pdo,'customer_qr_register','',5,3600);auth_rate_failure($pdo,'customer_qr_register','',5,3600,3600);
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
    auth_rate_check($pdo,'customer_login',$email);
    $s=$pdo->prepare('SELECT id,password_hash,is_active FROM customers WHERE email=? LIMIT 1');$s->execute([$email]);$u=$s->fetch();
    if(!$u||!(int)$u['is_active']||!password_verify($password,(string)$u['password_hash'])){auth_rate_failure($pdo,'customer_login',$email);json_response(['ok'=>false,'error'=>'invalid_credentials'],401);}
    auth_rate_clear($pdo,'customer_login',$email);session_regenerate_id(true);$_SESSION['customer_id']=(int)$u['id'];$_SESSION['customer_csrf']=bin2hex(random_bytes(24));
    json_response(['ok'=>true,'customer'=>customer_me($pdo),'csrf'=>customer_csrf()]);
  }
  if($action==='logout'&&$_SERVER['REQUEST_METHOD']==='POST'){
    customer_require($pdo);customer_csrf_check();$_SESSION=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);}session_destroy();json_response(['ok'=>true]);
  }
  if($action==='favorite'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $u=customer_require($pdo);customer_csrf_check();$in=input_json();$pid=(int)($in['product_id']??0);if($pid<1)json_response(['ok'=>false,'error'=>'bad_product'],422);
    $s=$pdo->prepare('SELECT 1 FROM customer_favorites WHERE customer_id=? AND product_id=?');$s->execute([(int)$u['id'],$pid]);$exists=(bool)$s->fetchColumn();
    if($exists){
      $d=$pdo->prepare('DELETE FROM customer_favorites WHERE customer_id=? AND product_id=?');$d->execute([(int)$u['id'],$pid]);$active=false;
    }else{
      $product=$pdo->prepare('SELECT id FROM products WHERE id=?');$product->execute([$pid]);if(!$product->fetchColumn())json_response(['ok'=>false,'error'=>'bad_product'],404);
      $i=$pdo->prepare('INSERT IGNORE INTO customer_favorites(customer_id,product_id) VALUES(?,?)');$i->execute([(int)$u['id'],$pid]);$active=true;
    }
    $count=$pdo->prepare('SELECT COUNT(*) FROM customer_favorites WHERE customer_id=?');$count->execute([(int)$u['id']]);
    json_response(['ok'=>true,'active'=>$active,'count'=>(int)$count->fetchColumn()]);
  }
  if($action==='favorite_remove'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $u=customer_require($pdo);customer_csrf_check();$in=input_json();$pid=(int)($in['product_id']??0);if($pid<1)json_response(['ok'=>false,'error'=>'bad_product'],422);
    $d=$pdo->prepare('DELETE FROM customer_favorites WHERE customer_id=? AND product_id=?');$d->execute([(int)$u['id'],$pid]);
    $count=$pdo->prepare('SELECT COUNT(*) FROM customer_favorites WHERE customer_id=?');$count->execute([(int)$u['id']]);
    json_response(['ok'=>true,'active'=>false,'removed'=>$d->rowCount()>0,'count'=>(int)$count->fetchColumn()]);
  }
  if($action==='favorites_merge'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $u=customer_require($pdo);customer_csrf_check();$in=input_json();$raw=is_array($in['product_ids']??null)?$in['product_ids']:[];
    $ids=[];foreach(array_slice($raw,0,300) as $v){$id=(int)$v;if($id>0)$ids[$id]=true;}
    if(!$ids)json_response(['ok'=>true,'merged'=>0]);
    $marks=implode(',',array_fill(0,count($ids),'?'));$s=$pdo->prepare("SELECT id FROM products WHERE id IN ($marks)");$s->execute(array_keys($ids));$valid=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
    $ins=$pdo->prepare('INSERT IGNORE INTO customer_favorites(customer_id,product_id) VALUES(?,?)');$merged=0;foreach($valid as $pid){$ins->execute([(int)$u['id'],$pid]);$merged+=$ins->rowCount();}
    json_response(['ok'=>true,'merged'=>$merged]);
  }
  if($action==='order'&&$_SERVER['REQUEST_METHOD']==='GET'){
    $u=customer_require($pdo);$number=trim((string)($_GET['number']??''));if($number==='')json_response(['ok'=>false,'error'=>'bad_order'],422);
    $s=$pdo->prepare('SELECT id,order_number,status,total_rub,delivery_method,pickup_store,address,comment,created_at,updated_at FROM orders WHERE customer_id=? AND order_number=? LIMIT 1');$s->execute([(int)$u['id'],$number]);$order=$s->fetch();if(!$order)json_response(['ok'=>false,'error'=>'not_found'],404);
    $items=$pdo->prepare('SELECT oi.product_id,oi.title,oi.price_rub,oi.quantity,oi.line_total_rub,p.main_image,p.images,p.is_active,p.stock_qty,p.stock_status,p.availability,p.price_rub AS current_price_rub FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=? ORDER BY oi.id');$items->execute([(int)$order['id']]);$itemRows=$items->fetchAll();
    foreach($itemRows as &$item){
      $item['image']=customer_order_image($item);
      $item['current_available']=((int)($item['is_active']??0)===1)&&(($item['stock_status']??'')!=='out_of_stock')&&(($item['availability']??'')!=='out_of_stock')&&($item['stock_qty']===null||(int)$item['stock_qty']>0)&&((int)($item['current_price_rub']??0)>0);
      unset($item['main_image'],$item['images'],$item['is_active'],$item['stock_status'],$item['availability']);
    }unset($item);
    $history=customer_order_history($pdo,$order);
    unset($order['id'],$order['updated_at']);
    json_response(['ok'=>true,'order'=>$order,'items'=>$itemRows,'history'=>$history['items'],'history_complete'=>$history['complete']]);
  }
  if($action==='repeat_order'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $u=customer_require($pdo);customer_csrf_check();$in=input_json();$number=trim((string)($in['order_number']??''));if($number==='')json_response(['ok'=>false,'error'=>'bad_order'],422);
    $s=$pdo->prepare("SELECT id,status FROM orders WHERE customer_id=? AND order_number=? LIMIT 1");$s->execute([(int)$u['id'],$number]);$order=$s->fetch();if(!$order)json_response(['ok'=>false,'error'=>'not_found'],404);
    if((string)$order['status']!=='completed')json_response(['ok'=>false,'error'=>'repeat_not_available'],409);
    $q=$pdo->prepare('SELECT oi.product_id,oi.title,oi.quantity,p.is_active,p.stock_qty,p.stock_status,p.availability,p.price_rub FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=? ORDER BY oi.id');$q->execute([(int)$order['id']]);
    $cart=[];$skipped=[];
    foreach($q->fetchAll() as $row){
      $requested=max(1,(int)$row['quantity']);$pid=(int)($row['product_id']??0);
      $available=$pid>0&&(int)($row['is_active']??0)===1&&($row['stock_status']??'')!=='out_of_stock'&&($row['availability']??'')!=='out_of_stock'&&(int)($row['price_rub']??0)>0;
      if(!$available){$skipped[]=['product_id'=>$pid,'title'=>(string)$row['title'],'requested'=>$requested,'added'=>0,'reason'=>'unavailable'];continue;}
      $allowed=$requested;if($row['stock_qty']!==null)$allowed=min($allowed,max(0,(int)$row['stock_qty']));
      if($allowed<1){$skipped[]=['product_id'=>$pid,'title'=>(string)$row['title'],'requested'=>$requested,'added'=>0,'reason'=>'out_of_stock'];continue;}
      for($i=0;$i<$allowed&&count($cart)<500;$i++)$cart[]=(string)$pid;
      if($allowed<$requested)$skipped[]=['product_id'=>$pid,'title'=>(string)$row['title'],'requested'=>$requested,'added'=>$allowed,'reason'=>'reduced_stock'];
    }
    json_response(['ok'=>true,'order_number'=>$number,'cart_items'=>$cart,'added_count'=>count($cart),'skipped'=>$skipped]);
  }
  if($action==='reviews'&&$_SERVER['REQUEST_METHOD']==='GET'){
    $pid=(int)($_GET['product_id']??0);if($pid<1)json_response(['ok'=>false,'error'=>'bad_product'],422);
    $s=$pdo->prepare("SELECT r.id,r.rating,r.review_text,r.created_at,c.name,EXISTS(SELECT 1 FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.customer_id=r.customer_id AND oi.product_id=r.product_id AND o.status='completed') AS verified_purchase FROM product_reviews r LEFT JOIN customers c ON c.id=r.customer_id WHERE r.product_id=? AND r.status='approved' ORDER BY r.id DESC LIMIT 50");$s->execute([$pid]);$rows=$s->fetchAll();
    foreach($rows as &$row)$row['verified_purchase']=(bool)$row['verified_purchase'];unset($row);
    $avg=0;if($rows)$avg=array_sum(array_map(fn($r)=>(int)$r['rating'],$rows))/count($rows);
    json_response(['ok'=>true,'count'=>count($rows),'average'=>round($avg,1),'items'=>$rows]);
  }
  if($action==='review_submit'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $u=customer_require($pdo);customer_csrf_check();$in=input_json();$pid=(int)($in['product_id']??0);$rating=(int)($in['rating']??0);$text=trim((string)($in['text']??''));
    if($pid<1||$rating<1||$rating>5||mb_strlen($text)<10||mb_strlen($text)>5000)json_response(['ok'=>false,'error'=>'invalid_review'],422);
    $customerId=(int)$u['id'];$lock='profisport_review_'.$customerId.'_'.$pid;
    $l=$pdo->prepare('SELECT GET_LOCK(?,5)');$l->execute([$lock]);if((int)$l->fetchColumn()!==1)json_response(['ok'=>false,'error'=>'busy'],409);
    try{
      $purchase=$pdo->prepare("SELECT o.id FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.customer_id=? AND oi.product_id=? AND o.status='completed' ORDER BY o.id DESC LIMIT 1");
      $purchase->execute([$customerId,$pid]);if(!$purchase->fetchColumn())json_response(['ok'=>false,'error'=>'review_not_eligible'],403);
      $existing=$pdo->prepare('SELECT id,status FROM product_reviews WHERE customer_id=? AND product_id=? ORDER BY id DESC LIMIT 1');$existing->execute([$customerId,$pid]);$review=$existing->fetch();
      if($review){
        if((string)$review['status']!=='rejected')json_response(['ok'=>false,'error'=>'duplicate_review'],409);
        $s=$pdo->prepare("UPDATE product_reviews SET rating=?,review_text=?,status='pending',updated_at=NOW() WHERE id=?");$s->execute([$rating,$text,(int)$review['id']]);
        json_response(['ok'=>true,'status'=>'pending','resubmitted'=>true,'review_id'=>(int)$review['id']]);
      }
      $s=$pdo->prepare("INSERT INTO product_reviews(customer_id,product_id,rating,review_text,status) VALUES(?,?,?,?,'pending')");$s->execute([$customerId,$pid,$rating,$text]);
      json_response(['ok'=>true,'status'=>'pending','resubmitted'=>false,'review_id'=>(int)$pdo->lastInsertId()]);
    }finally{
      try{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lock]);}catch(Throwable $ignored){}
    }
  }
  json_response(['ok'=>false,'error'=>'not_found'],404);
}catch(Throwable $e){error_log($e->__toString());json_response(['ok'=>false,'error'=>'server_error'],500);}
