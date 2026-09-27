const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const index=read('index.html'),theme=read('sport-energy.css'),motion=read('motion.css');

assert(index.includes('Профи<span>Спорт</span>'),'Russian ProfiSport wordmark must remain in the header');
assert(!index.includes('>ProfiSport<'),'Do not replace the visible Russian wordmark with Latin text');
assert(index.includes('Больше спорта<br><em>в твоей жизни</em>'),'Sport Energy hero message is missing');
assert(index.includes('class="energyProof stagger"'),'Hero proof strip is missing');
assert(index.includes('class="energyRibbon"'),'Energy ribbon is missing');
assert(index.includes('sport-energy.css?v=8'),'Homepage must load the Sport Energy theme');

for(const page of ['product.html','checkout.html','service.html','shop.html','workshop.html','buyer-info.html']){
  assert(read(page).includes('sport-energy.css?v=8'),`${page} must load the public Sport Energy theme`);
}
assert(motion.includes('.profileDashboardHero'),'Profile must receive its lightweight Sport Energy account styling through the shared motion layer');
for(const asset of ['assets/hubs/picker-reference-v1.webp','assets/hero/winter.jpg','assets/hero/winter-storage-v10.webp','assets/hero/classic-workshop-wide-v5.webp']){
  assert(fs.statSync(path.join(root,asset)).size>10000,`${asset} must exist`);
}
for(const token of ['--energy: #ffc928','.energyProof','#categoryTiles.categoryTiles','.storefrontHome>#picker','.storeLocation','prefers-reduced-motion: reduce']){
  assert(theme.includes(token),`Theme contract missing: ${token}`);
}
assert(!/https?:\/\//.test(theme),'Theme must not add remote visual assets');
console.log('Sport Energy theme, Russian wordmark, local assets and responsive sections passed.');
