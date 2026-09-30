// Real browser and editor scripts, isolated in-memory API (not a PHP/DB test).
const {chromium}=require('playwright');
const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chromium'});
 try{
  for(const entry of ['index.html','fields.html']){
   const context=await browser.newContext(),page=await context.newPage(),errors=[];
   page.on('pageerror',e=>errors.push(e.message));
   page.on('dialog',dialog=>dialog.accept());
   const fixture=JSON.parse(fs.readFileSync(path.join(root,'cms/demo-state.json'),'utf8'));
   fixture.site={key:'test',name:'Test studio'};fixture.site_url='https://id-studio.test/';
   if(entry==='index.html'){fixture.manifest={pages:{}};fixture.templates={};fixture.draft={pages:{'index.html':{title:'Главная',fields:{},blocks:[],layout:[]}}};}
   let saves=0,conflict=false,delay=0;
   await page.route('**/*',async route=>{
    const url=new URL(route.request().url());
    if(url.hostname!=='id-studio.test')return route.abort();
    if(url.pathname==='/cms/api.php'){
     const action=url.searchParams.get('action');let body;
     if(action==='session')body={csrf:'test',authenticated:true};
     else if(action==='state')body=fixture;
     else if(action==='sites')body={items:[{site_key:'test',name:'Test studio',url:fixture.site_url}]};
     else if(action==='save'){
      saves++;const input=route.request().postDataJSON();
      if(delay)await new Promise(resolve=>setTimeout(resolve,delay));
      if(conflict||input.version!==fixture.version)return route.fulfill({status:409,json:{error:'Конфликт версий'}});
      fixture.draft=input.draft;fixture.version++;body={draft:fixture.draft,version:fixture.version,published_version:fixture.published_version};
     }else throw Error('Unexpected API action: '+action);
     return route.fulfill({json:body});
    }
    const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));
    if(!file.startsWith(root+path.sep)||!fs.existsSync(file)||fs.statSync(file).isDirectory())return route.fulfill({status:404,body:''});
    const type={'.html':'text/html','.js':'text/javascript','.css':'text/css','.json':'application/json'}[path.extname(file)]||'application/octet-stream';
    return route.fulfill({contentType:type,body:fs.readFileSync(file)});
   });
   await page.goto('https://id-studio.test/cms/'+entry);
   await page.waitForFunction(()=>typeof state!=='undefined'&&state&&!document.querySelector('#app').hidden);
   if(entry==='index.html'){
    await page.evaluate(()=>addBlock('text'));
    await page.waitForFunction(()=>!dirty);
    assert.equal(fixture.draft.pages['index.html'].layout.length,1);
    assert.equal(await page.evaluate(()=>undoStates.length>1),true,'Autosave preserves undo');
    await page.evaluate(()=>travel(-1));await page.waitForFunction(()=>!dirty);
    assert.equal(fixture.draft.pages['index.html'].layout.length,0,'Undo also autosaves');
    conflict=true;await page.evaluate(()=>addBlock('text'));
    await page.waitForFunction(()=>document.querySelector('#status').textContent.includes('Автосохранение остановлено'));
    fixture.version++;
    await page.reload();await page.locator('#recoveryDialog').waitFor({state:'visible'});
    assert.equal(fixture.draft.pages['index.html'].layout.length,0,'Reload does not silently overwrite server');
    assert.match(await page.locator('#recoverLocal').textContent(),/Заменить/);
    if(process.env.CMS_SCREENSHOT_DIR){fs.mkdirSync(process.env.CMS_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.CMS_SCREENSHOT_DIR,'recovery-desktop.png')});await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(process.env.CMS_SCREENSHOT_DIR,'recovery-mobile.png')});await page.setViewportSize({width:1280,height:720});}
    await page.evaluate(()=>{window.originalStorageSet=Storage.prototype.setItem;Storage.prototype.setItem=function(){throw Error('QuotaExceededError');};});
    await page.locator('#recoverLocal').click();
    await page.waitForFunction(()=>document.querySelector('#status').textContent.includes('Автосохранение остановлено'));
    assert(await page.evaluate(()=>localStorage.length)>0,'Old backup survives failed local write and failed server write');
    await page.evaluate(()=>{Storage.prototype.setItem=window.originalStorageSet;});
    conflict=false;await page.locator('#save').click();await page.waitForFunction(()=>!dirty);
    assert.equal(fixture.draft.pages['index.html'].layout.length,1,'Recover after reload');
    assert.equal(await page.evaluate(()=>localStorage.length),0,'Accepted backup cleared only after save');
   }else{
    const field=page.locator('#fields textarea').first();await field.fill('First autosaved text');
    await page.waitForFunction(()=>!dirty);
    assert.equal(await field.inputValue(),'First autosaved text');
    assert.equal(await field.evaluate(el=>el===document.activeElement),true,'Focus survives autosave');
    delay=900;await field.fill('Slow request');
    await page.waitForFunction(()=>document.querySelector('#status').textContent==='Сохраняю черновик…');
    await field.fill('Newer typing');await page.waitForFunction(()=>!dirty);
    assert.equal(await field.inputValue(),'Newer typing');
    assert(Object.values(fixture.draft.pages['index.html'].fields).includes('Newer typing'));
    conflict=true;await field.fill('Keep local on conflict');
    await page.waitForFunction(()=>document.querySelector('#status').textContent.includes('Автосохранение остановлено'));
    const count=saves;await field.fill('More local text');await page.waitForTimeout(1800);
    assert.equal(saves,count);assert.equal(await page.evaluate(()=>dirty),true);
    assert.equal(await field.inputValue(),'More local text');
    fixture.version++;conflict=false;
    await page.locator('#recoveryButton').click();await page.locator('#recoverLocal').click();
    assert(await page.evaluate(()=>localStorage.length)>0,'Current tab backup survives recovery until acknowledged');
    await page.waitForFunction(()=>!dirty);
    assert(Object.values(fixture.draft.pages['index.html'].fields).includes('More local text'));
    assert.equal(await page.evaluate(()=>localStorage.length),0);
    conflict=true;await field.fill('Discard explicitly');
    await page.waitForFunction(()=>document.querySelector('#status').textContent.includes('Автосохранение остановлено'));
    const beforeDiscard=saves;
    await page.locator('#recoveryButton').click();await page.locator('#recoverServer').click();
    await page.waitForFunction(()=>!dirty);
    assert.equal(await field.inputValue(),'More local text');assert.equal(saves,beforeDiscard,'Choosing server does not write');
   }
   assert.deepEqual(errors,[],entry+' has no runtime exceptions');
   console.log(entry+': browser autosave checks passed ('+saves+' writes)');
   await context.close();
  }
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
