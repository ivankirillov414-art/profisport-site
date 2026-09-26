<?php
declare(strict_types=1);

const VEHICLE_SPEC_ASPECT_2025='https://aspect-bikes.ru/upload/media/aspect-catalog-2025.pdf';
const VEHICLE_SPEC_ASPECT_OASIS_2026='https://aspect-bikes.ru/catalog/aspect-oasis-275/';
const VEHICLE_SPEC_ASPECT_OASIS_PRO_2026='https://www.aspect-bikes.ru/catalog/aspect-oasis-pro-275/';
const VEHICLE_SPEC_SHIMANO_MT200='https://bike.shimano.com/en-SG/products/components/pdp.P-BR-MT200.html';
const VEHICLE_SPEC_HAGEN_39_2025='https://hagen.bike/threenine';
const VEHICLE_SPEC_HAGEN_311_2025='https://hagen.bike/mtbthreeelevenblack';
const VEHICLE_SPEC_WELT_ROCKET_30_HD_2026='https://www.welt-bikes.com/ru/ru/vse-velosipedy/gornye/Welt_Rocket_3.0_HD_26';
const VEHICLE_SPEC_STARK_ROUTER_273_2024='https://stark.ru/bikes/velosipedy/gornye/kross-kantri/router/router-27-3-hd-2024/';
const VEHICLE_SPEC_STARK_ROUTER_293_2024='https://stark.ru/bikes/velosipedy/gornye/kross-kantri/router/router-29-3-hd-2024/';
const VEHICLE_SPEC_STARK_ROUTER_274_2024='https://stark.ru/bikes/velosipedy/gornye/kross-kantri/router/router-27-4-hd-2024/';
const VEHICLE_SPEC_STARK_ROUTER_294_2024='https://stark.ru/bikes/velosipedy/gornye/kross-kantri/router/router-29-4-hd-2024/';
const VEHICLE_SPEC_STARK_ROUTER_293_2025='https://stark.ru/bikes/velosipedy/gornye/kross-kantri/router/router-29-3-hd-2025/';
const VEHICLE_SPEC_STARK_VIVA_272_D_2025='https://stark.ru/bikes/velosipedy/gornye/trekking/viva/viva-27-2-d-2025/';
const VEHICLE_SPEC_STARK_VIVA_272_HD_2025='https://stark.ru/bikes/velosipedy/gornye/trekking/viva/viva-27-2-hd-2025/';
const VEHICLE_SPEC_STARK_VIVA_273_HD_2025='https://stark.ru/bikes/velosipedy/gornye/trekking/viva/viva-27-3-hd-2025/';
const VEHICLE_SPEC_STARK_VIVA_275_HD_2025='https://stark.ru/bikes/velosipedy/gornye/trekking/viva/viva-27-5-hd-2025/';
const VEHICLE_SPEC_WELT_STORM_26_MD_2026='https://www.welt-bikes.com/ru/ru/vse-velosipedy/gornye/Welt_Storm_26?optionId=1201';
const VEHICLE_SPEC_WELT_ICON_20_2026='https://www.welt-bikes.com/ru/ru/vse-velosipedy/gornye/icon2_2026?optionId=1149';
const VEHICLE_SPEC_WELT_BRAVE_10_20_VB_2026='https://www.welt-bikes.com/ru/ru/vse-velosipedy/podrostkovye-velosipedy/brave1vb_2026?optionId=1040';
const VEHICLE_SPEC_WELT_BRAVE_10_24_MD_2026='https://www.welt-bikes.com/ru/ru/vse-velosipedy/podrostkovye-velosipedy/brave1md24_2026?optionId=1060';
const VEHICLE_SPEC_WELT_BRAVE_20_24_HD_2026='https://www.welt-bikes.com/ru/ru/vse-velosipedy/podrostkovye-velosipedy/brave2hd24_2026?optionId=1064';
const VEHICLE_SPEC_ASPECT_AIR_20_2026='https://www.aspect-bikes.ru/catalog/aspect-air-20/';
const VEHICLE_SPEC_ASPECT_AURA_20_2026='https://www.aspect-bikes.ru/catalog/aspect-aura-20/';
const VEHICLE_SPEC_WELT_MOOVIX_10_MD_24_2026='https://www.welt-bikes.com/ru/ru/vse-velosipedy/podrostkovye-velosipedy/movix1md24_2026?optionId=1070';
const VEHICLE_SPEC_PROFISPORT_MOOVIX_10_MD_24_2026='https://velo56.ru/catalog/velosipedy/podrostkovye-24/velosiped-welt-moovix-1-0-md-24-shiny-orange-2026-56804/';
const VEHICLE_SPEC_REGISTRY_VERSION='2026-09-26-batch5';

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

function vehicle_spec_registry_welt_rocket_30_hd_2026(string $wheel): array {
    $source=VEHICLE_SPEC_WELT_ROCKET_30_HD_2026;
    return [
        vehicle_spec_registry_component('fork','fork','Вилка','996 Alloy ⌀32, Tapered Crown, MLO, 100mm',$source,'2ROXX'),
        vehicle_spec_registry_component('rims','wheels','Обода','Alloy, double wall',$source,null),
        vehicle_spec_registry_component('hubs','hubs','Втулки','A282 F/R, 2+2 sealed bearings',$source,'WZ'),
        vehicle_spec_registry_component('front_tire','front_tire','Передняя покрышка','W2030 '.$wheel.'x2.25',$source,'Wanda'),
        vehicle_spec_registry_component('rear_tire','rear_tire','Задняя покрышка','W2030 '.$wheel.'x2.25',$source,'Wanda'),
        vehicle_spec_registry_component('rear_derailleur','drivetrain','Задний переключатель','RD-U2000-GS, 8sp',$source,'Shimano Essa'),
        vehicle_spec_registry_component('shifter','cockpit','Манетка','SL-M315, 8sp',$source,'Shimano Altus'),
        vehicle_spec_registry_component('cranks','cranks','Система','TL036 Alloy, 170mm/34T',$source,null),
        vehicle_spec_registry_component('cassette','drivetrain','Кассета','CS-HR8-40, 11-40T',$source,'Sunshine'),
        vehicle_spec_registry_component('front_brake','front_brake','Передний тормоз','TKD176 Hydraulic Disc',$source,'Tektro'),
        vehicle_spec_registry_component('rear_brake','rear_brake','Задний тормоз','TKD176 Hydraulic Disc',$source,'Tektro'),
        vehicle_spec_registry_component('handlebar','cockpit','Руль','LS102 Alloy, 31.8, 720mm, 6° Backsweep',$source,null),
        vehicle_spec_registry_component('saddle','saddle','Седло','VD1213-02 MTB Comfort',$source,null),
    ];
}

