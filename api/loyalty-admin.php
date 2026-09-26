<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
start_secure_session();
function out(array $x,int $c=200):never{http_response_code($c);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
  require_admin();
  if($_SERVER['REQUEST_METHOD']!=='GET')out(['ok'=>false,'error'=>'method_not_allowed'],405);
  $status=loyalty_program_status($pdo);
  $stats=[
    'customer_balance'=>(int)$pdo->query('SELECT COALESCE(SUM(bonus_balance),0) FROM customers')->fetchColumn(),
    'customers_with_balance'=>(int)$pdo->query('SELECT COUNT(*) FROM customers WHERE bonus_balance>0')->fetchColumn(),
    'transactions'=>(int)$pdo->query('SELECT COUNT(*) FROM loyalty_transactions')->fetchColumn(),
    'active_transactions'=>(int)$pdo->query("SELECT COUNT(*) FROM loyalty_transactions WHERE status='active'")->fetchColumn(),
    'expired_transactions'=>(int)$pdo->query("SELECT COUNT(*) FROM loyalty_transactions WHERE status='expired'")->fetchColumn(),
  ];
  out(['ok'=>true,'program'=>$status,'stats'=>$stats]);
}catch(Throwable $e){error_log($e->__toString());out(['ok'=>false,'error'=>'server_error'],500);}
