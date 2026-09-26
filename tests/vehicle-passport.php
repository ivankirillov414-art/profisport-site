<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
function vp_check(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }

$pdo->beginTransaction();
try{
    $specUpdate=$pdo->prepare('UPDATE products SET specs=? WHERE id=3');
    $specUpdate->execute([json_encode(['Цепь'=>'KMC TEST','Тормоза'=>'Shimano TEST','Цвет'=>'Black'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
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

    $alertComponent=vehicle_passport_save_component($pdo,$vehicleId,[
        'component_key'=>'test_alert_part','hotspot_key'=>'general','label'=>'Тестовый расходник','source_type'=>'service','source_verified'=>true,
        'wear_mode'=>'time','baseline_life_value'=>100,'baseline_life_unit'=>'days','installed_at'=>(new DateTimeImmutable('-85 days'))->format('Y-m-d H:i:s')
    ],1);
    $alertId=(int)$alertComponent['id'];
    vehicle_passport_record_event($pdo,$alertId,['event_type'=>'installed','event_at'=>(new DateTimeImmutable('-85 days'))->format('Y-m-d H:i:s'),'include_learning'=>false],1);
    $passport=vehicle_passport_payload($pdo,$vehicleId,false);
    $alerts=vehicle_maintenance_alerts_for_customer($pdo,$customerId,true);
    $alert=array_values(array_filter($alerts,fn($x)=>(int)$x['component_id']===$alertId))[0]??null;
    vp_check($alert!==null&&$alert['severity']==='soon'&&(float)$alert['wear_percent']>=80,'85 percent time wear must create a soon maintenance alert');
    vp_check(vehicle_maintenance_acknowledge($pdo,$customerId,(int)$alert['id'])===true,'customer must be able to acknowledge own open alert');
    $alerts=vehicle_maintenance_alerts_for_customer($pdo,$customerId,true);
    $ack=array_values(array_filter($alerts,fn($x)=>(int)$x['component_id']===$alertId))[0]??null;
    vp_check($ack!==null&&$ack['status']==='acknowledged','acknowledged alert must remain visible until technical resolution');

    $pdo->prepare('UPDATE vehicle_components SET baseline_life_value=85 WHERE id=?')->execute([$alertId]);
    vehicle_passport_payload($pdo,$vehicleId,false);
    $alerts=vehicle_maintenance_alerts_for_customer($pdo,$customerId,true);
    $escalated=array_values(array_filter($alerts,fn($x)=>(int)$x['component_id']===$alertId))[0]??null;
    vp_check($escalated!==null&&$escalated['severity']==='due'&&$escalated['status']==='open','severity escalation must reopen an acknowledged alert');

    vehicle_passport_record_event($pdo,$alertId,['event_type'=>'replaced','event_at'=>date('Y-m-d H:i:s'),'include_learning'=>true],1);
    $passport=vehicle_passport_payload($pdo,$vehicleId,false);
    $alerts=vehicle_maintenance_alerts_for_customer($pdo,$customerId,true);
    vp_check(count(array_filter($alerts,fn($x)=>(int)$x['component_id']===$alertId))===0,'confirmed replacement must automatically resolve the maintenance alert');

    $dueComponent=vehicle_passport_save_component($pdo,$vehicleId,[
        'component_key'=>'test_due_part','hotspot_key'=>'general','label'=>'Просроченный расходник','source_type'=>'service','source_verified'=>true,
        'wear_mode'=>'time','baseline_life_value'=>100,'baseline_life_unit'=>'days','installed_at'=>(new DateTimeImmutable('-100 days'))->format('Y-m-d H:i:s')
    ],1);
    $dueId=(int)$dueComponent['id'];vehicle_passport_record_event($pdo,$dueId,['event_type'=>'installed','event_at'=>(new DateTimeImmutable('-100 days'))->format('Y-m-d H:i:s'),'include_learning'=>false],1);
    vehicle_passport_payload($pdo,$vehicleId,false);
    $dueAlerts=vehicle_maintenance_alerts_for_customer($pdo,$customerId,true);
    $due=array_values(array_filter($dueAlerts,fn($x)=>(int)$x['component_id']===$dueId))[0]??null;
    vp_check($due!==null&&$due['severity']==='due','95+ percent wear must create a due alert');

    $pdo->prepare('DELETE FROM site_settings WHERE setting_key=?')->execute(['vehicle_maintenance_last_refresh_date']);
    $daily=vehicle_maintenance_refresh_daily($pdo);vp_check($daily['ran']===true,'first daily maintenance refresh must run');
    $dailyAgain=vehicle_maintenance_refresh_daily($pdo);vp_check($dailyAgain['ran']===false&&$dailyAgain['reason']==='current','second maintenance refresh on the same day must be a no-op');

    $productInsert=$pdo->prepare("INSERT INTO products(title,name,brand,model,sku,price_rub,price,stock_qty,stock_status,availability,is_active,category_path,main_image,images) VALUES(?,?,?,?,?,1200,1200,3,'in_stock','in_stock',1,'Велокомплектующие / Тормоза',NULL,'[]')");
    $productInsert->execute(['Колодки Shimano B05S-RX Resin','Колодки Shimano B05S-RX Resin','Shimano','B05S-RX Resin','B05S-RX']);$padProductId=(int)$pdo->lastInsertId();
    $pad=vehicle_passport_save_component($pdo,$vehicleId,[
        'component_key'=>'rear_brake_pads','hotspot_key'=>'rear_brake','label'=>'Задние тормозные колодки','manufacturer'=>'Shimano','model'=>'B05S-RX Resin',
        'source_type'=>'official','source_url'=>'https://example.test/b05s','source_verified'=>true,'wear_mode'=>'inspection'
    ],1);
    $parts=vehicle_passport_components($pdo,$vehicleId,false);$linked=next_component($parts,'rear_brake_pads');
    vp_check($linked!==null&&(int)$linked['compatible_product_id']===$padProductId,'one exact in-stock model/manufacturer match must auto-link a compatible product');
    vp_check(($linked['compatible_product']['available']??false)===true,'linked compatible product must expose live availability');

    $productInsert->execute(['Колодки Shimano B05S-RX Resin OEM','Колодки Shimano B05S-RX Resin OEM','Shimano','B05S-RX Resin','B05S-RX-OEM']);
    $pdo->prepare('UPDATE vehicle_components SET compatible_product_id=NULL WHERE id=?')->execute([(int)$pad['id']]);
    vehicle_passport_auto_link_compatible_products($pdo,$vehicleId);
    $linked=component_row($pdo,(int)$pad['id']);
    vp_check($linked['compatible_product_id']===null,'multiple exact catalog candidates must stay unlinked instead of guessing');

    $weak=vehicle_passport_save_component($pdo,$vehicleId,[
        'component_key'=>'front_tire','hotspot_key'=>'front_tire','label'=>'Передняя покрышка','manufacturer'=>'Mitas','model'=>'Ocelot',
        'source_type'=>'official','source_url'=>'https://example.test/ocelot','source_verified'=>true,'wear_mode'=>'inspection'
    ],1);
    $weakProduct=$pdo->prepare("INSERT INTO products(title,name,brand,model,sku,price_rub,price,stock_qty,stock_status,availability,is_active,category_path,main_image,images) VALUES('Покрышка Mitas Ocelot','Покрышка Mitas Ocelot','Mitas','Ocelot','OCELOT',2200,2200,2,'in_stock','in_stock',1,'Велокомплектующие / Покрышки',NULL,'[]')");
    $weakProduct->execute();vehicle_passport_auto_link_compatible_products($pdo,$vehicleId);$weakRow=component_row($pdo,(int)$weak['id']);
    vp_check($weakRow['compatible_product_id']===null,'model without a strong alphanumeric identifier must not auto-link');

    $pdo->rollBack();
    echo "PASS: vehicle passport truth, adaptive wear, alerts and strict unique compatible-product linking\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

function next_component(array $rows,string $key): ?array { foreach($rows as $row)if(($row['component_key']??'')===$key)return $row;return null; }
function component_row(PDO $pdo,int $id): array { $s=$pdo->prepare('SELECT * FROM vehicle_components WHERE id=?');$s->execute([$id]);$row=$s->fetch();if(!$row)throw new RuntimeException('component missing');return $row; }
