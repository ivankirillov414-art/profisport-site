<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';

function discount_check(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }

$pdo->beginTransaction();
try{
    $cfg=loyalty_center_sanitize_config([
        'enabled'=>true,
        'discounts_enabled'=>true,
        'discount_stack_rule'=>'max',
        'earn_basis'=>'after_discounts',
        'discount_groups'=>[
            ['key'=>'accessories','name'=>'Аксессуары','percent_bp'=>1000,'categories'=>['Аксессуары / Шлемы','Аксессуары / Фонари']]
        ],
    ]);
    discount_check(loyalty_configured($cfg),'discount-only config must be valid');
    $s=$pdo->prepare('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $s->execute(['loyalty_config_v1',json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);

    $s=$pdo->prepare("INSERT INTO customers(name,email,phone,password_hash,bonus_balance) VALUES('Discount test','discount-test@example.test','+79990000009',NULL,0)");
    $s->execute();$customerId=(int)$pdo->lastInsertId();

    $base=[
        'items'=>[
            ['id'=>101,'title'=>'Helmet','price'=>1000,'qty'=>2,'line'=>2000,'category_path'=>'Аксессуары / Шлемы'],
            ['id'=>102,'title'=>'Bike','price'=>5000,'qty'=>1,'line'=>5000,'category_path'=>'Велосипеды / Горные'],
        ],
        'total'=>7000,
    ];
    $priced=loyalty_discount_order_items($pdo,$base,$customerId);
    discount_check($priced['subtotal']===7000&&$priced['discount']===200&&$priced['total']===6800,'category discount must apply only to selected category');
    discount_check($priced['items'][0]['discount_percent_bp']===1000&&$priced['items'][1]['discount_percent_bp']===0,'category group must be exact');

    loyalty_set_customer_discount($pdo,$customerId,true,700,null,'test',1);
    $priced=loyalty_discount_order_items($pdo,$base,$customerId);
    discount_check($priced['items'][0]['discount_percent_bp']===1000,'max rule must keep larger category discount');
    discount_check($priced['items'][1]['discount_percent_bp']===700,'all-catalog personal discount must apply outside group');

    loyalty_set_customer_discount($pdo,$customerId,true,1500,'accessories','test',1);
    $priced=loyalty_discount_order_items($pdo,$base,$customerId);
    discount_check($priced['items'][0]['discount_percent_bp']===1500&&$priced['items'][1]['discount_percent_bp']===0,'group-scoped personal discount must stay in its group');

    $cfg['discount_stack_rule']='sum';$s->execute(['loyalty_config_v1',json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $priced=loyalty_discount_order_items($pdo,$base,$customerId);
    discount_check($priced['items'][0]['discount_percent_bp']===2500,'sum rule must combine category and personal discount');

    $cfg['enabled']=false;$s->execute(['loyalty_config_v1',json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $priced=loyalty_discount_order_items($pdo,$base,$customerId);
    discount_check($priced['discount']===0&&$priced['total']===7000,'master switch must disable all discounts');

    $pdo->rollBack();
    echo "PASS: central category and personal discounts, overlap policies and master switch\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
