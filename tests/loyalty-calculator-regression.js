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

for(const id of ['earnEnabled','redeemEnabled','expirationEnabled','reviewBonusEnabled','categoryExclusionsEnabled','earnPercentRange','redeemPercentRange','pointValueRange','expirationDaysRange','minOrderRange','reviewBonusRange','scenarioOrder','scenarioEligibleShare','scenarioBalance','scenarioMargin','saveDraft']){
  assert.match(page,new RegExp('id="'+id+'"'),'calculator missing #'+id);
}
assert.match(page,/Боевой запуск пока заблокирован/,'calculator must explain live activation lock');
assert.match(page,/Экономика на примере заказа/,'calculator must expose scenario economics');
assert.match(js,/function currentConfig/,'calculator must build a draft config');
assert.match(js,/function update/,'calculator must update live economics');
assert.match(js,/conservativeMargin/,'calculator must estimate conservative margin');
assert.match(js,/action:'save_draft'/,'calculator must save drafts');
assert.match(api,/loyalty_save_draft/,'admin API must save validated loyalty drafts');
assert.match(api,/can_edit/,'admin API must expose edit permission');
assert.match(api,/category_options/,'admin API must expose catalog categories');
assert.match(api,/live_activation_available'=>false/,'live activation must remain blocked');
assert.match(engine,/cfg\['enabled'\]=false/,'draft sanitizer must force the master program off');
for(const flag of ['earn_enabled','redeem_enabled','expiration_enabled','review_bonus_enabled','category_exclusions_enabled']) assert.match(engine,new RegExp("'"+flag+"'"),'engine missing '+flag);
assert.match(engine,/function loyalty_redemption_preview/,'engine must support redemption simulation');
assert.match(css,/\.switch/,'calculator toggles must be styled');
assert.match(css,/\.results/,'calculator results must be styled');

const ids=[...page.matchAll(/\bid="([^"]+)"/g)].map(m=>m[1]);
assert.deepEqual([...new Set(ids.filter((id,i)=>ids.indexOf(id)!==i))],[],'loyalty calculator must not contain duplicate ids');
new Function(js);

console.log('Loyalty calculator admin regression checks passed.');
