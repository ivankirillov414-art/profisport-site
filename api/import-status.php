<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function import_status_out(array $data,int $code=200): never {
    http_response_code($code);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function import_status_auth(array $config): void {
    $got=(string)($_SERVER['HTTP_X_IMPORT_TOKEN']??'');
    $expected=(string)($config['import_token']??'');
    if($expected===''||$got===''||!hash_equals($expected,$got))import_status_out(['ok'=>false,'error'=>'unauthorized'],401);
}
function import_status_files(string $root): array {
    $products=[];$categories=[];
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach($it as $f){
        if(!$f->isFile()||strtolower($f->getExtension())!=='csv')continue;
        $name=mb_strtolower($f->getFilename());
        $row=['path'=>$f->getPathname(),'file'=>$f->getFilename(),'size'=>$f->getSize(),'mtime'=>$f->getMTime()];
        if(str_contains($name,'tovary'))$products[]=$row;
        elseif(str_contains($name,'categor'))$categories[]=$row;
    }
    $pick=function(array $rows):?array{
        if(!$rows)return null;
        usort($rows,fn($a,$b)=>($b['mtime']<=>$a['mtime'])?:($b['size']<=>$a['size']));
        return $rows[0];
    };
    return [$pick($products),$pick($categories)];
}
function import_status_setting(PDO $pdo,string $key): string {
    $s=$pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key=? LIMIT 1');
    $s->execute([$key]);
    return trim((string)($s->fetchColumn()?:''));
}

try{
    import_status_auth($config);
    $root=realpath(__DIR__.'/../import');
    if(!$root)import_status_out(['ok'=>true,'ready'=>false,'needs_update'=>false,'reason'=>'import_directory_missing']);
    [$product,$category]=import_status_files($root);
    if(!$product||!$category)import_status_out(['ok'=>true,'ready'=>false,'needs_update'=>false,'reason'=>'1c_csv_missing']);
    $snapshot=hash_file('sha256',$product['path']);
    if($snapshot===false)throw new RuntimeException('snapshot_failed');
    $current=import_status_setting($pdo,'current_1c_snapshot');
    import_status_out([
        'ok'=>true,
        'ready'=>true,
        'needs_update'=>$current===''||!hash_equals($current,$snapshot),
        'snapshot'=>$snapshot,
        'current_snapshot'=>$current?:null,
        'source'=>$product['file'],
        'source_size'=>(int)$product['size'],
        'source_modified'=>date(DATE_ATOM,(int)$product['mtime']),
        'categories'=>$category['file']
    ]);
}catch(Throwable $e){
    error_log($e->__toString());
    import_status_out(['ok'=>false,'error'=>'status_failed'],500);
}
