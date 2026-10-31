import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {createRequire} from 'node:module';
import {root} from './runtime.mjs';
import {loadPlaywright} from './playwright.mjs';
const require=createRequire(import.meta.url);
export async function verifyBrowserErrors(fixture,report) {
 const {chromium}=loadPlaywright(),browser=await chromium.launch({headless:true});
 const directory=path.join(root,'tests/e2e/artifacts');fs.mkdirSync(directory,{recursive:true});
 try {for(const [width,height]of [[390,844],[1024,768],[1366,768]]){
  await report.check('UI error injection / unconfigured and retry / '+width,async()=>{
   const context=await browser.newContext({viewport:{width,height}}),page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
   let mode='unconfigured',loginCalls=0;
   await page.route('**/api/index.php?*',async route=>{
    const url=new URL(route.request().url()),r=url.searchParams.get('r');
    assert.equal(url.origin,new URL(fixture.base_url).origin);
    if(mode==='offline')return route.abort('internetdisconnected');
    if(mode==='unconfigured')return route.fulfill({status:503,json:{ok:false,error:{code:'NOT_CONFIGURED',message:'QA: CRM ещё не настроена.'}}});
    if(r==='auth/me')return route.fulfill({json:{ok:true,data:{user:null,csrf:'a'.repeat(64)}}});
    if(r==='auth/login'){loginCalls++;return route.fulfill({status:401,json:{ok:false,error:{code:'LOGIN_FAILED',message:'QA: неверная почта или пароль.'}}});}
    throw Error('Unexpected error-fixture route '+r);
   });
   try{
    await page.goto(new URL('crm/',fixture.base_url).href);
    await page.getByRole('heading',{name:'CRM недоступна'}).waitFor();
    await page.getByRole('alert').filter({hasText:'QA: CRM ещё не настроена.'}).waitFor();
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'document overflows horizontally');
    mode='ready';await page.getByRole('button',{name:'Повторить подключение',exact:true}).click();
    await page.getByRole('heading',{name:'Вход в CRM'}).waitFor();
    await page.getByLabel('Электронная почта',{exact:true}).fill('fixture@qa.invalid');await page.getByLabel('Пароль',{exact:true}).fill('fixture-not-a-real-password');
    mode='ready';await page.getByRole('button',{name:'Войти',exact:true}).click();
    await page.getByRole('alert').filter({hasText:'QA: неверная почта или пароль.'}).waitFor();
    assert.equal(loginCalls,1);assert(await page.getByRole('button',{name:'Войти',exact:true}).isEnabled(),'retry action stayed locked');
    mode='offline';await page.getByRole('button',{name:'Войти',exact:true}).click();
    await page.getByRole('alert').filter({hasText:'Нет связи с сервером.'}).waitFor();
    assert(await page.getByRole('button',{name:'Войти',exact:true}).isEnabled());
    assert.equal(await page.getByLabel('Электронная почта',{exact:true}).inputValue(),'fixture@qa.invalid','retry erased the form');
    assert.deepEqual(errors,[]);await page.screenshot({path:path.join(directory,'ui-errors-'+width+'.png'),fullPage:true});
    return {kind:'UI-only fault injection; not core/API authorization proof',viewport:{width,height},configuration_error:true,login_error:true,offline_error:true,retry_unlocked:true,no_horizontal_overflow:true};
   }catch(error){await page.screenshot({path:path.join(directory,'ui-errors-failure-'+width+'.png'),fullPage:true});fs.writeFileSync(path.join(directory,'ui-errors-failure-'+width+'.txt'),JSON.stringify({body:await page.locator('body').innerText(),errors},null,2));throw error;}
   finally{await context.close();}
  });
 }}finally{await browser.close();}
}
