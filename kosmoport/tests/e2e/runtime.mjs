import fs from 'node:fs';
import path from 'node:path';
import net from 'node:net';
import crypto from 'node:crypto';
import {fileURLToPath} from 'node:url';
import {spawn,spawnSync} from 'node:child_process';
export const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
export const php=process.env.KCRM_QA_PHP||'C:/Users/Izop300520251/Documents/ChatGPT/cfqn ghjabcgjhn/tools/php-runtime/php.exe';
export const phpFlags=['-n','-d','extension_dir='+path.join(path.dirname(php),'ext'),...['pdo_mysql','pdo_sqlite','fileinfo','openssl','mbstring','zip'].flatMap(n=>['-d','extension='+n])];
export function phpRun(file,args=[],env={}) {
 const r=phpResult(file,args,env);
 if(r.status!==0)throw Error(path.basename(file)+': '+String(r.stderr||r.stdout||r.error?.message).slice(-1800));
 return r.stdout;
}
export function phpResult(file,args=[],env={}) {return spawnSync(php,[...phpFlags,file,...args],{cwd:root,encoding:'utf8',windowsHide:true,timeout:120000,env:{...process.env,...env}});}
export function writeConfiguration(file,config){fs.writeFileSync(file,"<?php\nreturn json_decode(base64_decode('"+Buffer.from(JSON.stringify(config)).toString('base64')+"'), true, 512, JSON_THROW_ON_ERROR);\n");}
export async function freePort(){return new Promise((resolve,reject)=>{const server=net.createServer();server.once('error',reject);server.listen(0,'127.0.0.1',()=>{const port=server.address().port;server.close(()=>resolve(port));});});}
export async function createRun() {
 const id=new Date().toISOString().slice(0,10).replaceAll('-','')+'_'+crypto.randomBytes(5).toString('hex');
 const directory=path.join(root,'tests/e2e/.runtime/runs',id);fs.mkdirSync(directory,{recursive:true});
 const server=JSON.parse(fs.readFileSync(path.join(root,'tests/e2e/.runtime/local-db.json'),'utf8'));
 if(server.marker!=='KCRM_QA_LOCAL_SERVER'||server.host!=='127.0.0.1')throw Error('Not a disposable loopback database');
 const database={driver:'mysql',host:server.host,port:server.port,name:'kcrmqa_'+id,username:server.username,password:server.password,charset:'utf8mb4'};
 phpRun(path.join(root,'tests/e2e/db-admin.php'),['create',database.name]);
 const port=await freePort(),secret=()=>crypto.randomBytes(24).toString('hex');
 const setupKey=secret(),owner={name:'QA Владелец',email:'owner.'+id+'@qa.invalid',password:'QA-'+secret()};
 const config={environment:'test',app_name:'Космопорт QA',base_path:'/kosmoport',timezone:'Asia/Yekaterinburg',storage_path:path.join(directory,'storage'),app_key:secret(),setup_key_hash:crypto.createHash('sha256').update(setupKey).digest('hex'),database,session:{name:'KCRMQASESSID',secure:false,same_site:'Lax',idle_seconds:1800,absolute_seconds:43200},documents:{max_upload_bytes:1024*1024}};
 const configFile=path.join(directory,'config.php');
 writeConfiguration(configFile,config);
 const fixture={marker:'KCRM_QA_DISPOSABLE_V1',environment:'test',id,directory,database,base_url:'http://127.0.0.1:'+port+'/kosmoport/',setup_key:setupKey,owner,config_file:configFile,configuration:config};
 fixture.fixture_file=path.join(directory,'fixture.json');fs.writeFileSync(fixture.fixture_file,JSON.stringify(fixture,null,2));
 return fixture;
}
export async function startApp(fixture) {
 const log=fs.openSync(path.join(fixture.directory,'php-server.log'),'a');
 const child=spawn(php,[...phpFlags,'-d','display_errors=0','-d','log_errors=1','-d','upload_max_filesize=12M','-d','post_max_size=14M','-S','127.0.0.1:'+new URL(fixture.base_url).port,'-t',path.join(root,'public'),path.join(root,'tests/e2e/dev-router.php')],{cwd:root,windowsHide:true,stdio:['ignore',log,log],env:{...process.env,KCRM_CONFIG:fixture.config_file,KCRM_QA_BASE_PATH:'/kosmoport'}});fs.closeSync(log);
 child.on('error',()=>{});
 for(let n=0;n<60;n++) {
  if(child.exitCode!==null)throw Error('QA PHP server exited; see private php-server.log');
  try {await fetch(new URL('crm/',fixture.base_url),{signal:AbortSignal.timeout(1500)});return child;}catch{}
  await new Promise(r=>setTimeout(r,200));
 }
 child.kill();throw Error('QA PHP server unavailable');
}
export async function stopApp(child) {if(child&&child.exitCode===null){child.kill();await new Promise(resolve=>{child.once('exit',resolve);setTimeout(resolve,3000);});}}
