(()=>{
'use strict';
const $=id=>document.getElementById(id),esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
const dialog=$('photoEditor');let product=null,csrf='',busy=false,canEdit=false,limits={},page=1,searchSerial=0,detailSerial=0,pending=null,previewUrl='';
function preview(url){
  url=String(url||'');if(url.startsWith('/import/'))return '../api/product-image.php?p='+encodeURIComponent(url.slice(8));
  if(url.startsWith('import/'))return '../api/product-image.php?p='+encodeURIComponent(url.slice(7));
  if(/^https:\/\//i.test(url)||/^\/(?:uploads|assets)\//.test(url))return encodeURI(url);
  if(/^(?:uploads|assets|api)\//.test(url))return '../'+encodeURI(url);return '';
}
async function request(url,options={}){
  const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...options});let body;
  try{body=await response.json();}catch{throw new Error('Сервер не подтвердил результат. Обновите карточку перед повторением.');}
  if(!response.ok||body.ok!==true)throw Object.assign(new Error(body.error||'Ошибка сервера.'),{status:response.status});return body;
}
function notice(text,error=false){$('editorMessage').textContent=text;$('editorMessage').className=error?'bad':'ok';}
function resetPending(){pending=null;if(previewUrl)URL.revokeObjectURL(previewUrl);previewUrl='';$('pendingPhoto').hidden=true;$('photoFile').value='';}
function controls(){
  for(const b of dialog.querySelectorAll('button'))b.disabled=busy;
  $('addPhoto').disabled=busy||!canEdit||!limits.image_processing;
  $('resetSource').disabled=busy||!canEdit||!product?.manual;
  $('savePhoto').disabled=busy||!pending||!canEdit;
  $('photoFile').disabled=busy;
}
function render(){
  if(!product)return;
  $('editorTitle').textContent=product.name;
  $('editorMeta').textContent='#'+product.id+(product.sku?' · '+product.sku:'')+(product.active?'':' · скрыт с витрины');
  $('editorMode').textContent=product.manual?'Ручная галерея. Следующая выгрузка из 1С её не изменит.':'Фотографии из 1С. После первого изменения галерея станет ручной.';
  const images=product.images||[];
  $('photoGallery').innerHTML=images.length?images.map((url,i)=>`<article class="photo-item"><div class="photo-preview"><img src="${esc(preview(url))}" alt="Фото товара ${i+1}" loading="lazy"></div><b>${i===0?'Главное фото':'Фото '+(i+1)}</b><div class="photo-actions">${canEdit?`<button data-photo="primary" data-index="${i}" ${i===0?'disabled':''}>Сделать главным</button><button data-photo="replace" data-index="${i}">Заменить</button><button class="danger" data-photo="remove" data-index="${i}">Убрать</button>`:''}</div></article>`).join(''):'<p class="muted">У этого товара пока нет фотографий. Нажмите «Добавить фотографию».</p>';
  if(!limits.image_processing)$('uploadHint').textContent='Обработка JPG/PNG/WebP недоступна на хостинге. Выбор главного фото и удаление из галереи доступны.';
  else $('uploadHint').textContent='JPG, PNG или WebP, до '+(limits.max_file_bytes/1048576).toFixed(1)+' МБ. Сначала появится предпросмотр.';
  controls();
  const first=$('photoGallery').querySelector('[data-photo="primary"][data-index="0"]');if(first)first.disabled=true;
}
async function openProduct(id){
  if(busy)return;const serial=++detailSerial;resetPending();product=null;notice('Загрузка карточки…');$('photoGallery').innerHTML='';$('editorTitle').textContent='Фотографии товара';if(!dialog.open)dialog.showModal();
  busy=true;controls();
  try{const d=await request('../api/product-photos.php?id='+Number(id));if(serial!==detailSerial)return;product=d.product;csrf=d.csrf;limits=d.limits;canEdit=d.can_edit;notice('');}
  catch(e){notice(e.message,true);}finally{if(serial===detailSerial){busy=false;render();controls();}}
}
async function list(){
  const serial=++searchSerial;$('searchMessage').textContent='Поиск…';
  try{
    const q=new URLSearchParams({q:$('photoSearch').value.trim(),page:String(page)});const d=await request('../api/product-photos.php?'+q);if(serial!==searchSerial)return;
    csrf=d.csrf;limits=d.limits;canEdit=d.can_edit;$('searchMessage').textContent='Найдено товаров: '+d.total;
    $('photoProducts').innerHTML=d.items.length?d.items.map(p=>`<button type="button" class="product-result" data-product="${p.id}"><span class="result-preview">${p.image?`<img src="${esc(preview(p.image))}" alt="" loading="lazy">`:'Нет фото'}</span><span><b>${esc(p.name)}</b><small>#${p.id}${p.sku?' · '+esc(p.sku):''} · ${p.manual?'Ручные фото':'Фото 1С'}${p.active?'':' · Скрыт'}</small><small>${esc(p.category)}</small><strong>Редактировать фото →</strong></span></button>`).join(''):'<p>Товары не найдены. Попробуйте название, артикул или ID.</p>';
    $('prevPhotos').disabled=page<=1;$('nextPhotos').disabled=page*20>=d.total;$('photoPage').textContent='Страница '+page;
  }catch(e){if(serial===searchSerial)$('searchMessage').textContent=e.message;}
}
async function mutate(action,target='',file=null){
  if(busy||!product||!canEdit)return;
  const id=product.id,revision=product.revision;busy=true;controls();notice('Сохраняю…');
  try{
    const args={revision,request_id:crypto.randomUUID(),target};let body,headers={'X-CSRF-Token':csrf};
    if(file){body=new FormData();for(const [k,v] of Object.entries(args))body.append(k,String(v));body.append('photo',file,file.name);}
    else{body=JSON.stringify(args);headers['Content-Type']='application/json';}
    const d=await request('../api/product-photos.php?id='+id+'&action='+action,{method:'POST',headers,body});
    product=d.product;resetPending();notice(action==='reset'?'Восстановлены фотографии из последней выгрузки 1С.':'Фотографии сохранены.');render();await list();
  }catch(e){
    // Reconcile a lost acknowledgement before offering another mutation.
    try{const d=await request('../api/product-photos.php?id='+id);product=d.product;csrf=d.csrf;limits=d.limits;canEdit=d.can_edit;render();}catch{product=null;canEdit=false;}
    resetPending();notice(e.message+' Проверьте галерею перед повторением.',true);
  }finally{busy=false;controls();render();}
}
let selectionAction='upload',selectionTarget='';
function choose(action,target=''){if(busy||!canEdit)return;selectionAction=action;selectionTarget=target;$('photoFile').value='';$('photoFile').click();}
$('photoFile').addEventListener('change',()=>{
  const file=$('photoFile').files?.[0];if(!file||!product)return;
  if(!/\.(jpe?g|png|webp)$/i.test(file.name)||file.size<1||file.size>limits.max_file_bytes){notice('Выберите JPG, PNG или WebP в пределах указанного размера.',true);return;}
  resetPending();previewUrl=URL.createObjectURL(file);pending={file,action:selectionAction,target:selectionTarget};
  $('pendingImage').src=previewUrl;$('pendingName').textContent=(selectionAction==='replace'?'Замена: ':'Новое фото: ')+file.name;$('pendingPhoto').hidden=false;notice('Проверьте снимок и нажмите «Сохранить фотографию».');controls();
});
$('addPhoto').onclick=()=>choose('upload');$('savePhoto').onclick=()=>{if(pending)mutate(pending.action,pending.target,pending.file);};$('cancelPhoto').onclick=()=>{resetPending();controls();};
$('photoGallery').addEventListener('click',event=>{
  const b=event.target.closest('[data-photo]');if(!b||busy||!product)return;const url=product.images[Number(b.dataset.index)];if(!url)return;
  if(b.dataset.photo==='replace')choose('replace',url);
  else if(b.dataset.photo==='remove'){if(confirm('Убрать фотографию из карточки? Исходный файл останется сохранён.'))mutate('remove',url);}
  else mutate('primary',url);
});
$('resetSource').onclick=()=>{if(confirm('Вернуть галерею из текущей выгрузки 1С вместо ручной? Загруженные файлы не удаляются.'))mutate('reset');};
$('closeEditor').onclick=()=>{if(!busy){resetPending();dialog.close();}};dialog.addEventListener('cancel',e=>{if(busy)e.preventDefault();else resetPending();});
$('photoProducts').addEventListener('click',e=>{const b=e.target.closest('[data-product]');if(b)openProduct(b.dataset.product);});
$('examples').addEventListener('click',e=>{const b=e.target.closest('[data-product]');if(b)openProduct(b.dataset.product);});
$('photoSearchForm').onsubmit=e=>{e.preventDefault();page=1;list();};$('prevPhotos').onclick=()=>{if(page>1){page--;list();}};$('nextPhotos').onclick=()=>{page++;list();};
async function health(){
  $('refresh').disabled=true;$('generated').textContent='Проверка…';
  try{const d=await request('../api/photo-health.php?v=manual-1'),s=d.stats||{};
    $('generated').textContent='Проверено: '+d.generated_at;
    $('metrics').innerHTML=[[s.active_products,'Активных товаров'],[s.products_with_working_image,'С рабочим фото'],[s.products_without_db_image,'Без фото'],[s.products_with_manual_gallery,'Ручных галерей']].map(([n,t])=>`<div class="metric"><b>${Number(n||0)}</b><span>${t}</span></div>`).join('');
    $('examples').innerHTML=(d.unresolved_examples||[]).map(p=>`<button class="health-row" data-product="${p.id}">#${p.id} · ${esc(p.name)}<small>${esc(p.reason)} · Добавить фото →</small></button>`).join('')||'<p class="ok">Нет выявленных проблем с фотографиями.</p>';
  }catch(e){$('generated').textContent=e.message;}finally{$('refresh').disabled=false;}
}
$('refresh').onclick=health;list();health();
})();
