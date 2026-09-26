<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';

function vsr_check(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function vsr_component(PDO $pdo,int $vehicleId,string $key): ?array {
    $s=$pdo->prepare('SELECT * FROM vehicle_components WHERE vehicle_id=? AND component_key=? LIMIT 1');$s->execute([$vehicleId,$key]);$r=$s->fetch();return $r?:null;
}

$pdo->beginTransaction();
try{
    $cases=[
        ['Велосипед 29 Aspect Nickel Pro (2025), Зеленый','aspect-nickel-pro-2025-29'],
        ['Велосипед 27,5 Aspect Nickel Elite (2025), Серый','aspect-nickel-elite-2025-27.5'],
        ['Велосипед 29 Aspect Cobalt Pro (2025), Черный','aspect-cobalt-pro-2025-29'],
        ['Велосипед 27.5 Aspect Cobalt (2025), Красный','aspect-cobalt-2025-27.5'],
        ['Велосипед 27,5 Aspect Aura (2025), Белый','aspect-aura-2025-27.5'],
        ['Велосипед 27.5 Aspect Oasis (2026), Серый','aspect-oasis-2026-27.5'],
        ['Велосипед 27,5 Aspect Oasis Pro (2026), Серый','aspect-oasis-pro-2026-27.5'],
        ['Велосипед 27,5 Hagen 3.9, 2025, штормовой синий, металлик','hagen-3.9-2025-27.5'],
        ['Велосипед 27,5 Hagen 3.11, 2025, черный металлик, полумат','hagen-3.11-2025-27.5'],
        ['Велосипед 27,5 Welt Rocket 3.0 HD Punk Khaki (2026)','welt-rocket-3.0-hd-2026-27.5'],
        ['Велосипед Stark Router 29.3 HD (2025)','stark-router-29.3-hd-2025'],
        ['Велосипед Stark Router 27.4 HD (2024)','stark-router-27.4-hd-2024'],
        ['Велосипед Stark Viva 27.5 HD (2025)','stark-viva-27-5-hd-2025'],
        ['Велосипед Stark Viva 27.2 D (2025)','stark-viva-27-2-d-2025'],
        ['Велосипед 26 Welt Storm 26 MD Pure Black (2026)','welt-storm-26-md-2026-26'],
        ['Велосипед 29 Welt Icon 2.0 29 Steel Graphite (2026)','welt-icon-2.0-2026-29'],
        ['Велосипед 27,5 Welt Icon 2.0 27,5 Steel Graphite (2026)','welt-icon-2.0-2026-27.5'],
        ['Велосипед Welt Brave 1.0 20 VB Calm Green (2026)','welt-brave-1.0-20-vb-2026-20'],
        ['Велосипед Welt Brave 1.0 24 MD Bizarre Green (2026)','welt-brave-1.0-24-md-2026-24'],
        ['Велосипед Welt Brave 2.0 24 HD Deep Purple (2026)','welt-brave-2.0-24-hd-2026-24'],
        ['Велосипед 20 Aspect Air Зеленый (2026)','aspect-air-20-2026-20'],
        ['Велосипед 20 Aspect Aura Фиолетовый (2026)','aspect-aura-20-2026-20'],
        ['Велосипед Stark Router 27.3 HD (2024), красный','stark-router-27.3-hd-2024'],
        ['Велосипед STARK Router 29.3 HD 2024','stark-router-29.3-hd-2024'],
        ['Велосипед Stark Router 27.4 HD (2024)','stark-router-27.4-hd-2024'],
        ['Велосипед Stark Router 29.4 HD (2024)','stark-router-29.4-hd-2024'],
        ['Велосипед Stark Router 29.3 HD (2025)','stark-router-29.3-hd-2025'],
        ['Велосипед Stark Viva 27.2 D (2025)','stark-viva-27-2-d-2025'],
        ['Велосипед Stark Viva 27.2 HD (2025)','stark-viva-27-2-hd-2025'],
        ['Велосипед Stark Viva 27.3 HD (2025)','stark-viva-27-3-hd-2025'],
        ['Велосипед Stark Viva 27.5 HD (2025)','stark-viva-27-5-hd-2025'],
    ];
    foreach($cases as [$title,$key]){
        $profile=vehicle_spec_registry_match($title);
        vsr_check($profile!==null&&$profile['key']===$key,'registry match failed for '.$title);
    }
    vsr_check(vehicle_spec_registry_match('Велосипед 29 Aspect Nickel Pro (2026), Зеленый')===null,'wrong model year must not match');
    vsr_check(vehicle_spec_registry_match('Велосипед 26 Aspect Nickel Pro (2025), Зеленый')===null,'unsupported wheel size must not match');
    vsr_check(vehicle_spec_registry_match('Велосипед Stark Router 29.4 HD (2025)')===null,'STARK profile with wrong year must not match');
    vsr_check(vehicle_spec_registry_match('Велосипед Stark Viva 27.2 HD (2024)')===null,'STARK Viva profile with wrong year must not match');
    vsr_check(vehicle_spec_registry_match('Велосипед 20 Aspect Air Зеленый (2025)')===null,'Aspect AIR 20 wrong year must not match');
    vsr_check(vehicle_spec_registry_match('Велосипед 24 Aspect Air Зеленый (2026)')===null,'Aspect AIR wrong wheel size must not match');
    $moovixConflict=vehicle_spec_registry_conflict_match('Велосипед Welt Moovix 1.0 MD 24 Shiny Orange (2026)');
    vsr_check($moovixConflict!==null&&$moovixConflict['key']==='conflict-welt-moovix-1.0-md-24-2026','known Moovix source disagreement must be classified as conflict');
    vsr_check(vehicle_spec_registry_match('Велосипед Welt Moovix 1.0 MD 24 Shiny Orange (2026)')===null,'conflicted Moovix must not receive an automatic verified profile');
    vsr_check(count(vehicle_spec_registry_profiles())===34,'verified registry must contain thirty-four exact profiles after Aspect kids batch');

    $product=$pdo->prepare("INSERT INTO products(title,name,brand,model,price_rub,price,stock_qty,stock_status,availability,is_active,category_path,main_image,images) VALUES(?,?,?,?,120000,120000,2,'in_stock','in_stock',1,'Велосипеды / Горные',NULL,'[]')");
    $title='Велосипед 29 Aspect Nickel Pro (2025), Зеленый';
    $product->execute([$title,$title,'Aspect','Nickel Pro']);$productId=(int)$pdo->lastInsertId();
    $customer=$pdo->prepare("INSERT INTO customers(name,email,phone,password_hash,bonus_balance) VALUES('Registry Test','registry-test@example.test','+79990000007',NULL,0)");
    $customer->execute();$customerId=(int)$pdo->lastInsertId();
    $vehicle=$pdo->prepare("INSERT INTO customer_vehicles(customer_id,product_id,title,vehicle_type,order_number,purchase_date,is_active) VALUES(?,?,?,'bicycle','REG-1','2026-01-01 00:00:00',1)");
    $vehicle->execute([$customerId,$productId,$title]);$vehicleId=(int)$pdo->lastInsertId();

    $result=vehicle_spec_registry_apply_vehicle($pdo,$vehicleId);
    vsr_check($result['matched']===true&&$result['profile']==='aspect-nickel-pro-2025-29','verified profile must apply to exact customer vehicle');
    $frontBrake=vsr_component($pdo,$vehicleId,'front_brake');
    $frontPads=vsr_component($pdo,$vehicleId,'front_brake_pads');
    $rearPads=vsr_component($pdo,$vehicleId,'rear_brake_pads');
    vsr_check($frontBrake&&$frontBrake['model']==='BR-MT200 Hydraulic Disc'&&$frontBrake['manufacturer']==='Shimano','official MT200 brake must be stored');
    vsr_check($frontPads&&$frontPads['model']==='B05S-RX Resin'&&$rearPads&&$rearPads['model']==='B05S-RX Resin','MT200 pads must be added only from Shimano compatibility evidence');
    vsr_check($frontPads['wear_mode']==='inspection'&&$frontPads['baseline_life_value']===null,'pad lifetime must remain unknown rather than invented');
    vsr_check($frontPads['source_type']==='official'&&$frontPads['source_profile_key']==='aspect-nickel-pro-2025-29','official profile provenance must be stored');
    vsr_check(str_contains((string)$frontPads['source_url'],'shimano.com'),'pad compatibility source must be Shimano');

    $starkTitle='Велосипед Stark Router 29.3 HD (2025)';
    $product->execute([$starkTitle,$starkTitle,'Stark','Router 29.3 HD']);$starkProductId=(int)$pdo->lastInsertId();
    $vehicle->execute([$customerId,$starkProductId,$starkTitle]);$starkVehicleId=(int)$pdo->lastInsertId();
    $starkResult=vehicle_spec_registry_apply_vehicle($pdo,$starkVehicleId);
    vsr_check($starkResult['matched']===true&&$starkResult['profile']==='stark-router-29.3-hd-2025','exact STARK 2025 profile must apply');
    vsr_check(vsr_component($pdo,$starkVehicleId,'front_brake')['model']==='Tektro HD-M275 hydraulic disc','STARK official M275 brake must be stored');
    vsr_check(vsr_component($pdo,$starkVehicleId,'front_brake_pads')===null,'Tektro M275 pads must remain unconfirmed without a primary compatibility source');

    $weltTitle='Велосипед 29 Welt Icon 2.0 29 Steel Graphite (2026)';
    $product->execute([$weltTitle,$weltTitle,'Welt','Icon 2.0']);$weltProductId=(int)$pdo->lastInsertId();
    $vehicle->execute([$customerId,$weltProductId,$weltTitle]);$weltVehicleId=(int)$pdo->lastInsertId();
    $welt=vehicle_spec_registry_apply_vehicle($pdo,$weltVehicleId);
    vsr_check($welt['matched']===true&&$welt['profile']==='welt-icon-2.0-2026-29','exact WELT Icon 2.0 2026 profile must apply');
    $weltBrake=vsr_component($pdo,$weltVehicleId,'front_brake');
    $weltPads=vsr_component($pdo,$weltVehicleId,'front_brake_pads');
    vsr_check($weltBrake&&$weltBrake['model']==='MT-200 Hydraulic Disc, 180/160mm','WELT Icon must store official MT-200 brake');
    vsr_check($weltPads&&$weltPads['model']==='B05S-RX Resin'&&str_contains((string)$weltPads['source_url'],'shimano.com'),'WELT MT-200 pads must come from Shimano compatibility evidence');
    vsr_check($weltPads['source_profile_version']===VEHICLE_SPEC_REGISTRY_VERSION,'component registry version must track the exact applied registry release');

    $braveTitle='Велосипед Welt Brave 2.0 24 HD Deep Purple (2026)';
    $product->execute([$braveTitle,$braveTitle,'Welt','Brave 2.0 24 HD']);$braveProductId=(int)$pdo->lastInsertId();
    $vehicle->execute([$customerId,$braveProductId,$braveTitle]);$braveVehicleId=(int)$pdo->lastInsertId();
    $brave=vehicle_spec_registry_apply_vehicle($pdo,$braveVehicleId);
    vsr_check($brave['matched']===true&&$brave['profile']==='welt-brave-2.0-24-hd-2026-24','exact WELT Brave 2.0 24 HD 2026 profile must apply');
    $braveBrake=vsr_component($pdo,$braveVehicleId,'front_brake');
    vsr_check($braveBrake&&$braveBrake['model']==='TKD176 Hydraulic Disc'&&$braveBrake['manufacturer']==='Tektro','Brave 2.0 must store official TKD176 brake');
    vsr_check(vsr_component($pdo,$braveVehicleId,'front_brake_pads')===null,'TKD176 pads must stay unconfirmed without separate compatibility evidence');
    vsr_check(vsr_component($pdo,$braveVehicleId,'cassette')['model']==='HG200-8 12-32T','Brave 2.0 must use the official Russian specifications block');

    $airTitle='Велосипед 20 Aspect Air Зеленый (2026)';
    $product->execute([$airTitle,$airTitle,'Aspect','Air']);$airProductId=(int)$pdo->lastInsertId();
    $vehicle->execute([$customerId,$airProductId,$airTitle]);$airVehicleId=(int)$pdo->lastInsertId();
    $air=vehicle_spec_registry_apply_vehicle($pdo,$airVehicleId);
    vsr_check($air['matched']===true&&$air['profile']==='aspect-air-20-2026-20','Aspect AIR 20 2026 profile must apply');
    vsr_check(vsr_component($pdo,$airVehicleId,'front_tire')['model']==='Chaoyang H-5129 20x2.0','Aspect AIR 20 official tire must be stored');
    vsr_check(vsr_component($pdo,$airVehicleId,'front_brake')['model']==='V-brake','Aspect AIR 20 must keep official V-brake fact');

    $auraTitle='Велосипед 20 Aspect Aura Фиолетовый (2026)';
    $product->execute([$auraTitle,$auraTitle,'Aspect','Aura']);$auraProductId=(int)$pdo->lastInsertId();
    $vehicle->execute([$customerId,$auraProductId,$auraTitle]);$auraVehicleId=(int)$pdo->lastInsertId();
    $aura=vehicle_spec_registry_apply_vehicle($pdo,$auraVehicleId);
    vsr_check($aura['matched']===true&&$aura['profile']==='aspect-aura-20-2026-20','Aspect AURA 20 2026 profile must apply');
    vsr_check(vsr_component($pdo,$auraVehicleId,'front_tire')===null&&vsr_component($pdo,$auraVehicleId,'rear_tire')===null,'ambiguous AURA tire rows must not be imported automatically');

    $starkTitle='Велосипед Stark Router 29.4 HD (2024)';
    $product->execute([$starkTitle,$starkTitle,'Stark','Router 29.4 HD']);$starkProductId=(int)$pdo->lastInsertId();
    $vehicle->execute([$customerId,$starkProductId,$starkTitle]);$starkVehicleId=(int)$pdo->lastInsertId();
    $stark=vehicle_spec_registry_apply_vehicle($pdo,$starkVehicleId);
    vsr_check($stark['matched']===true&&$stark['profile']==='stark-router-29.4-hd-2024','exact STARK Router profile must apply');
    $starkBrake=vsr_component($pdo,$starkVehicleId,'front_brake');
    vsr_check($starkBrake&&$starkBrake['model']==='Tektro HD-M275 hydraulic disc','STARK Router must store official Tektro brake model');
    vsr_check(vsr_component($pdo,$starkVehicleId,'front_brake_pads')===null,'Tektro M275 pads must remain unassigned without separate compatibility evidence');
    vsr_check($starkBrake['source_type']==='official'&&str_contains((string)$starkBrake['source_url'],'stark.ru'),'STARK component must carry official manufacturer source');

    $pdo->prepare("UPDATE vehicle_components SET source_type='service',source_profile_key=NULL,model='Workshop confirmed pad',source_verified_at=NOW() WHERE id=?")->execute([(int)$frontPads['id']]);
    $again=vehicle_spec_registry_apply_vehicle($pdo,$vehicleId);
    $frontPads=vsr_component($pdo,$vehicleId,'front_brake_pads');
    vsr_check($frontPads['model']==='Workshop confirmed pad'&&$frontPads['source_type']==='service','service-confirmed component must win over registry refresh');

    $manual=$pdo->prepare("INSERT INTO vehicle_components(vehicle_id,component_key,hotspot_key,label,model,source_type,source_verified_at,wear_mode,is_active) VALUES(?, 'custom_fact','general','Ручной узел','Manual fact','manual',NOW(),'inspection',1)");
    $manual->execute([$vehicleId]);vehicle_spec_registry_apply_vehicle($pdo,$vehicleId);
    vsr_check(vsr_component($pdo,$vehicleId,'custom_fact')['model']==='Manual fact','unrelated manual facts must survive registry apply');

    $scan=vehicle_spec_registry_scan_catalog($pdo,5000);
    $matched=array_values(array_filter($scan['items'],fn($x)=>(int)$x['product_id']===$productId));
    vsr_check(count($matched)===1&&$matched[0]['status']==='matched'&&$matched[0]['profile_key']==='aspect-nickel-pro-2025-29','catalog scan must expose exact verified match');

    $unknownTitle='Велосипед 29 Unknown Research Bike (2026)';
    $product->execute([$unknownTitle,$unknownTitle,'Unknown','Research Bike']);$unknownId=(int)$pdo->lastInsertId();
    $moovixTitle='Велосипед Welt Moovix 1.0 MD 24 Shiny Orange (2026)';
    $product->execute([$moovixTitle,$moovixTitle,'Welt','Moovix 1.0 MD 24']);$moovixId=(int)$pdo->lastInsertId();
    $vehicle->execute([$customerId,$moovixId,$moovixTitle]);$moovixVehicleId=(int)$pdo->lastInsertId();
    $moovixApply=vehicle_spec_registry_apply_vehicle($pdo,$moovixVehicleId);
    vsr_check($moovixApply['matched']===false,'conflicted Moovix must never auto-apply official components');
    $scan=vehicle_spec_registry_scan_catalog($pdo,5000);
    vsr_check(($scan['conflicts']??0)>=1,'catalog scan must count source conflicts separately');
    $queue=vehicle_spec_registry_queue($pdo,'unmatched',1000);
    vsr_check(count(array_filter($queue,fn($x)=>(int)$x['product_id']===$unknownId))===1,'unverified bicycle must remain in research queue');
    $conflicts=vehicle_spec_registry_queue($pdo,'conflict',1000);
    $moovixRows=array_values(array_filter($conflicts,fn($x)=>(int)$x['product_id']===$moovixId));
    vsr_check(count($moovixRows)===1&&str_contains((string)$moovixRows[0]['note'],'расходятся'),'known source disagreement must stay in conflict queue with an explanation');
    vsr_check(str_contains((string)$moovixRows[0]['reference_url'],'welt-bikes.com'),'conflict queue must retain the official comparison source');

    $pdo->prepare('DELETE FROM site_settings WHERE setting_key=?')->execute(['vehicle_spec_registry_applied_version']);
    $sync=vehicle_spec_registry_sync_once($pdo);
    vsr_check($sync['ran']===true&&$sync['version']===VEHICLE_SPEC_REGISTRY_VERSION,'first registry maintenance sync must apply current version');
    $againSync=vehicle_spec_registry_sync_once($pdo);
    vsr_check($againSync['ran']===false&&$againSync['reason']==='current','registry maintenance sync must be idempotent for current version');
    vsr_check(vehicle_spec_registry_applied_version($pdo)===VEHICLE_SPEC_REGISTRY_VERSION,'applied registry version must be persisted');

    $pdo->rollBack();
    echo "PASS: verified Aspect/Hagen/Welt/STARK registry matching, official provenance, source precedence and research queue\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
