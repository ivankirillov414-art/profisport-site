import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
const require=createRequire(import.meta.url);
const pricing=require('../public/site/pricing.js');
const php=process.env.KCRM_PHP || 'php';
function run(relative,args=[]){const r=spawnSync(php,['-n',fileURLToPath(new URL(relative,import.meta.url)),...args],{encoding:'utf8',timeout:15000,env:{...process.env,KCRM_CONFIG:''}});assert.equal(r.error,undefined,r.error?.message);assert.equal(r.status,0,r.stderr||r.stdout);return r.stdout;}
test('PHP server and browser helper return identical quotes for every price fixture',()=>{
 const matrix=JSON.parse(run('./pricing.php',['--matrix']));
 matrix.cases.forEach((input,index)=>assert.deepEqual(pricing.quote(matrix.catalog,matrix.event,input),matrix.quotes[index],JSON.stringify(input)));
 matrix.basket_cases.forEach((input,index)=>assert.deepEqual(pricing.basket(matrix.catalog,matrix.event,input),matrix.baskets[index],JSON.stringify(input)));
});
test('Rendered public page carries the canonical catalog and separate child/adult controls while payment remains closed',()=>{
 const html=run('./website-render-fixture.php');
 const match=html.match(/window\.KOSMOPORT_CONFIG=(.*?);<\/script>/s);assert.ok(match,'Client configuration is rendered');
 const config=JSON.parse(match[1]);
 assert.equal(config.openingDate,'2026-11-25');assert.equal(config.closingDate,'2027-01-18');
 assert.equal(config.dates,'25 ноября 2026 — 18 января 2027');
 const schema=JSON.parse(html.match(/<script\b[^>]*type=["']application\/ld\+json["'][^>]*>(.*?)<\/script>/s)[1]);
 assert.equal(schema.startDate,config.openingDate);assert.equal(schema.endDate,config.closingDate);
 const dateInput=html.match(/<input\b[^>]*id="visit-date"[^>]*>/)[0];
 assert.ok(dateInput.includes('min="2026-11-25"') && dateInput.includes('value="2026-11-25"') && dateInput.includes('max="2027-01-18"'));
 assert.equal(config.priceCatalog.admission.weekday.child,790);assert.equal(config.priceCatalog.admission.weekday.adult,890);
 assert.equal(config.priceCatalog.admission.weekend.child,890);assert.equal(config.priceCatalog.admission.weekend.adult,990);
 assert.equal(config.salesOpen,false);assert.equal(config.checkoutUrl,'');
 assert.ok(html.includes('id="children-quantity"') && html.includes('id="adults-quantity"'));
 assert.ok(html.indexOf('src="site/pricing.js')<html.indexOf('src="site/script.js'));
 assert.ok(html.includes('ДЕТСКИЙ<br/>БИЛЕТ') && html.includes('ВЗРОСЛЫЙ<br/>БИЛЕТ'));
 assert.ok(html.includes('Включение входного билета в наборы и правила выбора мастер-классов уточняются.'));
 assert.ok(!html.includes('data-ticket-count="3"'),'No invented family-discount calculator');
 assert.equal((html.match(/data-planner-panel="[1-4]"/g)||[]).length,4,'Four stages');
 assert.equal((html.match(/class="selection-input"[^>]+value="0"/g)||[]).length,7,'All seven optional selections start empty');
 assert.ok(html.includes('id="planner-cart"') && html.includes('id="planner-mobile-total"')===false && html.includes('id="mobile-planner-total"'),'Wide cart and compact mobile summary');
});
test('calendar downloads use the rendered event dates and reject dates outside the updated period',async()=>{
 const html=run('./website-render-fixture.php');
 const config=JSON.parse(html.match(/window\.KOSMOPORT_CONFIG=(.*?);<\/script>/s)[1]);
 const nodes=new Map(),downloads=[],blobs=new Map();
 const node=(selector)=>{
  if(nodes.has(selector))return nodes.get(selector);
  const value={value:selector==='#visit-date'?config.openingDate:'1',dataset:{},style:{setProperty(){}},classList:{add(){},remove(){},toggle(){}},listeners:{},
   addEventListener(name,fn){this.listeners[name]=fn;},setAttribute(){},getAttribute(){return null;},querySelectorAll(){return [];},querySelector:child=>node(child),closest:selector=>selector==='form'?node('form'):null,
   setCustomValidity(message){this.validationMessage=message;},checkValidity(){return !this.validationMessage;},reportValidity(){},focus(){},appendChild(){},append(){},replaceChildren(){},remove(){},click(){if(this.download)downloads.push({name:this.download,blob:blobs.get(this.href)});},
   get valueAsNumber(){return Number(this.value);}};
  nodes.set(selector,value);return value;
 };
 const eventCalendar=node('event-calendar'),visitCalendar=node('visit-calendar');
 visitCalendar.closest=()=>node('#ticket-dialog');
 const document={addEventListener(){},querySelector:node,getElementById:id=>node('#'+id),querySelectorAll:selector=>selector==='.calendar-button'?[eventCalendar,visitCalendar]:[],
  createElement:tag=>node('download-'+tag),body:node('body'),documentElement:node('html')};
 class DownloadURL extends URL{static createObjectURL(blob){const id='blob:'+blobs.size;blobs.set(id,blob);return id;}static revokeObjectURL(){}}
 const window={KOSMOPORT_CONFIG:config,KosmoportPricing:pricing,scrollY:0,innerHeight:900,addEventListener(){},dispatchEvent(){},setTimeout(){},matchMedia(){return {matches:false,addEventListener(){}};}};
 vm.runInNewContext(readFileSync(new URL('../public/site/script.js',import.meta.url),'utf8'),{window,document,URL:DownloadURL,Blob,TextEncoder,Intl,Date,Math,CustomEvent:class{},setTimeout(){},setInterval(){}});
 eventCalendar.listeners.click();
 assert.equal(downloads[0].name,'kosmoport-2026-11-25.ics');
 let ics=(await downloads[0].blob.text()).replace(/\r\n /g,'');
 assert.ok(ics.includes('DTSTART;VALUE=DATE:20261125\r\n') && ics.includes('DTEND;VALUE=DATE:20261126\r\n'));
 assert.ok(ics.includes('25 ноября 2026 — 18 января 2027'));
 node('#visit-date').value='2027-01-18';visitCalendar.listeners.click();
 ics=(await downloads[1].blob.text()).replace(/\r\n /g,'');
 assert.ok(ics.includes('DTSTART;VALUE=DATE:20270118\r\n') && ics.includes('DTEND;VALUE=DATE:20270119\r\n'));
 for(const date of ['2026-11-24','2027-01-19']){node('#visit-date').value=date;visitCalendar.listeners.click();}
 assert.equal(downloads.length,2,'Dates outside the new event period must not download an ICS');
});
