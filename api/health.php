<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    require __DIR__.'/../server/bootstrap.php';
    $pdo->query('SELECT 1')->fetchColumn();
    $required=[
        'products'=>['id','name','price_rub','stock_qty','is_active'],
        'customers'=>['id','email','phone','bonus_balance'],
        'orders'=>['id','customer_id','total_rub','payable_rub','bonus_spent','bonus_earned'],
        'loyalty_transactions'=>['id','customer_id','amount','status','remaining_amount'],
        'customer_vehicles'=>['id','customer_id','is_active'],
        'service_requests'=>['id','customer_id','vehicle_id','status'],
        'service_request_status_history'=>['id','service_request_id','status'],
        'vehicle_components'=>['id','vehicle_id','component_key','source_type','wear_mode'],
        'vehicle_component_events'=>['id','component_id','event_type','event_at','include_learning'],
        'vehicle_spec_research_queue'=>['id','product_id','title','match_key','status'],
        'vehicle_maintenance_alerts'=>['id','customer_id','vehicle_id','component_id','severity','status'],
        'vehicle_replacement_purchases'=>['id','customer_id','order_id','order_item_id','product_id','quantity','status'],
        'vehicle_replacement_assignments'=>['id','purchase_id','component_id','assigned_at'],
    ];
    foreach($required as $table=>$columns){
        $present=table_columns($pdo,$table);
        foreach($columns as $column)if(!isset($present[$column]))throw new RuntimeException('schema_not_ready');
    }
    $active=(int)$pdo->query('SELECT COUNT(*) FROM products WHERE is_active=1')->fetchColumn();
    http_response_code(200);
    echo json_encode([
        'ok'=>true,
        'database'=>true,
        'schema'=>true,
        'catalog_active'=>$active,
        'service'=>'profisport-store',
        'time'=>gmdate('c'),
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log($e->__toString());
    http_response_code(503);
    echo json_encode([
        'ok'=>false,
        'database'=>false,
        'schema'=>false,
        'service'=>'profisport-store',
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
