<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
try {
    require_admin();
    $dir=realpath(__DIR__.'/../import');
    if(!$dir){echo json_encode(['files'=>[],'summary'=>[]],JSON_UNESCAPED_UNICODE);exit;}
    $allowed=['csv','xlsx','xls','sql','jpg','jpeg','png','webp','gif','avif'];
    $files=[];$summary=[];
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));
    foreach($it as $info){
        if(!$info->isFile())continue;
        $ext=strtolower($info->getExtension());
        if(!in_array($ext,$allowed,true))continue;
        $relative=str_replace(DIRECTORY_SEPARATOR,'/',substr($info->getPathname(),strlen($dir)+1));
        $kind=in_array($ext,['jpg','jpeg','png','webp','gif','avif'],true)?'image':'data';
        $files[]=['name'=>$info->getFilename(),'path'=>$relative,'type'=>$ext,'kind'=>$kind,'size'=>$info->getSize(),'modified'=>date(DATE_ATOM,$info->getMTime())];
        $summary[$kind]=($summary[$kind]??0)+1;
    }
    usort($files,fn($a,$b)=>strcmp($b['modified'],$a['modified']));
    echo json_encode(['files'=>$files,'summary'=>$summary],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch(Throwable $e){
    error_log($e->__toString());
    http_response_code(500);
    echo json_encode(['error'=>'server_error'],JSON_UNESCAPED_UNICODE);
}
