<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pm_out(array $x,int $code=200): never { http_response_code($code); echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function pm_norm(string $s): string { $s=mb_strtolower(trim($s)); $s=str_replace(['ё'],'е',$s); $s=preg_replace('/[^a-zа-я0-9]+/u',' ',$s)??''; return trim(preg_replace('/\s+/u',' ',$s)??''); }
function pm_tokens(string $s): array { $stop=['для','или','при','под','над','без','комплект','набор','шт','мм','см','черный','черная','черное','белый','белая','серый','синий','красный']; $out=[]; foreach(preg_split('/\s+/u',pm_norm($s))?:[] as $t){ if(mb_strlen($t)<3||in_array($t,$stop,true))continue; $out[$t]=true; } return array_keys($out); }
function pm_local_image_ok(?string $url): bool { if(!$url)return false; $url=trim($url); if($url==='')return false; if(preg_match('~^https?://~i',$url))return true; $path=parse_url($url,PHP_URL_PATH)?:$url; $path=rawurldecode($path); if(str_starts_with($path,'/import/')){ $root=realpath(__DIR__.'/../import'); if(!$root)return false; $rel=ltrim(substr($path,8),'/'); if($rel===''||str_contains($rel,'..'))return false; $full=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel)); return $full!==false&&str_starts_with($full,$root.DIRECTORY_SEPARATOR)&&is_file($full); } if(str_starts_with($path,'import/')){ $root=realpath(__DIR__.'/../import'); if(!$root)return false; $rel=substr($path,7); $full=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel)); return $full!==false&&is_file($full); } $doc=realpath(__DIR__.'/..'); if(!$doc)return false; $full=realpath($doc.DIRECTORY_SEPARATOR.ltrim($path,'/')); return $full!==false&&is_file($full); }
function pm_product_broken(array $p): bool { $imgs=json_decode((string)($p['images']??''),true); if(!is_array($imgs))$imgs=[]; $urls=array_values(array_unique(array_filter(array_merge([(string)($p['main_image']??'')],array_map('strval',$imgs))))); if(!$urls)return true; foreach($urls as $u)if(pm_local_image_ok($u))return false; return true; }
function pm_build_index(): array {
  $cache=__DIR__.'/../uploads/photo-candidate-index-v1.json';
  if(is_file($cache)&&filemtime($cache)>time()-86400*7){$j=json_decode((string)file_get_contents($cache),true);if(is_array($j))return $j;}
  $dataDir=__DIR__.'/../data'; $items=[];
  foreach(glob($dataDir.'/catalog-*.json')?:[] as $file){$rows=json_decode((string)file_get_contents($file),true);if(!is_array($rows))continue;foreach($rows as $r){if(!is_array($r))continue;$title=trim((string)($r['title']??$r['name']??''));$imgs=$r['images']??[];if($title===''||!is_array($imgs)||!$imgs)continue;$imgs=array_values(array_filter(array_map('strval',$imgs),fn($u)=>preg_match('~^https?://~i',$u)));if(!$imgs)continue;$items[]=['title'=>$title,'norm'=>pm_norm($title),'tokens'=>pm_tokens($title),'images'=>array_slice($imgs,0,8),'url'=>(string)($r['url']??'')];}}
  if(!is_dir(dirname($cache)))@mkdir(dirname($cache),0755,true); @file_put_contents($cache,json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); return $items;
}
function pm_candidates(string $name,string $sku,string $brand,string $model,array $idx,int $limit=8): array {
  $needle=pm_norm(trim($name.' '.$brand.' '.$model.' '.$sku)); $nt=pm_tokens($needle); $scores=[];
  foreach($idx as $i=>$r){$rn=(string)($r['norm']??'');$rt=$r['tokens']??[]; if(!$rn||!is_array($rt))continue; $inter=count(array_intersect($nt,$rt)); if($inter===0)continue; $union=max(1,count(array_unique(array_merge($nt,$rt)))); $score=($inter/$union)*100; if(pm_norm($name)===$rn)$score+=200; elseif(str_contains($rn,pm_norm($name))||str_contains(pm_norm($name),$rn))$score+=70; foreach(pm_tokens($brand.' '.$model.' '.$sku) as $t)if(in_array($t,$rt,true))$score+=25; if($score<18)continue; $scores[]=['score'=>$score,'i'=>$i]; }
  usort($scores,fn($a,$b)=>$b['score']<=>$a['score']); $out=[];$seen=[];
  foreach($scores as $s){$r=$idx[$s['i']];foreach(($r['images']??[]) as $img){if(isset($seen[$img]))continue;$seen[$img]=1;$out[]=['image'=>$img,'source_title'=>$r['title']??'','source_url'=>$r['url']??'','score'=>round((float)$s['score'],1)];if(count($out)>=$limit)return $out;}}
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
  $page=max(1,(int)($_GET['page']??1));$limit=min(30,max(5,(int)($_GET['limit']??12)));$q=trim((string)($_GET['q']??''));
  $where='is_active=1';$args=[];if($q!==''){$where.=' AND (name LIKE ? OR sku LIKE ? OR brand LIKE ? OR model LIKE ?)';$like='%'.$q.'%';$args=[$like,$like,$like,$like];}
  $sql='SELECT id,name,sku,brand,model,main_image,images,category_path,stock_qty FROM products WHERE '.$where.' ORDER BY id DESC';$st=$pdo->prepare($sql);$st->execute($args);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
  $broken=[];foreach($rows as $p){if(pm_product_broken($p))$broken[]=$p;}
  $total=count($broken);$slice=array_slice($broken,($page-1)*$limit,$limit);$idx=pm_build_index();$items=[];
  foreach($slice as $p){$items[]=['id'=>(int)$p['id'],'name'=>$p['name'],'sku'=>$p['sku'],'brand'=>$p['brand'],'model'=>$p['model'],'category_path'=>$p['category_path'],'stock_qty'=>$p['stock_qty']!==null?(int)$p['stock_qty']:null,'current_image'=>$p['main_image'],'candidates'=>pm_candidates((string)$p['name'],(string)$p['sku'],(string)$p['brand'],(string)$p['model'],$idx,8)];}
  pm_out(['ok'=>true,'page'=>$page,'limit'=>$limit,'total'=>$total,'pages'=>max(1,(int)ceil($total/$limit)),'items'=>$items]);
}catch(Throwable $e){error_log($e->__toString());pm_out(['ok'=>false,'error'=>'server_error'],500);}
