(()=>{
'use strict';
const $=id=>document.getElementById(id),esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const presets=[[90,110],[100,120],[110,130],[120,140],[130,150],[140,160],[150,165],[160,175],[170,185],[180,195],[190,205]];
let page=1,csrf='',items=[];
async function api(query,options={}){
 const response=await fetch('../api/bicycle-fit-admin.php?'+query,{cache:'no-store',...options});
 const data=await response.json();
 if(!response.ok||!data.ok)throw Error(data.error==='product_changed'?'Товар изменился. Обновите список и проверьте ростовку заново.':data.error==='unauthorized'?'Войдите в админку заново.':data.message||'Не удалось выполнить действие.');
 return data;
}
function card(p){
 const fit=p.fit||{},specs=p.specs.filter(s=>/рам|рост|кол[её]с/i.test(s.name));
 return `<article class="fitCard" data-id="${p.id}">${p.image?`<img src="${esc(p.image)}" alt="${esc(p.name)}" loading="lazy" decoding="async">`:'<p>Фото уточняется</p>'}<h2><a href="../product.html?id=${p.id}" target="_blank" rel="noopener">${esc(p.name)}</a></h2><small>Артикул: ${esc(p.sku||'не указан')} · В наличии: ${esc(p.stock)} · ${esc(p.price)} ₽</small><p class="${p.range?'confirmed':''}">${p.range?`Диапазон роста: ${esc(p.range)}`:'Задача: диапазон роста не уточнён'}</p><dl>${specs.map(s=>`<dt>${esc(s.name)}</dt><dd>${esc(s.value)}</dd>`).join('')}</dl><form><label>Выбрать заготовку диапазона<select name="preset"><option value="">Свой диапазон / выбрать вариант</option>${presets.map(([min,max])=>`<option value="${min},${max}">${min}–${max} см</option>`).join('')}</select></label><div class="bounds"><label>Рост от, см<input name="min" type="number" min="80" max="219" step="1" required value="${esc(fit.min??'')}"></label><label>Рост до, см<input name="max" type="number" min="81" max="220" step="1" required value="${esc(fit.max??'')}"></label></div><label>Размер рамы — своё значение<input name="frame" maxlength="100" placeholder="Например, 17 дюймов, M или 540 мм" value="${esc(fit.frame??'')}"></label><label>Пояснение / источник<textarea name="note" maxlength="1000" rows="3" placeholder="Таблица производителя, результат замера или особенности посадки">${esc(fit.note??'')}</textarea></label><label><input name="confirm" type="checkbox" required style="display:inline;width:auto"> Проверил диапазон для этого велосипеда</label><button type="submit">Подтвердить и сохранить</button><p class="feedback" role="status"></p></form></article>`;
}
async function load(){
 $('message').textContent='Загружаем задачи…';
 try{
 const data=await api(new URLSearchParams({page,q:$('query').value,mode:$('mode').value}));
 items=data.items;csrf=data.csrf;
 $('cards').innerHTML=items.map(card).join('')||'<p>В этом списке нет товаров.</p>';
 $('message').textContent=`Нужно уточнить: ${data.pending}. В текущем списке: ${data.total}.`;
 $('pageInfo').textContent=`${data.page} / ${data.pages}`;$('prev').disabled=page<=1;$('next').disabled=page>=data.pages;
 for(const article of $('cards').children){
 const form=article.querySelector('form');if(!form)continue;
 form.elements.preset.onchange=()=>{const bounds=form.elements.preset.value.split(',');if(bounds.length===2){form.elements.min.value=bounds[0];form.elements.max.value=bounds[1]}form.elements.confirm.checked=false};
 form.onsubmit=async event=>{
 event.preventDefault();const p=items.find(p=>p.id===Number(article.dataset.id)),button=form.querySelector('button'),feedback=form.querySelector('.feedback');
 const min=Number(form.elements.min.value),max=Number(form.elements.max.value);
 if(min>=max){feedback.textContent='Рост «от» должен быть меньше роста «до».';return}
 button.disabled=true;feedback.textContent='Сохраняем…';
 try{await api('',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({id:p.id,version:p.version,min,max,frame:form.elements.frame.value,note:form.elements.note.value})});await load();$('message').textContent='Ростовка сохранена. '+$('message').textContent}
 catch(e){feedback.textContent=e.message;feedback.classList.add('error');button.disabled=false}
 };
 }
 }catch(e){$('message').textContent=e.message}
}
$('filters').onsubmit=e=>{e.preventDefault();page=1;load()};$('prev').onclick=()=>{page--;load()};$('next').onclick=()=>{page++;load()};load();
})();
