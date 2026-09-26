<?php
declare(strict_types=1);

function vehicle_passport_hotspots(): array {
    return [
        ['key'=>'saddle','label'=>'Седло и подседельный штырь','x'=>32.6,'y'=>24.8],
        ['key'=>'cockpit','label'=>'Руль и рулевая','x'=>65.2,'y'=>23.5],
        ['key'=>'fork','label'=>'Вилка','x'=>72.3,'y'=>49.6],
        ['key'=>'front_brake','label'=>'Передний тормоз','x'=>77.8,'y'=>65.9],
        ['key'=>'rear_brake','label'=>'Задний тормоз','x'=>15.2,'y'=>62.5],
        ['key'=>'front_tire','label'=>'Передняя покрышка','x'=>92.0,'y'=>51.0],
        ['key'=>'rear_tire','label'=>'Задняя покрышка','x'=>6.8,'y'=>51.0],
        ['key'=>'wheels','label'=>'Обода и спицы','x'=>84.0,'y'=>82.0],
        ['key'=>'hubs','label'=>'Втулки','x'=>18.1,'y'=>68.6],
        ['key'=>'chain','label'=>'Цепь','x'=>32.6,'y'=>66.4],
        ['key'=>'drivetrain','label'=>'Кассета и переключатель','x'=>18.1,'y'=>76.3],
        ['key'=>'cranks','label'=>'Каретка и шатуны','x'=>42.0,'y'=>70.4],
        ['key'=>'pedals','label'=>'Педали','x'=>51.4,'y'=>72.4],
        ['key'=>'frame','label'=>'Рама','x'=>50.3,'y'=>42.0],
        ['key'=>'rear_shock','label'=>'Задний амортизатор','x'=>44.9,'y'=>57.5],
    ];
}

