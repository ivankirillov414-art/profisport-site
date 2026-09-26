const fs=require('fs');
const path=require('path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

const page=read('admin/loyalty.php');
const js=read('admin/loyalty-calculator.js');
const css=read('admin/loyalty-calculator.css');
const api=read('api/loyalty-admin.php');
const engine=read('server/loyalty.php');
const center=read('server/loyalty-center.php');
const customerAdmin=read('api/customer-admin.php');
const checkout=read('checkout.js');
const orderCreate=read('api/order-create.php');
const orderSchema=read('server/order-schema.php');

for(const id of ['programEnabled','discountGroups','addDiscountGroup','discountStackRule','earnBasis','productPreview','earnEnabled','redeemEnabled','expirationEnabled','reviewBonusEnabled','saveDraft','publishChanges','publishPassword']){
  assert.match(page,new RegExp('id="'+id+'"'),'loyalty center missing #'+id);
}
assert.match(page,/Центр лояльности/,'single loyalty center title is required');
assert.doesNotMatch(page,/id="activateProgram"|id="deactivateProgram"/,'second activation UI must be removed');
assert.match(js,/action:'publish'/,'editor must publish through one password-gated action');
assert.match(js,/current_password:password/,'publish must send the current admin password');
assert.match(js,/window\.confirm/,'publish must require final confirmation');
assert.match(js,/function renderPreview/,'draft must preview real catalog products');
assert.match(js,/function renderGroups/,'editor must render dynamic discount groups');

assert.match(api,/loyalty_center_store_draft/,'draft must be isolated from live config');
assert.match(api,/loyalty_center_publish/,'publish must be handled by central domain');
assert.match(api,/preview_products/,'API must provide actual products for preview');
assert.match(api,/category_options/,'API must provide actual 1C categories');

for(const fn of ['ensure_loyalty_center_schema','loyalty_center_publish','loyalty_effective_discount','loyalty_discount_order_items','loyalty_set_customer_discount']){
  assert.match(center,new RegExp('function '+fn+'\\b'),'central loyalty domain missing '+fn);
}
assert.match(center,/loyalty_draft_config_v2/,'draft must have a separate settings key');
assert.match(center,/loyalty_config_versions/,'published configurations must be versioned');
assert.match(center,/customer_discounts/,'personal discounts need a server table');
assert.match(center,/current_password_invalid/,'publish must reject a wrong password');
assert.match(center,/discount_stack_rule/,'discount overlap policy must be explicit');
assert.match(engine,/discounts_enabled/,'active loyalty config must include discounts');
assert.match(engine,/earn_basis/,'active loyalty config must include bonus accrual basis');

assert.match(customerAdmin,/action==='set_discount'/,'customer card API must support personal discount');
assert.match(customerAdmin,/role.*owner/,'personal discount changes must be owner-only');
assert.match(orderCreate,/loyalty_discount_order_items/,'server checkout must apply central discounts');
assert.match(orderCreate,/subtotal_rub/,'orders must snapshot pre-discount subtotal');
assert.match(orderCreate,/discount_rub/,'orders must snapshot discount amount');
assert.match(orderSchema,/base_price_rub/,'order items must preserve base price');
assert.match(orderSchema,/discount_percent_bp/,'order items must preserve applied discount');
assert.match(checkout,/function productDiscount/,'checkout must preview published discounts');
assert.match(checkout,/customer_discount/,'checkout must include the signed-in customer discount');

new Function(js);new Function(checkout);
console.log('Central loyalty editor, category discounts, personal discounts and publish flow checks passed.');