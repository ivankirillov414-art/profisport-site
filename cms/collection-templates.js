'use strict';
function collectionPageKey(c,e){return 'c-'+c.id.length+'-'+c.id+'-'+e.slug+'.html';}
function collectionPageURL(c,e){const url=new URL('site.php',location.href);url.searchParams.set('site',site);url.searchParams.set('page',collectionPageKey(c,e));return url.href;}
function collectionTemplate(id){
 if(!componentWritable())return;const collection=structuredClone(state.draft.collections.find(c=>c.id===id));collection.template??=[];
 const d=collectionDialog('Шаблон · '+collection.name,'collectionTemplateDialog'),form=el('form');d.append(el('p','Один шаблон создаёт страницу для каждой записи, включённой в публикацию. Выберите поля для подстановки или оставьте постоянный текст. Изменения выйдут после публикации сайта.'),form);
 const rows=el('div');form.append(rows);
 const propLabels={title:'Заголовок',text:'Текст',text2:'Вторая колонка',image:'Изображение',alt:'Описание изображения',label:'Текст кнопки',url:'Ссылка кнопки'};
 function draw(){rows.replaceChildren();collection.template.forEach((section,index)=>{
  const box=el('fieldset');box.append(el('legend',(index+1)+'. '+labels[section.block.type]));const p=section.block.props;
  for(const [prop,label] of Object.entries(propLabels)){
   const choices={'':'Постоянное значение'};for(const f of collection.fields)if((prop!=='image'||f.type==='image')&&(prop!=='url'||f.type==='url'))choices[f.key]=f.label;
   const binding=collectionSelect(box,label+' — источник',choices,section.bindings[prop]||''),value=collectionInput(box,label,p[prop],['text','text2'].includes(prop)?'textarea':'text');value.disabled=!!section.bindings[prop];value.oninput=()=>p[prop]=value.value;
   binding.onchange=()=>{if(binding.value)section.bindings[prop]=binding.value;else delete section.bindings[prop];value.disabled=!!binding.value;};
  }
  for(const [key,label,fallback] of [['background','Фон','#ffffff'],['color','Цвет текста','#172033'],['accent','Цвет кнопки','#2463eb']]){const input=collectionInput(box,label,p[key]||fallback,'color');input.oninput=()=>p[key]=input.value;}
  for(const [key,label,choices] of [['space','Отступы',{'24':'24 px','48':'48 px','80':'80 px','120':'120 px'}],['align','Выравнивание',{left:'Слева',center:'По центру',right:'Справа'}]]){const input=collectionSelect(box,label,choices,p[key]||Object.keys(choices)[0]);input.onchange=()=>p[key]=input.value;}
  box.append(actionButton('Удалить секцию',()=>{collection.template.splice(index,1);draw();}));for(const delta of [-1,1]){const b=actionButton(delta<0?'Выше':'Ниже',()=>{[collection.template[index],collection.template[index+delta]]=[collection.template[index+delta],collection.template[index]];draw();});b.disabled=index+delta<0||index+delta>=collection.template.length;box.append(b);}rows.append(box);
 });}
 const types=collectionSelect(form,'Тип новой секции',Object.fromEntries(['hero','text','image','columns','cta','contacts','spacer','split'].map(type=>[type,labels[type]])),'text');
 form.append(actionButton('Добавить секцию в шаблон',()=>{if(collection.template.length>=20)return;const type=types.value,props=CMBlocks.defaults(type);props.title='';props.text='';collection.template.push({block:{id:'template-'+crypto.randomUUID(),type,props},bindings:type==='text'||type==='hero'?{title:collection.titleField}:{}});draw();}));
 const candidates=sectionModels().map(m=>CMSComponents.reference(m.get('recipe'))).filter(r=>r&&r.type!=='existing'),choices=Object.fromEntries(candidates.map(r=>[r.id,sectionName(CMSComponents.expand(r,state.draft.components))]));
 if(candidates.length){const source=collectionSelect(form,'Копия секции текущей страницы',choices,candidates[0].id);form.append(actionButton('Скопировать секцию в шаблон',()=>{if(collection.template.length>=20)return;const block=CMSComponents.render(candidates.find(r=>r.id===source.value),state.draft.components);block.id='template-'+crypto.randomUUID();collection.template.push({block,bindings:{}});draw();}));}
 const entries=collection.entries.filter(e=>e.status==='published');const record=collectionSelect(form,'Запись для предпросмотра',Object.fromEntries(entries.map(e=>[e.id,e.values[collection.titleField]||e.slug])),entries[0]?.id||'');
 const preview=el('div'),notice=el('p');notice.setAttribute('role','status');form.append(actionButton('Предпросмотр шаблона',async()=>{notice.textContent='';try{const all=structuredClone(state.draft.collections);all[all.findIndex(c=>c.id===id)]=collection;const result=await api('validate-collections',{collections:all}),entry=entries.find(e=>e.id===record.value);if(!entry)throw Error('Добавьте запись и включите её в публикацию для предпросмотра.');const data=result.pages[collectionPageKey(collection,entry)];if(!data)throw Error('Добавьте секцию в шаблон.');
  const iframe=el('iframe');iframe.title='Предпросмотр записи';iframe.setAttribute('sandbox','');iframe.style.cssText='width:100%;height:440px;border:1px solid #d4dae3;border-radius:8px';iframe.srcdoc='<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>'+CMBlocks.css+'body{margin:0}</style><body>'+data.layout.map(r=>CMBlocks.html(r,state.site_url)).join('')+'</body></html>';preview.replaceChildren(iframe);
 }catch(error){notice.textContent=error.message;}}),notice,preview);
 const submit=el('button','Сохранить шаблон');submit.type='submit';form.append(submit);form.onsubmit=async event=>{event.preventDefault();await collectionCommit(d,all=>{all[all.findIndex(c=>c.id===id)]=collection;});};draw();
}
const collectionPanelBase=collectionsPanel;
collectionsPanel=function(){collectionPanelBase();const d=$('#collectionsDialog');if(!d)return;const cards=[...d.querySelectorAll('.site-card')];(state.draft.collections||[]).forEach((c,i)=>cards[i].append(actionButton('Шаблон страниц',()=>{d.close();collectionTemplate(c.id);})))};
const collectionRecordsBase=collectionRecords;
collectionRecords=function(id){collectionRecordsBase(id);const d=$('#collectionRecordsDialog'),c=state.draft.collections.find(c=>c.id===id);if(!d||!c.template?.length)return;const links=el('details');links.append(el('summary','Адреса страниц после публикации'));for(const entry of c.entries.filter(e=>e.status==='published')){const a=el('a',entry.values[c.titleField]||entry.slug);a.href=collectionPageURL(c,entry);a.target='_blank';a.rel='noopener';const p=el('p');p.append(a);links.append(p);}d.append(links);};
collectionsButton.onclick=()=>collectionsPanel();
