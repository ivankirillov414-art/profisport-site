<?php
declare(strict_types=1);
$applicationRoot=dirname(__DIR__);
try { if ((getenv('KCRM_CONFIG') || is_file($applicationRoot.'/config/config.php')) && is_file($applicationRoot.'/app/bootstrap.php')) require_once $applicationRoot.'/app/bootstrap.php'; }
catch (Throwable $error) { error_log('Kosmoport public bootstrap: '.$error->getMessage()); }
require_once $applicationRoot.'/app/website/content.php';
$publication=kp_read_published();
$content=$publication['content'];
$field=static fn(string $id): string=>kp_escape($content['fields'][$id]??'');
$brand=kp_escape($content['brand']);
$event=$content['event'];
$dates=kp_escape(kp_event_dates($event));
$venue=kp_escape($event['city'].' · '.$event['venue']);
$price=kp_escape(kp_money($content['tickets']['price']));
$familyPrice=kp_escape(kp_money($content['tickets']['price']===null?null:$content['tickets']['price']*3));
$clientConfig=[
    'brand'=>$content['brand'],'ticketPrice'=>$content['tickets']['price'],
    'salesOpen'=>$content['tickets']['sales_open'],'checkoutUrl'=>$content['tickets']['checkout_url'],
    'openingDate'=>$event['opening_date'],'closingDate'=>$event['closing_date'],
    'venue'=>$event['city'].', '.$event['venue'].', '.$event['address'],
    'dates'=>kp_event_dates($event),'fields'=>$content['fields'],
];
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-cache');
require $applicationRoot.'/app/website/template.php';
