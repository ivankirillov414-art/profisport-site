const fs=require('fs');
const path=require('path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

const domain=read('server/vehicle-passport.php');
const customer=read('api/customer.php');
const profile=read('profile.html');
const css=read('profile-dashboard.css');
const customerAdmin=read('api/customer-admin.php');
const adminCustomer=read('admin/customer.php');

for(const table of ['vehicle_replacement_purchases','vehicle_replacement_assignments']){
  assert.match(domain,new RegExp('CREATE TABLE IF NOT EXISTS '+table),'replacement assignment schema missing '+table);
}
for(const fn of ['vehicle_replacement_candidate_rows','vehicle_replacement_pending_for_customer','vehicle_replacement_assign_customer','vehicle_passport_sync_replacement_purchases']){
  assert.match(domain,new RegExp('function '+fn+'\\b'),'replacement assignment domain missing '+fn);
}
assert.match(domain,/if\(count\(\$rows\)===1\)/,'unique compatible purchase may keep the existing single-bike flow');
assert.match(domain,/if\(count\(\$rows\)>1\)/,'ambiguous compatible purchase must create a pending choice');
assert.match(domain,/remaining_qty/,'pending purchase must track remaining quantity');
assert.match(domain,/replacement_component_not_compatible/,'server must reject a component that is not compatible with the purchased product');
assert.match(domain,/replacement_already_assigned/,'same purchase unit must not be assigned twice to the same component');
assert.match(domain,/status='assigned'/,'fully consumed purchase must leave the pending queue');

assert.match(customer,/replacement_purchases/,'customer payload must expose ambiguous replacement purchases');
assert.match(customer,/action==='assign_replacement_purchase'/,'customer API must accept explicit assignment');
assert.match(customer,/vehicle_replacement_assign_customer/,'assignment must be validated by the server domain');

assert.match(profile,/id="replacementPurchases"/,'customer cabinet needs a replacement purchase choice surface');
assert.match(profile,/function renderReplacementPurchases/,'customer cabinet must render pending purchase choices');
assert.match(profile,/На какую технику установили/,'UI must ask the customer instead of guessing');
assert.match(profile,/data-assign-purchase/,'each candidate must have an explicit assignment action');
assert.match(profile,/assign_replacement_purchase/,'UI assignment must call the protected customer API');
assert.match(css,/\.replacementPurchaseCard/,'replacement assignment cards must be styled');

assert.match(customerAdmin,/replacement_purchases/,'admin payload must expose pending replacement purchases');
assert.match(customerAdmin,/action==='assign_replacement_purchase'/,'admin API must assign a pending replacement purchase');
assert.match(customerAdmin,/vehicle_replacement_assign_customer\(\$pdo,\$id,\$purchaseId,\$componentId,\(int\)\(\$admin\['id'\]/,'admin ID must be passed into the replacement event');
assert.match(adminCustomer,/Неназначенные расходники/,'admin card must surface pending replacement purchases');
assert.match(adminCustomer,/data-admin-assign-purchase/,'admin card must offer explicit vehicle/component choices');

const inline=[...profile.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]).filter(Boolean);
for(const source of inline)new Function(source);
const adminInline=[...adminCustomer.matchAll(/<script(?![^>]*\\bsrc=)[^>]*>([\\s\\S]*?)<\\/script>/g)].map(m=>m[1]).filter(Boolean);
for(const source of adminInline)new Function(source);
console.log('Ambiguous replacement purchase assignment checks passed.');