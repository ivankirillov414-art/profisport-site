<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
function tier_check(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
$pdo->beginTransaction();
try{
    $pdo->exec("INSERT INTO customers(name,email,phone,password_hash) VALUES('Tier test','tier-db@example.test','+79990005555',NULL)");$customerId=(int)$pdo->lastInsertId();
    $adminId=(int)$pdo->query("SELECT id FROM admin_users ORDER BY id LIMIT 1")->fetchColumn();
    function tier_order(PDO $pdo,int $customerId,int $price,int $qty=1,bool $promo=false): array {
        insert_order_row($pdo,'orders',['customer_id'=>$customerId,'order_number'=>'tier-'.bin2hex(random_bytes(5)),'customer_name'=>'Tier test','phone'=>'+79990005555','status'=>'new','delivery_method'=>'pickup','total_rub'=>$price*$qty,'subtotal_rub'=>$price*$qty]);$id=(int)$pdo->lastInsertId();
        insert_order_row($pdo,'order_items',['order_id'=>$id,'product_id'=>1,'title'=>'Tier test','price_rub'=>$price,'base_price_rub'=>$price,'quantity'=>$qty,'line_total_rub'=>$price*$qty,'base_line_total_rub'=>$price*$qty,'loyalty_promo'=>(int)$promo]);
        $itemId=(int)$pdo->lastInsertId();return ['id'=>$id,'customer_id'=>$customerId,'status'=>'new','item_id'=>$itemId];
    }
    $first=tier_order($pdo,$customerId,50000);
    tier_check(loyalty_purchase_tier($pdo,$customerId)['percent_bp']===0,'Unpaid order cannot earn tier');
    $r=loyalty_confirm_paid($pdo,$first,$adminId);tier_check($r['percent_bp']===500,'First 50k purchase must give only 5%');
    tier_check(loyalty_confirm_paid($pdo,$first,$adminId)['percent_bp']===500,'Duplicate payment must not raise tier');
    $promo=tier_order($pdo,$customerId,100000,1,true);tier_check(loyalty_confirm_paid($pdo,$promo,$adminId)['percent_bp']===500,'Promotions must not earn tier');
    $second=tier_order($pdo,$customerId,15000,2);tier_check(loyalty_confirm_paid($pdo,$second,$adminId)['percent_bp']===700,'30k at 5% earns 7%');
    $third=tier_order($pdo,$customerId,25000,2);tier_check(loyalty_confirm_paid($pdo,$third,$adminId)['percent_bp']===1000,'50k at 7% earns 10%');
    $priced=loyalty_discount_order_items($pdo,['items'=>[['id'=>1,'title'=>'Normal','price'=>1000,'qty'=>1,'category_path'=>'','loyalty_promo'=>false],['id'=>2,'title'=>'Sale','price'=>1000,'qty'=>1,'category_path'=>'','loyalty_promo'=>true]]],$customerId);
    tier_check($priced['items'][0]['price']===900 && $priced['items'][1]['price']===1000,'Tier must apply when bonus program disabled and exclude promotions');
    $r=loyalty_record_returns($pdo,$third,[['id'=>$third['item_id'],'quantity'=>1,'previous_quantity'=>0]],$adminId);tier_check($r['percent_bp']===700,'Partial return crossing 50k must revoke 10%');
    try{loyalty_record_returns($pdo,$third,[['id'=>$third['item_id'],'quantity'=>2,'previous_quantity'=>0]],$adminId);throw new LogicException('Stale return accepted');}catch(InvalidArgumentException $e){}
    $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$second['id']]);tier_check(loyalty_purchase_tier($pdo,$customerId)['percent_bp']===500,'Full cancellation must revoke 7%');
    $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$first['id']]);tier_check(loyalty_purchase_tier($pdo,$customerId)['percent_bp']===500,'Remaining paid 25k order earns only first 5% on replay');
    $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$third['id']]);tier_check(loyalty_purchase_tier($pdo,$customerId)['percent_bp']===0,'All eligible purchases reversed means no tier');
    echo "PASS: payment idempotency, promo exclusion, automatic tiers, partial returns, stale writes and chronological replay\n";
}finally{$pdo->rollBack();}
