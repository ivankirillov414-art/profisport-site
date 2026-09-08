<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pm_out(array $x,int $code=200): never { http_response_code($code); echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function pm_norm(string $s): string { $s=mb_strtolower(trim($s)); $s=str_replace(['ё'],'е',$s); $s=preg_replace('/[^a-zа-я0-9]+/u',' ',$s)??''; return trim(preg_replace('/\s+/u',' ',$s)??''); }
function pm_tokens(string $s): array { $stop=['для','или','при','под','над','без','комплект','набор','шт','мм','см','черный','черная','черное','белый','белая','белое','серый','серая','серое','синий','синяя','синее','красный','красная','красное','зеленый','зеленая','желтый','желтая','купить','цена','фото','товар']; $out=[]; foreach(preg_split('/\s+/u',pm_norm($s))?:[] as $t){ if(mb_strlen($t)<3||in_array($t,$stop,true))continue; $out[$t]=true; } return array_keys($out); }
function pm_local_image_ok(?string $url): bool { if(!$url)return false; $url=trim($url); if($url==='')return false; if(preg_match('~^https?://~i',$url))return true; $path=parse_url($url,PHP_URL_PATH)?:$url; $path=rawurldecode($path); if(str_starts_with($path,'/import/')){ $root=realpath(__DIR__.'/../import'); if(!$root)return false; $rel=ltrim(substr($path,8),'/'); if($rel===''||str_contains($rel,'..'))return false; $full=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel)); return $full!==false&&str_starts_with($full,$root.DIRECTORY_SEPARATOR)&&is_file($full); } if(str_starts_with($path,'import/')){ $root=realpath(__DIR__.'/../import'); if(!$root)return false; $rel=substr($path,7); $full=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel)); return $full!==false&&is_file($full); } $doc=realpath(__DIR__.'/..'); if(!$doc)return false; $full=realpath($doc.DIRECTORY_SEPARATOR.ltrim($path,'/')); return $full!==false&&is_file($full); }
function pm_product_broken(array $p): bool { $imgs=json_decode((string)($p['images']??''),true); if(!is_array($imgs))$imgs=[]; $urls=array_values(array_unique(array_filter(array_merge([(string)($p['main_image']??'')],array_map('strval',$imgs))))); if(!$urls)return true; foreach($urls as $u)if(pm_local_image_ok($u))return false; return true; }

function pm_build_index(): array {
  $cache=__DIR__.'/../uploads/photo-candidate-index-v2.json';
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
      $items[]=['title'=>$title,'norm'=>pm_norm($title),'tokens'=>pm_tokens($title),'images'=>array_slice($imgs,0,1),'url'=>(string)($r['url']??'')];
    }
  }
  if(!is_dir(dirname($cache)))@mkdir(dirname($cache),0755,true);
  @file_put_contents($cache,json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  return $items;
}

function pm_candidates(string $name,string $brand,string $model,array $idx,int $limit=8): array {
  $nameNorm=pm_norm($name); $brandNorm=pm_norm($brand); $modelNorm=pm_norm($model);
  $needle=pm_norm(trim($name.' '.$brand.' '.$model)); $nt=pm_tokens($needle); $scores=[];
  foreach($idx as $i=>$r){
    $rn=(string)($r['norm']??''); $rt=$r['tokens']??[]; if(!$rn||!is_array($rt))continue;
    $inter=count(array_intersect($nt,$rt)); if($inter===0)continue;
    $coverage=$inter/max(1,count($nt)); $precision=$inter/max(1,count($rt));
    $exact=$nameNorm!==''&&$rn===$nameNorm;
    $contained=$nameNorm!==''&&(str_contains($rn,$nameNorm)||str_contains($nameNorm,$rn));
    if(!$exact&&!$contained&&$inter<2&&$coverage<0.55)continue;
    $score=$coverage*120+$precision*80;
    if($exact)$score+=260; elseif($contained)$score+=110;
    if($brandNorm!==''&&str_contains($rn,$brandNorm))$score+=40;
    if($modelNorm!==''&&str_contains($rn,$modelNorm))$score+=90;
    if($score<60)continue;
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
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>9,CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126 Safari/537.36',CURLOPT_HTTPHEADER=>['Accept-Language: ru-RU,ru;q=0.9,en;q=0.7']]);
    $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
    return is_string($body)&&$code>=200&&$code<400?$body:'';
  }
  $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>9,'follow_location'=>1,'header'=>"User-Agent: Mozilla/5.0\r\nAccept-Language: ru-RU,ru;q=0.9,en;q=0.7\r\n"]]);
  $body=@file_get_contents($url,false,$ctx); return is_string($body)?$body:'';
}

