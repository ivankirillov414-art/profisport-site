<?php
declare(strict_types=1);

function ensure_loyalty_center_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_discounts (
        customer_id BIGINT UNSIGNED PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        percent_bp INT UNSIGNED NOT NULL DEFAULT 0,
        group_key VARCHAR(80) NULL,
        note VARCHAR(255) NULL,
        admin_user_id INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_customer_discount_enabled(enabled,percent_bp),
        INDEX idx_customer_discount_group(group_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS loyalty_config_versions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        config_json LONGTEXT NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        published_by_admin_user_id INT UNSIGNED NULL,
        published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_loyalty_version_published(published_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $s=$pdo->prepare('INSERT IGNORE INTO site_settings(setting_key,setting_value) VALUES(?,?)');
    $s->execute(['loyalty_draft_config_v2',json_encode(loyalty_config($pdo),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
}

function loyalty_center_group_key(string $value): string {
    $value=mb_strtolower(trim($value),'UTF-8');
    $value=preg_replace('/[^a-z0-9а-яё_-]+/u','-',$value)??'';
    $value=trim($value,'-_');
    return mb_substr($value,0,80);
}

function loyalty_center_sanitize_groups(mixed $raw): array {
    if(!is_array($raw))return [];
    $out=[];$seen=[];
    foreach(array_slice($raw,0,40) as $index=>$row){
        if(!is_array($row))continue;
        $name=trim((string)($row['name']??''));if($name==='')continue;
        $key=loyalty_center_group_key((string)($row['key']??$name));if($key==='')$key='group-'.($index+1);
        if(isset($seen[$key]))$key.='-'.($index+1);$seen[$key]=true;
        $percent=(int)round((float)($row['percent_bp']??0));$percent=max(0,min(9000,$percent));
        $categories=[];
        foreach(is_array($row['categories']??null)?$row['categories']:[] as $category){
            $category=trim((string)$category);
            if($category!==''&&mb_strlen($category)<=500)$categories[]=$category;
        }
        $out[]=['key'=>$key,'name'=>mb_substr($name,0,120),'percent_bp'=>$percent,'categories'=>array_values(array_unique($categories))];
    }
    return $out;
}

function loyalty_center_sanitize_config(array $input): array {
    $cfg=loyalty_sanitize_config_input($input);
    $cfg['enabled']=($input['enabled']??false)===true;
    $cfg['discounts_enabled']=($input['discounts_enabled']??false)===true;
    $stack=(string)($input['discount_stack_rule']??'max');
    $cfg['discount_stack_rule']=in_array($stack,['max','sum','personal_overrides'],true)?$stack:'max';
    $basis=(string)($input['earn_basis']??'after_discounts');
    $cfg['earn_basis']=in_array($basis,['after_discounts','before_discounts'],true)?$basis:'after_discounts';
    $cfg['discount_groups']=loyalty_center_sanitize_groups($input['discount_groups']??[]);
    $cfg['activation_at']=null;
    return $cfg;
}

function loyalty_center_draft(PDO $pdo): array {
    $s=$pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key=? LIMIT 1');$s->execute(['loyalty_draft_config_v2']);
    $raw=$s->fetchColumn();$decoded=is_string($raw)?json_decode($raw,true):null;
    return is_array($decoded)?array_merge(loyalty_default_config(),$decoded):loyalty_config($pdo);
}

function loyalty_center_store_draft(PDO $pdo,array $input): array {
    $cfg=loyalty_center_sanitize_config($input);
    $s=$pdo->prepare('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $s->execute(['loyalty_draft_config_v2',json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    return $cfg;
}

function loyalty_center_verify_admin_password(PDO $pdo,int $adminId,string $password): void {
    auth_rate_check($pdo,'loyalty_publish',(string)$adminId,5,900);
    $s=$pdo->prepare('SELECT password_hash FROM admin_users WHERE id=? AND is_active=1 LIMIT 1');$s->execute([$adminId]);$hash=(string)($s->fetchColumn()?:'');
    if($password===''||$hash===''||!password_verify($password,$hash)){
        auth_rate_failure($pdo,'loyalty_publish',(string)$adminId,5,900,900);
        throw new DomainException('current_password_invalid');
    }
    auth_rate_clear($pdo,'loyalty_publish',(string)$adminId);
}

function loyalty_center_publish(PDO $pdo,array $input,int $adminId,string $password): array {
    loyalty_center_verify_admin_password($pdo,$adminId,$password);
    $cfg=loyalty_center_sanitize_config($input);
    if($cfg['enabled']&&!loyalty_configured($cfg))throw new DomainException('loyalty_not_configured');
    $current=loyalty_config($pdo);
    $cfg['activation_at']=$cfg['enabled']?((bool)($current['enabled']??false)&&!empty($current['activation_at'])?$current['activation_at']:date('Y-m-d H:i:s')):null;
    $json=json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $pdo->beginTransaction();
    try{
        $s=$pdo->prepare('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        $s->execute(['loyalty_config_v1',$json]);$s->execute(['loyalty_draft_config_v2',$json]);
        $v=$pdo->prepare('INSERT INTO loyalty_config_versions(config_json,enabled,published_by_admin_user_id) VALUES(?,?,?)');
        $v->execute([$json,$cfg['enabled']?1:0,$adminId]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return loyalty_program_status($pdo);
}

function loyalty_center_category_group(string $categoryPath,array $cfg): ?array {
    if(!($cfg['discounts_enabled']??false))return null;
    foreach(is_array($cfg['discount_groups']??null)?$cfg['discount_groups']:[] as $group){
        foreach(is_array($group['categories']??null)?$group['categories']:[] as $category){
            if(trim((string)$category)===trim($categoryPath))return $group;
        }
    }
    return null;
}

function loyalty_customer_discount(PDO $pdo,int $customerId): ?array {
    if($customerId<1)return null;
    $s=$pdo->prepare('SELECT enabled,percent_bp,group_key,note,admin_user_id,updated_at FROM customer_discounts WHERE customer_id=? LIMIT 1');$s->execute([$customerId]);$row=$s->fetch();
    if(!$row)return null;
    $row['enabled']=(bool)$row['enabled'];$row['percent_bp']=(int)$row['percent_bp'];return $row;
}

function loyalty_set_customer_discount(PDO $pdo,int $customerId,bool $enabled,int $percentBp,?string $groupKey,?string $note,int $adminId): array {
    if($customerId<1)throw new InvalidArgumentException('bad_customer');
    $percentBp=max(0,min(9000,$percentBp));
    if($enabled&&$percentBp<1)throw new InvalidArgumentException('bad_discount');
    $groupKey=$groupKey!==null&&trim($groupKey)!==''?loyalty_center_group_key($groupKey):null;
    $s=$pdo->prepare('INSERT INTO customer_discounts(customer_id,enabled,percent_bp,group_key,note,admin_user_id) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),percent_bp=VALUES(percent_bp),group_key=VALUES(group_key),note=VALUES(note),admin_user_id=VALUES(admin_user_id)');
    $s->execute([$customerId,$enabled?1:0,$percentBp,$groupKey,$note!==null?mb_substr(trim($note),0,255):null,$adminId]);
    return loyalty_customer_discount($pdo,$customerId)??['enabled'=>false,'percent_bp'=>0,'group_key'=>null];
}

function loyalty_effective_discount(PDO $pdo,string $categoryPath,?int $customerId,array $cfg): array {
    $group=loyalty_center_category_group($categoryPath,$cfg);
    $categoryBp=$group?max(0,min(9000,(int)($group['percent_bp']??0))):0;
    $personal=($customerId??0)>0?loyalty_customer_discount($pdo,(int)$customerId):null;
    $personalBp=0;
    if($personal&&$personal['enabled']){
        $scope=(string)($personal['group_key']??'');
        if($scope===''||($group&&$scope===(string)($group['key']??'')))$personalBp=max(0,min(9000,(int)$personal['percent_bp']));
    }
    $rule=(string)($cfg['discount_stack_rule']??'max');
    $effective=$rule==='sum'?min(9000,$categoryBp+$personalBp):($rule==='personal_overrides'&&$personalBp>0?$personalBp:max($categoryBp,$personalBp));
    return [
        'percent_bp'=>$effective,
        'category_percent_bp'=>$categoryBp,
        'personal_percent_bp'=>$personalBp,
        'group_key'=>$group['key']??null,
        'group_name'=>$group['name']??null,
        'rule'=>$rule,
    ];
}

function loyalty_discount_order_items(PDO $pdo,array $calculated,?int $customerId): array {
    $cfg=loyalty_config($pdo);$subtotal=0;$total=0;$discountTotal=0;$items=[];
    foreach($calculated['items'] as $item){
        $base=(int)$item['price'];$qty=(int)$item['qty'];$baseLine=$base*$qty;
        $discount=loyalty_effective_discount($pdo,(string)($item['category_path']??''),$customerId,$cfg);
        $bp=(int)$discount['percent_bp'];$price=max(0,(int)round($base*(10000-$bp)/10000));$line=$price*$qty;
        $items[]=array_merge($item,[
            'base_price'=>$base,'base_line'=>$baseLine,'price'=>$price,'line'=>$line,
            'discount_percent_bp'=>$bp,'discount_rub'=>$baseLine-$line,'discount_group_key'=>$discount['group_key'],
        ]);
        $subtotal+=$baseLine;$total+=$line;$discountTotal+=($baseLine-$line);
    }
    return ['items'=>$items,'subtotal'=>$subtotal,'discount'=>$discountTotal,'total'=>$total];
}

function loyalty_center_preview_products(PDO $pdo,int $limit=10): array {
    $limit=max(1,min(10,$limit));
    $s=$pdo->query("SELECT id,name,price_rub,category_path,stock_qty FROM products WHERE is_active=1 AND COALESCE(stock_qty,0)>0 AND COALESCE(price_rub,0)>0 ORDER BY updated_at DESC,id DESC LIMIT ".$limit);
    return $s->fetchAll();
}

function loyalty_center_category_options(PDO $pdo): array {
    $s=$pdo->query("SELECT category_path,COUNT(*) product_count FROM products WHERE is_active=1 AND category_path IS NOT NULL AND TRIM(category_path)<>'' GROUP BY category_path ORDER BY category_path LIMIT 1000");
    $out=[];foreach($s->fetchAll() as $row){$path=trim((string)$row['category_path']);if($path!=='')$out[]=['path'=>$path,'name'=>$path,'count'=>(int)$row['product_count']];}
    return $out;
}
