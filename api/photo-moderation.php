<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pm_out(array $x,int $code=200): never { http_response_code($code); echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function pm_norm(string $s): string { $s=mb_strtolower(trim($s)); $s=str_replace(['ё'],'е',$s); $s=preg_replace('/[^a-zа-я0-9]+/u',' ',$s)??''; return trim(preg_replace('/\s+/u',' ',$s)??''); }
function pm_tokens(string $s): array { $stop=['для','или','при','под','над','без','комплект','набор','шт','мм','см','черный','черная','черное','белый','белая','белое','серый','серая','серое','синий','синяя','синее','красный','красная','красное','зеленый','зеленая','желтый','желтая','купить','цена','фото','товар']; $out=[]; foreach(preg_split('/\s+/u',pm_norm($s))?:[] as $t){ if(mb_strlen($t)<3||in_array($t,$stop,true))continue; $out[$t]=true; } return array_keys($out); }
function pm_standalone_numbers(string $s): array { $out=[]; if(preg_match_all('/(?<![\p{L}\p{N}])\d+(?:[.,]\d+)?(?![\p{L}\p{N}])/u',$s,$m)){ foreach($m[0] as $n){$n=str_replace(',','.',$n);$n=ltrim($n,'0');if($n===''||str_starts_with($n,'.'))$n='0'.$n;$out[$n]=true;} } return array_keys($out); }
function pm_distinctive_tokens(string $s,string $brand=''): array { $generic=['велосипед','велосипеды','самокат','самокаты','трюковой','трюковые','подростковый','подростковые','детский','детские','горный','горные','спортивный','спортивные','мат','маты','фитнес','покрытием','размер','модель']; $brandTokens=array_flip(pm_tokens($brand)); $out=[]; foreach(pm_tokens($s) as $t){ if(isset($brandTokens[$t])||in_array($t,$generic,true))continue; $out[$t]=true; } return array_keys($out); }
function pm_title_relevant(string $candidate,string $name,string $brand,string $model,string $categoryPath=''): bool {
  $candidate=trim($candidate); if($candidate==='')return false;
  $cn=pm_norm($candidate); $nn=pm_norm($name); if($cn===''||$nn==='')return false;
  if($cn===$nn)return true;

  $brandN=pm_norm($brand); $modelN=pm_norm($model);
  $brandMatch=$brandN!==''&&mb_strlen($brandN)>=3&&str_contains($cn,$brandN);
  $modelMatch=$modelN!==''&&mb_strlen($modelN)>=2&&str_contains($cn,$modelN);

  // Размеры и номера моделей — сильный сигнал. 24/470 не должны совпадать с 28/300.
  $targetNums=pm_standalone_numbers($name.' '.$model);
  $candNums=pm_standalone_numbers($candidate);
  if($targetNums&&$candNums&&!array_intersect($targetNums,$candNums))return false;

  $targetDistinct=pm_distinctive_tokens($name.' '.$model,$brand);
  $candDistinct=pm_distinctive_tokens($candidate,$brand);
  $common=array_values(array_intersect($targetDistinct,$candDistinct));

  // Если бренд в карточке известен, чужой бренд не принимаем без точного совпадения модели.
  if($brandN!==''&&!$brandMatch&&!$modelMatch)return false;
  if($modelMatch&&count($common)>=1)return true;

  $need=count($targetDistinct)>=4?2:1;
  if(count($common)<$need)return false;

  // Для длинных названий одного общего слова недостаточно: нужен хотя бы заметный процент совпадений.
  $coverage=count($common)/max(1,count($targetDistinct));
  if(count($targetDistinct)>=6&&$coverage<0.34)return false;
  return true;
}
function pm_local_image_ok(?string $url): bool { if(!$url)return false; $url=trim($url); if($url==='')return false; if(preg_match('~^https?://~i',$url))return true; $path=parse_url($url,PHP_URL_PATH)?:$url; $path=rawurldecode($path); if(str_starts_with($path,'/import/')){ $root=realpath(__DIR__.'/../import'); if(!$root)return false; $rel=ltrim(substr($path,8),'/'); if($rel===''||str_contains($rel,'..'))return false; $full=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel)); return $full!==false&&str_starts_with($full,$root.DIRECTORY_SEPARATOR)&&is_file($full); } if(str_starts_with($path,'import/')){ $root=realpath(__DIR__.'/../import'); if(!$root)return false; $rel=substr($path,7); $full=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel)); return $full!==false&&is_file($full); } $doc=realpath(__DIR__.'/..'); if(!$doc)return false; $full=realpath($doc.DIRECTORY_SEPARATOR.ltrim($path,'/')); return $full!==false&&is_file($full); }
function pm_product_broken(array $p): bool { $imgs=json_decode((string)($p['images']??''),true); if(!is_array($imgs))$imgs=[]; $urls=array_values(array_unique(array_filter(array_merge([(string)($p['main_image']??'')],array_map('strval',$imgs))))); if(!$urls)return true; foreach($urls as $u)if(pm_local_image_ok($u))return false; return true; }

