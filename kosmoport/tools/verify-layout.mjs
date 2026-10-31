import fs from 'node:fs';
import path from 'node:path';
import {fixture} from '../tests/e2e/harness.mjs';
import {root,freePort,startApp,stopApp} from '../tests/e2e/runtime.mjs';
import {loadPlaywright} from '../tests/e2e/playwright.mjs';
const id=process.argv.find(x=>x.startsWith('--fixture-id='))?.split('=')[1];if(!/^\d{8}_[a-f0-9]{10}$/.test(id||''))throw Error('Explicit QA fixture ID required');
const context=fixture(path.join(root,'tests/e2e/.runtime/runs',id,'fixture.json'));context.base_url='http://127.0.0.1:'+await freePort()+'/kosmoport/';
let server,browser;
try{server=await startApp(context);browser=await loadPlaywright().chromium.launch({headless:true});const page=await browser.newPage({viewport:{width:390,height:844}});await page.goto(new URL('crm/',context.base_url).href);await page.getByLabel('Электронная почта',{exact:true}).fill(context.owner.email);await page.getByLabel('Пароль',{exact:true}).fill(context.owner.password);const response=page.waitForResponse(r=>new URL(r.url()).searchParams.get('r')==='auth/login');await page.getByRole('button',{name:'Войти',exact:true}).click();const login=await response;if(login.status()!==200)throw Error('Diagnostic login HTTP '+login.status());await page.getByRole('heading',{name:'Обзор',exact:true}).waitFor();await page.locator('[data-nav="partners"]').click();await page.getByRole('heading',{name:'Партнёры',exact:true}).waitFor();
 const measure=()=>page.evaluate(()=>({viewport:innerWidth,document:document.documentElement.scrollWidth,span:[...document.querySelectorAll('.table-wrap .sr-only')].map(e=>({position:getComputedStyle(e).position,offset_parent:e.offsetParent?.tagName,offset_parent_class:e.offsetParent?.className,left:e.getBoundingClientRect().left,right:e.getBoundingClientRect().right})),wrapper:[...document.querySelectorAll('.table-wrap')].map(e=>({client:e.clientWidth,scroll:e.scrollWidth,overflow:getComputedStyle(e).overflowX}))}));
 const before=await measure();const style=await page.addStyleTag({content:'.table-wrap{position:relative!important}'});const contained=await measure();await style.evaluate(e=>e.remove());const restored=await measure();
 const result={checked_at:new Date().toISOString(),fixture_id:id,scope:'Read-only local browser diagnostic; CSS variation in test browser only, no source edit',before,temporary_table_wrapper_position_relative:contained,restored};fs.writeFileSync(path.join(root,'tests/e2e/artifacts/mobile-layout-diagnostic.json'),JSON.stringify(result,null,2));console.log(JSON.stringify(result));
}finally{await browser?.close();await stopApp(server);}
