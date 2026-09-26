<?php
require __DIR__.'/../server/bootstrap.php';
$pdo->exec("INSERT INTO products(id,title,name,price_rub,price,stock_qty,stock_status,availability,is_active,category_path,main_image,images) VALUES(1,'Test ball','Test ball',150,150,2,'in_stock','in_stock',1,'Sport / Balls','https://example.test/test-ball.jpg','[\"https://example.test/test-ball.jpg\"]'),(2,'Price missing','Price missing',0,0,2,'in_stock','in_stock',1,NULL,NULL,'[]'),(3,'Demo bicycle','Demo bicycle',1200,1200,2,'in_stock','in_stock',1,'Велосипеды / Горные','https://example.test/demo-bicycle.jpg','[\"https://example.test/demo-bicycle.jpg\"]')");
$s=$pdo->prepare('UPDATE admin_users SET password_hash=?,force_password_setup=0');$s->execute([password_hash('test-only-password',PASSWORD_DEFAULT)]);
