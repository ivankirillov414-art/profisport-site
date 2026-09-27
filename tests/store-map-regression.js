const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');
const root = path.join(__dirname, '..');
const attrs = {};
const map = {getAttribute:k=>attrs[k],setAttribute:(k,v)=>attrs[k]=v};
const route = {setAttribute(){}};
const card = {setAttribute(){}};
const choices = ['55.121480,51.788904','55.124228,51.788261'].map((point,i)=>({
 dataset:{map:point,route:'route'+i,card:'card'+i},
 closest:()=>({querySelector:()=>({textContent:'address'+i})}),
 addEventListener:(event,fn)=>choices[i].change=fn
}));
let selected = choices[0];
const section = {
 querySelector:s=>s.includes(':checked')?selected:s==='.routeButton'?route:s==='.mapFallback'?card:map,
 querySelectorAll:()=>choices
};
vm.runInNewContext(fs.readFileSync(path.join(root,'store-location.js'),'utf8'),{document:{getElementById:()=>section}});
assert(attrs.src.includes('55.121480%2C51.788904'));
selected=choices[1]; selected.change();
assert(attrs.src.includes('55.124228%2C51.788261'));
assert.equal(route.href,'route1'); assert.equal(card.href,'card1');
assert.equal(map.title,'ПрофиСпорт — address1');
const html=fs.readFileSync(path.join(root,'index.html'),'utf8');
assert(!/storeMapScheme|mapStorePin|mapSchemeNote|mapEmbed/.test(html));
assert(html.includes('<div class="storeMap"><iframe'));
console.log('Real store map selection and route regression passed.');
