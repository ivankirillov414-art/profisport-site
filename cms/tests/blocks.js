const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const context={window:{},location:{href:'https://example.com/'},URL};vm.createContext(context);vm.runInContext(fs.readFileSync('cms/blocks.js','utf8'),context);const b=context.window.CMBlocks;
const html=b.html({id:'new-1',type:'hero',props:{title:'<script>alert(1)</script>',text:'<img src=x onerror=alert(1)>',image:'javascript:alert(1)',url:'javascript:alert(1)',label:'click',background:'red;display:none'}});
assert(!html.includes('<script>'));assert(!html.includes('<img'));assert(!html.includes('href='));assert(!html.includes('display:none'));assert(html.includes('&lt;script&gt;'));
assert(b.html({id:'ok',type:'image',props:{image:'images/photo.webp'}}).includes('https://example.com/images/photo.webp'));
assert.equal(fs.readFileSync('cms/connector.js','utf8'),fs.readFileSync('cms-client.js','utf8'));
assert.equal(fs.readFileSync('cms/connector.js','utf8'),fs.readFileSync('cms/blocks.js','utf8')+fs.readFileSync('cms/tools/adapter.js','utf8'));
console.log('Shared visual renderer checks passed');
const gallery=b.html({id:'g',type:'gallery',props:{items:[{title:'<svg onload=alert(1)>',image:'javascript:alert(1)',alt:'" onerror="alert(1)'}],visibility:'mobile',mobileSpace:'24'}});
assert(!gallery.includes('<svg'));assert(!gallery.includes('<img'));assert(gallery.includes('&lt;svg'));assert(gallery.includes('cms-only-mobile'));assert(gallery.includes('--cms-mobile-space:24px'));
for(const type of Object.keys(b.catalog)){const result=b.html({id:type,type,props:b.defaults(type)});assert(result.startsWith('<section'));assert(!result.includes('undefined'));}
assert(b.html({id:'faq',type:'faq',props:{items:[{title:'Question',text:'Answer'}]}}).includes('<details><summary>Question</summary>'));
console.log('ID compound block rendering and responsive settings passed');
assert.equal(fs.readFileSync('cms/block-styles.css','utf8').trim(),b.css,'Canvas must load the exact shared CSS without editor parsing');
