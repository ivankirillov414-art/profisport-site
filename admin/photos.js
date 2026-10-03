(()=>{
  'use strict';
  const $=id=>document.getElementById(id),endpoint='../api/product-photos.php';
  let page=1,pages=0,listVersion=0,detailVersion=0,current=null,busy=false,csrf='',preview='',maxBytes=8*1048576,canEdit=false;
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const safeUrl=url=>typeof url==='string'&&(/^(?:\/import\/|https:\/\/)/i.test(url))?url:'';
  function message(id,text,error=false){$(id).textContent=text;$(id).className=error?'error':'muted';}
  async function request(url,options={}){
    const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...options});let d;
    try{d=await r.json();}catch{throw new Error(`Сервер не подтвердил результат (HTTP ${r.status}). Обновите карточку для проверки.`);}
    if(!r.ok||d.ok!==true){const e=new Error(d.error||`Ошибка HTTP ${r.status}`);e.status=r.status;throw e;}return d;
  }
  async function auth(){if(csrf)return csrf;const d=await request('../server/api.php?action=me');csrf=d.csrf||'';if(!csrf)throw new Error('Войдите в админку заново.');return csrf;}
  function imageMarkup(url,alt=''){const u=safeUrl(url);return u?`<img src="${esc(u)}" alt="${esc(alt)}" loading="lazy" referrerpolicy="no-referrer">`:'';}
  async function list(){
    const version=++listVersion;message('photoStatus','Загружаю товары…');$('photoPrev').disabled=$('photoNext').disabled=true;
    const params=new URLSearchParams({q:$('photoQuery').value.trim(),missing:$('photoMissing').checked?'1':'0',archived:$('photoArchived').checked?'1':'0',page:String(page)});
    try{
      const d=await request(endpoint+'?'+params);if(version!==listVersion)return;
      pages=d.pages||0;
      if(page>Math.max(1,pages)){page=Math.max(1,pages);return list();}
      $('photoResults').innerHTML=(d.items||[]).map(p=>`<article class="product"><div class="thumb">${imageMarkup(p.preview,p.name)||'Нет фото'}</div><h3>${esc(p.name)}</h3><div class="meta">ID ${Number(p.id)} · Артикул: ${esc(p.sku||'—')} · Код 1С: ${esc(p.source_id||'—')}<br>${esc(p.category||'Без категории')}${p.active?'':' · Скрыт'}</div><button type="button" data-product="${Number(p.id)}">${p.preview?'Изменить фотографии':'Добавить фото'}</button></article>`).join('');
      message('photoStatus',d.total?`Найдено товаров: ${Number(d.total)}. Выберите нужную карточку.`:'Ничего не найдено. Измените поиск или отключите «Только без фото».');
      $('photoPage').textContent=pages?`${page} / ${pages}`:'0 / 0';$('photoPrev').disabled=page<=1;$('photoNext').disabled=page>=pages;
    }catch(e){if(version===listVersion)message('photoStatus',e.message,true);}
  }
  function clearPreview(){if(preview)URL.revokeObjectURL(preview);preview='';$('photoPreview').hidden=true;$('photoPreview').removeAttribute('src');$('photoFile').value='';}
  function controls(){
    $('photoClose').disabled=busy;$('photoFile').disabled=busy||!canEdit;$('photoMakePrimary').disabled=busy||!canEdit;
    $('photoSave').disabled=busy||!canEdit||!current||!$('photoFile').files?.length;
    for(const b of $('photoGallery').querySelectorAll('button'))b.disabled=busy||!canEdit;
  }
  function render(d){
    if(!d.product||!d.product.revision)throw new Error('Сервер не вернул карточку товара.');
    current=d.product;canEdit=d.can_edit===true;maxBytes=Number(d.max_file_bytes)||maxBytes;
    $('photoEditorTitle').textContent=current.name;
    $('photoProductMeta').textContent=`ID ${current.id} · Артикул: ${current.sku||'—'} · Код 1С: ${current.source_id||'—'} · ${current.category||'Без категории'}`;
    $('photoProductLink').href='../product.html?id='+Number(current.id);
    $('photoLimit').textContent=`Лимит файла: ${(maxBytes/1048576).toFixed(1)} МБ; не более 12 мегапикселей.`;
    $('photoGallery').innerHTML=current.photos.length?current.photos.map((p,i)=>`<div class="photo${p.primary?' primary':''}">${imageMarkup(p.preview,'Фотография товара')}<small>${p.primary?'Основное · ':''}${p.manual?'Загружено вручную':'Из каталога'}${p.working?'':' · Файл недоступен'}</small>${!p.primary&&p.working?`<button class="secondary" data-primary="${i}" type="button">Сделать основным</button>`:''}</div>`).join(''):'<p class="muted">У товара пока нет фотографий.</p>';
    controls();
  }
  async function open(id){
    if(busy)return;const version=++detailVersion;current=null;canEdit=false;clearPreview();controls();
    $('photoEditorTitle').textContent='Загрузка…';$('photoGallery').innerHTML='';$('photoProductMeta').textContent='';$('photoProductLink').removeAttribute('href');message('photoEditorStatus','Загружаю карточку…');
    if(!$('photoEditor').open)$('photoEditor').showModal();
    try{const d=await request(endpoint+'?id='+Number(id));if(version!==detailVersion)return;render(d);message('photoEditorStatus',canEdit?'': 'Доступен только просмотр. Изменения вносит владелец или администратор.');}catch(e){if(version===detailVersion)message('photoEditorStatus',e.message,true);}
  }
  async function mutate(action,body,form=false){
    if(busy||!current||!canEdit)return;busy=true;controls();message('photoEditorStatus',action==='upload'?'Загружаю и сохраняю фотографию…':'Сохраняю основное фото…');
    try{
      const token=await auth();const headers={'X-CSRF-Token':token};if(!form)headers['Content-Type']='application/json';
      const d=await request(endpoint+'?action='+action,{method:'POST',headers,body:form?body:JSON.stringify(body)});
      render(d);clearPreview();message('photoEditorStatus','Сохранено. Фотография привязана к товару и сохранится при обновлении из 1С.');$('photoEditorStatus').className='success';list();
    }catch(e){
      if(e.status===401||e.status===403)csrf='';
      message('photoEditorStatus',e.message+(e.status===409?' Закройте и откройте карточку заново.':' При обрыве связи обновите карточку перед повторной загрузкой.'),true);
    }finally{busy=false;controls();}
  }
  $('photoSearch').addEventListener('submit',e=>{e.preventDefault();page=1;list();});
  let timer;$('photoQuery').addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>{page=1;list();},350);});
  for(const id of ['photoMissing','photoArchived'])$(id).addEventListener('change',()=>{page=1;list();});
  $('photoRefresh').onclick=()=>list();$('photoPrev').onclick=()=>{if(page>1){page--;list();}};$('photoNext').onclick=()=>{if(page<pages){page++;list();}};
  $('photoResults').addEventListener('click',e=>{const b=e.target.closest('[data-product]');if(b)open(b.dataset.product);});
  $('photoGallery').addEventListener('click',e=>{const b=e.target.closest('[data-primary]');if(b&&current){const p=current.photos[Number(b.dataset.primary)];if(p)mutate('primary',{id:current.id,revision:current.revision,url:p.url});}});
  $('photoFile').addEventListener('change',()=>{
    if(preview)URL.revokeObjectURL(preview);preview='';$('photoPreview').hidden=true;
    const file=$('photoFile').files?.[0];
    if(file){if(!/\.(jpe?g|png|webp)$/i.test(file.name)||file.size<1||file.size>maxBytes){message('photoEditorStatus','Выберите JPG, PNG или WebP допустимого размера.',true);$('photoFile').value='';}else{preview=URL.createObjectURL(file);$('photoPreview').src=preview;$('photoPreview').hidden=false;message('photoEditorStatus','Фото выбрано. Нажмите «Загрузить и сохранить».');}}
    controls();
  });
  $('photoSave').onclick=()=>{const file=$('photoFile').files?.[0];if(!file||!current)return;const form=new FormData();form.append('id',String(current.id));form.append('revision',current.revision);form.append('primary',$('photoMakePrimary').checked?'1':'0');form.append('photo',file);mutate('upload',form,true);};
  $('photoClose').onclick=()=>{if(!busy)$('photoEditor').close();};
  $('photoEditor').addEventListener('cancel',e=>{if(busy)e.preventDefault();});$('photoEditor').addEventListener('close',()=>{detailVersion++;clearPreview();current=null;});
  $('photoAuditRefresh').onclick=async()=>{const b=$('photoAuditRefresh');b.disabled=true;message('photoAuditStatus','Проверяю фотографии…');try{const d=await request('../api/photo-health.php?v=manual-photos-1'),s=d.stats||{};message('photoAuditStatus',`Активных товаров: ${s.active_products||0}. Со ссылкой на фото: ${s.products_with_working_image||0}. Без фото: ${s.products_without_db_image||0}. С отсутствующим локальным файлом: ${s.products_with_broken_local_only||0}. Внешние адреса отдельно не проверялись.`);}catch(e){message('photoAuditStatus',e.message,true);}finally{b.disabled=false;}};
  list();
})();
