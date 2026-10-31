import fs from 'node:fs/promises';
import path from 'node:path';
import {createRequire} from 'node:module';
import crypto from 'node:crypto';
const require=createRequire(import.meta.url);
let library;
try{library=require(process.env.KCRM_PLAYWRIGHT_MODULE||'playwright');}
catch(error){if(process.platform!=='win32'||!process.env.USERPROFILE)throw error;library=require(path.join(process.env.USERPROFILE,'.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'));}
const base=new URL(process.argv[2]||'https://ferryffggg.infinityfreeapp.com/kosmoport/');
if(base.protocol!=='https:'||base.username||base.password||base.search||base.hash)throw Error('A plain HTTPS application base URL is required');
if(!base.pathname.endsWith('/'))base.pathname+='/';
const output=path.resolve(process.argv[3]||'host-guard-proof.json');
const receipt=process.argv[4]?JSON.parse(await fs.readFile(process.argv[4],'utf8')):null;
if(receipt && (receipt.origin!==base.origin || !/^[a-f0-9]{32}$/.test(receipt.instance_id||'') || !/^[a-f0-9]{32}$/.test(receipt.deployment_nonce||'') || !/^[a-f0-9]{64}$/.test(receipt.package_sha256||'') || Object.keys(receipt.guard_sha256||{}).length!==10))throw Error('A complete current deployment receipt is required');
const browser=await library.chromium.launch({headless:true});
const checked=[];
let title,publicControl;
const evidence='Actual Chromium navigation and HTTP response inspection; no FTP-derived HTTP status';
try{
 const context=await browser.newContext();
 const page=await context.newPage();
 const root=await page.goto(base.href,{waitUntil:'networkidle',timeout:60000});
 title=await page.title();
 if(root.status()!==200||!/космопорт/i.test(title)||new URL(page.url()).origin!==base.origin)throw Error('The real public Kosmoport page was not reached; no guard proof can be issued');
 if(receipt){
  const relative=receipt.public_control?.path;
  if(typeof relative!=='string'||!relative.startsWith('public/')||relative.includes('..')||relative.includes('\\'))throw Error('Invalid public canary metadata');
  const target=new URL(relative.slice('public/'.length),base);
  const response=await page.goto(target.href,{waitUntil:'networkidle',timeout:45000});
  const bodyHash=crypto.createHash('sha256').update(await response.body()).digest('hex');
  if(response.status()!==200||page.url()!==target.href||bodyHash!==receipt.public_control.sha256)throw Error('Fresh public canary was not received by the browser');
  publicControl={status:200,method:'GET',network_error:false,url:target.href,final_url:page.url(),body_sha256:bodyHash,challenge:false,evidence};
 }
 const relatives=receipt?[receipt.privacy_probe?.path,receipt.document_probe?.path,'config/config.php','app/bootstrap.php']:['storage/privacy-probe.txt','app/website/default-content.json','config/config.php','migrations/001_core.sql','tools/backup-admin.php','bin/core-migrate.php','SOURCE-PACKAGE.json'];
 for(const relative of relatives){
  if(typeof relative!=='string'||relative.startsWith('/')||relative.includes('..')||relative.includes('\\'))throw Error('Invalid private canary metadata');
  const target=new URL(relative,base);
  const response=await page.goto(target.href,{waitUntil:'domcontentloaded',timeout:45000});
  const status=response?.status();
  if(![403,404].includes(status)||page.url()!==target.href)throw Error('Private HTTP path is not proven denied: '+relative+' HTTP '+status);
  const html=await page.content();
  if(/\/aes\.js|cf-chl-|challenge-platform|This site requires Javascript|Checking your browser|captcha/i.test(html))throw Error('A host security challenge is not proof of application privacy: '+relative);
  checked.push({path:receipt?target.pathname:relative,status,method:'GET',url:target.href,final_url:page.url(),challenge:false,network_error:false,evidence});
 }
 await context.close();
}finally{await browser.close();}
const result=receipt?{kind:'interactive-browser-observation',observer:'Automated real Chromium navigation',instance_id:receipt.instance_id,deployment_nonce:receipt.deployment_nonce,origin:base.origin,package_sha256:receipt.package_sha256,guard_sha256:receipt.guard_sha256,checked_at:new Date().toISOString(),private_gets:checked,public_control:publicControl,public_title:title}:{format:'kosmoport-host-guard-v1',base_url:base.href,checked_at:new Date().toISOString(),release:process.env.GITHUB_SHA||null,public_title:title,private_http_denial_verified:true,checked};
await fs.mkdir(path.dirname(output),{recursive:true});
await fs.writeFile(output,JSON.stringify(result,null,2)+'\n');
console.log(JSON.stringify({ok:true,base_url:base.href,private_http_denial_verified:true,statuses:checked.map(item=>item.status),fresh_public_control:publicControl?.status===200,proof_file:output},null,2));
