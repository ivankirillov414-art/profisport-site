<?php
require __DIR__.'/../server/bootstrap.php';
if(PHP_SAPI!=='cli'||$config['db_name']!=='profisport_test')throw new RuntimeException('Disposable test database required');
if((int)$pdo->query("SELECT COUNT(*) FROM orders WHERE total_amount<>total_rub OR delivery_type<>CASE WHEN delivery_method='orenburg_delivery' THEN 'delivery' ELSE 'pickup' END")->fetchColumn()!==0)throw new RuntimeException('Order aliases diverged');
if((int)$pdo->query('SELECT COUNT(*) FROM order_items WHERE product_name<>title OR price<>price_rub OR total<>line_total_rub')->fetchColumn()!==0)throw new RuntimeException('Line aliases diverged');
if((int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()<2)throw new RuntimeException('Checkout did not persist orders');
echo "PASS: checkout writes canonical and legacy fields consistently\n";
