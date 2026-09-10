const fs=require('fs');
const js=fs.readFileSync('product-kant.js','utf8');
const html=fs.readFileSync('product.html','utf8');
const fail=m=>{console.error('PRODUCT GALLERY REGRESSION:',m);process.exitCode=1};
if(!js.includes("main.addEventListener('error'"))fail('main product image error fallback is missing');
if(!js.includes("img.addEventListener('error'"))fail('thumbnail error removal is missing');
if(!js.includes("placeholder.textContent='Фото уточняется'"))fail('final gallery placeholder is missing');
if(!/product-kant\.js\?v=3/.test(html))fail('product gallery cache version was not bumped');
if(!process.exitCode)console.log('Product gallery regression checks passed.');