function ensure_vehicle_passport_schema(PDO $pdo): void {
    $cols=table_columns($pdo,'customer_vehicles');
    $defs=[
        'serial_number'=>'VARCHAR(180) NULL',
        'odometer_km'=>'DECIMAL(12,1) NULL',
        'odometer_updated_at'=>'DATETIME NULL',
        'spec_snapshot'=>'LONGTEXT NULL',
    ];
    foreach($defs as $name=>$def)if(!isset($cols[$name]))$pdo->exec("ALTER TABLE customer_vehicles ADD COLUMN `$name` $def");

    $pdo->exec("CREATE TABLE IF NOT EXISTS vehicle_components (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        vehicle_id BIGINT UNSIGNED NOT NULL,
        component_key VARCHAR(80) NOT NULL,
        hotspot_key VARCHAR(80) NOT NULL,
        label VARCHAR(180) NOT NULL,
        manufacturer VARCHAR(180) NULL,
        model VARCHAR(500) NULL,
        installed_product_id BIGINT UNSIGNED NULL,
        compatible_product_id BIGINT UNSIGNED NULL,
        source_type VARCHAR(40) NOT NULL DEFAULT 'manual',
        source_url VARCHAR(1200) NULL,
        source_note VARCHAR(500) NULL,
        source_verified_at DATETIME NULL,
        wear_mode VARCHAR(30) NOT NULL DEFAULT 'inspection',
        baseline_life_value DECIMAL(12,3) NULL,
        baseline_life_unit VARCHAR(30) NULL,
        baseline_measurement DECIMAL(12,4) NULL,
        replacement_threshold DECIMAL(12,4) NULL,
        measurement_unit VARCHAR(40) NULL,
        measurement_direction VARCHAR(12) NULL,
        current_measurement DECIMAL(12,4) NULL,
        current_measurement_at DATETIME NULL,
        installed_at DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY idx_vehicle_component_key(vehicle_id,component_key),
        INDEX idx_vehicle_component_vehicle(vehicle_id,is_active),
        INDEX idx_vehicle_component_hotspot(vehicle_id,hotspot_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS vehicle_component_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        component_id BIGINT UNSIGNED NOT NULL,
        event_type VARCHAR(30) NOT NULL,
        event_at DATETIME NOT NULL,
        odometer_km DECIMAL(12,1) NULL,
        measurement_value DECIMAL(12,4) NULL,
        measurement_unit VARCHAR(40) NULL,
        include_learning TINYINT(1) NOT NULL DEFAULT 1,
        note VARCHAR(1000) NULL,
        admin_user_id INT UNSIGNED NULL,
        source_order_id BIGINT UNSIGNED NULL,
        source_product_id BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY idx_component_purchase_event(component_id,event_type,source_order_id,source_product_id),
        INDEX idx_component_events_component(component_id,event_at,id),
        INDEX idx_component_events_type(component_id,event_type,event_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $eventCols=table_columns($pdo,'vehicle_component_events');
    $eventDefs=['source_order_id'=>'BIGINT UNSIGNED NULL','source_product_id'=>'BIGINT UNSIGNED NULL'];
    foreach($eventDefs as $name=>$def)if(!isset($eventCols[$name]))$pdo->exec("ALTER TABLE vehicle_component_events ADD COLUMN `$name` $def");
    try{
        $idx=[];foreach($pdo->query('SHOW INDEX FROM vehicle_component_events') as $row)$idx[(string)$row['Key_name']]=true;
        if(!isset($idx['idx_component_purchase_event']))$pdo->exec('CREATE UNIQUE INDEX idx_component_purchase_event ON vehicle_component_events(component_id,event_type,source_order_id,source_product_id)');
    }catch(Throwable $e){error_log('vehicle_passport_event_index_migration_failed: '.$e->getMessage());}
}

function vehicle_passport_component_templates(): array {
    return [
        'brake_pads'=>['hotspot'=>'front_brake','label'=>'Тормозные колодки','patterns'=>['тормозные колодки','колодки тормозные','колодки']],
        'brakes'=>['hotspot'=>'front_brake','label'=>'Тормозная система','patterns'=>['тормоза','тормозная система','тормоз передний','тормоз задний']],
        'chain'=>['hotspot'=>'chain','label'=>'Цепь','patterns'=>['цепь']],
        'cassette'=>['hotspot'=>'drivetrain','label'=>'Кассета / трещотка','patterns'=>['кассета','трещотка']],
        'rear_derailleur'=>['hotspot'=>'drivetrain','label'=>'Задний переключатель','patterns'=>['задний переключатель','переключатель задний']],
        'front_derailleur'=>['hotspot'=>'drivetrain','label'=>'Передний переключатель','patterns'=>['передний переключатель','переключатель передний']],
        'tires'=>['hotspot'=>'front_tire','label'=>'Покрышки','patterns'=>['покрышки','покрышка','шины','шина']],
        'fork'=>['hotspot'=>'fork','label'=>'Вилка','patterns'=>['вилка']],
        'rear_shock'=>['hotspot'=>'rear_shock','label'=>'Задний амортизатор','patterns'=>['задний амортизатор','амортизатор задний']],
        'rims'=>['hotspot'=>'wheels','label'=>'Обода','patterns'=>['обода','обод']],
        'hubs'=>['hotspot'=>'hubs','label'=>'Втулки','patterns'=>['втулки','втулка передняя','втулка задняя']],
        'bottom_bracket'=>['hotspot'=>'cranks','label'=>'Каретка','patterns'=>['каретка']],
        'cranks'=>['hotspot'=>'cranks','label'=>'Шатуны','patterns'=>['шатуны','система шатунов']],
        'pedals'=>['hotspot'=>'pedals','label'=>'Педали','patterns'=>['педали']],
        'handlebar'=>['hotspot'=>'cockpit','label'=>'Руль','patterns'=>['руль']],
        'saddle'=>['hotspot'=>'saddle','label'=>'Седло','patterns'=>['седло']],
        'frame'=>['hotspot'=>'frame','label'=>'Рама','patterns'=>['рама']],
    ];
}

function vehicle_passport_norm(string $value): string {
    $value=mb_strtolower(trim($value),'UTF-8');
    return preg_replace('/\s+/u',' ',$value)??$value;
}

function vehicle_passport_seed_specs(PDO $pdo,int $vehicleId,array $specs,string $sourceNote='Комплектация из карточки товара 1С',?string $installedAt=null): int {
    if($vehicleId<1||!$specs)return 0;
    $templates=vehicle_passport_component_templates();$count=0;
    $insert=$pdo->prepare("INSERT IGNORE INTO vehicle_components(vehicle_id,component_key,hotspot_key,label,model,source_type,source_note,wear_mode,installed_at) VALUES(?,?,?,?,?,'1c_spec',?,'inspection',?)");
    $installedEvent=$pdo->prepare("INSERT IGNORE INTO vehicle_component_events(component_id,event_type,event_at,include_learning,note) VALUES(?,'installed',?,0,'Начальная установка по покупке техники')");
    foreach($specs as $key=>$value){
        if(is_array($value)||is_object($value))continue;
        $text=trim((string)$value);if($text==='')continue;$nk=vehicle_passport_norm((string)$key);
        foreach($templates as $componentKey=>$tpl){
            $match=false;foreach($tpl['patterns'] as $pattern)if($nk===vehicle_passport_norm($pattern)||str_contains($nk,vehicle_passport_norm($pattern))){$match=true;break;}
            if(!$match)continue;
            $insert->execute([$vehicleId,$componentKey,$tpl['hotspot'],$tpl['label'],mb_substr($text,0,500),$sourceNote,$installedAt]);
            if($insert->rowCount()>0){$id=(int)$pdo->lastInsertId();if($id>0&&$installedAt)$installedEvent->execute([$id,$installedAt]);$count++;}break;
        }
    }
    return $count;
}

function vehicle_passport_seed_from_product(PDO $pdo,int $vehicleId,?int $productId,?string $installedAt=null): int {
    if($vehicleId<1||!$productId)return 0;
    $s=$pdo->prepare('SELECT specs FROM products WHERE id=? LIMIT 1');$s->execute([$productId]);$raw=$s->fetchColumn();
    $specs=is_string($raw)?json_decode($raw,true):null;
    return is_array($specs)?vehicle_passport_seed_specs($pdo,$vehicleId,$specs,'Комплектация из карточки товара 1С',$installedAt):0;
}

function vehicle_passport_seed_vehicle(PDO $pdo,int $vehicleId): int {
    $s=$pdo->prepare('SELECT product_id,spec_snapshot,purchase_date,created_at FROM customer_vehicles WHERE id=? LIMIT 1');$s->execute([$vehicleId]);$row=$s->fetch();if(!$row)return 0;
    $installedAt=(string)($row['purchase_date']?:$row['created_at']?:'');$installedAt=$installedAt!==''?$installedAt:null;
    $snapshot=is_string($row['spec_snapshot']??null)?json_decode((string)$row['spec_snapshot'],true):null;
    $count=is_array($snapshot)&&$snapshot
        ?vehicle_passport_seed_specs($pdo,$vehicleId,$snapshot,'Комплектация из снимка 1С на момент покупки',$installedAt)
        :vehicle_passport_seed_from_product($pdo,$vehicleId,$row['product_id']!==null?(int)$row['product_id']:null,$installedAt);
    if(function_exists('vehicle_spec_registry_apply_vehicle')){
        $official=vehicle_spec_registry_apply_vehicle($pdo,$vehicleId);
        $count+=(int)($official['inserted']??0);
    }
    return $count;
}

function vehicle_passport_median(array $values): ?float {
    $values=array_values(array_filter(array_map('floatval',$values),fn($v)=>$v>0));if(!$values)return null;
    sort($values,SORT_NUMERIC);$n=count($values);$m=intdiv($n,2);
    return $n%2?$values[$m]:($values[$m-1]+$values[$m])/2;
}

function vehicle_passport_learning_cycles(PDO $pdo,int $componentId,string $mode): array {
    $s=$pdo->prepare("SELECT event_type,event_at,odometer_km,include_learning FROM vehicle_component_events WHERE component_id=? AND event_type IN ('installed','replaced') ORDER BY event_at,id");
    $s->execute([$componentId]);$rows=$s->fetchAll();$cycles=[];$previous=null;
    foreach($rows as $row){
        if($previous!==null&&$row['event_type']==='replaced'&&(int)$row['include_learning']===1){
            if($mode==='time'){
                $a=strtotime((string)$previous['event_at']);$b=strtotime((string)$row['event_at']);if($a&&$b&&$b>$a)$cycles[]=($b-$a)/86400;
            }elseif($mode==='distance'&&$previous['odometer_km']!==null&&$row['odometer_km']!==null){
                $delta=(float)$row['odometer_km']-(float)$previous['odometer_km'];if($delta>0)$cycles[]=$delta;
            }
        }
        $previous=$row;
    }
    return $cycles;
}

function vehicle_passport_learning_weight(int $samples): float {
    return match(true){$samples<=0=>0.0,$samples===1=>0.10,$samples===2=>0.50,$samples===3=>0.70,$samples===4=>0.80,default=>0.90};
}

function vehicle_passport_last_lifecycle(PDO $pdo,int $componentId): ?array {
    $s=$pdo->prepare("SELECT event_type,event_at,odometer_km FROM vehicle_component_events WHERE component_id=? AND event_type IN ('installed','replaced') ORDER BY event_at DESC,id DESC LIMIT 1");
    $s->execute([$componentId]);$row=$s->fetch();return $row?:null;
}

function vehicle_passport_wear(PDO $pdo,array $component,?float $vehicleOdometer=null): array {
    $mode=(string)($component['wear_mode']??'inspection');$percent=null;$remaining=null;$predicted=null;$samples=0;$personalMedian=null;$basis='inspection';$confidence='unknown';
    if($mode==='measurement'&&$component['current_measurement']!==null&&$component['baseline_measurement']!==null&&$component['replacement_threshold']!==null){
        $current=(float)$component['current_measurement'];$base=(float)$component['baseline_measurement'];$threshold=(float)$component['replacement_threshold'];$direction=(string)($component['measurement_direction']??'down');
        $den=$direction==='up'?($threshold-$base):($base-$threshold);$num=$direction==='up'?($current-$base):($base-$current);
        if(abs($den)>0.000001){$percent=max(0,min(100,$num/$den*100));$basis='measurement';$confidence='measured';}
    }elseif(in_array($mode,['time','distance'],true)){
        $cycles=vehicle_passport_learning_cycles($pdo,(int)$component['id'],$mode);$samples=count($cycles);$personalMedian=vehicle_passport_median($cycles);
        $baseline=$component['baseline_life_value']!==null?(float)$component['baseline_life_value']:null;
        if($baseline!==null&&$baseline>0){
            $weight=vehicle_passport_learning_weight($samples);$predicted=$personalMedian!==null?($baseline*(1-$weight)+$personalMedian*$weight):$baseline;$basis=$samples?'baseline+personal':'baseline';$confidence=$samples>=3?'high':($samples>=1?'medium':'baseline');
        }elseif($personalMedian!==null&&$samples>=2){$predicted=$personalMedian;$basis='personal';$confidence=$samples>=4?'high':'medium';}
        if($predicted!==null&&$predicted>0){
            $last=vehicle_passport_last_lifecycle($pdo,(int)$component['id']);
            if($mode==='time'){
                $start=$last['event_at']??$component['installed_at']??null;if($start){$elapsed=max(0,(time()-strtotime((string)$start))/86400);$percent=max(0,min(100,$elapsed/$predicted*100));$remaining=max(0,$predicted-$elapsed);}
            }elseif($mode==='distance'&&$vehicleOdometer!==null){
                $startKm=$last['odometer_km']??null;if($startKm!==null){$elapsed=max(0,$vehicleOdometer-(float)$startKm);$percent=max(0,min(100,$elapsed/$predicted*100));$remaining=max(0,$predicted-$elapsed);}
            }
        }
    }
    $state=$percent===null?'unknown':($percent<60?'good':($percent<80?'watch':($percent<95?'soon':'due')));
    return [
        'mode'=>$mode,'percent'=>$percent!==null?round($percent,1):null,'state'=>$state,
        'predicted_life'=>$predicted!==null?round($predicted,1):null,'remaining'=>$remaining!==null?round($remaining,1):null,
        'unit'=>$mode==='time'?'days':($mode==='distance'?'km':($component['measurement_unit']??null)),
        'personal_samples'=>$samples,'personal_median'=>$personalMedian!==null?round($personalMedian,1):null,'basis'=>$basis,'confidence'=>$confidence,
    ];
}

function vehicle_passport_components(PDO $pdo,int $vehicleId,bool $includeEvents=false): array {
    vehicle_passport_seed_vehicle($pdo,$vehicleId);
    $v=$pdo->prepare('SELECT odometer_km FROM customer_vehicles WHERE id=? LIMIT 1');$v->execute([$vehicleId]);$od=$v->fetchColumn();$odometer=$od!==false&&$od!==null?(float)$od:null;
    $s=$pdo->prepare('SELECT * FROM vehicle_components WHERE vehicle_id=? AND is_active=1 ORDER BY id');$s->execute([$vehicleId]);$rows=$s->fetchAll();
    $events=$includeEvents?$pdo->prepare('SELECT id,event_type,event_at,odometer_km,measurement_value,measurement_unit,include_learning,note,admin_user_id,source_order_id,source_product_id FROM vehicle_component_events WHERE component_id=? ORDER BY event_at DESC,id DESC LIMIT 50'):null;
    $pending=$pdo->prepare("SELECT id,event_at,source_order_id,source_product_id,note FROM vehicle_component_events p WHERE p.component_id=? AND p.event_type='replacement_purchase' AND NOT EXISTS(SELECT 1 FROM vehicle_component_events r WHERE r.component_id=p.component_id AND r.event_type='replaced' AND r.event_at>=p.event_at) ORDER BY p.event_at DESC,p.id DESC LIMIT 1");
    foreach($rows as &$row){
        $row['id']=(int)$row['id'];$row['vehicle_id']=(int)$row['vehicle_id'];$row['wear']=vehicle_passport_wear($pdo,$row,$odometer);
        $pending->execute([(int)$row['id']]);$row['pending_replacement_purchase']=$pending->fetch()?:null;
        if($includeEvents&&$events){$events->execute([(int)$row['id']]);$row['events']=$events->fetchAll();}
    }unset($row);
    return $rows;
}

function vehicle_passport_payload(PDO $pdo,int $vehicleId,bool $includeEvents=false): ?array {
    if($vehicleId<1)return null;
    $s=$pdo->prepare('SELECT v.id,v.customer_id,v.product_id,v.title,v.vehicle_type,v.order_number,v.purchase_date,v.serial_number,v.odometer_km,v.odometer_updated_at,v.created_at,p.brand,p.model,p.sku FROM customer_vehicles v LEFT JOIN products p ON p.id=v.product_id WHERE v.id=? AND v.is_active=1 LIMIT 1');
    $s->execute([$vehicleId]);$vehicle=$s->fetch();if(!$vehicle)return null;
    $vehicle['id']=(int)$vehicle['id'];$vehicle['customer_id']=(int)$vehicle['customer_id'];$vehicle['product_id']=$vehicle['product_id']!==null?(int)$vehicle['product_id']:null;
    $verifiedProfile=function_exists('vehicle_spec_registry_match')?vehicle_spec_registry_match((string)$vehicle['title']):null;
    return [
        'vehicle'=>$vehicle,
        'hotspots'=>(string)$vehicle['vehicle_type']==='bicycle'?vehicle_passport_hotspots():[],
        'components'=>vehicle_passport_components($pdo,$vehicleId,$includeEvents),
        'verified_profile'=>$verifiedProfile?['key'=>$verifiedProfile['key'],'source_url'=>$verifiedProfile['source_url'],'verified_at'=>'2026-09-26']:null,
    ];
}

function vehicle_passport_save_component(PDO $pdo,int $vehicleId,array $in,int $adminId): array {
    $id=(int)($in['id']??0);$componentKey=trim((string)($in['component_key']??''));$hotspot=trim((string)($in['hotspot_key']??''));$label=trim((string)($in['label']??''));
    if($vehicleId<1||$componentKey===''||$hotspot===''||$label==='')throw new InvalidArgumentException('invalid_component');
    $allowedHotspots=array_merge(['general'],array_column(vehicle_passport_hotspots(),'key'));if(!in_array($hotspot,$allowedHotspots,true))throw new InvalidArgumentException('invalid_hotspot');
    $wearMode=(string)($in['wear_mode']??'inspection');if(!in_array($wearMode,['inspection','time','distance','measurement'],true))throw new InvalidArgumentException('invalid_wear_mode');
    $sourceType=(string)($in['source_type']??'manual');if(!in_array($sourceType,['manual','1c_spec','official','service'],true))throw new InvalidArgumentException('invalid_source_type');
    $url=trim((string)($in['source_url']??''));if($url!==''&&!filter_var($url,FILTER_VALIDATE_URL))throw new InvalidArgumentException('invalid_source_url');
    $verified=($in['source_verified']??false)===true;
    $vals=[
        'manufacturer'=>trim((string)($in['manufacturer']??''))?:null,'model'=>trim((string)($in['model']??''))?:null,
        'compatible_product_id'=>(int)($in['compatible_product_id']??0)?:null,'source_type'=>$sourceType,'source_url'=>$url?:null,'source_note'=>trim((string)($in['source_note']??''))?:null,
        'wear_mode'=>$wearMode,'baseline_life_value'=>is_numeric($in['baseline_life_value']??null)?(float)$in['baseline_life_value']:null,'baseline_life_unit'=>trim((string)($in['baseline_life_unit']??''))?:null,
        'baseline_measurement'=>is_numeric($in['baseline_measurement']??null)?(float)$in['baseline_measurement']:null,'replacement_threshold'=>is_numeric($in['replacement_threshold']??null)?(float)$in['replacement_threshold']:null,
        'measurement_unit'=>trim((string)($in['measurement_unit']??''))?:null,'measurement_direction'=>in_array((string)($in['measurement_direction']??''),['up','down'],true)?(string)$in['measurement_direction']:null,
        'installed_at'=>trim((string)($in['installed_at']??''))?:null,
    ];
    if($id>0){
        $s=$pdo->prepare('UPDATE vehicle_components SET component_key=?,hotspot_key=?,label=?,manufacturer=?,model=?,compatible_product_id=?,source_type=?,source_url=?,source_note=?,source_verified_at=?,wear_mode=?,baseline_life_value=?,baseline_life_unit=?,baseline_measurement=?,replacement_threshold=?,measurement_unit=?,measurement_direction=?,installed_at=? WHERE id=? AND vehicle_id=?');
        $s->execute([$componentKey,$hotspot,$label,$vals['manufacturer'],$vals['model'],$vals['compatible_product_id'],$sourceType,$vals['source_url'],$vals['source_note'],$verified?date('Y-m-d H:i:s'):null,$wearMode,$vals['baseline_life_value'],$vals['baseline_life_unit'],$vals['baseline_measurement'],$vals['replacement_threshold'],$vals['measurement_unit'],$vals['measurement_direction'],$vals['installed_at'],$id,$vehicleId]);
    }else{
        $s=$pdo->prepare('INSERT INTO vehicle_components(vehicle_id,component_key,hotspot_key,label,manufacturer,model,compatible_product_id,source_type,source_url,source_note,source_verified_at,wear_mode,baseline_life_value,baseline_life_unit,baseline_measurement,replacement_threshold,measurement_unit,measurement_direction,installed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE hotspot_key=VALUES(hotspot_key),label=VALUES(label),manufacturer=VALUES(manufacturer),model=VALUES(model),compatible_product_id=VALUES(compatible_product_id),source_type=VALUES(source_type),source_url=VALUES(source_url),source_note=VALUES(source_note),source_verified_at=VALUES(source_verified_at),wear_mode=VALUES(wear_mode),baseline_life_value=VALUES(baseline_life_value),baseline_life_unit=VALUES(baseline_life_unit),baseline_measurement=VALUES(baseline_measurement),replacement_threshold=VALUES(replacement_threshold),measurement_unit=VALUES(measurement_unit),measurement_direction=VALUES(measurement_direction),installed_at=VALUES(installed_at)');
        $s->execute([$vehicleId,$componentKey,$hotspot,$label,$vals['manufacturer'],$vals['model'],$vals['compatible_product_id'],$sourceType,$vals['source_url'],$vals['source_note'],$verified?date('Y-m-d H:i:s'):null,$wearMode,$vals['baseline_life_value'],$vals['baseline_life_unit'],$vals['baseline_measurement'],$vals['replacement_threshold'],$vals['measurement_unit'],$vals['measurement_direction'],$vals['installed_at']]);
        $id=(int)$pdo->lastInsertId();if($id===0){$q=$pdo->prepare('SELECT id FROM vehicle_components WHERE vehicle_id=? AND component_key=? LIMIT 1');$q->execute([$vehicleId,$componentKey]);$id=(int)$q->fetchColumn();}
    }
    audit($pdo,'vehicle_component_save','vehicle_component',(string)$id,['vehicle_id'=>$vehicleId,'source_type'=>$sourceType,'verified'=>$verified,'wear_mode'=>$wearMode]);
    $q=$pdo->prepare('SELECT * FROM vehicle_components WHERE id=? LIMIT 1');$q->execute([$id]);$row=$q->fetch();if(!$row)throw new RuntimeException('component_not_found');
    $row['wear']=vehicle_passport_wear($pdo,$row,null);return $row;
}

function vehicle_passport_record_event(PDO $pdo,int $componentId,array $in,int $adminId): int {
    $type=(string)($in['event_type']??'');if(!in_array($type,['installed','replaced','measured','inspected','replacement_purchase'],true))throw new InvalidArgumentException('invalid_event');
    $eventAt=trim((string)($in['event_at']??''));if($eventAt==='')$eventAt=date('Y-m-d H:i:s');
    $odo=is_numeric($in['odometer_km']??null)?(float)$in['odometer_km']:null;$measurement=is_numeric($in['measurement_value']??null)?(float)$in['measurement_value']:null;$unit=trim((string)($in['measurement_unit']??''))?:null;
    $include=($in['include_learning']??true)!==false;$note=mb_substr(trim((string)($in['note']??'')),0,1000);
    $s=$pdo->prepare('INSERT INTO vehicle_component_events(component_id,event_type,event_at,odometer_km,measurement_value,measurement_unit,include_learning,note,admin_user_id,source_order_id,source_product_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $s->execute([$componentId,$type,$eventAt,$odo,$measurement,$unit,$include?1:0,$note?:null,$adminId,(int)($in['source_order_id']??0)?:null,(int)($in['source_product_id']??0)?:null]);$id=(int)$pdo->lastInsertId();
    if(in_array($type,['installed','replaced'],true))$pdo->prepare('UPDATE vehicle_components SET installed_at=?,current_measurement=NULL,current_measurement_at=NULL WHERE id=?')->execute([$eventAt,$componentId]);
    if($type==='measured'&&$measurement!==null)$pdo->prepare('UPDATE vehicle_components SET current_measurement=?,measurement_unit=COALESCE(?,measurement_unit),current_measurement_at=? WHERE id=?')->execute([$measurement,$unit,$eventAt,$componentId]);
    if($odo!==null){
        $pdo->prepare('UPDATE customer_vehicles v JOIN vehicle_components c ON c.vehicle_id=v.id SET v.odometer_km=?,v.odometer_updated_at=? WHERE c.id=?')->execute([$odo,$eventAt,$componentId]);
    }
    audit($pdo,'vehicle_component_event','vehicle_component',(string)$componentId,['event_id'=>$id,'event_type'=>$type,'include_learning'=>$include]);return $id;
}


function vehicle_passport_sync_replacement_purchases(PDO $pdo,int $orderId): int {
    $o=$pdo->prepare("SELECT customer_id,status,created_at FROM orders WHERE id=? LIMIT 1");$o->execute([$orderId]);$order=$o->fetch();
    if(!$order||(string)$order['status']!=='completed'||(int)($order['customer_id']??0)<1)return 0;
    $items=$pdo->prepare("SELECT DISTINCT product_id,title FROM order_items WHERE order_id=? AND product_id IS NOT NULL");$items->execute([$orderId]);
    $matches=$pdo->prepare("SELECT c.id component_id FROM customer_vehicles v JOIN vehicle_components c ON c.vehicle_id=v.id AND c.is_active=1 WHERE v.customer_id=? AND v.is_active=1 AND c.compatible_product_id=? ORDER BY c.id");
    $insert=$pdo->prepare("INSERT IGNORE INTO vehicle_component_events(component_id,event_type,event_at,include_learning,note,source_order_id,source_product_id) VALUES(?,'replacement_purchase',?,0,?,?,?)");$count=0;
    foreach($items->fetchAll() as $item){
        $matches->execute([(int)$order['customer_id'],(int)$item['product_id']]);$rows=$matches->fetchAll();
        if(count($rows)!==1)continue; // Не угадываем, для какого из нескольких совместимых велосипедов куплена деталь.
        $note='Куплен совместимый расходник: '.mb_substr((string)$item['title'],0,500);
        $insert->execute([(int)$rows[0]['component_id'],(string)$order['created_at'],$note,$orderId,(int)$item['product_id']]);$count+=$insert->rowCount();
    }
    return $count;
}

function vehicle_passport_confirm_customer_replacement(PDO $pdo,int $customerId,int $componentId): array {
    $s=$pdo->prepare("SELECT c.id,c.vehicle_id,v.odometer_km FROM vehicle_components c JOIN customer_vehicles v ON v.id=c.vehicle_id WHERE c.id=? AND v.customer_id=? AND c.is_active=1 AND v.is_active=1 LIMIT 1");
    $s->execute([$componentId,$customerId]);$row=$s->fetch();if(!$row)throw new DomainException('component_not_owned');
    $p=$pdo->prepare("SELECT id,event_at,source_order_id,source_product_id FROM vehicle_component_events WHERE component_id=? AND event_type='replacement_purchase' AND NOT EXISTS(SELECT 1 FROM vehicle_component_events r WHERE r.component_id=? AND r.event_type='replaced' AND r.event_at>=vehicle_component_events.event_at) ORDER BY event_at DESC,id DESC LIMIT 1");
    $p->execute([$componentId,$componentId]);$purchase=$p->fetch();if(!$purchase)throw new DomainException('replacement_purchase_not_found');
    $event=[
        'event_type'=>'replaced','event_at'=>date('Y-m-d H:i:s'),'odometer_km'=>$row['odometer_km']!==null?(float)$row['odometer_km']:null,
        'include_learning'=>true,'note'=>'Замена подтверждена клиентом после покупки совместимого расходника',
        'source_order_id'=>(int)$purchase['source_order_id'],'source_product_id'=>(int)$purchase['source_product_id'],
    ];
    $eventId=vehicle_passport_record_event($pdo,$componentId,$event,0);
    return ['event_id'=>$eventId,'vehicle_id'=>(int)$row['vehicle_id']];
}
