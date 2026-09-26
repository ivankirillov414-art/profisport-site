<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
function out(array $x,int $c=200):never{http_response_code($c);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function loyalty_category_options(PDO $pdo): array {
  $rows=$pdo->query("SELECT category_path,COUNT(*) product_count FROM products WHERE category_path IS NOT NULL AND TRIM(category_path)<>'' GROUP BY category_path ORDER BY product_count DESC,category_path LIMIT 500")->fetchAll();
  $totals=[];
  foreach($rows as $row){
    $path=trim((string)$row['category_path']);if($path==='')continue;
    $parts=preg_split('~\s*(?:/|>|\\\\|»|→)\s*~u',$path)?:[$path];
    $top=trim((string)($parts[0]??$path));if($top==='')$top=$path;
    $totals[$top]=($totals[$top]??0)+(int)$row['product_count'];
  }
  arsort($totals,SORT_NUMERIC);$out=[];
  foreach(array_slice($totals,0,80,true) as $name=>$count)$out[]=['name'=>$name,'count'=>$count];
  return $out;
}
try{
  $admin=require_admin();
  if($_SERVER['REQUEST_METHOD']==='POST'){
    if(($admin['role']??'')!=='owner')out(['ok'=>false,'error'=>'forbidden'],403);
    csrf_check();$in=input_json();$action=(string)($in['action']??'save_draft');
    if($action==='save_draft'){
      if(loyalty_program_enabled($pdo))out(['ok'=>false,'error'=>'program_live'],409);
      $cfg=loyalty_save_draft($pdo,is_array($in['config']??null)?$in['config']:[]);
      audit($pdo,'loyalty_draft_save','loyalty','v1',['config'=>$cfg]);
      out(['ok'=>true,'program'=>loyalty_program_status($pdo),'csrf'=>$_SESSION['csrf']??'']);
    }
    if($action==='activate'){
      $program=loyalty_set_program_enabled($pdo,true,(int)($admin['id']??0));
      audit($pdo,'loyalty_activate','loyalty','v1',['activation_at'=>$program['activation_at']]);
      out(['ok'=>true,'program'=>$program,'csrf'=>$_SESSION['csrf']??'']);
    }
    if($action==='deactivate'){
      $program=loyalty_set_program_enabled($pdo,false,(int)($admin['id']??0));
      audit($pdo,'loyalty_deactivate','loyalty','v1');
      out(['ok'=>true,'program'=>$program,'csrf'=>$_SESSION['csrf']??'']);
    }
    out(['ok'=>false,'error'=>'bad_action'],422);
  }
  if($_SERVER['REQUEST_METHOD']!=='GET')out(['ok'=>false,'error'=>'method_not_allowed'],405);
  $status=loyalty_program_status($pdo);
  $stats=[
    'customer_balance'=>(int)$pdo->query('SELECT COALESCE(SUM(bonus_balance),0) FROM customers')->fetchColumn(),
    'customers_with_balance'=>(int)$pdo->query('SELECT COUNT(*) FROM customers WHERE bonus_balance>0')->fetchColumn(),
    'transactions'=>(int)$pdo->query('SELECT COUNT(*) FROM loyalty_transactions')->fetchColumn(),
    'active_transactions'=>(int)$pdo->query("SELECT COUNT(*) FROM loyalty_transactions WHERE status='active'")->fetchColumn(),
    'expired_transactions'=>(int)$pdo->query("SELECT COUNT(*) FROM loyalty_transactions WHERE status='expired'")->fetchColumn(),
  ];
  out(['ok'=>true,'program'=>$status,'stats'=>$stats,'category_options'=>loyalty_category_options($pdo),'csrf'=>$_SESSION['csrf']??'','can_edit'=>($admin['role']??'')==='owner','live_activation_available'=>true,'can_activate'=>($admin['role']??'')==='owner'&&$status['configured']]);
}catch(InvalidArgumentException $e){out(['ok'=>false,'error'=>$e->getMessage()],422);
}catch(Throwable $e){error_log($e->__toString());out(['ok'=>false,'error'=>'server_error'],500);}
