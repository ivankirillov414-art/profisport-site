(()=>{
  const catalog=document.getElementById('catalog');if(!catalog||document.getElementById('adminUpdateUpload'))return;
  const box=document.createElement('div');box.id='adminUpdateUpload';box.style.cssText='margin:0 0 20px;padding:16px;border:1px solid #e3e3df;border-radius:14px;background:#fafaf8';
  box.innerHTML=`
    <h3 style="margin:0 0 6px">Загрузить новое обновление</h3>
    <div class="muted" style="margin-bottom:12px">Обязательны только две CSV-таблицы. Картинки можно не загружать или добавить только часть. Существующие фотографии сохраняются; отсутствие новых фото не мешает обновлению товаров.</div>
    <label style="display:grid;gap:5px;margin:10px 0"><b>Товары / сводка (CSV)</b><input id="adminProductsCsv" type="file" accept=".csv,text/csv"></label>
    <label style="display:grid;gap:5px;margin:10px 0"><b>Категории (CSV)</b><input id="adminCategoriesCsv" type="file" accept=".csv,text/csv"></label>
    <label style="display:flex;gap:8px;align-items:center;margin:12px 0"><input id="adminUploadWithoutImages" type="checkbox"> Без новых картинок — обновить только таблицы</label>
    <fieldset id="adminOptionalImages" style="border:1px solid #e3e3df;border-radius:10px;min-width:0;margin:10px 0">
      <legend>Картинки — необязательно</legend>
      <label style="display:grid;gap:5px;margin:10px 0"><b>Папка с картинками</b><input id="adminImagesFolder" type="file" accept=".jpg,.jpeg,.png,.webp,.gif,.avif" multiple webkitdirectory directory></label>
      <label style="display:grid;gap:5px;margin:10px 0"><b>Или отдельные картинки</b><input id="adminImageFiles" type="file" accept=".jpg,.jpeg,.png,.webp,.gif,.avif" multiple></label>
      <small class="muted">Выберите только нужные файлы или оставьте оба поля пустыми. Повторно загружать все фотографии не нужно.</small>
    </fieldset>
    <label style="display:flex;gap:8px;align-items:center;margin:12px 0"><input id="adminRunAfterUpload" type="checkbox" checked> После загрузки сразу обновить каталог</label>
    <button class="importBtn" id="adminUploadUpdateBtn" type="button">Загрузить обновление</button>
    <progress id="adminUploadProgress" max="100" value="0" style="display:none;width:100%;margin-top:12px"></progress>
    <div id="adminUploadUpdateMsg" class="msg muted" role="status" aria-live="polite"></div>`;
  catalog.insertBefore(box,catalog.firstChild);

  const btn=document.getElementById('adminUploadUpdateBtn'),msg=document.getElementById('adminUploadUpdateMsg'),progress=document.getElementById('adminUploadProgress');
  const products=document.getElementById('adminProductsCsv'),categories=document.getElementById('adminCategoriesCsv'),images=document.getElementById('adminImagesFolder');
  const separateImages=document.getElementById('adminImageFiles'),withoutImages=document.getElementById('adminUploadWithoutImages'),photoFields=document.getElementById('adminOptionalImages'),runAfter=document.getElementById('adminRunAfterUpload');
  let busy=false;
  function syncControls(){
    for(const control of [btn,products,categories,withoutImages,runAfter])control.disabled=busy;
    photoFields.disabled=busy||withoutImages.checked;
    images.disabled=separateImages.disabled=photoFields.disabled;
  }
  withoutImages.addEventListener('change',syncControls);syncControls();
  const csrf=async()=>{let token=window.getProfisportCsrf?.()||'';if(!token&&window.refreshProfisportAuth){await window.refreshProfisportAuth();token=window.getProfisportCsrf?.()||'';}if(!token)throw new Error('Нужно заново войти в админку');return token;};
  async function jsonPost(url,token,body={},form=false){
    const opt={method:'POST',credentials:'same-origin',cache:'no-store',headers:{'X-CSRF-Token':token}};
    if(form)opt.body=body;else{opt.headers['Content-Type']='application/json';opt.body=JSON.stringify(body);}
    const r=await fetch(url,opt);const text=await r.text();let data;try{data=JSON.parse(text)}catch{throw new Error('Сервер вернул некорректный ответ');}
    if(!r.ok||!data.ok)throw new Error(data.error||('HTTP '+r.status));return data;
  }
  function chunks(items,maxFiles=20,maxBytes=18*1024*1024){const out=[];let chunk=[],bytes=0;for(const item of items){if(chunk.length&&(chunk.length>=maxFiles||bytes+item.file.size>maxBytes)){out.push(chunk);chunk=[];bytes=0;}chunk.push(item);bytes+=item.file.size;}if(chunk.length)out.push(chunk);return out;}
  async function uploadChunk(batch,items,token){
    const form=new FormData();for(const item of items){form.append('files[]',item.file,item.file.name);form.append('paths[]',item.path);}
    const result=await jsonPost('../api/import-upload.php?action=upload&batch='+encodeURIComponent(batch),token,form,true);
    if(Number(result.saved)!==items.length)throw new Error('Сервер не подтвердил получение всех выбранных файлов. Каталог не обновлён.');
    return result;
  }
  async function cancel(batch,token){if(!batch)return;try{await jsonPost('../api/import-upload.php?action=cancel&batch='+encodeURIComponent(batch),token,{});}catch{}}

  btn.addEventListener('click',async()=>{
    if(busy)return;
    const product=products.files?.[0],category=categories.files?.[0];if(!product||!category){msg.className='msg err';msg.textContent='Выберите обе CSV-таблицы: товары/сводка и категории. Картинки необязательны.';return;}
    if(!/\.csv$/i.test(product.name)||!/\.csv$/i.test(category.name)){msg.className='msg err';msg.textContent='Для текущего импорта нужны CSV-файлы.';return;}
    // A folder or a full photo set is never required. Explicitly opting out also ignores old selections.
    const selectedImages=withoutImages.checked?[]:[...(images.files||[]),...(separateImages.files||[])];
    const imageFiles=selectedImages.filter(f=>/\.(jpe?g|png|webp|gif|avif)$/i.test(f.name));
    const skipped=selectedImages.length-imageFiles.length;
    const photoItems=new Map();
    for(const file of imageFiles){
      const path='images/'+(file.webkitRelativePath||file.name),key=path.toLowerCase();
      const prior=photoItems.get(key);
      if(prior&&prior.file!==file){msg.className='msg err';msg.textContent='Два выбранных файла имеют одинаковый путь: '+path+'. Оставьте один файл или включите «Без новых картинок».';return;}
      photoItems.set(key,{file,path});
    }
    const items=[{file:product,path:'Tovary.csv'},{file:category,path:'Categories.csv'},...photoItems.values()];
    const totalBytes=items.reduce((s,x)=>s+x.file.size,0),parts=chunks(items),shouldRun=runAfter.checked;let batch='',doneBytes=0,token='';
    busy=true;syncControls();progress.style.display='block';progress.value=0;msg.className='msg';msg.textContent=`Подготовка: 2 таблицы, новых картинок ${photoItems.size}, ${(totalBytes/1048576).toFixed(1)} МБ`;
    try{
      token=await csrf();const start=await jsonPost('../api/import-upload.php?action=start',token,{});batch=start.batch;
      for(let i=0;i<parts.length;i++){msg.textContent=`Загрузка выбранных файлов… ${i+1} / ${parts.length}`;await uploadChunk(batch,parts[i],token);doneBytes+=parts[i].reduce((s,x)=>s+x.file.size,0);progress.value=totalBytes?Math.round(doneBytes/totalBytes*95):90;}
      msg.textContent='Проверка таблиц и публикация выбранных файлов…';const final=await jsonPost('../api/import-upload.php?action=finalize&batch='+encodeURIComponent(batch),token,{});batch='';
      if(Number(final.files)!==items.length||Number(final.images)!==photoItems.size)throw new Error('Число опубликованных файлов отличается от выбранных. Обновление каталога не запущено; проверьте список файлов.');
      progress.value=100;msg.className='msg ok';
      msg.textContent=photoItems.size===0?'Таблицы загружены без новых картинок. Существующие фотографии не удалены.':`Таблицы загружены, добавлено картинок: ${final.images}. Остальные фотографии сохранены.`;
      if(skipped)msg.textContent+=` Пропущено файлов, не являющихся изображениями: ${skipped}.`;
      document.getElementById('refreshImports')?.click();
      if(shouldRun){msg.textContent+=' Результат обновления каталога появится ниже.';setTimeout(()=>document.getElementById('run1cImportBtn')?.click(),400);}
      else msg.textContent+=' Для применения нажмите «Обновить каталог» ниже.';
    }catch(e){msg.className='msg err';msg.textContent='Загрузка остановлена: '+e.message;await cancel(batch,token);}finally{busy=false;syncControls();}
  });
})();