function vehicle_spec_registry_welt_storm_26_md_2026(): array {
    $source=VEHICLE_SPEC_WELT_STORM_26_MD_2026;
    return [
        vehicle_spec_registry_component('fork','fork','Вилка','2ROXX 981 Alloy MLO, 100mm',$source,'2ROXX'),
        vehicle_spec_registry_component('rims','wheels','Обода','Alloy, Double wall',$source,null),
        vehicle_spec_registry_component('hubs','hubs','Втулки','WZ A282 F/R, 2+2 Sealed Bearings',$source,'WZ'),
        vehicle_spec_registry_component('front_tire','front_tire','Передняя покрышка','Wanda W2030 26x2.25',$source,'Wanda'),
        vehicle_spec_registry_component('rear_tire','rear_tire','Задняя покрышка','Wanda W2030 26x2.25',$source,'Wanda'),
        vehicle_spec_registry_component('rear_derailleur','drivetrain','Задний переключатель','Tourney TY-300',$source,'Shimano'),
        vehicle_spec_registry_component('shifter','cockpit','Манетка','Altus SL-M315, 7sp',$source,'Shimano'),
        vehicle_spec_registry_component('cranks','cranks','Система','TL036 Alloy, 170mm/34T',$source,null),
        vehicle_spec_registry_component('cassette','drivetrain','Кассета','ATA 11-32T, 7sp',$source,'ATA'),
        vehicle_spec_registry_component('front_brake','front_brake','Передний тормоз','DX2005 Mechanical Disc',$source,null),
        vehicle_spec_registry_component('rear_brake','rear_brake','Задний тормоз','DX2005 Mechanical Disc',$source,null),
        vehicle_spec_registry_component('handlebar','cockpit','Руль','JB-6818 Alloy, 31.8×680mm, 6° Backsweep',$source,null),
        vehicle_spec_registry_component('saddle','saddle','Седло','VD1213-02 MTB Comfort',$source,null),
    ];
}

function vehicle_spec_registry_welt_icon_20_2026(string $wheel): array {
    $source=VEHICLE_SPEC_WELT_ICON_20_2026;
    return array_merge([
        vehicle_spec_registry_component('fork','fork','Вилка','2ROXX MD-999 Air, 120mm, 32mm alloy ED stanchions, HLO',$source,'2ROXX'),
        vehicle_spec_registry_component('rims','wheels','Обода','R30-2C Alloy, Double wall, Tubeless ready, 30C',$source,null),
        vehicle_spec_registry_component('hubs','hubs','Втулки','WZ A707 F/R, M15X100/M12X142, 2+2 Sealed Bearings',$source,'WZ'),
        vehicle_spec_registry_component('front_tire','front_tire','Передняя покрышка','Wanda 1226 '.$wheel.'x2.25 Tanwall',$source,'Wanda'),
        vehicle_spec_registry_component('rear_tire','rear_tire','Задняя покрышка','Wanda 1226 '.$wheel.'x2.25 Tanwall',$source,'Wanda'),
        vehicle_spec_registry_component('rear_derailleur','drivetrain','Задний переключатель','Cues RD-U4000, 9sp',$source,'Shimano'),
        vehicle_spec_registry_component('shifter','cockpit','Манетка','Cues SL-U4000-9R',$source,'Shimano'),
        vehicle_spec_registry_component('cranks','cranks','Система','TL013 Alloy, Hollow Axle, 170mm/34T',$source,null),
        vehicle_spec_registry_component('cassette','drivetrain','Кассета','Sunshine CS-HR9-46L-QS, 11-46T, 9S',$source,'Sunshine'),
        vehicle_spec_registry_component('front_brake','front_brake','Передний тормоз','MT-200 Hydraulic Disc, 180/160mm',$source,'Shimano'),
        vehicle_spec_registry_component('rear_brake','rear_brake','Задний тормоз','MT-200 Hydraulic Disc, 180/160mm',$source,'Shimano'),
        vehicle_spec_registry_component('handlebar','cockpit','Руль','HB-12BT Alloy, 31.8×760mm, 5° Backsweep',$source,null),
        vehicle_spec_registry_component('saddle','saddle','Седло','VD1224 XC/Trail series',$source,null),
    ],vehicle_spec_registry_mt200_pads());
}

