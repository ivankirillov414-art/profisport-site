'use strict';
function componentWritable(){if(busy||!access.permissions().edit){status('Редактирование сейчас недоступно.',true);return false;}return true;}
function componentDialog(title){const d=el('dialog',undefined,'access-dialog');d.append(el('h2',title),actionButton('Закрыть',()=>d.close()));d.addEventListener('close',()=>d.remove());d.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&['z','y','s'].includes(e.key.toLowerCase())){e.stopPropagation();if(e.key.toLowerCase()==='s')e.preventDefault();}});document.body.append(d);d.showModal();return d;}
async function componentChange(fn){if(!componentWritable())return false;sync();fn();await renderPage();changed();return true;}
async function createComponent(model,name){
 if(!componentWritable())return false;const recipe=model?.get('recipe');
 if(!recipe||recipe.type==='existing'||recipe._componentId){status('Выберите обычную созданную секцию.',true);return false;}
 if(!name.trim()||(state.draft.components||[]).length>=50){status('Укажите название; допускается до 50 общих блоков.',true);return false;}
 const id='global-'+crypto.randomUUID();
 return componentChange(()=>{state.draft.components??=[];state.draft.components.push({id,name:name.trim(),block:CMSComponents.render(recipe)});const layout=state.draft.pages[page].layout,index=layout.findIndex(r=>r.id===recipe.id);layout[index]={id:recipe.id,type:'global',component:id,overrides:{}};});
}
async function insertComponent(id){if(ensureLayout().length>=100){status('На странице допускается до 100 секций.',true);return false;}return componentChange(()=>state.draft.pages[page].layout.push({id:'new-'+crypto.randomUUID(),type:'global',component:id,overrides:{}}));}
async function editComponent(id){
 if(!componentWritable())return;const current=(state.draft.components||[]).find(c=>c.id===id);if(!current)return;
 const copy=structuredClone(current),d=componentDialog('Изменить общий блок'),form=el('form'),uses=CMSComponents.uses(state.draft,id),message=el('p','');d.id='componentEditDialog';
 d.append(el('p',`Изменения затронут ${uses.length} экземпляров. Местные изменения отдельных экземпляров сохранятся. Сайт изменится после публикации.`));
 const name=inputField('Название общего блока',copy.name,v=>copy.name=v);name.querySelector('input').required=true;name.querySelector('input').maxLength=100;form.append(name);
 const p=copy.block.props;
 for(const [key,label] of [['title','Заголовок'],['text','Текст'],['text2','Вторая колонка'],['image','Адрес изображения'],['alt','Описание изображения'],['label','Текст кнопки'],['url','Ссылка кнопки']])form.append(inputField(label,p[key],v=>p[key]=v,['text','text2'].includes(key)?'textarea':'text'));
 for(const [key,label,fallback] of [['background','Фон','#ffffff'],['color','Цвет текста','#172033'],['accent','Цвет кнопки','#2463eb']])form.append(inputField(label,p[key]||fallback,v=>p[key]=v,'color'));
 const choices={align:['Выравнивание',{left:'Слева',center:'По центру',right:'Справа'}],space:['Отступы',{'24':'24 px','48':'48 px','80':'80 px','120':'120 px'}],mobileSpace:['Отступы на телефоне',{'24':'24 px','48':'48 px','80':'80 px','120':'120 px'}],visibility:['Устройства',{all:'Все',desktop:'Планшет и компьютер',mobile:'Телефон'}],columns:['Колонки',{'2':'2','3':'3','4':'4'}],radius:['Скругление',{'0':'0','8':'8','16':'16','24':'24'}],font:['Шрифт',{Arial:'Arial',Verdana:'Verdana',Georgia:'Georgia',Trebuchet:'Trebuchet',Times:'Times'}],fontWeight:['Насыщенность',{'400':'400','500':'500','600':'600','700':'700','800':'800'}]};
 for(const [key,[label,values]] of Object.entries(choices)){const wrap=el('label',label),select=el('select');const inherit=el('option','По умолчанию');inherit.value='';select.append(inherit);for(const [value,text] of Object.entries(values)){const option=el('option',text);option.value=value;select.append(option);}select.value=p[key]||'';select.onchange=()=>p[key]=select.value;wrap.append(select);form.append(wrap);}
 const size=inputField('Размер шрифта (10–96, пусто — по умолчанию)',p.fontSize,v=>p.fontSize=v,'number');Object.assign(size.querySelector('input'),{min:'10',max:'96'});form.append(size);
 if(Array.isArray(p.items)){const rows=el('div');form.append(rows);function drawItems(){rows.replaceChildren(el('h3','Элементы'));p.items.forEach((item,i)=>{const details=el('details');details.append(el('summary','Элемент '+(i+1)));for(const [key,label] of [['title','Заголовок элемента'],['text','Текст элемента'],['image','Изображение элемента'],['alt','Описание изображения элемента'],['label','Текст кнопки элемента'],['url','Ссылка элемента']])details.append(inputField(label,item[key],v=>item[key]=v,key==='text'?'textarea':'text'));details.append(actionButton('Удалить элемент',()=>{p.items.splice(i,1);drawItems();}));for(const delta of [-1,1]){const b=actionButton(delta<0?'Выше':'Ниже',()=>{[p.items[i],p.items[i+delta]]=[p.items[i+delta],p.items[i]];drawItems();});b.disabled=i+delta<0||i+delta>=p.items.length;details.append(b);}rows.append(details);});const add=actionButton('Добавить элемент',()=>{p.items.push({title:'',text:'',image:'',alt:'',label:'',url:''});drawItems();});add.disabled=p.items.length>=24;rows.append(add);}drawItems();}
 const submit=el('button','Применить ко всем экземплярам');submit.type='submit';form.append(submit);d.append(form,message);
 form.onsubmit=async e=>{e.preventDefault();if(!copy.name.trim()){message.textContent='Укажите название.';return;}if(await componentChange(()=>{const at=state.draft.components.findIndex(c=>c.id===id);state.draft.components[at]=copy;}))d.close();};
 d.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='s'){e.preventDefault();e.stopPropagation();form.requestSubmit();}});
}
function componentInstance(model){
 if(!componentWritable())return;sync();const recipe=CMSComponents.reference(model.get('recipe')),component=state.draft.components.find(c=>c.id===recipe.component);if(!component)return;
 const instanceId=recipe.id,d=componentDialog(component.name);d.id='componentInstanceDialog';
 d.append(el('p','Обычные правки текста, изображений и оформления меняют только этот экземпляр. Общий блок редактируется отдельно.'),el('p','Местных настроек: '+Object.keys(recipe.overrides||{}).length));
 d.append(actionButton('Изменить общий блок',()=>{d.close();editComponent(component.id);}),actionButton('Сбросить местные изменения',async()=>{if(await componentChange(()=>{const r=state.draft.pages[page].layout.find(r=>r.id===instanceId);r.overrides={};}))d.close();}),actionButton('Отвязать экземпляр',async()=>{if(await componentChange(()=>{const layout=state.draft.pages[page].layout,index=layout.findIndex(r=>r.id===instanceId);layout[index]=CMSComponents.render(layout[index],state.draft.components);}))d.close();}));
}
function componentsPanel(){
 if(!componentWritable())return;sync();const d=componentDialog('Общие блоки сайта');d.id='componentsDialog';
 const intro=el('p','Общие блоки обновляются сразу во всех связанных экземплярах. Сохранённые «Мои блоки» остаются независимыми копиями.');d.append(intro);
 const create=el('form'),select=el('select'),label=el('label','Секция на текущей странице'),name=el('input');select.setAttribute('aria-label','Секция для общего блока');name.placeholder='Название общего блока';name.setAttribute('aria-label','Название общего блока');name.required=true;name.maxLength=100;
 const candidates=sectionModels().filter(m=>m.get('recipe').type!=='existing'&&!m.get('recipe')._componentId);for(const m of candidates){const option=el('option',sectionName(m.get('recipe')));option.value=m.get('recipe').id;select.append(option);}label.append(select);const submit=el('button','Создать из секции');submit.type='submit';submit.disabled=!candidates.length;create.append(label,name,submit);d.append(create);
 create.onsubmit=async e=>{e.preventDefault();if(await createComponent(candidates.find(m=>m.get('recipe').id===select.value),name.value))d.close();};
 const list=el('div');d.append(list);for(const component of state.draft.components||[]){const card=el('section',undefined,'site-card'),uses=CMSComponents.uses(state.draft,component.id);card.append(el('h3',component.name),el('p',uses.length+' экземпляров · '+[...new Set(uses.map(u=>pageMeta(u.page).title))].join(', ')));
  card.append(actionButton('Добавить на страницу',async()=>{if(await insertComponent(component.id))d.close();}),actionButton('Изменить общий блок',()=>{d.close();editComponent(component.id);}));
  const remove=actionButton('Удалить общий блок',async()=>{if(CMSComponents.uses(state.draft,component.id).length){status('Сначала отвяжите или удалите все экземпляры.',true);return;}if(await componentChange(()=>{state.draft.components=state.draft.components.filter(c=>c.id!==component.id);}))d.close();});remove.disabled=uses.length>0;remove.title=uses.length?'Сначала отвяжите или удалите экземпляры':'Удалить неиспользуемый общий блок';card.append(remove);list.append(card);
 }
 if(!list.children.length)list.append(el('p','Пока нет общих блоков. Создайте обычную секцию и преобразуйте её здесь.'));
}
const componentsButton=actionButton('Общие блоки',componentsPanel);componentsButton.id='componentsButton';$('#patternsBtn').after(componentsButton);
const componentBaseProperties=properties;
properties=function(model){componentBaseProperties(model);const root=rootSection(model);if(root?.get('recipe')?._componentId)$('#contextTools').append(actionButton('Связь с общим блоком',()=>componentInstance(root)));};
