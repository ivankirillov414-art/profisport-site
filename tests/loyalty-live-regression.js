const fs=require('fs');
const path=require('path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

const engine=read('server/loyalty.php');
const create=read('api/order-create.php');
const validation=read('server/order-validation.php');
const checkout=read('checkout.html');
const checkoutJs=read('checkout.js');
const customer=read('api/customer.php');
const profile=read('profile.html');
const adminApi=read('api/loyalty-admin.php');
const adminJs=read('admin/loyalty-calculator.js');

for(const fn of ['loyalty_set_program_enabled','loyalty_reconcile_customer_lots','loyalty_expire_customer','loyalty_consume_fifo','loyalty_reserve_order_redemption','loyalty_refund_order_redemption']){
  assert.match(engine,new RegExp('function '+fn+'\\b'),'live loyalty engine missing '+fn);
}
assert.match(engine,/remaining_amount/,'loyalty ledger must track unspent credit lots');
assert.match(engine,/ORDER BY created_at,id FOR UPDATE/,'redemption must consume credit lots FIFO under lock');
assert.match(engine,/kind='redeem_refund'/,'cancelled orders must create an auditable refund');
assert.match(engine,/to==='cancelled'/,'order lifecycle must handle cancellation even after live mode is disabled');

assert.match(validation,/bonus_spend/,'order validation must accept requested bonus spend');
assert.match(create,/loyalty_reserve_order_redemption/,'checkout API must reserve points inside order creation');
assert.match(create,/loyalty_login_required/,'guest checkout must not spend account points');
assert.match(create,/bonus_spent/,'order response must expose reserved points');
assert.match(create,/payable_rub/,'order response must expose cash payable');

assert.match(checkout,/id="loyaltyCheckout"/,'checkout must have a loyalty redemption panel');
assert.match(checkoutJs,/bonus_spend:bonusSpend/,'checkout must submit the selected point amount');
assert.match(checkoutJs,/bonus_spend_exceeds_limit/,'checkout must handle a changed server-side limit');

assert.match(customer,/loyalty_available_balance/,'customer payload must sweep expiry before showing live balance');
assert.match(customer,/expires_at,status,created_at/,'customer payload must expose ledger expiry/status');
assert.match(profile,/id="loyalty"/,'customer profile must expose a loyalty section');
assert.match(profile,/function renderCustomerLoyalty/,'customer profile must render balance and ledger history');

assert.match(adminApi,/action==='activate'/,'owner API must support explicit activation');
assert.match(adminApi,/action==='deactivate'/,'owner API must support explicit deactivation');
assert.match(adminJs,/action:'publish'/,'central loyalty editor must publish through one action');
assert.match(adminJs,/current_password:password/,'central loyalty publish must require the current admin password');
assert.doesNotMatch(adminJs,/setProgram\('activate'\)|setProgram\('deactivate'\)/,'secondary activation UI path must stay removed');

new Function(checkoutJs);
const inline=[...profile.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]).filter(Boolean);
for(const source of inline)new Function(source);
new Function(adminJs);

console.log('Live loyalty checkout, FIFO, expiry, refund and customer UI regression checks passed.');
