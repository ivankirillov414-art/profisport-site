<?php
declare(strict_types=1);
$configFile=__DIR__.'/../server/config.php';if(!is_file($configFile)){http_response_code(404);exit;}$config=require $configFile;
function normTitle(string $s):string{$s=mb_strtolower(trim($s));$s=str_replace(['ё','–','—'],['е','-','-'],$s);$s=preg_replace('/[^a-zа-я0-9]+/u',' ',$s)??'';return trim(preg_replace('/\s+/u',' ',$s)??$s);}
try{
 $id=(int)($_GET['id']??0);if($id<1){http_response_code(404);exit;}
 $pdo=new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',$config['db_host'],$config['db_name']),$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $s=$pdo->prepare('SELECT name FROM products WHERE id=? LIMIT 1');$s->execute([$id]);$name=(string)($s->fetchColumn()?:'');if($name===''){http_response_code(404);exit;}
 $key=normTitle($name);$cache=__DIR__.'/../uploads/product-image-fallback-map.json';$map=[];
 if(is_file($cache)){$j=json_decode((string)file_get_contents($cache),true);if(is_array($j))$map=$j;}
 if(!$map){foreach(glob(__DIR__.'/../data/catalog-*.json')?:[] as $file){$rows=json_decode((string)file_get_contents($file),true);if(!is_array($rows))continue;foreach($rows as $p){$title=(string)($p['title']??$p['name']??'');$images=$p['images']??[];if($title===''||!is_array($images)||!$images)continue;$u=(string)($images[0]??'');if($u!==''&&preg_match('#^https?://#i',$u))$map[normTitle($title)]=$u;}}if($map){@file_put_contents($cache,json_encode($map,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}}
 $url=(string)($map[$key]??'');if($url===''){http_response_code(404);exit;}
 header('Cache-Control: public, max-age=604800, stale-while-revalidate=86400');header('Location: '.$url,302);exit;
}catch(Throwable $e){error_log($e->__toString());http_response_code(404);exit;}
