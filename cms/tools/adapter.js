/* Connector preserves existing DOM nodes and their event listeners. */
(()=>{'use strict';
 const script=document.currentScript;
 const endpoint=new URL(script?.dataset.cmsEndpoint||(script?.src.includes('/cms-client.js')?'cms/api.php':'api.php'),script?.src||location.href);
 const site=script?.dataset.cmsSite||'', page=script?.dataset.cmsPage||new URLSearchParams(location.search).get('page')||location.pathname.split('/').pop()||'index.html';
 if(site)endpoint.searchParams.set('site',site);endpoint.searchParams.set('action','public');
 const safeUrl=v=>!!CMBlocks.url(v,location.href);
 const originalNodes=new Map();
 function apply(data){
  if(!data||!Array.isArray(data.fields))return;
  for(const field of data.fields){let nodes;try{nodes=document.querySelectorAll(field.selector);}catch{continue;}
   for(const el of nodes){const v=field.value;if(typeof v!=='string')continue;
    if(field.kind==='text'){el.textContent=v;el.style.whiteSpace='pre-line';}
    else if(field.kind==='alt')el.setAttribute('alt',v);
    else if(v&&safeUrl(v)){if(field.kind==='link'){el.setAttribute('href',v);el.removeAttribute('data-category');}else if(field.kind==='image')el.setAttribute('src',v);else if(field.kind==='background')el.style.backgroundImage=`url(${JSON.stringify(new URL(v,location.href).href)})`;}
   }
  }
  if(Array.isArray(data.layout)) {
   const main=document.querySelector('[data-cms-root]')||document.querySelector('main');if(!main)return;
   // Resolve all selectors before moving anything. Retain identities across preview messages.
   for(const s of data.sections||[])if(!originalNodes.has(s.id)){const el=document.querySelector(s.selector);if(el&&el.parentNode===main)originalNodes.set(s.id,{el,display:el.style.display,hidden:el.hidden});}
   main.querySelectorAll(':scope > [data-cms-new]').forEach(n=>n.remove());
   for(const block of data.layout){if(block.type==='existing'){const entry=originalNodes.get(block.id);if(!entry)continue;main.append(entry.el);entry.el.hidden=!block.visible;entry.el.style.display=block.visible?entry.display:'none';}else {const template=document.createElement('template');template.innerHTML=CMBlocks.html(block,location.href);main.append(template.content);}}
   if(!document.getElementById('cms-block-css')){const style=document.createElement('style');style.id='cms-block-css';style.textContent=CMBlocks.css;document.head.append(style);}
  } else {
   const pairs=(data.blocks||[]).map(b=>({b,el:document.querySelector(b.selector)})).filter(x=>x.el);
   for(const parent of new Set(pairs.map(x=>x.el.parentNode))){const wanted=pairs.filter(x=>x.el.parentNode===parent),nodes=new Set(wanted.map(x=>x.el)),markers=[];for(const child of Array.from(parent.children))if(nodes.has(child)){const marker=document.createComment('cms-block');parent.insertBefore(marker,child);markers.push(marker);}wanted.forEach((x,i)=>{if(x.b.changed)x.el.style.setProperty('display',x.b.visible?'':'none','important');if(x.b.changed&&x.b.visible)x.el.hidden=false;parent.insertBefore(x.el,markers[i]);});markers.forEach(m=>m.remove());}
  }
  window.dispatchEvent(new Event('resize'));
 }
 if(new URLSearchParams(location.search).get('cms-preview')==='1'&&window.opener){window.addEventListener('message',e=>{if(e.origin!==endpoint.origin||e.source!==window.opener||e.data?.type!=='cms-preview')return;apply(e.data.payload);});window.opener.postMessage({type:'cms-ready'},endpoint.origin);return;}
 fetch(endpoint,{credentials:'omit',signal:AbortSignal.timeout(6000)}).then(r=>{if(!r.ok)throw Error('CMS unavailable');return r.json();}).then(data=>{if(data.published)apply(data.pages?.[page]);}).catch(()=>{});
})();
