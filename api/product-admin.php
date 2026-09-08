<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
require_admin();
function product_version(array $p):string{return hash('sha256',json_encode($p,JSON_UNESCAPED_UNICODE));}
const PRODUCT_FIELDS='id,name,price_rub,old_price_rub,stock_qty,stock_status,availability,category_path,short_description,is_active';
try{
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $q=mb_substr(trim((string)($_GET['q']??'')),0,200);$page=max(1,min(100000,(int)($_GET['page']??1)));$where=$q!==''?' WHERE name LIKE ? OR sku LIKE ?':'';$args=$q!==''?['%'.$q.'%','%'.$q.'%']:[];
  $s=$pdo->prepare('SELECT COUNT(*) FROM products'.$where);$s->execute($args);$total=(int)$s->fetchColumn();
  $s=$pdo->prepare('SELECT '.PRODUCT_FIELDS.' FROM products'.$where.' ORDER BY id DESC LIMIT 30 OFFSET '.(($page-1)*30));$s->execute($args);$rows=$s->fetchAll();foreach($rows as &$p)$p['version']=product_version($p);unset($p);
  json_response(['ok'=>true,'items'=>$rows,'page'=>$page,'pages'=>max(1,(int)ceil($total/30)),'total'=>$total]);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
 csrf_check();$in=input_json();$id=(int)($in['id']??0);
 $name=$in['name']??'';$cat=$in['category_path']??'';$desc=$in['short_description']??'';
 if($id<1||!is_string($name)||mb_strlen(trim($name))<2||mb_strlen($name)>500||!is_string($cat)||mb_strlen($cat)>2000||!is_string($desc)||mb_strlen($desc)>10000)json_response(['ok'=>false,'error'=>'invalid_input'],422);
 foreach(['price_rub','old_price_rub','stock_qty'] as $key){$v=$in[$key]??null;if($v===null&&$key!=='price_rub')continue;if(!is_int($v)||$v<0||$v>2147483647)json_response(['ok'=>false,'error'=>'invalid_input'],422);}
 if(!in_array($in['is_active']??null,[0,1],true))json_response(['ok'=>false,'error'=>'invalid_input'],422);
 $pdo->beginTransaction();$s=$pdo->prepare('SELECT '.PRODUCT_FIELDS.' FROM products WHERE id=? FOR UPDATE');$s->execute([$id]);$old=$s->fetch();
 if(!$old){$pdo->rollBack();json_response(['ok'=>false,'error'=>'not_found'],404);}
 if(!is_string($in['version']??null)||!hash_equals(product_version($old),$in['version'])){$pdo->rollBack();json_response(['ok'=>false,'error'=>'product_changed'],409);}
 $qty=$in['stock_qty']??null;$stock=$qty===null?'unknown':($qty>0?'in_stock':'out_of_stock');
 $s=$pdo->prepare('UPDATE products SET name=?,price_rub=?,price=?,old_price_rub=?,old_price=?,stock_qty=?,stock_status=?,availability=?,category_path=?,short_description=?,is_active=? WHERE id=?');
 $s->execute([trim($name),$in['price_rub'],$in['price_rub'],$in['old_price_rub']??null,$in['old_price_rub']??null,$qty,$stock,$stock,trim($cat),trim($desc),$in['is_active'],$id]);
 audit($pdo,'product_update','product',(string)$id);$pdo->commit();json_response(['ok'=>true]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());json_response(['ok'=>false,'error'=>'server_error'],500);}
