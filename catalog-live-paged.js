(()=>{
  const LIVE_PAGE_SIZE=500;
  const LIVE_CONCURRENCY=2;
  const LIVE_RETRIES=2;
  const INITIAL_PAGE_SIZE=24;
  let pagedCatalogPromise=null;

  const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));

  async function fetchLiveRange(offset,limit,includeCount=false){
    let lastError;
    for(let attempt=1;attempt<=LIVE_RETRIES;attempt++){
      try{
        const suffix=includeCount?'&count=1':'';
        return await catalogRequest(`api/catalog.php?limit=${limit}&offset=${offset}${suffix}&v=imgtruth5`,{},parseCatalogResponse);
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

  async function loadPagedLiveCatalog(onInitial){
    const first=await fetchLiveRange(0,INITIAL_PAGE_SIZE,true);
    const firstItems=Array.isArray(first.items)?first.items:[];
    const total=Number(first.total);
    if(!firstItems.length)throw new Error('live catalog first page empty');
    if(!Number.isFinite(total)||total<firstItems.length)throw new Error('live catalog total unavailable');

    if(typeof onInitial==='function')onInitial(firstItems.filter(isPurchasableCatalogRow).map(normalizeProduct));
    const pages=[firstItems];
    if(total>firstItems.length){
      const offsets=[];
      for(let offset=firstItems.length;offset<total;offset+=LIVE_PAGE_SIZE)offsets.push(offset);
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

  const CACHE_KEY='live-catalog-imgtruth5-taxonomy5',CACHE_MAX_AGE=120000;
  function catalogCache(mode,items,maxAge=CACHE_MAX_AGE){
    return new Promise(resolve=>{
      let database,settled=false;
      const finish=value=>{if(settled)return;settled=true;clearTimeout(timer);if(database)database.close();resolve(value)};
      const timer=setTimeout(()=>finish(null),400);
      try{
        const open=indexedDB.open('profisport-catalog',1);
        open.onupgradeneeded=()=>{if(!open.result.objectStoreNames.contains('catalog'))open.result.createObjectStore('catalog')};
        open.onerror=()=>finish(null);open.onblocked=()=>finish(null);
        open.onsuccess=()=>{
          database=open.result;if(settled){database.close();return;}
          const transaction=database.transaction('catalog',mode);
          transaction.onabort=()=>finish(null);transaction.onerror=()=>finish(null);
          const bucket=transaction.objectStore('catalog');
          if(mode==='readwrite'){
            bucket.put({savedAt:Date.now(),items},CACHE_KEY);transaction.oncomplete=()=>finish(true);
          }else{
            const get=bucket.get(CACHE_KEY);
            get.onerror=()=>finish(null);
            get.onsuccess=()=>{const record=get.result,age=Date.now()-Number(record?.savedAt);finish(record&&age>=0&&age<maxAge&&Array.isArray(record.items)&&record.items.length?record.items:null)};
          }
        };
      }catch(error){finish(null)}
    });
  }
  const readCatalogCache=(maxAge=CACHE_MAX_AGE)=>catalogCache('readonly',undefined,maxAge);
  const writeCatalogCache=items=>catalogCache('readwrite',items);

  window.loadRealCatalog=function loadRealCatalogPaged(onInitial){
    if(pagedCatalogPromise)return pagedCatalogPromise;
    pagedCatalogPromise=(async()=>{
      try{
        const cached=await readCatalogCache();
        if(cached){window.CATALOG_SOURCE='live-cache';window.CATALOG_PHOTO_SOURCE='mysql';window.CATALOG_LIVE_ROWS=cached.length;return cached;}
        const rows=await loadPagedLiveCatalog(onInitial);
        const liveItems=rows.filter(isPurchasableCatalogRow);
        if(!liveItems.length)throw new Error('live catalog empty after validation');
        window.CATALOG_SOURCE='live-paged';
        window.CATALOG_LIVE_ROWS=liveItems.length;
        window.CATALOG_PHOTO_SOURCE='mysql';
        const normalized=liveItems.map(normalizeProduct);
        void writeCatalogCache(normalized);
        return normalized;
      }catch(error){
        window.CATALOG_LOAD_ERROR=String(error?.message||error||'unknown');
        console.error('Paged live catalog failed; parser fallback is disabled.',error);
        const stale=await readCatalogCache(24*60*60*1000);
        if(stale){
          window.CATALOG_SOURCE='live-cache-stale';
          window.CATALOG_PHOTO_SOURCE='mysql';
          window.CATALOG_LIVE_ROWS=stale.length;
          return stale;
        }
        throw error;
      }
    })();
    return pagedCatalogPromise;
  };
})();

