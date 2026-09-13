const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
// This live product showed ski wax through parser fallback on 2026-09-13.
const parserRow=JSON.parse(read('data/catalog-003.json')).find(p=>p.title==='Лыжи гоночные BRADOS PRO SKATE AIR H-2 (NIS)');
assert(parserRow?.images?.length>1,'Keep the contaminated parser fixture');

function runtime(paged=false){
  const ctx=vm.createContext({console:{error(){}},AbortController,setTimeout,clearTimeout,URLSearchParams,window:{},fetch:async url=>{
    if(url.startsWith('api/catalog.php'))throw Error('live metadata unavailable');
    const data=url==='data/manifest.json'?{parts:['fixture.json']}:[parserRow];
    return{ok:true,json:async()=>data};
  }});
  vm.runInContext(read('catalog-loader.js'),ctx);
  if(paged)vm.runInContext(read('catalog-live-paged.js'),ctx);
  return ctx;
}

(async()=>{
  const ctx=runtime();
  const missing=ctx.normalizeProduct({id:859,title:parserRow.title,price_rub:13850,images:[],image_source_missing:true,fallback_image:'api/product-fallback-image.php?name=unverified'},0);
  assert.equal(missing.image,'');
  assert.equal(missing.images.length,0,'Missing native photo must not become wax/pads');
  const native=ctx.normalizeProduct({id:184,title:'Лыжи гоночные PRO BRADOS SKATE',images:['/import/images/native.jpg','/import/images/native-detail.jpg']},0);
  assert.equal(native.images.length,2);
  assert.equal(native.image,'api/product-image.php?p=images%2Fnative.jpg');
  for(const paged of [false,true]){
    const c=runtime(paged);
    const rows=await(paged?c.window.loadRealCatalog():c.loadRealCatalog());
    assert.equal(rows.length,1,'Keep the product metadata when live loading fails');
    assert.equal(rows[0].images.length,1,'Only the DB resolver may supply a photo');
    assert(rows[0].image.startsWith('api/product-db-image.php?'));
    assert(!rows[0].images.some(url=>parserRow.images.includes(url)),'Never expose parser recommendations as product photos');
    assert.equal(c.window.CATALOG_PHOTO_SOURCE,'mysql-resolver-only');
  }
  console.log('Photo ownership: missing native image stays empty; native gallery preserved; both emergency metadata loaders reject parser photos.');
})().catch(error=>{console.error(error);process.exitCode=1});
