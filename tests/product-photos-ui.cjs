const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 const fixture=JSON.parse(fs.readFileSync('/tmp/photo-test-fixtures.json','utf8'));
 const browser=await chromium.launch({headless:true});
 try{
  const context=await browser.newContext({viewport:{width:1280,height:900}});
  await context.addCookies([{name:'PROFISPORT_ADMIN',value:fixture.cookie.split('=')[1],url:'http://127.0.0.1:8080',httpOnly:true}]);
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const match=fs.readFileSync('.htaccess','utf8').match(/Header always set Content-Security-Policy "([^"]+)"/);
  assert(match,'Production CSP must be present');
  // Local HTTP fixture has no TLS; enforce all other production CSP directives.
  const csp=match[1].replace(/;\s*upgrade-insecure-requests/,'');
  await page.route('**/admin/photos.php',async route=>{const response=await route.fetch();await route.fulfill({response,headers:{...response.headers(),'content-security-policy':csp}});});
  await page.goto('http://127.0.0.1:8080/admin/photos.php');
  await page.locator('#photoSearch').fill('PHOTO-A');await page.locator('#photoSearchForm button').click();
  const result=page.locator('[data-product="'+fixture.a+'"]', {hasText:'Редактировать фото'});await result.click();
  await page.locator('#editorMode').filter({hasText:'Ручная галерея'}).waitFor();
  const chooser=page.waitForEvent('filechooser');await page.locator('#addPhoto').click();await (await chooser).setFiles('import/photo-test/upload.png');
  await page.locator('#pendingPhoto:visible').waitFor();assert((await page.locator('#pendingImage').getAttribute('src')).startsWith('blob:'));
  await page.waitForFunction(()=>document.getElementById('pendingImage').naturalWidth>0);
  await page.locator('#savePhoto').click();await page.locator('#editorMessage').filter({hasText:'Фотографии сохранены'}).waitFor();
  assert.equal(await page.locator('.photo-item').count(),1);
  assert.equal(await page.locator('#pendingPhoto').isVisible(),false);
  assert.equal(await page.locator('[data-photo="primary"]').isDisabled(),true);
  await page.screenshot({path:'/tmp/manual-photos-desktop.png',fullPage:true});
  await page.setViewportSize({width:390,height:844});
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Mobile horizontal overflow');
  await page.screenshot({path:'/tmp/manual-photos-mobile.png',fullPage:true});
  assert.deepEqual(errors,[]);
  console.log('PASS: browser search, modal, file chooser, CSP-compatible local preview, real save, primary status and mobile layout');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
