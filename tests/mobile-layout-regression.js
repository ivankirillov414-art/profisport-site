const fs=require('fs');
const path=require('path');
const root=path.resolve(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const fail=(msg)=>{console.error('MOBILE LAYOUT REGRESSION:',msg);process.exitCode=1};

const index=read('index.html');
const css=read('storefront-v2.css');
const app=read('app.js');
const cssCode=css.replace(/\/\*[\s\S]*?\*\//g,'');

for(const old of ['storefront.css','mobile-layout-fix.css','mobile-viewport-lock.css','mobile-viewport-lock.js','catalog-fast.js','storefront-kant.js','storefront-kant.css','storefront-mobile.js','theme-clean-v1.css']){
  if(index.includes(old))fail(`index.html references obsolete layer: ${old}`);
}
if(!index.includes('storefront-v2.css?v=1'))fail('index.html must load storefront-v2.css?v=1');
if(!index.includes('app.js?v=20'))fail('index.html must load the unified app.js?v=20');
if((index.match(/<link rel="stylesheet"/g)||[]).length!==1)fail('home page must have exactly one stylesheet');

if(/100vw/i.test(cssCode))fail('storefront-v2.css must not use 100vw in CSS rules');
if(/visualViewport|MutationObserver/.test(cssCode+app))fail('viewport/mutation layout locks are forbidden');
if(/(?:right|left):\s*-(?:[4-9]\d|[1-9]\d{2,})px\b/i.test(cssCode))fail('large negative off-canvas positioning is forbidden');
if(!/grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/.test(cssCode))fail('mobile product grid must use shrink-safe minmax columns');
if(!/\.heroTrack\{display:block;[^}]*transform:none!important;[^}]*transition:none\}/.test(cssCode))fail('mobile hero must not keep an off-screen translated strip');
if(!/select\{[^}]*-webkit-appearance:none;[^}]*inline-size:100%;[^}]*min-inline-size:0/.test(cssCode))fail('native selects must not contribute their option intrinsic width');
if(!/#catalogProducts\{[^}]*contain:inline-size/.test(cssCode))fail('catalog must contain intrinsic inline sizing');
if(!/\.filters\{[^}]*contain:inline-size/.test(cssCode))fail('filter row must contain intrinsic inline sizing');

for(const file of ['product.html','checkout.html','profile.html','service.html']){
  const html=read(file);
  if(!html.includes('storefront-v2.css?v=1'))fail(`${file} is not on storefront-v2.css?v=1`);
  if(/mobile-layout-fix|mobile-viewport-lock|theme-clean-v1|storefront-kant\.css\?v=1|storefront\.css/.test(html))fail(`${file} still loads an obsolete public layout layer`);
}

if(!process.exitCode)console.log('Mobile layout regression checks passed.');
