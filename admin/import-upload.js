(()=>{
  const catalog=document.getElementById('catalog');if(!catalog||document.getElementById('adminUpdateUpload'))return;
  const box=document.createElement('div');box.id='adminUpdateUpload';box.style.cssText='margin:0 0 20px;padding:16px;border:1px solid #e3e3df;border-radius:14px;background:#fafaf8';
  box.innerHTML=`
    <h3 style="margin:0 0 6px">Загрузить новое обновление</h3>
    <div class="muted" style="margin-bottom:12px">Загрузите две CSV-таблицы из 1С и корневую папку с картинками. Файлы сначала попадают во временную область и становятся видимыми импорту только после полной загрузки.</div>
    <label style="display:grid;gap:5px;margin:10px 0"><b>Товары / сводка (CSV)</b><input id="adminProductsCsv" type="file" accept=".csv,text/csv"></label>
    <label style="display:grid;gap:5px;margin:10px 0"><b>Категории (CSV)</b><input id="adminCategoriesCsv" type="file" accept=".csv,text/csv"></label>
    <label style="display:grid;gap:5px;margin:10px 0"><b>Папка с картинками</b><input id="adminImagesFolder" type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/avif" multiple webkitdirectory directory></label>
    <label style="display:flex;gap:8px;align-items:center;margin:12px 0"><input id="adminRunAfterUpload" type="checkbox" checked> После загрузки сразу обновить каталог</label>
    <button class="importBtn" id="adminUploadUpdateBtn" type="button">Загрузить обновление</button>
    <progress id="adminUploadProgress" max="100" value="0" style="display:none;width:100%;margin-top:12px"></progress>
    <div id="adminUploadUpdateMsg" class="msg muted"></div>`;
  catalog.insertBefore(box,catalog.firstChild);

  const btn=document.getElementById('adminUploadUpdateBtn'),msg=document.getElementById('adminUploadUpdateMsg'),progress=document.getElementById('adminUploadProgress');
  const products=document.getElementById('adminProductsCsv'),categories=document.getElementById('adminCategoriesCsv'),images=document.getElementById('adminImagesFolder');
  const csrf=async()=>{let token=window.getProfisportCsrf?.()||'';if(!token&&window.refreshProfisportAuth){await window.refreshProfisportAuth();token=window.getProfisportCsrf?.()||'';}if(!token)throw new Error('Нужно заново войти в админку');return token;};
  async function jsonPost(url,token,body={},form=false){
    const opt={method:'POST',credentials:'same-origin',cache:'no-store',headers:{'X-CSRF-Token':token}};
    if(form)opt.body=body;else{opt.headers['Content-Type']='application/json';opt.body=JSON.stringify(body);}
    const r=await fetch(url,opt);const text=await r.text();let data;try{data=JSON.parse(text)}catch{throw new Error('Сервер вернул некорректный ответ');}
    if(!r.ok||!data.ok)throw new Error(data.error||('HTTP '+r.status));return data;
  }
  function chunks(items,maxFiles=20,maxBytes=18*1024*1024){const out=[];let chunk=[],bytes=0;for(const item of items){if(chunk.length&&(chunk.length>=maxFiles||bytes+item.file.size>maxBytes)){out.push(chunk);chunk=[];bytes=0;}chunk.push(item);bytes+=item.file.size;}if(chunk.length)out.push(chunk);return out;}
  async function uploadChunk(batch,items,token){const form=new FormData();for(const item of items){form.append('files[]',item.file,item.file.name);form.append('paths[]',item.path);}return jsonPost('../api/import-upload.php?action=upload&batch='+encodeURIComponent(batch),token,form,true);}
  async function cancel(batch,token){if(!batch)return;try{await jsonPost('../api/import-upload.php?action=cancel&batch='+encodeURIComponent(batch),token,{});}catch{}}

  btn.addEventListener('click',async()=>{
    const product=products.files?.[0],category=categories.files?.[0];if(!product||!category){msg.className='msg err';msg.textContent='Выберите обе CSV-таблицы: товары/сводка и категории.';return;}
    if(!/\.csv$/i.test(product.name)||!/\.csv$/i.test(category.name)){msg.className='msg err';msg.textContent='Для текущего импорта нужны CSV-файлы.';return;}
    const imageFiles=[...(images.files||[])];const items=[{file:product,path:'Tovary.csv'},{file:category,path:'Categories.csv'},...imageFiles.map(f=>({file:f,path:'images/'+(f.webkitRelativePath||f.name)}))];
    const totalBytes=items.reduce((s,x)=>s+x.file.size,0),parts=chunks(items);let batch='',doneBytes=0,token='';
    btn.disabled=true;progress.style.display='block';progress.value=0;msg.className='msg';msg.textContent=`Подготовка: ${items.length} файлов, ${(totalBytes/1048576).toFixed(1)} МБ`;
    try{
      token=await csrf();const start=await jsonPost('../api/import-upload.php?action=start',token,{});batch=start.batch;
      for(let i=0;i<parts.length;i++){msg.textContent=`Загрузка файлов… ${i+1} / ${parts.length}`;await uploadChunk(batch,parts[i],token);doneBytes+=parts[i].reduce((s,x)=>s+x.file.size,0);progress.value=totalBytes?Math.round(doneBytes/totalBytes*95):90;}
      msg.textContent='Проверка комплекта и публикация…';const final=await jsonPost('../api/import-upload.php?action=finalize&batch='+encodeURIComponent(batch),token,{});batch='';progress.value=100;
      msg.className='msg ok';msg.textContent=`Обновление загружено: ${final.files} файлов, изображений ${final.images}. FileZilla не нужен.`;
      document.getElementById('refreshImports')?.click();
      if(document.getElementById('adminRunAfterUpload')?.checked){setTimeout(()=>document.getElementById('run1cImportBtn')?.click(),400);}
    }catch(e){msg.className='msg err';msg.textContent='Загрузка остановлена: '+e.message;await cancel(batch,token);}finally{btn.disabled=false;}
  });
})();