<?php
declare(strict_types=1);
require __DIR__.'/../app/tickets/Service.php';
use Kosmoport\Tickets\Service;use Kosmoport\Tickets\TicketError;
function expect(bool $x,string $m):void{if(!$x)throw new RuntimeException($m);}
function rejects(callable $f,string $code):void{try{$f();}catch(TicketError $e){expect($e->errorCode===$code,'wrong error '.$e->errorCode.' expected '.$code);return;}throw new RuntimeException('expected rejection '.$code);}
$db=new PDO('sqlite::memory:');$db->exec('PRAGMA foreign_keys=ON');
$sql=file_get_contents(__DIR__.'/../migrations/030_tickets.sql');$sql=preg_replace('/ ENGINE=InnoDB(?: DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin)?/','',$sql);$sql=str_replace('INSERT IGNORE','INSERT OR IGNORE',$sql);$sql=str_replace(', INDEX(batch_id), INDEX(used_at)','',$sql);$sql=str_replace(', INDEX(admitted_at)','',$sql);$db->exec($sql);
$audit=[];$s=new Service($db,function(...$args)use(&$audit){$audit[]=$args;},'staff-1');
$doc=['format'=>Service::FORMAT,'version'=>1,'sources'=>[['id'=>'source-original','name'=>'Школа 1','category'=>'Школы','address'=>'Оренбург']],'batches'=>[['id'=>'batch-original','source_id'=>'source-original','name'=>'Партия','active'=>1,'print_config'=>[]]],'tickets'=>[['code'=>'999999999000000001','batch_id'=>'batch-original','used_at'=>null,'scan_id'=>null],['code'=>'999999999000000002','batch_id'=>'batch-original','used_at'=>null,'scan_id'=>null]],'admissions'=>[],'events'=>[]];
expect($s->import($doc)['added']===4,'import counts');expect($s->import($doc)['unchanged']===4,'repeat import');
$missing=$doc;$missing['sources']=[];$missing['batches']=[];$missing['tickets']=[['code'=>'999999999000000003','batch_id'=>'missing','used_at'=>null,'scan_id'=>null]];rejects(fn()=>$s->import($missing),'missing_batch');
$s->check('999999999000000001');expect($s->statistics('all','2026-10-31')['used_invitations']===0,'check consumed ticket');
rejects(fn()=>$s->admit(['code'=>'999999999000000001','request_id'=>'r1']),'adult_confirmation_required');
$request=['code'=>'999999999000000001','request_id'=>'r1','adult_payment_confirmed'=>true];$a=$s->admit($request);expect($s->admit($request)===$a,'idempotency');expect($a['fiscal_payment_verified']===false,'fiscal claim');
rejects(fn()=>$s->admit([...$request,'code'=>'999999999000000002']),'request_conflict');rejects(fn()=>$s->admit([...$request,'request_id'=>'r2']),'already_used');
expect($s->statistics('all','2026-10-31')['used_invitations']===1,'statistics');
$conflict=$doc;$conflict['sources'][0]['name']='Changed';$conflict['sources'][]=['id'=>'new-source','name'=>'Must roll back','category'=>'Школы','address'=>''];rejects(fn()=>$s->import($conflict),'import_conflict');expect(count($s->sources())===1,'partial import persisted');
$s->reverse(['admission_id'=>$a['admission_id'],'reason'=>'Ошибка сотрудника']);expect($s->statistics('all','2026-10-31')['used_invitations']===0,'reverse statistics');rejects(fn()=>$s->reverse(['admission_id'=>$a['admission_id'],'reason'=>'Again']),'already_reversed');
$b=$s->admit([...$request,'request_id'=>'r3']);expect($b['admission_id']!==$a['admission_id'],'readmit ID');expect($s->admit($request)===$a,'retry after reversal changed old response');
$db->exec("UPDATE kcrm_ticket_batches SET active=0");rejects(fn()=>$s->check('999999999000000002'),'blocked');rejects(fn()=>$s->admit([...$request,'code'=>'999999999000000002','request_id'=>'blocked']),'blocked');
expect(Service::range('day','2026-11-01')===['2026-10-31T19:00:00.000Z','2026-11-01T19:00:00.000Z'],'Orenburg day');expect(Service::range('week','2026-11-01')===['2026-10-25T19:00:00.000Z','2026-11-01T19:00:00.000Z'],'Monday week');rejects(fn()=>Service::range('day','2026-02-30'),'invalid_date');
expect(Service::range('month','2026-11-30')===['2026-10-31T19:00:00.000Z','2026-11-30T19:00:00.000Z'],'month boundary');
expect(count($audit)>=5,'audit missing');$export=$s->export();expect($export['tickets'][0]['scan_id']===$b['admission_id'],'export live state');
echo "TICKETS_SERVICE_PASS: import rollback, stable IDs, preview, manual payment, idempotency, duplicate, reversal, block, UTC+5 statistics, export\n";
