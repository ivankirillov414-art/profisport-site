'use strict';
function pageSEO(){
 if(!componentWritable())return;sync();const key=page,seo=structuredClone(state.draft.pages[key].seo||{title:'',description:'',canonical:'',image:'',noindex:false}),d=collectionDialog('SEO · '+pageMeta(key).title,'pageSEODialog'),form=el('form'),message=el('p');message.setAttribute('role','status');d.append(form,message);
 form.append(el('p','Пустой заголовок использует название страницы. Пустой canonical использует её основной адрес. Изменения действуют после публикации.'));
 for(const [key,label,type,max] of [['title','Заголовок для поиска','text',200],['description','Описание для поиска','textarea',650],['canonical','Canonical — полный HTTPS-адрес','url',2048],['image','Изображение для соцсетей — URL','text',2048]]){const input=collectionInput(form,label,seo[key],type);input.maxLength=max;input.oninput=()=>seo[key]=input.value;}
 const noindex=collectionInput(form,'Не индексировать страницу',seo.noindex,'checkbox');noindex.onchange=()=>seo.noindex=noindex.checked;
 if(state.manifest.pages[key])form.append(el('p','Это страница подключённого сайта. Его сервер должен выводить метаданные из CMS в исходном HTML; один JavaScript-коннектор этого не обеспечивает.'));
 const sitemap=el('a','Открыть sitemap опубликованного сайта'),url=new URL('sitemap.php',location.href);url.searchParams.set('site',site);sitemap.href=url.href;sitemap.target='_blank';sitemap.rel='noopener';form.append(sitemap);
 const submit=el('button','Сохранить SEO');submit.type='submit';form.append(submit);
 form.onsubmit=async event=>{event.preventDefault();sync();const draft=structuredClone(state.draft);draft.pages[key].seo=seo;d.inert=true;try{await api('validate-collections',{collections:draft.collections||[],pages:draft.pages,components:draft.components||[]});state.draft.pages[key].seo=seo;changed();d.close();}catch(error){message.textContent=error.message;}finally{d.inert=false;}};
}
const seoButton=actionButton('SEO страницы',pageSEO);seoButton.id='seoButton';$('#collectionsButton').after(seoButton);
