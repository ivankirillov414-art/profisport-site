<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30, stale-while-revalidate=60');
try{
  $stmt=$pdo->query("SELECT id,source_id,name,slug,sku,brand,model,price,old_price,stock_status,stock_qty,short_description,description,specs_json,main_image,source_hash,external_url,price_rub,old_price_rub,availability,category_path,specs,images,updated_at FROM products WHERE is_active=1 AND COALESCE(stock_status,'unknown')<>'out_of_stock' AND COALESCE(availability,'unknown')<>'out_of_stock' ORDER BY sort_order ASC,id ASC");
  $items=[];
  while($p=$stmt->fetch(PDO::FETCH_ASSOC)){
    $images=json_decode((string)($p['images']??''),true); if(!is_array($images))$images=[];
    if(!$images&&!empty($p['main_image']))$images=[(string)$p['main_image']];
    $specs=json_decode((string)($p['specs']??$p['specs_json']??''),true); if(!is_array($specs))$specs=[];
    $path=(string)($p['category_path']??''); $categoryPath=$path===''?[]:array_values(array_filter(array_map('trim',explode('/',$path))));
    $items[]=[
      'id'=>(int)$p['id'],'source_id'=>$p['source_id'],'name'=>$p['name'],'title'=>$p['name'],'slug'=>$p['slug'],'sku'=>$p['sku'],'brand'=>$p['brand'],'model'=>$p['model'],
      'price'=>(float)$p['price'],'price_rub'=>(int)$p['price_rub'],'old_price'=>$p['old_price']!==null?(float)$p['old_price']:null,'old_price_rub'=>$p['old_price_rub']!==null?(int)$p['old_price_rub']:null,
      'availability'=>$p['availability']?:$p['stock_status'],'stock_status'=>$p['stock_status'],'stock_qty'=>$p['stock_qty']!==null?(int)$p['stock_qty']:null,'category_path'=>$categoryPath,
      'description'=>$p['description']?:$p['short_description'],'specs'=>$specs,'images'=>$images,'image'=>$images[0]??null,'url'=>'product.html?id='.(int)$p['id'],'updated_at'=>$p['updated_at']
    ];
  }
  echo json_encode(['ok'=>true,'count'=>count($items),'items'=>$items],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){error_log($e->__toString());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'catalog_unavailable']);}
