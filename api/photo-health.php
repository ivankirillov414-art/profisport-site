<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ph_out(array $x,int $code=200): never {http_response_code($code);echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function ph_norm(string $s): string {$s=mb_strtolower(trim($s),'UTF-8');$s=str_replace('ё','е',$s);$s=preg_replace('/[^a-zа-я0-9]+/u',' ',$s)??'';return trim(preg_replace('/\s+/u',' ',$s)??'');}
function ph_local_status(string $url): ?bool {
    $url=trim($url);if($url==='')return false;if(preg_match('~^https?://~i',$url))return null;
    $path=(string)(parse_url($url,PHP_URL_PATH)??$url);$path=rawurldecode($path);
    if(str_starts_with($path,'/import/'))$rel=substr($path,8);elseif(str_starts_with($path,'import/'))$rel=substr($path,7);else return null;
    $rel=ltrim(str_replace('\\','/',$rel),'/');if($rel===''||str_contains($rel,'..'))return false;
    $root=realpath(__DIR__.'/../import');if(!$root)return false;$full=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel));
    return $full!==false&&str_starts_with($full,$root.DIRECTORY_SEPARATOR)&&is_file($full);
}
function ph_override_names(): array {
    $file=__DIR__.'/../data/photo-overrides.json';if(!is_file($file))return [];
    $j=json_decode((string)@file_get_contents($file),true);$items=is_array($j['items']??null)?$j['items']:[];$out=[];
    foreach($items as $key=>$row){$url=is_array($row)?trim((string)($row['image']??'')):trim((string)$row);if($url!==''&&preg_match('~^https?://~i',$url))$out[ph_norm((string)$key)]=true;}
    return $out;
}
function ph_fallback_index(): array {
    $dataRoot=realpath(__DIR__.'/../data');if(!$dataRoot)return ['by_key'=>[],'by_name'=>[]];
    $manifestPath=$dataRoot.DIRECTORY_SEPARATOR.'manifest.json';if(!is_file($manifestPath))return ['by_key'=>[],'by_name'=>[]];
    $manifest=json_decode((string)file_get_contents($manifestPath),true);$parts=is_array($manifest['parts']??null)?$manifest['parts']:[];$rows=[];$freq=[];
    foreach($parts as $part){$file=$dataRoot.DIRECTORY_SEPARATOR.basename((string)$part);if(!is_file($file))continue;$chunk=json_decode((string)file_get_contents($file),true);if(!is_array($chunk))continue;
        foreach($chunk as $row){if(!is_array($row))continue;$title=trim((string)($row['title']??$row['name']??''));$images=is_array($row['images']??null)?$row['images']:[];if($title===''||!$images)continue;$path=is_array($row['category_path']??null)?$row['category_path']:[];$cat=$path?(string)end($path):'';$valid=[];
            foreach($images as $u){$u=trim((string)$u);if(!preg_match('~^https?://~i',$u))continue;$valid[]=$u;$freq[$u]=($freq[$u]??0)+1;}if($valid)$rows[]=['name'=>ph_norm($title),'cat'=>ph_norm($cat),'images'=>array_values(array_unique($valid))];}}
    $byKey=[];$nameBuckets=[];foreach($rows as $row){$safe=[];foreach($row['images'] as $u){if(($freq[$u]??0)<=3)$safe[]=$u;if(count($safe)>=3)break;}if(!$safe)continue;$key=$row['name'].'|'.$row['cat'];if(!isset($byKey[$key]))$byKey[$key]=true;else$byKey[$key]=false;$nameBuckets[$row['name']][]=json_encode($safe,JSON_UNESCAPED_SLASHES);}
    $byKey=array_filter($byKey,fn($v)=>$v===true);$byName=[];foreach($nameBuckets as $name=>$sets){if(count(array_unique($sets))===1)$byName[$name]=true;}return ['by_key'=>$byKey,'by_name'=>$byName];
}
function ph_has_fallback(array $idx,array $overrides,string $name,string $categoryPath): bool {
    $n=ph_norm($name);if($n==='')return false;if(!empty($overrides[$n]))return true;
    $parts=array_values(array_filter(array_map('trim',explode('/',$categoryPath))));$cat=$parts?(string)end($parts):'';$c=ph_norm($cat);
    if($c!==''&&!empty($idx['by_key'][$n.'|'.$c]))return true;return !empty($idx['by_name'][$n]);
}

try{
    require_admin();$st=$pdo->query("SELECT id,name,main_image,images,category_path FROM products WHERE is_active=1 ORDER BY id");$idx=ph_fallback_index();$overrides=ph_override_names();
    $stats=['active_products'=>0,'products_without_db_image'=>0,'products_with_local_image'=>0,'products_with_remote_image'=>0,'broken_local_references'=>0,'products_with_broken_local_only'=>0,'fallback_available'=>0,'autonomous_overrides'=>count($overrides),'unresolved_products'=>0];$examples=[];
    while($p=$st->fetch(PDO::FETCH_ASSOC)){$stats['active_products']++;$imgs=json_decode((string)($p['images']??''),true);if(!is_array($imgs))$imgs=[];$urls=array_values(array_unique(array_filter(array_merge([(string)($p['main_image']??'')],array_map('strval',$imgs)),fn($u)=>trim((string)$u)!=='')));if(!$urls)$stats['products_without_db_image']++;
        $hasLocal=false;$hasRemote=false;$hadBrokenLocal=false;foreach($urls as $u){$s=ph_local_status((string)$u);if($s===true)$hasLocal=true;elseif($s===false){$stats['broken_local_references']++;$hadBrokenLocal=true;}else$hasRemote=true;}
        if($hasLocal)$stats['products_with_local_image']++;if($hasRemote)$stats['products_with_remote_image']++;if($hadBrokenLocal&&!$hasLocal&&!$hasRemote)$stats['products_with_broken_local_only']++;
        $needsFallback=!$hasLocal&&!$hasRemote;$fallback=$needsFallback&&ph_has_fallback($idx,$overrides,(string)$p['name'],(string)($p['category_path']??''));if($fallback)$stats['fallback_available']++;
        if($needsFallback&&!$fallback){$stats['unresolved_products']++;if(count($examples)<20)$examples[]=['id'=>(int)$p['id'],'name'=>(string)$p['name'],'category'=>(string)($p['category_path']??'')];}}
    ph_out(['ok'=>true,'generated_at'=>date('c'),'source_policy'=>['primary'=>'mysql.products.main_image/images','fallback'=>'only_when_no_working_db_reference','internet'=>'manual_moderation_only'],'stats'=>$stats,'unresolved_examples'=>$examples]);
}catch(Throwable $e){error_log($e->__toString());ph_out(['ok'=>false,'error'=>'photo_health_failed'],500);}