function vehicle_spec_registry_welt_brave_10_20_vb_2026(): array {
    $source=VEHICLE_SPEC_WELT_BRAVE_10_20_VB_2026;
    return [
        vehicle_spec_registry_component('fork','fork','Вилка','2ROXX 440 Alloy, 60mm, Preload, Tapered Crown, MLO, Soft spring',$source,'2ROXX'),
        vehicle_spec_registry_component('rims','wheels','Обода','Alloy, double wall',$source,null),
        vehicle_spec_registry_component('hubs','hubs','Втулки','LGR-A201',$source,null),
        vehicle_spec_registry_component('front_tire','front_tire','Передняя покрышка','CST 3030 20x2.125',$source,'CST'),
        vehicle_spec_registry_component('rear_tire','rear_tire','Задняя покрышка','CST 3030 20x2.125',$source,'CST'),
        vehicle_spec_registry_component('rear_derailleur','drivetrain','Задний переключатель','Tourney TY-300',$source,'Shimano'),
        vehicle_spec_registry_component('shifter','cockpit','Манетка','TS38-7',$source,'Microshift'),
        vehicle_spec_registry_component('cranks','cranks','Система','TL097 Alloy 130mm*32T',$source,null),
        vehicle_spec_registry_component('cassette','drivetrain','Трещотка','FW 14-28T, ED Black',$source,null),
        vehicle_spec_registry_component('front_brake','front_brake','Передний тормоз','YX-C22 V-Brake',$source,null),
        vehicle_spec_registry_component('rear_brake','rear_brake','Задний тормоз','YX-C22 V-Brake',$source,null),
        vehicle_spec_registry_component('handlebar','cockpit','Руль','Alloy, 31.8×560mm, 6° Backsweep',$source,null),
        vehicle_spec_registry_component('saddle','saddle','Седло','WL-8182, Teens Morphology',$source,null),
    ];
}

function vehicle_spec_registry_welt_brave_10_24_md_2026(): array {
    $source=VEHICLE_SPEC_WELT_BRAVE_10_24_MD_2026;
    return [
        vehicle_spec_registry_component('fork','fork','Вилка','2ROXX Alloy, MLO, Preload, 80mm, Soft spring',$source,'2ROXX'),
        vehicle_spec_registry_component('rims','wheels','Обода','Alloy, double wall',$source,null),
        vehicle_spec_registry_component('hubs','hubs','Втулки','LGR-A208 F/R',$source,null),
        vehicle_spec_registry_component('front_tire','front_tire','Передняя покрышка','Kenda Booster K1227 24x2.2',$source,'Kenda'),
        vehicle_spec_registry_component('rear_tire','rear_tire','Задняя покрышка','Kenda Booster K1227 24x2.2',$source,'Kenda'),
        vehicle_spec_registry_component('rear_derailleur','drivetrain','Задний переключатель','Tourney TY-300',$source,'Shimano'),
        vehicle_spec_registry_component('shifter','cockpit','Манетка','TS38-7',$source,'Microshift'),
        vehicle_spec_registry_component('cranks','cranks','Система','TL097 Alloy 140mm*32T',$source,null),
        vehicle_spec_registry_component('cassette','drivetrain','Трещотка','FW 14-28T, ED Black',$source,null),
        vehicle_spec_registry_component('front_brake','front_brake','Передний тормоз','DSC310 Mechanical Disc',$source,'Repute'),
        vehicle_spec_registry_component('rear_brake','rear_brake','Задний тормоз','DSC310 Mechanical Disc',$source,'Repute'),
        vehicle_spec_registry_component('brake_rotors','front_brake','Тормозные диски','160/160 mm',$source,'Repute'),
        vehicle_spec_registry_component('handlebar','cockpit','Руль','JTA-8720 Alloy, 31.8×60mm',$source,null),
        vehicle_spec_registry_component('saddle','saddle','Седло','WL-8182, Teens Morphology',$source,null),
    ];
}

function vehicle_spec_registry_welt_brave_20_24_hd_2026(): array {
    $source=VEHICLE_SPEC_WELT_BRAVE_20_24_HD_2026;
    return [
        vehicle_spec_registry_component('fork','fork','Вилка','2ROXX Alloy, MLO, Preload, 80mm, Soft spring',$source,'2ROXX'),
        vehicle_spec_registry_component('rims','wheels','Обода','Alloy, double wall',$source,null),
        vehicle_spec_registry_component('hubs','hubs','Втулки','LGR-A208 F/R',$source,null),
        vehicle_spec_registry_component('front_tire','front_tire','Передняя покрышка','Kenda Booster K1228 24x2.2',$source,'Kenda'),
        vehicle_spec_registry_component('rear_tire','rear_tire','Задняя покрышка','Kenda Booster K1228 24x2.2',$source,'Kenda'),
        vehicle_spec_registry_component('rear_derailleur','drivetrain','Задний переключатель','Altus RD-M310, 8sp',$source,'Shimano'),
        vehicle_spec_registry_component('shifter','cockpit','Манетка','Altus SL-M315, 8sp',$source,'Shimano'),
        vehicle_spec_registry_component('cranks','cranks','Система','TL097 Alloy 140mm*32T',$source,null),
        vehicle_spec_registry_component('cassette','drivetrain','Кассета','HG200-8 12-32T',$source,'Shimano'),
        vehicle_spec_registry_component('front_brake','front_brake','Передний тормоз','TKD176 Hydraulic Disc',$source,'Tektro'),
        vehicle_spec_registry_component('rear_brake','rear_brake','Задний тормоз','TKD176 Hydraulic Disc',$source,'Tektro'),
        vehicle_spec_registry_component('handlebar','cockpit','Руль','JTA-8720 Alloy, 31.8×60mm',$source,null),
        vehicle_spec_registry_component('saddle','saddle','Седло','WL-8182, Teens Morphology',$source,null),
    ];
}