function pm_result_relevant(string $title,string $sourceUrl,array $queryTokens,string $brand,string $model): bool {
  $hay=pm_norm($title.' '.$sourceUrl);
  if($hay==='')return false;
  $brand=pm_norm($brand); $model=pm_norm($model);
  if($brand!==''&&mb_strlen($brand)>=3&&str_contains($hay,$brand))return true;
  if($model!==''&&mb_strlen($model)>=3&&str_contains($hay,$model))return true;
  $hits=0; foreach($queryTokens as $t){ if(str_contains($hay,$t))$hits++; }
  if(count($queryTokens)<=2)return $hits>=1;
  return $hits>=2;
}

function pm_internet_candidates(string $name,string $brand,string $model,int $limit=8): array {
  $query=trim(implode(' ',array_filter([$brand,$name,$model],fn($v)=>trim((string)$v)!=='')));
  if($query==='')return [];
  $queryTokens=pm_tokens($query);
  $cacheDir=__DIR__.'/../uploads/photo-internet-cache-v2'; if(!is_dir($cacheDir))@mkdir($cacheDir,0755,true);
  $cache=$cacheDir.'/'.hash('sha256',pm_norm($query)).'.json';
  if(is_file($cache)&&filemtime($cache)>time()-86400*3){$j=json_decode((string)file_get_contents($cache),true);if(is_array($j))return array_slice($j,0,$limit);}
  $url='https://www.bing.com/images/search?q='.rawurlencode($query).'&form=HDRSC2&first=1';
  $html=pm_http_get($url); if($html==='')return [];

  // Берём только реальные карточки результатов Bing Images, а не любые случайные m="..."
  // атрибуты со страницы. Именно старый широкий парсер давал посторонние картинки.
  preg_match_all('/<a\b[^>]*class="[^"]*\biusc\b[^"]*"[^>]*\bm="([^"]+)"[^>]*>/i',$html,$matches);
  if(empty($matches[1])){
    preg_match_all('/<a\b[^>]*\bm="([^"]+)"[^>]*class="[^"]*\biusc\b[^"]*"[^>]*>/i',$html,$matches);
  }

  $out=[];$seen=[];
  foreach(($matches[1]??[]) as $attr){
    $raw=html_entity_decode((string)$attr,ENT_QUOTES|ENT_HTML5,'UTF-8'); $j=json_decode($raw,true); if(!is_array($j))continue;
    $img=trim((string)($j['murl']??'')); if(!preg_match('~^https?://~i',$img)||isset($seen[$img]))continue;
    $title=trim((string)($j['t']??$j['desc']??'')); $src=trim((string)($j['purl']??''));
    if(!pm_result_relevant($title,$src,$queryTokens,$brand,$model))continue;
    $seen[$img]=1;
    $out[]=['image'=>$img,'source_title'=>$title!==''?$title:'Результат из интернета','source_url'=>preg_match('~^https?://~i',$src)?$src:'','score'=>0,'source'=>'internet'];
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
    $st=$pdo->prepare('SELECT id,name,brand,model FROM products WHERE id=? AND is_active=1 LIMIT 1'); $st->execute([$id]); $p=$st->fetch(); if(!$p)pm_out(['ok'=>false,'error'=>'not_found'],404);
    $c=pm_internet_candidates((string)$p['name'],(string)$p['brand'],(string)$p['model'],8);
    pm_out(['ok'=>true,'product_id'=>$id,'query'=>trim((string)$p['brand'].' '.(string)$p['name'].' '.(string)$p['model']),'candidates'=>$c]);
  }

  $page=max(1,(int)($_GET['page']??1));$limit=min(30,max(5,(int)($_GET['limit']??12)));$q=trim((string)($_GET['q']??''));
  $where='is_active=1';$args=[];if($q!==''){$where.=' AND (name LIKE ? OR brand LIKE ? OR model LIKE ?)';$like='%'.$q.'%';$args=[$like,$like,$like];}
  $sql='SELECT id,name,sku,brand,model,main_image,images,category_path,stock_qty FROM products WHERE '.$where.' ORDER BY id DESC';$st=$pdo->prepare($sql);$st->execute($args);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
  $broken=[];foreach($rows as $p){if(pm_product_broken($p))$broken[]=$p;}
  $total=count($broken);$slice=array_slice($broken,($page-1)*$limit,$limit);$idx=pm_build_index();$items=[];
  foreach($slice as $p){$items[]=['id'=>(int)$p['id'],'name'=>$p['name'],'sku'=>$p['sku'],'brand'=>$p['brand'],'model'=>$p['model'],'category_path'=>$p['category_path'],'stock_qty'=>$p['stock_qty']!==null?(int)$p['stock_qty']:null,'current_image'=>$p['main_image'],'candidates'=>pm_candidates((string)$p['name'],(string)$p['brand'],(string)$p['model'],$idx,8)];}
  pm_out(['ok'=>true,'page'=>$page,'limit'=>$limit,'total'=>$total,'pages'=>max(1,(int)ceil($total/$limit)),'items'=>$items]);
}catch(Throwable $e){error_log($e->__toString());pm_out(['ok'=>false,'error'=>'server_error'],500);}
