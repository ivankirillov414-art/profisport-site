<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
function out(array $x,int $c=200):never{http_response_code($c);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}

try{
  $admin=require_admin();
  if($_SERVER['REQUEST_METHOD']==='POST'){
    if(($admin['role']??'')!=='owner')out(['ok'=>false,'error'=>'forbidden'],403);
    csrf_check();$in=input_json();$action=(string)($in['action']??'save_draft');
    if($action==='save_draft'){
      $cfg=loyalty_center_store_draft($pdo,is_array($in['config']??null)?$in['config']:[]);
      audit($pdo,'loyalty_draft_save','loyalty','v2',['config'=>$cfg]);
      out(['ok'=>true,'draft'=>$cfg,'program'=>loyalty_program_status($pdo),'csrf'=>$_SESSION['csrf']??'']);
    }
    if($action==='publish'){
      $cfg=is_array($in['config']??null)?$in['config']:loyalty_center_draft($pdo);
      $password=(string)($in['current_password']??'');
      $program=loyalty_center_publish($pdo,$cfg,(int)$admin['id'],$password);
      audit($pdo,'loyalty_publish','loyalty','v2',['enabled'=>$program['enabled'],'config'=>$program['config']]);
      out(['ok'=>true,'program'=>$program,'draft'=>loyalty_center_draft($pdo),'csrf'=>$_SESSION['csrf']??'']);
    }
    out(['ok'=>false,'error'=>'bad_action'],422);
  }
  if($_SERVER['REQUEST_METHOD']!=='GET')out(['ok'=>false,'error'=>'method_not_allowed'],405);
  $status=loyalty_program_status($pdo);
  $stats=[
    'customer_balance'=>(int)$pdo->query('SELECT COALESCE(SUM(bonus_balance),0) FROM customers')->fetchColumn(),
    'customers_with_balance'=>(int)$pdo->query('SELECT COUNT(*) FROM customers WHERE bonus_balance>0')->fetchColumn(),
    'transactions'=>(int)$pdo->query('SELECT COUNT(*) FROM loyalty_transactions')->fetchColumn(),
    'customers_with_personal_discount'=>(int)$pdo->query('SELECT COUNT(*) FROM customer_discounts WHERE enabled=1 AND percent_bp>0')->fetchColumn(),
    'versions'=>(int)$pdo->query('SELECT COUNT(*) FROM loyalty_config_versions')->fetchColumn(),
  ];
  $versions=$pdo->query('SELECT id,enabled,published_by_admin_user_id,published_at FROM loyalty_config_versions ORDER BY id DESC LIMIT 10')->fetchAll();
  out([
    'ok'=>true,
    'program'=>$status,
    'draft'=>loyalty_center_draft($pdo),
    'stats'=>$stats,
    'category_options'=>loyalty_center_category_options($pdo),
    'preview_products'=>loyalty_center_preview_products($pdo,10),
    'versions'=>$versions,
    'csrf'=>$_SESSION['csrf']??'',
    'can_edit'=>($admin['role']??'')==='owner'
  ]);
}catch(InvalidArgumentException $e){out(['ok'=>false,'error'=>$e->getMessage()],422);
}catch(DomainException $e){out(['ok'=>false,'error'=>$e->getMessage()],$e->getMessage()==='current_password_invalid'?403:409);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log($e->__toString());out(['ok'=>false,'error'=>'server_error'],500);}
