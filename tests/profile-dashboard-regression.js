const fs=require('fs');
const path=require('path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

const html=read('profile.html');
const css=read('profile-dashboard.css');
const api=read('api/customer.php');

for(const id of ['customerPhone','activeOrder','activeOrderStatus','ordersCount','favoritesCount','reviewsCount','reviewsSummary']){
  assert.match(html,new RegExp('id="'+id+'"'),'profile dashboard must contain #'+id);
}
assert.match(html,/href="#orders"/,'orders quick card must point to orders');
assert.match(html,/href="#favorites-section"/,'favorites quick card must point to favorites');
assert.match(html,/href="#reviews"/,'reviews quick card must point to reviews');
assert.match(html,/Активных заказов сейчас нет/,'dashboard must have active-order empty state');
assert.doesNotMatch(html,/Бонусный баланс|История бонусов|id="bonus"|id="loyalty"/,'bonus program must stay hidden on dashboard');
assert.match(api,/reviews_count/,'customer payload must expose review count');
assert.match(api,/SELECT COUNT\(\*\) FROM product_reviews WHERE customer_id=\?/,'review count must be scoped to current customer');
assert.match(css,/profileQuickGrid/,'dashboard styles must include quick cards');
assert.match(css,/activeOrderGrid/,'dashboard styles must include active order layout');

const ids=[...html.matchAll(/\bid="([^"]+)"/g)].map(m=>m[1]);
const duplicates=ids.filter((id,i)=>ids.indexOf(id)!==i);
assert.deepEqual([...new Set(duplicates)],[],'profile.html must not contain duplicate ids');

const inline=[...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]).filter(Boolean);
for(const source of inline)new Function(source);

console.log('Customer profile dashboard block 1 regression checks passed.');
