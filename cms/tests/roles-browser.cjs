// Actual PHP/MariaDB installation, prepared by integration.php + http_contract.py.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
assert.equal(process.env.CMS_TEST,'1','Only a disposable local CMS');
const url='http://localhost:8123/cms/';
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chromium'}),errors=[];
 try{
  const ownerContext=await browser.newContext(),owner=await ownerContext.newPage();owner.on('pageerror',e=>errors.push(e.message));
  async function login(page,user,password,target=url){await page.goto(target);await page.locator('#loginForm input[name=username]').fill(user);await page.locator('#loginForm input[name=password]').fill(password);await page.locator('#loginForm button').click();await page.locator('#app').waitFor({state:'visible'});await page.waitForFunction(()=>document.querySelector('#siteSelect')?document.querySelector('#siteSelect').value===state.site.key&&typeof canvasMain!=='undefined'&&!!canvasMain&&!building:document.querySelector('#fields').childElementCount>0);}
  await login(owner,'test-owner','test-only-password-1234');
  await owner.locator('#usersButton').click();const form=owner.locator('#userAccessForm');await form.waitFor();
  const stamp=Date.now(),username='browser-member-'+stamp,password='browser-test-password-1234',pageTitle='Страница редактора '+stamp;
  await form.locator('[name=username]').fill(username);await form.locator('[name=password]').fill(password);
  await form.locator('[data-site="http-site"]').selectOption('editor');await form.locator('button[type=submit]').click();
  await owner.getByText('Доступ сохранён.',{exact:true}).waitFor();
  const memberContext=await browser.newContext(),member=await memberContext.newPage();member.on('pageerror',e=>errors.push(e.message));
  await login(member,username,password);
  assert.equal(await member.locator('#siteSelect option').count(),1);
  assert.equal(await member.locator('#siteSelect').inputValue(),'http-site');
  assert.equal(await member.locator('#publish').isVisible(),false);
  assert.equal(await member.locator('#usersButton').isVisible(),false);
  assert.equal(await member.locator('#save').isVisible(),true);
  await member.locator('#addPage').click();await member.locator('#pageForm [name=title]').fill(pageTitle);await member.locator('#pageForm [name=slug]').fill('member-'+stamp);await member.locator('#pageForm button').click();
  await member.waitForFunction(title=>!dirty&&Object.values(state.draft.pages).some(p=>p.title===title),pageTitle);
  await member.reload();await member.getByRole('button',{name:pageTitle,exact:true}).waitFor();
  // Change from editor to viewer using the owner UI; old session stops working.
  await owner.locator('#userAccessForm [data-site="http-site"]').selectOption('viewer');await owner.locator('#userAccessForm button[type=submit]').click();await owner.getByText('Доступ сохранён.',{exact:true}).waitFor();
  await member.reload();await member.locator('#loginStatus').filter({hasText:'Войдите снова'}).waitFor();
  await login(member,username,password);
  assert.equal(await member.locator('#save').isVisible(),false);
  assert.equal(await member.locator('#addPage').isVisible(),false);
  assert.equal(await member.locator('#openLibrary').isVisible(),false);
  assert.equal(await member.locator('#passwordButton').isVisible(),true);
  assert.equal(await member.locator('#canvas').evaluate(n=>n.inert),true);
  await member.goto(url+'?site=profisport');await member.locator('#noSites').getByRole('link',{name:'HTTP Site',exact:true}).click();await member.waitForFunction(()=>document.querySelector('#siteSelect').value==='http-site');assert.equal(await member.locator('#siteSelect').inputValue(),'http-site');
  // Promotion is also read from fresh server state and enables publication.
  await owner.locator('#userAccessForm [data-site="http-site"]').selectOption('publisher');await owner.locator('#userAccessForm button[type=submit]').click();await owner.getByText('Доступ сохранён.',{exact:true}).waitFor();
  await login(member,username,password);assert.equal(await member.locator('#publish').isVisible(),true);
  await member.locator('#passwordButton').click();const passwordForm=member.locator('.access-dialog form');
  await passwordForm.locator('[name=current_password]').fill(password);await passwordForm.locator('[name=password]').fill(password+'-new');await passwordForm.locator('[name=confirm]').fill(password+'-new');await passwordForm.locator('button[type=submit]').click();
  await member.getByText('Пароль изменён. Остальные сеансы завершены.',{exact:true}).waitFor();
  if(process.env.CMS_SCREENSHOT_DIR){const fs=require('node:fs'),path=require('node:path');fs.mkdirSync(process.env.CMS_SCREENSHOT_DIR,{recursive:true});await member.setViewportSize({width:390,height:844});await member.screenshot({path:path.join(process.env.CMS_SCREENSHOT_DIR,'role-mobile.png')});}
  await owner.locator('#userAccessForm [data-site="profisport"]').selectOption('viewer');await owner.locator('#userAccessForm button[type=submit]').click();await owner.getByText('Доступ сохранён.',{exact:true}).waitFor();
  await login(member,username,password+'-new',url+'fields.html?site=profisport');
  assert.equal(await member.locator('#fields').evaluate(n=>n.inert),true);assert.equal(await member.locator('#save').isVisible(),false);assert.equal(await member.locator('#publish').isVisible(),false);
  await member.goto(url+'fields.html?site=http-site');await member.locator('#fields').getByText('У этого сайта нет связанных текстовых полей. Страницы редактируются в визуальном редакторе.',{exact:true}).waitFor();assert.equal(await member.locator('#pages a').getAttribute('href'),'index.html?site=http-site');
  await owner.locator('#userAccessForm [data-site="profisport"]').selectOption('');
  await owner.locator('#userAccessForm [data-site="http-site"]').selectOption('');await owner.locator('#userAccessForm button[type=submit]').click();await owner.getByText('Доступ сохранён.',{exact:true}).waitFor();
  await member.goto(url);await member.locator('#loginForm input[name=username]').fill(username);await member.locator('#loginForm input[name=password]').fill(password+'-new');await member.locator('#loginForm button').click();await member.locator('#noSites').waitFor({state:'visible'});
  assert.equal(await member.locator('#app').isVisible(),false);
  await member.locator('#noSites').getByRole('button',{name:'Выйти',exact:true}).click();await member.locator('#loginForm').waitFor({state:'visible'});
  assert.deepEqual(errors,[]);
  console.log('Roles browser passed: owner creates member, editor saves, role changes revoke sessions, viewer controls, publisher access and password change');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
