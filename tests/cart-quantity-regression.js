const fs=require('fs');
const src=fs.readFileSync('app.js','utf8');
const fail=m=>{console.error('CART QUANTITY REGRESSION:',m);process.exitCode=1};
if(src.includes('cart.findIndex(x=>String(x.id)===id)'))fail('decrement still treats primitive cart ids as objects');
if(!src.includes('cart.findIndex(x=>String(x)===id)'))fail('primitive-id decrement lookup is missing');
if(!/function changeQty\(encoded,delta\)/.test(src))fail('changeQty function is missing');
if(!process.exitCode)console.log('Cart quantity regression checks passed.');
