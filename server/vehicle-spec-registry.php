<?php
declare(strict_types=1);

const VEHICLE_SPEC_ASPECT_2025='https://aspect-bikes.ru/upload/media/aspect-catalog-2025.pdf';
const VEHICLE_SPEC_ASPECT_OASIS_2026='https://aspect-bikes.ru/catalog/aspect-oasis-275/';
const VEHICLE_SPEC_ASPECT_OASIS_PRO_2026='https://www.aspect-bikes.ru/catalog/aspect-oasis-pro-275/';
const VEHICLE_SPEC_SHIMANO_MT200='https://bike.shimano.com/en-SG/products/components/pdp.P-BR-MT200.html';
const VEHICLE_SPEC_HAGEN_39_2025='https://hagen.bike/threenine';
const VEHICLE_SPEC_HAGEN_311_2025='https://hagen.bike/mtbthreeelevenblack';

function vehicle_spec_registry_component(
    string $key,string $hotspot,string $label,string $model,string $sourceUrl,
    ?string $manufacturer=null,string $note='Официальная спецификация производителя'
): array {
    return [
        'component_key'=>$key,'hotspot_key'=>$hotspot,'label'=>$label,
        'manufacturer'=>$manufacturer,'model'=>$model,'source_type'=>'official',
        'source_url'=>$sourceUrl,'source_note'=>$note,'source_verified_at'=>'2026-09-26 00:00:00',
        'wear_mode'=>'inspection',
    ];
}

function vehicle_spec_registry_mt200_pads(): array {
    return [
        vehicle_spec_registry_component('front_brake_pads','front_brake','Передние тормозные колодки','B05S-RX Resin','https://bike.shimano.com/en-SG/products/components/pdp.P-BR-MT200.html','Shimano','Совместимость BR-MT200 → B05S-RX подтверждена Shimano'),
        vehicle_spec_registry_component('rear_brake_pads','rear_brake','Задние тормозные колодки','B05S-RX Resin','https://bike.shimano.com/en-SG/products/components/pdp.P-BR-MT200.html','Shimano','Совместимость BR-MT200 → B05S-RX подтверждена Shimano'),
    ];
}

function vehicle_spec_registry_common_controls(string $source,string $handlebar,string $stem,string $seatpost,string $saddle,string $hubs,string $rims): array {
    return [
        vehicle_spec_registry_component('handlebar','cockpit','Руль',$handlebar,$source,'Code'),
        vehicle_spec_registry_component('stem','cockpit','Вынос',$stem,$source,'Code'),
        vehicle_spec_registry_component('seatpost','saddle','Подседельный штырь',$seatpost,$source,'Code'),
        vehicle_spec_registry_component('saddle','saddle','Седло',$saddle,$source,'Code'),
        vehicle_spec_registry_component('hubs','hubs','Втулки',$hubs,$source,'Code'),
        vehicle_spec_registry_component('rims','wheels','Обода',$rims,$source,null),
    ];
}

function vehicle_spec_registry_2025_nickel_pro(string $wheel): array {
    $source=VEHICLE_SPEC_ASPECT_2025;
    $components=[
        vehicle_spec_registry_component('fork','fork','Вилка','GTMRK 362MLO, 100 мм',$source,'GTMRK'),
        vehicle_spec_registry_component('rear_derailleur','drivetrain','Задний переключатель','RD-M3020',$source,'Shimano Acera'),
        vehicle_spec_registry_component('shifter','cockpit','Манетка','SL-M315',$source,'Shimano Altus'),
        vehicle_spec_registry_component('cranks','cranks','Шатуны','C10Y-NW 34T',$source,'Prowheel'),
        vehicle_spec_registry_component('bottom_bracket','cranks','Каретка','B910 124.5/73mm',$source,'Neco'),
        vehicle_spec_registry_component('cassette','drivetrain','Кассета','MTB-CS-HR8-40 8S',$source,'SunShine'),
        vehicle_spec_registry_component('front_brake','front_brake','Передний тормоз','BR-MT200 Hydraulic Disc',$source,'Shimano'),
        vehicle_spec_registry_component('rear_brake','rear_brake','Задний тормоз','BR-MT200 Hydraulic Disc',$source,'Shimano'),
        vehicle_spec_registry_component('brake_rotors','front_brake','Тормозные диски',$wheel==='29'?'RT-26 180/160':'RT-26 160/160',$source,'Shimano'),
        vehicle_spec_registry_component('front_tire','front_tire','Передняя покрышка','H-5129 '.$wheel.'x2.2',$source,'Chaoyang'),
        vehicle_spec_registry_component('rear_tire','rear_tire','Задняя покрышка','H-5129 '.$wheel.'x2.2',$source,'Chaoyang'),
    ];
    return array_merge($components,vehicle_spec_registry_common_controls($source,'Code-802 31.8×760','Code-008','Code-705 27.2×350','Code-K265','Code H1 100/135 32H','D23race Tubeless Ready'),vehicle_spec_registry_mt200_pads());
}

