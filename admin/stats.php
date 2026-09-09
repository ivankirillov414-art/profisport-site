<?php
require __DIR__.'/guard.php';
$rows=$pdo->query('SELECT status,COUNT(*) AS count,COALESCE(SUM(total_rub),0) AS total FROM orders GROUP BY status')->fetchAll();
$labels=['new'=>'Новые','confirmed'=>'Подтверждённые','processing'=>'В работе','ready'=>'Готовы к выдаче','completed'=>'Завершённые','cancelled'=>'Отменённые'];
?><!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Статистика — ПрофиСпорт</title><link rel="stylesheet" href="reports.css"><main><a href="index.php">← Управление магазином</a><h1>Статистика заказов</h1><p>Суммы товаров по сохранённым заказам за всё время. Оплату и стоимость доставки этот отчёт не учитывает.</p><table><thead><tr><th>Статус</th><th>Заказов</th><th>Сумма товаров</th></tr></thead><tbody>
<?php foreach($rows as $row):?><tr><td><?=htmlspecialchars($labels[$row['status']]??$row['status'],ENT_QUOTES,'UTF-8')?></td><td><?=(int)$row['count']?></td><td><?=number_format((float)$row['total'],2,',',' ')?> ₽</td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="3">Заказов пока нет.</td></tr><?php endif;?></tbody></table><p><a href="orders.php">Перейти к обработке заказов →</a></p></main></html>