function vehicle_spec_registry_stark_router_2024(string $model): array {
    $is4=str_contains($model,'.4');$wheel=str_starts_with($model,'29')?'29':'27.5';
    $source=match($model){
        '27.3'=>VEHICLE_SPEC_STARK_ROUTER_273_2024,
        '29.3'=>VEHICLE_SPEC_STARK_ROUTER_293_2024,
        '27.4'=>VEHICLE_SPEC_STARK_ROUTER_274_2024,
        '29.4'=>VEHICLE_SPEC_STARK_ROUTER_294_2024,
        default=>throw new InvalidArgumentException('unknown_stark_router_2024'),
    };
    $components=[
        vehicle_spec_registry_component('fork','fork','Вилка',$is4?'Grinz ES-456 HLO, 100 mm':'Grinz ES-451 HLO, 100 mm',$source,'Grinz'),
        vehicle_spec_registry_component('handlebar','cockpit','Руль','Jieshun JH-802L, alloy, 31.8×720 mm',$source,'Jieshun'),
        vehicle_spec_registry_component('stem','cockpit','Вынос','Jieshun JX-008, alloy, 31.8×80 mm',$source,'Jieshun'),
        vehicle_spec_registry_component('rims','wheels','Обода','Qijian DA-18, double wall',$source,'Qijian'),
        vehicle_spec_registry_component('hubs','hubs','Втулки','Solon DH902R/902F, 2 sealed bearings',$source,'Solon'),
        vehicle_spec_registry_component('front_tire','front_tire','Передняя покрышка','CST C-1563 '.$wheel.'x2.25',$source,'CST'),
        vehicle_spec_registry_component('rear_tire','rear_tire','Задняя покрышка','CST C-1563 '.$wheel.'x2.25',$source,'CST'),
        vehicle_spec_registry_component('cranks','cranks','Система',$is4?'Prowheel Zephyr FD04S '.($model==='29.4'?'32T':'34T'):'Prowheel MA-AC49 22/32/42T',$source,'Prowheel'),
        vehicle_spec_registry_component('cassette','drivetrain','Кассета',$is4?'Shimano CUES CS-LG300-9 11-41T':'Sunshine CS-HR8-36, 8sp',$source,$is4?'Shimano':'Sunshine'),
        vehicle_spec_registry_component('chain','chain','Цепь',$is4?'KMC Z9':'KMC Z8',$source,'KMC'),
        vehicle_spec_registry_component('bottom_bracket','cranks','Каретка','Neco B-910 cartridge',$source,'Neco'),
        vehicle_spec_registry_component('shifter','cockpit','Манетки',$is4?'Shimano CUES SL-U4000, 9sp':'Shimano Altus SL-M315, 3×8',$source,'Shimano'),
        vehicle_spec_registry_component('rear_derailleur','drivetrain','Задний переключатель',$is4?'Shimano CUES RD-U4000-GS':'Shimano Altus RD-M310',$source,'Shimano'),
        vehicle_spec_registry_component('front_brake','front_brake','Передний тормоз','Tektro HD-M275 hydraulic disc',$source,'Tektro'),
        vehicle_spec_registry_component('rear_brake','rear_brake','Задний тормоз','Tektro HD-M275 hydraulic disc',$source,'Tektro'),
        vehicle_spec_registry_component('brake_rotors','front_brake','Тормозные диски','180/160 mm',$source,'Tektro'),
        vehicle_spec_registry_component('seatpost','saddle','Подседельный штырь','31.6×350 mm',$source,null),
        vehicle_spec_registry_component('saddle','saddle','Седло','Zeus #1040',$source,'Zeus'),
        vehicle_spec_registry_component('pedals','pedals','Педали','Feimin FP-803ZU',$source,'Feimin'),
    ];
    if(!$is4)$components[]=vehicle_spec_registry_component('front_derailleur','drivetrain','Передний переключатель','Shimano Tourney TY500',$source,'Shimano');
    return $components;
}

function vehicle_spec_registry_stark_router_293_2025(): array {
    $source=VEHICLE_SPEC_STARK_ROUTER_293_2025;
    return [
        vehicle_spec_registry_component('fork','fork','Вилка','Grinz Hortus SL, 100 mm',$source,'Grinz'),
        vehicle_spec_registry_component('handlebar','cockpit','Руль','Jieshun JH-802L, alloy, 31.8×720 mm',$source,'Jieshun'),
        vehicle_spec_registry_component('stem','cockpit','Вынос','Jieshun JX-008, alloy, 31.8×80 mm',$source,'Jieshun'),
        vehicle_spec_registry_component('rims','wheels','Обода','Qijian DA-18, double wall',$source,'Qijian'),
        vehicle_spec_registry_component('hubs','hubs','Втулки','Solon DH902R/DH902F, 2 sealed bearings',$source,'Solon'),
        vehicle_spec_registry_component('front_tire','front_tire','Передняя покрышка','Chaoyang Phantom Dry H-5234 29x2.2',$source,'Chaoyang'),
        vehicle_spec_registry_component('rear_tire','rear_tire','Задняя покрышка','Chaoyang Phantom Dry H-5234 29x2.2',$source,'Chaoyang'),
        vehicle_spec_registry_component('cranks','cranks','Система','Prowheel Zephyr FD04S 34T',$source,'Prowheel'),
        vehicle_spec_registry_component('cassette','drivetrain','Кассета','Sunshine MTB-CS-HR9-36, 9sp',$source,'Sunshine'),
        vehicle_spec_registry_component('chain','chain','Цепь','KMC Z9',$source,'KMC'),
        vehicle_spec_registry_component('bottom_bracket','cranks','Каретка','Neco B-910 cartridge',$source,'Neco'),
        vehicle_spec_registry_component('shifter','cockpit','Манетка','Microshift SL-M759, 9sp',$source,'Microshift'),
        vehicle_spec_registry_component('rear_derailleur','drivetrain','Задний переключатель','Shimano Altus RD-M2000',$source,'Shimano'),
        vehicle_spec_registry_component('front_brake','front_brake','Передний тормоз','Tektro HD-M275 hydraulic disc',$source,'Tektro'),
        vehicle_spec_registry_component('rear_brake','rear_brake','Задний тормоз','Tektro HD-M275 hydraulic disc',$source,'Tektro'),
        vehicle_spec_registry_component('brake_rotors','front_brake','Тормозные диски','180/160 mm',$source,'Tektro'),
        vehicle_spec_registry_component('seatpost','saddle','Подседельный штырь','31.6×350 mm',$source,null),
        vehicle_spec_registry_component('saddle','saddle','Седло','Zeus #1040',$source,'Zeus'),
        vehicle_spec_registry_component('pedals','pedals','Педали','Feimin FP-803 ZU',$source,'Feimin'),
    ];
}