function vehicle_spec_registry_2025_nickel_elite(string $wheel): array {
    $source=VEHICLE_SPEC_ASPECT_2025;
    $components=[
        vehicle_spec_registry_component('fork','fork','Вилка','GTMRK 362MLO, 100 мм',$source,'GTMRK'),
        vehicle_spec_registry_component('rear_derailleur','drivetrain','Задний переключатель','RD-U3020',$source,'Shimano CUES'),
        vehicle_spec_registry_component('shifter','cockpit','Манетка','SL-U4000',$source,'Shimano CUES'),
        vehicle_spec_registry_component('cranks','cranks','Шатуны','C10Y-NW 34T',$source,'Prowheel'),
        vehicle_spec_registry_component('bottom_bracket','cranks','Каретка','B910 124.5/73mm',$source,'Neco'),
        vehicle_spec_registry_component('cassette','drivetrain','Кассета','MTB-CS-HR9-40 9S',$source,'SunShine'),
        vehicle_spec_registry_component('front_brake','front_brake','Передний тормоз','BR-MT200 Hydraulic Disc',$source,'Shimano'),
        vehicle_spec_registry_component('rear_brake','rear_brake','Задний тормоз','BR-MT200 Hydraulic Disc',$source,'Shimano'),
        vehicle_spec_registry_component('brake_rotors','front_brake','Тормозные диски',$wheel==='29'?'RT-26 180/160':'RT-26 160/160',$source,'Shimano'),
        vehicle_spec_registry_component('front_tire','front_tire','Передняя покрышка','Jack Rabbit '.$wheel.'x2.2',$source,'CST'),
        vehicle_spec_registry_component('rear_tire','rear_tire','Задняя покрышка','Jack Rabbit '.$wheel.'x2.2',$source,'CST'),
    ];
    return array_merge($components,vehicle_spec_registry_common_controls($source,'Code-802 31.8×760','Code-008','Code-705 27.2×350','Code-K265','Code H1 100/135 32H','D23race Tubeless Ready'),vehicle_spec_registry_mt200_pads());
}

function vehicle_spec_registry_2025_cobalt(string $wheel,bool $pro): array {
    $source=VEHICLE_SPEC_ASPECT_2025;
    $components=[
        vehicle_spec_registry_component('fork','fork','Вилка','GTMRK 362MLO, 100 мм',$source,'GTMRK'),
        vehicle_spec_registry_component('rear_derailleur','drivetrain','Задний переключатель',$pro?'RD-U4000':'RD-U3020',$source,'Shimano CUES'),
        vehicle_spec_registry_component('shifter','cockpit','Манетка','SL-U4000',$source,'Shimano CUES'),
        vehicle_spec_registry_component('cranks','cranks','Шатуны',$pro?'CI0Y-NW 34T':'C10Y-NW 34T',$source,'Prowheel'),
        vehicle_spec_registry_component('bottom_bracket','cranks','Каретка','B910 124.5/73mm',$source,'Neco'),
        vehicle_spec_registry_component('cassette','drivetrain','Кассета',$pro?'LG300 9sp 11-46':'MTB-CS-HR9-40 9S',$source,$pro?'Shimano':'SunShine'),
        vehicle_spec_registry_component('front_brake','front_brake','Передний тормоз','BR-MT200 Hydraulic Disc',$source,'Shimano'),
        vehicle_spec_registry_component('rear_brake','rear_brake','Задний тормоз','BR-MT200 Hydraulic Disc',$source,'Shimano'),
        vehicle_spec_registry_component('brake_rotors','front_brake','Тормозные диски',$wheel==='29'?'RT-26 180/160':'RT-26 160/160',$source,'Shimano'),
        vehicle_spec_registry_component('front_tire','front_tire','Передняя покрышка','Booster K1227 '.$wheel.'x2.25 SkinWall',$source,'Kenda'),
        vehicle_spec_registry_component('rear_tire','rear_tire','Задняя покрышка','Booster K1227 '.$wheel.'x2.25 SkinWall',$source,'Kenda'),
    ];
    return array_merge($components,vehicle_spec_registry_common_controls($source,'Code-802 31.8×760',$pro?'Code AS-002N':'Code AS-002N','Code-609 27.2×350','Code-K246','Code H1 100/135 32H','D23race Tubeless Ready'),vehicle_spec_registry_mt200_pads());
}

