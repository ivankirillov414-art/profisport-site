const fs=require('fs');
const path=require('path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

const registry=read('server/vehicle-spec-registry.php');
const passport=read('server/vehicle-passport.php');
const bootstrap=read('server/bootstrap.php');
const api=read('api/vehicle-spec-admin.php');
const page=read('admin/vehicle-specs.php');
const adminIndex=read('admin/index.php');

assert.match(bootstrap,/vehicle-spec-registry\.php/,'bootstrap must load verified spec registry');
assert.match(bootstrap,/ensure_vehicle_spec_registry_schema\(\$pdo\)/,'registry research schema must migrate');
for(const fn of ['vehicle_spec_registry_profiles','vehicle_spec_registry_match','vehicle_spec_registry_scan_catalog','vehicle_spec_registry_apply_vehicle','vehicle_spec_registry_apply_all','vehicle_spec_registry_queue']){
  assert.match(registry,new RegExp('function '+fn+'\\b'),'registry missing '+fn);
}
for(const key of ['aspect-nickel-pro-2025-','aspect-nickel-elite-2025-','aspect-cobalt-pro-2025-','aspect-aura-2025-27.5','aspect-oasis-2026-27.5','aspect-oasis-pro-2026-27.5','hagen-3.9-2025-','hagen-3.11-2025-','welt-rocket-3.0-hd-2026-','stark-router-','stark-viva-','stark-router-','stark-viva-']){
  assert.ok(registry.includes(key),'verified profile family missing '+key);
}
assert.match(registry,/HAGEN_39_2025/,'registry must include the official Hagen 3.9 source');
assert.match(registry,/HAGEN_311_2025/,'registry must include the official Hagen 3.11 source');
assert.match(registry,/WELT_ROCKET_30_HD_2026/,'registry must include the official Welt Rocket 3.0 HD source');
assert.match(registry,/STARK_ROUTER_293_2025/,'registry must include official STARK Router 29.3 HD 2025 source');
assert.match(registry,/STARK_VIVA_275_HD_2025/,'registry must include official STARK Viva 27.5 HD 2025 source');
assert.match(registry,/wheel_in_model/,'models that encode wheel size in the official model name must be supported explicitly');
assert.match(registry,/STARK_ROUTER_294_2024/,'registry must include official STARK Router 29.4 2024 source');
assert.match(registry,/STARK_ROUTER_293_2025/,'registry must include official STARK Router 29.3 2025 source');
assert.match(registry,/STARK_VIVA_272_HD_2025/,'registry must include official STARK Viva 27.2 HD 2025 source');
assert.match(registry,/STARK_VIVA_275_HD_2025/,'registry must include official STARK Viva 27.5 HD 2025 source');
assert.match(registry,/wheel_in_model/,'matcher must support manufacturer model names that encode wheel family');
assert.match(registry,/B05S-RX Resin/,'MT200 profile must include Shimano-confirmed B05S-RX pads');
assert.match(registry,/bike\.shimano\.com/,'pad model must carry Shimano source');
assert.doesNotMatch(registry,/baseline_life_value.*B05S|B05S[\s\S]{0,200}baseline_life_value/,'registry must not invent a pad lifetime');
assert.doesNotMatch(registry,/M275[\s\S]{0,500}brake_pads/,'M275 pads must not be inferred without a verified compatibility source');
assert.doesNotMatch(registry,/M275[\s\S]{0,180}(?:B05S|E10\.11|P20\.11)|(?:B05S|E10\.11|P20\.11)[\s\S]{0,180}M275/,'registry must not infer a Tektro M275 pad model');
assert.match(passport,/count\(\$rows\)!==1/,'replacement purchase must not guess among multiple matching bicycles');
assert.match(registry,/in_array\(\$sourceType,\['manual','service'\],true\)/,'manual and service facts must be protected from registry refresh');
assert.match(registry,/source_profile_key/,'official registry provenance must be persisted');
assert.match(registry,/vehicle_spec_research_queue/,'unmatched catalog bicycles must enter a research queue');

assert.match(passport,/vehicle_spec_registry_apply_vehicle/,'passport reads must enrich from the verified registry');
assert.match(passport,/verified_profile/,'passport payload must expose verified profile metadata');
assert.match(api,/action==='apply_registry'/,'admin API must apply verified registry in bulk');
assert.match(api,/action==='rescan'/,'admin API must rescan current 1C catalog');
assert.match(page,/Покрытие паспортов техники/,'coverage dashboard must exist');
assert.match(page,/Требует исследования/,'coverage dashboard must surface unverified models');
assert.match(page,/Применить подтверждённый реестр/,'coverage dashboard must expose explicit apply action');
assert.match(adminIndex,/vehicle-specs\.php/,'main admin must link to passport coverage');
assert.match(adminIndex,/Центр лояльности/,'main admin should use the single loyalty center name');

const inline=[...page.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]).filter(Boolean);
for(const source of inline)new Function(source);

console.log('Verified bicycle specification registry and coverage dashboard checks passed.');