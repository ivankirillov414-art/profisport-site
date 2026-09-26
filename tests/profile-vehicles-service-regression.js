const fs=require('fs');
const path=require('path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

const bootstrap=read('server/bootstrap.php');
const domain=read('server/customer-vehicles.php');
const customer=read('api/customer.php');
const orders=read('api/orders.php');
const service=read('api/service.php');
const adminService=read('admin/service.js');
const profile=read('profile.html');
const css=read('profile-dashboard.css');

assert.match(bootstrap,/customer-vehicles\.php/,'bootstrap must load vehicle domain');
assert.match(bootstrap,/ensure_customer_vehicle_schema\(\$pdo\)/,'bootstrap must migrate vehicle/service schema');
assert.match(domain,/CREATE TABLE IF NOT EXISTS customer_vehicles/,'vehicle ownership table is required');
assert.match(domain,/service_request_status_history/,'service status history table is required');
assert.match(domain,/function customer_vehicle_sync_order/,'completed orders must sync vehicles');
assert.match(domain,/function customer_vehicle_sync_customer/,'old completed orders must backfill vehicles');
assert.match(domain,/function customer_vehicle_rows/,'customer vehicle payload helper is required');
assert.match(domain,/function customer_service_rows/,'customer service payload helper is required');
assert.match(domain,/самокат/u,'vehicle classifier must recognize scooters');
assert.match(domain,/велосипед/u,'vehicle classifier must recognize bicycles');

assert.match(customer,/vehicles'=>customer_vehicle_rows/,'customer payload must expose vehicles');
assert.match(customer,/service_requests'=>customer_service_rows/,'customer payload must expose service history');
assert.match(customer,/action==='service_submit'/,'customer must be able to submit linked service requests');
assert.match(customer,/customer_csrf_check\(\)/,'customer service submit must require CSRF');
assert.match(customer,/customer_vehicles WHERE id=\? AND customer_id=\? AND is_active=1/,'customer may only service owned active vehicles');
assert.match(orders,/customer_vehicle_sync_order\(\$pdo,\$id\)/,'order status changes must sync vehicle ownership');

for(const status of ['accepted','diagnostics','repair','ready']) assert.match(service,new RegExp("'"+status+"'"),'service API missing '+status+' status');
assert.match(service,/record_service_request_status/,'admin service status changes must be journaled');
assert.match(service,/vehicle_title/,'admin service API must expose linked vehicle');
assert.match(adminService,/Диагностика/,'admin service UI must show repair lifecycle');
assert.match(adminService,/vehicle_title/,'admin service UI must show linked customer vehicle');

for(const id of ['vehicles-service','vehiclesCount','customerVehicles','serviceComposer','customerServiceRequests','serviceSummary']){
  assert.match(profile,new RegExp('id="'+id+'"'),'profile missing #'+id);
}
for(const fn of ['renderVehiclesService','vehicleCardMarkup','serviceRequestMarkup','openVehicleService','submitVehicleService']){
  assert.match(profile,new RegExp('function '+fn+'\\b'),'profile must implement '+fn);
}
assert.match(profile,/action='service_submit'|api\('service_submit'/,'profile must submit service requests through customer API');
for(const cls of ['vehicleCard','vehicleServiceComposer','customerServiceCard','serviceTimeline','serviceStatusChip']){
  assert.match(css,new RegExp('\\.'+cls+'(?:\\{|[,.:])'),'vehicle/service CSS missing .'+cls);
}

const ids=[...profile.matchAll(/\bid="([^"]+)"/g)].map(m=>m[1]);
assert.deepEqual([...new Set(ids.filter((id,i)=>ids.indexOf(id)!==i))],[],'profile.html must not contain duplicate ids');
const inline=[...profile.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]).filter(Boolean);
for(const source of inline)new Function(source);
new Function(adminService);

console.log('Customer My Vehicles and linked service regression checks passed.');
