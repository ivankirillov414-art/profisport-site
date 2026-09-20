/* Thin storefront adapter. All editing and storage belong to the standalone CMS. */
(()=>{'use strict';
 const page=location.pathname.split('/').pop()||'index.html';
 const safeUrl=value=>{if(typeof value!=='string'||/[\x00-\x20\x7f\\]/.test(value)||value.startsWith('//'))return false;try{return ['https:','mailto:','tel:'].includes(new URL(value,location.href).protocol);}catch{return false;}};
 function apply(data){
  if(!data||!Array.isArray(data.fields))return;
  for(const field of data.fields){let nodes;try{nodes=document.querySelectorAll(field.selector);}catch{continue;}
   for(const el of nodes){const v=field.value;if(typeof v!=='string')continue;
    if(field.kind==='text'){el.textContent=v;el.style.whiteSpace='pre-line';}
    else if(field.kind==='alt')el.setAttribute('alt',v);
    else if(v&&safeUrl(v)){if(field.kind==='link'){el.setAttribute('href',v);el.removeAttribute('data-category');}else if(field.kind==='image')el.setAttribute('src',v);else if(field.kind==='background')el.style.backgroundImage=`url(${JSON.stringify(new URL(v,location.href).href)})`;}
   }
  }
  // Reorder only the known content blocks; use markers to preserve other siblings.
  const pairs=(data.blocks||[]).map(b=>({b,el:document.querySelector(b.selector)})).filter(x=>x.el);
  const parents=new Set(pairs.map(x=>x.el.parentNode));
  for(const parent of parents){const wanted=pairs.filter(x=>x.el.parentNode===parent);const nodes=new Set(wanted.map(x=>x.el));const markers=[];for(const child of Array.from(parent.children)){if(nodes.has(child)){const marker=document.createComment('cms-block');parent.insertBefore(marker,child);markers.push(marker);}}wanted.forEach((x,i)=>{if(x.b.changed)x.el.style.setProperty('display',x.b.visible?'':'none','important');if(x.b.changed&&x.b.visible)x.el.hidden=false;parent.insertBefore(x.el,markers[i]);});markers.forEach(m=>m.remove());}
  window.dispatchEvent(new Event('resize'));
 }
 if(new URLSearchParams(location.search).get('cms-preview')==='1'&&window.opener){
  window.addEventListener('message',e=>{if(e.origin!==location.origin||e.source!==window.opener||e.data?.type!=='cms-preview')return;apply(e.data.payload);});
  window.opener.postMessage({type:'cms-ready'},location.origin);return;
 }
 fetch('cms/api.php?action=public',{credentials:'omit',signal:AbortSignal.timeout(4000)}).then(r=>{if(!r.ok)throw Error('CMS unavailable');return r.json();}).then(data=>{if(data.published)apply(data.pages?.[page]);}).catch(()=>{/* Original HTML remains usable when CMS is unavailable. */});
})();
