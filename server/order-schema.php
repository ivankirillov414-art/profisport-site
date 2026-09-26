<?php
declare(strict_types=1);

// Preserve the original columns: older installations used different names.
function ensure_order_columns(PDO $pdo): void {
    $version='order_columns_v3';
    $done=$pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key=?');
    $done->execute([$version]);
    if($done->fetchColumn()==='1')return;
    if((int)$pdo->query("SELECT GET_LOCK('profisport_order_columns_v3',10)")->fetchColumn()!==1)throw new RuntimeException('Order schema migration is busy');
    try{
        $done->execute([$version]);if($done->fetchColumn()==='1')return;
        $definitions=[
            'orders'=>['delivery_method'=>'VARCHAR(30) NULL','pickup_store'=>'VARCHAR(180) NULL','total_rub'=>'DECIMAL(12,2) NULL','bonus_spent'=>'INT NOT NULL DEFAULT 0','bonus_earned'=>'INT NOT NULL DEFAULT 0','payable_rub'=>'DECIMAL(12,2) NULL'],
            'order_items'=>['title'=>'VARCHAR(500) NULL','price_rub'=>'DECIMAL(12,2) NULL','line_total_rub'=>'DECIMAL(12,2) NULL','category_path'=>'TEXT NULL'],
        ];
        foreach($definitions as $table=>$fields){
            $cols=table_columns($pdo,$table);
            foreach($fields as $name=>$definition)if(!isset($cols[$name]))$pdo->exec("ALTER TABLE `$table` ADD COLUMN `$name` $definition");
        }
        $orders=table_columns($pdo,'orders');$items=table_columns($pdo,'order_items');
        $delivery=isset($orders['delivery_type'])?"CASE WHEN delivery_type='delivery' THEN 'orenburg_delivery' ELSE 'pickup' END":"'pickup'";
        $total=isset($orders['total_amount'])?'total_amount':'0';
        // Assign updated_at to itself so backfilling does not change order history.
        $pdo->exec("UPDATE orders SET delivery_method=COALESCE(delivery_method,$delivery),total_rub=COALESCE(total_rub,$total),updated_at=updated_at WHERE delivery_method IS NULL OR total_rub IS NULL");
        $pdo->exec("UPDATE orders SET payable_rub=COALESCE(payable_rub,total_rub),updated_at=updated_at WHERE payable_rub IS NULL");
        // Before block 2 the checkout had only one pickup location, so older pickup orders can be backfilled accurately.
        $pdo->exec("UPDATE orders SET pickup_store='Проспект Победы, 79',updated_at=updated_at WHERE delivery_method='pickup' AND (pickup_store IS NULL OR TRIM(pickup_store)='')");
        $title=isset($items['product_name'])?'product_name':"''";
        $price=isset($items['price'])?'price':'0';$line=isset($items['total'])?'total':'0';
        $pdo->exec("UPDATE order_items SET title=COALESCE(title,$title),price_rub=COALESCE(price_rub,$price),line_total_rub=COALESCE(line_total_rub,$line) WHERE title IS NULL OR price_rub IS NULL OR line_total_rub IS NULL");
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_status_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(30) NOT NULL,
            changed_by_admin_user_id INT UNSIGNED NULL,
            source VARCHAR(30) NOT NULL DEFAULT 'system',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order_status_history_order (order_id,id),
            INDEX idx_order_status_history_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $s=$pdo->prepare('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$s->execute([$version,'1']);
    }finally{$pdo->query("SELECT RELEASE_LOCK('profisport_order_columns_v3')");}
}

function record_order_status(PDO $pdo,int $orderId,string $status,?int $adminUserId=null,string $source='system',?string $createdAt=null): void {
    if($orderId<1||!in_array($status,['new','confirmed','processing','ready','completed','cancelled'],true))throw new InvalidArgumentException('Invalid order status history row');
    if(!preg_match('/^[a-z_]{2,30}$/D',$source))$source='system';
    if($createdAt!==null){
        $s=$pdo->prepare('INSERT INTO order_status_history(order_id,status,changed_by_admin_user_id,source,created_at) VALUES(?,?,?,?,?)');
        $s->execute([$orderId,$status,$adminUserId,$source,$createdAt]);
        return;
    }
    $s=$pdo->prepare('INSERT INTO order_status_history(order_id,status,changed_by_admin_user_id,source,created_at) VALUES(?,?,?,?,NOW())');
    $s->execute([$orderId,$status,$adminUserId,$source]);
}

// Dual-write the legacy columns where present, including mandatory product_name.
function insert_order_row(PDO $pdo,string $table,array $row): void {
    if(!in_array($table,['orders','order_items'],true))throw new InvalidArgumentException('Invalid order table');
    $columns=table_columns($pdo,$table);
    $aliases=$table==='orders'?['total_amount'=>'total_rub']:['product_name'=>'title','price'=>'price_rub','total'=>'line_total_rub'];
    foreach($aliases as $old=>$current)if(isset($columns[$old]))$row[$old]=$row[$current];
    if($table==='orders'&&isset($columns['delivery_type']))$row['delivery_type']=$row['delivery_method']==='orenburg_delivery'?'delivery':'pickup';
    foreach(array_keys($row) as $key)if(!preg_match('/^[a-z_]+$/D',$key)||!isset($columns[$key]))throw new InvalidArgumentException('Invalid order column');
    $names=implode('`,`',array_keys($row));$marks=implode(',',array_fill(0,count($row),'?'));
    $s=$pdo->prepare("INSERT INTO `$table` (`$names`) VALUES ($marks)");$s->execute(array_values($row));
}
