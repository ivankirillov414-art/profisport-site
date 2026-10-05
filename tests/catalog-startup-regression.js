const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../catalog-live-paged.js'),'utf8');
const html=fs.readFileSync(path.join(__dirname,'../index.html'),'utf8');
const app=fs.readFileSync(path.join(__dirname,'../app.js'),'utf8');
const now=Date.now();
function database(record){return{open(){const request={};queueMicrotask(()=>{request.result={close(){},objectStoreNames:{contains:()=>true},transaction(){return{objectStore(){return{get(){const get={};queueMicrotask(()=>{get.result=record;get.onsuccess()});return get},put(value){record=value}}}}}};request.onsuccess()});return request}}}
function runtime({cached,hold=false}={}){
 const calls=[];let release,preview;
 const gate=hold?new Promise(r=>release=r):Promise.resolve();
 const context={console,URLSearchParams,setTimeout,clearTimeout,indexedDB:database(cached),window:{},parseCatalogResponse:()=>{},isPurchasableCatalogRow:()=>true,normalizeProduct:p=>p,
 catalogRequest:async(url)=>{calls.push(url);const params=new URL(url,'https://example.test').searchParams;const offset=Number(params.get('offset')),limit=Number(params.get('limit'));assert(url.startsWith('api/catalog.php?'),'Live test must not fall back to parser data');if(offset>0)await gate;return{ok:true,total:1025,items:Array.from({length:Math.min(limit,1025-offset)},(_,i)=>({id:offset+i+1,name:'Product '+(offset+i+1)}))}}};
 vm.runInNewContext(source,context);return{context,calls,release,previewPromise:new Promise(r=>preview=r),onPreview:items=>preview(items)};
}
(async()=>{
 const live=runtime({hold:true});let completed=false;
 const complete=live.context.window.loadRealCatalog(live.onPreview).then(rows=>{completed=true;return rows});
 const preview=await live.previewPromise;assert.equal(preview.length,24);assert.equal(completed,false);
 assert(live.calls[0].includes('limit=24&offset=0&count=1'));
 live.release();const rows=await complete;assert.equal(rows.length,1025);assert.equal(new Set(rows.map(p=>p.id)).size,1025);assert.equal(rows.at(-1).id,1025);
 assert(live.calls.some(x=>x.includes('offset=24')));assert(live.calls.some(x=>x.includes('offset=524')));assert(live.calls.some(x=>x.includes('offset=1024')));
 const cached=runtime({cached:{savedAt:now,items:[{id:7}]}});assert.equal((await cached.context.window.loadRealCatalog())[0].id,7);assert.equal(cached.calls.length,0);
 const stale=runtime({cached:{savedAt:now-120001,items:[{id:7}]}});assert.equal((await stale.context.window.loadRealCatalog()).length,1025);assert(stale.calls.length>0);
 const noCache=runtime();delete noCache.context.indexedDB;assert.equal((await noCache.context.window.loadRealCatalog()).length,1025);
 for(const category of ['bicycle','skiing','cycling','accessories','fitness','tourism'])assert(html.includes(`data-department="${category}"`),'Categories must exist before JavaScript finishes loading');
 assert(!html.includes('Загрузка категорий…'));
 assert(app.includes('if(!catalogComplete)'), 'Early filters must wait for complete results');
 console.log('Catalog startup: first 24 rows before remaining pages, complete IDs, fresh/expired/unavailable cache, immediate categories passed.');
})().catch(e=>{console.error(e);process.exitCode=1});

// Repeated completed repair ticks must not invalidate an unchanged live catalogue.
(async()=>{
 const loader=fs.readFileSync(path.join(__dirname,'../catalog-loader.js'),'utf8');
 let calls=0,events=0;
 let repair={state:'done',snapshot:'current',created:2,restored:3};
 const stored=new Map();
 const context={console,setTimeout:fn=>{queueMicrotask(fn);return 1},setInterval:()=>{},
  location:{protocol:'https:'},document:{hidden:false,readyState:'loading',addEventListener(){},documentElement:{dataset:{}}},
  window:{dispatchEvent(){events++}},CustomEvent:function(){},
  localStorage:{getItem:k=>stored.get(k)||null,setItem:(k,v)=>stored.set(k,v)},
  catalogRequest:async()=>{calls++;return {source_options:repair}}};
 vm.createContext(context);
 vm.runInContext('let catalogPromise,initialCatalogPromise;'+loader.slice(loader.indexOf('function scheduleCatalogMaintenance(')),context);
 const tick=()=>{stored.delete('profisport_migration_tick_last_check_options_v2');return context.triggerScheduledHostingMigration()};
 await tick();await tick();assert.equal(calls,2);assert.equal(events,1);
 repair={...repair,snapshot:'next'};await tick();assert.equal(events,2);
 context.document.hidden=true;await tick();assert.equal(calls,3,'Hidden tabs do not start maintenance');
 console.log('Catalogue maintenance: unchanged repairs retain cache, changed export invalidates it, hidden tabs stay idle.');
})().catch(e=>{console.error(e);process.exitCode=1});
