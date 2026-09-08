function readStoredArray(key){try{const value=JSON.parse(localStorage.getItem(key)||'[]');return Array.isArray(value)?value.filter(x=>typeof x==='string'||typeof x==='number'):[]}catch(e){return[]}}
function pathOf(p){return p.category_path||p.breadcrumbs||[]}
function imageUrl(u){
  if(!u)return'';
  u=String(u);
  if(u.startsWith('/import/'))return`api/product-image.php?p=${encodeURIComponent(u.slice(8))}`;
  if(u.startsWith('import/'))return`api/product-image.php?p=${encodeURIComponent(u.slice(7))}`;
  return u;
}
function fallbackImageUrl(name,cat){
  return`api/product-fallback-image.php?name=${encodeURIComponent(name||'')}&cat=${encodeURIComponent(cat||'')}`;
}
function normalizeProduct(p,i){
  const path=pathOf(p),
    price=Number(p.price_rub??String(p.price||0).replace(/[^0-9]/g,''))||0,
    rawOld=Number(p.old_price_rub??String(p.old_price||0).replace(/[^0-9]/g,''))||0,
    oldPrice=rawOld>price?rawOld:0,
    qty=p.stock_qty===null||p.stock_qty===undefined||p.stock_qty===''?null:Number(p.stock_qty),
    stockCode=(p.availability==='in_stock'||p.stock_status==='in_stock')?'in':(p.availability==='out_of_stock'||p.stock_status==='out_of_stock')?'out':'unknown',
    name=p.title||p.name||'Товар',
    specs=p.specs||{},
    description=p.description||'',
    pathText=Array.isArray(path)?path.join(' '):String(path||''),
    cat=Array.isArray(path)?(path[path.length-1]||path[0]||'Каталог'):'Каталог',
    images=(Array.isArray(p.images)?p.images:[]).map(imageUrl).filter(Boolean),
    main=imageUrl(p.image||p.main_image||''),
    fallback=fallbackImageUrl(name,cat),
    baseImages=images.length?images:(main?[main]:[]),
    finalImages=[...new Set([...baseImages,fallback])],
    stockText=stockCode==='in'?(Number.isFinite(qty)&&qty>0?`В наличии: ${qty} шт.`:'В наличии'):stockCode==='out'?'Нет в наличии':'Уточняйте наличие';
  return{
    id:p.id??p.url??p.sku??`real-${i}`,
    sourceId:p.source_id||'',
    name,
    price,
    oldPrice,
    cat,
    path:Array.isArray(path)?path:[],
    pathText,
    searchText:[name,p.sku||'',p.brand||'',p.model||'',pathText,description,Object.entries(specs).flat().join(' ')].join(' ').toLowerCase(),
    icon:'🏷️',
    image:finalImages[0]||'',
    images:finalImages,
    url:p.url||`product.html?id=${encodeURIComponent(p.id??'')}`,
    stock:stockText,
    stockCode,
    stockQty:Number.isFinite(qty)?qty:null,
    specs,
    description,
    sku:p.sku||'',
    brand:p.brand||'',
    model:p.model||''
  }
}

// Bound both the request and response body so a stalled server cannot block startup.
async function catalogRequest(url,options={},parse=r=>r.json()){
  const controller=new AbortController();
  let timer;
  try{
    return await Promise.race([
      (async()=>{
        const response=await fetch(url,{...options,signal:controller.signal});
        if(!response.ok)throw Error(`catalog HTTP ${response.status}`);
        return parse(response);
      })(),
      new Promise((_,reject)=>{timer=setTimeout(()=>{
        reject(Error('catalog request timed out'));
        controller.abort();
      },15000)})
    ]);
  }finally{clearTimeout(timer)}
}

let catalogPromise,initialCatalogPromise;
async function parseCatalogResponse(r){
  if(!r.ok)throw Error(`live catalog HTTP ${r.status}`);
  const j=await r.json();
  if(!j.ok||!Array.isArray(j.items))throw Error('live catalog invalid');
  return j;
}
async function loadInitialCatalog(){
  if(initialCatalogPromise)return initialCatalogPromise;
  initialCatalogPromise=(async()=>{
    try{
      const pre=window.__psFirstCatalogPromise;
      const j=pre?await pre:await parseCatalogResponse(await fetch('api/catalog.php?limit=24&count=1&v=imgfix1',{cache:'no-cache'}));
      return{items:j.items.map(normalizeProduct),total:Number(j.total??j.count??0),imageHealth:j.image_health||null}
    }catch(e){
      console.warn('Fast first catalog page unavailable',e);
      return{items:[],total:0,imageHealth:null}
    }
  })();
  return initialCatalogPromise
}
async function loadStaticCatalog(){
  const manifest=await catalogRequest('data/manifest.json?v=8552',{cache:'default'});
  if(!Array.isArray(manifest.parts)||!manifest.parts.length)throw Error('empty catalog manifest');
  const key=`ps-catalog-${manifest.products}-${manifest.parts.length}-v4`;
  try{
    const cached=sessionStorage.getItem(key);
    if(cached){
      const rows=JSON.parse(cached);
      if(rows.length===manifest.products)return rows.map(normalizeProduct)
    }
  }catch(e){}
  const batches=await Promise.all(manifest.parts.map(async name=>{
    return catalogRequest(`data/${name}?v=8552`,{cache:'force-cache'})
  }));
  const rows=batches.flat();
  if(manifest.products&&rows.length!==manifest.products)throw Error(`catalog incomplete: ${rows.length}/${manifest.products}`);
  try{sessionStorage.setItem(key,JSON.stringify(rows))}catch(e){}
  return rows.map(normalizeProduct)
}
function startLiveCatalog(){
  if(catalogPromise)return catalogPromise;
  catalogPromise=(async()=>{
    try{
      const j=await catalogRequest('api/catalog.php?v=imgfix1',{cache:'no-cache'},parseCatalogResponse);
      window.__psImageHealth=j.image_health||null;
      return j.items.map(normalizeProduct)
    }catch(e){
      console.warn('Live 1C catalog unavailable, static fallback is used',e);
      return loadStaticCatalog()
    }
  })();
  return catalogPromise
}
async function loadRealCatalog(){
  try{return await startLiveCatalog()}
  catch(e){catalogPromise=null;throw e}
}

async function loadProduct(id){
  if(/^[1-9][0-9]*$/.test(String(id))){
    const j=await catalogRequest('api/catalog.php?id='+encodeURIComponent(id),{cache:'no-store'},parseCatalogResponse);
    return j.items.length?normalizeProduct(j.items[0],0):null;
  }
  return (await loadRealCatalog()).find(p=>String(p.id)===String(id))||null;
}