function vehicle_spec_registry_2025_aura(): array {
    $source=VEHICLE_SPEC_ASPECT_2025;
    return array_merge([
        vehicle_spec_registry_component('fork','fork','Вилка','GTMRK 367 AIR/HLO, 100 мм',$source,'GTMRK'),
        vehicle_spec_registry_component('rear_derailleur','drivetrain','Задний переключатель','RD-U3020',$source,'Shimano CUES'),
        vehicle_spec_registry_component('shifter','cockpit','Манетка','SL-U4000',$source,'Shimano CUES'),
        vehicle_spec_registry_component('cranks','cranks','Шатуны','C10Y-NW 32T×170',$source,'Prowheel'),
        vehicle_spec_registry_component('bottom_bracket','cranks','Каретка','FP-B902 124.5/73mm',$source,null),
        vehicle_spec_registry_component('cassette','drivetrain','Кассета','MTB-CS-HR9-40 9S',$source,'SunShine'),
        vehicle_spec_registry_component('front_brake','front_brake','Передний тормоз','M275 Hydraulic Disc',$source,'Tektro'),
        vehicle_spec_registry_component('rear_brake','rear_brake','Задний тормоз','M275 Hydraulic Disc',$source,'Tektro'),
        vehicle_spec_registry_component('brake_rotors','front_brake','Тормозные диски','TR-52 160/160',$source,'Tektro'),
        vehicle_spec_registry_component('front_tire','front_tire','Передняя покрышка','Jack Rabbit 27.5x2.25',$source,'CST'),
        vehicle_spec_registry_component('rear_tire','rear_tire','Задняя покрышка','Jack Rabbit 27.5x2.25',$source,'CST'),
    ],vehicle_spec_registry_common_controls($source,'Code-802 31.8×720','Code-302A 50 мм','Code-705 31.6×350','Code-K355','Code H1 100/135 32H','Code D23 ETRTO 584×23'));
}

function vehicle_spec_registry_2026_oasis(bool $pro): array {
    $source=$pro?VEHICLE_SPEC_ASPECT_OASIS_PRO_2026:VEHICLE_SPEC_ASPECT_OASIS_2026;
    return array_merge([
        vehicle_spec_registry_component('fork','fork','Вилка','GTMRK 328 MLO, 100 мм',$source,'GTMRK'),
        vehicle_spec_registry_component('rear_derailleur','drivetrain','Задний переключатель','RD-TY300',$source,'Shimano Tourney'),
        vehicle_spec_registry_component('shifter','cockpit','Манетка','SL-A700-7W-2',$source,'LTWOO'),
        vehicle_spec_registry_component('cranks','cranks','Шатуны','Code DMD NW 34T',$source,'Code'),
        vehicle_spec_registry_component('cassette','drivetrain','Кассета','MTB-CS-HR7-34 7S 11-34T',$source,'Sunshine'),
        vehicle_spec_registry_component('front_brake','front_brake','Передний тормоз',$pro?'TKD-176 Hydraulic Disc':'DSC310 Mechanical Disc',$source,$pro?'Tektro':'RPT'),
        vehicle_spec_registry_component('rear_brake','rear_brake','Задний тормоз',$pro?'TKD-176 Hydraulic Disc':'DSC310 Mechanical Disc',$source,$pro?'Tektro':'RPT'),
        vehicle_spec_registry_component('brake_rotors','front_brake','Тормозные диски',$pro?'TEKTRO 160/160':'RPT-009 160/160',$source,$pro?'Tektro':'RPT'),
        vehicle_spec_registry_component('front_tire','front_tire','Передняя покрышка','P1277 27.5x2.3',$source,'WANDA'),
        vehicle_spec_registry_component('rear_tire','rear_tire','Задняя покрышка','P1277 27.5x2.3',$source,'WANDA'),
    ],vehicle_spec_registry_common_controls($source,'Code-802 31.8×720','Code-302A 60 мм','Code-8510 27.2×350','Code-K355','Code H1 100/135 32H','Code D21'));
}

