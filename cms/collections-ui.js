'use strict';
const collectionTypes={string:'Короткий текст',text:'Длинный текст',number:'Число',boolean:'Да / нет',date:'Дата',image:'Изображение (URL)',url:'Ссылка',reference:'Связанная запись'};
function collectionInput(parent,label,value='',type='text'){
 const wrap=el('label',label),input=el(type==='textarea'?'textarea':'input');if(type!=='textarea')input.type=type;
 if(type==='checkbox')input.checked=!!value;else input.value=value??'';wrap.append(input);parent.append(wrap);return input;
}
function collectionSelect(parent,label,options,value){const wrap=el('label',label),select=el('select');for(const [key,title] of Object.entries(options)){const o=el('option',title);o.value=key;select.append(o);}select.value=value;select.setAttribute('aria-label',label);wrap.append(select);parent.append(wrap);return select;}
function collectionDialog(title,id){const d=componentDialog(title);d.id=id;return d;}
async function collectionCommit(dialog,transform){
 if(!componentWritable())return false;const message=dialog.querySelector('[role=status]')||el('p');message.setAttribute('role','status');dialog.append(message);message.textContent='Проверяю…';
 const before=JSON.stringify(state.draft.collections||[]),copy=structuredClone(state.draft.collections||[]);dialog.inert=true;
 try{transform(copy);sync();const result=await api('validate-collections',{collections:copy,pages:state.draft.pages,components:state.draft.components||[]});
  if(Object.keys(result.pages||{}).some(key=>state.draft.pages[key]))throw Error('Адрес записи совпадает с адресом существующей страницы.');
  if(before!==JSON.stringify(state.draft.collections||[]))throw Error('Коллекции изменились. Откройте форму заново.');
  sync();state.draft.collections=result.collections;await renderPage();changed();dialog.close();return true;
 }catch(error){message.textContent=error.message;return false;}finally{dialog.inert=false;}
}
function collectionSchema(id=null){
 if(!componentWritable())return;const original=(state.draft.collections||[]).find(c=>c.id===id);
 const copy=structuredClone(original||{id:'',name:'',titleField:'title',fields:[{key:'title',label:'Название',type:'string',required:true}],entries:[]});
 const d=collectionDialog(original?'Поля коллекции':'Новая коллекция','collectionSchemaDialog'),form=el('form');d.append(form);
 const name=collectionInput(form,'Название коллекции',copy.name);name.required=true;name.maxLength=100;
 const key=collectionInput(form,'Адрес коллекции',copy.id);key.required=true;key.pattern='[a-z][a-z0-9-]{0,31}';key.maxLength=32;key.disabled=!!original;
 name.oninput=()=>copy.name=name.value;key.oninput=()=>copy.id=key.value;
 form.append(el('p','Адреса полей используются в связях и шаблонах. Тип и адрес существующего поля сохраняются. Удаление поля удалит его значения из записей.'));
 const rows=el('div'),titleWrap=el('div');form.append(rows,titleWrap);
 function draw(){rows.replaceChildren();copy.fields.forEach((field,index)=>{
  const card=el('fieldset');card.append(el('legend','Поле '+(index+1)));const label=collectionInput(card,'Подпись поля',field.label);label.required=true;label.maxLength=100;label.oninput=()=>{field.label=label.value;drawTitle();};
  const address=collectionInput(card,'Адрес поля',field.key);address.required=true;address.pattern='[a-z][a-z0-9-]{0,31}';address.maxLength=32;address.disabled=!!original?.fields.some(f=>f.key===field.key);address.oninput=()=>{field.key=address.value;drawTitle();};
  const type=collectionSelect(card,'Тип поля',collectionTypes,field.type);type.disabled=address.disabled;type.onchange=()=>{field.type=type.value;if(type.value==='reference')field.target=copy.id;else delete field.target;draw();};
  const required=collectionInput(card,'Обязательное при публикации',field.required,'checkbox');required.onchange=()=>field.required=required.checked;
  if(field.type==='reference'){const options=Object.fromEntries((state.draft.collections||[]).map(c=>[c.id,c.name]));options[copy.id]=copy.name||'Эта коллекция';const target=collectionSelect(card,'Коллекция для связи',options,field.target||copy.id);target.disabled=address.disabled;target.onchange=()=>field.target=target.value;}
  const remove=actionButton('Удалить поле',()=>{if(original?.fields.some(f=>f.key===field.key)&&!confirm('Удалить поле и его значения во всех записях?'))return;copy.fields.splice(index,1);for(const entry of copy.entries)delete entry.values[field.key];draw();});remove.disabled=field.key===copy.titleField;card.append(remove);rows.append(card);
 });drawTitle();}
 function drawTitle(){titleWrap.replaceChildren();const choices=Object.fromEntries(copy.fields.filter(f=>f.type==='string').map(f=>[f.key,f.label||f.key]));const input=collectionSelect(titleWrap,'Поле названия записи',choices,copy.titleField);input.required=true;input.onchange=()=>{copy.titleField=input.value;draw();};}
 const add=actionButton('Добавить поле',()=>{if(copy.fields.length>=20)return;copy.fields.push({key:'field-'+(copy.fields.length+1),label:'Новое поле',type:'string',required:false});draw();});form.append(add);
 const submit=el('button','Сохранить коллекцию');submit.type='submit';form.append(submit);draw();
 form.onsubmit=async event=>{event.preventDefault();copy.name=name.value;copy.id=key.value;await collectionCommit(d,all=>{if(original)all[all.findIndex(c=>c.id===id)]=copy;else all.push(copy);});};
}
function collectionEntry(collectionId,entryId=null){
 if(!componentWritable())return;const collection=state.draft.collections.find(c=>c.id===collectionId),existing=collection.entries.find(e=>e.id===entryId);
 const entry=structuredClone(existing||{id:'record-'+crypto.randomUUID().slice(0,30),slug:'',status:'draft',values:{}});
 const d=collectionDialog(existing?'Редактировать запись':'Новая запись','collectionEntryDialog'),form=el('form');d.append(form);
 const slug=collectionInput(form,'Адрес записи',entry.slug);slug.required=true;slug.pattern='[a-z][a-z0-9-]{0,39}';slug.maxLength=40;
 const statusInput=collectionSelect(form,'Включение в публикацию',{draft:'Черновик — скрыт от посетителей',published:'Включить при публикации сайта'},entry.status);
 const controls=[];
 for(const field of collection.fields){let input;
  if(field.type==='reference'){const target=state.draft.collections.find(c=>c.id===field.target),choices={'':'Не выбрано'};for(const item of target?.entries||[])choices[item.id]=(item.values[target.titleField]||item.slug)+(item.status==='draft'?' · черновик':'');input=collectionSelect(form,field.label,choices,entry.values[field.key]||'');}
  else input=collectionInput(form,field.label,entry.values[field.key],{text:'textarea',number:'number',boolean:'checkbox',date:'date'}[field.type]||'text');
  if(field.type==='number'){input.step='any';input.min='-1000000000000';input.max='1000000000000';}
  if(['string','text','url','image'].includes(field.type))input.maxLength=field.type==='text'?12000:2000;
  controls.push({field,input});
 }
 form.append(el('p','Запись станет доступна посетителям после публикации сайта и подключения к странице.'));
 const submit=el('button','Сохранить запись');submit.type='submit';form.append(submit);
 form.onsubmit=async event=>{event.preventDefault();entry.slug=slug.value;entry.status=statusInput.value;for(const {field,input} of controls)entry.values[field.key]=field.type==='boolean'?input.checked:input.value===''?null:field.type==='number'?Number(input.value):input.value;
  await collectionCommit(d,all=>{const target=all.find(c=>c.id===collectionId);if(existing)target.entries[target.entries.findIndex(e=>e.id===entryId)]=entry;else target.entries.push(entry);});};
}
function collectionRecords(id){
 if(!componentWritable())return;const c=state.draft.collections.find(c=>c.id===id),d=collectionDialog(c.name,'collectionRecordsDialog');
 d.append(actionButton('Добавить запись',()=>{d.close();collectionEntry(id);}));const search=collectionInput(d,'Поиск записей'),list=el('div');d.append(list);
 function draw(){list.replaceChildren();for(const entry of c.entries){const title=entry.values[c.titleField]||entry.slug;if(!String(title+' '+entry.slug).toLocaleLowerCase().includes(search.value.toLocaleLowerCase()))continue;
  const row=el('section',undefined,'site-card');row.append(el('h3',String(title)),el('p',entry.slug+' · '+(entry.status==='published'?'Включена в публикацию':'Черновик')),actionButton('Редактировать запись',()=>{d.close();collectionEntry(id,entry.id);}),actionButton('Удалить запись',async()=>{if(!confirm('Удалить запись «'+title+'» из черновика?'))return;await collectionCommit(d,all=>{const target=all.find(x=>x.id===id);target.entries=target.entries.filter(x=>x.id!==entry.id);});}));list.append(row);
 }if(!list.children.length)list.append(el('p','Записей не найдено.'));}search.oninput=draw;draw();
}
function collectionsPanel(){
 if(!componentWritable())return;sync();const d=collectionDialog('Коллекции контента','collectionsDialog');d.append(el('p','Записи хранятся в черновике сайта. Изменения публикуются вместе со страницами.'),actionButton('Новая коллекция',()=>{d.close();collectionSchema();}));
 for(const c of state.draft.collections||[]){const row=el('section',undefined,'site-card');row.append(el('h3',c.name),el('p',c.entries.length+' записей · '+c.fields.length+' полей'),actionButton('Открыть записи',()=>{d.close();collectionRecords(c.id);}),actionButton('Настроить поля',()=>{d.close();collectionSchema(c.id);}),actionButton('Удалить коллекцию',async()=>{if(!confirm('Удалить коллекцию «'+c.name+'» и все её записи из черновика?'))return;await collectionCommit(d,all=>all.splice(all.findIndex(x=>x.id===c.id),1));}));d.append(row);}
}
const collectionsButton=actionButton('Коллекции',collectionsPanel);collectionsButton.id='collectionsButton';$('#componentsButton').after(collectionsButton);
