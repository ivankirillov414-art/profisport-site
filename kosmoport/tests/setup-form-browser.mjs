import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import crypto from 'node:crypto';
import {spawn} from 'node:child_process';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
const require=createRequire(import.meta.url);
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const php=process.env.KCRM_TEST_PHP||path.join(process.env.USERPROFILE||'', 'Documents/ChatGPT/cfqn ghjabcgjhn/tools/php-runtime/php.exe');
let library;
try{library=require(process.env.KCRM_PLAYWRIGHT_MODULE||'playwright');}
catch(error){if(process.platform!=='win32')throw error;library=require(path.join(process.env.USERPROFILE,'.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'));}
const folder=await fs.mkdtemp(path.join(os.tmpdir(),'kcrm-form-browser-'));
const key=crypto.randomBytes(32).toString('hex');
const config={environment:'test',base_path:'',storage_path:path.join(folder,'storage'),app_key:crypto.randomBytes(32).toString('hex'),setup_key_hash:crypto.createHash('sha256').update(key).digest('hex'),database:{driver:'sqlite_test',path:path.join(folder,'fixture.sqlite'),allow_test_driver:true}};
const configFile=path.join(folder,'config.php');
await fs.writeFile(configFile,"<?php return json_decode(base64_decode('"+Buffer.from(JSON.stringify(config)).toString('base64')+"'),true);");
const socket=net.createServer();
await new Promise(resolve=>socket.listen(0,'127.0.0.1',resolve));
const port=socket.address().port;
await new Promise(resolve=>socket.close(resolve));
const base='http://127.0.0.1:'+port;
const args=[];
if(process.env.KCRM_TEST_PHP_EXT)args.push('-d','extension_dir='+process.env.KCRM_TEST_PHP_EXT);
args.push('-d','extension=pdo_sqlite','-d','display_errors=0','-S','127.0.0.1:'+port,'-t',path.join(root,'public'));
const server=spawn(php,args,{env:{...process.env,KCRM_CONFIG:configFile},windowsHide:true,stdio:'ignore'});
let browser;
try{
 for(let attempt=0;;attempt++){
  try{const response=await fetch(base+'/api/index.php?r=health');if(response.status===200)break;}
  catch{}
  if(attempt===99||server.exitCode!==null)throw Error('Isolated PHP fixture did not start');
  await new Promise(resolve=>setTimeout(resolve,100));
 }
 browser=await library.chromium.launch({headless:true});
 const page=await browser.newPage();
 let actualOrigin;
 page.on('request',request=>{if(request.method()==='POST')actualOrigin=request.headers().origin;});
 const setup=await page.goto(base+'/setup.php');
 if(setup.headers()['referrer-policy']!=='same-origin')throw Error('Setup must preserve same-origin form Origin');
 const csrf=await page.locator('[name="_csrf"]').inputValue();
 await page.locator('[name="setup_key"]').fill(key);
 await page.locator('form').evaluate(form=>{form.noValidate=true;});
 const [rejection]=await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Создать владельца',exact:true}).click()]);
 if(rejection.status()!==422||actualOrigin!==base||!await page.locator('.error').innerText().then(value=>value.includes('Имя владельца')))throw Error('Real browser form did not reach ordinary field validation');
 const form={_csrf:csrf,setup_key:key,name:'',email:'',password:''};
 for(const [origin,token,code] of [['null',csrf,'ORIGIN_FORBIDDEN'],['https://example.invalid',csrf,'ORIGIN_FORBIDDEN'],[base,'0'.repeat(64),'CSRF_INVALID']]){
  const response=await page.request.post(base+'/setup.php',{form:{...form,_csrf:token},headers:{Origin:origin,Accept:'application/json'}});
  const json=await response.json();
  if(response.status()!==403||json.error?.code!==code)throw Error('Origin/CSRF rejection changed');
 }
 const health=await page.request.get(base+'/api/index.php?r=health').then(response=>response.json());
 if(health.data.ready!==false)throw Error('Fixture unexpectedly installed an owner');
 console.log(JSON.stringify({ok:true,checks:5,real_browser_form_status:422,same_origin_header:true,null_origin_denied:true,cross_origin_denied:true,invalid_csrf_denied:true,owner_created:false,fixture_only:true}));
}catch{
 console.error('Setup form regression failed; synthetic key and interaction diagnostics withheld');
 process.exitCode=1;
}finally{
 if(browser)await browser.close().catch(()=>{});
 if(server.exitCode===null){server.kill();await Promise.race([new Promise(resolve=>server.once('exit',resolve)),new Promise(resolve=>setTimeout(resolve,5000))]);}
 if(server.exitCode===null)server.kill('SIGKILL');
 const absolute=path.resolve(folder), temporary=path.resolve(os.tmpdir());
 if(path.dirname(absolute)!==temporary||!path.basename(absolute).startsWith('kcrm-form-browser-'))throw Error('Refusing cleanup outside the owned synthetic fixture');
 await fs.rm(absolute,{recursive:true,force:true});
}
