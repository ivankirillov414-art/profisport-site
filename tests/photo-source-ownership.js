const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
// This live product showed ski wax through parser fallback on 2026-09-13.
const parserRow={title:'Лыжи гоночные BRADOS PRO SKATE AIR H-2 (NIS)',price_rub:13850,availability:'in_stock',category_path:['Беговые лыжи','Лыжи','Коньковые'],images:['https://example.test/unrelated-wax.jpg','https://example.test/recommended-product.jpg']};

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
  const reordered=ctx.normalizeProduct({id:184,title:'Лыжи',main_image:'/import/images/native.jpg',image:'/import/images/old.jpg',images:['/import/images/old.jpg','/import/images/native.jpg','/import/images/native-detail.jpg']},0);
  assert.equal(reordered.image,'api/product-image.php?p=images%2Fnative.jpg','Explicit main photo must win over stale gallery order');
  assert.equal(reordered.images.length,3,'Keep gallery entries without duplicating the main photo');
  const apiMain=ctx.normalizeProduct({id:184,title:'Лыжи',image:'/import/images/native.jpg',images:['/import/images/old.jpg']},0);
  assert.equal(apiMain.image,'api/product-image.php?p=images%2Fnative.jpg','API-selected image must win over gallery order');
  for(const paged of [false,true]){
    const c=runtime(paged);
    await assert.rejects(paged?c.window.loadRealCatalog():c.loadRealCatalog(),/live metadata unavailable|live catalog/);
    assert.notEqual(c.window.CATALOG_SOURCE,'static-db-photo-resolver','Archived parser data must never become storefront metadata');
  }
  console.log('Photo ownership: missing native image stays empty; native gallery preserved; live failure never falls back to parser metadata.');
})().catch(error=>{console.error(error);process.exitCode=1});
