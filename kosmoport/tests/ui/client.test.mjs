import assert from 'node:assert/strict';
import {createApi,ApiError} from '../../public/assets/crm-api.js';
import {escapeHtml,date,number,filename,objectJson,canAdmit,sourceUsed} from '../../public/assets/crm-utils.js';

let passed=0;
async function test(name,fn){await fn();passed++;console.log('PASS '+name);}
const json=(data,status=200)=>new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json'}});
const ok=data=>json({ok:true,data});
let requests=[];
let respond=()=>ok({user:null,csrf:'initial'});
const api=createApi('https://example.test/kosmoport/api/index.php',async(url,init)=>{requests.push({url,init});return respond(url,init);});
await test('relative hosting prefix and encoded query',()=>{
 const url=new URL(api.url('documents/download',{id:'a&x=other',version:2}));
 assert.equal(url.pathname,'/kosmoport/api/index.php');assert.equal(url.searchParams.get('r'),'documents/download');assert.equal(url.searchParams.get('id'),'a&x=other');
});
await test('mutations fail closed before CSRF bootstrap',async()=>{
 await assert.rejects(api.post('tasks',{title:'x'}),error=>error.status===419);assert.equal(requests.length,0);
});
await test('login bootstrap uses same-origin credentials and no cache',async()=>{
 await api.get('auth/me');assert.equal(requests.at(-1).init.credentials,'same-origin');assert.equal(requests.at(-1).init.cache,'no-store');
});
await test('JSON mutations send CSRF and preserve structured content',async()=>{
 respond=()=>ok({id:'1',csrf:'rotated'});await api.post('tasks',{title:'Тест\nстрока'});
 const {init}=requests.at(-1);assert.equal(init.headers['X-CSRF-Token'],'initial');assert.deepEqual(JSON.parse(init.body),{title:'Тест\nстрока'});
 await api.post('tasks',{});assert.equal(requests.at(-1).init.headers['X-CSRF-Token'],'rotated');
});
await test('multipart uploads leave boundary to browser',async()=>{
 const form=new FormData();form.set('title','Акт');form.set('file',new Blob(['test']),'test.txt');await api.post('documents/upload',form);
 assert.equal(requests.at(-1).init.body,form);assert.equal(requests.at(-1).init.headers['Content-Type'],undefined);
});
for(const status of [401,403,409,413,422,503])await test('HTTP '+status+' preserved for UI handling',async()=>{
 respond=()=>json({ok:false,error:{code:'TEST',message:'Понятная ошибка'}},status);
 await assert.rejects(api.get('documents'),error=>error instanceof ApiError && error.status===status && error.code==='TEST' && error.message==='Понятная ошибка');
});
await test('server HTML cannot be mistaken for data',async()=>{
 respond=()=>new Response('<html>Provider browser challenge</html>',{status:200});
 await assert.rejects(api.get('auth/me'),error=>error.code==='INVALID_RESPONSE');
});
await test('lost network reports uncertain action without false success',async()=>{
 respond=()=>{throw new TypeError('offline');};await assert.rejects(api.post('tickets/admit',{}),error=>error.status===0 && /Нет связи/.test(error.message));
});
await test('protected download validates errors before creating blob',async()=>{
 respond=()=>json({ok:false,error:{message:'Нет доступа'}},403);await assert.rejects(api.download('documents/download',{id:'x'}),error=>error.status===403);
 respond=()=>new Response('file',{headers:{'Content-Type':'application/pdf','Content-Disposition':'attachment; filename="act.pdf"'}});
 const result=await api.download('documents/download',{id:'x'});assert.equal(await result.blob.text(),'file');assert.equal(filename(result.disposition),'act.pdf');
});
await test('JSON document is downloaded as a file when attachment is set',async()=>{
 respond=()=>new Response('{"document":"test"}',{headers:{'Content-Type':'application/json','Content-Disposition':'attachment; filename="act.json"'}});
 const result=await api.download('documents/download',{id:'x'});assert.equal(await result.blob.text(),'{"document":"test"}');
});
await test('provider HTML never becomes a successful document download',async()=>{
 respond=()=>new Response('<html>Challenge</html>',{headers:{'Content-Type':'text/html'}});
 await assert.rejects(api.download('documents/download',{id:'x'}),error=>error.code==='INVALID_RESPONSE');
});
await test('logout clears CSRF for future mutations',async()=>{
 api.clearSession();await assert.rejects(api.post('tasks',{}),error=>error.status===419);
});
await test('untrusted record text is escaped',()=>assert.equal(escapeHtml('<img src=x onerror="bad"> &'), '&lt;img src=x onerror=&quot;bad&quot;&gt; &amp;'));
await test('UTC dates rendered in exhibition timezone',()=>{assert.match(date('2026-10-30T21:30:00Z',true),/31\.10\.2026/);assert.equal(date('2026-10-31'),'31.10.2026');assert.equal(date('bad'),'—');});
await test('unavailable metrics are not invented zeroes',()=>{assert.equal(number(undefined),'—');assert.equal(number(null),'—');assert.equal(number(0),'0');});
await test('JSON editor rejects arrays and null',()=>{assert.throws(()=>objectJson('[]'));assert.throws(()=>objectJson('null'));assert.deepEqual(objectJson('{"title":"Космопорт"}'),{title:'Космопорт'});});
await test('admission requires positive state and respects explicit denial',()=>{
 assert.equal(canAdmit({can_admit:true,ticket:{status:'available'}}),true);assert.equal(canAdmit({can_admit:false,valid:true}),false);
 for(const status of ['used','admitted','blocked','revoked','expired','cancelled'])assert.equal(canAdmit({can_admit:true,ticket:{status}}),false);
 assert.equal(canAdmit({}),false);assert.equal(canAdmit(null),false);
});
await test('UTF-8 filename parsed and traversal neutralized',()=>assert.equal(filename("attachment; filename*=UTF-8''%D0%90%D0%BA%D1%82%2F1.pdf"),'Акт_1.pdf'));
await test('actual ticket statistics used field is preserved',()=>{assert.equal(sourceUsed({used:7}),7);assert.equal(sourceUsed({used:0}),0);assert.equal(sourceUsed({}),undefined);assert.equal(canAdmit({status:'valid',consumed:false}),true);});
await test('import conflict list survives the transport envelope',async()=>{
 respond=()=>json({ok:false,error:{code:'import_conflict',message:'Расхождения',conflicts:[{collection:'tickets',id:'test'}]}},409);
 const second=createApi('https://example.test/kosmoport/api/index.php',async(url,init)=>new URL(url).searchParams.get('r')==='auth/me'?ok({csrf:'test'}):respond());
 await second.get('auth/me');await assert.rejects(second.post('tickets/import',{}),error=>error.status===409 && error.details?.[0]?.id==='test');
});
console.log(`${passed} UI client checks passed. Mock transport only; no production operations.`);
