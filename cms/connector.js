/* ID: one safe recipe renderer for the editor, preview and connected websites. */
(()=>{'use strict';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const url=(v,base)=>{if(!v||/[\x00-\x20\x7f\\]/.test(v)||v.startsWith('//'))return '';try{const u=new URL(v,base);return ['https:','http:','mailto:','tel:'].includes(u.protocol)?u.href:'';}catch{return '';}};
const catalog={hero:['Главный экран','Основное','▰'],text:['Текст','Основное','☰'],image:['Изображение','Медиа','▧'],columns:['Две колонки','Основное','▥'],cta:['Кнопка и призыв','Основное','▱'],contacts:['Контакты','Основное','⌖'],spacer:['Отступ','Основное','↕'],gallery:['Галерея','Медиа','▦'],cards:['Карточки','Композиции','▤'],faq:['Вопросы и ответы','Композиции','≡'],pricing:['Тарифы','Композиции','₽'],testimonials:['Отзывы','Композиции','❞'],metrics:['Цифры','Композиции','№'],split:['Текст + фото','Медиа','◧']};
const css=`.cms-block{box-sizing:border-box;width:100%;padding:48px 24px;margin:0;font-family:Arial,sans-serif;line-height:1.6;overflow-wrap:anywhere}.cms-block *{box-sizing:border-box}.cms-block .cms-inner{max-width:1120px;margin:auto}.cms-block h2{font-size:clamp(26px,4vw,48px);line-height:1.15;margin:0 0 20px;color:inherit}.cms-block h3{font-size:22px;line-height:1.3;color:inherit;margin:0 0 12px}.cms-block p{white-space:pre-line;font-size:18px;margin:12px 0 24px}.cms-block img{display:block;max-width:100%;height:auto;border-radius:var(--cms-radius,16px);margin:24px auto}.cms-block .cms-button{display:inline-block;background:var(--cms-accent,#2463eb);color:white;padding:12px 24px;border-radius:8px;text-decoration:none}.cms-block .cms-columns,.cms-block .cms-grid{display:grid;grid-template-columns:repeat(var(--cms-columns,2),minmax(0,1fr));gap:24px}.cms-block .cms-card{padding:24px;border:1px solid currentColor;border-radius:var(--cms-radius,16px)}.cms-block .cms-card p{font-size:16px}.cms-block figure{margin:0}.cms-block .cms-gallery img{width:100%;aspect-ratio:4/3;object-fit:cover;margin:0 0 12px}.cms-block figcaption{font-size:14px}.cms-block details{border-bottom:1px solid currentColor;padding:18px 0}.cms-block summary{font-size:20px;font-weight:bold;cursor:pointer}.cms-block .cms-metric h3{font-size:clamp(36px,5vw,64px)}.cms-block .cms-split{display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:center}.cms-block .cms-placeholder{display:grid;place-items:center;min-height:180px;background:#e9edf3;color:#6c7789;border-radius:var(--cms-radius,16px);font-size:14px}.cms-block blockquote{margin:0}.cms-block cite{font-style:normal;font-weight:bold}@media(min-width:641px){.cms-block.cms-only-mobile{display:none!important}}@media(max-width:640px){.cms-block .cms-columns,.cms-block .cms-grid,.cms-block .cms-split{grid-template-columns:1fr}.cms-block{padding:var(--cms-mobile-space,32px) 20px!important}.cms-block.cms-only-desktop{display:none!important}}`;
function html(block,base=location.href){const p=block.props||{},color=(v,f)=>/^#[\da-f]{6}$/i.test(v)?v:f,space=['24','48','80','120'].includes(p.space)?p.space:'48',mobile=['24','48','80','120'].includes(p.mobileSpace)?p.mobileSpace:'24',cols=['2','3','4'].includes(p.columns)?p.columns:'3',radius=['0','8','16','24'].includes(p.radius)?p.radius:'16',align=['left','center','right'].includes(p.align)?p.align:'left';
const pic=(v,placeholder=false)=>{const src=url(v.image,base);return src&&/^https?:/.test(src)?`<img src="${esc(src)}" alt="${esc(v.alt)}" loading="lazy">`:placeholder?'<div class="cms-placeholder">Добавьте изображение</div>':'';};
const button=v=>{const href=url(v.url,base);return v.label&&href?`<a class="cms-button" href="${esc(href)}">${esc(v.label)}</a>`:'';};
const heading=p.title?`<h2>${esc(p.title)}</h2>`:'',text=p.text?`<p>${esc(p.text)}</p>`:'';let inner='';
if(block.type!=='spacer'){
 if(block.type==='split')inner=`<div class="cms-split"><div>${heading}${text}${button(p)}</div>${pic(p,true)}</div>`;
 else {inner=heading;
 if(block.type==='columns')inner+=`<div class="cms-columns" style="--cms-columns:2"><p>${esc(p.text)}</p><p>${esc(p.text2)}</p></div>`;
 else inner+=text;
 const items=Array.isArray(p.items)?p.items:[];
 if(block.type==='faq')inner+=items.map(v=>`<details><summary>${esc(v.title)}</summary><p>${esc(v.text)}</p></details>`).join('');
 else if(['gallery','cards','pricing','testimonials','metrics'].includes(block.type))inner+=`<div class="cms-grid cms-${esc(block.type)}">`+items.map(v=>block.type==='gallery'?`<figure>${pic(v,true)}<figcaption>${esc(v.title)}</figcaption></figure>`:block.type==='testimonials'?`<blockquote class="cms-card"><p>${esc(v.text)}</p><cite>${esc(v.title)}</cite></blockquote>`:block.type==='metrics'?`<div class="cms-metric"><h3>${esc(v.title)}</h3><p>${esc(v.text)}</p></div>`:`<article class="cms-card">${pic(v)}<h3>${esc(v.title)}</h3><p>${esc(v.text)}</p>${button(v)}</article>`).join('')+'</div>';
 else inner+=pic(p,block.type==='image');
 inner+=button(p);
 }
}
const visibility=['mobile','desktop'].includes(p.visibility)?' cms-only-'+p.visibility:'';
return `<section class="cms-block${visibility}" data-cms-new="${esc(block.id)}" style="background:${color(p.background,'#ffffff')};color:${color(p.color,'#172033')};text-align:${align};padding-top:${space}px;padding-bottom:${space}px;--cms-mobile-space:${mobile}px;--cms-columns:${cols};--cms-radius:${radius}px;--cms-accent:${color(p.accent,'#2463eb')}"><div class="cms-inner">${inner}</div></section>`;
}
function defaults(type){const p={title:catalog[type]?.[0]||'Блок',text:type==='spacer'?'':'Добавьте свой текст. Изменения сразу видны на странице.',text2:type==='columns'?'Текст второй колонки.':'',image:'',alt:'',label:['hero','cta'].includes(type)?'Подробнее':'',url:['hero','cta'].includes(type)?'#contacts':'',background:type==='hero'?'#eef4ff':'#ffffff',color:'#172033',align:type==='hero'?'center':'left',space:type==='hero'?'80':'48',mobileSpace:'24',visibility:'all',columns:'3',radius:'16',accent:'#2463eb'};
if(['gallery','cards','faq','pricing','testimonials','metrics'].includes(type))p.items=Array.from({length:3},(_,i)=>({title:type==='metrics'?'—':type==='faq'?'Вопрос '+(i+1):type==='testimonials'?'Имя автора':'Элемент '+(i+1),text:type==='testimonials'?'Добавьте реальный отзыв с разрешения автора.':'Добавьте описание.',image:'',alt:'',label:'',url:''}));
return p;}
window.CMBlocks={html,css,defaults,esc,url,catalog};
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
