<?php
declare(strict_types=1);
require __DIR__.'/guard.php';
$checks=[];
$checks['PHP 8.1+']=version_compare(PHP_VERSION,'8.1','>=')?'OK':'Требуется PHP 8.1+';
foreach(['pdo_mysql','mbstring','json','fileinfo'] as $ext)$checks['Расширение '.$ext]=extension_loaded($ext)?'OK':'Отсутствует';
$required=[
 'orders'=>['id','customer_id','order_number','customer_name','phone','email','delivery_method','address','comment','status','total_rub','request_key','request_hash','created_at'],
 'order_items'=>['id','order_id','product_id','title','price_rub','quantity','line_total_rub'],
 'products'=>['id','name','price_rub','stock_qty','is_active'],
 'service_requests'=>['id','request_number','name','phone','status','request_key'],
];
foreach($required as $table=>$columns){
 try{$present=table_columns($pdo,$table);$missing=array_diff($columns,array_keys($present));$checks['Таблица '.$table]=$missing?'Не хватает полей: '.implode(', ',$missing):'OK';}
 catch(Throwable $e){$checks['Таблица '.$table]='Не удалось проверить';error_log($e->__toString());}
}
try{$pdo->query('SELECT id,order_number,customer_name,phone,status,total_rub,created_at FROM orders ORDER BY id DESC LIMIT 1');$checks['Чтение заказов']='OK';}
catch(Throwable $e){$checks['Чтение заказов']='Ошибка SQL: '.(string)$e->getCode();error_log($e->__toString());}
function health_escape(string $s):string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Проверка магазина — ПрофиСпорт</title>
<style>body{font:16px/1.5 system-ui;margin:24px auto;padding:0 16px;max-width:800px;background:#f5f5f3;color:#202124}table{border-collapse:collapse;background:white;width:100%}td,th{padding:12px;text-align:left;border-bottom:1px solid #ddd}a{color:inherit}.ok{color:#18763c}.bad{color:#a92b20}</style>
<a href="index.php">← Управление магазином</a><h1>Проверка магазина</h1><p>Проверка сервера и структуры базы. Данные клиентов и пароли здесь не отображаются.</p><table><tr><th>Проверка</th><th>Результат</th></tr>
<?php foreach($checks as $label=>$result):?><tr><td><?=health_escape($label)?></td><td class="<?=$result==='OK'?'ok':'bad'?>"><?=health_escape($result)?></td></tr><?php endforeach;?></table></html>
