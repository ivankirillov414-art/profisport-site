<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) { http_response_code(500); exit('Server configuration is missing'); }
$config = require $configFile;

function db(): PDO { static $pdo=null; global $config; if($pdo instanceof PDO)return $pdo; $dsn=sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',$config['db_host'],$config['db_name']); $pdo=new PDO($dsn,$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]); return $pdo; }
function table_columns(PDO $pdo,string $table): array { $out=[];foreach($pdo->query("SHOW COLUMNS FROM `$table`") as $r)$out[(string)$r['Field']]=true;return $out; }
function ensure_product_columns(PDO $pdo): void {
  $cols=table_columns($pdo,'products');
  $defs=[
    'source_hash'=>'CHAR(64) NULL',
    'external_url'=>'VARCHAR(1000) NULL',
    'price_rub'=>'INT NOT NULL DEFAULT 0',
    'old_price_rub'=>'INT NULL',
    'stock_qty'=>'INT NULL',
    'stock_status'=>"VARCHAR(40) NOT NULL DEFAULT 'unknown'",
    'availability'=>"VARCHAR(40) NOT NULL DEFAULT 'unknown'",
    'category_path'=>'TEXT NULL',
    'specs'=>'LONGTEXT NULL',
    'description'=>'LONGTEXT NULL',
    'images'=>'LONGTEXT NULL',
    'is_active'=>'TINYINT(1) NOT NULL DEFAULT 1',
    'created_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'updated_at'=>'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
  ];
  foreach($defs as $name=>$def){if(!isset($cols[$name])){$pdo->exec("ALTER TABLE products ADD COLUMN `$name` $def");$cols[$name]=true;}}
  try{$idx=[];foreach($pdo->query("SHOW INDEX FROM products") as $r)$idx[(string)$r['Key_name']]=true;if(!isset($idx['idx_source_hash']))$pdo->exec('CREATE INDEX idx_source_hash ON products(source_hash)');if(!isset($idx['idx_active']))$pdo->exec('CREATE INDEX idx_active ON products(is_active)');if(!isset($idx['idx_stock_status']))$pdo->exec('CREATE INDEX idx_stock_status ON products(stock_status)');}catch(Throwable $e){error_log('index_migration_failed: '.$e->getMessage());}
}
function ensure_schema(PDO $pdo): void {
$pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,username VARCHAR(120) NOT NULL UNIQUE,password_hash VARCHAR(255) NULL,role VARCHAR(30) NOT NULL DEFAULT 'owner',is_active TINYINT(1) NOT NULL DEFAULT 1,force_password_setup TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,setting_key VARCHAR(120) NOT NULL UNIQUE,setting_value LONGTEXT NULL,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS products (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source_hash CHAR(64) NULL,external_url VARCHAR(1000) NULL,title VARCHAR(500) NOT NULL,price_rub INT NOT NULL DEFAULT 0,old_price_rub INT NULL,stock_qty INT NULL,stock_status VARCHAR(40) NOT NULL DEFAULT 'unknown',availability VARCHAR(40) NOT NULL DEFAULT 'unknown',category_path TEXT NULL,specs LONGTEXT NULL,description LONGTEXT NULL,images LONGTEXT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_source_hash (source_hash),INDEX idx_title (title(191)),INDEX idx_price (price_rub),INDEX idx_active (is_active),INDEX idx_stock_status (stock_status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
ensure_product_columns($pdo);
$pdo->exec("CREATE TABLE IF NOT EXISTS orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_number VARCHAR(40) NOT NULL UNIQUE,customer_name VARCHAR(200) NOT NULL,phone VARCHAR(40) NOT NULL,email VARCHAR(200) NULL,delivery_method VARCHAR(30) NOT NULL DEFAULT 'pickup',address TEXT NULL,comment TEXT NULL,status VARCHAR(30) NOT NULL DEFAULT 'new',total_rub INT NOT NULL DEFAULT 0,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_status (status),INDEX idx_created (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS order_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,product_id BIGINT UNSIGNED NULL,title VARCHAR(500) NOT NULL,price_rub INT NOT NULL,quantity INT NOT NULL DEFAULT 1,line_total_rub INT NOT NULL,CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,INDEX idx_order (order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,admin_user_id INT UNSIGNED NULL,action VARCHAR(100) NOT NULL,entity_type VARCHAR(50) NULL,entity_id VARCHAR(100) NULL,payload LONGTEXT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_created (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$stmt=$pdo->prepare("INSERT IGNORE INTO admin_users (username,password_hash,role,is_active,force_password_setup) VALUES (?,NULL,'owner',1,1)");$stmt->execute(['Иван Кириллов 414']);
}
function start_secure_session(): void { if(session_status()===PHP_SESSION_ACTIVE)return; ini_set('session.use_strict_mode','1'); ini_set('session.gc_maxlifetime',(string)(60*60*24*14)); session_name('PROFISPORT_ADMIN'); session_set_cookie_params(['lifetime'=>60*60*24*14,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']); session_start(); }
function json_response(array $data,int $status=200): never { http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit; }
function input_json(): array {$raw=file_get_contents('php://input')?:'';$data=json_decode($raw,true);return is_array($data)?$data:[];}
function require_admin(): array {start_secure_session();if(empty($_SESSION['admin']))json_response(['ok'=>false,'error'=>'unauthorized'],401);return $_SESSION['admin'];}
function csrf_check(): void {start_secure_session();$token=$_SERVER['HTTP_X_CSRF_TOKEN']??'';if(!$token||empty($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],$token))json_response(['ok'=>false,'error'=>'csrf'],403);}
function audit(PDO $pdo,string $action,?string $type=null,?string $id=null,array $payload=[]):void{try{$adminId=$_SESSION['admin']['id']??null;$s=$pdo->prepare('INSERT INTO audit_log (admin_user_id,action,entity_type,entity_id,payload) VALUES (?,?,?,?,?)');$s->execute([$adminId,$action,$type,$id,$payload?json_encode($payload,JSON_UNESCAPED_UNICODE):null]);}catch(Throwable $e){error_log('audit_failed: '.$e->getMessage());}}
$pdo=db();ensure_schema($pdo);
