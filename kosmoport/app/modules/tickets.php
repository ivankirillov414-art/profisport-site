<?php
declare(strict_types=1);
require_once __DIR__.'/../tickets/Service.php';
use Kosmoport\Tickets\Service;
use Kosmoport\Tickets\TicketError;
$wrap=static function(array $roles,callable $fn):Closure{return static function()use($roles,$fn):void{
    $user=crm_require_roles($roles);
    try{$s=new Service(crm_db(),'crm_audit',(string)$user['id']);$data=$fn($s);crm_json(['ok'=>true,'data'=>$data]);}
    catch(TicketError $e){crm_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage(),'conflicts'=>$e->conflicts]],$e->status);}
};};
return [
 'GET tickets/sources'=>$wrap(['cashier','manager'],fn($s)=>['items'=>$s->sources()]),
 'GET tickets/batches'=>$wrap(['cashier','manager'],fn($s)=>['items'=>$s->batches()]),
 'POST tickets/check'=>$wrap(['cashier','manager'],fn($s)=>$s->check((string)(crm_input()['code']??''))),
 'POST tickets/admit'=>$wrap(['cashier','manager'],fn($s)=>$s->admit(crm_input())),
 'POST tickets/reverse'=>$wrap(['manager'],fn($s)=>$s->reverse(crm_input())),
 'GET tickets/statistics'=>$wrap(['cashier','manager'],fn($s)=>$s->statistics((string)($_GET['period']??'day'),(string)($_GET['date']??(new DateTimeImmutable('now',new DateTimeZone('Asia/Yekaterinburg')))->format('Y-m-d')))),
 'POST tickets/import'=>$wrap(['manager'],fn($s)=>$s->import(crm_input())),
 'GET tickets/export'=>$wrap(['manager'],fn($s)=>$s->export()),
];

