const fs=require('fs');
const path=require('path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

const html=read('profile.html');
const css=read('profile-dashboard.css');
const api=read('api/customer.php');

assert.match(api,/function customer_favorite_details/,'API must expose full favorite details');
assert.match(api,/favorite_details/,'account payload must include favorite details');
assert.match(api,/LEFT JOIN products p ON p.id=cf.product_id/,'favorite details must retain unavailable products');
assert.match(api,/SELECT COUNT\(\*\) FROM customer_favorites WHERE customer_id=\?/,'favorite mutation must return canonical count');
assert.match(api,/SELECT id FROM products WHERE id IN \(\$marks\)/,'guest favorites merge must preserve existing products even when unavailable');

for(const fn of ['favoriteCardMarkup','favoriteEmptyMarkup','removeFavorite','addFavoriteToCart','bindFavoriteCards']){
  assert.match(html,new RegExp('function '+fn+'\\b'),'profile must implement '+fn);
}
assert.match(html,/id="favoritesSummary"/,'favorites section must expose live availability summary');
assert.match(html,/data-favorite-remove/,'favorites must support removal');
assert.match(html,/data-favorite-cart/,'favorites must support add to cart');
assert.match(html,/Нет в наличии/,'favorites must visibly retain unavailable items');
assert.match(html,/favorite_details/,'profile must render server favorite details');
assert.doesNotMatch(html,/favoriteIds\.map\(id=>byId/,'profile must not silently drop unavailable favorites through catalog-only lookup');

for(const cls of ['favoriteCard','favoriteImage','favoriteStock','favoritePriceRow','favoriteActions','favoriteEmpty']){
  assert.match(css,new RegExp('\\.'+cls+'(?:\\{|[,.:])'),'favorites CSS must contain .'+cls);
}

const ids=[...html.matchAll(/\bid="([^"]+)"/g)].map(m=>m[1]);
assert.deepEqual([...new Set(ids.filter((id,i)=>ids.indexOf(id)!==i))],[],'profile.html must not contain duplicate ids');
const inline=[...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]).filter(Boolean);
for(const source of inline)new Function(source);

console.log('Customer favorites block 3 regression checks passed.');
