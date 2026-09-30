'use strict';
function collectionListDialog(collectionId,instanceId=null){
 if(!componentWritable())return;sync();const c=state.draft.collections.find(c=>c.id===collectionId);if(!c)return;
 const existing=state.draft.pages[page].layout.find(r=>r.id===instanceId),props=CMBlocks.defaults('cards');delete props.items;props.title=c.name;props.text='';
 const recipe=structuredClone(existing||{id:'list-'+crypto.randomUUID(),type:'collection',collection:c.id,mapping:{title:c.titleField},query:{pageSize:6,sortField:'',direction:'asc',filterField:''},props});
 const d=collectionDialog('Список · '+c.name,'collectionListDialog'),form=el('form'),message=el('p');message.setAttribute('role','status');d.append(el('p','Карточки обновляются из коллекции. Здесь настраивается их отображение; содержимое меняется в записях. Изменения появятся на сайте после публикации.'),form,message);
 for(const [key,label] of [['title','Заголовок списка'],['text','Описание списка']]){const input=collectionInput(form,label,recipe.props[key],key==='text'?'textarea':'text');input.oninput=()=>recipe.props[key]=input.value;}
 for(const [prop,label] of [['title','Название карточки'],['text','Описание карточки'],['image','Изображение карточки'],['alt','Описание изображения']]){const choices=prop==='title'?{}:{'':'Не выводить'};for(const f of c.fields)if(prop!=='image'||f.type==='image')choices[f.key]=f.label;const input=collectionSelect(form,label,choices,recipe.mapping[prop]||'');input.onchange=()=>{if(input.value)recipe.mapping[prop]=input.value;else delete recipe.mapping[prop];};}
 const filterFields={'':'Без фильтра'},sortFields={'':'Порядок записей'};for(const f of c.fields){sortFields[f.key]=f.label;if(['string','number','boolean','date','reference'].includes(f.type))filterFields[f.key]=f.label;}
 for(const [key,label,choices] of [['sortField','Сортировать по полю',sortFields],['direction','Направление',{asc:'По возрастанию',desc:'По убыванию'}],['filterField','Фильтр для посетителей',filterFields]]){const input=collectionSelect(form,label,choices,recipe.query[key]);input.onchange=()=>recipe.query[key]=input.value;}
 const size=collectionInput(form,'Записей на странице',recipe.query.pageSize,'number');size.min='1';size.max='24';size.required=true;size.oninput=()=>recipe.query.pageSize=Number(size.value);
 const columns=collectionSelect(form,'Колонки',{'2':'2','3':'3','4':'4'},recipe.props.columns||'3');columns.onchange=()=>recipe.props.columns=columns.value;
 for(const [key,label] of [['background','Фон'],['color','Цвет текста'],['accent','Цвет кнопки']]){const input=collectionInput(form,label,recipe.props[key],'color');input.oninput=()=>recipe.props[key]=input.value;}
 const submit=el('button',existing?'Сохранить настройки списка':'Добавить список на страницу');submit.type='submit';form.append(submit);
 async function apply(remove=false){sync();const draft=structuredClone(state.draft),layout=draft.pages[page].layout,at=layout.findIndex(r=>r.id===instanceId);if(remove)layout.splice(at,1);else if(existing)layout[at]=recipe;else layout.push(recipe);d.inert=true;try{await api('validate-collections',{collections:draft.collections,pages:draft.pages,components:draft.components||[]});state.draft.pages[page].layout=layout;await renderPage();changed();d.close();}catch(error){message.textContent=error.message;}finally{d.inert=false;}}
 form.onsubmit=async e=>{e.preventDefault();await apply();};if(existing)form.append(actionButton('Удалить список со страницы',()=>apply(true)));
}
labels['collection-list']='Список коллекции';
const collectionListPanelBase=collectionsPanel;
collectionsPanel=function(){collectionListPanelBase();const d=$('#collectionsDialog');if(!d)return;const cards=[...d.querySelectorAll('.site-card')];(state.draft.collections||[]).forEach((c,i)=>cards[i].append(actionButton('Добавить список на страницу',()=>{d.close();collectionListDialog(c.id);})))};
collectionsButton.onclick=()=>collectionsPanel();
const collectionOpenBase=openSection;
openSection=function(id){const recipe=sectionModels().find(m=>m.get('recipe').id===id)?.get('recipe');if(recipe?._collectionRecipe){collectionListDialog(recipe._collectionRecipe.collection,id);return;}return collectionOpenBase(id);};
const collectionConfigureBase=configureNewModels;
configureNewModels=function(root){if(root.get('recipe')?._collectionRecipe)return;collectionConfigureBase(root);};
const collectionPropertiesBase=properties;
properties=function(model){const root=rootSection(model),recipe=root?.get('recipe');if(!recipe?._collectionRecipe)return collectionPropertiesBase(model);const bar=$('#contextTools');bar.replaceChildren(actionButton('Настроить список',()=>{$('#editDialog').close();collectionListDialog(recipe._collectionRecipe.collection,recipe.id);}));bar.hidden=!$('#editDialog').open;positionTools();};
