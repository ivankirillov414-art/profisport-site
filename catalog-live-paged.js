(()=>{
  const LIVE_PAGE_SIZE=500;
  const LIVE_CONCURRENCY=2;
  const LIVE_RETRIES=4;
  let pagedCatalogPromise=null;

  const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));

  async function fetchLiveRange(offset,limit,includeCount=false){
    let lastError;
    for(let attempt=1;attempt<=LIVE_RETRIES;attempt++){
      try{
        const suffix=includeCount?'&count=1':'';
        return await catalogRequest(`api/catalog.php?limit=${limit}&offset=${offset}${suffix}&v=imgtruth4`,{},parseCatalogResponse);
      }catch(error){
        lastError=error;
        if(attempt<LIVE_RETRIES)await wait(250*attempt);
      }
    }

    // InfinityFree can occasionally choke on a larger response. Split only the
    // failed range instead of immediately throwing the storefront onto static data.
    if(limit>50){
      const left=Math.floor(limit/2);
      const right=limit-left;
      const a=await fetchLiveRange(offset,left,false);
      const b=await fetchLiveRange(offset+left,right,false);
      return{ok:true,items:[...(a.items||[]),...(b.items||[])],total:null};
    }
    throw lastError||new Error('live catalog range unavailable');
  }

  async function loadPagedLiveCatalog(){
    const first=await fetchLiveRange(0,LIVE_PAGE_SIZE,true);
    const firstItems=Array.isArray(first.items)?first.items:[];
    const total=Number(first.total);
    if(!firstItems.length)throw new Error('live catalog first page empty');
    if(!Number.isFinite(total)||total<firstItems.length)throw new Error('live catalog total unavailable');

    const pages=[firstItems];
    if(total>firstItems.length){
      const offsets=[];
      for(let offset=LIVE_PAGE_SIZE;offset<total;offset+=LIVE_PAGE_SIZE)offsets.push(offset);
      for(let i=0;i<offsets.length;i+=LIVE_CONCURRENCY){
        const batch=offsets.slice(i,i+LIVE_CONCURRENCY);
        const results=await Promise.all(batch.map(offset=>fetchLiveRange(offset,Math.min(LIVE_PAGE_SIZE,total-offset),false)));
        for(const result of results)pages.push(Array.isArray(result.items)?result.items:[]);
      }
    }

    const seen=new Set();
    const live=[];
    for(const row of pages.flat()){
      const key=String(row?.id??'');
      if(key&&seen.has(key))continue;
      if(key)seen.add(key);
      live.push(row);
    }
    if(live.length!==total)throw new Error(`live catalog incomplete: ${live.length}/${total}`);
    return live;
  }

  function staticRowWithDbPhoto(row){
    const name=String(row?.title??row?.name??'').trim();
    if(!name)return row;
    const path=Array.isArray(row?.category_path)?row.category_path:[];
    const cat=String(path[path.length-1]??'').trim();
    const params=new URLSearchParams({name});
    if(cat)params.set('cat',cat);
    const brand=String(row?.brand??'').trim();
    const model=String(row?.model??'').trim();
    if(brand)params.set('brand',brand);
    if(model)params.set('model',model);
    const resolver=`api/product-db-image.php?${params.toString()}`;
    const staticImages=Array.isArray(row?.images)?row.images.filter(Boolean):[];
    return{...row,image:resolver,main_image:resolver,images:[resolver,...staticImages]};
  }

  async function loadStaticCatalogFallback(){
    const manifest=await catalogRequest('data/manifest.json',{},r=>r.json());
    const parts=Array.isArray(manifest.parts)?manifest.parts:[];
    const arrays=await Promise.all(parts.map(file=>catalogRequest(`data/${file}`,{},r=>r.json())));
    window.CATALOG_SOURCE='static-db-photo-resolver';
    window.CATALOG_PARSER_ROWS_WITH_IMAGES=Number(manifest.parser_rows_with_images)||0;
    window.CATALOG_PHOTO_SOURCE='mysql-resolver+parser-emergency';
    return arrays.flat().filter(isPurchasableCatalogRow).map(staticRowWithDbPhoto).map(normalizeProduct);
  }

  window.loadRealCatalog=function loadRealCatalogPaged(){
    if(pagedCatalogPromise)return pagedCatalogPromise;
    pagedCatalogPromise=(async()=>{
      try{
        const rows=await loadPagedLiveCatalog();
        const liveItems=rows.filter(isPurchasableCatalogRow);
        if(!liveItems.length)throw new Error('live catalog empty after validation');
        window.CATALOG_SOURCE='live-paged';
        window.CATALOG_LIVE_ROWS=liveItems.length;
        window.CATALOG_PHOTO_SOURCE='mysql';
        return liveItems.map(normalizeProduct);
      }catch(error){
        window.CATALOG_LOAD_ERROR=String(error?.message||error||'unknown');
        console.error('Paged live catalog failed; static metadata will resolve photos against DB.',error);
        return loadStaticCatalogFallback();
      }
    })();
    return pagedCatalogPromise;
  };
})();
