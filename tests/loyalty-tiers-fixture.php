<?php
require __DIR__.'/../server/bootstrap.php';
$pdo->prepare("INSERT INTO customers(name,email,phone,password_hash,birth_date) VALUES('Tier HTTP','tier-http@example.test','+79990006666',?,'1990-01-01')")->execute([password_hash('test-only-tier-password',PASSWORD_DEFAULT)]);
$customerId=(int)$pdo->lastInsertId();
foreach([[88001,50000,null],[88002,30000,null],[88003,50000,null],[88004,60000,80000]] as [$id,$price,$old]){
    $pdo->prepare("INSERT INTO products(id,title,name,price_rub,price,old_price_rub,old_price,stock_qty,stock_status,availability,is_active,category_path) VALUES(?,'Tier product','Tier product',?,?,?,?,10,'in_stock','in_stock',1,'Tier tests')")->execute([$id,$price,$price,$old,$old]);
}
echo json_encode(['customer_id'=>$customerId]);
