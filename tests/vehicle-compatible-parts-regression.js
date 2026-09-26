const fs=require('fs');
const path=require('path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

const domain=read('server/vehicle-passport.php');
const profile=read('profile.html');
const css=read('profile-dashboard.css');

for(const fn of ['vehicle_compatible_component_keys','vehicle_part_match_tokens','vehicle_passport_find_unique_compatible_product','vehicle_passport_auto_link_compatible_products']){
  assert.match(domain,new RegExp('function '+fn+'\\b'),'compatible parts domain missing '+fn);
}
assert.match(domain,/count\(\$candidates\)!==1/,'auto-link must require exactly one catalog candidate');
assert.match(domain,/compatible_product_id IS NULL/,'auto-link must never overwrite a manual compatibility assignment');
assert.match(domain,/COALESCE\(stock_qty,0\)>0/,'auto-link must only use in-stock products');
assert.match(domain,/COALESCE\(price_rub,0\)>0/,'auto-link must only use purchasable products');
assert.match(domain,/\$hasLetter&&\$hasDigit/,'auto-link must require a strong alphanumeric model token');
assert.match(domain,/manufacturerMatched/,'manufacturer must participate in exact matching');
assert.match(domain,/compatible_product.*available/,'passport payload must expose current compatible-product availability');

assert.match(profile,/c\.compatible_product\?\.available/,'passport purchase action must require live availability');
assert.match(profile,/a\.compatible_product\?\.available/,'maintenance alert purchase action must require live availability');
assert.match(profile,/Совместимая деталь сейчас не в наличии/,'known but unavailable parts must be shown honestly');
assert.match(css,/\.vehiclePassportPartUnavailable/,'unavailable compatible-part state must be styled');

const inline=[...profile.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]).filter(Boolean);
for(const source of inline)new Function(source);
console.log('Strict compatible catalog part linking checks passed.');