function pm_build_index(): array {
  $cache=__DIR__.'/../uploads/photo-candidate-index-v3.json';
  if(is_file($cache)&&filemtime($cache)>time()-86400*7){$j=json_decode((string)file_get_contents($cache),true);if(is_array($j))return $j;}
  $dataDir=__DIR__.'/../data'; $items=[];
  foreach(glob($dataDir.'/catalog-*.json')?:[] as $file){
    $rows=json_decode((string)file_get_contents($file),true); if(!is_array($rows))continue;
    foreach($rows as $r){
      if(!is_array($r))continue;
      $title=trim((string)($r['title']??$r['name']??'')); $imgs=$r['images']??[];
      if($title===''||!is_array($imgs)||!$imgs)continue;
      $imgs=array_values(array_filter(array_map('strval',$imgs),fn($u)=>preg_match('~^https?://~i',$u)));
      if(!$imgs)continue;
      $path=$r['category_path']??[]; $cat=is_array($path)?implode(' / ',array_map('strval',$path)):(string)$path;
      $items[]=['title'=>$title,'norm'=>pm_norm($title),'tokens'=>pm_tokens($title),'category'=>$cat,'images'=>array_slice($imgs,0,1),'url'=>(string)($r['url']??'')];
    }
  }
  if(!is_dir(dirname($cache)))@mkdir(dirname($cache),0755,true);
  @file_put_contents($cache,json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  return $items;
}

function pm_candidates(string $name,string $brand,string $model,string $categoryPath,array $idx,int $limit=8): array {
  $nameNorm=pm_norm($name); $brandNorm=pm_norm($brand); $modelNorm=pm_norm($model);
  $targetDistinct=pm_distinctive_tokens($name.' '.$model,$brand); $targetNums=pm_standalone_numbers($name.' '.$model); $scores=[];
  foreach($idx as $i=>$r){
    $title=(string)($r['title']??''); $rn=(string)($r['norm']??''); if($title===''||$rn==='')continue;
    if(!pm_title_relevant($title,$name,$brand,$model,$categoryPath))continue;

    $candDistinct=pm_distinctive_tokens($title,$brand);
    $common=count(array_intersect($targetDistinct,$candDistinct));
    $coverage=$common/max(1,count($targetDistinct));
    $exact=$nameNorm!==''&&$rn===$nameNorm;
    $modelMatch=$modelNorm!==''&&str_contains($rn,$modelNorm);
    $brandMatch=$brandNorm!==''&&str_contains($rn,$brandNorm);
    $numMatches=count(array_intersect($targetNums,pm_standalone_numbers($title)));

    $score=$coverage*180+$common*28+$numMatches*55;
    if($exact)$score+=420;
    if($modelMatch)$score+=150;
    if($brandMatch)$score+=45;
    if($score<80)continue;
    $scores[]=['score'=>$score,'i'=>$i];
  }
  usort($scores,fn($a,$b)=>$b['score']<=>$a['score']); $out=[];$seen=[];
  foreach($scores as $s){
    $r=$idx[$s['i']];
    foreach(($r['images']??[]) as $img){
      if(isset($seen[$img]))continue; $seen[$img]=1;
      $out[]=['image'=>$img,'source_title'=>$r['title']??'','source_url'=>$r['url']??'','score'=>round((float)$s['score'],1),'source'=>'catalog'];
      if(count($out)>=$limit)return $out;
    }
  }
  return $out;
}

function pm_http_get(string $url): string {
  if(function_exists('curl_init')){
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>10,CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126 Safari/537.36',CURLOPT_HTTPHEADER=>['Accept-Language: ru-RU,ru;q=0.9,en;q=0.7','Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8']]);
    $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
    return is_string($body)&&$code>=200&&$code<400?$body:'';
  }
  $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>10,'follow_location'=>1,'header'=>"User-Agent: Mozilla/5.0\r\nAccept-Language: ru-RU,ru;q=0.9,en;q=0.7\r\n"]]);
  $body=@file_get_contents($url,false,$ctx); return is_string($body)?$body:'';
}

