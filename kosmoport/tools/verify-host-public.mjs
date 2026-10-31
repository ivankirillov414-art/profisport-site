import fs from 'node:fs/promises';
import path from 'node:path';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url);
let module;
try{module=require(process.env.KCRM_PLAYWRIGHT_MODULE||'playwright');}
catch(error){if(process.platform!=='win32'||!process.env.USERPROFILE)throw error;module=require(path.join(process.env.USERPROFILE,'.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'));}
const base=new URL(process.argv[2]||'https://ferryffggg.infinityfreeapp.com/kosmoport/');
if(base.protocol!=='https:'||base.username||base.password||base.search||base.hash)throw Error('Plain HTTPS project URL required');
const output=path.resolve(process.argv[3]||'host-readiness.public.json');
const browser=await module.chromium.launch({headless:true});
const result={base_url:base.href,checked_at:new Date().toISOString(),authenticated_production_flow_verified:false};
try{
 const page=await browser.newPage();
 const website=await page.goto(base.href,{waitUntil:'networkidle',timeout:60000});
 if(website.status()!==200||!/космопорт/i.test(await page.title()))throw Error('Public website not reached');
 result.website=true;
 const api=async route=>{
  const url=new URL('api/index.php',base);url.searchParams.set('r',route);
  const response=await page.goto(url.href,{waitUntil:'networkidle',timeout:45000});
  if(response.status()!==200||!response.headers()['content-type']?.includes('application/json'))throw Error('Actual browser API did not return JSON: '+route+' HTTP '+response.status());
  const data=await response.json();if(data.ok!==true)throw Error('API readiness failed: '+route);return data.data;
 };
 const health=await api('health');result.application_version=health.version;result.owner_setup_pending=health.ready!==true;
 const me=await api('auth/me');
 if(me.user!==null||!(/^[a-f0-9]{64}$/i.test(me.csrf||'')))throw Error('Anonymous session/CSRF bootstrap failed');
 result.anonymous_session_and_csrf=true;
 const crm=await page.goto(new URL('crm/',base).href,{waitUntil:'networkidle',timeout:45000});
 if(crm.status()!==200)throw Error('CRM public entry unavailable');
 await page.getByRole('heading',{name:'Вход в CRM',exact:true}).waitFor();
 result.crm_login_page=true;
 const setup=await page.goto(new URL('setup.php',base).href,{waitUntil:'networkidle',timeout:45000});
 if(setup.status()!==200||await page.locator('input[name="setup_key"]').count()!==1)throw Error('Independent owner setup form unavailable');
 result.independent_setup_form=true;
 if(process.argv.includes('--alias')){
  const alias=new URL('/cms/sites/kosmosfera/',base.origin);
  const response=await page.goto(alias.href,{waitUntil:'networkidle',timeout:60000});
  if(response.status()!==200||!new URL(page.url()).pathname.startsWith(base.pathname)||!/космопорт/i.test(await page.title()))throw Error('Old Kosmosfera URL did not reach Kosmoport');
  result.kosmosfera_alias=true;
 }
}finally{await browser.close();}
await fs.mkdir(path.dirname(output),{recursive:true});
await fs.writeFile(output,JSON.stringify({ok:true,...result},null,2)+'\n');
console.log(JSON.stringify({ok:true,...result},null,2));
