<?php
declare(strict_types=1);
$root=realpath(__DIR__.'/../import');
$rel=(string)($_GET['p']??'');
$rel=str_replace('\\','/',$rel);
$rel=ltrim($rel,'/');
if(!$root||$rel===''||str_contains($rel,'..')){http_response_code(404);exit;}
$path=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel));
if(!$path||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)||!is_file($path)){http_response_code(404);exit;}
$ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
$types=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif','avif'=>'image/avif'];
if(!isset($types[$ext])){http_response_code(415);exit;}
$etag='"'.sha1($path.'|'.filemtime($path).'|'.filesize($path)).'"';
header('Content-Type: '.$types[$ext]);
header('Cache-Control: public, max-age=604800, immutable');
header('ETag: '.$etag);
if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){http_response_code(304);exit;}
header('Content-Length: '.filesize($path));
readfile($path);
