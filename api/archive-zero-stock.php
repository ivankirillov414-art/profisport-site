<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok'=>false,'error'=>'post_required'],405);
    csrf_check();
    $archive=$pdo->prepare("UPDATE products SET is_active=0, availability='out_of_stock', stock_status='out_of_stock', updated_at=NOW() WHERE stock_qty IS NOT NULL AND stock_qty<=0 AND is_active<>0");
    $archive->execute();
    $archived=$archive->rowCount();
    $restore=$pdo->prepare("UPDATE products SET is_active=1, availability='in_stock', stock_status='in_stock', updated_at=NOW() WHERE stock_qty>0 AND is_active=0");
    $restore->execute();
    $restored=$restore->rowCount();
    $active=(int)$pdo->query("SELECT COUNT(*) FROM products WHERE is_active=1 AND (stock_qty IS NULL OR stock_qty>0)")->fetchColumn();
    $zero=(int)$pdo->query("SELECT COUNT(*) FROM products WHERE stock_qty IS NOT NULL AND stock_qty<=0")->fetchColumn();
    audit($pdo,'archive_zero_stock','products',null,['archived'=>$archived,'restored'=>$restored,'active'=>$active,'zero_stock'=>$zero]);
    json_response(['ok'=>true,'archived'=>$archived,'restored'=>$restored,'active'=>$active,'zero_stock'=>$zero]);
} catch(Throwable $e) {
    error_log($e->__toString());
    json_response(['ok'=>false,'error'=>'archive_failed'],500);
}