function vehicle_spec_registry_hagen_2025(string $model): array {
    $is311=$model==='3.11';$source=$is311?VEHICLE_SPEC_HAGEN_311_2025:VEHICLE_SPEC_HAGEN_39_2025;
    $components=[
        vehicle_spec_registry_component('fork','fork','Вилка',$is311?'D3 AIR, 100-120mm, 30mm, rebound':'D3 MLO 30mm, 100-120mm',$source,null),
        vehicle_spec_registry_component('rear_derailleur','drivetrain','Задний переключатель',$is311?'RD-U6000 CUES 11':'RD-U4000 CUES 9',$source,'Shimano'),
        vehicle_spec_registry_component('shifter','cockpit','Манетка',$is311?'SL-U6000 CUES 11 R':'SL-U4000 CUES 9 R',$source,'Shimano'),
        vehicle_spec_registry_component('front_brake','front_brake','Передний тормоз','HD MT-200 180/160mm',$source,'Shimano'),
        vehicle_spec_registry_component('rear_brake','rear_brake','Задний тормоз','HD MT-200 180/160mm',$source,'Shimano'),
        vehicle_spec_registry_component('cassette','drivetrain','Кассета',$is311?'HR11 11-46T':'HR9 11-42T',$source,'Sunshine'),
        vehicle_spec_registry_component('hubs','hubs','Втулки','DH-901F/R 32H, sealed bearings',$source,null),
        vehicle_spec_registry_component('handlebar','cockpit','Руль','Alloy 31.8, 720/740mm, 5° sweep, 10mm rise',$source,null),
        vehicle_spec_registry_component('cranks','cranks','Система',$is311?'RMZ 32T 170/175mm integrated axle':'CY-10 NW 32T 170/175mm',$source,'Prowheel'),
        vehicle_spec_registry_component('front_tire','front_tire','Передняя покрышка','Kenda 1259 2.25',$source,'Kenda'),
        vehicle_spec_registry_component('rear_tire','rear_tire','Задняя покрышка','Kenda 1259 2.25',$source,'Kenda'),
        vehicle_spec_registry_component('pedals','pedals','Педали','B572 DU sealed bearing',$source,null),
        vehicle_spec_registry_component('seatpost','saddle','Подседельный штырь','Alloy 31.6×350/400, 2-bolt',$source,null),
        vehicle_spec_registry_component('saddle','saddle','Седло','MTB, 100% PU, anatomical',$source,null),
        vehicle_spec_registry_component('rims','wheels','Обода','DP-27 27mm, double wall, F/V 48mm',$source,null),
    ];
    return array_merge($components,vehicle_spec_registry_mt200_pads());
}

