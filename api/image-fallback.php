<?php
declare(strict_types=1);

$configFile=__DIR__.'/../server/config.php';
if(!is_file($configFile)){http_response_code(404);exit;}
$config=require $configFile;

try{
    $id=(int)($_GET['id']??0);
    if($id<1){http_response_code(404);exit;}

    $pdo=new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',$config['db_host'],$config['db_name']),
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
    $s=$pdo->prepare('SELECT name,category_path FROM products WHERE id=? LIMIT 1');
    $s->execute([$id]);
    $row=$s->fetch();
    if(!$row||trim((string)$row['name'])===''){http_response_code(404);exit;}

    $cat='';
    $parts=array_values(array_filter(array_map('trim',explode('/',(string)($row['category_path']??'')))));
    if($parts)$cat=(string)end($parts);

    $target='product-fallback-image.php?name='.rawurlencode((string)$row['name']).'&cat='.rawurlencode($cat);
    header('Cache-Control: public, max-age=3600');
    header('Location: '.$target,true,302);
    exit;
}catch(Throwable $e){
    error_log($e->__toString());
    http_response_code(404);
    exit;
}
