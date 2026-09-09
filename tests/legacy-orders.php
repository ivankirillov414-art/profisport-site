<?php
declare(strict_types=1);
define('PROFISPORT_SKIP_SCHEMA',true);
require __DIR__.'/../server/bootstrap.php';
if(PHP_SAPI!=='cli'||$config['db_name']!=='profisport_test')throw new RuntimeException('Disposable test database required');
$pdo->exec("CREATE TABLE orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,customer_id BIGINT UNSIGNED NULL,order_number VARCHAR(50) NOT NULL UNIQUE,customer_name VARCHAR(255) NOT NULL,phone VARCHAR(50) NOT NULL,email VARCHAR(255) NULL,delivery_type ENUM('pickup','delivery') NOT NULL DEFAULT 'pickup',address TEXT NULL,comment TEXT NULL,status ENUM('new','confirmed','processing','ready','completed','cancelled') NOT NULL DEFAULT 'new',total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE order_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,product_id BIGINT UNSIGNED NULL,product_name VARCHAR(500) NOT NULL,quantity INT NOT NULL DEFAULT 1,price DECIMAL(12,2) NOT NULL DEFAULT 0,total DECIMAL(12,2) NOT NULL DEFAULT 0) ENGINE=InnoDB");
$pdo->exec("INSERT INTO orders(id,order_number,customer_name,phone,delivery_type,status,total_amount,created_at,updated_at) VALUES(9000,'LEGACY-1','Test legacy','+79990000000','delivery','processing',151.50,'2020-01-01','2020-02-01')");
$pdo->exec("INSERT INTO order_items(order_id,product_name,quantity,price,total) VALUES(9000,'Legacy ball',2,75.75,151.50)");
ensure_schema($pdo);ensure_schema($pdo);
$o=$pdo->query('SELECT * FROM orders WHERE id=9000')->fetch();$i=$pdo->query('SELECT * FROM order_items WHERE order_id=9000')->fetch();
if($o['delivery_method']!=='orenburg_delivery'||$o['total_rub']!=='151.50'||$o['total_amount']!=='151.50'||$o['status']!=='processing'||$o['updated_at']!=='2020-02-01 00:00:00')throw new RuntimeException('Legacy order was not preserved');
if($i['title']!=='Legacy ball'||$i['price_rub']!=='75.75'||$i['line_total_rub']!=='151.50'||$i['product_name']!=='Legacy ball')throw new RuntimeException('Legacy lines were not preserved');
// Subsequent HTTP tests start with no orders but keep all legacy constraints.
$pdo->exec('DELETE FROM order_items WHERE order_id=9000');$pdo->exec('DELETE FROM orders WHERE id=9000');
echo "PASS: legacy migration, original values, decimals, status, timestamps and repeatability\n";
