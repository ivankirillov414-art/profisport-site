'use strict';
(function(root){
 function store(storage,namespace,id){
  const prefix='id-studio-recovery:'+JSON.stringify(namespace)+':',key=prefix+id;
  function list(){const records=[];for(let i=0;i<storage.length;i++){
   const k=storage.key(i);if(!k?.startsWith(prefix))continue;
   try{const value=JSON.parse(storage.getItem(k));if(value?.schema===1&&Number.isInteger(value.version)&&value.draft?.pages&&typeof value.draft.pages==='object')records.push({...value,key:k});}catch{}
  }return records.sort((a,b)=>b.updatedAt-a.updatedAt);}
  return {key,list,write(draft,version){storage.setItem(key,JSON.stringify({schema:1,version,draft,updatedAt:Date.now()}));},remove(k=key){if(!k.startsWith(prefix))throw Error('Wrong recovery namespace');storage.removeItem(k);}};
 }
 function create({getState,isDirty,syncDraft,settle,resume,loadRemote,apply,report,enabled=true}){
  let cache=null,identity='',dialog=null,adopted=new Set();
  const tab=crypto.randomUUID();
  const button=document.createElement('button');button.id='recoveryButton';button.type='button';button.textContent='Локальные копии';button.hidden=!enabled;
  document.querySelector('#save').parentElement.append(button);
  function storage(){const s=getState();if(!enabled||!s)return null;
   const next=JSON.stringify([location.pathname.replace(/[^/]*$/,''),s.user,s.site.key]);
   if(identity!==next){cache=store(localStorage,JSON.parse(next),tab);identity=next;adopted=new Set();}return cache;
  }
  function capture(){try{const c=storage();if(c&&isDirty()){syncDraft();c.write(getState().draft,getState().version);button.textContent='Локальные копии';}}catch{button.textContent='Локальная копия недоступна';report('Не удалось записать локальную копию. Не закрывайте вкладку до сохранения на сервере.',true);}}
  function saved(){try{if(isDirty())capture();else {const c=storage();if(c){c.remove();for(const key of adopted)c.remove(key);adopted.clear();}}}catch{report('Не удалось очистить локальную копию.',true);}}
  function node(tag,text){const n=document.createElement(tag);n.textContent=text;return n;}
  function download(record){const url=URL.createObjectURL(new Blob([JSON.stringify(record.draft,null,2)],{type:'application/json'})),a=document.createElement('a');a.href=url;a.download='id-studio-draft.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}
  function display(remote,records){
   dialog.replaceChildren(node('h2','Восстановление черновика'));
   if(!records.length){dialog.append(node('p','Несохранённых локальных копий нет.'));const close=node('button','Закрыть');close.onclick=()=>dialog.close();dialog.append(close);return;}
   const choice=node('select','');choice.setAttribute('aria-label','Локальная копия');choice.style.cssText='max-width:100%;padding:10px;margin-bottom:12px';
   records.forEach((r,i)=>{const option=node('option',new Date(r.updatedAt).toLocaleString()+' · на основе версии '+r.version);option.value=String(i);choice.append(option);});
   const body=node('div','');dialog.append(choice,body);
   function draw(){const record=records[Number(choice.value)];body.replaceChildren();
    const conflict=record.version!==remote.version;
    body.append(node('p',conflict?'Серверный черновик изменился. Локальная копия заменит весь текущий черновик, включая другие страницы. Сравните версии перед выбором.':'Найдена несохранённая локальная копия. Выберите, какой черновик открыть.'));
    const affected=[...new Set([...Object.keys(record.draft.pages),...Object.keys(remote.draft.pages)])].filter(key=>JSON.stringify(record.draft.pages[key])!==JSON.stringify(remote.draft.pages[key]));
    body.append(node('p','Отличаются страницы: '+(affected.map(key=>remote.manifest.pages[key]?.title||record.draft.pages[key]?.title||remote.draft.pages[key]?.title||key).join(', ')||'нет различий')+'.'));
    body.append(node('p','Опубликованный сайт не изменится. Локальная копия будет отправлена только после вашего выбора.'));
    for(const [label,draft] of [['Локальная копия',record.draft],['Серверная версия '+remote.version,remote.draft]]){const details=node('details',''),pre=node('pre',JSON.stringify(draft,null,2));pre.style.cssText='max-height:240px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere';details.append(node('summary',label));for(const key of affected){const p=draft.pages[key];details.append(node('h3',remote.manifest.pages[key]?.title||p?.title||key));if(!p){details.append(node('p','Страница отсутствует'));continue;}for(const [id,value] of Object.entries(p.fields||{})){const other=(draft===record.draft?remote.draft:record.draft).pages[key]?.fields?.[id];if(value===other)continue;const name=remote.manifest.pages[key]?.fields?.find(f=>f.id===id)?.label||'Поле страницы';details.append(node('p',name+': '+String(value)));}if(p.layout){details.append(node('p','Секций: '+p.layout.length));for(const block of p.layout){const props=block.props||{};const text=[props.title,props.text,...(props.items||[]).flatMap(item=>[item.title,item.text])].filter(Boolean).join(' · ');if(text)details.append(node('p',text));}}}const raw=node('details','');raw.append(node('summary','Подробные данные'),pre);details.append(raw);body.append(details);}
    const exportButton=node('button','Скачать локальную копию');exportButton.onclick=()=>download(record);
    const local=node('button',conflict?'Заменить черновик локальной копией':'Восстановить локальную копию');local.id='recoverLocal';local.className='primary';
    const server=node('button','Оставить серверную версию');server.id='recoverServer';
    const later=node('button','Решить позже');later.onclick=()=>dialog.close();
    async function choose(useLocal){local.disabled=server.disabled=true;try{
     await apply(remote,useLocal?record.draft:null);if(useLocal){adopted.add(record.key);capture();}else {saved();storage().remove(record.key);}dialog.close();
    }catch(error){report(error.message,true);local.disabled=server.disabled=false;}}
    local.onclick=()=>choose(true);server.onclick=()=>choose(false);const actions=node('div','');actions.style.cssText='display:flex;flex-wrap:wrap;gap:10px;margin-top:20px';actions.append(local,server,exportButton,later);body.append(actions);
   }choice.onchange=draw;draw();
  }
  async function open(remote=null){if(dialog?.open)return;
   if(!dialog){dialog=document.createElement('dialog');dialog.addEventListener('close',resume);}dialog.id='recoveryDialog';dialog.style.cssText='max-width:850px;width:calc(100% - 40px);max-height:85vh;overflow:auto';document.body.append(dialog);dialog.replaceChildren(node('p','Загружаю версии…'));dialog.showModal();
   try{await settle();capture();remote??=await loadRemote();if(remote.user!==getState().user||remote.site.key!==getState().site.key)throw Error('Сеанс пользователя изменился. Обновите страницу перед восстановлением.');display(structuredClone(remote),storage()?.list()||[]);}catch(error){dialog.replaceChildren(node('p','Не удалось загрузить серверную версию: '+error.message));const close=node('button','Закрыть');close.onclick=()=>dialog.close();dialog.append(close);}
  }
  button.onclick=()=>open();
  return {capture,saved,async offer(){try{if(storage()?.list().length)await open(getState());}catch{report('Локальное восстановление недоступно в этом браузере.',true);}}};
 }
 if(typeof module==='object'&&module.exports)module.exports={store};else root.CMSRecovery={store,create};
})(globalThis);
