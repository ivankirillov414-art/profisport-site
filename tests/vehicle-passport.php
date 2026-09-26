<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
function vp_check(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }

$pdo->beginTransaction();
try{
    $pdo->exec("UPDATE products SET specs='{"Цепь":"KMC TEST","Тормоза":"Shimano TEST","Цвет":"Black"}' WHERE id=3");
    $s=$pdo->prepare("INSERT INTO customers(name,email,phone,password_hash,bonus_balance) VALUES('Passport test','passport-test@example.test','+79990000008',NULL,0)");
    $s->execute();$customerId=(int)$pdo->lastInsertId();
    $snapshot=json_encode(['Цепь'=>'KMC SNAPSHOT','Тормоза'=>'Shimano SNAPSHOT','Цвет'=>'Blue'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $s=$pdo->prepare("INSERT INTO customer_vehicles(customer_id,product_id,title,vehicle_type,order_number,purchase_date,spec_snapshot,is_active) VALUES(?,3,'Demo bicycle','bicycle','PASS-1',NOW(),?,1)");
    $s->execute([$customerId,$snapshot]);$vehicleId=(int)$pdo->lastInsertId();

    $seeded=vehicle_passport_seed_vehicle($pdo,$vehicleId);vp_check($seeded===2,'only explicit recognized 1C specs should seed components');
    $components=vehicle_passport_components($pdo,$vehicleId,true);
    $chain=next_component($components,'chain');$brakes=next_component($components,'brakes');
    vp_check($chain!==null&&$chain['model']==='KMC SNAPSHOT','passport must use purchase-time spec snapshot, not changed product specs');
    vp_check($brakes!==null&&$brakes['model']==='Shimano SNAPSHOT','brake system snapshot must seed without inventing pad model');
    vp_check(next_component($components,'brake_pads')===null,'brake pads must not be inferred from a generic brake spec');

    $component=vehicle_passport_save_component($pdo,$vehicleId,[
        'component_key'=>'front_brake_pads','hotspot_key'=>'front_brake','label'=>'Передние тормозные колодки','manufacturer'=>'Test','model'=>'Pad A',
        'source_type'=>'official','source_url'=>'https://example.test/pad-a','source_note'=>'Official test data','source_verified'=>true,
        'wear_mode'=>'time','baseline_life_value'=>100,'baseline_life_unit'=>'days','installed_at'=>'2025-01-01 00:00:00'
    ],1);
    $componentId=(int)$component['id'];

    vehicle_passport_record_event($pdo,$componentId,['event_type'=>'installed','event_at'=>'2025-01-01 00:00:00','include_learning'=>true],1);
    vehicle_passport_record_event($pdo,$componentId,['event_type'=>'replaced','event_at'=>'2025-04-01 00:00:00','include_learning'=>true],1);
    $row=component_row($pdo,$componentId);$wear=vehicle_passport_wear($pdo,$row,null);
    vp_check($wear['personal_samples']===1&&abs((float)$wear['predicted_life']-99.0)<0.2,'one 90-day cycle should gently move 100-day baseline toward 99');

    vehicle_passport_record_event($pdo,$componentId,['event_type'=>'replaced','event_at'=>'2025-06-30 00:00:00','include_learning'=>true],1);
    $row=component_row($pdo,$componentId);$wear=vehicle_passport_wear($pdo,$row,null);
    vp_check($wear['personal_samples']===2&&abs((float)$wear['predicted_life']-95.0)<0.2,'two stable 90-day cycles should move prediction toward 95');

    vehicle_passport_record_event($pdo,$componentId,['event_type'=>'replaced','event_at'=>'2025-09-28 00:00:00','include_learning'=>false,'note'=>'Аварийная замена, не обучать'],1);
    $row=component_row($pdo,$componentId);$wear=vehicle_passport_wear($pdo,$row,null);
    vp_check($wear['personal_samples']===2&&abs((float)$wear['predicted_life']-95.0)<0.2,'excluded replacement must reset lifecycle without training the forecast');

    $measured=vehicle_passport_save_component($pdo,$vehicleId,[
        'component_key'=>'chain_wear','hotspot_key'=>'chain','label'=>'Износ цепи','source_type'=>'service','source_verified'=>true,
        'wear_mode'=>'measurement','baseline_measurement'=>0,'replacement_threshold'=>0.75,'measurement_unit'=>'%','measurement_direction'=>'up'
    ],1);
    vehicle_passport_record_event($pdo,(int)$measured['id'],['event_type'=>'measured','event_at'=>'2026-01-01 00:00:00','measurement_value'=>0.375,'measurement_unit'=>'%','include_learning'=>true],1);
    $row=component_row($pdo,(int)$measured['id']);$wear=vehicle_passport_wear($pdo,$row,null);
    vp_check(abs((float)$wear['percent']-50.0)<0.1&&$wear['basis']==='measurement','real service measurement must override timer-style estimation');

    $pdo->rollBack();
    echo "PASS: vehicle passport snapshot truth, no inferred parts, adaptive replacement learning and measurement wear\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

function next_component(array $rows,string $key): ?array { foreach($rows as $row)if(($row['component_key']??'')===$key)return $row;return null; }
function component_row(PDO $pdo,int $id): array { $s=$pdo->prepare('SELECT * FROM vehicle_components WHERE id=?');$s->execute([$id]);$row=$s->fetch();if(!$row)throw new RuntimeException('component missing');return $row; }
