const fs=require('fs');
const path=require('path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

const profile=read('profile.html');
const css=read('profile-dashboard.css');
const customer=read('api/customer.php');
const adminOrders=read('api/orders.php');
const create=read('api/order-create.php');
const schema=read('server/order-schema.php');
const validation=read('server/order-validation.php');
const checkout=read('checkout.html');
const checkoutJs=read('checkout.js');

assert.match(checkout,/name="pickup_store"/,'checkout must capture pickup store');
assert.match(checkout,/Проспект Победы, 79/,'checkout must offer Pobedy 79');
assert.match(checkout,/Проспект Победы, 118 строение 2/,'checkout must offer Pobedy 118 building 2');
assert.match(checkoutJs,/pickup_store:/,'checkout payload must send pickup store');
assert.match(validation,/invalid_pickup_store/,'server must reject unknown pickup stores');

assert.match(schema,/pickup_store/,'orders schema must persist pickup store');
assert.match(schema,/CREATE TABLE IF NOT EXISTS order_status_history/,'orders schema must create status history');
assert.match(schema,/function record_order_status/,'status writes must use a shared history helper');
assert.match(create,/record_order_status\(\$pdo,\$orderId,'new'/,'checkout must write initial new status');
assert.match(adminOrders,/record_order_status/,'admin status changes must be recorded');
assert.match(adminOrders,/legacy_current/,'old orders must retain truthful partial history');

assert.match(customer,/customer_order_image/,'customer order detail must resolve the current source image');
assert.match(customer,/customer_order_history/,'customer order detail must expose status history');
assert.match(customer,/action==='repeat_order'/,'customer API must expose repeat order');
assert.match(customer,/repeat_not_available/,'only completed orders may be repeated');
assert.match(customer,/stock_qty/,'repeat order must check current stock');
assert.match(customer,/price_rub/,'repeat order must check current price');
assert.match(customer,/current_available/,'order detail must report current product availability');

assert.match(profile,/function repeatOrder/,'profile must implement repeat order UI');
assert.match(profile,/Повторить заказ/,'completed order card must offer repeat');
assert.match(profile,/История статусов/,'order card must show status history');
assert.match(profile,/customerOrderItemImage/,'order card must show product image area');
assert.match(profile,/orderDeliveryText/,'order card must show pickup store or delivery address');
assert.match(profile,/Подробнее/,'order list must open full details');

for(const cls of ['customerOrderCard','customerOrderDetails','customerOrderItem','customerOrderHistory','customerRepeatOrder']){
  assert.match(css,new RegExp('\\.'+cls+'(?:\\{|[,.:])'),'orders CSS must contain .'+cls);
}
console.log('Customer order block 2 regression checks passed.');