function vehicle_spec_registry_stark_viva_2025(string $model): array {
    $source=match($model){
        '27.2 D'=>VEHICLE_SPEC_STARK_VIVA_272_D_2025,
        '27.2 HD'=>VEHICLE_SPEC_STARK_VIVA_272_HD_2025,
        '27.3 HD'=>VEHICLE_SPEC_STARK_VIVA_273_HD_2025,
        '27.5 HD'=>VEHICLE_SPEC_STARK_VIVA_275_HD_2025,
        default=>throw new InvalidArgumentException('unknown_stark_viva_2025'),
    };
    $is275=$model==='27.5 HD';$is273=$model==='27.3 HD';$isD=$model==='27.2 D';
    $components=[
        vehicle_spec_registry_component('fork','fork','Вилка',$is275?'Grinz Nemus S+, air/oil, 100 mm':($is273?'Grinz Hortus SL, 100 mm':'Grinz Hortus S, 100 mm'),$source,'Grinz'),
        vehicle_spec_registry_component('handlebar','cockpit','Руль','Jieshun JH-802L, alloy, 31.8×700 mm',$source,'Jieshun'),
        vehicle_spec_registry_component('stem','cockpit','Вынос','Jieshun JX-008, alloy, 31.8×60 mm',$source,'Jieshun'),
        vehicle_spec_registry_component('rims','wheels','Обода',$is275?'Citron DR-25A, double wall':'Qijian DA-18, double wall',$source,$is275?'Citron':'Qijian'),
        vehicle_spec_registry_component('hubs','hubs','Втулки',$is275?'Solon DH536SR/DH536SF, 4 sealed bearings':'Solon DH902R/DH902F, 2 sealed bearings',$source,'Solon'),
        vehicle_spec_registry_component('front_tire','front_tire','Передняя покрышка',($is273||$is275?'Chaoyang Falcon 5185 27.5x1.95':'Seoyun SY-B030 27.5x2.1'),$source,$is273||$is275?'Chaoyang':'Seoyun'),
        vehicle_spec_registry_component('rear_tire','rear_tire','Задняя покрышка',($is273||$is275?'Chaoyang Falcon 5185 27.5x1.95':'Seoyun SY-B030 27.5x2.1'),$source,$is273||$is275?'Chaoyang':'Seoyun'),
        vehicle_spec_registry_component('cranks','cranks','Система',$is275?'Jiancun X6M-713L-4C 32T':'Prowheel Zephyr FD04S '.($is273?'34T':'32T'),$source,$is275?'Jiancun':'Prowheel'),
        vehicle_spec_registry_component('cassette','drivetrain','Кассета',$is275?'Microshift CH103A 11-42T':($is273?'Sunshine MTB-CS-HR9-36':'Sunshine CS-HR8-36'),$source,$is275?'Microshift':'Sunshine'),
        vehicle_spec_registry_component('chain','chain','Цепь',$is275?'KMC X10':($is273?'KMC Z9':'KMC Z8'),$source,'KMC'),
        vehicle_spec_registry_component('bottom_bracket','cranks','Каретка',$is275?'Prowheel cartridge':'Neco B-910 cartridge',$source,$is275?'Prowheel':'Neco'),
        vehicle_spec_registry_component('shifter','cockpit','Манетка',$is275?'Microshift SL-850R-10':($is273?'Microshift SL-M759':'Shimano Altus SL-M315'),$source,$is275||$is273?'Microshift':'Shimano'),
        vehicle_spec_registry_component('rear_derailleur','drivetrain','Задний переключатель',$is275?'Microshift RD-665M':($is273?'Shimano Altus RD-M2000':'Shimano Tourney RD-TX800'),$source,$is275?'Microshift':'Shimano'),
        vehicle_spec_registry_component('front_brake','front_brake','Передний тормоз',$isD?'Repute DSC910 mechanical disc':'Tektro HD-M275 hydraulic disc',$source,$isD?'Repute':'Tektro'),
        vehicle_spec_registry_component('rear_brake','rear_brake','Задний тормоз',$isD?'Repute DSC910 mechanical disc':'Tektro HD-M275 hydraulic disc',$source,$isD?'Repute':'Tektro'),
        vehicle_spec_registry_component('brake_rotors','front_brake','Тормозные диски',$isD?'180/160 mm':'160/160 mm',$source,$isD?'Repute':'Tektro'),
        vehicle_spec_registry_component('seatpost','saddle','Подседельный штырь','31.6×350 mm',$source,null),
        vehicle_spec_registry_component('saddle','saddle','Седло','Zeus #1009',$source,'Zeus'),
        vehicle_spec_registry_component('pedals','pedals','Педали','Feimin FP-873 ZU',$source,'Feimin'),
    ];
    return $components;
}

