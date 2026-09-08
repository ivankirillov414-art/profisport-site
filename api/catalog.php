<?php
declare(strict_types=1);
$configFile=__DIR__.'/../server/config.php';
if(!is_file($configFile)){http_response_code(500);exit;}
$config=require $configFile;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
try{
  $pdo=new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',$config['db_host'],$config['db_name']),$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
  $limit=max(0,min(500,(int)($_GET['limit']??0)));
  $offset=max(0,(int)($_GET['offset']??0));
  $sql="SELECT id,source_id,name,slug,sku,brand,model,price,old_price,stock_status,stock_qty,short_description,main_image,price_rub,old_price_rub,availability,category_path,images,updated_at FROM products WHERE is_active=1 AND COALESCE(stock_status,'unknown')<>'out_of_stock' AND COALESCE(availability,'unknown')<>'out_of_stock' ORDER BY sort_order ASC,id ASC";
  if($limit>0)$sql.=' LIMIT '.$limit.' OFFSET '.$offset;
  $stmt=$pdo->query($sql);
  $items=[];
  while($p=$stmt->fetch()){
    $images=json_decode((string)($p['images']??''),true);if(!is_array($images))$images=[];
    if(!$images&&!empty($p['main_image']))$images=[(string)$p['main_image']];
    $path=(string)($p['category_path']??'');$categoryPath=$path===''?[]:array_values(array_filter(array_map('trim',explode('/',$path))));
    $items[]=['id'=>(int)$p['id'],'source_id'=>$p['source_id'],'name'=>$p['name'],'title'=>$p['name'],'slug'=>$p['slug'],'sku'=>$p['sku'],'brand'=>$p['brand'],'model'=>$p['model'],'price'=>(float)$p['price'],'price_rub'=>(int)$p['price_rub'],'old_price'=>$p['old_price']!==null?(float)$p['old_price']:null,'old_price_rub'=>$p['old_price_rub']!==null?(int)$p['old_price_rub']:null,'availability'=>$p['availability']?:$p['stock_status'],'stock_status'=>$p['stock_status'],'stock_qty'=>$p['stock_qty']!==null?(int)$p['stock_qty']:null,'category_path'=>$categoryPath,'description'=>$p['short_description']??'','specs'=>[],'images'=>$images,'image'=>$images[0]??null,'url'=>'product.html?id='.(int)$p['id'],'updated_at'=>$p['updated_at']];
  }
  $total=null;
  if(isset($_GET['count'])&&$_GET['count']==='1')$total=(int)$pdo->query("SELECT COUNT(*) FROM products WHERE is_active=1 AND COALESCE(stock_status,'unknown')<>'out_of_stock' AND COALESCE(availability,'unknown')<>'out_of_stock'")->fetchColumn();
  echo json_encode(['ok'=>true,'count'=>count($items),'total'=>$total,'items'=>$items],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){error_log($e->__toString());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'catalog_unavailable']);}
