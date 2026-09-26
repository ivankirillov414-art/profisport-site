const fs=require('fs');
const path=require('path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

const domain=read('server/vehicle-passport.php');
const customer=read('api/customer.php');
const customerAdmin=read('api/customer-admin.php');
const profile=read('profile.html');
const css=read('profile-dashboard.css');
const adminCustomer=read('admin/customer.php');
const tick=read('api/migration-tick.php');

assert.match(domain,/CREATE TABLE IF NOT EXISTS vehicle_maintenance_alerts/,'maintenance alerts must persist');
for(const fn of ['vehicle_maintenance_alert_payload','vehicle_maintenance_refresh','vehicle_maintenance_alerts_for_customer','vehicle_maintenance_acknowledge','vehicle_maintenance_refresh_daily']){
  assert.match(domain,new RegExp('function '+fn+'\\b'),'maintenance domain missing '+fn);
}
assert.match(domain,/\(float\)\$percent<80/,'customer notification threshold must start at 80 percent');
assert.match(domain,/\(float\)\$percent>=95\?'due':'soon'/,'95 percent must escalate maintenance severity');
assert.match(domain,/status='resolved'/,'resolved technical alerts must be persisted');
assert.match(domain,/vehicle_maintenance_last_refresh_date/,'daily refresh must be idempotent');

assert.match(customer,/maintenance_alerts/,'customer payload must expose maintenance alerts');
assert.match(customer,/action==='ack_maintenance_alert'/,'customer API must support acknowledgment');
assert.match(customer,/vehicle_maintenance_acknowledge/,'acknowledgment must be ownership-scoped in domain');
assert.match(customerAdmin,/maintenance_alerts/,'admin customer card must expose maintenance warnings');

assert.match(profile,/id="maintenanceAlerts"/,'profile must contain maintenance alert surface');
assert.match(profile,/function renderMaintenanceAlerts/,'profile must render maintenance alert cards');
assert.match(profile,/ack_maintenance_alert/,'profile must let customer mark an alert as read');
assert.match(profile,/Открыть паспорт/,'alert must route customer to the affected vehicle passport');
assert.match(css,/\.maintenanceAlert\.due/,'due maintenance alerts need a distinct visual state');
assert.match(adminCustomer,/Требует внимания/,'admin customer card must show maintenance warning section');
assert.match(tick,/vehicle_maintenance_refresh_daily/,'protected maintenance tick must refresh wear alerts daily');

const inline=[...profile.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]).filter(Boolean);
for(const source of inline)new Function(source);
const adminInline=[...adminCustomer.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]).filter(Boolean);
for(const source of adminInline)new Function(source);

console.log('Vehicle maintenance alert lifecycle and customer/admin surfaces passed.');