/* Shared safe renderer: editor, preview and published pages use the same recipes. */
(()=>{'use strict';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const url=(v,base)=>{if(!v||/[\x00-\x20\x7f\\]/.test(v)||v.startsWith('//'))return '';try{const u=new URL(v,base);return ['https:','http:','mailto:','tel:'].includes(u.protocol)?u.href:'';}catch{return '';}};
const css=`.cms-block{box-sizing:border-box;width:100%;padding:48px 24px;margin:0;font-family:Arial,sans-serif;line-height:1.6;overflow-wrap:anywhere}.cms-block *{box-sizing:border-box}.cms-block .cms-inner{max-width:1120px;margin:auto}.cms-block h2{font-size:clamp(26px,4vw,48px);line-height:1.15;margin:0 0 20px;color:inherit}.cms-block p{white-space:pre-line;font-size:18px;margin:12px 0 24px}.cms-block img{display:block;max-width:100%;height:auto;border-radius:16px;margin:24px auto}.cms-block .cms-button{display:inline-block;background:#2463eb;color:white;padding:12px 24px;border-radius:8px;text-decoration:none}.cms-block .cms-columns{display:grid;grid-template-columns:1fr 1fr;gap:32px}@media(max-width:640px){.cms-block .cms-columns{grid-template-columns:1fr}.cms-block{padding-left:20px!important;padding-right:20px!important}}`;
function html(block,base=location.href){const p=block.props||{},bg=/^#[\da-f]{6}$/i.test(p.background)?p.background:'#ffffff',color=/^#[\da-f]{6}$/i.test(p.color)?p.color:'#172033',align=['left','center','right'].includes(p.align)?p.align:'left',space=['24','48','80','120'].includes(p.space)?p.space:'48';let inner='';
if(block.type!=='spacer'){
if(p.title)inner+=`<h2>${esc(p.title)}</h2>`;
if(block.type==='columns')inner+=`<div class="cms-columns"><p>${esc(p.text)}</p><p>${esc(p.text2)}</p></div>`;
else if(p.text)inner+=`<p>${esc(p.text)}</p>`;
const src=url(p.image,base);if(src&&['https:','http:'].includes(new URL(src).protocol))inner+=`<img src="${esc(src)}" alt="${esc(p.alt)}" loading="lazy">`;
const href=url(p.url,base);if(p.label&&href)inner+=`<a class="cms-button" href="${esc(href)}">${esc(p.label)}</a>`;
}
return `<section class="cms-block" data-cms-new="${esc(block.id)}" style="background:${bg};color:${color};text-align:${align};padding-top:${space}px;padding-bottom:${space}px"><div class="cms-inner">${inner}</div></section>`;
}
function defaults(type){return {title:({hero:'Ваш новый заголовок',text:'Текстовый блок',image:'Изображение',columns:'Две колонки',cta:'Приглашение к действию',contacts:'Свяжитесь с нами',spacer:''})[type],text:type==='spacer'?'':'Добавьте свой текст. Изменения сразу видны на странице.',text2:type==='columns'?'Текст второй колонки.':'',image:'',alt:'',label:['hero','cta'].includes(type)?'Подробнее':'',url:['hero','cta'].includes(type)?'#contacts':'',background:type==='hero'?'#eef4ff':'#ffffff',color:'#172033',align:type==='hero'?'center':'left',space:type==='hero'?'80':'48'};}
window.CMBlocks={html,css,defaults,esc,url};
})();
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
