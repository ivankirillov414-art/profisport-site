<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';

function live_check(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }

$pdo->beginTransaction();
try{
    $cfg=loyalty_save_draft($pdo,[
        'redeem_enabled'=>true,
        'expiration_enabled'=>true,
        'point_value_kopeks'=>100,
        'max_redeem_percent_bp'=>10000,
        'expiration_days'=>365,
    ]);
    live_check(loyalty_configured($cfg),'test loyalty config must be complete');
    $program=loyalty_set_program_enabled($pdo,true,1);
    live_check($program['enabled']===true,'program must activate explicitly');

    $s=$pdo->prepare("INSERT INTO customers(name,email,phone,password_hash,bonus_balance) VALUES('Loyalty test','loyalty-live@example.test','+79990000001',NULL,0)");
    $s->execute();$customerId=(int)$pdo->lastInsertId();
    $future1=(new DateTimeImmutable('+10 days'))->format('Y-m-d H:i:s');
    $future2=(new DateTimeImmutable('+20 days'))->format('Y-m-d H:i:s');
    $c1=loyalty_post($pdo,$customerId,50,'test_credit','test','credit-1',null,'Первый пакет',$future1);
    $c2=loyalty_post($pdo,$customerId,30,'test_credit','test','credit-2',null,'Второй пакет',$future2);
    live_check($c1['balance']===50&&$c2['balance']===80,'credits must update balance');

    insert_order_row($pdo,'orders',[
        'customer_id'=>$customerId,'order_number'=>'PS-LOYALTY-TEST','customer_name'=>'Loyalty test','phone'=>'+79990000001',
        'email'=>'loyalty-live@example.test','delivery_method'=>'pickup','pickup_store'=>'Проспект Победы, 79','address'=>null,
        'comment'=>null,'status'=>'new','total_rub'=>100,'payable_rub'=>100,'bonus_spent'=>0,'bonus_earned'=>0,
        'request_key'=>str_repeat('9',64),'request_hash'=>str_repeat('8',64),
    ]);
    $orderId=(int)$pdo->lastInsertId();
    $reserved=loyalty_reserve_order_redemption($pdo,$customerId,$orderId,100,60);
    live_check($reserved['points']===60&&abs($reserved['payable_rub']-40.0)<0.001&&$reserved['balance']===20,'checkout reservation must reduce payable and balance');

    $q=$pdo->prepare("SELECT source_id,remaining_amount,status FROM loyalty_transactions WHERE customer_id=? AND source_type='test' ORDER BY id");$q->execute([$customerId]);$lots=$q->fetchAll();
    live_check((int)$lots[0]['remaining_amount']===0&&(string)$lots[0]['status']==='spent','FIFO must consume the oldest credit first');
    live_check((int)$lots[1]['remaining_amount']===20&&(string)$lots[1]['status']==='active','FIFO must consume only the needed part of the second credit');

    $refund=loyalty_refund_order_redemption($pdo,$orderId,1);
    live_check($refund['refunded']===60&&$refund['balance']===80,'cancel refund must restore reserved points');
    $q=$pdo->prepare("SELECT amount,expires_at FROM loyalty_transactions WHERE customer_id=? AND kind='redeem_refund' ORDER BY id");$q->execute([$customerId]);$refundLots=$q->fetchAll();
    live_check(count($refundLots)===2,'refund must restore the consumed FIFO lots separately');
    live_check((int)$refundLots[0]['amount']===50&&(string)$refundLots[0]['expires_at']===$future1,'first refunded lot must keep its original expiry');
    live_check((int)$refundLots[1]['amount']===10&&(string)$refundLots[1]['expires_at']===$future2,'second refunded lot must keep its original expiry');
    $o=$pdo->prepare('SELECT bonus_spent,payable_rub FROM orders WHERE id=?');$o->execute([$orderId]);$order=$o->fetch();
    live_check((int)$order['bonus_spent']===0&&abs((float)$order['payable_rub']-100.0)<0.001,'refund must reset order payable values');

    $past=(new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');
    $expiredCredit=loyalty_post($pdo,$customerId,25,'test_credit','test','credit-expired',null,'Просроченный пакет',$past);
    live_check($expiredCredit['balance']===105,'expired test credit starts in the ledger');
    $available=loyalty_available_balance($pdo,$customerId);
    live_check($available===80,'expired unspent points must be removed from balance');
    $q=$pdo->prepare("SELECT remaining_amount,status FROM loyalty_transactions WHERE customer_id=? AND source_type='test' AND source_id='credit-expired'");$q->execute([$customerId]);$expired=$q->fetch();
    live_check((int)$expired['remaining_amount']===0&&(string)$expired['status']==='expired','expired lot must close');
    $q=$pdo->prepare("SELECT COUNT(*) FROM loyalty_transactions WHERE customer_id=? AND kind='expiry' AND source_type='expiry'");$q->execute([$customerId]);
    live_check((int)$q->fetchColumn()===1,'expiry must create one auditable ledger row');

    $pdo->rollBack();
    echo "PASS: live loyalty activation, FIFO reservation, cancellation refund and expiry\n";
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    throw $e;
}
