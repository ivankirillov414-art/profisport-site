<?php
declare(strict_types=1);
function crm_require_roles(array $roles):array{throw new RuntimeException(implode(',',$roles));}
$routes=require __DIR__.'/../app/modules/tickets.php';
foreach(['GET tickets/sources','GET tickets/batches','POST tickets/check','POST tickets/admit','POST tickets/reverse','GET tickets/statistics','POST tickets/import','GET tickets/export'] as $r){if(!isset($routes[$r]))throw new RuntimeException('Missing route '.$r);try{$routes[$r]();}catch(RuntimeException $e){$wanted=in_array($r,['POST tickets/reverse','POST tickets/import','GET tickets/export'],true)?'manager':'cashier,manager';if($e->getMessage()!==$wanted)throw $e;continue;}throw new RuntimeException('No role guard');}
echo "TICKETS_ROUTES_PASS: eight routes invoke role guard before DB access; cashier cannot import/export/reverse\n";
