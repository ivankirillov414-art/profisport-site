import fs from 'node:fs/promises';
import path from 'node:path';
import {createRequire} from 'node:module';

const require=createRequire(import.meta.url);
let browserModule;
try { browserModule=require(process.env.KCRM_PLAYWRIGHT_MODULE||'playwright'); }
catch(error) {
 if(process.platform!=='win32'||!process.env.USERPROFILE)throw error;
 browserModule=require(path.join(process.env.USERPROFILE,'.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'));
}
const {chromium}=browserModule;
const base=new URL(process.argv[2]||'http://127.0.0.1:43180/');
const out=path.resolve(process.argv[3]||'../../outputs/website-preview');
if (!['http:','https:'].includes(base.protocol) || base.username || base.password) throw Error('Invalid public website URL');
await fs.mkdir(out,{recursive:true});
const browser=await chromium.launch({headless:true});
const results=[];
try {
 for (const width of [1366,1024,390]) {
  const page=await browser.newPage({viewport:{width,height:900},deviceScaleFactor:1});
  const errors=[];
  page.on('pageerror',error=>errors.push(String(error.message)));
  const response=await page.goto(base.href,{waitUntil:'networkidle',timeout:60000});
  await page.evaluate(async()=>{ await Promise.all([...document.images].filter(img=>img.getAttribute('src')).map(async img=>{img.loading='eager';try{await img.decode();}catch{}})); });
  await page.screenshot({path:path.join(out,`kosmoport-${width}.png`),fullPage:true});
  await page.screenshot({path:path.join(out,`kosmoport-first-screen-${width}.png`),fullPage:false});
  if(await page.locator('#family').count()) await page.locator('#family').screenshot({path:path.join(out,`kosmoport-family-${width}.png`)});
  const snapshot=await page.evaluate(()=>({
   title:document.title,text:document.body.innerText,
   overflow:document.documentElement.scrollWidth>innerWidth+1,
   brokenImages:[...document.images].filter(x=>x.currentSrc && (!x.complete||!x.naturalWidth)).map(x=>x.getAttribute('src')),
  }));
  const local=['127.0.0.1','localhost'].includes(base.hostname);
  if (local) {
   if(response.status()!==200||!snapshot.title.includes('КОСМОПОРТ')||snapshot.overflow||snapshot.brokenImages.length||errors.length) throw Error(JSON.stringify({width,status:response.status(),snapshot,errors}));
   if (/[→←↗↖↘↙↑↓⟶⟵➜➡➝↔›‹✦✧⚛♡⌖◎🪐]/u.test(snapshot.text)) throw Error('Decorative arrows or symbols remain visible');
   if (!snapshot.text.includes('18 января 2027')) throw Error('Confirmed closing date missing');
   if (snapshot.text.includes('ВСЕЙСЕМЬИ') || snapshot.text.includes('ТЫДУМАЕШЬ')) throw Error('Joined words remain visible');
   await page.locator('.single-ticket').click();
   if (!await page.locator('#ticket-dialog').isVisible()) throw Error('Ticket dialog did not open');
   await page.locator('#plus').click();
   if(await page.locator('#quantity').innerText()!=='2') throw Error('Ticket quantity did not update');
   await page.locator('#ticket-dialog [data-close]').click();
   await page.locator('.invite-link').click();
   if (!await page.locator('#invite-dialog').innerText().then(x=>x.includes('взрослого'))) throw Error('Invitation terms missing');
   await page.locator('#invite-dialog .button[data-close]').click();
   if(width===390) {
    await page.locator('.menu-toggle').click();
    if(await page.locator('.menu-toggle').getAttribute('aria-expanded')!=='true') throw Error('Mobile menu did not open');
    await page.locator('#mobile-menu a[href="#visit"]').click();
    if(await page.locator('.menu-toggle').getAttribute('aria-expanded')!=='false') throw Error('Mobile menu did not close');
   }
  }
  results.push({width,status:response.status(),title:snapshot.title,overflow:snapshot.overflow,brokenImages:snapshot.brokenImages,errors,url:page.url(),bodyExcerpt:local?undefined:snapshot.text.slice(0,1800)});
  await page.close();
 }
} finally { await browser.close(); }
await fs.writeFile(path.join(out,'browser-result.json'),JSON.stringify({base:base.href,checked_at:new Date().toISOString(),results},null,2)+'\n');
console.log(JSON.stringify({ok:true,results,output:out},null,2));
