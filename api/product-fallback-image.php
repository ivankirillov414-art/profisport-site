<?php
declare(strict_types=1);

function norm_key(string $s): string {
    $s=mb_strtolower(trim($s),'UTF-8');
    $s=str_replace('ё','е',$s);
    $s=preg_replace('/[^a-zа-я0-9]+/u',' ',$s)??'';
    return trim(preg_replace('/\s+/u',' ',$s)??'');
}

function load_photo_overrides(): array {
    $file=__DIR__.'/../data/photo-overrides.json';
    if(!is_file($file))return [];
    $j=json_decode((string)@file_get_contents($file),true);
    if(!is_array($j))return [];
    $items=is_array($j['items']??null)?$j['items']:[];
    $out=[];
    foreach($items as $key=>$row){
        if(is_string($row))$url=trim($row);
        elseif(is_array($row))$url=trim((string)($row['image']??''));
        else continue;
        if($url!==''&&preg_match('~^https?://~i',$url))$out[norm_key((string)$key)]=$url;
    }
    return $out;
}

function load_fallback_index(): array {
    $dataRoot=realpath(__DIR__.'/../data');
    if(!$dataRoot)return ['by_key'=>[],'by_name'=>[]];
    $manifestPath=$dataRoot.DIRECTORY_SEPARATOR.'manifest.json';
    if(!is_file($manifestPath))return ['by_key'=>[],'by_name'=>[]];

    $cacheDir=__DIR__.'/../uploads';
    if(!is_dir($cacheDir))@mkdir($cacheDir,0755,true);
    $cachePath=$cacheDir.'/fallback-image-index-v2.json';
    $manifestMtime=(int)@filemtime($manifestPath);

    if(is_file($cachePath)&&(int)@filemtime($cachePath)>=$manifestMtime){
        $cached=json_decode((string)@file_get_contents($cachePath),true);
        if(is_array($cached)&&isset($cached['by_key'],$cached['by_name']))return $cached;
    }

    $manifest=json_decode((string)file_get_contents($manifestPath),true);
    $parts=is_array($manifest['parts']??null)?$manifest['parts']:[];
    $rows=[];
    $freq=[];

    foreach($parts as $part){
        $file=$dataRoot.DIRECTORY_SEPARATOR.basename((string)$part);
        if(!is_file($file))continue;
        $chunk=json_decode((string)file_get_contents($file),true);
        if(!is_array($chunk))continue;
        foreach($chunk as $row){
            if(!is_array($row))continue;
            $title=trim((string)($row['title']??$row['name']??''));
            $images=is_array($row['images']??null)?$row['images']:[];
            if($title===''||!$images)continue;
            $path=is_array($row['category_path']??null)?$row['category_path']:[];
            $cat=$path?(string)end($path):'';
            $valid=[];
            foreach($images as $u){
                $u=trim((string)$u);
                if(!preg_match('~^https?://~i',$u))continue;
                $valid[]=$u;
                $freq[$u]=($freq[$u]??0)+1;
            }
            if($valid)$rows[]=['name'=>norm_key($title),'cat'=>norm_key($cat),'images'=>array_values(array_unique($valid))];
        }
    }

    $byKey=[];
    $nameBuckets=[];
    foreach($rows as $row){
        $safe=[];
        foreach($row['images'] as $u){
            if(($freq[$u]??0)<=3)$safe[]=$u;
            if(count($safe)>=3)break;
        }
        if(!$safe)continue;
        $key=$row['name'].'|'.$row['cat'];
        if(!isset($byKey[$key]))$byKey[$key]=$safe;
        elseif($byKey[$key]!==$safe)$byKey[$key]=null;
        $nameBuckets[$row['name']][]=$safe;
    }

    $byKey=array_filter($byKey,fn($v)=>is_array($v)&&$v);
    $byName=[];
    foreach($nameBuckets as $name=>$sets){
        $uniq=[];
        foreach($sets as $set)$uniq[json_encode($set,JSON_UNESCAPED_SLASHES)]=1;
        if(count($uniq)!==1)continue;
        $one=array_key_first($uniq);
        $decoded=json_decode((string)$one,true);
        if(is_array($decoded)&&$decoded)$byName[$name]=$decoded;
    }

    $index=['generated_at'=>date('c'),'by_key'=>$byKey,'by_name'=>$byName];
    if(is_dir($cacheDir)){
        $tmp=$cachePath.'.tmp.'.getmypid();
        if(@file_put_contents($tmp,json_encode($index,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))!==false)@rename($tmp,$cachePath);
        else @unlink($tmp);
    }
    return $index;
}

$name=norm_key((string)($_GET['name']??''));
$cat=norm_key((string)($_GET['cat']??''));
if($name===''){http_response_code(404);exit;}

$overrides=load_photo_overrides();
if(!empty($overrides[$name])){
    header('Cache-Control: public, max-age=3600, stale-while-revalidate=86400');
    header('Location: '.$overrides[$name],true,302);
    exit;
}

$index=load_fallback_index();
$urls=[];
if($cat!==''&&!empty($index['by_key'][$name.'|'.$cat]))$urls=$index['by_key'][$name.'|'.$cat];
elseif(!empty($index['by_name'][$name]))$urls=$index['by_name'][$name];

$url=(string)($urls[0]??'');
if($url===''||!preg_match('~^https?://~i',$url)){http_response_code(404);exit;}

header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');
header('Location: '.$url,true,302);
exit;
