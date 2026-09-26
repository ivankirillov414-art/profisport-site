<?php
declare(strict_types=1);

const VEHICLE_SPEC_ASPECT_2025='https://aspect-bikes.ru/upload/media/aspect-catalog-2025.pdf';
const VEHICLE_SPEC_ASPECT_OASIS_2026='https://aspect-bikes.ru/catalog/aspect-oasis-275/';
const VEHICLE_SPEC_ASPECT_OASIS_PRO_2026='https://www.aspect-bikes.ru/catalog/aspect-oasis-pro-275/';
const VEHICLE_SPEC_SHIMANO_MT200='https://bike.shimano.com/en-SG/products/components/pdp.P-BR-MT200.html';

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
    if(count($matches)!==1)return null;
    return $matches[0];
}

function vehicle_spec_registry_profile_keys(): array {
    return array_column(vehicle_spec_registry_profiles(),'key');
}
