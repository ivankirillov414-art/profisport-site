const fs=require('fs');
const path=require('path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

const list=read('admin/customers.php');
const detail=read('admin/customer.php');
const api=read('api/customer-admin.php');

assert.match(list,/customer\.php\?id=/,'customer list must link to a dedicated customer card');
assert.match(list,/Отзывов:/,'customer list should expose review count');
assert.match(api,/function customer_detail\b/,'admin API must expose customer detail helper');
for(const token of ['preferred_store','customer_vehicle_rows','customer_service_rows','loyalty_transactions','audit_log','order_status_history']){
  assert.match(api,new RegExp(token),'customer detail API missing '+token);
}
for(const title of ['Профиль','Персональная скидка','Бонусы','Моя техника','Сервис','Заказы','Избранное','Отзывы','История действий']){
  assert.match(detail,new RegExp(title),'customer card missing section '+title);
}
assert.match(detail,/customer-admin\.php\?id=/,'customer card must load admin customer detail API');
assert.match(detail,/service_requests/,'customer card must render service data');
assert.match(detail,/loyalty_program/,'customer card must surface loyalty program state');

const inline=[...detail.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]).filter(Boolean);
for(const source of inline)new Function(source);
const listInline=[...list.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]).filter(Boolean);
for(const source of listInline)new Function(source);

console.log('Admin customer card regression checks passed.');