function vehicle_spec_registry_aspect_air_20_2026(): array {
    $source=VEHICLE_SPEC_ASPECT_AIR_20_2026;$note='Официальная карточка модели; официальный каталог Aspect относит AIR 20 к KIDS 2026';
    return [
        vehicle_spec_registry_component('fork','fork','Вилка','RIGID ALLOY FORK',$source,null,$note),
        vehicle_spec_registry_component('rear_derailleur','drivetrain','Задний переключатель','Tourney RD-TY200D',$source,'Shimano',$note),
        vehicle_spec_registry_component('shifter','cockpit','Манетка','RevoShift SL-RV400-6R',$source,'Shimano',$note),
        vehicle_spec_registry_component('cranks','cranks','Система','Steel 32T 127mm',$source,null,$note),
        vehicle_spec_registry_component('cassette','drivetrain','Кассета','TZ500-6 14-28T',$source,'Shimano',$note),
        vehicle_spec_registry_component('front_brake','front_brake','Передний тормоз','V-brake',$source,null,$note),
        vehicle_spec_registry_component('rear_brake','rear_brake','Задний тормоз','V-brake',$source,null,$note),
        vehicle_spec_registry_component('handlebar','cockpit','Руль','Code Kids 20, 31.8×580mm, rise 30mm, backsweep 9°',$source,'Code',$note),
        vehicle_spec_registry_component('stem','cockpit','Вынос','Code-008, 50mm, 7°',$source,'Code',$note),
        vehicle_spec_registry_component('seatpost','saddle','Подседельный штырь','Code 609 27.2×250mm Alloy',$source,'Code',$note),
        vehicle_spec_registry_component('saddle','saddle','Седло','Code-4058',$source,'Code',$note),
        vehicle_spec_registry_component('hubs','hubs','Втулки','Industrial bearings, alloy body 100/130mm 28H, QR',$source,null,$note),
        vehicle_spec_registry_component('rims','wheels','Обода','Alloy Double Wall',$source,null,$note),
        vehicle_spec_registry_component('front_tire','front_tire','Передняя покрышка','Chaoyang H-5129 20x2.0',$source,'Chaoyang',$note),
        vehicle_spec_registry_component('rear_tire','rear_tire','Задняя покрышка','Chaoyang H-5129 20x2.0',$source,'Chaoyang',$note),
    ];
}

function vehicle_spec_registry_aspect_aura_20_2026(): array {
    $source=VEHICLE_SPEC_ASPECT_AURA_20_2026;$note='Официальная карточка модели; официальный каталог Aspect относит AURA 20 к KIDS 2026. Покрышки не занесены автоматически из-за двух противоречивых строк размеров в источнике.';
    return [
        vehicle_spec_registry_component('fork','fork','Вилка','RIGID ALLOY FORK',$source,null,$note),
        vehicle_spec_registry_component('rear_derailleur','drivetrain','Задний переключатель','Tourney RD-TY200D',$source,'Shimano',$note),
        vehicle_spec_registry_component('shifter','cockpit','Манетка','RevoShift SL-RV400-6R',$source,'Shimano',$note),
        vehicle_spec_registry_component('cranks','cranks','Система','Steel 32T 127mm',$source,null,$note),
        vehicle_spec_registry_component('cassette','drivetrain','Кассета','TZ500-6 14-28T',$source,'Shimano',$note),
        vehicle_spec_registry_component('front_brake','front_brake','Передний тормоз','V-brake',$source,null,$note),
        vehicle_spec_registry_component('rear_brake','rear_brake','Задний тормоз','V-brake',$source,null,$note),
        vehicle_spec_registry_component('handlebar','cockpit','Руль','Code Kids 20, 31.8×580mm, rise 30mm, backsweep 9°',$source,'Code',$note),
        vehicle_spec_registry_component('stem','cockpit','Вынос','Code-008, 50mm, 7°',$source,'Code',$note),
        vehicle_spec_registry_component('seatpost','saddle','Подседельный штырь','Code 609 27.2×250mm Alloy',$source,'Code',$note),
        vehicle_spec_registry_component('saddle','saddle','Седло','Code-4058',$source,'Code',$note),
        vehicle_spec_registry_component('hubs','hubs','Втулки','Industrial bearings, alloy body 100/130mm 28H, QR',$source,null,$note),
        vehicle_spec_registry_component('rims','wheels','Обода','Alloy Double Wall',$source,null,$note),
    ];
}