function vehicle_spec_registry_profiles(): array {
    $profiles=[];
    foreach(['27.5','29'] as $wheel){
        $profiles[]=['key'=>'aspect-nickel-pro-2025-'.$wheel,'brand'=>'Aspect','model'=>'Nickel Pro','year'=>2025,'wheel'=>$wheel,'source_url'=>VEHICLE_SPEC_ASPECT_2025,'components'=>vehicle_spec_registry_2025_nickel_pro($wheel)];
        $profiles[]=['key'=>'aspect-nickel-elite-2025-'.$wheel,'brand'=>'Aspect','model'=>'Nickel Elite','year'=>2025,'wheel'=>$wheel,'source_url'=>VEHICLE_SPEC_ASPECT_2025,'components'=>vehicle_spec_registry_2025_nickel_elite($wheel)];
        $profiles[]=['key'=>'aspect-cobalt-2025-'.$wheel,'brand'=>'Aspect','model'=>'Cobalt','year'=>2025,'wheel'=>$wheel,'source_url'=>VEHICLE_SPEC_ASPECT_2025,'components'=>vehicle_spec_registry_2025_cobalt($wheel,false)];
        $profiles[]=['key'=>'aspect-cobalt-pro-2025-'.$wheel,'brand'=>'Aspect','model'=>'Cobalt Pro','year'=>2025,'wheel'=>$wheel,'source_url'=>VEHICLE_SPEC_ASPECT_2025,'components'=>vehicle_spec_registry_2025_cobalt($wheel,true)];
    }
    $profiles[]=['key'=>'aspect-aura-2025-27.5','brand'=>'Aspect','model'=>'Aura','year'=>2025,'wheel'=>'27.5','source_url'=>VEHICLE_SPEC_ASPECT_2025,'components'=>vehicle_spec_registry_2025_aura()];
    $profiles[]=['key'=>'aspect-oasis-2026-27.5','brand'=>'Aspect','model'=>'Oasis','year'=>2026,'wheel'=>'27.5','source_url'=>VEHICLE_SPEC_ASPECT_OASIS_2026,'components'=>vehicle_spec_registry_2026_oasis(false)];
    $profiles[]=['key'=>'aspect-oasis-pro-2026-27.5','brand'=>'Aspect','model'=>'Oasis Pro','year'=>2026,'wheel'=>'27.5','source_url'=>VEHICLE_SPEC_ASPECT_OASIS_PRO_2026,'components'=>vehicle_spec_registry_2026_oasis(true)];
    foreach(['27.5','29'] as $wheel){
        $profiles[]=['key'=>'hagen-3.9-2025-'.$wheel,'brand'=>'Hagen','model'=>'3.9','year'=>2025,'wheel'=>$wheel,'source_url'=>VEHICLE_SPEC_HAGEN_39_2025,'components'=>vehicle_spec_registry_hagen_2025('3.9')];
        $profiles[]=['key'=>'hagen-3.11-2025-'.$wheel,'brand'=>'Hagen','model'=>'3.11','year'=>2025,'wheel'=>$wheel,'source_url'=>VEHICLE_SPEC_HAGEN_311_2025,'components'=>vehicle_spec_registry_hagen_2025('3.11')];
    }
    return $profiles;
}

function vehicle_spec_registry_normalize_title(string $title): string {
    $s=mb_strtolower($title,'UTF-8');
    $s=str_replace(['ё','27,5','27.5"','29"'],['е','27.5','27.5','29'],$s);
    $s=preg_replace('/[^a-zа-я0-9.]+/u',' ',$s)??$s;
    return trim(preg_replace('/\s+/u',' ',$s)??$s);
}

function vehicle_spec_registry_match(string $title): ?array {
    $n=vehicle_spec_registry_normalize_title($title);
    $matches=[];
    foreach(vehicle_spec_registry_profiles() as $profile){
        $brand=vehicle_spec_registry_normalize_title((string)$profile['brand']);
        $model=vehicle_spec_registry_normalize_title((string)$profile['model']);
        $year=(string)$profile['year'];$wheel=(string)$profile['wheel'];
        if(!str_contains($n,$brand.' '.$model))continue;
        if(!preg_match('/(?:^| )'.preg_quote($year,'/').'(?: |$)/u',$n))continue;
        if(!preg_match('/(?:^| )'.preg_quote($wheel,'/').'(?: |$)/u',$n))continue;
        $matches[]=$profile;
    }
    if(!$matches)return null;
    usort($matches,fn($a,$b)=>mb_strlen((string)$b['model'])<=>mb_strlen((string)$a['model']));
    $best=$matches[0];$bestLen=mb_strlen((string)$best['model']);
    if(isset($matches[1])&&mb_strlen((string)$matches[1]['model'])===$bestLen)return null;
    return $best;
}

