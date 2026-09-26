<?php
declare(strict_types=1);

function loyalty_default_config(): array {
    return [
        'enabled'=>false,
        'earn_enabled'=>false,
        'redeem_enabled'=>false,
        'expiration_enabled'=>false,
        'review_bonus_enabled'=>false,
        'category_exclusions_enabled'=>false,
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
        'remaining_amount'=>'INT NULL',
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
    $pdo->exec("UPDATE loyalty_transactions SET remaining_amount=CASE WHEN amount>0 THEN amount ELSE 0 END WHERE remaining_amount IS NULL");
    $s=$pdo->prepare('INSERT IGNORE INTO site_settings(setting_key,setting_value) VALUES(?,?)');
    $s->execute(['loyalty_config_v1',json_encode(loyalty_default_config(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
}

function loyalty_config(PDO $pdo): array {
    $base=loyalty_default_config();
    $s=$pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key=? LIMIT 1');$s->execute(['loyalty_config_v1']);
    $raw=$s->fetchColumn();$decoded=is_string($raw)?json_decode($raw,true):null;
    if(!is_array($decoded))return $base;
    $cfg=array_merge($base,array_intersect_key($decoded,$base));
    foreach(['enabled','earn_enabled','redeem_enabled','expiration_enabled','review_bonus_enabled','category_exclusions_enabled'] as $key)$cfg[$key]=($cfg[$key]??false)===true;
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

function loyalty_sanitize_config_input(array $input): array {
    $cfg=loyalty_default_config();
    foreach(['earn_enabled','redeem_enabled','expiration_enabled','review_bonus_enabled','category_exclusions_enabled'] as $key)$cfg[$key]=($input[$key]??false)===true;
    $ranges=[
        'earn_percent_bp'=>[0,5000],
        'max_redeem_percent_bp'=>[0,10000],
        'point_value_kopeks'=>[1,100000],
        'expiration_days'=>[1,3650],
        'min_order_rub'=>[0,10000000],
        'review_bonus'=>[0,1000000],
    ];
    foreach($ranges as $key=>[$min,$max]){
        $value=$input[$key]??null;
        if($value===null||$value===''){$cfg[$key]=null;continue;}
        if(!is_numeric($value))throw new InvalidArgumentException('invalid_'.$key);
        $value=(int)round((float)$value);
        if($value<$min||$value>$max)throw new InvalidArgumentException('invalid_'.$key);
        $cfg[$key]=$value;
    }
    $raw=is_array($input['excluded_category_prefixes']??null)?$input['excluded_category_prefixes']:[];
    $cfg['excluded_category_prefixes']=array_values(array_slice(array_unique(array_filter(array_map(function($x){
        $v=trim((string)$x);return mb_substr($v,0,250);
    },$raw),fn($x)=>$x!=='')),0,100));
    // Draft calculator is deliberately non-live until checkout redemption is completed.
    $cfg['enabled']=false;$cfg['activation_at']=null;
    return $cfg;
}

function loyalty_save_draft(PDO $pdo,array $input): array {
    $cfg=loyalty_sanitize_config_input($input);
    $s=$pdo->prepare('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $s->execute(['loyalty_config_v1',json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    return $cfg;
}

function loyalty_configured(array $cfg): bool {
    $anyFeature=$cfg['earn_enabled']||$cfg['redeem_enabled']||$cfg['review_bonus_enabled'];
    if(!$anyFeature)return false;
    if(!is_int($cfg['point_value_kopeks'])||$cfg['point_value_kopeks']<1||$cfg['point_value_kopeks']>100000)return false;
    if($cfg['earn_enabled']){
        if(!is_int($cfg['earn_percent_bp'])||$cfg['earn_percent_bp']<0||$cfg['earn_percent_bp']>5000)return false;
        if(!is_int($cfg['min_order_rub'])||$cfg['min_order_rub']<0||$cfg['min_order_rub']>10000000)return false;
    }
    if($cfg['redeem_enabled']&&(!is_int($cfg['max_redeem_percent_bp'])||$cfg['max_redeem_percent_bp']<0||$cfg['max_redeem_percent_bp']>10000))return false;
    if($cfg['expiration_enabled']&&(!is_int($cfg['expiration_days'])||$cfg['expiration_days']<1||$cfg['expiration_days']>3650))return false;
    if($cfg['review_bonus_enabled']&&(!is_int($cfg['review_bonus'])||$cfg['review_bonus']<0||$cfg['review_bonus']>1000000))return false;
    return true;
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
            $dup=$pdo->prepare("SELECT id,amount FROM loyalty_transactions WHERE customer_id=? AND kind=? AND source_type=? AND source_id=? ORDER BY id DESC LIMIT 1");
            $dup->execute([$customerId,$kind,$sourceType,$sourceId]);$existing=$dup->fetch();
            if($existing){if($ownsTransaction)$pdo->commit();return ['transaction_id'=>(int)$existing['id'],'amount'=>(int)$existing['amount'],'balance'=>$current,'duplicate'=>true];}
        }
        $s=$pdo->prepare('INSERT INTO loyalty_transactions(customer_id,amount,kind,source_type,source_id,note,order_id,expires_at,reversal_of_id,status,admin_user_id,metadata,remaining_amount) VALUES(?,?,?,?,?,?,?,?,NULL,\'active\',?,?,?)');
        $s->execute([$customerId,$amount,$kind,$sourceType,$sourceId,$note,$orderId,$expiresAt,$adminUserId,$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,$amount>0?$amount:0]);
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
        $result=loyalty_post($pdo,$customerId,$actual,'manual','admin',null,null,$note?:'Ручная корректировка',null,$adminUserId,['requested_amount'=>$requestedAmount]);
        if($actual<0)loyalty_reconcile_customer_lots($pdo,$customerId);
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
    if(!loyalty_configured($cfg)||!$cfg['earn_enabled'])return ['eligible_rub'=>0,'points'=>0];
    $eligible=0;
    foreach($items as $item){
        if($cfg['category_exclusions_enabled']&&loyalty_category_excluded((string)($item['category_path']??''),$cfg))continue;
        $eligible+=max(0,(int)($item['line_total_rub']??0));
    }
    if($eligible<(int)$cfg['min_order_rub'])return ['eligible_rub'=>$eligible,'points'=>0];
    $numerator=$eligible*(int)$cfg['earn_percent_bp']*100;
    $denominator=10000*(int)$cfg['point_value_kopeks'];
    $points=$denominator>0?intdiv($numerator,$denominator):0;
    return ['eligible_rub'=>$eligible,'points'=>max(0,$points)];
}

function loyalty_redemption_preview(int $orderRub,int $balancePoints,array $cfg): array {
    $orderRub=max(0,$orderRub);$balancePoints=max(0,$balancePoints);
    if(!loyalty_configured($cfg)||!$cfg['redeem_enabled'])return ['max_points'=>0,'discount_rub'=>0,'payable_rub'=>$orderRub];
    $pointKopeks=(int)$cfg['point_value_kopeks'];$maxShareKopeks=intdiv($orderRub*100*(int)$cfg['max_redeem_percent_bp'],10000);
    $balanceValueKopeks=$balancePoints*$pointKopeks;$discountKopeks=min($maxShareKopeks,$balanceValueKopeks,$orderRub*100);
    $maxPoints=$pointKopeks>0?intdiv($discountKopeks,$pointKopeks):0;
    $discountRub=intdiv($maxPoints*$pointKopeks,100);
    return ['max_points'=>$maxPoints,'discount_rub'=>$discountRub,'payable_rub'=>max(0,$orderRub-$discountRub)];
}

function loyalty_expiry_date(array $cfg): ?string {
    if(!$cfg['expiration_enabled']||!is_int($cfg['expiration_days'])||$cfg['expiration_days']<1)return null;
    return (new DateTimeImmutable())->modify('+'.$cfg['expiration_days'].' days')->format('Y-m-d H:i:s');
}


function loyalty_set_program_enabled(PDO $pdo,bool $enabled,int $adminUserId=0): array {
    $cfg=loyalty_config($pdo);
    if($enabled&&!loyalty_configured($cfg))throw new DomainException('loyalty_not_configured');
    $cfg['enabled']=$enabled;
    $cfg['activation_at']=$enabled?($cfg['activation_at']?:date('Y-m-d H:i:s')):null;
    $s=$pdo->prepare('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $s->execute(['loyalty_config_v1',json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    return loyalty_program_status($pdo);
}

function loyalty_decode_metadata(mixed $raw): array {
    if(!is_string($raw)||$raw==='')return [];
    $x=json_decode($raw,true);return is_array($x)?$x:[];
}

function loyalty_reconcile_customer_lots(PDO $pdo,int $customerId): array {
    if($customerId<1)return ['balance'=>0,'lot_total'=>0];
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT bonus_balance FROM customers WHERE id=? FOR UPDATE');$q->execute([$customerId]);$raw=$q->fetchColumn();
        if($raw===false)throw new RuntimeException('Customer not found');
        $balance=max(0,(int)$raw);
        $lots=$pdo->prepare("SELECT id,remaining_amount,status FROM loyalty_transactions WHERE customer_id=? AND amount>0 ORDER BY id FOR UPDATE");
        $lots->execute([$customerId]);$rows=$lots->fetchAll();$lotTotal=0;
        foreach($rows as $row)if((string)$row['status']==='active')$lotTotal+=max(0,(int)($row['remaining_amount']??0));
        if($lotTotal>$balance){
            $cut=$lotTotal-$balance;
            foreach($rows as $row){
                if($cut<=0)break;
                if((string)$row['status']!=='active')continue;
                $remaining=max(0,(int)($row['remaining_amount']??0));if($remaining<1)continue;
                $used=min($remaining,$cut);$next=$remaining-$used;
                $u=$pdo->prepare("UPDATE loyalty_transactions SET remaining_amount=?,status=? WHERE id=?");
                $u->execute([$next,$next>0?'active':'spent',(int)$row['id']]);$cut-=$used;$lotTotal-=$used;
            }
        }elseif($lotTotal<$balance){
            $diff=$balance-$lotTotal;
            $s=$pdo->prepare("INSERT INTO loyalty_transactions(customer_id,amount,kind,source_type,source_id,note,status,metadata,remaining_amount) VALUES(?,?,'legacy_balance','migration',?,'Перенос существующего бонусного баланса','active',?,?)");
            $source='customer:'.$customerId.':'.bin2hex(random_bytes(6));
            $s->execute([$customerId,$diff,$source,json_encode(['reconciled'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$diff]);$lotTotal+=$diff;
        }
        if($owns)$pdo->commit();
        return ['balance'=>$balance,'lot_total'=>$lotTotal];
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function loyalty_expire_customer(PDO $pdo,int $customerId): array {
    if($customerId<1)return ['expired_points'=>0,'balance'=>0];
    $status=loyalty_program_status($pdo);$cfg=$status['config'];
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $reconciled=loyalty_reconcile_customer_lots($pdo,$customerId);$balance=(int)$reconciled['balance'];
        if(!$status['enabled']||!$cfg['expiration_enabled']){if($owns)$pdo->commit();return ['expired_points'=>0,'balance'=>$balance];}
        $s=$pdo->prepare("SELECT id,remaining_amount,expires_at FROM loyalty_transactions WHERE customer_id=? AND amount>0 AND status='active' AND remaining_amount>0 AND expires_at IS NOT NULL AND expires_at<=NOW() ORDER BY id FOR UPDATE");
        $s->execute([$customerId]);$expired=0;
        foreach($s->fetchAll() as $row){
            $amount=max(0,(int)$row['remaining_amount']);if($amount<1)continue;
            $creditId=(int)$row['id'];
            $dup=$pdo->prepare("SELECT id FROM loyalty_transactions WHERE customer_id=? AND kind='expiry' AND source_type='expiry' AND source_id=? LIMIT 1");$dup->execute([$customerId,(string)$creditId]);
            if(!$dup->fetchColumn()){
                $meta=json_encode(['credit_transaction_id'=>$creditId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $i=$pdo->prepare("INSERT INTO loyalty_transactions(customer_id,amount,kind,source_type,source_id,note,status,metadata,remaining_amount) VALUES(?,?,'expiry','expiry',?,'Сгорание бонусов по сроку действия','active',?,0)");
                $i->execute([$customerId,-$amount,(string)$creditId,$meta]);
            }
            $pdo->prepare("UPDATE loyalty_transactions SET remaining_amount=0,status='expired' WHERE id=?")->execute([$creditId]);$expired+=$amount;
        }
        if($expired>0){$balance=max(0,$balance-$expired);$pdo->prepare('UPDATE customers SET bonus_balance=? WHERE id=?')->execute([$balance,$customerId]);}
        if($owns)$pdo->commit();
        return ['expired_points'=>$expired,'balance'=>$balance];
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function loyalty_available_balance(PDO $pdo,int $customerId): int {
    return (int)loyalty_expire_customer($pdo,$customerId)['balance'];
}

function loyalty_consume_fifo(PDO $pdo,int $customerId,int $points): array {
    if($points<1)return [];
    $balance=loyalty_available_balance($pdo,$customerId);
    if($balance<$points)throw new DomainException('insufficient_bonus_balance');
    $s=$pdo->prepare("SELECT id,remaining_amount,expires_at FROM loyalty_transactions WHERE customer_id=? AND amount>0 AND status='active' AND remaining_amount>0 ORDER BY created_at,id FOR UPDATE");
    $s->execute([$customerId]);$need=$points;$alloc=[];
    foreach($s->fetchAll() as $row){
        if($need<=0)break;$remaining=max(0,(int)$row['remaining_amount']);if($remaining<1)continue;
        $take=min($remaining,$need);$next=$remaining-$take;
        $pdo->prepare('UPDATE loyalty_transactions SET remaining_amount=?,status=? WHERE id=?')->execute([$next,$next>0?'active':'spent',(int)$row['id']]);
        $alloc[]=['transaction_id'=>(int)$row['id'],'amount'=>$take,'expires_at'=>$row['expires_at']?:null];$need-=$take;
    }
    if($need>0)throw new RuntimeException('loyalty_lot_mismatch');
    return $alloc;
}

function loyalty_reserve_order_redemption(PDO $pdo,int $customerId,int $orderId,int $orderRub,int $requestedPoints): array {
    if($requestedPoints<1)return ['points'=>0,'discount_rub'=>0,'payable_rub'=>$orderRub,'balance'=>loyalty_available_balance($pdo,$customerId)];
    $program=loyalty_program_status($pdo);$cfg=$program['config'];
    if(!$program['enabled']||!$cfg['redeem_enabled'])throw new DomainException('loyalty_unavailable');
    $dup=$pdo->prepare("SELECT id,amount,metadata FROM loyalty_transactions WHERE customer_id=? AND kind='redeem_reserve' AND source_type='order' AND source_id=? LIMIT 1");
    $dup->execute([$customerId,(string)$orderId]);$existing=$dup->fetch();
    if($existing){
        $meta=loyalty_decode_metadata($existing['metadata']??null);
        return ['points'=>abs((int)$existing['amount']),'discount_rub'=>(float)($meta['discount_rub']??0),'payable_rub'=>(float)($meta['payable_rub']??$orderRub),'balance'=>loyalty_available_balance($pdo,$customerId),'duplicate'=>true];
    }
    $balance=loyalty_available_balance($pdo,$customerId);$preview=loyalty_redemption_preview($orderRub,$balance,$cfg);
    if($requestedPoints>(int)$preview['max_points'])throw new DomainException('bonus_spend_exceeds_limit');
    $allocations=loyalty_consume_fifo($pdo,$customerId,$requestedPoints);
    $discountKopeks=$requestedPoints*(int)$cfg['point_value_kopeks'];$discountRub=round($discountKopeks/100,2);$payable=max(0,$orderRub-$discountRub);
    $meta=['allocations'=>$allocations,'point_value_kopeks'=>(int)$cfg['point_value_kopeks'],'discount_rub'=>$discountRub,'payable_rub'=>$payable];
    $s=$pdo->prepare("INSERT INTO loyalty_transactions(customer_id,amount,kind,source_type,source_id,note,order_id,status,metadata,remaining_amount) VALUES(?,?,'redeem_reserve','order',?,'Резерв бонусов для заказа',?,'active',?,0)");
    $s->execute([$customerId,-$requestedPoints,(string)$orderId,$orderId,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $balance-=$requestedPoints;$pdo->prepare('UPDATE customers SET bonus_balance=? WHERE id=?')->execute([$balance,$customerId]);
    $pdo->prepare('UPDATE orders SET bonus_spent=?,payable_rub=? WHERE id=?')->execute([$requestedPoints,$payable,$orderId]);
    return ['points'=>$requestedPoints,'discount_rub'=>$discountRub,'payable_rub'=>$payable,'balance'=>$balance,'duplicate'=>false];
}

function loyalty_refund_order_redemption(PDO $pdo,int $orderId,?int $adminUserId=null): array {
    $q=$pdo->prepare("SELECT * FROM loyalty_transactions WHERE order_id=? AND kind='redeem_reserve' AND source_type='order' ORDER BY id DESC LIMIT 1");$q->execute([$orderId]);$reserve=$q->fetch();
    if(!$reserve)return ['refunded'=>0,'reason'=>'no_redemption'];
    $customerId=(int)$reserve['customer_id'];$points=abs((int)$reserve['amount']);$meta=loyalty_decode_metadata($reserve['metadata']??null);
    $alreadyQ=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM loyalty_transactions WHERE customer_id=? AND order_id=? AND kind='redeem_refund'");
    $alreadyQ->execute([$customerId,$orderId]);$already=max(0,(int)$alreadyQ->fetchColumn());
    if($already>=$points)return ['refunded'=>0,'reason'=>'duplicate','balance'=>loyalty_available_balance($pdo,$customerId)];
    $left=$points-$already;$refunded=0;$allocations=is_array($meta['allocations']??null)?$meta['allocations']:[];
    foreach($allocations as $allocation){
        if($left<=0)break;
        $creditId=(int)($allocation['transaction_id']??0);$amount=min($left,max(0,(int)($allocation['amount']??0)));if($amount<1)continue;
        $expires=isset($allocation['expires_at'])&&is_string($allocation['expires_at'])&&$allocation['expires_at']!==''?$allocation['expires_at']:null;
        $result=loyalty_post($pdo,$customerId,$amount,'redeem_refund','order_refund',$orderId.':'.$creditId,$orderId,'Возврат бонусов после отмены заказа',$expires,$adminUserId,['reserve_transaction_id'=>(int)$reserve['id'],'credit_transaction_id'=>$creditId,'restored_expiry'=>true]);
        if(!$result['duplicate']){$refunded+=(int)$result['amount'];$left-=(int)$result['amount'];}
        else $left=max(0,$left-$amount);
    }
    if($left>0){
        $cfg=loyalty_config($pdo);
        $result=loyalty_post($pdo,$customerId,$left,'redeem_refund','order_refund',$orderId.':fallback',$orderId,'Возврат бонусов после отмены заказа',loyalty_expiry_date($cfg),$adminUserId,['reserve_transaction_id'=>(int)$reserve['id'],'legacy_fallback'=>true]);
        if(!$result['duplicate'])$refunded+=(int)$result['amount'];
    }
    if(loyalty_program_enabled($pdo))loyalty_expire_customer($pdo,$customerId);
    $order=$pdo->prepare('SELECT total_rub FROM orders WHERE id=? LIMIT 1');$order->execute([$orderId]);$total=(float)$order->fetchColumn();
    $pdo->prepare('UPDATE orders SET bonus_spent=0,payable_rub=? WHERE id=?')->execute([$total,$orderId]);
    return ['refunded'=>$refunded,'reason'=>$refunded>0?'refunded':'duplicate','balance'=>loyalty_available_balance($pdo,$customerId)];
}
function loyalty_award_review(PDO $pdo,int $reviewId,int $customerId): array {
    $status=loyalty_program_status($pdo);$cfg=$status['config'];
    if(!$status['enabled'])return ['awarded'=>0,'reason'=>$status['configured']?'program_disabled':'program_unconfigured'];
    if(!$cfg['review_bonus_enabled'])return ['awarded'=>0,'reason'=>'review_bonus_disabled'];
    $amount=(int)$cfg['review_bonus'];if($amount<=0)return ['awarded'=>0,'reason'=>'review_bonus_zero'];
    $result=loyalty_post($pdo,$customerId,$amount,'review_bonus','review',(string)$reviewId,null,'Бонус за опубликованный отзыв',loyalty_expiry_date($cfg),null,['review_id'=>$reviewId]);
    $pdo->prepare('UPDATE product_reviews SET bonus_awarded=1 WHERE id=?')->execute([$reviewId]);
    return ['awarded'=>$result['amount'],'reason'=>$result['duplicate']?'duplicate':'awarded','balance'=>$result['balance'],'transaction_id'=>$result['transaction_id']];
}

function loyalty_reverse_transaction(PDO $pdo,array $original,string $sourceType,string $sourceId,?int $adminUserId=null): array {
    $customerId=(int)$original['customer_id'];$amount=(int)$original['amount'];if($amount<=0)return ['amount'=>0,'balance'=>null,'duplicate'=>false];
    $dup=$pdo->prepare("SELECT id,amount FROM loyalty_transactions WHERE customer_id=? AND kind='reversal' AND source_type=? AND source_id=? AND reversal_of_id=? LIMIT 1");
    $dup->execute([$customerId,$sourceType,$sourceId,(int)$original['id']]);$existing=$dup->fetch();
    if($existing)return ['amount'=>(int)$existing['amount'],'duplicate'=>true,'transaction_id'=>(int)$existing['id'],'balance'=>null];
    $available=loyalty_available_balance($pdo,$customerId);$revoke=min($amount,max(0,$available));
    if($revoke<=0)return ['amount'=>0,'balance'=>$available,'duplicate'=>false,'reason'=>'no_available_points'];
    $result=loyalty_post($pdo,$customerId,-$revoke,'reversal',$sourceType,$sourceId,(int)($original['order_id']??0)?:null,'Отмена ранее начисленных бонусов',null,$adminUserId,['reversal_of_id'=>(int)$original['id'],'requested_amount'=>$amount]);
    loyalty_reconcile_customer_lots($pdo,$customerId);
    $pdo->prepare('UPDATE loyalty_transactions SET reversal_of_id=? WHERE id=?')->execute([(int)$original['id'],$result['transaction_id']]);
    return $result;
}

function loyalty_handle_order_status_change(PDO $pdo,int $orderId,string $from,string $to,?int $adminUserId=null): array {
    $status=loyalty_program_status($pdo);$cfg=$status['config'];
    $o=$pdo->prepare('SELECT id,customer_id,total_rub,bonus_spent,bonus_earned FROM orders WHERE id=? LIMIT 1');$o->execute([$orderId]);$order=$o->fetch();
    if(!$order||(int)($order['customer_id']??0)<1)return ['changed'=>false,'reason'=>'no_customer'];
    $customerId=(int)$order['customer_id'];
    if($to==='cancelled'&&$from!=='cancelled'){
        $refund=loyalty_refund_order_redemption($pdo,$orderId,$adminUserId);$reversal=null;
        if($from==='completed'){
            $q=$pdo->prepare("SELECT * FROM loyalty_transactions WHERE customer_id=? AND kind='order_earn' AND source_type='order' AND source_id=? ORDER BY id DESC LIMIT 1");
            $q->execute([$customerId,(string)$orderId]);$original=$q->fetch();
            if($original){$reversal=loyalty_reverse_transaction($pdo,$original,'order_cancel',(string)$orderId,$adminUserId);$pdo->prepare('UPDATE orders SET bonus_earned=0 WHERE id=?')->execute([$orderId]);}
        }
        return ['changed'=>(int)($refund['refunded']??0)>0||$reversal!==null,'reason'=>'cancelled','refund'=>$refund,'reversal'=>$reversal];
    }
    if(!$status['enabled'])return ['changed'=>false,'reason'=>$status['configured']?'program_disabled':'program_unconfigured'];
    if($to==='completed'&&$from!=='completed'){
        if(!$cfg['earn_enabled'])return ['changed'=>false,'reason'=>'earn_disabled'];
        $items=$pdo->prepare('SELECT line_total_rub,category_path FROM order_items WHERE order_id=? ORDER BY id');$items->execute([$orderId]);
        $preview=loyalty_order_earn_preview($items->fetchAll(),$cfg);$points=(int)$preview['points'];
        if($points<=0)return ['changed'=>false,'reason'=>'zero_earn','preview'=>$preview];
        $result=loyalty_post($pdo,$customerId,$points,'order_earn','order',(string)$orderId,$orderId,'Бонусы за завершённый заказ',loyalty_expiry_date($cfg),$adminUserId,['eligible_rub'=>$preview['eligible_rub']]);
        $pdo->prepare('UPDATE orders SET bonus_earned=? WHERE id=?')->execute([$result['amount'],$orderId]);
        return ['changed'=>true,'reason'=>'earned','points'=>$result['amount'],'balance'=>$result['balance'],'preview'=>$preview];
    }
    return ['changed'=>false,'reason'=>'status_not_applicable'];
}
