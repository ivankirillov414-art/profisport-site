<?php
declare(strict_types=1);

function loyalty_default_config(): array {
    return [
        'enabled'=>false,
        'activation_at'=>null,
        'earn_percent_bp'=>null,
        'max_redeem_percent_bp'=>null,
        'point_value_kopeks'=>null,
        'expiration_days'=>null,
        'min_order_rub'=>null,
        'review_bonus'=>null,
        'excluded_category_prefixes'=>[],
    ];
}

function ensure_loyalty_schema(PDO $pdo): void {
    $cols=table_columns($pdo,'loyalty_transactions');
    $defs=[
        'order_id'=>'BIGINT UNSIGNED NULL',
        'expires_at'=>'DATETIME NULL',
        'reversal_of_id'=>'BIGINT UNSIGNED NULL',
        'status'=>"VARCHAR(20) NOT NULL DEFAULT 'active'",
        'admin_user_id'=>'INT UNSIGNED NULL',
        'metadata'=>'LONGTEXT NULL',
    ];
    foreach($defs as $name=>$def){
        if(!isset($cols[$name])){$pdo->exec("ALTER TABLE loyalty_transactions ADD COLUMN `$name` $def");$cols[$name]=true;}
    }
    try{
        $idx=[];foreach($pdo->query('SHOW INDEX FROM loyalty_transactions') as $r)$idx[(string)$r['Key_name']]=true;
        if(!isset($idx['idx_loyalty_status_expiry']))$pdo->exec('CREATE INDEX idx_loyalty_status_expiry ON loyalty_transactions(status,expires_at)');
        if(!isset($idx['idx_loyalty_source']))$pdo->exec('CREATE INDEX idx_loyalty_source ON loyalty_transactions(customer_id,source_type,source_id,kind)');
        if(!isset($idx['idx_loyalty_order']))$pdo->exec('CREATE INDEX idx_loyalty_order ON loyalty_transactions(order_id)');
        if(!isset($idx['idx_loyalty_reversal']))$pdo->exec('CREATE INDEX idx_loyalty_reversal ON loyalty_transactions(reversal_of_id)');
    }catch(Throwable $e){error_log('loyalty_index_migration_failed: '.$e->getMessage());}
    $s=$pdo->prepare('INSERT IGNORE INTO site_settings(setting_key,setting_value) VALUES(?,?)');
    $s->execute(['loyalty_config_v1',json_encode(loyalty_default_config(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
}

function loyalty_config(PDO $pdo): array {
    $base=loyalty_default_config();
    $s=$pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key=? LIMIT 1');$s->execute(['loyalty_config_v1']);
    $raw=$s->fetchColumn();$decoded=is_string($raw)?json_decode($raw,true):null;
    if(!is_array($decoded))return $base;
    $cfg=array_merge($base,array_intersect_key($decoded,$base));
    $cfg['enabled']=($cfg['enabled']??false)===true;
    foreach(['earn_percent_bp','max_redeem_percent_bp','point_value_kopeks','expiration_days','min_order_rub','review_bonus'] as $key){
        if($cfg[$key]===null||$cfg[$key]==='')$cfg[$key]=null;
        elseif(is_numeric($cfg[$key]))$cfg[$key]=(int)$cfg[$key];
        else $cfg[$key]=null;
    }
    if(!is_array($cfg['excluded_category_prefixes']))$cfg['excluded_category_prefixes']=[];
    $cfg['excluded_category_prefixes']=array_values(array_unique(array_filter(array_map(fn($x)=>trim((string)$x),$cfg['excluded_category_prefixes']),fn($x)=>$x!=='')));
    $cfg['activation_at']=is_string($cfg['activation_at'])&&trim($cfg['activation_at'])!==''?$cfg['activation_at']:null;
    return $cfg;
}

function loyalty_configured(array $cfg): bool {
    return is_int($cfg['earn_percent_bp'])&&$cfg['earn_percent_bp']>=0&&$cfg['earn_percent_bp']<=10000
        &&is_int($cfg['max_redeem_percent_bp'])&&$cfg['max_redeem_percent_bp']>=0&&$cfg['max_redeem_percent_bp']<=10000
        &&is_int($cfg['point_value_kopeks'])&&$cfg['point_value_kopeks']>=1&&$cfg['point_value_kopeks']<=100000
        &&is_int($cfg['expiration_days'])&&$cfg['expiration_days']>=1&&$cfg['expiration_days']<=3650
        &&is_int($cfg['min_order_rub'])&&$cfg['min_order_rub']>=0
        &&is_int($cfg['review_bonus'])&&$cfg['review_bonus']>=0;
}

function loyalty_program_status(PDO $pdo): array {
    $cfg=loyalty_config($pdo);$configured=loyalty_configured($cfg);
    return [
        'enabled'=>$configured&&$cfg['enabled']===true,
        'configured'=>$configured,
        'stored_enabled'=>$cfg['enabled']===true,
        'activation_at'=>$cfg['activation_at'],
        'config'=>$cfg,
    ];
}

function loyalty_program_enabled(PDO $pdo): bool {
    return loyalty_program_status($pdo)['enabled']===true;
}

function loyalty_post(PDO $pdo,int $customerId,int $amount,string $kind,?string $sourceType=null,?string $sourceId=null,?int $orderId=null,?string $note=null,?string $expiresAt=null,?int $adminUserId=null,array $metadata=[]): array {
    if($customerId<1||$amount===0||!preg_match('/^[a-z_]{2,40}$/D',$kind))throw new InvalidArgumentException('Invalid loyalty transaction');
    if($sourceType!==null&&!preg_match('/^[a-z_]{2,40}$/D',$sourceType))throw new InvalidArgumentException('Invalid loyalty source');
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT bonus_balance FROM customers WHERE id=? FOR UPDATE');$lock->execute([$customerId]);$current=$lock->fetchColumn();
        if($current===false)throw new RuntimeException('Customer not found');
        $current=(int)$current;
        if($amount<0&&$current+$amount<0)throw new DomainException('insufficient_bonus_balance');
        if($sourceType!==null&&$sourceId!==null){
            $dup=$pdo->prepare("SELECT id,amount FROM loyalty_transactions WHERE customer_id=? AND kind=? AND source_type=? AND source_id=? AND status='active' ORDER BY id DESC LIMIT 1");
            $dup->execute([$customerId,$kind,$sourceType,$sourceId]);$existing=$dup->fetch();
            if($existing){if($ownsTransaction)$pdo->commit();return ['transaction_id'=>(int)$existing['id'],'amount'=>(int)$existing['amount'],'balance'=>$current,'duplicate'=>true];}
        }
        $s=$pdo->prepare('INSERT INTO loyalty_transactions(customer_id,amount,kind,source_type,source_id,note,order_id,expires_at,reversal_of_id,status,admin_user_id,metadata) VALUES(?,?,?,?,?,?,?,?,NULL,\'active\',?,?)');
        $s->execute([$customerId,$amount,$kind,$sourceType,$sourceId,$note,$orderId,$expiresAt,$adminUserId,$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null]);
        $id=(int)$pdo->lastInsertId();$balance=$current+$amount;
        $u=$pdo->prepare('UPDATE customers SET bonus_balance=? WHERE id=?');$u->execute([$balance,$customerId]);
        if($ownsTransaction)$pdo->commit();
        return ['transaction_id'=>$id,'amount'=>$amount,'balance'=>$balance,'duplicate'=>false];
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function loyalty_manual_adjustment(PDO $pdo,int $customerId,int $requestedAmount,string $note,int $adminUserId): array {
    if($requestedAmount===0)throw new InvalidArgumentException('Invalid loyalty adjustment');
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $s=$pdo->prepare('SELECT bonus_balance FROM customers WHERE id=? FOR UPDATE');$s->execute([$customerId]);$current=$s->fetchColumn();
        if($current===false)throw new RuntimeException('Customer not found');
        $current=(int)$current;$target=max(0,$current+$requestedAmount);$actual=$target-$current;
        if($actual===0){if($owns)$pdo->commit();return ['transaction_id'=>null,'amount'=>0,'balance'=>$current,'duplicate'=>false];}
        $result=loyalty_post($pdo,$customerId,$actual,'manual','admin',(string)$adminUserId,null,$note?:'Ручная корректировка',null,$adminUserId,['requested_amount'=>$requestedAmount]);
        if($owns)$pdo->commit();return $result;
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function loyalty_category_excluded(string $categoryPath,array $cfg): bool {
    $path=mb_strtolower(trim($categoryPath),'UTF-8');if($path==='')return false;
    foreach($cfg['excluded_category_prefixes'] as $prefix){
        $p=mb_strtolower(trim((string)$prefix),'UTF-8');if($p!==''&&str_starts_with($path,$p))return true;
    }
    return false;
}

function loyalty_order_earn_preview(array $items,array $cfg): array {
    if(!loyalty_configured($cfg))return ['eligible_rub'=>0,'points'=>0];
    $eligible=0;
    foreach($items as $item){
        if(loyalty_category_excluded((string)($item['category_path']??''),$cfg))continue;
        $eligible+=max(0,(int)($item['line_total_rub']??0));
    }
    if($eligible<(int)$cfg['min_order_rub'])return ['eligible_rub'=>$eligible,'points'=>0];
    $numerator=$eligible*(int)$cfg['earn_percent_bp']*100;
    $denominator=10000*(int)$cfg['point_value_kopeks'];
    $points=$denominator>0?intdiv($numerator,$denominator):0;
    return ['eligible_rub'=>$eligible,'points'=>max(0,$points)];
}

function loyalty_expiry_date(array $cfg): ?string {
    if(!is_int($cfg['expiration_days'])||$cfg['expiration_days']<1)return null;
    return (new DateTimeImmutable())->modify('+'.$cfg['expiration_days'].' days')->format('Y-m-d H:i:s');
}

function loyalty_award_review(PDO $pdo,int $reviewId,int $customerId): array {
    $status=loyalty_program_status($pdo);$cfg=$status['config'];
    if(!$status['enabled'])return ['awarded'=>0,'reason'=>$status['configured']?'program_disabled':'program_unconfigured'];
    $amount=(int)$cfg['review_bonus'];if($amount<=0)return ['awarded'=>0,'reason'=>'review_bonus_zero'];
    $result=loyalty_post($pdo,$customerId,$amount,'review_bonus','review',(string)$reviewId,null,'Бонус за опубликованный отзыв',loyalty_expiry_date($cfg),null,['review_id'=>$reviewId]);
    $pdo->prepare('UPDATE product_reviews SET bonus_awarded=1 WHERE id=?')->execute([$reviewId]);
    return ['awarded'=>$result['amount'],'reason'=>$result['duplicate']?'duplicate':'awarded','balance'=>$result['balance'],'transaction_id'=>$result['transaction_id']];
}

function loyalty_reverse_transaction(PDO $pdo,array $original,string $sourceType,string $sourceId,?int $adminUserId=null): array {
    $customerId=(int)$original['customer_id'];$amount=(int)$original['amount'];if($amount<=0)return ['amount'=>0,'balance'=>null,'duplicate'=>false];
    $dup=$pdo->prepare("SELECT id,amount FROM loyalty_transactions WHERE customer_id=? AND kind='reversal' AND source_type=? AND source_id=? AND reversal_of_id=? AND status='active' LIMIT 1");
    $dup->execute([$customerId,$sourceType,$sourceId,(int)$original['id']]);$existing=$dup->fetch();
    if($existing)return ['amount'=>(int)$existing['amount'],'duplicate'=>true,'transaction_id'=>(int)$existing['id'],'balance'=>null];
    $result=loyalty_post($pdo,$customerId,-$amount,'reversal',$sourceType,$sourceId,(int)($original['order_id']??0)?:null,'Отмена ранее начисленных бонусов',null,$adminUserId,['reversal_of_id'=>(int)$original['id']]);
    $pdo->prepare('UPDATE loyalty_transactions SET reversal_of_id=? WHERE id=?')->execute([(int)$original['id'],$result['transaction_id']]);
    return $result;
}

function loyalty_handle_order_status_change(PDO $pdo,int $orderId,string $from,string $to,?int $adminUserId=null): array {
    $status=loyalty_program_status($pdo);if(!$status['enabled'])return ['changed'=>false,'reason'=>$status['configured']?'program_disabled':'program_unconfigured'];
    $cfg=$status['config'];
    $o=$pdo->prepare('SELECT id,customer_id,total_rub,bonus_earned FROM orders WHERE id=? LIMIT 1');$o->execute([$orderId]);$order=$o->fetch();
    if(!$order||(int)($order['customer_id']??0)<1)return ['changed'=>false,'reason'=>'no_customer'];
    $customerId=(int)$order['customer_id'];
    if($to==='completed'&&$from!=='completed'){
        $items=$pdo->prepare('SELECT line_total_rub,category_path FROM order_items WHERE order_id=? ORDER BY id');$items->execute([$orderId]);
        $preview=loyalty_order_earn_preview($items->fetchAll(),$cfg);$points=(int)$preview['points'];
        if($points<=0)return ['changed'=>false,'reason'=>'zero_earn','preview'=>$preview];
        $result=loyalty_post($pdo,$customerId,$points,'order_earn','order',(string)$orderId,$orderId,'Бонусы за завершённый заказ',loyalty_expiry_date($cfg),$adminUserId,['eligible_rub'=>$preview['eligible_rub']]);
        $pdo->prepare('UPDATE orders SET bonus_earned=? WHERE id=?')->execute([$result['amount'],$orderId]);
        return ['changed'=>true,'reason'=>'earned','points'=>$result['amount'],'balance'=>$result['balance'],'preview'=>$preview];
    }
    if($to==='cancelled'&&$from==='completed'){
        $q=$pdo->prepare("SELECT * FROM loyalty_transactions WHERE customer_id=? AND kind='order_earn' AND source_type='order' AND source_id=? AND status='active' ORDER BY id DESC LIMIT 1");
        $q->execute([$customerId,(string)$orderId]);$original=$q->fetch();if(!$original)return ['changed'=>false,'reason'=>'no_earn_to_reverse'];
        $result=loyalty_reverse_transaction($pdo,$original,'order_cancel',(string)$orderId,$adminUserId);
        $pdo->prepare('UPDATE orders SET bonus_earned=0 WHERE id=?')->execute([$orderId]);
        return ['changed'=>true,'reason'=>'reversed','points'=>$result['amount'],'balance'=>$result['balance']??null];
    }
    return ['changed'=>false,'reason'=>'status_not_applicable'];
}
