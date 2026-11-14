<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/payments/readiness.php';

$tests=0;
function check(bool $ok,string $name):void {global $tests;$tests++;if(!$ok)throw new RuntimeException($name);}
function crm_current_user():?array {return $GLOBALS['actor']??null;}
function crm_fail(string $code,string $message,int $status=422):never {throw new RuntimeException($code,$status);}
function crm_config():array {if(!isset($GLOBALS['private']))throw new RuntimeException('Config must not be read before authorization');return $GLOBALS['private'];}

$empty=kp_payment_readiness_report([],[]);
check($empty['provider']===null && $empty['mode']==='disabled','No provider or mode invented');
check(!$empty['live_ready'] && !$empty['sales_open'] && !$empty['hosted_redirect_configured'],'Closed by default');
check($empty['published_price']===null,'No stale or invented price');
check(!kp_payment_https('http://example.test/pay') && !kp_payment_https('https://user:secret@example.test/pay') && !kp_payment_https('javascript:alert(1)'),'Unsafe checkout URLs rejected');
foreach([0,-1,'849',NAN,INF,849.001,1000001] as $invalid)check(!kp_payment_positive_price($invalid),'Invalid paid price rejected');
check(kp_payment_positive_price(849.0) && kp_payment_positive_price(849.99),'Numeric price accepted');
$private=['website'=>['public_url'=>'https://example.test','organizer_name'=>'Test organizer','privacy_url'=>'https://example.test/privacy','contact_email'=>'owner@example.test'],
 'payments'=>['provider'=>'yookassa','mode'=>'live','merchant_status'=>'approved','merchant_id'=>'PRIVATE-MERCHANT-ID','secret_key'=>'PRIVATE-SECRET-KEY','return_url'=>'https://example.test/return','webhook_url'=>'https://example.test/hook','offer_url'=>'https://example.test/offer','fiscalization'=>'provider_receipts']];
$full=kp_payment_readiness_report($private,['tickets'=>['price'=>849,'sales_open'=>true,'checkout_url'=>'https://example.test/pay']]);
check($full['hosted_checkout_url_present'] && !$full['hosted_redirect_configured'] && !$full['live_ready'] && !$full['provider_verified'],'Configured legacy URL cannot enable the closed catalog payment route');
$json=json_encode($full,JSON_THROW_ON_ERROR);
check(!str_contains($json,'PRIVATE-MERCHANT-ID') && !str_contains($json,'PRIVATE-SECRET-KEY'),'Secrets and merchant IDs not returned');
check(in_array('provider_order_api',$full['blockers'],true) && in_array('verified_payment_notifications',$full['blockers'],true),'Unimplemented critical stages always blocked');
foreach([null,['role'=>'admin'],['role'=>'editor'],['role'=>'manager']] as $actor){$GLOBALS['actor']=$actor;try{kp_payment_readiness();throw new RuntimeException('Unauthorized allowed');}catch(RuntimeException $e){check($e->getCode()===($actor===null?401:403),'Explicit owner-only authorization before config/content');}}
$routes=require dirname(__DIR__).'/app/modules/payments.php';
check(array_keys($routes)===['GET payments/readiness'],'Only read-only route added');
$GLOBALS['actor']=['id'=>'owner-fixture','role'=>'owner'];$GLOBALS['private']=$private;
$owner=($routes['GET payments/readiness'])();
check($owner['credentials']['secret_key_present'] && $owner['published_price']===null && !$owner['live_ready'],'Owner endpoint reads private flags and actual packaged publication without enabling sales');
check($owner['price_source']==='owner_confirmed_catalog' && $owner['price_catalog']['admission']['weekday']['adult']===890,'New category catalog is not confused with missing old scalar price');
check(!in_array('price',$owner['blockers'],true) && !$owner['checkout_route_enabled'],'Confirmed catalog prices are present while unintegrated checkout remains closed');
check($owner['price_catalog']['confirmed_on']==='2026-11-14' && $owner['price_catalog']['version']===2 && array_column($owner['price_catalog']['combos'],'includes_admission')===[false,false,false] && !in_array('activities',$owner['blockers'],true) && !in_array('combos',$owner['blockers'],true),'Confirmed activity and combo catalog prices are inventoried');
echo json_encode(['pass'=>true,'checks'=>$tests,'scope'=>'Pure readiness inventory, authorization failure paths, no network or payment calls'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
