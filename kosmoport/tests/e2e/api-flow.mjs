import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import {pathToFileURL} from 'node:url';
import {Session,Blocked} from './harness.mjs';
import {root,phpRun} from './runtime.mjs';
const fixtureAction=(context,action)=>JSON.parse(phpRun(path.join(root,'tests/e2e/fixture.php'),[context.fixture_file,action]));
export async function verifyApi(context,report) {
 const setup=new Session(context.base_url);let hiddenName='csrf';
 const installed=await report.check('HTTP setup: one-time key, CSRF and exactly one owner',async()=>{
  const page=await setup.page('setup.php');assert.equal(page.status,200);
  const fields=[...page.text.matchAll(/<input\b[^>]*>/gi)].map(([tag])=>({name:tag.match(/name=["']([^"']+)/i)?.[1],value:tag.match(/value=["']([^"']*)/i)?.[1]}));
  const token=fields.find(x=>/csrf/i.test(x.name||'')&&/^[a-f0-9]{64}$/i.test(x.value||''));assert(token,'setup did not issue a CSRF form token');hiddenName=token.name;setup.csrf=token.value;
  const values={name:context.owner.name,email:context.owner.email,password:context.owner.password,setup_key:context.setup_key,[hiddenName]:token.value};
  assert.equal((await setup.page('setup.php',{method:'POST',csrf:'',form:{...values,[hiddenName]:''}})).status,403,'setup accepted missing CSRF');
  const arrayValues={...values};delete arrayValues[hiddenName];arrayValues[hiddenName+'[]']=token.value;
  const malformed=await setup.page('setup.php',{method:'POST',csrf:'',form:arrayValues});assert.equal(malformed.status,403,'array-valued setup CSRF must be controlled 403');assert(!/TypeError|Stack trace/.test(malformed.text));
  assert.equal((await setup.page('setup.php',{method:'POST',form:{...values,setup_key:'invalid-'.repeat(7)}})).status,403,'setup accepted wrong key');
  const created=await setup.page('setup.php',{method:'POST',form:values});assert([200,201,302,303].includes(created.status),'valid setup HTTP '+created.status);
  assert.equal(fixtureAction(context,'query').users,1,'setup failed to create exactly one owner');return {database:context.database.name,wrong_key_denied:true,missing_csrf_denied:true,owners:1};
 });
 if(!installed){await report.check('dependent API acceptance scenarios',()=>{throw new Blocked('Owner setup did not complete; dependent writes not attempted');});return;}
 const owner=new Session(context.base_url);context.session=owner;
 const login=await report.check('real login: session and CSRF rotate; health has no credentials',async()=>{
  const health=await owner.ok('health');assert.equal(health.ready,true);assert(!/password|storage_path|setup_key|app_key/i.test(JSON.stringify(health)));
  const anonymous=await owner.ok('auth/me');assert.equal(anonymous.user,null);const before=[...owner.cookies.values()].join();const oldCsrf=owner.csrf;
  const denied=await owner.request('auth/login',{method:'POST',body:{email:context.owner.email,password:'wrong-fixture-password'},expected:401});assert.equal(denied.json?.ok,false);
  const response=await owner.request('auth/login',{method:'POST',body:{email:context.owner.email,password:context.owner.password},expected:200});assert.equal(response.data.user.role,'owner');assert.notEqual([...owner.cookies.values()].join(),before);assert.notEqual(owner.csrf,oldCsrf);
  const cookie=response.headers.getSetCookie().join(';');assert.match(cookie,/httponly/i);assert.match(cookie,/samesite=lax/i);assert.match(cookie,/path=\/kosmoport\//i);
  context.owner_id=response.data.user.id;return {owner_role:true,cookie_http_only:true,path:'/kosmoport/',rotation:true};
 });
 if(!login)return;
 await report.check('setup cannot be repeated after owner creation',async()=>{const response=await owner.page('setup.php',{method:'POST',form:{setup_key:context.setup_key,name:context.owner.name,email:context.owner.email,password:context.owner.password,[hiddenName]:owner.csrf}});assert.equal(response.status,409);assert.equal(fixtureAction(context,'query').users,1);});
 await report.check('anonymous protected routes deny access',async()=>{const anonymous=new Session(context.base_url);for(const route of ['partners','tasks','users','audit','documents','tickets/sources']){const r=await anonymous.request(route,{expected:401});assert.equal(r.json?.ok,false);}return {routes:6};});
 await report.check('mutations reject absent/forged CSRF and foreign Origin',async()=>{
  for(const csrf of ['', '0'.repeat(64)])await owner.request('auth/logout',{method:'POST',body:{},csrf,expected:403});
  await owner.request('auth/logout',{method:'POST',body:{},extraHeaders:{Origin:'https://qa-attacker.invalid'},expected:403});assert.equal((await owner.ok('auth/me')).user.role,'owner');
 });
 await report.check('owner creates real role accounts; API never exposes password hashes',async()=>{
  context.users={};for(const role of ['manager','cashier','editor','document_manager','reader']){
   const email=role+'.'+context.id+'@qa.invalid';const created=await owner.ok('users',{method:'POST',body:{name:'QA '+role,email,password:context.owner.password,role,active:true}});const user=created.user||created.item||created;assert(user.id,role+' user id missing');context.users[role]={...user,email};
  }
  const users=await owner.ok('users');assert(Array.isArray(users.items));assert(!/password_hash|session_version|setup_key|app_key/.test(JSON.stringify(users)));return {roles:Object.keys(context.users)};
 });
 await report.check('partner and assigned task persist; validation is non-destructive',async()=>{
  const name='QA Точка & <тест> '+context.id;
  await owner.request('partners',{method:'POST',body:{name:''},expected:422});
  await owner.ok('partners',{method:'POST',body:{name,contact_name:"QA O'Reilly",phone:'+70000000000',email:'partner@qa.invalid',address:'QA Оренбург',status:'new',notes:'</textarea><script>window.qaXss=1</script>'}});
  const list=await owner.ok('partners',{query:{q:context.id}});const partner=list.items.find(x=>x.name===name);assert(partner?.id);context.partner=partner;
  const title='QA Проверить договор '+context.id;const user=context.users?.manager;if(!user)throw new Blocked('Manager fixture account unavailable');
  await owner.ok('tasks',{method:'POST',body:{title,status:'todo',assignee_id:user.id,partner_id:partner.id,due_date:'2026-11-02'}});
  const tasks=await owner.ok('tasks',{query:{q:context.id}});const task=tasks.items.find(x=>x.title===title);assert.equal(task?.assignee_id,user.id);assert.equal(task?.due_date,'2026-11-02');context.task=task;
  return {partner_id:partner.id,task_id:task.id,stored_and_read_back:true};
 });
 await report.check('cashier cannot access audit, documents or website editing',async()=>{
  if(!context.users?.cashier)throw new Blocked('Cashier fixture account unavailable');
  const cashier=new Session(context.base_url);await cashier.login(context.users.cashier.email,context.owner.password);context.cashier=cashier;
  for(const route of ['audit','documents','website/draft'])assert.equal((await cashier.request(route,{expected:403})).json.error.code,'FORBIDDEN');
  await cashier.request('website/save',{method:'POST',body:{content:{},expected_revision:0},expected:403});
 });
 await report.check('real role matrix: manager, reader, editor and document manager',async()=>{
  context.role_sessions={};
  for(const role of ['manager','reader','editor','document_manager']){
   if(!context.users?.[role])throw new Blocked('Role fixture unavailable: '+role);
   const session=new Session(context.base_url);const login=await session.login(context.users[role].email,context.owner.password);assert.equal(login.user.role,role);
   context.role_sessions[role]=session;
   await session.ok('partners');await session.ok('tasks');
   if(role==='reader')for(const route of ['partners','tasks'])assert.equal((await session.request(route,{method:'POST',body:{name:'QA denied',title:'QA denied'},expected:403})).json.error.code,'FORBIDDEN');
   if(role==='editor')await session.ok('website/draft');
   if(role==='document_manager')await session.ok('documents');
   assert.equal((await session.request('audit',{expected:403})).json.error.code,'FORBIDDEN');
  }return {roles:['manager','reader','editor','document_manager'],authenticated:true,server_denials:true};
 });
 for(const [file,name]of [['staff-flow.mjs','staff'],['tickets-flow.mjs','tickets'],['documents-flow.mjs','documents'],['website-flow.mjs','website']]){
  const module=path.join(root,'tests/e2e',file);
  if(fs.existsSync(module)){const loaded=await import(pathToFileURL(module).href);await loaded.verify(context,report);}
  else await report.check(name+' integrated cases',()=>{throw new Blocked('QA '+file+' not implemented yet');});
 }
 await report.check('logout invalidates prior authenticated session',async()=>{
  const stale=new Session(context.base_url);stale.cookies=new Map(owner.cookies);stale.csrf=owner.csrf;
  await owner.ok('auth/logout',{method:'POST',body:{}});assert.equal((await owner.ok('auth/me')).user,null);await stale.request('partners',{expected:401});
 });
}