function vehicle_spec_registry_conflicts(): array {
    return [[
        'key'=>'conflict-welt-moovix-1.0-md-24-2026',
        'brand'=>'Welt','model'=>'Moovix 1.0 MD 24','year'=>2026,'wheel'=>'24','wheel_in_model'=>true,
        'reference_url'=>VEHICLE_SPEC_WELT_MOOVIX_10_MD_24_2026,
        'note'=>'Карточка ProfiSport 2026 и текущая официальная спецификация WELT расходятся по трансмиссии, покрышкам, тормозам и системе. Автозаполнение заблокировано до проверки фактической комплектации конкретной партии.',
    ]];
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
    $profiles[]=['key'=>'aspect-air-20-2026-20','brand'=>'Aspect','model'=>'Air','year'=>2026,'wheel'=>'20','source_url'=>VEHICLE_SPEC_ASPECT_AIR_20_2026,'components'=>vehicle_spec_registry_aspect_air_20_2026()];
    $profiles[]=['key'=>'aspect-aura-20-2026-20','brand'=>'Aspect','model'=>'Aura','year'=>2026,'wheel'=>'20','source_url'=>VEHICLE_SPEC_ASPECT_AURA_20_2026,'components'=>vehicle_spec_registry_aspect_aura_20_2026()];
    foreach(['27.5','29'] as $wheel){
        $profiles[]=['key'=>'hagen-3.9-2025-'.$wheel,'brand'=>'Hagen','model'=>'3.9','year'=>2025,'wheel'=>$wheel,'source_url'=>VEHICLE_SPEC_HAGEN_39_2025,'components'=>vehicle_spec_registry_hagen_2025('3.9')];
        $profiles[]=['key'=>'hagen-3.11-2025-'.$wheel,'brand'=>'Hagen','model'=>'3.11','year'=>2025,'wheel'=>$wheel,'source_url'=>VEHICLE_SPEC_HAGEN_311_2025,'components'=>vehicle_spec_registry_hagen_2025('3.11')];
        $profiles[]=['key'=>'welt-rocket-3.0-hd-2026-'.$wheel,'brand'=>'Welt','model'=>'Rocket 3.0 HD','year'=>2026,'wheel'=>$wheel,'source_url'=>VEHICLE_SPEC_WELT_ROCKET_30_HD_2026,'components'=>vehicle_spec_registry_welt_rocket_30_hd_2026($wheel)];
        $profiles[]=['key'=>'welt-icon-2.0-2026-'.$wheel,'brand'=>'Welt','model'=>'Icon 2.0','year'=>2026,'wheel'=>$wheel,'source_url'=>VEHICLE_SPEC_WELT_ICON_20_2026,'components'=>vehicle_spec_registry_welt_icon_20_2026($wheel)];
    }
    $profiles[]=['key'=>'welt-storm-26-md-2026-26','brand'=>'Welt','model'=>'Storm 26 MD','year'=>2026,'wheel'=>'26','source_url'=>VEHICLE_SPEC_WELT_STORM_26_MD_2026,'components'=>vehicle_spec_registry_welt_storm_26_md_2026()];
    $profiles[]=['key'=>'welt-brave-1.0-20-vb-2026-20','brand'=>'Welt','model'=>'Brave 1.0 20 VB','year'=>2026,'wheel'=>'20','wheel_in_model'=>true,'source_url'=>VEHICLE_SPEC_WELT_BRAVE_10_20_VB_2026,'components'=>vehicle_spec_registry_welt_brave_10_20_vb_2026()];
    $profiles[]=['key'=>'welt-brave-1.0-24-md-2026-24','brand'=>'Welt','model'=>'Brave 1.0 24 MD','year'=>2026,'wheel'=>'24','wheel_in_model'=>true,'source_url'=>VEHICLE_SPEC_WELT_BRAVE_10_24_MD_2026,'components'=>vehicle_spec_registry_welt_brave_10_24_md_2026()];
    $profiles[]=['key'=>'welt-brave-2.0-24-hd-2026-24','brand'=>'Welt','model'=>'Brave 2.0 24 HD','year'=>2026,'wheel'=>'24','wheel_in_model'=>true,'source_url'=>VEHICLE_SPEC_WELT_BRAVE_20_24_HD_2026,'components'=>vehicle_spec_registry_welt_brave_20_24_hd_2026()];
    foreach(['27.3','29.3','27.4','29.4'] as $model){
        $wheel=str_starts_with($model,'29')?'29':'27.5';
        $source=match($model){'27.3'=>VEHICLE_SPEC_STARK_ROUTER_273_2024,'29.3'=>VEHICLE_SPEC_STARK_ROUTER_293_2024,'27.4'=>VEHICLE_SPEC_STARK_ROUTER_274_2024,'29.4'=>VEHICLE_SPEC_STARK_ROUTER_294_2024};
        $profiles[]=['key'=>'stark-router-'.$model.'-hd-2024','brand'=>'Stark','model'=>'Router '.$model.' HD','year'=>2024,'wheel'=>$wheel,'wheel_in_model'=>true,'source_url'=>$source,'components'=>vehicle_spec_registry_stark_router_2024($model)];
    }
    $profiles[]=['key'=>'stark-router-29.3-hd-2025','brand'=>'Stark','model'=>'Router 29.3 HD','year'=>2025,'wheel'=>'29','wheel_in_model'=>true,'source_url'=>VEHICLE_SPEC_STARK_ROUTER_293_2025,'components'=>vehicle_spec_registry_stark_router_293_2025()];
    foreach(['27.2 D','27.2 HD','27.3 HD','27.5 HD'] as $model){
        $source=match($model){'27.2 D'=>VEHICLE_SPEC_STARK_VIVA_272_D_2025,'27.2 HD'=>VEHICLE_SPEC_STARK_VIVA_272_HD_2025,'27.3 HD'=>VEHICLE_SPEC_STARK_VIVA_273_HD_2025,'27.5 HD'=>VEHICLE_SPEC_STARK_VIVA_275_HD_2025};
        $profiles[]=['key'=>'stark-viva-'.strtolower(str_replace([' ','.'],['-','-'],$model)).'-2025','brand'=>'Stark','model'=>'Viva '.$model,'year'=>2025,'wheel'=>'27.5','wheel_in_model'=>true,'source_url'=>$source,'components'=>vehicle_spec_registry_stark_viva_2025($model)];
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
        if(!($profile['wheel_in_model']??false)&&!preg_match('/(?:^| )'.preg_quote($wheel,'/').'(?: |$)/u',$n))continue;
        $matches[]=$profile;
    }
    if(!$matches)return null;
    usort($matches,fn($a,$b)=>mb_strlen((string)$b['model'])<=>mb_strlen((string)$a['model']));
    $best=$matches[0];$bestLen=mb_strlen((string)$best['model']);
    if(isset($matches[1])&&mb_strlen((string)$matches[1]['model'])===$bestLen)return null;
    return $best;
}

