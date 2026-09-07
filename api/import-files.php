<?php
require __DIR__.'/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['admin_id'])) { http_response_code(401); echo json_encode(['error'=>'auth']); exit; }
$dir=realpath(__DIR__.'/../import');
if(!$dir){echo json_encode(['files'=>[]]);exit;}
$allowed=['csv','xlsx','xls','sql']; $files=[];
foreach(scandir($dir) as $name){
 if($name[0]==='.')continue; $path=$dir.DIRECTORY_SEPARATOR.$name; if(!is_file($path))continue;
 $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION)); if(!in_array($ext,$allowed,true))continue;
 $files[]=['name'=>$name,'type'=>$ext,'size'=>filesize($path),'modified'=>date(DATE_ATOM,filemtime($path))];
}
usort($files,fn($a,$b)=>strcmp($b['modified'],$a['modified']));
echo json_encode(['files'=>$files],JSON_UNESCAPED_UNICODE);
