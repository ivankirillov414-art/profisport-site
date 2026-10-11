<?php
declare(strict_types=1);

function ensure_loyalty_tiers_schema(PDO $pdo): void {
    $key='loyalty_purchase_tiers_v1';
    $q=$pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key=?');$q->execute([$key]);if($q->fetchColumn()==='1')return;
    if((int)$pdo->query("SELECT GET_LOCK('profisport_loyalty_tiers_v1',10)")->fetchColumn()!==1)throw new RuntimeException('Loyalty migration busy');
    try{
        $q->execute([$key]);if($q->fetchColumn()==='1')return;
        $cols=table_columns($pdo,'order_items');
        foreach(['loyalty_promo'=>'TINYINT(1) NOT NULL DEFAULT 1','returned_quantity'=>'INT UNSIGNED NOT NULL DEFAULT 0'] as $name=>$def){
            if(!isset($cols[$name]))$pdo->exec("ALTER TABLE order_items ADD COLUMN `$name` $def");
        }
        // Old orders are excluded: their promotion status at purchase cannot be reconstructed safely.
        $pdo->exec("CREATE TABLE IF NOT EXISTS loyalty_paid_orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL UNIQUE,customer_id BIGINT UNSIGNED NOT NULL,paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,admin_user_id INT UNSIGNED NOT NULL,INDEX idx_customer_paid(customer_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->prepare('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,'1']);
    }finally{$pdo->query("SELECT RELEASE_LOCK('profisport_loyalty_tiers_v1')");}
}
function loyalty_next_tier(int $bp,int $eligibleKopeks): int {
    if($bp===0 && $eligibleKopeks>500000)return 500;
    if($bp===500 && $eligibleKopeks>=3000000)return 700;
    if($bp===700 && $eligibleKopeks>=5000000)return 1000;
    return $bp;
}
function loyalty_purchase_tier(PDO $pdo,int $customerId): array {
    $bp=0;
    if($customerId>0){
        $q=$pdo->prepare("SELECT p.id,COALESCE(SUM(CASE WHEN i.loyalty_promo=0 THEN ROUND(COALESCE(i.base_price_rub,i.price_rub)*100)*(i.quantity-i.returned_quantity) ELSE 0 END),0) eligible_kopeks FROM loyalty_paid_orders p JOIN orders o ON o.id=p.order_id LEFT JOIN order_items i ON i.order_id=o.id WHERE p.customer_id=? AND o.status<>'cancelled' GROUP BY p.id ORDER BY p.id");$q->execute([$customerId]);
        foreach($q as $row)$bp=loyalty_next_tier($bp,(int)$row['eligible_kopeks']);
    }
    return ['percent_bp'=>$bp,'percent'=>$bp/100,'next_percent'=>$bp===0?5:($bp===500?7:($bp===700?10:null)),'next_purchase_rub'=>$bp===0?5000:($bp===500?30000:($bp===700?50000:null)),'first_threshold_exclusive'=>$bp===0];
}
function loyalty_confirm_paid(PDO $pdo,array $order,int $adminId): array {
    $customerId=(int)($order['customer_id']??0);
    if($customerId<1)throw new InvalidArgumentException('loyalty_customer_required');
    if($order['status']==='cancelled')throw new InvalidArgumentException('cancelled_order');
    $pdo->prepare('SELECT id FROM customers WHERE id=? FOR UPDATE')->execute([$customerId]);
    $q=$pdo->prepare('INSERT IGNORE INTO loyalty_paid_orders(order_id,customer_id,admin_user_id) VALUES(?,?,?)');$q->execute([(int)$order['id'],$customerId,$adminId]);
    audit($pdo,'order_payment_confirmed','order',(string)$order['id']);
    return loyalty_purchase_tier($pdo,$customerId);
}
function loyalty_record_returns(PDO $pdo,array $order,array $input,int $adminId): array {
    $customerId=(int)($order['customer_id']??0);
    if($customerId>0)$pdo->prepare('SELECT id FROM customers WHERE id=? FOR UPDATE')->execute([$customerId]);
    $q=$pdo->prepare('SELECT id,quantity,returned_quantity FROM order_items WHERE order_id=? ORDER BY id FOR UPDATE');$q->execute([(int)$order['id']]);$rows=$q->fetchAll();
    if(count($input)!==count($rows))throw new InvalidArgumentException('invalid_returns');
    $byId=[];foreach($rows as $r)$byId[(int)$r['id']]=$r;$seen=[];
    foreach($input as $entry){
        if(!is_array($entry)||!is_int($entry['id']??null)||!is_int($entry['quantity']??null)||!is_int($entry['previous_quantity']??null))throw new InvalidArgumentException('invalid_returns');
        $id=$entry['id'];$qty=$entry['quantity'];$r=$byId[$id]??null;
        if(!$r||isset($seen[$id])||$entry['previous_quantity']!==(int)$r['returned_quantity']||$qty<(int)$r['returned_quantity']||$qty>(int)$r['quantity'])throw new InvalidArgumentException('invalid_returns');
        $seen[$id]=true;
    }
    foreach($input as $entry)$pdo->prepare('UPDATE order_items SET returned_quantity=? WHERE id=? AND order_id=?')->execute([$entry['quantity'],$entry['id'],(int)$order['id']]);
    audit($pdo,'order_return','order',(string)$order['id'],['items'=>$input]);
    return loyalty_purchase_tier($pdo,$customerId);
}
