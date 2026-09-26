<?php
declare(strict_types=1);

function customer_vehicle_type(string $title,string $categoryPath): ?string {
    $titleNorm=mb_strtolower(trim($title),'UTF-8');
    $categoryNorm=mb_strtolower(trim($categoryPath),'UTF-8');
    $top=trim((string)(preg_split('~\s*(?:/|>|»|→)\s*~u',$categoryNorm)[0]??$categoryNorm));
    if(preg_match('/запчаст|аксессуар|детал|покрыш|камер|компонент/u',$top))return null;
    if(preg_match('/самокат/u',$top)||preg_match('/^(?:электро)?самокат\b/u',$titleNorm))return 'scooter';
    if(preg_match('/велосипед|\bbmx\b/u',$top)||preg_match('/^(?:электро)?велосипед\b|^bmx\b/u',$titleNorm))return 'bicycle';
    return null;
}

function customer_vehicle_image(array $row): ?string {
    $urls=[(string)($row['main_image']??''),(string)($row['image_url']??'')];
    $images=json_decode((string)($row['images']??''),true);
    if(is_array($images))foreach($images as $value)$urls[]=(string)$value;
    foreach($urls as $url){
        $url=trim($url);if($url==='')continue;
        if(str_starts_with($url,'/import/'))return 'api/product-image.php?p='.rawurlencode(substr($url,8));
        if(str_starts_with($url,'import/'))return 'api/product-image.php?p='.rawurlencode(substr($url,7));
        if(preg_match('~^https?://~i',$url)||str_starts_with($url,'api/'))return $url;
    }
    return null;
}