function vehicle_spec_registry_profile_keys(): array {
    return array_column(vehicle_spec_registry_profiles(),'key');
}


function ensure_vehicle_spec_registry_schema(PDO $pdo): void {
    $componentCols=table_columns($pdo,'vehicle_components');
    $defs=[
        'source_profile_key'=>'VARCHAR(120) NULL',
        'source_profile_version'=>'VARCHAR(40) NULL',
    ];
    foreach($defs as $name=>$def)if(!isset($componentCols[$name]))$pdo->exec("ALTER TABLE vehicle_components ADD COLUMN `$name` $def");

    $pdo->exec("CREATE TABLE IF NOT EXISTS vehicle_spec_research_queue (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(500) NOT NULL,
        brand VARCHAR(180) NULL,
        model VARCHAR(255) NULL,
        match_key VARCHAR(120) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'unmatched',
        first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY idx_vehicle_spec_research_product(product_id),
        INDEX idx_vehicle_spec_research_status(status,last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vehicle_spec_registry_scan_catalog(PDO $pdo,int $limit=1500): array {
    $limit=max(1,min(5000,$limit));
    $s=$pdo->query("SELECT id,name,brand,model,category_path,is_active,stock_qty FROM products WHERE is_active=1 AND COALESCE(stock_qty,0)>0 ORDER BY id DESC LIMIT ".$limit);
    $upsert=$pdo->prepare("INSERT INTO vehicle_spec_research_queue(product_id,title,brand,model,match_key,status,first_seen_at,last_seen_at) VALUES(?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE title=VALUES(title),brand=VALUES(brand),model=VALUES(model),match_key=VALUES(match_key),status=VALUES(status),last_seen_at=NOW()");
    $matched=0;$unmatched=0;$rows=[];
    foreach($s->fetchAll() as $row){
        $type=function_exists('customer_vehicle_type')?customer_vehicle_type((string)$row['name'],(string)($row['category_path']??'')):null;
        if($type!=='bicycle')continue;
        $profile=vehicle_spec_registry_match((string)$row['name']);$status=$profile?'matched':'unmatched';
        $upsert->execute([(int)$row['id'],(string)$row['name'],$row['brand']!==null?(string)$row['brand']:null,$row['model']!==null?(string)$row['model']:null,$profile['key']??null,$status]);
        $status==='matched'?$matched++:$unmatched++;
        $rows[]=['product_id'=>(int)$row['id'],'title'=>(string)$row['name'],'brand'=>$row['brand'],'model'=>$row['model'],'status'=>$status,'profile_key'=>$profile['key']??null];
    }
    return ['matched'=>$matched,'unmatched'=>$unmatched,'total'=>$matched+$unmatched,'items'=>$rows];
}

function vehicle_spec_registry_apply_vehicle(PDO $pdo,int $vehicleId): array {
    if($vehicleId<1)return ['matched'=>false,'inserted'=>0,'updated'=>0,'profile'=>null];
    $q=$pdo->prepare("SELECT id,title,vehicle_type,purchase_date,created_at FROM customer_vehicles WHERE id=? AND is_active=1 LIMIT 1");$q->execute([$vehicleId]);$vehicle=$q->fetch();
    if(!$vehicle||(string)$vehicle['vehicle_type']!=='bicycle')return ['matched'=>false,'inserted'=>0,'updated'=>0,'profile'=>null];
    $profile=vehicle_spec_registry_match((string)$vehicle['title']);if(!$profile)return ['matched'=>false,'inserted'=>0,'updated'=>0,'profile'=>null];
    $installedAt=(string)($vehicle['purchase_date']?:$vehicle['created_at']?:'');$installedAt=$installedAt!==''?$installedAt:null;
    $find=$pdo->prepare('SELECT id,source_type,source_profile_key,source_verified_at FROM vehicle_components WHERE vehicle_id=? AND component_key=? LIMIT 1');
    $insert=$pdo->prepare("INSERT INTO vehicle_components(vehicle_id,component_key,hotspot_key,label,manufacturer,model,source_type,source_url,source_note,source_verified_at,source_profile_key,source_profile_version,wear_mode,installed_at,is_active) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
    $update=$pdo->prepare("UPDATE vehicle_components SET hotspot_key=?,label=?,manufacturer=?,model=?,source_type='official',source_url=?,source_note=?,source_verified_at=?,source_profile_key=?,source_profile_version=?,is_active=1 WHERE id=?");
    $event=$pdo->prepare("INSERT IGNORE INTO vehicle_component_events(component_id,event_type,event_at,include_learning,note) VALUES(?,'installed',?,0,'Начальная установка по покупке техники')");
    $inserted=0;$updated=0;
    foreach($profile['components'] as $component){
        $find->execute([$vehicleId,(string)$component['component_key']]);$existing=$find->fetch();
        if($existing){
            $sourceType=(string)$existing['source_type'];$existingProfile=(string)($existing['source_profile_key']??'');
            // Manually/service curated data and separately verified official data always win over the built-in registry.
            if(in_array($sourceType,['manual','service'],true))continue;
            if($sourceType==='official'&&$existingProfile!==''&&$existingProfile!==(string)$profile['key'])continue;
            if($sourceType==='official'&&$existingProfile===''&&!empty($existing['source_verified_at']))continue;
            $update->execute([
                (string)$component['hotspot_key'],(string)$component['label'],$component['manufacturer']??null,(string)$component['model'],
                (string)$component['source_url'],(string)$component['source_note'],(string)$component['source_verified_at'],
                (string)$profile['key'],'2026-09-26',(int)$existing['id']
            ]);$updated++;
            continue;
        }
        $insert->execute([
            $vehicleId,(string)$component['component_key'],(string)$component['hotspot_key'],(string)$component['label'],$component['manufacturer']??null,(string)$component['model'],
            'official',(string)$component['source_url'],(string)$component['source_note'],(string)$component['source_verified_at'],
            (string)$profile['key'],'2026-09-26','inspection',$installedAt
        ]);
        $id=(int)$pdo->lastInsertId();if($id>0&&$installedAt)$event->execute([$id,$installedAt]);$inserted++;
    }
    $preciseKeys=array_column($profile['components'],'component_key');
    $legacy=[];
    if(in_array('front_brake',$preciseKeys,true)||in_array('rear_brake',$preciseKeys,true))$legacy[]='brakes';
    if(in_array('front_tire',$preciseKeys,true)||in_array('rear_tire',$preciseKeys,true))$legacy[]='tires';
    if($legacy){
        $marks=implode(',',array_fill(0,count($legacy),'?'));
        $hide=$pdo->prepare("UPDATE vehicle_components SET is_active=0 WHERE vehicle_id=? AND source_type='1c_spec' AND component_key IN ($marks)");
        $hide->execute(array_merge([$vehicleId],$legacy));
    }
    return ['matched'=>true,'inserted'=>$inserted,'updated'=>$updated,'profile'=>$profile['key']];
}

function vehicle_spec_registry_apply_all(PDO $pdo,int $limit=5000): array {
    $limit=max(1,min(10000,$limit));
    $s=$pdo->query("SELECT id FROM customer_vehicles WHERE is_active=1 AND vehicle_type='bicycle' ORDER BY id LIMIT ".$limit);
    $matched=0;$inserted=0;$updated=0;
    foreach($s->fetchAll() as $row){
        $result=vehicle_spec_registry_apply_vehicle($pdo,(int)$row['id']);
        if($result['matched'])$matched++;$inserted+=(int)$result['inserted'];$updated+=(int)$result['updated'];
    }
    return ['vehicles_matched'=>$matched,'components_inserted'=>$inserted,'components_updated'=>$updated];
}

function vehicle_spec_registry_queue(PDO $pdo,string $status='unmatched',int $limit=200): array {
    $status=in_array($status,['matched','unmatched'],true)?$status:'unmatched';$limit=max(1,min(1000,$limit));
    $s=$pdo->prepare("SELECT product_id,title,brand,model,match_key,status,first_seen_at,last_seen_at FROM vehicle_spec_research_queue WHERE status=? ORDER BY last_seen_at DESC,product_id DESC LIMIT ".$limit);
    $s->execute([$status]);return $s->fetchAll();
}
