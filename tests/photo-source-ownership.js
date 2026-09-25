const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');

function runtime(){
  const ctx=vm.createContext({console:{error(){}},AbortController,setTimeout,clearTimeout,URLSearchParams,window:{}});
  vm.runInContext(read('catalog-loader.js'),ctx);
  return ctx;
}

(async()=>{
  const ctx=runtime();
  const missing=ctx.normalizeProduct({id:859,title:'Товар без фотографии',price_rub:13850,images:[],image_source_missing:true,fallback_image:'api/product-fallback-image.php?name=unverified'},0);
  assert.equal(missing.image,'');
  assert.equal(missing.images.length,0,'A product without a physical 1C/DB photo must stay without a photo');

  const native=ctx.normalizeProduct({id:184,title:'Лыжи',images:['/import/images/native.jpg','/import/images/native-detail.jpg']},0);
  assert.equal(native.images.length,2);
  assert.equal(native.image,'api/product-image.php?p=images%2Fnative.jpg');

  const reordered=ctx.normalizeProduct({id:184,title:'Лыжи',main_image:'/import/images/native.jpg',image:'/import/images/old.jpg',images:['/import/images/old.jpg','/import/images/native.jpg','/import/images/native-detail.jpg']},0);
  assert.equal(reordered.image,'api/product-image.php?p=images%2Fnative.jpg','Explicit DB main photo must win over gallery order');
  assert.equal(reordered.images.length,3,'Keep verified DB gallery entries without duplicating the main photo');

  const loader=read('catalog-loader.js'),paged=read('catalog-live-paged.js');
  assert(!loader.includes('data/manifest.json')&&!paged.includes('data/manifest.json'),'Storefront must not depend on retired parser data');
  assert(!loader.includes('product-fallback-image.php'),'Unverified photo fallback must not be used by catalog loader');
  assert(!paged.includes('loadStaticCatalogFallback'),'Paged catalog must not have a static/parser fallback');

  console.log('Photo ownership: missing 1C/DB photo stays empty; verified DB gallery is preserved; parser fallback is absent.');
})().catch(error=>{console.error(error);process.exitCode=1});
