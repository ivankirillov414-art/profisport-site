// Actual core HTTP checks of the same payload builders used by the UI.
// Disposable SQLite fixture only; no hosting, real credentials or business data.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import net from 'node:net';
import crypto from 'node:crypto';
import {spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {once} from 'node:events';
import {createApi} from '../../public/assets/crm-api.js';
import {recordPayload,staffPayload} from '../../public/assets/crm-forms.js';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const php=process.argv[2];if(!php || !fs.existsSync(php))throw new Error('Pass the existing PHP runtime path as the first argument.');
const dir=fs.mkdtempSync(path.join(os.tmpdir(),'kosmoport-ui-http-'));
const setupKey=crypto.randomBytes(30).toString('base64url'),password=crypto.randomBytes(22).toString('base64url');
let child,passed=0;
async function test(name,callback){await callback();passed++;console.log('PASS '+name);}
function session(endpoint){
  const cookies=new Map();
  return createApi(endpoint,async(url,init={})=>{
    assert.equal(new URL(url).hostname,'127.0.0.1');assert.equal(new URL(url).protocol,'http:');
    const response=await fetch(url,{...init,headers:{...init.headers,...(cookies.size?{Cookie:[...cookies].map(([k,v])=>k+'='+v).join('; ')}:{})}});
    for(const cookie of response.headers.getSetCookie()){
      const pair=cookie.split(';',1)[0],index=pair.indexOf('=');cookies.set(pair.slice(0,index),pair.slice(index+1));
    }
    return response;
  });
}
try{
  fs.mkdirSync(path.join(dir,'storage'));
  const config={environment:'test',base_path:'/kosmoport',timezone:'Asia/Yekaterinburg',storage_path:path.join(dir,'storage'),app_key:crypto.randomBytes(32).toString('hex'),setup_key_hash:crypto.createHash('sha256').update(setupKey).digest('hex'),database:{driver:'sqlite_test',path:path.join(dir,'fixture.sqlite'),allow_test_driver:true}};
  const configPath=path.join(dir,'config.php');fs.writeFileSync(configPath,"<?php return json_decode(base64_decode('"+Buffer.from(JSON.stringify(config)).toString('base64')+"'),true);");
  const probe=net.createServer();probe.listen(0,'127.0.0.1');await once(probe,'listening');const port=probe.address().port;await new Promise(resolve=>probe.close(resolve));
  const log=fs.openSync(path.join(dir,'php.log'),'a');
  child=spawn(php,['-n','-d','extension_dir='+path.join(path.dirname(php),'ext'),'-d','extension=pdo_sqlite','-d','extension=pdo_mysql','-d','display_errors=0','-S','127.0.0.1:'+port,'tests/e2e/dev-router.php'],{cwd:root,env:{...process.env,KCRM_CONFIG:configPath},stdio:['ignore',log,log],windowsHide:true});
  fs.closeSync(log);
  const base=`http://127.0.0.1:${port}/kosmoport/`,endpoint=new URL('api/index.php',base);
  let ready=false;
  for(let i=0;i<80;i++){
    try{const response=await fetch(endpoint+'?r=auth/me',{signal:AbortSignal.timeout(1000)});if(response.status===200){ready=true;break;}}catch{}
    await new Promise(resolve=>setTimeout(resolve,100));
  }
  assert(ready,'Local PHP test server must start');
  const owner=session(endpoint);
  // The real one-time setup needs its own anonymous cookie/CSRF session.
  const cookies=new Map();
  async function setupFetch(url,init={}){
    const response=await fetch(url,{...init,headers:{Accept:'application/json',...init.headers,...(cookies.size?{Cookie:[...cookies].map(([k,v])=>k+'='+v).join('; ')}:{})}});
    for(const c of response.headers.getSetCookie()){const pair=c.split(';',1)[0],index=pair.indexOf('=');cookies.set(pair.slice(0,index),pair.slice(index+1));}return response;
  }
  const bootstrap=await (await setupFetch(endpoint+'?r=auth/me')).json();
  const installed=await setupFetch(new URL('setup.php',base),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':bootstrap.data.csrf},body:JSON.stringify({setup_key:setupKey,name:'UI Тестовый владелец',email:'ui-owner@example.invalid',password})});
  assert.equal(installed.status,201,'Disposable initial setup');
  await owner.get('auth/me');const me=await owner.post('auth/login',{email:'ui-owner@example.invalid',password});assert.equal(me.user.role,'owner');
  let partner,task,staff;
  await test('UI partner builder creates and edits with a numeric expected_revision',async()=>{
    partner=await owner.post('partners',recordPayload({name:'UI Тестовая точка',contact_name:'До изменения'}));assert.equal(partner.revision,1);
    partner=await owner.post('partners',recordPayload({contact_name:'После изменения'},partner));assert.equal(partner.revision,2);assert.equal(partner.contact_name,'После изменения');
  });
  await test('stale partner payload returns 409 and cannot overwrite current data',async()=>{
    const values={contact_name:'Несохранённый ввод'};
    await assert.rejects(owner.post('partners',recordPayload(values,{...partner,revision:1})),e=>e.status===409 && e.code==='REVISION_CONFLICT');
    assert.equal(values.contact_name,'Несохранённый ввод');const found=(await owner.get('partners')).items.find(row=>row.id===partner.id);assert.equal(found.contact_name,'После изменения');
  });
  await test('UI task builder edits actual core task and preserves unrelated fields',async()=>{
    task=await owner.post('tasks',recordPayload({title:'UI Тестовая задача',description:'Сохранить это описание',status:'todo',assignee_id:null,due_date:'2026-11-24'}));
    task=await owner.post('tasks',recordPayload({title:'UI Изменённая задача',status:'blocked'},task));assert.equal(task.revision,2);assert.equal(task.description,'Сохранить это описание');
    await assert.rejects(owner.post('tasks',recordPayload({title:'Старое окно'},{...task,revision:1})),e=>e.status===409);
  });
  const staffPassword=crypto.randomBytes(20).toString('base64url');
  await test('UI staff create and edit omit a blank replacement password',async()=>{
    staff=await owner.post('users',staffPayload({name:'UI Читатель',email:'ui-reader@example.invalid',role:'reader',active:true,password:staffPassword}));
    assert.equal(staff.role,'reader');assert.equal(Object.hasOwn(staff,'password_hash'),false);
    staff=await owner.post('users',staffPayload({name:'UI Новое имя',email:staff.email,role:staff.role,active:true,password:''},staff));assert.equal(staff.revision,2);
    const reader=session(endpoint);await reader.get('auth/me');const logged=await reader.post('auth/login',{email:staff.email,password:staffPassword});assert.equal(logged.user.name,'UI Новое имя');
  });
  await test('reader cannot write employees; deactivation revokes the old session',async()=>{
    const reader=session(endpoint);await reader.get('auth/me');await reader.post('auth/login',{email:staff.email,password:staffPassword});
    await assert.rejects(reader.post('users',staffPayload({name:'Denied',email:'denied@example.invalid',role:'reader',active:true,password:staffPassword})),e=>e.status===403);
    staff=await owner.post('users',staffPayload({active:false,password:''},staff));assert.equal(staff.active,false);
    assert.equal((await reader.get('auth/me')).user,null);await assert.rejects(reader.get('partners'),e=>e.status===401);
  });
  await test('backend protects the last owner with the actual UI payload shape',async()=>{
    const account=(await owner.get('users',{include_inactive:1})).items.find(row=>row.id===me.user.id);
    await assert.rejects(owner.post('users',staffPayload({active:false,password:''},account)),e=>e.status===409 && e.code==='LAST_OWNER');assert.equal((await owner.get('auth/me')).user.id,me.user.id);
  });
  console.log(`${passed} actual core HTTP/UI-payload checks passed on disposable SQLite. No browser or production claim.`);
}finally{
  if(child && child.exitCode===null){const exited=once(child,'exit');child.kill();await exited;}
  const resolved=fs.realpathSync(dir),tempRoot=fs.realpathSync(os.tmpdir());
  assert(resolved.startsWith(tempRoot+path.sep) && path.basename(resolved).startsWith('kosmoport-ui-http-'),'Cleanup restricted to this generated temp directory');
  fs.rmSync(resolved,{recursive:true,force:true,maxRetries:10,retryDelay:100});
}