function vehicle_spec_registry_conflict_match(string $title): ?array {
    $n=vehicle_spec_registry_normalize_title($title);$matches=[];
    foreach(vehicle_spec_registry_conflicts() as $rule){
        $brand=vehicle_spec_registry_normalize_title((string)$rule['brand']);$model=vehicle_spec_registry_normalize_title((string)$rule['model']);
        $year=(string)$rule['year'];$wheel=(string)$rule['wheel'];
        if(!str_contains($n,$brand.' '.$model))continue;
        if(!preg_match('/(?:^| )'.preg_quote($year,'/').'(?: |$)/u',$n))continue;
        if(!($rule['wheel_in_model']??false)&&!preg_match('/(?:^| )'.preg_quote($wheel,'/').'(?: |$)/u',$n))continue;
        $matches[]=$rule;
    }
    return count($matches)===1?$matches[0]:null;
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
        note VARCHAR(1200) NULL,
        reference_url VARCHAR(1200) NULL,
        first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY idx_vehicle_spec_research_product(product_id),
        INDEX idx_vehicle_spec_research_status(status,last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $queueCols=table_columns($pdo,'vehicle_spec_research_queue');
    $queueDefs=['note'=>'VARCHAR(1200) NULL','reference_url'=>'VARCHAR(1200) NULL'];
    foreach($queueDefs as $name=>$def)if(!isset($queueCols[$name]))$pdo->exec("ALTER TABLE vehicle_spec_research_queue ADD COLUMN `$name` $def");
}

function vehicle_spec_registry_scan_catalog(PDO $pdo,int $limit=1500): array {
    $limit=max(1,min(5000,$limit));
    $s=$pdo->query("SELECT id,name,brand,model,category_path,is_active,stock_qty FROM products WHERE is_active=1 AND COALESCE(stock_qty,0)>0 ORDER BY id DESC LIMIT ".$limit);
    $upsert=$pdo->prepare("INSERT INTO vehicle_spec_research_queue(product_id,title,brand,model,match_key,status,note,reference_url,first_seen_at,last_seen_at) VALUES(?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE title=VALUES(title),brand=VALUES(brand),model=VALUES(model),match_key=VALUES(match_key),status=VALUES(status),note=VALUES(note),reference_url=VALUES(reference_url),last_seen_at=NOW()");
    $matched=0;$unmatched=0;$conflicts=0;$rows=[];
    foreach($s->fetchAll() as $row){
        $type=function_exists('customer_vehicle_type')?customer_vehicle_type((string)$row['name'],(string)($row['category_path']??'')):null;
        if($type!=='bicycle')continue;
        $profile=vehicle_spec_registry_match((string)$row['name']);$conflict=$profile?null:vehicle_spec_registry_conflict_match((string)$row['name']);
        $status=$profile?'matched':($conflict?'conflict':'unmatched');$matchKey=$profile['key']??$conflict['key']??null;
        $note=$conflict['note']??null;$reference=$profile['source_url']??$conflict['reference_url']??null;
        $upsert->execute([(int)$row['id'],(string)$row['name'],$row['brand']!==null?(string)$row['brand']:null,$row['model']!==null?(string)$row['model']:null,$matchKey,$status,$note,$reference]);
        if($status==='matched')$matched++;elseif($status==='conflict')$conflicts++;else$unmatched++;
        $rows[]=['product_id'=>(int)$row['id'],'title'=>(string)$row['name'],'brand'=>$row['brand'],'model'=>$row['model'],'status'=>$status,'profile_key'=>$matchKey,'note'=>$note,'reference_url'=>$reference];
    }
    return ['matched'=>$matched,'unmatched'=>$unmatched,'conflicts'=>$conflicts,'total'=>$matched+$unmatched+$conflicts,'items'=>$rows];
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
                (string)$profile['key'],VEHICLE_SPEC_REGISTRY_VERSION,(int)$existing['id']
            ]);$updated++;
            continue;
        }
        $insert->execute([
            $vehicleId,(string)$component['component_key'],(string)$component['hotspot_key'],(string)$component['label'],$component['manufacturer']??null,(string)$component['model'],
            'official',(string)$component['source_url'],(string)$component['source_note'],(string)$component['source_verified_at'],
            (string)$profile['key'],VEHICLE_SPEC_REGISTRY_VERSION,'inspection',$installedAt
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
    $status=in_array($status,['matched','unmatched','conflict'],true)?$status:'unmatched';$limit=max(1,min(1000,$limit));
    $s=$pdo->prepare("SELECT product_id,title,brand,model,match_key,status,note,reference_url,first_seen_at,last_seen_at FROM vehicle_spec_research_queue WHERE status=? ORDER BY last_seen_at DESC,product_id DESC LIMIT ".$limit);
    $s->execute([$status]);return $s->fetchAll();
}

function vehicle_spec_registry_applied_version(PDO $pdo): string {
    $s=$pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key=? LIMIT 1');
    $s->execute(['vehicle_spec_registry_applied_version']);$value=$s->fetchColumn();
    return is_string($value)?$value:'';
}

function vehicle_spec_registry_sync_once(PDO $pdo): array {
    $version=VEHICLE_SPEC_REGISTRY_VERSION;
    if(vehicle_spec_registry_applied_version($pdo)===$version)return ['ran'=>false,'version'=>$version,'reason'=>'current'];
    $lock=(int)$pdo->query("SELECT GET_LOCK('profisport_vehicle_spec_registry',0)")->fetchColumn();
    if($lock!==1)return ['ran'=>false,'version'=>$version,'reason'=>'busy'];
    try{
        if(vehicle_spec_registry_applied_version($pdo)===$version)return ['ran'=>false,'version'=>$version,'reason'=>'current'];
        $scan=vehicle_spec_registry_scan_catalog($pdo,5000);
        $applied=vehicle_spec_registry_apply_all($pdo,10000);
        $s=$pdo->prepare('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        $s->execute(['vehicle_spec_registry_applied_version',$version]);
        return ['ran'=>true,'version'=>$version,'scan'=>['matched'=>$scan['matched'],'unmatched'=>$scan['unmatched'],'conflicts'=>$scan['conflicts'],'total'=>$scan['total']],'applied'=>$applied];
    }finally{
        $pdo->query("SELECT RELEASE_LOCK('profisport_vehicle_spec_registry')");
    }
}
