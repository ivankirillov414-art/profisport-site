import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {createRequire} from 'node:module';
import {Session,Blocked} from './harness.mjs';
import {root} from './runtime.mjs';
import {loadPlaywright} from './playwright.mjs';
import {verifyStaffBrowser} from './browser-staff.mjs';
const require=createRequire(import.meta.url);
export async function verify(context,report){
 if(!context.document||!context.partner||!context.ticket_batch){await report.check('integrated browser prerequisites',()=>{throw new Blocked('Document/partner/ticket setup missing');});return;}
 const helper=new Session(context.base_url);await helper.login(context.owner.email,context.owner.password);
 const {chromium}=loadPlaywright(),browser=await chromium.launch({headless:true}),directory=path.join(root,'tests/e2e/artifacts');
 const files=['public/crm/index.php',...fs.readdirSync(path.join(root,'public/assets')).filter(n=>/^crm.*\.(js|css)$/.test(n)).map(n=>'public/assets/'+n)],hash=relative=>crypto.createHash('sha256').update(fs.readFileSync(path.join(root,relative))).digest('hex');
 const source=Object.fromEntries(files.map(n=>[n,hash(n)])),changes=()=>files.filter(n=>hash(n)!==source[n]);
 try{let index=0;for(const [width,height]of [[390,844],[1024,768],[1366,768]]){
  const browserContext=await browser.newContext({viewport:{width,height},acceptDownloads:true}),page=await browserContext.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const checkWidth=async()=>assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'horizontal page overflow');
  const shot=async name=>{await checkWidth();await page.screenshot({path:path.join(directory,'integrated-'+width+'-'+name+'.png'),fullPage:true});};
  const section=(label,fn)=>report.check('real browser / '+width+' / '+label,async()=>{
   try{if(changes().length)throw new Blocked('UI source updated during the run: '+changes().join(', '));const result=await fn();await checkWidth();assert.deepEqual(errors,[]);assert(!/[\u2190-\u21ff\u27f0-\u27ff\u2900-\u297f\u2b00-\u2b11]/u.test(await page.locator('body').innerText()),'arrow glyph in visible UI');return {viewport:{width,height},real_api:true,real_mysql:true,...result};}
   catch(error){await page.screenshot({path:path.join(directory,'integrated-'+width+'-'+label+'-failure.png'),fullPage:true});const layout=await page.evaluate(()=>({viewport:innerWidth,document_width:document.documentElement.scrollWidth,overflow:[...document.querySelectorAll('body *')].map(e=>({tag:e.tagName,class:e.className,left:e.getBoundingClientRect().left,right:e.getBoundingClientRect().right,width:e.getBoundingClientRect().width,client:e.clientWidth,scroll:e.scrollWidth})).filter(e=>e.right>innerWidth+1||e.left< -1).slice(0,24)}));fs.writeFileSync(path.join(directory,'integrated-'+width+'-'+label+'-failure.txt'),JSON.stringify({body:await page.locator('body').innerText(),errors,layout},null,2));
    if(await page.locator('dialog[open]').count())await page.locator('dialog[open]').getByRole('button',{name:'Закрыть',exact:true}).first().click().catch(()=>{});throw error;
   }
  });
  try{
   const loggedIn=await section('login',async()=>{await page.goto(new URL('crm/',context.base_url).href);await page.getByRole('heading',{name:'Вход в CRM'}).waitFor();await page.getByLabel('Электронная почта',{exact:true}).fill(context.owner.email);await page.getByLabel('Пароль',{exact:true}).fill(context.owner.password);const response=page.waitForResponse(r=>new URL(r.url()).searchParams.get('r')==='auth/login'&&r.request().method()==='POST');await page.getByRole('button',{name:'Войти',exact:true}).click();const r=await response;if(r.status()===429)throw new Blocked('Combined QA logins reached the unchanged core IP rate limit; use fewer redundant fixture logins');assert.equal(r.status(),200);await page.getByRole('heading',{name:'Обзор',exact:true}).waitFor();await shot('overview');return {login:true};});
   if(!loggedIn)continue;
   await section('partner-edit',async()=>{
    await page.locator('[data-nav="partners"]').click();await page.getByRole('heading',{name:'Партнёры',exact:true}).waitFor();const row=page.locator('tr').filter({hasText:context.partner.name});await row.getByRole('button',{name:'Изменить',exact:true}).click();await page.locator('dialog input[name="contact_name"]').fill('QA браузер '+width);
    const response=page.waitForResponse(r=>new URL(r.url()).searchParams.get('r')==='partners'&&r.request().method()==='POST');await page.locator('dialog').getByRole('button',{name:'Сохранить',exact:true}).click();const r=await response;const data=await r.json();assert.equal(r.status(),200,data.error?.message);await page.locator('dialog').waitFor({state:'hidden'});await row.locator('td').nth(1).filter({hasText:'QA браузер '+width}).waitFor();assert.equal(await page.evaluate(()=>window.qaXss),undefined);await shot('partners');return {stored_edit_readback:true};
   });
   await section('task-edit',async()=>{
    await page.locator('[data-nav="tasks"]').click();await page.getByRole('heading',{name:'Задачи',exact:true}).waitFor();await page.locator('[data-task-id="'+context.task.id+'"]').click();const title='QA браузер задача '+width;await page.locator('dialog input[name="title"]').fill(title);await page.locator('dialog select[name="status"]').selectOption('in_progress');
    const response=page.waitForResponse(r=>new URL(r.url()).searchParams.get('r')==='tasks'&&r.request().method()==='POST');await page.locator('dialog').getByRole('button',{name:'Сохранить',exact:true}).click();const r=await response;const data=await r.json();assert.equal(r.status(),200,data.error?.message);await page.locator('dialog').waitFor({state:'hidden'});await page.getByText(title,{exact:true}).waitFor();await shot('tasks');return {stored_edit_readback:true};
   });
   await section('staff-settings',()=>verifyStaffBrowser(page,context,width,shot));
   await section('ticket-admission',async()=>{
    const code='87'+Date.now().toString()+String(index++).padStart(3,'0');await helper.ok('tickets/import',{method:'POST',body:{format:'kosmoport.tickets',version:1,sources:[],batches:[],tickets:[{code,batch_id:context.ticket_batch,used_at:null,scan_id:null}],admissions:[],events:[]}});
    await page.locator('[data-nav="tickets"]').click();await page.locator('#ticket-code').fill(code);await page.getByRole('button',{name:'Проверить',exact:true}).click();await page.getByRole('heading',{name:'Приглашение найдено'}).waitFor();const confirm=page.getByRole('button',{name:'Подтвердить проход',exact:true});assert(await confirm.isDisabled());await shot('ticket-check');await page.getByLabel('Оплата взрослого подтверждена вручную').check();assert(await confirm.isEnabled());
    const response=page.waitForResponse(r=>new URL(r.url()).searchParams.get('r')==='tickets/admit'&&r.request().method()==='POST');await confirm.click();const accepted=await (await response).json();assert.equal(accepted.ok,true);assert.equal(accepted.data.fiscal_payment_verified,false);await page.getByText('Проход зарегистрирован',{exact:true}).waitFor();await shot('ticket-admitted');await page.getByRole('button',{name:'Следующее приглашение',exact:true}).click();await page.locator('#ticket-code').fill(code);await page.getByRole('button',{name:'Проверить',exact:true}).click();await page.getByRole('alert').filter({hasText:'Билет уже использован'}).waitFor();await helper.ok('tickets/reverse',{method:'POST',body:{admission_id:accepted.data.admission_id,reason:'QA отмена браузерного прохода'}});return {check_without_consumption:true,manual_confirmation:true,duplicate_denied:true,no_fiscal_payment:true};
   });
   await section('documents',async()=>{
    await page.locator('[data-nav="documents"]').click();await page.getByRole('heading',{name:'Документы',exact:true}).waitFor();const row=page.locator('tr').filter({hasText:context.document.title});await row.getByRole('button',{name:'Версии',exact:true}).click();await page.getByText('Версия 1',{exact:true}).waitFor();await page.getByText('Версия 2',{exact:true}).waitFor();await page.getByRole('button',{name:'Закрыть',exact:true}).click();
    const event=page.waitForEvent('download');await row.getByRole('button',{name:'Скачать',exact:true}).click();const downloaded=path.join(directory,'download-'+width+'.pdf');await (await event).saveAs(downloaded);assert.equal(crypto.createHash('sha256').update(fs.readFileSync(downloaded)).digest('hex'),context.document.version2_sha256);await shot('documents');return {versions:[1,2],protected_exact_download:true};
   });
   await section('website',async()=>{
    await page.locator('[data-nav="website"]').click();await page.locator('#website-form').waitFor();const basic=await page.locator('#website-form input[name="brand"]').count()>0;
    const editor=page.locator(basic?'#website-form input[name="brand"]':'#website-form textarea[name="content"]'),property=basic?'brand':'tagline';
    const fill=async value=>{if(!await editor.isVisible()&&await page.locator('.json-details summary').count())await page.locator('.json-details summary').click();if(basic){await editor.fill(value);return value;}const content=JSON.parse(await editor.inputValue());content[property]=value;const text=JSON.stringify(content,null,2);await editor.fill(text);return text;};
    const local=await fill('QA локальный '+width),parallel=await helper.ok('website/draft');parallel.content[property]='QA параллельный '+width;await helper.ok('website/save',{method:'POST',body:{content:parallel.content,expected_revision:parallel.revision}});
    await page.getByRole('button',{name:'Сохранить черновик',exact:true}).click();await page.getByText('Конфликт версий',{exact:true}).waitFor();assert.equal(await editor.inputValue(),local);assert(await page.locator('#publish-button').isDisabled());await shot('website-conflict');page.once('dialog',dialog=>dialog.accept());await page.getByRole('button',{name:'Загрузить актуальный черновик',exact:true}).click();await page.getByText('Все изменения сохранены',{exact:true}).waitFor();
    const published='QA опубликовано '+width;await fill(published);await page.getByRole('button',{name:'Сохранить черновик',exact:true}).click();await page.locator('#notice').filter({hasText:'Черновик сохранён.'}).waitFor();await page.locator('#publish-button').click();await page.getByRole('checkbox',{name:'Содержимое проверено и готово к публикации.'}).check();await page.locator('dialog').getByRole('button',{name:'Опубликовать',exact:true}).click();await page.locator('dialog').waitFor({state:'hidden'});assert.equal((await helper.ok('website/content')).content[property],published);await shot('website');return {editor_mode:basic?'basic_fields':'json',conflict_preserves_text:true,reviewed_publish:true};
   });
   await section('logout',async()=>{page.once('dialog',dialog=>dialog.accept());await page.getByRole('button',{name:'Выйти',exact:true}).click();await page.getByRole('heading',{name:'Вход в CRM'}).waitFor();return {logout:true};});
  }finally{await browserContext.close();}
 }
 if(context.users?.cashier)await report.check('real browser / cashier / server403 presentation',async()=>{
  const browserContext=await browser.newContext({viewport:{width:390,height:844}}),page=await browserContext.newPage();try{await page.goto(new URL('crm/',context.base_url).href);await page.getByLabel('Электронная почта',{exact:true}).fill(context.users.cashier.email);await page.getByLabel('Пароль',{exact:true}).fill(context.owner.password);await page.getByRole('button',{name:'Войти',exact:true}).click();await page.getByRole('heading',{name:'Обзор',exact:true}).waitFor();await page.locator('[data-nav="documents"]').click();await page.getByRole('heading',{name:'Нет доступа к разделу'}).waitFor();await page.screenshot({path:path.join(directory,'cashier-forbidden-390.png'),fullPage:true});return {real_role:'cashier',documents_denied:true};}finally{await browserContext.close();}
 });
 }finally{await report.check('UI source snapshot unchanged during browser tests',()=>{const changed=changes();fs.writeFileSync(path.join(directory,'ui-source-hashes.json'),JSON.stringify({start:source,end:Object.fromEntries(files.map(n=>[n,hash(n)])),changed},null,2));if(changed.length)throw new Blocked('Rerun after UI editing finishes: '+changed.join(', '));return {files:files.length,source_unchanged:true};});await browser.close();}
}
