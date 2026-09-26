<?php
declare(strict_types=1);
require __DIR__.'/../server/loyalty.php';

$default=loyalty_default_config();
if($default['enabled']!==false||loyalty_configured($default)!==false)throw new RuntimeException('Default loyalty config must be disabled and incomplete');

$config=$default;
$config['earn_enabled']=true;
$config['redeem_enabled']=true;
$config['expiration_enabled']=true;
$config['review_bonus_enabled']=true;
$config['category_exclusions_enabled']=true;
$config['earn_percent_bp']=500;
$config['max_redeem_percent_bp']=3000;
$config['point_value_kopeks']=100;
$config['expiration_days']=365;
$config['min_order_rub']=1000;
$config['review_bonus']=25;
$config['excluded_category_prefixes']=['Подарочные сертификаты'];
if(!loyalty_configured($config))throw new RuntimeException('Complete loyalty config was rejected');

$preview=loyalty_order_earn_preview([
  ['line_total_rub'=>10000,'category_path'=>'Велосипеды / Горные'],
  ['line_total_rub'=>2000,'category_path'=>'Подарочные сертификаты / Электронные'],
],$config);
if($preview['eligible_rub']!==10000||$preview['points']!==500)throw new RuntimeException('Loyalty earn preview is incorrect');
$redeem=loyalty_redemption_preview(10000,2000,$config);
if($redeem['max_points']!==2000||$redeem['discount_rub']!==2000||$redeem['payable_rub']!==8000)throw new RuntimeException('Loyalty redemption preview is incorrect');

$config['min_order_rub']=20000;
$preview=loyalty_order_earn_preview([['line_total_rub'=>10000,'category_path'=>'Велосипеды']],$config);
if($preview['points']!==0)throw new RuntimeException('Minimum order rule is incorrect');

echo "PASS: loyalty config safety, feature switches, category exclusions, earn and redemption math\n";
