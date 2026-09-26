const fs=require('fs');
const path=require('path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

const domain=read('server/vehicle-passport.php');
const bootstrap=read('server/bootstrap.php');
const vehicles=read('server/customer-vehicles.php');
const customer=read('api/customer.php');
const profile=read('profile.html');
const css=read('profile-dashboard.css');
const admin=read('admin/vehicle.php');
const adminApi=read('api/vehicle-passport-admin.php');
const customerCard=read('admin/customer.php');

assert.match(bootstrap,/vehicle-passport\.php/,'bootstrap must load vehicle passport domain');
assert.match(bootstrap,/ensure_vehicle_passport_schema\(\$pdo\)/,'bootstrap must migrate vehicle passport schema');
for(const table of ['vehicle_components','vehicle_component_events']) assert.match(domain,new RegExp('CREATE TABLE IF NOT EXISTS '+table),'missing '+table);
for(const fn of ['vehicle_passport_hotspots','vehicle_passport_seed_vehicle','vehicle_passport_learning_cycles','vehicle_passport_learning_weight','vehicle_passport_wear','vehicle_passport_payload','vehicle_passport_save_component','vehicle_passport_record_event']){
  assert.match(domain,new RegExp('function '+fn+'\\b'),'vehicle passport missing '+fn);
}
assert.match(domain,/Комплектация из снимка 1С на момент покупки/,'passport must prefer purchase-time specification snapshot');
assert.match(domain,/brake_pads/,'explicit brake pad specs may be stored');
assert.doesNotMatch(domain,/Shimano B05S|B05S-RX/,'passport domain must not hard-code an inferred brake pad model');
assert.match(domain,/\$samples===1=>0\.10/,'first personal cycle must have low weight');
assert.match(domain,/\$samples===2=>0\.50/,'second personal cycle must materially personalize forecast');
assert.match(domain,/default=>0\.90/,'mature personal history must dominate baseline without fully discarding it');
assert.match(domain,/include_learning/,'events must be optionally excluded from learning');
assert.match(domain,/mode==='measurement'/,'real measurements must have a dedicated wear path');

assert.match(vehicles,/spec_snapshot/,'vehicle ownership must snapshot specifications');
assert.match(vehicles,/vehicle_passport_seed_vehicle/,'vehicle sync must seed passport data');
assert.match(vehicles,/passport.*vehicle_passport_payload/,'customer vehicle rows must expose passports');
assert.match(customer,/vehicles'=>customer_vehicle_rows/,'customer payload must carry passport-enabled vehicles');

assert.match(profile,/id="vehiclePassport"/,'customer profile must contain passport surface');
assert.match(profile,/function openVehiclePassport/,'customer profile must render the interactive passport');
assert.match(profile,/assets\/guide\/bicycle\.png/,'customer passport must reuse the approved service bicycle visual');
assert.match(profile,/Точная модель не подтверждена/,'customer UI must expose uncertainty instead of inventing a component');
assert.match(profile,/data-passport-hotspot/,'customer passport must support interactive hotspots');
assert.match(css,/\.vehiclePassportHotspot/,'passport hotspots must be styled');
assert.match(css,/\.vehiclePassportComponent/,'passport component cards must be styled');

assert.match(admin,/Паспорт техники/,'admin vehicle passport page must exist');
assert.match(admin,/include_learning/,'admin must be able to exclude abnormal cycles from learning');
assert.match(admin,/source_verified/,'admin must confirm evidence sources');
assert.match(adminApi,/action==='save_component'/,'admin API must save component facts');
assert.match(adminApi,/action==='record_event'/,'admin API must record replacements and measurements');
assert.match(customerCard,/vehicle\.php\?id=/,'customer admin card must link to vehicle passport');

const inline=[...profile.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]).filter(Boolean);
for(const source of inline)new Function(source);
const adminInline=[...admin.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]).filter(Boolean);
for(const source of adminInline)new Function(source);

console.log('Vehicle passport, source truth, adaptive wear and interactive customer/admin UI checks passed.');