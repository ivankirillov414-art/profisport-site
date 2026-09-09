const fs=require('fs');
const path=require('path');
const root=path.resolve(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const fail=(msg)=>{console.error('MOBILE LAYOUT REGRESSION:',msg);process.exitCode=1};

const index=read('index.html');
const css=read('storefront.css');
const app=read('app.js');

for(const old of ['mobile-layout-fix.css','mobile-viewport-lock.css','mobile-viewport-lock.js','catalog-fast.js','storefront-kant.js','storefront-kant.css','storefront-mobile.js','theme-clean-v1.css']){
  if(index.includes(old))fail(`index.html references obsolete layer: ${old}`);
}
if(!index.includes('storefront.css?v=2'))fail('index.html must load storefront.css?v=2');
if(!index.includes('app.js?v=20'))fail('index.html must load the unified app.js?v=20');
if((index.match(/<link rel="stylesheet"/g)||[]).length!==1)fail('home page must have exactly one stylesheet');

if(/100vw/i.test(css))fail('storefront.css must not use 100vw');
if(/visualViewport|MutationObserver/.test(css+app))fail('viewport/mutation layout locks are forbidden');
if(/right:\s*-\d|left:\s*-\d/.test(css))fail('negative off-canvas positioning is forbidden');
if(!/grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/.test(css))fail('mobile product grid must use shrink-safe minmax columns');
if(!/\.heroTrack\{display:block;transform:none!important;transition:none\}/.test(css))fail('mobile hero must not keep an off-screen translated strip');

for(const file of ['product.html','checkout.html','profile.html','service.html']){
  const html=read(file);
  if(!html.includes('storefront.css?v=2'))fail(`${file} is not on the unified responsive stylesheet`);
  if(/mobile-layout-fix|mobile-viewport-lock|theme-clean-v1|storefront-kant\.css/.test(html))fail(`${file} still loads an obsolete mobile/theme layer`);
}

if(!process.exitCode)console.log('Mobile layout regression checks passed.');
