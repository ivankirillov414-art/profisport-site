<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
require_once __DIR__.'/../server/product-photos.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function ph_out(array $x,int $code=200): never {http_response_code($code);echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function ph_local_status(string $url): ?bool {
  $url=trim($url);if($url==='')return false;
  $manual=pph_photo_path($url);if($manual!==null)return is_file($manual);
  if(preg_match('~^https?://~i',$url))return true;
  $path=(string)(parse_url($url,PHP_URL_PATH)??$url);$path=rawurldecode($path);
  if(str_starts_with($path,'/import/'))$rel=substr($path,8);elseif(str_starts_with($path,'import/'))$rel=substr($path,7);else return null;
  $rel=ltrim(str_replace('\\','/',$rel),'/');if($rel===''||str_contains($rel,'..'))return false;
  $root=realpath(__DIR__.'/../import');if(!$root)return false;
  $full=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel));
  return $full!==false&&str_starts_with($full,$root.DIRECTORY_SEPARATOR)&&is_file($full);
}
try{
  require_admin();
  $st=$pdo->query("SELECT id,source_id,name,main_image,images,category_path FROM products WHERE is_active=1 ORDER BY id");
  $stats=['active_products'=>0,'products_with_manual_gallery'=>0,'products_with_working_image'=>0,'products_without_db_image'=>0,'products_with_broken_local_only'=>0,'broken_local_references'=>0];$examples=[];
  foreach(pph_apply_rows($pdo,$st->fetchAll(PDO::FETCH_ASSOC)) as $p){
    $stats['active_products']++;if(!empty($p['_photo_manual']))$stats['products_with_manual_gallery']++;
    $imgs=json_decode((string)($p['images']??''),true);if(!is_array($imgs))$imgs=[];
    $urls=array_values(array_unique(array_filter(array_merge([(string)($p['main_image']??'')],array_map('strval',$imgs)),fn($u)=>trim((string)$u)!=='')));
    if(!$urls){$stats['products_without_db_image']++;if(count($examples)<30)$examples[]=['id'=>(int)$p['id'],'name'=>(string)$p['name'],'category'=>(string)($p['category_path']??''),'reason'=>'нет фото в текущей галерее'];continue;}
    $working=false;$brokenLocal=false;
    foreach($urls as $u){$status=ph_local_status((string)$u);if($status===true||$status===null)$working=true;elseif($status===false){$brokenLocal=true;$stats['broken_local_references']++;}}
    if($working)$stats['products_with_working_image']++;
    elseif($brokenLocal){$stats['products_with_broken_local_only']++;if(count($examples)<30)$examples[]=['id'=>(int)$p['id'],'name'=>(string)$p['name'],'category'=>(string)($p['category_path']??''),'reason'=>'битая локальная ссылка'];}
  }
  ph_out(['ok'=>true,'generated_at'=>date(DATE_ATOM),'source_policy'=>['primary'=>'manual_override_or_current_1c_mysql','fallback'=>'disabled','internet_search'=>'disabled','admin_override'=>'enabled'],'stats'=>$stats,'unresolved_examples'=>$examples]);
}catch(Throwable $e){error_log($e->__toString());ph_out(['ok'=>false,'error'=>'photo_health_failed'],500);}
