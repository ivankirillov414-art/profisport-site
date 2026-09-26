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
    ];
    foreach($cases as [$title,$key]){
        $profile=vehicle_spec_registry_match($title);
        vsr_check($profile!==null&&$profile['key']===$key,'registry match failed for '.$title);
    }
    vsr_check(vehicle_spec_registry_match('Велосипед 29 Aspect Nickel Pro (2026), Зеленый')===null,'wrong model year must not match');
    vsr_check(vehicle_spec_registry_match('Велосипед 26 Aspect Nickel Pro (2025), Зеленый')===null,'unsupported wheel size must not match');
    vsr_check(count(vehicle_spec_registry_profiles())===17,'verified registry batch must contain seventeen exact profiles');

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
    $scan=vehicle_spec_registry_scan_catalog($pdo,5000);
    $queue=vehicle_spec_registry_queue($pdo,'unmatched',1000);
    vsr_check(count(array_filter($queue,fn($x)=>(int)$x['product_id']===$unknownId))===1,'unverified bicycle must remain in research queue');

    $pdo->rollBack();
    echo "PASS: verified Aspect registry matching, official provenance, source precedence and research queue\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