function pm_result_relevant(string $title,string $sourceUrl,array $queryTokens,string $brand,string $model,string $name,string $categoryPath): bool {
  $candidate=trim($title)!==''?$title:$sourceUrl;
  return pm_title_relevant($candidate,$name,$brand,$model,$categoryPath);
}

function pm_add_result(array &$out,array &$seen,string $img,string $title,string $src,string $engine,array $queryTokens,string $brand,string $model,string $name,string $categoryPath,int $limit): bool {
  $img=trim($img); if(!preg_match('~^https?://~i',$img)||isset($seen[$img]))return false;
  if(!pm_result_relevant($title,$src,$queryTokens,$brand,$model,$name,$categoryPath))return false;
  $seen[$img]=1;
  $out[]=['image'=>$img,'source_title'=>$title!==''?$title:'Результат из интернета','source_url'=>preg_match('~^https?://~i',$src)?$src:'','score'=>0,'source'=>'internet','engine'=>$engine];
  return count($out)>=$limit;
}

function pm_bing_candidates(string $query,array &$out,array &$seen,array $queryTokens,string $brand,string $model,string $name,string $categoryPath,int $limit): void {
  foreach([
    'https://www.bing.com/images/search?q='.rawurlencode($query).'&form=HDRSC2&first=1',
    'https://www.bing.com/images/async?q='.rawurlencode($query).'&first=0&count=35&relp=35&scenario=ImageBasicHover'
  ] as $url){
    $html=pm_http_get($url); if($html==='')continue;
    preg_match_all('/\bm=(?:"([^"]+)"|\'([^\']+)\')/i',$html,$matches,PREG_SET_ORDER);
    foreach($matches as $m){
      $attr=$m[1]!==''?$m[1]:($m[2]??''); if($attr==='')continue;
      $raw=html_entity_decode($attr,ENT_QUOTES|ENT_HTML5,'UTF-8'); $j=json_decode($raw,true); if(!is_array($j))continue;
      $img=(string)($j['murl']??''); $title=trim((string)($j['t']??$j['desc']??'')); $src=trim((string)($j['purl']??''));
      if(pm_add_result($out,$seen,$img,$title,$src,'bing',$queryTokens,$brand,$model,$name,$categoryPath,$limit))return;
    }
    if(count($out)>=$limit)return;
  }
}

function pm_ddg_candidates(string $query,array &$out,array &$seen,array $queryTokens,string $brand,string $model,string $name,string $categoryPath,int $limit): void {
  $html=pm_http_get('https://duckduckgo.com/?q='.rawurlencode($query)); if($html==='')return;
  $vqd='';
  if(preg_match('/vqd=[\'\"]?([0-9-]+)[\'\"]?/i',$html,$m))$vqd=$m[1];
  if($vqd==='')return;
  $json=pm_http_get('https://duckduckgo.com/i.js?l=ru-ru&o=json&q='.rawurlencode($query).'&vqd='.rawurlencode($vqd).'&f=,,,,,&p=1'); if($json==='')return;
  $data=json_decode($json,true); if(!is_array($data))return;
  foreach(($data['results']??[]) as $r){
    if(!is_array($r))continue;
    $img=(string)($r['image']??''); $title=trim((string)($r['title']??'')); $src=trim((string)($r['url']??$r['source']??''));
    if(pm_add_result($out,$seen,$img,$title,$src,'duckduckgo',$queryTokens,$brand,$model,$name,$categoryPath,$limit))return;
  }
}

