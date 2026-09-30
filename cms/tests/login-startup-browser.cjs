// Slow script/session startup must never permit native GET credential submission.
const {chromium}=require('playwright'),assert=require('node:assert/strict');
(async()=>{const browser=await chromium.launch({headless:true,channel:'chromium'});try{
 for(const [file,script] of [['index.html','visual.js'],['fields.html','editor.js']]){
  const context=await browser.newContext(),page=await context.newPage();let releaseScript,releaseSession;
  const scriptGate=new Promise(r=>releaseScript=r),sessionGate=new Promise(r=>releaseSession=r),requests=[];
  page.on('request',r=>requests.push(r));await page.route('**/'+script,async route=>{await scriptGate;await route.continue();});
  await page.route('**/api.php?**',async route=>{const action=new URL(route.request().url()).searchParams.get('action');if(action==='session'){await sessionGate;await route.fulfill({json:{csrf:'startup-test',authenticated:false}});}else await route.fulfill({status:401,json:{error:'Expected test response'}});});
  await page.goto('http://localhost:8123/cms/'+file,{waitUntil:'commit'});await page.locator('#loginForm').waitFor();assert.equal(await page.locator('#loginForm').getAttribute('method'),'post');assert(await page.locator('#loginForm button').isDisabled());
  await page.locator('#loginForm [name=username]').fill('startup-user');await page.locator('#loginForm [name=password]').fill('startup-test-password');releaseScript();await page.waitForFunction(()=>typeof csrf!=='undefined');assert(await page.locator('#loginForm button').isDisabled());releaseSession();await page.locator('#loginForm button:enabled').waitFor();await page.locator('#loginForm button').click();await page.getByText('Expected test response',{exact:true}).waitFor();
  const login=requests.find(r=>r.url().includes('action=login'));assert(login);assert.equal(login.method(),'POST');assert.equal(login.headers()['x-csrf-token'],'startup-test');assert.equal(JSON.parse(login.postData()).password,'startup-test-password');assert(!requests.some(r=>r.url().includes('startup-test-password')));await context.close();
 }
 console.log('Login startup passed: disabled before scripts/session, POST-only credentials and prepared CSRF in both editors');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
