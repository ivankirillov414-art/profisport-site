// Real PHP/MariaDB and browser; use only the disposable CMS test installation.
const {chromium}=require('playwright'),assert=require('node:assert/strict');
assert.equal(process.env.CMS_TEST,'1');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chromium'});
 try{
  const context=await browser.newContext(),page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('http://localhost:8123/cms/');await page.locator('#loginForm [name=username]').fill('test-owner');await page.locator('#loginForm [name=password]').fill('test-only-password-1234');await page.locator('#loginForm button').click();
  await page.waitForFunction(()=>typeof canvasMain!=='undefined'&&canvasMain&&!building);
  const key='components-'+Date.now();
  await page.evaluate(async key=>{await api('create-site',{key,name:'Shared components test',url:'https://example.test/'});await switchSite(key);},key);
  await page.locator('#addSectionTop').click();await page.getByRole('button',{name:'Добавить · Текст',exact:true}).click();await page.locator('#closeEditor').click();
  await page.locator('#componentsButton').click();await page.getByLabel('Название общего блока',{exact:true}).fill('Общий призыв');await page.getByRole('button',{name:'Создать из секции',exact:true}).click();
  await page.waitForFunction(()=>!dirty&&state.draft.components?.length===1);
  const firstId=await page.evaluate(()=>state.draft.components[0].id);
  assert.equal(await page.evaluate(()=>state.draft.pages['index.html'].layout[0].type),'global');
  await page.locator('#addPage').click();await page.locator('#pageForm [name=title]').fill('Контакты');await page.locator('#pageForm [name=slug]').fill('contact');await page.locator('#pageForm button').click();
  await page.locator('#componentsButton').click();await page.getByRole('button',{name:'Добавить на страницу',exact:true}).click();
  await page.locator('#componentsButton').click();assert.equal(await page.getByRole('button',{name:'Удалить общий блок',exact:true}).isDisabled(),true);
  if(process.env.CMS_SCREENSHOT_DIR){const fs=require('node:fs'),path=require('node:path');fs.mkdirSync(process.env.CMS_SCREENSHOT_DIR,{recursive:true});await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(process.env.CMS_SCREENSHOT_DIR,'components-mobile.png')});await page.setViewportSize({width:1280,height:720});}
  await page.getByRole('button',{name:'Изменить общий блок',exact:true}).click();await page.locator('#componentEditDialog').getByLabel('Заголовок',{exact:true}).fill('Общее предложение');await page.getByRole('button',{name:'Применить ко всем экземплярам',exact:true}).click();
  await page.waitForFunction(()=>!dirty);
  assert.equal(await page.evaluate(()=>CMSComponents.render(state.draft.pages['index.html'].layout[0],state.draft.components).props.title),'Общее предложение');
  assert.equal(await page.evaluate(()=>CMSComponents.render(state.draft.pages['contact.html'].layout[0],state.draft.components).props.title),'Общее предложение');
  // Local edits use the actual canvas recipe editor; they do not change the definition.
  await page.evaluate(()=>updateRecipe(sectionModels()[0],'title','Местное предложение'));
  await page.waitForFunction(()=>!dirty);
  assert.equal(await page.evaluate(()=>state.draft.components[0].block.props.title),'Общее предложение');
  assert.equal(await page.evaluate(()=>state.draft.pages['contact.html'].layout[0].overrides.title),'Местное предложение');
  await page.locator('#componentsButton').click();await page.getByRole('button',{name:'Изменить общий блок',exact:true}).click();await page.locator('#componentEditDialog').getByLabel('Заголовок',{exact:true}).fill('Обновлённое предложение');await page.getByRole('button',{name:'Применить ко всем экземплярам',exact:true}).click();await page.waitForFunction(()=>!dirty);
  await page.reload();await page.waitForFunction(()=>typeof canvasMain!=='undefined'&&canvasMain&&!building);
  const saved=await page.evaluate(()=>({id:state.draft.components[0].id,home:CMSComponents.render(state.draft.pages['index.html'].layout[0],state.draft.components).props.title,contact:CMSComponents.render(state.draft.pages['contact.html'].layout[0],state.draft.components).props.title}));
  assert.deepEqual(saved,{id:firstId,home:'Обновлённое предложение',contact:'Местное предложение'});
  assert.equal((await page.evaluate(()=>api('public'))).published,false,'Draft not published by autosave');
  const popupPromise=context.waitForEvent('page');await page.locator('#preview').click();const preview=await popupPromise;await preview.getByRole('heading',{name:'Обновлённое предложение',exact:true}).waitFor();await preview.close();
  await page.locator('#publish').click();await page.locator('#confirmPublish').click();await page.locator('#publishDialog').waitFor({state:'hidden'});
  const publicData=await page.evaluate(()=>api('public'));assert.equal(publicData.pages['index.html'].layout[0].type,'text');assert.equal(publicData.pages['contact.html'].layout[0].props.title,'Местное предложение');assert(!JSON.stringify(publicData).includes('Общий призыв'));
  const live=await context.newPage();live.on('pageerror',e=>errors.push(e.message));await live.goto('http://localhost:8123/cms/site.php?site='+key+'&page=index.html');await live.getByRole('heading',{name:'Обновлённое предложение',exact:true}).waitFor();
  await live.goto('http://localhost:8123/cms/site.php?site='+key+'&page=contact.html');await live.getByRole('heading',{name:'Местное предложение',exact:true}).waitFor();
  // Reset one exception, then detach that instance; its appearance stays independent.
  await page.getByRole('button',{name:'Контакты',exact:true}).click();await page.evaluate(()=>componentInstance(sectionModels()[0]));await page.getByRole('button',{name:'Сбросить местные изменения',exact:true}).click();await page.waitForFunction(()=>!dirty);
  assert.equal(await page.evaluate(()=>CMSComponents.render(state.draft.pages['contact.html'].layout[0],state.draft.components).props.title),'Обновлённое предложение');
  await page.evaluate(()=>componentInstance(sectionModels()[0]));await page.getByRole('button',{name:'Отвязать экземпляр',exact:true}).click();await page.waitForFunction(()=>!dirty);
  assert.equal(await page.evaluate(()=>state.draft.pages['contact.html'].layout[0].type),'text');
  await page.locator('#componentsButton').click();await page.getByRole('button',{name:'Изменить общий блок',exact:true}).click();await page.locator('#componentEditDialog').getByLabel('Заголовок',{exact:true}).fill('Только связанные экземпляры');await page.getByRole('button',{name:'Применить ко всем экземплярам',exact:true}).click();await page.waitForFunction(()=>!dirty);
  assert.equal(await page.evaluate(()=>state.draft.pages['contact.html'].layout[0].props.title),'Обновлённое предложение');
  // A history restore recovers definitions and references together, without publishing.
  const history=await page.evaluate(()=>api('history'));const publishedVersion=history.items.find(item=>item.action==='publish');
  assert(publishedVersion);await page.evaluate(id=>mutation('restore',{id}),publishedVersion.id);
  assert.equal(await page.evaluate(()=>state.draft.pages['contact.html'].layout[0].type),'global');
  assert.equal(await page.evaluate(()=>state.draft.pages['contact.html'].layout[0].overrides.title),'Местное предложение');
  assert.equal((await page.evaluate(()=>api('public'))).pages['index.html'].layout[0].props.title,'Обновлённое предложение');
  assert.deepEqual(errors,[]);
  console.log('Shared components browser passed: UI creation, two pages, local exceptions, reload, publication, public rendering, reset, detach and history restore');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
