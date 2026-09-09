<?php
declare(strict_types=1);

$configFile=__DIR__.'/../server/config.php';
if(!is_file($configFile)){http_response_code(500);exit;}
$config=require $configFile;
require __DIR__.'/../server/catalog-quality.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, stale-while-revalidate=300');

function local_import_file(string $url): ?string {
    $path=(string)(parse_url($url,PHP_URL_PATH)??'');
    if($path==='')$path=$url;
    if(str_starts_with($path,'/import/'))$rel=substr($path,8);
    elseif(str_starts_with($path,'import/'))$rel=substr($path,7);
    else return null;
    $rel=ltrim(str_replace('\\','/',$rel),'/');
    if($rel===''||str_contains($rel,'..'))return '';
    $root=realpath(__DIR__.'/../import');
    if(!$root)return '';
    $candidate=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel));
    if(!$candidate||!str_starts_with($candidate,$root.DIRECTORY_SEPARATOR)||!is_file($candidate))return '';
    return $candidate;
}

function image_is_usable(string $url,int &$brokenLocal): bool {
    $url=trim($url);
    if($url==='')return false;
    $local=local_import_file($url);
    if($local===null)return true;
    if($local===''){$brokenLocal++;return false;}
    return true;
}

try{
  $pdo=new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',$config['db_host'],$config['db_name']),
    $config['db_user'],
    $config['db_pass'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
  );

  $activeWhere="is_active=1 AND COALESCE(stock_status,'unknown')<>'out_of_stock' AND COALESCE(availability,'unknown')<>'out_of_stock'";
  $excludedMissingPrice=(int)$pdo->query("SELECT COUNT(*) FROM products WHERE $activeWhere AND COALESCE(price_rub,0)<=0")->fetchColumn();

  $limit=max(0,min(500,(int)($_GET['limit']??0)));
  $offset=max(0,(int)($_GET['offset']??0));
  $sql="SELECT id,source_id,name,slug,sku,brand,model,price,old_price,stock_status,stock_qty,short_description,description,specs,main_image,price_rub,old_price_rub,availability,category_path,images,updated_at FROM products WHERE $activeWhere AND COALESCE(price_rub,0)>0 ORDER BY sort_order ASC,id ASC";
  $productId=max(0,(int)($_GET['id']??0));
  if($productId>0)$sql=str_replace(' ORDER BY',' AND id='.$productId.' ORDER BY',$sql);
  if($limit>0)$sql.=' LIMIT '.$limit.' OFFSET '.$offset;
  $stmt=$pdo->query($sql);

  $items=[];
  $brokenLocal=0;
  $itemsWithoutSourceImage=0;
  $itemsWithLocalImage=0;
  $itemsWithRemoteImage=0;
  $removedSuspiciousSpecs=0;
  $suppressedInvalidOldPrices=0;
  $brandsInferred=0;

  while($p=$stmt->fetch()){
    $decoded=json_decode((string)($p['images']??''),true);
    if(!is_array($decoded))$decoded=[];
    if(!$decoded&&!empty($p['main_image']))$decoded=[(string)$p['main_image']];

    $images=[];
    foreach($decoded as $img){
      if(!is_string($img)||!image_is_usable($img,$brokenLocal))continue;
      $img=trim($img);
      if($img!==''&&!in_array($img,$images,true))$images[]=$img;
    }

    $main=(string)($p['main_image']??'');
    if(!$images&&$main!==''&&!in_array($main,$decoded,true)&&image_is_usable($main,$brokenLocal)){
      $images[]=$main;
    }

    $path=(string)($p['category_path']??'');
    $categoryPath=$path===''?[]:array_values(array_filter(array_map('trim',explode('/',$path))));
    $cat=$categoryPath?(string)end($categoryPath):'';

    $sourceImageMissing=!$images;
    if($sourceImageMissing){
      $itemsWithoutSourceImage++;
      $images[]='api/product-fallback-image.php?name='.rawurlencode((string)$p['name']).'&cat='.rawurlencode($cat);
    }else{
      $firstPath=(string)(parse_url($images[0],PHP_URL_PATH)??$images[0]);
      if(str_starts_with($firstPath,'/import/')||str_starts_with($firstPath,'import/'))$itemsWithLocalImage++;
      else $itemsWithRemoteImage++;
    }

    $specs=json_decode((string)($p['specs']??'{}'),true);
    if(!is_array($specs))$specs=[];
    $specs=catalog_sanitize_specs((string)$p['name'],$specs,$removedSuspiciousSpecs);
    $brandWasInferred=false;
    $brand=catalog_resolve_brand((string)$p['name'],$p['brand']!==null?(string)$p['brand']:null,$specs,$brandWasInferred);
    if($brandWasInferred)$brandsInferred++;

    $price=(float)$p['price'];
    $priceRub=(int)$p['price_rub'];
    $rawOldPrice=$p['old_price']!==null?(float)$p['old_price']:null;
    $rawOldPriceRub=$p['old_price_rub']!==null?(int)$p['old_price_rub']:null;
    $oldPrice=catalog_sanitize_old_price_float($price,$rawOldPrice);
    $oldPriceRub=catalog_sanitize_old_price($priceRub,$rawOldPriceRub);
    if($rawOldPriceRub!==null&&$oldPriceRub===null)$suppressedInvalidOldPrices++;

    $items[]=[
      'id'=>(int)$p['id'],
      'source_id'=>$p['source_id'],
      'name'=>$p['name'],
      'title'=>$p['name'],
      'slug'=>$p['slug'],
      'sku'=>$p['sku'],
      'brand'=>$brand,
      'brand_inferred'=>$brandWasInferred,
      'model'=>$p['model'],
      'price'=>$price,
      'price_rub'=>$priceRub,
      'old_price'=>$oldPrice,
      'old_price_rub'=>$oldPriceRub,
      'availability'=>$p['availability']?:$p['stock_status'],
      'stock_status'=>$p['stock_status'],
      'stock_qty'=>$p['stock_qty']!==null?(int)$p['stock_qty']:null,
      'category_path'=>$categoryPath,
      'description'=>$p['short_description']?:($p['description']??''),
      'specs'=>$specs,
      'images'=>$images,
      'image'=>$images[0]??null,
      'image_source_missing'=>$sourceImageMissing,
      'url'=>'product.html?id='.(int)$p['id'],
      'updated_at'=>$p['updated_at']
    ];
  }

  $total=null;
  if(isset($_GET['count'])&&$_GET['count']==='1'){
    $total=(int)$pdo->query("SELECT COUNT(*) FROM products WHERE $activeWhere AND COALESCE(price_rub,0)>0")->fetchColumn();
  }

  echo json_encode([
    'ok'=>true,
    'count'=>count($items),
    'total'=>$total,
    'image_health'=>[
      'broken_local_references_removed'=>$brokenLocal,
      'items_without_source_image'=>$itemsWithoutSourceImage,
      'items_with_local_image'=>$itemsWithLocalImage,
      'items_with_remote_image'=>$itemsWithRemoteImage
    ],
    'quality_health'=>[
      'excluded_missing_price'=>$excludedMissingPrice,
      'suspicious_specs_removed'=>$removedSuspiciousSpecs,
      'invalid_old_prices_suppressed'=>$suppressedInvalidOldPrices,
      'brands_inferred'=>$brandsInferred
    ],
    'items'=>$items
  ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){
  error_log($e->__toString());
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'catalog_unavailable']);
}
