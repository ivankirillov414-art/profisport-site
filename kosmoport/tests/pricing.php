<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/website/pricing.php';
require_once dirname(__DIR__).'/app/website/content.php';
$catalog=kp_price_catalog();$event=kp_default_content()['event'];$checks=0;
function expect_price(bool $ok,string $label):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($label);}
function crm_fail(string $code,string $message,int $status=422):never{throw new RuntimeException($code,$status);}
$cases=[
 ['date'=>'2026-11-27','children'=>1,'adults'=>1],
 ['date'=>'2026-11-28','children'=>1,'adults'=>1],
 ['date'=>'2026-11-29','children'=>0,'adults'=>1],
 ['date'=>'2026-11-30','children'=>1,'adults'=>0],
 ['date'=>'2026-11-24','children'=>1,'adults'=>2],
 ['date'=>'2027-01-18','children'=>2,'adults'=>1],
 ['date'=>'2027-01-01','children'=>1,'adults'=>1],
 ['date'=>'2026-11-23','children'=>1,'adults'=>1],
 ['date'=>'2027-01-19','children'=>1,'adults'=>1],
 ['date'=>'2026-02-30','children'=>1,'adults'=>1],
 ['date'=>'2026-11-28','children'=>0,'adults'=>0],
 ['date'=>'2026-11-28','children'=>200,'adults'=>0],
 ['date'=>'2026-11-28','children'=>100,'adults'=>101],
 ['date'=>'2026-11-28','children'=>'1','adults'=>1],
 ['date'=>'2026-11-28','children'=>1.5,'adults'=>1],
 ['date'=>'0000-01-01','children'=>1,'adults'=>1],
 ['date'=>"2026-11-24\n",'children'=>1,'adults'=>1],
 ['date'=>'2026-02-29','children'=>1,'adults'=>1],
];
$quotes=array_map(static fn(array $input):array=>kp_price_quote($catalog,$event,$input),$cases);
expect_price($quotes[0]['admission_total']===1680 && $quotes[0]['day_type']==='weekday','Friday child+adult');
expect_price($quotes[1]['admission_total']===1880 && $quotes[1]['day_type']==='weekend','Saturday raises BOTH guest categories');
expect_price($quotes[2]['admission_total']===990,'Adult only never receives child minimum price');
expect_price($quotes[3]['admission_total']===790,'Weekday child only');
expect_price($quotes[4]['ok'] && $quotes[5]['ok'],'Inclusive event boundaries');
expect_price($quotes[6]['admission_total']===1680,'No invented holiday override on Friday 1 Jan');
expect_price($quotes[7]['error']==='outside_event' && $quotes[8]['error']==='outside_event','Dates outside event rejected');
expect_price($quotes[9]['error']==='invalid_date','Impossible date rejected');
expect_price($quotes[10]['error']==='invalid_quantity' && $quotes[12]['error']==='invalid_quantity','Guest total bounds');
expect_price($quotes[11]['guest_count']===200 && $quotes[11]['admission_total']===178000,'Largest permitted count');
expect_price($quotes[13]['error']==='invalid_quantity' && $quotes[14]['error']==='invalid_quantity','Strict numeric integer input');
expect_price(array_column($catalog['activities']['master_classes'],'price')===[399,499,599] && $catalog['activities']['vr']['price']===499,'Activity rates');
expect_price(array_column($catalog['combos'],'price')===[999,1499,1999] && array_column($catalog['combos'],'includes_admission')===[null,null,null],'Bundles never imply entry included');
expect_price(!kp_catalog_live_sales_ready() && !$catalog['sales_enabled'],'Payment closed pending actual provider integration');
$legacy=kp_default_content();$legacy['fields']['k14-text']='Мой сохранённый текст программы';$legacy['tickets']['price']=null;
expect_price(kp_validate_content($legacy)['fields']['k14-text']==='Мой сохранённый текст программы','Legacy CMS schema/custom content remains valid');
$_GET=['date'=>'2026-11-28','children'=>'2','adults'=>'1'];$api=kp_public_price_quote();
expect_price($api['guest_count']===3 && $api['admission_total']===2770,'Public server quote parses canonical integer query and category amounts');
$_GET=['date'=>'2026-11-28','children'=>'1.5','adults'=>'1'];try{kp_public_price_quote();throw new RuntimeException('Invalid query accepted');}catch(RuntimeException $e){expect_price($e->getCode()===422,'Invalid query cannot reach paid flow');}
$base=['date'=>'2026-11-27','children'=>1,'adults'=>1];
$basketCases=[$base,[...$base,'combos'=>['combo999'=>1]],[...$base,'combos'=>['combo999'=>1],'extras'=>['mc399'=>1,'vr'=>1]],[...$base,'date'=>'2026-11-28','combos'=>['combo999'=>1],'extras'=>['mc399'=>1,'vr'=>1]],[...$base,'combos'=>['combo999'=>1,'combo1499'=>1,'combo1999'=>1]],[...$base,'combos'=>['unknown'=>1]],[...$base,'extras'=>['vr'=>-1]],[...$base,'extras'=>['vr'=>1.5]],[...$base,'combos'=>null]];
$baskets=array_map(static fn(array $input):array=>kp_price_basket($catalog,$event,$input),$basketCases);
expect_price($baskets[0]['planned_total']===1680 && $baskets[0]['extras_total']===0 && $baskets[0]['bundles_total']===0,'No preselected paid extras');
expect_price($baskets[1]['planned_total']===2679 && count($baskets[1]['lines'])===3,'Bundle charged once without adding included MC and VR unit prices');
expect_price($baskets[2]['planned_total']===3577 && $baskets[2]['extras_total']===898,'Explicit additional activities remain separate from bundle');
expect_price($baskets[3]['planned_total']-$baskets[2]['planned_total']===200 && $baskets[3]['extras_total']===$baskets[2]['extras_total'] && $baskets[3]['bundles_total']===$baskets[2]['bundles_total'],'Date changes admission only');
expect_price($baskets[4]['bundles_total']===4497 && $baskets[4]['payable_total']===null && $baskets[4]['requires_terms_confirmation'],'Bundle composition is a plan, not an approved payable total');
foreach(array_slice($baskets,5) as $invalid)expect_price(!$invalid['ok'] && $invalid['error']==='invalid_selection','Invalid selection cannot change totals');
if(in_array('--matrix',$argv,true)){echo json_encode(['catalog'=>$catalog,'event'=>$event,'cases'=>$cases,'quotes'=>$quotes,'basket_cases'=>$basketCases,'baskets'=>$baskets],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";exit;}
echo json_encode(['pass'=>true,'checks'=>$checks,'quote_cases'=>count($cases)],JSON_THROW_ON_ERROR)."\n";
