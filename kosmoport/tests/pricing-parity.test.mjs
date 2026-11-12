import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';
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
 const html=run('../public/index.php');
 const match=html.match(/window\.KOSMOPORT_CONFIG=(.*?);<\/script>/s);assert.ok(match,'Client configuration is rendered');
 const config=JSON.parse(match[1]);
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