function pm_internet_candidates(string $name,string $brand,string $model,string $categoryPath,int $limit=8): array {
  $parts=array_values(array_filter([trim($name),trim($categoryPath),trim($brand),trim($model)],fn($v)=>$v!==''));
  $fullQuery=trim(implode(' ',$parts)); if($fullQuery==='')return [];
  $queries=[];
  foreach([
    $fullQuery,
    trim($categoryPath.' '.$name.' '.$brand.' '.$model),
    trim($name.' '.$brand.' '.$model.' '.$categoryPath),
    trim($name.' '.$categoryPath)
  ] as $q){$n=pm_norm($q);if($n!==''&&!isset($queries[$n]))$queries[$n]=$q;}
  $queries=array_values($queries); $queryTokens=pm_tokens($fullQuery);
  $cacheDir=__DIR__.'/../uploads/photo-internet-cache-v5'; if(!is_dir($cacheDir))@mkdir($cacheDir,0755,true);
  $cache=$cacheDir.'/'.hash('sha256',pm_norm(implode(' | ',$queries))).'.json';
  if(is_file($cache)&&filemtime($cache)>time()-86400*3){$j=json_decode((string)file_get_contents($cache),true);if(is_array($j))return array_slice($j,0,$limit);}
  $out=[];$seen=[];
  foreach($queries as $query){
    pm_bing_candidates($query,$out,$seen,$queryTokens,$brand,$model,$name,$categoryPath,$limit);
    if(count($out)>=$limit)break;
    pm_ddg_candidates($query,$out,$seen,$queryTokens,$brand,$model,$name,$categoryPath,$limit);
    if(count($out)>=$limit)break;
  }
  if($out)@file_put_contents($cache,json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  return $out;
}

try{
  $admin=require_admin();
  if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check(); $in=input_json(); $id=(int)($in['product_id']??0); $url=trim((string)($in['image_url']??''));
    if($id<1)pm_out(['ok'=>false,'error'=>'bad_product'],422);
    if($url!==''&&!preg_match('~^(https?://|/|api/)~i',$url))pm_out(['ok'=>false,'error'=>'bad_image_url'],422);
    $st=$pdo->prepare('SELECT id,name,images,main_image FROM products WHERE id=? LIMIT 1');$st->execute([$id]);$p=$st->fetch();if(!$p)pm_out(['ok'=>false,'error'=>'not_found'],404);
    if($url===''){$imgs=[];$main=null;}else{$old=json_decode((string)($p['images']??''),true);if(!is_array($old))$old=[];$imgs=array_values(array_unique(array_merge([$url],array_map('strval',$old))));$main=$url;}
    $up=$pdo->prepare('UPDATE products SET main_image=?,images=?,updated_at=NOW() WHERE id=?');$up->execute([$main,json_encode($imgs,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id]);audit($pdo,'photo_moderation_select','product',(string)$id,['image_url'=>$url]);pm_out(['ok'=>true,'product_id'=>$id,'image_url'=>$url]);
  }

  if(($_GET['mode']??'')==='internet'){
    $id=(int)($_GET['product_id']??0); if($id<1)pm_out(['ok'=>false,'error'=>'bad_product'],422);
    $st=$pdo->prepare('SELECT id,name,brand,model,category_path FROM products WHERE id=? AND is_active=1 LIMIT 1'); $st->execute([$id]); $p=$st->fetch(); if(!$p)pm_out(['ok'=>false,'error'=>'not_found'],404);
    $c=pm_internet_candidates((string)$p['name'],(string)$p['brand'],(string)$p['model'],(string)$p['category_path'],8);
    $descriptor=trim(implode(' ',array_filter([(string)$p['name'],(string)$p['category_path'],(string)$p['brand'],(string)$p['model']],fn($v)=>trim($v)!=='')));
    pm_out(['ok'=>true,'product_id'=>$id,'query'=>$descriptor,'candidates'=>$c]);
  }

  $page=max(1,(int)($_GET['page']??1));$limit=min(30,max(5,(int)($_GET['limit']??12)));$q=trim((string)($_GET['q']??''));
  $where='is_active=1';$args=[];if($q!==''){$where.=' AND (name LIKE ? OR brand LIKE ? OR model LIKE ?)';$like='%'.$q.'%';$args=[$like,$like,$like];}
  $sql='SELECT id,name,sku,brand,model,main_image,images,category_path,stock_qty FROM products WHERE '.$where.' ORDER BY id DESC';$st=$pdo->prepare($sql);$st->execute($args);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
  $broken=[];foreach($rows as $p){if(pm_product_broken($p))$broken[]=$p;}
  $total=count($broken);$slice=array_slice($broken,($page-1)*$limit,$limit);$idx=pm_build_index();$items=[];
  foreach($slice as $p){$items[]=['id'=>(int)$p['id'],'name'=>$p['name'],'sku'=>$p['sku'],'brand'=>$p['brand'],'model'=>$p['model'],'category_path'=>$p['category_path'],'stock_qty'=>$p['stock_qty']!==null?(int)$p['stock_qty']:null,'current_image'=>$p['main_image'],'candidates'=>pm_candidates((string)$p['name'],(string)$p['brand'],(string)$p['model'],(string)$p['category_path'],$idx,8)];}
  pm_out(['ok'=>true,'page'=>$page,'limit'=>$limit,'total'=>$total,'pages'=>max(1,(int)ceil($total/$limit)),'items'=>$items]);
}catch(Throwable $e){error_log($e->__toString());pm_out(['ok'=>false,'error'=>'server_error'],500);}
