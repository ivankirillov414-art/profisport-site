<?php
declare(strict_types=1);

$configFile=__DIR__.'/../server/config.php';
if(!is_file($configFile)){http_response_code(500);exit;}
$config=require $configFile;

function pdi_norm(string $s): string {
    $s=mb_strtolower(trim($s),'UTF-8');
    $s=str_replace('ё','е',$s);
    $s=preg_replace('/[^a-zа-я0-9]+/u',' ',$s)??'';
    return trim(preg_replace('/\s+/u',' ',$s)??'');
}

function pdi_category_tail(string $path): string {
    $parts=array_values(array_filter(array_map('trim',explode('/',$path))));
    return $parts?(string)end($parts):'';
}

function pdi_local_rel(string $url): ?string {
    $path=(string)(parse_url($url,PHP_URL_PATH)??$url);
    $path=rawurldecode($path);
    if(str_starts_with($path,'/import/'))$rel=substr($path,8);
    elseif(str_starts_with($path,'import/'))$rel=substr($path,7);
    else return null;
    $rel=ltrim(str_replace('\\','/',$rel),'/');
    if($rel===''||str_contains($rel,'..'))return '';
    $root=realpath(__DIR__.'/../import');
    if(!$root)return '';
    $full=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel));
    if(!$full||!str_starts_with($full,$root.DIRECTORY_SEPARATOR)||!is_file($full))return '';
    return $rel;
}

function pdi_first_image(array $row): ?array {
    $decoded=json_decode((string)($row['images']??''),true);
    if(!is_array($decoded))$decoded=[];
    $urls=array_values(array_unique(array_filter(array_merge([(string)($row['main_image']??'')],array_map('strval',$decoded)),fn($v)=>trim((string)$v)!=='')));
    foreach($urls as $url){
        $url=trim((string)$url);
        $rel=pdi_local_rel($url);
        if($rel!==null){if($rel!=='')return ['kind'=>'local','value'=>$rel];continue;}
        if(preg_match('~^https?://~i',$url))return ['kind'=>'remote','value'=>$url];
    }
    return null;
}

$name=trim((string)($_GET['name']??''));
$cat=trim((string)($_GET['cat']??''));
$brand=trim((string)($_GET['brand']??''));
$model=trim((string)($_GET['model']??''));
if($name===''||mb_strlen($name,'UTF-8')>300){http_response_code(404);exit;}

try{
    $pdo=new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',$config['db_host'],$config['db_name']),
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $st=$pdo->prepare("SELECT name,brand,model,category_path,main_image,images FROM products WHERE is_active=1 AND name=:name LIMIT 20");
    $st->execute([':name'=>$name]);
    $rows=$st->fetchAll();
    if(!$rows){http_response_code(404);exit;}

    $wantCat=pdi_norm($cat);$wantBrand=pdi_norm($brand);$wantModel=pdi_norm($model);
    $ranked=[];
    foreach($rows as $row){
        $img=pdi_first_image($row);if(!$img)continue;
        $score=100;
        $rowCat=pdi_norm(pdi_category_tail((string)($row['category_path']??'')));
        $rowBrand=pdi_norm((string)($row['brand']??''));
        $rowModel=pdi_norm((string)($row['model']??''));
        if($wantCat!==''&&$rowCat===$wantCat)$score+=40;
        if($wantBrand!==''&&$rowBrand!==''&&$rowBrand===$wantBrand)$score+=20;
        if($wantModel!==''&&$rowModel!==''&&$rowModel===$wantModel)$score+=30;
        $ranked[]=['score'=>$score,'img'=>$img];
    }
    if(!$ranked){http_response_code(404);exit;}
    usort($ranked,fn($a,$b)=>$b['score']<=>$a['score']);
    $chosen=$ranked[0]['img'];

    header('Cache-Control: public, max-age=1800, stale-while-revalidate=86400');
    if($chosen['kind']==='local'){
        header('Location: product-image.php?p='.rawurlencode((string)$chosen['value']),true,302);
        exit;
    }
    header('Location: '.(string)$chosen['value'],true,302);
    exit;
}catch(Throwable $e){
    error_log($e->__toString());
    http_response_code(404);
    exit;
}
