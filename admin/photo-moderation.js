(()=>{
  let page=1,pages=1,csrf='';
  const $=id=>document.getElementById(id),grid=$('grid'),summary=$('summary'),q=$('q'),pageInfo=$('pageInfo');
  const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  if(q)q.placeholder='Поиск по названию, бренду или модели';

  async function auth(){
    try{
      const r=await fetch('../server/api.php?action=me',{cache:'no-store'}),j=await r.json();
      csrf=j.csrf||'';
      return r.ok&&j.ok;
    }catch(e){console.error(e);return false}
  }

  function cand(x,id){
    const label=x.source==='internet'?'Интернет':(x.source_title||'Вариант');
    return `<button class="cand" type="button" data-url="${esc(x.image)}" data-id="${id}"><img src="${esc(x.image)}" loading="lazy" alt=""><small>${esc(label)}</small></button>`;
  }

  async function load(){
    grid.classList.add('loading');
    summary.textContent='Ищу товары без фотографий и подбираю варианты по названию и модели…';
    try{
      const u=new URL('../api/photo-moderation.php',location.href);
      u.searchParams.set('page',page);u.searchParams.set('limit','12');
      if(q.value.trim())u.searchParams.set('q',q.value.trim());
      const r=await fetch(u,{cache:'no-store'}),j=await r.json();
      if(!r.ok||!j.ok)throw new Error(j.error||`HTTP ${r.status}`);
      pages=j.pages||1;
      summary.textContent=`Нужно проверить вручную: ${j.total}. Страница ${j.page} из ${pages}. Артикул в подборе фото не используется.`;
      pageInfo.textContent=`${j.page} / ${pages}`;
      $('prev').disabled=page<=1;$('next').disabled=page>=pages;
      if(!j.items.length){grid.innerHTML='<div class="empty">Товаров для модерации на этой странице нет.</div>';return}
      grid.innerHTML=j.items.map(card).join('');bind();
    }catch(e){summary.innerHTML='<span class="err">Не удалось загрузить очередь фотографий.</span>';grid.innerHTML='';console.error(e)}
    finally{grid.classList.remove('loading')}
  }

  function card(p){
    const c=(p.candidates||[]).map(x=>cand(x,p.id)).join('');
    return `<article class="product" data-product="${p.id}" data-selected=""><h2>${esc(p.name)}</h2><div class="meta">${p.brand?`Бренд: ${esc(p.brand)} · `:''}${p.model?`Модель: ${esc(p.model)} · `:''}${p.sku?`Артикул: ${esc(p.sku)} · `:''}${p.stock_qty!=null?`Остаток: ${p.stock_qty} · `:''}${esc(p.category_path||'')}</div><div class="cands">${c||'<div class="muted noLocal">В собранном каталоге точных вариантов пока нет.</div>'}</div><div class="actions"><button class="btn secondary internet" type="button">Найти ещё в интернете</button></div><div class="manual"><input class="manualUrl" placeholder="Или вставить прямую ссылку на фото"><button class="btn chooseManual" type="button">Поставить</button></div><div class="actions"><button class="btn save" type="button" disabled>Выбрать фото</button><button class="btn secondary skip" type="button">Пропустить</button></div><div class="status"></div></article>`;
  }

  function bindCands(card){
    card.querySelectorAll('.cand').forEach(b=>{
      if(b.dataset.bound==='1')return;b.dataset.bound='1';
      const img=b.querySelector('img');if(img)img.onerror=()=>{b.style.display='none'};
      b.onclick=()=>{card.querySelectorAll('.cand').forEach(x=>x.classList.remove('selected'));b.classList.add('selected');card.dataset.selected=b.dataset.url||'';card.querySelector('.save').disabled=!card.dataset.selected};
    });
  }

  function bind(){
    document.querySelectorAll('.product').forEach(card=>{
      bindCands(card);
      card.querySelector('.save').onclick=()=>card.dataset.selected&&save(card,card.dataset.selected);
      card.querySelector('.chooseManual').onclick=()=>{const v=card.querySelector('.manualUrl').value.trim();if(v)save(card,v)};
      card.querySelector('.internet').onclick=()=>internet(card);
      card.querySelector('.skip').onclick=()=>card.remove();
    });
  }

  async function internet(card){
    const btn=card.querySelector('.internet'),st=card.querySelector('.status');
    btn.disabled=true;st.textContent='Ищу по названию, типу, бренду и модели — без артикула…';
    try{
      const u=new URL('../api/photo-moderation.php',location.href);u.searchParams.set('mode','internet');u.searchParams.set('product_id',card.dataset.product);
      const r=await fetch(u,{cache:'no-store'}),j=await r.json();
      if(!r.ok||!j.ok)throw new Error(j.error||`HTTP ${r.status}`);
      const list=j.candidates||[];
      if(!list.length){st.innerHTML='<span class="muted">Дополнительных вариантов в интернете не найдено.</span>';return}
      const box=card.querySelector('.cands'),seen=new Set([...card.querySelectorAll('.cand')].map(x=>x.dataset.url));
      card.querySelector('.noLocal')?.remove();
      const fresh=list.filter(x=>x.image&&!seen.has(x.image));
      box.insertAdjacentHTML('beforeend',fresh.map(x=>cand(x,card.dataset.product)).join(''));bindCands(card);
      st.innerHTML=fresh.length?`<span class="ok">Нашёл ещё вариантов: ${fresh.length}. Выбери подходящее фото.</span>`:'<span class="muted">Новых уникальных вариантов нет.</span>';
    }catch(e){st.innerHTML='<span class="err">Интернет-поиск сейчас недоступен. Можно вставить ссылку вручную.</span>';console.error(e)}
    finally{btn.disabled=false}
  }

  async function save(card,url){
    const st=card.querySelector('.status');st.textContent='Сохраняю…';
    try{
      const r=await fetch('../api/photo-moderation.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({product_id:Number(card.dataset.product),image_url:url})}),j=await r.json();
      if(!r.ok||!j.ok)throw new Error(j.error||r.status);
      st.innerHTML='<span class="ok">Готово. Фото сохранено.</span>';
      setTimeout(()=>{load();health()},500);
    }catch(e){st.innerHTML='<span class="err">Не удалось сохранить.</span>';console.error(e)}
  }

  async function health(){
    const text=$('healthText'),box=$('healthGrid'),btn=$('healthBtn');
    if(!text||!box)return;
    if(btn)btn.disabled=true;text.textContent='Проверяю живую MySQL-базу и реальные файлы /import/…';box.innerHTML='';
    try{
      const r=await fetch('../api/photo-health.php?v=1',{cache:'no-store'}),j=await r.json();
      if(!r.ok||!j.ok)throw new Error(j.error||`HTTP ${r.status}`);
      const s=j.stats||{};
      text.textContent=`Проверено активных товаров: ${s.active_products||0}. Внешние HTTP-фото считаются существующими ссылками и проверяются отдельно при загрузке.`;
      const cards=[
        [s.broken_local_references||0,'битых ссылок /import/','bad'],
        [s.products_with_broken_local_only||0,'товаров только с битым локальным фото','bad'],
        [s.fallback_available||0,'можно восстановить из резервной базы','good'],
        [s.unresolved_products||0,'реально осталось без найденного фото','bad'],
        [s.products_without_db_image||0,'без ссылки на фото в БД',''],
        [s.products_with_local_image||0,'с рабочим локальным фото','good'],
        [s.products_with_remote_image||0,'с внешней ссылкой на фото',''],
        [s.active_products||0,'активных товаров всего','']
      ];
      box.innerHTML=cards.map(([n,label,cl])=>`<div class="healthCard ${cl}"><b>${n}</b><span>${label}</span></div>`).join('');
    }catch(e){text.innerHTML='<span class="err">Не удалось выполнить диагностику базы фотографий.</span>';console.error(e)}
    finally{if(btn)btn.disabled=false}
  }

  $('search').onclick=()=>{page=1;load()};
  $('reset').onclick=()=>{q.value='';page=1;load()};
  q.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();page=1;load()}});
  $('prev').onclick=()=>{if(page>1){page--;load()}};$('next').onclick=()=>{if(page<pages){page++;load()}};
  if($('healthBtn'))$('healthBtn').onclick=health;
  (async()=>{if(await auth()){await Promise.all([load(),health()])}else summary.innerHTML='<span class="err">Нужно войти в админку.</span>'})();
})();