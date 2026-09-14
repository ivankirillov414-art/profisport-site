// Small live category response, cached per product for five minutes in this tab.
async function loadSimilarProducts(product){
  const key='ps-related-live-v1';
  const id=String(product.id);
  let cache={};
  try{cache=JSON.parse(sessionStorage.getItem(key)||'{}')||{}}catch(e){}
  const entry=cache[id];
  if(entry&&Date.now()-entry.at<300000&&Array.isArray(entry.rows))return entry.rows;
  const response=await catalogRequest('api/catalog.php?related_to='+encodeURIComponent(id)+'&limit=12&v=related1',{},parseCatalogResponse);
  const rows=response.items.filter(isPurchasableCatalogRow).map(normalizeProduct);
  try{
    cache[id]={at:Date.now(),rows};
    const recent=Object.entries(cache).filter(([,x])=>x&&Date.now()-x.at<300000).sort((a,b)=>b[1].at-a[1].at).slice(0,30);
    sessionStorage.setItem(key,JSON.stringify(Object.fromEntries(recent)));
  }catch(e){}
  return rows;
}

function descriptionText(value){
  // Preserve paragraphs without inserting source HTML into the live document.
  const source=String(value).replace(/<\/(?:p|div|li|h[1-6])\s*>/gi,'\n\n').replace(/<br\s*\/?>/gi,'\n');
  const doc=new DOMParser().parseFromString(source,'text/html');
  doc.querySelectorAll('script,style,iframe,object').forEach(el=>el.remove());
  return (doc.body.textContent||'').trim();
}
