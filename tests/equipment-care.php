<?php
declare(strict_types=1);
require_once __DIR__.'/../server/equipment-care.php';
require_once __DIR__.'/../server/customer-vehicles.php';
function care_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach(['Ролики раздвижные 31-34'=>'inline_skates','Скейтборд TT'=>'skateboard','Лонгборд TT'=>'skateboard','Беговые лыжи Fischer'=>'skis','Лыжи горные Rossignol'=>'skis','Сноуборд Atomic'=>'snowboard'] as $title=>$type)care_check(customer_vehicle_type($title,'')===$type,$title);
foreach(['Ролики Shimano для переключателя','Ролики для пресса','Лыжа боковая к снегокату'] as $title)care_check(customer_vehicle_type($title,'')===null,'accessory: '.$title);
care_check(customer_vehicle_type('Самокат TT','Запчасти / самокаты')===null,'accessory category');
foreach(['bicycle','scooter','inline_skates','skateboard','skis','snowboard'] as $type){
    $plan=equipment_care_templates(['vehicle_type'=>$type,'title'=>'Test']);
    care_check(count($plan)>=3,'plan '.$type);
    care_check(count(array_unique(array_column($plan,'key')))===count($plan),'unique care keys');
    foreach($plan as $item)care_check($item['interval']===null,'no invented lifetime');
}
$bike=['vehicle_type'=>'bicycle','title'=>'Bicycle'];
$generic=equipment_care_templates($bike,[['component_key'=>'chain','model'=>'KMC']]);
care_check($generic[0]['measurement_threshold']===null,'do not apply SRAM threshold to KMC');
$sram=equipment_care_templates($bike,[['component_key'=>'chain','model'=>'SRAM PC 870']])[0];
care_check($sram['measurement_threshold']===0.8,'SRAM explicit criterion');
$nordic=equipment_care_templates(['vehicle_type'=>'skis','title'=>'Беговые лыжи Rossignol']);
care_check(array_values(array_filter($nordic,fn($x)=>$x['key']==='bindings'))[0]['interval']===null,'no alpine interval on Nordic skis');
$alpine=equipment_care_templates(['vehicle_type'=>'skis','title'=>'Горные лыжи Rossignol']);
care_check(array_values(array_filter($alpine,fn($x)=>$x['key']==='bindings'))[0]['interval']===30,'Rossignol alpine binding inspection interval');
$events=[];
foreach([3000,2000,1000] as $i=>$usage)$events[]=['id'=>3-$i,'care_key'=>'chain','event_type'=>'replaced','condition_state'=>'good','usage_value'=>$usage,'usage_unit'=>'km','include_learning'=>1,'measurement_value'=>null];
$status=equipment_care_status($generic[0],array_slice($events,1),2500,'km');
care_check($status['wear_percent']===null,'one finished cycle insufficient');
$status=equipment_care_status($generic[0],$events,3500,'km');
care_check($status['wear_percent']===50.0&&$status['remaining']===500.0,'personal forecast from two completed cycles');
$inspection=['id'=>4,'care_key'=>'chain','event_type'=>'checked','condition_state'=>'good','usage_value'=>3500,'usage_unit'=>'km','include_learning'=>1,'measurement_value'=>null];
$status=equipment_care_status($generic[0],[$inspection,...$events],3900,'km');
care_check($status['wear_percent']===90.0&&$status['state']==='soon','inspection does not reset physical lifetime');
$abnormal=$inspection;$abnormal['event_type']='replaced';$abnormal['include_learning']=0;
$status=equipment_care_status($generic[0],[$abnormal,...$events],4000,'km');
care_check($status['personal_cycles']===2&&$status['wear_percent']===50.0,'abnormal replacement resets cycle without training');
$measured=$inspection;$measured['measurement_value']=0.4;
$status=equipment_care_status($sram,[$measured,...$events],3900,'km');
care_check($status['wear_percent']===50.0&&$status['basis']==='measurement','actual measurement overrides usage estimate');
$new=$events[0];$new['id']=5;$new['usage_value']=4000;
$status=equipment_care_status($sram,[$new,$measured,...$events],4000,'km');
care_check($status['wear_percent']===0.0&&$status['basis']==='personal','replacement invalidates earlier measurement');
echo "Equipment types, manufacturer scope, personal cycles, abnormal replacements and measured wear passed.\n";
