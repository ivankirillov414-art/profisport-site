(()=>{
  const catalog=document.getElementById('catalog');if(!catalog||document.getElementById('adminUpdateUpload'))return;
  const box=document.createElement('div');box.id='adminUpdateUpload';box.style.cssText='margin:0 0 20px;padding:16px;border:1px solid #e3e3df;border-radius:14px;background:#fafaf8;scroll-margin-top:80px';
  box.innerHTML=`
    <h3 style="margin:0 0 6px">Загрузить обновление</h3>
    <p class="muted">Одна CSV-таблица с любым названием и фотографии отдельными файлами. Вторая таблица и папки не нужны.</p>
    <label style="display:grid;gap:5px;margin:12px 0"><b>Таблица товаров (CSV)</b><input id="adminProductsCsv" type="file" accept=".csv,text/csv"></label>
    <label style="display:grid;gap:5px;margin:12px 0"><b>Выбрать фотографии</b><input id="adminImagesFiles" type="file" accept=".jpg,.jpeg,.png,.webp,.gif,.avif" multiple></label>
    <p class="muted" style="margin:6px 0">Можно выбрать сразу несколько файлов и добавить ещё следующим выбором. Названия фотографий должны совпадать со ссылками в таблице. Без новых фото сохраняются прежние.</p>
    <div><span id="adminImagesCount">Фотографии не выбраны</span> <button id="adminClearImages" class="secondary" type="button">Очистить выбор фото</button></div>
    <label style="display:flex;gap:8px;align-items:center;margin:12px 0"><input id="adminRunAfterUpload" type="checkbox" checked> После загрузки обновить каталог</label>
    <button class="importBtn" id="adminUploadUpdateBtn" type="button">Загрузить обновление</button>
    <progress id="adminUploadProgress" max="100" value="0" style="display:none;width:100%;margin-top:12px"></progress>
    <div id="adminUploadUpdateMsg" class="msg muted" role="status" aria-live="polite"></div>`;
  catalog.insertBefore(box,catalog.firstChild);
  const $=id=>document.getElementById(id),btn=$('adminUploadUpdateBtn'),msg=$('adminUploadUpdateMsg'),progress=$('adminUploadProgress');
  const products=$('adminProductsCsv'),images=$('adminImagesFiles'),clear=$('adminClearImages'),selected=new Map();let busy=false;
  const count=()=>{$('adminImagesCount').textContent=selected.size?`Выбрано фотографий: ${selected.size}`:'Фотографии не выбраны';};
  images.addEventListener('change',()=>{
    if(busy)return;
    const incoming=[...(images.files||[])];
    try{
      const next=new Map(selected);
      for(const f of incoming){
        if(!/\.(jpe?g|png|webp|gif|avif)$/i.test(f.name))throw new Error(`Неподдерживаемый формат: ${f.name}`);
        const key=f.name.toLocaleLowerCase('ru-RU'),old=next.get(key);
        if(old&&(old.size!==f.size||old.lastModified!==f.lastModified))throw new Error(`Разные фотографии называются одинаково: ${f.name}. Очистите выбор и оставьте нужный файл.`);
        next.set(key,f);
      }
      selected.clear();for(const [key,f] of next)selected.set(key,f);count();msg.textContent='';
    }catch(e){msg.className='msg err';msg.textContent=e.message;}finally{images.value='';}
  });
  clear.onclick=()=>{if(!busy){selected.clear();images.value='';count();}};
  async function token(){
    let value=window.getProfisportCsrf?.()||'';
    if(!value){const r=await fetch('../server/api.php?action=me',{credentials:'same-origin',cache:'no-store'});const d=await r.json();if(!r.ok||!d.ok)throw new Error('Нужно заново войти в админку');value=d.csrf||'';}
    if(!value)throw new Error('Нужно заново войти в админку');return value;
  }
  async function post(action,batch,csrf,body={},form=false){
    const url='../api/import-upload.php?action='+action+(batch?'&batch='+encodeURIComponent(batch):'');
    const options={method:'POST',credentials:'same-origin',cache:'no-store',headers:{'X-CSRF-Token':csrf},body:form?body:JSON.stringify(body)};
    if(!form)options.headers['Content-Type']='application/json';
    const r=await fetch(url,options);let d;try{d=await r.json();}catch{throw new Error(`Сервер не подтвердил загрузку (HTTP ${r.status}).`);}
    if(!r.ok||d.ok!==true)throw new Error(d.error||`Ошибка HTTP ${r.status}`);return d;
  }
  function chunks(items,limits){
    const out=[];let part=[],bytes=0;
    for(const item of items){
      if(item.file.size<1||item.file.size>Math.min(limits.max_file_bytes,limits.max_batch_bytes))throw new Error(`Файл «${item.file.name}» пуст или превышает лимит хостинга (${Math.floor(Math.min(limits.max_file_bytes,limits.max_batch_bytes)/1048576)} МБ).`);
      if(part.length&&(part.length>=limits.max_files||bytes+item.file.size>limits.max_batch_bytes)){out.push(part);part=[];bytes=0;}
      part.push(item);bytes+=item.file.size;
    }
    if(part.length)out.push(part);return out;
  }
  btn.addEventListener('click',async()=>{
    if(busy)return;
    const file=products.files?.[0];if(!file||!/\.csv$/i.test(file.name)){msg.className='msg err';msg.textContent='Выберите одну CSV-таблицу. Название файла может быть любым.';return;}
    const items=[{file,path:file.name},...[...selected.values()].map(file=>({file,path:'images/'+file.name}))];
    const total=items.reduce((n,x)=>n+x.file.size,0);let batch='',csrf='',sent=0,published=false;
    busy=true;for(const el of [btn,products,images,clear])el.disabled=true;
    progress.style.display='block';progress.value=0;msg.className='msg';msg.textContent='Подготовка загрузки…';
    try{
      csrf=await token();const start=await post('start','',csrf);batch=start.batch;
      const parts=chunks(items,start.limits||{max_file_bytes:18*1048576,max_batch_bytes:18*1048576,max_files:20});
      for(let i=0;i<parts.length;i++){
        msg.textContent=`Загрузка ${i+1} / ${parts.length}: одна таблица и ${selected.size} фото`;
        const body=new FormData();for(const item of parts[i]){body.append('files[]',item.file,item.file.name);body.append('paths[]',item.path);}
        const r=await post('upload',batch,csrf,body,true);if(r.saved!==parts[i].length)throw new Error('Сервер сохранил не все файлы. Каталог не обновлён.');
        sent+=parts[i].reduce((n,x)=>n+x.file.size,0);progress.value=total?Math.round(sent/total*95):90;
      }
      msg.textContent='Проверка комплекта…';const result=await post('finalize',batch,csrf);published=true;batch='';progress.value=100;
      msg.className='msg ok';msg.textContent=`Загружено: одна таблица «${file.name}» и ${result.images} фото.`;
      $('refreshImports')?.click();
      if($('adminRunAfterUpload').checked){
        const run=$('run1cImportBtn');if(!run||run.disabled)msg.textContent+=' Каталог ещё не обновлён: нажмите «Обновить каталог» после завершения текущей операции.';
        else{msg.textContent+=' Запускаю обновление каталога — результат появится ниже.';run.click();}
      }
    }catch(e){
      msg.className='msg err';msg.textContent='Загрузка остановлена: '+e.message;
      if(batch&&!published)await post('cancel',batch,csrf).catch(()=>{});
    }finally{busy=false;for(const el of [btn,products,images,clear])el.disabled=false;}
  });
})();