function ensure_customer_vehicle_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_vehicles (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id BIGINT UNSIGNED NOT NULL,
        source_order_id BIGINT UNSIGNED NULL,
        source_order_item_id BIGINT UNSIGNED NULL,
        unit_index INT UNSIGNED NOT NULL DEFAULT 1,
        product_id BIGINT UNSIGNED NULL,
        title VARCHAR(500) NOT NULL,
        vehicle_type VARCHAR(40) NOT NULL,
        category_path TEXT NULL,
        image_url TEXT NULL,
        order_number VARCHAR(40) NULL,
        purchase_date DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY idx_vehicle_source_unit(source_order_item_id,unit_index),
        INDEX idx_vehicle_customer(customer_id,is_active),
        INDEX idx_vehicle_order(source_order_id),
        INDEX idx_vehicle_product(product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $cols=table_columns($pdo,'service_requests');
    $defs=[
        'customer_id'=>'BIGINT UNSIGNED NULL',
        'vehicle_id'=>'BIGINT UNSIGNED NULL',
        'source'=>"VARCHAR(30) NOT NULL DEFAULT 'public'",
    ];
    foreach($defs as $name=>$def)if(!isset($cols[$name])){$pdo->exec("ALTER TABLE service_requests ADD COLUMN `$name` $def");$cols[$name]=true;}
    try{
        $idx=[];foreach($pdo->query('SHOW INDEX FROM service_requests') as $r)$idx[(string)$r['Key_name']]=true;
        if(!isset($idx['idx_service_customer']))$pdo->exec('CREATE INDEX idx_service_customer ON service_requests(customer_id,created_at)');
        if(!isset($idx['idx_service_vehicle']))$pdo->exec('CREATE INDEX idx_service_vehicle ON service_requests(vehicle_id,created_at)');
    }catch(Throwable $e){error_log('service_customer_index_migration_failed: '.$e->getMessage());}

    $pdo->exec("CREATE TABLE IF NOT EXISTS service_request_status_history (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        service_request_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(30) NOT NULL,
        source VARCHAR(30) NOT NULL DEFAULT 'system',
        changed_by_admin_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_service_history_request(service_request_id,id),
        INDEX idx_service_history_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function record_service_request_status(PDO $pdo,int $requestId,string $status,string $source='system',?int $adminUserId=null,?string $createdAt=null): void {
    $allowed=['new','contacted','accepted','diagnostics','repair','ready','completed','cancelled'];
    if($requestId<1||!in_array($status,$allowed,true))throw new InvalidArgumentException('invalid_service_status');
    if(!preg_match('/^[a-z_]{2,30}$/D',$source))$source='system';
    if($createdAt!==null){
        $s=$pdo->prepare('INSERT INTO service_request_status_history(service_request_id,status,source,changed_by_admin_user_id,created_at) VALUES(?,?,?,?,?)');
        $s->execute([$requestId,$status,$source,$adminUserId,$createdAt]);return;
    }
    $s=$pdo->prepare('INSERT INTO service_request_status_history(service_request_id,status,source,changed_by_admin_user_id,created_at) VALUES(?,?,?,?,NOW())');
    $s->execute([$requestId,$status,$source,$adminUserId]);
}

function ensure_service_request_history(PDO $pdo,int $requestId): void {
    $s=$pdo->prepare('SELECT COUNT(*) FROM service_request_status_history WHERE service_request_id=?');$s->execute([$requestId]);
    if((int)$s->fetchColumn()>0)return;
    $q=$pdo->prepare('SELECT status,created_at FROM service_requests WHERE id=? LIMIT 1');$q->execute([$requestId]);$row=$q->fetch();
    if(!$row)return;
    record_service_request_status($pdo,$requestId,'new','legacy',null,(string)$row['created_at']);
    if((string)$row['status']!=='new')record_service_request_status($pdo,$requestId,(string)$row['status'],'legacy_current');
}

function customer_vehicle_sync_order(PDO $pdo,int $orderId): int {
    $o=$pdo->prepare('SELECT id,customer_id,order_number,status,created_at FROM orders WHERE id=? LIMIT 1');$o->execute([$orderId]);$order=$o->fetch();
    if(!$order||(int)($order['customer_id']??0)<1)return 0;
    if((string)$order['status']!=='completed'){
        $s=$pdo->prepare('UPDATE customer_vehicles SET is_active=0 WHERE source_order_id=?');$s->execute([$orderId]);return 0;
    }
    $items=$pdo->prepare('SELECT oi.id order_item_id,oi.product_id,oi.title,oi.quantity,oi.category_path,p.main_image,p.images FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=? ORDER BY oi.id');
    $items->execute([$orderId]);$count=0;
    $insert=$pdo->prepare("INSERT INTO customer_vehicles(customer_id,source_order_id,source_order_item_id,unit_index,product_id,title,vehicle_type,category_path,image_url,order_number,purchase_date,is_active)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,1)
        ON DUPLICATE KEY UPDATE customer_id=VALUES(customer_id),product_id=VALUES(product_id),title=VALUES(title),vehicle_type=VALUES(vehicle_type),category_path=VALUES(category_path),image_url=VALUES(image_url),order_number=VALUES(order_number),purchase_date=VALUES(purchase_date),is_active=1");
    foreach($items->fetchAll() as $item){
        $type=customer_vehicle_type((string)$item['title'],(string)($item['category_path']??''));if($type===null)continue;
        $image=customer_vehicle_image($item);$qty=max(1,min(20,(int)$item['quantity']));
        for($unit=1;$unit<=$qty;$unit++){
            $insert->execute([(int)$order['customer_id'],$orderId,(int)$item['order_item_id'],$unit,$item['product_id']!==null?(int)$item['product_id']:null,(string)$item['title'],$type,(string)($item['category_path']??''),$image,(string)$order['order_number'],(string)$order['created_at']]);$count++;
        }
    }
    return $count;
}

function customer_vehicle_sync_customer(PDO $pdo,int $customerId): int {
    if($customerId<1)return 0;
    $s=$pdo->prepare("SELECT id FROM orders WHERE customer_id=? AND status='completed' ORDER BY id");$s->execute([$customerId]);$count=0;
    foreach($s->fetchAll() as $row)$count+=customer_vehicle_sync_order($pdo,(int)$row['id']);
    return $count;
}

function customer_vehicle_rows(PDO $pdo,int $customerId): array {
    customer_vehicle_sync_customer($pdo,$customerId);
    $s=$pdo->prepare('SELECT v.id,v.product_id,v.title,v.vehicle_type,v.category_path,v.image_url,v.order_number,v.purchase_date,v.unit_index,v.created_at,p.main_image,p.images FROM customer_vehicles v LEFT JOIN products p ON p.id=v.product_id WHERE v.customer_id=? AND v.is_active=1 ORDER BY COALESCE(v.purchase_date,v.created_at) DESC,v.id DESC');
    $s->execute([$customerId]);$rows=$s->fetchAll();
    foreach($rows as &$row){$row['id']=(int)$row['id'];$row['product_id']=$row['product_id']!==null?(int)$row['product_id']:null;$row['unit_index']=(int)$row['unit_index'];$row['image']=customer_vehicle_image($row);unset($row['main_image'],$row['images'],$row['image_url']);}unset($row);
    return $rows;
}

function customer_service_rows(PDO $pdo,int $customerId): array {
    $s=$pdo->prepare('SELECT sr.id,sr.request_number,sr.vehicle_id,sr.service_type,sr.bike,sr.problem,sr.status,sr.created_at,sr.updated_at,v.title vehicle_title,v.vehicle_type FROM service_requests sr LEFT JOIN customer_vehicles v ON v.id=sr.vehicle_id WHERE sr.customer_id=? ORDER BY sr.id DESC LIMIT 100');
    $s->execute([$customerId]);$rows=$s->fetchAll();
    $h=$pdo->prepare('SELECT status,source,created_at FROM service_request_status_history WHERE service_request_id=? ORDER BY id');
    foreach($rows as &$row){
        $row['id']=(int)$row['id'];$row['vehicle_id']=$row['vehicle_id']!==null?(int)$row['vehicle_id']:null;
        ensure_service_request_history($pdo,(int)$row['id']);$h->execute([(int)$row['id']]);$row['history']=$h->fetchAll();
    }unset($row);
    return $rows;
}
